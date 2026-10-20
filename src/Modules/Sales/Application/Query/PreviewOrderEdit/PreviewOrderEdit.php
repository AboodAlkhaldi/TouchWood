<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PreviewOrderEdit;

/** A staff edit worked out before it is confirmed (sales.md §1.7): what it would come to. */
final readonly class PreviewOrderEdit
{
    /**
     * @param  list<array{variantId: string, mode: string, quantity: int}>  $lines  the order's lines, gift aside
     */
    public function __construct(
        public string $orderId,
        public array $lines,
        public ?string $addressId = null,
        public string $gift = 'KEEP',
    ) {}
}
