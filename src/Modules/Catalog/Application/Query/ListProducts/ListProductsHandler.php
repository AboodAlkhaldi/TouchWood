<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListProducts;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Products\CatalogProductReads;
use Modules\Catalog\Application\Query\Products\ProductFilter;
use Modules\Catalog\Application\Query\Products\ProductReaders;
use Modules\Catalog\Application\Query\Products\ProductRow;
use Modules\Catalog\Public\Enums\ProductStage;
use Shared\Application\Unauthorized;
use Shared\Domain\Text\LatinDigits;

/**
 * **The products list** (catalog.md §4.4 S8, P4): every product to whoever reads products in some
 * store - a product belongs to no store -, what each store does with it only for the stores the reader
 * covers. One store chosen must be one of those. A filter the screen did not offer (a stage or a
 * store state that is not one) is left out rather than refused: it came from an address; so are bytes
 * that are not text in a search.
 */
final readonly class ListProductsHandler
{
    public const string PERMISSION = CatalogPermissions::PRODUCT_VIEW;

    public const int PER_PAGE_MAX = 100;

    public function __construct(
        private ProductReaders $readers,
        private CatalogProductReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListProducts $query): ProductList
    {
        $covered = $this->readers->authorize();
        $given = $query->filter;
        $storeId = $given->storeId === null ? null : strtolower(trim($given->storeId));

        if ($storeId !== null && $covered !== null && ! in_array($storeId, $covered, true)) {
            throw new Unauthorized(self::PERMISSION);
        }

        $search = $given->search === null ? null : trim(LatinDigits::of(mb_scrub($given->search, 'UTF-8')));
        $filter = new ProductFilter(
            search: $search === '' ? null : $search,
            stage: ProductStage::tryFrom((string) $given->stage)?->value,
            categoryId: self::id($given->categoryId),
            brandId: self::id($given->brandId),
            storeId: $storeId,
            storeState: $storeId !== null && in_array($given->storeState, ProductFilter::STORE_STATES, true) ? $given->storeState : null,
            after: self::id($given->after),
        );

        [$rows, $more] = $this->reads->products($filter, min(max($query->perPage, 1), self::PER_PAGE_MAX));

        if ($covered !== null) {
            $rows = array_map(static fn (ProductRow $row): ProductRow => new ProductRow(
                $row->id, $row->nameAr, $row->nameEn, $row->codes, $row->stage, $row->brandId, $row->brandNameAr,
                $row->brandNameEn, $row->categoryId, $row->categoryNameAr, $row->categoryNameEn, $row->photoMediaId,
                $row->variants, array_values(array_intersect($row->onIn, $covered)), $row->storeState, $row->storeVariantsOn,
            ), $rows);
        }

        return new ProductList($rows, $more, $filter, $this->readers->storeToCreateIn() !== null);
    }

    /** An id from the address: lower-cased, or none. Its shape is the read's to check. */
    private static function id(?string $id): ?string
    {
        return $id === null || trim($id) === '' ? null : strtolower(trim($id));
    }
}
