<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One region, city or district the address form offers (frontend.md §3.6, F9 on Platform's places).
 *
 * `name` is the page's language; `otherName` the other one, searched too, so a customer who types
 * "Jeddah" on the Arabic page still finds جدة.
 */
#[TypeScript]
final class PlaceOption extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $otherName,
    ) {}
}
