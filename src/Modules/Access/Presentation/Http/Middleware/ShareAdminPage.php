<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use App\Http\Middleware\HandleInertiaRequests;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Access\Application\Query\AdminShell\AdminShellForStaff;
use Modules\Access\Application\Query\CurrentStore\CurrentStoreForStaff;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Symfony\Component\HttpFoundation\Response;
use Tighten\Ziggy\Ziggy;

/**
 * What every admin page carries besides its own data (frontend.md §2.2, stage 2b step 1).
 *
 * The framework's share - the shell, the theme, the direction - is `HandleInertiaRequests`, which
 * knows about no module. These are the admin panel's own facts, so they are shared here, by the
 * module that owns them: who is signed in, the menu of what they may do, and the store they are
 * working in. Platform's own admin screens (step 3) sit under the same prefix and are served the
 * same shell.
 *
 * Every value is a closure, so a page that does not need one never pays for it: Inertia calls only
 * what it sends, and a partial reload of a table asks for nothing here at all.
 */
final readonly class ShareAdminPage
{
    public const string ALIAS = 'admin.page';

    public function __construct(
        private Application $app,
        private AdminShellForStaff $shell,
        private CurrentStoreForStaff $stores,
        private AdminMenu $menu,
        private PlatformApi $platform,
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
            'store' => fn (): ?array => $this->store($locale),
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

        // Asked in Arabic only to read a field off the account; the answer decides the real
        // language, so the argument here cannot change what comes back.
        $viewer = $this->shell->forCurrentStaff('ar');

        return $viewer === null ? 'ar' : $viewer->locale;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function viewer(string $locale): ?array
    {
        $viewer = $this->shell->forCurrentStaff($locale);

        return $viewer === null ? null : [
            'id' => $viewer->id,
            'name' => $viewer->name,
            'roleLabel' => $viewer->roleLabel,
            'avatarUrl' => $viewer->avatarUrl,
            'isSuperAdmin' => $viewer->isSuperAdmin,
        ];
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
                ], $entries),
            ];
        }

        return $groups;
    }

    /**
     * The store the panel is working in, and the stores it may offer. Null for anyone not signed
     * in. `fellBack` is true when the store they had chosen is no longer theirs, and the layout
     * says so once.
     *
     * @return array<string, mixed>|null
     */
    private function store(string $locale): ?array
    {
        $opening = $this->stores->forCurrentStaff();

        if ($opening === null) {
            return null;
        }

        $named = [];

        foreach ($this->platform->stores() as $store) {
            // A store's name is held in both languages; the panel shows the one being read.
            $named[$store->id] = ['id' => $store->id, 'name' => $store->name->in($locale)];
        }

        return [
            'current' => $opening->storeId === null ? null : ($named[$opening->storeId] ?? null),
            'available' => array_values(array_intersect_key($named, array_flip($opening->available))),
            'fellBack' => $opening->fellBack,
        ];
    }
}
