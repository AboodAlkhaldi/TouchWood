<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The theme a campaign links to, as its page shows it (content.md §1.7 #7): its name, dates, state, its 11 parts each
 * with a filled mark, and store by store whether its dates match.
 */
#[TypeScript]
final class CampaignThemeData extends Data
{
    /**
     * @param  list<ThemePartFillData>  $parts
     * @param  list<ThemeDateMatchData>  $dates
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public string $startsLocal,
        public string $endsLocal,
        public string $state,
        public array $parts,
        public array $dates,
    ) {}
}
