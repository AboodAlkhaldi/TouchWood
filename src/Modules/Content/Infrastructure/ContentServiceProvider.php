<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Public\Contracts\CatalogUsages;
use Modules\Catalog\Public\Events\ProductArchived;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentJobs;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Application\Query\Shop\ShopReads;
use Modules\Content\Application\Redirects\AddressProbe;
use Modules\Content\Application\Redirects\SheetReader;
use Modules\Content\Application\Sitemap\SitemapFiles;
use Modules\Content\Application\Sitemap\SitemapSources;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\Repository\BlogCategories;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Documents;
use Modules\Content\Domain\Repository\FrameItems;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Content\Domain\Repository\Popups;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Content\Domain\Repository\RedirectUploads;
use Modules\Content\Domain\Repository\SlugHistory;
use Modules\Content\Domain\Repository\SmartBars;
use Modules\Content\Domain\Repository\StoreSeo;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Content\Domain\Repository\WaitingJobs;
use Modules\Content\Infrastructure\Cache\VersionedContentCache;
use Modules\Content\Infrastructure\Eloquent\ContentCatalogUsage;
use Modules\Content\Infrastructure\Eloquent\DatabaseBanners;
use Modules\Content\Infrastructure\Eloquent\DatabaseBlogCategories;
use Modules\Content\Infrastructure\Eloquent\DatabaseContentLocks;
use Modules\Content\Infrastructure\Eloquent\DatabaseDocuments;
use Modules\Content\Infrastructure\Eloquent\DatabaseFrameItems;
use Modules\Content\Infrastructure\Eloquent\DatabaseHomeSections;
use Modules\Content\Infrastructure\Eloquent\DatabasePopups;
use Modules\Content\Infrastructure\Eloquent\DatabaseRedirects;
use Modules\Content\Infrastructure\Eloquent\DatabaseRedirectUploads;
use Modules\Content\Infrastructure\Eloquent\DatabaseScreenReads;
use Modules\Content\Infrastructure\Eloquent\DatabaseShopReads;
use Modules\Content\Infrastructure\Eloquent\DatabaseSitemapSources;
use Modules\Content\Infrastructure\Eloquent\DatabaseSlugHistory;
use Modules\Content\Infrastructure\Eloquent\DatabaseSmartBars;
use Modules\Content\Infrastructure\Eloquent\DatabaseStoreSeo;
use Modules\Content\Infrastructure\Eloquent\DatabaseUnreadTranslations;
use Modules\Content\Infrastructure\Eloquent\DatabaseWaitingJobs;
use Modules\Content\Infrastructure\External\HttpAddressProbe;
use Modules\Content\Infrastructure\External\NoCampaignsYet;
use Modules\Content\Infrastructure\Files\LocalSitemapFiles;
use Modules\Content\Infrastructure\Listener\ForgetShopCopiesOfFile;
use Modules\Content\Infrastructure\Listener\QueueSitemapBuild;
use Modules\Content\Infrastructure\Listener\WriteStartingSections;
use Modules\Content\Infrastructure\Media\ContentMediaUsage;
use Modules\Content\Infrastructure\Queue\BuildSitemapsJob;
use Modules\Content\Infrastructure\Queue\PruneRedirectUploadsJob;
use Modules\Content\Infrastructure\Queue\QueuedContentJobs;
use Modules\Content\Infrastructure\Queue\QueueWantedSitemapsJob;
use Modules\Content\Infrastructure\Sheets\OpenSpoutSheetReader;
use Modules\Content\Presentation\Http\RedirectAnswer;
use Modules\Content\Public\Contracts\ContentApi;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\UnmatchedAddresses;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\Events\MediaVariantsReady;
use Modules\Platform\Public\Events\StoreActivated;
use Modules\Platform\Public\Events\StoreCreated;
use Modules\Platform\Public\Events\StoreDeactivated;

/**
 * Content's wiring (content.md; handoff §13.2): what the shop says and how it is dressed. Registered after
 * Platform, Catalog and Pricing, which it depends on, and after Access, whose public surface it uses to
 * declare its permissions and for nothing else (§9.4 A1; ContentAccessUseTest).
 *
 * PR 2, the foundation: its permissions, its errors and their words, the `content` schema with its slug
 * history, the text format and Translate. PR 3, its own items: the home page, the bar and footer, banners, pop-ups,
 * smart bars, pages, the blog and projects, their uploads, Platform's `MediaUsage` and Catalog's `CatalogUsage`, and
 * the shop's cached reads (registered as the frame's part with the shop's frame, PR 9). PR 4: SEO defaults, the
 * sitemaps and the robots file, the redirects and their answer to an address nobody answers. `ContentApi` stays answered by
 * the placeholder - no campaign exists yet - until campaigns land (PR 6), so Ops, built against it, runs.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContentApi::class, NoCampaignsYet::class);

        $this->app->bind(HomeSections::class, DatabaseHomeSections::class);
        $this->app->bind(FrameItems::class, DatabaseFrameItems::class);
        $this->app->bind(Banners::class, DatabaseBanners::class);
        $this->app->bind(Popups::class, DatabasePopups::class);
        $this->app->bind(SmartBars::class, DatabaseSmartBars::class);
        $this->app->bind(Documents::class, DatabaseDocuments::class);
        $this->app->bind(BlogCategories::class, DatabaseBlogCategories::class);
        $this->app->bind(SlugHistory::class, DatabaseSlugHistory::class);
        $this->app->bind(ContentLocks::class, DatabaseContentLocks::class);
        $this->app->bind(ContentCache::class, VersionedContentCache::class);
        $this->app->bind(ShopReads::class, DatabaseShopReads::class);
        $this->app->bind(ScreenReads::class, DatabaseScreenReads::class);

        // PR 4: SEO, the sitemaps, the robots file and the redirects.
        $this->app->bind(StoreSeo::class, DatabaseStoreSeo::class);
        $this->app->bind(Redirects::class, DatabaseRedirects::class);
        $this->app->bind(RedirectUploads::class, DatabaseRedirectUploads::class);
        $this->app->bind(WaitingJobs::class, DatabaseWaitingJobs::class);
        $this->app->bind(SitemapSources::class, DatabaseSitemapSources::class);
        $this->app->bind(SitemapFiles::class, LocalSitemapFiles::class);
        $this->app->bind(ContentJobs::class, QueuedContentJobs::class);
        $this->app->bind(AddressProbe::class, HttpAddressProbe::class);
        $this->app->bind(SheetReader::class, OpenSpoutSheetReader::class);

        // PR 7b: Translate's mark kept until read (content.md §1.3, P14).
        $this->app->bind(UnreadTranslations::class, DatabaseUnreadTranslations::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'content');

        // Content sits above Access, so it declares its own permissions through Access's public catalog
        // (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('content', ...ContentPermissions::definitions());

        // Every file an item names has its RESTRICT key; Platform asks before deleting one (§1.1 rule 10), and
        // Catalog before deleting a category a banner or a smart bar names (§1.1 rule 17).
        $this->app->make(MediaUsages::class)->register('content', ContentMediaUsage::class);
        $this->app->make(CatalogUsages::class)->register('content', ContentCatalogUsage::class);

        Event::listen(StoreCreated::class, [WriteStartingSections::class, 'handle']);
        Event::listen(MediaVariantsReady::class, [ForgetShopCopiesOfFile::class, 'handle']);

        // What the sitemaps list changed: a store switched on or off, Catalog's changes (§6). Each only marks the
        // build as wanted; the ten-minute tick queues it.
        foreach ([StoreActivated::class, StoreDeactivated::class] as $event) {
            Event::listen($event, [QueueSitemapBuild::class, 'store']);
        }

        foreach ([ProductArchived::class, ProductMadeReady::class, StoreListingChanged::class] as $event) {
            Event::listen($event, [QueueSitemapBuild::class, 'catalog']);
        }

        // The Content section's entries (§4.3, Q12 (a)), each landing with its screen (PR 7), never before.
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('content', 'home_page', 'content', 'content.admin.home', ContentPermissions::HOMEPAGE_MANAGE, 10, icon: 'home_page'),
            new MenuEntryDto('content', 'bars', 'content', 'content.admin.bars', ContentPermissions::BAR_MANAGE, 20, icon: 'bars'),
            new MenuEntryDto('content', 'banners', 'content', 'content.admin.banners', ContentPermissions::BANNER_MANAGE, 30, icon: 'banners'),
            new MenuEntryDto('content', 'popups', 'content', 'content.admin.popups', ContentPermissions::POPUP_MANAGE, 60, icon: 'popups'),
            new MenuEntryDto('content', 'pages', 'content', 'content.admin.pages', ContentPermissions::PAGE_MANAGE, 70, icon: 'document_types'),
            new MenuEntryDto('content', 'blog', 'content', 'content.admin.blog', ContentPermissions::BLOG_MANAGE, 80, icon: 'audit'),
            new MenuEntryDto('content', 'projects', 'content', 'content.admin.projects', ContentPermissions::PROJECT_MANAGE, 90, icon: 'companies'),
            new MenuEntryDto('content', 'seo', 'content', 'content.admin.seo', ContentPermissions::SEO_MANAGE, 100, icon: 'stores'),
            new MenuEntryDto('content', 'redirects', 'content', 'content.admin.redirects', ContentPermissions::REDIRECT_MANAGE, 110, icon: 'returns'),
        );

        // An old site's address on a line of the redirects answers 301 where nothing else answers (§1.15, row L2).
        $this->app->make(UnmatchedAddresses::class)->register(RedirectAnswer::class);

        // Scheduled work is queued as a job, never command() or call() (owner, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Every on store's sitemap built again each night, beside the other night jobs (plan K10).
            $schedule->job(new BuildSitemapsJob)->timezone('Asia/Riyadh')->dailyAt('03:10')->onOneServer();
            // A build a change wants, queued when it may run.
            $schedule->job(new QueueWantedSitemapsJob)->everyTenMinutes()->onOneServer();
            // Checked redirect sheets nobody applied, gone after 24 hours.
            $schedule->job(new PruneRedirectUploadsJob)->hourly()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/public-routes.php');
        }
    }
}
