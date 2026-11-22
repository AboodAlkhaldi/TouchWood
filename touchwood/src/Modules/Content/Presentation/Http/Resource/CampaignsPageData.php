<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W4 · Campaigns (content.md §4.4): one store's campaigns by its run's state, a tab, with each tab's count, 50 at a
 * time.
 */
#[TypeScript]
final class CampaignsPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  array<string, int>  $counts  tab => how many
     * @param  list<CampaignRowData>  $campaigns
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        public string $storeTimezone,
        public string $tab,
        public array $counts,
        public array $campaigns,
        public ?string $next,
    ) {}
}
