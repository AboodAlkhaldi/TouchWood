<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One theme on W5's list (content.md §4.4): its names, stores with each state, its dates as typed, how many of its 11
 * parts are filled and its badge, one state or Mixed.
 */
#[TypeScript]
final class ThemeRowData extends Data
{
    /**
     * @param  list<RunStateData>  $stores
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public array $stores,
        public string $startsLocal,
        public string $endsLocal,
        public ?string $zone,
        public int $parts,
        public string $state,
        public int $version,
    ) {}
}
