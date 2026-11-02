<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Query\ViewInbox\ViewInbox;
use Modules\Ops\Application\Query\ViewInbox\ViewInboxHandler;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\ValueObject\InboxOwner;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\RecordingSmsSenders;

use function Pest\Laravel\seed;

/*
| S10, S10b (ops.md §1.6; owner, 2026-11-01): a security email or SMS that fails puts one bell entry in each
| active Super Admin's inbox, at most once an hour per channel, whatever their switches, and never touches the
| request of the person whose message failed. Through the real OpsSecurityMessages, with providers that refuse.
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

/** One security SMS that fails, as the person's request sees it. */
function securityAlertFailSms(): void
{
    securityAlertSms()->failing();

    expect(fn () => app(SecurityMessages::class)->staffSignInCode('+966501234567', 'en', '482913'))->toThrow(ProviderFailed::class);
}

/**
 * The alert's entries of one kind, every column of each.
 *
 * @return list<array<string, mixed>>
 */
function securityAlertEntries(string $kind = 'security_email_failed'): array
{
    return array_values(array_map(static fn (object $row): array => (array) $row, DB::table('ops.inbox_entries')->where('kind', $kind)->orderBy('created_at')->orderBy('staff_id')->get()->all()));
}

/** @return list<string> */
function securityAlertOwners(string $kind = 'security_email_failed'): array
{
    return array_column(securityAlertEntries($kind), 'staff_id');
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

it('costs a failure after the first two statements of its own', function () {
    Fx::staff(superAdmin: true);
    securityAlertFail();
    DB::flushQueryLog();
    DB::enableQueryLog();

    securityAlertFail();

    $queries = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
    DB::disableQueryLog();

    // Measured 2026-11-01: the FAILED row, the wait's limit, and the claim that finds the hour taken.
    expect($queries)->toHaveCount(3)
        ->and($queries[0])->toContain('sent_messages')
        ->and($queries[1])->toContain('lock_timeout')
        ->and($queries[2])->toContain('handled_events');
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

it('says one could not be sent and what to check if it repeats, in both languages, naming nothing of the message', function (Closure $fail, string $kind, array $words) {
    Fx::staff(superAdmin: true);

    $fail();

    $entry = securityAlertEntries($kind)[0];
    $written = json_encode($entry, JSON_UNESCAPED_UNICODE);

    expect([$entry['title_en'], $entry['line_en'], $entry['title_ar'], $entry['line_ar']])->toBe($words)
        ->and($entry['link'])->toBeNull()
        ->and($entry['read_at'])->toBeNull()
        ->and($written)->not->toContain('Omar')
        ->and($written)->not->toContain(securityAlertPerson()->email)
        ->and($written)->not->toContain('secret-token')
        ->and($written)->not->toContain('example.test')
        ->and($written)->not->toContain('482913')
        ->and($written)->not->toContain('501234567');
})->with([
    'an email' => [securityAlertFail(...), 'security_email_failed', [
        'A security email could not be sent',
        'If this repeats, check the email settings and the log.',
        'تعذّر إرسال رسالة أمان بالبريد الإلكتروني',
        'إذا تكرر هذا، فراجع إعدادات البريد الإلكتروني والسجل.',
    ]],
    'an SMS' => [securityAlertFailSms(...), 'security_sms_failed', [
        'A security SMS could not be sent',
        'If this repeats, check the SMS settings and the log.',
        'تعذّر إرسال رسالة أمان نصية',
        'إذا تكرر هذا، فراجع إعدادات الرسائل النصية والسجل.',
    ]],
]);

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
        ->and($entries[0]->title)->toBe('A security email could not be sent')
        ->and($entries[0]->link)->toBeNull();
});

it('reaches a Super Admin who switched every topic off, and still only by the bell', function () {
    $super = Fx::staff(superAdmin: true);

    foreach (StaffNotificationTopic::cases() as $topic) {
        DB::table('access.staff_notification_preferences')->insert(['staff_user_id' => $super, 'topic' => $topic->value, 'email' => $topic === StaffNotificationTopic::PaymentProblems, 'panel' => false]);
    }

    securityAlertFail();
    securityAlertFailSms();

    expect(securityAlertOwners())->toBe([$super])
        ->and(securityAlertOwners('security_sms_failed'))->toBe([$super])
        ->and(CapturedEmails::installed()->sent)->toBe([])
        ->and(securityAlertSms()->sent)->toBe([]);
    Queue::assertNothingPushed();
});

it('reaches no Super Admin who is not active', function () {
    $active = Fx::staff(superAdmin: true);
    Fx::staff(StaffStatus::Disabled, superAdmin: true);

    securityAlertFail();

    expect(securityAlertOwners())->toBe([$active]);
});

it('files the entry under the first store when it is switched off, and it shows in the bell', function () {
    $super = Fx::staff(superAdmin: true);
    $first = Fx::storeId('eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    // The base store cannot be off, so another store is put first.
    DB::table('platform.stores')->where('id', $first)->update(['position' => (int) DB::table('platform.stores')->min('position') - 1]);
    Cache::flush();
    expect(app(PlatformApi::class)->allStores()[0]->id)->toBe($first)
        ->and(app(PlatformApi::class)->stores()[0]->id)->not->toBe($first);

    securityAlertFail();

    expect(securityAlertEntries()[0]['store_id'])->toBe($first);
    Fx::actAsStaff($super);
    expect(app(ViewInboxHandler::class)->handle(new ViewInbox('en'))->rows)->toHaveCount(1);
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

it('writes nothing when the email goes out', function () {
    Fx::staff(superAdmin: true);
    CapturedEmails::installed()->fails = false;

    app(SecurityMessages::class)->staffInvitation(securityAlertPerson(), 'https://example.test/admin/invitation/abc');

    expect(securityAlertEntries())->toBe([]);
});

describe('a failed SMS', function () {
    it('gives each Super Admin one bell entry for a burst, and sends no SMS or email about itself', function () {
        $super = Fx::staff(superAdmin: true);
        Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin);

        for ($i = 0; $i < 5; $i++) {
            securityAlertFailSms();
        }

        expect(securityAlertOwners('security_sms_failed'))->toBe([$super])
            ->and(securityAlertEntries())->toBe([])
            ->and(securityAlertSms()->sent)->toBe([])
            ->and(CapturedEmails::installed()->sent)->toBe([])
            ->and(DB::table('ops.sent_messages')->where('kind', 'security')->where('channel', 'SMS')->where('status', 'FAILED')->count())->toBe(5)
            ->and(DB::table('ops.sent_messages')->count())->toBe(5);
        Queue::assertNothingPushed();
    });

    it('has its own hour: an SMS and an email failing in one hour each tell them, and neither again until the next', function () {
        $super = Fx::staff(superAdmin: true);

        securityAlertFail();
        securityAlertFailSms();
        securityAlertFail();
        securityAlertFailSms();

        expect(securityAlertOwners())->toBe([$super])
            ->and(securityAlertOwners('security_sms_failed'))->toBe([$super]);

        CarbonImmutable::setTestNow('2026-11-01 11:10:00');
        securityAlertFailSms();

        expect(securityAlertOwners())->toBe([$super])
            ->and(securityAlertOwners('security_sms_failed'))->toBe([$super, $super]);
    });
});

describe('a failure writing the alert', function () {
    it('never reaches the caller: a database refusal is reported, the email\'s own failure goes on as it did', function () {
        $super = Fx::staff(superAdmin: true);
        Exceptions::fake();
        // A real refusal from the database, not a stand-in.
        DB::statement("ALTER TABLE ops.inbox_entries ADD CONSTRAINT refuse_the_alert CHECK (kind <> 'security_email_failed')");

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

        Exceptions::assertReported(static fn (RuntimeException $failed): bool => $failed->getMessage() === 'The inbox is away.');
        expect(securityAlertEntries())->toBe([])
            // The claim went with the write.
            ->and(DB::table('ops.handled_events')->count())->toBe(0)
            ->and(DB::table('ops.sent_messages')->where('status', 'FAILED')->count())->toBe(1);
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
