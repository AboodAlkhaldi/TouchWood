<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\OpenCheckoutPayment;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentSessionDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\PaymentInProgress;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\QuoteNotFound;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Modules\Sales\Domain\Repository\Quotes;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A session for a quote's total, lasting the quote's time **plus the window and the wait**, so a Pay at its last
 * second has its full time (payments.md §1.4 step 0). Opening again reuses an unused one, uncounted by the limit,
 * which is Payments' own. While a try is live there is no form (step 9).
 */
final readonly class OpenCheckoutPaymentHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_PLACE;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Quotes $quotes,
        private Carts $carts,
        private CheckoutTries $tries,
        private PaymentsApi $payments,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|QuoteNotFound|QuoteExpired|WayToPayNotOffered|PaymentInProgress
     */
    public function handle(OpenCheckoutPayment $command): PaymentSessionDto
    {
        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSalesAttribute('store', 'not a store id');
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $customerId = strtolower($actor->id);
        $quoteId = strtolower(trim($command->quoteId));
        $quote = Ulids::valid($quoteId) ? $this->quotes->find($quoteId) : null;

        if ($quote === null || $quote->customerId !== $customerId || $quote->storeId !== $store->value) {
            throw new QuoteNotFound;
        }

        if (CarbonImmutable::now() >= $quote->expiresAt) {
            throw new QuoteExpired;
        }

        if ($quote->wayToPay !== WayToPay::Online->value) {
            throw new WayToPayNotOffered;
        }

        $cart = $this->carts->find($store, $customerId, null);

        if ($cart !== null && $this->tries->liveOfCart($cart->id) !== null) {
            throw new PaymentInProgress;
        }

        $view = QuoteView::fromArray($quote->id, $quote->storeId, $quote->customerId, $quote->addressId, WayToPay::Online, $quote->expiresAt, $quote->createdAt, $quote->result);
        $timers = $this->payments->timers($store);

        return $this->payments->openSession(new SessionRequestDto(
            $store, $quote->id, $customerId, $view->totals->orderTotal, $command->locale,
            CarbonImmutable::instance($quote->expiresAt)->addMinutes($timers->window + $timers->wait),
        ));
    }
}
