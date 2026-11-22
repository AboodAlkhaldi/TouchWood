<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A segment as M3 shows it (promotions.md §4.1): named in the page's language, both names kept for its
 * Edit… dialog; `type` STATIC or DYNAMIC, `audience` INDIVIDUAL or COMPANY; a rule's kind and numbers
 * (null for a segment picked by hand); `refreshedAt` null until first filled.
 */
#[TypeScript]
final class SegmentRowData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $nameAr,
        public string $nameEn,
        public string $type,
        public string $audience,
        public ?string $rule,
        public ?int $ruleMonths,
        public ?int $ruleOrders,
        public int $memberCount,
        public ?string $refreshedAt,
    ) {}
}
