<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Storefront;

use Modules\Catalog\Public\Contracts\ShopCardFact;
use Modules\Feedback\Domain\Repository\Wishlist;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * The Save heart on the shop's product cards (feedback.md §2.4 B5; catalog.md amendment 32): `feedback.saved`
 * — `{saved: true}` for each of the page's products the signed-in customer saved in this store, in one
 * read; nothing for the others, nothing for a guest.
 */
final readonly class SavedCardFact implements ShopCardFact
{
    public function __construct(
        private Wishlist $wishlist,
    ) {}

    public function read(StoreId $store, ?string $customerId, array $productIds): array
    {
        $customerId = $customerId === null ? '' : strtolower(trim($customerId));

        if (! Ulids::valid($customerId) || $productIds === []) {
            return [];
        }

        $saved = [];

        foreach ($this->wishlist->savedAmong($customerId, $store, $productIds) as $productId) {
            $saved[$productId] = ['saved' => true];
        }

        return $saved;
    }
}
