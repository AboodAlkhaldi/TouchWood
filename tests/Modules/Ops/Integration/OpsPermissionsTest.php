<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Ops\Application\OpsPermissions;
use Shared\Application\PermissionScope;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Ops' permissions in Access's catalog (ops.md §3): six jobs per store under Messages and Reports
| (access.md amendment 86), a message to many for admins only; one's own bell held by every customer
| and every staff member; the nightly removal reserved to the system. Their names in both languages
| are checked with every module's by Access's PermissionCatalogTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Ops declared, sorted
 */
function opsDeclaredPermissions(): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'ops.')),
    ));
    sort($names);

    return $names;
}

it('declares the six jobs of the spec, the two bells, the system\'s removal and the SMS countries', function () {
    expect(opsDeclaredPermissions())->toBe([
        'ops.inbox.view_own',
        'ops.log.prune',
        'ops.message.send_many',
        'ops.message.send_one',
        'ops.newsletter.manage',
        'ops.notice.manage',
        'ops.panel_inbox.view_own',
        'ops.report.daily',
        'ops.report.view',
        'ops.sms_countries.manage',
    ]);
});

it('makes the six jobs per store, under Messages and Reports, a message to many for admins only', function (string $name, bool $adminOnly) {
    $catalog = app(InMemoryPermissionCatalog::class);
    $permission = $catalog->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::MessagesAndReports)
        ->and($permission?->reserved)->toBeFalse()
        ->and($catalog->isAdminOnly($name))->toBe($adminOnly);

    if (! $adminOnly) {
        Fx::actAsStaff(Fx::staffWith([$name], ['sa']));

        expect(Fx::allows($name, Fx::inStore('sa')))->toBeTrue()
            ->and(Fx::allows($name, Fx::inStore('ae')))->toBeFalse();
    }
})->with([
    'one customer' => [OpsPermissions::MESSAGE_SEND_ONE, false],
    'many' => [OpsPermissions::MESSAGE_SEND_MANY, true],
    'notices' => [OpsPermissions::NOTICE_MANAGE, false],
    'newsletter' => [OpsPermissions::NEWSLETTER_MANAGE, false],
    'reports' => [OpsPermissions::REPORT_VIEW, false],
    'daily reports' => [OpsPermissions::REPORT_DAILY, false],
]);

it('gives every customer the shop\'s bell and every staff member the panel\'s, each only their own kind', function () {
    $catalog = app(InMemoryPermissionCatalog::class);

    expect($catalog->definition(OpsPermissions::INBOX_VIEW_OWN)?->audience)->toBe(PermissionAudience::EveryCustomer)
        ->and($catalog->definition(OpsPermissions::PANEL_INBOX_VIEW_OWN)?->audience)->toBe(PermissionAudience::EveryStaff);

    Fx::actAsCustomer(Fx::customer());
    expect(Fx::allows(OpsPermissions::INBOX_VIEW_OWN, PermissionScope::global()))->toBeTrue()
        ->and(Fx::allows(OpsPermissions::PANEL_INBOX_VIEW_OWN, PermissionScope::global()))->toBeFalse();

    // A staff member whose role names no bell still has theirs: it is never in a role.
    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));
    expect(Fx::allows(OpsPermissions::PANEL_INBOX_VIEW_OWN, PermissionScope::global()))->toBeTrue()
        ->and(Fx::allows(OpsPermissions::INBOX_VIEW_OWN, PermissionScope::global()))->toBeFalse();
});

it('keeps the removal of old rows the system\'s alone', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(OpsPermissions::LOG_PRUNE);

    expect($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global);
});

it('reserves the SMS countries to Super Admins, store-free, as Platform\'s places are (access.md amendment 90)', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(OpsPermissions::SMS_COUNTRIES_MANAGE);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull();

    // Not an admin, whatever their role holds.
    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa'], RoleLevel::Admin));
    expect(Fx::allows(OpsPermissions::SMS_COUNTRIES_MANAGE, PermissionScope::global()))->toBeFalse();

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(Fx::allows(OpsPermissions::SMS_COUNTRIES_MANAGE, PermissionScope::global()))->toBeTrue();
});
