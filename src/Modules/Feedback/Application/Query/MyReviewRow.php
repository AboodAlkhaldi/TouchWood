<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use DateTimeImmutable;
use Modules\Feedback\Public\Enums\ReviewMark;
use Modules\Feedback\Public\Enums\ReviewStatus;

/**
 * One of my reviews (feedback.md §3, "My reviews"): the product as the store it was written in shows it, and
 * the reason staff gave when it was not published or taken down (§1.1).
 */
final readonly class MyReviewRow
{
    /**
     * @param  list<VariantValueRow>  $variant
     */
    public function __construct(
        public string $reviewId,
        public string $storeId,
        public string $storeName,
        public FeedbackCard $product,
        public array $variant,
        public int $rating,
        public string $body,
        public string $locale,
        public ReviewStatus $status,
        public ReviewMark $mark,
        public ?string $reason,
        public DateTimeImmutable $writtenAt,
    ) {}
}
