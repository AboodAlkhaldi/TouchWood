<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\CancelCampaign;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\CampaignNotChangeable;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * Cancel Campaign (content.md §1.7 #9, #10, #12) on runs still scheduled - every one, or one store's: its prices go
 * (`CampaignPrices::endNow`, which removes a run not started and ends one Pricing judges started), the run is
 * `CANCELLED`, **its notice's tie is cleared** - the notice stays a draft in Ops, ready for another use - and no event
 * fires. **Each run's row is locked first**, so of Cancel and going live whichever gets there first wins; the loser is
 * told "This campaign has just gone live. End it instead." (`went_live`). Each run in its own transaction, in store-id
 * order.
 */
final readonly class CancelCampaignHandler
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
     * @return list<string> the runs cancelled
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(CancelCampaign $command): array
    {
        $this->access->holds(self::PERMISSION);
        $runs = $this->rules->runsNamed($command->campaignId, $command->storeId);
        $stores = [];

        foreach ($runs === [] ? [null] : $runs as $run) {
            $store = $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);
            $stores[$store->id] = $store;
        }

        $waiting = array_values(array_filter($runs, static fn (CampaignRun $run): bool => $run->state === RunState::Scheduled));

        if ($waiting === []) {
            throw array_filter($runs, static fn (CampaignRun $run): bool => $run->state === RunState::Live) !== []
                ? new CampaignNotChangeable(CampaignNotChangeable::WENT_LIVE)
                : new InvalidContentInput('state', InvalidContentInput::STATE);
        }

        $cancelled = [];

        foreach ($waiting as $run) {
            $this->cancelOne($run->id, $stores[$run->storeId]);
            $cancelled[] = $run->id;
        }

        return $cancelled;
    }

    /**
     * @throws ContentError
     */
    private function cancelOne(string $runId, StoreDto $store): void
    {
        $this->db->transaction(function () use ($runId, $store): void {
            $run = $this->campaigns->lockRun($runId) ?? throw new ContentNotFound;

            if ($run->state !== RunState::Scheduled) {
                throw $run->state === RunState::Live
                    ? new CampaignNotChangeable(CampaignNotChangeable::WENT_LIVE)
                    : new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            $this->rules->endPrices($run);
            $cancelled = $run->with(state: RunState::Cancelled, notice: true, noticeId: null, cancelledAt: Moments::now());
            $this->campaigns->updateRun($cancelled);
            $this->rules->keepTargets($run, null);
            $this->platform->recordAudit(ContentAudit::entry('content.campaign.cancelled', 'content.campaign_run', $run->id, $store->id, ContentAudit::diff($run->fields(), $cancelled->fields())));
        }, 3);
    }
}
