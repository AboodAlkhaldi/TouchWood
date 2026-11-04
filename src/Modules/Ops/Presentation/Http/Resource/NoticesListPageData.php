<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Offer Notices (ops.md §4.15 O5): the store's page of notices and the stores on offer. */
#[TypeScript]
final class NoticesListPageData extends Data
{
    /**
     * @param  list<NoticeData>  $notices
     * @param  list<StoreChoiceData>  $stores
     */
    public function __construct(
        public array $notices,
        public ?string $next,
        public array $stores,
        public string $storeCode,
        /** The store's zone, named beside the moments its notices show. */
        public string $timezone,
    ) {}
}
