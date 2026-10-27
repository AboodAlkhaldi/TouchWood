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

    // The product page opens on 60 cm: its sale price, the old one struck, 20% off.
    $page = visit('/sa/en/products/'.$slug('product', $p['product']));
    expect($text($page, '[data-test="price"]'))->toContain('24.00')
        ->and($text($page, '[data-test="price-before"]'))->toContain('30.00')
        ->and($text($page, '[data-test="price-off"]'))->toBe('−20%');

    // 100 cm, sold wholesale only: priced at its minimum of 5 (the band from 5), its bands under the choice.
    $page->click('100 cm');
    expect(browserUntil($page, "document.querySelector('[data-test=\"wholesale-price\"]')?.textContent.includes('35.00')"))->toBeTrue()
        ->and($text($page, '[data-test="band-current"]'))->toContain('From 5 pieces')->toContain('35.00')
        ->and($text($page, '[data-test="bands"]'))->toContain('From 20 pieces')->toContain('32.00');

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
