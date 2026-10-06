<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Domain\Exception\ImportUndecided;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;

/**
 * **A product not a draft keeps its codes** (catalog.md §1.12; owner, 2026-10-06, amendment 11(b)):
 * the products of the file decided "update" or "replace" whose catalog product is ready or archived,
 * giving one of its variants — the same values — another code. Bringing in would change a code that
 * product keeps (`UpdateVariant` refuses it), so the confirm asks first: skip it, or upload the file
 * corrected. Values are read as bringing in reads them; one the page creates is new, so it matches no
 * variant, and one still undecided is asked about as a name.
 */
final readonly class ImportCodeChanges
{
    public function __construct(
        private Imports $imports,
        private ProductRepository $products,
        private VariantRepository $variants,
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private WarrantyRepository $warranties,
    ) {}

    /**
     * @param  list<ImportProduct>  $rows  the import's products taking part
     * @return list<string> the ids of those that would change a code their catalog product keeps
     */
    public function of(string $importId, array $rows): array
    {
        $changing = array_filter($rows, static fn (ImportProduct $row): bool => in_array($row->decision, [ImportProduct::UPDATE, ImportProduct::REPLACE], true) && $row->conflictProductId !== null);

        if ($changing === []) {
            return [];
        }

        $names = [];

        foreach ($this->imports->names($importId) as $name) {
            $names[$name->kind.' '.$name->key] = $name;
        }

        $references = new ImportReferences(CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties), $names, [], $this->brands->defaultBrand()?->id() ?? '');
        $found = [];

        foreach ($changing as $row) {
            $product = $this->products->find((string) $row->conflictProductId);

            if ($product === null || $product->isDraft()) {
                continue;
            }

            $codes = [];

            foreach ($this->variants->ofProduct($product->id()) as $variant) {
                $codes[ImportBringer::combination($variant->combination()->valueIds)] = $variant->code()->value;
            }

            foreach ($row->effective()->variants as $variant) {
                $key = self::combination($variant, $references);

                if ($key !== null && isset($codes[$key]) && $codes[$key] !== $variant->code) {
                    $found[] = $row->id;

                    break;
                }
            }
        }

        return $found;
    }

    /** The variant's values as the catalog's ids — or null when one is not the catalog's yet. */
    private static function combination(FileVariant $variant, ImportReferences $references): ?string
    {
        $values = [];

        try {
            foreach ($variant->values as $attribute => $value) {
                $attributeId = $references->attribute((string) $attribute);
                $valueId = $attributeId === null ? null : $references->value((string) $attribute, $attributeId, $value);

                if ($attributeId === null || $valueId === null) {
                    return null;
                }

                $values[$attributeId] = $valueId;
            }
        } catch (ImportUndecided) {
            return null;
        }

        return ImportBringer::combination($values);
    }
}
