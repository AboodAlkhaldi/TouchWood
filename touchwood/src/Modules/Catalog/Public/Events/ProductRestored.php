<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An archived product is ready again, Inactive everywhere (catalog.md §4.1, §6.1). A draft archived
 * when abandoned comes back a draft, and sends nothing (amendment 3(m)).
 * Ids only, sent after the change commits.
 */
final readonly class ProductRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
