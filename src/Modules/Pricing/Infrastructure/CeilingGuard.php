<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure;

use Modules\Platform\Public\Contracts\SettingGuard;
use Modules\Pricing\Application\Prices\CeilingCheck;
use Modules\Pricing\Domain\Exception\CeilingBelowSales;
use Modules\Pricing\Domain\Repository\PricingLocks;
use Modules\Pricing\Domain\ValueObject\Sale;
use Shared\Domain\ValueObject\StoreId;

/**
 * Holds back a lower discount ceiling while sales or category discounts running or scheduled go deeper
 * than it (pricing.md §1.12; owner, 2026-10-13), naming them. A higher one, or the same, always passes.
 */
final readonly class CeilingGuard implements SettingGuard
{
    public function __construct(
        private CeilingCheck $check,
        private PricingLocks $locks,
    ) {}

    public function check(string $key, ?StoreId $store, mixed $from, mixed $to): void
    {
        if ($store === null || ! is_int($to) || (is_int($from) && $to >= $from)) {
            return;
        }

        // The store's pricing lock, so a sale or a retail price saved meanwhile is seen here - and one
        // waiting for it reads the new ceiling. The setting is locked first, never the other way round.
        $this->locks->lockStore($store);
        $over = $this->check->salesOver($store, $to);
        $discounts = $this->check->discountsOver($store, $to);

        if ($over !== [] || $discounts !== []) {
            throw new CeilingBelowSales([...array_map(static fn (Sale $sale): string => $sale->id, $over), ...array_keys($discounts)], $this->check->namesFor($over, $discounts));
        }
    }
}
