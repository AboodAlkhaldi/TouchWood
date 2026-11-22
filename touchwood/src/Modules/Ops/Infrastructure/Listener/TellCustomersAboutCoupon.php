<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Events\CouponAssigned;

/**
 * Tells the customers a coupon was given to (ops.md §1.5 C15): `CouponAssigned` is one event for the whole
 * assignment, so the customers it added are paged through `PromotionsApi::assignedCustomers`, each page read
 * at once. The code is read through `PromotionsApi::couponCode` when the message is written; a coupon deleted
 * since sends nothing. A service message (§1.3: coupons assigned to them).
 *
 * From the queue, retried on failure. Each customer is told once by an id made of the event and the customer
 * (a hash of the two, written as a UUID, in `ops.handled_events`), so a retry carries on where a failure
 * stopped, and an event delivered again sends nothing.
 */
final class TellCustomersAboutCoupon implements ShouldQueue
{
    /** Customers a page. */
    public const int PAGE = 500;

    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly AccessApi $access,
        private readonly PromotionsApi $promotions,
        private readonly CustomerMessages $customers,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(CouponAssigned $event): void
    {
        $message = new EventMessage(MessageKind::CouponAssigned, strtolower($event->storeId), ['couponId' => strtolower($event->couponId)]);
        $after = null;

        do {
            $page = $this->promotions->assignedCustomers($event->assignmentId, $after, self::PAGE);

            foreach ($this->access->customers($page) as $customer) {
                $this->handled->once(self::onceId($event->eventId, $customer->id), self::class, fn () => $this->customers->tellCustomer($message, $customer));
            }

            $after = count($page) === self::PAGE ? $page[count($page) - 1] : null;
        } while ($after !== null);
    }

    /** The same id for the same event and customer, always: the first 32 hex digits of a SHA-1. */
    public static function onceId(string $eventId, string $customerId): string
    {
        $hash = substr(sha1('coupon_assigned|'.strtolower($eventId).'|'.strtolower($customerId)), 0, 32);

        return implode('-', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)]);
    }
}
