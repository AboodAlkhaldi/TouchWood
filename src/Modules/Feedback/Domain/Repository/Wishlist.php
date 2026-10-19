<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Repository;

use DateTimeImmutable;
use Shared\Domain\ValueObject\StoreId;

/** The wishlists (feedback.md §1.4, §5): every item names the store it was saved in. */
interface Wishlist
{
    /** How many items the customer's list holds, in every store. */
    public function count(string $customerId): int;

    public function holds(string $customerId, string $productId, StoreId $store): bool;

    public function save(string $customerId, string $productId, StoreId $store, DateTimeImmutable $at): void;

    /** Nothing when the item is not there. */
    public function remove(string $customerId, string $productId, StoreId $store): void;

    /** The whole list, in every store — an anonymized account's (§1.5). */
    public function clear(string $customerId): void;
}
