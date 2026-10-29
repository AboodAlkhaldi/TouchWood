<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The store a country ships from, named on E8 and in the Stores page's picker.
 */
#[TypeScript]
final class PlaceStoreRow extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $active,
    ) {}
}
