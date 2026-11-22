<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ChooseRunNotice;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\CampaignRules;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\CampaignNotChangeable;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Chooses the notice a run sends once, on going live (content.md §1.7 #10): one of **its store's draft notices** in Ops,
 * read through Content's own port (`NoticeNotDraft` otherwise), and **one notice serves one run at a time**
 * (`NoticeTaken`; the partial unique index behind it). Only while the run is a draft or scheduled: after go-live it
 * was sent and can't be recalled (`notice_sent`). None clears the tie.
 */
final readonly class ChooseRunNoticeHandler
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
     * @throws ContentError|Unauthorized
     */
    public function handle(ChooseRunNotice $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->campaigns->findRun($command->runId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $found, $store, $staffId): void {
            $now = Moments::now();
            $campaign = $this->campaigns->lock((string) $found?->campaignId) ?? throw new ContentNotFound;
            $run = $this->campaigns->lockRun($command->runId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $campaign->version, $campaign->updatedBy, $campaign->updatedAt, $store);

            if ($run->state !== RunState::Draft && $run->state !== RunState::Scheduled) {
                throw new CampaignNotChangeable(CampaignNotChangeable::NOTICE_SENT);
            }

            $noticeId = $this->rules->notice($command->noticeId, $run);
            $this->campaigns->updateRun($run->with(notice: true, noticeId: $noticeId));
            $this->campaigns->update(new Campaign($campaign->id, $campaign->nameAr, $campaign->nameEn, $campaign->startsLocal, $campaign->endsLocal, $campaign->themeId, $campaign->version + 1, $staffId, $now));
            $this->platform->recordAudit(ContentAudit::entry('content.campaign.notice_chosen', 'content.campaign_run', $run->id, $store->id, ContentAudit::diff(['notice_id' => $run->noticeId], ['notice_id' => $noticeId])));
        }, 3);
    }
}
