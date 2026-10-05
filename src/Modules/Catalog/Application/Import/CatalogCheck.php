<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * **A product file against the catalog** (catalog.md §1.12), once the file's own checks passed: the
 * names the catalog lacks, each listed once with the products using it, for the import's page — and
 * what no decision on that page could mend, collected as problems that refuse the file:
 *
 * - an attribute used for two jobs in the file (variant values, filters, details), or for a job the
 *   catalog's attribute of that name does not have — an attribute has one job (§1.7);
 * - a category path ending at a category that has sub-categories (the guide, §1.1);
 * - a set the catalog has whose attributes the variants' values do not match; a new set given
 *   different attributes by two products (the guide, §1.2);
 * - a photo that is not JPEG, PNG or WebP, or over the media library's limit (the guide, §1.6);
 * - a product whose codes two of the catalog's products hold: it can update or replace one only.
 *
 * Whether a name it found is still there when the products are brought in is asked again then.
 */
final class CatalogCheck
{
    private const array PHOTO_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string, array{kind: string, written: string, key: string, attribute: string|null, attributeKind: AttributeKind|null, products: array<int, true>, matches: int}> */
    private array $rows = [];

    /** @var array<string, array{kind: AttributeKind, product: int}> an attribute's key => its first use in the file */
    private array $uses = [];

    /** @var array<string, array{attributes: list<string>, product: int}> a new set's key => the attributes its first product gives it */
    private array $newSets = [];

    /** @var array<string, true> each problem once, however many products repeat it */
    private array $reported = [];

    private function __construct(
        private readonly CatalogNames $names,
        private readonly FileProblems $problems,
    ) {}

    /**
     * @param  list<FileProduct>  $products  as the file gave them, or as the page's changes left them
     * @return list<ImportNameRow> in the order the products first use them
     */
    public static function names(array $products, CatalogNames $names, FileProblems $problems): array
    {
        $check = new self($names, $problems);

        foreach ($products as $product) {
            $check->product($product);
        }

        return array_values(array_map(
            static fn (array $row): ImportNameRow => new ImportNameRow($row['kind'], $row['written'], $row['key'], $row['attribute'], $row['attributeKind'], array_keys($row['products']), $row['matches']),
            $check->rows,
        ));
    }

    /**
     * @param  array<string, int>  $files  the zip's files: path => size unpacked
     */
    public static function photos(ProductsFile $file, array $files, int $maxBytes, FileProblems $problems): void
    {
        $limit = number_format($maxBytes / 1048576, $maxBytes % 1048576 === 0 ? 0 : 1).' MB';

        foreach ($file->products as $product) {
            foreach ($product->allPhotos() as $path) {
                if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::PHOTO_TYPES, true)) {
                    $problems->add("product {$product->number} › photos", "{$path}: a JPEG, PNG or WebP photo");
                } elseif (($files[$path] ?? 0) > $maxBytes) {
                    $problems->add("product {$product->number} › photos", "{$path}: at most {$limit}");
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $holders  code => the catalog's product holding it
     * @return array<int, string> product number => the catalog's product already holding its codes
     */
    public static function conflicts(ProductsFile $file, array $holders, FileProblems $problems): array
    {
        $conflicts = [];

        foreach ($file->products as $product) {
            $held = [];

            foreach ($product->codes() as $code) {
                if (isset($holders[$code])) {
                    $held[$holders[$code]][] = $code;
                }
            }

            if (count($held) > 1) {
                $groups = array_map(static fn (array $codes): string => implode(', ', $codes), array_values($held));
                $problems->add("product {$product->number} › variants", 'codes of one catalog product at most, to update or replace it: '.implode(' and ', $groups).' belong to '.count($held).' different products');
            } elseif ($held !== []) {
                $conflicts[$product->number] = (string) array_key_first($held);
            }
        }

        return $conflicts;
    }

    private function product(FileProduct $product): void
    {
        $at = "product {$product->number}";

        // A brand by its number is listed as "#2", apart from any brand named "2".
        if ($product->brandNumber !== null && $this->names->brandNumbered($product->brandNumber) === null) {
            $this->need(ImportNameRow::BRAND, "#{$product->brandNumber}", ["#{$product->brandNumber}"], null, null, $product->number, 0);
        } elseif ($product->brand !== null && $this->names->brand($product->brand) === null) {
            $this->need(ImportNameRow::BRAND, $product->brand, [$product->brand], null, null, $product->number, $this->names->brandMatches($product->brand));
        }

        // What the import's page picked stands for the name (amendment 7(c)): it is checked when brought in.
        if ($product->warrantyId === null && $product->warranty !== null && $this->names->warranty($product->warranty) === null) {
            $this->need(ImportNameRow::WARRANTY, $product->warranty, [$product->warranty], null, null, $product->number, $this->names->warrantyMatches($product->warranty));
        }

        if ($product->categoryId === null && $product->category !== null) {
            $this->category($product->category, "{$at} › category", $product->number);
        }

        if ($product->attributeSet !== null) {
            $this->set($product, $product->attributeSet, $at);
        }

        foreach ($product->variants as $index => $variant) {
            $where = "{$at} › variants ".($index + 1);

            foreach ($variant->values as $attribute => $value) {
                $this->value((string) $attribute, $value, AttributeKind::Variant, "{$where} › values › {$attribute}", $product->number);
            }

            foreach (array_keys($variant->details) as $attribute) {
                $this->attribute((string) $attribute, AttributeKind::Informational, "{$where} › details › {$attribute}", $product->number);
            }
        }

        foreach ($product->filters as $attribute => $values) {
            foreach ($values as $value) {
                $this->value((string) $attribute, $value, AttributeKind::Filterable, "{$at} › filters › {$attribute}", $product->number);
            }
        }
    }

    /**
     * @param  list<string>  $path
     */
    private function category(array $path, string $at, int $number): void
    {
        $id = $this->names->category($path);
        $written = implode(' / ', $path);

        if ($id !== null) {
            if ($this->names->hasChildren($id)) {
                $this->problem($at, "a category with no sub-categories: {$written} has some");
            }

            return;
        }

        // Each level the catalog lacks is its own name to decide, a parent before its children.
        for ($length = 1; $length <= count($path); $length++) {
            $prefix = array_slice($path, 0, $length);

            if ($this->names->category($prefix) === null) {
                $this->need(ImportNameRow::CATEGORY, implode(' / ', $prefix), $prefix, null, null, $number, $this->names->categoryMatches($prefix));
            }
        }
    }

    private function set(FileProduct $product, string $name, string $at): void
    {
        $attributes = array_map('strval', array_keys(($product->variants[0] ?? null)->values ?? []));
        $setId = $this->names->set($name);

        if ($setId === null) {
            $this->need(ImportNameRow::SET, $name, [$name], null, null, $product->number, $this->names->setMatches($name));
            $given = array_map(CatalogNames::key(...), $attributes);
            sort($given);
            $first = $this->newSets[CatalogNames::key($name)] ??= ['attributes' => $given, 'product' => $product->number];

            if ($first['attributes'] !== $given) {
                $this->problem("{$at} › attribute_set", "the same attributes in every product for the new set {$name}: product {$first['product']} gives it others");
            }

            return;
        }

        $members = $this->names->setMembers($setId);

        foreach ($attributes as $attribute) {
            $existing = $this->names->attribute($attribute);

            if ($existing !== null && ! in_array($existing->id(), $members, true)) {
                $this->problem("{$at} › variants › values › {$attribute}", "an attribute of the set {$name}");
            }
        }

        if (count($attributes) !== count($members)) {
            $this->problem("{$at} › variants › values", "one value for each of the {$name} set's ".count($members).' attributes');
        }
    }

    private function value(string $attribute, string $value, AttributeKind $kind, string $at, int $number): void
    {
        $attributeId = $this->attribute($attribute, $kind, $at, $number);

        if ($attributeId === null || $this->names->value($attributeId, $value) === null) {
            $this->need(ImportNameRow::VALUE, $value, [$attribute, $value], $attribute, null, $number, $attributeId === null ? 0 : $this->names->valueMatches($attributeId, $value));
        }
    }

    /**
     * @return string|null the catalog's attribute of that name, when it has one doing this job
     */
    private function attribute(string $name, AttributeKind $kind, string $at, int $number): ?string
    {
        $key = CatalogNames::key($name);
        $first = $this->uses[$key] ??= ['kind' => $kind, 'product' => $number];

        if ($first['kind'] !== $kind) {
            $this->problem($at, "{$name} in one place only — values, filters or details — as in product {$first['product']}: an attribute has one job", 'file '.$key);

            return null;
        }

        $existing = $this->names->attribute($name);

        if ($existing === null) {
            $this->need(ImportNameRow::ATTRIBUTE, $name, [$name], null, $kind, $number, $this->names->attributeMatches($name));

            return null;
        }

        if ($existing->kind() !== $kind) {
            $this->problem($at, "{$name} in ".self::place($existing->kind()).', where the catalog uses it: an attribute has one job', 'catalog '.$key);

            return null;
        }

        return $existing->id();
    }

    /**
     * @param  list<string>  $names  what keyOf() reads
     */
    private function need(string $kind, string $written, array $names, ?string $attribute, ?AttributeKind $attributeKind, int $number, int $matches): void
    {
        $key = ImportNameRow::keyOf($names);
        $this->rows[$kind.' '.$key] ??= ['kind' => $kind, 'written' => $written, 'key' => $key, 'attribute' => $attribute, 'attributeKind' => $attributeKind, 'products' => [], 'matches' => $matches];
        $this->rows[$kind.' '.$key]['products'][$number] = true;
    }

    /**
     * Each problem once: by where and what, or by $once when every product would repeat it.
     */
    private function problem(string $at, string $problem, ?string $once = null): void
    {
        $seen = $once ?? $at."\x1F".$problem;

        if (! isset($this->reported[$seen])) {
            $this->reported[$seen] = true;
            $this->problems->add($at, $problem);
        }
    }

    private static function place(AttributeKind $kind): string
    {
        return match ($kind) {
            AttributeKind::Variant => 'values',
            AttributeKind::Filterable => 'filters',
            AttributeKind::Informational => 'details',
        };
    }
}
