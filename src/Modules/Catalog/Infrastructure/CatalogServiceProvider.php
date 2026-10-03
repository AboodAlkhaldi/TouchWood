<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\Repository\WordPairRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseAttributeRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseBrandRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseCategoryRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseLabelRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseListLocks;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseWarrantyRepository;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseWordPairRepository;
use Modules\Catalog\Infrastructure\Listener\WriteStartingCategoryOrder;
use Modules\Catalog\Infrastructure\Media\CatalogImagesUsage;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Events\StoreCreated;

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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'catalog');

        // Catalog sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('catalog', ...CatalogPermissions::definitions());

        // A store opened later starts with the base store's order of the menu (amendment 1(d)).
        Event::listen(StoreCreated::class, [WriteStartingCategoryOrder::class, 'handle']);

        // Brand logos and category photos: deleting the media leaves them without (§2.4).
        $this->app->make(MediaUsages::class)->register('catalog', CatalogImagesUsage::class);
    }
}
