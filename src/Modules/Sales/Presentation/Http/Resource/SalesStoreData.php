<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A store on the order screens' filter (sales.md §1.12): its code, name in the page's language, and whether it is on. */
#[TypeScript]
final class SalesStoreData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive,
    ) {}
}
