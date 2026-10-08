<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Catalog\Application\CatalogApiImpl;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportArchives;
use Modules\Catalog\Application\Import\ImportQueue;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Application\Query\ListCategories\ListCategoriesHandler;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Shop\ShopReader;
use Modules\Catalog\Application\Search\SearchLog;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\Repository\WordPairRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseAttributeRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseBrandRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseCatalogListReads;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseCatalogProductReads;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseCategoryRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseImports;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseLabelRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseListingRows;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseListLocks;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseProductRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseSearchLog;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseShopReader;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseStoreListingRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseVariantRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseWarrantyRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseWordPairRepository;
use Modules\Catalog\Infrastructure\Import\DiskImportArchives;
use Modules\Catalog\Infrastructure\Listener\RefreshCardPhotos;
use Modules\Catalog\Infrastructure\Media\CatalogImagesUsage;
use Modules\Catalog\Infrastructure\Media\ProductPhotosUsage;
use Modules\Catalog\Infrastructure\Queue\LaravelImportQueue;
use Modules\Catalog\Infrastructure\Queue\PruneSearchLogJob;
use Modules\Catalog\Presentation\Console\RebuildListingCommand;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\Events\MediaVariantsReady;

/**
 * What is sold, and where (catalog.md). Registered after Access: Catalog uses Access's public
 * surface to declare its permissions, and for nothing else (handoff §4.4, owner 2026-10-02).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ListLocks::class, DatabaseListLocks::class);
        $this->app->bind(BrandRepository::class, DatabaseBrandRepository::class);
        $this->app->bind(CategoryRepository::class, DatabaseCategoryRepository::class);
        $this->app->bind(AttributeRepository::class, DatabaseAttributeRepository::class);
        $this->app->bind(LabelRepository::class, DatabaseLabelRepository::class);
        $this->app->bind(WarrantyRepository::class, DatabaseWarrantyRepository::class);
        $this->app->bind(WordPairRepository::class, DatabaseWordPairRepository::class);
        $this->app->bind(ProductRepository::class, DatabaseProductRepository::class);
        $this->app->bind(VariantRepository::class, DatabaseVariantRepository::class);
        $this->app->bind(StoreListingRepository::class, DatabaseStoreListingRepository::class);
        $this->app->bind(ListingRows::class, DatabaseListingRows::class);
        $this->app->bind(ShopReader::class, DatabaseShopReader::class);
        // The panel's screens read the shared lists through their own reads (§4.4).
        $this->app->bind(CatalogListReads::class, DatabaseCatalogListReads::class);
        $this->app->bind(CatalogProductReads::class, DatabaseCatalogProductReads::class);
        $this->app->bind(SearchLog::class, DatabaseSearchLog::class);
        $this->app->bind(Imports::class, DatabaseImports::class);
        $this->app->bind(ImportQueue::class, LaravelImportQueue::class);
        // A products file's zip waits on the disk config/catalog.php names (amendment 6).
        $this->app->bind(ImportArchives::class, static fn ($app): DiskImportArchives => new DiskImportArchives($app->make(Factory::class), (string) config('catalog.imports.disk'), sys_get_temp_dir()));
        // What the modules above Catalog may ask it (§2.1). ListingFacts is declared, and bound with
        // stage 5, which first calls it (amendment 5(i)).
        $this->app->bind(CatalogApi::class, CatalogApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'catalog');

        // Catalog sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('catalog', ...CatalogPermissions::definitions());

        // Brand logos and category photos: deleting the media leaves them without (§2.4).
        $this->app->make(MediaUsages::class)->register('catalog', CatalogImagesUsage::class);
        // Product and variant photos: detached, except a ready product's last ready one (amendment 3(b)).
        $this->app->make(MediaUsages::class)->register('catalog', ProductPhotosUsage::class);

        // A photo whose sizes became ready may be a card's photo now (§6.2).
        Event::listen(MediaVariantsReady::class, [RefreshCardPhotos::class, 'handle']);

        // The panel's menu: the products and the shared lists' screens (catalog.md §4.4), each offered
        // for any of the jobs its screen serves, in any store — the screen decides the rest.
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('catalog', 'products', PermissionGroup::Catalog->value, 'catalog.admin.products', CatalogPermissions::PRODUCT_VIEW, 10, icon: 'catalog'),
            new MenuEntryDto('catalog', 'categories', PermissionGroup::Catalog->value, 'catalog.admin.categories', ListCategoriesHandler::JOBS, 20, icon: 'catalog'),
            new MenuEntryDto('catalog', 'brands', PermissionGroup::Catalog->value, 'catalog.admin.brands', CatalogPermissions::BRAND_MANAGE, 30, icon: 'catalog'),
            new MenuEntryDto('catalog', 'attributes', PermissionGroup::Catalog->value, 'catalog.admin.attributes', CatalogPermissions::ATTRIBUTE_MANAGE, 40, icon: 'catalog'),
            new MenuEntryDto('catalog', 'variations', PermissionGroup::Catalog->value, 'catalog.admin.variations', CatalogPermissions::ATTRIBUTE_MANAGE, 50, icon: 'catalog'),
            new MenuEntryDto('catalog', 'labels', PermissionGroup::Catalog->value, 'catalog.admin.labels', CatalogPermissions::LABEL_MANAGE, 60, icon: 'catalog'),
            new MenuEntryDto('catalog', 'warranties', PermissionGroup::Catalog->value, 'catalog.admin.warranties', CatalogPermissions::WARRANTY_MANAGE, 70, icon: 'catalog'),
            new MenuEntryDto('catalog', 'search_words', PermissionGroup::Catalog->value, 'catalog.admin.search-words', CatalogPermissions::SEARCH_WORD_MANAGE, 80, icon: 'catalog'),
        );

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildListingCommand::class]);
        }

        // The search log keeps twelve months (§1.11). Scheduled work is queued as a job, never
        // command() or call() (owner's decision, 2026-09-18): 01:00 where the application runs, 04:00
        // in Riyadh, away from Access's midnight sweep.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(PruneSearchLogJob::class)->dailyAt('01:00')->onOneServer();
        });
    }
}
