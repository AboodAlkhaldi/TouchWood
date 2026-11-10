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
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * Schedules a campaign's draft runs (content.md §1.7 #14, #15, #16). **All of them are checked first, with no
 * writes**: English beside the Arabic name, an end still to come, **Pricing's permission in a store whose run holds
 * price choices** (`CampaignPricesNotAllowed`, naming it), a chosen notice still a draft of its store. A run with no
 * prices schedules as it is (#16: "theme only").
 *
 * Then **each run schedules in its own transaction, one store's pricing lock at a time, in store-id order** (lesson 37):
 * the campaign's row, the run's, then `CampaignPrices::save` - the only call that hands a draft's choices to Pricing,
 * which is the record from then on (Content's copy is cleared). **If a store then refuses** (Pricing's ceiling, a
 * target no longer there), the runs done stay scheduled, the rest stay drafts, and the refusal goes to the person: the
 * campaign reads Mixed until another press.
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
    ) {}

    /**
     * @return list<string> the runs scheduled, in store-id order
     *
     * @throws ContentError|Unauthorized
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

        foreach ($drafts as $run) {
            if ($this->scheduleOne($run->id, $stores[$run->storeId], $staffId)) {
                $scheduled[] = $run->id;
            }
        }

        return $scheduled;
    }

    /**
     * One store's run, in its own transaction.
     *
     * @throws ContentError
     */
    private function scheduleOne(string $runId, StoreDto $store, ?string $staffId): bool
    {
        return $this->db->transaction(function () use ($runId, $store, $staffId): bool {
            $now = Moments::now();
            $run = $this->campaigns->findRun($runId) ?? throw new ContentNotFound;
            $campaign = $this->campaigns->lock($run->campaignId) ?? throw new ContentNotFound;
            $run = $this->campaigns->lockRun($runId) ?? throw new ContentNotFound;

            // Scheduled by another press meanwhile, or taken off: nothing to do here.
            if ($run->state !== RunState::Draft) {
                return false;
            }

            if ($run->priceChoices !== null && ! $run->priceChoices->isEmpty()) {
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
