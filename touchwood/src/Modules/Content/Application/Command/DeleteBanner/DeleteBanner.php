<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteBanner;

/** **Delete Banner…** (content.md §1.1 rule 11, W3): a draft only, for good. */
final readonly class DeleteBanner
{
    public function __construct(
        public string $bannerId,
    ) {}
}
