<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ListPayments;

use DateTimeImmutable;

/**
 * One attempt in the list (payments.md §4.2). `method` as MyFatoorah's answer gave it, null with no answer; `orderNumber`
 * null for a try not paid ("No order"); `reasons` why it needs attention, in the words of `PaymentRow::*`, for the
 * screen to say in full.
 */
final readonly class PaymentRow
{
    public const string MISMATCH = 'MISMATCH';

    public const string PAID_TWICE = 'PAID_TWICE';

    public const string CURRENCY_REFUSED = 'CURRENCY_REFUSED';

    /** Paid with no order behind it: a success after release (a refund due), or a paid try not yet made an order. */
    public const string PAID_NO_ORDER = 'PAID_NO_ORDER';

    /** A success on a request that had ended, on an order that exists. */
    public const string LATE_SUCCESS = 'LATE_SUCCESS';

    public const string NO_ANSWER = 'NO_ANSWER';

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $attemptId,
        public string $storeId,
        public string $ownerId,
        public string $ownerKind,
        public string $orderId,
        public ?string $orderNumber,
        public ?string $customerName,
        public int $amountMinor,
        public string $currencyCode,
        public ?string $method,
        public string $state,
        public string $mode,
        public bool $needsAttention,
        public bool $mismatch,
        public bool $paidTwice,
        public bool $currencyRefused,
        public bool $nothingCharged,
        public DateTimeImmutable $createdAt,
        public array $reasons = [],
    ) {}

    public function isTest(): bool
    {
        return $this->mode === 'TEST';
    }

    public function withOrder(?string $orderNumber): self
    {
        return new self(
            $this->attemptId, $this->storeId, $this->ownerId, $this->ownerKind, $this->orderId, $orderNumber, $this->customerName, $this->amountMinor, $this->currencyCode,
            $this->method, $this->state, $this->mode, $this->needsAttention, $this->mismatch, $this->paidTwice, $this->currencyRefused, $this->nothingCharged, $this->createdAt,
            $this->needsAttention ? $this->reasonsWith($orderNumber) : [],
        );
    }

    /**
     * @return list<string>
     */
    private function reasonsWith(?string $orderNumber): array
    {
        $reasons = [];

        if ($this->currencyRefused) {
            $reasons[] = self::CURRENCY_REFUSED;
        } elseif ($this->mismatch) {
            $reasons[] = self::MISMATCH;
        }

        if ($this->paidTwice) {
            $reasons[] = self::PAID_TWICE;
        }

        if ($reasons !== []) {
            return $reasons;
        }

        if ($this->state === 'SUCCEEDED') {
            return [$orderNumber === null ? self::PAID_NO_ORDER : self::LATE_SUCCESS];
        }

        return [self::NO_ANSWER];
    }
}
