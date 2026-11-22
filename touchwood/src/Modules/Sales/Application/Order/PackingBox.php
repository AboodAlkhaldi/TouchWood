<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Sales\Application\Query\Own\OrderLineView;
use Modules\Shipping\Public\Contracts\ShippingApi;
use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Modules\Shipping\Public\Dto\ParcelLineDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * The box Shipping suggests for an order's pieces, as they stand — the gift packed like any piece
 * (shipping.md §1.7; gap 5.1 (a)): worked out each time the staff order page or the packing slip is
 * shown, never stored, so never stale — from the lines the page read already. Null when the store has no
 * box switched on, or nothing to pack.
 */
final readonly class PackingBox
{
    public function __construct(
        private ShippingApi $shipping,
    ) {}

    /** @param  list<OrderLineView>  $lines  the order's lines as they stand, the gift among them */
    public function of(string $storeId, array $lines): ?BoxSuggestionDto
    {
        $pieces = [];

        foreach ($lines as $line) {
            $pieces[$line->variantId] = ($pieces[$line->variantId] ?? 0) + $line->quantity;
        }

        return $pieces === [] ? null : $this->shipping->suggestBox(StoreId::fromString($storeId), array_map(
            static fn (string $variantId, int $quantity): ParcelLineDto => new ParcelLineDto($variantId, $quantity),
            array_keys($pieces),
            array_values($pieces),
        ));
    }
}
