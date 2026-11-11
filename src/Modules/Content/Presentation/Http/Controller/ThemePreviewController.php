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
 * browser the signed cookie and opens the theme's first store's home; `DELETE /admin/content/themes/preview` takes it
 * away and goes back. The handler checks the reserved job in each of the theme's stores; this checks nothing (handoff
 * §19).
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

        return redirect('/'.$preview['store']->code.'/ar')->withCookie($cookie->issue($preview['themeId'], $actor->id));
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
