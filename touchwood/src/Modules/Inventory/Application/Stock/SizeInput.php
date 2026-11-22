<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantBriefDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Shared\Domain\ValueObject\StoreId;

/**
 * The sizes a change names, as callers write them, checked in one read however many (catalog.md
 * amendment 23(h), `variantBriefs`): each must exist and be neither archived nor of an archived product
 * - an archived size is read-only in Inventory until it is restored, its stock kept as it is (owner,
 * 2026-10-11). A refusal names every size it is about (stage 5's screens, Q2).
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
        return $this->checked($given, $this->catalog->variantBriefs($given));
    }

    /**
     * **A stocktake's sizes** (stage 5's screens step 4; Add Size, Q24): as `check()`, and a size the store
     * has not switched on is counted only when its product is ready - a draft's size is stocked once the
     * store sells it, or once the product is ready to be picked in Add Size (coordinator's decision,
     * 2026-10-19). A size switched on is counted whatever its product's stage, as before.
     *
     * @param  list<string>  $given
     * @return array<string, array{string, string}>
     *
     * @throws InvalidInventoryInput
     */
    public function checkCount(StoreId $store, array $given): array
    {
        $briefs = $this->catalog->variantBriefs($given);
        $own = $this->checked($given, $briefs);
        $notReady = array_filter($own, static fn (array $size): bool => $briefs[$size[0]]->productStage !== ProductStage::Ready);

        if ($notReady === []) {
            return $own;
        }

        // Asked only when a size's product is not ready: one read of what the store has switched on.
        $on = array_flip($this->catalog->switchedOnVariantIds($store));
        $refused = array_map('strval', array_keys(array_filter($notReady, static fn (array $size): bool => ! isset($on[$size[0]]))));

        if ($refused !== []) {
            throw new InvalidInventoryInput('variant', $refused);
        }

        return $own;
    }

    /**
     * The same read, for a change that writes what it can and names the rest - the provider's
     * numbers (§2.5): the sizes that may be written, the archived ones (the size or its product), and
     * those that do not exist.
     *
     * @param  list<string>  $given
     * @return array{array<string, array{string, string}>, list<string>, list<string>} [each writable id as given => [its own id, its product's id], archived ids as given, ids as given not found]
     */
    public function sort(array $given): array
    {
        return self::sorted($given, $this->catalog->variantBriefs($given));
    }

    /**
     * @param  list<string>  $given
     * @param  array<string, VariantBriefDto>  $briefs
     * @return array<string, array{string, string}>
     *
     * @throws InvalidInventoryInput
     */
    private function checked(array $given, array $briefs): array
    {
        [$own, $archived, $notFound] = self::sorted($given, $briefs);

        // Named, so a screen marks the rows refused (Q2).
        if ($archived !== [] || $notFound !== []) {
            throw new InvalidInventoryInput('variant', [...$archived, ...$notFound]);
        }

        return $own;
    }

    /**
     * @param  list<string>  $given
     * @param  array<string, VariantBriefDto>  $briefs
     * @return array{array<string, array{string, string}>, list<string>, list<string>}
     */
    private static function sorted(array $given, array $briefs): array
    {
        $own = $archived = $notFound = [];

        foreach ($given as $id) {
            // Keyed by the size's own id, as Catalog writes it: lower case, no spaces around.
            $brief = $briefs[strtolower(trim($id))] ?? null;

            if ($brief === null) {
                $notFound[] = $id;
            } elseif ($brief->isArchived || $brief->productStage === ProductStage::Archived) {
                $archived[] = $id;
            } else {
                $own[$id] = [$brief->variantId, $brief->productId];
            }
        }

        return [$own, $archived, $notFound];
    }
}
