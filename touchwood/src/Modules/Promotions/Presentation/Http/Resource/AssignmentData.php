<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A customer an assigned coupon was given to (promotions.md §4.1 M2, Customers): when, from which
 * segment (null once that segment is gone), and how often they used it - removable only while unused.
 */
#[TypeScript]
final class AssignmentData extends Data
{
    public function __construct(
        public CustomerNameData $customer,
        public string $assignedAt,
        public ?string $segment,
        public int $uses,
    ) {}
}
