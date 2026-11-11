<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\EndCampaign;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\CampaignNotChangeable;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * End Campaign (content.md §1.7 #8, #12) on live runs - every one, or one store's: the run ends now and its prices stop
 * now (`CampaignPrices::endNow`); the theme is not touched; no event. Each run's row locked first, in its own
 * transaction, in store-id order. A run that has not gone live is not ended here: `not_live` (it is cancelled).
 */
final readonly class EndCampaignHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private CampaignRules $rules,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @return list<string> the runs ended
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(EndCampaign $command): array
    {
        $this->access->holds(self::PERMISSION);
        $runs = $this->rules->runsNamed($command->campaignId, $command->storeId);
        $stores = [];

        foreach ($runs === [] ? [null] : $runs as $run) {
            $store = $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);
            $stores[$store->id] = $store;
        }

        $live = array_values(array_filter($runs, static fn (CampaignRun $run): bool => $run->state === RunState::Live));

        if ($live === []) {
            throw new CampaignNotChangeable(CampaignNotChangeable::NOT_LIVE);
        }

        $ended = [];

        foreach ($live as $run) {
            $this->endOne($run->id, $stores[$run->storeId]);
            $ended[] = $run->id;
        }

        return $ended;
    }

    /**
     * @throws ContentError
     */
    private function endOne(string $runId, StoreDto $store): void
    {
        $this->db->transaction(function () use ($runId, $store): void {
            $run = $this->campaigns->lockRun($runId) ?? throw new ContentNotFound;

            // Ended on time by the job meanwhile: nothing left to stop.
            if ($run->state !== RunState::Live) {
                return;
            }

            $now = Moments::now();
            $this->rules->endPrices($run);
            [$starts, $ends] = Moments::endNow($run->startsAt, $now);
            $ended = $run->with(state: RunState::Ended, startsAt: $starts, endsAt: $ends, endedAt: $now);
            $this->campaigns->updateRun($ended);
            $this->rules->keepTargets($run, null);
            $this->platform->recordAudit(ContentAudit::entry('content.campaign.ended', 'content.campaign_run', $run->id, $store->id, ContentAudit::diff($run->fields(), $ended->fields())));
        }, 3);
    }
}
