<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Public\Contracts\SalesApi;
use Modules\Sales\Public\Dto\DeliveredPurchaseDto;
use Modules\Sales\Public\Dto\ReturnedProductsDto;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/**
 * What Sales tells other modules of what was sold (sales.md §2.1) — reads only. A gift line never counts:
 * nobody bought it (feedback.md §9.1b, 20).
 */
final readonly class DatabaseSalesApi implements SalesApi
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function deliveredPurchase(string $customerId, string $productId): ?DeliveredPurchaseDto
    {
        $customerId = strtolower(trim($customerId));
        $productId = strtolower(trim($productId));

        if (! Ulids::valid($customerId) || ! Ulids::valid($productId)) {
            return null;
        }

        // The latest delivered, in any store.
        $latest = $this->db->table('sales.order_lines AS l')->join('sales.orders AS o', 'o.id', '=', 'l.order_id')
            ->where('o.customer_id', $customerId)->where('l.product_id', $productId)->where('l.is_gift', false)->whereNotNull('o.delivered_at')
            ->orderByDesc('o.delivered_at')->orderByDesc('l.id')->first(['l.variant_id', 'o.delivered_at']);

        if (! $latest instanceof stdClass) {
            return null;
        }

        $returned = $this->db->table('sales.return_lines AS rl')->join('sales.returns AS r', 'r.id', '=', 'rl.return_id')
            ->join('sales.order_lines AS l', 'l.id', '=', 'rl.order_line_id')->join('sales.orders AS o', 'o.id', '=', 'rl.order_id')
            ->where('o.customer_id', $customerId)->where('l.product_id', $productId)->where('l.is_gift', false)
            ->where('r.status', 'COMPLETED')->where('rl.received_quantity', '>', 0)->exists();

        return new DeliveredPurchaseDto((string) $latest->variant_id, CarbonImmutable::parse((string) $latest->delivered_at), $returned);
    }

    public function returnedProducts(string $returnId): ?ReturnedProductsDto
    {
        $returnId = strtolower(trim($returnId));
        $return = Ulids::valid($returnId)
            ? $this->db->table('sales.returns AS r')->join('sales.orders AS o', 'o.id', '=', 'r.order_id')
                ->where('r.id', $returnId)->where('r.status', 'COMPLETED')->first(['o.customer_id', 'r.store_id'])
            : null;

        if (! $return instanceof stdClass) {
            return null;
        }

        $productIds = $this->db->table('sales.return_lines AS rl')->join('sales.order_lines AS l', 'l.id', '=', 'rl.order_line_id')
            ->where('rl.return_id', $returnId)->where('l.is_gift', false)->where('rl.received_quantity', '>', 0)
            ->distinct()->orderBy('l.product_id')->pluck('l.product_id')->all();

        return new ReturnedProductsDto((string) $return->customer_id, (string) $return->store_id, array_values(array_map('strval', $productIds)));
    }
}
