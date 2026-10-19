<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Platform\Application\Menu\InMemoryAdminMenu;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The admin menu's Support section (feedback.md §2.3, platform.md §9.4; handoff §14: "Support — Product
| questions"). Feedback registers no entry yet — its screens come later — so the section is proved on a
| menu of the test's own, leaving the application's untouched.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('takes an entry under Support and shows the section after Marketing and before Points', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(
        new MenuEntryDto('feedback', 'questions', PermissionGroup::Support->value, 'feedback.test.questions', FeedbackPermissions::QUESTION_ANSWER, 10),
        new MenuEntryDto('test', 'points', PermissionGroup::Points->value, 'feedback.test.points', FeedbackPermissions::QUESTION_ANSWER, 10),
        new MenuEntryDto('test', 'coupons', PermissionGroup::Marketing->value, 'feedback.test.coupons', FeedbackPermissions::QUESTION_ANSWER, 10),
    );

    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['sa']));

    expect(array_keys($menu->forCurrentActor()))->toBe(['marketing', 'support', 'points'])
        ->and(array_map(static fn (MenuEntryDto $entry): string => $entry->key, $menu->forCurrentActor()['support'] ?? []))->toBe(['questions']);
});

it('offers the Support section only to someone holding one of its jobs', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(new MenuEntryDto('feedback', 'reviews', PermissionGroup::Support->value, 'feedback.test.reviews', FeedbackPermissions::REVIEW_MODERATE, 10));

    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));
    expect(array_keys($menu->forCurrentActor()))->toBe(['support']);

    Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['sa']));
    expect($menu->forCurrentActor())->toBe([]);
});
