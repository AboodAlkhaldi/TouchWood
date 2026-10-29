<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCode;
use Modules\Access\Application\Command\RequestCustomerPhoneCode\RequestCustomerPhoneCodeHandler;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhone;
use Modules\Access\Application\Command\VerifyCustomerPhone\VerifyCustomerPhoneHandler;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\CodeRequestTooSoon;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Public\Events\CustomerPhoneVerified;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function signInCodesToNumber(): int
{
    return count(array_filter(
        RecordingSecurityMessages::installed()->codes,
        fn (array $code): bool => $code['phone'] === '+966501234567',
    ));
}

function customerPhoneOf(string $customerId): ?string
{
    $phone = DB::table('access.customers')->where('id', $customerId)->value('phone');

    return $phone === null ? null : (string) $phone;
}

/**
 * A customer asks and answers from a storefront page, so both run in a store: the SMS numbers are
 * that store's settings (spec §1.8).
 */
function customerAskForCode(string $phone): void
{
    Fx::inStoreCode('sa', fn () => app(RequestCustomerPhoneCodeHandler::class)->handle(new RequestCustomerPhoneCode($phone)));
}

function customerEnterCode(?string $code = null): void
{
    Fx::inStoreCode('sa', fn () => app(VerifyCustomerPhoneHandler::class)->handle(
        new VerifyCustomerPhone($code ?? RecordingSecurityMessages::installed()->lastCode()),
    ));
}

describe('a customer\'s phone (spec §1.3)', function () {
    it('adds the first number once its code is right', function () {
        Event::fake([CustomerPhoneVerified::class]);
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);

        customerAskForCode('+966 50 123 4567');

        expect(customerPhoneOf($customerId))->toBeNull()
            ->and(RecordingSecurityMessages::installed()->codes[0]['phone'])->toBe('+966501234567');

        customerEnterCode();

        expect(customerPhoneOf($customerId))->toBe('+966501234567')
            ->and(DB::table('access.customers')->where('id', $customerId)->value('phone_verified_at'))->not->toBeNull()
            ->and(DB::table('access.phone_codes')->count())->toBe(0)
            ->and(Fx::audits('access.customer.phone_verified', $customerId))->toBe(1);

        Event::assertDispatched(CustomerPhoneVerified::class);
    });

    it('keeps the old number live until the new one is verified', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        customerAskForCode('+966501234567');
        customerEnterCode();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        customerAskForCode('+966509999999');

        expect(customerPhoneOf($customerId))->toBe('+966501234567')
            ->and(DB::table('access.phone_codes')->where('customer_id', $customerId)->value('purpose'))->toBe('CHANGE');

        customerEnterCode();

        expect(customerPhoneOf($customerId))->toBe('+966509999999');
    });

    it('refuses a number another customer uses, when it is entered', function () {
        $first = Fx::customer('first@example.test');
        Fx::actAsCustomer($first);
        customerAskForCode('+966501234567');
        customerEnterCode();

        $second = Fx::customer('second@example.test');
        Fx::actAsCustomer($second);

        expect(fn () => customerAskForCode('+966501234567'))->toThrow(PhoneAlreadyInUse::class)
            ->and(RecordingSecurityMessages::installed()->codes)->toHaveCount(1);
    });

    it('refuses in code a number taken while the code was on its way, before the unique index would', function () {
        $first = Fx::customer('first@example.test');
        $second = Fx::customer('second@example.test');
        Fx::actAsCustomer($second);
        customerAskForCode('+966501234567');

        // The other customer takes it meanwhile.
        DB::table('access.customers')->where('id', $first)->update(['phone' => '+966501234567', 'phone_verified_at' => now()]);
        Fx::withoutCustomerUniqueIndexes();

        expect(fn () => customerEnterCode())->toThrow(PhoneAlreadyInUse::class)
            ->and(customerPhoneOf($second))->toBeNull();
    });

    it('counts wrong codes and dies after five, and waits a minute between codes', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        customerAskForCode('+966501234567');
        $code = RecordingSecurityMessages::installed()->lastCode();

        expect(fn () => customerAskForCode('+966501234567'))->toThrow(CodeRequestTooSoon::class);

        foreach (range(1, 5) as $try) {
            expect(fn () => customerEnterCode($code === '000000' ? '111111' : '000000'))->toThrow(InvalidCode::class);
        }

        // The wrong tries were counted and committed, so the right code is refused too.
        expect(fn () => customerEnterCode($code))->toThrow(InvalidCode::class)
            ->and(customerPhoneOf($customerId))->toBeNull()
            ->and(DB::table('access.phone_codes')->where('customer_id', $customerId)->value('attempts'))->toBe(5);
    });

    it('lets a code die after five minutes', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        customerAskForCode('+966501234567');
        $code = RecordingSecurityMessages::installed()->lastCode();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

        expect(fn () => customerEnterCode($code))->toThrow(InvalidCode::class)
            ->and(customerPhoneOf($customerId))->toBeNull();
    });

    it('still takes the code at four minutes', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        customerAskForCode('+966501234567');
        $code = RecordingSecurityMessages::installed()->lastCode();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(4));
        customerEnterCode($code);

        expect(customerPhoneOf($customerId))->toBe('+966501234567');
    });

    it('sends at most 3 codes an hour to one number (amendment 28)', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);

        foreach (range(1, 3) as $sent) {
            customerAskForCode('+966501234567');
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        }

        expect(signInCodesToNumber())->toBe(3)
            ->and(fn () => customerAskForCode('+966501234567'))->toThrow(CodeRequestTooSoon::class);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(61));
        customerAskForCode('+966501234567');

        expect(signInCodesToNumber())->toBe(4);
    });

    it('sends at most 5 codes an hour to one account, whatever the numbers (amendment 85)', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);

        foreach (range(1, 5) as $sent) {
            customerAskForCode('+96650123456'.$sent);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        }

        expect(RecordingSecurityMessages::installed()->codes)->toHaveCount(5)
            ->and(fn () => customerAskForCode('+966501234569'))->toThrow(CodeRequestTooSoon::class);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(61));
        customerAskForCode('+966501234569');

        expect(RecordingSecurityMessages::installed()->codes)->toHaveCount(6);
    });

    it('counts no limit for a refused request (amendment 85)', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);

        foreach (range(1, 3) as $sent) {
            customerAskForCode('+966501234567');
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        }

        // The 4th to the same number is refused by the number's limit; had it counted for the account,
        // the second new number below would be its 5th code and the third its 6th.
        expect(fn () => customerAskForCode('+966501234567'))->toThrow(CodeRequestTooSoon::class);
        customerAskForCode('+966501234561');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        customerAskForCode('+966501234562');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        expect(RecordingSecurityMessages::installed()->codes)->toHaveCount(5)
            ->and(fn () => customerAskForCode('+966501234563'))->toThrow(CodeRequestTooSoon::class);
    });

    it('sends at most the store\'s codes an hour across its customers, a spend guard (amendment 85)', function () {
        $defaults = array_column(array_map(fn ($each) => ['key' => $each->key, 'default' => $each->default], CustomerSecuritySettings::definitions()), 'default', 'key');
        expect($defaults[CustomerSecuritySettings::CODES_PER_STORE_HOUR])->toBe(500)
            ->and($defaults[CustomerSecuritySettings::CODES_PER_ACCOUNT_HOUR])->toBe(5);
        Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(CustomerSecuritySettings::CODES_PER_STORE_HOUR, 'sa', 2)));

        foreach (['first', 'second'] as $at => $name) {
            Fx::actAsCustomer(Fx::customer("{$name}@example.test"));
            customerAskForCode('+96650123456'.$at);
        }

        Fx::actAsCustomer(Fx::customer('third@example.test'));

        expect(fn () => customerAskForCode('+966501234569'))->toThrow(CodeRequestTooSoon::class)
            ->and(RecordingSecurityMessages::installed()->codes)->toHaveCount(2);
    });

    it('follows the account\'s setting (amendment 85)', function () {
        Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(CustomerSecuritySettings::CODES_PER_ACCOUNT_HOUR, 'sa', 1)));
        Fx::actAsCustomer(Fx::customer());
        customerAskForCode('+966501234561');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        expect(fn () => customerAskForCode('+966501234562'))->toThrow(CodeRequestTooSoon::class);
    });

    it('keeps the number itself out of the rate-limit keys', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        customerAskForCode('+966501234567');

        // The cache table holds the limiter's keys, and a backup or a slow query log holds them
        // again: a phone number belongs in neither (review of step 7).
        expect(DB::table('cache')->where('key', 'like', '%966501234567%')->count())->toBe(0)
            ->and(DB::table('cache')->where('key', 'like', '%'.hash('sha256', '+966501234567').'%')->count())->toBeGreaterThan(0);
    });

    it('sends a code to the number the account already has, for a customer who never verified it', function () {
        $customerId = Fx::customer();
        Fx::actAsCustomer($customerId);
        customerAskForCode('+966501234567');
        customerEnterCode();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        // Their own number is not "another customer's": asking again must work.
        customerAskForCode('+966501234567');

        expect(RecordingSecurityMessages::installed()->codes)->toHaveCount(2);
    });

    it('is only for the customer themselves', function () {
        Fx::customer();
        Fx::actAs(Actor::guest('01k5n0v9m1t8q7r6s5w4x3y2z1'));

        expect(fn () => customerAskForCode('+966501234567'))->toThrow(Unauthorized::class)
            ->and(fn () => customerEnterCode('123456'))->toThrow(Unauthorized::class);
    });
});
