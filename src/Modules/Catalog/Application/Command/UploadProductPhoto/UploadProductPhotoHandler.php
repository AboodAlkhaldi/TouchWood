<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadProductPhoto;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A product's photo, uploaded from its page** (catalog.md §3, §4.4 S9, P5): under
 * `catalog.product.update`, checked as every change to the product's shared data is - in every store
 * where the product is on, or, on nowhere, in some store (`ProductAccess`). Platform's upload takes one
 * scope: one of the stores where the product is on, or - on nowhere - one where the reader holds the
 * job (All stores for someone holding it everywhere). A variant's photo is for a variant of that
 * product, or nothing is uploaded (`VariantNotFound`). Platform keeps the file, checks its type and
 * size, and audits the upload under Catalog's job; nothing about the product changes until the gallery
 * or the variant's photos are saved with it, and that handler checks the photo again.
 */
final readonly class UploadProductPhotoHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_UPDATE;

    public function __construct(
        private ProductAccess $access,
        private Authorizer $authorizer,
        private ProductRepository $products,
        private VariantRepository $variants,
        private StoreListingRepository $listings,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the photo's media id
     *
     * @throws DomainError|ProductNotFound|Unauthorized|VariantNotFound
     */
    public function handle(UploadProductPhoto $command): string
    {
        $this->access->authorize(self::PERMISSION);
        $product = $this->products->find($command->productId) ?? throw new ProductNotFound($command->productId);
        $this->access->authorizeFor(self::PERMISSION, $product->id());

        if ($command->variantId !== null && $this->variants->find($command->variantId)?->productId() !== $product->id()) {
            throw new VariantNotFound($command->variantId);
        }

        return $this->platform->uploadMediaFor(new ModuleUploadDto(
            'catalog',
            self::PERMISSION,
            $this->scope($product->id()),
            MediaVisibility::Public,
            $command->path,
            $command->fileName,
        ));
    }

    private function scope(string $productId): PermissionScope
    {
        $on = $this->listings->activeStoresOf($productId);

        if ($on !== []) {
            return PermissionScope::store(StoreId::fromString($on[0]));
        }

        $held = $this->authorizer->storesWith(self::PERMISSION);

        return $held === null || $held === [] ? PermissionScope::allStores() : PermissionScope::store($held[0]);
    }
}
