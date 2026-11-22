<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSubscribers;

/** A store's newsletter list, a page at a time (ops.md §1.9, §4): `after` is the cursor the page before gave. */
final readonly class ListSubscribers
{
    public function __construct(
        public string $storeId,
        public ?string $after = null,
    ) {}
}
