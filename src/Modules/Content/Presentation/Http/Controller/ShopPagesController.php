<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Public\Contracts\ShopBestSellers;
use Modules\Catalog\Public\Contracts\ShopCategoryTiles;
use Modules\Catalog\Public\Contracts\ShopProductCards;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Query\Shop\ContentFrame;
use Modules\Content\Application\Query\Shop\ContentHome;
use Modules\Content\Application\Query\Shop\HomeAlternates;
use Modules\Content\Application\Query\Shop\ShopDocuments;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Content\Domain\Repository\SlugHistory;
use Modules\Content\Presentation\Http\Resource\ContentAddressData;
use Modules\Content\Presentation\Http\Resource\ContentAlternateData;
use Modules\Content\Presentation\Http\Resource\ContentHeadData;
use Modules\Content\Presentation\Http\Resource\ShopBlogPageData;
use Modules\Content\Presentation\Http\Resource\ShopCampaignPageData;
use Modules\Content\Presentation\Http\Resource\ShopDocumentPageData;
use Modules\Content\Presentation\Http\Resource\ShopHomePageData;
use Modules\Content\Presentation\Http\Resource\ShopProjectsPageData;
use Modules\Content\Presentation\Http\ShopFrameOfRequest;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * **The shop's own Content pages** (content.md §4.5): H1 the store's home, H2 a page, H3 the blog and its categories,
 * H4 a post, H5 the projects, H6 a project, H7 a campaign. Each carries its canonical address and its other language's
 * (§1.14) through Content's own `ContentAddressData`; a home names every on store's home too (Q21 (a)).
 *
 * **Only what is published answers.** An old address, or the other language's, answers 301 to the current one **only
 * when that page answers 200** (§1.1 rule 15); a draft, an item gone or an address nobody held is the shop's own 404, the
 * same as an address that never existed. The projects are hidden - `/projects` 404 - until the first is published
 * (§1.13). A campaign's page answers only while its run is live: before, 404; after its end, 301 to the home.
 *
 * Request-scoped services come as each action's arguments, never the constructor's (lesson 200): the router keeps a
 * controller as long as its route, and Platform's answers hold the request's store directory and settings.
 */
final readonly class ShopPagesController
{
    /** Catalog's shop words too: the home, posts, projects and campaign pages draw Catalog's product cards and prices. @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'catalog::shop', 'content::shop'];

    /** Posts and projects on a page of their lists (H3, H5). */
    public const int LIST_PAGE = 12;

    /** Products on a page of a campaign's (H7), as a page of the catalogue's lists. */
    public const int CAMPAIGN_PAGE = 24;

    public function __construct(
        private Page $page,
        private ShopDocuments $documents,
        private SlugHistory $slugs,
    ) {}

    public function home(Request $request, PlatformApi $platform, ContentHome $home, ShopFrameOfRequest $frame, ShopBestSellers $bestSellers, ShopCategoryTiles $tiles): Response
    {
        $store = self::store($request);
        $locale = self::locale();
        $content = $home->for($store, $locale);
        ['kept' => $kept, 'now' => $now] = $frame->of($request, $store, $locale);
        $parts = ContentFrame::theme($kept, $now)['parts'] ?? [];
        // A live theme's hero artwork replaces the desktop hero's banner and leads the phone's three (§1.6, §1.8 part 2).
        $art = ContentFrame::picture($parts[ThemePartKind::Hero->value]['images'] ?? [], $locale, null, null);

        if ($art !== null) {
            $content['heroBanner'] = ['id' => 'theme', 'link' => null, 'alt' => null, 'image' => $art['desktop']];
            $content['phoneBanners'] = array_slice([['id' => 'theme', 'link' => null, 'alt' => null, 'image' => $art['phone']], ...$content['phoneBanners']], 0, 3);
        }
        $bestSeller = $categories = null;

        foreach ($content['sections'] as $section) {
            if ($section['type'] === HomeSectionType::BestSellers->value) {
                $bestSeller = self::count($section['words']['count'] ?? null, 8);
            }

            if ($section['type'] === HomeSectionType::Categories->value) {
                $categories = self::count($section['words']['count'] ?? null, 8);
            }
        }

        // Read per request, only for a section on the page: they depend on who is looking (§1.4).
        $sellers = $bestSeller === null ? ['cards' => [], 'cardFacts' => []] : $bestSellers->read($store->storeId(), $locale, $bestSeller);
        $tileRow = $categories === null ? [] : $tiles->read($store->storeId(), $locale, $categories);
        $sections = array_values(array_filter(
            $content['sections'],
            // A section with nothing to show draws nothing (§1.4).
            static fn (array $section): bool => match ($section['type']) {
                HomeSectionType::BestSellers->value => $sellers['cards'] !== [],
                HomeSectionType::Categories->value => $tileRow !== [],
                default => true,
            },
        ));
        $points = $this->points($platform, $store, $locale, $sections);

        if ($points === null) {
            $sections = array_values(array_filter($sections, static fn (array $section): bool => $section['type'] !== HomeSectionType::Points->value));
        }

        $seo = ContentFrame::seo($kept);
        $root = rtrim(url('/'), '/');
        $alternates = [];

        foreach (HomeAlternates::of($store, $platform->stores(), $root) as $hrefLang => $href) {
            $alternates[] = new ContentAlternateData($hrefLang, $href);
        }

        return $this->page->render('Content/Storefront/Home', (new ShopHomePageData(
            $sections,
            $content['heroBanner'],
            $content['phoneBanners'],
            $sellers['cards'],
            $sellers['cardFacts'],
            $tileRow,
            $points,
            // The bands' artwork is the theme's custom properties (theme.style); here, whether each has some, and its words.
            [
                'promises' => $parts[ThemePartKind::Promises->value]['extras'] ?? [],
                'promisesArt' => ($parts[ThemePartKind::Promises->value]['images'] ?? []) !== [],
                'pointsHeading' => $parts[ThemePartKind::Points->value]['text'] ?? null,
                'pointsArt' => ($parts[ThemePartKind::Points->value]['images'] ?? []) !== [],
                'logosHeading' => $parts[ThemePartKind::Logos->value]['text'] ?? null,
                'logosArt' => ($parts[ThemePartKind::Logos->value]['images'] ?? []) !== [],
            ],
            new ContentAddressData("{$root}/{$store->code}/{$locale}", "{$root}/{$store->code}/".self::other($locale), self::other($locale), false, $alternates),
            new ContentHeadData($seo['site'] ?? $store->name->in($locale), $seo['description'], self::shareImage($seo['image'])),
        ))->toArray(), self::WORDS)->toResponse($request);
    }

    public function page(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame, ShopProductCards $cards): Response
    {
        return $this->document($request, $platform, DocumentKind::Page, 'storefront.content.page', $frame, $cards);
    }

    public function post(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame, ShopProductCards $cards): Response
    {
        return $this->document($request, $platform, DocumentKind::Post, 'storefront.content.post', $frame, $cards);
    }

    public function project(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame, ShopProductCards $cards): Response
    {
        return $this->document($request, $platform, DocumentKind::Project, 'storefront.content.project', $frame, $cards);
    }

    public function blog(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame): Response
    {
        return $this->blogList($request, $platform, $frame, null);
    }

    public function blogCategory(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame): Response
    {
        return $this->blogList($request, $platform, $frame, self::slug($request));
    }

    public function projects(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame): Response
    {
        $store = self::store($request);
        $locale = self::locale();

        // Hidden until the first is published (§1.13): no page at all.
        if (! $this->documents->anyPublished(DocumentKind::Project, $store->id)) {
            throw new NotFoundHttpException;
        }

        $after = self::cursor($request);
        $rows = $this->documents->projects($store->id, $locale, $after, self::LIST_PAGE + 1);
        $next = count($rows) > self::LIST_PAGE ? self::cursorOf($rows[self::LIST_PAGE - 1]) : null;
        $rows = array_slice($rows, 0, self::LIST_PAGE);
        $urls = self::urls($platform, array_map(static fn (array $row): ?string => $row['photoMediaId'], $rows));
        $seo = ContentFrame::seo($frame->of($request, $store, $locale)['kept']);

        return $this->page->render('Content/Storefront/Projects', (new ShopProjectsPageData(
            array_map(static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'slug' => $row['slug'], 'city' => $row['city'], 'photo' => $urls[$row['photoMediaId'] ?? ''] ?? null], $rows),
            $next,
            self::address('storefront.content.projects', $locale, [], [], $after !== null),
            new ContentHeadData(self::titled($seo, self::word('content::shop.projects.title')), $seo['description'], self::shareImage($seo['image'])),
        ))->toArray(), self::WORDS)->toResponse($request);
    }

    public function campaign(Request $request, ShopFrameOfRequest $frame, ShopProductCards $cards, CampaignPrices $prices): Response
    {
        $store = self::store($request);
        $locale = self::locale();
        $run = $this->documents->run($store->id, $locale, self::slug($request));
        $now = Moments::now();

        if ($run === null || ! in_array($run['state'], ['LIVE', 'ENDED'], true)) {
            throw new NotFoundHttpException;
        }

        // After its end, its old links (an ad, a banner) go to the home rather than to nothing (§9.3 #34).
        if ($run['state'] === 'ENDED' || $run['endsAt'] <= $now) {
            return new RedirectResponse(route('storefront.home'), 301);
        }

        if ($run['inOtherLanguage']) {
            return $run['slug'] === null ? throw new NotFoundHttpException : self::moved($request, 'storefront.content.campaign', $run['slug'], true);
        }

        // Every product the run reaches - its categories' and brands' across every store - paged through what this store lists
        // for this shopper in one read (catalog.md amendment 47): 24 a page while more are there, never an empty page.
        $shown = $cards->page($store->storeId(), $locale, $prices->reachedProductIds($store->storeId(), $run['id']), self::text($request, 'after'), self::CAMPAIGN_PAGE);
        $next = $shown['next'];

        $seo = ContentFrame::seo($frame->of($request, $store, $locale)['kept']);

        return $this->page->render('Content/Storefront/Campaign', (new ShopCampaignPageData(
            $run['name'],
            CarbonImmutable::instance($run['endsAt'])->toIso8601String(),
            $shown['cards'],
            $next,
            $shown['cardFacts'],
            // A campaign lasts a few weeks: kept out of search engines, as Catalog's results pages are (the build's pick).
            self::address('storefront.content.campaign', $locale, ['slug' => (string) $run['slug']], $run['otherSlug'] === null ? null : ['slug' => $run['otherSlug']], true),
            new ContentHeadData(self::titled($seo, $run['name']), $seo['description'], self::shareImage($seo['image'])),
        ))->toArray(), self::WORDS)->toResponse($request);
    }

    private function document(Request $request, PlatformApi $platform, DocumentKind $kind, string $route, ShopFrameOfRequest $frame, ShopProductCards $cards): Response
    {
        $store = self::store($request);
        $locale = self::locale();
        $slug = self::slug($request);
        $found = $this->documents->published($kind, $store->id, $locale, $slug);

        if ($found === null) {
            return $this->movedOrMissing($request, $store, $kind->value, $locale, $slug, $route, fn (string $itemId, string $in): ?string => $this->documents->currentSlug($kind, $itemId, $in));
        }

        $mediaIds = [$found['shareMediaId'], $found['coverMediaId'], ...$found['photos'], ...array_map(static fn (array $image): string => $image['mediaId'], $found['images'])];
        $urls = self::urls($platform, $mediaIds);
        // A body image is drawn with its own width and height (§1.2): asked only of a body holding one.
        $sizes = $found['images'] === [] ? [] : $platform->mediaOf(array_map(static fn (array $image): string => $image['mediaId'], $found['images']));
        $images = [];

        foreach ($found['images'] as $image) {
            $file = $urls[$image['mediaId']] ?? null;
            $images[$image['ref']] = [
                'src' => $file === null ? null : self::format($file['detail'] ?? $file['zoom'] ?? []),
                'width' => $sizes[$image['mediaId']]->width ?? null,
                'height' => $sizes[$image['mediaId']]->height ?? null,
            ];
        }

        $products = $found['products'] === [] ? ['cards' => [], 'cardFacts' => []] : $cards->read($store->storeId(), $locale, $found['products']);
        $seo = ContentFrame::seo($frame->of($request, $store, $locale)['kept']);
        $picture = $urls[$found['shareMediaId'] ?? ''] ?? $urls[$found['coverMediaId'] ?? ''] ?? $urls[$found['photos'][0] ?? ''] ?? $seo['image'];
        $blocks = is_array($found['body']['blocks'] ?? null) ? array_values(array_filter($found['body']['blocks'], 'is_array')) : [];

        return $this->page->render('Content/Storefront/Document', (new ShopDocumentPageData(
            $kind->key(),
            $found['title'],
            $blocks,
            $images,
            $kind === DocumentKind::Post ? $found['publishedAt']?->format(DATE_ATOM) : null,
            $found['summary'],
            $urls[$found['coverMediaId'] ?? ''] ?? null,
            $found['coverAlt'],
            $found['city'],
            $found['category'],
            array_values(array_filter(array_map(static fn (string $photo): ?array => $urls[$photo] ?? null, $found['photos']))),
            $products['cards'],
            $products['cardFacts'],
            self::address($route, $locale, ['slug' => $found['slug']], $found['otherSlug'] === null ? null : ['slug' => $found['otherSlug']], false),
            new ContentHeadData(self::titled($seo, $found['seoTitle'] ?? $found['title']), $found['seoDescription'] ?? $found['summary'] ?? $seo['description'], self::shareImage($picture)),
        ))->toArray(), self::WORDS)->toResponse($request);
    }

    private function blogList(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame, ?string $categorySlug): Response
    {
        $store = self::store($request);
        $locale = self::locale();
        $categories = $this->documents->blogCategories($store->id);
        $current = null;

        if ($categorySlug !== null) {
            foreach ($categories as $category) {
                if ($category["slug_{$locale}"] === $categorySlug) {
                    $current = $category;
                }
            }

            if ($current === null) {
                // An old address, or the other language's, of a category still holding a published post (§1.1 rule 15).
                $listed = array_column($categories, null, 'id');

                return $this->movedOrMissing($request, $store, 'BLOG_CATEGORY', $locale, $categorySlug, 'storefront.content.blog-category', static fn (string $itemId, string $in): ?string => isset($listed[$itemId]) ? $listed[$itemId]["slug_{$in}"] : null);
            }
        }

        $after = self::cursor($request);
        $rows = $this->documents->posts($store->id, $locale, $current['id'] ?? null, $after, self::LIST_PAGE + 1);
        $next = count($rows) > self::LIST_PAGE ? self::cursorOf($rows[self::LIST_PAGE - 1]) : null;
        $rows = array_slice($rows, 0, self::LIST_PAGE);
        $urls = self::urls($platform, array_map(static fn (array $row): ?string => $row['coverMediaId'], $rows));
        $seo = ContentFrame::seo($frame->of($request, $store, $locale)['kept']);
        $name = $current === null ? null : (string) $current["name_{$locale}"];
        $other = self::other($locale);

        return $this->page->render('Content/Storefront/Blog', (new ShopBlogPageData(
            array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'summary' => $row['summary'],
                'cover' => $urls[$row['coverMediaId'] ?? ''] ?? null,
                'coverAlt' => $row['coverAlt'],
                'publishedAt' => $row['publishedAt']->format(DATE_ATOM),
                'category' => $row['category'],
            ], $rows),
            $next,
            array_map(static fn (array $category): array => ['name' => (string) $category["name_{$locale}"], 'slug' => (string) $category["slug_{$locale}"]], $categories),
            $current === null ? null : (string) $current["slug_{$locale}"],
            $name,
            $current === null
                // An empty blog, or a further page of it, is kept out of search engines: nothing new to find there.
                ? self::address('storefront.content.blog', $locale, [], [], $rows === [] || $after !== null)
                : self::address('storefront.content.blog-category', $locale, ['slug' => (string) $current["slug_{$locale}"]], ['slug' => (string) $current["slug_{$other}"]], $after !== null),
            new ContentHeadData(self::titled($seo, $name ?? self::word('content::shop.blog.title')), $seo['description'], self::shareImage($seo['image'])),
        ))->toArray(), self::WORDS)->toResponse($request);
    }

    /**
     * An address no published item holds now: the item that held it before, in this language or the other, answers 301
     * to its current address **when that answers 200** (`$current` gives it only then); anything else is the 404 of an
     * address that never existed, so a draft stays hidden (§1.1 rule 15).
     *
     * @param  callable(string, string): ?string  $current  an item's current address in a language while it answers
     */
    private function movedOrMissing(Request $request, StoreDto $store, string $kind, string $locale, string $slug, string $route, callable $current): RedirectResponse
    {
        foreach ([$locale, self::other($locale)] as $held) {
            $itemId = $this->slugs->holder($store->id, $kind, $held, $slug);
            $now = $itemId === null ? null : $current(strtolower($itemId), $locale);

            if ($now !== null && $now !== $slug) {
                return self::moved($request, $route, $now, $held !== $locale);
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * The Points band's figures (§1.4 POINTS, Q16 (b)): read from the store's settings, never typed; none while the
     * programme is off, or no Points section is shown.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return array{earn: int, off: int, currency: string}|null
     */
    private function points(PlatformApi $platform, StoreDto $store, string $locale, array $sections): ?array
    {
        if (! in_array(HomeSectionType::Points->value, array_column($sections, 'type'), true)) {
            return null;
        }

        $setting = static fn (string $key) => $platform->setting("loyalty.points.{$key}", $store->storeId());

        if (! $setting('enabled')->bool()) {
            return null;
        }

        return ['earn' => $setting('earn_per_unit')->int(), 'off' => $setting('points_per_unit_off')->int(), 'currency' => $store->currencySymbol($locale)];
    }

    /**
     * Files' sizes by media id, in one read; a file whose sizes are not ready is left out.
     *
     * @param  list<string|null>  $mediaIds
     * @return array<string, array<string, array<string, string>>>
     */
    private static function urls(PlatformApi $platform, array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter($mediaIds, static fn (?string $id): bool => $id !== null && $id !== '')));
        $urls = [];

        foreach ($ids === [] ? [] : $platform->mediaUrlsOf($ids) as $id => $media) {
            if ($media->variants !== []) {
                $urls[strtolower((string) $id)] = $media->variants;
            }
        }

        return $urls;
    }

    /**
     * The address of a list or an item, and the same in the other language: a list has one in both; an item only where
     * it has a slug there.
     *
     * @param  array<string, string>  $here
     * @param  array<string, string>|null  $there
     */
    private static function address(string $route, string $locale, array $here, ?array $there, bool $noindex): ContentAddressData
    {
        $other = self::other($locale);

        return new ContentAddressData(route($route, $here), $there === null ? null : route($route, ['locale' => $other, ...$there]), $there === null ? null : $other, $noindex);
    }

    /** A permanent move to the current address, the shopper's query kept; across the languages a browser keeps none. */
    private static function moved(Request $request, string $route, string $slug, bool $acrossLanguages): RedirectResponse
    {
        $query = array_filter($request->query(), static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value) && ! in_array($key, ['slug', 'store', 'locale'], true), ARRAY_FILTER_USE_BOTH);

        return new RedirectResponse(route($route, [...$query, 'slug' => $slug]), 301, $acrossLanguages ? ['Cache-Control' => 'no-cache'] : []);
    }

    /**
     * The page's title through the store's pattern (§1.14): `:title | :site`, or the title alone with no site name.
     *
     * @param  array{site: string|null, pattern: string|null}  $seo
     */
    private static function titled(array $seo, string $title): string
    {
        if ($seo['site'] === null) {
            return $title;
        }

        return strtr($seo['pattern'] ?? ':title | :site', [':title' => $title, ':site' => $seo['site']]);
    }

    /**
     * A share image's address: the largest ready size short of the original, which is never served (handoff §5.5).
     *
     * @param  array<string, array<string, string>>  $sizes
     */
    private static function shareImage(array $sizes): ?string
    {
        return self::format($sizes['detail'] ?? $sizes['zoom'] ?? $sizes['card'] ?? []);
    }

    /**
     * @param  array<string, string>  $formats
     */
    private static function format(array $formats): ?string
    {
        return $formats['jpg'] ?? $formats['webp'] ?? $formats['avif'] ?? null;
    }

    /**
     * A list's cursor from the address (`after`): the last row's moment and id, written by `cursorOf`; anything else
     * is a page that is not one.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function cursor(Request $request): ?array
    {
        $after = self::text($request, 'after');

        if ($after === null) {
            return null;
        }

        $parts = explode('_', $after, 2);

        if (count($parts) !== 2 || preg_match('/\A\d{10}\z/', $parts[0]) !== 1 || preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/i', $parts[1]) !== 1) {
            throw new NotFoundHttpException;
        }

        return [CarbonImmutable::createFromTimestampUTC((int) $parts[0])->format(DATE_ATOM), strtolower($parts[1])];
    }

    /**
     * @param  array{id: string, publishedAt: \DateTimeImmutable}  $row
     */
    private static function cursorOf(array $row): string
    {
        return $row['publishedAt']->getTimestamp().'_'.$row['id'];
    }

    private static function word(string $key): string
    {
        $word = trans($key);

        return is_string($word) ? $word : $key;
    }

    private static function count(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    private static function store(Request $request): StoreDto
    {
        // ResolveStore forgets the route's store; its attribute holds it (lessons 74, 75).
        $store = $request->attributes->get(StorefrontArea::RESOLVED_STORE);

        return $store instanceof StoreDto ? $store : throw new NotFoundHttpException;
    }

    /** @return 'ar'|'en' */
    private static function locale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }

    /** @return 'ar'|'en' */
    private static function other(string $locale): string
    {
        return $locale === 'ar' ? 'en' : 'ar';
    }

    private static function slug(Request $request): string
    {
        $slug = $request->route('slug');

        return is_string($slug) ? trim($slug) : '';
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
