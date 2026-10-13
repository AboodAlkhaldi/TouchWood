<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ViewCustomerPoints;

use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\StaffPointsAction;
use Modules\Loyalty\Application\Query\PointsView;
use Modules\Loyalty\Application\Query\PointsViews;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * A customer's points as staff read them (loyalty.md §3): `loyalty.points.view` in **the store the
 * points belong to**, not the customer's home store; every change by hand with its admin's name (a
 * Super Admin withheld) and reason. A store the reader does not cover, or no such customer, is
 * `PointsAccountNotFound` — the same answer for both (§7); a customer with no points there reads 0.
 */
final readonly class ViewCustomerPointsHandler
{
    public const string PERMISSION = LoyaltyPermissions::VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffPointsAction $staff,
        private PointsAccounts $accounts,
        private PointsViews $views,
    ) {}

    /**
     * @throws InvalidPointsAttribute|PointsAccountNotFound|Unauthorized
     */
    public function handle(ViewCustomerPoints $query): PointsView
    {
        $store = Inputs::store($query->storeId);
        $this->authorizer->authorize(self::PERMISSION, $this->staff->scope(self::PERMISSION, $store));
        $customerId = $this->staff->customer($query->customerId);

        return $this->views->of($this->accounts->find($customerId, $store), $store, true, $query->beforeAt, $query->beforeId, $query->perPage);
    }
}
