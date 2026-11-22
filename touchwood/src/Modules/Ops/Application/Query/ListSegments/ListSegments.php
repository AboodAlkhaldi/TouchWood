<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSegments;

/**
 * A store's Promotions segments for one of Ops' forms, newest first, a page at a time (ops.md §4.3, §4.4):
 * `permission` is the form's, `after` the cursor the page before gave.
 */
final readonly class ListSegments
{
    public function __construct(
        public string $storeId,
        public string $permission,
        public ?string $after = null,
    ) {}
}
