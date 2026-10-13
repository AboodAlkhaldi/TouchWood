<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\AddPoints;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\PointsAudit;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Application\Points\StaffPointsAction;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * Adds points by hand (loyalty.md §1.9): under `loyalty.points.adjust` in the points' store — an
 * admin's job only — with a reason, audited. They form a lot that expires the store's period later,
 * the account made if absent. Works while the programme is off (§1.9, accepted with the spec).
 */
final readonly class AddPointsHandler
{
    public const string PERMISSION = LoyaltyPermissions::ADJUST;

    public function __construct(
        private Authorizer $authorizer,
        private StaffPointsAction $staff,
        private ProgrammeReader $programmes,
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws InvalidPointsAttribute|PointsAccountNotFound|Unauthorized
     */
    public function handle(AddPoints $command): void
    {
        $store = Inputs::store($command->storeId);
        $this->authorizer->authorize(self::PERMISSION, $this->staff->scope(self::PERMISSION, $store));
        $staffId = $this->staff->staffId(self::PERMISSION);
        $customerId = $this->staff->customer($command->customerId);
        $points = Inputs::points($command->points);
        $reason = Inputs::reason($command->reason);
        $months = $this->programmes->of($store)->expiryMonths;

        $this->db->transaction(function () use ($customerId, $store, $points, $staffId, $reason, $months): void {
            $now = CarbonImmutable::now();
            $account = $this->accounts->lockOrCreate($customerId, $store, $now);

            $this->ledger->add($account, $points, $staffId, $reason, $now->addMonthsNoOverflow($months), $now);
            $this->platform->recordAudit(PointsAudit::byHand('loyalty.points.added', $account, $points, $reason));
        }, 3);
    }
}
