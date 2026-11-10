<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Infrastructure\Queue\BringInImportJob;
use Modules\Platform\Public\LongQueue;
use Modules\Promotions\Infrastructure\Queue\RefreshSegmentsJob;
use Modules\Sync\Infrastructure\Queue\PruneSyncLogJob;
use Modules\Sync\Infrastructure\Queue\ReadStoreJob;

/*
| The two queue connections (owner, 2026-11-10; config/queue.php): a database queue hands a job to a
| second worker once it has been reserved longer than its connection's `retry_after`, so every queued
| class must end before its connection's limit. A job that may run longer than the normal one's goes on
| the long one (`LongQueue`), whose worker runs only it.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

/** Room between a job's own end and the moment its connection hands it on. */
const QUEUE_CONNECTIONS_MARGIN = 30;

/** A worker's `--timeout` when a queued class states none (HANDOFF's deploy notes: both workers run with 60). */
const QUEUE_CONNECTIONS_WORKER_TIMEOUT = 60;

/** The normal connection, as hosting runs it: `QUEUE_CONNECTION=database` (tests run it as `sync`). */
const QUEUE_CONNECTIONS_NORMAL = 'database';

/**
 * Every class in src that is queued - jobs and queued listeners -, with its source.
 *
 * @return array<class-string, string>
 */
function queueConnectionsClasses(): array
{
    $queued = [];
    $root = base_path('src');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Only a file that declares a class is asked about it (FailedJobsTest's rule).
        if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+\w+/m', $source) !== 1) {
            continue;
        }

        $class = str_replace(['/', '.php'], ['\\', ''], substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root)) + 1));

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $queued[$class] = $source;
        }
    }

    return $queued;
}

/** Its own timeout - `$timeout`, or Laravel's `#[Timeout]`, the longer if both -, or null when it states none. */
function queueConnectionsOwnTimeout(string $class): ?int
{
    $reflection = new ReflectionClass($class);
    $stated = [];

    if ($reflection->hasProperty('timeout') && is_int($timeout = $reflection->getProperty('timeout')->getDefaultValue())) {
        $stated[] = $timeout;
    }

    foreach ($reflection->getAttributes(Timeout::class) as $attribute) {
        $stated[] = $attribute->newInstance()->timeout;
    }

    return $stated === [] ? null : max($stated);
}

/** Its own timeout, or the worker's when it states none. */
function queueConnectionsTimeout(string $class): int
{
    return queueConnectionsOwnTimeout($class) ?? QUEUE_CONNECTIONS_WORKER_TIMEOUT;
}

/** Whether its constructor puts it on the long connection. */
function queueConnectionsOnLong(string $source): bool
{
    return str_contains($source, '$this->onConnection(LongQueue::connection());');
}

/**
 * Built with stand-ins for its constructor's values - only plain values, which every long job takes.
 */
function queueConnectionsBuild(string $class): object
{
    $reflection = new ReflectionClass($class);
    $arguments = [];

    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        $arguments[] = match (true) {
            $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
            $type?->allowsNull() === true => null,
            $name === 'string' => 'x',
            $name === 'int' => 0,
            $name === 'bool' => false,
            $name === 'array' => [],
            default => throw new LogicException("{$class}: the guard cannot build a {$name} for \${$parameter->getName()} - teach queueConnectionsBuild()."),
        };
    }

    return $reflection->newInstanceArgs($arguments);
}

/** Its connection's `retry_after`, as config/queue.php reads it. */
function queueConnectionsRetryAfter(string $connection): int
{
    $retryAfter = config("queue.connections.{$connection}.retry_after");

    expect($retryAfter)->toBeInt("config/queue.php has no retry_after for {$connection}");

    return (int) $retryAfter;
}

it('runs two database connections on one table, each on its own queue, the long one\'s limit the higher', function () {
    $normal = config('queue.connections.'.QUEUE_CONNECTIONS_NORMAL);
    $long = config('queue.connections.'.LongQueue::CONNECTION);

    expect($normal['driver'])->toBe('database')
        ->and($long['driver'])->toBe('database')
        ->and($long['table'])->toBe($normal['table'])
        ->and($long['connection'])->toBe($normal['connection'])
        // One queue name for both and the normal worker would reserve a long job and hand it on at its own limit.
        ->and($long['queue'])->not->toBe($normal['queue'])
        ->and($long['retry_after'])->toBeGreaterThan($normal['retry_after'])
        ->and($long['after_commit'])->toBeTrue();
});

it('names the long connection database-long unless hosting says otherwise, and tests run it as they run the default', function () {
    expect(config('queue.long_connection'))->toBe('sync')
        ->and(LongQueue::connection())->toBe('sync');

    config(['queue.long_connection' => null]);

    expect(LongQueue::connection())->toBe(LongQueue::CONNECTION);
});

it('puts every queued class that may outlast the normal connection on the long one, and keeps each under its own connection\'s limit', function () {
    // As on the server: the long jobs' connection is database-long, not the tests' sync.
    config(['queue.long_connection' => LongQueue::CONNECTION]);

    $normalLimit = queueConnectionsRetryAfter(QUEUE_CONNECTIONS_NORMAL);
    $longLimit = queueConnectionsRetryAfter(LongQueue::CONNECTION);
    $queued = queueConnectionsClasses();
    $long = [];

    // A moved folder must not make this pass over nothing: 70 queued classes, 4 of them long (2026-11-10).
    expect(count($queued))->toBeGreaterThanOrEqual(60);

    foreach ($queued as $class => $source) {
        $timeout = queueConnectionsTimeout($class);
        $onLong = queueConnectionsOnLong($source);

        // Only LongQueue names a connection, and nothing names a queue: either named by hand would escape both
        // rules below (a long job on the normal connection's queue would be reserved by the normal worker).
        expect(preg_match('/onConnection\s*\(|viaConnection\s*\(|onQueue\s*\(|viaQueue\s*\(|(?:\$|->)connection\s*=(?!=)|(?:\$|->)queue\s*=(?!=)/', str_replace('$this->onConnection(LongQueue::connection());', '', $source)))
            ->toBe(0, "{$class} names its own queue connection or queue")
            ->and((new ReflectionClass($class))->getAttributes(Connection::class))->toBe([], "{$class} names its connection in an attribute")
            ->and((new ReflectionClass($class))->getAttributes(QueueAttribute::class))->toBe([], "{$class} names its queue in an attribute");

        if ($timeout > $normalLimit - QUEUE_CONNECTIONS_MARGIN) {
            expect($onLong)->toBeTrue("{$class} may run {$timeout} s, past the normal connection's {$normalLimit} s less ".QUEUE_CONNECTIONS_MARGIN.': put it on LongQueue');
        }

        if ($onLong) {
            $long[] = $class;

            expect(queueConnectionsOwnTimeout($class))->not->toBeNull("{$class} is on the long connection without a timeout of its own")
                ->and(queueConnectionsBuild($class)->connection)->toBe(LongQueue::CONNECTION, "{$class} does not reach the long connection when built");
        }

        $limit = $onLong ? $longLimit : $normalLimit;

        expect($timeout + QUEUE_CONNECTIONS_MARGIN)->toBeLessThanOrEqual($limit, "{$class} may run {$timeout} s, within ".QUEUE_CONNECTIONS_MARGIN." s of its connection's {$limit} s");
    }

    expect(count($long))->toBeGreaterThanOrEqual(4);
});

it('sends each long job to the long connection, dispatched or scheduled', function () {
    config(['queue.long_connection' => LongQueue::CONNECTION]);
    Queue::fake();

    BringInImportJob::dispatch('import');
    ReadStoreJob::dispatch('read');

    // The scheduler's own callback - the one `schedule:run` calls - without its one-server lock.
    foreach ([RefreshSegmentsJob::class, PruneSyncLogJob::class] as $class) {
        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event->description === $class);

        expect($event)->toBeInstanceOf(CallbackEvent::class, "{$class} is not scheduled");

        $callback = (fn () => $this->callback)->call($event);
        $callback();
    }

    foreach ([BringInImportJob::class, ReadStoreJob::class, RefreshSegmentsJob::class, PruneSyncLogJob::class] as $class) {
        Queue::assertPushed($class, static fn (object $job): bool => $job->connection === LongQueue::CONNECTION);
    }
});

it('writes a long job to the jobs table on the long queue, where the normal worker never looks', function () {
    config(['queue.long_connection' => LongQueue::CONNECTION]);

    BringInImportJob::dispatch('import');

    expect(DB::table('jobs')->get(['queue'])->pluck('queue')->all())->toBe(['long'])
        ->and(DB::table('jobs')->where('queue', config('queue.connections.'.QUEUE_CONNECTIONS_NORMAL.'.queue'))->count())->toBe(0);
});

it('never names a connection where a job is dispatched, except the long one', function () {
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('src'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/onConnection\s*\(([^)]*)\)/', (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] as $argument) {
            if ($argument !== 'LongQueue::connection(') {
                $found[] = $file->getFilename().': '.$argument;
            }
        }
    }

    expect($found)->toBe([]);
});
