<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountry;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountryHandler;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Shipping\Application\Command\ChangeCarrier\ChangeCarrier;
use Modules\Shipping\Application\Command\ChangeCarrier\ChangeCarrierHandler;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrier;
use Modules\Shipping\Application\Command\CreateCarrier\CreateCarrierHandler;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrier;
use Modules\Shipping\Application\Command\DeleteCarrier\DeleteCarrierHandler;
use Modules\Shipping\Application\Command\OrderCarriers\OrderCarriers;
use Modules\Shipping\Application\Command\OrderCarriers\OrderCarriersHandler;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrier;
use Modules\Shipping\Application\Command\SwitchCarrier\SwitchCarrierHandler;
use Modules\Shipping\Application\Command\UploadCarrierLogo\UploadCarrierLogo;
use Modules\Shipping\Application\Command\UploadCarrierLogo\UploadCarrierLogoHandler;
use Modules\Shipping\Application\Query\CarrierRow;
use Modules\Shipping\Application\Query\ListCarriers\CarrierCountry;
use Modules\Shipping\Application\Query\ListCarriers\ListCarriers;
use Modules\Shipping\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrier;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrierHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\CarrierNameTaken;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\CarrierStillOn;
use Modules\Shipping\Domain\Exception\CountryHasCarriers;
use Modules\Shipping\Domain\Exception\CountryNotShipped;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

/*
| A store's carriers (shipping.md §1.3, §3, §7; stage 7 step 3 and the owner's answers Q1–Q7 of
| 2026-10-29). Every helper is named after this file's subject: a function in a Pest file is global.
*/

function carriersStore(string $code = 'sa'): string
{
    return (string) DB::table('platform.stores')->where('code', $code)->value('id');
}

function carriersCreate(string $nameEn, string $country = 'SA', string $store = 'sa', bool $active = true, ?string $nameAr = null): string
{
    return app(CreateCarrierHandler::class)->handle(new CreateCarrier(carriersStore($store), $country, $nameAr ?? 'ناقل '.$nameEn, $nameEn, active: $active));
}

/**
 * Bahrain on the Saudi store's list: a place list first (Platform's rule).
 */
function carriersShipToBahrain(): void
{
    app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('BH'));
    $region = app(AddPlaceHandler::class)->handle(new AddPlace('BH', PlaceKind::Region->value, null, 'العاصمة', 'Capital'));
    app(AddPlaceHandler::class)->handle(new AddPlace('BH', PlaceKind::City->value, $region, 'المنامة', 'Manama'));
    app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'BH'));
}

/**
 * @return array<string, mixed>|null
 */
function carriersAudit(string $action): ?array
{
    $changes = DB::table('platform.audit_entries')->where('action', $action)->orderByDesc('id')->value('changes');

    return $changes === null ? null : json_decode((string) $changes, true);
}

/**
 * @return array<string, list<string>> country => carrier English names, in order
 */
function carriersListed(string $store = 'sa'): array
{
    $out = [];

    foreach (app(ListCarriersHandler::class)->handle(new ListCarriers(carriersStore($store), 'en')) as $country) {
        $out[$country->code] = array_map(static fn (CarrierRow $row): string => $row->name->en, $country->carriers);
    }

    return $out;
}

describe('creating a carrier', function () {
    it('keeps every detail, trimmed, digits Latin, the phone in E.164, last in its country, audited', function () {
        carriersCreate('Aramex');
        $id = app(CreateCarrierHandler::class)->handle(new CreateCarrier(
            carriersStore(), ' sa ', ' سمسا ٢ ', ' SMSA 2 ', CatalogFixtures::media(), '00966 50-123 4567', 'https://smsaexpress.com', 'https://smsaexpress.com/track?awb={number}',
        ));

        $row = app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), strtoupper($id)));

        expect([$row->countryCode, $row->name->ar, $row->name->en, $row->phone, $row->website, $row->trackingPattern, $row->active, $row->position, $row->freeAboveMinor])
            ->toBe(['SA', 'سمسا 2', 'SMSA 2', '+966501234567', 'https://smsaexpress.com', 'https://smsaexpress.com/track?awb={number}', true, 2, null])
            ->and($row->logoMediaId)->not->toBeNull()
            ->and(carriersAudit('shipping.carrier.created'))->toHaveKeys(['name_ar', 'name_en', 'country', 'position']);
    });

    it('refuses a country the store does not ship to, and one another store ships to', function (string $country) {
        expect(fn () => carriersCreate('Aramex', $country))->toThrow(CountryNotShipped::class);
    })->with(['not on any list' => 'BH', 'the Egyptian store\'s' => 'EG']);

    it('creates one in another of the store\'s countries, numbered within that country', function () {
        carriersShipToBahrain();
        carriersCreate('Aramex');
        carriersCreate('Aramex Bahrain', 'BH');

        expect(app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), carriersCreate('DHL Bahrain', 'BH')))->position)->toBe(2)
            ->and(carriersListed())->toBe(['SA' => ['Aramex'], 'BH' => ['Aramex Bahrain', 'DHL Bahrain']]);
    });

    it('refuses a name another carrier of the store has, in either language, ignoring case and spaces, naming it', function (string $ar, string $en, string $language) {
        carriersCreate('Aramex', nameAr: 'أرامكس');

        try {
            app(CreateCarrierHandler::class)->handle(new CreateCarrier(carriersStore(), 'SA', $ar, $en));
            expect('accepted')->toBe('refused');
        } catch (CarrierNameTaken $error) {
            expect($error->context())->toBe(['language' => $language, 'name_ar' => 'أرامكس', 'name_en' => 'Aramex']);
        }
    })->with([
        'the English name' => ['ناقل', '  ARAMEX ', 'en'],
        'the Arabic name' => [' أرامكس', 'Other', 'ar'],
    ]);

    it('lets another store use the same name', function () {
        carriersCreate('Aramex');

        expect(carriersCreate('Aramex', 'EG', 'eg'))->toBeString();
    });

    it('refuses what is out of range, naming the field and the rule', function (array $fields, string $field, string $rule) {
        $fields += ['ar' => 'ناقل', 'en' => 'Carrier', 'logo' => null, 'phone' => null, 'website' => null, 'tracking' => null];

        try {
            app(CreateCarrierHandler::class)->handle(new CreateCarrier(carriersStore(), 'SA', $fields['ar'], $fields['en'], $fields['logo'], $fields['phone'], $fields['website'], $fields['tracking']));
            expect('accepted')->toBe('refused');
        } catch (InvalidShippingInput $error) {
            expect([$error->field, $error->rule])->toBe([$field, $rule]);
        }
    })->with([
        'no Arabic name' => [['ar' => '  '], 'name_ar', '1-100 characters'],
        'a long English name' => [['en' => str_repeat('a', 101)], 'name_en', '1-100 characters'],
        'a phone with no country' => [['phone' => '0501234567'], 'phone', 'an international number such as +966501234567'],
        'a plain http website' => [['website' => 'http://carrier.example'], 'website', 'https'],
        'a website that is not a link' => [['website' => 'carrier dot com'], 'website', 'https'],
        'a long website' => [['website' => 'https://carrier.example/'.str_repeat('a', 480)], 'website', 'at most 500 characters'],
        'a pattern with no number' => [['tracking' => 'https://carrier.example/track'], 'tracking_pattern', 'tracking_pattern_number'],
        'a pattern with two numbers' => [['tracking' => 'https://carrier.example/{number}/{number}'], 'tracking_pattern', 'tracking_pattern_number'],
        'a plain http pattern' => [['tracking' => 'http://carrier.example/t?n={number}'], 'tracking_pattern', 'https'],
        'a long pattern' => [['tracking' => 'https://carrier.example/'.str_repeat('a', 470).'?n={number}'], 'tracking_pattern', 'at most 500 characters'],
    ]);

    it('takes a logo that is a public image only', function (Closure $media) {
        expect(fn () => app(CreateCarrierHandler::class)->handle(new CreateCarrier(carriersStore(), 'SA', 'ناقل', 'Carrier', $media())))
            ->toThrow(InvalidShippingInput::class);
    })->with([
        'a private file' => [fn () => CatalogFixtures::media('PRIVATE')],
        'a PDF' => [fn () => CatalogFixtures::media('PUBLIC', 'application/pdf')],
        'nothing' => [fn () => '01jbz0000000000000000000aa'],
    ]);
});

describe('changing, switching, ordering', function () {
    it('changes the details, keeps the country, audits only what changed', function () {
        $id = carriersCreate('Aramex');
        app(ChangeCarrierHandler::class)->handle(new ChangeCarrier(carriersStore(), $id, 'أرامكس', 'Aramex Express', null, '+966501234567'));

        $row = app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), $id));

        expect([$row->name->ar, $row->name->en, $row->phone, $row->countryCode])->toBe(['أرامكس', 'Aramex Express', '+966501234567', 'SA'])
            ->and(array_keys(carriersAudit('shipping.carrier.changed') ?? []))->toEqualCanonicalizing(['name_ar', 'name_en', 'phone']);
    });

    it('records nothing when nothing changed', function () {
        $id = carriersCreate('Aramex', nameAr: 'أرامكس');
        app(ChangeCarrierHandler::class)->handle(new ChangeCarrier(carriersStore(), $id, 'أرامكس', 'Aramex'));

        expect(carriersAudit('shipping.carrier.changed'))->toBeNull();
    });

    it('keeps its own names when they are changed only in case', function () {
        $id = carriersCreate('Aramex');

        app(ChangeCarrierHandler::class)->handle(new ChangeCarrier(carriersStore(), $id, 'ناقل Aramex', 'ARAMEX'));

        expect(app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), $id))->name->en)->toBe('ARAMEX');
    });

    it('refuses a name another carrier has on a change', function () {
        carriersCreate('Aramex');
        $smsa = carriersCreate('SMSA');

        expect(fn () => app(ChangeCarrierHandler::class)->handle(new ChangeCarrier(carriersStore(), $smsa, 'ناقل SMSA', 'aramex')))->toThrow(CarrierNameTaken::class);
    });

    it('switches off and on, audited each time, nothing for a switch to where it is', function () {
        $id = carriersCreate('Aramex');
        app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(carriersStore(), $id, true));
        app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(carriersStore(), $id, false));

        expect(app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), $id))->active)->toBeFalse()
            ->and(DB::table('platform.audit_entries')->where('action', 'like', 'shipping.carrier.switched_%')->pluck('action')->all())->toBe(['shipping.carrier.switched_off']);
    });

    it('orders a country\'s carriers: those sent first, the rest after them in their order', function () {
        $a = carriersCreate('A');
        $b = carriersCreate('B');
        $c = carriersCreate('C');

        app(OrderCarriersHandler::class)->handle(new OrderCarriers(carriersStore(), 'sa', [strtoupper($c), $a]));

        expect(carriersListed())->toBe(['SA' => ['C', 'A', 'B']])
            ->and(DB::table('platform.audit_entries')->where('action', 'shipping.carrier.ordered')->count())->toBe(3);
    });

    it('refuses an order naming a carrier twice', function () {
        $a = carriersCreate('A');

        try {
            app(OrderCarriersHandler::class)->handle(new OrderCarriers(carriersStore(), 'SA', [$a, strtoupper($a)]));
            expect('accepted')->toBe('refused');
        } catch (InvalidShippingInput $error) {
            expect($error->field)->toBe('carriers');
        }
    });

    it('answers a carrier of another country or store in an order as not found', function (Closure $ids) {
        carriersShipToBahrain();
        $a = carriersCreate('A');

        expect(fn () => app(OrderCarriersHandler::class)->handle(new OrderCarriers(carriersStore(), 'SA', $ids($a))))->toThrow(CarrierNotFound::class);
    })->with([
        'another country\'s' => [fn (string $a) => [$a, carriersCreate('B', 'BH')]],
        'another store\'s' => [fn (string $a) => [carriersCreate('E', 'EG', 'eg')]],
    ]);
});

describe('deleting', function () {
    it('refuses a carrier that is on', function () {
        $id = carriersCreate('Aramex');

        expect(fn () => app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(carriersStore(), $id)))->toThrow(CarrierStillOn::class);
    });

    it('deletes one that is off, its logo left in the library, its names in the audit', function () {
        $logo = CatalogFixtures::media();
        $id = app(CreateCarrierHandler::class)->handle(new CreateCarrier(carriersStore(), 'SA', 'أرامكس', 'Aramex', $logo, active: false));

        app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(carriersStore(), $id));

        expect(DB::table('shipping.carriers')->count())->toBe(0)
            ->and(DB::table('platform.media')->where('id', $logo)->exists())->toBeTrue()
            ->and(carriersAudit('shipping.carrier.deleted'))->toHaveKeys(['name_ar', 'name_en']);
    });

    it('answers another store\'s carrier as not found, everywhere', function (Closure $call) {
        $egypt = carriersCreate('Bosta', 'EG', 'eg', active: false);

        expect(fn () => $call($egypt))->toThrow(CarrierNotFound::class);
    })->with([
        'view' => [fn (string $id) => app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), $id))],
        'change' => [fn (string $id) => app(ChangeCarrierHandler::class)->handle(new ChangeCarrier(carriersStore(), $id, 'ب', 'B'))],
        'switch' => [fn (string $id) => app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(carriersStore(), $id, true))],
        'delete' => [fn (string $id) => app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(carriersStore(), $id))],
        'not an id' => [fn (string $id) => app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(carriersStore(), 'aramex'))],
    ]);
});

describe('the ship-to countries (Q3)', function () {
    it('keeps a country on the store\'s list while a carrier is there, off or not', function () {
        carriersShipToBahrain();
        carriersCreate('Aramex Bahrain', 'BH', active: false);

        expect(fn () => app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('sa', 'BH')))->toThrow(CountryHasCarriers::class)
            ->and(DB::table('platform.store_countries')->where('country_code', 'BH')->exists())->toBeTrue();
    });

    it('lets it go once its carriers are deleted', function () {
        carriersShipToBahrain();
        $id = carriersCreate('Aramex Bahrain', 'BH', active: false);
        app(DeleteCarrierHandler::class)->handle(new DeleteCarrier(carriersStore(), $id));

        app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('sa', 'BH'));

        expect(DB::table('platform.store_countries')->where('country_code', 'BH')->exists())->toBeFalse();
    });
});

describe('the carriers list', function () {
    it('lists every ship-to country, its own first and marked, each with its carriers, off ones too', function () {
        carriersShipToBahrain();
        carriersCreate('Aramex', active: false);

        $countries = app(ListCarriersHandler::class)->handle(new ListCarriers(carriersStore(), 'en'));

        expect(array_map(static fn (CarrierCountry $country): array => [$country->code, $country->own, $country->name->en, count($country->carriers)], $countries))
            ->toBe([['SA', true, 'Saudi Arabia', 1], ['BH', false, 'Bahrain', 0]])
            ->and($countries[0]->carriers[0]->active)->toBeFalse();
    });
});

describe('who may', function () {
    it('lets a store\'s admin manage its carriers, and staff given the view read them', function () {
        Fx::actAsAdmin(['sa'], [ShippingPermissions::CARRIERS_MANAGE, ShippingPermissions::CARRIERS_VIEW]);
        $id = carriersCreate('Aramex');

        Fx::actAsStaff(Fx::staffWith([ShippingPermissions::CARRIERS_VIEW], ['sa']));

        expect(carriersListed())->toBe(['SA' => ['Aramex']])
            ->and(fn () => app(SwitchCarrierHandler::class)->handle(new SwitchCarrier(carriersStore(), $id, false)))->toThrow(Unauthorized::class);
    });

    it('refuses another store\'s admin', function () {
        Fx::actAsAdmin(['eg'], [ShippingPermissions::CARRIERS_MANAGE, ShippingPermissions::CARRIERS_VIEW]);

        expect(fn () => carriersCreate('Aramex'))->toThrow(Unauthorized::class)
            ->and(fn () => carriersListed())->toThrow(Unauthorized::class);
    });

    it('refuses a customer', function () {
        Fx::actAsCustomer(Fx::customer());

        expect(fn () => carriersListed())->toThrow(Unauthorized::class);
    });

    it('refuses anyone but staff, the system too', function () {
        Fx::actAs(Actor::system());

        expect(fn () => carriersCreate('Aramex'))->toThrow(Unauthorized::class);
    });

    it('uploads a logo for a store\'s admin as a public image, and refuses another store\'s', function () {
        Storage::fake('local');
        Fx::actAsAdmin(['sa'], [ShippingPermissions::CARRIERS_MANAGE]);
        $file = UploadedFile::fake()->image('aramex.png', 200, 200);

        $id = app(UploadCarrierLogoHandler::class)->handle(new UploadCarrierLogo(carriersStore(), (string) $file->getRealPath(), 'aramex.png'));

        expect(DB::table('platform.media')->where('id', $id)->value('visibility'))->toBe('PUBLIC')
            ->and(fn () => app(UploadCarrierLogoHandler::class)->handle(new UploadCarrierLogo(carriersStore('eg'), (string) $file->getRealPath(), 'aramex.png')))
            ->toThrow(Unauthorized::class);
    });

    it('refuses a store that is not one', function (string $store) {
        expect(fn () => app(ListCarriersHandler::class)->handle(new ListCarriers($store, 'en')))->toThrow(InvalidShippingInput::class);
    })->with(['not an id' => 'sa', 'no such store' => '01jbz0000000000000000000aa']);
});

describe('a logo deleted from the media library', function () {
    it('leaves its carriers with no logo, audited', function () {
        $logo = CatalogFixtures::media();
        $id = app(CreateCarrierHandler::class)->handle(new CreateCarrier(carriersStore(), 'SA', 'أرامكس', 'Aramex', $logo));

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($logo));

        expect(app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), $id))->logoMediaId)->toBeNull()
            ->and(carriersAudit('shipping.carrier.logo_detached'))->toHaveKey('logo_media_id');
    });

    it('refuses the whole delete to staff who may delete media but not manage that store\'s carriers', function () {
        $logo = CatalogFixtures::media();
        $id = app(CreateCarrierHandler::class)->handle(new CreateCarrier(carriersStore(), 'SA', 'أرامكس', 'Aramex', $logo));
        Fx::actAsAdmin(['eg'], [PlatformPermissions::MEDIA_DELETE, ShippingPermissions::CARRIERS_MANAGE]);

        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($logo)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(app(ViewCarrierHandler::class)->handle(new ViewCarrier(carriersStore(), $id))->logoMediaId)->toBe($logo)
            ->and(DB::table('platform.media')->where('id', $logo)->exists())->toBeTrue();
    });
});
