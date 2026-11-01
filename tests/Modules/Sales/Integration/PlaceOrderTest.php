<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCode;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCodeHandler;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhone;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhoneHandler;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\B2B\Public\Dto\BankAccountDto;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\NotEnoughStock;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Public\Events\PointsEarned;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrder;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrderHandler;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLine;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLineHandler;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrder;
use Modules\Sales\Application\Command\PlaceOrder\PlaceOrderHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\QuoteNotFound;
use Modules\Sales\Domain\Exception\TooManyWaitingOrders;
use Modules\Sales\Infrastructure\Listener\ForgetCustomer;
use Modules\Sales\Public\Events\OrderPlaced;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Placing the order (sales.md §1.3, §8 "Checkout"): one transaction — the quote locked, placing twice
| placing once, the number per store, every rule that can change in 15 minutes again, the order written
| snapshotted with its shares, the coupon used, the points redeemed, the stock held, the order's fact
| recorded, the cart's bought lines gone, OrderPlaced in the outbox. Any refusal rolls everything back as
| QuoteChanged, naming what moved.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** What a refused placement must leave behind: nothing. */
function nothingPlaced(): void
{
    expect(DB::table('sales.orders')->count())->toBe(0)
        ->and(DB::table('sales.order_lines')->count())->toBe(0)
        ->and(DB::table('promotions.coupon_uses')->count())->toBe(0)
        ->and(DB::table('promotions.order_facts')->count())->toBe(0)
        ->and(DB::table('inventory.holds')->count())->toBe(0)
        // An order delivered before (the points' source) is Loyalty's own: none redeemed here.
        ->and(DB::table('loyalty.orders')->where('points_redeemed', '>', 0)->count())->toBe(0)
        // Nothing told of this placement; that earlier delivery's PointsEarned is Loyalty's own (loyalty.md §6).
        ->and(DB::table('public.outbox_messages')->where('type', '!=', PointsEarned::class)->count())->toBe(0);
}

describe('placing', function () {
    it('writes the order as quoted, holds its stock, tells of it, and empties the cart of it', function () {
        ['address' => $address, 'variants' => $variants, 'customer' => $customer, 'product' => $product] = Cx::ready();
        $t0 = Cx::clockAhead();
        CarbonImmutable::setTestNow($t0);
        $quote = Cx::start($address);
        $name = (string) DB::table('catalog.products')->where('id', $product)->value('name_en');

        $placed = Cx::place($quote->id);

        $order = DB::table('sales.orders')->where('id', $placed->id)->first();
        $customerRow = DB::table('access.customers')->where('id', $customer)->first();
        expect($placed->number)->toBe('SA-0001')
            ->and($order?->number)->toBe('SA-0001')
            ->and($order?->quote_id)->toBe($quote->id)
            ->and($order?->status)->toBe('PENDING_APPROVAL')
            ->and($order?->shipment_status)->toBe('NOT_SHIPPED')
            ->and($order?->audience)->toBe('PUBLIC')
            ->and($order?->way_to_pay)->toBe('STAFF_CONTACT')
            ->and($order?->customer_email)->toBe($customerRow?->email)
            ->and($order?->customer_phone)->toBe($customerRow?->phone)
            ->and($order?->customer_name)->toBe('Sara Ali')
            ->and(json_decode((string) $order?->address, true)['formatted'] ?? null)->not->toBeNull()
            // An address keeps no phone; the order's phone is the account's (access.md amendment 89).
            ->and(json_decode((string) $order?->address, true))->not->toHaveKey('phone')
            ->and((int) $order?->gross_subtotal_minor)->toBe(250_00)
            ->and((int) $order?->order_total_minor)->toBe($quote->totals->orderTotal->minorUnits)
            ->and((int) $order?->due_minor)->toBe($quote->totals->orderTotal->minorUnits)
            ->and((int) $order?->vat_minor)->toBe($quote->totals->vat->minorUnits)
            ->and((int) $order?->paid_minor)->toBe(0);

        $lines = DB::table('sales.order_lines')->where('order_id', $placed->id)->orderBy('total_minor', 'desc')->get();
        expect($lines->map(fn ($line): array => [$line->variant_id, $line->quantity, (int) $line->total_minor, $line->price_kind, $line->name_en, $line->is_gift])->all())
            ->toBe([[$variants[0], 2, 200_00, 'BASE', $name, false], [$variants[1], 1, 50_00, 'BASE', $name, false]])
            ->and($lines->sum('vat_share_minor'))->toBe($quote->totals->vat->minorUnits)
            ->and(json_decode((string) $lines->first()?->variant_values, true)[0]['valueEn'] ?? null)->toBe('60 cm')
            ->and($lines->first()?->code)->not->toBe('');

        $history = DB::table('sales.order_history')->where('order_id', $placed->id)->get();
        expect($history->pluck('kind')->all())->toBe(['PLACED'])
            ->and((string) $history->first()?->detail)->not->toContain('@');

        $hold = DB::table('inventory.holds')->where('order_id', $placed->id)->first();
        expect($hold)->not->toBeNull()
            ->and($hold?->expires_at)->toBeNull()
            ->and(DB::table('inventory.hold_lines')->where('hold_id', $hold?->id)->orderBy('quantity', 'desc')->get()->map(fn ($line): array => [$line->variant_id, $line->quantity, $line->gift])->all())
            ->toBe([[$variants[0], 2, false], [$variants[1], 1, false]])
            ->and(DB::table('promotions.order_facts')->where('order_id', $placed->id)->exists())->toBeTrue();

        $told = DB::table('public.outbox_messages')->get();
        expect($told)->toHaveCount(1)
            ->and($told->first()?->type)->toBe(OrderPlaced::class)
            ->and(json_decode((string) $told->first()?->payload, true))->toMatchArray(['orderId' => $placed->id, 'customerId' => $customer]);

        expect(DB::table('sales.cart_lines')->count())->toBe(0)
            ->and(DB::table('sales.quotes')->where('id', $quote->id)->exists())->toBeTrue();
    });

    it('uses the coupon, redeems the points and holds the gift, each share adding up', function () {
        ['address' => $address, 'variants' => $variants, 'customer' => $customer] = Cx::ready();
        Pr::ceiling(100);
        $couponId = Pr::coupon('SUMMER-15');
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        $level = Pr::giftLevel($variants[1], 100_00);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = Cx::start($address, usePoints: true);
        expect($quote->pointsUsed)->toBeGreaterThan(0)->and($quote->giftLevelId)->toBe($level);

        $placed = Cx::place($quote->id);

        $order = DB::table('sales.orders')->where('id', $placed->id)->first();
        $lines = DB::table('sales.order_lines')->where('order_id', $placed->id)->get();
        expect($order?->coupon_id)->toBe($couponId)
            ->and($order?->coupon_code)->toBe('SUMMER-15')
            ->and((int) $order?->coupon_discount_minor)->toBe($quote->couponDiscount->minorUnits)
            ->and((int) $order?->points_used)->toBe($quote->pointsUsed)
            ->and($order?->gift_level_id)->toBe($level)
            ->and((int) $lines->sum('coupon_share_minor'))->toBe($quote->couponDiscount->minorUnits)
            ->and((int) $lines->sum('points_share_minor'))->toBe($quote->pointsDiscount->minorUnits)
            ->and((int) $lines->sum('vat_share_minor'))->toBe($quote->totals->vat->minorUnits)
            ->and($lines->where('is_gift', true)->pluck('variant_id')->all())->toBe([$variants[1]])
            ->and((int) DB::table('promotions.coupon_uses')->where('order_id', $placed->id)->value('discount_minor'))->toBe($quote->couponDiscount->minorUnits)
            ->and(DB::table('promotions.coupon_uses')->where('order_id', $placed->id)->value('order_number'))->toBe('SA-0001')
            ->and((int) DB::table('loyalty.orders')->where('order_id', $placed->id)->value('points_redeemed'))->toBe($quote->pointsUsed)
            ->and(DB::table('inventory.hold_lines')->where('gift', true)->pluck('variant_id')->all())->toBe([$variants[1]])
            ->and(DB::table('sales.carts')->value('coupon_code'))->toBeNull();
    });

    it('places a quote once however often it is placed, and numbers the next order on', function () {
        ['address' => $address, 'variants' => $variants] = Cx::ready();
        $quote = Cx::start($address);

        $first = Cx::place($quote->id);
        $again = Cx::place($quote->id);
        Cx::add($variants[0]);
        $next = Cx::place(Cx::start($address)->id);

        expect($again)->toEqual($first)
            ->and($next->number)->toBe('SA-0002')
            ->and(DB::table('sales.orders')->count())->toBe(2)
            ->and(DB::table('public.outbox_messages')->count())->toBe(2)
            ->and(DB::table('inventory.holds')->count())->toBe(2);
    });

    it('answers a placed quote with its order after it expired, and refuses one never placed', function () {
        ['address' => $address, 'variants' => $variants] = Cx::ready();
        $t0 = Cx::clockAhead();
        CarbonImmutable::setTestNow($t0);
        $placedQuote = Cx::start($address);
        $placed = Cx::place($placedQuote->id);
        Cx::add($variants[0]);
        $other = Cx::start($address);
        CarbonImmutable::setTestNow($t0->addMinutes(15));

        expect(Cx::place($placedQuote->id))->toEqual($placed)
            ->and(fn () => Cx::place($other->id))->toThrow(QuoteExpired::class);
    });

    it('lets only a signed-in customer place their own quote, in its own store', function (Closure $spoil) {
        ['address' => $address] = Cx::ready();
        $quote = Cx::start($address);

        expect(fn () => $spoil($quote->id))->toThrow(QuoteNotFound::class);
        nothingPlaced();
    })->with([
        'another customer' => [function (string $quoteId) {
            Fx::actAsCustomer(Cx::customer());

            return Cx::place($quoteId);
        }],
        'another store' => [fn (string $quoteId) => Cx::place($quoteId, 'eg')],
        'no such quote' => [fn (string $quoteId) => Cx::place(strtolower((string) Str::ulid()))],
        'not an id' => [fn (string $quoteId) => Cx::place('quote')],
    ]);

    it('refuses a guest and staff', function (Closure $actor) {
        ['address' => $address] = Cx::ready();
        $quote = Cx::start($address);
        Fx::actAs($actor());

        expect(fn () => Cx::place($quote->id))->toThrow(Unauthorized::class);
    })->with([
        'a guest' => [fn () => Actor::guest(strtolower((string) Str::ulid()))],
        'staff' => [fn () => Actor::staff(Fx::staff(superAdmin: true))],
    ]);
});

describe('what changed since the quote', function () {
    it('refuses as QuoteChanged, naming what moved, and leaves nothing behind', function (Closure $change, string $reason) {
        $ready = Cx::ready();
        $quote = Cx::start($ready['address']);
        $change($ready);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe($reason);
        nothingPlaced();
        expect(DB::table('sales.cart_lines')->count())->toBe(2);
    })->with([
        'the account may no longer order' => [fn (array $ready) => DB::table('access.customers')->where('id', $ready['customer'])->update(['email_verified_at' => null]), 'sales.may_not_order'],
        'a size ran short' => [fn (array $ready) => Ix::count('sa', [$ready['variants'][0] => 1]), 'sales.not_orderable'],
        'the address went' => [fn (array $ready) => DB::table('access.addresses')->where('id', $ready['address'])->delete(), 'sales.address_not_usable'],
        'a gift is now reached' => [fn (array $ready) => Pr::giftLevel($ready['variants'][1], 100_00), 'gift'],
        'a size not available now' => [fn (array $ready) => DB::table('catalog.store_variants')->where('variant_id', $ready['variants'][0])->update(['not_available_now' => true]), 'sales.not_orderable'],
        'its category now hidden from the account' => [function (array $ready) {
            $hidden = Px::category('Trade only');
            DB::table('catalog.products')->where('id', $ready['product'])->update(['category_id' => $hidden]);
            DB::table('catalog.store_category_audiences')->insert(['store_id' => Fx::storeId('sa'), 'category_id' => $hidden, 'for_individuals' => false, 'for_companies' => true, 'created_at' => now(), 'updated_at' => now()]);
        }, 'sales.not_for_your_account'],
    ]);

    it('refuses once the store was switched off', function () {
        ['address' => $address] = Cx::ready(storeCode: 'eg');
        $store = Fx::storeId('eg');
        $quote = Cx::start($address, storeCode: 'eg');
        $customer = app(ActorContext::class)->current();
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAs($customer);

        expect(Pr::thrown(fn () => app(PlaceOrderHandler::class)->handle(new PlaceOrder($store, $quote->id)), QuoteChanged::class)->reason)->toBe('sales.may_not_order');
        nothingPlaced();
    });

    it('refuses once the company was suspended in the store', function () {
        ['address' => $address] = Cx::ready('company');
        $approved = true;
        $b2b = Mockery::mock(B2BApi::class);
        $b2b->shouldReceive('isApproved')->andReturnUsing(function () use (&$approved) {
            return $approved;
        });
        $b2b->shouldReceive('bankAccount')->andReturn(null);
        app()->instance(B2BApi::class, $b2b);
        $quote = Cx::start($address);
        $approved = false;

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('sales.may_not_order');
        nothingPlaced();
    });

    it('refuses once the points were spent on another order', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        Pr::ceiling(100);
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        $quote = Cx::start($address, usePoints: true);
        $elsewhere = Lx::quote($customer, null, 10_000_00);
        Lx::place($customer, $elsewhere, 10_000_00);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toStartWith('loyalty.redemption_')
            ->and(DB::table('sales.orders')->count())->toBe(0)
            ->and(DB::table('inventory.holds')->count())->toBe(0);
    });

    it('skips the number a refused placement took', function () {
        $ready = Cx::ready();
        $refused = Cx::start($ready['address']);
        Ix::count('sa', [$ready['variants'][0] => 1]);
        expect(fn () => Cx::place($refused->id))->toThrow(QuoteChanged::class);
        Ix::count('sa', [$ready['variants'][0] => 100]);

        // The sequence gave 1 to the refused one, rolled back with it or not: never given twice.
        $placed = Cx::place(Cx::start($ready['address'])->id);

        expect($placed->number)->toBe('SA-0002');
    });

    it('keeps the snapshot as placed though the catalog changes after', function () {
        $ready = Cx::ready();
        $placed = Cx::place(Cx::start($ready['address'])->id);
        $before = DB::table('sales.order_lines')->where('order_id', $placed->id)->orderBy('id')->get(['name_ar', 'name_en', 'code', 'variant_values', 'unit_minor', 'total_minor'])->all();
        DB::table('catalog.products')->where('id', $ready['product'])->update(['name_en' => 'Renamed', 'name_ar' => 'اسم آخر']);
        Rx::set('sa', [$ready['variants'][0] => '10.00', $ready['variants'][1] => '5.00']);

        expect(DB::table('sales.order_lines')->where('order_id', $placed->id)->orderBy('id')->get(['name_ar', 'name_en', 'code', 'variant_values', 'unit_minor', 'total_minor'])->all())->toEqual($before)
            ->and(array_column($before, 'name_en'))->not->toContain('Renamed');
    });

    it('refuses when the ceiling was lowered under the coupon', function () {
        ['address' => $address] = Cx::ready();
        Pr::ceiling(50);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = Cx::start($address);
        Pr::ceiling(10);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('ceiling');
        nothingPlaced();
    });

    it('refuses when the coupon still fits the lowered ceiling but the coupon and the points do not', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = Cx::start($address, usePoints: true);
        expect($quote->couponDiscount->minorUnits)->toBe(37_50)
            ->and($quote->pointsDiscount->minorUnits)->toBeGreaterThan(2_50);
        // 16% of 250.00 is 40.00: room for the coupon's 37.50, not for it and the points.
        Pr::ceiling(16);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('ceiling');
        nothingPlaced();
    });

    it('refuses when the coupon was switched off, as Promotions says', function () {
        ['address' => $address] = Cx::ready();
        Pr::ceiling(50);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = Cx::start($address);
        DB::table('promotions.coupons')->update(['active' => false]);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('promotions.coupon_refused');
        nothingPlaced();
    });

    it('refuses when the points programme was switched off, as Loyalty says', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        Pr::ceiling(100);
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        $quote = Cx::start($address, usePoints: true);
        Lx::programme('sa', [ProgrammeSettings::ENABLED => false]);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('loyalty.points_programme_off');
        nothingPlaced();
    });

    it('refuses a bank transfer once the store has no account', function () {
        ['address' => $address] = Cx::ready('company');
        $account = new BankAccountDto('SA00 0000', 'Bank', 'TouchWood');
        $b2b = Mockery::mock(B2BApi::class);
        $b2b->shouldReceive('isApproved')->andReturn(true);
        $b2b->shouldReceive('bankAccount')->andReturnUsing(function () use (&$account) {
            return $account;
        });
        app()->instance(B2BApi::class, $b2b);
        $quote = Cx::start($address, 'BANK_TRANSFER');
        $account = null;

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('way_to_pay');
        nothingPlaced();
    });
});

describe('what the review asked for', function () {
    it('rolls back the coupon used and the points redeemed when the stock cannot be held, naming the sizes', function () {
        ['address' => $address, 'variants' => $variants, 'customer' => $customer] = Cx::ready();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = Cx::start($address, usePoints: true);
        expect($quote->pointsUsed)->toBeGreaterThan(0);
        placeHoldFailing(new NotEnoughStock([$variants[0] => 1]));

        $changed = Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class);

        expect($changed->reason)->toBe('inventory.not_enough_stock')
            ->and($changed->context())->toBe(['reason' => 'inventory.not_enough_stock', 'variants' => $variants[0]])
            ->and($changed->getPrevious())->toBeInstanceOf(NotEnoughStock::class);
        nothingPlaced();
    });

    it('lets a fault go on as itself, never as QuoteChanged', function () {
        ['address' => $address] = Cx::ready();
        $quote = Cx::start($address);
        placeHoldFailing(new InvalidInventoryInput('variant'));

        expect(fn () => Cx::place($quote->id))->toThrow(InvalidInventoryInput::class);
        nothingPlaced();
    });

    it('takes only the quoted pieces out of the cart, and a coupon typed since stays', function () {
        ['address' => $address, 'variants' => $variants] = Cx::ready();
        Pr::ceiling(50);
        Pr::coupon('SUMMER-15');
        Pr::coupon('AUTUMN-10');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $quote = Cx::start($address);
        app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $variants[0], 'RETAIL', 5));
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'AUTUMN-10'));

        Cx::place($quote->id);

        expect(DB::table('sales.cart_lines')->get()->map(fn ($line): array => [$line->variant_id, $line->quantity])->all())->toBe([[$variants[0], 3]])
            ->and(DB::table('sales.carts')->value('coupon_code'))->toBe('AUTUMN-10');
    });

    it('refuses when the gift quoted is no longer given', function () {
        ['address' => $address, 'variants' => $variants] = Cx::ready();
        $level = Pr::giftLevel($variants[1], 100_00);
        $quote = Cx::start($address);
        expect($quote->giftLevelId)->toBe($level);
        DB::table('promotions.gift_levels')->update(['active' => false]);

        expect(Pr::thrown(fn () => Cx::place($quote->id), QuoteChanged::class)->reason)->toBe('gift');
        nothingPlaced();
    });

    it('spreads the VAT over the lines only: the shipping carries none (§9.5 #1)', function () {
        Cx::serve('sa', 25_00);
        ['address' => $address] = Cx::ready();
        $quote = Cx::start($address);

        $placed = Cx::place($quote->id);

        $lines = (int) DB::table('sales.order_lines')->where('order_id', $placed->id)->sum('vat_share_minor');
        $order = DB::table('sales.orders')->where('id', $placed->id)->first();
        // 250.00 of goods: VAT 37.50 on them alone; 250.00 + 37.50 + 25.00 = 312.50.
        expect([$quote->totals->shipping->minorUnits, $quote->totals->taxableBase->minorUnits, $quote->totals->vat->minorUnits, $quote->totals->orderTotal->minorUnits])
            ->toBe([25_00, 250_00, 37_50, 312_50])
            ->and($lines)->toBe($quote->totals->vat->minorUnits)
            ->and([(int) $order?->shipping_fee_minor, (int) $order?->shipping_rule_fee_minor, $order?->ordered_carrier_en, (bool) $order?->shipping_fee_pending])
            ->toBe([25_00, 25_00, Cx::CARRIER, false]);
    });

    it('keeps the coupon code on the order as Promotions keeps codes', function () {
        ['address' => $address] = Cx::ready();
        Pr::ceiling(50);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'summer-١٥'));

        $placed = Cx::place(Cx::start($address)->id);

        expect(DB::table('sales.orders')->where('id', $placed->id)->value('coupon_code'))->toBe('SUMMER-15');
    });

    it('clears an anonymized account email from its orders, and keeps the rest', function () {
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);
        $phone = DB::table('sales.orders')->where('id', $placed->id)->value('customer_phone');

        app(ForgetCustomer::class)->handle(new CustomerAnonymized((string) Str::uuid(), strtoupper($customer), CarbonImmutable::now()));

        $order = DB::table('sales.orders')->where('id', $placed->id)->first();
        expect($order?->customer_email)->toBeNull()
            ->and($order?->customer_name)->toBe('Sara Ali')
            ->and($order?->customer_phone)->toBe($phone)
            ->and($order?->address)->not->toBeNull();
    });
});

// One phone per customer (access.md amendment 89 (d), owner 2026-10-30): an order keeps the phone it was placed with.
describe('the order phone', function () {
    it('keeps the phone as placed on an open order when the account changes and verifies another', function () {
        RecordingSecurityMessages::install();
        ['address' => $address, 'customer' => $customer] = Cx::ready();
        $placed = Cx::place(Cx::start($address)->id);
        $before = (string) DB::table('sales.orders')->where('id', $placed->id)->value('customer_phone');
        $next = Bx::unusedPhone();

        Fx::inStoreCode('sa', fn () => app(RequestCustomerPhoneCodeHandler::class)->handle(new RequestCustomerPhoneCode($next)));
        Fx::inStoreCode('sa', fn () => app(VerifyCustomerPhoneHandler::class)->handle(
            new VerifyCustomerPhone(RecordingSecurityMessages::installed()->lastCode()),
        ));

        $order = DB::table('sales.orders')->where('id', $placed->id)->first();
        expect(DB::table('access.customers')->where('id', $customer)->value('phone'))->toBe($next)
            ->and($order?->status)->toBe('PENDING_APPROVAL')
            ->and($order?->customer_phone)->toBe($before)
            ->and($before)->not->toBe($next);
    });
});

// At most 3 orders waiting for approval per customer per store, one number for every store (owner,
// 2026-10-28, answer 51): checked at the quote, and at placing under a lock per customer and store.
describe('orders waiting for approval', function () {
    it('refuses a fourth quote, and a fourth placement quoted before the third was placed', function () {
        $ready = Cx::ready();
        Cx::place(Cx::start($ready['address'])->id);
        Cx::place(placeQuoteAgain($ready));
        $third = placeQuoteAgain($ready);
        $fourth = Cx::start($ready['address'])->id;
        Cx::place($third);
        $orders = DB::table('sales.orders')->count();

        expect(Pr::thrown(fn () => Cx::place($fourth), TooManyWaitingOrders::class)->context())->toBe(['most' => 3])
            ->and(DB::table('sales.orders')->count())->toBe($orders)
            ->and(fn () => placeQuoteAgain($ready))->toThrow(TooManyWaitingOrders::class);
    });

    it('takes a new order once one is decided or cancelled', function (Closure $decide) {
        $ready = Cx::ready();
        $first = Cx::place(Cx::start($ready['address'])->id)->id;
        Cx::place(placeQuoteAgain($ready));
        Cx::place(placeQuoteAgain($ready));
        $decide($first, $ready['customer']);
        Fx::actAsCustomer($ready['customer']);

        expect(Cx::place(placeQuoteAgain($ready))->number)->toBe('SA-0004');
    })->with([
        'approved' => [function (string $order) {
            Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE], ['sa']));
            app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        }],
        'rejected' => [function (string $order) {
            Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE], ['sa']));
            app(RejectOrderHandler::class)->handle(new RejectOrder($order, 'Not for us'));
        }],
        'cancelled by the customer' => [fn (string $order) => app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND'))],
    ]);

    it('counts only the customer\'s own orders in this store', function () {
        // Another customer's three in the store.
        $other = Cx::ready();
        Cx::place(Cx::start($other['address'])->id);
        Cx::place(placeQuoteAgain($other));
        Cx::place(placeQuoteAgain($other));
        // The customer's own three in another store.
        $abroad = Cx::ready(storeCode: 'eg');
        Cx::place(Cx::start($abroad['address'], storeCode: 'eg')->id, 'eg');
        foreach ([1, 2] as $again) {
            Cx::add($abroad['variants'][0], storeCode: 'eg');
            Cx::place(Cx::start($abroad['address'], storeCode: 'eg')->id, 'eg');
        }
        $address = Bx::savedAddress($abroad['customer'], 'sa');
        Cx::add($other['variants'][0]);

        expect(Cx::place(Cx::start($address)->id)->number)->toBe('SA-0004');
    });

    it('follows the number an admin of every store set', function () {
        Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(SalesSettings::WAITING_ORDERS, null, 1)));
        $ready = Cx::ready();
        Cx::place(Cx::start($ready['address'])->id);

        expect(Pr::thrown(fn () => placeQuoteAgain($ready), TooManyWaitingOrders::class)->context())->toBe(['most' => 1]);
    });

    // Proves placing takes the customer's lock in the store; that the count comes after it is the
    // handler's order of the two calls (PlaceOrderHandler step 1), read in review, not tested here.
    it('counts under a lock per customer and store: a placement meanwhile waits for it', function () {
        $ready = Cx::ready();
        $quote = Cx::start($ready['address'])->id;
        config(['database.connections.waiting_race' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('waiting_race');
        // Another placement of the customer's in the store, holding the lock.
        $other->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', ['sales:waiting:'.Fx::storeId('sa').':'.$ready['customer']]);
        DB::statement("SET LOCAL lock_timeout = '300ms'");

        try {
            $waited = Pr::thrown(fn () => Cx::place($quote), QueryException::class);
        } finally {
            $other->select('SELECT pg_advisory_unlock_all()');
            $other->disconnect();
        }

        expect($waited->getCode())->toBe('55P03')
            ->and($waited->getSql())->toContain('pg_advisory_xact_lock');
    });
});

/**
 * The customer's next order of the first size, quoted; the cart filled again.
 *
 * @param  array{customer: string, address: string, variants: list<string>, product: string}  $ready
 */
function placeQuoteAgain(array $ready): string
{
    Cx::add($ready['variants'][0]);

    return Cx::start($ready['address'])->id;
}

/** Inventory's hold refusing with this error; its reads as they are. */
function placeHoldFailing(Throwable $error): void
{
    $real = app(InventoryApi::class);
    $inventory = Mockery::mock(InventoryApi::class);
    $inventory->shouldReceive('stock')->andReturnUsing(fn (mixed ...$arguments): mixed => $real->stock(...$arguments));
    $inventory->shouldReceive('hold')->andThrow($error);
    app()->instance(InventoryApi::class, $inventory);
}
