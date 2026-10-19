<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * M3 · a segment's page (promotions.md §4.1): its facts; its members, 50 at a time by customer, the
 * cursor null on the last page; and - for a hand-picked segment a manager is picking for - what the
 * search found (`candidates`, the search's text, `candidatesMore` when it found more than shown).
 */
#[TypeScript]
final class SegmentPage extends Data
{
    /**
     * @param  list<MemberData>  $members
     * @param  list<MemberData>  $candidates
     */
    public function __construct(
        public SegmentRowData $segment,
        public array $members,
        public ?string $nextMember,
        public array $candidates,
        public ?string $search,
        public bool $candidatesMore,
        public bool $mayManage,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
    ) {}
}
