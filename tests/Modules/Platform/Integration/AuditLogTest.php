<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrency;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrencyHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Infrastructure\Eloquent\DatabaseAuditLog;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Infrastructure\Http\AssignCorrelationId;

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
        $log = new DatabaseAuditLog($connection, app(ActorContext::class), app());

        expect($connection->transactionLevel())->toBe(0)
            ->and(fn () => $log->record(new AuditEntryDto('testing.thing.changed', 'testing.thing', 'thing-1', null, AuditChanges::none())))
            ->toThrow(LogicException::class, 'inside the transaction');
    });

    it('refuses an IP address on a customer or system entry', function (string $actorType, ?string $actorId) {
        expect(fn () => DB::table('platform.audit_entries')->insert([
            'occurred_at' => now(),
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
        Context::add(AssignCorrelationId::CONTEXT_KEY, 'corr-12345678');

        auditSomething();
        $entry = latestAuditEntry();

        expect($entry['actor_type'])->toBe('SYSTEM')
            ->and($entry['actor_id'])->toBeNull()
            ->and($entry['correlation_id'])->toBe('corr-12345678')
            ->and($entry['ip_address'])->toBeNull()
            ->and(decodedChanges($entry['changes']))->toEqual(['colour' => ['red', 'blue'], 'email' => 'changed']); // jsonb reorders keys
    });

    it('records the IP address of a staff member', function () {
        actingAs(Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3'));
        app()->instance('request', Request::create('/admin', server: ['REMOTE_ADDR' => '203.0.113.7']));

        auditSomething();

        expect(latestAuditEntry())->toMatchArray([
            'actor_type' => 'STAFF',
            'actor_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3',
            'ip_address' => '203.0.113.7',
        ]);
    });

    it('never records the IP address of a customer', function () {
        actingAs(Actor::customer('01j8z3k4m5n6p7q8r9s0t1v2w3'));
        app()->instance('request', Request::create('/sa', server: ['REMOTE_ADDR' => '203.0.113.7']));

        auditSomething();

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
