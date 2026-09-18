<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Infrastructure\Queue\JobAwareActorContext;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

use function Pest\Laravel\withHeader;

/*
| Owner's decision (2026-09-18): a queued job acts as the system, and records whose action queued it.
*/

uses(RefreshDatabase::class);

const REQUESTER_ID = '01j8z3k4m5n6p7q8r9s0t1v2w3';

/**
 * A queued job that remembers who it ran as and writes one audit entry.
 */
final class RecordsItsActorJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<Actor> */
    public static array $seen = [];

    public function __construct(
        public readonly bool $queueAnother = false,
    ) {}

    public function handle(ActorContext $actors, PlatformApi $platform): void
    {
        self::$seen[] = $actors->current();

        DB::transaction(fn () => $platform->recordAudit(new AuditEntryDto('testing.job.ran', 'testing.job', 'job-1', null, AuditChanges::none())));

        if ($this->queueAnother) {
            self::dispatch();
        }
    }
}

/**
 * A queued job that fails, remembering who its failed() method ran as.
 */
final class FailsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static ?Actor $failedAs = null;

    public function handle(): void
    {
        throw new RuntimeException('The job broke.');
    }

    public function failed(?Throwable $error): void
    {
        self::$failedAs = app(ActorContext::class)->current();
    }
}

/**
 * Registers the actor the way Access will: as a binding, which Platform wraps.
 */
function actingThroughBinding(Actor $actor): void
{
    app()->scoped(ActorContext::class, fn (): ActorContext => new readonly class($actor) implements ActorContext
    {
        public function __construct(private Actor $actor) {}

        public function current(): Actor
        {
            return $this->actor;
        }
    });
    app()->forgetScopedInstances();
}

beforeEach(function () {
    RecordsItsActorJob::$seen = [];
    FailsJob::$failedAs = null;
});

it('wraps an actor context registered after Platform, as Access will register its own', function () {
    actingThroughBinding(Actor::staff(REQUESTER_ID));

    expect(app(ActorContext::class))->toBeInstanceOf(JobAwareActorContext::class);
});

it('runs a job queued by a staff member as the system, and the audit records who asked', function () {
    actingThroughBinding(Actor::staff(REQUESTER_ID));

    RecordsItsActorJob::dispatch();

    $entry = DB::table('platform.audit_entries')->where('action', 'testing.job.ran')->first();

    expect(RecordsItsActorJob::$seen[0]->type)->toBe(ActorType::System)
        ->and(RecordsItsActorJob::$seen[0]->requestedBy?->id)->toBe(REQUESTER_ID)
        ->and($entry?->source)->toBe('JOB')
        ->and($entry?->actor_type)->toBe('SYSTEM')
        ->and($entry?->actor_id)->toBeNull()
        ->and($entry?->requested_by_type)->toBe('STAFF')
        ->and($entry?->requested_by_id)->toBe(REQUESTER_ID)
        // No request behind a job, so no IP address, even though a staff member asked.
        ->and($entry?->ip_address)->toBeNull();
});

it('gives the request its own actor back once a job has run inside it', function () {
    // With the "sync" queue a job runs inside the request that queued it.
    actingThroughBinding(Actor::staff(REQUESTER_ID));

    RecordsItsActorJob::dispatch();

    expect(app(ActorContext::class)->current()->type)->toBe(ActorType::Staff);
});

it('runs a failed job\'s failed() method as the system too, and gives the actor back afterwards', function () {
    actingThroughBinding(Actor::staff(REQUESTER_ID));

    expect(fn () => Bus::dispatch(new FailsJob))->toThrow(RuntimeException::class, 'The job broke.');

    expect(FailsJob::$failedAs?->type)->toBe(ActorType::System)
        ->and(FailsJob::$failedAs?->requestedBy?->id)->toBe(REQUESTER_ID)
        ->and(app(ActorContext::class)->current()->type)->toBe(ActorType::Staff);
});

it('audits a sync job inside a web request as JOB, then the request itself as WEB, under one correlation id of ours', function () {
    actingThroughBinding(Actor::staff(REQUESTER_ID));

    Route::post('/_probe/queue-in-request', function (PlatformApi $platform) {
        RecordsItsActorJob::dispatch();
        DB::transaction(fn () => $platform->recordAudit(new AuditEntryDto('testing.request.ran', 'testing.request', 'request-1', null, AuditChanges::none())));

        return response()->noContent();
    });

    $response = withHeader('X-Correlation-Id', 'chosen-by-the-caller')->post('/_probe/queue-in-request')->assertNoContent();

    $job = DB::table('platform.audit_entries')->where('action', 'testing.job.ran')->first();
    $request = DB::table('platform.audit_entries')->where('action', 'testing.request.ran')->first();
    $correlationId = $response->headers->get('X-Correlation-Id');

    expect($job?->source)->toBe('JOB')
        ->and($job?->actor_type)->toBe('SYSTEM')
        ->and($job?->requested_by_type)->toBe('STAFF')
        ->and($job?->ip_address)->toBeNull()
        ->and($request?->source)->toBe('WEB')
        ->and($request?->actor_type)->toBe('STAFF')
        ->and($request?->ip_address)->toBe('127.0.0.1')
        ->and($correlationId)->toBeString()
        ->and($correlationId)->not->toBe('chosen-by-the-caller')
        ->and($request?->correlation_id)->toBe($correlationId)
        ->and($job?->correlation_id)->toBe($correlationId);
});

it('keeps the original requester when a job queues another job', function () {
    actingThroughBinding(Actor::guest(REQUESTER_ID));

    RecordsItsActorJob::dispatch(queueAnother: true);

    expect(RecordsItsActorJob::$seen)->toHaveCount(2)
        ->and(RecordsItsActorJob::$seen[1]->type)->toBe(ActorType::System)
        ->and(RecordsItsActorJob::$seen[1]->requestedBy?->type)->toBe(ActorType::Guest)
        ->and(RecordsItsActorJob::$seen[1]->requestedBy?->id)->toBe(REQUESTER_ID);
});

it('runs a job the system queued with no requester', function () {
    RecordsItsActorJob::dispatch();

    expect(RecordsItsActorJob::$seen[0]->type)->toBe(ActorType::System)
        ->and(RecordsItsActorJob::$seen[0]->requestedBy)->toBeNull();
});

it('carries the requester through the database queue to a real worker', function () {
    actingThroughBinding(Actor::integration(REQUESTER_ID));

    RecordsItsActorJob::dispatch()->onConnection('database');

    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    $entry = DB::table('platform.audit_entries')->where('action', 'testing.job.ran')->first();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($entry?->actor_type)->toBe('SYSTEM')
        ->and($entry?->requested_by_type)->toBe('INTEGRATION')
        ->and($entry?->requested_by_id)->toBe(REQUESTER_ID);
});
