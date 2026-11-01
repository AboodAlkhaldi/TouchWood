<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Infrastructure\Messages\SecurityFailureAlert;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Symfony\Component\Process\Process;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;

use function Pest\Laravel\seed;

/*
| Security emails failing at once (ops.md §1.6 S10): a request waits on another's uncommitted claim of the hour
| for a moment at most, then leaves it to them; when the other rolls back, the one waiting takes the claim. A
| Super Admin never gets two entries. The other request is a second connection or a second process; one process
| shares the after-commit callbacks of its connections, so the claim is held behind Laravel's back.
|
| Outside RefreshDatabase's transaction (lesson 87), as Feedback's race tests: it commits its own rows and
| empties the database before and after itself; skipped under --parallel.
*/

function securityAlertRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

function securityAlertRaceOn(Connection $db): SecurityFailureAlert
{
    return new SecurityFailureAlert($db, new HandledEvents($db), app(AccessApi::class), app(PlatformApi::class), app(Inbox::class), app(Translator::class));
}

/** This hour's claim of the email alert, as the alert would take it. */
function securityAlertRaceClaim(): string
{
    return SecurityFailureAlert::onceId(MessageKind::SecurityEmailFailed, CarbonImmutable::now());
}

beforeEach(function () {
    if (securityAlertRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    config(['database.connections.security_alert_race' => config('database.connections.'.config('database.default'))]);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfHour()->addMinutes(10));
});

afterEach(function () {
    if (securityAlertRaceInParallel()) {
        return;
    }

    CarbonImmutable::setTestNow();
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
        ->and(DB::table('ops.inbox_entries')->where('kind', 'security_email_failed')->pluck('staff_id')->all())->toBe([$super])
        ->and(DB::table('ops.sent_messages')->where('status', 'FAILED')->count())->toBe(1);
})->skip(fn (): bool => securityAlertRaceInParallel(), 'Commits its own rows: not under --parallel.');

it('gives up waiting on a claim another holds, reports nothing, and that one still counts', function () {
    Fx::staff(superAdmin: true);
    Exceptions::fake();
    $holder = DB::connection('security_alert_race')->getPdo();
    $holder->beginTransaction();
    $holder->prepare('INSERT INTO ops.handled_events (event_id, listener, handled_at) VALUES (?, ?, now())')->execute([securityAlertRaceClaim(), SecurityFailureAlert::class]);

    $started = microtime(true);
    securityAlertRaceOn(DB::connection())->failed(MessageChannel::Email, null);
    $waited = microtime(true) - $started;

    Exceptions::assertNothingReported();
    expect($waited)->toBeGreaterThan(0.2)->toBeLessThan(2.0)
        ->and(DB::table('ops.inbox_entries')->count())->toBe(0);

    // The other committed: the hour is taken, so a later failure writes nothing.
    $holder->commit();
    securityAlertRaceOn(DB::connection())->failed(MessageChannel::Email, null);

    expect(DB::table('ops.inbox_entries')->count())->toBe(0)
        ->and(DB::table('ops.handled_events')->count())->toBe(1);
})->skip(fn (): bool => securityAlertRaceInParallel(), 'Commits its own rows: not under --parallel.');

it('takes the claim when the request it waited on rolls back', function () {
    $super = Fx::staff(superAdmin: true);
    $db = (array) config('database.connections.'.config('database.default'));
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'], $db['database']);

    // A second process holds the claim until it sees this one blocked on it, then rolls back.
    $holder = new Process([PHP_BINARY, '-r', <<<'PHP'
        [, $dsn, $user, $password, $claim, $listener] = $argv;
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO ops.handled_events (event_id, listener, handled_at) VALUES (?, ?, now())')->execute([$claim, $listener]);
        echo "claimed\n";
        $blocked = $pdo->prepare('SELECT count(*) FROM pg_stat_activity WHERE pg_backend_pid() = ANY(pg_blocking_pids(pid))');
        $until = microtime(true) + 10;
        while (microtime(true) < $until) {
            $blocked->execute();
            if ((int) $blocked->fetchColumn() > 0) {
                $pdo->rollBack();
                echo "released a waiter\n";
                exit(0);
            }
            usleep(2000);
        }
        $pdo->rollBack();
        echo "nobody waited\n";
        PHP, '--', $dsn, (string) $db['username'], (string) $db['password'], securityAlertRaceClaim(), SecurityFailureAlert::class]);
    $holder->start();
    $holder->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'claimed'));

    securityAlertRaceOn(DB::connection())->failed(MessageChannel::Email, null);
    $holder->wait();

    expect($holder->getOutput())->toContain('released a waiter')
        ->and(DB::table('ops.inbox_entries')->where('kind', 'security_email_failed')->pluck('staff_id')->all())->toBe([$super])
        ->and(DB::table('ops.handled_events')->count())->toBe(1);
})->skip(fn (): bool => securityAlertRaceInParallel(), 'Commits its own rows: not under --parallel.');
