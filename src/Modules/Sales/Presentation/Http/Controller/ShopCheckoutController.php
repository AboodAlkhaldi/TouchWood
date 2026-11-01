<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrder;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrderHandler;
use Modules\Sales\Application\Command\StartCheckout\StartCheckoutHandler;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocument;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrder;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Presentation\Http\Resource\CheckoutPages;
use Shared\Application\StoreContext;
use Shared\Domain\Error\DomainError;

/*
| S5, S6 - checkout and the order placed (sales.md §4.1), a signed-in customer's. Checkout is one page whose
| quote is worked out again each time something on it changes - the address, the points, the way to pay, all
| in its address so a reload keeps them; anything refusing the whole checkout (an empty cart, a marked line,
| an account that may not order) goes back to the cart and says why. Placing sends only the quote's id
| (handoff §2); anything that changed since keeps the customer here with the new totals (R1-21, R1-22).
*/
final readonly class ShopCheckoutController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'sales::shop_checkout', 'sales::errors'];

    public function __construct(
        private Page $page,
        private CheckoutPages $pages,
        private StoreContext $stores,
    ) {}

    public function checkout(Request $request, StartCheckoutHandler $start): Response|RedirectResponse
    {
        $asked = $request->query('asked');

        try {
            $page = $this->pages->checkout(
                $this->stores->current()->value,
                app()->getLocale(),
                self::query($request, 'address'),
                $request->query('points') === '1',
                // 0 or none: as many as allowed.
                is_string($asked) && ctype_digit($asked) && strlen($asked) <= 9 && (int) $asked > 0 ? (int) $asked : null,
                self::query($request, 'way'),
                $start,
                // The carrier the customer chose among those serving the address (§9.5 #2).
                self::query($request, 'carrier'),
            );
        } catch (DomainError $error) {
            return redirect()->route('storefront.sales.cart')->withErrors(['form' => FormErrors::message($error)]);
        }

        return $this->page->render('Sales/Storefront/Checkout', $page->toArray(), self::WORDS);
    }

    public function place(Request $request, PlaceOrderHandler $handler): RedirectResponse
    {
        $quote = $request->input('quote');

        try {
            $placed = $handler->handle(new PlaceOrder($this->stores->current()->value, is_string($quote) ? $quote : ''));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->route('storefront.sales.placed', ['number' => $placed->number]);
    }

    public function placed(string $number, ViewOwnOrderHandler $view): Response
    {
        return $this->page->render('Sales/Storefront/Placed', $this->pages->placed($number, app()->getLocale(), $view)->toArray(), self::WORDS);
    }

    /** A transfer receipt on the order just placed (§1.9): up to three, a PDF or a photo. */
    public function receipt(Request $request, string $number, ViewOwnOrderHandler $view, UploadTransferDocumentHandler $handler): RedirectResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile || $file->getRealPath() === false) {
            return back()->withErrors(['form' => __('sales::shop_checkout.placed.no_file')]);
        }

        try {
            $order = $view->handle(new ViewOwnOrder($number));
            $handler->handle(new UploadTransferDocument($order->orderId, (string) $file->getRealPath(), $file->getClientOriginalName()));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('sales::shop_checkout.placed.uploaded'));
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' && strlen($value) <= 64 ? $value : null;
    }
}
