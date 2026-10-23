<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Review;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Feedback\Application\FeedbackAudit;
use Modules\Feedback\Application\StaffChange;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\ReviewNotFound;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Shared\Domain\ValueObject\StoreId;

/**
 * A review moved on by its store's staff (feedback.md §1.1, §4): approved from waiting — it shows in
 * every store selling the product and counts in its rating; rejected from waiting, for good; or
 * unpublished once shown — it leaves the shop and the rating, pushed into Catalog's listing with it. A reason only with a refusal. Anything
 * else is `InvalidModeration` (repeating an approval too, answer 20). The row is locked first, the
 * rating kept in the same transaction (§5); each audited.
 */
final readonly class Moderation
{
    public function __construct(
        private StaffChange $change,
        private Reviews $reviews,
        private ListingFacts $listing,
    ) {}

    /**
     * @throws ReviewNotFound|InvalidModeration
     */
    public function move(StoreId $store, string $staffId, string $reviewId, ReviewStatus $from, ReviewStatus $to, ?string $reason, ?string $locale = null): void
    {
        $this->change->run(function () use ($store, $staffId, $reviewId, $from, $to, $reason, $locale): array {
            $review = $this->reviews->lock($store, $reviewId) ?? throw new ReviewNotFound;

            if ($review->status !== $from) {
                throw new InvalidModeration;
            }

            $now = CarbonImmutable::now();
            $this->reviews->moderate($review, $to, $reason, $staffId, $now);
            // The text's language as staff set it (§4.2 B3).
            $relabelled = $locale !== null && $locale !== $review->locale;

            if ($relabelled) {
                $this->reviews->setLocale($review, $locale);
            }

            if ($to === ReviewStatus::Approved || $from === ReviewStatus::Approved) {
                $this->reviews->countRating($review->productId, $review->rating, $to === ReviewStatus::Approved ? 1 : -1, $now);
                // Into Catalog's listing in the same transaction (§1.2): a list is never stale.
                $this->listing->ratings([$review->productId => $this->reviews->rating($review->productId)]);
            }

            $action = match ($to) {
                ReviewStatus::Approved => 'approved',
                ReviewStatus::Rejected => 'rejected',
                default => 'unpublished',
            };

            return [null, [FeedbackAudit::entry(
                'review', $action, $review->id, $store->value,
                ['status' => $from->value, 'reason' => null, ...($relabelled ? ['locale' => $review->locale] : [])],
                ['status' => $to->value, 'reason' => $reason, ...($relabelled ? ['locale' => $locale] : [])],
            )]];
        });
    }
}
