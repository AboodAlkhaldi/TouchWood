<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * The store's discount ceiling (promotions.md §1.6): Pricing's setting since 2026-10-13, read through
 * Platform's settings — Promotions owns no number of its own (owner, 2026-10-17).
 */
final readonly class Ceiling
{
    /** Pricing's key (`PricingSettings::MAX_DISCOUNT_PERCENT`), named here as Promotions may not read Pricing's inside. */
    public const string KEY = 'pricing.ceiling.max_discount_percent';

    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function percentIn(StoreId $store): int
    {
        return $this->platform->setting(self::KEY, $store)->int();
    }
}
