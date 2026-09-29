<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Application\Command\DeleteFailedJob\DeleteFailedJob;
use Modules\Platform\Application\Command\DeleteFailedJob\DeleteFailedJobHandler;
use Modules\Platform\Application\Command\RetryFailedJob\RetryFailedJob;
use Modules\Platform\Application\Command\RetryFailedJob\RetryFailedJobHandler;
use Modules\Platform\Application\Query\ListFailedJobs\FailedJobRow;
use Modules\Platform\Application\Query\ListFailedJobs\ListFailedJobs;
use Modules\Platform\Application\Query\ListFailedJobs\ListFailedJobsHandler;
use Modules\Platform\Application\Query\ViewFailedJob\ViewFailedJob;
use Modules\Platform\Application\Query\ViewFailedJob\ViewFailedJobHandler;
use Modules\Platform\Domain\Exception\FailedJobNotFound;
use Modules\Platform\Domain\Exception\FailedJobNotRetryable;
use Modules\Platform\Presentation\Http\Resource\FailedJobNames;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The queue's failed work (platform.md §3, frontend.md E7): a job that fails its last attempt waits
| until an admin retries or deletes it. The jobs here fail for real, in a real worker on the database
| queue, as they would on the server.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

final class FailedJobsTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Whether the next run fails: the worker runs in this process. */
    public static bool $fails = true;

    public int $tries = 1;

    public function __construct(public string $marker) {}

    public function handle(): void
    {
        if (self::$fails) {
            throw new RuntimeException('Could not reach the thing: the attempt was refused.');
        }

        cache()->put('failed-jobs-test-ran', $this->marker);
    }
}

beforeEach(function () {
    seed(PlatformSeeder::class);
    FailedJobsTestJob::$fails = true;
    cache()->forget('failed-jobs-test-ran');
});

/**
 * A job that fails its only attempt in a real worker, and so waits in failed_jobs. Its uuid.
 */
function failedJobsFail(string $marker = 'first'): string
{
    FailedJobsTestJob::dispatch($marker)->onConnection('database');
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    return (string) DB::table('failed_jobs')->orderByDesc('id')->value('uuid');
}

function failedJobsAdmin(): void
{
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::JOBS_MANAGE], ['sa'], RoleLevel::Admin));
}

/**
 * The first page's rows.
 *
 * @return list<FailedJobRow>
 */
function failedJobsList(): array
{
    return app(ListFailedJobsHandler::class)->handle(new ListFailedJobs)->rows;
}

/**
 * A failed job written straight into the table, as a worker on another connection would leave one.
 */
function failedJobsRow(string $connection = 'database', ?string $failedAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $id,
        'connection' => $connection,
        'queue' => 'default',
        'payload' => json_encode(['uuid' => $id, 'displayName' => FailedJobsTestJob::class, 'maxTries' => 1, 'data' => []], JSON_THROW_ON_ERROR),
        'exception' => 'RuntimeException: gone',
        'failed_at' => $failedAt ?? now(),
    ]);

    return $id;
}

it('lists a job that failed its last attempt: what ran, when, the tries it was allowed, the error\'s first line', function () {
    $id = failedJobsFail();
    failedJobsAdmin();

    $rows = failedJobsList();

    expect($id)->not->toBe('')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($id)
        ->and($rows[0]->className)->toBe(FailedJobsTestJob::class)
        ->and($rows[0]->triesAllowed)->toBe(1)
        ->and($rows[0]->queue)->toBe('default')
        ->and($rows[0]->errorLine)->toStartWith('RuntimeException: Could not reach the thing: the attempt was refused.')
        ->and($rows[0]->errorLine)->not->toContain("\n")
        ->and($rows[0]->retryable)->toBeTrue();
});

it('shows one job\'s whole error', function () {
    $id = failedJobsFail();
    failedJobsAdmin();

    $view = app(ViewFailedJobHandler::class)->handle(new ViewFailedJob($id));

    expect($view->job->error)->toContain('Could not reach the thing')
        ->and($view->job->error)->toContain('Stack trace')
        ->and($view->retryable)->toBeTrue();
});

it('lists them oldest first', function () {
    $newer = failedJobsFail('newer');
    $older = failedJobsFail('older');
    DB::table('failed_jobs')->where('uuid', $older)->update(['failed_at' => now()->subDays(40)]);
    failedJobsAdmin();

    expect(array_map(static fn (FailedJobRow $row): string => $row->id, failedJobsList()))->toBe([$older, $newer]);
});

it('pages them, oldest first, from where the page before ended', function () {
    $ids = [
        failedJobsRow(failedAt: '2026-09-01 10:00:00'),
        failedJobsRow(failedAt: '2026-09-02 10:00:00'),
        failedJobsRow(failedAt: '2026-09-02 10:00:00'),
    ];
    failedJobsAdmin();
    $sameTime = [$ids[1], $ids[2]];
    sort($sameTime);
    $expected = [$ids[0], ...$sameTime];

    $first = app(ListFailedJobsHandler::class)->handle(new ListFailedJobs(perPage: 2));
    $second = app(ListFailedJobsHandler::class)->handle(new ListFailedJobs($first->nextFailedAt, $first->nextId, 2));

    expect(array_map(static fn (FailedJobRow $row): string => $row->id, $first->rows))->toBe(array_slice($expected, 0, 2))
        ->and($first->nextId)->toBe($expected[1])
        ->and(array_map(static fn (FailedJobRow $row): string => $row->id, $second->rows))->toBe([$expected[2]])
        ->and($second->nextFailedAt)->toBeNull()
        ->and($second->nextId)->toBeNull();
});

it('keeps them: nothing is scheduled to prune or flush the failed jobs', function () {
    $scheduled = array_map(
        static fn ($event): string => strtolower(($event->command ?? '').' '.($event->description ?? '')),
        app(Schedule::class)->events(),
    );

    // A moved provider must not make this pass over nothing: the three sweeps are scheduled.
    expect(count($scheduled))->toBeGreaterThanOrEqual(3);

    foreach ($scheduled as $event) {
        expect($event)->not->toContain('prune-failed')
            ->and($event)->not->toContain('queue:flush')
            ->and($event)->not->toContain('queue:forget');
    }
});

it('never lets a staff role hold the permission: it goes into admin roles only', function () {
    expect(fn () => Fx::role([PlatformPermissions::JOBS_MANAGE], RoleLevel::Staff))->toThrow(AdminOnlyPermission::class);

    $roleId = Fx::role([PlatformPermissions::JOBS_MANAGE], RoleLevel::Admin);

    expect(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::JOBS_MANAGE]);
});

it('offers no retry for a job that failed on another queue, refuses one, and deletes it', function () {
    $id = failedJobsRow('sync');
    failedJobsAdmin();

    expect(failedJobsList()[0]->retryable)->toBeFalse()
        ->and(app(ViewFailedJobHandler::class)->handle(new ViewFailedJob($id))->retryable)->toBeFalse()
        ->and(fn () => app(RetryFailedJobHandler::class)->handle(new RetryFailedJob($id)))->toThrow(FailedJobNotRetryable::class)
        ->and(DB::table('failed_jobs')->where('uuid', $id)->exists())->toBeTrue()
        ->and(DB::table('platform.audit_entries')->where('action', 'platform.failed_job.retried')->count())->toBe(0);

    app(DeleteFailedJobHandler::class)->handle(new DeleteFailedJob($id));

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('retries only on a database queue in the application\'s own database, where the push and the delete commit together', function (?string $database, bool $retryable) {
    config(['queue.connections.elsewhere' => ['driver' => 'database', 'connection' => $database, 'table' => 'jobs', 'queue' => 'default']]);
    failedJobsRow('elsewhere');
    failedJobsAdmin();

    expect(failedJobsList()[0]->retryable)->toBe($retryable);
})->with([
    'its own database, named' => ['pgsql', true],
    'its own database, by default' => [null, true],
    'another database' => ['sqlite', false],
]);

it('keeps a page between one and a hundred jobs', function (int $asked, int $given) {
    for ($i = 0; $i < 101; $i++) {
        failedJobsRow(failedAt: now()->subMinutes($i)->toDateTimeString());
    }
    failedJobsAdmin();

    expect(app(ListFailedJobsHandler::class)->handle(new ListFailedJobs(perPage: $asked))->rows)->toHaveCount($given);
})->with([[0, 1], [1000, 100]]);

it('answers "not found" to an id that is not a uuid', function (string $id) {
    failedJobsAdmin();

    expect(fn () => app(ViewFailedJobHandler::class)->handle(new ViewFailedJob($id)))->toThrow(FailedJobNotFound::class)
        ->and(fn () => app(RetryFailedJobHandler::class)->handle(new RetryFailedJob($id)))->toThrow(FailedJobNotFound::class)
        ->and(fn () => app(DeleteFailedJobHandler::class)->handle(new DeleteFailedJob($id)))->toThrow(FailedJobNotFound::class);
})->with([
    '1',
    'not-a-uuid',
    "3f0e5c1a\0",
    // Not UTF-8: asked of PostgreSQL as it is, an encoding error rather than "not found".
    'invalid utf-8' => "3f0e5c1a\xC3\x28",
]);

it('retries one: back on its queue, off the list, and it runs; audited without its error', function () {
    $id = failedJobsFail('retried');
    failedJobsAdmin();
    FailedJobsTestJob::$fails = false;

    app(RetryFailedJobHandler::class)->handle(new RetryFailedJob($id));

    $entry = DB::table('platform.audit_entries')->where('action', 'platform.failed_job.retried')->where('subject_id', $id)->first();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(0)
        ->and($entry)->not->toBeNull()
        ->and((string) $entry?->changes)->toContain(json_encode(FailedJobsTestJob::class, JSON_THROW_ON_ERROR))
        ->and((string) $entry?->changes)->not->toContain('Could not reach')
        ->and($entry?->store_id)->toBeNull();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(cache()->get('failed-jobs-test-ran'))->toBe('retried')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('locks the job\'s row inside its own transaction, so two admins take turns', function (Closure $act) {
    $id = failedJobsFail();
    failedJobsAdmin();
    $locks = [];
    DB::listen(static function ($query) use (&$locks): void {
        if (str_contains($query->sql, '"failed_jobs"') && str_contains(strtolower($query->sql), 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });

    $act($id);

    // Level 2: inside the command's transaction, itself inside the test's (RefreshDatabase).
    expect($locks)->toBe([2]);
})->with([
    'retrying' => [fn (string $id) => app(RetryFailedJobHandler::class)->handle(new RetryFailedJob($id))],
    'deleting' => [fn (string $id) => app(DeleteFailedJobHandler::class)->handle(new DeleteFailedJob($id))],
]);

it('counts the attempts afresh for a queue that keeps them in the job itself', function () {
    // The database queue counts attempts in its own column; a queue that counts them inside the
    // payload — Redis, say — gets them reset, as queue:retry does.
    DB::table('failed_jobs')->insert([
        'uuid' => '6a1f0c2e-1111-4222-8333-444455556666',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => FailedJobsTestJob::class, 'attempts' => 3, 'maxTries' => 3, 'data' => []], JSON_THROW_ON_ERROR),
        'exception' => 'RuntimeException: gone',
        'failed_at' => now(),
    ]);
    failedJobsAdmin();

    app(RetryFailedJobHandler::class)->handle(new RetryFailedJob('6a1f0c2e-1111-4222-8333-444455556666'));

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect(is_array($payload) ? $payload['attempts'] ?? null : null)->toBe(0)
        ->and(is_array($payload) ? $payload['maxTries'] ?? null : null)->toBe(3);
});

it('lists a retried job again when it fails again', function () {
    $id = failedJobsFail();
    failedJobsAdmin();

    app(RetryFailedJobHandler::class)->handle(new RetryFailedJob($id));
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(failedJobsList())->toHaveCount(1);
});

it('deletes one for good, unrun; audited without its error', function () {
    $id = failedJobsFail();
    failedJobsAdmin();

    app(DeleteFailedJobHandler::class)->handle(new DeleteFailedJob($id));

    $entry = DB::table('platform.audit_entries')->where('action', 'platform.failed_job.deleted')->where('subject_id', $id)->first();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($entry)->not->toBeNull()
        ->and((string) $entry?->changes)->not->toContain('Could not reach');
});

it('answers "not found" to a job already handled, or never there, and changes nothing', function (Closure $act) {
    $id = failedJobsFail();
    failedJobsAdmin();
    app(DeleteFailedJobHandler::class)->handle(new DeleteFailedJob($id));
    $entries = DB::table('platform.audit_entries')->count();

    expect(fn () => $act($id))->toThrow(FailedJobNotFound::class)
        ->and(fn () => $act('3f0e5c1a-0000-4000-8000-000000000000'))->toThrow(FailedJobNotFound::class)
        ->and(DB::table('platform.audit_entries')->count())->toBe($entries)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'retrying' => [fn (string $id) => app(RetryFailedJobHandler::class)->handle(new RetryFailedJob($id))],
    'deleting' => [fn (string $id) => app(DeleteFailedJobHandler::class)->handle(new DeleteFailedJob($id))],
    'viewing' => [fn (string $id) => app(ViewFailedJobHandler::class)->handle(new ViewFailedJob($id))],
]);

it('refuses anyone without the permission, and changes nothing', function (Closure $act) {
    $id = failedJobsFail();
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::AUDIT_VIEW], ['*'], RoleLevel::Admin));

    expect(fn () => $act($id))->toThrow(Unauthorized::class)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'listing' => [fn (string $id) => failedJobsList()],
    'viewing' => [fn (string $id) => app(ViewFailedJobHandler::class)->handle(new ViewFailedJob($id))],
    'retrying' => [fn (string $id) => app(RetryFailedJobHandler::class)->handle(new RetryFailedJob($id))],
    'deleting' => [fn (string $id) => app(DeleteFailedJobHandler::class)->handle(new DeleteFailedJob($id))],
]);

it('lets a Super Admin in, as every admin-only action does', function () {
    failedJobsFail();
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(failedJobsList())->toHaveCount(1);
});

it('names every queued class in the modules, in Arabic and English, and each states its tries', function () {
    $queued = [];
    $root = base_path('src');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        // Only a file that declares a class is asked about it: asking class_exists() of a language
        // file, a route file or a migration would load it (the review of the screen).
        if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+\w+/m', (string) file_get_contents($file->getPathname())) !== 1) {
            continue;
        }

        $class = str_replace(['/', '.php'], ['\\', ''], substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root)) + 1));

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $queued[] = $class;
        }
    }

    // A moved folder must not make this pass over nothing: five today (2026-09-29).
    expect(count($queued))->toBeGreaterThanOrEqual(5);

    foreach ($queued as $class) {
        $key = FailedJobNames::keyFor($class);

        expect($key)->not->toBeNull("{$class} is outside the modules");

        foreach (['ar', 'en'] as $locale) {
            $name = trans((string) $key, [], $locale);

            expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$class} has no {$locale} name at {$key}");
        }

        // Every queued job states its own tries - as a number, or a tries() that answers one -, or the
        // worker's number applies and the screen could not say what it was (owner, 2026-09-29).
        $reflection = new ReflectionClass($class);
        $states = $reflection->hasMethod('tries')
            || ($reflection->hasProperty('tries') && is_int($reflection->getProperty('tries')->getDefaultValue()));

        expect($states)->toBeTrue("{$class} does not state its tries");
    }
});
