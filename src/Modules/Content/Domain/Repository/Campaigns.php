<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use DateTimeImmutable;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;

/**
 * Campaigns and their runs (content.md §1.7, §5: `content.campaigns`, `content.campaign_runs`,
 * `content.campaign_run_targets`). **One lock order** (lesson 37): the campaign's row, then its runs in store-id
 * order; the job locks one run alone.
 */
interface Campaigns
{
    public function find(string $id): ?Campaign;

    public function lock(string $id): ?Campaign;

    public function add(Campaign $campaign): void;

    public function update(Campaign $campaign): void;

    public function delete(string $id): void;

    /**
     * The campaign's runs, in store-id order.
     *
     * @return list<CampaignRun>
     */
    public function runsOf(string $campaignId): array;

    /**
     * The campaign's runs locked until the transaction ends, in store-id order.
     *
     * @return list<CampaignRun>
     */
    public function lockRuns(string $campaignId): array;

    public function findRun(string $runId): ?CampaignRun;

    public function lockRun(string $runId): ?CampaignRun;

    public function addRun(CampaignRun $run): void;

    /** Writes what a change may change: state, moments, choices, notice, stamps, ended and cancelled. */
    public function updateRun(CampaignRun $run): void;

    public function deleteRun(string $runId): void;

    /**
     * These runs with their campaign's name, in one query; an unknown id left out.
     *
     * @param  list<string>  $runIds
     * @return array<string, array{storeId: string, state: RunState, nameAr: string, nameEn: string|null}> by run id
     */
    public function namesOf(array $runIds): array;

    /** The run that holds this notice while it may still send it (a draft or scheduled run), or null. */
    public function noticeHolder(string $noticeId): ?string;

    /**
     * The categories and brands the run names, kept with their RESTRICT keys while it may still price them (§1.1
     * rule 17); empty lists release them.
     *
     * @param  list<string>  $categoryIds
     * @param  list<string>  $brandIds
     */
    public function keepTargets(string $runId, array $categoryIds, array $brandIds): void;

    /**
     * Runs the job has something to do with now (§1.7 #9, #11), at most `$limit`, oldest start first: any scheduled or
     * live one whose end has come; in a store that is on, a scheduled one whose start has come and a live one one of
     * whose alerts may be due. A run of an off store waits, so it never fills the batch (Q17 (a)).
     *
     * @param  list<string>  $onStores  the stores switched on
     * @return list<CampaignRun>
     */
    public function due(DateTimeImmutable $now, array $onStores, int $limit): array;

    /**
     * Makes the run live by one conditional update (§1.7 #9): only a run still scheduled, whose start has come and whose
     * end has not. True for the update that changed the row - and only it writes `CampaignWentLive`.
     */
    public function goLive(string $runId, DateTimeImmutable $now): bool;

    /** Ends a scheduled or live run whose end has come, by a conditional update; true when this one did. */
    public function endOnTime(string $runId, DateTimeImmutable $now): bool;

    /**
     * Stamps one ending alert by a conditional update on its stamp (`72` or `24`), on a live run only: true for the
     * update that stamped it, which alone writes its `CampaignExpiring`.
     */
    public function stampWarning(string $runId, int $hours, DateTimeImmutable $now): bool;
}
