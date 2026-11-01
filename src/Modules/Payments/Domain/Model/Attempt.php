<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentOwnerKind;

/**
 * One charge with one session - one invoice (payments.md §4, §5). Changed only under its row's lock, so two
 * notices, a notice and a page, or two workers settle it once (rule 6).
 */
final readonly class Attempt
{
    public function __construct(
        public string $id,
        public string $storeId,
        public PaymentOwnerKind $ownerKind,
        public string $ownerId,
        public string $orderId,
        public string $buyerId,
        public string $sessionId,
        public string $keyId,
        public PaymentMode $mode,
        public string $currencyCode,
        public int $amountMinor,
        public AttemptState $state,
        public string $idempotencyKey,
        public string $locale,
        public string $returnUrl,
        public DateTimeImmutable $windowEndsAt,
        public DateTimeImmutable $waitEndsAt,
        public DateTimeImmutable $holdEndsAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $chargeClaimedAt = null,
        public ?string $invoiceId = null,
        public ?string $gatewayPaymentId = null,
        public ?string $frameUrl = null,
        public ?DateTimeImmutable $nextCheckAt = null,
        public ?DateTimeImmutable $lastCheckedAt = null,
        public ?DateTimeImmutable $succeededAt = null,
        public ?int $paidMinor = null,
        public bool $mismatch = false,
        public bool $paidTwice = false,
        public bool $needsAttention = false,
        public bool $nothingCharged = false,
        public ?string $refusedReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  property => its new value
     */
    public function with(array $changes): self
    {
        /** @var self */
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
