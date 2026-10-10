<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A category was put under another parent (catalog.md §6.1, amendment 16(i)): what
 * `CatalogApi::productIdsInCategory` answers for it, and for every category above it, old and new,
 * has changed — Pricing keeps its category discounts expanded. It names a category, not a product, so
 * it is sent whatever stage the products in it are at. Ids only, sent after the change commits.
 */
final readonly class CategoryMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $categoryId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
