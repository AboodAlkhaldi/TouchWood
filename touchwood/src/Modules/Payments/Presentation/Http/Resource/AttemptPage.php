<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `/admin/payments/{id}` (payments.md §4.2, Q20, Q21): the attempt, the customer's details for staff who may read them
 * (§9.14 #11), its transactions and calls, and Ask the Gateway Now while it is open or unknown.
 */
#[TypeScript]
final class AttemptPage extends Data
{
    /**
     * @param  list<AttemptTransactionData>  $transactions
     * @param  list<AttemptCallData>  $calls
     */
    public function __construct(
        public PaymentRowData $attempt,
        public bool $isTest,
        public ?string $contactName,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public bool $mayReadContact,
        public array $transactions,
        public array $calls,
        public bool $canAsk,
        public bool $refundDue,
        public string $timezone,
    ) {}
}
