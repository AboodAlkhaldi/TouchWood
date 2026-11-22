<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Inertia\Response;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Application\Query\MyCouponRow;
use Modules\Promotions\Application\Query\ViewMyCoupons\ViewMyCoupons;
use Modules\Promotions\Application\Query\ViewMyCoupons\ViewMyCouponsHandler;
use Modules\Promotions\Presentation\Http\Resource\MarketingScreen;
use Modules\Promotions\Presentation\Http\Resource\MoneyData;
use Modules\Promotions\Presentation\Http\Resource\MyCouponData;
use Modules\Promotions\Presentation\Http\Resource\MyCouponsPage;
use Shared\Application\StoreContext;

/*
| M5 - the customer's own coupons in the shop's store (promotions.md §4.1), a page of their account beside
| Access's own tabs (access.md amendment 50), always offered (owner's Q12). It never names an account:
| the handler reads who is asking.
*/
final readonly class MyCouponsController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'promotions::shop_coupons', 'promotions::discounts', 'promotions::errors'];

    public function __construct(
        private Page $page,
    ) {}

    public function show(StoreContext $stores, PlatformApi $platform, MarketingScreen $screen, ViewMyCouponsHandler $view): Response
    {
        $storeId = $stores->current();
        $store = $platform->store($storeId);
        $exponent = $store->currencyExponent ?? 2;
        $money = static fn ($value): ?MoneyData => $value === null ? null : MoneyData::of($value, $exponent);

        return $this->page->render('Promotions/Storefront/Coupons', (new MyCouponsPage(
            array_map(static fn (MyCouponRow $row): MyCouponData => new MyCouponData(
                $row->code,
                $screen->name($row->nameAr, $row->nameEn),
                $row->discountKind->value,
                $row->percent,
                $money($row->cap),
                $money($row->amount),
                $money($row->minimum),
                $row->endsAt === null ? null : MarketingScreen::moment($row->endsAt),
                $row->usesLeft,
            ), $view->handle(new ViewMyCoupons($storeId->value))),
            $store->timezone ?? 'UTC',
        ))->toArray(), self::WORDS);
    }
}
