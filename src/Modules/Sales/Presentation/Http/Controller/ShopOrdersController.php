<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Application\Command\BuyAgain\BuyAgain;
use Modules\Sales\Application\Command\BuyAgain\BuyAgainHandler;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrder;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrderHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\UploadReturnPhoto\UploadReturnPhoto;
use Modules\Sales\Application\Command\UploadReturnPhoto\UploadReturnPhotoHandler;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturn;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturnHandler;
use Modules\Sales\Application\Query\ListOwnOrders\ListOwnOrdersHandler;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Application\Query\ViewOwnReturn\ViewOwnReturnHandler;
use Modules\Sales\Presentation\Http\Resource\OwnPages;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/*
| S7–S10 - the customer's orders and returns (sales.md §4.1): My Orders beside the account's tabs, an order with
| Cancel, the receipts, Buy Again and Ask for a Return, the return asked with up to three photos, and a return
| with Withdraw while it waits. None names an account: each handler reads who is asking, and another
| customer's order or return is not found.
*/
final readonly class ShopOrdersController
{
    /** Buy Again's lines that could not go in, said on the cart once (R2-24). */
    public const string SKIPPED = 'sales.buy_again_skipped';

    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'sales::shop_orders', 'sales::shop_checkout', 'sales::errors'];

    /** The most photos a return asks with (R2-23). */
    private const int PHOTOS = 3;

    public function __construct(
        private Page $page,
        private OwnPages $pages,
    ) {}

    public function orders(Request $request, ListOwnOrdersHandler $list): Response
    {
        $after = $request->query('after');

        return $this->page->render('Sales/Storefront/MyOrders', $this->pages->orders(is_string($after) && strlen($after) <= 64 ? $after : null, app()->getLocale(), $list)->toArray(), self::WORDS);
    }

    public function order(string $number, ViewOwnOrderHandler $view): Response
    {
        return $this->page->render('Sales/Storefront/Order', $this->pages->order($number, app()->getLocale(), $view)->toArray(), self::WORDS);
    }

    public function cancel(Request $request, string $number, ViewOwnOrderHandler $view, CancelOwnOrderHandler $handler): RedirectResponse
    {
        $other = trim(self::text($request, 'other'));

        try {
            $handler->handle(new CancelOwnOrder($this->pages->orderId($number, $view), self::text($request, 'reason'), $other === '' ? null : $other));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('sales::shop_orders.toast.cancelled'));
    }

    /** Buy Again (R1-33, R2-24): to the cart of the order's store, which says what could not go in and why. */
    public function buyAgain(Request $request, string $number, BuyAgainHandler $handler, PlatformApi $platform): RedirectResponse
    {
        try {
            $result = $handler->handle(new BuyAgain($number));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        $store = $platform->store(StoreId::fromString($result->storeId));
        $request->session()->flash(self::SKIPPED, OwnPages::skipped($result->skipped, app()->getLocale()));

        return redirect()->route('storefront.sales.cart', $store === null ? [] : ['store' => $store->code])
            ->with('status', __('sales::shop_orders.toast.bought_again', ['number' => $number]));
    }

    public function askReturn(string $number, ViewOwnOrderHandler $view): Response
    {
        return $this->page->render('Sales/Storefront/AskReturn', $this->pages->askReturn($number, app()->getLocale(), $view)->toArray(), self::WORDS);
    }

    /** The return asked, then its photos, each its own file (R2-23): a photo refused leaves the return asked. */
    public function requestReturn(Request $request, string $number, ViewOwnOrderHandler $view, RequestReturnHandler $handler, UploadReturnPhotoHandler $photos): RedirectResponse
    {
        $sent = $request->input('lines');
        $lines = [];

        foreach (is_array($sent) ? $sent : [] as $line) {
            if (is_array($line) && is_string($line['orderLineId'] ?? null) && is_numeric($line['quantity'] ?? null) && (int) $line['quantity'] > 0) {
                $lines[] = ['orderLineId' => $line['orderLineId'], 'quantity' => (int) $line['quantity']];
            }
        }

        $other = trim(self::text($request, 'other'));

        try {
            $returnId = $handler->handle(new RequestReturn($this->pages->orderId($number, $view), self::text($request, 'reason'), $other === '' ? null : $other, $lines));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        $files = $request->file('photos');
        $refused = null;

        foreach (array_slice(is_array($files) ? $files : [], 0, self::PHOTOS) as $file) {
            if ($file->getRealPath() === false) {
                continue;
            }

            try {
                $photos->handle(new UploadReturnPhoto($returnId, (string) $file->getRealPath(), $file->getClientOriginalName()));
            } catch (DomainError $error) {
                $refused = FormErrors::message($error);
            }
        }

        $done = redirect()->route('storefront.sales.return', ['return' => $returnId])->with('status', __('sales::shop_orders.toast.return_asked'));

        return $refused === null ? $done : $done->withErrors(['form' => $refused]);
    }

    public function returned(string $return, ViewOwnReturnHandler $view, ViewOwnOrderHandler $orders): Response
    {
        return $this->page->render('Sales/Storefront/Return', $this->pages->returned($return, app()->getLocale(), $view, $orders)->toArray(), self::WORDS);
    }

    public function withdraw(Request $request, string $return, WithdrawReturnHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new WithdrawReturn($return));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('sales::shop_orders.toast.withdrawn'));
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }
}
