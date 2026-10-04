<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Listing;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;

/**
 * A photo's sizes are ready (Platform's `MediaVariantsReady`, catalog.md §6.2): each product whose
 * gallery holds it has its listing rows written again, so a card shows it as soon as it can, under
 * the products' lock as every change to them. Run as often as the event comes, it writes the same
 * rows (§9.3 #1).
 */
final readonly class CardPhotoReady
{
    public function __construct(
        private ProductRepository $products,
        private ListingRows $listingRows,
        private ListLocks $locks,
        private ConnectionInterface $db,
    ) {}

    public function handle(string $mediaId): void
    {
        // Most photos ready are no product's: nothing to lock for them.
        if ($this->products->withPhoto($mediaId) === []) {
            return;
        }

        $this->db->transaction(function () use ($mediaId): void {
            $this->locks->lock(ListLocks::PRODUCTS);
            $this->listingRows->refresh($this->products->withPhoto($mediaId));
        }, 3);
    }
}
