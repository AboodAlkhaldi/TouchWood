<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * What every Inertia page carries, whichever area it belongs to (frontend.md §1.3, §2.1).
 *
 * Framework glue, so it knows nothing about any module: the shell it renders into, the asset
 * version, the theme and language this browser asked for, and whatever the last request wants to
 * say. Who is signed in, what the menu holds and which store the panel is in are the admin shell's
 * business, shared by Access's own middleware over /admin - because they are Access's facts, and
 * app/ has no business reaching into a module for them.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * The theme this browser chose, remembered per browser in a cookie so the server renders the
     * right one and nothing flashes (decision of 2026-09-19). Light until the person chooses.
     */
    public const string THEME_COOKIE = 'tw_theme';

    /** The language the panel is *displayed* in, which is not the person's communication language. */
    public const string LOCALE_COOKIE = 'tw_locale';

    /** A year: the choice is a preference, and re-choosing it every session would be a nuisance. */
    public const int COOKIE_MINUTES = 525600;

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = $this->locale($request);

        return [
            ...parent::share($request),
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'theme' => $this->theme($request),
            // Each page adds the files it needs; a page that names none carries no words, which is
            // a mistake a test catches rather than a blank screen nobody explains.
            'translations' => [],
            'flash' => [
                'status' => fn (): ?string => $this->flashString($request, 'status'),
            ],
            /*
            | The proof that a request came from one of our own pages, carried in the page itself.
            |
            | Laravel also writes it to a readable XSRF-TOKEN cookie, and that is what a browser
            | would normally send back. But the admin panel runs its own session on /admin while the
            | storefront's runs on /, and the cookie takes the session's path - so a staff member who
            | looks at the shop and comes back has **two** cookies of that name, and which one is
            | sent first is left to the browser. Laravel reads _token, then X-CSRF-TOKEN, then the
            | cookie; sending it from here means the cookie is never reached for, and the ambiguity
            | stops mattering (owner, 2026-09-22).
            |
            | It travels as a prop rather than a fixed tag in the shell because signing in
            | regenerates the session, and with it this token.
            */
            'csrfToken' => fn (): string => (string) $request->session()->token(),
        ];
    }

    /**
     * The asset version, so a person on an old build is sent the new one instead of being handed
     * JavaScript that no longer matches the server.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    private function theme(Request $request): string
    {
        return $request->cookie(self::THEME_COOKIE) === 'dark' ? 'dark' : 'light';
    }

    /**
     * The displayed language: this browser's choice, else whatever the application decided for the
     * request. An area that knows better - the admin panel, where a staff member has a saved
     * language - sets the application's locale before this runs.
     */
    private function locale(Request $request): string
    {
        $chosen = $request->cookie(self::LOCALE_COOKIE);

        if ($chosen === 'ar' || $chosen === 'en') {
            return $chosen;
        }

        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }

    private function flashString(Request $request, string $key): ?string
    {
        $value = $request->session()->get($key);

        return is_string($value) ? $value : null;
    }
}
