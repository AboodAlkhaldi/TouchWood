<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
 * @return list<FailedJobRow>
 */
function failedJobsList(): array
{
    return app(ListFailedJobsHandler::class)->handle(new ListFailedJobs);
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
        ->and($rows[0]->errorLine)->not->toContain("\n");
});

it('shows one job\'s whole error', function () {
    $id = failedJobsFail();
    failedJobsAdmin();

    $job = app(ViewFailedJobHandler::class)->handle(new ViewFailedJob($id));

    expect($job->error)->toContain('Could not reach the thing')
        ->and($job->error)->toContain('Stack trace');
});

it('lists them oldest first, and keeps them: nothing deletes a failed job on its own', function () {
    $newer = failedJobsFail('newer');
    $older = failedJobsFail('older');
    DB::table('failed_jobs')->where('uuid', $older)->update(['failed_at' => now()->subDays(40)]);
    failedJobsAdmin();

    expect(array_map(static fn (FailedJobRow $row): string => $row->id, failedJobsList()))->toBe([$older, $newer]);
});

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

it('names every queued class in the modules, in Arabic and English', function () {
    $queued = [];
    $root = base_path('src');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
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
    }
});
