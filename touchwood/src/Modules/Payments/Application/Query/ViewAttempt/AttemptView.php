<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ViewAttempt;

use Modules\Payments\Application\Query\ListPayments\PaymentRow;

/**
 * An attempt's page (payments.md §4.2). `contact` null for staff who may not read customers. `canAsk`: open or unknown,
 * so "Ask the Gateway Now" is offered.
 */
final readonly class AttemptView
{
    /**
     * @param  array{name: ?string, email: ?string, phone: ?string}|null  $contact
     * @param  list<AttemptTransactionRow>  $transactions
     * @param  list<AttemptCallRow>  $calls
     */
    public function __construct(
        public PaymentRow $attempt,
        public ?array $contact,
        public array $transactions,
        public array $calls,
        public bool $canAsk,
    ) {}
}
