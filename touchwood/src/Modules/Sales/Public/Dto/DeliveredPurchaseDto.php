<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use DateTimeImmutable;

/**
 * A product delivered to a customer (sales.md §2.1): the variant delivered last and when, and whether
 * any of the product came back in a completed return.
 */
final readonly class DeliveredPurchaseDto
{
    public function __construct(
        public string $variantId,
        public DateTimeImmutable $deliveredAt,
        public bool $returned,
    ) {}
}
