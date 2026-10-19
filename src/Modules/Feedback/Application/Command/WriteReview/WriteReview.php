<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\WriteReview;

/** A customer's review of a product delivered to them, written in a store (feedback.md §1.1). */
final readonly class WriteReview
{
    public function __construct(
        public string $storeId,
        public string $productId,
        public int $rating,
        public string $body,
        public string $locale,
    ) {}
}
