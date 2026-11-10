<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UseCampaignDates;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\ThemeRules;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\Repository\Themes;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * **Use Campaign's Dates** (content.md §1.7 #7, Q6 (a)), a Super Admin's: when a linked theme's dates differ from its
 * campaign's, the theme takes the campaign's start and end as typed, each of **the theme's own stores** reading them on
 * its own clock (Q1 (a)), so store by store they then start and end together. Changing the theme stays a Super
 * Admin's (`content.theme.manage` in each of its stores). A scheduled theme stays held to the clash rule, under each
 * store's theme lock; an ended one is history.
 */
final readonly class UseCampaignDatesHandler
{
    public const string PERMISSION = ContentPermissions::THEME_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Campaigns $campaigns,
        private Themes $themes,
        private ThemeRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(UseCampaignDates $command): void
    {
        $this->access->holds(self::PERMISSION);
        $campaign = $this->campaigns->find($command->campaignId);
        $found = $campaign?->themeId === null ? null : $this->themes->find($campaign->themeId);
        $stores = [];

        foreach ($found === null ? [null] : array_keys($found->stores) as $storeId) {
            $store = $this->access->authorize(self::PERMISSION, $storeId, item: true);
            $stores[$store->id] = $store;
        }

        if ($campaign === null || $found === null) {
            throw new ContentNotFound;
        }

        $moments = ThemeRules::moments($campaign->startsLocal, $campaign->endsLocal, array_values($stores));
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $campaign, $found, $stores, $moments, $staffId): void {
            $now = Moments::now();
            $this->rules->lockStores(array_keys($stores));
            $was = $this->themes->lock($found->id) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, reset($stores) ?: throw new ContentNotFound);

            if ($was->hasEnded($now)) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            $theme = new Theme($was->id, $was->slug, $was->nameAr, $was->nameEn, $was->isScheduled, $campaign->startsLocal, $campaign->endsLocal, $moments, $was->version + 1, $staffId, $now);

            if ($was->isScheduled) {
                $this->rules->requireSchedulable($theme, $this->themes->partsOf($was->id), $stores, $now);
            }

            $this->themes->update($theme);
            $this->platform->recordAudit(ContentAudit::entry('content.theme.dates_from_campaign', 'content.theme', $was->id, null, ContentAudit::diff($was->fields(), [...$theme->fields(), 'campaign_id' => $campaign->id])));
            $this->cache->invalidate();
        }, 3);
    }
}
