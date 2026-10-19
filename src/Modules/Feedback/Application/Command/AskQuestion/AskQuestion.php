<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\AskQuestion;

/** A signed-in customer's question about a product, asked in a store (feedback.md §1.3). */
final readonly class AskQuestion
{
    public function __construct(
        public string $storeId,
        public string $productId,
        public string $body,
        public string $locale,
    ) {}
}
