<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrency;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrencyHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Infrastructure\Eloquent\DatabaseAuditLog;
use Modules\Platform\Infrastructure\HttpRequestState;
use Modules\Platform\Infrastructure\Queue\JobActorState;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\CorrelationId;

use function Pest\Laravel\withServerVariables;

uses(RefreshDatabase::class);

function actingAs(Actor $actor): void
{
    app()->instance(ActorContext::class, new readonly class($actor) implements ActorContext
    {
        public function __construct(private Actor $actor) {}

        public function current(): Actor
        {
            return $this->actor;
        }
    });
}

/**
 * Sends a real HTTP request (from 203.0.113.7) whose handler records one audit entry.
 */
function auditThroughAWebRequest(): void
{
    Route::post('/_probe/audit', function () {
        DB::transaction(fn () => auditSomething());

        return response()->noContent();
    });

    withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post('/_probe/audit')->assertNoContent();
}

/**
 * A queued job that records one audit entry.
 */
final class AuditsFromAJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        DB::transaction(fn () => auditSomething('testing.job.changed'));
    }
}

function auditSomething(string $action = 'testing.thing.changed'): void
{
    app(PlatformApi::class)->recordAudit(new AuditEntryDto(
        $action,
        'testing.thing',
        'thing-1',
        null,
        AuditChanges::none()->changed('colour', 'red', 'blue')->personal('email'),
    ));
}

/**
 * @return array<string, mixed>
 */
function latestAuditEntry(): array
{
    $row = DB::table('platform.audit_entries')->orderByDesc('id')->first();

    if ($row === null) {
        throw new LogicException('No audit entry was written.');
    }

    return (array) $row;
}

/**
 * @return array<array-key, mixed>
 */
function decodedChanges(mixed $json): array
{
    $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

describe('the table', function () {
    it('refuses to change an entry', function () {
        auditSomething();

        expect(fn () => DB::table('platform.audit_entries')->update(['action' => 'rewritten']))
            ->toThrow(QueryException::class, 'append-only');
    });

    it('refuses to delete an entry', function () {
        auditSomething();

        expect(fn () => DB::table('platform.audit_entries')->delete())
            ->toThrow(QueryException::class, 'append-only');
    });

    it('refuses to truncate the log', function () {
        auditSomething();

        expect(fn () => DB::statement('TRUNCATE platform.audit_entries'))
            ->toThrow(QueryException::class, 'append-only');
    });

    it('refuses to record an entry outside a transaction', function () {
        // The test's own transaction wraps only the default connection, so a second connection to
        // the same database genuinely has no transaction open.
        config(['database.connections.outside_transaction' => config('database.connections.pgsql')]);
        $connection = DB::connection('outside_transaction');
        $log = new DatabaseAuditLog($connection, app(ActorContext::class), app(), app(JobActorState::class), app(HttpRequestState::class));

        expect($connection->transactionLevel())->toBe(0)
            ->and(fn () => $log->record(new AuditEntryDto('testing.thing.changed', 'testing.thing', 'thing-1', null, AuditChanges::none())))
            ->toThrow(LogicException::class, 'inside the transaction');
    });

    it('refuses an IP address on a customer or system entry', function (string $actorType, ?string $actorId) {
        expect(fn () => DB::table('platform.audit_entries')->insert([
            'source' => 'WEB',
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => 'testing.thing.changed',
            'subject_type' => 'testing.thing',
            'subject_id' => 'thing-1',
            'ip_address' => '203.0.113.7',
        ]))->toThrow(QueryException::class, 'audit_entries_ip_staff_only');
    })->with([
        'customer' => ['CUSTOMER', '01j8z3k4m5n6p7q8r9s0t1v2w3'],
        'system' => ['SYSTEM', null],
    ]);

    it('stores the IP address as inet and the changes as jsonb', function () {
        $types = DB::table('information_schema.columns')
            ->where('table_schema', 'platform')
            ->where('table_name', 'audit_entries')
            ->pluck('data_type', 'column_name')
            ->all();

        expect($types)->toMatchArray([
            'id' => 'bigint',
            'occurred_at' => 'timestamp with time zone',
            'actor_type' => 'character varying',
            'changes' => 'jsonb',
            'ip_address' => 'inet',
        ]);
    });
});

describe('recording', function () {
    it('records the system as the actor, with the correlation id and no IP', function () {
        Context::add(CorrelationId::CONTEXT_KEY, 'corr-12345678');

        auditSomething();
        $entry = latestAuditEntry();

        expect($entry['actor_type'])->toBe('SYSTEM')
            ->and($entry['actor_id'])->toBeNull()
            ->and($entry['correlation_id'])->toBe('corr-12345678')
            ->and($entry['ip_address'])->toBeNull()
            ->and(decodedChanges($entry['changes']))->toEqual(['colour' => ['red', 'blue'], 'email' => 'changed']); // jsonb reorders keys
    });

    it('records the IP address of a staff member making a web request', function () {
        actingAs(Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3'));

        auditThroughAWebRequest();

        expect(latestAuditEntry())->toMatchArray([
            'source' => 'WEB',
            'actor_type' => 'STAFF',
            'actor_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3',
            'ip_address' => '203.0.113.7',
        ]);
    });

    it('never records the IP address of a customer', function () {
        actingAs(Actor::customer('01j8z3k4m5n6p7q8r9s0t1v2w3'));

        auditThroughAWebRequest();

        expect(latestAuditEntry()['ip_address'])->toBeNull();
    });

    it('leaves no entry when the change it records rolls back', function () {
        $before = DB::table('platform.audit_entries')->count();

        try {
            DB::transaction(function () {
                auditSomething();

                throw new RuntimeException('The change failed.');
            });
        } catch (RuntimeException) {
        }

        expect(DB::table('platform.audit_entries')->count())->toBe($before);
    });
});

describe('store and currency changes', function () {
    beforeEach(function () {
        app(CreateCurrencyHandler::class)->handle(new CreateCurrency('XTS', 2, 'عملة', 'Currency', 'ع.ت', 'XTS', "\u{20C1}"));
        app(CreateStoreHandler::class)->handle(new CreateStore('xa', 'متجر', 'Store', 'XA', 'XTS', 1500, 'Asia/Riyadh', 1));
    });

    it('audits creating a currency, globally, with its values', function () {
        $entry = (array) DB::table('platform.audit_entries')->where('action', 'platform.currency.created')->first();

        expect($entry['subject_id'])->toBe('XTS')
            ->and($entry['store_id'])->toBeNull()
            ->and(decodedChanges($entry['changes'])['exponent'])->toBe([null, 2]);
    });

    it('audits creating a store, under that store, with its values', function () {
        $storeId = app(PlatformApi::class)->storeByCode('xa')?->id;
        $entry = (array) DB::table('platform.audit_entries')->where('action', 'platform.store.created')->first();

        expect($entry['subject_type'])->toBe('platform.store')
            ->and($entry['subject_id'])->toBe($storeId)
            ->and($entry['store_id'])->toBe($storeId)
            ->and(decodedChanges($entry['changes'])['tax_rate_basis_points'])->toBe([null, 1500]);
    });

    it('audits a store update with the old and new value of each changed attribute', function () {
        app(UpdateStoreHandler::class)->handle(new UpdateStore('xa', nameEn: 'Renamed', taxRateBasisPoints: 1600));

        expect(decodedChanges(latestAuditEntry()['changes']))->toEqual([
            'name' => [['ar' => 'متجر', 'en' => 'Store'], ['ar' => 'متجر', 'en' => 'Renamed']],
            'tax_rate_basis_points' => [1500, 1600],
        ]);
    });

    it('audits clearing a currency sign', function () {
        app(UpdateCurrencyHandler::class)->handle(new UpdateCurrency('XTS', clearSign: true));

        expect(latestAuditEntry()['action'])->toBe('platform.currency.updated')
            ->and(decodedChanges(latestAuditEntry()['changes']))->toBe(['sign' => ["\u{20C1}", null]]);
    });

    it('writes no entry when an update changes nothing', function () {
        $before = DB::table('platform.audit_entries')->count();

        app(UpdateStoreHandler::class)->handle(new UpdateStore('xa', taxRateBasisPoints: 1500));

        expect(DB::table('platform.audit_entries')->count())->toBe($before);
    });
});

describe('source and date', function () {
    it('marks a change made from an artisan command as CONSOLE, dated by the database', function () {
        auditSomething();
        $entry = latestAuditEntry();

        expect($entry['source'])->toBe('CONSOLE')
            ->and($entry['occurred_at'])->toBe($entry['recorded_at']);
    });

    it('marks a change made through a web request as WEB', function () {
        auditThroughAWebRequest();

        expect(latestAuditEntry()['source'])->toBe('WEB');
    });

    it('marks a web request made by an integration as INTEGRATION', function () {
        actingAs(Actor::integration('01j8z3k4m5n6p7q8r9s0t1v2w3'));

        auditThroughAWebRequest();

        expect(latestAuditEntry())->toMatchArray(['source' => 'INTEGRATION', 'actor_type' => 'INTEGRATION', 'ip_address' => null]);
    });

    it('marks a change made by a queued job as JOB', function () {
        AuditsFromAJob::dispatch();

        expect(latestAuditEntry())->toMatchArray(['source' => 'JOB', 'actor_type' => 'SYSTEM', 'action' => 'testing.job.changed']);
    });

    it('keeps the real date and actor of imported history, and when it was really written', function () {
        DB::transaction(fn () => app(PlatformApi::class)->recordImportedAudit(
            new AuditEntryDto('legacy.order.cancelled', 'legacy.order', 'TW-10428', null, AuditChanges::none()),
            Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3'),
            new DateTimeImmutable('2019-05-01 10:00:00+00:00'),
        ));
        $entry = latestAuditEntry();

        expect($entry['source'])->toBe('IMPORT')
            ->and($entry['actor_type'])->toBe('STAFF')
            ->and((new DateTimeImmutable((string) $entry['occurred_at']))->format('Y-m-d H:i'))->toBe('2019-05-01 10:00')
            ->and(new DateTimeImmutable((string) $entry['recorded_at']))->toBeGreaterThan(new DateTimeImmutable('2020-01-01'))
            ->and($entry['ip_address'])->toBeNull();
    });

    it('lets only the system import history', function () {
        actingAs(Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3'));

        DB::transaction(fn () => app(PlatformApi::class)->recordImportedAudit(
            new AuditEntryDto('legacy.order.cancelled', 'legacy.order', 'TW-10428', null, AuditChanges::none()),
            Actor::system(),
            new DateTimeImmutable('2019-05-01'),
        ));
    })->throws(LogicException::class, 'Only the system imports history');

    it('refuses imported history dated in the future', function () {
        DB::transaction(fn () => app(PlatformApi::class)->recordImportedAudit(
            new AuditEntryDto('legacy.order.cancelled', 'legacy.order', 'TW-10428', null, AuditChanges::none()),
            Actor::system(),
            new DateTimeImmutable('+1 day'),
        ));
    })->throws(InvalidArgumentException::class, 'cannot happen in the future');

    it('refuses imported history dated after the transaction started, which the database would refuse', function () {
        // PHP's clock is ahead of the transaction's start time, which is what recorded_at holds.
        DB::transaction(fn () => app(PlatformApi::class)->recordImportedAudit(
            new AuditEntryDto('legacy.order.cancelled', 'legacy.order', 'TW-10428', null, AuditChanges::none()),
            Actor::system(),
            new DateTimeImmutable,
        ));
    })->throws(InvalidArgumentException::class, 'cannot happen in the future');

    it('keeps the time zone of an imported date: 10:00 in Riyadh is 07:00 UTC', function () {
        DB::transaction(fn () => app(PlatformApi::class)->recordImportedAudit(
            new AuditEntryDto('legacy.order.cancelled', 'legacy.order', 'TW-10428', null, AuditChanges::none()),
            Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3'),
            new DateTimeImmutable('2019-05-01 10:00:00+03:00'),
        ));

        $occurredAt = DB::selectOne("select to_char(occurred_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as utc from platform.audit_entries order by id desc limit 1");

        expect($occurredAt?->utc)->toBe('2019-05-01 07:00:00');
    });

    it('refuses imported history whose actor carries a requester, before the database would', function () {
        DB::transaction(fn () => app(PlatformApi::class)->recordImportedAudit(
            new AuditEntryDto('legacy.order.cancelled', 'legacy.order', 'TW-10428', null, AuditChanges::none()),
            Actor::system(Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3')),
            new DateTimeImmutable('2019-05-01'),
        ));
    })->throws(InvalidArgumentException::class, 'it has no requester');

    it('never records the IP address of a staff member working from the console', function () {
        actingAs(Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3'));

        auditSomething();

        expect(latestAuditEntry())->toMatchArray(['source' => 'CONSOLE', 'actor_type' => 'STAFF', 'ip_address' => null]);
    });

    it('audits a queued job as the system even when the actor binding skips Platform\'s wrapper', function () {
        // instance() bypasses the wrapper around ActorContext; the entry must still be JOB and SYSTEM.
        actingAs(Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3'));

        AuditsFromAJob::dispatch();

        expect(latestAuditEntry())->toMatchArray([
            'source' => 'JOB',
            'actor_type' => 'SYSTEM',
            'requested_by_type' => 'STAFF',
            'requested_by_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3',
        ]);
    });
});

describe('what the code refuses before the database would', function () {
    it('refuses an actor that carries a requester outside a queued job', function () {
        actingAs(Actor::system(Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3')));

        auditSomething();
    })->throws(LogicException::class, 'only a queued job acts on someone\'s behalf');

    it('refuses an entry for a store that does not exist', function () {
        app(PlatformApi::class)->recordAudit(new AuditEntryDto('testing.thing.changed', 'testing.thing', 'thing-1', '01j8z3k4m5n6p7q8r9s0t1v2w3', AuditChanges::none()));
    })->throws(InvalidArgumentException::class, 'names a store that does not exist');

    it('refuses an action, subject type or subject id that is empty or too long for its column', function (string $action, string $subjectType, string $subjectId) {
        app(PlatformApi::class)->recordAudit(new AuditEntryDto($action, $subjectType, $subjectId, null, AuditChanges::none()));
    })->throws(InvalidArgumentException::class, 'must be 1 to')->with([
        'action over 100' => [str_repeat('a', 101), 'testing.thing', 'thing-1'],
        'subject type over 100' => ['testing.thing.changed', str_repeat('a', 101), 'thing-1'],
        'subject id over 64' => ['testing.thing.changed', 'testing.thing', str_repeat('a', 65)],
        'empty subject id' => ['testing.thing.changed', 'testing.thing', ''],
    ]);

    it('accepts values exactly at the column limits', function () {
        app(PlatformApi::class)->recordAudit(new AuditEntryDto(str_repeat('a', 100), str_repeat('b', 100), str_repeat('c', 64), null, AuditChanges::none()));

        expect(latestAuditEntry()['subject_id'])->toBe(str_repeat('c', 64));
    });
});
