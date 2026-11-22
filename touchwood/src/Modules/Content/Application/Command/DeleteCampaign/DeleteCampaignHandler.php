<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteCampaign;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Deletes a campaign **only while every run is a draft** (content.md §1.1 rule 11): a scheduled, live, ended or
 * cancelled run is history and stays (`NotDeletable`). Its runs, their kept categories and brands and their smart bars
 * go with it; nothing ever reached Pricing. Needs the campaign's job in every store it runs in. Audited.
 */
final readonly class DeleteCampaignHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGN_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private TranslationMarks $marks,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(DeleteCampaign $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->campaigns->find($command->campaignId);
        $runs = $found === null ? [] : $this->campaigns->runsOf($found->id);
        $stores = [];

        foreach ($runs === [] ? [null] : $runs as $run) {
            $stores[] = $this->access->authorize(self::PERMISSION, $run?->storeId, item: true);
        }

        $this->db->transaction(function () use ($command, $stores): void {
            $was = $this->campaigns->lock($command->campaignId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $stores[0]);

            if (array_filter($this->campaigns->lockRuns($was->id), static fn (CampaignRun $run): bool => $run->state !== RunState::Draft) !== []) {
                throw new NotDeletable;
            }

            $this->campaigns->delete($was->id);
            // Its Translate mark goes with it (§1.3 #4).
            $this->marks->forget(UnreadTranslations::CAMPAIGN, $was->id);
            $this->platform->recordAudit(ContentAudit::entry('content.campaign.deleted', 'content.campaign', $was->id, null, ContentAudit::diff($was->fields(), [])));
        }, 3);
    }
}
