<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\MarkTypeListsReviewed;

/**
 * A store's admins saying they looked at both lists and nothing needs changing (b2b.md §1.3, §3.2).
 */
final readonly class MarkTypeListsReviewed
{
    public function __construct(
        public string $storeId,
    ) {}
}
