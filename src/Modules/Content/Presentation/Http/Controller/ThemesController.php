<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\DeleteTheme\DeleteTheme;
use Modules\Content\Application\Command\DeleteTheme\DeleteThemeHandler;
use Modules\Content\Application\Command\EndTheme\EndTheme;
use Modules\Content\Application\Command\EndTheme\EndThemeHandler;
use Modules\Content\Application\Command\SaveTheme\SaveTheme;
use Modules\Content\Application\Command\SaveTheme\SaveThemeHandler;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleTheme;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleThemeHandler;
use Modules\Content\Application\Command\ThemeBackToDraft\ThemeBackToDraft;
use Modules\Content\Application\Command\ThemeBackToDraft\ThemeBackToDraftHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Application\Query\Themes\ThemesHandler;
use Modules\Content\Application\ThemeRules;
use Modules\Content\Domain\Model\ThemePart;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Content\Presentation\Http\Resource\NamedChoiceData;
use Modules\Content\Presentation\Http\Resource\RunStateData;
use Modules\Content\Presentation\Http\Resource\ThemeData;
use Modules\Content\Presentation\Http\Resource\ThemeFormPageData;
use Modules\Content\Presentation\Http\Resource\ThemePartData;
use Modules\Content\Presentation\Http\Resource\ThemePartImageData;
use Modules\Content\Presentation\Http\Resource\ThemePromiseData;
use Modules\Content\Presentation\Http\Resource\ThemeRowData;
use Modules\Content\Presentation\Http\Resource\ThemesPageData;
use Modules\Content\Presentation\Http\ThemePreviewCookie;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\Error\DomainError;

/*
| W5 · Themes and W5b, a theme's page (content.md §4.4, §1.8), a Super Admin's (`content.theme.manage`, reserved, which
| each handler asks again in every store the theme picks): the list, New Theme and each theme's page - its names,
| stores, dates and its 11 parts - Save Draft or Schedule Theme (the clash refused under the dates, §1.8), and what its
| menu does: End Now while live, Back to Draft while scheduled, Delete Theme as a draft. Preview Theme is
| ThemePreviewController's.
*/
final readonly class ThemesController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_themes'];

    /** @var list<string> */
    private const array BOXES = ['name_ar', 'name_en', 'starts_at', 'ends_at', 'stores'];

    private const string LIST = '/admin/content/themes';

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, ThemesHandler $themes, PlatformApi $platform): Response|JsonResponse
    {
        $stores = [];

        foreach ($platform->allStores() as $store) {
            $stores[strtolower($store->id)] = $store;
        }

        $rows = array_map(static function (array $row) use ($stores): ThemeRowData {
            /** @var list<array{storeId: string, state: string}> $each */
            $each = $row['stores'];
            $first = $stores[$each[0]['storeId'] ?? ''] ?? null;

            return new ThemeRowData(
                (string) $row['id'],
                (string) $row['nameAr'],
                is_string($row['nameEn']) ? $row['nameEn'] : null,
                array_map(static fn (array $store): RunStateData => new RunStateData($store['storeId'], isset($stores[$store['storeId']]) ? $stores[$store['storeId']]->code : '', $store['state']), $each),
                (string) $row['startsLocal'],
                (string) $row['endsLocal'],
                // Every moment typed is read on each store's own clock: the first store's zone is named (§1.1 rule 6).
                $first?->timezone,
                (int) $row['parts'],
                (string) $row['state'],
                (int) $row['version'],
            );
        }, $themes->list(Inputs::text($request, 'after')));
        $next = count($rows) === ScreenReads::PAGE ? $rows[count($rows) - 1]->id : null;

        if ($request->expectsJson()) {
            return new JsonResponse(['themes' => array_map(static fn (ThemeRowData $row): array => $row->toArray(), $rows), 'next' => $next]);
        }

        return $this->screens->render('Content/Admin/Themes/Index', (new ThemesPageData($rows, $next))->toArray(), self::WORDS);
    }

    public function create(ThemesHandler $themes, ContentActionsForReader $actions): Response
    {
        // Asked as the list is: a Super Admin's (§3).
        $themes->list(null);

        return $this->screens->render('Content/Admin/Themes/Form', (new ThemeFormPageData(
            $this->screens->stores(ContentPermissions::THEME_MANAGE),
            null,
            [],
            [],
            [],
            $actions->translationReady(),
            false,
        ))->toArray(), self::WORDS);
    }

    public function edit(Request $request, string $theme, ThemesHandler $themes, ContentActionsForReader $actions, ThemePreviewCookie $preview, ActorContext $actors, PlatformApi $platform): Response
    {
        $read = $themes->theme($theme);
        $found = $read['theme'];
        $mediaIds = array_merge([], ...array_map(static fn (ThemePart $part): array => array_values($part->images), $read['parts']));
        $actor = $actors->current();
        $codes = [];

        foreach ($platform->allStores() as $store) {
            $codes[strtolower($store->id)] = $store->code;
        }

        return $this->screens->render('Content/Admin/Themes/Form', (new ThemeFormPageData(
            $this->screens->stores(ContentPermissions::THEME_MANAGE),
            new ThemeData(
                $found->id,
                $found->nameAr,
                $found->nameEn,
                array_keys($found->stores),
                $found->startsLocal,
                $found->endsLocal,
                $found->version,
                $read['state'],
                array_map(static fn (string $storeId, string $state): RunStateData => new RunStateData($storeId, $codes[$storeId] ?? '', $state), array_keys($read['states']), array_values($read['states'])),
                $read['unread'],
            ),
            array_map(static fn (ThemePart $part): ThemePartData => new ThemePartData(
                $part->part->value,
                $part->textAr,
                $part->textEn,
                $part->link,
                array_values(array_map(static fn (array $words): ThemePromiseData => new ThemePromiseData($words['ar'], $words['en']), $part->extras)),
                array_map(static fn (string $key, string $mediaId): ThemePartImageData => new ThemePartImageData($key, $mediaId), array_keys($part->images), array_values($part->images)),
            ), $read['parts']),
            $this->screens->images($mediaIds),
            array_map(static fn (array $campaign): NamedChoiceData => new NamedChoiceData($campaign['id'], $campaign['nameAr'], $campaign['nameEn']), $read['campaigns']),
            $actions->translationReady(),
            $actor->type === ActorType::Staff && $preview->themeFor($request, $actor->id) === $found->id,
        ))->toArray(), self::WORDS);
    }

    public function store(Request $request, SaveThemeHandler $save, ScheduleThemeHandler $schedule): RedirectResponse
    {
        return $this->save($request, null, $save, $schedule);
    }

    public function update(Request $request, string $theme, SaveThemeHandler $save, ScheduleThemeHandler $schedule): RedirectResponse
    {
        return $this->save($request, $theme, $save, $schedule);
    }

    public function end(Request $request, string $theme, EndThemeHandler $end): RedirectResponse
    {
        return self::act($request, fn () => $end->handle(new EndTheme($theme, Inputs::version($request))), 'content::admin_themes.ended');
    }

    public function draft(Request $request, string $theme, ThemeBackToDraftHandler $draft): RedirectResponse
    {
        return self::act($request, fn () => $draft->handle(new ThemeBackToDraft($theme, Inputs::version($request))), 'content::admin_themes.drafted');
    }

    public function delete(Request $request, string $theme, DeleteThemeHandler $delete): RedirectResponse
    {
        try {
            $delete->handle(new DeleteTheme($theme, Inputs::version($request)));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return redirect(self::LIST)->with('status', __('content::admin_themes.deleted'));
    }

    /**
     * Save Draft, or Schedule Theme: saved, then scheduled at the version the save made. A new one whose Schedule is
     * refused - a clash among the reasons, said under the dates - stays a draft, and its own page says why.
     */
    private function save(Request $request, ?string $id, SaveThemeHandler $save, ScheduleThemeHandler $schedule): RedirectResponse
    {
        $version = Inputs::version($request);

        try {
            $saved = $save->handle(new SaveTheme(
                $id,
                $version,
                Inputs::typed($request, 'name_ar'),
                Inputs::typed($request, 'name_en'),
                Inputs::text($request, 'starts_at'),
                Inputs::text($request, 'ends_at'),
                Inputs::texts($request, 'stores'),
                self::parts($request),
                Inputs::flag($request, 'translated'),
                Inputs::flag($request, 'read'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::boxes());
        }

        if (Inputs::text($request, 'intent') === 'schedule') {
            try {
                $schedule->handle(new ScheduleTheme($saved, $id === null ? 1 : $version + 1, Inputs::flag($request, 'read')));
            } catch (DomainError $error) {
                // Two steps, so the save stands: the page says so beside the refusal, and the form takes what was saved.
                return ($id === null ? Refusals::to(self::LIST."/{$saved}", $error, self::boxes()) : Refusals::back($request, $error, self::boxes()))->with('status', __('content::admin_themes.saved_not_scheduled'));
            }

            return redirect(self::LIST."/{$saved}")->with('status', __('content::admin_themes.scheduled'));
        }

        return redirect(self::LIST."/{$saved}")->with('status', __('content::admin_themes.saved'));
    }

    /**
     * The form's boxes by the names its refusals carry: the theme's own, and each part's (`part_hero`, its words, link,
     * promises and per-language images), so a part's refusal lands under the part.
     *
     * @return list<string>
     */
    private static function boxes(): array
    {
        $boxes = self::BOXES;

        foreach (ThemePartKind::cases() as $kind) {
            $part = 'part_'.strtolower($kind->value);
            array_push($boxes, $part, "{$part}_ar", "{$part}_en", "{$part}_link");

            foreach ($kind->slots() as $slot) {
                $boxes[] = "{$part}_".strtolower($slot).'_en';
            }
        }

        foreach (range(1, ThemeRules::MAX_PROMISES) as $n) {
            array_push($boxes, "part_promises_promise_{$n}_ar", "part_promises_promise_{$n}_en");
        }

        return $boxes;
    }

    /**
     * The parts as the form sends them; anything that is not a part's map is left for the handler to refuse.
     *
     * @return list<array<string, mixed>>
     */
    private static function parts(Request $request): array
    {
        $value = $request->input('parts');
        $parts = [];

        foreach (is_array($value) ? $value : [] as $part) {
            if (is_array($part)) {
                /** @var array<string, mixed> $part */
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @param  callable(): mixed  $change
     */
    private static function act(Request $request, callable $change, string $toast): RedirectResponse
    {
        try {
            $change();
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($toast));
    }
}
