<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\DismissCartNotice;

/** Close on the cart's notice of the lines that left at a sign-in (sales.md §4.1 S4, §4.2 B17). */
final readonly class DismissCartNotice
{
    public function __construct(
        public string $storeId,
    ) {}
}
