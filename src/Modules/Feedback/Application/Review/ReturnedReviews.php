<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Review;

use Illuminate\Database\ConnectionInterface;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Sales\Public\Contracts\SalesApi;

/**
 * A return completed (feedback.md §1.1, §6): the customer's reviews of the products it took back flip
 * to "Returned" — per product, also after the review was written; the review stays published. Each
 * product under the (customer, product) lock a review is written under, so the two never miss each
 * other. Done once however often it runs: a mark already set is left as it is.
 */
final readonly class ReturnedReviews
{
    public function __construct(
        private SalesApi $sales,
        private Reviews $reviews,
        private ReviewLock $lock,
        private ConnectionInterface $db,
    ) {}

    public function flip(string $returnId): void
    {
        $returned = $this->sales->returnedProducts($returnId);

        if ($returned === null) {
            return;
        }

        foreach (array_values(array_unique(array_map(strtolower(...), $returned->productIds))) as $productId) {
            $this->db->transaction(function () use ($returned, $productId): void {
                $this->lock->take(strtolower($returned->customerId), $productId);
                $this->reviews->markReturned(strtolower($returned->customerId), $productId);
            });
        }
    }
}
