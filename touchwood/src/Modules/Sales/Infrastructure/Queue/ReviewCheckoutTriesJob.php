<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sales\Application\Checkout\TryReview;

/** The hourly look at the tries past their window (§9.5 #4). */
final class ReviewCheckoutTriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3): the next hour's run takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public int $timeout = 300;

    public function handle(TryReview $review): void
    {
        $review->run();
    }
}
