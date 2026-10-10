<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A brand on the brands screen (catalog.md §1.6, §4.4 S1): its fixed number, its look, its marks, and
 * its description written back as the plain text the form edits (P1).
 */
#[TypeScript]
final class BrandData extends Data
{
    public function __construct(
        public string $id,
        public int $number,
        public string $nameAr,
        public string $nameEn,
        public ?string $slugAr,
        public ?string $slugEn,
        /** HOUSE, EXCLUSIVE_AGENT or DISTRIBUTOR. */
        public string $agencyType,
        public bool $showInDefaultListings,
        public bool $isDefault,
        public bool $active,
        public int $position,
        public ?string $originCountry,
        public ?string $logoMediaId,
        /** The logo's thumbnail, once its sizes are ready. */
        public ?string $logo,
        public string $descriptionAr,
        public string $descriptionEn,
        public int $products,
    ) {}
}
