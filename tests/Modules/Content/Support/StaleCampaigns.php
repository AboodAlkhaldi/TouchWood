<?php

declare(strict_types=1);

namespace Tests\Modules\Content\Support;

use DateTimeImmutable;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Infrastructure\Eloquent\DatabaseCampaigns;

/**
 * The campaigns' repository with its unlocked reads frozen at a moment: what `due()`, `runsOf()` and `find()` answered then is
 * what they answer now, so a handler meets the copy it read before another change committed (review of #382). Every
 * lock and write goes to the database as it is.
 */
final class StaleCampaigns implements Campaigns
{
    /** @var list<CampaignRun>|null */
    private ?array $due = null;

    /** @var array<string, list<CampaignRun>> */
    private array $runsOf = [];

    /** @var array<string, Campaign|null> */
    private array $found = [];

    public function __construct(
        private readonly DatabaseCampaigns $real,
    ) {}

    /**
     * Installed in the container, reads frozen at this moment: the due runs, and these campaigns' runs.
     *
     * @param  list<string>  $onStores
     */
    public static function freeze(DateTimeImmutable $now, array $onStores, string ...$campaignIds): self
    {
        $stale = new self(app(DatabaseCampaigns::class));
        $stale->due = $stale->real->due($now, $onStores, 200);

        foreach ($campaignIds as $id) {
            $stale->runsOf[strtolower($id)] = $stale->real->runsOf($id);
            $stale->found[strtolower($id)] = $stale->real->find($id);
        }

        app()->instance(Campaigns::class, $stale);

        return $stale;
    }

    public function find(string $id): ?Campaign
    {
        return array_key_exists(strtolower($id), $this->found) ? $this->found[strtolower($id)] : $this->real->find($id);
    }

    public function lock(string $id): ?Campaign
    {
        return $this->real->lock($id);
    }

    public function add(Campaign $campaign): void
    {
        $this->real->add($campaign);
    }

    public function update(Campaign $campaign): void
    {
        $this->real->update($campaign);
    }

    public function delete(string $id): void
    {
        $this->real->delete($id);
    }

    public function runsOf(string $campaignId): array
    {
        return $this->runsOf[strtolower($campaignId)] ?? $this->real->runsOf($campaignId);
    }

    public function lockRuns(string $campaignId): array
    {
        return $this->real->lockRuns($campaignId);
    }

    public function findRun(string $runId): ?CampaignRun
    {
        return $this->real->findRun($runId);
    }

    public function lockRun(string $runId): ?CampaignRun
    {
        return $this->real->lockRun($runId);
    }

    public function addRun(CampaignRun $run): void
    {
        $this->real->addRun($run);
    }

    public function updateRun(CampaignRun $run): void
    {
        $this->real->updateRun($run);
    }

    public function deleteRun(string $runId): void
    {
        $this->real->deleteRun($runId);
    }

    public function namesOf(array $runIds): array
    {
        return $this->real->namesOf($runIds);
    }

    public function noticeHolder(string $noticeId): ?string
    {
        return $this->real->noticeHolder($noticeId);
    }

    public function keepTargets(string $runId, array $categoryIds, array $brandIds): void
    {
        $this->real->keepTargets($runId, $categoryIds, $brandIds);
    }

    public function due(DateTimeImmutable $now, array $onStores, int $limit): array
    {
        return $this->due ?? $this->real->due($now, $onStores, $limit);
    }

    public function goLive(string $runId, DateTimeImmutable $now): bool
    {
        return $this->real->goLive($runId, $now);
    }

    public function endOnTime(string $runId, DateTimeImmutable $now): bool
    {
        return $this->real->endOnTime($runId, $now);
    }

    public function stampWarning(string $runId, int $hours, DateTimeImmutable $now): bool
    {
        return $this->real->stampWarning($runId, $hours, $now);
    }

    public function giveAddress(string $runId, string $storeId, string $slugAr, string $slugEn): void
    {
        $this->real->giveAddress($runId, $storeId, $slugAr, $slugEn);
    }
}
