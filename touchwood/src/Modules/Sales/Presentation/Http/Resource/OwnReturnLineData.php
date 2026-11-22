<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A line of the customer's return (§4.1 S10): the pieces asked, and received once they came. */
#[TypeScript]
final class OwnReturnLineData extends Data
{
    public function __construct(
        public string $name,
        public string $values,
        public string $code,
        public bool $gift,
        public int $asked,
        public ?int $received,
    ) {}
}
