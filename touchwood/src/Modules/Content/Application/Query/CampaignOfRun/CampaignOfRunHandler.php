<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\CampaignOfRun;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Repository\Campaigns;
use Shared\Application\Unauthorized;

/**
 * The campaign a run belongs to, for the link S8's alert carries (`/admin/content/campaigns/runs/{run}`, ops.md §9.7
 * #3; content.md W4b: "`/runs/{run}` lands here"): under the campaign's job in the run's store; an unknown run, or
 * one of a store the reader holds nothing in, the same answer (`ContentNotFound`).
 */
final readonly class CampaignOfRunHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
    ) {}

    /**
     * @return string the campaign's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(string $runId): string
    {
        $this->access->holds(self::PERMISSION);
        $run = $this->campaigns->findRun($runId);
        $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);

        return (string) $run?->campaignId;
    }
}
