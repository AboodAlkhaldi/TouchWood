<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\FollowCheckoutPayment;

use Modules\Sales\Application\Checkout\CheckoutTryView;
use Modules\Sales\Application\Checkout\TryPage;
use Modules\Sales\Application\Checkout\TrySettling;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\CheckoutTryNotFound;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `CompleteOnlineOrder` from the page (§9.5 #4): Payments asked first, then the order made or the try released at
 * once, whichever the answer says. The event does the same if the page never comes back: once, either way. A
 * store switched off meanwhile still finishes its try (payments.md §1.10).
 */
final readonly class FollowCheckoutPaymentHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_PLACE;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private CheckoutTries $tries,
        private TrySettling $settling,
        private TryPage $page,
    ) {}

    /** @throws Unauthorized|CheckoutTryNotFound */
    public function handle(FollowCheckoutPayment $command): CheckoutTryView
    {
        $tryId = strtolower(trim($command->tryId));
        $try = Ulids::valid($tryId) ? $this->tries->find($tryId) : null;

        if ($try === null) {
            throw new CheckoutTryNotFound;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store(StoreId::fromString($try->storeId)));
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        if ($try->customerId !== strtolower($actor->id)) {
            throw new CheckoutTryNotFound;
        }

        return $this->page->view($try->id, $this->settling->settle($try));
    }
}
