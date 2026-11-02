<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentState;

/** What the page shows of a try (payments.md §1.4 steps 5–9): the order once it is paid, the times while it is not. */
final readonly class CheckoutTryView
{
    public function __construct(
        public string $tryId,
        public string $state,
        public PaymentState $payment,
        public ?string $orderNumber,
        public ?DateTimeImmutable $windowEndsAt,
        public ?DateTimeImmutable $waitEndsAt,
        public ?DateTimeImmutable $holdEndsAt,
        public bool $nothingCharged,
        public bool $refundDue,
        /** The gateway's code when declined, for the page's plain words. */
        public ?string $declineCode = null,
        /** The first time its ending is shown: the closed store's one page, shown once (payments.md §1.10). */
        public bool $firstSeen = false,
    ) {}
}
