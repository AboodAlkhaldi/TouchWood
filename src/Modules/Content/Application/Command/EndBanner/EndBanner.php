<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\EndBanner;

/** **End Now…** (content.md §4.1, W3): a live banner ends at this moment, and stays listed as history. */
final readonly class EndBanner
{
    public function __construct(
        public string $bannerId,
    ) {}
}
