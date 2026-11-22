<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\WriteReview;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\RemovedMeanwhile;
use Modules\Feedback\Application\Review\ReviewLock;
use Modules\Feedback\Domain\Exception\AlreadyReviewed;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\NotAVerifiedPurchase;
use Modules\Feedback\Domain\Model\FeedbackText;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Feedback\Public\Enums\ReviewMark;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Public\Contracts\SalesApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A customer writes a review (feedback.md §1.1): a rating and text, in a store that sells the product
 * (answer 19) — whose staff then moderate it — **once per product, for ever**, and only with **a
 * verified purchase**: the product delivered to them in any store, as Sales answers. It shows the
 * variant delivered last, and is marked "Returned" at once if any of the product came back. It waits
 * as PENDING until staff approve it.
 *
 * Under the (customer, product) lock: two at once cannot both pass the one-review rule (the unique key
 * is the backstop), and a return settling meanwhile is never missed.
 */
final readonly class WriteReviewHandler
{
    public const string PERMISSION = FeedbackPermissions::REVIEW_WRITE;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private SalesApi $sales,
        private Reviews $reviews,
        private ReviewLock $lock,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute|AlreadyReviewed|NotAVerifiedPurchase
     */
    public function handle(WriteReview $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $customerId = strtolower($actor->id);
        $store = self::store($command->storeId);

        if (! ($this->platform->store($store)->isActive ?? false)) {
            throw new InvalidFeedbackAttribute('store', 'no such store open');
        }

        $productId = strtolower(trim($command->productId));
        $rating = FeedbackText::rating($command->rating);
        $body = FeedbackText::of($command->body, 'body', 2000);
        // The language it is written in, whatever the shop's (§2.4 B3).
        $locale = FeedbackText::languageOf($body);

        if (! Ulids::valid($productId) || ! in_array($productId, $this->catalog->productsSoldIn($store, [$productId]), true)) {
            throw new InvalidFeedbackAttribute('product', 'not sold in this store');
        }

        try {
            return $this->db->transaction(function () use ($customerId, $productId, $store, $rating, $body, $locale): string {
                $this->lock->take($customerId, $productId);

                if ($this->reviews->written($customerId, $productId)) {
                    throw new AlreadyReviewed;
                }

                $purchase = $this->sales->deliveredPurchase($customerId, $productId) ?? throw new NotAVerifiedPurchase;
                $variant = $this->catalog->variants([$purchase->variantId])[strtolower($purchase->variantId)] ?? null;

                // The variant shown belongs to the product (§1.1): a code rule, as no composite key holds it.
                if ($variant === null || $variant->productId !== $productId) {
                    throw new InvalidFeedbackAttribute('variant', 'not of the product');
                }

                $reviewId = strtolower((string) Str::ulid());
                $this->reviews->create($reviewId, $productId, $variant->id, $customerId, $store, $rating, $body, $locale, $purchase->returned ? ReviewMark::Returned : ReviewMark::VerifiedPurchase, CarbonImmutable::now());

                return $reviewId;
            });
        } catch (UniqueConstraintViolationException) {
            // The lock makes this a backstop: a review written by a path that skipped it.
            throw new AlreadyReviewed;
        } catch (QueryException $failed) {
            throw RemovedMeanwhile::of($failed);
        }
    }

    /**
     * @throws InvalidFeedbackAttribute
     */
    private static function store(string $storeId): StoreId
    {
        try {
            return StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidFeedbackAttribute('store', 'not a store id');
        }
    }
}
