<?php

declare(strict_types=1);

use Tests\TestCase;

/*
| Unit tests run without the framework. Integration, Feature and Browser tests boot the
| application against PostgreSQL (see phpunit.xml).
|
| Browser tests need it too: the plugin serves the real application to a real browser, and without
| the framework booted even reading a config value fails before the browser is ever opened.
*/

pest()->extend(TestCase::class)->in(
    'Shared/Integration',
    'Shared/Feature',
    'Modules/*/Integration',
    'Modules/*/Feature',
    'Browser',
);

/*
| Waiting in a browser test. The plugin's own assertions read the page once and do not wait (lesson
| 121), and a click only starts the request it sends - so a test that must see what a request
| changes polls for it inside the page, here, rather than with a copy of this in every file.
*/

/**
 * Whether the JavaScript expression comes to hold in the page within four seconds: under the
 * plugin's own five seconds for a script, so it answers rather than times out.
 */
function browserUntil(mixed $page, string $expression): bool
{
    return $page->script(<<<JS
        () => new Promise((resolve) => {
            const until = Date.now() + 4000;
            const tick = () => {
                let held = false;
                try { held = Boolean({$expression}); } catch (error) { held = false; }
                if (held || Date.now() > until) { resolve(held); } else { setTimeout(tick, 50); }
            };
            tick();
        })
        JS) === true;
}

/**
 * Whether the staff member has landed in the panel. The code's submit only starts the sign-in, and
 * a page opened before its answer lands is the sign-in screen again (it raced, and lost, in CI on
 * 2026-10-04) - so every sign-in waits for this before it opens the screen under test.
 */
function signedInToPanel(mixed $page): bool
{
    return browserUntil($page, "window.location.pathname === '/admin'");
}

/*
| Opening a page by its address. The plugin gives navigate() one second to load the page, and when
| the page is slower - a busy machine, a full suite - it asks for the same page again; the first
| answer then lands in the middle of the second and the test fails with "Navigation to … is
| interrupted by another navigation to …" (found 2026-10-06: the app itself asks for nothing). Given
| as navigate()'s options, this lets the load take up to five seconds, as the first page of every
| test already may, before the plugin asks again. Used where that failure was seen (owner,
| 2026-10-06).
*/
const BROWSER_PAGE_LOAD = ['timeout' => 5_000];
