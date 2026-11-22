<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ListPayments;

/**
 * The Payments list (payments.md §4.2). No store: every store the staff member may see. `state` an attempt's (§4),
 * `mode` `TEST` or `LIVE`, `after` the last list's `nextCursor`.
 */
final readonly class ListPayments
{
    public function __construct(
        public ?string $storeId = null,
        public ?string $state = null,
        public ?string $mode = null,
        public ?string $after = null,
    ) {}
}
