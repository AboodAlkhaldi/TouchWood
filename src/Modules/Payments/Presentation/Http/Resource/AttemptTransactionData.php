<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of MyFatoorah's transactions on an attempt, as it reported it (payments.md §4.2). */
#[TypeScript]
final class AttemptTransactionData extends Data
{
    public function __construct(
        public string $id,
        public string $gatewayPaymentId,
        public string $status,
        public bool $succeeded,
        public string $method,
        public ?string $cardBrand,
        public ?string $amount,
        public ?string $paidAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public bool $mismatch,
        public bool $paidTwice,
        public string $seenAt,
    ) {}
}
