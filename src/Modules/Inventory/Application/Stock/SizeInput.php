<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;

/**
 * The sizes a change names, as callers write them, checked in two reads however many (catalog.md
 * amendment 16(i)): each must exist and be neither archived nor of an archived product - an archived
 * size is read-only in Inventory until it is restored, its stock kept as it is (owner, 2026-10-11).
 */
final readonly class SizeInput
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * @param  list<string>  $given
     * @return array<string, array{string, string}> each id as given => [the size's own id, its product's id]
     *
     * @throws InvalidInventoryInput
     */
    public function check(array $given): array
    {
        [$own, $archived, $notFound] = $this->sort($given);

        if ($archived !== [] || $notFound !== []) {
            throw new InvalidInventoryInput('variant');
        }

        return $own;
    }

    /**
     * The same reads, for a change that writes what it can and names the rest - the provider's
     * numbers (§2.5): the sizes that may be written, the archived ones (the size or its product), and
     * those that do not exist.
     *
     * @param  list<string>  $given
     * @return array{array<string, array{string, string}>, list<string>, list<string>} [each writable id as given => [its own id, its product's id], archived ids as given, ids as given not found]
     */
    public function sort(array $given): array
    {
        $variants = $this->catalog->variants($given);
        $products = $this->catalog->products(array_values(array_unique(array_map(
            static fn (VariantDto $variant): string => $variant->productId,
            array_values($variants),
        ))));
        $own = $archived = $notFound = [];

        foreach ($given as $id) {
            // Keyed by the size's own id, as Catalog writes it: lower case, no spaces around.
            $variant = $variants[strtolower(trim($id))] ?? null;
            $product = $variant === null ? null : ($products[$variant->productId] ?? null);

            if ($variant === null || $product === null) {
                $notFound[] = $id;
            } elseif ($variant->isArchived || $product->stage === ProductStage::Archived) {
                $archived[] = $id;
            } else {
                $own[$id] = [$variant->id, $variant->productId];
            }
        }

        return [$own, $archived, $notFound];
    }
}
