<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Shop;

use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Feedback\Application\Query\FeedbackReader;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Feedback's part of a product's page (feedback.md §2.3; catalog.md amendment 26(b)): read **once a
 * request** for all three of its sections — one statement, then the authors' names in one read and
 * the reviews' variants in Catalog's few, only when there are rows — and kept for the request, so
 * the three sections cost what one does.
 */
final class ShopFeedback
{
    /** Reviews and questions a page. */
    public const int PAGE = 10;

    /** @var array<string, ProductFeedback> */
    private array $read = [];

    private ?object $readFor = null;

    public function __construct(
        private readonly ShopFeedbackQueries $queries,
        private readonly FeedbackReader $reader,
    ) {}

    public function of(ShopProductVisitDto $visit): ProductFeedback
    {
        $reviewsAfter = self::after($visit->after['feedback.reviews'] ?? null);
        $questionsAfter = self::after($visit->after['feedback.questions'] ?? null);
        // What it holds belongs to the request that read it, as the shop's own `ShopVisit`.
        $request = app()->bound('request') ? app('request') : null;

        if ($request !== $this->readFor) {
            $this->read = [];
            $this->readFor = is_object($request) ? $request : null;
        }

        $key = implode('|', [$visit->store->value, $visit->locale, $visit->productId, $visit->customerId ?? '', $reviewsAfter ?? '', $questionsAfter ?? '']);

        return $this->read[$key] ??= $this->load($visit, $reviewsAfter, $questionsAfter);
    }

    private function load(ShopProductVisitDto $visit, ?string $reviewsAfter, ?string $questionsAfter): ProductFeedback
    {
        $rows = $this->queries->forProduct(strtolower($visit->productId), $visit->store, $visit->customerId === null ? null : strtolower($visit->customerId), $reviewsAfter, $questionsAfter, self::PAGE + 1);
        $reviews = array_slice($rows->reviews, 0, self::PAGE);
        $questions = array_slice($rows->questions, 0, self::PAGE);
        $authors = $this->reader->authors([
            ...array_map(static fn (ShopReview $review): string => $review->customerId, $reviews),
            ...array_map(static fn (ShopQuestion $question): string => $question->customerId, $questions),
        ]);
        $variants = $this->reader->variants($visit->locale, array_map(static fn (ShopReview $review): string => $review->variantId, $reviews));
        $stores = $questions === [] ? [] : $this->reader->stores();

        return new ProductFeedback(
            // Tenths rounded half up in whole numbers, as the cards' stars (catalog.md amendment 25).
            $rows->ratingCount === 0 ? null : intdiv(20 * $rows->ratingSum + $rows->ratingCount, 2 * $rows->ratingCount) / 10,
            $rows->ratingCount,
            array_map(static fn (ShopReview $review): array => [
                'review' => $review,
                'author' => FeedbackReader::author($authors, $review->customerId),
                'variant' => $variants[$review->variantId] ?? [],
            ], $reviews),
            count($rows->reviews) > self::PAGE && $reviews !== [] ? $reviews[count($reviews) - 1]->id : null,
            array_map(static fn (ShopQuestion $question): array => [
                'question' => $question,
                'author' => FeedbackReader::author($authors, $question->customerId),
                'store' => isset($stores[$question->storeId]) ? $stores[$question->storeId]->name->in($visit->locale) : '',
            ], $questions),
            count($rows->questions) > self::PAGE && $questions !== [] ? $questions[count($questions) - 1]->id : null,
            $visit->customerId !== null,
            $rows->saved,
            $rows->reviewed,
        );
    }

    /** A place in a list as the address gives it; anything that is not an id starts from the top. */
    private static function after(?string $after): ?string
    {
        $id = $after === null ? '' : strtolower(trim($after));

        return Ulids::valid($id) ? $id : null;
    }
}
