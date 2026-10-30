<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Query\ListSettings\ListSettings;
use Modules\Platform\Application\Query\ListSettings\ListSettingsHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Shipping\Application\Command\ChangeBox\ChangeBox;
use Modules\Shipping\Application\Command\ChangeBox\ChangeBoxHandler;
use Modules\Shipping\Application\Command\CreateBox\CreateBox;
use Modules\Shipping\Application\Command\CreateBox\CreateBoxHandler;
use Modules\Shipping\Application\Command\DeleteBox\DeleteBox;
use Modules\Shipping\Application\Command\DeleteBox\DeleteBoxHandler;
use Modules\Shipping\Application\Command\OrderBoxes\OrderBoxes;
use Modules\Shipping\Application\Command\OrderBoxes\OrderBoxesHandler;
use Modules\Shipping\Application\Command\SetSizePacking\SetSizePacking;
use Modules\Shipping\Application\Command\SetSizePacking\SetSizePackingHandler;
use Modules\Shipping\Application\Command\SwitchBox\SwitchBox;
use Modules\Shipping\Application\Command\SwitchBox\SwitchBoxHandler;
use Modules\Shipping\Application\Query\ListBoxes\BoxRow;
use Modules\Shipping\Application\Query\ListBoxes\ListBoxes;
use Modules\Shipping\Application\Query\ListBoxes\ListBoxesHandler;
use Modules\Shipping\Application\Query\ListPackingSizes\ListPackingSizes;
use Modules\Shipping\Application\Query\ListPackingSizes\ListPackingSizesHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Application\ShippingSettings;
use Modules\Shipping\Domain\Exception\BoxNameTaken;
use Modules\Shipping\Domain\Exception\BoxNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Public\Contracts\ShippingApi;
use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Modules\Shipping\Public\Dto\ParcelLineDto;
use Modules\Shipping\Public\Enums\BoxSuggestionReason;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

/*
| A store's boxes, the sizes it packs by hand, and the box suggestion (shipping.md §1.7, §3; stage 7 step 5,
| with the owner's answers of 2026-10-29/30: the total-size check counts the padding, hand-packed sizes are
| set on the Boxes page, and the small picks - padding on both sides, a piece may turn, all boxes off means
| no suggestion, a tie goes to the first in the list). Every helper is named after this file's subject.
*/

function boxesStore(string $code = 'sa'): string
{
    return (string) DB::table('platform.stores')->where('code', $code)->value('id');
}

function boxesCreate(string $name, int $length, int $width, int $height, int $weight = 50000, bool $active = true, string $store = 'sa'): string
{
    return app(CreateBoxHandler::class)->handle(new CreateBox(boxesStore($store), $name, $length, $width, $height, $weight, $active));
}

/**
 * A size with its weight (g) and sizes (mm) - any of them null for "not in Catalog".
 */
function boxesSize(string $code, ?int $weight, ?int $length, ?int $width, ?int $height): string
{
    $product = Px::product('Chair '.$code);
    $variant = Px::variant($product, $code);
    DB::table('catalog.variants')->where('id', $variant)->update(['weight_grams' => $weight, 'length_mm' => $length, 'width_mm' => $width, 'height_mm' => $height]);
    // Sold in the Saudi store: the sizes the Boxes page's search offers are the store's own.
    DB::table('catalog.store_products')->insert([
        'store_id' => boxesStore(), 'product_id' => $product, 'not_available_now' => false, 'retail_minimum' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('catalog.store_variants')->insert([
        'store_id' => boxesStore(), 'product_id' => $product, 'variant_id' => $variant, 'is_active' => true, 'not_available_now' => false,
        'sells_retail' => true, 'sells_wholesale' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $variant;
}

/**
 * @param  array<string, int>  $lines  variant id => quantity
 */
function boxesSuggest(array $lines, string $store = 'sa'): ?BoxSuggestionDto
{
    $parcel = [];

    foreach ($lines as $variant => $quantity) {
        $parcel[] = new ParcelLineDto($variant, $quantity);
    }

    return app(ShippingApi::class)->suggestBox(StoreId::fromString(boxesStore($store)), $parcel);
}

function boxesPadding(int $mm): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting(ShippingSettings::PADDING_MM, 'sa', $mm));
}

/**
 * @return list<string> the store's boxes' names, in their order
 */
function boxesListed(string $store = 'sa'): array
{
    return array_map(static fn (BoxRow $box): string => $box->name, app(ListBoxesHandler::class)->handle(new ListBoxes(boxesStore($store)))->boxes);
}

describe('the box list (§1.7)', function () {
    it('keeps a box trimmed, digits Latin, last in the list, audited', function () {
        boxesCreate('Small', 300, 200, 100);
        $id = boxesCreate(' Box ٢ ', 600, 400, 300, 20000);
        $list = app(ListBoxesHandler::class)->handle(new ListBoxes(boxesStore()));

        expect($list->paddingMm)->toBe(10)
            ->and(array_map(static fn (BoxRow $box): array => [$box->id, $box->name, $box->lengthMm, $box->widthMm, $box->heightMm, $box->maxWeightG, $box->active, $box->position], $list->boxes))
            ->toBe([[$list->boxes[0]->id, 'Small', 300, 200, 100, 50000, true, 1], [$id, 'Box 2', 600, 400, 300, 20000, true, 2]])
            ->and(DB::table('platform.audit_entries')->where('action', 'shipping.box.created')->count())->toBe(2);
    });

    it('refuses a name another box of the store has, ignoring case; another store may use it', function () {
        boxesCreate('Small', 300, 200, 100);

        expect(fn () => boxesCreate(' small ', 300, 200, 100))->toThrow(BoxNameTaken::class)
            ->and(boxesCreate('Small', 300, 200, 100, store: 'eg'))->toBeString();
    });

    it('refuses sizes and weights out of range, and an empty name', function (string $name, int $length, int $weight) {
        expect(fn () => boxesCreate($name, $length, 200, 100, $weight))->toThrow(InvalidShippingInput::class);
    })->with([
        'no name' => ['  ', 300, 1000],
        'no length' => ['Box', 0, 1000],
        'too long' => ['Box', 1000001, 1000],
        'no weight' => ['Box', 300, 0],
    ]);

    it('changes, switches, orders and deletes a box, each audited, nothing for no change', function () {
        $small = boxesCreate('Small', 300, 200, 100);
        $large = boxesCreate('Large', 600, 400, 300);
        app(ChangeBoxHandler::class)->handle(new ChangeBox(boxesStore(), $small, 'Small', 300, 200, 100, 50000));

        expect(DB::table('platform.audit_entries')->where('action', 'shipping.box.changed')->count())->toBe(0);

        app(ChangeBoxHandler::class)->handle(new ChangeBox(boxesStore(), $small, 'Little', 310, 200, 100, 40000));
        app(SwitchBoxHandler::class)->handle(new SwitchBox(boxesStore(), $small, false));
        app(OrderBoxesHandler::class)->handle(new OrderBoxes(boxesStore(), [$large]));

        expect(boxesListed())->toBe(['Large', 'Little'])
            ->and(app(ListBoxesHandler::class)->handle(new ListBoxes(boxesStore()))->boxes[1]->active)->toBeFalse()
            ->and(DB::table('platform.audit_entries')->where('action', 'shipping.box.changed')->count())->toBe(1);

        app(DeleteBoxHandler::class)->handle(new DeleteBox(boxesStore(), $small));

        expect(boxesListed())->toBe(['Large'])
            ->and(fn () => app(DeleteBoxHandler::class)->handle(new DeleteBox(boxesStore(), $small)))->toThrow(BoxNotFound::class);
    });

    it('answers another store\'s box as not found, and refuses an order naming a box twice', function () {
        $egypt = boxesCreate('Egypt', 300, 200, 100, store: 'eg');
        $small = boxesCreate('Small', 300, 200, 100);

        expect(fn () => app(SwitchBoxHandler::class)->handle(new SwitchBox(boxesStore(), $egypt, false)))->toThrow(BoxNotFound::class)
            ->and(fn () => app(OrderBoxesHandler::class)->handle(new OrderBoxes(boxesStore(), [$egypt])))->toThrow(BoxNotFound::class)
            ->and(fn () => app(OrderBoxesHandler::class)->handle(new OrderBoxes(boxesStore(), [$small, $small])))->toThrow(InvalidShippingInput::class);
    });

    it('lets those who manage boxes in that store - staff too - do it, and no one else', function () {
        Fx::actAsStaff(Fx::staffWith([ShippingPermissions::BOXES_MANAGE], ['sa']));

        expect(boxesCreate('Small', 300, 200, 100))->toBeString()
            ->and(fn () => boxesCreate('Egypt', 300, 200, 100, store: 'eg'))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([ShippingPermissions::CARRIERS_VIEW], ['sa']));

        expect(fn () => boxesListed())->toThrow(Unauthorized::class);
    });

    it('leaves a setting marked so off Platform\'s Settings page, still changed through Platform', function () {
        app(SettingsRegistry::class)->define('boxestest', new SettingDefinitionDto('boxestest.area.hidden', SettingScope::Store, SettingType::Integer, ['min:0'], 1, ShippingPermissions::BOXES_MANAGE, onSettingsPage: false));
        $keys = array_map(static fn ($row): string => $row->key, app(ListSettingsHandler::class)->handle(new ListSettings(boxesStore())));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting('boxestest.area.hidden', 'sa', 7));

        // The padding stays on the page until the Boxes screen's field ships (§1.7).
        expect($keys)->toContain(ShippingSettings::PADDING_MM)
            ->and(in_array('boxestest.area.hidden', $keys, true))->toBeFalse()
            ->and(app(PlatformApi::class)->setting('boxestest.area.hidden', StoreId::fromString(boxesStore()))->int())->toBe(7);
    });

    it('lets staff who manage boxes change the padding', function () {
        Fx::actAsStaff(Fx::staffWith([ShippingPermissions::BOXES_MANAGE], ['sa']));
        boxesPadding(15);

        expect(app(ListBoxesHandler::class)->handle(new ListBoxes(boxesStore()))->paddingMm)->toBe(15);
    });

    it('puts a new box last, after a gap a delete left', function () {
        $first = boxesCreate('A', 300, 200, 100);
        $second = boxesCreate('B', 300, 200, 100);
        boxesCreate('C', 300, 200, 100);
        boxesCreate('D', 300, 200, 100);
        app(DeleteBoxHandler::class)->handle(new DeleteBox(boxesStore(), $first));
        app(DeleteBoxHandler::class)->handle(new DeleteBox(boxesStore(), $second));
        boxesCreate('E', 300, 200, 100);

        expect(boxesListed())->toBe(['C', 'D', 'E']);
    });

    it('refuses a change to a name another box has, and keeps its own name in another case', function () {
        $small = boxesCreate('Small', 300, 200, 100);
        boxesCreate('Large', 600, 400, 300);

        expect(fn () => app(ChangeBoxHandler::class)->handle(new ChangeBox(boxesStore(), $small, 'LARGE', 300, 200, 100, 50000)))->toThrow(BoxNameTaken::class);

        app(ChangeBoxHandler::class)->handle(new ChangeBox(boxesStore(), $small, 'SMALL', 300, 200, 100, 50000));

        expect(boxesListed())->toBe(['SMALL', 'Large'])
            ->and(fn () => app(SwitchBoxHandler::class)->handle(new SwitchBox(boxesStore(), 'nope', false)))->toThrow(BoxNotFound::class);
    });
});

describe('sizes packed by hand (§1.7, gap 5.3)', function () {
    it('marks a size by hand and back, audited once each way, and lists it', function () {
        $chair = boxesSize('2101', 5000, 500, 500, 800);
        $table = boxesSize('2201', 9000, 900, 600, 700);
        $set = static fn (string $variant, bool $byHand) => app(SetSizePackingHandler::class)->handle(new SetSizePacking(boxesStore(), $variant, $byHand));
        $set($chair, true);
        $set($chair, true);

        $packed = app(ListPackingSizesHandler::class)->handle(new ListPackingSizes(boxesStore(), true));
        $others = app(ListPackingSizesHandler::class)->handle(new ListPackingSizes(boxesStore(), false));

        expect($packed->total)->toBe(1)
            ->and(array_values(array_intersect(collect($others->rows)->pluck('variantId')->all(), [$table, $chair])))->toBe([$table])
            ->and(DB::table('platform.audit_entries')->where('action', 'shipping.size_packing.changed')->count())->toBe(1);

        $set($chair, false);

        expect(app(ListPackingSizesHandler::class)->handle(new ListPackingSizes(boxesStore(), true))->total)->toBe(0)
            ->and(DB::table('platform.audit_entries')->where('action', 'shipping.size_packing.changed')->count())->toBe(2);
    });

    it('refuses a size that is not Catalog\'s', function (string $variant) {
        expect(fn () => app(SetSizePackingHandler::class)->handle(new SetSizePacking(boxesStore(), $variant, true)))->toThrow(InvalidShippingInput::class);
    })->with(['not an id' => 'nope', 'no such size' => '01jzzzzzzzzzzzzzzzzzzzzzzz']);
});

describe('the suggestion (§1.7, gap 5.2)', function () {
    it('suggests nothing while the store has no switched-on box', function () {
        $chair = boxesSize('2101', 5000, 500, 500, 800);

        expect(boxesSuggest([$chair => 1]))->toBeNull();

        boxesCreate('Off', 900, 900, 900, active: false);

        expect(boxesSuggest([$chair => 1]))->toBeNull();
    });

    it('picks the smallest box that takes the pieces, turned as they must, padding on both sides', function () {
        $chair = boxesSize('2101', 5000, 800, 500, 500);
        boxesCreate('Huge', 1000, 1000, 1000);
        boxesCreate('Snug', 520, 820, 520);
        boxesCreate('Tight', 519, 820, 520);

        // 800 × 500 × 500 fits 820 × 520 × 520 less 10 mm on every side (800 × 500 × 500); 519 is 1 mm short.
        expect(boxesSuggest([$chair => 1]))->toEqual(BoxSuggestionDto::box((string) DB::table('shipping.boxes')->where('name', 'Snug')->value('id'), 'Snug'));

        boxesPadding(11);

        expect(boxesSuggest([$chair => 1])?->boxName)->toBe('Huge');
    });

    it('adds the pieces\' volumes and weights: ten chairs never fit a box one chair fits', function () {
        $chair = boxesSize('2101', 5000, 500, 500, 800);
        boxesCreate('One chair', 620, 620, 920, 60000);

        expect(boxesSuggest([$chair => 1])?->boxName)->toBe('One chair')
            ->and(boxesSuggest([$chair => 10]))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::NothingFits));

        // Smaller than "One chair" and the chair fits it, but it takes 4,999 g and the chair weighs 5,000 g.
        boxesCreate('Light', 600, 600, 900, 4999);

        expect(boxesSuggest([$chair => 1])?->boxName)->toBe('One chair');
    });

    it('needs each piece to fit on its own, not only their total size', function () {
        // A rod 1,000 mm long: its volume fits a cube with 500 mm inside, its length does not.
        $rod = boxesSize('2301', 1000, 1000, 50, 50);
        boxesCreate('Cube', 520, 520, 520);

        expect(boxesSuggest([$rod => 1]))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::NothingFits));
    });

    it('gives a tie to the first box in the list', function () {
        $chair = boxesSize('2101', 5000, 500, 500, 500);
        $second = boxesCreate('Second', 700, 700, 700);
        boxesCreate('First', 700, 700, 700);
        app(OrderBoxesHandler::class)->handle(new OrderBoxes(boxesStore(), [(string) DB::table('shipping.boxes')->where('name', 'First')->value('id'), $second]));

        expect(boxesSuggest([$chair => 1])?->boxName)->toBe('First');
    });

    it('counts the padding in the total size: two pieces fit the box as entered but not with the padding', function () {
        // Each 270 × 270 × 100 = 7,290,000 mm³; two = 14,580,000. The box 300 × 300 × 200 holds 18,000,000 as
        // entered, but 280 × 280 × 180 = 14,112,000 less 10 mm on every side - and each piece alone fits that.
        $tile = boxesSize('2401', 100, 270, 270, 100);
        boxesCreate('Flat', 300, 300, 200);

        expect(boxesSuggest([$tile => 1])?->boxName)->toBe('Flat')
            ->and(boxesSuggest([$tile => 2]))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::NothingFits));
    });

    it('adds the weights of different sizes in one order', function () {
        $chair = boxesSize('2501', 5000, 100, 100, 100);
        $stool = boxesSize('2502', 4000, 100, 100, 100);
        boxesCreate('Rated', 900, 900, 900, 9000);

        expect(boxesSuggest([$chair => 1, $stool => 1])?->boxName)->toBe('Rated')
            ->and(boxesSuggest([$chair => 1, $stool => 2]))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::NothingFits));
    });

    it('needs the bigger box when one size of a mixed order is too long for the smaller', function () {
        $cup = boxesSize('2601', 100, 50, 50, 50);
        $rod = boxesSize('2602', 500, 700, 40, 40);
        boxesCreate('Small', 300, 300, 300);
        boxesCreate('Long', 800, 300, 300);

        expect(boxesSuggest([$cup => 1])?->boxName)->toBe('Small')
            ->and(boxesSuggest([$cup => 1, $rod => 1])?->boxName)->toBe('Long');
    });

    it('suggests nothing for an order with no piece', function () {
        $cup = boxesSize('2701', 100, 50, 50, 50);
        boxesCreate('Small', 300, 300, 300);

        expect(boxesSuggest([]))->toBeNull()
            ->and(boxesSuggest([$cup => 0]))->toBeNull();
    });

    it('ranks boxes by their inner sizes as entered, not after the padding (§1.7)', function () {
        $pin = boxesSize('2801', 10, 50, 5, 5);
        boxesCreate('Cube', 100, 100, 100);
        boxesCreate('Tube', 2000, 25, 25);

        // Entered: Cube 1,000,000 < Tube 1,250,000; after 10 mm padding Tube would be the smaller.
        expect(boxesSuggest([$pin => 1])?->boxName)->toBe('Cube');
    });

    it('refuses an archived size by hand, lets one go back, and lists archived ones only where they are by hand', function () {
        $chair = boxesSize('2901', 5000, 500, 500, 500);
        $set = static fn (bool $byHand) => app(SetSizePackingHandler::class)->handle(new SetSizePacking(boxesStore(), $chair, $byHand));
        $set(true);
        DB::table('catalog.variants')->where('id', $chair)->update(['is_archived' => true]);

        expect(app(ListPackingSizesHandler::class)->handle(new ListPackingSizes(boxesStore(), true))->total)->toBe(1)
            ->and(collect(app(ListPackingSizesHandler::class)->handle(new ListPackingSizes(boxesStore(), false))->rows)->pluck('variantId')->all())->not->toContain($chair);

        $set(false);

        expect(fn () => $set(true))->toThrow(InvalidShippingInput::class);
    });

    it('says pack by hand for a size marked so, or one Catalog has no weight or sizes for', function () {
        $chair = boxesSize('2101', 5000, 500, 500, 500);
        $noWeight = boxesSize('2102', null, 500, 500, 500);
        $noHeight = boxesSize('2103', 5000, 500, 500, null);
        boxesCreate('Box', 900, 900, 900);
        app(SetSizePackingHandler::class)->handle(new SetSizePacking(boxesStore(), $chair, true));

        expect(boxesSuggest([$chair => 1]))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::ManualSize))
            ->and(boxesSuggest([$noWeight => 1]))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::NoSizeFacts))
            ->and(boxesSuggest([$noHeight => 1]))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::NoSizeFacts));
    });

    it('reads only the store\'s own boxes and hand-packed sizes', function () {
        $chair = boxesSize('2101', 5000, 500, 500, 500);
        boxesCreate('Egypt box', 900, 900, 900, store: 'eg');
        app(SetSizePackingHandler::class)->handle(new SetSizePacking(boxesStore('eg'), $chair, true));

        expect(boxesSuggest([$chair => 1]))->toBeNull()
            ->and(boxesSuggest([$chair => 1], 'eg'))->toEqual(BoxSuggestionDto::byHand(BoxSuggestionReason::ManualSize));
    });
});
