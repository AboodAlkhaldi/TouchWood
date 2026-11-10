<?php

declare(strict_types=1);

namespace Tests\Modules\Content\Support;

use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

/**
 * Content's screens in a real browser (content.md §4.4): a staff member holding these jobs signed in through the panel's
 * door, in the language asked; every page then read at a phone's 375 x 812 once it settles (`browserSoon`).
 */
final class ContentBrowser
{
    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $stores
     */
    public static function signIn(array $permissions, string $locale = 'en', array $stores = ['ae']): mixed
    {
        $staffId = Fx::staffWith($permissions, $stores, RoleLevel::Admin);
        DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);

        $page = visit('/admin/sign-in')
            ->type('#email', (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))
            ->type('#password', Fx::STAFF_PASSWORD)
            ->click('button[type="submit"]');

        expect(browserSoon($page, "window.location.pathname === '/admin/sign-in/code' && document.querySelector('input[autocomplete=\"one-time-code\"]') !== null"))->toBeTrue();

        $page->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
            ->click('button[type="submit"]');

        expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();

        return $page;
    }

    /** A page that fits a phone: no sideways scroll, once it has settled. */
    public static function fitsPhone(mixed $page): bool
    {
        return browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth');
    }

    /** No Arabic-Indic digit anywhere on the page (owner, 2026-10-06). */
    public static function latinDigits(mixed $page): bool
    {
        return ! (bool) $page->script('/[٠-٩]/.test(document.body.textContent)');
    }

    /** A run's own number, so its rows are never another run's (lesson 174). */
    public static function run(): string
    {
        return substr((string) hrtime(true), -10);
    }
}
