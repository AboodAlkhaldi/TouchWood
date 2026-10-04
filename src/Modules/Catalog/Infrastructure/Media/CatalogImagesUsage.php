<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Media;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUseDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A brand's logo and a category's photo are public media (catalog.md §2.4): uses that never block a
 * delete — the brand goes on without a logo, the category without a photo. Detaching changes the
 * shared list, so it takes that list's job with All stores, as editing it would, under the list's
 * lock, and each brand or category that lost its picture is audited.
 *
 * Product and variant photos are `ProductPhotosUsage`'s.
 */
final readonly class CatalogImagesUsage implements MediaUsage
{
    public function __construct(
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private ListLocks $locks,
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    public function usesOf(string $mediaId): array
    {
        return [
            ...array_map(static fn (string $id): MediaUseDto => new MediaUseDto('catalog.brand', $id, false), $this->brands->withLogo($mediaId)),
            ...array_map(static fn (string $id): MediaUseDto => new MediaUseDto('catalog.category', $id, false), $this->categories->withImage($mediaId)),
        ];
    }

    public function detach(string $mediaId): void
    {
        $brandIds = $this->brands->withLogo($mediaId);
        $categoryIds = $this->categories->withImage($mediaId);

        // Both asked before anything changes: a refusal cancels the whole delete anyway, and
        // asking first keeps it from half-reading the lists.
        if ($brandIds !== []) {
            $this->authorizer->authorize(CatalogPermissions::BRAND_MANAGE, PermissionScope::allStores());
        }

        if ($categoryIds !== []) {
            $this->authorizer->authorize(CatalogPermissions::CATEGORY_MANAGE, PermissionScope::allStores());
        }

        if ($brandIds !== []) {
            $this->locks->lock(ListLocks::BRANDS);

            foreach ($this->brands->withLogo($mediaId) as $id) {
                $brand = $this->brands->byId($id);

                if ($brand === null) {
                    continue;
                }

                $brand->dropLogo();
                $entry = ListAudit::changed('brand', 'logo_detached', $brand->id(), $brand->pullChanges(), $brand->snapshot());

                if ($entry !== null) {
                    $this->brands->update($brand);
                    $this->platform->recordAudit($entry);
                }
            }
        }

        if ($categoryIds !== []) {
            $this->locks->lock(ListLocks::CATEGORIES);

            foreach ($this->categories->withImage($mediaId) as $id) {
                $category = $this->categories->byId($id);

                if ($category === null) {
                    continue;
                }

                $category->dropImage();
                $entry = ListAudit::changed('category', 'image_detached', $category->id(), $category->pullChanges(), $category->snapshot());

                if ($entry !== null) {
                    $this->categories->update($category);
                    $this->platform->recordAudit($entry);
                }
            }
        }
    }
}
