<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A warranty a product may carry (catalog.md §4.4 S9); a new choice takes an active one.
 */
#[TypeScript]
final class WarrantyOptionData extends Data
{
    /**
     * @param  int|null  $periodMonths  none: for life
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public ?int $periodMonths,
        public bool $active,
    ) {}
}
