<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sync\Application\Notifications\NotificationOutcome;
use Modules\Sync\Application\Notifications\NotificationReceiver;

/**
 * The provider calls this when a price or a stock number changes (sync.md §1.3): answered at once and
 * with no body - 202 its read queued, 401 refused, 422 not a list of codes, 429 more than 60 calls a
 * minute for the store (the hourly read covers what is refused). Nothing here calls the provider.
 */
final readonly class NotificationController
{
    public const int CALLS_A_MINUTE = 60;

    public function __construct(
        private NotificationReceiver $receiver,
    ) {}

    public function __invoke(Request $request, string $storeId): Response
    {
        // Every call counts, signed or not: a flood is turned away before any work.
        $key = 'sync-notify:'.strtolower($storeId);

        if (RateLimiter::tooManyAttempts($key, self::CALLS_A_MINUTE)) {
            return response()->noContent(429);
        }

        RateLimiter::hit($key, 60);

        $outcome = $this->receiver->receive(
            $storeId,
            $request->header('X-TouchWood-Timestamp'),
            $request->header('X-TouchWood-Signature'),
            $request->getContent(),
        );

        return response()->noContent(match ($outcome) {
            NotificationOutcome::Queued => 202,
            NotificationOutcome::Refused => 401,
            NotificationOutcome::Invalid => 422,
        });
    }
}
