<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A request's page (payments.md §4.2, Q12): the order, read as the order's page reads it (sales.md §9.9 #1). No request
 * open on it means "This payment link has ended."; `payment` is the one still open on it, so no form.
 */
#[TypeScript]
final class PayRequestPage extends Data
{
    public function __construct(
        public OwnOrderPage $order,
        public ?CheckoutTryData $payment,
    ) {}
}
