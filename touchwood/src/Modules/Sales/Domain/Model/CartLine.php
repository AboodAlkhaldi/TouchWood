<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;
use Modules\Catalog\Public\Enums\SaleMode;

/** A line of a cart (sales.md §1.1): a size, how it is bought, and how many. */
final readonly class CartLine
{
    /** The most of one line, as the table holds it. */
    public const int MOST = 1_000_000;

    public function __construct(
        public string $variantId,
        public SaleMode $mode,
        public int $quantity,
        public DateTimeImmutable $addedAt,
    ) {}
}
