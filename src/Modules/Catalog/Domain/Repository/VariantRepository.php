<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\Variant;

/**
 * Variants with their values and details (catalog.md §1.2, §5.1), changed under
 * `ListLocks::PRODUCTS`.
 */
interface VariantRepository
{
    public function nextId(): string;

    public function find(string $variantId): ?Variant;

    /** Read again, its row locked, inside a change. */
    public function byId(string $variantId): ?Variant;

    /**
     * @return list<Variant> by position, then id
     */
    public function ofProduct(string $productId): array;

    public function hasAny(string $productId): bool;

    /** Inserts the variant with its values and details. */
    public function add(Variant $variant): void;

    /** Writes the variant, its values and details as they now are. */
    public function update(Variant $variant): void;

    public function delete(string $variantId): void;

    /** Whether another variant of the product has this combination, archived ones included (§1.2). */
    public function combinationTaken(string $productId, string $combination, ?string $exceptVariantId = null): bool;

    /** Whether any variant of the product other than this one carries the code. */
    public function codeInUse(string $productId, string $code, ?string $exceptVariantId = null): bool;

    /** Renames a code on every variant of the product carrying it (a correction, amendment 3(e)). */
    public function renameCode(string $productId, string $from, string $to): void;

    public function anyWithValue(string $valueId): bool;

    /** Whether any variant takes a value of the attribute, or a detail of it. */
    public function anyWithAttribute(string $attributeId): bool;
}
