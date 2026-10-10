<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An attribute (catalog.md §1.7, §4.4 S3): its job and what locks it, whether a variation holds it,
 * and whether anything uses it.
 */
#[TypeScript]
final class AttributeData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        /** INFORMATIONAL, FILTERABLE or VARIANT. */
        public string $kind,
        public ?string $unitAr,
        public ?string $unitEn,
        public bool $isColour,
        public bool $active,
        public int $position,
        public int $values,
        /** Its job and colour stay as they are: it has values, or variants carry details of it. */
        public bool $kindLocked,
        public bool $inVariation,
        public bool $inUse,
    ) {}
}
