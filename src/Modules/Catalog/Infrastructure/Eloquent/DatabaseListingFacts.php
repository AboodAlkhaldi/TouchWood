<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Dto\ListingPrice;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `ListingFacts`, received (catalog.md §2.2, §5.2; amendments 15, 16(h), 16(i)): each fact pushed is
 * kept in `catalog.store_variant_facts`, Catalog's own table, inside the caller's transaction, so a
 * listing written again from Catalog's tables never loses it. Only the facts given are written: a
 * price leaves whether the variant is orderable as it was, and the other way round. A variant that
 * is not Catalog's is left out. The cards read these facts with the shop's pages (P22); until then
 * they are kept, not shown.
 */
final readonly class DatabaseListingFacts implements ListingFacts
{
    private const string TABLE = 'catalog.store_variant_facts';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function orderable(StoreId $store, array $variantIds, bool $orderable): void
    {
        $this->write($store, array_fill_keys($variantIds, ['orderable' => $orderable]));
    }

    public function endingSoon(StoreId $store, array $variantIds, bool $endingSoon): void
    {
        $this->write($store, array_fill_keys($variantIds, ['ending_soon' => $endingSoon]));
    }

    public function prices(StoreId $store, array $prices): void
    {
        $this->write($store, array_map(static fn (?ListingPrice $price): array => [
            'price_minor' => $price?->now->minorUnits,
            'price_before_minor' => $price?->before?->minorUnits,
            'currency' => $price?->now->currencyCode,
        ], $prices));
    }

    public function salesRanks(StoreId $store, array $ranks): void
    {
        throw new LogicException("Sales' ranks are kept from stage 6, in a table of their own (catalog.md §5.2): nothing receives them yet.");
    }

    /**
     * @param  array<array-key, array<string, bool|int|string|null>>  $facts  variant id => its columns
     */
    private function write(StoreId $store, array $facts): void
    {
        $byId = [];

        foreach ($facts as $variantId => $columns) {
            $id = strtolower(trim((string) $variantId));

            if (Ulids::valid($id)) {
                $byId[$id] = $columns;
            }
        }

        $known = $byId === [] ? [] : array_map('strval', $this->db->table('catalog.variants')->whereIn('id', array_keys($byId))->pluck('id')->all());

        if ($known === []) {
            return;
        }

        $now = CarbonImmutable::now();
        $rows = array_map(static fn (string $id): array => ['store_id' => $store->value, 'variant_id' => $id, ...$byId[$id], 'created_at' => $now, 'updated_at' => $now], $known);

        // One shape of columns a call: every fact pushed in one call is the same kind.
        $this->db->table(self::TABLE)->upsert($rows, ['store_id', 'variant_id'], [...array_keys($byId[$known[0]]), 'updated_at']);
    }
}
