<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cookie\QueueingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\GuestIdentity;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Access\Public\Events\GuestBecameCustomer;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Sales\Application\Command\AddCartLine\AddCartLine;
use Modules\Sales\Application\Command\AddCartLine\AddCartLineHandler;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLine;
use Modules\Sales\Application\Command\ChangeCartLine\ChangeCartLineHandler;
use Modules\Sales\Application\Command\EmptyOldCarts\EmptyOldCarts;
use Modules\Sales\Application\Command\EmptyOldCarts\EmptyOldCartsHandler;
use Modules\Sales\Application\Command\RemoveCartLine\RemoveCartLine;
use Modules\Sales\Application\Command\RemoveCartLine\RemoveCartLineHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\SignInToUseCoupon;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\RemovedLine;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Infrastructure\Queue\EmptyOldCartsJob;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The cart (sales.md §1.1, §6, §8 "Cart"): permissive adds — any size, either mode, 1 to 1,000,000 —
| one cart a buyer a store; a signed-in individual never buys wholesale (answer 32); the guest's id made
| with the first line; a guest's carts moving or merging at sign-in, the lines the account may not buy
| leaving with why; "sign in to use a coupon"; an anonymized account's carts deleted; 90 days.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function cartStore(string $code = 'sa'): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/** A browser with no guest cookie yet, acting as a guest. */
function cartAsGuest(?string $cookie = null): void
{
    app()->instance('request', Request::create('/sa/en', 'GET', [], $cookie === null ? [] : [(string) config('access.storefront.guest_cookie') => $cookie]));
    app()->forgetScopedInstances();
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
}

/** The refusal a call answered with, of a value it names. */
function cartRefused(Closure $call): InvalidSalesAttribute
{
    try {
        $call();
    } catch (InvalidSalesAttribute $refused) {
        return $refused;
    }

    throw new RuntimeException('Nothing was refused.');
}

function cartAdd(string $variantId, string $mode = 'RETAIL', int $quantity = 1, string $storeCode = 'sa'): void
{
    app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId($storeCode), $variantId, $mode, $quantity));
}

function cartOf(?string $customerId, ?string $guestId, string $storeCode = 'sa'): ?Cart
{
    return app(Carts::class)->find(cartStore($storeCode), $customerId, $guestId);
}

/**
 * @return list<array{string, string, int}>
 */
function cartLines(?Cart $cart): array
{
    return $cart === null ? [] : array_map(static fn ($line): array => [$line->variantId, $line->mode->value, $line->quantity], $cart->lines);
}

describe('adding', function () {
    it('adds any size in either mode, the same size and mode once, its quantities added and capped', function () {
        ['variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        $omar = Fx::customer('omar@example.test', 'sa', 'company');
        Fx::actAsCustomer($omar);

        cartAdd(strtoupper($sixty), 'retail', 2);
        cartAdd($sixty, 'RETAIL', 3);
        cartAdd($sixty, 'WHOLESALE', 10);
        cartAdd($eighty, 'RETAIL', 999_999);
        cartAdd($eighty, 'RETAIL', 5);

        expect(cartLines(cartOf($omar, null)))->toBe([[$sixty, 'RETAIL', 5], [$sixty, 'WHOLESALE', 10], [$eighty, 'RETAIL', 1_000_000]])
            ->and(DB::table('sales.carts')->count())->toBe(1);
    });

    it('keeps one cart a store: the same customer\'s cart in another store is another', function () {
        ['variants' => [$sixty]] = Px::ready();
        $sara = Fx::customer();
        Fx::actAsCustomer($sara);

        cartAdd($sixty);
        cartAdd($sixty, storeCode: 'ae');

        expect(cartLines(cartOf($sara, null)))->toBe([[$sixty, 'RETAIL', 1]])
            ->and(cartLines(cartOf($sara, null, 'ae')))->toBe([[$sixty, 'RETAIL', 1]])
            ->and(DB::table('sales.carts')->count())->toBe(2);
    });

    it('never lets a signed-in individual buy wholesale, and lets a company and a guest', function () {
        ['variants' => [$sixty]] = Px::ready();
        Fx::actAsCustomer(Fx::customer());

        expect(fn () => cartAdd($sixty, 'WHOLESALE'))->toThrow(NotForYourAccount::class)
            ->and(DB::table('sales.cart_lines')->count())->toBe(0);

        Fx::actAsCustomer(Fx::customer('works@example.test', 'sa', 'company'));
        cartAdd($sixty, 'WHOLESALE');

        cartAsGuest();
        cartAdd($sixty, 'WHOLESALE');

        expect(DB::table('sales.cart_lines')->where('sale_mode', 'WHOLESALE')->count())->toBe(2);
    });

    it('makes the guest with the first line, and keeps the same guest for the rest of the request', function () {
        ['variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        cartAsGuest();

        cartAdd($sixty);
        cartAdd($eighty);
        $guest = app(GuestIdentity::class)->current();

        expect($guest)->not->toBeNull()
            ->and(app(QueueingFactory::class)->queued((string) config('access.storefront.guest_cookie'))?->getValue())->toBe($guest)
            ->and(cartLines(cartOf(null, $guest)))->toBe([[$sixty, 'RETAIL', 1], [$eighty, 'RETAIL', 1]]);
    });

    it('refuses what cannot be added, naming why', function (Closure $add, string $attribute) {
        ['variants' => [$sixty]] = Px::ready();
        Fx::actAsCustomer(Fx::customer());

        expect(cartRefused(fn () => $add($sixty))->attribute)->toBe($attribute)
            ->and(DB::table('sales.carts')->count())->toBe(0);
    })->with([
        'a store that is not one' => [fn (string $v) => app(AddCartLineHandler::class)->handle(new AddCartLine('not-a-store', $v, 'RETAIL', 1)), 'store'],
        'a size that is not one' => [fn () => cartAdd('not-a-size'), 'variant'],
        'a size that is nothing' => [fn () => cartAdd(strtolower((string) Str::ulid())), 'variant'],
        'a mode that is neither' => [fn (string $v) => cartAdd($v, 'BULK'), 'sale_mode'],
        'none' => [fn (string $v) => cartAdd($v, quantity: 0), 'quantity'],
        'too many' => [fn (string $v) => cartAdd($v, quantity: 1_000_001), 'quantity'],
        'a store switched off' => [function (string $v) {
            $egypt = Fx::storeId('eg');
            Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
            Fx::actAsCustomer(Fx::customer('late@example.test'));
            app(AddCartLineHandler::class)->handle(new AddCartLine($egypt, $v, 'RETAIL', 1));
        }, 'store'],
    ]);

    it('is for a customer or a guest only', function () {
        ['variants' => [$sixty]] = Px::ready();
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => cartAdd($sixty))->toThrow(Unauthorized::class);
    });
});

describe('changing and removing', function () {
    it('sets a line\'s quantity, refuses a line the cart has not, and removes one or nothing', function () {
        ['variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        $sara = Fx::customer();
        Fx::actAsCustomer($sara);
        cartAdd($sixty, quantity: 2);

        app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $sixty, 'RETAIL', 7));

        expect(cartLines(cartOf($sara, null)))->toBe([[$sixty, 'RETAIL', 7]])
            ->and(cartRefused(fn () => app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $eighty, 'RETAIL', 1)))->attribute)->toBe('variant')
            ->and(fn () => app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $sixty, 'RETAIL', 0)))->toThrow(InvalidSalesAttribute::class);

        app(RemoveCartLineHandler::class)->handle(new RemoveCartLine(Fx::storeId('sa'), $eighty, 'RETAIL'));
        app(RemoveCartLineHandler::class)->handle(new RemoveCartLine(Fx::storeId('sa'), strtoupper($sixty), 'retail'));

        expect(cartLines(cartOf($sara, null)))->toBe([]);
    });

    it('lets a guest with no cart yet remove nothing, and makes no guest for it', function () {
        ['variants' => [$sixty]] = Px::ready();
        cartAsGuest();

        app(RemoveCartLineHandler::class)->handle(new RemoveCartLine(Fx::storeId('sa'), $sixty, 'RETAIL'));

        expect(app(GuestIdentity::class)->current())->toBeNull()
            ->and(DB::table('sales.carts')->count())->toBe(0);
    });
});

describe('the coupon', function () {
    it('keeps the code a customer typed, spaces around it left off, and takes it off when emptied', function () {
        $sara = Fx::customer();
        Fx::actAsCustomer($sara);

        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), '  summer-15 '));
        expect(cartOf($sara, null)?->couponCode)->toBe('summer-15');

        app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), '   '));
        expect(cartOf($sara, null)?->couponCode)->toBeNull()
            ->and(cartRefused(fn () => app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), str_repeat('A', 65))))->attribute)->toBe('coupon');
    });

    it('tells a guest to sign in to use a coupon', function () {
        cartAsGuest();

        expect(fn () => app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15')))->toThrow(SignInToUseCoupon::class);
    });
});

describe('a guest who signs in', function () {
    it('moves the guest\'s carts in every store to a new account, and merges them into an existing one', function () {
        ['variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
        $sara = Fx::customer();
        Fx::actAsCustomer($sara);
        cartAdd($sixty, quantity: 2);
        cartAsGuest();
        cartAdd($sixty, quantity: 3);
        cartAdd($eighty);
        cartAdd($eighty, storeCode: 'ae');
        $guest = (string) app(GuestIdentity::class)->current();

        event(new GuestBecameCustomer((string) Str::uuid(), strtoupper($guest), $sara, GuestBecameCustomer::SIGNED_IN, CarbonImmutable::now()));
        event(new GuestBecameCustomer((string) Str::uuid(), $guest, $sara, GuestBecameCustomer::SIGNED_IN, CarbonImmutable::now()));

        expect(cartLines(cartOf($sara, null)))->toBe([[$sixty, 'RETAIL', 5], [$eighty, 'RETAIL', 1]])
            ->and(cartLines(cartOf($sara, null, 'ae')))->toBe([[$eighty, 'RETAIL', 1]])
            ->and(DB::table('sales.carts')->whereNotNull('guest_id')->count())->toBe(0)
            ->and(DB::table('sales.carts')->count())->toBe(2);
    });

    it('takes out what an individual may not buy, each with why, and keeps a company\'s wholesale', function () {
        $hidden = Px::category('Trade only');
        ['variants' => [$open]] = Px::ready(['60 cm']);
        ['variants' => [$closed]] = Px::ready(['60 cm'], $hidden);
        DB::table('catalog.store_category_audiences')->insert(['store_id' => Fx::storeId('sa'), 'category_id' => $hidden, 'for_individuals' => false, 'for_companies' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sara = Fx::customer();
        $works = Fx::customer('works@example.test', 'sa', 'company');

        foreach ([$sara, $works] as $customer) {
            cartAsGuest();
            cartAdd($open, quantity: 2);
            cartAdd($open, 'WHOLESALE', 20);
            cartAdd($closed);
            event(new GuestBecameCustomer((string) Str::uuid(), (string) app(GuestIdentity::class)->current(), $customer, GuestBecameCustomer::REGISTERED, CarbonImmutable::now()));
        }

        $saraCart = cartOf($sara, null);
        $notice = array_map(static fn (RemovedLine $line): array => [$line->variantId, $line->mode->value, $line->quantity, $line->reason], $saraCart === null ? [] : $saraCart->notice);

        expect(cartLines(cartOf($sara, null)))->toBe([[$open, 'RETAIL', 2]])
            ->and($notice)->toBe([[$open, 'WHOLESALE', 20, RemovedLine::WHOLESALE], [$closed, 'RETAIL', 1, RemovedLine::NOT_FOR_YOUR_ACCOUNT]])
            ->and(cartLines(cartOf($works, null)))->toBe([[$open, 'RETAIL', 2], [$open, 'WHOLESALE', 20], [$closed, 'RETAIL', 1]])
            ->and(cartOf($works, null)?->notice)->toBe([]);

        // The notice stays until the cart next changes.
        Fx::actAsCustomer($sara);
        cartAdd($open);
        expect(cartOf($sara, null)?->notice)->toBe([]);
    });
});

it('deletes an anonymized account\'s carts in every store, and no one else\'s', function () {
    ['variants' => [$sixty]] = Px::ready();
    $sara = Fx::customer();
    $layla = Fx::customer('layla@example.test');
    Fx::actAsCustomer($sara);
    cartAdd($sixty);
    cartAdd($sixty, storeCode: 'ae');
    Fx::actAsCustomer($layla);
    cartAdd($sixty);

    event(new CustomerAnonymized((string) Str::uuid(), strtoupper($sara), CarbonImmutable::now()));

    expect(DB::table('sales.carts')->pluck('customer_id')->all())->toBe([$layla]);
});

it('empties the carts no one touched for 90 days each night, as the system only', function () {
    ['variants' => [$sixty]] = Px::ready();
    $sara = Fx::customer();
    $layla = Fx::customer('layla@example.test');
    Fx::actAsCustomer($sara);
    cartAdd($sixty);
    Fx::actAsCustomer($layla);
    cartAdd($sixty);
    DB::table('sales.carts')->where('customer_id', $sara)->update(['updated_at' => CarbonImmutable::now()->subDays(91)]);
    DB::table('sales.carts')->where('customer_id', $layla)->update(['updated_at' => CarbonImmutable::now()->subDays(89)]);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(fn () => app(EmptyOldCartsHandler::class)->handle(new EmptyOldCarts))->toThrow(Unauthorized::class);

    $emptied = Fx::asSystem(fn () => app(EmptyOldCartsHandler::class)->handle(new EmptyOldCarts));

    expect($emptied)->toBe(1)
        ->and(DB::table('sales.carts')->pluck('customer_id')->all())->toBe([$layla]);

    Queue::fake();
    $nightly = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event->description === EmptyOldCartsJob::class);
    expect($nightly?->onOneServer)->toBeTrue();
    $nightly?->run(app());
    Queue::assertPushed(EmptyOldCartsJob::class, 1);
});

describe('what the review asked for', function () {
    it('makes no guest for an add it refuses', function () {
        cartAsGuest();

        expect(cartRefused(fn () => cartAdd('not-a-size'))->attribute)->toBe('variant')
            ->and(cartRefused(fn () => cartAdd(strtolower((string) Str::ulid())))->attribute)->toBe('variant')
            ->and(app(GuestIdentity::class)->current())->toBeNull()
            ->and(app(QueueingFactory::class)->getQueuedCookies())->toBe([]);
    });

    it('never lets a signed-in individual set a wholesale line, and refuses staff every change', function () {
        ['variants' => [$sixty]] = Px::ready();
        Fx::actAsCustomer(Fx::customer());
        expect(fn () => app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $sixty, 'WHOLESALE', 2)))->toThrow(NotForYourAccount::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $sixty, 'RETAIL', 2)))->toThrow(Unauthorized::class)
            ->and(fn () => app(RemoveCartLineHandler::class)->handle(new RemoveCartLine(Fx::storeId('sa'), $sixty, 'RETAIL')))->toThrow(Unauthorized::class)
            ->and(fn () => app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15')))->toThrow(Unauthorized::class);
    });

    it('caps a merge at 1,000,000, keeps each line\'s time, and moves a cart of a store switched off since', function () {
        ['variants' => [$sixty]] = Px::ready(['60 cm'], null);
        $sara = Fx::customer();
        Fx::actAsCustomer($sara);
        cartAdd($sixty, quantity: 600_000);
        cartAsGuest();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->subDay());
        cartAdd($sixty, quantity: 600_000);
        cartAdd($sixty, storeCode: 'eg');
        CarbonImmutable::setTestNow();
        $guest = (string) app(GuestIdentity::class)->current();
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        event(new GuestBecameCustomer((string) Str::uuid(), $guest, $sara, GuestBecameCustomer::SIGNED_IN, CarbonImmutable::now()));

        expect(cartLines(cartOf($sara, null)))->toBe([[$sixty, 'RETAIL', 1_000_000]])
            ->and(DB::table('sales.carts')->whereNull('guest_id')->count())->toBe(2)
            ->and(DB::table('sales.carts')->whereNotNull('guest_id')->count())->toBe(0);
    });

    it('ends the notice with any change of the cart: a quantity, a removal, a coupon', function (Closure $change) {
        $hidden = Px::category('Trade only');
        ['variants' => [$open]] = Px::ready(['60 cm']);
        ['variants' => [$closed]] = Px::ready(['60 cm'], $hidden);
        DB::table('catalog.store_category_audiences')->insert(['store_id' => Fx::storeId('sa'), 'category_id' => $hidden, 'for_individuals' => false, 'for_companies' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sara = Fx::customer();
        cartAsGuest();
        cartAdd($open);
        cartAdd($closed);
        event(new GuestBecameCustomer((string) Str::uuid(), (string) app(GuestIdentity::class)->current(), $sara, GuestBecameCustomer::REGISTERED, CarbonImmutable::now()));
        expect(cartOf($sara, null)?->notice)->toHaveCount(1);

        Fx::actAsCustomer($sara);
        $change($open);

        expect(cartOf($sara, null)?->notice)->toBe([]);
    })->with([
        'a quantity' => [fn (string $v) => app(ChangeCartLineHandler::class)->handle(new ChangeCartLine(Fx::storeId('sa'), $v, 'RETAIL', 3))],
        'a removal' => [fn (string $v) => app(RemoveCartLineHandler::class)->handle(new RemoveCartLine(Fx::storeId('sa'), $v, 'RETAIL'))],
        'a coupon' => [fn () => app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'))],
    ]);

    it('never fails a sign-in: a move that fails is reported and the guest\'s carts stay', function () {
        Exceptions::fake();
        ['variants' => [$sixty]] = Px::ready();
        $sara = Fx::customer();
        cartAsGuest();
        cartAdd($sixty);
        $guest = (string) app(GuestIdentity::class)->current();
        $real = app(Carts::class);
        $failing = Mockery::mock(Carts::class);
        $failing->shouldReceive('lockGuestCarts')->andThrow(new RuntimeException('The cart is away.'));
        app()->instance(Carts::class, $failing);

        event(new GuestBecameCustomer((string) Str::uuid(), $guest, $sara, GuestBecameCustomer::SIGNED_IN, CarbonImmutable::now()));

        Exceptions::assertReported(RuntimeException::class);
        app()->instance(Carts::class, $real);
        expect(cartLines(cartOf(null, $guest)))->toBe([[$sixty, 'RETAIL', 1]]);
    });
});
