<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The search words screen (catalog.md §4.4 S7): the shared word pairs, and — for someone holding the
 * job with All stores — the searches that found nothing, every store's or one's, a page at a time.
 */
#[TypeScript]
final class SearchWordsPage extends Data
{
    /**
     * @param  list<WordPairData>  $pairs
     * @param  list<NoResultSearchData>|null  $searches  null for a reader who may not read them
     * @param  list<StoreOptionData>  $stores  the store filter of the searches
     */
    public function __construct(
        public array $pairs,
        public bool $mayChange,
        public ?array $searches,
        public int $page,
        public bool $more,
        public ?string $storeCode,
        public array $stores,
        /** The chosen store's zone for the moments shown, else none: the base store's (frontend.md §1.10). */
        public ?string $storeTimezone,
    ) {}
}
