<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ReturnToCheckout;

use InvalidArgumentException;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Sales\Application\Checkout\CheckoutTryView;
use Modules\Sales\Application\Checkout\TryPage;
use Modules\Sales\Application\Checkout\TryRelease;
use Modules\Sales\Application\Checkout\TrySettling;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Model\CheckoutTry;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Nothing sent to the gateway yet: the try ends there and then, **as if Pay had never been pressed**. The charge
 * made: it cannot be pretended away, and the page says until when it can still go through; paid, even by the event
 * while they were away: their new order, once.
 * Null: no payment stands in the way.
 */
final readonly class ReturnToCheckoutHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_PLACE;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Carts $carts,
        private CheckoutTries $tries,
        private PaymentsApi $payments,
        private TryRelease $release,
        private TrySettling $settling,
        private TryPage $page,
    ) {}

    /** @throws Unauthorized|InvalidSalesAttribute */
    public function handle(ReturnToCheckout $command): ?CheckoutTryView
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
        $cart = $this->carts->find($store, $customerId, null);
        $try = $cart === null ? null : $this->tries->liveOfCart($cart->id);

        if ($try === null) {
            // The event made their order while they were away: told once, or the cart would just look short.
            $paid = $this->tries->unseenPaid($store->value, $customerId);

            return $paid === null ? null : $this->page->view($paid->id, $this->settling->settle($paid));
        }

        if ($this->payments->abandon($try->attemptId)) {
            $this->release->release($try->id);

            return null;
        }

        $view = $this->page->view($try->id, $this->settling->settle($try));

        return $view->state === CheckoutTry::RELEASED ? null : $view;
    }
}
