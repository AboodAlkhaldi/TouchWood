<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Price;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Shipping\Application\ShippingAudit;
use Modules\Shipping\Domain\Model\Carrier;
use Modules\Shipping\Domain\Model\PriceGroup;
use Shared\Domain\ValueObject\Money;

/**
 * A change of a carrier's prices, audited on the carrier (shipping.md §1.4: who, which carrier, which
 * places, from what amount to what): one attribute per amount that changed - "price 20.00" - from the
 * places it had to the places it has, named in English, the first 30 and how many more. A group whose
 * amount changed reads as one amount emptied and another filled.
 */
final class PriceAudit
{
    private const int NAMED = 30;

    /**
     * @param  array<string, PriceGroup>  $before
     * @param  list<PriceGroup>  $after
     * @return list<AuditEntryDto>
     */
    public static function entries(Carrier $carrier, StoreDto $store, array $before, array $after, PlatformApi $platform): array
    {
        $was = self::byAmount(array_values($before));
        $now = self::byAmount($after);
        $changed = [];

        foreach (array_unique([...array_keys($was), ...array_keys($now)]) as $amount) {
            if (($was[$amount] ?? []) !== ($now[$amount] ?? [])) {
                $changed[] = $amount;
            }
        }

        if ($changed === []) {
            return [];
        }

        $ids = [];

        foreach ($changed as $amount) {
            array_push($ids, ...($was[$amount] ?? []), ...($now[$amount] ?? []));
        }

        $names = [];

        foreach ($platform->place(array_values(array_unique($ids))) as $id => $place) {
            $names[$id] = $place->name->en;
        }

        $from = [];
        $to = [];

        foreach ($changed as $amount) {
            $key = 'price '.Money::of($amount, $store->currencyCode)->format($store->currencyExponent);
            $from[$key] = self::named($was[$amount] ?? [], $names);
            $to[$key] = self::named($now[$amount] ?? [], $names);
        }

        return [ShippingAudit::entry('carrier', 'prices_changed', $carrier->id, $store->id, $from, $to)];
    }

    /**
     * @param  list<PriceGroup>  $groups
     * @return array<int, list<string>> amount => place ids
     */
    private static function byAmount(array $groups): array
    {
        $amounts = [];

        foreach ($groups as $group) {
            if (! $group->isEmpty()) {
                $amounts[$group->amountMinor()] = $group->placeIds();
            }
        }

        return $amounts;
    }

    /**
     * @param  list<string>  $placeIds
     * @param  array<string, string>  $names
     */
    private static function named(array $placeIds, array $names): ?string
    {
        if ($placeIds === []) {
            return null;
        }

        $named = array_map(static fn (string $id): string => $names[$id] ?? $id, $placeIds);
        sort($named);
        $more = count($named) - self::NAMED;

        return implode(', ', array_slice($named, 0, self::NAMED)).($more > 0 ? " and {$more} more" : '');
    }
}
