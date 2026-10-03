<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Model\AttributeSet;
use Modules\Catalog\Domain\Model\AttributeValue;
use Modules\Catalog\Domain\ValueObject\LocalizedName;

/**
 * Attributes, their values and the attribute sets: one library (catalog.md §1.7), changed under one
 * lock (`ListLocks::ATTRIBUTES`).
 */
interface AttributeRepository
{
    public function nextId(): string;

    public function find(string $attributeId): ?Attribute;

    public function byId(string $attributeId): ?Attribute;

    public function add(Attribute $attribute): void;

    public function update(Attribute $attribute): void;

    /** Removes the attribute with its values. */
    public function delete(string $attributeId): void;

    /**
     * @return list<Attribute>
     */
    public function all(): array;

    public function hasValues(string $attributeId): bool;

    /** Whether any attribute set holds it. */
    public function inSets(string $attributeId): bool;

    public function findValue(string $valueId): ?AttributeValue;

    public function valueById(string $valueId): ?AttributeValue;

    /**
     * Whether another value of the attribute has this name in either language, ignoring letter
     * case — "Black" and "black" are one value (owner, 2026-10-02).
     */
    public function valueNameTaken(string $attributeId, LocalizedName $name, ?string $exceptValueId = null): bool;

    public function addValue(AttributeValue $value): void;

    public function updateValue(AttributeValue $value): void;

    public function deleteValue(string $valueId): void;

    /**
     * @return list<AttributeValue> by position and then name
     */
    public function valuesOf(string $attributeId): array;

    public function findSet(string $setId): ?AttributeSet;

    public function setById(string $setId): ?AttributeSet;

    public function addSet(AttributeSet $set): void;

    public function updateSet(AttributeSet $set): void;

    public function deleteSet(string $setId): void;

    /**
     * @return list<AttributeSet>
     */
    public function sets(): array;
}
