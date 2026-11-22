<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use DateTimeImmutable;
use Modules\Sales\Public\Enums\ReturnReason;
use Shared\Domain\ValueObject\Money;

/**
 * A return as staff see it (sales.md §3 ViewReturn): its order, the customer by name, where it stands with every
 * moment and who decided, the customer's reason and words, staff's refusal words, its lines with what came and
 * what went back into stock, its photos (opened with `OpenOrderFile`), the refund and whether it carried the
 * shipping.
 */
final readonly class StaffReturnView
{
    /**
     * @param  list<array{orderLineId: string, variantId: string, nameAr: string, nameEn: string, values: list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>, code: string, gift: bool, asked: int, received: ?int, restocked: ?int}>  $lines
     * @param  list<array{mediaId: string, position: int}>  $photos
     */
    public function __construct(
        public string $returnId,
        public string $orderId,
        public string $orderNumber,
        public string $storeId,
        public CustomerName $customer,
        public string $status,
        public ReturnReason $reason,
        public ?string $reasonOther,
        public ?string $staffReason,
        public DateTimeImmutable $requestedAt,
        public ?string $decidedBy,
        public ?DateTimeImmutable $decidedAt,
        public ?DateTimeImmutable $withdrawnAt,
        public ?DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $closedAt,
        public ?DateTimeImmutable $completedAt,
        public ?Money $refundDue,
        public bool $shippingRefunded,
        public array $lines,
        public array $photos,
        public int $exponent,
    ) {}
}
