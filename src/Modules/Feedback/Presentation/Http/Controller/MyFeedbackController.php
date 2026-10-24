<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Feedback\Application\Query\ViewMyQuestions\ViewMyQuestionsHandler;
use Modules\Feedback\Application\Query\ViewMyReviews\ViewMyReviewsHandler;
use Modules\Feedback\Application\Query\ViewMyWishlist\ViewMyWishlistHandler;
use Modules\Feedback\Presentation\Http\Resource\AccountPages;

/*
| F8 - My Reviews, My Questions and My Wishlist (feedback.md §4.1), pages of the shop account beside
| Access's own tabs (access.md amendment 50), always offered (owner's Q19). None names an account: the
| handlers read who is asking. Show More is `after`, the cursor the page before gave.
*/
final readonly class MyFeedbackController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'feedback::shop', 'feedback::errors'];

    public function __construct(
        private Page $page,
        private AccountPages $pages,
    ) {}

    public function reviews(Request $request, ViewMyReviewsHandler $view): Response
    {
        return $this->page->render('Feedback/Storefront/MyReviews', $this->pages->reviews(self::after($request), $view)->toArray(), self::WORDS);
    }

    public function questions(Request $request, ViewMyQuestionsHandler $view): Response
    {
        return $this->page->render('Feedback/Storefront/MyQuestions', $this->pages->questions(self::after($request), $view)->toArray(), self::WORDS);
    }

    public function wishlist(ViewMyWishlistHandler $view): Response
    {
        return $this->page->render('Feedback/Storefront/MyWishlist', $this->pages->wishlist($view)->toArray(), self::WORDS);
    }

    /** The cursor a page gave, as it gave it: at most 64 characters, else the first page. */
    private static function after(Request $request): ?string
    {
        $after = $request->query('after');

        return is_string($after) && $after !== '' && strlen($after) <= 64 ? $after : null;
    }
}
