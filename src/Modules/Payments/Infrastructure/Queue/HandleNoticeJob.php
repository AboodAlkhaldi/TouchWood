<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Payments\Application\Notice\NoticeHandling;

/**
 * A kept notice makes us ask the gateway (payments.md §1.1 rule 1), after answering MyFatoorah at once.
 */
final class HandleNoticeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries; the hourly look takes up what is left unhandled. */
    public int $tries = 3;

    /** As long as `CheckAttemptJob`: it may ask the gateway three times. */
    public int $timeout = 150;

    public function __construct(
        public readonly string $noticeId,
    ) {}

    public function handle(NoticeHandling $handling): void
    {
        $handling->handle($this->noticeId);
    }
}
