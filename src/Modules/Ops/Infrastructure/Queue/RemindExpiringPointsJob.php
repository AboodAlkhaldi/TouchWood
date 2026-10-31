<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Infrastructure\Delivery\ExpiringPointsReminder;

/**
 * The nightly reminder before points expire (ops.md §1.5 C14): every store's customers whose lots expire
 * a week from today, in the store's own day. Each message goes once whatever runs it again
 * (ExpiringPointsReminder), so a retry carries on where a failure stopped.
 */
final class RemindExpiringPointsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three attempts over about 35 minutes: the database away. */
    public int $tries = 3;

    /** @var list<int> seconds before each retry */
    public array $backoff = [300, 1800];

    public int $uniqueFor = 3600;

    public function handle(ExpiringPointsReminder $reminder): void
    {
        $reminder->remind(CarbonImmutable::now()->toDateTimeImmutable());
    }
}
