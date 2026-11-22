<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The staff company list (b2b.md §3.2, §4.6): the companies of the reader's stores, waiting ones
 * first. Who appears is B2B's answer (ListCompanies); this only carries it.
 */
#[TypeScript]
final class StaffCompanyListPage extends Data
{
    /**
     * @param  list<StaffCompanyRowData>  $companies  waiting first, the oldest sent first
     * @param  list<string>  $statuses  the four statuses the list may be filtered by
     * @param  list<StaffStoreOptionData>  $stores  the stores the reader may filter by - a Super
     *                                              Admin's off ones too, marked Off (amendment 30)
     */
    public function __construct(
        public array $companies,
        public int $total,
        public int $page,
        public int $perPage,
        public ?string $search,
        public ?string $status,
        public ?string $storeId,
        public array $statuses,
        public array $stores,
        /** The filtered store's zone, for the moments on the page; null for every store (frontend.md §1.10). */
        public ?string $storeTimezone,
    ) {}
}
