<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\StorefrontArea;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Sales\Application\Command\AddCartLine\AddCartLine;
use Modules\Sales\Application\Command\AddCartLine\AddCartLineHandler;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLine;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLineHandler;
use Modules\Sales\Application\Command\DismissCartNotice\DismissCartNotice;
use Modules\Sales\Application\Command\DismissCartNotice\DismissCartNoticeHandler;
use Modules\Sales\Application\Command\RemoveCartLine\RemoveCartLine;
use Modules\Sales\Application\Command\RemoveCartLine\RemoveCartLineHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Query\ViewCart\ViewCartHandler;
use Modules\Sales\Presentation\Http\Resource\CartPages;
use Shared\Application\StoreContext;
use Shared\Domain\Error\DomainError;

/*
| S4 - the cart (sales.md §4.1), a guest's or a customer's, in the store being browsed: the page, and the
| changes it sends - a line's pieces set, a line taken off, the coupon typed - each back to the cart with
| its toast, or its refusal in its own words. Add to Cart (S2) sends `add` from the product page; the notice's
| Close puts it away for good (R2-8; §4.2 B17).
*/
final readonly class ShopCartController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'sales::shop_cart', 'sales::errors'];

    public function __construct(
        private Page $page,
        private CartPages $pages,
        private StoreContext $stores,
    ) {}

    public function cart(Request $request, ViewCartHandler $view): Response
    {
        $skipped = $request->session()->get(ShopOrdersController::SKIPPED);

        return $this->page->render('Sales/Storefront/Cart', $this->pages->cart($this->stores->current()->value, app()->getLocale(), $view, is_array($skipped) ? array_values($skipped) : [])->toArray(), self::WORDS);
    }

    public function add(Request $request, AddCartLineHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new AddCartLine($this->store(), self::text($request, 'variant'), self::text($request, 'mode'), $request->integer('quantity'))), null);
    }

    public function change(Request $request, ChangeCartLineHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new ChangeCartLine($this->store(), self::text($request, 'variant'), self::text($request, 'mode'), $request->integer('quantity'))), null);
    }

    public function remove(Request $request, RemoveCartLineHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RemoveCartLine($this->store(), self::text($request, 'variant'), self::text($request, 'mode'))), 'removed');
    }

    public function coupon(Request $request, SetCartCouponHandler $handler): RedirectResponse
    {
        $code = trim(self::text($request, 'code'));

        return self::act($request, fn () => $handler->handle(new SetCartCoupon($this->store(), $code === '' ? null : $code)), $code === '' ? 'coupon_cleared' : 'coupon_kept');
    }

    public function dismissNotice(Request $request, DismissCartNoticeHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new DismissCartNotice($this->store())), null);
    }

    /** A guest's Checkout (R1-16): to sign in or register, then straight back to the cart, theirs joined to the account's. */
    public function signIn(Request $request): RedirectResponse
    {
        $request->session()->put('url.intended', route('storefront.sales.cart'));

        return redirect()->route('storefront.sign-in');
    }

    private function store(): string
    {
        return $this->stores->current()->value;
    }

    /** The change made, then back where it was sent from; a quantity set says nothing - the line shows it. */
    private static function act(Request $request, Closure $work, ?string $done): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return $done === null ? back() : back()->with('status', __("sales::shop_cart.toast.{$done}"));
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }
}
