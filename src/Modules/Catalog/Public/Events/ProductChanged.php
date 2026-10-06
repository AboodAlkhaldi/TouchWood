<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A product's shared data changed — its details, a variant, its photos, words, filter values or relations (catalog.md §6.1).
 * Ids only, sent after the change commits.
 */
final readonly class ProductChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
