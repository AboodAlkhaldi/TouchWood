<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\FollowRequestPayment;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentState;

/** What a request's page shows of its payment (payments.md §1.5, §4.2): the same answers and timers as checkout. */
final readonly class RequestPaymentView
{
    public function __construct(
        public string $requestId,
        public PaymentState $payment,
        public ?DateTimeImmutable $windowEndsAt,
        public ?DateTimeImmutable $waitEndsAt,
        public ?DateTimeImmutable $holdEndsAt,
        public bool $nothingCharged,
        /** The gateway's code when declined, for the page's plain words. */
        public ?string $declineCode,
    ) {}
}
