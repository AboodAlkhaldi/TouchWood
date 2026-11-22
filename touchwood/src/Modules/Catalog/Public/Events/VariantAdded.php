<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A variant was added — chosen in no store until each store chooses it (catalog.md §1.3, §6.1).
 * Ids only, sent after the change commits.
 */
final readonly class VariantAdded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public string $variantId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
