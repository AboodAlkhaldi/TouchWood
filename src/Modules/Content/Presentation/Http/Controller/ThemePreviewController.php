<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Modules\Content\Application\Query\PreviewTheme\PreviewThemeHandler;
use Modules\Content\Presentation\Http\ThemePreviewCookie;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Unauthorized;

/**
 * **Preview Theme** and **Stop Preview** (content.md W5b): `POST /admin/content/themes/{theme}/preview` gives this
 * browser the signed cookie and goes back to the theme's page; `DELETE /admin/content/themes/preview` takes it away and
 * goes back. The handler checks the reserved job in each of the theme's stores; this checks nothing (handoff §19).
 *
 * **The shop shows a preview only in a staff view** (§9.3d D5), which Access opens (`POST /admin/staff-view`) and
 * Content may not call, so the page opens it next, in the theme's first store (PR 9's review: a redirect straight to
 * the shop opened no staff view, and the preview showed nothing).
 */
final readonly class ThemePreviewController
{
    public function start(string $theme, PreviewThemeHandler $handler, ThemePreviewCookie $cookie, ActorContext $actors): RedirectResponse
    {
        $preview = $handler->handle($theme);
        $actor = $actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized(PreviewThemeHandler::PERMISSION);
        }

        return redirect()->back()->withCookie($cookie->issue($preview['themeId'], $actor->id));
    }

    public function stop(ThemePreviewCookie $cookie): RedirectResponse
    {
        return redirect()->back()->withCookie($cookie->forget());
    }

    /** Stop Preview on the shop's own line (PR 9): the same cookie forgotten, back to the page it was pressed on. */
    public function stopOnShop(ThemePreviewCookie $cookie): RedirectResponse
    {
        return redirect()->back()->withCookie($cookie->forget());
    }
}
