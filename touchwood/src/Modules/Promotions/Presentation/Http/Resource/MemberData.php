<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A segment's member (promotions.md §1.5, §4.1 M3): the name only, and since when; `member` on a
 * hand-picker's result says it is already in the segment.
 */
#[TypeScript]
final class MemberData extends Data
{
    public function __construct(
        public CustomerNameData $customer,
        public ?string $addedAt,
        public bool $member,
    ) {}
}
