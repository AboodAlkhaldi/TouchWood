<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W4b, a campaign's page (content.md §4.4): New Campaign, or one that exists with each store's run, the linked
 * theme's card and the themes on offer.
 */
#[TypeScript]
final class CampaignFormPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores  where the reader holds the campaign job
     * @param  list<CampaignRunData>  $runs
     * @param  list<NamedChoiceData>  $themes
     */
    public function __construct(
        public array $stores,
        public ?CampaignData $campaign,
        public array $runs,
        public ?CampaignThemeData $theme,
        public array $themes,
        public bool $mayOpenThemes,
        public bool $translationReady,
    ) {}
}
