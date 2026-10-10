<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One variant as its product's Variants tab shows it (catalog.md §4.4 S9).
 */
#[TypeScript]
final class VariantData extends Data
{
    /**
     * @param  list<VariantValueData>  $values
     * @param  list<VariantDetailData>  $details
     * @param  list<PhotoData>  $photos
     */
    public function __construct(
        public string $id,
        public string $code,
        public int $position,
        public bool $archived,
        public array $values,
        public array $details,
        public ?int $weightGrams,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
        public array $photos,
    ) {}
}
