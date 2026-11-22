<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

use DateTimeImmutable;
use Modules\Feedback\Public\Enums\ReviewMark;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Modules\Platform\Public\Dto\StaffNameDto;

/**
 * A review in a store's queue (feedback.md §1.1, §3): its author in the public form only, who decided on
 * it as the reader may be shown them.
 */
final readonly class ReviewRow
{
    /**
     * @param  list<VariantValueRow>  $variant
     */
    public function __construct(
        public string $reviewId,
        public FeedbackCard $product,
        public array $variant,
        public AuthorName $author,
        public int $rating,
        public string $body,
        public string $locale,
        public ReviewStatus $status,
        public ReviewMark $mark,
        public ?string $reason,
        public DateTimeImmutable $writtenAt,
        public ?StaffNameDto $moderatedBy,
        public ?DateTimeImmutable $moderatedAt,
    ) {}
}
