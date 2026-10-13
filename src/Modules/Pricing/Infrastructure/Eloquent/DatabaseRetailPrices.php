<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\PriceSource;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * `pricing.retail_prices` (pricing.md §5): every query names its store (catalog.md amendment 4(h)).
 */
final readonly class DatabaseRetailPrices implements RetailPrices
{
    private const string TABLE = 'pricing.retail_prices';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function forVariants(StoreId $store, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $prices = [];

        foreach ($this->db->table(self::TABLE)->where('store_id', $store->value)->whereIn('variant_id', $variantIds)->get() as $row) {
            $prices[(string) $row->variant_id] = Money::of((int) $row->amount_minor, (string) $row->currency_code);
        }

        return $prices;
    }

    public function save(StoreId $store, string $variantId, Money $amount, PriceSource $source): void
    {
        $this->db->table(self::TABLE)->upsert([[
            'store_id' => $store->value,
            'variant_id' => $variantId,
            'amount_minor' => $amount->minorUnits,
            'currency_code' => $amount->currencyCode,
            'source' => $source->value,
            'updated_at' => CarbonImmutable::now(),
        ]], ['store_id', 'variant_id'], ['amount_minor', 'currency_code', 'source', 'updated_at']);
    }

    public function remove(StoreId $store, string $variantId): void
    {
        $this->db->table(self::TABLE)->where('store_id', $store->value)->where('variant_id', $variantId)->delete();
    }

    public function pricedVariants(StoreId $store): array
    {
        return array_values(array_map('strval', $this->db->table(self::TABLE)->where('store_id', $store->value)->orderBy('variant_id')->pluck('variant_id')->all()));
    }
}
