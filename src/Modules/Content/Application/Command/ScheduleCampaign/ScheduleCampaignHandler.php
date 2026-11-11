<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ScheduleCampaign;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\RefusalWords;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\CampaignPartlyScheduled;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * Schedules a campaign's draft runs (content.md §1.7 #14, #15, #16). **All of them are checked first, with no
 * writes**: English beside the Arabic name, an end still to come, **Pricing's permission in a store whose run holds
 * price choices** (`CampaignPricesNotAllowed`, naming it), a chosen notice still a draft of its store. A run with no
 * prices schedules as it is (#16: "theme only").
 *
 * Then **each run schedules in its own transaction, one store's pricing lock at a time, in store-id order** (lesson 37):
 * the campaign's row, the run's, then `CampaignPrices::save` - the only call that hands a draft's choices to Pricing,
 * which is the record from then on (Content's copy is cleared). **If a store then refuses** (Pricing's ceiling, a
 * target no longer there), the runs done stay scheduled, the rest stay drafts, and the person is told which store
 * refused and which are scheduled already (`CampaignPartlyScheduled`, the store's own refusal kept beside it; the first
 * store's refusal alone, when none is done): the campaign reads Mixed until another press. **Translate's mark** on the
 * English name asks for the tick in each store's transaction, and the tick clears it (§1.3 #4, `TranslationUnread`).
 */
final readonly class ScheduleCampaignHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private CampaignRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private RefusalWords $words,
        private TranslationMarks $marks,
    ) {}

    /**
     * @return list<string> the runs scheduled, in store-id order
     *
     * @throws ContentError|Unauthorized|Throwable
     */
    public function handle(ScheduleCampaign $command): array
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->campaigns->find($command->campaignId);
        $runs = $found === null ? [] : $this->campaigns->runsOf($found->id);
        $targets = array_values(array_filter($runs, static fn (CampaignRun $run): bool => $command->storeId === null || $run->storeId === strtolower($command->storeId)));
        $stores = [];

        foreach ($targets === [] ? [null] : $targets as $run) {
            $store = $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);
            $stores[$store->id] = $store;
        }

        $drafts = array_values(array_filter($targets, static fn (CampaignRun $run): bool => $run->state === RunState::Draft));

        if ($found === null) {
            throw new ContentNotFound;
        }

        if ($drafts === []) {
            throw new InvalidContentInput('state', InvalidContentInput::STATE);
        }

        // Every run checked before any is written (§1.7 #15).
        $now = Moments::now();
        $this->check->version($command->version, $found->version, $found->updatedBy, $found->updatedAt, $stores[$drafts[0]->storeId]);
        Words::requireEnglish('name', $found->nameAr, $found->nameEn);

        foreach ($drafts as $run) {
            if ($run->endsAt <= $now) {
                throw new InvalidContentInput('ends_at', InvalidContentInput::PAST);
            }

            if ($run->priceChoices !== null && ! $run->priceChoices->isEmpty()) {
                $this->rules->requirePriceRight($stores[$run->storeId]);
            }

            if ($run->noticeId !== null) {
                $this->rules->requireDraftNotice($run->noticeId, $run->storeId);
            }
        }

        $staffId = $this->access->staffId();
        $scheduled = [];
        // The version this press expects: the form's, raised by each store this press schedules.
        $version = $command->version;

        foreach ($drafts as $run) {
            try {
                $done = $this->scheduleOne($run->id, $stores[$run->storeId], $staffId, $version, $command->read);
            } catch (Throwable $failure) {
                throw $this->stopped($found->id, $run, $failure);
            }

            if ($done) {
                $scheduled[] = $run->id;
                $version++;
            }
        }

        return $scheduled;
    }

    /**
     * Partly scheduled (§1.7 #15; reviews of #382): when the campaign has a run scheduled now, the person is told which
     * store refused, why - the store's own refusal, or words for a failure that is none - and **every** store scheduled
     * now, this press's and earlier ones'. With none scheduled, the refusal goes as it is.
     */
    private function stopped(string $campaignId, CampaignRun $refused, Throwable $failure): Throwable
    {
        $scheduledNow = [];

        foreach ($this->campaigns->runsOf($campaignId) as $run) {
            if ($run->state === RunState::Scheduled && $run->id !== $refused->id) {
                $store = $this->platform->store(StoreId::fromString($run->storeId));
                $scheduledNow[] = $store === null ? $run->storeId : $store->name->in(app()->getLocale());
            }
        }

        if ($scheduledNow === []) {
            return $failure;
        }

        $refusal = $failure instanceof DomainError ? $failure : null;
        $store = $this->platform->store(StoreId::fromString($refused->storeId));

        return new CampaignPartlyScheduled(
            $store === null ? $refused->storeId : $store->name->in(app()->getLocale()),
            implode(app()->getLocale() === 'ar' ? '، ' : ', ', $scheduledNow),
            $refusal === null ? $this->words->unexpected() : $this->words->sentence($refusal),
            $refusal,
            $failure,
        );
    }

    /**
     * One store's run, in its own transaction, **judged again on its locked rows** (review of #382): the campaign's
     * version as this press expects it, the English name, an end still to come, a notice still a draft, and Pricing's
     * permission for the choices the run holds now - a SetRunPrices between the first checks and this one is not
     * handed to Pricing for someone without the price right.
     *
     * @throws ContentError
     */
    private function scheduleOne(string $runId, StoreDto $store, ?string $staffId, int $version, bool $read): bool
    {
        return $this->db->transaction(function () use ($runId, $store, $staffId, $version, $read): bool {
            $now = Moments::now();
            $run = $this->campaigns->findRun($runId) ?? throw new ContentNotFound;
            $campaign = $this->campaigns->lock($run->campaignId) ?? throw new ContentNotFound;
            $run = $this->campaigns->lockRun($runId) ?? throw new ContentNotFound;

            // Scheduled by another press meanwhile, or taken off: nothing to do here.
            if ($run->state !== RunState::Draft) {
                return false;
            }

            $this->check->version($version, $campaign->version, $campaign->updatedBy, $campaign->updatedAt, $store);
            Words::requireEnglish('name', $campaign->nameAr, $campaign->nameEn);
            // Translate's English name nobody has said they read asks for the tick (§1.3 #4), refused here before this
            // store writes anything; the tick clears the mark with the first store scheduled. A campaign still marked
            // has no run scheduled (its saves are refused once one is), so the first store refuses it whole.
            $this->marks->showing(UnreadTranslations::CAMPAIGN, $campaign->id, $read);

            if ($run->endsAt <= $now) {
                throw new InvalidContentInput('ends_at', InvalidContentInput::PAST);
            }

            if ($run->noticeId !== null) {
                $this->rules->requireDraftNotice($run->noticeId, $run->storeId);
            }

            if ($run->priceChoices !== null && ! $run->priceChoices->isEmpty()) {
                $this->rules->requirePriceRight($store);
                $this->rules->savePrices($run, $run->priceChoices, $store);
            }

            $scheduled = $run->with(state: RunState::Scheduled, choices: true, priceChoices: null);
            $this->campaigns->updateRun($scheduled);
            $this->campaigns->update(new Campaign($campaign->id, $campaign->nameAr, $campaign->nameEn, $campaign->startsLocal, $campaign->endsLocal, $campaign->themeId, $campaign->version + 1, $staffId, $now));
            $this->platform->recordAudit(ContentAudit::entry('content.campaign.scheduled', 'content.campaign_run', $run->id, $store->id, ContentAudit::diff($run->fields(), $scheduled->fields())));

            return true;
        }, 3);
    }
}
