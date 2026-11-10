<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveCampaign;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\CampaignNotChangeable;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\Service\CampaignAlerts;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * Writes a campaign's shared fields (content.md §1.7 #1, #5, #8): name per language (Arabic to save, English once any
 * run is scheduled or live), a start and an end - **required**, read on each store's own clock (Q1 (a)) - its stores,
 * one run each, and an optional theme not ended. **The shared fields need the campaign's job in every store it runs
 * in.**
 *
 * - A draft or scheduled campaign changes in full: a scheduled run's prices move with its dates in Pricing.
 * - **Half live**: once any run has gone live, its start, its stores and its theme link are locked for every run
 *   (`CampaignNotChangeable` `locked`); the name and the end still change, and the new end reaches every run
 *   scheduled or live (Pricing's `changeEnd`) - **an ended or cancelled run is never revived**.
 * - A moved end clears an ending alert's stamp whose moment is ahead again (§1.7 #11).
 * - A store taken off: its draft run goes; a scheduled one must be cancelled first; an ended or cancelled one stays.
 *
 * One transaction under the campaign's row, then its runs in store-id order (lesson 37); Pricing's store locks come
 * after, in the same order. Refused over a newer version.
 */
final readonly class SaveCampaignHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    public const int MAX_NAME = 100;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private CampaignRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the campaign's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveCampaign $command): string
    {
        if ($command->campaignId !== null) {
            $this->access->holds(self::PERMISSION);
            $found = $this->campaigns->find($command->campaignId);

            // Every store it already runs in, before anything is read of it (§1.7 #5).
            foreach ($found === null ? [null] : $this->campaigns->runsOf($found->id) as $run) {
                $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);
            }
        }

        $name = Words::arabic('name', $command->nameAr, $command->nameEn, self::MAX_NAME);
        $starts = Moments::wall('starts_at', $command->startsAt);
        $ends = Moments::wall('ends_at', $command->endsAt);

        if ($ends <= $starts) {
            throw new InvalidContentInput('ends_at', InvalidContentInput::DATES);
        }

        $stores = $this->rules->stores($command->storeIds);
        $moments = [];

        foreach ($stores as $store) {
            $moments[$store->id] = Moments::onStoreClock($starts, $ends, $store);
        }

        $staffId = $this->access->staffId();

        return $command->campaignId === null
            ? $this->create($name, $starts, $ends, $stores, $moments, $command->themeId, $staffId)
            : $this->change($command, $name, $starts, $ends, $stores, $moments, $staffId);
    }

    /**
     * @param  array{ar: string, en: string|null}  $name
     * @param  list<StoreDto>  $stores
     * @param  array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>  $moments
     */
    private function create(array $name, string $starts, string $ends, array $stores, array $moments, ?string $themeId, ?string $staffId): string
    {
        $now = Moments::now();
        $themeId = $this->rules->theme($themeId, $now);

        return $this->db->transaction(function () use ($name, $starts, $ends, $stores, $moments, $themeId, $staffId, $now): string {
            $id = strtolower((string) Str::ulid());
            $campaign = new Campaign($id, $name['ar'], $name['en'], $starts, $ends, $themeId, 1, $staffId, $now);
            $this->campaigns->add($campaign);

            foreach ($stores as $store) {
                $this->campaigns->addRun(new CampaignRun(strtolower((string) Str::ulid()), $id, $store->id, RunState::Draft, $moments[$store->id][0], $moments[$store->id][1], null, null));
            }

            $this->platform->recordAudit(ContentAudit::entry('content.campaign.created', 'content.campaign', $id, null, ContentAudit::diff([], [...$campaign->fields(), 'stores' => self::storeList($stores)])));

            return $id;
        }, 3);
    }

    /**
     * @param  array{ar: string, en: string|null}  $name
     * @param  list<StoreDto>  $stores
     * @param  array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>  $moments
     */
    private function change(SaveCampaign $command, array $name, string $starts, string $ends, array $stores, array $moments, ?string $staffId): string
    {
        return $this->db->transaction(function () use ($command, $name, $starts, $ends, $stores, $moments, $staffId): string {
            $now = Moments::now();
            $was = $this->campaigns->lock((string) $command->campaignId) ?? throw new ContentNotFound;
            $byStore = [];

            foreach ($stores as $store) {
                $byStore[$store->id] = $store;
            }

            $runs = $this->campaigns->lockRuns($was->id);
            $first = $stores[0];
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $first);

            $open = array_values(array_filter($runs, static fn (CampaignRun $run): bool => ! $run->state->isOver()));

            if ($open === []) {
                throw new CampaignNotChangeable(CampaignNotChangeable::OVER);
            }

            $halfLive = array_filter($runs, static fn (CampaignRun $run): bool => $run->wentLiveAt !== null) !== [];
            $held = array_map(static fn (CampaignRun $run): string => $run->storeId, $runs);
            $added = array_values(array_diff(array_keys($byStore), $held));
            $removed = array_values(array_filter($runs, static fn (CampaignRun $run): bool => ! isset($byStore[$run->storeId])));
            $themeId = $command->themeId === null || trim($command->themeId) === '' ? null : strtolower(trim($command->themeId));

            if ($halfLive && ($starts !== $was->startsLocal || $added !== [] || $removed !== [] || $themeId !== $was->themeId)) {
                throw new CampaignNotChangeable(CampaignNotChangeable::LOCKED);
            }

            if ($themeId !== $was->themeId) {
                $themeId = $this->rules->theme($themeId, $now);
            }

            // English is required once something reaches Pricing or the shop (§1.1 rule 2): blanked on a run scheduled
            // or live, refused.
            if ($name['en'] === null && array_filter($open, static fn (CampaignRun $run): bool => $run->state !== RunState::Draft) !== []) {
                throw new MissingEnglish('name');
            }

            foreach ($removed as $run) {
                match ($run->state) {
                    RunState::Draft => $this->campaigns->deleteRun($run->id),
                    RunState::Scheduled => throw new CampaignNotChangeable(CampaignNotChangeable::STORE_SCHEDULED),
                    default => throw new CampaignNotChangeable(CampaignNotChangeable::OVER),
                };
            }

            foreach ($open as $run) {
                if (! isset($byStore[$run->storeId])) {
                    continue;
                }

                [$runStarts, $runEnds] = $moments[$run->storeId];
                $this->changeRun($run, $byStore[$run->storeId], $runStarts, $runEnds, $now);
            }

            foreach ($added as $storeId) {
                $this->campaigns->addRun(new CampaignRun(strtolower((string) Str::ulid()), $was->id, $storeId, RunState::Draft, $moments[$storeId][0], $moments[$storeId][1], null, null));
            }

            $campaign = new Campaign($was->id, $name['ar'], $name['en'], $starts, $ends, $themeId, $was->version + 1, $staffId, $now);
            $this->campaigns->update($campaign);
            $this->platform->recordAudit(ContentAudit::entry('content.campaign.changed', 'content.campaign', $was->id, null, ContentAudit::diff(
                [...$was->fields(), 'stores' => implode(',', $held)],
                [...$campaign->fields(), 'stores' => self::storeList($stores)],
            )));

            return $was->id;
        }, 3);
    }

    /**
     * One run's new moments: a draft's as they are; a scheduled run's with its prices in Pricing; a live run's end only
     * (the start is locked, checked above). A new end on a run scheduled or live is still to come.
     *
     * @throws ContentError
     */
    private function changeRun(CampaignRun $run, StoreDto $store, \DateTimeImmutable $starts, \DateTimeImmutable $ends, \DateTimeImmutable $now): void
    {
        if ($run->startsAt == $starts && $run->endsAt == $ends) {
            return;
        }

        if ($run->state === RunState::Draft) {
            $this->campaigns->updateRun($run->with(startsAt: $starts, endsAt: $ends));

            return;
        }

        if ($ends <= $now) {
            throw new InvalidContentInput('ends_at', InvalidContentInput::PAST);
        }

        [$warned72, $warned24] = CampaignAlerts::afterEndMoved($run, $ends, $now);
        $moved = $run->with(startsAt: $starts, endsAt: $ends, warnings: true, warned72At: $warned72, warned24At: $warned24);

        if ($run->startsAt != $starts) {
            // A scheduled run's new start: its prices written again with the new dates. One whose start has come is
            // going live this minute, and is half live in all but name.
            if ($run->state !== RunState::Scheduled || $run->startsAt <= $now) {
                throw new CampaignNotChangeable(CampaignNotChangeable::LOCKED);
            }

            $this->rules->savePrices($moved, $this->rules->heldPrices($run), $store);
        } else {
            $this->rules->moveEnd($run, $ends);
        }

        $this->campaigns->updateRun($moved);
    }

    /**
     * @param  list<StoreDto>  $stores
     */
    private static function storeList(array $stores): string
    {
        return implode(',', array_map(static fn (StoreDto $store): string => $store->id, $stores));
    }
}
