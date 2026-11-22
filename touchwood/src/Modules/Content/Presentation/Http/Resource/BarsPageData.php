<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W2 · Bars (content.md §4.4): the store's announcement bar and its smart bars. A smart bar on a campaign picks one of
 * the store's scheduled or live runs, which arrive with campaigns (plan PR 6): until then none is offered.
 */
#[TypeScript]
final class BarsPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  list<SmartBarData>  $smartBars
     * @param  list<CategoryChoiceData>  $categories
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        /** The store's zone, which the page's moments are typed and shown in. */
        public string $storeTimezone,
        /** `announcement` or `smart`. */
        public string $tab,
        public BarData $bar,
        public array $smartBars,
        public array $categories,
        /** Whether Translate can work on this server (§1.3). */
        public bool $translationReady,
    ) {}
}
