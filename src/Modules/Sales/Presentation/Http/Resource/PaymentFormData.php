<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What the page needs to draw MyFatoorah's form (payments.md §1.3). */
#[TypeScript]
final class PaymentFormData extends Data
{
    public function __construct(
        public string $sessionId,
        public string $scriptUrl,
    ) {}
}
