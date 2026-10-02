<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListStaff\ListStaff;
use Modules\Access\Application\Query\ListStaff\ListStaffHandler;
use Modules\Access\Application\Query\ListStaff\StaffSummary;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Presentation\Http\Resource\StaffGroup;
use Modules\Access\Presentation\Http\Resource\StaffPages;
use Modules\Access\Presentation\Http\Resource\StaffRow;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Platform\Application\Query\ListAudit\ListAudit;
use Modules\Platform\Application\Query\ListAudit\ListAuditHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    // The names below are read in English, unless a test says otherwise.
    app()->setLocale('en');
});

/*
| Super Admins are invisible (access.md §1.6, §3.3, amendment 54; owner, 2026-10-02): to admins and
| staff they are not listed, not counted and not named; only Super Admins see each other, in a section
| of their own; wherever a staff member is named to someone else, a Super Admin reads as "System
| administrator", with no name and no id.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

/**
 * One audit entry by this staff member, from a web request, about this subject.
 */
function invisibleAuditEntry(string $actorId, string $subjectType = 'platform.store', string $subjectId = 'invisible-test', ?string $requestedBy = null): int
{
    return (int) DB::table('platform.audit_entries')->insertGetId([
        'occurred_at' => '2027-03-01 10:00:00+00',
        'recorded_at' => '2027-03-01 10:00:00+00',
        'source' => $requestedBy === null ? 'WEB' : 'JOB',
        'store_id' => null,
        'actor_type' => $requestedBy === null ? 'STAFF' : 'SYSTEM',
        'actor_id' => $requestedBy === null ? $actorId : null,
        'requested_by_type' => $requestedBy === null ? null : 'STAFF',
        'requested_by_id' => $requestedBy,
        'action' => 'platform.store.updated',
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'changes' => json_encode(['tax_rate_basis_points' => [1500, 1600]], JSON_THROW_ON_ERROR),
        'ip_address' => $requestedBy === null ? '10.20.30.40' : null,
    ]);
}

/**
 * @return array<string, array{actorId: ?string, actorName: ?string, ip: ?string, requestedById: ?string, requestedByName: ?string, subjectId: ?string, subjectName: ?string, changes: array<string, mixed>}>
 */
function invisibleAuditRead(?string $actorFilter = null): array
{
    $rows = [];

    foreach (app(ListAuditHandler::class)->handle(new ListAudit(from: '2027-03-01', until: '2027-03-01', actorId: $actorFilter))->entries as $entry) {
        $rows['entry-'.$entry->id] = [
            'actorId' => $entry->actorId,
            'actorName' => $entry->actorName,
            'ip' => $entry->ipAddress,
            'requestedById' => $entry->requestedById,
            'requestedByName' => $entry->requestedByName,
            'subjectId' => $entry->subjectId,
            'subjectName' => $entry->subjectName,
            'changes' => $entry->changes,
        ];
    }

    return $rows;
}

describe('the staff list', function () {
    it('shows a Super Admin the Super Admins in a section of their own, never among the admins', function () {
        $other = Fx::staff(superAdmin: true, firstName: 'Other');
        $admin = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['*'], RoleLevel::Admin);
        $reader = Fx::staff(superAdmin: true, firstName: 'Reader');
        Fx::actAsStaff($reader);

        $page = app(ListStaffHandler::class)->handle(new ListStaff(perPage: 100));
        $listed = array_map(static fn (StaffSummary $person): string => $person->id, $page->staff);
        $superAdmins = array_map(static fn (StaffSummary $person): string => $person->id, $page->superAdmins);
        sort($superAdmins);
        $expected = [$other, $reader];
        sort($expected);

        expect($listed)->toContain($admin)
            ->and($listed)->not->toContain($other)
            ->and($listed)->not->toContain($reader)
            ->and($superAdmins)->toBe($expected)
            ->and($page->superAdmins[0]->isSuperAdmin)->toBeTrue()
            // Counted apart: the total is the list's.
            ->and($page->total)->toBe(count($listed));
    });

    it('hands the staff screen the Super Admins as its first section, apart from the admins', function () {
        $other = Fx::staff(superAdmin: true, firstName: 'Other');
        $admin = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['*'], RoleLevel::Admin);
        Fx::actAsStaff(Fx::staff(superAdmin: true, firstName: 'Reader'));

        $groups = app(StaffPages::class)->list(app(ListStaffHandler::class), null, null)->groups;
        $members = static fn (StaffGroup $group): array => array_map(static fn (StaffRow $row): string => $row->id, $group->staff);

        expect($groups[0]->key)->toBe(StaffPages::SUPER_ADMINS)
            ->and($groups[0]->label)->toBe('Super Admins')
            ->and($members($groups[0]))->toContain($other)
            ->and($groups[1]->key)->toBe(StaffPages::ADMINS)
            ->and($members($groups[1]))->toBe([$admin]);
    });

    it('gives an admin of every store no Super Admins section, and no Super Admin in the list or the count', function () {
        Fx::staff(superAdmin: true);
        $admin = Fx::actAsAdmin(['*'], [AccessPermissions::STAFF_VIEW]);

        $page = app(ListStaffHandler::class)->handle(new ListStaff(perPage: 100));

        expect($page->superAdmins)->toBe([])
            ->and(array_map(static fn (StaffSummary $person): string => $person->id, $page->staff))->toBe([$admin])
            ->and($page->total)->toBe(1);
    });
});

describe('a staff member named to someone else', function () {
    it('names a Super Admin "System administrator" to an admin, with no id, and by name to a Super Admin', function () {
        $superAdmin = Fx::staff(superAdmin: true, firstName: 'Hidden');
        $colleague = Fx::staff(firstName: 'Visible');
        Fx::actAsAdmin(['*'], [AccessPermissions::STAFF_VIEW]);

        $toAdmin = app(AccessApi::class)->staffDisplayNames([$superAdmin, $colleague, 'not-a-staff-id']);

        expect($toAdmin)->toHaveCount(2)
            ->and($toAdmin[$superAdmin]->name)->toBe('System administrator')
            ->and($toAdmin[$superAdmin]->id)->toBeNull()
            ->and($toAdmin[$superAdmin]->systemAdministrator)->toBeTrue()
            ->and($toAdmin[$colleague]->name)->toBe('Visible Member')
            ->and($toAdmin[$colleague]->id)->toBe($colleague);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $toSuperAdmin = app(AccessApi::class)->staffDisplayNames([$superAdmin]);

        expect($toSuperAdmin[$superAdmin]->name)->toBe('Hidden Member')
            ->and($toSuperAdmin[$superAdmin]->id)->toBe($superAdmin)
            ->and($toSuperAdmin[$superAdmin]->systemAdministrator)->toBeFalse();
    });

    it('says "System administrator" in the reader\'s language', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        Fx::actAsAdmin(['*'], [AccessPermissions::STAFF_VIEW]);
        app()->setLocale('ar');

        expect(app(AccessApi::class)->staffDisplayNames([$superAdmin])[$superAdmin]->name)->toBe('مدير النظام');
    });

    it('masks a Super Admin in the audit log to an admin: actor, requester and subject, with no id or address', function () {
        $superAdmin = Fx::staff(superAdmin: true, firstName: 'Hidden');
        $byHand = invisibleAuditEntry($superAdmin);
        $queued = invisibleAuditEntry('', requestedBy: $superAdmin);
        $about = invisibleAuditEntry(Fx::staff(firstName: 'Visible'), 'access.staff_user', $superAdmin);
        Fx::actAsAdmin(['*'], [PlatformPermissions::AUDIT_VIEW]);

        $read = invisibleAuditRead();

        expect($read['entry-'.$byHand])->toMatchArray(['actorId' => null, 'actorName' => 'System administrator', 'ip' => null])
            ->and($read['entry-'.$queued])->toMatchArray(['requestedById' => null, 'requestedByName' => 'System administrator'])
            ->and($read['entry-'.$about])->toMatchArray(['actorName' => 'Visible Member', 'subjectId' => null, 'subjectName' => 'System administrator', 'changes' => []])
            // The entries themselves stay.
            ->and($read)->toHaveCount(3);
    });

    it('shows a Super Admin the audit log in full', function () {
        $superAdmin = Fx::staff(superAdmin: true, firstName: 'Hidden');
        $byHand = invisibleAuditEntry($superAdmin);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(invisibleAuditRead()['entry-'.$byHand])->toMatchArray([
            'actorId' => $superAdmin,
            'actorName' => 'Hidden Member',
            'ip' => '10.20.30.40',
        ]);
    });

    it('answers an admin who filters the log by a Super Admin\'s id as for an id that never existed', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        invisibleAuditEntry($superAdmin);
        Fx::actAsAdmin(['*'], [PlatformPermissions::AUDIT_VIEW]);

        expect(invisibleAuditRead($superAdmin))->toBe([]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(invisibleAuditRead($superAdmin))->toHaveCount(1);
    });
});
