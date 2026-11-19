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

        return self::signInAs($staffId);
    }

    /** A staff member made by the test - a Super Admin, say - signed in through the panel's door, in their own language. */
    public static function signInAs(string $staffId): mixed
    {
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

    /** What sticks out past the window's edge, for a failure's message: the last few elements, by name. */
    public static function overflow(mixed $page): string
    {
        // The first element out of the window, then its parents up the page, each with its width and left edge.
        return (string) $page->script("(() => { const out = [...document.querySelectorAll('body *')].find((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && (r.right > document.documentElement.clientWidth + 1 || r.left < -1); }); const chain = []; for (let e = out; e && chain.length < 12; e = e.parentElement) { const r = e.getBoundingClientRect(); chain.push(e.tagName + '[' + (e.getAttribute('data-test') || String(e.className).slice(0, 40)) + '] w' + Math.round(r.width) + ' l' + Math.round(r.left)); } return 'client ' + document.documentElement.clientWidth + ': ' + chain.join(' < '); })()");
    }

    /** No Arabic-Indic digit anywhere on the page (owner, 2026-10-06). */
    public static function latinDigits(mixed $page): bool
    {
        return ! (bool) $page->script('/[٠-٩]/.test(document.body.textContent)');
    }

    /**
     * The contrast (WCAG, 1 to 21) of `$text`'s colour on `$ground`'s: its gradient's first colour where it has one (a
     * fade's darkest end, where the words sit), else its background colour - laid over white, a light photo being the
     * worst case under a see-through ground. The browser itself mixes the colours (a canvas pixel), so any CSS colour
     * form counts. 0 where either is missing, or the ground can't be measured: a picture (`url(`, `image-set(`, a live
     * theme's art) or no colour at all. A gradient is read at its first stop only, so it must be solid where the words sit.
     */
    public static function contrast(mixed $page, string $text, string $ground): float
    {
        return (float) $page->script(self::lights($text, $ground).'; return lit ? Math.round(((Math.max(lit[0], lit[1]) + 0.05) / (Math.min(lit[0], lit[1]) + 0.05)) * 100) / 100 : 0; })()');
    }

    /** Whether `$text`'s words are lighter than `$ground`, and the ground itself dark (relative light under 0.1): light words on a dark band. */
    public static function lightOnDark(mixed $page, string $text, string $ground): bool
    {
        return (bool) $page->script(self::lights($text, $ground).'; return lit !== null && lit[0] > lit[1] && lit[1] < 0.1; })()');
    }

    /** The start of a script that sets `lit` to [the words' relative light, the ground's], or null where either is missing. */
    private static function lights(string $text, string $ground): string
    {
        $text = json_encode($text, JSON_THROW_ON_ERROR);
        $ground = json_encode($ground, JSON_THROW_ON_ERROR);

        return "(() => {
            const text = document.querySelector({$text}), ground = document.querySelector({$ground});
            const pixel = (colour) => { const c = document.createElement('canvas').getContext('2d'); c.fillStyle = '#ffffff'; c.fillRect(0, 0, 1, 1); c.fillStyle = colour; c.fillRect(0, 0, 1, 1); return [...c.getImageData(0, 0, 1, 1).data].slice(0, 3); };
            const light = (rgb) => { const [r, g, b] = rgb.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
            let lit = null;
            if (text && ground) {
                const style = getComputedStyle(ground);
                const picture = /url\(|image-set\(/.test(style.backgroundImage);
                const fade = style.backgroundImage.match(/(?:rgba?|hsla?|oklab|oklch|lab|lch|color)\([^()]*\)/);
                const plain = style.backgroundColor !== 'rgba(0, 0, 0, 0)' && style.backgroundColor !== 'transparent';
                if (!picture && (fade || plain)) {
                    lit = [light(pixel(getComputedStyle(text).color)), light(pixel(fade ? fade[0] : style.backgroundColor))];
                }
            }
        ";
    }

    /** A run's own number, so its rows are never another run's (lesson 174). */
    public static function run(): string
    {
        return substr((string) hrtime(true), -10);
    }
}
