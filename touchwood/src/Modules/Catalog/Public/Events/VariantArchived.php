<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A variant was archived on its own: Inactive in every store (catalog.md §1.2, §6.1).
 * Ids only, sent after the change commits.
 */
final readonly class VariantArchived implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public string $variantId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
