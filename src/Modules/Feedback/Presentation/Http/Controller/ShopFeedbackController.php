<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Controller;

use App\Http\FormErrors;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
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
| A guest who presses Save (owner's Q18 (a)) is sent to sign in; the address kept for after it is a signed
| one that saves the product and goes back to the page the heart was pressed on - so the heart is never
| saved for a link someone else made.
*/
final readonly class ShopFeedbackController
{
    /** How long the kept address stays good: a sign-in, a forgotten password, a second try. */
    private const int SAVE_AFTER_MINUTES = 60;

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
            $request->session()->put('url.intended', URL::temporarySignedRoute(
                'storefront.feedback.save-after-sign-in',
                now()->addMinutes(self::SAVE_AFTER_MINUTES),
                ['product' => $product, 'back' => self::back($request->input('back'))],
            ));

            return redirect()->route('storefront.sign-in')->with('status', __('feedback::shop.toast.sign_in_to_save'));
        }

        return self::act($request, fn () => $handler->handle(new SaveToWishlist($stores->current()->value, $product)), 'saved');
    }

    public function remove(Request $request, StoreContext $stores, RemoveFromWishlistHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RemoveFromWishlist($stores->current()->value, self::product($request))), 'removed');
    }

    /** After signing in from the heart: the product saved, and back to its page. */
    public function saveAfterSignIn(Request $request, StoreContext $stores, SaveToWishlistHandler $handler): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('storefront.home');
        }

        try {
            $handler->handle(new SaveToWishlist($stores->current()->value, self::product($request)));
        } catch (DomainError $error) {
            return redirect(self::back($request->query('back')))->withErrors(['form' => FormErrors::message($error)]);
        }

        return redirect(self::back($request->query('back')))->with('status', __('feedback::shop.toast.saved'));
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
