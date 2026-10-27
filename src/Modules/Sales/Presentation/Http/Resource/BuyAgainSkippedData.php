<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A line Buy Again could not put in the cart, and why (§4.1 S8; R2-24). */
#[TypeScript]
final class BuyAgainSkippedData extends Data
{
    public function __construct(
        public string $name,
        public string $mode,
        public int $quantity,
        public string $reason,
    ) {}
}
