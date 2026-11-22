<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The customer an order is for, by name only; "Deleted customer" once anonymized (§3.2). */
#[TypeScript]
final class OrderCustomerData extends Data
{
    public function __construct(
        public string $id,
        public ?string $name,
        public bool $deleted,
    ) {}
}
