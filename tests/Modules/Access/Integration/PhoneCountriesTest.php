<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitation;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitationHandler;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCode;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCodeHandler;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChange;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChangeHandler;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfile;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfileHandler;
use Modules\Access\Application\Security\CustomerPhoneVerification;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\ValueObject\CustomerPhoneCodePurpose;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Amendment 87 (owner, 2026-10-29): a phone from a country not on Ops' SMS countries list is refused
| wherever an account phone is entered - staff and customers alike - and no code is sent to it. A saved
| phone whose country is taken off the list counts as none: the customer reads as unverified and cannot
| order until they change it. The start list is Saudi Arabia (966) and Egypt (20); the United Arab
| Emirates (971) is not on it.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

const PHONE_COUNTRIES_UNLISTED = '+971501234567';

/**
 * The refusal every entry point gives: the phone field, with its own words (amendment 87).
 */
function phoneCountriesRefused(): Closure
{
    return fn (InvalidAccessAttribute $error) => expect($error->attribute)->toBe('phone')
        ->and($error->rule)->toBe('phone_country');
}

it('refuses a number from a country not on the list wherever an account phone is entered', function (Closure $enter) {
    expect($enter)->toThrow(phoneCountriesRefused())
        ->and(RecordingSecurityMessages::installed()->codes)->toBe([])
        ->and(RecordingSecurityMessages::installed()->invitations)->toBe([]);
})->with([
    'inviting a staff member' => [function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(InviteStaffHandler::class)->handle(new InviteStaff(
            'noura@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, PHONE_COUNTRIES_UNLISTED, 'en',
            AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]),
        ));
    }],
    'accepting an invitation' => [function () {
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        app(AcceptStaffInvitationHandler::class)->handle(new AcceptStaffInvitation('any-token', 'a long enough password', PHONE_COUNTRIES_UNLISTED));
    }],
    'creating a Super Admin' => [fn () => Fx::asSystem(fn () => app(CreateSuperAdminHandler::class)->handle(
        new CreateSuperAdmin('owner@example.test', 'Abood', 'Owner', 'Founder', '1995-01-01', 'SA', PHONE_COUNTRIES_UNLISTED, 'ar'),
    ))],
    'an admin editing a staff member' => [function () {
        $staffId = Fx::staff();
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(UpdateStaffProfileHandler::class)->handle(new UpdateStaffProfile($staffId, 'Staff', 'Member', 'Tester', '1990-01-01', 'SA', 'Riyadh', PHONE_COUNTRIES_UNLISTED, null));
    }],
    'a staff member changing their own' => [function () {
        Fx::actAsStaff(Fx::staff());
        app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange(PHONE_COUNTRIES_UNLISTED, Fx::STAFF_PASSWORD, '10.0.0.1'));
    }],
    'a customer adding theirs' => [function () {
        Fx::actAsCustomer(Fx::customer());
        Fx::inStoreCode('sa', fn () => app(RequestCustomerPhoneCodeHandler::class)->handle(new RequestCustomerPhoneCode(PHONE_COUNTRIES_UNLISTED)));
    }],
]);

it('takes a number from a listed country, Egypt\'s too, as before', function () {
    Fx::actAsCustomer(Fx::customer());
    Fx::inStoreCode('sa', fn () => app(RequestCustomerPhoneCodeHandler::class)->handle(new RequestCustomerPhoneCode('+20 100 123 4567')));

    expect(RecordingSecurityMessages::installed()->codes[0]['phone'])->toBe('+201001234567');
});

it('sends no code to an unlisted number, whoever asks, before any limit is counted', function () {
    $staffId = Fx::staff();
    $customerId = Fx::customer();
    $now = CarbonImmutable::now();

    expect(fn () => DB::transaction(fn () => app(PhoneVerification::class)->send($staffId, PhoneNumber::of(PHONE_COUNTRIES_UNLISTED), PhoneCodePurpose::SignIn, Language::of('en'), $now)))
        ->toThrow(phoneCountriesRefused())
        ->and(fn () => Fx::inStoreCode('sa', fn () => DB::transaction(fn () => app(CustomerPhoneVerification::class)->send($customerId, PhoneNumber::of(PHONE_COUNTRIES_UNLISTED), CustomerPhoneCodePurpose::Add, Language::of('en'), $now))))
        ->toThrow(phoneCountriesRefused())
        ->and(RecordingSecurityMessages::installed()->codes)->toBe([])
        ->and(DB::table('access.staff_sign_in_codes')->where('staff_user_id', $staffId)->exists())->toBeFalse();
});

it('reads a customer\'s verified phone as unverified once its country leaves the list, so they cannot order', function () {
    $customerId = Fx::customer();
    DB::table('access.customers')->where('id', $customerId)->update(['phone' => '+201001234567', 'phone_verified_at' => now(), 'email_verified_at' => now()]);
    $access = app(AccessApi::class);

    expect($access->customer($customerId)?->phoneVerified)->toBeTrue()
        ->and($access->customerMayOrder($customerId))->toBeTrue();

    DB::table('ops.sms_countries')->where('country_code', 'EG')->delete();

    expect(app(AccessApi::class)->customer($customerId)?->phoneVerified)->toBeFalse()
        ->and(app(AccessApi::class)->customers([$customerId])[$customerId]->phoneVerified)->toBeFalse()
        ->and(app(AccessApi::class)->customerMayOrder($customerId))->toBeFalse()
        // The number itself stays on the account until they change it.
        ->and(app(AccessApi::class)->customer($customerId)?->phone)->toBe('+201001234567');
});

it('leaves the address book\'s recipient phones alone: they receive no code', function () {
    // SaveAddress is not asked (owner, 2026-10-29: its own job after this step).
    expect((string) file_get_contents(base_path('src/Modules/Access/Application/Command/SaveAddress/SaveAddressHandler.php')))
        ->not->toContain('ServedPhones');
});
