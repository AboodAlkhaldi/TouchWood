<?php

declare(strict_types=1);

/*
| A page's own data may not use a name that every page already carries (owner, 2026-09-27).
|
| Inertia lays a page's own props over the shared ones, so a page field with a shared name does
| not sit beside it - it replaces it, on that page only, silently. Five pages carried a person's
| communication language as `locale`, which is the displayed language everywhere else, and on them
| the language switch was stuck: it read the email language and offered "English" on a page that
| was already English. The fields are `communicationLocale` now, and this keeps it that way.
|
| The shared names come from the four places that share them: app/Http/Middleware/HandleInertiaRequests
| (with Inertia's own `errors`), Access's ShareAdminPage and ShareStorefrontPage, and Platform's
| ShareStorefront (with `frame`, the shop's parts, and `staffView`). A name added there belongs here too.
*/

const SHARED_PAGE_PROPS = [
    'errors', 'locale', 'direction', 'theme', 'translations', 'flash', 'csrfToken',
    'viewer', 'menu', 'sidebarOpen', 'sidebarSections', 'viewStores', 'panelTimezone', 'routes',
    'shop', 'shopper', 'accountMenu', 'shopperLines', 'staffView', 'frame', 'panelFrame',
];

/**
 * Every page resource: the data classes a controller hands whole to a page, named `...Page` - or `...PageData`, as
 * Content's are (PR 8: a campaign's page carried its linked theme as `theme`, the shared light-or-dark choice, and the
 * panel's theme switch broke on it with nothing to say why).
 *
 * @return list<class-string>
 */
function pageResourceClasses(): array
{
    $classes = [];
    $resources = dirname(__DIR__, 2).'/src/Modules/*/Presentation/Http/Resource';

    foreach ([...(glob("{$resources}/*Page.php") ?: []), ...(glob("{$resources}/*PageData.php") ?: [])] as $file) {
        $module = basename(dirname($file, 4));
        /** @var class-string $class */
        $class = "Modules\\{$module}\\Presentation\\Http\\Resource\\".basename($file, '.php');
        $classes[] = $class;
    }

    return $classes;
}

it('finds the page resources to check', function () {
    // A guard over files must find something, or it passes over nothing. These five are the ones
    // that carried `locale`; more pages only add to them.
    expect(pageResourceClasses())
        ->toContain('Modules\\Access\\Presentation\\Http\\Resource\\AccountPage')
        ->toContain('Modules\\Access\\Presentation\\Http\\Resource\\CustomerAccountPage')
        ->toContain('Modules\\Access\\Presentation\\Http\\Resource\\CustomerDetailsPage')
        ->toContain('Modules\\Access\\Presentation\\Http\\Resource\\StaffMemberPage')
        ->toContain('Modules\\Access\\Presentation\\Http\\Resource\\InviteStaffPage')
        // Content names its page resources `...PageData` (plan PR 10's trap).
        ->toContain('Modules\\Content\\Presentation\\Http\\Resource\\BannersPageData')
        ->toContain('Modules\\Content\\Presentation\\Http\\Resource\\CampaignFormPageData')
        ->toContain('Modules\\Content\\Presentation\\Http\\Resource\\ThemeFormPageData');
});

it('gives no page a field named like one every page already carries', function () {
    $clashes = [];

    foreach (pageResourceClasses() as $class) {
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (in_array($property->getName(), SHARED_PAGE_PROPS, true)) {
                $clashes[] = $class.'::$'.$property->getName();
            }
        }
    }

    expect($clashes)->toBe([], 'A page field replaces the shared prop of the same name on that page: rename it.');
});
