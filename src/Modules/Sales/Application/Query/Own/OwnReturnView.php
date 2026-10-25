<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * The customer's own return (sales.md §3 ViewOwnReturn): its order's number, where it stands, the reason they
 * gave (their own words with "Other"), staff's words when refused, its times, the refund once completed, its
 * lines and how many photos it holds.
 */
final readonly class OwnReturnView
{
    /**
     * @param  list<ReturnLineView>  $lines
     */
    public function __construct(
        public string $returnId,
        public string $orderNumber,
        public string $status,
        public string $reason,
        public ?string $reasonOther,
        public ?string $staffReason,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $decidedAt,
        public ?DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $completedAt,
        public ?Money $refundDue,
        public array $lines,
        public int $photos,
    ) {}
}
