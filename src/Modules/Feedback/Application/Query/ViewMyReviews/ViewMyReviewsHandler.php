<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ViewMyReviews;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\FeedbackReader;
use Modules\Feedback\Application\Query\MyReviewRow;
use Modules\Feedback\Application\Query\MyReviewsPage;
use Modules\Feedback\Application\Query\ReadInputs;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * "My reviews" (feedback.md §3): `feedback.own.view`, the signed-in customer's own only — every
 * review in every store, newest first, whatever became of it, with the reason staff wrote when it was
 * not published or taken down (§1.1). Each product as the store it was written in shows it.
 */
final readonly class ViewMyReviewsHandler
{
    public const string PERMISSION = FeedbackPermissions::OWN_VIEW;

    public const int PAGE = 20;

    public function __construct(
        private Authorizer $authorizer,
        private ReadInputs $inputs,
        private FeedbackQueries $queries,
        private FeedbackReader $reader,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(ViewMyReviews $query): MyReviewsPage
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->inputs->customer(self::PERMISSION);
        $locale = ReadInputs::locale($query->locale);
        $reviews = $this->queries->reviewsBy($customerId, ReadInputs::after($query->after), self::PAGE + 1);
        $more = count($reviews) > self::PAGE;
        $reviews = array_slice($reviews, 0, self::PAGE);

        $byStore = [];

        foreach ($reviews as $review) {
            $byStore[$review->storeId][] = $review->productId;
        }

        $cards = $this->reader->cardsByStore($locale, $byStore);
        $variants = $this->reader->variants($locale, array_map(static fn ($review): string => $review->variantId, $reviews));

        return new MyReviewsPage(
            array_map(static fn ($review): MyReviewRow => new MyReviewRow(
                $review->id,
                $review->storeId,
                FeedbackReader::card($cards[$review->storeId] ?? [], $review->productId),
                $variants[$review->variantId] ?? [],
                $review->rating,
                $review->body,
                $review->locale,
                $review->status,
                $review->mark,
                $review->reason,
                $review->createdAt,
            ), $reviews),
            $more && $reviews !== [] ? $reviews[count($reviews) - 1]->id : null,
        );
    }
}
