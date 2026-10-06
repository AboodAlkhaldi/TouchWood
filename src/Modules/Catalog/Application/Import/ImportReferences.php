<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Domain\Exception\ImportUndecided;

/**
 * **What each name of a product means, once decided** (catalog.md §1.12, page part 1; amendment 7):
 * the catalog's own first — matched as the file's names are —, then the Super Admin's decision: the
 * one it means, the one made for it, or refused (null). What the page picked by id stands for the
 * name. A name neither in the catalog nor decided cannot be brought in (`ImportUndecided`).
 */
final readonly class ImportReferences
{
    /**
     * @param  array<string, ImportName>  $names  kind and key => the decision
     * @param  array<string, string>  $created  kind and key => the list item made for it
     */
    public function __construct(
        private CatalogNames $catalog,
        private array $names,
        private array $created,
        private string $defaultBrandId,
    ) {}

    /** A refused brand, or none, is the default brand (amendment 7(a)). */
    public function brand(FileProduct $product): string
    {
        return $this->givenBrand($product) ?? $this->defaultBrandId;
    }

    /** The brand the file gave, as decided — null when it gave none or it was refused. */
    public function givenBrand(FileProduct $product): ?string
    {
        if ($product->brandNumber !== null) {
            return $this->catalog->brandNumbered($product->brandNumber) ?? $this->decided(ImportNameRow::BRAND, ["#{$product->brandNumber}"]);
        }

        return $product->brand === null ? null : $this->catalog->brand($product->brand) ?? $this->decided(ImportNameRow::BRAND, [$product->brand]);
    }

    public function category(FileProduct $product): ?string
    {
        if ($product->categoryId !== null || $product->category === null) {
            return $product->categoryId;
        }

        return $this->catalog->category($product->category) ?? $this->decided(ImportNameRow::CATEGORY, $product->category);
    }

    public function warranty(FileProduct $product): ?string
    {
        if ($product->warrantyId !== null || $product->warranty === null) {
            return $product->warrantyId;
        }

        return $this->catalog->warranty($product->warranty) ?? $this->decided(ImportNameRow::WARRANTY, [$product->warranty]);
    }

    public function set(string $name): ?string
    {
        return $this->catalog->set($name) ?? $this->decided(ImportNameRow::SET, [$name]);
    }

    public function attribute(string $name): ?string
    {
        return $this->catalog->attribute($name)?->id() ?? $this->decided(ImportNameRow::ATTRIBUTE, [$name]);
    }

    /**
     * A value of an attribute the file names: the attribute's own value of that name, or as decided.
     */
    public function value(string $attribute, string $attributeId, string $name): ?string
    {
        return $this->catalog->value($attributeId, $name) ?? $this->decided(ImportNameRow::VALUE, [$attribute, $name]);
    }

    /**
     * @param  list<string>  $names  what keyOf() reads
     *
     * @throws ImportUndecided
     */
    private function decided(string $kind, array $names): ?string
    {
        $key = $kind.' '.ImportNameRow::keyOf($names);
        $name = $this->names[$key] ?? throw new ImportUndecided(1, 0);

        return match ($name->decision) {
            ImportName::EXISTING => $name->targetId,
            ImportName::CREATE => $this->created[$key] ?? null,
            ImportName::REFUSE => null,
            default => throw new ImportUndecided(1, 0),
        };
    }
}
