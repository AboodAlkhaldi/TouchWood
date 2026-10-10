<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The attributes screen (catalog.md §4.4 S3).
 */
#[TypeScript]
final class AttributesPage extends Data
{
    /**
     * @param  list<AttributeData>  $attributes
     */
    public function __construct(
        public array $attributes,
        public bool $mayChange,
    ) {}
}
