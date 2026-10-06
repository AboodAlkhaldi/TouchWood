<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Application\CatalogApiImpl;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportArchives;
use Modules\Catalog\Application\Import\ImportQueue;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Listing\ListingRows;
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
use Modules\Platform\Public\Contracts\MediaUsages;
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
