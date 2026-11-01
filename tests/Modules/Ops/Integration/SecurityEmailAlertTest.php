<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Query\ViewInbox\ViewInbox;
use Modules\Ops\Application\Query\ViewInbox\ViewInboxHandler;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\ValueObject\InboxOwner;
use Modules\Ops\Infrastructure\Messages\OpsSecurityMessages;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\RecordingSmsSenders;

use function Pest\Laravel\seed;

/*
| S10 (ops.md §1.6; owner, 2026-11-01): a security email that fails puts one bell entry in each Super Admin's
| inbox, at most once an hour, and never touches the request of the person whose email failed. Through the
| real OpsSecurityMessages, with an email provider that refuses.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    CarbonImmutable::setTestNow('2026-11-01 10:10:00');
    Queue::fake();
    CapturedEmails::install()->fails = true;
    app()->instance('securityAlertSms', RecordingSmsSenders::install());
    $person = app(AccessApi::class)->staff(Fx::staff(firstName: 'Omar')) ?? throw new LogicException('No staff member.');
    app()->instance('securityAlertPerson', $person);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The staff member whose invitation fails: an ordinary active account, nobody the alert is for. */
function securityAlertPerson(): StaffDto
{
    $person = app('securityAlertPerson');
    assert($person instanceof StaffDto);

    return $person;
}

function securityAlertSms(): RecordingSmsSenders
{
    $sms = app('securityAlertSms');
    assert($sms instanceof RecordingSmsSenders);

    return $sms;
}

/** One security email that fails, as the person's request sees it. */
function securityAlertFail(): void
{
    expect(fn () => app(SecurityMessages::class)->staffInvitation(securityAlertPerson(), 'https://example.test/admin/invitation/secret-token'))
        ->toThrow(ProviderFailed::class, 'Brevo');
}

/**
 * The alert's entries, every column of each.
 *
 * @return list<array<string, mixed>>
 */
function securityAlertEntries(): array
{
    return array_values(array_map(static fn (object $row): array => (array) $row, DB::table('ops.inbox_entries')->where('kind', 'security_emails_failing')->orderBy('created_at')->orderBy('staff_id')->get()->all()));
}

/** @return list<string> */
function securityAlertOwners(): array
{
    return array_column(securityAlertEntries(), 'staff_id');
}

it('gives each Super Admin one bell entry for a burst of failures, and nobody an email or an SMS', function () {
    $first = Fx::staff(superAdmin: true);
    $second = Fx::staff(superAdmin: true);
    $wanted = [$first, $second];
    sort($wanted);

    for ($i = 0; $i < 25; $i++) {
        securityAlertFail();
    }

    expect(securityAlertOwners())->toBe($wanted)
        ->and(CapturedEmails::installed()->sent)->toBe([])
        ->and(securityAlertSms()->sent)->toBe([])
        ->and(DB::table('ops.sent_messages')->where('kind', 'security')->where('status', 'FAILED')->count())->toBe(25)
        // Only the failed emails' rows: nothing is recorded as sent for the alert.
        ->and(DB::table('ops.sent_messages')->count())->toBe(25);
    Queue::assertNothingPushed();
});

it('costs a failure after the first one statement of its own', function () {
    Fx::staff(superAdmin: true);
    securityAlertFail();
    DB::flushQueryLog();
    DB::enableQueryLog();

    securityAlertFail();

    $queries = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
    DB::disableQueryLog();

    // Measured 2026-11-01: the FAILED row, and the claim that finds the hour taken.
    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toContain('sent_messages')
        ->and($queries[1])->toContain('handled_events');
});

it('holds to one entry within the clock hour, and writes the next in the next', function () {
    $super = Fx::staff(superAdmin: true);

    securityAlertFail();
    CarbonImmutable::setTestNow('2026-11-01 10:59:59');
    securityAlertFail();
    expect(securityAlertOwners())->toBe([$super]);

    CarbonImmutable::setTestNow('2026-11-01 11:10:00');
    securityAlertFail();
    expect(securityAlertOwners())->toBe([$super, $super]);
});

it('says only that security emails are not going out, in both languages', function () {
    Fx::staff(superAdmin: true);

    securityAlertFail();

    $entry = securityAlertEntries()[0];
    $words = json_encode($entry, JSON_UNESCAPED_UNICODE);

    expect($entry['title_en'])->toBe('Security emails are not going out')
        ->and($entry['line_en'])->toBe("Security emails, such as verification, invitations and password resets, could not be sent. Check the email provider's settings.")
        ->and($entry['title_ar'])->toBe('رسائل الأمان لا تُرسل')
        ->and($entry['line_ar'])->toBe('تعذّر إرسال رسائل الأمان بالبريد الإلكتروني، مثل التأكيد والدعوات وإعادة تعيين كلمة المرور. راجع إعدادات مزوّد البريد.')
        ->and($entry['link'])->toBeNull()
        ->and($entry['read_at'])->toBeNull()
        // Nothing of the message: no person, no address, no link, no token.
        ->and($words)->not->toContain('Omar')
        ->and($words)->not->toContain(securityAlertPerson()->email)
        ->and($words)->not->toContain('secret-token')
        ->and($words)->not->toContain('example.test');
});

it('reaches only Super Admins: no Admin, however many of Ops\' permissions they hold', function () {
    $super = Fx::staff(superAdmin: true);
    Fx::staffWith(['ops.notice.manage', 'ops.message.send_many', 'ops.report.daily'], ['sa', 'ae'], RoleLevel::Admin);

    securityAlertFail();

    expect(securityAlertOwners())->toBe([$super]);
});

it('shows in the Super Admin\'s bell with nowhere to go', function () {
    $super = Fx::staff(superAdmin: true);
    securityAlertFail();
    Fx::actAsStaff($super);

    $entries = app(ViewInboxHandler::class)->handle(new ViewInbox('en'))->rows;

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->title)->toBe('Security emails are not going out')
        ->and($entries[0]->link)->toBeNull();
});

it('follows the Super Admin\'s in-panel switch for Payment problems, as S5 and S5b do', function () {
    $off = Fx::staff(superAdmin: true);
    $on = Fx::staff(superAdmin: true);
    DB::table('access.staff_notification_preferences')->insert(['staff_user_id' => $off, 'topic' => 'PAYMENT_PROBLEMS', 'email' => true, 'panel' => false]);

    securityAlertFail();

    expect(securityAlertOwners())->toBe([$on])
        // Their email switch on, and still no email: the bell alone.
        ->and(CapturedEmails::installed()->sent)->toBe([]);
    Queue::assertNothingPushed();
});

it('files the entry under the failed email\'s store, and under the first store when it has none', function () {
    Fx::staff(superAdmin: true);
    // Registering sends the customer's own verification email: this one goes out.
    CapturedEmails::installed()->fails = false;
    $customer = app(AccessApi::class)->customer(Fx::customer('holder@example.test')) ?? throw new LogicException('No customer.');
    CapturedEmails::installed()->fails = true;

    expect(fn () => app(SecurityMessages::class)->companyRejected($customer, 'Missing papers', Fx::storeId('ae')))->toThrow(ProviderFailed::class);
    expect(securityAlertEntries()[0]['store_id'])->toBe(Fx::storeId('ae'));

    CarbonImmutable::setTestNow('2026-11-01 11:10:00');
    securityAlertFail();

    expect(securityAlertEntries()[1]['store_id'])->toBe(Fx::storeId('sa'));
});

it('writes nothing when the email goes out, or when an SMS fails', function () {
    Fx::staff(superAdmin: true);
    CapturedEmails::installed()->fails = false;

    app(SecurityMessages::class)->staffInvitation(securityAlertPerson(), 'https://example.test/admin/invitation/abc');
    securityAlertSms()->failing();
    expect(fn () => app(SecurityMessages::class)->staffSignInCode('+966501234567', 'en', '482913'))->toThrow(ProviderFailed::class);

    expect(securityAlertEntries())->toBe([]);
});

describe('a failure writing the alert', function () {
    it('never reaches the caller: a database refusal is reported, the email\'s own failure goes on as it did', function () {
        $super = Fx::staff(superAdmin: true);
        Exceptions::fake();
        // A real refusal from the database, not a stand-in.
        DB::statement("ALTER TABLE ops.inbox_entries ADD CONSTRAINT refuse_the_alert CHECK (kind <> 'security_emails_failing')");

        securityAlertFail();

        Exceptions::assertReported(static fn (QueryException $failed): bool => str_contains($failed->getMessage(), 'refuse_the_alert'));
        expect(securityAlertEntries())->toBe([])
            // The connection still works, and the FAILED row stands.
            ->and(DB::table('ops.sent_messages')->where('status', 'FAILED')->count())->toBe(1);

        // The claim went with the write, so the next failure tries again.
        DB::statement('ALTER TABLE ops.inbox_entries DROP CONSTRAINT refuse_the_alert');
        securityAlertFail();
        expect(securityAlertOwners())->toBe([$super]);
    });

    it('never reaches the caller whatever breaks', function () {
        Fx::staff(superAdmin: true);
        Exceptions::fake();
        app()->instance(Inbox::class, new class implements Inbox
        {
            public function add(InboxEntry $entry): void
            {
                throw new RuntimeException('The inbox is away.');
            }

            public function markRead(InboxOwner $owner, string $entryId, DateTimeImmutable $at): bool
            {
                return false;
            }

            public function markAllRead(InboxOwner $owner, DateTimeImmutable $at): int
            {
                return 0;
            }
        });

        securityAlertFail();

        Exceptions::assertReported(RuntimeException::class);
        expect(app(SecurityMessages::class))->toBeInstanceOf(OpsSecurityMessages::class);
    });
});

describe('a caller with a transaction open', function () {
    it('writes nothing until the caller commits', function () {
        $super = Fx::staff(superAdmin: true);

        DB::transaction(function (): void {
            securityAlertFail();
            expect(securityAlertEntries())->toBe([]);
        });

        expect(securityAlertOwners())->toBe([$super]);
    });

    it('leaves no claim when the caller rolls back, so the next failure still alerts', function () {
        $super = Fx::staff(superAdmin: true);

        DB::beginTransaction();
        securityAlertFail();
        DB::rollBack();

        expect(securityAlertEntries())->toBe([])
            ->and(DB::table('ops.handled_events')->count())->toBe(0);

        securityAlertFail();
        expect(securityAlertOwners())->toBe([$super]);
    });
});
