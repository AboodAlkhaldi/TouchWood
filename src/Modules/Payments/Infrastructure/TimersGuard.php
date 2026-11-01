<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Application\PaymentsSettings;
use Modules\Payments\Domain\Exception\HoldTooShort;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingGuard;
use Shared\Domain\ValueObject\StoreId;

/**
 * The hold limit always longer than the window and the wait together (payments.md §1.7; owner,
 * 2026-10-29, gap 8.3 (a)): a change of any of the three that would break it is refused. The store's
 * timers' lock is taken first, so two changes of different timers at once cannot each pass alone.
 */
final readonly class TimersGuard implements SettingGuard
{
    public function __construct(
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    public function check(string $key, ?StoreId $store, mixed $from, mixed $to): void
    {
        if (! is_int($to)) {
            return;
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['payments:timers:'.($store === null ? 'all' : $store->value)], false);

        $value = fn (string $setting): int => $setting === $key ? $to : $this->platform->setting($setting, $store)->int();
        $window = $value(PaymentsSettings::WINDOW_MINUTES);
        $wait = $value(PaymentsSettings::WAIT_MINUTES);
        $hold = $value(PaymentsSettings::HOLD_MINUTES);

        if ($window + $wait >= $hold) {
            throw new HoldTooShort($window, $wait, $hold);
        }
    }
}
