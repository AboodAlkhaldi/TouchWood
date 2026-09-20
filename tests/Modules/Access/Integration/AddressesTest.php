<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Address\GiveEveryStoreAnAddressFormat;
use Modules\Access\Application\Address\StartingAddressFormat;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddress;
use Modules\Access\Application\Command\DeleteAddress\DeleteAddressHandler;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddress;
use Modules\Access\Application\Command\SetDefaultAddress\SetDefaultAddressHandler;
use Modules\Access\Application\Command\UpdateStoreAddressFormat\UpdateStoreAddressFormat;
use Modules\Access\Application\Command\UpdateStoreAddressFormat\UpdateStoreAddressFormatHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\AddressFormatMissing;
use Modules\Access\Domain\Exception\AddressNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\Exception\TooManyAddresses;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Events\StoreCreated;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function saveAddress(array $overrides = []): string
{
    $command = new SaveAddress(
        storeId: $overrides['storeId'] ?? Fx::storeId('sa'),
        label: $overrides['label'] ?? 'Home',
        recipientName: $overrides['recipientName'] ?? 'Sara Ali',
        phone: $overrides['phone'] ?? '+966501234567',
        fields: $overrides['fields'] ?? addressBookFields(),
        latitude: $overrides['latitude'] ?? null,
        longitude: $overrides['longitude'] ?? null,
        isDefault: $overrides['isDefault'] ?? false,
        addressId: $overrides['addressId'] ?? null,
    );

    return app(SaveAddressHandler::class)->handle($command);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function addressBookFields(array $overrides = []): array
{
    return [
        'administrative_area' => 'Riyadh',
        'city' => 'Riyadh',
        'district' => 'Al Olaya',
        'street' => 'King Fahd Road',
        'building' => '7',
        ...$overrides,
    ];
}

function addressRow(string $addressId): ?stdClass
{
    return DB::table('access.addresses')->where('id', $addressId)->first();
}

describe('a customer\'s addresses (spec §1.9)', function () {
    it('saves the first address as the store\'s default', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);

        $addressId = saveAddress();
        $row = addressRow($addressId);

        expect($row?->is_default)->toBeTrue()
            ->and($row?->store_id)->toBe(Fx::storeId('sa'))
            // jsonb keeps no key order, so compare the pairs, not their order (the DTO orders them).
            ->and(json_decode((string) $row?->fields, true))->toEqualCanonicalizing(addressBookFields())
            ->and(Fx::audits('access.customer.address_added', $customerId))->toBe(1);
    });

    it('keeps every address field out of the audit log', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        saveAddress();

        $changes = json_decode((string) DB::table('platform.audit_entries')
            ->where('action', 'access.customer.address_added')->value('changes'), true);

        expect($changes)->toEqualCanonicalizing([
            'label' => 'changed',
            'recipient_name' => 'changed',
            'phone' => 'changed',
            'fields' => 'changed',
            'is_default' => [null, true],
        ]);
    });

    it('refuses a field the store\'s format does not define', function () {
        Fx::actAsCustomer(Fx::customer());

        expect(fn () => saveAddress(['fields' => addressBookFields(['province' => 'Riyadh'])]))
            ->toThrow(InvalidAddress::class, 'province');
    });

    it('refuses an address with a required field missing', function () {
        Fx::actAsCustomer(Fx::customer());
        $fields = addressBookFields();
        unset($fields['city']);

        expect(fn () => saveAddress(['fields' => $fields]))->toThrow(InvalidAddress::class, 'city');
    });

    it('refuses a store that does not exist, and one with no format', function () {
        Fx::actAsCustomer(Fx::customer());
        DB::table('access.store_address_formats')->where('store_id', Fx::storeId('ae'))->delete();

        expect(fn () => saveAddress(['storeId' => '01j8z3k4m5n6p7q8r9s0t1v2w3']))->toThrow(InvalidAccessAttribute::class, 'store')
            ->and(fn () => saveAddress(['storeId' => Fx::storeId('ae')]))->toThrow(AddressFormatMissing::class);
    });

    it('keeps at most ten in one store, counting each store on its own', function () {
        Fx::actAsCustomer(Fx::customer());

        foreach (range(1, 10) as $number) {
            saveAddress(['label' => "Home {$number}"]);
        }

        expect(fn () => saveAddress(['label' => 'One too many']))->toThrow(TooManyAddresses::class)
            // The same customer may still add one in another country.
            ->and(saveAddress(['storeId' => Fx::storeId('ae'), 'label' => 'Dubai']))->not->toBeEmpty();
    });

    it('changes an address without moving it to another store', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $addressId = saveAddress();

        saveAddress(['addressId' => $addressId, 'label' => 'Work', 'fields' => addressBookFields(['unit' => '12'])]);
        $row = addressRow($addressId);

        expect($row?->label)->toBe('Work')
            ->and(json_decode((string) $row?->fields, true))->toHaveKey('unit')
            ->and($row?->store_id)->toBe(Fx::storeId('sa'))
            ->and(Fx::audits('access.customer.address_updated', $customerId))->toBe(1);
    });

    it('answers someone else\'s address as no address at all', function () {
        $mine = Fx::customer();
        Fx::actAsCustomer($mine);
        $addressId = saveAddress();

        Fx::actAsCustomer(Fx::customer('other@example.test'));

        expect(fn () => saveAddress(['addressId' => $addressId]))->toThrow(AddressNotFound::class)
            ->and(fn () => app(DeleteAddressHandler::class)->handle(new DeleteAddress($addressId)))->toThrow(AddressNotFound::class)
            ->and(fn () => app(SetDefaultAddressHandler::class)->handle(new SetDefaultAddress($addressId)))->toThrow(AddressNotFound::class);
    });

    it('keeps one default in each store', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $first = saveAddress(['label' => 'Home']);
        $second = saveAddress(['label' => 'Work']);
        $abroad = saveAddress(['storeId' => Fx::storeId('ae'), 'label' => 'Dubai']);

        app(SetDefaultAddressHandler::class)->handle(new SetDefaultAddress($second));

        expect(addressRow($first)?->is_default)->toBeFalse()
            ->and(addressRow($second)?->is_default)->toBeTrue()
            // Another country keeps its own default.
            ->and(addressRow($abroad)?->is_default)->toBeTrue()
            ->and(DB::table('access.addresses')->where('customer_id', $customerId)->where('is_default', true)->count())->toBe(2);
    });

    it('moves the default to the newest address left when the default is deleted', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $first = saveAddress(['label' => 'Home']);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $second = saveAddress(['label' => 'Work']);

        app(DeleteAddressHandler::class)->handle(new DeleteAddress($first));

        expect(addressRow($first))->toBeNull()
            ->and(addressRow($second)?->is_default)->toBeTrue()
            ->and(Fx::audits('access.customer.address_deleted', $customerId))->toBe(1);
    });

    it('leaves nothing behind when the last address goes', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $addressId = saveAddress();

        app(DeleteAddressHandler::class)->handle(new DeleteAddress($addressId));

        expect(DB::table('access.addresses')->where('customer_id', $customerId)->count())->toBe(0);
    });

    it('is only for the customer acting', function () {
        Fx::actAsStaff(Fx::staff());

        expect(fn () => saveAddress())->toThrow(Unauthorized::class);
    });
});

describe('the addresses other modules read (spec §2.1)', function () {
    it('answers a store\'s addresses, the default first, printed by that store', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        saveAddress(['label' => 'Home']);
        $second = saveAddress(['label' => 'Work', 'isDefault' => true, 'latitude' => 24.713612, 'longitude' => 46.675299]);
        saveAddress(['storeId' => Fx::storeId('ae'), 'label' => 'Dubai']);

        $addresses = app(AccessApi::class)->addresses($customerId, Fx::storeId('sa'));

        expect($addresses)->toHaveCount(2)
            ->and($addresses[0]->id)->toBe($second)
            ->and($addresses[0]->isDefault)->toBeTrue()
            ->and($addresses[0]->isComplete)->toBeTrue()
            ->and($addresses[0]->latitude)->toBe(24.713612)
            ->and($addresses[0]->formatted)->toBe("7 King Fahd Road\nAl Olaya\nRiyadh")
            ->and($addresses[1]->isDefault)->toBeFalse();
    });

    it('marks an address the store\'s format has outgrown as not complete', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        $addressId = saveAddress();

        // The store now asks for a floor: what is saved is kept, but it may not be shipped to.
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']));
        $fields = StartingAddressFormat::forStore(Fx::storeId('sa'))->toArray();
        $fields[] = ['key' => 'floor_number', 'label_ar' => 'الدور', 'label_en' => 'Floor', 'required' => true, 'max_length' => 50, 'order' => 99];
        app(UpdateStoreAddressFormatHandler::class)->handle(new UpdateStoreAddressFormat(Fx::storeId('sa'), $fields, '{street}'));

        $address = app(AccessApi::class)->address($addressId);

        expect($address?->isComplete)->toBeFalse()
            ->and($address?->fields)->toBe(addressBookFields())
            ->and($address?->formatted)->toBe('King Fahd Road');
    });

    it('answers an unknown id with nothing', function () {
        expect(app(AccessApi::class)->address('not-an-id'))->toBeNull()
            ->and(app(AccessApi::class)->addresses(Fx::customer(), Fx::storeId('sa')))->toBe([]);
    });
});

describe('a store\'s address format (spec §3.3)', function () {
    it('leaves no store without one', function () {
        expect(DB::table('access.store_address_formats')->count())->toBe(DB::table('platform.stores')->count())
            ->and(DB::table('platform.stores')->count())->toBeGreaterThan(1);
    });

    it('gives the starting scheme to every store that has none, and changes no other', function () {
        $storeId = Fx::storeId('sa');
        DB::table('access.store_address_formats')->where('store_id', $storeId)->delete();
        DB::table('access.store_address_formats')->where('store_id', '!=', $storeId)->update(['display_template' => 'staff wrote this']);

        // What the migration does on an installation whose stores already exist (amendment 41).
        $written = app(GiveEveryStoreAnAddressFormat::class)->run();

        expect($written)->toBe(1)
            ->and(DB::table('access.store_address_formats')->where('store_id', $storeId)->value('display_template'))
            ->toBe(StartingAddressFormat::forStore($storeId)->displayTemplate)
            // A store that already had one keeps what staff wrote, and a second run writes nothing.
            ->and(DB::table('access.store_address_formats')->where('store_id', Fx::storeId('ae'))->value('display_template'))->toBe('staff wrote this')
            ->and(app(GiveEveryStoreAnAddressFormat::class)->run())->toBe(0);
    });

    it('gives a store opened later the same scheme', function () {
        $storeId = Fx::storeId('sa');
        DB::table('access.store_address_formats')->where('store_id', $storeId)->delete();

        event(new StoreCreated('e1', $storeId, CarbonImmutable::now()));

        expect(DB::table('access.store_address_formats')->where('store_id', $storeId)->exists())->toBeTrue();
    });

    it('changes only its own store, and takes effect at once', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']));

        app(UpdateStoreAddressFormatHandler::class)->handle(new UpdateStoreAddressFormat(
            Fx::storeId('sa'),
            [['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100, 'order' => 0]],
            '{city}',
        ));

        Fx::actAsCustomer($customerId);

        expect(saveAddress(['fields' => ['city' => 'Riyadh']]))->not->toBeEmpty()
            // The Emirates keep the scheme they had.
            ->and(fn () => saveAddress(['storeId' => Fx::storeId('ae'), 'fields' => ['city' => 'Dubai']]))
            ->toThrow(InvalidAddress::class, 'administrative_area');
    });

    it('is refused to staff who do not have that store', function () {
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['ae']));

        expect(fn () => app(UpdateStoreAddressFormatHandler::class)->handle(new UpdateStoreAddressFormat(
            Fx::storeId('sa'),
            StartingAddressFormat::forStore(Fx::storeId('sa'))->toArray(),
            '{city}',
        )))->toThrow(Unauthorized::class);
    });

    it('is audited without personal data, and names the store', function () {
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa']));

        app(UpdateStoreAddressFormatHandler::class)->handle(new UpdateStoreAddressFormat(
            Fx::storeId('sa'),
            [['key' => 'city', 'label_ar' => 'المدينة', 'label_en' => 'City', 'required' => true, 'max_length' => 100, 'order' => 0]],
            '{city}',
        ));

        $entry = DB::table('platform.audit_entries')->where('action', 'access.store_address_format.updated')->first();
        $changes = json_decode((string) $entry?->changes, true);

        expect($entry?->store_id)->toBe(Fx::storeId('sa'))
            ->and($changes['fields'][1] ?? null)->toBe(['city'])
            ->and($changes['required_fields'][1] ?? null)->toBe(['city'])
            ->and($changes['display_template'][1] ?? null)->toBe('{city}');
    });

    it('reads a warm format from the cache, not the table', function () {
        $storeId = Fx::storeId('sa');
        Fx::actAsCustomer(Fx::customer());
        saveAddress();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PlatformApi::class)->store(StoreId::fromString($storeId));
        $before = count(DB::getQueryLog());
        app(AccessApi::class)->addresses(Fx::customer('warm@example.test'), $storeId);
        $queries = array_column(array_slice(DB::getQueryLog(), $before), 'query');
        DB::disableQueryLog();

        foreach ($queries as $query) {
            expect($query)->not->toContain('store_address_formats');
        }
    });
});
