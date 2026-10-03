<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Model\AttributeValue;

/**
 * Which value of each variant-making attribute a variant is (catalog.md §1.2, §1.7): **exactly one
 * of every attribute of the product's set**, kept in the set's order, so two variants with the same
 * values have the same combination and the database's unique index sees it. A product without a set
 * has one variant at most, whose combination is empty.
 *
 * Values are given as rows read now, so the rules are checked against what they are: each belongs to
 * its attribute, and a value newly chosen is active — one the variant already has may stay after it
 * was deactivated, as a set keeps a member.
 */
final readonly class Combination
{
    /**
     * @param  array<string, string>  $valueIds  attribute id => value id, in the set's order
     */
    private function __construct(
        public array $valueIds,
    ) {}

    /**
     * @param  list<string>  $attributeIds  the set's attributes, in its order
     * @param  array<string, AttributeValue>  $chosen  attribute id => the value chosen for it
     * @param  list<string>  $held  the value ids the variant has now
     *
     * @throws InvalidCatalogAttribute|ListItemInactive
     */
    public static function of(array $attributeIds, array $chosen, array $held = []): self
    {
        $valueIds = [];

        foreach ($attributeIds as $attributeId) {
            $value = $chosen[$attributeId] ?? throw new InvalidCatalogAttribute('values', "one value of every attribute of the product's set");

            if ($value->attributeId() !== $attributeId) {
                throw new InvalidCatalogAttribute('values', 'each value of its own attribute');
            }

            if (! $value->isActive() && ! in_array($value->id(), $held, true)) {
                throw new ListItemInactive;
            }

            $valueIds[$attributeId] = $value->id();
        }

        return new self($valueIds);
    }

    /**
     * @param  array<string, string>  $valueIds  attribute id => value id, in the set's order
     */
    public static function reconstitute(array $valueIds): self
    {
        return new self($valueIds);
    }

    /** The key the unique index compares: the value ids in the set's order. */
    public function key(): string
    {
        return implode(',', $this->valueIds);
    }
}
