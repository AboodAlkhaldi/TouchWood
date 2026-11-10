<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Content\Infrastructure\Files\LocalSitemapFiles;
use Modules\Content\Infrastructure\Queue\BuildSitemapsJob;
use Modules\Content\Infrastructure\Queue\CheckRedirectsJob;
use Modules\Content\Infrastructure\Queue\OneSitemapBuild;
use Modules\Content\Infrastructure\Queue\RunOnce;

use function Pest\Laravel\seed;

/*
| A queued job runs once, whatever the queue hands out (PR 4 review): the database queue gives a job still running to
| a second worker once its retry_after (90 s) passes, and that copy carries the same uuid. A copy of a job running now,
| or of one that finished, deletes itself - so a long build never starts the next one, for ever. Another worker is a
| second database connection here; the copy is a queue job carrying the same uuid.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
    Storage::fake(LocalSitemapFiles::DISK);
});

afterEach(function () {
    DB::purge('pgsql_other_worker');
});

/** The queue's record of a job: the same uuid on every copy the queue hands out. */
function runOnceQueued(BuildSitemapsJob|CheckRedirectsJob $job, string $uuid): BuildSitemapsJob|CheckRedirectsJob
{
    $job->setJob(new SyncJob(app(), (string) json_encode(['uuid' => $uuid, 'displayName' => $job::class, 'data' => []]), 'sync', 'default'));

    return $job;
}

/** The job through its own middleware, as the worker runs it; true when its work ran. */
function runOnceRun(BuildSitemapsJob|CheckRedirectsJob $job, ?Closure $work = null): bool
{
    $ran = false;

    (new Pipeline(app()))->send($job)->through($job->middleware())->then(function (BuildSitemapsJob|CheckRedirectsJob $job) use (&$ran, $work): void {
        $ran = true;
        $work === null ? app()->call($job->handle(...)) : $work($job);
    });

    return $ran;
}

function runOnceOtherWorker(): ConnectionInterface
{
    config(['database.connections.pgsql_other_worker' => config('database.connections.pgsql')]);

    return DB::connection('pgsql_other_worker');
}

function runOnceLockFree(string $key): bool
{
    $other = runOnceOtherWorker();
    $free = (bool) ($other->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS got', [$key])?->got);

    if ($free) {
        $other->select('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
    }

    return $free;
}

it('builds once, and the copy of the finished build the queue hands out again deletes itself: no second build', function () {
    $uuid = (string) Str::uuid();

    expect(runOnceRun(runOnceQueued(new BuildSitemapsJob, $uuid)))->toBeTrue();
    $built = DB::table('content.sitemaps')->orderBy('store_id')->pluck('file_key')->all();
    expect($built)->toHaveCount(3)
        ->and(DB::table('content.job_runs')->where('uuid', $uuid)->exists())->toBeTrue();

    $copy = runOnceQueued(new BuildSitemapsJob, $uuid);

    expect(runOnceRun($copy))->toBeFalse()
        ->and($copy->job?->isDeleted())->toBeTrue()
        ->and($copy->job?->isReleased())->toBeFalse()
        ->and(DB::table('content.sitemaps')->orderBy('store_id')->pluck('file_key')->all())->toBe($built);
});

it('deletes the copy of a job running now, on another worker, instead of waiting for it', function (string $class) {
    $uuid = (string) Str::uuid();
    $other = runOnceOtherWorker();
    $other->select('SELECT pg_advisory_lock(hashtext(?))', ['content:job:'.$uuid]);

    try {
        $copy = runOnceQueued($class === BuildSitemapsJob::class ? new BuildSitemapsJob : new CheckRedirectsJob, $uuid);

        expect(runOnceRun($copy, static fn () => null))->toBeFalse()
            ->and($copy->job?->isDeleted())->toBeTrue()
            ->and($copy->job?->isReleased())->toBeFalse();
    } finally {
        $other->select('SELECT pg_advisory_unlock(hashtext(?))', ['content:job:'.$uuid]);
    }
})->with([BuildSitemapsJob::class, CheckRedirectsJob::class]);

it('lets a build that waits for another build go back to the queue, and run when it comes back', function () {
    $uuid = (string) Str::uuid();
    $other = runOnceOtherWorker();
    $other->select('SELECT pg_advisory_lock(hashtext(?))', [OneSitemapBuild::KEY]);

    try {
        $waiting = runOnceQueued(new BuildSitemapsJob, $uuid);
        expect(runOnceRun($waiting))->toBeFalse()->and($waiting->job?->isReleased())->toBeTrue();
    } finally {
        $other->select('SELECT pg_advisory_unlock(hashtext(?))', [OneSitemapBuild::KEY]);
    }

    // Released, it is no finished job: its return, the same uuid, builds.
    expect(DB::table('content.job_runs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(runOnceRun(runOnceQueued(new BuildSitemapsJob, $uuid)))->toBeTrue()
        ->and(DB::table('content.sitemaps')->count())->toBe(3);
});

it('lets the retry of a job that failed run, and frees both locks when the work throws', function () {
    $uuid = (string) Str::uuid();

    expect(fn () => runOnceRun(runOnceQueued(new BuildSitemapsJob, $uuid), static fn () => throw new RuntimeException('Disk full.')))->toThrow(RuntimeException::class, 'Disk full.');

    expect(runOnceLockFree('content:job:'.$uuid))->toBeTrue()
        ->and(runOnceLockFree(OneSitemapBuild::KEY))->toBeTrue()
        ->and(DB::table('content.job_runs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(runOnceRun(runOnceQueued(new BuildSitemapsJob, $uuid)))->toBeTrue();
});

it('removes finished jobs older than two days as each job ends', function () {
    $old = (string) Str::uuid();
    DB::table('content.job_runs')->insert(['uuid' => $old, 'finished_at' => CarbonImmutable::now()->subDays(RunOnce::KEEP_DAYS)->subSecond()]);
    $recent = (string) Str::uuid();
    DB::table('content.job_runs')->insert(['uuid' => $recent, 'finished_at' => CarbonImmutable::now()->subDay()]);

    runOnceRun(runOnceQueued(new CheckRedirectsJob, (string) Str::uuid()), static fn () => null);

    expect(DB::table('content.job_runs')->where('uuid', $old)->exists())->toBeFalse()
        ->and(DB::table('content.job_runs')->where('uuid', $recent)->exists())->toBeTrue();
});

it('says so in the log when a lock was not held as the job ended', function () {
    $log = Log::spy();
    $uuid = (string) Str::uuid();

    // The work lets go of both locks itself: their unlocks at the end find nothing to let go.
    runOnceRun(runOnceQueued(new BuildSitemapsJob, $uuid), static function () use ($uuid): void {
        DB::select('SELECT pg_advisory_unlock(hashtext(?))', ['content:job:'.$uuid]);
        DB::select('SELECT pg_advisory_unlock(hashtext(?))', [OneSitemapBuild::KEY]);
    });

    $log->shouldHaveReceived('warning')->twice();
});

it('runs a job run by hand, with no queue record, as it is', function () {
    $ran = runOnceRun(new CheckRedirectsJob, static fn () => null);

    expect($ran)->toBeTrue()->and(DB::table('content.job_runs')->count())->toBe(0);
});

it('bounds a build\'s attempts, so nothing waits for ever', function () {
    $job = new BuildSitemapsJob;

    expect($job->tries)->toBeGreaterThan(0)->toBeLessThanOrEqual(240)
        ->and($job->maxExceptions)->toBe(3)
        ->and(array_map(static fn (object $middleware): string => $middleware::class, $job->middleware()))->toBe([RunOnce::class, OneSitemapBuild::class])
        ->and(array_map(static fn (object $middleware): string => $middleware::class, (new CheckRedirectsJob)->middleware()))->toBe([RunOnce::class]);
});
