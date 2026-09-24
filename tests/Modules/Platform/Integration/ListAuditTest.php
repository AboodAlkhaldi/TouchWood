<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Query\ListAudit\ListAudit;
use Modules\Platform\Application\Query\ListAudit\ListAuditHandler;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * Writes one entry straight into the log, because the point of these tests is what can be read
 * back rather than what put it there. The table is append-only, so nothing here can be tidied up
 * afterwards either — which is itself the rule being relied on.
 */
function auditEntry(string $action, ?string $storeId, string $occurredAt, string $source = 'WEB'): int
{
    return (int) DB::table('platform.audit_entries')->insertGetId([
        'occurred_at' => $occurredAt,
        'recorded_at' => $occurredAt,
        'source' => $source,
        'store_id' => $storeId,
        'actor_type' => 'SYSTEM',
        'actor_id' => null,
        'action' => $action,
        'subject_type' => 'platform.store',
        'subject_id' => 'test',
        'changes' => json_encode(['name' => ['before', 'after'], 'email' => 'changed'], JSON_THROW_ON_ERROR),
    ]);
}

/**
 * @return list<string>
 */
function auditActions(ListAudit $query = new ListAudit): array
{
    return array_map(
        static fn ($entry): string => $entry->action,
        app(ListAuditHandler::class)->handle($query)->entries,
    );
}

/**
 * Stage 2b, step 3. What the audit log gives back (frontend.md §3.5, E6).
 *
 * The log is append-only and kept forever, so the only questions are who may read which entries,
 * and whether a page boundary can lose one.
 */
describe('the audit log', function () {
    it('refuses somebody who may read no store\'s log', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']));

        expect(fn () => auditActions())->toThrow(Unauthorized::class);
    });

    it('shows a reader their own stores, and keeps the rest from them', function () {
        auditEntry('platform.store.updated', Fx::storeId('sa'), '2027-01-01 10:00:00+00');
        auditEntry('platform.currency.created', Fx::storeId('eg'), '2027-01-01 11:00:00+00');
        Fx::actAsAdmin(['sa'], [PlatformPermissions::AUDIT_VIEW]);

        // Only this day's, because opening the stores wrote entries of its own and this is a log
        // that is never emptied.
        expect(auditActions(new ListAudit(from: '2027-01-01')))->toBe(['platform.store.updated']);
    });

    it('keeps an entry that belongs to no store for a reader of every store', function () {
        // A currency, a global setting, a role: the system's changes rather than a shop's.
        auditEntry('platform.currency.updated', null, '2027-01-01 12:00:00+00');
        $onlyThisDay = new ListAudit(from: '2027-01-01');

        Fx::actAsAdmin(['sa'], [PlatformPermissions::AUDIT_VIEW]);
        expect(auditActions($onlyThisDay))->toBe([]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(auditActions($onlyThisDay))->toBe(['platform.currency.updated']);
    });

    it('reads newest first, and a page picks up exactly where the last one stopped', function () {
        // Three entries sharing one moment: the id is what keeps the order steady, and a boundary
        // that falls inside that moment is where a keyset page goes wrong if it is written badly.
        $moment = '2027-01-01 13:00:00+00';
        $ids = [
            auditEntry('platform.store.created', null, $moment),
            auditEntry('platform.store.updated', null, $moment),
            auditEntry('platform.currency.created', null, $moment),
        ];
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $first = app(ListAuditHandler::class)->handle(new ListAudit(from: '2027-01-01', perPage: 2));

        expect($first->entries)->toHaveCount(2)
            ->and($first->nextId)->not->toBeNull();

        $second = app(ListAuditHandler::class)->handle(new ListAudit(
            from: '2027-01-01',
            cursorOccurredAt: $first->nextOccurredAt,
            cursorId: $first->nextId,
            perPage: 2,
        ));

        $seen = array_map(static fn ($entry): int => (int) $entry->id, [...$first->entries, ...$second->entries]);

        // Every entry once: nothing shown twice, and nothing stepped over.
        expect(array_intersect($ids, $seen))->toHaveCount(3)
            ->and($seen)->toBe(array_unique($seen));
    });

    it('filters by action, source and day', function () {
        auditEntry('platform.store.updated', null, '2027-01-01 09:00:00+00');
        auditEntry('platform.currency.updated', null, '2027-01-03 09:00:00+00', 'CONSOLE');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(auditActions(new ListAudit(action: 'platform.currency.updated', from: '2027-01-01')))
            ->toBe(['platform.currency.updated'])
            ->and(auditActions(new ListAudit(source: 'CONSOLE', from: '2027-01-01')))
            ->toBe(['platform.currency.updated'])
            // "Until the 1st" means the whole of the 1st, not the moment it begins.
            ->and(auditActions(new ListAudit(from: '2027-01-01', until: '2027-01-01')))
            ->toBe(['platform.store.updated']);
    });

    it('offers only the actions that are really in the log', function () {
        auditEntry('platform.store.updated', null, '2027-01-01 09:00:00+00');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        // Read from the log itself, so a module that starts recording something new appears in the
        // filter without anybody remembering to add it.
        expect(app(ListAuditHandler::class)->actions())->toContain('platform.store.updated');
    });
});
