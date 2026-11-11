<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store's run as W4b shows it (content.md §4.4): its state and moments, its prices and whether they may still
 * change, the reader's rights there, the store's ceiling and currency, the sizes it skips now, its notice and the
 * store's drafts.
 */
#[TypeScript]
final class CampaignRunData extends Data
{
    /**
     * @param  list<CampaignTargetData>  $skipped
     * @param  list<CampaignNoticeData>  $drafts
     */
    public function __construct(
        public string $id,
        public ContentStoreData $store,
        public string $state,
        public string $startsAt,
        public string $endsAt,
        public bool $pricesOpen,
        public bool $mayPrice,
        public bool $mayNotices,
        public ?int $ceilingPercent,
        public string $currency,
        public int $decimals,
        public ?CampaignPricesData $prices,
        public array $skipped,
        public ?CampaignNoticeData $notice,
        public array $drafts,
    ) {}
}
