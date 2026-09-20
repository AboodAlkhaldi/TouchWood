<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomer;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomerHandler;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfile;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfileHandler;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\EmailAlreadyRegistered;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\PasswordTooWeak;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Events\CustomerRegistered;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

const CUSTOMER_PASSWORD = 'a long enough password';

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * @return array<string, mixed>
 */
function customerRow(string $customerId): array
{
    return (array) DB::table('access.customers')->where('id', $customerId)->first();
}

function customerRegister(string $email = 'sara@example.test', string $storeCode = 'sa', string $accountType = 'individual', string $password = CUSTOMER_PASSWORD, string $locale = 'en', bool $terms = true, string $firstName = 'Sara', string $lastName = 'Ali'): string
{
    return Fx::inStoreCode($storeCode, fn (): string => app(RegisterCustomerHandler::class)->handle(
        new RegisterCustomer($email, $password, $firstName, $lastName, $accountType, $locale, $terms),
    ));
}

describe('registering (spec §1.2)', function () {
    it('creates an active account in the store the visitor is in, unverified, and emails the link', function () {
        Event::fake([CustomerRegistered::class]);
        Fx::actAs(Actor::guest('01k5n0v9m1t8q7r6s5w4x3y2z1'));

        $customerId = customerRegister();
        $row = customerRow($customerId);
        $verification = RecordingSecurityMessages::installed()->emailVerifications[0] ?? null;

        expect($row['status'])->toBe('ACTIVE')
            ->and($row['account_type'])->toBe('INDIVIDUAL')
            ->and($row['email'])->toBe('sara@example.test')
            ->and($row['password'])->not->toBe(CUSTOMER_PASSWORD)
            ->and($row['email_verified_at'])->toBeNull()
            ->and($row['phone'])->toBeNull()
            ->and($row['home_store_id'])->toBe(Fx::storeId('sa'))
            ->and($row['last_store_id'])->toBe(Fx::storeId('sa'))
            ->and($row['terms_version'])->toBe('2026-01')
            ->and($row['terms_accepted_at'])->not->toBeNull()
            ->and($verification['to'] ?? null)->toBe('sara@example.test')
            ->and($verification['link'] ?? '')->toStartWith(config('app.url').'/sa/en/account/verify-email/'.$customerId)
            ->and($verification['link'] ?? '')->toContain('signature=');

        Event::assertDispatched(CustomerRegistered::class, fn (CustomerRegistered $event): bool => $event->customerId === $customerId
            && $event->accountType === AccountType::Individual
            && $event->storeId === Fx::storeId('sa'));
    });

    it('audits the registration with the personal fields marked as changed only', function () {
        $customerId = customerRegister();
        $entry = DB::table('platform.audit_entries')->where('action', 'access.customer.registered')->first();

        expect($entry?->subject_id)->toBe($customerId)
            ->and($entry?->store_id)->toBe(Fx::storeId('sa'))
            // PostgreSQL keeps jsonb keys in its own order, so the comparison ignores it.
            ->and(json_decode((string) $entry?->changes, true))->toEqualCanonicalizing([
                'first_name' => 'changed',
                'last_name' => 'changed',
                'email' => 'changed',
                'account_type' => [null, 'INDIVIDUAL'],
                'status' => [null, 'ACTIVE'],
                'locale' => [null, 'en'],
                'terms_version' => [null, '2026-01'],
            ]);
    });

    it('answers an email that already belongs to any account in the same words', function (Closure $take) {
        $take();
        $sent = count(RecordingSecurityMessages::installed()->emailVerifications);

        // A staff address must read exactly like a customer's, or this public form would tell a
        // stranger who works here (owner, 2026-09-20).
        expect(fn () => customerRegister('taken@example.test'))->toThrow(EmailAlreadyRegistered::class)
            ->and(RecordingSecurityMessages::installed()->emailVerifications)->toHaveCount($sent);
    })->with([
        'a customer has it' => [fn () => customerRegister('taken@example.test')],
        'a staff member has it' => [function (): void {
            DB::table('access.staff_users')->where('id', Fx::staff())->update(['email' => 'TAKEN@example.test']);
        }],
    ]);

    it('registers a company account, whose company application belongs to B2B', function () {
        $customerId = customerRegister(accountType: 'company');

        expect(customerRow($customerId)['account_type'])->toBe('COMPANY')
            ->and(app(AccessApi::class)->customer($customerId)?->accountType)->toBe(AccountType::Company);
    });

    it('refuses what the form must never accept', function (Closure $register, string $error) {
        expect($register)->toThrow($error)
            ->and(DB::table('access.customers')->count())->toBe(0);
    })->with([
        'the terms not accepted' => [fn () => customerRegister(terms: false), InvalidAccessAttribute::class],
        'neither an individual nor a company' => [fn () => customerRegister(accountType: 'wholesaler'), InvalidAccessAttribute::class],
        'no first name' => [fn () => customerRegister(firstName: '  '), InvalidAccessAttribute::class],
        'a name too long' => [fn () => customerRegister(lastName: str_repeat('a', 101)), InvalidAccessAttribute::class],
        'not an email address' => [fn () => customerRegister('sara@'), InvalidAccessAttribute::class],
        'a language we do not speak' => [fn () => customerRegister(locale: 'fr'), InvalidAccessAttribute::class],
        'a password under 8 characters' => [fn () => customerRegister(password: 'short'), PasswordTooWeak::class],
        'a leaked password' => [fn () => customerRegister(password: FakeBreachList::LEAKED), PasswordTooWeak::class],
    ]);

    it('records the terms version of the store the customer registered in (amendment 37)', function () {
        Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(
            new UpdateSetting(CustomerSecuritySettings::TERMS_VERSION, 'ae', '2026-09-AE'),
        ));

        $inEmirates = customerRegister('ae@example.test', storeCode: 'ae');
        $inSaudi = customerRegister('sa@example.test');

        expect(customerRow($inEmirates)['terms_version'])->toBe('2026-09-AE')
            ->and(customerRow($inSaudi)['terms_version'])->toBe('2026-01')
            // Each account belongs to the store it registered in, and its link points there.
            ->and(customerRow($inEmirates)['home_store_id'])->toBe(Fx::storeId('ae'))
            ->and(customerRow($inSaudi)['home_store_id'])->toBe(Fx::storeId('sa'))
            ->and(RecordingSecurityMessages::installed()->emailVerifications[0]['link'])->toContain('/ae/en/account/verify-email/')
            ->and(RecordingSecurityMessages::installed()->emailVerifications[1]['link'])->toContain('/sa/en/account/verify-email/');
    });

    it('builds the link on APP_URL, whatever host or scheme the request carries', function () {
        config(['app.url' => 'https://panel.touchwood.test']);
        // As a request carrying "Host: attacker.example" over plain http would leave the generator.
        app('url')->forceRootUrl('http://attacker.example');
        app('url')->forceScheme('http');

        customerRegister();

        // The scheme matters as much as the host: a link signed as http, opened as https, would
        // fail its own signature check.
        expect(RecordingSecurityMessages::installed()->emailVerifications[0]['link'])
            ->toStartWith('https://panel.touchwood.test/sa/en/account/verify-email/');
    });

    it('refuses to build a link when APP_URL is not set, instead of sending a broken one', function () {
        config(['app.url' => '']);

        expect(fn () => customerRegister())->toThrow(LogicException::class, 'APP_URL')
            ->and(RecordingSecurityMessages::installed()->emailVerifications)->toBe([]);
    });

    it('refuses a taken email in code, before the unique index would', function () {
        customerRegister('taken@example.test');
        Fx::withoutCustomerUniqueIndexes();

        expect(fn () => customerRegister('TAKEN@example.test'))->toThrow(EmailAlreadyRegistered::class)
            ->and(DB::table('access.customers')->count())->toBe(1);
    });
});

describe('a customer\'s own profile (spec §3.1)', function () {
    it('changes their name and the language their messages come in', function () {
        $customerId = customerRegister();
        Fx::actAsCustomer($customerId);

        app(UpdateCustomerProfileHandler::class)->handle(new UpdateCustomerProfile('Sarah', 'Alsaleh', 'ar'));

        $row = customerRow($customerId);
        $audit = DB::table('platform.audit_entries')->where('action', 'access.customer.profile_updated')->value('changes');

        expect($row['first_name'])->toBe('Sarah')
            ->and($row['last_name'])->toBe('Alsaleh')
            ->and($row['locale'])->toBe('ar')
            ->and(json_decode((string) $audit, true))->toEqualCanonicalizing([
                'first_name' => 'changed',
                'last_name' => 'changed',
                'locale' => ['en', 'ar'],
            ]);
    });

    it('is only for the customer themselves: a guest or a staff member is refused', function (Closure $act) {
        $customerId = customerRegister();
        $act();

        expect(fn () => app(UpdateCustomerProfileHandler::class)->handle(new UpdateCustomerProfile('Sarah', 'Ali', 'en')))
            ->toThrow(Unauthorized::class)
            ->and(customerRow($customerId)['first_name'])->toBe('Sara');
    })->with([
        'a guest' => [fn () => Fx::actAs(Actor::guest('01k5n0v9m1t8q7r6s5w4x3y2z1'))],
        'a staff member' => [fn () => Fx::actAsStaff(Fx::staff())],
        // The system holds every permission, so only the "who is this?" check can refuse it.
        'the system, from a console command' => [fn () => Fx::actAs(Actor::system())],
    ]);
});

describe('what other modules may ask (spec §2.1)', function () {
    it('answers who a customer is, and whether they may order', function () {
        $customerId = customerRegister();

        expect(app(AccessApi::class)->customer($customerId)?->email)->toBe('sara@example.test')
            ->and(app(AccessApi::class)->customer($customerId)?->emailVerified)->toBeFalse()
            ->and(app(AccessApi::class)->customerMayOrder($customerId))->toBeFalse()
            ->and(app(AccessApi::class)->customer('01k5n0v9m1t8q7r6s5w4x3y2z1'))->toBeNull()
            ->and(app(AccessApi::class)->customerMayOrder('not-an-id'))->toBeFalse();
    });
});
