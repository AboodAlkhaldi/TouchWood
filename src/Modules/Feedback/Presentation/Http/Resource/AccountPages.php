<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Catalog\Public\Contracts\ShopProductCards;
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
        private ShopProductCards $cards,
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
            $group->storeId,
            strtolower($this->platform->store(StoreId::fromString($group->storeId))->code ?? ''),
            $group->storeName,
            $group->storeOpen,
            array_map(fn (WishlistItemRow $item): WishlistItemData => new WishlistItemData(
                $this->card($item->product, $group->storeId, ! $item->unavailable),
                self::moment($item->savedAt),
            ), $group->items),
            $this->shopCards($group),
        ), $view->handle(new ViewMyWishlist($this->locale()))));
    }

    /** A product as the account shows it: linked in its own store, unless Catalog no longer has it. */
    private function card(FeedbackCard $card, string $storeId, ?bool $sold = null): ProductCardData
    {
        $code = $this->platform->store(StoreId::fromString($storeId))?->code;

        return new ProductCardData(
            $card->productId,
            $card->name,
            // The shop's product page is `{store}/{locale}/products/{slug}` (storefront.catalog.product); without
            // `products/` every link here was a 404 (found 2026-11-20 by the wishlist tile's browser test).
            $code === null || $card->slug === null || $card->removed ? null : '/'.strtolower($code).'/'.$this->locale().'/products/'.$card->slug,
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

    /**
     * **The tiles' price and stars** (flags-2026-11.md S2.2, owner 2026-11-18): the shop's own cards for the items the
     * person can order now, in one read per store (`ShopProductCards::read`). The page's own queries, measured in
     * ShopScreensTest: 6 -> 10 for one store (the card read's 4, signed in), 16 for two (owner, 2026-11-20: accepted,
     * where 7 -> 8 had been estimated). The read takes at most 100 products a call, so more than 100 orderable saves in
     * one store take a read per 100 (owner, 2026-11-20: accepted; a wishlist holds up to 500). A card is the shop's, so
     * the price is this person's own. An item that cannot be ordered now is left out by that read and keeps its plain tile.
     *
     * @return array<string, array<string, mixed>> product id => its shop card
     */
    private function shopCards(WishlistGroup $group): array
    {
        $ids = array_values(array_map(static fn (WishlistItemRow $item): string => $item->product->productId, array_filter($group->items, static fn (WishlistItemRow $item): bool => ! $item->unavailable)));

        if (! $group->storeOpen || $ids === []) {
            return [];
        }

        $cards = [];

        foreach (array_chunk($ids, 100) as $chunk) {
            foreach ($this->cards->read(StoreId::fromString($group->storeId), $this->locale(), $chunk)['cards'] as $card) {
                $cards[(string) ($card['productId'] ?? '')] = $card;
            }
        }

        unset($cards['']);

        return $cards;
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
