<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\RoleEditorPermissions\EditorPermission;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissions;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Feedback\Application\FeedbackPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Feedback's permissions in Access's catalog (feedback.md §3): what every customer may do — review,
| ask, keep a wishlist — store-free; the staff jobs per store, reviews and questions under Support
| (owner, 2026-10-18), wishlists under Customers; the ratings switch admin-only. Their names in both
| languages are checked with every module's by Access's test.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Feedback declared, sorted
 */
function feedbackDeclaredPermissions(): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'feedback.')),
    ));
    sort($names);

    return $names;
}

function feedbackOffered(string $name, RoleLevel $level): ?EditorPermission
{
    foreach (app(RoleEditorPermissionsHandler::class)->handle(new RoleEditorPermissions($level)) as $item) {
        if ($item->name === $name) {
            return $item;
        }
    }

    return null;
}

it('declares the seven permissions of the spec', function () {
    expect(feedbackDeclaredPermissions())->toBe([
        'feedback.question.answer',
        'feedback.question.ask',
        'feedback.review.moderate',
        'feedback.review.write',
        'feedback.settings.update',
        'feedback.wishlist.manage',
        'feedback.wishlist.view',
    ]);
});

it('gives every customer reviewing, asking and a wishlist, store-free, outside the role editor', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::EveryCustomer)
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull();

    Fx::actAsCustomer(Fx::customer());

    expect(Fx::allows($name, PermissionScope::global()))->toBeTrue();
})->with(['feedback.review.write', 'feedback.question.ask', 'feedback.wishlist.manage']);

it('makes the staff jobs per store, in their areas, open to a staff role, in the role\'s own store', function (string $name, PermissionGroup $group) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe($group)
        ->and($permission?->reserved)->toBeFalse()
        ->and(app(InMemoryPermissionCatalog::class)->isAdminOnly($name))->toBeFalse();

    Fx::actAsStaff(Fx::staffWith([$name], ['sa']));

    expect(Fx::allows($name, PermissionScope::store(StoreId::fromString(Fx::storeId('sa')))))->toBeTrue()
        ->and(Fx::allows($name, PermissionScope::store(StoreId::fromString(Fx::storeId('ae')))))->toBeFalse()
        ->and(app(Authorizer::class)->storesWith($name))->toEqual([StoreId::fromString(Fx::storeId('sa'))]);
})->with([
    'reviews' => ['feedback.review.moderate', PermissionGroup::Support],
    'questions' => ['feedback.question.answer', PermissionGroup::Support],
    'wishlists' => ['feedback.wishlist.view', PermissionGroup::Customers],
]);

it('keeps the ratings switch to admin roles, under Support (owner, 2026-10-09)', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(FeedbackPermissions::SETTINGS_UPDATE);

    expect($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Support)
        ->and(app(InMemoryPermissionCatalog::class)->isAdminOnly(FeedbackPermissions::SETTINGS_UPDATE))->toBeTrue();

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(feedbackOffered(FeedbackPermissions::SETTINGS_UPDATE, RoleLevel::Staff))->toBeNull()
        ->and(feedbackOffered(FeedbackPermissions::SETTINGS_UPDATE, RoleLevel::Admin)?->adminOnly)->toBeTrue();
});
