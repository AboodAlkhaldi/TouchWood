<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\InventoryPermissions;
use Modules\Promotions\Application\Command\ChangeGiftLevel\ChangeGiftLevel;
use Modules\Promotions\Application\Command\ChangeGiftLevel\ChangeGiftLevelHandler;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevel;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevelHandler;
use Modules\Promotions\Application\Command\GiftLevelFields;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevels;
use Modules\Promotions\Application\Query\ListGiftLevels\ListGiftLevelsHandler;
use Modules\Promotions\Application\Query\SearchGiftSizes\SearchGiftSizes;
use Modules\Promotions\Application\Query\SearchGiftSizes\SearchGiftSizesHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The gift dialog's tick box (promotions.md M4; owner, Q11 (c), 2026-10-19): "also limit this size's
| normal sales to its stock" — the size made stock-dependent in the level's own save, only for a reader
| who also holds Inventory's switch job in that store; and the dialog's reads say whether a size already
| counts on stock and whether the store is wired, so the screen reads neither Catalog nor Inventory.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** @return array{0: string, 1: string} two sizes of one ready product, chosen in the 'sa' store */
function giftStockSizes(): array
{
    [$pen, $mug] = Px::ready(['60 cm', '80 cm'])['variants'];
    Rx::switchOn('sa', [$pen, $mug]);

    return [$pen, $mug];
}

/** A staff member with these jobs in the 'sa' store, acting. */
function giftStockStaff(bool $stock = true): void
{
    Fx::actAsStaff(Fx::staffWith($stock ? [PromotionsPermissions::GIFT_MANAGE, InventoryPermissions::STOCK_MANAGE] : [PromotionsPermissions::GIFT_MANAGE], ['sa']));
}

function giftStockFields(string $variantId, bool $ticked, int $amountMinor = 1000_00): GiftLevelFields
{
    return new GiftLevelFields($amountMinor, $variantId, true, true, makeStockDependent: $ticked);
}

function giftStockCounts(string $variantId): bool
{
    return app(InventoryApi::class)->stock(StoreId::fromString(Fx::storeId('sa')), [$variantId])[$variantId]->countsOnStock ?? false;
}

describe('the tick box', function () {
    it('makes the size stock-dependent in a wired store, in the level own save', function () {
        Ix::wired();
        [$pen] = giftStockSizes();
        giftStockStaff();
        expect(giftStockCounts($pen))->toBeFalse();

        $level = app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, true)));

        expect($level)->not->toBe('')
            ->and(giftStockCounts($pen))->toBeTrue();
    });

    it('leaves the size as it is when not ticked', function () {
        Ix::wired();
        [$pen] = giftStockSizes();
        giftStockStaff();

        app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, false)));

        expect(giftStockCounts($pen))->toBeFalse();
    });

    it('makes the new size stock-dependent when a level is changed with it ticked', function () {
        Ix::wired();
        [$pen, $mug] = giftStockSizes();
        giftStockStaff();
        $level = app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, false)));

        app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($level, giftStockFields($mug, true)));

        expect(giftStockCounts($mug))->toBeTrue()
            ->and(giftStockCounts($pen))->toBeFalse()
            ->and(DB::table('promotions.gift_levels')->where('id', $level)->value('variant_id'))->toBe($mug);
    });

    it('refuses the tick to a reader without Inventory switch job, and saves nothing', function (Closure $save) {
        Ix::wired();
        [$pen, $mug] = giftStockSizes();
        giftStockStaff();
        $level = app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, false)));
        giftStockStaff(stock: false);

        expect(fn () => $save($level, $mug))->toThrow(Unauthorized::class)
            ->and(DB::table('promotions.gift_levels')->count())->toBe(1)
            ->and(DB::table('promotions.gift_levels')->where('id', $level)->value('variant_id'))->toBe($pen)
            ->and(giftStockCounts($mug))->toBeFalse();
    })->with([
        'a new level' => [fn (string $level, string $mug) => app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($mug, true, 2000_00)))],
        'a change' => [fn (string $level, string $mug) => app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($level, giftStockFields($mug, true)))],
    ]);

    it('saves no level when Inventory refuses the switch', function () {
        Ix::wired();
        [$pen] = giftStockSizes();
        giftStockStaff();
        $inventory = Mockery::mock(InventoryApi::class);
        $inventory->shouldReceive('makeStockDependent')->andThrow(new InvalidInventoryInput('variant', [$pen]));
        app()->instance(InventoryApi::class, $inventory);

        expect(fn () => app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, true))))->toThrow(InvalidInventoryInput::class)
            ->and(DB::table('promotions.gift_levels')->count())->toBe(0);
    });

    it('saves the level and changes nothing in a store with no provider', function () {
        [$pen] = giftStockSizes();
        giftStockStaff();

        $level = app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, true)));

        expect(DB::table('promotions.gift_levels')->where('id', $level)->exists())->toBeTrue()
            ->and(DB::table('inventory.stock')->where('variant_id', $pen)->value('stock_dependent'))->not->toBeTrue();
    });
});

describe('the dialog reads', function () {
    it('says on each level whether its size counts on stock and whether the store is wired', function (bool $wired) {
        if ($wired) {
            Ix::wired();
        }

        [$pen, $mug] = giftStockSizes();
        giftStockStaff();
        app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, $wired)));
        app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($mug, false, 2000_00)));

        $rows = app(ListGiftLevelsHandler::class)->handle(new ListGiftLevels(Fx::storeId('sa')));

        // With no provider every size counts on its stock; wired, only the one ticked.
        expect(array_map(fn ($row): array => [$row->variantId, $row->stockDependent, $row->wired], $rows))
            ->toBe([[$mug, ! $wired ? true : false, $wired], [$pen, true, $wired]]);
    })->with([
        'no provider' => [false],
        'wired' => [true],
    ]);

    it('finds the store sizes by name or code with the same facts, archived ones left out', function () {
        Ix::wired();
        [$pen, $mug] = giftStockSizes();
        giftStockStaff();
        app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId('sa'), giftStockFields($pen, true)));
        $code = (string) DB::table('catalog.variants')->where('id', $mug)->value('code');
        DB::table('catalog.variants')->where('id', $pen)->update(['is_archived' => true]);

        $byCode = app(SearchGiftSizesHandler::class)->handle(new SearchGiftSizes(Fx::storeId('sa'), $code));
        $byName = app(SearchGiftSizesHandler::class)->handle(new SearchGiftSizes(Fx::storeId('sa'), 'Drawer'));

        expect(array_map(fn ($row): array => [$row->variantId, $row->code, $row->stockDependent, $row->wired, $row->productNameEn !== null], $byCode))
            ->toBe([[$mug, $code, false, true, true]])
            ->and(array_column(array_map(fn ($row): array => (array) $row, $byName), 'variantId'))->toBe([$mug]);
    });

    it('searches for whoever may manage gift levels in the store only', function () {
        giftStockSizes();
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::VIEW], ['sa']));

        expect(fn () => app(SearchGiftSizesHandler::class)->handle(new SearchGiftSizes(Fx::storeId('sa'), 'Drawer')))->toThrow(Unauthorized::class);
    });

    it('shows at most 20 sizes', function () {
        $product = Px::ready(array_map(static fn (int $i): string => ($i * 10).' cm', range(1, 22)));
        Rx::switchOn('sa', $product['variants']);
        giftStockStaff();

        expect(app(SearchGiftSizesHandler::class)->handle(new SearchGiftSizes(Fx::storeId('sa'), '')))->toHaveCount(SearchGiftSizesHandler::MOST);
    });
});
