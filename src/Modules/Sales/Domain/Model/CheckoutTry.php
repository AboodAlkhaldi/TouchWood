<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;

/**
 * A payment under way (sales.md §9.5 #4): no order exists until it is paid. Everything is reserved under the
 * order's future id, and the order is made from this try's quote and its copies of the address and contact.
 */
final readonly class CheckoutTry
{
    public const string LIVE = 'LIVE';

    public const string PAID = 'PAID';

    public const string RELEASED = 'RELEASED';

    /**
     * @param  array<string, mixed>|null  $address  gone once the try ends
     * @param  array<string, mixed>|null  $contact  gone once the try ends
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $customerId,
        public string $cartId,
        public int $cartVersion,
        public string $quoteId,
        public string $orderId,
        public string $attemptId,
        public string $sessionId,
        public string $state,
        public int $amount,
        public string $currencyCode,
        public DateTimeImmutable $windowEndsAt,
        public ?int $refundDue,
        public ?array $address,
        public ?array $contact,
        public ?DateTimeImmutable $shortOfStockAt,
        public bool $needsAttention,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $endedAt,
        public ?DateTimeImmutable $seenAt = null,
    ) {}

    public function isLive(): bool
    {
        return $this->state === self::LIVE;
    }
}
