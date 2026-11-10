<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindow;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindowHandler;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindows;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindowsHandler;
use Modules\Pricing\Application\Prices\DiscountPreviewer;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Application\Query\ListPrices\ListPrices;
use Modules\Pricing\Application\Query\ListPrices\ListPricesHandler;
use Modules\Pricing\Application\Query\ListPrices\PriceRow;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsFor;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsForHandler;
use Modules\Pricing\Domain\Exception\CeilingBelowSales;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\Exception\PercentOutOfRange;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Domain\ValueObject\DiscountLines;
use Modules\Pricing\Infrastructure\Queue\ApplyPriceWindowJob;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Contracts\ProviderPrices;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Modules\Pricing\Public\Dto\CampaignPreviewSizeDto;
use Modules\Pricing\Public\Dto\CampaignPriceSetDto;
use Modules\Pricing\Public\Dto\CampaignTargetDto;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Enums\CampaignLines;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Modules\Pricing\Public\Enums\PriceKind;
use Modules\Pricing\Public\Events\CampaignReachChecked;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| Campaign prices (pricing.md §9.6; content.md §1.7 #3, #4, #14, #17, Q2, Q3, Q4, Q18): Content's runs,
| held and applied by Pricing once scheduled - products, sizes, categories and brands, each a percentage
| off, a size also a fixed price; the most particular target pricing a size; products left out of
| categories and brands; never an archived size; one more candidate, the lowest winning, an "always wins"
| sale or discount beating it; the ceiling held on saving, on a lower ceiling and on a retail price; a
| category or brand target following its products; the timed tasks; in a wired store a provider's price
| past the ceiling taken and the size skipped, rejoining by itself.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-11-10 10:00:00+00');
    Queue::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function campaignPrices(): CampaignPrices
{
    return app(CampaignPrices::class);
}

function campaignSa(): StoreId
{
    return StoreId::fromString(Rx::storeId('sa'));
}

function campaignRun(): string
{
    return strtolower((string) Str::ulid());
}

function campaignAt(string $moment): DateTimeImmutable
{
    return CarbonImmutable::parse($moment)->toDateTimeImmutable();
}

function campaignPercent(CampaignTargetKind $kind, string $id, int $basisPoints): CampaignTargetDto
{
    return new CampaignTargetDto($kind, $id, $basisPoints);
}

function campaignFixed(string $sizeId, int $halalas): CampaignTargetDto
{
    return new CampaignTargetDto(CampaignTargetKind::Size, $sizeId, amount: Money::of($halalas, 'SAR'));
}

/**
 * @param  list<CampaignTargetDto>  $targets
 * @param  list<string>  $leftOut
 */
function campaignSet(array $targets, CampaignLines $lines = CampaignLines::Both, array $leftOut = []): CampaignPriceSetDto
{
    return new CampaignPriceSetDto($lines, $targets, $leftOut);
}

/**
 * @param  list<CampaignTargetDto>  $targets
 * @param  list<string>  $leftOut
 */
function campaignSave(string $run, array $targets, CampaignLines $lines = CampaignLines::Both, string $start = '2026-11-10 09:00:00+00', string $end = '2026-11-20 00:00:00+00', array $leftOut = []): void
{
    campaignPrices()->save(campaignSa(), $run, campaignSet($targets, $lines, $leftOut), campaignAt($start), campaignAt($end));
}

/**
 * A save not yet made, for `toThrow` and `Rx::invalid`: a run of its own unless one is named.
 *
 * @param  list<CampaignTargetDto>  $targets
 * @param  list<string>  $leftOut
 */
function campaignSaving(array $targets, string $start = '2026-11-10 09:00:00+00', string $end = '2026-11-20 00:00:00+00', array $leftOut = [], ?string $run = null): Closure
{
    return static fn () => campaignSave($run ?? campaignRun(), $targets, start: $start, end: $end, leftOut: $leftOut);
}

function campaignUnit(string $size, SaleMode $mode = SaleMode::Retail): ?int
{
    return app(PricingApi::class)->prices(campaignSa(), [new CartLineDto($size, $mode, 1)])->lines[0]->unit->minorUnits ?? null;
}

function campaignKind(string $size): ?PriceKind
{
    return app(PricingApi::class)->prices(campaignSa(), [new CartLineDto($size, SaleMode::Retail, 1)])->lines[0]->kind ?? null;
}

function campaignWired(bool $wired = true): void
{
    app()->instance(StoreWiring::class, new class($wired) implements StoreWiring
    {
        public function __construct(private bool $wired) {}

        public function isWired(StoreId $store): bool
        {
            return $this->wired;
        }

        public function wiredStores(): array
        {
            return $this->wired ? array_map(static fn ($store): string => $store->id, app(PlatformApi::class)->allStores()) : [];
        }
    });
}

/**
 * A category with a sub-category, a product in each, a product of another brand outside them; every
 * size at 100 SAR.
 *
 * @return array{top: string, child: string, brand: string, inTop: array{product: string, variants: list<string>, width: string}, inChild: array{product: string, variants: list<string>, width: string}, outside: array{product: string, variants: list<string>, width: string}}
 */
function campaignShop(): array
{
    $top = Px::category('Kitchens');
    $child = Px::category('Drawers', $top);
    $inTop = Px::ready(['60 cm'], Px::category('Shelves', $top));
    $inChild = Px::ready(['60 cm', '80 cm'], $child);
    $outside = Px::ready(['60 cm'], Px::category('Doors'));
    $brand = Px::brand('Hafele');
    DB::table('catalog.products')->where('id', $outside['product'])->update(['brand_id' => $brand]);
    Rx::set('sa', array_fill_keys([...$inTop['variants'], ...$inChild['variants'], ...$outside['variants']], '100'));

    return ['top' => $top, 'child' => $child, 'brand' => $brand, 'inTop' => $inTop, 'inChild' => $inChild, 'outside' => $outside];
}

describe('what a run prices (§9.6 #1, #2)', function () {
    it('prices products, sizes, categories and brands, the most particular target saying a size\'s price, those left out left out', function () {
        $shop = campaignShop();
        [$sixty, $eighty] = $shop['inChild']['variants'];
        $run = campaignRun();

        campaignSave($run, [
            campaignPercent(CampaignTargetKind::Category, $shop['top'], 1000),
            // The product's own 5% beats its category's 10%: picked by name, it stays as picked.
            campaignPercent(CampaignTargetKind::Product, $shop['inChild']['product'], 500),
            // And a size's own price beats its product's.
            campaignFixed($eighty, 7500),
            campaignPercent(CampaignTargetKind::Brand, $shop['brand'], 2000),
        ], leftOut: [$shop['inTop']['product']]);

        expect(campaignUnit($shop['inTop']['variants'][0]))->toBe(10000)
            ->and(campaignUnit($sixty))->toBe(9500)
            ->and(campaignUnit($eighty))->toBe(7500)
            ->and(campaignUnit($shop['outside']['variants'][0]))->toBe(8000)
            ->and(campaignKind($sixty))->toBe(PriceKind::Campaign)
            ->and(Rx::candidates('sa', $sixty))->toContain("RETAIL 1 9500 SAR CAMPAIGN - 2026-11-10 09:00:00+00 2026-11-20 00:00:00+00 {$run}", "WHOLESALE 1 9500 SAR CAMPAIGN - 2026-11-10 09:00:00+00 2026-11-20 00:00:00+00 {$run}")
            // Catalog's card shows the price and the retail price before it at once (§1.10).
            ->and(Rx::told('sa', $eighty))->toBe([7500, 10000, 'SAR'])
            ->and(campaignPrices()->read(campaignSa(), strtoupper($run))?->targets)->toHaveCount(4)
            ->and(campaignPrices()->read(campaignSa(), $run)?->leftOut)->toBe([$shop['inTop']['product']]);

        // Priced again for another reason, a left-out product still takes nothing from its category.
        Rx::set('sa', [$shop['inTop']['variants'][0] => '110']);

        expect(campaignUnit($shop['inTop']['variants'][0]))->toBe(11000);
    });

    it('takes the lowest of a size\'s categories and brand, and a left-out product keeps a target picked by name', function () {
        $shop = campaignShop();
        $size = $shop['inChild']['variants'][0];
        $brand = (string) DB::table('catalog.products')->where('id', $shop['inChild']['product'])->value('brand_id');

        campaignSave(campaignRun(), [
            campaignPercent(CampaignTargetKind::Category, $shop['top'], 1000),
            campaignPercent(CampaignTargetKind::Category, $shop['child'], 1500),
            campaignPercent(CampaignTargetKind::Brand, $brand, 1200),
            campaignFixed($shop['inTop']['variants'][0], 9000),
        ], leftOut: [$shop['inTop']['product']]);

        expect(campaignUnit($size))->toBe(8500)
            ->and(campaignUnit($shop['inTop']['variants'][0]))->toBe(9000);
    });

    it('applies to the lines chosen: retail only, wholesale only, or both (content.md Q3 (a))', function () {
        $shop = campaignShop();
        [$a, $b] = $shop['inChild']['variants'];

        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Size, $a, 1000)], CampaignLines::Retail);
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Size, $b, 1000)], CampaignLines::Wholesale);

        $modes = static fn (string $size): array => array_values(array_unique(array_map(static fn (string $line): string => explode(' ', $line)[0], array_filter(Rx::candidates('sa', $size), static fn (string $line): bool => str_contains($line, 'CAMPAIGN')))));

        expect($modes($a))->toBe(['RETAIL'])
            ->and($modes($b))->toBe(['WHOLESALE'])
            ->and(campaignUnit($b))->toBe(10000);
    });

    it('never prices an archived size, and drops one archived after it was saved', function () {
        $shop = campaignShop();
        [$a, $b] = $shop['inChild']['variants'];
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Product, $shop['inChild']['product'], 1000)]);

        expect(campaignUnit($b))->toBe(9000);

        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($b)));

        expect(array_filter(Rx::candidates('sa', $b), static fn (string $line): bool => str_contains($line, 'CAMPAIGN')))->toBe([])
            ->and(campaignUnit($a))->toBe(9000);
    });

    it('re-prices a percentage when the retail price changes', function () {
        $shop = campaignShop();
        $size = $shop['inTop']['variants'][0];
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Category, $shop['top'], 1000)]);

        Rx::set('sa', [$size => '200']);

        expect(campaignUnit($size))->toBe(18000);
    });

    it('follows a product moved into or out of its category, or given another brand (content.md Q18 (a))', function () {
        $shop = campaignShop();
        $moved = $shop['outside'];
        $product = app(ProductRepository::class)->find($moved['product']) ?? throw new LogicException('No product.');
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Category, $shop['child'], 1000)]);

        expect(campaignUnit($moved['variants'][0]))->toBe(10000);

        Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($moved['product'], $product->name()->ar, $product->name()->en, $product->brandId(), descriptionAr: $text, descriptionEn: $text, categoryId: $shop['child'])));

        expect(campaignUnit($moved['variants'][0]))->toBe(9000);

        // A brand target the same way: the product given another brand loses it.
        $brandRun = campaignRun();
        campaignSave($brandRun, [campaignPercent(CampaignTargetKind::Brand, $shop['brand'], 2000)]);
        expect(campaignUnit($moved['variants'][0]))->toBe(8000);

        Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($moved['product'], $product->name()->ar, $product->name()->en, Px::brand('Blum'), descriptionAr: $text, descriptionEn: $text, categoryId: $shop['child'])));

        expect(campaignUnit($moved['variants'][0]))->toBe(9000);
    });
});

describe('among the other prices (§9.6 #3)', function () {
    it('is one more candidate: the lowest wins, a cheaper sale beating it, many runs at once', function () {
        $shop = campaignShop();
        [$a, $b] = $shop['inChild']['variants'];
        Rx::switchOn('sa', [$a]);
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Category, $shop['child'], 1000)]);
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Size, $b, 1500)]);
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$a], null, '85', null, null)));

        expect(campaignUnit($a))->toBe(8500)
            ->and(campaignKind($a))->toBe(PriceKind::Sale)
            ->and(campaignUnit($b))->toBe(8500)
            ->and(campaignKind($b))->toBe(PriceKind::Campaign);
    });

    it('gives way to an "always wins" sale or category discount even when cheaper (content.md Q4 (a))', function () {
        $shop = campaignShop();
        [$a] = $shop['inChild']['variants'];
        Rx::switchOn('sa', [$a]);
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Category, $shop['top'], 2500)]);
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$a], null, '95', null, null, true)));
        $shelves = (string) DB::table('catalog.products')->where('id', $shop['inTop']['product'])->value('category_id');
        Fx::asSystem(fn () => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $shelves, '5', null, 'BOTH', null, null, true)));

        expect(campaignUnit($a))->toBe(9500)
            ->and(campaignUnit($shop['inTop']['variants'][0]))->toBe(9500)
            ->and(campaignKind($shop['inTop']['variants'][0]))->toBe(PriceKind::Category)
            // Elsewhere in the category the campaign still wins.
            ->and(campaignUnit($shop['inChild']['variants'][1]))->toBe(7500);
    });

    it('shows its sizes under On Sale Now', function () {
        $shop = campaignShop();
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Size, $shop['outside']['variants'][0], 1000)]);
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Size, $shop['inTop']['variants'][0], 1000)], start: '2026-11-12 00:00:00+00');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $ids = array_map(static fn (PriceRow $row): string => $row->size->variantId, app(ListPricesHandler::class)->handle(new ListPrices(Rx::storeId('sa'), null, ListPrices::ON_SALE_NOW))->rows);

        expect($ids)->toBe([$shop['outside']['variants'][0]]);
    });

    it('is named in an offer\'s note before saving when it reaches those sizes in those dates', function () {
        $shop = campaignShop();
        $size = $shop['inChild']['variants'][0];
        Rx::switchOn('sa', [$size]);
        $run = campaignRun();
        campaignSave($run, [campaignPercent(CampaignTargetKind::Product, $shop['inChild']['product'], 2000)]);
        app()->setLocale('en');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $note = app(OverlapsForHandler::class)->handle(new OverlapsFor(Rx::storeId('sa'), null, [$size], null, null, percent: '10'));
        $later = app(OverlapsForHandler::class)->handle(new OverlapsFor(Rx::storeId('sa'), null, [$size], '2026-11-25 00:00:00+00', null, percent: '10'));

        expect(array_map(static fn ($campaign): array => [$campaign->id, $campaign->sharedVariantIds, $campaign->description], $note->campaigns))->toBe([[$run, [$size], 'A campaign']])
            ->and($note->sizes[0]->wins)->toBeFalse()
            ->and($note->sizes[0]->notWinning)->toBe('lower_price')
            ->and($later->campaigns)->toBe([]);
    });
});

it('is named in a category discount\'s preview on the sizes it prices, its dates and lines meeting the discount\'s', function () {
    $shop = campaignShop();
    $run = campaignRun();
    $retailOnly = campaignRun();
    campaignSave($run, [campaignPercent(CampaignTargetKind::Size, $shop['inChild']['variants'][0], 1000)]);
    campaignSave($retailOnly, [campaignPercent(CampaignTargetKind::Size, $shop['inChild']['variants'][1], 1000)], CampaignLines::Retail);
    $store = app(PlatformApi::class)->store(campaignSa()) ?? throw new LogicException('No store.');
    $discount = static fn (DiscountLines $lines, string $start): CategoryDiscount => new CategoryDiscount('preview', $store->id, $shop['top'], 500, null, $lines, campaignAt($start), null, false, []);

    $both = app(DiscountPreviewer::class)->preview($store, $discount(DiscountLines::Both, '2026-11-10 10:00:00+00'));
    $wholesale = app(DiscountPreviewer::class)->preview($store, $discount(DiscountLines::Wholesale, '2026-11-10 10:00:00+00'));
    $later = app(DiscountPreviewer::class)->preview($store, $discount(DiscountLines::Both, '2026-11-25 00:00:00+00'));

    expect(array_map(static fn ($campaign): array => [$campaign->id, $campaign->lines, $campaign->sharedVariantIds], $both->overlappingCampaigns))
        ->toEqualCanonicalizing([[$run, 'BOTH', [$shop['inChild']['variants'][0]]], [$retailOnly, 'RETAIL', [$shop['inChild']['variants'][1]]]])
        ->and(array_map(static fn ($campaign): string => $campaign->id, $wholesale->overlappingCampaigns))->toBe([$run])
        ->and($later->overlappingCampaigns)->toBe([])
        // Its retail prices read from the same candidates: every size of the category, at 100.
        ->and($both->reachedSizes)->toBe(3);
});

describe('the ceiling (§9.6 #4; content.md Q2 (a))', function () {
    it('refuses a run deeper than the ceiling, naming the products, a fixed price by its sizes', function () {
        $shop = campaignShop();
        app()->setLocale('en');

        try {
            campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Category, $shop['child'], 3500)]);
            $percent = null;
        } catch (OverCeiling $error) {
            $percent = [$error->ceilingPercent, $error->products];
        }

        try {
            campaignSave(campaignRun(), [campaignFixed($shop['inTop']['variants'][0], 6000)]);
            $fixed = null;
        } catch (OverCeiling $error) {
            $fixed = [$error->variants, $error->products];
        }

        expect($percent[0] ?? null)->toBe(30)
            ->and($percent[1] ?? '')->toStartWith('Drawers')
            ->and($fixed[0] ?? null)->toBe([$shop['inTop']['variants'][0]])
            ->and($fixed[1] ?? '')->toStartWith('Drawer')
            ->and(DB::table('pricing.campaign_sets')->count())->toBe(0);

        // At the ceiling itself: allowed.
        campaignSave(campaignRun(), [campaignFixed($shop['inTop']['variants'][0], 7000)]);
        expect(campaignUnit($shop['inTop']['variants'][0]))->toBe(7000);
    });

    it('holds back a lower ceiling while a run goes deeper, naming what it was set on', function () {
        $shop = campaignShop();
        campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Category, $shop['child'], 2500)]);
        app()->setLocale('en');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        try {
            app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 20));
            $named = null;
        } catch (CeilingBelowSales $error) {
            $named = $error->products;
        }

        expect($named)->toStartWith('Drawers');

        // Down to the run's own depth: allowed.
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 25));
        expect(app(PlatformApi::class)->setting(PricingSettings::MAX_DISCOUNT_PERCENT, campaignSa())->value)->toBe(25);
    });

    it('holds back a retail price that takes a fixed price past it, naming the size', function () {
        $shop = campaignShop();
        $size = $shop['inTop']['variants'][0];
        campaignSave(campaignRun(), [campaignFixed($size, 7500)]);

        try {
            Rx::set('sa', [$size => '120']);
            $over = null;
        } catch (OverCeiling $error) {
            $over = $error->variants;
        }

        expect($over)->toBe([$size])
            ->and((int) Rx::retail('sa', $size)?->amount_minor)->toBe(10000);

        Rx::set('sa', [$size => '105']);
        expect(campaignUnit($size))->toBe(7500);
    });
});

describe('its life (§9.6 #7, #8)', function () {
    it('queues its timed tasks, and its start and end push the prices', function () {
        $shop = campaignShop();
        $size = $shop['inTop']['variants'][0];
        $run = campaignRun();
        campaignSave($run, [campaignPercent(CampaignTargetKind::Size, $size, 1000)], start: '2026-11-12 00:00:00+00', end: '2026-11-15 00:00:00+00');
        $queue = Queue::getFacadeRoot();
        assert($queue instanceof QueueFake);
        $tasks = $queue->pushed(ApplyPriceWindowJob::class, static fn (ApplyPriceWindowJob $job): bool => $job->saleId === $run);

        expect($tasks)->toHaveCount(2)
            ->and(Rx::told('sa', $size)[0] ?? null)->toBe(10000);

        CarbonImmutable::setTestNow('2026-11-12 00:00:00+00');
        $start = $tasks->first();
        assert($start instanceof ApplyPriceWindowJob);

        expect(Fx::asSystem(fn (): bool => app(ApplyPriceWindowHandler::class)->handle(new ApplyPriceWindow($start->storeId, $start->saleId, $start->moment))))->toBeTrue()
            ->and(Rx::told('sa', $size)[0] ?? null)->toBe(9000);

        // The daily check does it again, in case a task was lost.
        CarbonImmutable::setTestNow('2026-11-15 01:00:00+00');
        expect(Fx::asSystem(fn (): int => app(CheckPriceWindowsHandler::class)->handle(new CheckPriceWindows)))->toBeGreaterThanOrEqual(1)
            ->and(Rx::told('sa', $size)[0] ?? null)->toBe(10000);
    });

    it('changes its end, ends now, removes - a run started is never removed, an ended one never changed', function () {
        $shop = campaignShop();
        $size = $shop['inTop']['variants'][0];
        $live = campaignRun();
        $scheduled = campaignRun();
        campaignSave($live, [campaignPercent(CampaignTargetKind::Size, $size, 1000)]);
        campaignSave($scheduled, [campaignPercent(CampaignTargetKind::Size, $shop['outside']['variants'][0], 1000)], start: '2026-11-12 00:00:00+00');

        campaignPrices()->changeEnd(campaignSa(), $live, campaignAt('2026-11-25 00:00:00+00'));

        expect((string) DB::table('pricing.campaign_sets')->where('run_id', $live)->value('ends_at'))->toBe('2026-11-25 00:00:00+00')
            ->and(fn () => campaignPrices()->changeEnd(campaignSa(), $live, campaignAt('2026-11-10 09:30:00+00')))->toThrow(WindowInvalid::class)
            ->and(fn () => campaignPrices()->remove(campaignSa(), $live))->toThrow(NotChangeable::class)
            ->and(fn () => campaignSave($live, [campaignPercent(CampaignTargetKind::Size, $size, 500)]))->toThrow(NotChangeable::class);

        campaignPrices()->endNow(campaignSa(), $live);
        campaignPrices()->endNow(campaignSa(), $scheduled);

        expect(campaignUnit($size))->toBe(10000)
            ->and(DB::table('pricing.campaign_sets')->where('run_id', $scheduled)->exists())->toBeFalse()
            ->and(fn () => campaignPrices()->changeEnd(campaignSa(), $live, campaignAt('2026-11-30 00:00:00+00')))->toThrow(NotChangeable::class);

        // A run Pricing does not hold: nothing to end or remove (a campaign may carry no prices).
        campaignPrices()->endNow(campaignSa(), campaignRun());
        campaignPrices()->remove(campaignSa(), campaignRun());
        expect(campaignPrices()->read(campaignSa(), campaignRun()))->toBeNull();
    });

    it('replaces a scheduled run\'s prices whole, the sizes it reached before written again', function () {
        $shop = campaignShop();
        $run = campaignRun();
        campaignSave($run, [campaignPercent(CampaignTargetKind::Size, $shop['outside']['variants'][0], 1000)]);
        // Saved again with other prices before it starts (as Content's Schedule after a change).
        CarbonImmutable::setTestNow('2026-11-10 08:00:00+00');
        campaignSave($run, [campaignPercent(CampaignTargetKind::Size, $shop['inTop']['variants'][0], 1000)]);
        CarbonImmutable::setTestNow('2026-11-10 10:00:00+00');
        Fx::asSystem(fn () => app(CheckPriceWindowsHandler::class)->handle(new CheckPriceWindows));

        expect(campaignUnit($shop['outside']['variants'][0]))->toBe(10000)
            ->and(campaignUnit($shop['inTop']['variants'][0]))->toBe(9000)
            ->and(DB::table('pricing.campaign_targets')->where('run_id', $run)->count())->toBe(1);
    });

    it('refuses what is not a set of prices, before anything is written', function () {
        $shop = campaignShop();
        $size = $shop['inTop']['variants'][0];
        $save = campaignSaving(...);

        expect($save([campaignPercent(CampaignTargetKind::Size, $size, 1000)], end: '2026-11-10 09:00:00+00'))->toThrow(WindowInvalid::class)
            ->and($save([campaignPercent(CampaignTargetKind::Size, $size, 1000)], start: '2026-11-01 00:00:00+00', end: '2026-11-05 00:00:00+00'))->toThrow(WindowInvalid::class)
            ->and($save([campaignPercent(CampaignTargetKind::Size, $size, 10000)]))->toThrow(PercentOutOfRange::class)
            ->and($save([campaignPercent(CampaignTargetKind::Size, $size, 0)]))->toThrow(PercentOutOfRange::class)
            ->and(Rx::invalid($save([new CampaignTargetDto(CampaignTargetKind::Product, $shop['inTop']['product'], amount: Money::of(5000, 'SAR'))]))?->attribute)->toBe('amount')
            ->and(Rx::invalid($save([new CampaignTargetDto(CampaignTargetKind::Size, $size, amount: Money::of(5000, 'EGP'))]))?->attribute)->toBe('amount')
            ->and(Rx::invalid($save([new CampaignTargetDto(CampaignTargetKind::Size, $size, 1000, Money::of(9000, 'SAR'))]))?->attribute)->toBe('target')
            ->and(Rx::invalid($save([campaignPercent(CampaignTargetKind::Size, $size, 1000), campaignPercent(CampaignTargetKind::Size, strtoupper($size), 500)]))?->attribute)->toBe('target')
            ->and(Rx::invalid($save([campaignPercent(CampaignTargetKind::Brand, '01jzzzzzzzzzzzzzzzzzzzzzzz', 1000)]))?->attribute)->toBe('target')
            ->and(Rx::invalid($save([campaignPercent(CampaignTargetKind::Category, 'not-an-id', 1000)]))?->attribute)->toBe('target')
            ->and(Rx::invalid($save([], leftOut: ['01jzzzzzzzzzzzzzzzzzzzzzzz']))?->attribute)->toBe('left_out')
            ->and(Rx::invalid($save([], run: 'run-1'))?->attribute)->toBe('campaign')
            ->and(Rx::invalid(fn () => campaignPrices()->save(StoreId::fromString('01jzzzzzzzzzzzzzzzzzzzzzzz'), campaignRun(), campaignSet([]), campaignAt('2026-11-11'), campaignAt('2026-11-12')))?->attribute)->toBe('store')
            ->and(DB::table('pricing.campaign_sets')->count())->toBe(0);

        // A run of another store is never read or changed from this one.
        $run = campaignRun();
        campaignSave($run, [campaignPercent(CampaignTargetKind::Size, $size, 1000)]);
        $egypt = StoreId::fromString(Rx::storeId('eg'));

        expect(Rx::invalid(fn () => campaignPrices()->save($egypt, $run, campaignSet([]), campaignAt('2026-11-11'), campaignAt('2026-11-12')))?->attribute)->toBe('campaign')
            ->and(Rx::invalid(fn () => campaignPrices()->endNow($egypt, $run))?->attribute)->toBe('campaign')
            ->and(campaignPrices()->read($egypt, $run))->toBeNull()
            ->and(campaignPrices()->productIds($egypt, $run, null, 10))->toBe([]);
    });

    it('answers the products it reaches, keyset-paged, for the shop\'s campaign page', function () {
        $shop = campaignShop();
        $run = campaignRun();
        campaignSave($run, [
            campaignPercent(CampaignTargetKind::Category, $shop['top'], 1000),
            campaignFixed($shop['outside']['variants'][0], 9000),
        ], leftOut: [$shop['inTop']['product']]);
        $all = [$shop['inChild']['product'], $shop['outside']['product']];
        sort($all);

        expect(campaignPrices()->productIds(campaignSa(), $run, null, 10))->toBe($all)
            ->and(campaignPrices()->productIds(campaignSa(), $run, null, 1))->toBe([$all[0]])
            ->and(campaignPrices()->productIds(campaignSa(), $run, $all[0], 10))->toBe([$all[1]]);
    });
});

describe('its preview (§9.6 #8)', function () {
    it('prices each size, says why it skips the others, counts them all and names the other offers, writing nothing', function () {
        $shop = campaignShop();
        [$a, $b] = $shop['inChild']['variants'];
        $unpriced = Px::ready(['60 cm'], $shop['child']);
        Rx::switchOn('sa', [$a]);
        $sale = Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$a], null, '95', null, null)));
        $other = campaignRun();
        campaignSave($other, [campaignPercent(CampaignTargetKind::Size, $b, 500)], CampaignLines::Retail);
        $before = Rx::allCandidates();
        app()->setLocale('en');

        $preview = campaignPrices()->preview(campaignSa(), campaignSet([
            campaignPercent(CampaignTargetKind::Category, $shop['child'], 1000),
            campaignFixed($b, 10500),
            campaignFixed($shop['inTop']['variants'][0], 6000),
        ]), campaignAt('2026-11-11 00:00:00+00'), campaignAt('2026-11-20 00:00:00+00'));
        $sizes = [];

        foreach ($preview->products as $product) {
            foreach ($product->sizes as $size) {
                $sizes[$size->variantId] = $size;
            }
        }

        $judged = static fn (string $id): array => [$sizes[$id]->newPrice?->minorUnits, $sizes[$id]->depthBasisPoints, $sizes[$id]->skipped];

        expect($preview->reachedProducts)->toBe(3)
            ->and($preview->reachedSizes)->toBe(1)
            ->and($preview->skippedSizes)->toBe(3)
            ->and($preview->deepestBasisPoints)->toBe(1000)
            ->and($judged($a))->toBe([9000, 1000, null])
            ->and($judged($b))->toBe([10500, 0, 'not_below_retail'])
            ->and($judged($shop['inTop']['variants'][0]))->toBe([6000, 4000, 'past_ceiling'])
            ->and($judged($unpriced['variants'][0]))->toBe([null, null, 'no_price'])
            ->and($sizes[$a])->toBeInstanceOf(CampaignPreviewSizeDto::class)
            ->and(array_map(static fn ($offer): array => [$offer->kind, $offer->id, $offer->variantIds], $preview->otherOffers))->toBe([[PriceKind::Sale, $sale, [$a]]])
            ->and(Rx::allCandidates())->toBe($before)
            ->and(DB::table('pricing.campaign_sets')->count())->toBe(1);

        // The other run shares a size once the dates and lines meet - and a run being changed is never its own other.
        $again = campaignPrices()->preview(campaignSa(), campaignSet([campaignPercent(CampaignTargetKind::Size, $b, 1000)]), campaignAt('2026-11-11 00:00:00+00'), campaignAt('2026-11-20 00:00:00+00'));
        $itself = campaignPrices()->preview(campaignSa(), campaignSet([campaignPercent(CampaignTargetKind::Size, $b, 1000)]), campaignAt('2026-11-11 00:00:00+00'), campaignAt('2026-11-20 00:00:00+00'), runId: $other);

        expect(array_map(static fn ($offer): array => [$offer->kind, $offer->id, $offer->variantIds], $again->otherOffers))->toBe([[PriceKind::Campaign, $other, [$b]]])
            ->and($itself->otherOffers)->toBe([]);
    });

    it('pages its products 20 at a time by name, the totals counting them all', function () {
        $category = Px::category('Kitchens');
        $sizes = [];

        for ($n = 0; $n < 21; $n++) {
            $sizes[] = Px::ready(['60 cm'], $category)['variants'][0];
        }

        Rx::set('sa', array_fill_keys($sizes, '100'));
        $first = campaignPrices()->preview(campaignSa(), campaignSet([campaignPercent(CampaignTargetKind::Category, $category, 1000)]), campaignAt('2026-11-11'), campaignAt('2026-11-20'));
        $second = campaignPrices()->preview(campaignSa(), campaignSet([campaignPercent(CampaignTargetKind::Category, $category, 1000)]), campaignAt('2026-11-11'), campaignAt('2026-11-20'), $first->next);

        expect($first->products)->toHaveCount(20)
            ->and([$first->reachedProducts, $first->reachedSizes])->toBe([21, 21])
            ->and($first->next)->not->toBeNull()
            ->and($second->products)->toHaveCount(1)
            ->and($second->next)->toBeNull();
    });
});

describe('a wired store (§9.6 #5; sync.md §9.5 amendment 2)', function () {
    it('takes a provider\'s price past the ceiling and skips the size, naming the run, until the price allows again', function () {
        $shop = campaignShop();
        [$a, $b] = $shop['inChild']['variants'];
        $run = campaignRun();
        campaignSave($run, [campaignFixed($a, 7500), campaignFixed($b, 8000)]);
        campaignWired();
        Event::fake([CampaignReachChecked::class]);
        $apply = static fn (array $prices, string $read): mixed => Fx::asSystem(fn () => app(ProviderPrices::class)->apply(campaignSa(), $prices, $read));

        // 7500 of 120 is 37.5% off: past the 30% ceiling. The price is taken; the run skips the size.
        $result = $apply([$a => '120', $b => '110'], 'read-1');

        expect($result->campaignsSkipped)->toBe([['runId' => $run, 'variantId' => $a]])
            ->and(campaignUnit($a))->toBe(12000)
            ->and(campaignUnit($b))->toBe(8000)
            ->and(campaignPrices()->skippedSizes(campaignSa(), $run))->toBe([$a]);

        Event::assertDispatched(CampaignReachChecked::class, static fn (CampaignReachChecked $event): bool => $event->skipped === [['runId' => $run, 'variantId' => $a]] && in_array($a, $event->variantIds, true));

        // It rejoins by itself once the price allows: Sync is told no run skips it now.
        $result = $apply([$a => '105'], 'read-2');

        expect($result->campaignsSkipped)->toBe([])
            ->and(campaignUnit($a))->toBe(7500)
            ->and(campaignPrices()->skippedSizes(campaignSa(), $run))->toBe([]);

        Event::assertDispatched(CampaignReachChecked::class, static fn (CampaignReachChecked $event): bool => $event->skipped === [] && $event->variantIds === [$a]);
    });

    it('saves a fixed price past the ceiling there, skipping the size, and a raised ceiling brings it back', function () {
        $shop = campaignShop();
        $size = $shop['inTop']['variants'][0];
        campaignWired();
        $run = campaignRun();

        campaignSave($run, [campaignFixed($size, 6000)]);

        expect(campaignUnit($size))->toBe(10000)
            ->and(campaignPrices()->skippedSizes(campaignSa(), $run))->toBe([$size])
            // A percentage is still held to it in a wired store.
            ->and(fn () => campaignSave(campaignRun(), [campaignPercent(CampaignTargetKind::Size, $size, 3500)]))->toThrow(OverCeiling::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', 40));

        expect(campaignUnit($size))->toBe(6000)
            ->and(campaignPrices()->skippedSizes(campaignSa(), $run))->toBe([]);
    });

    it('reads the sizes a run skips in one query', function () {
        $shop = campaignShop();
        campaignWired();
        $run = campaignRun();
        campaignSave($run, [campaignFixed($shop['inTop']['variants'][0], 6000), campaignFixed($shop['outside']['variants'][0], 6000)]);
        [$prices, $store] = [campaignPrices(), campaignSa()];
        $queries = Cx::recordQueries();

        $skipped = $prices->skippedSizes($store, $run);

        expect(count($queries))->toBe(1)
            ->and($skipped)->toEqualCanonicalizing([$shop['inTop']['variants'][0], $shop['outside']['variants'][0]]);
    });

    it('keeps no skip outside a wired store', function () {
        $shop = campaignShop();
        $run = campaignRun();
        campaignSave($run, [campaignFixed($shop['inTop']['variants'][0], 7000)]);

        expect(campaignPrices()->skippedSizes(campaignSa(), $run))->toBe([])
            ->and(DB::table('pricing.campaign_skips')->count())->toBe(0);
    });
});
