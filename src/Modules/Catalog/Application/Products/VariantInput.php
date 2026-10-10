<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Domain\Exception\CodeTaken;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\Combination;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Domain\ValueObject\VariantDetail;
use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * A variant's form as it arrives — its values, its details, its code — read against the rows they
 * name, inside the product's change and with those rows locked (catalog.md §1.2, §1.7).
 */
final readonly class VariantInput
{
    /** More details than any variant shows, and a ceiling on what one request walks through. */
    public const int MAX_DETAILS = 100;

    public function __construct(
        private ProductReferences $references,
        private AttributeRepository $attributes,
        private ProductRepository $products,
        private VariantRepository $variants,
    ) {}

    /**
     * The variant's values: one of every attribute of the product's set, none for a product without
     * one (it then has a single variant).
     *
     * @param  array<array-key, mixed>  $values  attribute id => value id, as the request sent them
     * @param  list<string>  $held  the value ids the variant has now
     *
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound
     */
    public function combination(Product $product, array $values, array $held = []): Combination
    {
        $set = $this->references->attributeSet($product->attributeSetId(), $product->attributeSetId());
        $members = $set === null ? [] : $this->references->members($set);

        if (count($values) > count($members)) {
            throw new InvalidCatalogAttribute('values', "only the attributes of the product's set");
        }

        $chosen = [];

        foreach ($values as $attributeId => $valueId) {
            if (! is_string($valueId)) {
                throw new InvalidCatalogAttribute('values', 'values, by their ids');
            }

            $value = $this->attributes->valueById($valueId) ?? throw new ListItemNotFound($valueId);
            $chosen[strtolower((string) $attributeId)] = $value;
        }

        return Combination::of(array_map(static fn (Attribute $attribute): string => $attribute->id(), $members), $chosen, $held);
    }

    /**
     * The variant's details: for each "details only" attribute, text in both languages or a number.
     *
     * @param  array<array-key, mixed>  $details  attribute id => ['text_ar' => …, 'text_en' => …] or ['number' => …]
     * @param  list<string>  $held  the attribute ids the variant has details of now
     * @return array<string, VariantDetail>
     *
     * @throws InvalidCatalogAttribute|ListItemInactive|ListItemNotFound
     */
    public function details(array $details, array $held = []): array
    {
        if (count($details) > self::MAX_DETAILS) {
            throw new InvalidCatalogAttribute('details', 'at most '.self::MAX_DETAILS);
        }

        $kept = [];

        foreach ($details as $attributeId => $detail) {
            $attribute = $this->attributes->byId((string) $attributeId) ?? throw new ListItemNotFound((string) $attributeId);

            if ($attribute->kind() !== AttributeKind::Informational) {
                throw new InvalidCatalogAttribute('details', 'details of attributes shown in the details only');
            }

            if (! $attribute->isActive() && ! in_array($attribute->id(), $held, true)) {
                throw new ListItemInactive;
            }

            $kept[$attribute->id()] = self::detail($detail);
        }

        return $kept;
    }

    /**
     * A code free for a variant of this product that does not carry it yet: **one no variant carries
     * now** (amendment 16(a)), and one no other product holds — now or once (amendment 3(e)); one the
     * product held before is taken back.
     *
     * @throws CodeTaken
     */
    public function freeCode(Product $product, ProductCode $code): ProductCode
    {
        $holder = $this->products->codeHolder($code->value);

        if ($holder !== null && ($holder !== $product->id() || $this->variants->codeInUse($product->id(), $code->value))) {
            throw new CodeTaken($code->value);
        }

        return $code;
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function detail(mixed $detail): VariantDetail
    {
        if (is_array($detail) && array_keys($detail) === ['number'] && is_string($detail['number'])) {
            return VariantDetail::number($detail['number']);
        }

        if (is_array($detail) && count($detail) === 2 && is_string($detail['text_ar'] ?? null) && is_string($detail['text_en'] ?? null)) {
            return VariantDetail::text($detail['text_ar'], $detail['text_en']);
        }

        throw new InvalidCatalogAttribute('details', 'text in both languages, or a number');
    }
}
