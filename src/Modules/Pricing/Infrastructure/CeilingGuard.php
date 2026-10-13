<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure;

use Modules\Platform\Public\Contracts\SettingGuard;
use Modules\Pricing\Application\Prices\CeilingCheck;
use Modules\Pricing\Domain\Exception\CeilingBelowSales;
use Shared\Domain\ValueObject\StoreId;

/**
 * Holds back a lower discount ceiling while sales running or scheduled go deeper than it (pricing.md
 * §1.12; owner, 2026-10-13), naming them. A higher one, or the same, always passes.
 */
final readonly class CeilingGuard implements SettingGuard
{
    public function __construct(
        private CeilingCheck $check,
    ) {}

    public function check(string $key, ?StoreId $store, mixed $from, mixed $to): void
    {
        if ($store === null || ! is_int($to) || (is_int($from) && $to >= $from)) {
            return;
        }

        $over = $this->check->salesOver($store, $to);

        if ($over !== []) {
            throw new CeilingBelowSales($over);
        }
    }
}
