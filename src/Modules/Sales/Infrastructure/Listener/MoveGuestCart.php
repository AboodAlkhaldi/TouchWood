<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Modules\Access\Public\Events\GuestBecameCustomer;
use Modules\Sales\Application\Cart\GuestCartMover;
use Throwable;

/**
 * A guest signed in or registered (sales.md §6): their carts move to the account, or merge into its
 * carts. **Not queued**: it runs right after the sign-in's transaction, so the next page shows the cart
 * already the customer's. **It never fails the sign-in**: Access starts the session after this runs, so
 * a failure here is reported and the guest's carts stay as they were — moved at the next sign-in. Done
 * once however often told (`GuestCartMover`).
 */
final readonly class MoveGuestCart
{
    public function __construct(
        private GuestCartMover $mover,
    ) {}

    public function handle(GuestBecameCustomer $event): void
    {
        try {
            $this->mover->move($event->guestId, $event->customerId);
        } catch (Throwable $failed) {
            report($failed);
        }
    }
}
