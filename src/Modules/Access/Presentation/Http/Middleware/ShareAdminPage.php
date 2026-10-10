<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\AdminArea;
use App\Http\Middleware\HandleInertiaRequests;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Access\Application\Query\AdminShell\AdminShellForStaff;
use Modules\Access\Application\Query\StoresForStaff\StoresForStaff;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\Dto\StoreDto;
use Symfony\Component\HttpFoundation\Response;
use Tighten\Ziggy\Ziggy;

/**
 * What every admin page carries besides its own data (frontend.md §2.2, stage 2b step 1).
 *
 * The framework's share - the shell, the theme, the direction - is `HandleInertiaRequests`, which
 * knows about no module. These are the admin panel's own facts, so they are shared here, by the
 * module that owns them: who is signed in, the menu of what they may do, the stores View Store may
 * open, and the zone a screen showing no one store writes its moments in (the panel has no store
 * worked in since 2026-10-06, access.md amendment 64). Platform's own admin screens (step 3) sit
 * under the same prefix and are served the same shell.
 *
 * Every value is a closure, so a page that does not need one never pays for it: Inertia calls only
 * what it sends, and a partial reload of a table asks for nothing here at all.
 */
final readonly class ShareAdminPage
{
    public const string ALIAS = AdminArea::PAGE;

    public function __construct(
        private Application $app,
        private AdminShellForStaff $shell,
        private StoresForStaff $stores,
        private StoreChoices $choices,
        private AdminMenu $menu,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->locale($request);
        $this->app->setLocale($locale);

        Inertia::share([
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'viewer' => fn (): ?array => $this->viewer($locale),
            'menu' => fn (): array => $this->menuFor($locale),
            // Whether the sidebar is open or shut down to its rail. Remembered per browser by the
            // sidebar component itself, and read back here so the server's first paint already has
            // it right - worked out in the browser instead, the page would flicker on every load.
            'sidebarOpen' => $request->cookie(HandleInertiaRequests::SIDEBAR_COOKIE) !== 'false',
            // Which of its business areas are open, read back for the same reason.
            'sidebarSections' => $this->openSections($request),
            // The stores View Store may open (access.md amendment 64): the panel has no store worked
            // in, so the person picks one there - with one store, it opens at once.
            'viewStores' => fn (): array => $this->viewStores($locale),
            // The zone a screen showing no one store writes its moments in: the base store's (the
            // owner, 2026-10-06); a store screen sends its own store's beside it (frontend.md §1.10).
            'panelTimezone' => fn (): string => $this->choices->baseTimezone(),
            // Only the admin group: a page here never carries the storefront's URLs (§1.4). It
            // travels with the page because Blade's @routes never reaches the SSR renderer.
            'routes' => fn (): array => (new Ziggy(group: 'admin'))->toArray(),
        ]);

        return $next($request);
    }

    /**
     * The language the panel is **displayed** in. This browser's choice first; otherwise the
     * person's own saved language, which is their communication language (amendment 16); Arabic
     * when nobody is signed in to ask.
     */
    private function locale(Request $request): string
    {
        $chosen = $request->cookie(HandleInertiaRequests::LOCALE_COOKIE);

        if ($chosen === 'ar' || $chosen === 'en') {
            return $chosen;
        }

        // The same answer the person block uses: the shell is read once per request (amendment 65).
        $viewer = $this->shell->forCurrentStaff();

        return $viewer === null ? 'ar' : $viewer->locale;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function viewer(string $locale): ?array
    {
        $viewer = $this->shell->forCurrentStaff();

        return $viewer === null ? null : [
            'id' => $viewer->id,
            'name' => $viewer->name,
            'roleLabel' => $viewer->roleLabel($locale),
            'avatarUrl' => $viewer->avatarUrl,
            'isSuperAdmin' => $viewer->isSuperAdmin,
        ];
    }

    /**
     * The sidebar's open business areas, as this browser left them: their keys, joined by dots in a
     * cookie the browser writes. It arrives as typed by whoever holds the browser, so only what can
     * be a group's key is kept - a word of letters and underscores - and no more of them than the
     * menu could ever hold; a key that names no group simply opens nothing.
     *
     * @return list<string>
     */
    private function openSections(Request $request): array
    {
        $cookie = $request->cookie(HandleInertiaRequests::SIDEBAR_SECTIONS_COOKIE);

        if (! is_string($cookie) || $cookie === '') {
            return [];
        }

        // \z, not $: a $ would let a key through with a newline after it.
        $keys = array_filter(explode('.', $cookie), fn (string $key): bool => preg_match('/^[a-z_]{1,40}\z/', $key) === 1);

        return array_slice(array_values(array_unique($keys)), 0, 20);
    }

    /**
     * The menu, as Platform's registry answered for this person, with each entry's words and link
     * resolved. A group with nothing in it for them is not here at all.
     *
     * @return list<array<string, mixed>>
     */
    private function menuFor(string $locale): array
    {
        $groups = [];

        foreach ($this->menu->forCurrentActor() as $group => $entries) {
            $groups[] = [
                'key' => $group,
                'label' => (string) __('access::permission_groups.'.$group, [], $locale),
                'entries' => array_map(fn (MenuEntryDto $entry): array => [
                    'module' => $entry->module,
                    'key' => $entry->key,
                    'label' => (string) __($entry->module.'::menu.'.$entry->key, [], $locale),
                    'href' => route($entry->routeName, [], false),
                    'comingSoon' => $entry->comingSoon(),
                    'icon' => $entry->icon,
                    // How many wait behind it — failed jobs, say — for this person, who is offered
                    // the entry; null when it counts nothing (frontend.md E7).
                    'count' => $this->menu->countOf($entry),
                ], $entries),
            ];
        }

        return $groups;
    }

    /**
     * The person's stores, as View Store's menu lists them: a Super Admin every store, an off one
     * marked; anyone else their stores that are on. Empty for anyone not signed in.
     *
     * @return list<array{code: string, name: string, isActive: bool}>
     */
    private function viewStores(string $locale): array
    {
        return array_map(static fn (StoreDto $store): array => [
            'code' => $store->code,
            'name' => $store->name->in($locale),
            'isActive' => $store->isActive,
        ], $this->stores->forCurrentStaff());
    }
}
