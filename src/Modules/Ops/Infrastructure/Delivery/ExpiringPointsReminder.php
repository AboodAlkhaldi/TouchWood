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

/**
 * C14, points expiring (ops.md §1.5): **7 days** before a lot expires, one message per customer per day,
 * summing every lot of theirs that expires that day - read through `LoyaltyApi::expiring` for the store's
 * own day a week ahead, midnight to midnight in its time zone. Every store, a switched-off one too (owner,
 * 2026-10-29).
 *
 * Each message is sent once by an id made of the store, the customer and the day (a hash of the three,
 * written as a UUID, in `ops.handled_events`): a run repeated the same day - a retry, a second worker -
 * sends nothing again.
 */
final readonly class ExpiringPointsReminder
{
    public const int DAYS_AHEAD = 7;

    /** Customers a page. */
    public const int PAGE = 500;

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
                    $once = self::onceId($store->id, $row->customerId, $date);

                    $this->handled->once($once, self::class, fn () => $this->customers->tell($message, $row->customerId));
                }

                $after = $page->next;
            } while ($after !== null);
        }
    }

    /** The same id for the same store, customer and day, always: the first 32 hex digits of a SHA-1. */
    public static function onceId(string $storeId, string $customerId, string $date): string
    {
        $hash = substr(sha1('points_expiring|'.strtolower($storeId).'|'.strtolower($customerId).'|'.$date), 0, 32);

        return implode('-', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)]);
    }
}
