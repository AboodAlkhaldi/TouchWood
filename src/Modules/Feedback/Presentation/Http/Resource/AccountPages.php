<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Feedback\Application\Query\FeedbackCard;
use Modules\Feedback\Application\Query\MyQuestionRow;
use Modules\Feedback\Application\Query\MyReviewRow;
use Modules\Feedback\Application\Query\VariantValueRow;
use Modules\Feedback\Application\Query\ViewMyQuestions\ViewMyQuestions;
use Modules\Feedback\Application\Query\ViewMyQuestions\ViewMyQuestionsHandler;
use Modules\Feedback\Application\Query\ViewMyReviews\ViewMyReviews;
use Modules\Feedback\Application\Query\ViewMyReviews\ViewMyReviewsHandler;
use Modules\Feedback\Application\Query\ViewMyWishlist\ViewMyWishlist;
use Modules\Feedback\Application\Query\ViewMyWishlist\ViewMyWishlistHandler;
use Modules\Feedback\Application\Query\WishlistGroup;
use Modules\Feedback\Application\Query\WishlistItemRow;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * F8 - the shop account's Feedback pages (feedback.md §4.1): the signed-in customer's own reviews,
 * questions and wishlist, in every store, each product linked in the store it belongs to. The handlers
 * read who is asking; nothing here names an account.
 */
final readonly class AccountPages
{
    public function __construct(
        private Application $app,
        private PlatformApi $platform,
    ) {}

    public function reviews(?string $after, ViewMyReviewsHandler $view): MyReviewsPage
    {
        $page = $view->handle(new ViewMyReviews($this->locale(), $after));

        return new MyReviewsPage(array_map(fn (MyReviewRow $row): MyReviewData => new MyReviewData(
            $row->reviewId,
            $this->card($row->product, $row->storeId),
            $row->storeName,
            array_map(static fn (VariantValueRow $value): ShopVariantValueData => new ShopVariantValueData($value->attribute, $value->value), $row->variant),
            $row->rating,
            $row->body,
            $row->locale,
            $row->status->value,
            $row->mark->value,
            $row->reason,
            self::moment($row->writtenAt),
        ), $page->rows), $page->next);
    }

    public function questions(?string $after, ViewMyQuestionsHandler $view): MyQuestionsPage
    {
        $page = $view->handle(new ViewMyQuestions($this->locale(), $after));

        return new MyQuestionsPage(array_map(fn (MyQuestionRow $row): MyQuestionData => new MyQuestionData(
            $row->questionId,
            $this->card($row->product, $row->storeId),
            $row->storeName,
            $row->body,
            $row->locale,
            $row->status->value,
            $row->answer,
            $row->answeredAt === null ? null : self::moment($row->answeredAt),
            $row->reason,
            self::moment($row->askedAt),
        ), $page->rows), $page->next);
    }

    public function wishlist(ViewMyWishlistHandler $view): MyWishlistPage
    {
        return new MyWishlistPage(array_map(fn (WishlistGroup $group): MyWishlistGroupData => new MyWishlistGroupData(
            strtolower($this->platform->store(StoreId::fromString($group->storeId))->code ?? ''),
            $group->storeName,
            $group->storeOpen,
            array_map(fn (WishlistItemRow $item): WishlistItemData => new WishlistItemData(
                $this->card($item->product, $group->storeId, ! $item->unavailable),
                self::moment($item->savedAt),
            ), $group->items),
        ), $view->handle(new ViewMyWishlist($this->locale()))));
    }

    /** A product as the account shows it: linked in its own store, unless Catalog no longer has it. */
    private function card(FeedbackCard $card, string $storeId, ?bool $sold = null): ProductCardData
    {
        $code = $this->platform->store(StoreId::fromString($storeId))?->code;

        return new ProductCardData(
            $card->productId,
            $card->name,
            $code === null || $card->slug === null || $card->removed ? null : '/'.strtolower($code).'/'.$this->locale().'/'.$card->slug,
            SupportPages::thumb($card->photo),
            $sold ?? $card->sold,
            $card->archived,
            $card->removed,
        );
    }

    private static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP');
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
