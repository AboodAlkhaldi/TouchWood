<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListNotices;

/** A store's offer notices, newest first, a page at a time (ops.md §1.7, §4): `after` is the cursor the page before gave. */
final readonly class ListNotices
{
    public function __construct(
        public string $storeId,
        public ?string $after = null,
    ) {}
}
