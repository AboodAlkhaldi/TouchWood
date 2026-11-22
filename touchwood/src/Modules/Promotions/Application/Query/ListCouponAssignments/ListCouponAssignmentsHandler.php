<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListCouponAssignments;

use Modules\Promotions\Application\Query\AssignmentRow;
use Modules\Promotions\Application\Query\AssignmentsPage;
use Modules\Promotions\Application\Query\CustomerNames;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MarketingReader;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A coupon's assignments (promotions.md §1.4, §3): each customer by name, when, from which segment, how
 * many times they used it; newest first, a page at a time by keyset; the names in one Access call.
 */
final readonly class ListCouponAssignmentsHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private Coupons $coupons,
        private MarketingQueries $queries,
        private CustomerNames $names,
    ) {}

    /**
     * @throws Unauthorized|CouponNotFound
     */
    public function handle(ListCouponAssignments $query): AssignmentsPage
    {
        [$store, $permission] = $this->reader->covered(fn () => $this->coupons->storeOf($query->couponId), static fn (): CouponNotFound => new CouponNotFound);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);
        $coupon = $this->coupons->byId($store, strtolower($query->couponId)) ?? throw new CouponNotFound;
        $perPage = min(max($query->perPage, 1), self::PER_PAGE);

        $given = $this->queries->assignments($coupon->id, $query->beforeAt, $query->beforeCustomerId, $perPage + 1);
        $more = count($given) > $perPage;
        $given = array_slice($given, 0, $perPage);
        $names = $given === [] ? [] : $this->names->of(array_column($given, 'customerId'));

        $rows = array_map(static fn (array $one): AssignmentRow => new AssignmentRow(
            CustomerNames::pick($names, $one['customerId']), $one['assignedAt'], $one['segmentId'], $one['segmentNameAr'], $one['segmentNameEn'], $one['uses'],
        ), $given);
        $last = $more ? end($rows) : false;

        return new AssignmentsPage($rows, $last === false ? null : $last->assignedAt, $last === false ? null : $last->customer->id);
    }
}
