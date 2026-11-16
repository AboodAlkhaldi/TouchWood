<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The shop's card to the design, in a real browser (shop-design.md §4): a reduced price in red with the old one readable
| and «وفّر» what a piece saves, the sale's percent at the photo's corner; Add to cart for one size, which adds it and says
| so, and Choose options for several; the heart filled at once, with no spinner - at a computer's width and on a phone.
|
| Deliberately no RefreshDatabase (the browser suite's way): each run makes its own category and products.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Px::numberFrom((int) substr((string) hrtime(true), -8));
});

/**
 * A category in Saudi Arabia with a one-size product on sale at 30.00 reduced to 24.00, and a two-size one at 30.00.
 *
 * @return array{slug: string, one: string, two: string}
 */
function shopCardItems(): array
{
    $sa = Fx::storeId('sa');
    $category = Px::category('Kitchens');
    $one = Px::ready(['60 cm'], $category);
    $two = Px::ready(['60 cm', '80 cm'], $category);

    foreach ([$one, $two] as $p) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore($sa, $p['product'], true)));
        Px::onSale('sa', $p['product']);
        Rx::set('sa', array_fill_keys($p['variants'], '30.00'));
    }

    // On a whole second, a sale starting now starts at once (Pricing's SaleInput).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
    Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale($sa, null, [$one['variants'][0]], null, '24.00', null, null)));
    CarbonImmutable::setTestNow();

    return [
        'slug' => (string) DB::table('catalog.category_slugs')->where('category_id', $category)->where('locale', 'en')->where('is_current', true)->value('slug'),
        'one' => $one['product'],
        'two' => $two['product'],
    ];
}

it('draws a reduced price in red with the old one and what it saves, the percent on the photo, and Add to cart or Choose options', function () {
    $items = shopCardItems();
    $page = visit('/sa/en/categories/'.$items['slug']);
    $page->resize(1440, 1000);
    $page->navigate('/sa/en/categories/'.$items['slug'], BROWSER_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"card\"]').length === 2"))->toBeTrue();

    // The one-size card on sale: red now, the old price struck and readable, «Save», −20% on the photo, Add to cart.
    $sale = "[...document.querySelectorAll('[data-test=\"card\"]')].find((card) => card.querySelector('[data-test=\"price-before\"]') !== null)";
    expect($page->script("{$sale}.querySelector('[data-test=\"price-now\"] bdi').textContent"))->toStartWith('24.00')
        // Red: our error colour, #982b24 in light mode.
        ->and($page->script("getComputedStyle({$sale}.querySelector('[data-test=\"price-now\"] bdi')).color"))->toBe('rgb(152, 43, 36)')
        ->and($page->script("parseFloat(getComputedStyle({$sale}.querySelector('[data-test=\"price-before\"]')).fontSize)"))->toBe(15)
        ->and($page->script("{$sale}.querySelector('[data-test=\"price-saving\"]').textContent"))->toContain('6.00')
        ->and($page->script("{$sale}.querySelector('[data-test=\"card-off\"]').textContent"))->toContain('20')
        ->and($page->script("{$sale}.querySelector('[data-test=\"card-add\"]') !== null"))->toBeTrue();

    // The two-size card: Choose options, the product page.
    $plain = "[...document.querySelectorAll('[data-test=\"card\"]')].find((card) => card.querySelector('[data-test=\"price-before\"]') === null)";
    expect($page->script("{$plain}.querySelector('[data-test=\"card-choose\"]')?.getAttribute('href') ?? ''"))->toContain('/products/')
        ->and($page->script("{$plain}.querySelector('[data-test=\"card-add\"]') === null"))->toBeTrue();

    // Add to cart adds the size and says so; the header's count goes up.
    $page->script("{$sale}.querySelector('[data-test=\"card-add\"]').click()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"header-cart-pieces\"]')?.textContent.trim() === '1'"))->toBeTrue('the cart did not count the added piece')
        ->and(browserSoon($page, "document.body.innerText.includes('was added to your cart')"))->toBeTrue('no word that it was added');

    // A phone: the cards fit, each with its 32 px «+».
    $page->resize(375, 812);
    $page->navigate('/sa/ar/categories/'.$items['slug'], BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"card\"]').length === 2"))->toBeTrue()
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the cards scroll sideways')
        ->and($page->script("Math.round(document.querySelector('[data-test=\"card-add-phone\"]').getBoundingClientRect().width)"))->toBe(32);
    $page->assertNoJavaScriptErrors();
});

it('fills the heart at once with no spinner, and keeps it saved', function () {
    $items = shopCardItems();
    $customer = Fx::customer(strtolower((string) Str::ulid()).'@example.test');
    $page = visit('/sa/en/sign-in')
        ->type('#email', (string) DB::table('access.customers')->where('id', $customer)->value('email'))
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');
    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue();

    $page->navigate('/sa/en/categories/'.$items['slug'], BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"card-heart\"]').length === 2"))->toBeTrue();

    // Pressed, it is pressed by the next frame - long before any answer - and nothing spins.
    expect($page->script("() => new Promise((resolve) => { const heart = document.querySelector('[data-test=\"card-heart\"]'); heart.click(); requestAnimationFrame(() => requestAnimationFrame(() => resolve(heart.getAttribute('aria-pressed') === 'true' && heart.querySelector('svg.animate-spin, [role=\"status\"]') === null))); })"))->toBeTrue('the heart did not fill at once');
    // The save went in the background: the customer's wishlist holds it.
    $saved = false;

    for ($try = 0; $try < 50 && ! $saved; $try++) {
        $saved = DB::table('feedback.wishlist_items')->where('customer_id', $customer)->count() === 1;
        $saved || usleep(200_000);
    }

    expect($saved)->toBeTrue('the save never reached the wishlist')
        ->and($page->script("document.querySelector('[data-test=\"card-heart\"]').getAttribute('aria-pressed')"))->toBe('true');
    $page->assertNoJavaScriptErrors();
});
