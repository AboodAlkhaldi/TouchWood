<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\ApproveReview;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Review\Moderation;
use Modules\Feedback\Application\StaffChange;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\ReviewNotFound;
use Modules\Feedback\Domain\Model\FeedbackText;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Shared\Application\Unauthorized;

/**
 * Approves a waiting review (feedback.md §1.1): it shows in every store selling the product, and
 * counts in its rating. By the staff of the store it was written in; a review of a store not covered
 * is not found (§7).
 */
final readonly class ApproveReviewHandler
{
    public const string PERMISSION = FeedbackPermissions::REVIEW_MODERATE;

    public function __construct(
        private StaffChange $change,
        private Reviews $reviews,
        private Moderation $moderation,
    ) {}

    /**
     * @throws Unauthorized|ReviewNotFound|InvalidModeration|InvalidFeedbackAttribute
     */
    public function handle(ApproveReview $command): void
    {
        [$store, $staffId] = $this->change->authorize(self::PERMISSION, fn () => $this->reviews->storeOf($command->reviewId), static fn (): ReviewNotFound => new ReviewNotFound);
        $locale = $command->locale === null ? null : FeedbackText::locale($command->locale);
        $this->moderation->move($store, $staffId, $command->reviewId, ReviewStatus::Pending, ReviewStatus::Approved, null, $locale);
    }
}
