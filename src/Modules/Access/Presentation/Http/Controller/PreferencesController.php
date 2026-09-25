<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The theme and the displayed language, remembered **per browser** in a cookie so the server
 * renders the right one from the first byte and nothing flashes (decided 2026-09-19).
 *
 * **Mounted in both areas, because a cookie belongs to a browser and not to a panel.** It used to
 * live on the panel's controller and answer only at /admin/preferences, and the shop's own theme
 * button posted there - to an endpoint behind a different session, whose token a shop page does not
 * carry. It failed on every press, on every page of the shop, and left the button looking broken
 * (found by the owner, 2026-09-25).
 *
 * The shop's copy asks for no store: the country page has none, and the theme is the same choice
 * whichever country somebody is looking at.
 *
 * The displayed language is not the person's communication language: emails and SMS codes keep
 * going in the language saved on their account, which only they change, in their own settings
 * (Access amendment 16). On the shop the displayed language is not a cookie at all - it is in the
 * address - so only the theme is ever sent from there.
 *
 * A value we do not know is ignored rather than refused. Nobody types this; it arrives from our own
 * toggle, and the worst a wrong one can do is leave the page as it was.
 */
final readonly class PreferencesController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $preference = $request->string('preference')->toString();
        $value = $request->string('value')->toString();

        $cookie = match (true) {
            $preference === 'theme' && in_array($value, ['light', 'dark'], true) => HandleInertiaRequests::THEME_COOKIE,
            $preference === 'locale' && in_array($value, ['ar', 'en'], true) => HandleInertiaRequests::LOCALE_COOKIE,
            default => null,
        };

        $back = back();

        return $cookie === null
            ? $back
            // Not httpOnly on purpose: it decides nothing and protects nothing, and a person's own
            // browser may read which theme it is showing.
            : $back->withCookie(cookie($cookie, $value, HandleInertiaRequests::COOKIE_MINUTES, httpOnly: false));
    }
}
