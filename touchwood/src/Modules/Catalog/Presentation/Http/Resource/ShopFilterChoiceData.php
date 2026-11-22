<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One choice in a category page's filters (P14): how many products picking it would show, and
 * whether it is picked.
 */
#[TypeScript]
final class ShopFilterChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $swatch,
        public int $count,
        public bool $chosen,
    ) {}
}
