<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccounts;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccountsHandler;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomer;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomerHandler;
use Modules\Access\Application\Command\SetOwnMarketingConsent\SetOwnMarketingConsent;
use Modules\Access\Application\Command\SetOwnMarketingConsent\SetOwnMarketingConsentHandler;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfile;
use Modules\Access\Application\Command\UpdateCustomerProfile\UpdateCustomerProfileHandler;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\MarketingConsentSource;
use Modules\Access\Public\Events\CustomerLocaleChanged;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Marketing consent (amendment 79) and new customers per day (amendment 83), for Ops: off unless the
| customer ticks it, changed only by the customer or withdrawn by an unsubscribe the system receives,
| announced by id, cleared by anonymizing.
*/

uses(RefreshDatabase::class);

/** Ops' kind of job, here so Access is tested alone: queued by a request, it runs as the system on the requester's behalf. */
final class MarketingConsentTestWithdrawJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $customerId) {}

    public function handle(AccessApi $access): void
    {
        $access->withdrawMarketingConsent($this->customerId, MarketingConsentSource::UnsubscribeLink);
    }
}

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function consentRegister(string $email, bool $consent): string
{
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

    return Fx::inStoreCode('sa', fn (): string => app(RegisterCustomerHandler::class)->handle(
        new RegisterCustomer($email, 'a long enough password', 'Sara', 'Ali', 'individual', 'en', true, marketingConsent: $consent),
    ));
}

/**
 * @return array<string, mixed>
 */
function consentRow(string $customerId): array
{
    return (array) DB::table('access.customers')->where('id', $customerId)
        ->first(['marketing_consent', 'marketing_consent_source', 'marketing_consent_changed_at']);
}

describe('at registration', function () {
    it('is off unless the box is ticked, and only a ticked box is announced', function () {
        Event::fake([MarketingConsentChanged::class]);

        $off = consentRegister('off@example.test', false);
        $on = consentRegister('on@example.test', true);

        expect(consentRow($off))->toBe(['marketing_consent' => false, 'marketing_consent_source' => null, 'marketing_consent_changed_at' => null])
            ->and(consentRow($on)['marketing_consent'])->toBeTrue()
            ->and(consentRow($on)['marketing_consent_source'])->toBe('REGISTRATION')
            ->and(app(AccessApi::class)->customer($on)?->marketingConsent)->toBeTrue()
            ->and(app(AccessApi::class)->customer($off)?->marketingConsent)->toBeFalse();

        Event::assertDispatchedTimes(MarketingConsentChanged::class, 1);
        Event::assertDispatched(MarketingConsentChanged::class, fn (MarketingConsentChanged $e): bool => $e->customerId === $on);
    });

    it('keeps the choice out of the audit log, which outlives the account: a change is recorded as changed only', function () {
        $on = consentRegister('on@example.test', true);

        $changes = json_decode((string) DB::table('platform.audit_entries')
            ->where('action', 'access.customer.registered')->where('subject_id', $on)->value('changes'), true);

        expect($changes)->not->toHaveKey('marketing_consent');

        Fx::actAsCustomer($on);
        app(SetOwnMarketingConsentHandler::class)->handle(new SetOwnMarketingConsent(false));
        $change = json_decode((string) DB::table('platform.audit_entries')
            ->where('action', 'access.customer.marketing_consent_changed')->where('subject_id', $on)->value('changes'), true);

        $written = (string) json_encode($change);

        expect($written)->toContain('marketing_consent')
            ->and(str_contains($written, 'true') || str_contains($written, 'false'))->toBeFalse();
    });
});

describe('in their own account', function () {
    it('turns on and off, audited and announced each time, and the same answer again changes nothing', function () {
        $id = Fx::customer();
        Fx::actAsCustomer($id);
        Event::fake([MarketingConsentChanged::class]);

        app(SetOwnMarketingConsentHandler::class)->handle(new SetOwnMarketingConsent(true));
        expect(consentRow($id)['marketing_consent'])->toBeTrue()
            ->and(consentRow($id)['marketing_consent_source'])->toBe('ACCOUNT');

        app(SetOwnMarketingConsentHandler::class)->handle(new SetOwnMarketingConsent(true));
        app(SetOwnMarketingConsentHandler::class)->handle(new SetOwnMarketingConsent(false));

        expect(consentRow($id)['marketing_consent'])->toBeFalse()
            ->and(Fx::audits('access.customer.marketing_consent_changed', $id))->toBe(2);
        Event::assertDispatchedTimes(MarketingConsentChanged::class, 2);
    });

    it('is the customer\'s alone: staff and guests are refused', function () {
        Fx::customer();
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(SetOwnMarketingConsentHandler::class)->handle(new SetOwnMarketingConsent(true)))
            ->toThrow(Unauthorized::class);

        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        expect(fn () => app(SetOwnMarketingConsentHandler::class)->handle(new SetOwnMarketingConsent(true)))
            ->toThrow(Unauthorized::class);
    });

    it('announces a change of language, and only a change', function () {
        $id = Fx::customer();
        Fx::actAsCustomer($id);
        Event::fake([CustomerLocaleChanged::class]);

        app(UpdateCustomerProfileHandler::class)->handle(new UpdateCustomerProfile('Sara', 'Ali', 'ar'));
        app(UpdateCustomerProfileHandler::class)->handle(new UpdateCustomerProfile('Sarah', 'Ali', 'ar'));

        Event::assertDispatchedTimes(CustomerLocaleChanged::class, 1);
    });
});

describe('an unsubscribe Ops received', function () {
    it('withdraws consent as the system, once, whatever the source of the withdrawal', function () {
        $id = consentRegister('on@example.test', true);
        Event::fake([MarketingConsentChanged::class]);

        Fx::asSystem(fn () => app(AccessApi::class)->withdrawMarketingConsent($id, MarketingConsentSource::UnsubscribeLink));
        Fx::asSystem(fn () => app(AccessApi::class)->withdrawMarketingConsent($id, MarketingConsentSource::NewsletterProvider));

        expect(consentRow($id)['marketing_consent'])->toBeFalse()
            ->and(consentRow($id)['marketing_consent_source'])->toBe('UNSUBSCRIBE_LINK')
            ->and(Fx::audits('access.customer.marketing_consent_withdrawn', $id))->toBe(1);
        Event::assertDispatchedTimes(MarketingConsentChanged::class, 1);
    });

    it('withdraws consent in a job that a guest\'s or a customer\'s request queued (as built)', function () {
        $byLink = consentRegister('link@example.test', true);
        $byCustomer = consentRegister('own@example.test', true);

        // The unsubscribe link and the provider's webhook are a guest's requests; a signed-in customer may follow a link too.
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        dispatch(new MarketingConsentTestWithdrawJob($byLink));
        Fx::actAsCustomer($byCustomer);
        dispatch(new MarketingConsentTestWithdrawJob($byCustomer));

        expect(consentRow($byLink)['marketing_consent'])->toBeFalse()
            ->and(consentRow($byCustomer)['marketing_consent'])->toBeFalse()
            ->and(DB::table('platform.audit_entries')->where('action', 'access.customer.marketing_consent_withdrawn')->where('subject_id', $byLink)->value('requested_by_type'))->toBe('GUEST');
    });

    it('is refused to a job a staff member queued: staff never change consent', function () {
        $id = consentRegister('on@example.test', true);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => dispatch(new MarketingConsentTestWithdrawJob($id)))->toThrow(Unauthorized::class)
            ->and(consentRow($id)['marketing_consent'])->toBeTrue();
    });

    it('is refused to a guest\'s own request, outside a job', function () {
        $id = consentRegister('on@example.test', true);
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => app(AccessApi::class)->withdrawMarketingConsent($id, MarketingConsentSource::UnsubscribeLink))->toThrow(Unauthorized::class)
            ->and(consentRow($id)['marketing_consent'])->toBeTrue();
    });

    it('is refused to a Super Admin, who holds every reserved permission but is not the system', function () {
        $id = consentRegister('on@example.test', true);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(AccessApi::class)->withdrawMarketingConsent($id, MarketingConsentSource::UnsubscribeLink))
            ->toThrow(Unauthorized::class)
            ->and(consentRow($id)['marketing_consent'])->toBeTrue();
    });

    it('changes nothing on an anonymized account, without an error', function () {
        $id = consentRegister('on@example.test', true);
        DB::table('access.customers')->where('id', $id)->update(['deletion_scheduled_for' => now()->subMinute()]);
        Fx::asSystem(fn () => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));

        Fx::asSystem(fn () => app(AccessApi::class)->withdrawMarketingConsent($id, MarketingConsentSource::UnsubscribeLink));

        expect(Fx::audits('access.customer.marketing_consent_withdrawn', $id))->toBe(0);
    });

    it('never gives consent, is refused to anyone but the system, and ignores an unknown id', function () {
        $id = Fx::customer();

        expect(fn () => Fx::asSystem(fn () => app(AccessApi::class)->withdrawMarketingConsent($id, MarketingConsentSource::Account)))
            ->toThrow(InvalidAccessAttribute::class);

        Fx::actAsCustomer($id);
        expect(fn () => app(AccessApi::class)->withdrawMarketingConsent($id, MarketingConsentSource::UnsubscribeLink))
            ->toThrow(Unauthorized::class);

        Fx::asSystem(fn () => app(AccessApi::class)->withdrawMarketingConsent('01k6abcdefghjkmnpqrstvwxyz', MarketingConsentSource::UnsubscribeLink));
        expect(true)->toBeTrue();
    });
});

it('is cleared when the account is anonymized', function () {
    $id = consentRegister('on@example.test', true);
    DB::table('access.customers')->where('id', $id)->update(['deletion_scheduled_for' => now()->subMinute()]);

    Fx::asSystem(fn () => app(AnonymizeDueAccountsHandler::class)->handle(new AnonymizeDueAccounts));

    expect(consentRow($id))->toBe(['marketing_consent' => false, 'marketing_consent_source' => null, 'marketing_consent_changed_at' => null]);
});

it('refuses a source without its date, an unknown source, or consent on from an unsubscribe, in the table itself', function () {
    $id = Fx::customer();

    expect(fn () => DB::table('access.customers')->where('id', $id)->update(['marketing_consent_source' => 'ACCOUNT']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('access.customers')->where('id', $id)->update(['marketing_consent_source' => 'STAFF', 'marketing_consent_changed_at' => now()]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('access.customers')->where('id', $id)->update(['marketing_consent' => true, 'marketing_consent_source' => 'UNSUBSCRIBE_LINK', 'marketing_consent_changed_at' => now()]))
        ->toThrow(QueryException::class);
});

describe('new customers per day (amendment 83)', function () {
    it('counts a Riyadh day exactly, midnight to midnight, each type, anonymized included, other stores left out', function () {
        $sa = StoreId::fromString(Fx::storeId('sa'));
        // Riyadh's 2026-10-28 is 2026-10-27 21:00 to 2026-10-28 21:00 in UTC.
        $day = CarbonImmutable::parse('2026-10-28 00:00:00', 'Asia/Riyadh');

        $at = [
            'first' => Fx::customer('a@example.test'),
            'last' => Fx::customer('b@example.test'),
            'company' => Fx::customer('c@example.test', 'sa', 'company'),
            'before' => Fx::customer('d@example.test'),
            'after' => Fx::customer('e@example.test'),
            'elsewhere' => Fx::customer('f@example.test', 'eg'),
        ];
        $when = [
            'first' => '2026-10-27 21:00:00+00', 'last' => '2026-10-28 20:59:59.999+00', 'company' => '2026-10-28 06:00:00+00',
            'before' => '2026-10-27 20:59:59.999+00', 'after' => '2026-10-28 21:00:00+00', 'elsewhere' => '2026-10-28 06:00:00+00',
        ];

        foreach ($at as $name => $id) {
            DB::statement('UPDATE access.customers SET created_at = ?::timestamptz WHERE id = ?', [$when[$name], $id]);
        }

        DB::table('access.customers')->where('id', $at['last'])->update(['anonymized_at' => now()]);

        expect(app(AccessApi::class)->customersRegistered($sa, $day, $day->addDay()))->toBe(['individuals' => 2, 'companies' => 1])
            ->and(fn () => app(AccessApi::class)->customersRegistered($sa, $day, $day))->toThrow(InvalidArgumentException::class);
    });
});
