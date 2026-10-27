<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Modules\B2B\Application\Settings\BankAccountSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Sales\Application\SalesSettings;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
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
| Sales's shop screens over HTTP (sales.md §4.1 S3–S6): the cart - its lines, a line's pieces set and taken
| off, the coupon kept, a guest's Checkout sent to sign in and back - and checkout over the quote, placed
| once, the order placed with a company's bank details.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
});

/**
 * A customer with two of a 100.00 size and one of a 50.00 in their cart in 'sa', then the real ActorContext
 * given back; and a browser signed in as them.
 *
 * @return array{0: array{customer: string, address: string, variants: list<string>, product: string}, 1: AdminBrowser}
 */
function shopCartScreensCustomer(string $type = 'individual', bool $bank = false): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $ready = Cx::ready($type);

        if ($type === 'company') {
            Bx::approved($ready['customer']);
        }

        if ($bank) {
            Fx::actAsStaff(Fx::staff(superAdmin: true));
            app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32'));
            app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::BANK, 'sa', 'Al Noor Bank'));
            app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::HOLDER, 'sa', 'TouchWood Trading'));
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
    $email = (string) DB::table('access.customers')->where('id', $ready['customer'])->value('email');
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return [$ready, $browser];
}

/**
 * @return array<string, mixed>
 */
function shopCartScreensProps(AdminBrowser $browser, string $uri, string $component): array
{
    $props = [];

    $browser->get($uri)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props) {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** Work done as the system, then the real ActorContext given back (the fixtures leave theirs bound). */
function shopCartScreensAsSystem(Closure $work): void
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        $work();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

describe('S3, S4 · The cart', function () {
    it('shows the lines with today\'s prices, the pieces and the subtotal, and the header\'s cart', function () {
        [, $browser] = shopCartScreensCustomer();

        $cart = shopCartScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

        expect(count($cart['lines']))->toBe(2)
            ->and([$cart['pieces'], $cart['subtotal']['minor'], $cart['mayOrder'], $cart['problems']])->toBe([3, 250_00, true, false])
            ->and($cart['lines'][0])->toMatchArray(['mode' => 'RETAIL', 'quantity' => 2, 'problem' => null])
            ->and($cart['lines'][0]['unit']['minor'])->toBe(100_00)
            ->and($cart['frame']['sales']['words']['cart'])->toBe('Cart');
    });

    it('sets a line\'s pieces, takes a line off, and keeps the coupon for checkout', function () {
        [$ready, $browser] = shopCartScreensCustomer();
        [$first, $second] = $ready['variants'];

        $browser->post('/sa/en/cart/lines/change', ['variant' => $first, 'mode' => 'RETAIL', 'quantity' => 5])->assertRedirect();
        $removed = $browser->post('/sa/en/cart/lines/remove', ['variant' => $second, 'mode' => 'RETAIL']);
        $kept = $browser->post('/sa/en/cart/coupon', ['code' => 'WELCOME']);
        $cart = shopCartScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

        expect([AdminBrowser::flashed($removed, 'status'), AdminBrowser::flashed($kept, 'status')])->toBe(['Removed from your cart.', 'Coupon kept for checkout.'])
            ->and(array_map(static fn (array $line): array => [$line['variantId'], $line['quantity']], $cart['lines']))->toBe([[$first, 5]])
            ->and([$cart['pieces'], $cart['couponCode']])->toBe([5, 'WELCOME']);
    });

    it('offers Add to Cart on the product page, and adds the pieces asked to the cart', function () {
        [$ready, $browser] = shopCartScreensCustomer();
        $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $ready['product'])->where('locale', 'en')->where('is_current', true)->value('slug');

        $product = shopCartScreensProps($browser, "/sa/en/products/{$slug}", 'Catalog/Storefront/Product');
        $browser->post('/sa/en/cart/lines', ['variant' => $ready['variants'][1], 'mode' => 'RETAIL', 'quantity' => 2])->assertRedirect();
        $cart = shopCartScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

        // Each size with what this customer's cart holds of it, sold retail only (sales.md §4.2 B15).
        $sizes = $product['sections']['sales.cart']['sizes'] ?? [];

        expect(array_column($sizes, 'inCart', 'variantId'))->toBe([$ready['variants'][0] => 2, $ready['variants'][1] => 1])
            ->and(array_map(static fn (array $size): array => [$size['retail']['inCart'] ?? null, $size['retail']['outOfStock'] ?? null, $size['wholesale'], $size['outOfStock']], $sizes))
            ->toBe([[2, false, null, false], [1, false, null, false]])
            ->and($product['translations']['sales::shop_add.add.add'] ?? null)->toBe('Add to Cart')
            ->and(array_column($cart['lines'], 'quantity', 'variantId')[$ready['variants'][1]] ?? null)->toBe(3);
    });

    it('shows the shipping and how much more ships free, the next gift, the points and the header\'s pieces (B12, B13)', function () {
        [$ready, $browser] = shopCartScreensCustomer();
        shopCartScreensAsSystem(function (): void {
            Cx::setting(SalesSettings::FLAT_FEE, 25_00);
            Cx::setting(SalesSettings::FREE_ABOVE, 300_00);
            ['variants' => [$gift]] = Px::ready(['90 cm']);
            Rx::switchOn('sa', [$gift]);
            Rx::set('sa', [$gift => '10.00']);
            Ix::count('sa', [$gift => 5]);
            Pr::giftLevel($gift, 400_00);
            Lx::programme();
        });

        $cart = shopCartScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

        // The goods: 2 × 100.00 + 50.00 = 250.00 - 50.00 short of free shipping, 150.00 of the gift.
        expect([$cart['shippingFee']['minor'], $cart['freeAbove']['minor'], $cart['freeMissing']['minor'], $cart['freeShare']])->toBe([25_00, 300_00, 50_00, 83])
            ->and([$cart['nextGift']['amount']['minor'], $cart['nextGift']['missing']['minor']])->toBe([400_00, 150_00])
            ->and($cart['pointsToEarn'])->toBeGreaterThan(0)
            ->and([$cart['mayNotOrder'], $cart['bestSellers']])->toBe([null, []])
            ->and($cart['frame']['sales']['pieces'])->toBe(3)
            ->and($cart['translations']['sales::shop_cart.extras.for_free_shipping'] ?? null)->toBe('more for free shipping.');

        // Past the free-above amount: nothing missing, the bar full.
        $browser->post('/sa/en/cart/lines/change', ['variant' => $ready['variants'][1], 'mode' => 'RETAIL', 'quantity' => 2])->assertRedirect();
        $free = shopCartScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

        expect([$free['freeMissing'], $free['freeShare'], $free['frame']['sales']['pieces']])->toBe([null, 100, 4]);
    });

    it('shows the store\'s best sellers under an empty cart, and an account that may not order why, with its fix (B12, B14)', function () {
        [$ready, $browser] = shopCartScreensCustomer();
        DB::table('access.customers')->where('id', $ready['customer'])->update(['email_verified_at' => null]);

        $blocked = shopCartScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');
        DB::table('sales.cart_lines')->delete();
        $empty = shopCartScreensProps($browser, '/sa/en/cart', 'Sales/Storefront/Cart');

        expect([$blocked['mayOrder'], $blocked['mayNotOrder']])->toBe([false, 'EMAIL_UNVERIFIED'])
            ->and($blocked['bestSellers'])->toBe([])
            ->and($empty['lines'])->toBe([])
            ->and(array_column($empty['bestSellers'], 'productId'))->toContain($ready['product'])
            ->and($empty['frame']['sales']['pieces'])->toBe(0);
    });

    it('shows a guest an empty cart, and sends their Checkout to sign in and back to the cart', function () {
        $guest = new AdminBrowser('10.9.7.'.random_int(20, 250));

        $cart = shopCartScreensProps($guest, '/sa/en/cart', 'Sales/Storefront/Cart');
        $checkout = $guest->get('/sa/en/cart/sign-in');

        expect([$cart['lines'], $cart['pieces'], $cart['mayOrder']])->toBe([[], 0, false])
            ->and($checkout->headers->get('Location'))->toEndWith('/sa/en/sign-in');
        $guest->get('/sa/en/checkout')->assertRedirect();
    });
});

describe('S5, S6 · Checkout and the order placed', function () {
    it('works out the quote at the default address, places the order once, and shows it placed', function () {
        [$ready, $browser] = shopCartScreensCustomer();

        $checkout = shopCartScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $placed = $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId']]);
        $again = $browser->post('/sa/en/checkout/place', ['quote' => $checkout['quoteId']]);
        $number = (string) DB::table('sales.orders')->where('customer_id', $ready['customer'])->value('number');
        $page = shopCartScreensProps($browser, "/sa/en/orders/{$number}/placed", 'Sales/Storefront/Placed');

        expect([$checkout['addressId'], $checkout['ways'], $checkout['way'], $checkout['subtotal']['minor']])->toBe([$ready['address'], ['STAFF_CONTACT'], 'STAFF_CONTACT', 250_00])
            ->and([$checkout['savings'], $checkout['coupon'], $checkout['points'], $checkout['bank']])->toBe([null, null, null, null])
            ->and($checkout['total']['minor'])->toBeGreaterThan(250_00)
            ->and($placed->headers->get('Location'))->toEndWith("/sa/en/orders/{$number}/placed")
            ->and($again->headers->get('Location'))->toEndWith("/sa/en/orders/{$number}/placed")
            ->and(DB::table('sales.orders')->where('customer_id', $ready['customer'])->count())->toBe(1)
            ->and([$page['number'], $page['wayToPay'], $page['total']['minor']])->toBe([$number, 'STAFF_CONTACT', $checkout['total']['minor']]);
    });

    it('asks a company to choose its way, and shows the store\'s bank details for a transfer', function () {
        [, $browser] = shopCartScreensCustomer('company', bank: true);

        $first = shopCartScreensProps($browser, '/sa/en/checkout', 'Sales/Storefront/Checkout');
        $transfer = shopCartScreensProps($browser, '/sa/en/checkout?way=BANK_TRANSFER', 'Sales/Storefront/Checkout');
        $browser->post('/sa/en/checkout/place', ['quote' => $transfer['quoteId']]);
        $number = (string) DB::table('sales.orders')->orderByDesc('placed_at')->value('number');
        $placed = shopCartScreensProps($browser, "/sa/en/orders/{$number}/placed", 'Sales/Storefront/Placed');

        expect([$first['ways'], $first['way']])->toBe([['BANK_TRANSFER', 'STAFF_CONTACT'], null])
            ->and([$transfer['way'], $transfer['bank']['iban'] ?? null])->toBe(['BANK_TRANSFER', 'GB82 WEST 1234 5698 7654 32'])
            ->and([$placed['wayToPay'], $placed['bank']['holder'] ?? null, $placed['mayUpload']])->toBe(['BANK_TRANSFER', 'TouchWood Trading', true]);
    });

    it('sends a checkout with nothing to order back to the cart, saying why', function () {
        [$ready, $browser] = shopCartScreensCustomer();

        foreach ($ready['variants'] as $variant) {
            $browser->post('/sa/en/cart/lines/remove', ['variant' => $variant, 'mode' => 'RETAIL']);
        }

        $empty = $browser->get('/sa/en/checkout');

        expect($empty->headers->get('Location'))->toEndWith('/sa/en/cart')
            ->and(AdminBrowser::formError($empty))->not->toBeNull();
    });
});
