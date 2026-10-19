<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A segment an assigned coupon may be given to (promotions.md §4.1 M2, owner's Q14): only those whose
 * audience the coupon allows; `memberCount` for the dialog's "gives it to its N members now".
 */
#[TypeScript]
final class SegmentChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $audience,
        public int $memberCount,
    ) {}
}
