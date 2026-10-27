<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A size ticked "reduced in the provider" (§1.5): who and when. */
#[TypeScript]
final class OrderTickData extends Data
{
    public function __construct(
        public string $variantId,
        public ?string $by,
        public string $at,
    ) {}
}
