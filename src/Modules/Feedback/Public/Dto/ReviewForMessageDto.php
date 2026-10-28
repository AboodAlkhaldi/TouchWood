<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Dto;

use Modules\Feedback\Public\Enums\ReviewStatus;

/** A review as Ops' message about it needs it (`FeedbackApi::reviewForMessage`). */
final readonly class ReviewForMessageDto
{
    public function __construct(
        public string $reviewId,
        public string $productId,
        public string $variantId,
        public string $storeId,
        public string $customerId,
        public ReviewStatus $status,
        public int $rating,
        /** The language the customer wrote in. */
        public string $locale,
        /** The words staff wrote rejecting or taking it down; null when none. */
        public ?string $reason,
    ) {}
}
