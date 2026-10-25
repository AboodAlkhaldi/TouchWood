<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCoupon;
use Modules\Sales\Application\Command\SetCartCoupon\SetCartCouponHandler;
use Modules\Sales\Application\Query\ViewCart\CartLineView;
use Modules\Sales\Application\Query\ViewCart\CartView;
use Modules\Sales\Application\Query\ViewCart\ViewCart;
use Modules\Sales\Application\Query\ViewCart\ViewCartHandler;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Public\Enums\CartLineProblem;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
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

it('gives a guest their cart, and an empty one before any line', function () {
    ['variants' => $variants] = Cx::ready();
    app()->instance('request', Request::create('/sa/en', 'GET'));
    app()->forgetScopedInstances();
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

    $view = cartView();

    expect([$view->lines, $view->couponCode, $view->subtotal])->toBe([[], null, null])
        ->and($variants)->toHaveCount(2);
});

it('refuses staff and a language but Arabic or English', function () {
    Cx::ready();

    expect(fn () => cartView('fr'))->toThrow(InvalidSalesAttribute::class);

    Fx::actAsStaff(Fx::staff());
    expect(fn () => cartView())->toThrow(Unauthorized::class);
});
