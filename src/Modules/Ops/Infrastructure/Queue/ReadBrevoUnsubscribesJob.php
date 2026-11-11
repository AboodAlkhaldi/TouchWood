<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Infrastructure\External\BrevoUnsubscribeRead;

/**
 * The nightly read of Brevo's unsubscribes (ops.md §9.6 #1), queued by the scheduler at 03:20 Riyadh time: one
 * ReadBrevoListUnsubscribesJob per list kept, each reading its own list, so one list's failure holds up no other and
 * no job runs long. It carries the moment it was queued, which each list keeps as its read's start.
 *
 * **Off unless `services.brevo.nightly_read`** (BREVO_NIGHTLY_READ) is on and the newsletter's driver is Brevo: off,
 * it does nothing, and nothing is called. It stays off until one real run has shown the right people
 * (docs/HANDOFF.md §15.4).
 */
final class ReadBrevoUnsubscribesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try: it only reads the lists' ids, and the next night queues them again. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    /** The moment it was queued, as an ISO 8601 string. */
    public string $runAt;

    public function __construct(?string $runAt = null)
    {
        $this->runAt = $runAt ?? CarbonImmutable::now()->toIso8601String();
    }

    public static function switchedOn(Config $config): bool
    {
        return $config->get('services.brevo.nightly_read') === true && $config->get('ops.newsletter.driver') === 'brevo';
    }

    public function handle(Config $config, BrevoUnsubscribeRead $read): void
    {
        if (! self::switchedOn($config)) {
            return;
        }

        foreach ($read->lists() as $listId) {
            ReadBrevoListUnsubscribesJob::dispatch($listId, $this->runAt);
        }
    }
}
