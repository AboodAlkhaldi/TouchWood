<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Sales\Application\Command\AddCartLine\AddCartLine;
use Modules\Sales\Application\Command\AddCartLine\AddCartLineHandler;
use Modules\Sales\Application\Command\DismissCartNotice\DismissCartNotice;
use Modules\Sales\Application\Command\DismissCartNotice\DismissCartNoticeHandler;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Query\ViewCart\CartLineView;
use Modules\Sales\Application\Query\ViewCart\CartNoticeLine;
use Modules\Sales\Application\Query\ViewCart\CartView;
use Modules\Sales\Application\Query\ViewCart\ViewCart;
use Modules\Sales\Application\Query\ViewCart\ViewCartHandler;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Model\RemovedLine;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Public\Enums\CartLineProblem;
use Modules\Sales\Public\Enums\CartNoticeReason;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The cart (sales.md §1.1, §3 ViewCart): each line as it stands now — its card, today's prices, and the
| checkout's own rules marked on it instead of refusing (§1.2 step 2) — the coupon typed, the notice of lines
| that left, and the subtotal of the lines that can be ordered. A customer's or a guest's; read only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function cartView(string $locale = 'en'): CartView
{
    return app(ViewCartHandler::class)->handle(new ViewCart(Fx::storeId('sa'), $locale));
}

/** @return array<string, ?CartLineProblem> variant => problem */
function cartViewProblems(CartView $view): array
{
    $problems = [];

    foreach ($view->lines as $line) {
        $problems[$line->variantId] = $line->problem;
    }

    return $problems;
}

it('shows each line with its card and today prices, the coupon typed, and the subtotal', function () {
    ['variants' => $variants, 'product' => $product] = Cx::ready();
    Pr::coupon('SUMMER-15');
    app(SetCartCouponHandler::class)->handle(new SetCartCoupon(Fx::storeId('sa'), 'SUMMER-15'));

    $view = cartView();

    expect(array_map(fn (CartLineView $line): array => [$line->variantId, $line->productId, $line->mode, $line->quantity, $line->unit?->minorUnits, $line->total?->minorUnits, $line->problem], $view->lines))
        ->toBe([[$variants[0], $product, 'RETAIL', 2, 100_00, 200_00, null], [$variants[1], $product, 'RETAIL', 1, 50_00, 50_00, null]])
        ->and([$view->couponCode, $view->subtotal?->minorUnits, $view->exponent])->toBe(['SUMMER-15', 250_00, 2])
        ->and($view->lines[0]->name)->toBe((string) DB::table('catalog.products')->where('id', $product)->value('name_en'))
        ->and($view->lines[0]->slug)->not->toBeNull()
        ->and($view->lines[0]->code)->not->toBe('')
        ->and(cartView('ar')->lines[0]->name)->toBe((string) DB::table('catalog.products')->where('id', $product)->value('name_ar'));
});

it('marks each line that cannot be ordered as it is, and leaves it out of the subtotal', function (Closure $break, CartLineProblem $problem) {
    ['variants' => $variants, 'product' => $product] = Cx::ready();
    $break($variants, $product);

    $view = cartView();

    expect(cartViewProblems($view))->toBe([$variants[0] => $problem, $variants[1] => null])
        ->and($view->subtotal?->minorUnits)->toBe(50_00);
})->with([
    'not available now' => [fn (array $v) => DB::table('catalog.store_variants')->where('variant_id', $v[0])->update(['not_available_now' => true]), CartLineProblem::NotAvailable],
    'wholesale where it is not sold wholesale' => [fn (array $v) => DB::table('sales.cart_lines')->where('variant_id', $v[0])->update(['sale_mode' => 'WHOLESALE']), CartLineProblem::NotAvailable],
    'wholesale for an individual' => [function (array $v) {
        DB::table('catalog.store_variants')->where('variant_id', $v[0])->update(['sells_wholesale' => true]);
        DB::table('sales.cart_lines')->where('variant_id', $v[0])->update(['sale_mode' => 'WHOLESALE']);
    }, CartLineProblem::NotForYourAccount],
    'out of stock' => [fn (array $v) => Ix::count('sa', [$v[0] => 1]), CartLineProblem::OutOfStock],
    'no price' => [fn (array $v) => Rx::remove('sa', [$v[0]]), CartLineProblem::NotPriced],
]);

it('marks a line below or above its limits', function () {
    ['variants' => $variants, 'product' => $product] = Cx::ready();
    // At least 2, at most 2: the first size's 2 pieces fit; the second's 1 is below; then 3 is above.
    DB::table('catalog.store_products')->where('product_id', $product)->update(['retail_minimum' => 2, 'retail_maximum' => 2]);
    $below = cartViewProblems(cartView());
    DB::table('sales.cart_lines')->where('variant_id', $variants[0])->update(['quantity' => 3]);
    $above = cartViewProblems(cartView());

    expect($below)->toBe([$variants[0] => null, $variants[1] => CartLineProblem::BelowMinimum])
        ->and($above)->toBe([$variants[0] => CartLineProblem::AboveMaximum, $variants[1] => CartLineProblem::BelowMinimum]);
});

it('refuses staff and a language but Arabic or English', function () {
    Cx::ready();

    expect(fn () => cartView('fr'))->toThrow(InvalidSalesAttribute::class);

    Fx::actAsStaff(Fx::staff());
    expect(fn () => cartView())->toThrow(Unauthorized::class);
});

/** A browser with no guest cookie yet, acting as a guest. */
function cartViewAsGuest(): void
{
    app()->instance('request', Request::create('/sa/en', 'GET'));
    app()->forgetScopedInstances();
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
}

it('says whether this account may check out at all: a guest signs in first, an unapproved company cannot', function () {
    Cx::ready();
    $customer = cartView()->mayOrder;
    Fx::actAsCustomer(Cx::customer('company'));
    $company = cartView()->mayOrder;
    cartViewAsGuest();
    $guest = cartView();

    expect([$customer, $company, $guest->mayOrder, $guest->lines, $guest->couponCode, $guest->subtotal])->toBe([true, false, false, [], null, null]);
});

it('gives a guest their lines, wholesale ones marked only by what is true for any account', function () {
    ['variants' => $variants] = Cx::ready();
    DB::table('catalog.store_variants')->where('variant_id', $variants[1])->update(['sells_wholesale' => true]);
    cartViewAsGuest();
    app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId('sa'), $variants[0], 'RETAIL', 2));
    app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId('sa'), $variants[1], 'WHOLESALE', 1));

    $view = cartView();

    expect(array_map(fn (CartLineView $line): array => [$line->variantId, $line->mode, $line->problem], $view->lines))
        ->toBe([[$variants[0], 'RETAIL', null], [$variants[1], 'WHOLESALE', null]])
        ->and($view->couponCode)->toBeNull();
});

it('counts the pieces of a size over both modes against its stock, for an approved company', function () {
    $b2b = Mockery::mock(B2BApi::class);
    $b2b->shouldReceive('isApproved')->andReturn(true);
    app()->instance(B2BApi::class, $b2b);
    ['variants' => $variants] = Cx::ready('company');
    DB::table('catalog.store_variants')->where('variant_id', $variants[0])->update(['sells_wholesale' => true]);
    app(AddCartLineHandler::class)->handle(new AddCartLine(Fx::storeId('sa'), $variants[0], 'WHOLESALE', 2));
    Ix::count('sa', [$variants[0] => 3]);

    $view = cartView();

    // 2 retail and 2 wholesale of the first size against 3 in stock.
    expect(cartViewProblems($view))->toBe([$variants[0] => CartLineProblem::OutOfStock, $variants[1] => null])
        ->and(array_column(array_map(fn (CartLineView $line): array => ['p' => $line->problem], $view->lines), 'p'))->toHaveCount(3)
        ->and($view->mayOrder)->toBeTrue();
});

it('names each line that left the cart, and why', function () {
    ['variants' => $variants, 'product' => $product, 'customer' => $customer] = Cx::ready();
    $cart = app(Carts::class)->find(StoreId::fromString(Fx::storeId('sa')), $customer, null) ?? throw new LogicException('No cart.');
    app(Carts::class)->setNotice($cart->id, [new RemovedLine($variants[1], SaleMode::Wholesale, 3, RemovedLine::WHOLESALE)], CarbonImmutable::now());

    $notice = cartView()->notice;

    expect(array_map(fn (CartNoticeLine $line): array => [$line->variantId, $line->name, $line->mode, $line->quantity, $line->reason], $notice))
        ->toBe([[$variants[1], (string) DB::table('catalog.products')->where('id', $product)->value('name_en'), 'WHOLESALE', 3, CartNoticeReason::Wholesale]]);
});

it('closes the notice for good, and does nothing without a cart or a notice (sales.md §4.2 B17)', function () {
    ['variants' => $variants, 'customer' => $customer] = Cx::ready();
    $cart = app(Carts::class)->find(StoreId::fromString(Fx::storeId('sa')), $customer, null) ?? throw new LogicException('No cart.');
    app(Carts::class)->setNotice($cart->id, [new RemovedLine($variants[1], SaleMode::Wholesale, 3, RemovedLine::WHOLESALE)], CarbonImmutable::now());
    $dismiss = fn () => app(DismissCartNoticeHandler::class)->handle(new DismissCartNotice(Fx::storeId('sa')));

    $before = count(cartView()->notice);
    $dismiss();
    $dismiss();
    $after = cartView();
    cartViewAsGuest();
    $dismiss();

    expect([$before, $after->notice, count($after->lines)])->toBe([1, [], 2])
        ->and(DB::table('sales.carts')->where('id', $cart->id)->value('notice'))->toBeNull()
        ->and(DB::table('sales.carts')->whereNull('customer_id')->count())->toBe(0);

    Fx::actAsStaff(Fx::staff());
    expect($dismiss)->toThrow(Unauthorized::class);
});

it('reads a cart of three lines in the same number of queries as one of one', function () {
    ['variants' => $variants] = Cx::ready();
    $count = function (): int {
        cartView();
        DB::flushQueryLog();
        DB::enableQueryLog();
        cartView();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    DB::table('sales.cart_lines')->where('variant_id', $variants[1])->delete();
    $one = $count();
    ['variants' => $more] = Px::ready(['90 cm', '100 cm']);
    Rx::switchOn('sa', $more);
    Rx::set('sa', [$more[0] => '10.00', $more[1] => '20.00']);
    Ix::count('sa', [$more[0] => 10, $more[1] => 10]);
    Cx::add($more[0]);
    Cx::add($more[1]);

    // Measured 2026-10-25: the buyer and the store (CartBuyer, Platform), the cart and its lines, the sizes
    // (Catalog's store variants, variants, products), the categories' switches, the cards, the stock, the prices,
    // the currency - the limits a target, not a cap (owner, 2026-10-19).
    expect($count())->toBe($one);
});
