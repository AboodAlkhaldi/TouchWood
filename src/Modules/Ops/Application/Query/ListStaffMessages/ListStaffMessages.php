<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListStaffMessages;

/** A store's staff messages, newest first, a page at a time (ops.md §1.8, §4): `after` is the cursor the page before gave. */
final readonly class ListStaffMessages
{
    public function __construct(
        public string $storeId,
        public ?string $after = null,
    ) {}
}
