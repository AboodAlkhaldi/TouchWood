<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\ValueObject\InboxOwner;
use Modules\Ops\Infrastructure\Eloquent\DatabaseInbox;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Infrastructure\Messages\SecurityEmailAlert;
use Modules\Platform\Public\Contracts\PlatformApi;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;

use function Pest\Laravel\seed;

/*
| Two security emails failing at once (ops.md §1.6 S10): the second waits on the first's claim of the hour,
| then finds it taken, so a Super Admin never gets two entries. The second connection gives up on its wait
| (lock_timeout), which shows it waited.
|
| Outside RefreshDatabase's transaction (lesson 87), as Feedback's race tests: it commits its own rows and
| empties the database before and after itself; skipped under --parallel.
*/

function securityAlertRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

function securityAlertRaceOn(Connection $db, Inbox $inbox): SecurityEmailAlert
{
    return new SecurityEmailAlert($db, new HandledEvents($db), app(AccessApi::class), app(PlatformApi::class), $inbox, app(Translator::class));
}

beforeEach(function () {
    if (securityAlertRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    config(['database.connections.security_alert_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (securityAlertRaceInParallel()) {
        return;
    }

    DB::connection('security_alert_race')->disconnect();
    // The store directory read during the test is cached: the wipe must not meet the stores it removes.
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('writes after a real commit, as Access sends, and leaves nothing behind a real rollback', function () {
    $super = Fx::staff(superAdmin: true);
    $person = app(AccessApi::class)->staff(Fx::staff()) ?? throw new LogicException('No staff member.');
    CapturedEmails::install()->fails = true;
    $send = static fn () => app(SecurityMessages::class)->staffInvitation($person, 'https://example.test/admin/invitation/abc');

    // A rollback takes the failed email's row with it, and leaves the hour free.
    expect(static fn () => DB::transaction($send))->toThrow(ProviderFailed::class)
        ->and(DB::table('ops.inbox_entries')->count())->toBe(0)
        ->and(DB::table('ops.handled_events')->count())->toBe(0);

    // Access's own shape: the send after its commit, its failure thrown out of that commit.
    expect(static fn () => DB::transaction(static fn () => DB::afterCommit($send)))->toThrow(ProviderFailed::class)
        ->and(DB::table('ops.inbox_entries')->where('kind', 'security_emails_failing')->pluck('staff_id')->all())->toBe([$super])
        ->and(DB::table('ops.sent_messages')->where('status', 'FAILED')->count())->toBe(1);
})->skip(fn (): bool => securityAlertRaceInParallel(), 'Commits its own rows: not under --parallel.');

it('writes one entry when a second failure comes while the first is writing', function () {
    $super = Fx::staff(superAdmin: true);

    $first = DB::connection();
    $second = DB::connection('security_alert_race');
    $second->statement("SET lock_timeout = '500ms'");

    // The moment the first holds its claim, uncommitted, the second failure asks for the hour on its own
    // connection. Its claim directly: one process shares the after-commit callbacks of every connection, so
    // emailFailed here would wait for the first's commit, which two requests never do.
    $inbox = new class($first, new HandledEvents($second)) implements Inbox
    {
        public ?QueryException $waited = null;

        public function __construct(private ConnectionInterface $db, private HandledEvents $other) {}

        public function add(InboxEntry $entry): void
        {
            try {
                $this->other->once(SecurityEmailAlert::onceId(CarbonImmutable::now()), SecurityEmailAlert::class, static fn () => throw new LogicException('The hour is taken.'));
            } catch (QueryException $timedOut) {
                $this->waited = $timedOut;
            }

            (new DatabaseInbox($this->db))->add($entry);
        }

        public function markRead(InboxOwner $owner, string $entryId, DateTimeImmutable $at): bool
        {
            return false;
        }

        public function markAllRead(InboxOwner $owner, DateTimeImmutable $at): int
        {
            return 0;
        }
    };

    securityAlertRaceOn($first, $inbox)->emailFailed(null);

    // It stopped on the first's claim and gave up (55P03).
    expect($inbox->waited?->getCode())->toBe('55P03')
        ->and((string) $inbox->waited?->getSql())->toContain('handled_events')
        ->and(DB::table('ops.inbox_entries')->where('kind', 'security_emails_failing')->pluck('staff_id')->all())->toBe([$super]);

    // Once the first has committed, the second finds the hour taken and writes nothing.
    securityAlertRaceOn($second, new DatabaseInbox($second))->emailFailed(null);

    expect(DB::table('ops.inbox_entries')->where('kind', 'security_emails_failing')->count())->toBe(1)
        ->and(DB::table('ops.handled_events')->count())->toBe(1);
})->skip(fn (): bool => securityAlertRaceInParallel(), 'Commits its own rows: not under --parallel.');
