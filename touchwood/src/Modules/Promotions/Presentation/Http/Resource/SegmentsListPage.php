<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * M3 · Marketing › Segments (promotions.md §4.1): the store's segments, newest first, 50 at a time; the
 * cursor null on the last page. `mayManage` offers New Segment… (Q16).
 */
#[TypeScript]
final class SegmentsListPage extends Data
{
    /**
     * @param  list<SegmentRowData>  $segments
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public array $segments,
        public ?string $nextAt,
        public ?string $nextId,
        public bool $mayManage,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
        public array $stores,
    ) {}
}
