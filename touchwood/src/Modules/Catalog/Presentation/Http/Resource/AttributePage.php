<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One attribute's page (catalog.md §4.4 S3): its details, then its values in order.
 */
#[TypeScript]
final class AttributePage extends Data
{
    /**
     * @param  list<ValueData>  $values
     */
    public function __construct(
        public AttributeData $attribute,
        public array $values,
        public bool $mayChange,
    ) {}
}
