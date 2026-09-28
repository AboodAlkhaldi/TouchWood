<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Application\Query\ListAudit\AuditEntryRow;
use Modules\Platform\Application\Query\ListAudit\ListAudit;
use Modules\Platform\Application\Query\ListAudit\ListAuditHandler;
use Modules\Platform\Public\Enums\MediaVisibility;
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

/**
 * A file in the library, written straight in: what is being tested is how its entries read back.
 */
function auditMediaFile(MediaVisibility $visibility): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('platform.media')->insert([
        'id' => $id,
        'visibility' => $visibility->value,
        'disk' => $visibility === MediaVisibility::Private ? 'local' : 'public',
        'object_key' => 'media/'.$id.'.pdf',
        'original_filename' => 'paper.pdf',
        'mime' => 'application/pdf',
        'bytes' => 2_400,
        'checksum' => hash('sha256', $id),
        'created_at' => '2027-02-01 09:00:00+00',
        'updated_at' => '2027-02-01 09:00:00+00',
    ]);

    return $id;
}

/**
 * One entry about a file, by a staff member, as MediaAudit writes them.
 *
 * @param  array<string, mixed>  $changes
 */
function auditMediaEntry(string $action, string $mediaId, array $changes, string $occurredAt, string $actorId): int
{
    return (int) DB::table('platform.audit_entries')->insertGetId([
        'occurred_at' => $occurredAt,
        'recorded_at' => $occurredAt,
        'source' => 'WEB',
        'store_id' => null,
        'actor_type' => 'STAFF',
        'actor_id' => $actorId,
        'action' => $action,
        'subject_type' => 'platform.media',
        'subject_id' => $mediaId,
        'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
        'ip_address' => '10.0.0.7',
    ]);
}

/**
 * The entries of one day about one file, as the handler hands them back.
 *
 * @return list<AuditEntryRow>
 */
function auditEntriesAbout(string $mediaId): array
{
    return array_values(array_filter(
        app(ListAuditHandler::class)->handle(new ListAudit(from: '2027-02-01'))->entries,
        static fn (AuditEntryRow $entry): bool => $entry->subjectId === $mediaId || ($entry->withheld && $entry->occurredAt >= '2027-02-01'),
    ));
}

describe('entries about a private file (b2b.md amendment 8(c))', function () {
    it('keeps what was done, when and by whom, and withholds which file and what changed, from a reader who may not see private files', function () {
        $uploader = Fx::staff(superAdmin: true);
        $paper = auditMediaFile(MediaVisibility::Private);
        auditMediaEntry('platform.media.uploaded', $paper, ['visibility' => [null, 'PRIVATE'], 'mime' => [null, 'application/pdf'], 'for_module' => [null, 'b2b']], '2027-02-01 10:00:00+00', $uploader);
        auditMediaEntry('platform.media.alt_text_changed', $paper, ['alt_en' => [null, 'Commercial register of Company X']], '2027-02-01 11:00:00+00', $uploader);
        Fx::actAsAdmin(['*'], [PlatformPermissions::AUDIT_VIEW]);

        $entries = auditEntriesAbout($paper);

        expect(array_map(static fn (AuditEntryRow $entry): array => [$entry->action, $entry->subjectType, $entry->subjectId, $entry->changes, $entry->actorId, $entry->ipAddress, $entry->withheld], $entries))->toBe([
            ['platform.media.alt_text_changed', 'platform.media', null, [], $uploader, '10.0.0.7', true],
            ['platform.media.uploaded', 'platform.media', null, [], $uploader, '10.0.0.7', true],
        ]);
    });

    it('withholds them for a private file that has since been deleted, from what its upload recorded', function () {
        $uploader = Fx::staff(superAdmin: true);
        // No row: the file is gone, and only its entries remain.
        $gone = strtolower((string) Str::ulid());
        auditMediaEntry('platform.media.uploaded', $gone, ['visibility' => [null, 'PRIVATE']], '2027-02-01 10:00:00+00', $uploader);
        auditMediaEntry('platform.media.deleted', $gone, ['checksum' => ['abc', null]], '2027-02-01 12:00:00+00', $uploader);
        Fx::actAsAdmin(['*'], [PlatformPermissions::AUDIT_VIEW]);

        $entries = auditEntriesAbout($gone);

        expect(array_map(static fn (AuditEntryRow $entry): array => [$entry->action, $entry->subjectId, $entry->changes, $entry->withheld], $entries))->toBe([
            ['platform.media.deleted', null, [], true],
            ['platform.media.uploaded', null, [], true],
        ]);
    });

    it('shows the whole entry to someone who may see private files', function (Closure $reader) {
        $paper = auditMediaFile(MediaVisibility::Private);
        auditMediaEntry('platform.media.uploaded', $paper, ['visibility' => [null, 'PRIVATE']], '2027-02-01 10:00:00+00', Fx::staff(superAdmin: true));
        Fx::actAsStaff($reader());

        $entries = auditEntriesAbout($paper);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->subjectId)->toBe($paper)
            ->and($entries[0]->changes)->toBe(['visibility' => [null, 'PRIVATE']])
            ->and($entries[0]->withheld)->toBeFalse();
    })->with([
        'a Super Admin' => [fn () => Fx::staff(superAdmin: true)],
        'an admin given the permission' => [fn () => Fx::staffWith([PlatformPermissions::AUDIT_VIEW, PlatformPermissions::MEDIA_PRIVATE_VIEW], ['*'], RoleLevel::Admin)],
    ]);

    it('leaves a public file\'s entries whole for the same reader', function () {
        $photo = auditMediaFile(MediaVisibility::Public);
        auditMediaEntry('platform.media.uploaded', $photo, ['visibility' => [null, 'PUBLIC']], '2027-02-01 10:00:00+00', Fx::staff(superAdmin: true));
        Fx::actAsAdmin(['*'], [PlatformPermissions::AUDIT_VIEW]);

        $entries = auditEntriesAbout($photo);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->subjectId)->toBe($photo)
            ->and($entries[0]->changes)->toBe(['visibility' => [null, 'PUBLIC']])
            ->and($entries[0]->withheld)->toBeFalse();
    });

    it('leaves another subject with a private file\'s id alone', function () {
        // Only an entry about media is about a file: the same id on something else is not one. The
        // file's own entry is on the page too, so the private file really is asked about.
        $paper = auditMediaFile(MediaVisibility::Private);
        auditMediaEntry('platform.media.uploaded', $paper, ['visibility' => [null, 'PRIVATE']], '2027-02-01 09:00:00+00', Fx::staff(superAdmin: true));
        DB::table('platform.audit_entries')->insert([
            'occurred_at' => '2027-02-01 10:00:00+00',
            'recorded_at' => '2027-02-01 10:00:00+00',
            'source' => 'WEB',
            'store_id' => null,
            'actor_type' => 'SYSTEM',
            'action' => 'platform.store.updated',
            'subject_type' => 'platform.store',
            'subject_id' => $paper,
            'changes' => json_encode(['name' => ['a', 'b']], JSON_THROW_ON_ERROR),
        ]);
        Fx::actAsAdmin(['*'], [PlatformPermissions::AUDIT_VIEW]);

        $entries = auditEntriesAbout($paper);

        expect(array_map(static fn (AuditEntryRow $entry): array => [$entry->subjectType, $entry->subjectId, $entry->withheld], $entries))->toBe([
            ['platform.store', $paper, false],
            ['platform.media', null, true],
        ]);
    });
});
