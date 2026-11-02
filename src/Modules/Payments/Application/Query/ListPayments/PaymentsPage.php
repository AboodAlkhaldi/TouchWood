<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ListPayments;

/**
 * A page of the list (payments.md §4.2): `needingAttention` and its count only on the first page.
 */
final readonly class PaymentsPage
{
    /**
     * @param  list<PaymentRow>  $needingAttention
     * @param  list<PaymentRow>  $rows
     */
    public function __construct(
        public array $needingAttention,
        public int $needingAttentionCount,
        public array $rows,
        public ?string $nextCursor,
    ) {}
}
