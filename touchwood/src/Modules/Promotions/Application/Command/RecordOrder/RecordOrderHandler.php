<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RecordOrder;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Promotions\Application\Coupon\CallerCheck;
use Modules\Promotions\Application\Coupon\Ceiling;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\OrderFact;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Shared\Application\Unauthorized;

/**
 * Records a placed order's fact (promotions.md §1.5, §2.1), with or without a coupon, inside Sales's
 * checkout transaction: what the segments count, whatever happens to the order later (owner,
 * 2026-10-08), and the ceiling of that moment, which an edit keeps (§1.6). The same order again does
 * nothing; the same order said to be another store's or customer's is refused.
 */
final readonly class RecordOrderHandler
{
    public function __construct(
        private CallerCheck $caller,
        private OrderFacts $facts,
        private Ceiling $ceiling,
        private AccessApi $access,
    ) {}

    /**
     * @throws InvalidPromotionAttribute|Unauthorized
     */
    public function handle(RecordOrderCommand $command): void
    {
        $change = $command->change;
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $customerId = Inputs::id($change->customerId, 'customer');
        $recorded = $this->facts->of($orderId);

        // The scope is the order's recorded store when Promotions has one (§2.1).
        if ($recorded !== null && $recorded->storeId !== $store->value) {
            throw new InvalidPromotionAttribute('store', 'not the order\'s recorded store');
        }

        $this->caller->authorize($change->caller, $store);

        if ($recorded === null) {
            if ($this->access->customer($customerId) === null) {
                throw new InvalidPromotionAttribute('customer', 'no such customer');
            }

            $recorded = $this->facts->record($orderId, $store->value, $customerId, $change->placedAt, $this->ceiling->percentIn($store));
        }

        $this->sameOrder($recorded, $store->value, $customerId);
    }

    /** The fact there — the one just written, or the first of two at once — is this order's. */
    private function sameOrder(OrderFact $recorded, string $storeId, string $customerId): void
    {
        if ($recorded->storeId !== $storeId) {
            throw new InvalidPromotionAttribute('store', 'not the order\'s recorded store');
        }

        if ($recorded->customerId !== $customerId) {
            throw new InvalidPromotionAttribute('customer', 'the order is another customer\'s');
        }
    }
}
