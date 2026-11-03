<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Controller;

use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPayment;
use Modules\Sales\Application\Command\FollowCheckoutPayment\FollowCheckoutPaymentHandler;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPayment;
use Modules\Sales\Application\Command\OpenCheckoutPayment\OpenCheckoutPaymentHandler;
use Modules\Sales\Application\Command\PayOnline\PayOnline;
use Modules\Sales\Application\Command\PayOnline\PayOnlineHandler;
use Modules\Sales\Presentation\Http\Resource\CheckoutTryData;
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
    public function __construct(
        private StoreContext $stores,
        private PlatformApi $platform,
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

        return new JsonResponse((new PayStartData(
            $paying->tryId,
            $paying->start->frameUrl,
            $paying->start->windowEndsAt->format(DateTimeInterface::ATOM),
            now()->format(DateTimeInterface::ATOM),
        ))->toArray());
    }

    /** The page's own answer: Payments asked, the order made or the try released. For this customer's own try alone. */
    public function follow(string $try, FollowCheckoutPaymentHandler $handler): JsonResponse
    {
        $view = $handler->handle(new FollowCheckoutPayment($try));

        return new JsonResponse(CheckoutTryData::of($view, $this->mainStoreIfClosed())->toArray());
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
