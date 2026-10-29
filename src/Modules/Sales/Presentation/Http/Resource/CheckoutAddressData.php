<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of the account's addresses in this store, to deliver to (§4.1 S5 1): one the store's format no longer takes cannot be chosen. */
#[TypeScript]
final class CheckoutAddressData extends Data
{
    public function __construct(
        public string $id,
        public string $label,
        public string $recipientName,
        public string $formatted,
        public bool $isDefault,
        public bool $isComplete,
    ) {}
}
