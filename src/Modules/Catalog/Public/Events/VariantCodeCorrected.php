<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A variant's mistyped code was corrected: the provider's feed and the import match it again (catalog.md §1.2, §6.1).
 * Ids only, sent after the change commits.
 */
final readonly class VariantCodeCorrected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public string $variantId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
