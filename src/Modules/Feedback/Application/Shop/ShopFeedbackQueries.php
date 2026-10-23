<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Shop;

use Shared\Domain\ValueObject\StoreId;

/** What a product's page in the shop shows of Feedback's, read at once (feedback.md §8). */
interface ShopFeedbackQueries
{
    /**
     * The product's rating; a page of its approved reviews, newest first, and of its answered
     * questions, newest answer first, each after the id given — the reviews only of one star when one is
     * given (§4.2 B1); each star's count of its approved reviews; and, for a signed-in customer, whether
     * they saved it in this store and whether they reviewed it.
     */
    public function forProduct(string $productId, StoreId $store, ?string $customerId, ?string $reviewsAfter, ?string $questionsAfter, int $limit, ?int $star = null): ShopFeedbackRows;
}
