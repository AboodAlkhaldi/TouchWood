<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The shop's prices in a real browser (sales.md §4.1 S1; catalog.md amendment 35). The feature tests prove what
| the pages are handed; this proves a guest sees them drawn: a card's "From" and "Wholesale from", a size's
| price with its old one struck and its percent off, a size sold wholesale only priced at its minimum, and the
| wholesale bands under the Wholesale choice, the one the quantity typed buys at highlighted.
|
| Deliberately no RefreshDatabase, as the other browser tests: every row here is new, from the clock.
*/

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Px::numberFrom((int) substr((string) hrtime(true), -8));
});

it('draws a guest the card\'s prices, each size\'s price and sale, and the wholesale bands as the quantity changes', function () {
    // 60 cm retail at 30.00, on sale at 24.00; 80 cm both ways at 25.00, a band from 5 at 20.00; 100 cm
    // wholesale only at 40.00, bands from 5 at 35.00 and from 20 at 32.00. Wholesale from 5 pieces.
    $category = Px::category('Kitchens');
    $p = Px::ready(['60 cm', '80 cm', '100 cm'], $category);
    [$sixty, $eighty, $hundred] = $p['variants'];
    $store = Fx::storeId('sa');

    Fx::asSystem(function () use ($p, $store, $sixty, $eighty, $hundred): void {
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], [
            $sixty => ['retail' => true, 'wholesale' => false],
            $eighty => ['retail' => true, 'wholesale' => true],
            $hundred => ['retail' => false, 'wholesale' => true],
        ], wholesaleMinimum: 5));
    });
    Px::onSale('sa', $p['product']);
    Rx::set('sa', [$sixty => '30.00', $eighty => '25.00', $hundred => '40.00']);
    // On a whole second, a sale starting now starts at once (Pricing's SaleInput); the clock runs on after.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
    Fx::asSystem(function () use ($store, $sixty, $eighty, $hundred): void {
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $eighty, [5 => '20.00']));
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $hundred, [5 => '35.00', 20 => '32.00']));
        app(AddSaleHandler::class)->handle(new AddSale($store, null, [$sixty], null, '24.00', null, null));
    });
    CarbonImmutable::setTestNow();

    $slug = static fn (string $kind, string $id): string => (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', 'en')->where('is_current', true)->value('slug');
    $text = static fn (mixed $page, string $selector): string => (string) $page->script("document.querySelector('{$selector}')?.textContent ?? ''");

    // The card: "From" the lowest retail price (60 cm on sale), and "Wholesale from" 80 cm's band.
    $page = visit('/sa/en/categories/'.$slug('category', $category));
    expect($text($page, '[data-test="card"] [data-test="price-from"]'))->toContain('24.00')
        ->and($text($page, '[data-test="card"] [data-test="wholesale-from"]'))->toContain('Wholesale from')->toContain('20.00');

    // A phone (frontend.md §2.1): the card and its two prices fit, with nothing scrolling sideways.
    $page->resize(375, 812);
    expect(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the category scrolls sideways');

    // The product page opens on 60 cm: its sale price, the old one struck, 20% off.
    $page = visit('/sa/en/products/'.$slug('product', $p['product']));
    expect($text($page, '[data-test="price"]'))->toContain('24.00')
        ->and($text($page, '[data-test="price-before"]'))->toContain('30.00')
        ->and($text($page, '[data-test="price-off"]'))->toBe('−20%');

    // 100 cm, sold wholesale only: priced at its minimum of 5 (the band from 5), its bands under the choice. The
    // quantity turns to 5 a render after the price, so the wait is for the band it highlights.
    $page->click('100 cm');
    expect(browserUntil($page, "document.querySelector('[data-test=\"wholesale-price\"]')?.textContent.includes('35.00') && document.querySelector('[data-test=\"band-current\"]')?.textContent.includes('From 5 pieces')"))->toBeTrue()
        ->and($text($page, '[data-test="band-current"]'))->toContain('From 5 pieces')->toContain('35.00')
        ->and($text($page, '[data-test="bands"]'))->toContain('From 20 pieces')->toContain('32.00');

    // A phone (frontend.md §2.1): the bands under the choice fit, with nothing scrolling sideways.
    $page->resize(375, 812);
    expect(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the product page scrolls sideways');
    $page->resize(1280, 800);

    // 20 pieces typed: the band from 20 applies.
    $page->clear('#add-quantity')->type('#add-quantity', '20');
    expect(browserUntil($page, "document.querySelector('[data-test=\"band-current\"]')?.textContent.includes('32.00')"))->toBeTrue();

    // 80 cm sells both ways: its retail price under the name; Wholesale stays chosen, its band from 5 for 20
    // pieces; Retail chosen, no bands.
    $page->click('80 cm');
    expect(browserUntil($page, "document.querySelector('[data-test=\"price\"]')?.textContent.includes('25.00') && document.querySelector('[data-test=\"band-current\"]')?.textContent.includes('20.00')"))->toBeTrue();
    $page->click('[data-test="mode-RETAIL"]');
    expect(browserUntil($page, "!document.querySelector('[data-test=\"bands\"]')"))->toBeTrue();
});

// Amendment 43 (owner, 2026-11-11): a wholesale-only sale shows its old price struck through, as a retail sale.
it('strikes a wholesale-only size\'s old price through on a sale, on its card and its page, in both languages and on a phone', function () {
    // 100 cm, wholesale only from 5: 40.00, bands from 5 at 35.00 and from 20 at 32.00; on sale at 30.00.
    $category = Px::category('Kitchens');
    $p = Px::ready(['100 cm'], $category);
    [$hundred] = $p['variants'];
    $store = Fx::storeId('sa');

    Fx::asSystem(function () use ($p, $store, $hundred): void {
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], [$hundred => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5));
    });
    Px::onSale('sa', $p['product']);
    Rx::set('sa', [$hundred => '40.00']);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
    Fx::asSystem(function () use ($store, $hundred): void {
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $hundred, [5 => '35.00', 20 => '32.00']));
        app(AddSaleHandler::class)->handle(new AddSale($store, null, [$hundred], null, '30.00', null, null));
    });
    CarbonImmutable::setTestNow();

    $slug = static fn (string $kind, string $id, string $locale): string => rawurlencode((string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', $locale)->where('is_current', true)->value('slug'));
    $text = static fn (mixed $page, string $selector): string => (string) $page->script("document.querySelector('{$selector}')?.textContent ?? ''");
    $latin = static fn (string $shown): bool => preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $shown) === 0;

    // The card: "Wholesale from" 30.00, the least it would cost with no sale (the band from 20, 32.00) struck, 6% off.
    $page = visit('/sa/en/categories/'.$slug('category', $category, 'en'));
    expect(browserUntil($page, "document.querySelector('[data-test=\"card\"] [data-test=\"wholesale-from\"] [data-test=\"price-before\"]') !== null"))->toBeTrue()
        ->and($text($page, '[data-test="card"] [data-test="wholesale-from"]'))->toContain('Wholesale from')->toContain('30.00')
        ->and($text($page, '[data-test="card"] [data-test="wholesale-from"] s[data-test="price-before"]'))->toContain('Was')->toContain('32.00')
        ->and($text($page, '[data-test="card"] [data-test="wholesale-from"] [data-test="price-off"]'))->toBe('−6%');

    // Its page: priced at its minimum of 5 - the band from 5, 35.00, struck beside the sale's 30.00, 14% off.
    $page->navigate('/sa/en/products/'.$slug('product', $p['product'], 'en'), BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "document.querySelector('[data-test=\"wholesale-price\"] [data-test=\"price-before\"]')?.textContent.includes('35.00') === true"))->toBeTrue()
        ->and($text($page, '[data-test="wholesale-price"] [data-test="price"]'))->toContain('30.00')
        ->and($text($page, '[data-test="wholesale-price"] [data-test="price-off"]'))->toBe('−14%');

    // Arabic on a phone (frontend.md §2.1): right to left, the same prices in Latin digits, nothing scrolling sideways.
    $page->resize(375, 812);
    $page->navigate('/sa/ar/categories/'.$slug('category', $category, 'ar'), BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"card\"] [data-test=\"wholesale-from\"] [data-test=\"price-before\"]') !== null"))->toBeTrue()
        ->and($wholesale = $text($page, '[data-test="card"] [data-test="wholesale-from"]'))->toContain('الجملة ابتداءً من')->toContain('30.00')
        ->and($text($page, '[data-test="card"] [data-test="wholesale-from"] s[data-test="price-before"]'))->toContain('كان')->toContain('32.00')
        ->and($text($page, '[data-test="card"] [data-test="wholesale-from"] [data-test="price-off"]'))->toBe('−6%')
        ->and($latin($wholesale))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the category scrolls sideways');

    $page->navigate('/sa/ar/products/'.$slug('product', $p['product'], 'ar'), BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"wholesale-price\"] [data-test=\"price-before\"]')?.textContent.includes('35.00') === true"))->toBeTrue()
        ->and($price = $text($page, '[data-test="wholesale-price"]'))->toContain('30.00')->toContain('كان')
        ->and($text($page, '[data-test="wholesale-price"] [data-test="price-off"]'))->toBe('−14%')
        ->and($latin($price))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the product page scrolls sideways');

    $page->assertNoJavaScriptErrors();
});

it('starts the wholesale bands at the minimum after it moved: a band below it folded in, the size\'s own price up to a band above it', function () {
    // Pricing starts the first band at the wholesale minimum; Catalog may move the minimum after. Both sizes
    // wholesale only: 80 cm at 22.00, its band from 20 at 18.00 (set under a minimum of 20); 60 cm at 40.00,
    // bands from 5 at 35.00 and from 20 at 32.00 (under 5). Then the minimum moves to 10.
    $category = Px::category('Kitchens');
    $p = Px::ready(['60 cm', '80 cm'], $category);
    [$sixty, $eighty] = $p['variants'];
    $store = Fx::storeId('sa');
    $terms = static fn (int $minimum) => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], [
        $sixty => ['retail' => false, 'wholesale' => true],
        $eighty => ['retail' => false, 'wholesale' => true],
    ], wholesaleMinimum: $minimum));

    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true)));
    Px::onSale('sa', $p['product']);
    Rx::set('sa', [$sixty => '40.00', $eighty => '22.00']);
    Fx::asSystem(function () use ($store, $sixty, $eighty, $terms): void {
        $terms(20);
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $eighty, [20 => '18.00']));
        $terms(5);
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $sixty, [5 => '35.00', 20 => '32.00']));
        $terms(10);
    });

    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $p['product'])->where('locale', 'en')->where('is_current', true)->value('slug');
    $text = static fn (mixed $page, string $selector): string => (string) $page->script("document.querySelector('{$selector}')?.textContent ?? ''");

    // 60 cm: none bought under 10, so the band from 5 starts at 10.
    $page = visit('/sa/en/products/'.$slug);
    expect(browserUntil($page, "document.querySelector('[data-test=\"band-current\"]') !== null"))->toBeTrue()
        ->and($text($page, '[data-test="band-current"]'))->toContain('From 10 pieces')->toContain('35.00')
        ->and($text($page, '[data-test="bands"]'))->not->toContain('From 5 pieces')->toContain('From 20 pieces')
        ->and($text($page, '[data-test="wholesale-price"]'))->toContain('35.00');

    // A quantity under the minimum buys at no step: none highlighted.
    $page->clear('#add-quantity')->type('#add-quantity', '7');
    expect(browserUntil($page, "document.querySelector('[data-test=\"band-current\"]') === null"))->toBeTrue();

    // 80 cm: its own price from 10, its band from 20.
    $page->click('80 cm');
    expect(browserUntil($page, "document.querySelector('[data-test=\"band-current\"]')?.textContent.includes('22.00')"))->toBeTrue()
        ->and($text($page, '[data-test="band-current"]'))->toContain('From 10 pieces')
        ->and($text($page, '[data-test="bands"]'))->toContain('From 20 pieces')->toContain('18.00');
});
