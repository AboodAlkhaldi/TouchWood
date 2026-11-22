<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

use Modules\Payments\Application\Query\ListPayments\PaymentFilters;
use Modules\Payments\Application\Query\ListPayments\PaymentRow;
use Modules\Payments\Application\Query\ViewAttempt\AttemptCallRow;
use Modules\Payments\Application\Query\ViewAttempt\AttemptTransactionRow;

/**
 * What staff read of online payments (payments.md §4.2): one query per list, whatever its length.
 */
interface PaymentReads
{
    /**
     * Newest first, those needing attention left out: they have their own block.
     *
     * @return list<PaymentRow>
     */
    public function rows(PaymentFilters $filters, ?string $after, int $most): array;

    /**
     * Those needing attention, newest first.
     *
     * @return list<PaymentRow>
     */
    public function needingAttention(PaymentFilters $filters, int $most): array;

    public function needingAttentionCount(PaymentFilters $filters): int;

    public function row(string $attemptId): ?PaymentRow;

    /**
     * @return array{name: ?string, email: ?string, phone: ?string}|null
     */
    public function contact(string $attemptId): ?array;

    /**
     * Oldest first.
     *
     * @return list<AttemptTransactionRow>
     */
    public function transactions(string $attemptId): array;

    /**
     * Oldest first.
     *
     * @return list<AttemptCallRow>
     */
    public function calls(string $attemptId): array;
}
