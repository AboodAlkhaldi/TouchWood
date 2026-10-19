<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Domain\ValueObject\Band;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * `pricing.wholesale_bands` (pricing.md §5): every query names its store (catalog.md amendment 4(h)).
 */
final readonly class DatabaseWholesaleBands implements WholesaleBands
{
    private const string TABLE = 'pricing.wholesale_bands';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function forVariants(StoreId $store, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $bands = [];

        foreach ($this->db->table(self::TABLE)->where('store_id', $store->value)->whereIn('variant_id', $variantIds)->orderBy('variant_id')->orderBy('from_quantity')->get() as $row) {
            $bands[(string) $row->variant_id][] = new Band((int) $row->from_quantity, Money::of((int) $row->amount_minor, (string) $row->currency_code));
        }

        return $bands;
    }

    public function inStores(array $stores, array $variantIds): array
    {
        if ($stores === [] || $variantIds === []) {
            return [];
        }

        $bands = [];

        foreach ($this->db->table(self::TABLE)
            ->whereIn('store_id', array_map(static fn (StoreId $store): string => $store->value, $stores))
            ->whereIn('variant_id', $variantIds)
            ->orderBy('store_id')->orderBy('variant_id')->orderBy('from_quantity')
            ->get() as $row) {
            $bands[(string) $row->store_id][(string) $row->variant_id][] = new Band((int) $row->from_quantity, Money::of((int) $row->amount_minor, (string) $row->currency_code));
        }

        return $bands;
    }

    public function variantsWithBands(StoreId $store): array
    {
        return array_values(array_map('strval', $this->db->table(self::TABLE)->where('store_id', $store->value)->distinct()->orderBy('variant_id')->pluck('variant_id')->all()));
    }

    public function replace(StoreId $store, string $variantId, array $bands): void
    {
        $this->db->table(self::TABLE)->where('store_id', $store->value)->where('variant_id', $variantId)->delete();

        if ($bands === []) {
            return;
        }

        $this->db->table(self::TABLE)->insert(array_map(static fn (Band $band): array => [
            'store_id' => $store->value,
            'variant_id' => $variantId,
            'from_quantity' => $band->fromQuantity,
            'amount_minor' => $band->amount->minorUnits,
            'currency_code' => $band->amount->currencyCode,
        ], $bands));
    }
}
