<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CreateProduct;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductInput;
use Modules\Catalog\Application\Products\ProductReferences;
use Modules\Catalog\Application\Query\Products\ProductReaders;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Creating a product** (catalog.md §1.1, §3): `catalog.product.create` in **some store that is on**
 * - no store is asked, the panel having no store worked in (amendment 13(f), replacing 3(j)'s working
 * store). A draft, Active nowhere, on an active brand; its slugs free among products, now and ever.
 * Under the products' lock.
 */
final readonly class CreateProductHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_CREATE;

    public function __construct(
        private Authorizer $authorizer,
        private ProductReaders $readers,
        private SharedListChange $change,
        private ProductRepository $products,
        private ProductInput $input,
        private ProductReferences $references,
    ) {}

    /**
     * @return string the new product's id
     *
     * @throws BrandInactive|BrandNotFound|InvalidCatalogAttribute|SlugTaken|Unauthorized
     */
    public function handle(CreateProduct $command): string
    {
        // The first store that is on where the reader holds the job (ProductReaders, as the list asks it).
        $store = $this->readers->storeToCreateIn() ?? throw new Unauthorized(self::PERMISSION);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

        [$name, $slugs] = ProductInput::names($command->nameAr, $command->nameEn, $command->slugAr, $command->slugEn);
        $id = $this->products->nextId();

        return $this->change->run(ListLocks::PRODUCTS, function () use ($command, $name, $slugs, $id): array {
            $brandId = $command->brandId === null || trim($command->brandId) === ''
                ? $this->references->defaultBrand()
                : $this->references->brand($command->brandId);
            $this->input->requireFreeSlugs($slugs);

            $product = Product::create($id, $name, $slugs, $brandId);
            $this->products->add($product);

            return [$id, [ListAudit::added('product', $id, $product->snapshot())]];
        });
    }
}
