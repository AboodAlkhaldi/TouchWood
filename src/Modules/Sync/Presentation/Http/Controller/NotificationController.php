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
 * with no body - 202 its read queued, 401 refused, 413 too large, 422 not a list of codes, 429 more than
 * 60 calls a minute for the store (the hourly read covers what is refused). Nothing here calls the
 * provider.
 */
final readonly class NotificationController
{
    public const int CALLS_A_MINUTE = 60;

    /** One address's calls a minute, for every store: one provider notifying all its stores fits. */
    public const int CALLS_A_MINUTE_AN_ADDRESS = 600;

    public const int MOST_BYTES = 300_000;

    public function __construct(
        private NotificationReceiver $receiver,
    ) {}

    public function __invoke(Request $request, string $storeId): Response
    {
        // Every call counts, signed or not: a flood is turned away before any work - from one address
        // first, so made-up store ids cannot be sprayed, then for the store.
        foreach (['sync-notify-ip:'.$request->ip() => self::CALLS_A_MINUTE_AN_ADDRESS, 'sync-notify:'.strtolower($storeId) => self::CALLS_A_MINUTE] as $key => $most) {
            if (RateLimiter::tooManyAttempts($key, $most)) {
                return response()->noContent(429)->header('Retry-After', (string) RateLimiter::availableIn($key));
            }

            RateLimiter::hit($key, 60);
        }

        // 1,000 codes of 255 characters come to about 262 KB: anything larger is not a notice.
        if ((int) $request->header('Content-Length', '0') > self::MOST_BYTES || strlen($request->getContent()) > self::MOST_BYTES) {
            return response()->noContent(413);
        }

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
