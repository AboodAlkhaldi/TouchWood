<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Feedback\Domain\Repository\Wishlist;
use Shared\Domain\ValueObject\StoreId;

/** `Wishlist` in SQL, no Eloquent model (feedback.md §1.6). */
final readonly class DatabaseWishlist implements Wishlist
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function count(string $customerId): int
    {
        return $this->db->table('feedback.wishlist_items')->where('customer_id', $customerId)->count();
    }

    public function holds(string $customerId, string $productId, StoreId $store): bool
    {
        return $this->item($customerId, $productId, $store)->exists();
    }

    public function save(string $customerId, string $productId, StoreId $store, DateTimeImmutable $at): void
    {
        $this->db->table('feedback.wishlist_items')->insert([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'store_id' => $store->value,
            'saved_at' => Moment::of($at),
        ]);
    }

    public function remove(string $customerId, string $productId, StoreId $store): void
    {
        $this->item($customerId, $productId, $store)->delete();
    }

    public function clear(string $customerId): void
    {
        $this->db->table('feedback.wishlist_items')->where('customer_id', $customerId)->delete();
    }

    private function item(string $customerId, string $productId, StoreId $store): Builder
    {
        return $this->db->table('feedback.wishlist_items')->where('customer_id', $customerId)->where('product_id', $productId)->where('store_id', $store->value);
    }
}
