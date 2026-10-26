<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestion;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestionHandler;
use Modules\Feedback\Application\Command\RemoveFromWishlist\RemoveFromWishlist;
use Modules\Feedback\Application\Command\RemoveFromWishlist\RemoveFromWishlistHandler;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlist;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlistHandler;
use Modules\Feedback\Application\Command\WriteReview\WriteReview;
use Modules\Feedback\Application\Command\WriteReview\WriteReviewHandler;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\StoreContext;
use Shared\Domain\Error\DomainError;

/*
| What a shopper does from a product's page (feedback.md §4.1 F5, F6): writes a review, asks a question,
| presses the Save heart. Each in the shop's store and for whoever is asking - no route names an account.
|
| A guest who presses Save (owner's Q18 (a)) is sent to sign in, the product kept in their own session for
| an hour; once signed in (or registered) they come to the one address that saves what their session kept,
| once, and goes back to the page the heart was pressed on - a link from anyone else saves nothing. "Sign
| in to ask" (Q16 (a)) keeps the page alone, to come back to.
*/
final readonly class ShopFeedbackController
{
    /** How long a guest's pressed heart is kept: a sign-in, a forgotten password, a second try. */
    private const int SAVE_AFTER_MINUTES = 60;

    /** The session's key for it: the product, the page to go back to, until when. */
    private const string PENDING = 'feedback.save_after_sign_in';

    public function review(Request $request, StoreContext $stores, WriteReviewHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new WriteReview(
            $stores->current()->value,
            self::product($request),
            $request->integer('rating'),
            $request->string('body')->toString(),
        )), 'reviewed', ['body', 'rating']);
    }

    public function ask(Request $request, StoreContext $stores, AskQuestionHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new AskQuestion(
            $stores->current()->value,
            self::product($request),
            $request->string('body')->toString(),
        )), 'asked', ['body']);
    }

    public function save(Request $request, ActorContext $actors, StoreContext $stores, SaveToWishlistHandler $handler): RedirectResponse
    {
        $product = self::product($request);
        $actor = $actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            $request->session()->put(self::PENDING, ['product' => $product, 'back' => self::back($request->input('back')), 'until' => now()->addMinutes(self::SAVE_AFTER_MINUTES)->getTimestamp()]);
            $request->session()->put('url.intended', route('storefront.feedback.save-after-sign-in'));

            return redirect()->route('storefront.sign-in')->with('status', __('feedback::shop.toast.sign_in_to_save'));
        }

        return self::act($request, fn () => $handler->handle(new SaveToWishlist($stores->current()->value, $product)), 'saved');
    }

    public function remove(Request $request, StoreContext $stores, RemoveFromWishlistHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RemoveFromWishlist($stores->current()->value, self::product($request))), 'removed');
    }

    /** From My Wishlist: a product removed from the group of the store it was saved in, open or closed (§4.1 F8). */
    public function removeSaved(Request $request, RemoveFromWishlistHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RemoveFromWishlist($request->string('store')->toString(), $request->string('product')->toString())), 'removed');
    }

    /** After signing in from the heart: what this session kept saved, once, and back to its page. */
    public function saveAfterSignIn(Request $request, StoreContext $stores, SaveToWishlistHandler $handler): RedirectResponse
    {
        $pending = $request->session()->pull(self::PENDING);

        if (! is_array($pending) || ! is_string($pending['product'] ?? null) || ! is_int($pending['until'] ?? null) || $pending['until'] < now()->getTimestamp()) {
            return redirect()->route('storefront.home');
        }

        $back = self::back($pending['back'] ?? null);

        try {
            $handler->handle(new SaveToWishlist($stores->current()->value, $pending['product']));
        } catch (DomainError $error) {
            return redirect($back)->withErrors(['form' => FormErrors::message($error)]);
        }

        return redirect($back)->with('status', __('feedback::shop.toast.saved'));
    }

    /** "Sign in to ask" (Q16 (a)): to sign in, and back to this page after it; a heart pressed before is let go. */
    public function signInHere(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING);
        $request->session()->put('url.intended', self::back($request->query('back')));

        return redirect()->route('storefront.sign-in');
    }

    /**
     * @param  list<string>  $keep
     */
    private static function act(Request $request, Closure $work, string $done, array $keep = []): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, $keep);
        }

        return back()->with('status', __("feedback::shop.toast.{$done}"));
    }

    private static function product(Request $request): string
    {
        $product = $request->route('product');

        return is_string($product) ? $product : '';
    }

    /** Where to go back to: a path on this site only - never another host, never a guess. */
    private static function back(mixed $back): string
    {
        return is_string($back) && strlen($back) <= 500 && preg_match('#^/(?![/\\\\])[^\s]*$#', $back) === 1 ? $back : route('storefront.home', absolute: false);
    }
}
