<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RefreshSegments;

/** The nightly refresh of every store's segments (promotions.md §1.5): the system only. */
final readonly class RefreshSegments
{
    public function __construct(
        public int $page = 100,
    ) {}
}
