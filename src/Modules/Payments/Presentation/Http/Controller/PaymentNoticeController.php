<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Payments\Application\Notice\NoticeOutcome;
use Modules\Payments\Application\Notice\NoticeReceiver;

/**
 * The gateway's notice (payments.md §1.2, §1.8, §7): always answered with no body - 200 taken, 401 refused,
 * 413 too large, 429 over the limit, which MyFatoorah tries again.
 */
final readonly class PaymentNoticeController
{
    public const int CALLS_A_MINUTE = 120;

    /** One address's calls a minute, for every store: MyFatoorah notifying all our stores fits. */
    public const int CALLS_A_MINUTE_AN_ADDRESS = 600;

    public const int MOST_BYTES = 100_000;

    public function __construct(
        private NoticeReceiver $receiver,
    ) {}

    public function __invoke(Request $request, string $storeId, string $mode): Response
    {
        // Every call counts, signed or not: from one address first, so made-up store ids cannot be sprayed.
        foreach (['payments-notice-ip:'.$request->ip() => self::CALLS_A_MINUTE_AN_ADDRESS, 'payments-notice:'.strtolower($storeId) => self::CALLS_A_MINUTE] as $key => $most) {
            if (RateLimiter::tooManyAttempts($key, $most)) {
                return response()->noContent(429)->header('Retry-After', (string) RateLimiter::availableIn($key));
            }

            RateLimiter::hit($key, 60);
        }

        if ((int) $request->header('Content-Length', '0') > self::MOST_BYTES || strlen($request->getContent()) > self::MOST_BYTES) {
            return response()->noContent(413);
        }

        $outcome = $this->receiver->receive($storeId, $mode, $request->header('MyFatoorah-Signature'), $request->getContent());

        return response()->noContent($outcome === NoticeOutcome::Accepted ? 200 : 401);
    }
}
