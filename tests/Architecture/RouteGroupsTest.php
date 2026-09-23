<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tighten\Ziggy\Ziggy;

/*
| Routes are sent to a page by area (frontend.md §1.4).
|
| An admin page carries only the admin group; a storefront page only the storefront group. A
| shopper's page therefore never contains the admin URLs.
|
| This is tidiness, not protection — Ziggy's own README says so, and nothing relies on it: every
| admin route checks the person's permission in its handler (handoff §19). What the test is really
| for is the other half: a route that belongs to **neither** group is invisible to the screens, and
| the day somebody writes a link to it, the link is empty.
*/

uses(TestCase::class);

/**
 * Routes Laravel registers for itself. No page links to them by name, so they belong to no area.
 */
const FRAMEWORK_ROUTES = ['generated::health', 'storage.local', 'storage.local.upload'];

/**
 * Which Ziggy groups a route name falls into, given the patterns in config/ziggy.php.
 *
 * @param  array<string, list<string>>  $groups
 * @return list<string>
 */
function groupsForRoute(string $name, array $groups): array
{
    $in = [];

    foreach ($groups as $group => $patterns) {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                $in[] = $group;

                break;
            }
        }
    }

    return $in;
}

it('matches a route name to its group', function () {
    $groups = ['admin' => ['access.staff.*', 'admin.*'], 'storefront' => ['storefront.*']];

    expect(groupsForRoute('access.staff.sign-in', $groups))->toBe(['admin'])
        ->and(groupsForRoute('storefront.account.register', $groups))->toBe(['storefront'])
        ->and(groupsForRoute('something.else', $groups))->toBe([]);
});

it('puts every named route in exactly one area', function () {
    /** @var array<string, list<string>> $groups */
    $groups = (array) config('ziggy.groups');
    $homeless = [];
    $shared = [];
    $named = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        // Routes the framework registers for itself, which no screen ever links to by name: the
        // health check, and the local disk's file server (filesystems.php, "serve"). Named one by
        // one rather than by pattern, so a route of ours can never slip into this list.
        if ($name === null || in_array($name, FRAMEWORK_ROUTES, true)) {
            continue;
        }

        $named++;
        $in = groupsForRoute($name, $groups);

        if ($in === []) {
            $homeless[] = $name;
        } elseif (count($in) > 1) {
            $shared[] = $name.' => '.implode(', ', $in);
        }
    }

    // Guards against a routing change making this pass over nothing.
    expect($named)->toBeGreaterThan(20)
        ->and($homeless)->toBe([])
        ->and($shared)->toBe([]);
});

it('gives an admin page no storefront routes, and the reverse', function () {
    /** @var array<string, list<string>> $groups */
    $groups = (array) config('ziggy.groups');

    $admin = (new Ziggy(group: 'admin'))->toArray();
    $storefront = (new Ziggy(group: 'storefront'))->toArray();

    /** @var array<string, mixed> $adminRoutes */
    $adminRoutes = $admin['routes'];
    /** @var array<string, mixed> $storefrontRoutes */
    $storefrontRoutes = $storefront['routes'];

    // Both lists have something in them, or the assertion below is about nothing.
    expect($adminRoutes)->not->toBeEmpty()
        ->and($storefrontRoutes)->not->toBeEmpty()
        ->and(array_intersect_key($adminRoutes, $storefrontRoutes))->toBe([]);

    foreach (array_keys($storefrontRoutes) as $name) {
        expect(groupsForRoute((string) $name, $groups))->toBe(['storefront']);
    }
});
