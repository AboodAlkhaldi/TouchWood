<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CreateProduct;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductInput;
use Modules\Catalog\Application\Products\ProductReferences;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

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
        private PlatformApi $platform,
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
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($this->storeThatIsOn()));

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

    /**
     * The first store that is on where the reader holds the job - with All stores, the first store
     * that is on.
     *
     * @throws Unauthorized when the reader holds the job in no store that is on
     */
    private function storeThatIsOn(): StoreId
    {
        $held = $this->authorizer->storesWith(self::PERMISSION);

        if ($held === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $holds = [];

        foreach ($held ?? [] as $store) {
            $holds[$store->value] = true;
        }

        foreach ($this->platform->stores() as $store) {
            if ($held === null || isset($holds[strtolower($store->id)])) {
                return StoreId::fromString($store->id);
            }
        }

        throw new Unauthorized(self::PERMISSION);
    }
}
