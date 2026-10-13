<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindow;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindowHandler;
use Modules\Pricing\Application\Command\ChangeCategoryDiscount\ChangeCategoryDiscount;
use Modules\Pricing\Application\Command\ChangeCategoryDiscount\ChangeCategoryDiscountHandler;
use Modules\Pricing\Application\Command\EndCategoryDiscount\EndCategoryDiscount;
use Modules\Pricing\Application\Command\EndCategoryDiscount\EndCategoryDiscountHandler;
use Modules\Pricing\Application\Command\RemoveCategoryDiscount\RemoveCategoryDiscount;
use Modules\Pricing\Application\Command\RemoveCategoryDiscount\RemoveCategoryDiscountHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\AlwaysWinsOverlap;
use Modules\Pricing\Domain\Exception\CategoryNotUsable;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Infrastructure\Queue\ApplyPriceWindowJob;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Category discounts (pricing.md §1.5, §1.12, §3, §4.1; step 6a): a percentage or a fixed amount off the
| retail price of every product in a category and below it, now - less the products left out - on the
| lines chosen; a fixed amount skipping a size it would take to 0; never on a category missing or off,
| never deeper than the ceiling; "always wins" once per size across sales and discounts; the same life
| as a sale.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-13 10:00:00+00');
    Queue::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $leftOut
 */
function discountAdd(string $category, ?string $percent = null, ?string $amount = null, string $lines = 'BOTH', ?string $startsAt = null, ?string $endsAt = null, bool $alwaysWins = false, array $leftOut = []): string
{
    return Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $category, $percent, $amount, $lines, $startsAt, $endsAt, $alwaysWins, $leftOut)));
}

function discountUnit(string $size, SaleMode $mode = SaleMode::Retail): ?int
{
    return app(PricingApi::class)->prices(StoreId::fromString(Rx::storeId('sa')), [new CartLineDto($size, $mode, 1)])->lines[0]->unit->minorUnits ?? null;
}

/**
 * A category with two sub-categories, a product in each - products sit in a lowest category only - and
 * a product outside; every size at 100.
 *
 * @return array{top: string, child: string, inTop: array{product: string, variants: list<string>, width: string}, inChild: array{product: string, variants: list<string>, width: string}, outside: array{product: string, variants: list<string>, width: string}}
 */
function discountShop(): array
{
    $top = Px::category('Kitchens');
    $child = Px::category('Drawers', $top);
    // "In the top category": in its other sub-category, reached through it.
    $inTop = Px::ready(['60 cm'], Px::category('Shelves', $top));
    $inChild = Px::ready(['60 cm', '80 cm'], $child);
    $outside = Px::ready(['60 cm'], Px::category('Doors'));
    Rx::set('sa', array_fill_keys([...$inTop['variants'], ...$inChild['variants'], ...$outside['variants']], '100'));

    return ['top' => $top, 'child' => $child, 'inTop' => $inTop, 'inChild' => $inChild, 'outside' => $outside];
}

describe('what a discount reaches (§1.5)', function () {
    it('takes a percentage off every product in the category and below it, on the lines chosen, less those left out', function () {
        $shop = discountShop();

        discountAdd($shop['top'], percent: '10', lines: 'RETAIL', leftOut: [$shop['inChild']['product']]);

        expect(discountUnit($shop['inTop']['variants'][0]))->toBe(9000)
            ->and(discountUnit($shop['inTop']['variants'][0], SaleMode::Wholesale))->toBe(10000)
            ->and(discountUnit($shop['inChild']['variants'][0]))->toBe(10000)
            ->and(discountUnit($shop['outside']['variants'][0]))->toBe(10000)
            ->and(Fx::audits('pricing.category_discount.added'))->toBe(1);
    });

    it('takes a fixed amount off, skipping a size it would take to 0 or below', function () {
        $shop = discountShop();
        [$cheap, $dear] = $shop['inChild']['variants'];
        Rx::set('sa', [$cheap => '20', $dear => '100']);

        // 20 off 100 is 20%, within the ceiling; 20 off 20 would leave 0, so that size is skipped - and
        // a skipped size is not measured against the ceiling.
        discountAdd($shop['child'], amount: '20');

        expect(discountUnit($dear))->toBe(8000)
            ->and(discountUnit($cheap))->toBe(2000);
    });

    it('refuses a category that does not exist or is switched off', function () {
        $shop = discountShop();
        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($shop['child'], 'HIDE')));

        expect(fn () => discountAdd('01jzzzzzzzzzzzzzzzzzzzzzzz', percent: '10'))->toThrow(CategoryNotUsable::class)
            ->and(fn () => discountAdd($shop['child'], percent: '10'))->toThrow(CategoryNotUsable::class)
            ->and(DB::table('pricing.category_discounts')->count())->toBe(0);
    });

    it('refuses one deeper than the ceiling - a percentage above it, an amount deeper on a size, naming it', function () {
        $shop = discountShop();
        [$a, $b] = $shop['inChild']['variants'];
        Rx::set('sa', [$a => '100', $b => '50']);

        try {
            discountAdd($shop['child'], amount: '20');
            $named = null;
        } catch (OverCeiling $error) {
            $named = $error->variants;
        }

        expect(fn () => discountAdd($shop['child'], percent: '30.01'))->toThrow(OverCeiling::class)
            // 20 off 50 is 40%: past the 30% ceiling; 20 off 100 is not.
            ->and($named)->toBe([$b]);
    });
});

describe('its life and "always wins" (§4.1, §1.3)', function () {
    it('changes a scheduled one in full, a running one its end only; ends a running one, removes a scheduled one', function () {
        $shop = discountShop();
        $size = $shop['inTop']['variants'][0];
        $scheduled = discountAdd($shop['top'], percent: '10', startsAt: '2026-10-20 00:00:00+00');
        $running = discountAdd($shop['child'], percent: '5');
        $change = fn (string $id, string $category, string $percent, ?string $startsAt, ?string $endsAt) => Fx::asSystem(
            fn () => app(ChangeCategoryDiscountHandler::class)->handle(new ChangeCategoryDiscount($id, $category, $percent, null, 'BOTH', $startsAt, $endsAt)),
        );

        $change($scheduled, $shop['top'], '20', '2026-10-21 00:00:00+00', null);
        $change($running, $shop['child'], '5', null, '2026-10-25 00:00:00+00');

        expect(DB::table('pricing.category_discounts')->where('id', $scheduled)->value('percent_bp'))->toBe(2000)
            ->and(fn () => $change($running, $shop['child'], '6', null, null))->toThrow(NotChangeable::class)
            ->and(fn () => Fx::asSystem(fn () => app(RemoveCategoryDiscountHandler::class)->handle(new RemoveCategoryDiscount($running))))->toThrow(NotChangeable::class)
            ->and(fn () => Fx::asSystem(fn () => app(EndCategoryDiscountHandler::class)->handle(new EndCategoryDiscount($scheduled))))->toThrow(NotChangeable::class);

        CarbonImmutable::setTestNow('2026-10-13 11:00:00+00');
        Fx::asSystem(fn () => app(EndCategoryDiscountHandler::class)->handle(new EndCategoryDiscount($running)));
        Fx::asSystem(fn () => app(RemoveCategoryDiscountHandler::class)->handle(new RemoveCategoryDiscount($scheduled)));

        expect(discountUnit($shop['inChild']['variants'][0]))->toBe(10000)
            ->and(discountUnit($size))->toBe(10000)
            ->and(DB::table('pricing.category_discounts')->count())->toBe(1);
    });

    it('refuses two "always wins" on a size in the same dates, across sales and discounts', function () {
        $shop = discountShop();
        $size = $shop['inChild']['variants'][0];
        discountAdd($shop['top'], percent: '10', endsAt: '2026-10-30 00:00:00+00', alwaysWins: true);

        expect(fn () => discountAdd($shop['child'], percent: '5', alwaysWins: true))->toThrow(AlwaysWinsOverlap::class)
            ->and(fn () => Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$size], '5', null, null, null, true))))->toThrow(AlwaysWinsOverlap::class);

        // After it ends: allowed.
        discountAdd($shop['child'], percent: '5', startsAt: '2026-10-30 00:00:00+00', alwaysWins: true);

        expect(DB::table('pricing.category_discounts')->count())->toBe(2);
    });

    it('pushes its price at its start, as a sale\'s timed task does', function () {
        $shop = discountShop();
        $size = $shop['inTop']['variants'][0];
        $discount = discountAdd($shop['top'], percent: '10', startsAt: '2026-10-20 00:00:00+00');
        $queue = Queue::getFacadeRoot();
        assert($queue instanceof QueueFake);
        $task = $queue->pushed(ApplyPriceWindowJob::class, static fn (ApplyPriceWindowJob $job): bool => $job->saleId === $discount)->first();
        assert($task instanceof ApplyPriceWindowJob);

        CarbonImmutable::setTestNow('2026-10-20 00:00:00+00');

        expect(Fx::asSystem(fn (): bool => app(ApplyPriceWindowHandler::class)->handle(new ApplyPriceWindow($task->storeId, $task->saleId, $task->moment))))->toBeTrue()
            ->and((int) DB::table('catalog.store_variant_facts')->where('store_id', Rx::storeId('sa'))->where('variant_id', $size)->value('price_minor'))->toBe(9000);
    });

    it('needs Manage Category Discounts in that store', function () {
        $shop = discountShop();
        Cx::actAsStaffWith([PricingPermissions::CATEGORY_DISCOUNT_MANAGE], ['eg']);

        expect(fn () => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $shop['top'], '10', null, 'BOTH', null, null)))->toThrow(Unauthorized::class);
    });
});
