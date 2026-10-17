<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ListRedemptions;

use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\StaffPointsAction;
use Modules\Loyalty\Application\Query\PointsReader;
use Modules\Loyalty\Application\Query\PointsViews;
use Modules\Loyalty\Application\Query\RedemptionsPage;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * A store's redemptions (loyalty.md §3): `loyalty.points.view` in that store; newest first, a page at
 * a time by keyset (handoff §5.4).
 */
final readonly class ListRedemptionsHandler
{
    public const string PERMISSION = LoyaltyPermissions::VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffPointsAction $staff,
        private PointsReader $reader,
    ) {}

    /**
     * @throws InvalidPointsAttribute|PointsAccountNotFound|Unauthorized
     */
    public function handle(ListRedemptions $query): RedemptionsPage
    {
        $store = Inputs::store($query->storeId);
        // A store the reader does not cover reads as no points there (§7), as a customer's points do.
        $this->authorizer->authorize(self::PERMISSION, $this->staff->scope(self::PERMISSION, $store));
        $perPage = min(max($query->perPage, 1), PointsViews::PER_PAGE);

        $rows = $this->reader->redemptions($store->value, $query->beforeAt, $query->beforeId, $perPage + 1);
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $last = $more ? end($rows) : false;

        return new RedemptionsPage($rows, $last === false ? null : $last->occurredAt, $last === false ? null : $last->id);
    }
}
