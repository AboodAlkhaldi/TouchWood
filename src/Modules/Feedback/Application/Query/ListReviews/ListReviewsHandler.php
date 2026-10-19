<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListReviews;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\FeedbackReader;
use Modules\Feedback\Application\Query\ReadInputs;
use Modules\Feedback\Application\Query\ReviewRow;
use Modules\Feedback\Application\Query\ReviewsPage;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Public\Enums\ReviewStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A store's review queue (feedback.md §1.1, §3): `feedback.review.moderate` in the store, the reviews
 * written there in one state — waiting ones oldest first, the next to decide on top; any other state
 * newest first — 50 a page. Authors in their public form only, as shoppers see them (§1.1); who
 * decided as the reader may be shown them.
 */
final readonly class ListReviewsHandler
{
    public const string PERMISSION = FeedbackPermissions::REVIEW_MODERATE;

    public const int PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private ReadInputs $inputs,
        private FeedbackQueries $queries,
        private FeedbackReader $reader,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(ListReviews $query): ReviewsPage
    {
        $store = ReadInputs::store($query->storeId);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $this->inputs->staffIn(self::PERMISSION, $store);
        $status = ReviewStatus::tryFrom($query->status) ?? throw new InvalidFeedbackAttribute('status', 'not a review status');
        $locale = ReadInputs::locale($query->locale);

        $reviews = $this->queries->reviewsIn($store, $status, ReadInputs::after($query->after), self::PAGE + 1);
        $more = count($reviews) > self::PAGE;
        $reviews = array_slice($reviews, 0, self::PAGE);

        $cards = $this->reader->cards($store, $locale, array_map(static fn ($review): string => $review->productId, $reviews));
        $variants = $this->reader->variants($locale, array_map(static fn ($review): string => $review->variantId, $reviews));
        $authors = $this->reader->authors(array_map(static fn ($review): string => $review->customerId, $reviews));
        $staff = $this->reader->staff(array_values(array_filter(array_map(static fn ($review): ?string => $review->moderatedBy, $reviews))));

        return new ReviewsPage(
            array_map(static fn ($review): ReviewRow => new ReviewRow(
                $review->id,
                FeedbackReader::card($cards, $review->productId),
                $variants[$review->variantId] ?? [],
                FeedbackReader::author($authors, $review->customerId),
                $review->rating,
                $review->body,
                $review->locale,
                $review->status,
                $review->mark,
                $review->reason,
                $review->createdAt,
                $review->moderatedBy === null ? null : ($staff[$review->moderatedBy] ?? null),
                $review->moderatedAt,
            ), $reviews),
            $more && $reviews !== [] ? $reviews[count($reviews) - 1]->id : null,
        );
    }
}
