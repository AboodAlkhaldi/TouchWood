<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentRequestStateDto;
use Modules\Sales\Domain\Exception\PaymentBeingConfirmed;

/**
 * What a payment request means for its order (payments.md §1.5). **The order's lock is always taken first**, then
 * Payments locks the request, so one lock settles every race: `free` and `withdrawn` are called under it.
 */
final readonly class OrderRequests
{
    public function __construct(private PaymentsApi $payments) {}

    /** The order's open request, if it still asks what the order is missing: an amount since changed is an ended link. */
    public function live(string $orderId, int $missing): ?PaymentRequestStateDto
    {
        if ($missing <= 0) {
            return null;
        }

        $open = $this->payments->openRequest($orderId);

        return $open !== null && $open->amount->minorUnits === $missing ? $open : null;
    }

    /** @throws PaymentBeingConfirmed */
    public function free(string $orderId): void
    {
        if ($this->payments->paymentOpen($orderId)) {
            throw new PaymentBeingConfirmed;
        }
    }

    /**
     * The open request withdrawn: it no longer matches, or the money came another way (§1.5).
     *
     * @throws PaymentBeingConfirmed
     */
    public function withdrawn(string $orderId): void
    {
        if (! $this->payments->withdraw($orderId)) {
            throw new PaymentBeingConfirmed;
        }
    }
}
