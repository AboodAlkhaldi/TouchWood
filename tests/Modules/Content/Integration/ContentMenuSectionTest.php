<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Content\Application\ContentPermissions;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Platform\Application\Menu\InMemoryAdminMenu;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Promotions\Application\PromotionsPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The admin menu's Content section (content.md §4.3, Q12 (a); row L3): one section after Marketing, before
| Support. Content registers its entries with its screens (PR 7), so the section is proved on a menu of the
| test's own, leaving the application's untouched; the application's holds the entries built so far.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('takes an entry under Content and shows the section after Marketing and before Support', function () {
    $menu = new InMemoryAdminMenu(app());
    $menu->register(
        new MenuEntryDto('test', 'support', PermissionGroup::Support->value, 'content.test.support', FeedbackPermissions::REVIEW_MODERATE, 10),
        new MenuEntryDto('content', 'pages', PermissionGroup::Content->value, 'content.test.pages', ContentPermissions::PAGE_MANAGE, 70, icon: 'content'),
        new MenuEntryDto('test', 'coupons', PermissionGroup::Marketing->value, 'content.test.coupons', PromotionsPermissions::COUPON_MANAGE, 10),
    );

    Fx::actAsStaff(Fx::staffWith([ContentPermissions::PAGE_MANAGE, FeedbackPermissions::REVIEW_MODERATE, PromotionsPermissions::COUPON_MANAGE], ['sa']));

    expect(array_keys($menu->forCurrentActor()))->toBe(['marketing', 'content', 'support'])
        ->and(array_map(static fn (MenuEntryDto $entry): string => $entry->key, $menu->forCurrentActor()['content'] ?? []))->toBe(['pages']);
});

it('holds in the application\'s own menu only the entries whose screens are built, in §4.3\'s order', function () {
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(array_map(static fn (MenuEntryDto $entry): string => $entry->key, app(InMemoryAdminMenu::class)->forCurrentActor()['content'] ?? []))->toBe(['home_page', 'bars', 'banners', 'popups', 'pages', 'blog', 'projects', 'seo', 'redirects']);
});

it('names the Content section in both languages, and draws it with its own icon', function () {
    expect(__('access::permission_groups.content', [], 'en'))->toBe('Content')
        ->and(__('access::permission_groups.content', [], 'ar'))->toBe('المحتوى')
        ->and((string) file_get_contents(base_path('resources/js/components/MenuIcon.tsx')))->toMatch('/^\s*content: LayoutTemplate,$/m');
});
