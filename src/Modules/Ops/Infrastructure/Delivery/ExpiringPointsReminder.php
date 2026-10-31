<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Delivery;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Ramsey\Uuid\Uuid;

/**
 * C14, points expiring (ops.md §1.5): **7 days** before a lot expires, one message per customer per day,
 * summing every lot of theirs that expires that day - read through `LoyaltyApi::expiring` for the store's
 * own day a week ahead, midnight to midnight in its time zone. Every store, a switched-off one too (owner,
 * 2026-10-29).
 *
 * Each message is sent once by an id made of the store, the customer and the day (a name-based UUID in
 * `ops.handled_events`): a run repeated the same day - a retry, a second worker - sends nothing again.
 */
final readonly class ExpiringPointsReminder
{
    public const int DAYS_AHEAD = 7;

    /** Customers a page. */
    public const int PAGE = 500;

    /** The namespace of the ids C14 is sent once by. */
    private const string NAMESPACE = 'b5c7f0de-5a8f-4a43-9b2e-0b6a4f3e7c14';

    public function __construct(
        private PlatformApi $platform,
        private LoyaltyApi $loyalty,
        private CustomerMessages $customers,
        private HandledEvents $handled,
    ) {}

    public function remind(DateTimeImmutable $now): void
    {
        foreach ($this->platform->allStores() as $store) {
            $day = CarbonImmutable::instance($now)->setTimezone($store->timezone)->startOfDay()->addDays(self::DAYS_AHEAD);
            $date = $day->format('Y-m-d');
            $after = null;

            do {
                $page = $this->loyalty->expiring($store->storeId(), $day->toDateTimeImmutable(), $day->addDay()->toDateTimeImmutable(), $after, self::PAGE);

                foreach ($page->rows as $row) {
                    if ($row->points <= 0) {
                        continue;
                    }

                    $message = new EventMessage(MessageKind::PointsExpiring, $store->id, ['points' => $row->points, 'date' => $date]);
                    $once = Uuid::uuid5(self::NAMESPACE, "{$store->id}|".strtolower($row->customerId)."|{$date}")->toString();

                    $this->handled->once($once, self::class, fn () => $this->customers->tell($message, $row->customerId));
                }

                $after = $page->next;
            } while ($after !== null);
        }
    }
}
