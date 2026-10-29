<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Shipping\Application\Uncovered\UncoveredCounts;
use Modules\Shipping\Application\Uncovered\UncoveredPlaces;
use Modules\Shipping\Infrastructure\Queue\CountUncoveredPlacesJob;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrier;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrierHandler;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrier;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrierHandler;
use Modules\Shipping\Application\Command\DeletePriceGroup\DeletePriceGroup;
use Modules\Shipping\Application\Command\DeletePriceGroup\DeletePriceGroupHandler;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroup;
use Modules\Shipping\Application\Command\SavePriceGroup\SavePriceGroupHandler;
use Modules\Shipping\Application\Command\SetCarrierFreeAbove\SetCarrierFreeAbove;
use Modules\Shipping\Application\Command\SetCarrierFreeAbove\SetCarrierFreeAboveHandler;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrier;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrierHandler;
use Modules\Shipping\Application\Query\ListPriceGroups\ListPriceGroups;
use Modules\Shipping\Application\Query\ListPriceGroups\ListPriceGroupsHandler;
use Modules\Shipping\Application\Query\ListUncoveredPlaces\ListUncoveredPlaces;
use Modules\Shipping\Application\Query\ListUncoveredPlaces\ListUncoveredPlacesHandler;
use Modules\Shipping\Application\Query\PlaceRow;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Application\ShippingSettings;
use Modules\Shipping\Application\Uncovered\UncoveredPlacesCount;
use Modules\Shipping\Domain\Exception\AmountAlreadyUsed;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Exception\PlaceAlreadyPriced;
use Modules\Shipping\Domain\Exception\PlaceNotInCountry;
use Modules\Shipping\Domain\Exception\PriceGroupNotFound;
use Modules\Shipping\Public\Contracts\ShippingApi;
use Modules\Shipping\Public\Dto\ShippingDestinationDto;
use Modules\Shipping\Public\Dto\ShippingOptionDto;
use Modules\Shipping\Public\Enums\FreeShippingReason;
use Modules\Shipping\Public\Enums\ShippingCoverage;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

/*
| A carrier's prices, free shipping, the quote and the places no carrier serves (shipping.md §1.2,
| §1.4–§1.6, §2.1; stage 7 step 4, with the owner's answers of 2026-10-29: a whole region may be priced,
| the most specific place wins, a typed city takes its region's price, "Select all" and the uncovered
| rows of gap 4.2 and 4.3). Every helper is named after this file's subject.
*/

function pricesStore(string $code = 'sa'): string
{
    return (string) DB::table('platform.stores')->where('code', $code)->value('id');
}

function pricesPlace(string $kind, ?string $parent, string $nameEn, string $country = 'SA'): string
{
    if (! DB::table('platform.address_countries')->where('country_code', $country)->exists()) {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry($country));
    }

    return app(AddPlaceHandler::class)->handle(new AddPlace($country, $kind, $parent, $nameEn.' ع', $nameEn));
}

/**
 * Two Saudi regions: Riyadh Region (Riyadh with Olaya and Malaz; Diriyah with no district list) and
 * Eastern Region (Dammam).
 *
 * @return array<string, string> English name => id
 */
function pricesSaudiPlaces(): array
{
    $riyadhRegion = pricesPlace(PlaceKind::Region->value, null, 'Riyadh Region');
    $riyadh = pricesPlace(PlaceKind::City->value, $riyadhRegion, 'Riyadh');
    $eastern = pricesPlace(PlaceKind::Region->value, null, 'Eastern Region');

    return [
        'Riyadh Region' => $riyadhRegion,
        'Riyadh' => $riyadh,
        'Olaya' => pricesPlace(PlaceKind::District->value, $riyadh, 'Olaya'),
        'Malaz' => pricesPlace(PlaceKind::District->value, $riyadh, 'Malaz'),
        'Diriyah' => pricesPlace(PlaceKind::City->value, $riyadhRegion, 'Diriyah'),
        'Eastern Region' => $eastern,
        'Dammam' => pricesPlace(PlaceKind::City->value, $eastern, 'Dammam'),
    ];
}

function pricesCarrier(string $nameEn, bool $active = true, string $store = 'sa', string $country = 'SA'): string
{
    return app(CreateCarrierHandler::class)->handle(new CreateCarrier(pricesStore($store), $country, 'ناقل '.$nameEn, $nameEn, active: $active));
}

/**
 * @param  list<string>  $places
 * @param  list<string>  $moves
 */
function pricesSave(string $carrier, int $amount, array $places, ?string $group = null, array $moves = [], bool $all = false): string
{
    return app(SavePriceGroupHandler::class)->handle(new SavePriceGroup(pricesStore(), $carrier, $group, $amount, $places, $moves, $all));
}

/**
 * The refusal a save meets, or null when it saves.
 *
 * @param  list<string>  $places
 * @param  list<string>  $moves
 */
function pricesRefusal(string $carrier, int $amount, array $places, ?string $group = null, array $moves = []): ?Throwable
{
    try {
        pricesSave($carrier, $amount, $places, $group, $moves);

        return null;
    } catch (Throwable $refusal) {
        return $refusal;
    }
}

/**
 * @return array<int, list<string>> amount => its places' English names, sorted
 */
function pricesGroups(string $carrier): array
{
    $out = [];

    foreach (app(ListPriceGroupsHandler::class)->handle(new ListPriceGroups(pricesStore(), $carrier, 'en')) as $group) {
        $out[$group->amountMinor] = array_map(static fn (PlaceRow $place): string => $place->name->en, $group->places);
    }

    return $out;
}

function pricesQuote(?string $district = null, ?string $city = null, ?string $region = null, string $country = 'SA', int $goods = 10000, string $store = 'sa'): Modules\Shipping\Public\Dto\ShippingQuoteDto
{
    return app(ShippingApi::class)->quote(StoreId::fromString(pricesStore($store)), new ShippingDestinationDto($country, $region, $city, $district), Money::of($goods, 'SAR'));
}

/**
 * @return list<string> "Carrier: fee" in the quote's order
 */
function pricesOffered(?string $district = null, ?string $city = null, ?string $region = null, int $goods = 10000): array
{
    return array_map(static fn (ShippingOptionDto $option): string => "{$option->nameEn}: {$option->fee->minorUnits}", pricesQuote($district, $city, $region, goods: $goods)->options);
}

/**
 * @return array<string, list<string>> country => uncovered places' English names
 */
function pricesUncovered(): array
{
    $out = [];

    foreach (app(ListUncoveredPlacesHandler::class)->handle(new ListUncoveredPlaces(pricesStore(), 'en')) as $country) {
        $out[$country->code] = array_map(static fn (PlaceRow $place): string => $place->name->en, $country->places);
    }

    return $out;
}

function pricesFreeForAll(int $amount): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting(ShippingSettings::FREE_ABOVE_ALL, 'sa', $amount));
}

describe('the quote (§1.2)', function () {
    it('answers OUTSIDE for a country no store ships to, OTHER_STORE naming the store for another\'s', function () {
        $outside = pricesQuote(country: 'QA');
        $other = pricesQuote(country: 'EG');

        expect([$outside->coverage, $outside->options, $outside->otherStoreId])->toBe([ShippingCoverage::Outside, [], null])
            ->and([$other->coverage, $other->otherStoreId])->toBe([ShippingCoverage::OtherStore, pricesStore('eg')]);
    });

    it('answers NOT_COVERED in its own country with no carrier, and with no carrier pricing the place', function () {
        $places = pricesSaudiPlaces();

        expect(pricesQuote(city: $places['Riyadh'])->coverage)->toBe(ShippingCoverage::NotCovered);

        pricesSave(pricesCarrier('Aramex'), 2500, [$places['Dammam']]);

        expect(pricesQuote(city: $places['Riyadh'])->coverage)->toBe(ShippingCoverage::NotCovered)
            ->and(pricesQuote()->coverage)->toBe(ShippingCoverage::NotCovered);
    });

    it('offers each switched-on carrier at its fee, cheapest first, then by its place in the list', function () {
        $places = pricesSaudiPlaces();
        pricesSave(pricesCarrier('Aramex'), 3000, [$places['Riyadh']]);
        pricesSave(pricesCarrier('SMSA'), 2000, [$places['Riyadh']]);
        pricesSave(pricesCarrier('Naqel'), 3000, [$places['Riyadh']]);
        pricesSave(pricesCarrier('Off', active: false), 1000, [$places['Riyadh']]);

        expect(pricesQuote(city: $places['Riyadh'])->coverage)->toBe(ShippingCoverage::Served)
            ->and(pricesOffered(city: $places['Riyadh']))->toBe(['SMSA: 2000', 'Aramex: 3000', 'Naqel: 3000']);
    });

    it('takes the most specific price: the district, else the city, else the region (owner, 2026-10-29)', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 4000, [$places['Riyadh Region']]);
        pricesSave($aramex, 3000, [$places['Riyadh']]);
        pricesSave($aramex, 1500, [$places['Olaya']]);

        expect(pricesOffered(district: $places['Olaya'], city: $places['Riyadh'], region: $places['Riyadh Region']))->toBe(['Aramex: 1500'])
            ->and(pricesOffered(district: $places['Malaz'], city: $places['Riyadh'], region: $places['Riyadh Region']))->toBe(['Aramex: 3000'])
            ->and(pricesOffered(city: $places['Diriyah'], region: $places['Riyadh Region']))->toBe(['Aramex: 4000'])
            // A typed district takes its city's price; a typed city its region's (gap 4.1).
            ->and(pricesOffered(city: $places['Riyadh'], region: $places['Riyadh Region']))->toBe(['Aramex: 3000'])
            ->and(pricesOffered(region: $places['Riyadh Region']))->toBe(['Aramex: 4000'])
            ->and(pricesQuote(region: $places['Eastern Region'])->coverage)->toBe(ShippingCoverage::NotCovered);
    });

    it('serves a district priced alone, but not its city\'s other districts, while nothing above is priced', function () {
        $places = pricesSaudiPlaces();
        pricesSave(pricesCarrier('Aramex'), 1500, [$places['Olaya']]);

        expect(pricesOffered(district: $places['Olaya']))->toBe(['Aramex: 1500'])
            ->and(pricesQuote(district: $places['Malaz'])->coverage)->toBe(ShippingCoverage::NotCovered);
    });

    it('reads the place\'s city and region from Platform, never from the caller, and ignores another country\'s place', function () {
        $places = pricesSaudiPlaces();
        pricesSave(pricesCarrier('Aramex'), 4000, [$places['Riyadh Region']]);
        $manama = pricesPlace(PlaceKind::Region->value, null, 'Capital', 'BH');

        // The district names Riyadh Region above it, whatever region the caller sends.
        expect(pricesOffered(district: $places['Olaya'], region: $places['Eastern Region']))->toBe(['Aramex: 4000'])
            ->and(pricesQuote(region: $manama)->coverage)->toBe(ShippingCoverage::NotCovered)
            ->and(pricesQuote(city: 'not-an-id')->coverage)->toBe(ShippingCoverage::NotCovered);
    });

    it('takes 0 as a price: delivered free there, not "free because"', function () {
        $places = pricesSaudiPlaces();
        pricesSave(pricesCarrier('Aramex'), 0, [$places['Riyadh']]);

        $option = pricesQuote(city: $places['Riyadh'])->options[0];

        expect([$option->fee->minorUnits, $option->freeBecause])->toBe([0, null]);
    });

    it('keeps a retired place\'s price for the addresses that name it (§9.3 #4)', function () {
        $places = pricesSaudiPlaces();
        pricesSave(pricesCarrier('Aramex'), 3000, [$places['Riyadh']]);
        app(RetirePlaceHandler::class)->handle(new RetirePlace($places['Riyadh']));

        expect(pricesOffered(city: $places['Riyadh']))->toBe(['Aramex: 3000']);
    });
});

describe('free shipping (§1.5)', function () {
    it('makes a carrier free from its own amount, the amount itself included', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 3000, [$places['Riyadh']]);
        app(SetCarrierFreeAboveHandler::class)->handle(new SetCarrierFreeAbove(pricesStore(), $aramex, 50000));

        $at = pricesQuote(city: $places['Riyadh'], goods: 50000)->options[0];

        expect(pricesOffered(city: $places['Riyadh'], goods: 49999))->toBe(['Aramex: 3000'])
            ->and([$at->fee->minorUnits, $at->freeBecause])->toBe([0, FreeShippingReason::Carrier]);
    });

    it('lets the store-wide amount override every carrier\'s while set, and each carrier\'s own apply once cleared', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 3000, [$places['Riyadh']]);
        pricesSave(pricesCarrier('SMSA'), 2000, [$places['Riyadh']]);
        app(SetCarrierFreeAboveHandler::class)->handle(new SetCarrierFreeAbove(pricesStore(), $aramex, 10000));
        pricesFreeForAll(30000);

        // The carrier's own 100.00 is ignored while the store's 300.00 is set.
        expect(pricesOffered(city: $places['Riyadh'], goods: 20000))->toBe(['SMSA: 2000', 'Aramex: 3000'])
            ->and(array_map(static fn (ShippingOptionDto $option): ?FreeShippingReason => $option->freeBecause, pricesQuote(city: $places['Riyadh'], goods: 30000)->options))
            ->toBe([FreeShippingReason::Store, FreeShippingReason::Store]);

        pricesFreeForAll(0);

        expect(pricesOffered(city: $places['Riyadh'], goods: 20000))->toBe(['Aramex: 0', 'SMSA: 2000']);
    });

    it('refuses an amount out of range, audits a change and clears with null', function () {
        $aramex = pricesCarrier('Aramex');
        $set = static fn (?int $amount) => app(SetCarrierFreeAboveHandler::class)->handle(new SetCarrierFreeAbove(pricesStore(), $aramex, $amount));

        expect(fn () => $set(-1))->toThrow(InvalidShippingInput::class)
            ->and(fn () => $set(1000000001))->toThrow(InvalidShippingInput::class);

        $set(50000);
        $set(null);

        expect(DB::table('platform.audit_entries')->where('action', 'shipping.carrier.changed')->count())->toBe(2);
    });
});

describe('the price groups (§1.4)', function () {
    it('keeps a group with its places, any kind, sorted by name, audited on the carrier', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 2500, [$places['Riyadh'], $places['Eastern Region'], $places['Olaya']]);

        $audit = json_decode((string) DB::table('platform.audit_entries')->where('action', 'shipping.carrier.prices_changed')->value('changes'), true);

        expect(pricesGroups($aramex))->toBe([2500 => ['Eastern Region', 'Olaya', 'Riyadh']])
            ->and(json_encode($audit))->toContain('price 25.00')->toContain('Eastern Region, Olaya, Riyadh');
    });

    it('refuses a place another group of the carrier has, naming it and that amount; another carrier may have it', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 2000, [$places['Riyadh']]);
        $refusal = pricesRefusal($aramex, 3000, [$places['Riyadh'], $places['Dammam']]);

        expect($refusal)->toBeInstanceOf(PlaceAlreadyPriced::class)
            ->and($refusal instanceof PlaceAlreadyPriced ? [$refusal->nameEn, $refusal->amount] : null)->toBe(['Riyadh', '20.00'])
            ->and(pricesGroups($aramex))->toBe([2000 => ['Riyadh']])
            ->and(pricesSave(pricesCarrier('SMSA'), 2000, [$places['Riyadh']]))->toBeString();
    });

    it('moves a place here in the same save, and deletes the group it leaves empty', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 2000, [$places['Riyadh']]);
        pricesSave($aramex, 2500, [$places['Dammam'], $places['Diriyah']]);
        pricesSave($aramex, 3000, [$places['Riyadh'], $places['Dammam']], moves: [$places['Riyadh'], $places['Dammam']]);

        expect(pricesGroups($aramex))->toBe([2500 => ['Diriyah'], 3000 => ['Dammam', 'Riyadh']]);
    });

    it('refuses a new group with an amount the carrier has, naming that group', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        $twenty = pricesSave($aramex, 2000, [$places['Riyadh']]);
        $refusal = pricesRefusal($aramex, 2000, [$places['Dammam']]);

        expect($refusal)->toBeInstanceOf(AmountAlreadyUsed::class)
            ->and($refusal instanceof AmountAlreadyUsed ? [$refusal->groupId, $refusal->amount] : null)->toBe([$twenty, '20.00']);
    });

    it('merges a group whose amount is changed to another group\'s', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        $twenty = pricesSave($aramex, 2000, [$places['Riyadh']]);
        $thirty = pricesSave($aramex, 3000, [$places['Dammam']]);

        expect(pricesSave($aramex, 2000, [$places['Dammam'], $places['Diriyah']], $thirty))->toBe($twenty)
            ->and(pricesGroups($aramex))->toBe([2000 => ['Dammam', 'Diriyah', 'Riyadh']]);
    });

    it('changes a group\'s amount and places, and deletes a group saved with none', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        $group = pricesSave($aramex, 2000, [$places['Riyadh'], $places['Dammam']]);
        pricesSave($aramex, 2200, [$places['Riyadh']], $group);

        expect(pricesGroups($aramex))->toBe([2200 => ['Riyadh']]);

        pricesSave($aramex, 2200, [], $group);

        expect(pricesGroups($aramex))->toBe([])
            ->and(pricesRefusal($aramex, 2000, []))->toBeInstanceOf(InvalidShippingInput::class);
    });

    it('refuses an amount out of range, a group of another carrier, and places not in use in its country', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        $smsaGroup = pricesSave(pricesCarrier('SMSA'), 2000, [$places['Riyadh']]);
        $bahrain = pricesPlace(PlaceKind::Region->value, null, 'Capital', 'BH');
        app(RetirePlaceHandler::class)->handle(new RetirePlace($places['Dammam']));

        expect(pricesRefusal($aramex, -1, [$places['Riyadh']]))->toBeInstanceOf(InvalidShippingInput::class)
            ->and(pricesRefusal($aramex, 100000001, [$places['Riyadh']]))->toBeInstanceOf(InvalidShippingInput::class)
            ->and(pricesRefusal($aramex, 2000, [$places['Riyadh']], $smsaGroup))->toBeInstanceOf(PriceGroupNotFound::class)
            ->and(pricesRefusal($aramex, 2000, [$bahrain]))->toBeInstanceOf(PlaceNotInCountry::class)
            ->and(pricesRefusal($aramex, 2000, ['nope']))->toBeInstanceOf(PlaceNotInCountry::class)
            ->and(pricesRefusal($aramex, 2000, [$places['Dammam']]))->toBeInstanceOf(PlaceNotInCountry::class)
            ->and(pricesGroups($aramex))->toBe([]);
    });

    it('keeps a retired place in the group that has it when the group is saved again', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        $group = pricesSave($aramex, 2000, [$places['Riyadh'], $places['Dammam']]);
        app(RetirePlaceHandler::class)->handle(new RetirePlace($places['Dammam']));
        pricesSave($aramex, 2100, [$places['Riyadh'], $places['Dammam']], $group);

        expect(pricesGroups($aramex))->toBe([2100 => ['Dammam', 'Riyadh']]);
    });

    it('adds every city not yet priced, nor under a priced region, for the country\'s Select all (gap 4.2)', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 3000, [$places['Eastern Region']]);
        pricesSave($aramex, 2000, [$places['Riyadh']]);
        pricesSave($aramex, 4000, [], all: true);

        expect(pricesGroups($aramex))->toBe([2000 => ['Riyadh'], 3000 => ['Eastern Region'], 4000 => ['Diriyah']]);
    });

    it('adds every region not yet priced where the country has no city (gap 4.2)', function () {
        pricesPlace(PlaceKind::Region->value, null, 'Capital', 'BH');
        pricesPlace(PlaceKind::Region->value, null, 'Muharraq', 'BH');
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'BH'));
        $carrier = pricesCarrier('Bahrain Post', country: 'BH');
        pricesSave($carrier, 1000, [], all: true);

        expect(pricesGroups($carrier))->toBe([1000 => ['Capital', 'Muharraq']]);
    });

    it('deletes a group and its places, and every group goes with its carrier', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        $group = pricesSave($aramex, 2000, [$places['Riyadh']]);
        pricesSave($aramex, 3000, [$places['Dammam']]);
        app(DeletePriceGroupHandler::class)->handle(new DeletePriceGroup(pricesStore(), $aramex, $group));

        expect(pricesGroups($aramex))->toBe([3000 => ['Dammam']])
            ->and(fn () => app(DeletePriceGroupHandler::class)->handle(new DeletePriceGroup(pricesStore(), $aramex, $group)))->toThrow(PriceGroupNotFound::class);

        app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(pricesStore(), $aramex, false));
        app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(pricesStore(), $aramex));

        expect(DB::table('shipping.price_groups')->count())->toBe(0)
            ->and(DB::table('shipping.price_places')->count())->toBe(0);
    });

    it('lets only those who manage prices in that store change them; those who view carriers read them', function () {
        $places = pricesSaudiPlaces();
        $aramex = pricesCarrier('Aramex');
        Fx::actAsStaff(Fx::staffWith([ShippingPermissions::CARRIERS_VIEW], ['sa']));

        expect(pricesGroups($aramex))->toBe([])
            ->and(pricesRefusal($aramex, 2000, [$places['Riyadh']]))->toBeInstanceOf(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([ShippingPermissions::PRICES_MANAGE, ShippingPermissions::CARRIERS_VIEW], ['eg'], RoleLevel::Admin));

        expect(pricesRefusal($aramex, 2000, [$places['Riyadh']]))->toBeInstanceOf(Unauthorized::class);
    });
});

describe('the carriers Sales reads (§2.1)', function () {
    it('names a carrier by its id, null once deleted, and lists the store\'s, switched-on ones first', function () {
        $aramex = pricesCarrier('Aramex', active: false);
        pricesCarrier('SMSA');
        $api = app(ShippingApi::class);

        expect($api->carrier($aramex)?->nameEn)->toBe('Aramex')
            ->and($api->carrier('nope'))->toBeNull()
            ->and(array_map(static fn ($carrier): string => $carrier->nameEn, $api->carriers(StoreId::fromString(pricesStore()))))->toBe(['SMSA', 'Aramex']);

        app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(pricesStore(), $aramex));

        expect($api->carrier($aramex))->toBeNull();
    });

    it('makes the tracking link from the pattern, the number trimmed and encoded; none without a pattern', function () {
        $patterned = app(CreateCarrierHandler::class)->handle(new CreateCarrier(pricesStore(), 'SA', 'ناقل', 'Patterned', trackingPattern: 'https://track.example/?n={number}'));
        $api = app(ShippingApi::class);

        expect($api->trackingLink($patterned, ' AB 12/3 '))->toBe('https://track.example/?n=AB%2012%2F3')
            ->and($api->trackingLink($patterned, '  '))->toBeNull()
            ->and($api->trackingLink(pricesCarrier('Plain'), '123'))->toBeNull()
            ->and($api->carrier($patterned)?->hasTrackingPattern)->toBeTrue();
    });
});

describe('places no carrier serves (§1.6, gap 4.3)', function () {
    it('lists the biggest places nothing serves, follows the prices, and counts them for the menu', function () {
        $places = pricesSaudiPlaces();

        expect(pricesUncovered())->toBe(['SA' => ['Eastern Region', 'Riyadh Region']])
            ->and(app(UncoveredPlacesCount::class)->count())->toBe(2);

        $aramex = pricesCarrier('Aramex');
        pricesSave($aramex, 1500, [$places['Olaya']]);

        // Riyadh Region is partly served: Diriyah is a row; Riyadh is partly served: Malaz is a row.
        expect(pricesUncovered())->toBe(['SA' => ['Diriyah', 'Eastern Region', 'Malaz']])
            ->and(app(UncoveredPlacesCount::class)->count())->toBe(3);

        pricesSave($aramex, 4000, [$places['Riyadh Region'], $places['Eastern Region']]);

        expect(pricesUncovered())->toBe(['SA' => []])
            ->and(app(UncoveredPlacesCount::class)->count())->toBe(0);

        app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(pricesStore(), $aramex, false));

        // A switched-off carrier serves nothing.
        expect(pricesUncovered())->toBe(['SA' => ['Eastern Region', 'Riyadh Region']])
            ->and(app(UncoveredPlacesCount::class)->count())->toBe(2);
    });

    it('lists a place Platform adds at once; the count follows on its next count', function () {
        pricesSaudiPlaces();
        app(UncoveredPlacesCount::class)->count();
        pricesPlace(PlaceKind::Region->value, null, 'Makkah Region');

        expect(pricesUncovered()['SA'])->toContain('Makkah Region')
            ->and(app(UncoveredPlacesCount::class)->count())->toBe(2);

        (new CountUncoveredPlacesJob)->handle(app(PlatformApi::class), app(UncoveredPlaces::class), app(UncoveredCounts::class));

        expect(app(UncoveredPlacesCount::class)->count())->toBe(3);
    });
});
