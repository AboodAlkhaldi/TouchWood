<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A region, a city or a district on its level's page (platform.md §4.3).
 */
#[TypeScript]
final class PlaceRow extends Data
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public string $otherName,
        public string $nameAr,
        public string $nameEn,
        /** Its own mark; what is above it is the page's. */
        public bool $retired,
        /** In use under it: a region's cities, and a region's or a city's districts (0 for a district). */
        public int $cities,
        public int $districts,
    ) {}
}
