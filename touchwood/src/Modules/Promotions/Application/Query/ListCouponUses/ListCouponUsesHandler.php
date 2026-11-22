<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListCouponUses;

use Modules\Promotions\Application\Query\CouponUseRow;
use Modules\Promotions\Application\Query\CouponUsesPage;
use Modules\Promotions\Application\Query\CustomerNames;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MarketingReader;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * A coupon's uses (promotions.md §3): each by the order's **public number**, never its id (handoff
 * §5.3), the customer by name; newest first, a page at a time by keyset; the names in one Access call.
 */
final readonly class ListCouponUsesHandler
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
    public function handle(ListCouponUses $query): CouponUsesPage
    {
        [$store, $permission] = $this->reader->covered(fn () => $this->coupons->storeOf($query->couponId), static fn (): CouponNotFound => new CouponNotFound);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);
        $coupon = $this->coupons->byId($store, strtolower($query->couponId)) ?? throw new CouponNotFound;
        $perPage = min(max($query->perPage, 1), self::PER_PAGE);

        $uses = $this->queries->uses($coupon->id, $query->beforeAt, $query->beforeId, $perPage + 1);
        $more = count($uses) > $perPage;
        $uses = array_slice($uses, 0, $perPage);
        $names = $uses === [] ? [] : $this->names->of(array_column($uses, 'customerId'));

        $rows = array_map(static fn (array $use): CouponUseRow => new CouponUseRow(
            $use['id'], $use['orderNumber'], CustomerNames::pick($names, $use['customerId']), Money::of($use['discountMinor'], $coupon->currencyCode), $use['usedAt'],
        ), $uses);
        $last = $more ? end($rows) : false;

        return new CouponUsesPage($rows, $last === false ? null : $last->usedAt, $last === false ? null : $last->id);
    }
}
