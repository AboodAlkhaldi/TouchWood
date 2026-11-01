<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\StockDto;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Enums\Audience as LoyaltyAudience;
use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\GiftOfferDto;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotes;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotesHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Command\StartCheckout\StartCheckout;
use Modules\Sales\Application\Command\StartCheckout\StartCheckoutHandler;
use Modules\Sales\Application\Quote\QuoteBuilder;
use Modules\Sales\Application\Quote\QuoteRules;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Domain\Exception\AddressNotUsable;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\MayNotOrder;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\Quotes;
use Modules\Sales\Infrastructure\Queue\DeleteExpiredQuotesJob;
use Modules\Sales\Public\Enums\OrderAudience;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The checkout's quote (sales.md §1.2, §8 "Checkout"): built in its fixed order from the cart — who may
| order, what may be bought, the address, the prices, the coupon, the points, the goods total, the gift,
| the shipping, the totals, the ways to pay, the points it would earn — kept whole for 15 minutes at
| most; a coupon or points refused never fail it (answer 31). The other modules are the real ones; where
| a case needs what their data cannot easily say, the builder is given a stand-in for that one read.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** A customer of the store who may order: email and phone confirmed. */
function quoteCustomer(string $accountType = 'individual'): string
{
    return Cx::customer($accountType);
}

/**
 * Two sizes on sale in the store at 100.00 and 50.00, 100 of each in stock, a customer who may order with an address there,
 * acting as that customer, two of the first size and one of the second in the cart.
 *
 * @return array{customer: string, address: string, variants: list<string>, product: string}
 */
function quoteReady(string $accountType = 'individual'): array
{
    return Cx::ready($accountType);
}

function quoteAdd(string $variantId, int $quantity = 1, string $mode = 'RETAIL'): void
{
    Cx::add($variantId, $quantity, $mode);
}

function quoteStart(string $addressId, string $way = 'STAFF_CONTACT', bool $usePoints = false, ?int $asked = null): QuoteView
{
    return Cx::start($addressId, $way, $usePoints, $asked);
}

function quoteSetting(string $key, int|bool $value): void
{
    Cx::setting($key, $value);
}

function quoteMoney(int $minor): Money
{
    return Money::of($minor, Pr::currency());
}

/**
 * The builder given stand-ins for some of the reads it makes of the other modules — each named one
 * answered by the closure given, every other passed to the real module.
 *
 * @param  array<string, Closure>  $answers  method name => its answer
 */
function quoteWith(array $answers): void
{
    $pass = fn (object $real, string $method): Closure => $answers[$method] ?? fn (mixed ...$arguments): mixed => $real->{$method}(...$arguments);
    [$b2b, $payments, $catalog, $inventory, $promotions, $loyalty] = [app(B2BApi::class), app(PaymentsApi::class), app(CatalogApi::class), app(InventoryApi::class), app(PromotionsApi::class), app(LoyaltyApi::class)];

    $b2bStandIn = Mockery::mock(B2BApi::class);
    $b2bStandIn->shouldReceive('isApproved')->andReturnUsing($pass($b2b, 'isApproved'));
    $paymentsStandIn = Mockery::mock(PaymentsApi::class);
    $paymentsStandIn->shouldReceive('bankAccount')->andReturnUsing($pass($payments, 'bankAccount'));
    $catalogStandIn = Mockery::mock(CatalogApi::class);
    $catalogStandIn->shouldReceive('storeVariants')->andReturnUsing($pass($catalog, 'storeVariants'));
    $catalogStandIn->shouldReceive('productsOpenTo')->andReturnUsing($pass($catalog, 'productsOpenTo'));
    $inventoryStandIn = Mockery::mock(InventoryApi::class);
    $inventoryStandIn->shouldReceive('stock')->andReturnUsing($pass($inventory, 'stock'));
    $promotionsStandIn = Mockery::mock(PromotionsApi::class);
    $promotionsStandIn->shouldReceive('applyCoupon')->andReturnUsing($pass($promotions, 'applyCoupon'));
    $promotionsStandIn->shouldReceive('customerDiscountRoom')->andReturnUsing($pass($promotions, 'customerDiscountRoom'));
    $promotionsStandIn->shouldReceive('giftLevels')->andReturnUsing($pass($promotions, 'giftLevels'));
    $loyaltyStandIn = Mockery::mock(LoyaltyApi::class);
    $loyaltyStandIn->shouldReceive('quoteRedemption')->andReturnUsing($pass($loyalty, 'quoteRedemption'));
    $loyaltyStandIn->shouldReceive('earningPreview')->andReturnUsing($pass($loyalty, 'earningPreview'));
    $pricing = app(PricingApi::class);
    $pricingStandIn = Mockery::mock(PricingApi::class);
    $pricingStandIn->shouldReceive('prices')->andReturnUsing($pass($pricing, 'prices'));
    $pricingStandIn->shouldReceive('totals')->andReturnUsing($pass($pricing, 'totals'));

    // The rules are read again when the order is placed: the same stand-ins answer there.
    $rules = app()->make(QuoteRules::class, ['b2b' => $b2bStandIn, 'payments' => $paymentsStandIn, 'catalog' => $catalogStandIn, 'inventory' => $inventoryStandIn, 'promotions' => $promotionsStandIn]);
    app()->instance(QuoteRules::class, $rules);
    app()->instance(QuoteBuilder::class, app()->make(QuoteBuilder::class, [
        'rules' => $rules, 'promotions' => $promotionsStandIn, 'loyalty' => $loyaltyStandIn, 'pricing' => $pricingStandIn,
    ]));
}

/**
 * The cart's lines as Pricing is asked them.
 *
 * @param  array<mixed>  $lines
 * @return list<CartLineDto>
 */
function quoteLines(array $lines): array
{
    return array_values(array_filter($lines, static fn (mixed $line): bool => $line instanceof CartLineDto));
}

/** The prices as Pricing answered them, ending when given. */
function quotePricesEnding(CartPricesDto $prices, ?DateTimeImmutable $ends): CartPricesDto
{
    return new CartPricesDto($prices->storeId, $prices->currencyCode, $prices->taxRateBasisPoints, $prices->lines, $prices->unpriced, $prices->grossSubtotal, $prices->netSubtotal, $prices->pricedAt, $ends);
}

/**
 * The store's own answer for a size, with some of it changed.
 *
 * @param  array<string, mixed>  $changes
 */
function quoteVariant(string $variantId, array $changes): StoreVariantDto
{
    $real = app(CatalogApi::class)->storeVariant(StoreId::fromString(Fx::storeId('sa')), $variantId) ?? throw new LogicException('No such size.');

    return new StoreVariantDto(...[...get_object_vars($real), ...$changes]);
}

describe('a quote', function () {
    it('prices the cart, totals it, offers staff contact to an individual and keeps it whole for 15 minutes', function () {
        ['address' => $address, 'variants' => $variants, 'customer' => $customer] = quoteReady();
        Cx::serve('sa', 25_00);
        $t0 = Cx::clockAhead();
        CarbonImmutable::setTestNow($t0);

        $quote = quoteStart($address);

        $prices = $quote->prices;
        $expected = app(PricingApi::class)->totals($prices, quoteMoney(0), quoteMoney(0), quoteMoney(25_00));
        expect($quote->audience)->toBe(OrderAudience::Public)
            ->and(array_map(static fn ($line): array => [$line->variantId, $line->quantity, $line->unit->minorUnits], $prices->lines))->toBe([[$variants[0], 2, 100_00], [$variants[1], 1, 50_00]])
            ->and($prices->grossSubtotal->minorUnits)->toBe(250_00)
            ->and($quote->totals)->toEqual($expected)
            ->and($quote->totals->shipping->minorUnits)->toBe(25_00)
            ->and($quote->waysToPay)->toBe([WayToPay::StaffContact])
            ->and($quote->couponCode)->toBeNull()
            ->and($quote->pointsUsed)->toBe(0)
            ->and($quote->pointsRefusal)->toBeNull()
            ->and($quote->giftLevelId)->toBeNull()
            ->and($quote->expiresAt->format('Y-m-d H:i:s'))->toBe($t0->addMinutes(15)->format('Y-m-d H:i:s'));

        $kept = app(Quotes::class)->find($quote->id) ?? throw new LogicException('Not kept.');
        expect($kept->customerId)->toBe($customer)
            ->and($kept->addressId)->toBe($address)
            ->and($kept->usePoints)->toBeFalse()
            ->and($kept->pointsAsked)->toBeNull()
            ->and($kept->wayToPay)->toBe('STAFF_CONTACT')
            ->and($kept->expiresAt->format('U.u'))->toBe($quote->expiresAt->format('U.u'))
            ->and($kept->createdAt->format('Y-m-d H:i:s'))->toBe($t0->format('Y-m-d H:i:s'))
            ->and(QuoteView::fromArray($kept->id, $kept->storeId, $kept->customerId, $kept->addressId, WayToPay::from($kept->wayToPay), $kept->expiresAt, $kept->createdAt, $kept->result)->toArray())->toBe($quote->toArray());
    });

    it('ships for free once the goods total reaches the carrier free-above amount, or the store-wide one over it', function (?int $carrier, int $store, int $shipping, ?string $kind) {
        ['address' => $address] = quoteReady();
        Cx::serve('sa', 25_00);
        Cx::carrierFreeAbove($carrier);
        Cx::freeForAll($store);

        $quote = quoteStart($address);

        // The rule snapshotted with the fee: the price before free shipping, and whose amount applied (§9.5 #11).
        expect([$quote->totals->shipping->minorUnits, $quote->shipping->price->minorUnits, $quote->shipping->freeKind])->toBe([$shipping, 25_00, $kind]);
    })->with([
        'never free' => [null, 0, 25_00, null],
        'the carrier amount, reached exactly' => [250_00, 0, 0, 'CARRIER'],
        'the carrier amount, one short' => [250_01, 0, 25_00, 'CARRIER'],
        'the store-wide one over the carrier amount' => [100_00, 300_00, 25_00, 'STORE'],
        'the store-wide one reached' => [null, 250_00, 0, 'STORE'],
    ]);

    it('expires with the prices when they end before the 15 minutes', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        $t0 = Cx::clockAhead();
        CarbonImmutable::setTestNow($t0);
        $ends = $t0->addMinutes(7);
        $real = app(PricingApi::class);
        $pricing = Mockery::mock(PricingApi::class);
        $pricing->shouldReceive('prices')->andReturnUsing(
            function (StoreId $store, array $lines) use ($real, $ends): CartPricesDto {
                $prices = $real->prices($store, array_values(array_filter($lines, static fn (mixed $line): bool => $line instanceof CartLineDto)));

                return new CartPricesDto($prices->storeId, $prices->currencyCode, $prices->taxRateBasisPoints, $prices->lines, $prices->unpriced, $prices->grossSubtotal, $prices->netSubtotal, $prices->pricedAt, $ends);
            },
        );
        $pricing->shouldReceive('totals')->andReturnUsing(fn (mixed ...$arguments): mixed => $real->totals(...$arguments));
        app()->instance(QuoteBuilder::class, app()->make(QuoteBuilder::class, ['pricing' => $pricing]));

        expect(quoteStart($address)->expiresAt->format('H:i'))->toBe($t0->addMinutes(7)->format('H:i'))
            ->and($variants)->toHaveCount(2);
    });

    it('shows the points the order would earn', function () {
        ['address' => $address] = quoteReady();
        Lx::programme();
        $quote = quoteStart($address);

        expect($quote->earningPreview)->toBe(app(LoyaltyApi::class)->earningPreview(Fx::storeId('sa'), $quote->totals->goodsTotal))
            ->and($quote->earningPreview)->toBeGreaterThan(0);
    });
});

describe('the coupon', function () {
    it('takes the cart coupon off the goods, with one share a line, under the ceiling', function () {
        ['address' => $address] = quoteReady();
        Pr::ceiling(50);
        $couponId = Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));

        $quote = quoteStart($address);

        expect($quote->couponId)->toBe($couponId)
            ->and($quote->couponCode)->toBe('SUMMER-15')
            ->and($quote->couponDiscount->minorUnits)->toBe(37_50)
            ->and($quote->couponShares)->toBe([30_00, 7_50])
            ->and($quote->couponRefusal)->toBeNull()
            ->and($quote->totals->couponDiscount->minorUnits)->toBe(37_50)
            ->and($quote->totals->goodsTotal->minorUnits)->toBe(212_50);
    });

    it('carries why a coupon is refused, and quotes without it', function () {
        ['address' => $address] = quoteReady();
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'NO-SUCH'));

        $quote = quoteStart($address);

        expect($quote->couponRefusal)->toBe('UNKNOWN_CODE')
            ->and($quote->couponId)->toBeNull()
            ->and($quote->couponDiscount->minorUnits)->toBe(0)
            ->and($quote->couponShares)->toBe([])
            ->and($quote->totals->goodsTotal->minorUnits)->toBe(250_00);
    });
});

describe('the points', function () {
    it('uses as many points as allowed when none are named, and keeps that they were asked', function () {
        ['address' => $address, 'customer' => $customer] = quoteReady();
        Pr::ceiling(100);
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        $expected = Lx::quote($customer, null, 250_00);

        $quote = quoteStart($address, usePoints: true);

        expect($expected->refusal)->toBeNull()
            ->and($quote->pointsUsed)->toBe($expected->pointsCharged)
            ->and($quote->pointsDiscount)->toEqual($expected->discount)
            ->and($quote->totals->pointsDiscount)->toEqual($expected->discount)
            ->and($quote->pointsRefusal)->toBeNull()
            ->and($quote->pointsMode)->toBe($expected->mode->value)
            ->and($quote->pointsMostUsable)->toBe($expected->mostUsable)
            ->and(app(Quotes::class)->find($quote->id)?->usePoints)->toBeTrue();
    });

    it('carries Loyalty refusal, and quotes without the points', function () {
        ['address' => $address] = quoteReady();
        Lx::programme();

        $quote = quoteStart($address, usePoints: true);

        expect($quote->pointsRefusal)->toBe('NOTHING_TO_REDEEM')
            ->and($quote->pointsUsed)->toBe(0)
            ->and($quote->pointsDiscount->minorUnits)->toBe(0);
    });

    it('skips the points when the coupon taken does not allow them', function () {
        ['address' => $address, 'customer' => $customer] = quoteReady();
        Pr::ceiling(100);
        Lx::programme();
        Lx::deliver($customer, 1_000_00);
        Pr::coupon('SOLO', with: ['allow_with_points' => false]);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SOLO'));

        $quote = quoteStart($address, usePoints: true);

        expect($quote->pointsRefusal)->toBe(QuoteBuilder::NOT_WITH_COUPON)
            ->and($quote->pointsUsed)->toBe(0)
            ->and($quote->couponDiscount->minorUnits)->toBeGreaterThan(0)
            ->and($quote->allowWithPoints)->toBeFalse();
    });

    it('refuses the whole redemption when it passes what the coupon left of the room, and keeps it when it fits exactly', function (int $room, ?string $refusal) {
        ['address' => $address, 'customer' => $customer] = quoteReady();
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        quoteWith([
            'quoteRedemption' => fn () => new RedemptionQuote(RedemptionMode::Partial, 500, 500, quoteMoney(5_00), null),
            'customerDiscountRoom' => fn () => quoteMoney($room),
        ]);

        $quote = quoteStart($address, usePoints: true);

        expect($quote->pointsRefusal)->toBe($refusal)
            ->and($quote->pointsUsed)->toBe($refusal === null ? 500 : 0)
            ->and($quote->couponDiscount->minorUnits)->toBe(37_50)
            ->and($customer)->not->toBe('');
    })->with([
        'fits exactly' => [42_50, null],
        'one short' => [42_49, QuoteBuilder::PASSES_CEILING],
    ]);

    it('asks Loyalty for the points named, for the net subtotal and the buyer', function () {
        ['address' => $address, 'customer' => $customer] = quoteReady();
        $asked = [];
        $real = app(PricingApi::class);
        quoteWith([
            'quoteRedemption' => function ($request) use (&$asked) {
                $asked[] = [$request->customerId, $request->audience, $request->pointsAsked, $request->netSubtotal->minorUnits];

                return new RedemptionQuote(RedemptionMode::Partial, 0, 0, quoteMoney(0), RedemptionRefusal::ProgrammeOff);
            },
            // The prices already took 10.00 off: net below gross.
            'prices' => function (StoreId $store, array $lines) use ($real): CartPricesDto {
                $prices = $real->prices($store, quoteLines($lines));

                return new CartPricesDto($prices->storeId, $prices->currencyCode, $prices->taxRateBasisPoints, $prices->lines, $prices->unpriced, $prices->grossSubtotal, $prices->netSubtotal->subtract(quoteMoney(10_00)), $prices->pricedAt, $prices->validUntil);
            },
        ]);

        quoteStart($address, usePoints: true, asked: 70);

        expect($asked)->toBe([[$customer, LoyaltyAudience::Public, 70, 240_00]]);
    });
});

describe('the gift', function () {
    it('gives the highest level whose size is available as a gift after the order own pieces', function (int $giftable, int $lowGiftable, ?int $level) {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        $levels = [new GiftOfferDto('level-high', quoteMoney(200_00), $variants[0]), new GiftOfferDto('level-low', quoteMoney(100_00), $variants[1])];
        quoteWith([
            'giftLevels' => fn () => $levels,
            'stock' => fn (StoreId $store, array $ids) => array_combine($ids, array_map(
                fn (string $id): StockDto => new StockDto($id, false, true, null, $id === $variants[0] ? $giftable : $lowGiftable, false),
                $ids,
            )),
        ]);

        $quote = quoteStart($address);

        expect($quote->giftLevelId)->toBe($level === null ? null : $levels[$level]->levelId)
            ->and($quote->giftVariantId)->toBe($level === null ? null : $levels[$level]->variantId);
    })->with([
        'one left after the two bought' => [3, 0, 0],
        'none left of the high, one of the low after the one bought' => [2, 2, 1],
        'none left of either' => [2, 1, null],
    ]);

    it('asks for the levels the goods total reaches, after the coupon', function () {
        ['address' => $address] = quoteReady();
        Pr::ceiling(50);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $asked = [];
        quoteWith(['giftLevels' => function (StoreId $store, $audience, Money $total) use (&$asked) {
            $asked[] = [$audience->value, $total->minorUnits];

            return [];
        }]);

        quoteStart($address);

        expect($asked)->toBe([['PUBLIC', 212_50]]);
    });

    it('gives a real level of the store', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        $level = Pr::giftLevel($variants[1], 100_00);
        quoteWith(['stock' => fn (StoreId $store, array $ids) => array_combine($ids, array_map(fn (string $id): StockDto => new StockDto($id, false, true, null, 5, false), $ids))]);

        expect(quoteStart($address)->giftLevelId)->toBe($level);
    });
});

describe('the ways to pay', function () {
    it('offers a company a bank transfer only once the store has an account', function (bool $account, array $ways) {
        ['address' => $address] = quoteReady('company');
        quoteWith([
            'isApproved' => fn () => true,
            'bankAccount' => fn () => $account ? new BankAccountDto('SA00 0000', 'Bank', 'TouchWood') : null,
        ]);

        $quote = quoteStart($address);

        expect($quote->audience)->toBe(OrderAudience::Company)
            ->and($quote->waysToPay)->toBe($ways);
    })->with([
        'no account' => [false, [WayToPay::StaffContact]],
        'an account' => [true, [WayToPay::BankTransfer, WayToPay::StaffContact]],
    ]);

    it('refuses a way not offered', function (string $accountType, string $way) {
        ['address' => $address] = quoteReady($accountType);
        quoteWith(['isApproved' => fn () => true, 'bankAccount' => fn () => null]);

        expect(fn () => quoteStart($address, $way))->toThrow(WayToPayNotOffered::class);
    })->with([
        'online, until stage 7' => ['individual', 'ONLINE'],
        'a bank transfer to an individual' => ['individual', 'BANK_TRANSFER'],
        'a bank transfer with no account' => ['company', 'BANK_TRANSFER'],
        'online to a company' => ['company', 'ONLINE'],
    ]);

    it('refuses a way that is none', function () {
        ['address' => $address] = quoteReady();

        expect(Pr::thrown(fn () => quoteStart($address, 'CASH'), InvalidSalesAttribute::class)->attribute)->toBe('way_to_pay');
    });
});

describe('who may order', function () {
    it('refuses an account that may not order, and a company not approved here', function (string $accountType, Closure $spoil) {
        ['address' => $address, 'customer' => $customer] = quoteReady($accountType);
        $spoil($customer);

        expect(fn () => quoteStart($address))->toThrow(MayNotOrder::class);
    })->with([
        'no phone' => ['individual', fn (string $customer) => DB::table('access.customers')->where('id', $customer)->update(['phone' => null, 'phone_verified_at' => null])],
        'email not confirmed' => ['individual', fn (string $customer) => DB::table('access.customers')->where('id', $customer)->update(['email_verified_at' => null])],
        'a company not approved' => ['company', fn () => quoteWith(['isApproved' => fn () => false])],
        'a company never applied' => ['company', fn () => null],
    ]);

    it('refuses in a store switched off', function () {
        ['address' => $address] = quoteReady();
        $store = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        expect(fn () => app(StartCheckoutHandler::class)->handle(new StartCheckout($store, $address, false, null, 'STAFF_CONTACT')))->toThrow(MayNotOrder::class);
    });

    it('lets only a signed-in customer check out', function (Closure $actor) {
        ['address' => $address] = quoteReady();
        Fx::actAs($actor());

        expect(fn () => quoteStart($address))->toThrow(Unauthorized::class);
    })->with([
        'a guest' => [fn () => Actor::guest(strtolower((string) Str::ulid()))],
        'staff' => [fn () => Actor::staff(Fx::staff(superAdmin: true))],
    ]);

    it('refuses points named without using points, or fewer than 1', function (bool $use, int $asked) {
        ['address' => $address] = quoteReady();

        expect(Pr::thrown(fn () => quoteStart($address, usePoints: $use, asked: $asked), InvalidSalesAttribute::class)->attribute)->toBe('points');
    })->with([
        'not using points' => [false, 10],
        'zero' => [true, 0],
        'more than the column holds' => [true, 2_147_483_648],
    ]);
});

describe('what may be bought', function () {
    it('refuses an empty cart or none', function () {
        Fx::actAsCustomer($customer = quoteCustomer());

        expect(fn () => quoteStart(Bx::savedAddress($customer)))->toThrow(NotOrderable::class);
    });

    it('names each size that cannot be ordered as it is', function (array $changes) {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        quoteWith(['storeVariants' => fn (StoreId $store, array $ids) => [
            $variants[0] => quoteVariant($variants[0], []),
            $variants[1] => quoteVariant($variants[1], $changes),
        ]]);

        $refused = Pr::thrown(fn () => quoteStart($address), NotOrderable::class);
        expect($refused->variantIds)->toBe([$variants[1]])
            ->and($refused->context())->toBe(['variants' => $variants[1]]);
    })->with([
        'switched off' => [['isActive' => false]],
        'not orderable' => [['orderable' => false]],
        'not available now' => [['notAvailableNow' => true]],
        'not sold retail' => [['saleModes' => [SaleMode::Wholesale]]],
        'below its minimum' => [['retailMinimum' => 2]],
        'above its maximum' => [['retailMaximum' => 0]],
    ]);

    it('takes a line at its minimum and at its maximum', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        quoteWith(['storeVariants' => fn () => [
            $variants[0] => quoteVariant($variants[0], ['retailMinimum' => 2, 'retailMaximum' => 2]),
            $variants[1] => quoteVariant($variants[1], ['retailMinimum' => 1, 'retailMaximum' => 1]),
        ]]);

        expect(quoteStart($address)->prices->lines)->toHaveCount(2);
    });

    it('reads a wholesale line by its own limits, for a company', function (int $least, ?int $most, bool $taken) {
        ['address' => $address, 'variants' => $variants] = quoteReady('company');
        DB::table('sales.cart_lines')->where('variant_id', $variants[1])->update(['sale_mode' => 'WHOLESALE', 'quantity' => 10]);
        quoteWith([
            'isApproved' => fn () => true,
            'storeVariants' => fn () => [
                $variants[0] => quoteVariant($variants[0], []),
                $variants[1] => quoteVariant($variants[1], ['saleModes' => [SaleMode::Retail, SaleMode::Wholesale], 'wholesaleMinimum' => $least, 'wholesaleMaximum' => $most]),
            ],
        ]);

        $start = fn () => quoteStart($address);

        $taken ? expect($start)->not->toThrow(NotOrderable::class) : expect($start)->toThrow(NotOrderable::class);
    })->with([
        'within' => [10, 10, true],
        'below' => [11, null, false],
        'above' => [1, 9, false],
    ]);

    it('refuses a size that is not in the store at all', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        quoteWith(['storeVariants' => fn () => [$variants[0] => quoteVariant($variants[0], [])]]);

        expect(Pr::thrown(fn () => quoteStart($address), NotOrderable::class)->variantIds)->toBe([$variants[1]]);
    });

    it('refuses a wholesale line to an individual', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        DB::table('sales.cart_lines')->where('variant_id', $variants[1])->update(['sale_mode' => 'WHOLESALE']);

        expect(fn () => quoteStart($address))->toThrow(NotForYourAccount::class);
    });

    it('refuses a product whose category is not open to the account', function (string $accountType, string $kind) {
        ['address' => $address] = quoteReady($accountType);
        $asked = [];
        quoteWith(['isApproved' => fn () => true, 'productsOpenTo' => function ($store, $viewer) use (&$asked) {
            $asked[] = $viewer->value;

            return [];
        }]);

        expect(fn () => quoteStart($address))->toThrow(NotForYourAccount::class)
            ->and($asked)->toBe([$kind]);
    })->with([
        'an individual' => ['individual', 'INDIVIDUAL'],
        'a company' => ['company', 'COMPANY'],
    ]);

    it('counts the pieces of a size over both its modes against its stock', function (int $available, bool $taken) {
        ['address' => $address, 'variants' => $variants] = quoteReady('company');
        quoteAdd($variants[0], 3, 'WHOLESALE');
        quoteWith([
            'isApproved' => fn () => true,
            'storeVariants' => fn () => [
                $variants[0] => quoteVariant($variants[0], ['saleModes' => [SaleMode::Retail, SaleMode::Wholesale], 'wholesaleMinimum' => 1]),
                $variants[1] => quoteVariant($variants[1], []),
            ],
            'stock' => fn (StoreId $store, array $ids) => array_combine($ids, array_map(
                fn (string $id): StockDto => new StockDto($id, true, true, $id === $variants[0] ? $available : null, 0, false),
                $ids,
            )),
        ]);

        $start = fn () => quoteStart($address);

        $taken ? expect($start)->not->toThrow(NotOrderable::class) : expect(Pr::thrown($start, NotOrderable::class)->variantIds)->toBe([$variants[0]]);
    })->with([
        'exactly enough' => [5, true],
        'one short' => [4, false],
    ]);

    it('refuses a size its stock says cannot be ordered', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        quoteWith(['stock' => fn (StoreId $store, array $ids) => array_combine($ids, array_map(
            fn (string $id): StockDto => new StockDto($id, true, $id !== $variants[1], null, 0, false),
            $ids,
        ))]);

        expect(Pr::thrown(fn () => quoteStart($address), NotOrderable::class)->variantIds)->toBe([$variants[1]]);
    });

    it('refuses a size with no price', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        Rx::remove('sa', [$variants[1]]);

        expect(Pr::thrown(fn () => quoteStart($address), NotOrderable::class)->variantIds)->toBe([$variants[1]]);
    });
});

describe('the address', function () {
    it('refuses an address that is not the customer own in this store, or not complete', function (Closure $address) {
        ['customer' => $customer] = quoteReady();

        expect(fn () => quoteStart($address($customer)))->toThrow(AddressNotUsable::class);
    })->with([
        'none' => [fn () => strtolower((string) Str::ulid())],
        'not an id' => [fn () => 'home'],
        'another customer' => [fn () => Bx::savedAddress(quoteCustomer())],
        'another store' => [fn (string $customer) => Bx::savedAddress($customer, 'eg')],
        'not complete' => [function (string $customer) {
            $address = Bx::savedAddress($customer);
            DB::table('access.addresses')->where('id', $address)->update(['fields' => json_encode(['city' => 'Riyadh'])]);

            return $address;
        }],
    ]);
});

describe('the table', function () {
    it('keeps a quote valid only after it is made, its points asked only with points, and a known way to pay', function (array $changes) {
        ['address' => $address] = quoteReady();
        $quote = quoteStart($address);

        expect(fn () => DB::table('sales.quotes')->where('id', $quote->id)->update($changes))->toThrow(QueryException::class);
    })->with([
        'expiring when made' => [fn () => ['expires_at' => DB::raw('created_at')]],
        'points asked without points' => [['points_asked' => 5]],
        'no way to pay' => [['way_to_pay' => 'CASH']],
        'an empty coupon' => [['coupon_code' => '']],
    ]);

    it('deletes a customer quotes with the customer', function () {
        ['address' => $address, 'customer' => $customer] = quoteReady();
        quoteStart($address);
        // Ops' record of the messages sent to them (registering sent a link) holds on to the customer:
        // it goes first, as anonymizing would take it (ops.md §6).
        DB::table('ops.sent_messages')->where('customer_id', $customer)->delete();

        DB::table('access.customers')->where('id', $customer)->delete();

        expect(DB::table('sales.quotes')->count())->toBe(0);
    });
});

it('deletes the expired quotes each night, as the system only', function () {
    ['address' => $address] = quoteReady();
    $t0 = Cx::clockAhead();
    CarbonImmutable::setTestNow($t0);
    $old = quoteStart($address);
    CarbonImmutable::setTestNow($t0->addMinutes(10));
    $fresh = quoteStart($address);
    CarbonImmutable::setTestNow($t0->addMinutes(15)->addMicroseconds(1));

    expect(fn () => app(DeleteExpiredQuotesHandler::class)->handle(new DeleteExpiredQuotes))->toThrow(Unauthorized::class);
    // A super admin passes every permission check, and still is not the system.
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(fn () => app(DeleteExpiredQuotesHandler::class)->handle(new DeleteExpiredQuotes))->toThrow(Unauthorized::class);

    $deleted = Fx::asSystem(fn () => app(DeleteExpiredQuotesHandler::class)->handle(new DeleteExpiredQuotes));

    expect($deleted)->toBe(1)
        ->and(app(Quotes::class)->find($old->id))->toBeNull()
        ->and(app(Quotes::class)->find($fresh->id))->not->toBeNull();

    Queue::fake();
    $nightly = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event->description === DeleteExpiredQuotesJob::class);
    expect($nightly?->onOneServer)->toBeTrue()
        ->and($nightly?->expression)->toBe('30 3 * * *')
        ->and($nightly?->timezone)->toBe('Asia/Riyadh');
    $nightly?->run(app());
    Queue::assertPushed(DeleteExpiredQuotesJob::class, 1);
});

describe('what the review asked for', function () {
    it('keeps every part of a full quote, to the microsecond', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        $t0 = Cx::clockAhead();
        CarbonImmutable::setTestNow($t0->addMicroseconds(123456));
        Pr::ceiling(100);
        Pr::coupon('SUMMER-15');
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));
        $real = app(PricingApi::class);
        $ends = $t0->addMinutes(9)->addSeconds(59)->addMicroseconds(654321);
        quoteWith([
            'quoteRedemption' => fn () => new RedemptionQuote(RedemptionMode::Partial, 900, 500, quoteMoney(5_00), null),
            'giftLevels' => fn () => [new GiftOfferDto(strtolower((string) Str::ulid()), quoteMoney(100_00), $variants[1])],
            'stock' => fn (StoreId $store, array $ids) => array_combine($ids, array_map(fn (string $id): StockDto => new StockDto($id, false, true, null, 9, false), $ids)),
            'prices' => fn (StoreId $store, array $lines) => quotePricesEnding($real->prices($store, quoteLines($lines)), $ends),
        ]);

        $quote = quoteStart($address, usePoints: true, asked: 600);
        $kept = app(Quotes::class)->find($quote->id) ?? throw new LogicException('Not kept.');
        $back = QuoteView::fromArray($kept->id, $kept->storeId, $kept->customerId, $kept->addressId, WayToPay::from($kept->wayToPay), $kept->expiresAt, $kept->createdAt, $kept->result);

        expect($quote->couponShares)->not->toBe([])
            ->and($quote->pointsUsed)->toBe(500)
            ->and($quote->pointsMode)->toBe('PARTIAL')
            ->and($quote->pointsMostUsable)->toBe(900)
            ->and($quote->giftVariantId)->toBe($variants[1])
            ->and($quote->couponNameEn)->toBe('Summer coupon')
            ->and($back)->toEqual($quote)
            ->and($back->prices->validUntil?->format('H:i:s.u'))->toBe($ends->format('H:i:s.u'))
            ->and($back->prices->pricedAt->format('s.u'))->toBe('00.123456')
            ->and($back->expiresAt->format('H:i:s.u'))->toBe($t0->addMinutes(9)->addSeconds(59)->format('H:i:s.u'))
            ->and($back->createdAt->format('H:i:s.u'))->toBe($t0->format('H:i:s.u'));
    });

    it('carries a known coupon refused, by its names, never its id', function () {
        ['address' => $address] = quoteReady();
        Pr::coupon('BIG-ONLY', with: ['minimum_minor' => 1_000_00]);
        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'BIG-ONLY'));

        $quote = quoteStart($address);

        expect($quote->couponRefusal)->toBe('BELOW_MINIMUM')
            ->and($quote->couponId)->toBeNull()
            ->and($quote->couponNameEn)->toBe('Summer coupon')
            ->and($quote->couponNameAr)->toBe('كوبون الصيف')
            ->and($quote->couponDiscount->minorUnits)->toBe(0)
            ->and($quote->allowWithPoints)->toBeFalse();
    });

    it('takes a bank transfer when it is offered', function () {
        ['address' => $address] = quoteReady('company');
        quoteWith(['isApproved' => fn () => true, 'bankAccount' => fn () => new BankAccountDto('SA00 0000', 'Bank', 'TouchWood')]);

        $quote = quoteStart($address, 'bank_transfer');

        expect($quote->wayToPay)->toBe(WayToPay::BankTransfer)
            ->and(app(Quotes::class)->find($quote->id)?->wayToPay)->toBe('BANK_TRANSFER');
    });

    it('refuses a size Inventory gives no answer for', function () {
        ['address' => $address, 'variants' => $variants] = quoteReady();
        quoteWith(['stock' => fn () => [$variants[0] => new StockDto($variants[0], false, true, null, 0, false)]]);

        expect(Pr::thrown(fn () => quoteStart($address), NotOrderable::class)->variantIds)->toBe([$variants[1]]);
    });

    it('prices again once when the prices end while the quote is built, and expires when they end again', function (int $endedTimes, bool $quoted) {
        ['address' => $address] = quoteReady();
        $t0 = Cx::clockAhead();
        CarbonImmutable::setTestNow($t0);
        $real = app(PricingApi::class);
        $asked = 0;
        quoteWith(['prices' => function (StoreId $store, array $lines) use ($real, &$asked, $endedTimes): CartPricesDto {
            $asked++;

            return quotePricesEnding($real->prices($store, quoteLines($lines)), $asked <= $endedTimes ? CarbonImmutable::now() : null);
        }]);

        $quoted ? expect(quoteStart($address)->expiresAt->format('H:i'))->toBe($t0->addMinutes(15)->format('H:i')) : expect(fn () => quoteStart($address))->toThrow(QuoteExpired::class);
        expect($asked)->toBe(2);
    })->with([
        'once' => [1, true],
        'twice' => [2, false],
    ]);

    it('reads no cart before the store and the account are checked', function () {
        ['address' => $address, 'customer' => $customer] = quoteReady();
        $carts = Mockery::mock(Carts::class);
        $carts->shouldNotReceive('find');
        app()->instance(QuoteBuilder::class, app()->make(QuoteBuilder::class, ['carts' => $carts]));
        DB::table('access.customers')->where('id', $customer)->update(['email_verified_at' => null]);

        expect(fn () => quoteStart($address))->toThrow(MayNotOrder::class);
    });
});
