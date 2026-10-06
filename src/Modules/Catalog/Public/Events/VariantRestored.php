<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An archived variant was restored (catalog.md §1.2, §6.1).
 * Ids only, sent after the change commits.
 */
final readonly class VariantRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public string $variantId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
