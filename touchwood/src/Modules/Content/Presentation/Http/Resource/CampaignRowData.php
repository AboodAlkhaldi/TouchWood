<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One campaign on W4's list in a store (content.md §4.4): its names, that store's run - state, dates, notice - every
 * store's state, its theme's name, and its own badge, one state or Mixed (§4.1).
 */
#[TypeScript]
final class CampaignRowData extends Data
{
    /**
     * @param  list<RunStateData>  $stores
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public string $runId,
        public string $state,
        public string $overall,
        public ?string $startsAt,
        public ?string $endsAt,
        public array $stores,
        public ?string $themeAr,
        public ?string $themeEn,
        public ?CampaignNoticeData $notice,
        public int $version,
    ) {}
}
