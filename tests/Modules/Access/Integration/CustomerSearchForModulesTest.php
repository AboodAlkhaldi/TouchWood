<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Public\Contracts\AccessApi;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| AccessApi::customerIdsMatching and customerViewStores (access.md amendment 72(b), (e)): a module's
| staff list searched by a person - names in every store, emails and phones only among the customers
| the reader may view, as the staff customer list sees them (44(b)) - and which home stores' customers
| the reader may view.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function customerSearchFor(string $email, string $first, string $storeCode, ?string $phone = null): string
{
    $customer = Fx::customer($email, $storeCode);
    // A phone is kept only verified (customers_phone_verified_together).
    DB::table('access.customers')->where('id', $customer)->update(['first_name' => $first, 'last_name' => 'Search', 'phone' => $phone, 'phone_verified_at' => $phone === null ? null : now()]);

    return $customer;
}

it('matches a name in every store, and an email or a phone only among the customers the reader may view', function () {
    $sa = customerSearchFor('noor@example.test', 'Noor', 'sa', '+966500000001');
    $eg = customerSearchFor('hana@example.test', 'Hana', 'eg', '+201000000002');
    Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa']));
    $api = app(AccessApi::class);

    expect($api->customerIdsMatching('han', 10))->toBe([$eg])
        ->and($api->customerIdsMatching('noor@exa', 10))->toBe([$sa])
        // The Egyptian customer's email and phone name nobody to a reader of Saudi Arabia.
        ->and($api->customerIdsMatching('hana@exa', 10))->toBe([])
        ->and($api->customerIdsMatching('2010000', 10))->toBe([])
        // A phone typed in Arabic-Indic digits is searched in Latin, as the staff list searches it.
        ->and($api->customerIdsMatching('٥٠٠٠٠', 10))->toBe([$sa]);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    // Asked again for the new reader, as each request asks it.
    $api = app(AccessApi::class);

    expect($api->customerIdsMatching('hana@exa', 10))->toBe([$eg])
        ->and($api->customerViewStores())->toBeNull();
});

it('matches no email or phone at all for a reader who may view no customer, and never an anonymized customer', function () {
    $named = customerSearchFor('lina@example.test', 'Lina', 'sa');
    $gone = customerSearchFor('linaold@example.test', 'Lina', 'sa');
    DB::table('access.customers')->where('id', $gone)->update(['anonymized_at' => now()]);
    // A staff member with a job, but not the customers' (a role needs one).
    Fx::actAsStaff(Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']));
    $api = app(AccessApi::class);

    expect($api->customerViewStores())->toBe([])
        ->and($api->customerIdsMatching('lina@example', 10))->toBe([])
        ->and($api->customerIdsMatching('lina', 10))->toBe([$named]);
});

it('reads a % or an _ as itself, not as a wildcard', function () {
    customerSearchFor('a1@example.test', 'Abc', 'sa');
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(app(AccessApi::class)->customerIdsMatching('a%c', 10))->toBe([])
        ->and(app(AccessApi::class)->customerIdsMatching('a_c', 10))->toBe([]);
});

it('answers at most the limit asked, by id', function () {
    $ids = [customerSearchFor('m1@example.test', 'Maha', 'sa'), customerSearchFor('m2@example.test', 'Maha', 'sa'), customerSearchFor('m3@example.test', 'Maha', 'sa')];
    sort($ids);
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(app(AccessApi::class)->customerIdsMatching('maha', 2))->toBe(array_slice($ids, 0, 2));
});

it('refuses a text of fewer than 3 or more than 100 characters, or with a control character', function (string $text) {
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(fn () => app(AccessApi::class)->customerIdsMatching($text, 10))->toThrow(InvalidAccessAttribute::class);
})->with([
    'two, trimmed' => ['  ab  '],
    'a hundred and one' => [str_repeat('a', 101)],
    'a new line' => ["ab\nc"],
]);

it('says which home stores\' customers a reader may view', function () {
    Fx::actAsStaff(Fx::staffWith([AccessPermissions::CUSTOMER_VIEW], ['sa', 'eg']));

    expect(array_map(static fn (StoreId $store): string => $store->value, app(AccessApi::class)->customerViewStores() ?? []))
        ->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);
});
