<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Sales\Application\Command\AddCartLine\AddCartLine;
use Modules\Sales\Application\Command\AddCartLine\AddCartLineHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Query\CountCartPieces\CountCartPieces;
use Modules\Sales\Application\Query\CountCartPieces\CountCartPiecesHandler;
use Modules\Sales\Application\Query\ViewCart\CartView;
use Modules\Sales\Application\Query\ViewCart\ViewCart;
use Modules\Sales\Application\Query\ViewCart\ViewCartHandler;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Public\Enums\CartBlock;
use Shared\Application\Actor;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| What the cart page tells the shopper beside the lines (sales.md §4.2 B12): why checkout will not take this
| account, the shipping fee and the goods total from which it is free, the next gift not reached yet, and the
| points the order earns — against the goods total as far as the cart knows it, the subtotal less the coupon
| typed (owner, 2026-10-27). And the header's piece count (B13).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function cartExtras(): CartView
{
    return app(ViewCartHandler::class)->handle(new ViewCart(Fx::storeId('sa'), 'en'));
}

function cartExtrasPieces(string $storeCode = 'sa'): int
{
    return app(CountCartPiecesHandler::class)->handle(new CountCartPieces(Fx::storeId($storeCode)));
}

/** A browser with no guest cookie yet, acting as a guest. */
function cartExtrasAsGuest(): void
{
    app()->instance('request', Request::create('/sa/en', 'GET'));
    app()->forgetScopedInstances();
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
}

/** A size of its own product on sale in 'sa', with this much in stock — a gift's. */
function cartExtrasGift(int $stock): string
{
    ['variants' => [$gift]] = Px::ready(['90 cm']);
    Rx::switchOn('sa', [$gift]);
    Rx::set('sa', [$gift => '10.00']);
    Ix::count('sa', [$gift => $stock]);

    return $gift;
}

describe('the goods total', function () {
    it('is the subtotal with no coupon, and the subtotal less the coupon typed', function () {
        Cx::ready();
        $plain = cartExtras();
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $couponed = cartExtras();

        // 2 × 100.00 + 50.00 = 250.00; 15% off = 37.50.
        expect([$plain->subtotal?->minorUnits, $plain->goodsTotal?->minorUnits])->toBe([250_00, 250_00])
            ->and([$couponed->subtotal?->minorUnits, $couponed->goodsTotal?->minorUnits])->toBe([250_00, 212_50]);
    });

    it('takes nothing off for a coupon Promotions refuses', function () {
        Cx::ready();
        Pr::coupon('BIG-ONLY', with: ['minimum_minor' => 1000_00]);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'BIG-ONLY'));

        expect(cartExtras()->goodsTotal?->minorUnits)->toBe(250_00);
    });

    it('counts only the lines that can be ordered, the coupon too', function () {
        ['variants' => $variants] = Cx::ready();
        DB::table('catalog.store_variants')->where('variant_id', $variants[0])->update(['not_available_now' => true]);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));

        // Only the 50.00 line: 15% off = 7.50.
        expect(cartExtras()->goodsTotal?->minorUnits)->toBe(42_50);
    });

    it('is null with nothing that can be ordered, or no cart', function () {
        ['variants' => $variants] = Cx::ready();
        DB::table('catalog.store_variants')->whereIn('variant_id', $variants)->update(['not_available_now' => true]);
        $nothing = cartExtras();
        DB::table('sales.cart_lines')->delete();
        $empty = cartExtras();

        expect([$nothing->goodsTotal, $nothing->nextGift, $nothing->pointsToEarn])->toBe([null, null, 0])
            ->and([$empty->goodsTotal, $empty->nextGift, $empty->pointsToEarn])->toBe([null, null, 0]);
    });
});

describe('the shipping', function () {
    it('gives the store\'s fee and the amount shipping is free from, null when it never is', function () {
        Cx::ready();
        Cx::setting(SalesSettings::FLAT_FEE, 25_00);
        Cx::setting(SalesSettings::FREE_ABOVE, 300_00);
        $view = cartExtras();
        Cx::setting(SalesSettings::FREE_ABOVE, 0);
        $never = cartExtras();
        DB::table('sales.cart_lines')->delete();
        $empty = cartExtras();

        expect([$view->shippingFee?->minorUnits, $view->freeAbove?->minorUnits])->toBe([25_00, 300_00])
            ->and([$never->shippingFee?->minorUnits, $never->freeAbove])->toBe([25_00, null])
            // An empty cart still says it, in the store's currency.
            ->and([$empty->shippingFee?->minorUnits, $empty->shippingFee?->currencyCode])->toBe([25_00, Pr::currency()]);
    });
});

describe('the next gift', function () {
    it('is the lowest level not reached yet, and how much more the goods need — after the coupon', function () {
        Cx::ready();
        $gift = cartExtrasGift(5);
        Pr::giftLevel($gift, 100_00);
        Pr::giftLevel($gift, 500_00);
        Pr::giftLevel($gift, 300_00);
        $plain = cartExtras()->nextGift;
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $couponed = cartExtras()->nextGift;

        expect([$plain?->amount->minorUnits, $plain?->missing->minorUnits])->toBe([300_00, 50_00])
            ->and([$couponed?->amount->minorUnits, $couponed?->missing->minorUnits])->toBe([300_00, 87_50]);
    });

    it('passes over a level whose gift is not in stock after the cart\'s own pieces of it', function () {
        Cx::ready();
        $gone = cartExtrasGift(1);
        $there = cartExtrasGift(5);
        Pr::giftLevel($gone, 300_00);
        Pr::giftLevel($there, 400_00);
        $before = cartExtras()->nextGift?->amount->minorUnits;
        app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId('sa'), $gone, 'RETAIL', 1));
        $after = cartExtras()->nextGift?->amount->minorUnits;

        // The one piece of the first gift's size is in the cart now: none left to give. (The cart is 260.00.)
        expect([$before, $after])->toBe([300_00, 400_00]);
    });

    it('is the buyer\'s own: a company\'s levels for an approved company, none when nothing is above', function () {
        $b2b = Mockery::mock(B2BApi::class);
        $b2b->shouldReceive('isApproved')->andReturn(true);
        app()->instance(B2BApi::class, $b2b);
        Cx::ready('company');
        $gift = cartExtrasGift(5);
        Pr::giftLevel($gift, 300_00, with: ['for_public' => false]);
        $company = cartExtras()->nextGift?->amount->minorUnits;
        Pr::giftLevel($gift, 200_00);

        expect($company)->toBe(300_00)
            ->and(cartExtras()->nextGift?->amount->minorUnits)->toBe(300_00);

        Fx::actAsCustomer(Cx::customer());
        app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId('sa'), $gift, 'RETAIL', 1));

        // An individual's cart of 10.00: the company's level is not theirs.
        expect(cartExtras()->nextGift?->amount->minorUnits)->toBe(200_00);
    });
});

describe('the points', function () {
    it('are what Loyalty says the goods total earns, 0 with the programme off and for a guest', function () {
        Cx::ready();
        $off = cartExtras()->pointsToEarn;
        Lx::programme();
        $view = cartExtras();
        cartExtrasAsGuest();

        expect($off)->toBe(0)
            ->and($view->pointsToEarn)->toBe(app(LoyaltyApi::class)->earningPreview(Fx::storeId('sa'), $view->goodsTotal ?? throw new LogicException('No goods total.')))
            ->and($view->pointsToEarn)->toBeGreaterThan(0)
            ->and(cartExtras()->pointsToEarn)->toBe(0);
    });
});

describe('why checkout will not take this account', function () {
    it('names the first reason, and none exactly when Access and B2B would let the account order', function (array $columns, ?CartBlock $block) {
        ['customer' => $customer] = Cx::ready();
        if ($columns !== []) {
            DB::table('access.customers')->where('id', $customer)->update($columns);
        }

        $view = cartExtras();

        expect($view->mayNotOrder)->toBe($block)
            ->and($view->mayOrder)->toBe($block === null)
            ->and($view->mayOrder)->toBe(app(AccessApi::class)->customerMayOrder($customer));
    })->with([
        'may order' => [[], null],
        'email not confirmed' => [['email_verified_at' => null], CartBlock::EmailUnverified],
        // A phone is kept only once confirmed (customers_phone_verified_together): not confirmed is none yet.
        'phone not confirmed' => [['phone' => null, 'phone_verified_at' => null], CartBlock::PhoneUnverified],
        'neither: the email first' => [['email_verified_at' => null, 'phone' => null, 'phone_verified_at' => null], CartBlock::EmailUnverified],
        'blocked, before confirming anything' => [['status' => 'BLOCKED', 'email_verified_at' => null], CartBlock::AccountNotActive],
        'deletion asked for' => [['deletion_scheduled_for' => '2030-01-01 00:00:00+00'], CartBlock::AccountNotActive],
        'emptied' => [['anonymized_at' => '2026-01-01 00:00:00+00'], CartBlock::AccountNotActive],
    ]);

    it('asks a guest to sign in, and a company to be approved here', function () {
        Cx::ready('company');
        $company = cartExtras()->mayNotOrder;
        cartExtrasAsGuest();

        expect([$company, cartExtras()->mayNotOrder])->toBe([CartBlock::CompanyNotApproved, CartBlock::SignIn]);
    });
});

describe('the header\'s piece count (B13)', function () {
    it('counts the pieces on every line, over both modes, for a customer or a guest', function () {
        ['variants' => $variants] = Cx::ready();
        DB::table('catalog.store_variants')->where('variant_id', $variants[0])->update(['not_available_now' => true]);
        $customer = cartExtrasPieces();
        $otherStore = cartExtrasPieces('eg');
        cartExtrasAsGuest();
        $noGuestYet = cartExtrasPieces();
        app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId('sa'), $variants[1], 'RETAIL', 4));

        // 2 + 1, the line that cannot be ordered counted too; the guest's 4 once its first line made it.
        expect([$customer, $otherStore, $noGuestYet, cartExtrasPieces()])->toBe([3, 0, 0, 4]);
    });

    it('is 0 for staff, who have no cart, and in an off store, rather than refusing a shop page', function () {
        Cx::ready();
        // The base store is never off: Egypt's page, which the cart refuses (`no such store open`).
        $egypt = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $off = app(CountCartPiecesHandler::class)->handle(new CountCartPieces($egypt));
        Fx::actAsStaff(Fx::staff());

        expect([$off, cartExtrasPieces()])->toBe([0, 0]);
    });

    it('reads the count in one query', function () {
        Cx::ready();
        cartExtrasPieces();
        DB::flushQueryLog();
        DB::enableQueryLog();
        cartExtrasPieces();
        DB::disableQueryLog();

        // Measured 2026-10-27: the store and the permissions come from caches warmed by the first call.
        expect(count(DB::getQueryLog()))->toBe(1);
    });
});

it('agrees with checkout: the goods total, free shipping, the points, and the gift once the next level is reached', function () {
    ['address' => $address] = Cx::ready();
    Lx::programme();
    Cx::setting(SalesSettings::FLAT_FEE, 25_00);
    Cx::setting(SalesSettings::FREE_ABOVE, 200_00);
    $gift = cartExtrasGift(50);
    $reached = Pr::giftLevel($gift, 200_00);
    $next = Pr::giftLevel($gift, 300_00);
    Pr::coupon('SUMMER-15');
    app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));

    $cart = cartExtras();
    $quote = Cx::start($address);
    $free = $cart->freeAbove !== null && $cart->goodsTotal !== null && $cart->goodsTotal->isAtLeast($cart->freeAbove);

    // 250.00 less 15% = 212.50: shipping free from 200.00, the 200.00 gift held, 300.00 next.
    expect([$cart->goodsTotal?->minorUnits, $cart->pointsToEarn, $cart->freeAbove?->minorUnits, $free])
        ->toBe([$quote->totals->goodsTotal->minorUnits, $quote->earningPreview, $quote->shippingRuleFreeAbove->minorUnits, $quote->totals->shipping->isZero()])
        ->and([$cart->goodsTotal?->minorUnits, $free, $quote->giftLevelId, $cart->nextGift?->amount->minorUnits])->toBe([212_50, true, $reached, 300_00]);

    // 87.50 missing after 15% off: 11 × 10.00 more (360.00 less 15% = 306.00) reaches the next level at checkout.
    app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId('sa'), $gift, 'RETAIL', 11));

    expect(Cx::start($address)->giftLevelId)->toBe($next)
        ->and(cartExtras()->nextGift)->toBeNull();
});

it('reads a full cart — coupon, next gift, points — in a recorded number of queries', function () {
    Cx::ready();
    $count = function (): int {
        cartExtras();
        DB::flushQueryLog();
        DB::enableQueryLog();
        cartExtras();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $plain = $count();
    Lx::programme();
    $gift = cartExtrasGift(5);
    Pr::giftLevel($gift, 300_00);
    Pr::coupon('SUMMER-15');
    app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

    // Measured 2026-10-27: a plain cart of two lines 16 (as ViewCart read it before, the customer now read once
    // instead of twice, the shipping settings from Platform's cache); with a coupon typed, a gift level above
    // and the programme on, 22 — the coupon's reads, the levels above and their gifts' stock. A target, not a
    // cap (owner, 2026-10-19). Measured 2026-10-30: one more each, 17 and 23 - the SMS countries list, read
    // once when the customer is read (access.md amendment 87); a cache would be a query of its own.
    expect([$plain, $count()])->toBe([17, 23]);
});
