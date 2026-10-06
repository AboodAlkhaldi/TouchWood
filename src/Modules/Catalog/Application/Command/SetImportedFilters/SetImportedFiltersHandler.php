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
 * most 100 a product, a product they would take past it named and nothing changed.
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
            $named = array_sum(array_map('count', $product->filters));
            $fileHas = $named > 0 || $product->filterValueIds !== [];
            // A product the file updates without filter values of its own counts the catalog's; what is
            // added to it is kept apart, and joins the catalog's as they are when it is brought in.
            $catalog = ! $fileHas && $updates !== null ? $this->catalogFilters($updates->id(), $filterable) : null;

            if ($mode === ImportedProductsChange::FILL_EMPTY && ($fileHas || ($catalog ?? []) !== [] || $product->addedFilterValueIds !== [])) {
                return $product;
            }

            if ($catalog !== null && $mode === ImportedProductsChange::ADD) {
                $added = array_values(array_unique([...$product->addedFilterValueIds, ...$ids]));

                if (count(array_unique([...$catalog, ...$added])) > ProductParts::MAX_FILTER_VALUES) {
                    throw new InvalidCatalogAttribute("product {$product->number} › filters", 'at most '.ProductParts::MAX_FILTER_VALUES.' values');
                }

                return $product->with(['added_filter_value_ids' => $added]);
            }

            $kept = $mode === ImportedProductsChange::ADD ? array_values(array_unique([...$product->filterValueIds, ...$product->addedFilterValueIds, ...$ids])) : $ids;
            $filters = $mode === ImportedProductsChange::ADD ? $product->filters : [];

            if (count($kept) + ($mode === ImportedProductsChange::ADD ? $named : 0) > ProductParts::MAX_FILTER_VALUES) {
                throw new InvalidCatalogAttribute("product {$product->number} › filters", 'at most '.ProductParts::MAX_FILTER_VALUES.' values');
            }

            return $product->with(['filters' => $filters, 'filter_value_ids' => $kept, 'added_filter_value_ids' => []]);
        });
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
