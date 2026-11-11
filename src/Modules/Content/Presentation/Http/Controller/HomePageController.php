<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSection;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSectionHandler;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSection;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSectionHandler;
use Modules\Content\Application\Command\OrderHomeSections\OrderHomeSections;
use Modules\Content\Application\Command\OrderHomeSections\OrderHomeSectionsHandler;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSection;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSectionHandler;
use Modules\Content\Application\Command\SaveFooter\SaveFooter;
use Modules\Content\Application\Command\SaveFooter\SaveFooterHandler;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSection;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\HomePage\HomePageHandler;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Content\Domain\Service\HomeSectionSettings;
use Modules\Content\Presentation\Http\Resource\ContentStoreData;
use Modules\Content\Presentation\Http\Resource\FooterData;
use Modules\Content\Presentation\Http\Resource\HomeLogoData;
use Modules\Content\Presentation\Http\Resource\HomePageData;
use Modules\Content\Presentation\Http\Resource\HomeSectionData;
use Modules\Content\Presentation\Http\Resource\HomeSectionPageData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/*
| W1 · Home Page (content.md §4.4, §1.4, §1.5): the store's sections from top to bottom - added, ordered by dragging,
| shown or hidden as the switch is flipped, removed - each section's own page, and the Footer tab. All under
| `content.homepage.manage` in the store, which each handler asks again.
*/
final readonly class HomePageController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_home', 'catalog::admin'];

    /** @var list<string> */
    private const array FOOTER_BOXES = ['about_ar', 'about_en', 'phone', 'email', 'address_ar', 'address_en', 'copyright_ar', 'copyright_en', 'columns', 'column_links', 'column_title_ar', 'column_title_en', 'link_text_ar', 'link_text_en', 'link'];

    private const string HOME = '/admin/content/home';

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, HomePageHandler $home, ContentActionsForReader $actions): Response|JsonResponse
    {
        $store = $this->screens->store($request, ContentPermissions::HOMEPAGE_MANAGE);
        $sections = $home->sections($store->id);
        $rows = array_map(HomeSectionData::fromRead(...), $sections['sections']);

        if ($request->expectsJson()) {
            return new JsonResponse(['sections' => array_map(static fn (HomeSectionData $row): array => $row->toArray(), $rows), 'addable' => $sections['addable']]);
        }

        $tab = Inputs::text($request, 'tab') === 'footer' ? 'footer' : 'sections';

        // Each tab its own page, so neither carries the other's code (JavaScriptBudgetTest).
        return $this->screens->render($tab === 'footer' ? 'Content/Admin/Home/Footer' : 'Content/Admin/Home/Index', (new HomePageData(
            $this->screens->stores(ContentPermissions::HOMEPAGE_MANAGE),
            $store->code,
            $tab,
            $rows,
            $sections['addable'],
            FooterData::fromRead($home->footer($store->id)),
            $actions->translationReady(),
        ))->toArray(), self::WORDS);
    }

    public function edit(string $section, HomePageHandler $home, HomeSections $sections, PlatformApi $platform, ContentActionsForReader $actions): Response
    {
        $read = $home->section($section);
        /** @var HomeSection $found the handler found it in a store the reader holds */
        $found = $sections->find($section);
        /** @var StoreDto $store */
        $store = $platform->store(StoreId::fromString($found->storeId));
        $logos = is_array($read['logos'] ?? null) ? array_values($read['logos']) : [];
        $images = $this->screens->images(array_map(static fn (array $logo): ?string => is_string($logo['mediaId'] ?? null) ? $logo['mediaId'] : null, $logos));

        // The logos band's page alone carries the dragged list (JavaScriptBudgetTest).
        $component = ($read['type'] ?? null) === 'CLIENT_LOGOS' ? 'Content/Admin/Home/Logos' : 'Content/Admin/Home/Section';

        return $this->screens->render($component, (new HomeSectionPageData(
            ContentStoreData::of($store),
            HomeSectionData::fromRead($read),
            array_map(static fn (array $logo): HomeLogoData => new HomeLogoData(
                (string) $logo['mediaId'],
                $images[strtolower((string) $logo['mediaId'])] ?? null,
                (string) $logo['nameAr'],
                is_string($logo['nameEn'] ?? null) ? $logo['nameEn'] : null,
                is_string($logo['link'] ?? null) ? $logo['link'] : null,
            ), $logos),
            HomeSectionSettings::ICONS,
            $actions->translationReady(),
        ))->toArray(), self::WORDS);
    }

    public function add(Request $request, AddHomeSectionHandler $add): RedirectResponse
    {
        try {
            $id = $add->handle(new AddHomeSection(Inputs::text($request, 'store'), (string) Inputs::text($request, 'type')));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return redirect(self::HOME."/{$id}")->with('status', __('content::admin_home.sections.added'));
    }

    public function order(Request $request, OrderHomeSectionsHandler $order): RedirectResponse
    {
        try {
            $order->handle(new OrderHomeSections(Inputs::text($request, 'store'), Inputs::texts($request, 'ids')));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back();
    }

    public function show(Request $request, string $section, ShowHomeSectionHandler $show): RedirectResponse
    {
        $visible = Inputs::flag($request, 'visible');

        try {
            $show->handle(new ShowHomeSection($section, $visible, Inputs::flag($request, 'read')));
        } catch (MissingEnglish) {
            // The spec's own words for Show refused (§4.4 W1): "Add the English first."
            return back()->withErrors(['form' => __('content::admin_home.sections.add_english')]);
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($visible ? 'content::admin_home.sections.shown' : 'content::admin_home.sections.hidden'));
    }

    public function remove(Request $request, string $section, RemoveHomeSectionHandler $remove): RedirectResponse
    {
        try {
            $remove->handle(new RemoveHomeSection($section));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __('content::admin_home.sections.removed'));
    }

    public function fill(Request $request, string $section, FillHomeSectionHandler $fill): RedirectResponse
    {
        $settings = $request->input('settings');
        $logos = $request->input('logos');

        try {
            $fill->handle(new FillHomeSection(
                $section,
                Inputs::version($request),
                is_array($settings) ? $settings : [],
                is_array($logos) ? array_values(array_map(static fn (mixed $logo): array => [
                    'mediaId' => is_array($logo) && is_string($logo['mediaId'] ?? null) ? $logo['mediaId'] : '',
                    'nameAr' => is_array($logo) && is_string($logo['nameAr'] ?? null) ? $logo['nameAr'] : null,
                    'nameEn' => is_array($logo) && is_string($logo['nameEn'] ?? null) && $logo['nameEn'] !== '' ? $logo['nameEn'] : null,
                    'link' => is_array($logo) && is_string($logo['link'] ?? null) && trim($logo['link']) !== '' ? trim($logo['link']) : null,
                ], $logos)) : [],
                Inputs::flag($request, 'translated'),
                Inputs::flag($request, 'read'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::sectionBoxes());
        }

        return redirect(self::HOME.'?store='.$this->code(Inputs::text($request, 'store')))->with('status', __('content::admin_home.section.saved'));
    }

    public function saveFooter(Request $request, SaveFooterHandler $save): RedirectResponse
    {
        try {
            $save->handle(new SaveFooter(
                Inputs::text($request, 'store'),
                Inputs::version($request),
                Inputs::typed($request, 'about_ar'),
                Inputs::typed($request, 'about_en'),
                Inputs::text($request, 'phone'),
                Inputs::text($request, 'email'),
                Inputs::typed($request, 'address_ar'),
                Inputs::typed($request, 'address_en'),
                Inputs::typed($request, 'copyright_ar'),
                Inputs::typed($request, 'copyright_en'),
                self::columns($request->input('columns')),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::FOOTER_BOXES);
        }

        return back()->with('status', __('content::admin_home.footer.saved'));
    }

    /**
     * The footer's columns as the form sent them, read as SaveFooter takes them: a value of the wrong type is none.
     *
     * @return list<array{titleAr: string|null, titleEn: string|null, links: list<array{textAr: string|null, textEn: string|null, link: string|null}>}>
     */
    private static function columns(mixed $sent): array
    {
        $text = static fn (mixed $row, string $key): ?string => is_array($row) && is_string($row[$key] ?? null) && $row[$key] !== '' ? $row[$key] : null;
        $columns = [];

        foreach (is_array($sent) ? $sent : [] as $column) {
            $links = [];

            foreach (is_array($column) && is_array($column['links'] ?? null) ? $column['links'] : [] as $link) {
                $links[] = ['textAr' => $text($link, 'textAr'), 'textEn' => $text($link, 'textEn'), 'link' => $text($link, 'link')];
            }

            $columns[] = ['titleAr' => $text($column, 'titleAr'), 'titleEn' => $text($column, 'titleEn'), 'links' => $links];
        }

        return $columns;
    }

    /**
     * The boxes a section's form has, as `HomeSectionSettings` names them in a refusal.
     *
     * @return list<string>
     */
    private static function sectionBoxes(): array
    {
        $pairs = ['eyebrow', 'title', 'line', 'main_button', 'second_button', 'fact', 'promise_title', 'promise_line', 'heading', 'small_heading', 'individuals_title', 'individuals_line', 'individuals_button', 'companies_title', 'companies_line', 'companies_button', 'logo_name', 'text'];
        $boxes = ['main_button_link', 'second_button_link', 'individuals_button_link', 'companies_button_link', 'promise_icon', 'promises', 'facts', 'count', 'logos', 'logo', 'logo_link', 'link'];

        foreach ($pairs as $pair) {
            $boxes[] = "{$pair}_ar";
            $boxes[] = "{$pair}_en";
        }

        return $boxes;
    }

    /** The address's code of the store the form named by its id: the list it goes back to. */
    private function code(?string $storeId): string
    {
        foreach ($this->screens->stores(ContentPermissions::HOMEPAGE_MANAGE) as $store) {
            if ($store->id === strtolower((string) $storeId)) {
                return $store->code;
            }
        }

        return '';
    }
}
