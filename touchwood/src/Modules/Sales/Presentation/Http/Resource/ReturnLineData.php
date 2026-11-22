<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A line of a return (§1.8): the part's name in the page's language, its size and code, the pieces asked, received and back in stock. */
#[TypeScript]
final class ReturnLineData extends Data
{
    public function __construct(
        public string $orderLineId,
        public string $name,
        public string $values,
        public string $code,
        public bool $gift,
        public int $asked,
        public ?int $received,
        public ?int $restocked,
    ) {}
}
