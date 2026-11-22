<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedFilters;

use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportedProductsChange;
use Modules\Catalog\Application\Products\ProductParts;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Enums\AttributeKind;
use Shared\Application\Unauthorized;

/**
 * **Filter values for an import's products** (catalog.md §1.12, amendment 7(c), (d); §1.7): active
 * values of active filter attributes, kept by their ids beside the filters the file names — added to
 * each one's, replacing them (the file's then cleared), or only for the products that have none; at
 * most 100 a product, a product they would take past it named and nothing changed. Where the file
 * gives none, what is added or filled is kept apart and given when the products are brought in, to
 * what each then has (`FileProduct::asBroughtIn`, amendment 8(a)).
 */
final readonly class SetImportedFiltersHandler
{
    public const string PERMISSION = ImportedProductsChange::PERMISSION;

    public function __construct(
        private ImportedProductsChange $change,
        private AttributeRepository $attributes,
        private ProductRepository $products,
    ) {}

    /**
     * @return int how many products it changed
     *
     * @throws ImportClosed|InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|Unauthorized
     */
    public function handle(SetImportedFilters $command): int
    {
        $this->change->authorize();
        $mode = ImportedProductsChange::mode($command->mode, [ImportedProductsChange::ADD, ImportedProductsChange::REPLACE, ImportedProductsChange::FILL_EMPTY]);
        $ids = $this->values($command->valueIds);
        // Asked once, not for every product.
        $filterable = [];

        foreach ($this->attributes->all() as $attribute) {
            if ($attribute->kind() === AttributeKind::Filterable) {
                $filterable[$attribute->id()] = true;
            }
        }

        return $this->change->run($command->importId, $command->productIds, 'filters', implode(', ', $ids), $mode, function (FileProduct $product, ?Product $updates) use ($ids, $mode, $filterable): FileProduct {
            if ($mode === ImportedProductsChange::REPLACE) {
                return $product->with(['filters' => [], 'filter_value_ids' => $ids, 'added_filter_value_ids' => [], 'fill_filter_value_ids' => []]);
            }

            // The file gives its own: they are what it has, whatever a catalog product it updates has.
            if ($product->givesFilters()) {
                if ($mode === ImportedProductsChange::FILL_EMPTY) {
                    return $product;
                }

                $kept = array_values(array_unique([...$product->filterValueIds, ...$ids]));
                self::within($product, count($kept) + array_sum(array_map('count', $product->filters)));

                return $product->with(['filter_value_ids' => $kept]);
            }

            // The file gives none: what the product has is known when it is brought in — the catalog's,
            // for one the file updates (§1.12) — so what is asked is kept, and given then.
            if ($mode === ImportedProductsChange::FILL_EMPTY) {
                return $product->addedFilterValueIds !== [] || $product->fillFilterValueIds !== [] ? $product : $product->with(['fill_filter_value_ids' => $ids]);
            }

            $added = array_values(array_unique([...$product->addedFilterValueIds, ...$ids]));
            // Within the limit together with what it would have now.
            $has = $updates === null ? [] : $this->catalogFilters($updates->id(), $filterable);
            self::within($product, count(array_unique([...($has !== [] ? $has : $product->fillFilterValueIds), ...$added])));

            return $product->with(['added_filter_value_ids' => $added]);
        });
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function within(FileProduct $product, int $count): void
    {
        if ($count > ProductParts::MAX_FILTER_VALUES) {
            throw new InvalidCatalogAttribute("product {$product->number} › filters", 'at most '.ProductParts::MAX_FILTER_VALUES.' values');
        }
    }

    /**
     * The catalog product's filter values of filter attributes — a variant's own values count as
     * filters by themselves (amendment 3(a)), never kept here.
     *
     * @param  array<string, true>  $filterable  the filter attributes' ids
     * @return list<string>
     */
    private function catalogFilters(string $productId, array $filterable): array
    {
        $ids = [];

        foreach ($this->products->filterValues($productId) as $valueId => $attributeId) {
            if (isset($filterable[(string) $attributeId])) {
                $ids[] = (string) $valueId;
            }
        }

        return $ids;
    }

    /**
     * @param  array<array-key, mixed>  $valueIds
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound
     */
    private function values(array $valueIds): array
    {
        if ($valueIds === [] || count($valueIds) > ProductParts::MAX_FILTER_VALUES) {
            throw new InvalidCatalogAttribute('value_ids', 'from 1 to '.ProductParts::MAX_FILTER_VALUES.' values');
        }

        $ids = [];

        foreach ($valueIds as $id) {
            $value = (is_string($id) ? $this->attributes->findValue($id) : null) ?? throw new ListItemNotFound(is_string($id) ? $id : '');
            $attribute = $this->attributes->find($value->attributeId()) ?? throw new ListItemNotFound($value->attributeId());

            if ($attribute->kind() !== AttributeKind::Filterable) {
                throw new InvalidCatalogAttribute('value_ids', 'values of filter attributes');
            }

            if (! $value->isActive() || ! $attribute->isActive()) {
                throw new ListItemInactive;
            }

            $ids[] = $value->id();
        }

        return array_values(array_unique($ids));
    }
}
