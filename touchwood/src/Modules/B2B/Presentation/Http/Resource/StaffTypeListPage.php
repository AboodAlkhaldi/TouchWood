<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The types page (b2b.md §1.3, §4.6): one list of the store chosen in its filter — its company
 * types or its document types — with the store's "copied, not yet reviewed" notice, and what this
 * reader may do to it.
 */
#[TypeScript]
final class StaffTypeListPage extends Data
{
    /**
     * @param  string  $kind  company or document
     * @param  list<StaffStoreOptionData>  $stores  the page's own store filter (amendment 30)
     * @param  list<StaffTypeRowData>  $types  in the form's order
     */
    public function __construct(
        public string $kind,
        public string $storeName,
        /** The store shown, as its filter, its tabs and its actions carry it. */
        public string $storeCode,
        public array $stores,
        /** That store's zone, for the moments on the page (frontend.md §1.10). */
        public ?string $storeTimezone,
        public bool $copiedNotReviewed,
        public array $types,
        public StaffTypeActionsData $actions,
    ) {}
}
