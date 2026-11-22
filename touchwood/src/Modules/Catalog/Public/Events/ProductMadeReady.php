<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A product left its draft: stores may now choose it (catalog.md §4.1, §6.1).
 * Ids only, sent after the change commits.
 */
final readonly class ProductMadeReady implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
