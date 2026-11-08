<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Response as InertiaResponse;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentStartDto;
use Modules\Payments\Public\Enums\PaymentState;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPayment;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPaymentHandler;
use Modules\Sales\Application\Command\FollowRequestPayment\FollowRequestPayment;
use Modules\Sales\Application\Command\FollowRequestPayment\FollowRequestPaymentHandler;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPayment;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPaymentHandler;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPayment;
use Modules\Sales\Application\Command\OpenRequestPayment\OpenRequestPaymentHandler;
use Modules\Sales\Application\Command\PayOnline\PayOnline;
use Modules\Sales\Application\Command\PayOnline\PayOnlineHandler;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequest;
use Modules\Sales\Application\Command\PayOrderRequest\PayOrderRequestHandler;
use Modules\Sales\Application\Query\ViewOwnOrder\ViewOwnOrderHandler;
use Modules\Sales\Presentation\Http\Resource\CheckoutTryData;
use Modules\Sales\Presentation\Http\Resource\OwnPages;
use Modules\Sales\Presentation\Http\Resource\PaymentFormData;
use Modules\Sales\Presentation\Http\Resource\PayStartData;
use Shared\Application\StoreContext;
use Shared\Domain\ValueObject\StoreId;

/*
| Paying online at checkout (payments.md §1.4, §4.2; sales.md §9.8): JSON for the page that is already open, as its
| form and its clock must keep running through each answer. A refusal is the usual problem document (ProblemDetails).
| Nothing here opens a transaction: the charge and the asking must never run inside one (payments.md §9.13 #16).
*/
final readonly class ShopPaymentController
{
    /** @var list<string> */
    private const array REQUEST_WORDS = [...StorefrontArea::WORDS, 'sales::shop_orders', 'sales::shop_checkout', 'sales::shop_payment', 'sales::errors'];

    public function __construct(
        private StoreContext $stores,
        private PlatformApi $platform,
        private PaymentsApi $payments,
        private Page $page,
        private OwnPages $pages,
    ) {}

    /** The form for a quote: nothing is held or charged. Asked by the page, so a slow gateway never holds up checkout. */
    public function session(Request $request, OpenCheckoutPaymentHandler $handler): JsonResponse
    {
        $session = $handler->handle(new OpenCheckoutPayment($this->stores->current()->value, self::text($request, 'quote'), app()->getLocale()));

        return new JsonResponse((new PaymentFormData($session->sessionId, $session->scriptUrl))->toArray());
    }

    /** Pay: the quote and the session, never an amount (payments.md rule 12). The gateway's reason stays for staff. */
    public function pay(Request $request, PayOnlineHandler $handler): JsonResponse
    {
        $paying = $handler->handle(new PayOnline(
            $this->stores->current()->value,
            self::text($request, 'quote'),
            self::text($request, 'session'),
            app()->getLocale(),
            route('storefront.sales.payment.return'),
        ));

        return $this->started($paying->tryId, $paying->start);
    }

    /** The page's own answer: Payments asked, the order made or the try released. For this customer's own try alone. */
    public function follow(string $try, FollowCheckoutPaymentHandler $handler): JsonResponse
    {
        $view = $handler->handle(new FollowCheckoutPayment($try));

        return new JsonResponse(CheckoutTryData::of($view, $this->mainStoreIfClosed())->toArray());
    }

    /** A request's page (payments.md §4.2, Q12): anyone but the order's customer gets a 404. */
    public function requestPage(string $number, ViewOwnOrderHandler $view): InertiaResponse
    {
        return $this->page->render('Sales/Storefront/PayRequest', $this->pages->payRequest($number, $this->stores->current()->value, app()->getLocale(), $view)->toArray(), self::REQUEST_WORDS);
    }

    /** The form for a request: nothing is held or charged. */
    public function requestSession(string $number, OpenRequestPaymentHandler $handler): JsonResponse
    {
        $session = $handler->handle(new OpenRequestPayment($this->stores->current()->value, $number, app()->getLocale()));

        return new JsonResponse((new PaymentFormData($session->sessionId, $session->scriptUrl))->toArray());
    }

    /** Pay on a request's page: the session alone, its amount the request's (rule 12). */
    public function requestPay(Request $request, string $number, PayOrderRequestHandler $handler): JsonResponse
    {
        $paying = $handler->handle(new PayOrderRequest(
            $this->stores->current()->value,
            $number,
            self::text($request, 'session'),
            app()->getLocale(),
            route('storefront.sales.payment.return'),
        ));

        return $this->started($paying->requestId, $paying->start);
    }

    /** The request's answer, for this customer's own order alone. Paid goes back to the order, which says so once. */
    public function requestFollow(Request $request, string $number, string $paying, FollowRequestPaymentHandler $handler): JsonResponse
    {
        $view = $handler->handle(new FollowRequestPayment($this->stores->current()->value, $number, $paying));

        if ($view->payment === PaymentState::Succeeded) {
            $request->session()->flash('status', __('sales::shop_payment.request.received'));
        }

        return new JsonResponse(CheckoutTryData::ofRequest($view, $number, $this->mainStoreIfClosed())->toArray());
    }

    /**
     * Where the bank's page ends, inside our frame. It believes nothing and changes nothing: it only tells the page
     * around it to ask (rule 1). Opened on its own, it goes to checkout.
     */
    public function returned(): Response
    {
        return new Response(
            '<!doctype html><meta charset="utf-8"><title></title><script>'
            .'if (window.parent !== window) { window.parent.postMessage({ sender: "TW-payment-return" }, window.location.origin); }'
            .' else { window.location.replace("../../checkout"); }'
            .'</script>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    /** What the page needs once Pay has started. The hold's end is worked out here: the start names only the window's. */
    private function started(string $id, PaymentStartDto $start): JsonResponse
    {
        $timers = $this->payments->timers(StoreId::fromString($this->stores->current()->value));

        return new JsonResponse((new PayStartData(
            $id,
            $start->frameUrl,
            $start->windowEndsAt->format(DateTimeInterface::ATOM),
            CarbonImmutable::instance($start->windowEndsAt)->subMinutes($timers->window)->addMinutes($timers->hold)->format(DateTimeInterface::ATOM),
            now()->format(DateTimeInterface::ATOM),
        ))->toArray());
    }

    /** The main store's address while this store is switched off, for the closed store's one page (payments.md §1.10). */
    private function mainStoreIfClosed(): ?string
    {
        if ($this->platform->store(StoreId::fromString($this->stores->current()->value))->isActive ?? true) {
            return null;
        }

        foreach ($this->platform->stores() as $store) {
            if ($store->isBase) {
                return route('storefront.home', ['store' => strtolower($store->code), 'locale' => app()->getLocale()]);
            }
        }

        return null;
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }
}
