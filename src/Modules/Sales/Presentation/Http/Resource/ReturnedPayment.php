<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Sales\Application\Command\ReturnToCheckout\ReturnToCheckout;
use Modules\Sales\Application\Command\ReturnToCheckout\ReturnToCheckoutHandler;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\ActorType;

/**
 * Checkout or the cart opened while a try may stand in the way (payments.md §1.4 step 8, §4.2 Q8): null when none does.
 * With an order number, it was paid and the page goes there; otherwise it can still go through. A guest has no try.
 */
final readonly class ReturnedPayment
{
    public function __construct(
        private ActorContext $actors,
        private ReturnToCheckoutHandler $handler,
    ) {}

    public function read(string $storeId): ?CheckoutTryData
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            return null;
        }

        $view = $this->handler->handle(new ReturnToCheckout($storeId));

        return $view === null ? null : CheckoutTryData::of($view);
    }
}
