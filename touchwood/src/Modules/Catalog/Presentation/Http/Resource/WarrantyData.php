<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A warranty (catalog.md §1.9, §4.4 S6): its period — null for life — and its terms written back as
 * the plain text the form edits (P1).
 */
#[TypeScript]
final class WarrantyData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public ?int $periodMonths,
        public string $termsAr,
        public string $termsEn,
        public bool $active,
        public int $products,
    ) {}
}
