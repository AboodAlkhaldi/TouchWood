<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a variant's details: an attribute and its value, with its unit.
 */
#[TypeScript]
final class ShopDetailData extends Data
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}
}
