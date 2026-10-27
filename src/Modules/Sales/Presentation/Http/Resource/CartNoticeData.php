<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A line that left the cart at a sign-in, and why (§4.1 S4; R2-8). */
#[TypeScript]
final class CartNoticeData extends Data
{
    public function __construct(
        public string $variantId,
        public string $name,
        public string $values,
        public string $mode,
        public int $quantity,
        public string $reason,
    ) {}
}
