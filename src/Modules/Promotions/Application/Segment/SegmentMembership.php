<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Segment;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\Segment;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Public\Enums\SegmentRule;
use Modules\Promotions\Public\Enums\SegmentType;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who a segment holds (promotions.md §1.5): its rule over the store's placed orders, or the customers
 * picked by hand — of its audience, never an anonymized one (a hand-picked one leaves at the next
 * refresh too). Read in pages of a thousand and batches, never one customer at a time (handoff §5.4).
 *
 * Who may be in a store's segment: a customer registered in the store, or who placed an order in it
 * (owner, 2026-10-08).
 */
final readonly class SegmentMembership
{
    private const int PAGE = 1000;

    public function __construct(
        private AccessApi $access,
        private OrderFacts $facts,
        private Segments $segments,
    ) {}

    /**
     * The segment's members as they are now.
     *
     * @return list<string> customer ids
     */
    public function of(Segment $segment, DateTimeImmutable $now): array
    {
        if ($segment->type === SegmentType::Static) {
            return $this->live($segment, $this->segments->memberIds($segment->id));
        }

        $months = (int) $segment->ruleMonths;
        $since = CarbonImmutable::instance($now)->subMonthsNoOverflow($months);

        return match ($segment->rule) {
            SegmentRule::NoOrdersEver => $this->neverOrdered($segment),
            SegmentRule::NoOrderFor => $this->paged(fn (?string $after): array => $this->facts->lastOrderBefore($segment->storeId, $since, $after, self::PAGE), $segment),
            SegmentRule::AtLeastOrders => $this->paged(fn (?string $after): array => $this->facts->atLeastOrdersSince($segment->storeId, $since, (int) $segment->ruleOrders, $after, self::PAGE), $segment),
            null => [],
        };
    }

    /**
     * Customers picked by hand for the segment (§1.5): each must be of its audience, not anonymized,
     * and registered in its store or have ordered in it.
     *
     * @param  list<string>  $customerIds  lower-case ids
     * @return list<string> each once, in id order
     *
     * @throws InvalidPromotionAttribute
     */
    public function picked(Segment $segment, array $customerIds): array
    {
        $customerIds = array_values(array_unique($customerIds));
        sort($customerIds);
        $type = AccountType::from($segment->audience->value);
        $kept = [];

        foreach (array_chunk($customerIds, self::PAGE) as $chunk) {
            $found = $this->access->customers($chunk);
            $ordered = array_flip($this->facts->whoOrdered($segment->storeId, $chunk));

            foreach ($chunk as $customerId) {
                $customer = $found[$customerId] ?? null;

                if ($customer === null || $customer->anonymized || $customer->accountType !== $type
                    || ($customer->homeStoreId !== $segment->storeId && ! isset($ordered[$customerId]))) {
                    throw new InvalidPromotionAttribute('members', "{$customerId} cannot be in this segment");
                }

                $kept[] = $customerId;
            }
        }

        return $kept;
    }

    /**
     * `NO_ORDERS_EVER`: the store's registered customers of the audience (Access already leaves the
     * anonymized out) less those who ever ordered there.
     *
     * @return list<string>
     */
    private function neverOrdered(Segment $segment): array
    {
        $store = StoreId::fromString($segment->storeId);
        $type = AccountType::from($segment->audience->value);
        $members = [];
        $after = null;

        do {
            $page = $this->access->customerIdsInStore($store, $type, $after, self::PAGE);
            $ordered = array_flip($this->facts->whoOrdered($segment->storeId, $page));

            foreach ($page as $customerId) {
                if (! isset($ordered[$customerId])) {
                    $members[] = $customerId;
                }
            }

            $after = $page === [] ? null : $page[array_key_last($page)];
        } while (count($page) === self::PAGE);

        return $members;
    }

    /**
     * A rule's customers, page after page, each page kept to the live customers of the audience.
     *
     * @param  callable(?string): list<string>  $page
     * @return list<string>
     */
    private function paged(callable $page, Segment $segment): array
    {
        $members = [];
        $after = null;

        do {
            $ids = $page($after);
            array_push($members, ...$this->live($segment, $ids));
            $after = $ids === [] ? null : $ids[array_key_last($ids)];
        } while (count($ids) === self::PAGE);

        return $members;
    }

    /**
     * Of these customers, those of the segment's audience and not anonymized — read in batches.
     *
     * @param  list<string>  $customerIds
     * @return list<string>
     */
    private function live(Segment $segment, array $customerIds): array
    {
        $type = AccountType::from($segment->audience->value);
        $live = [];

        foreach (array_chunk($customerIds, self::PAGE) as $chunk) {
            foreach ($this->access->customers($chunk) as $customer) {
                if (! $customer->anonymized && $customer->accountType === $type) {
                    $live[] = $customer->id;
                }
            }
        }

        sort($live);

        return $live;
    }
}
