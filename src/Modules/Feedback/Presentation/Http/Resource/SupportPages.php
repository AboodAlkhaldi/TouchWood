<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\AuthorName;
use Modules\Feedback\Application\Query\FavouriteRow;
use Modules\Feedback\Application\Query\FeedbackActions\FeedbackActionsForReader;
use Modules\Feedback\Application\Query\FeedbackCard;
use Modules\Feedback\Application\Query\ListFavourites\ListFavourites;
use Modules\Feedback\Application\Query\ListFavourites\ListFavouritesHandler;
use Modules\Feedback\Application\Query\ListQuestions\ListQuestions;
use Modules\Feedback\Application\Query\ListQuestions\ListQuestionsHandler;
use Modules\Feedback\Application\Query\ListReviews\ListReviews;
use Modules\Feedback\Application\Query\ListReviews\ListReviewsHandler;
use Modules\Feedback\Application\Query\QuestionRow;
use Modules\Feedback\Application\Query\ReviewRow;
use Modules\Feedback\Application\Query\VariantValueRow;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StaffNameDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * The Support screens' data (feedback.md §4.1 F1–F3), built from Feedback's Application queries - which
 * authorize - and nothing of its own deciding: what a reader may do comes from FeedbackActionsForReader.
 */
final readonly class SupportPages
{
    /** The tabs of F1 and F2, the first the default: the waiting items, the next to decide on top. */
    public const array REVIEW_TABS = ['PENDING', 'APPROVED', 'REJECTED', 'UNPUBLISHED'];

    public const array QUESTION_TABS = ['PENDING', 'ANSWERED', 'REJECTED', 'UNPUBLISHED'];

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private FeedbackActionsForReader $actions,
    ) {}

    /**
     * F1.
     *
     * @throws Unauthorized
     */
    public function reviews(?string $storeCode, ?string $status, ?string $after, ListReviewsHandler $list): SupportListPage
    {
        [$store, $offered] = $this->store($storeCode, FeedbackPermissions::REVIEW_MODERATE);
        $tab = in_array($status, self::REVIEW_TABS, true) ? $status : self::REVIEW_TABS[0];
        $page = $list->handle(new ListReviews($store->id, $tab, $this->locale(), $after));

        return $this->page(
            $store,
            $offered,
            array_map(fn (ReviewRow $row): ReviewItemData => new ReviewItemData(
                $row->reviewId,
                $this->card($row->product, $store),
                self::variant($row->variant),
                self::author($row->author),
                $row->rating,
                $row->body,
                $row->locale,
                $row->status->value,
                $row->mark->value,
                $row->reason,
                self::moment($row->writtenAt),
                self::staff($row->moderatedBy),
                $row->moderatedAt === null ? null : self::moment($row->moderatedAt),
            ), $page->rows),
            [],
            [],
            $tab,
            $page->next,
            $this->actions->forStore($store->storeId())->mayModerate,
        );
    }

    /**
     * F2.
     *
     * @throws Unauthorized
     */
    public function questions(?string $storeCode, ?string $status, ?string $after, ListQuestionsHandler $list): SupportListPage
    {
        [$store, $offered] = $this->store($storeCode, FeedbackPermissions::QUESTION_ANSWER);
        $tab = in_array($status, self::QUESTION_TABS, true) ? $status : self::QUESTION_TABS[0];
        $page = $list->handle(new ListQuestions($store->id, $tab, $this->locale(), $after));

        return $this->page(
            $store,
            $offered,
            [],
            array_map(fn (QuestionRow $row): QuestionItemData => new QuestionItemData(
                $row->questionId,
                $this->card($row->product, $store),
                self::author($row->author),
                $row->body,
                $row->locale,
                $row->status->value,
                $row->answer,
                self::staff($row->answeredBy),
                $row->answeredAt === null ? null : self::moment($row->answeredAt),
                $row->reason,
                self::moment($row->askedAt),
            ), $page->rows),
            [],
            $tab,
            $page->next,
            $this->actions->forStore($store->storeId())->mayAnswer,
        );
    }

    /**
     * F3.
     *
     * @throws Unauthorized
     */
    public function favourites(?string $storeCode, ?string $after, ListFavouritesHandler $list): SupportListPage
    {
        [$store, $offered] = $this->store($storeCode, FeedbackPermissions::WISHLIST_VIEW);
        $page = $list->handle(new ListFavourites($store->id, $this->locale(), $after));

        return $this->page(
            $store,
            $offered,
            [],
            [],
            array_map(fn (FavouriteRow $row): FavouriteItemData => new FavouriteItemData($this->card($row->product, $store), $row->savedBy), $page->rows),
            null,
            $page->next,
            false,
        );
    }

    /**
     * @param  list<StoreDto>  $offered
     * @param  list<ReviewItemData>  $reviews
     * @param  list<QuestionItemData>  $questions
     * @param  list<FavouriteItemData>  $favourites
     */
    private function page(StoreDto $store, array $offered, array $reviews, array $questions, array $favourites, ?string $status, ?string $next, bool $mayAct): SupportListPage
    {
        $locale = $this->locale();

        return new SupportListPage(
            $reviews,
            $questions,
            $favourites,
            $status,
            $next,
            $mayAct,
            $store->code,
            $store->name->in($locale),
            $store->isActive,
            $store->timezone,
            array_map(static fn (StoreDto $option): StoreOptionData => new StoreOptionData($option->code, $option->name->in($locale), $option->isActive), $offered),
        );
    }

    /**
     * The store shown - the one asked for, else the first that is on - among those where the reader holds
     * the page's job; a Super Admin with every store off still opens one.
     *
     * @return array{0: StoreDto, 1: list<StoreDto>}
     *
     * @throws Unauthorized
     */
    private function store(?string $code, string $permission): array
    {
        $offered = $this->choices->forJobs($permission);
        $chosen = null;

        foreach ($offered as $store) {
            if ($code === null ? $store->isActive : strtolower($store->code) === strtolower(trim($code))) {
                $chosen = $store;
                break;
            }
        }

        $chosen ??= $code === null ? ($offered[0] ?? null) : null;

        return [$chosen ?? throw new Unauthorized($permission), $offered];
    }

    /** A product, its shop address in this store and the page's language, and its thumbnail. */
    private function card(FeedbackCard $card, StoreDto $store): ProductCardData
    {
        return new ProductCardData(
            $card->productId,
            $card->name,
            $card->slug === null || $card->removed ? null : '/'.strtolower($store->code).'/'.$this->locale().'/'.$card->slug,
            self::thumb($card->photo),
            $card->sold,
            $card->archived,
            $card->removed,
        );
    }

    /**
     * @param  array<string, array<string, string>>|null  $photo  size slug => format => URL
     */
    private static function thumb(?array $photo): ?string
    {
        $sizes = $photo['thumb'] ?? (is_array($photo) && $photo !== [] ? reset($photo) : []);

        foreach (['avif', 'webp', 'jpg', 'jpeg'] as $format) {
            if (is_string($sizes[$format] ?? null)) {
                return $sizes[$format];
            }
        }

        return null;
    }

    /**
     * @param  list<VariantValueRow>  $values
     * @return list<ShopVariantValueData>
     */
    private static function variant(array $values): array
    {
        return array_map(static fn (VariantValueRow $value): ShopVariantValueData => new ShopVariantValueData($value->attribute, $value->value), $values);
    }

    private static function author(AuthorName $author): ShopAuthorData
    {
        return new ShopAuthorData($author->firstName, $author->lastInitial, $author->deleted);
    }

    private static function staff(?StaffNameDto $staff): ?string
    {
        return $staff?->name;
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
