<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewInbox;

/** A page of my bell, newest first (ops.md §1.2); `after` the cursor the page before gave. */
final readonly class ViewInbox
{
    public function __construct(
        public string $locale,
        public ?string $after = null,
    ) {}
}
