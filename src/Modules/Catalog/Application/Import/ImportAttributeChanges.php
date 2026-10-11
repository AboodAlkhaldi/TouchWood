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
 * **A catalog product given other variant attributes by the file** (catalog.md §1.12, P33; owner,
 * 2026-10-10): **updating** it needs the file to name the product's own attributes — else it is
 * replaced whole or skipped; **replacing** it with other attributes needs the file to name **every
 * variant it has, archived ones too** — no variant is left half-described — else it is skipped or
 * the file uploaded corrected. Asked at the confirm, as `ImportCodeChanges` is; attributes are read
 * as bringing in reads them, and a product whose attributes are not all decided yet is asked about as
 * a name.
 */
final readonly class ImportAttributeChanges
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
     * @return list<string> the ids of those that would change their catalog product's attributes as they may not
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
            $file = $row->effective();
            $given = self::attributes($file, $references);
            $held = $this->products->variantAttributes((string) $row->conflictProductId);
            sort($held);

            if ($given === null || $given === $held) {
                continue;
            }

            $codes = array_map(static fn (FileVariant $variant): string => $variant->code, $file->variants);
            $unnamed = array_filter($this->variants->ofProduct((string) $row->conflictProductId), static fn ($variant): bool => ! in_array($variant->code()->value, $codes, true));

            if ($row->decision === ImportProduct::UPDATE || $unnamed !== []) {
                $found[] = $row->id;
            }
        }

        return $found;
    }

    /**
     * The attributes the file's first variant names, as the catalog's ids, sorted — or null when one
     * is not the catalog's yet.
     *
     * @return list<string>|null
     */
    private static function attributes(FileProduct $file, ImportReferences $references): ?array
    {
        $ids = [];

        try {
            foreach (array_keys(($file->variants[0] ?? null)->values ?? []) as $attribute) {
                $id = $references->attribute((string) $attribute);

                if ($id === null) {
                    return null;
                }

                $ids[] = $id;
            }
        } catch (ImportUndecided) {
            return null;
        }

        sort($ids);

        return $ids;
    }
}
