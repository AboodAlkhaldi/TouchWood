<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Domain\Repository\SentMessages;

/**
 * Meta later said a message it took **failed** (ops.md §9.8 points 3-4): the row of that provider id turns from SENT
 * to FAILED. Queued by the webhook, which answered 200 at once. Idempotent: a status Meta sends twice, or for a row
 * already failed, changes nothing, because only SENT turns. Delivered and read never come here: they change nothing.
 * The id is Meta's, no person's.
 *
 * **A status that beats the row** (review L5): the code's row is QUEUED with no id until the call has returned and
 * `markSent` has committed, so a very fast `failed` may find nothing. When no row holds the id at all, the job is
 * released for a few seconds and looks again, a bounded number of times (WAITS); an id that no row ever holds is then
 * let go - it is not ours, or its row is gone.
 */
final class HandleWhatsAppStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** The looks at an id no row holds yet, the first run included, 5 s, 10 s, 20 s and 40 s apart. */
    public const int WAITS = 5;

    /** One run more than the waits, so the last look is a run of its own, never a release past the tries. */
    public int $tries = 6;

    /** @var list<int> seconds before each retry after a failure of the database */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public string $providerId,
    ) {}

    public function handle(SentMessages $sent): void
    {
        if ($sent->markFailedByProviderId($this->providerId) || $sent->hasProviderId($this->providerId)) {
            return;
        }

        $attempt = $this->attempts();

        if ($attempt < self::WAITS) {
            $this->release(5 * (2 ** ($attempt - 1)));
        }
    }
}
