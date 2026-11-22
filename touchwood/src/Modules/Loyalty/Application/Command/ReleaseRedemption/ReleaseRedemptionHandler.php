<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\ReleaseRedemption;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Modules\Loyalty\Public\Dto\SettlePointsHold;
use Shared\Application\Unauthorized;

/**
 * The try did not pay: its held points are free again, **with their own dates**, and no redemption is
 * recorded (loyalty.md §9.6). Points whose date passed while they were held expire now, as they would
 * have: the hold goes first, then the account is locked again, which expires its dead lots. Once;
 * nothing to release does nothing.
 */
final readonly class ReleaseRedemptionHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private CallerCheck $caller,
        private PointsAccounts $accounts,
        private RedemptionHolds $holds,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized
     */
    public function handle(SettlePointsHold $change): void
    {
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $this->caller->authorize($change->caller, $store);
        $held = $this->holds->of($orderId);

        if ($held === null) {
            return;
        }

        if ($held->storeId !== $store->value) {
            throw new InvalidPointsAttribute('store', 'not the order\'s store');
        }

        $account = $this->accounts->byId($held->accountId) ?? throw new LogicException('A hold of an account that is gone.');

        $this->db->transaction(function () use ($account, $store, $orderId): void {
            $now = CarbonImmutable::now();
            $this->accounts->lockOrCreate($account->customerId, $store, $now);

            if ($this->holds->drop($orderId)) {
                // Locked again with the hold gone: what passed while it was held expires now.
                $this->accounts->lockOrCreate($account->customerId, $store, $now);
            }
        }, 3);
    }
}
