<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\DeductPoints;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\PointsAudit;
use Modules\Loyalty\Application\Points\StaffPointsAction;
use Modules\Loyalty\Domain\Exception\DeductionTooLarge;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Loyalty\Domain\Exception\PointsHeld;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Modules\Loyalty\Public\Events\PointsChangedByHand;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * Removes points by hand (loyalty.md §1.9): under `loyalty.points.adjust` in the points' store, with a
 * reason, audited. Taken from the lots that expire first, never more than the live balance (§1.4) —
 * worked out under the account's lock, after its dead lots expired — and final: removed points never
 * come back. A customer with no account in the store has nothing to remove.
 */
final readonly class DeductPointsHandler
{
    public const string PERMISSION = LoyaltyPermissions::ADJUST;

    public function __construct(
        private Authorizer $authorizer,
        private StaffPointsAction $staff,
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
        private PlatformApi $platform,
        private ConnectionInterface $db,
        private Outbox $outbox,
        private RedemptionHolds $holds,
    ) {}

    /**
     * @throws DeductionTooLarge|InvalidPointsAttribute|PointsAccountNotFound|PointsHeld|Unauthorized
     */
    public function handle(DeductPoints $command): void
    {
        $store = Inputs::store($command->storeId);
        $this->authorizer->authorize(self::PERMISSION, $this->staff->scope(self::PERMISSION, $store));
        $staffId = $this->staff->staffId(self::PERMISSION);
        $customerId = $this->staff->customer($command->customerId);
        $points = Inputs::points($command->points);
        $reason = Inputs::reason($command->reason);

        if ($this->accounts->find($customerId, $store) === null) {
            throw new PointsAccountNotFound;
        }

        $this->db->transaction(function () use ($customerId, $store, $points, $staffId, $reason): void {
            $now = CarbonImmutable::now();
            $account = $this->accounts->lockOrCreate($customerId, $store, $now);

            if ($points > $account->balance) {
                throw new DeductionTooLarge($account->balance);
            }

            // Held for an online payment still in progress: untouchable until it ends (§9.6, gap 7.2).
            $free = $this->holds->freePoints($account->id, $now);

            if ($points > $free) {
                throw new PointsHeld($free, $this->holds->heldBy($account->id));
            }

            $entry = $this->ledger->deduct($account, $points, $staffId, $reason, $now);
            $this->platform->recordAudit(PointsAudit::byHand('loyalty.points.deducted', $account, $points, $reason));
            // Ops tells the customer, with the admin's reason (§6; ops.md §1.5 C13).
            $this->outbox->record(new PointsChangedByHand((string) Str::uuid(), $entry, $store->value, $customerId, $now));
        }, 3);
    }
}
