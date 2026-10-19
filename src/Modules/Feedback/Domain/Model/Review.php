<?php

declare(strict_types=1);

namespace Modules\Feedback\Domain\Model;

use DateTimeImmutable;
use Modules\Feedback\Public\Enums\ReviewMark;
use Modules\Feedback\Public\Enums\ReviewStatus;

/** A review as it is now (feedback.md §1.1, §4). */
final readonly class Review
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $variantId,
        public string $customerId,
        public string $storeId,
        public int $rating,
        public string $body,
        public string $locale,
        public ReviewStatus $status,
        public ReviewMark $mark,
        public ?string $reason,
        public ?string $moderatedBy,
        public ?DateTimeImmutable $moderatedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
