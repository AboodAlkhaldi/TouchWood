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
use Modules\Content\Domain\Model\BannerPlacement;
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
use Modules\Content\Presentation\Http\Resource\ShopPolicyPageData;
use Modules\Content\Presentation\Http\Resource\ShopProjectPageData;
use Modules\Content\Presentation\Http\Resource\ShopProjectsPageData;
use Modules\Content\Presentation\Http\ShopFrameOfRequest;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Public\Contracts\CampaignPrices;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * **The shop's own Content pages** (content.md §4.5): H1 the store's home, H2 a page, H3 the blog and its categories,
 * H4 a post, H5 the projects, H6 a project, H7 a campaign; and a policy (flags-2026-11.md S3). Each carries its canonical address and its other language's
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
        // A live theme's hero artwork leads the banners at both widths, taking one of the five places (shop-design.md
        // §3.1, P2; content.md §1.8 part 2 as amended).
        $art = ContentFrame::picture($parts[ThemePartKind::Hero->value]['images'] ?? [], $locale, null, null);

        if ($art !== null) {
            $content['banners'] = array_slice([['id' => 'theme', 'link' => null, 'alt' => null, 'image' => $art['desktop'], 'phone' => $art['phone'], 'title' => null, 'line' => null, 'button' => null], ...$content['banners']], 0, BannerPlacement::HomeHero->liveAtOnce());
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
            $content['banners'],
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

    /**
     * A policy (flags-2026-11.md S3.1, S3.2): the one published in this store at this address, "Last updated" with the
     * moment its text was published (F15). `?version=N` (owner, 2026-11-20) shows that kept text; the current number shows
     * the current page; anything but a number is the shop's 404.
     *
     * **A text no longer valid stays readable** (the owner's decisions of 2026-11-20): a past version, or the text of a
     * policy switched off in this store, opens labelled as old - the dates it was in force in this store and a link to the
     * store's current policy of its kind, if any - kept out of search engines, the sitemap and the footer. `?policy=<id>`
     * names the policy a version belongs to (the builder's pick: after a split, a turn-off or an address taken, two
     * policies' version 3 may have been read at one address in one store); the staff's order page links that way. Only a
     * version that was in force in this store opens; one never published, never.
     *
     * A plain address forwards (301) to where its policy is now, as a page's does, before an old text is looked for; an
     * address asking for a version looks for it first, then forwards, the version asked kept.
     */
    public function policy(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame): Response
    {
        $store = self::store($request);
        $locale = self::locale();
        $slug = self::slug($request);
        $number = self::askedNumber($request);
        $pinned = self::askedPolicy($request);
        // A version asked is read with its own text and files (the current number's are the current text's).
        $found = $this->documents->policy($store->id, $locale, $slug, images: $number === null);

        if ($found !== null && ($pinned === null || $pinned === $found['id'])) {
            if ($number === null || $number === $found['version']) {
                $there = $found['otherSlug'] === null ? null : ['slug' => $found['otherSlug']];

                return $this->policyPage($request, $platform, $frame, $store, $locale, [
                    'title' => $found['title'], 'body' => $found['body'], 'images' => $found['images'], 'version' => $found['version'],
                    'updatedAt' => $found['updatedAt'], 'kind' => $found['kind'], 'past' => false, 'current' => null, 'inForce' => [],
                    'address' => self::address('storefront.content.policy', $locale, ['slug' => $found['slug']], $there, false),
                    'seoTitle' => $found['seoTitle'], 'seoDescription' => $found['seoDescription'], 'shareMediaId' => $found['shareMediaId'],
                ]);
            }

            $periods = $this->documents->policyPeriods($store->id, $found['id'], $number);

            if ($periods === []) {
                // A number alone the current policy never had here: another policy's text once read at this address with
                // that number (one switched off or split away before this one took the address), else nothing.
                $other = $pinned === null ? $this->documents->pastPolicy($store->id, $locale, $slug, $number, null) : null;

                if ($other === null || $other['id'] === $found['id']) {
                    throw new NotFoundHttpException;
                }

                $current = self::currentOfKind($frame->of($request, $store, $locale)['kept'], $other['kind'], $other['id']);

                return $this->pastPolicyPage($request, $platform, $frame, $store, $locale, $other['id'], $other['kind'], $other['number'], $other['periods'], $current, null);
            }

            $current = route('storefront.content.policy', ['slug' => $found['slug']]);

            return $this->pastPolicyPage($request, $platform, $frame, $store, $locale, $found['id'], $found['kind'], $number, $periods, $current, $found['shareMediaId']);
        }

        // An address asking for a version: that version first. A plain one: where its policy is now first.
        $explicit = $number !== null || $pinned !== null;
        $past = $explicit ? $this->documents->pastPolicy($store->id, $locale, $slug, $number, $pinned) : null;

        if ($past === null) {
            $moved = $this->movedTo($request, $store, 'POLICY', $locale, $slug, 'storefront.content.policy', fn (string $itemId, string $in): ?string => $this->documents->currentPolicySlug($store->id, $itemId, $in));

            if ($moved !== null) {
                return $moved;
            }

            $past = $explicit ? null : $this->documents->pastPolicy($store->id, $locale, $slug, null, null);
        }

        if ($past === null) {
            throw new NotFoundHttpException;
        }

        $current = self::currentOfKind($frame->of($request, $store, $locale)['kept'], $past['kind'], $past['id']);

        return $this->pastPolicyPage($request, $platform, $frame, $store, $locale, $past['id'], $past['kind'], $past['number'], $past['periods'], $current, null);
    }

    /**
     * A text no longer in force in this store, labelled as old (the owner's decisions of 2026-11-20): its version's words
     * and files, the dates it was in force here (periods one after another read as one), the current policy's link.
     *
     * @param  'ar'|'en'  $locale
     * @param  list<array{from: \DateTimeImmutable, until: \DateTimeImmutable, slugAr: string, slugEn: string}>  $periods  at least one
     */
    private function pastPolicyPage(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame, StoreDto $store, string $locale, string $policyId, string $kind, int $number, array $periods, ?string $current, ?string $shareMediaId): Response
    {
        $shown = $this->documents->policyVersion($policyId, $number, $locale) ?? throw new NotFoundHttpException;
        $inForce = [];

        foreach ($periods as $period) {
            $last = count($inForce) - 1;

            if ($last >= 0 && $inForce[$last]['until'] == $period['from']) {
                $inForce[$last]['until'] = $period['until'];

                continue;
            }

            $inForce[] = ['from' => $period['from'], 'until' => $period['until']];
        }

        // Its own address: the one it had here last, in each language, the version and the policy named.
        $lastPeriod = $periods[count($periods) - 1];
        $query = ['version' => (string) $number, 'policy' => $policyId];
        $here = route('storefront.content.policy', ['slug' => $locale === 'ar' ? $lastPeriod['slugAr'] : $lastPeriod['slugEn'], ...$query]);
        $other = self::other($locale);
        $there = route('storefront.content.policy', ['locale' => $other, 'slug' => $other === 'ar' ? $lastPeriod['slugAr'] : $lastPeriod['slugEn'], ...$query]);

        return $this->policyPage($request, $platform, $frame, $store, $locale, [
            'title' => $shown['title'], 'body' => $shown['body'], 'images' => $shown['images'], 'version' => $number,
            'updatedAt' => $shown['publishedAt'], 'kind' => $kind, 'past' => true, 'current' => $current,
            'inForce' => array_map(static fn (array $period): array => ['from' => $period['from']->format(DATE_ATOM), 'until' => $period['until']->format(DATE_ATOM)], $inForce),
            // Kept out of search engines; the current text, when there is one, is the page they should know.
            'address' => new ContentAddressData($current ?? $here, $there, $other, true),
            'seoTitle' => null, 'seoDescription' => null, 'shareMediaId' => $shareMediaId,
        ]);
    }

    /**
     * The store's current policy of a kind, from the frame's copy (no query): the one published there; for Other, of
     * which a store may publish several, only the same policy while it is published there.
     *
     * @param  array<string, mixed>  $kept
     */
    private static function currentOfKind(array $kept, string $kind, string $policyId): ?string
    {
        foreach (is_array($kept['policies'] ?? null) ? $kept['policies'] : [] as $policy) {
            if (! is_array($policy) || ($policy['kind'] ?? null) !== $kind || ($kind === 'OTHER' && ($policy['id'] ?? null) !== $policyId)) {
                continue;
            }

            return route('storefront.content.policy', ['slug' => (string) ($policy['slug'] ?? '')]);
        }

        return null;
    }

    /**
     * A policy's page, current or past.
     *
     * @param  array{title: string, body: array<string, mixed>, images: list<array{ref: int, mediaId: string, alt: string|null}>, version: int, updatedAt: \DateTimeImmutable|null, kind: string, past: bool, current: string|null, inForce: list<array{from: string, until: string}>, address: ContentAddressData, seoTitle: string|null, seoDescription: string|null, shareMediaId: string|null}  $page
     */
    private function policyPage(Request $request, PlatformApi $platform, ShopFrameOfRequest $frame, StoreDto $store, string $locale, array $page): Response
    {
        $bodyImages = $page['images'];
        $urls = self::urls($platform, [$page['shareMediaId'], ...array_map(static fn (array $image): string => $image['mediaId'], $bodyImages)]);
        $sizes = $bodyImages === [] ? [] : $platform->mediaOf(array_map(static fn (array $image): string => $image['mediaId'], $bodyImages));
        $images = [];

        foreach ($bodyImages as $image) {
            $file = $urls[$image['mediaId']] ?? null;
            $images[$image['ref']] = [
                'src' => $file === null ? null : self::format($file['detail'] ?? $file['zoom'] ?? []),
                'width' => $sizes[$image['mediaId']]->width ?? null,
                'height' => $sizes[$image['mediaId']]->height ?? null,
            ];
        }

        $seo = ContentFrame::seo($frame->of($request, $store, $locale)['kept']);
        $body = $page['body'];
        $blocks = is_array($body['blocks'] ?? null) ? array_values(array_filter($body['blocks'], 'is_array')) : [];
        $title = $page['title'];

        return $this->page->render('Content/Storefront/Policy', (new ShopPolicyPageData(
            $title,
            $blocks,
            $images,
            $page['version'],
            $page['updatedAt']?->format(DATE_ATOM),
            $page['past'],
            $page['current'],
            $page['address'],
            new ContentHeadData(self::titled($seo, $page['past'] ? $title : ($page['seoTitle'] ?? $title)), $page['seoDescription'] ?? $seo['description'], self::shareImage($urls[$page['shareMediaId'] ?? ''] ?? $seo['image'])),
            $page['kind'],
            $page['inForce'],
        ))->toArray(), [...self::WORDS, 'content::policies'])->toResponse($request);
    }

    /** `?version=N`: a number from 1, written one way only; anything else is no page (404). */
    private static function askedNumber(Request $request): ?int
    {
        $asked = $request->query('version');

        if ($asked === null) {
            return null;
        }

        if (! is_string($asked) || preg_match('/\A[1-9][0-9]{0,8}\z/', $asked) !== 1) {
            throw new NotFoundHttpException;
        }

        return (int) $asked;
    }

    /** `?policy=<id>`: a policy's id; anything but one text is no page (404), and a text no policy has finds none. */
    private static function askedPolicy(Request $request): ?string
    {
        $asked = $request->query('policy');

        if ($asked === null) {
            return null;
        }

        if (! is_string($asked)) {
            throw new NotFoundHttpException;
        }

        return strtolower($asked);
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
            array_map(static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'slug' => $row['slug'], 'city' => $row['city'], 'client' => $row['clientName'], 'photo' => $urls[$row['photoMediaId'] ?? ''] ?? null], $rows),
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

        $mediaIds = [$found['shareMediaId'], $found['coverMediaId'], $found['clientLogoMediaId'], ...$found['photos'], ...array_map(static fn (array $image): string => $image['mediaId'], $found['images'])];
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

        $address = self::address($route, $locale, ['slug' => $found['slug']], $found['otherSlug'] === null ? null : ['slug' => $found['otherSlug']], false);
        $head = new ContentHeadData(self::titled($seo, $found['seoTitle'] ?? $found['title']), $found['seoDescription'] ?? $found['summary'] ?? $seo['description'], self::shareImage($picture));

        if ($kind === DocumentKind::Project) {
            return $this->page->render('Content/Storefront/Project', (new ShopProjectPageData(
                $found['title'],
                $blocks,
                $images,
                $found['city'],
                $found['clientName'],
                // The client box (S4.1): drawn when a name, a logo or a link is set.
                $found['clientName'] === null && $found['clientLogoMediaId'] === null && $found['clientLink'] === null ? null : ['name' => $found['clientName'], 'logo' => $urls[$found['clientLogoMediaId'] ?? ''] ?? null, 'link' => $found['clientLink']],
                array_values(array_filter(array_map(static fn (string $photo): ?array => $urls[$photo] ?? null, $found['photos']))),
                $products['cards'],
                $products['cardFacts'],
                $address,
                $head,
                $found['videos'],
            ))->toArray(), self::WORDS)->toResponse($request);
        }

        return $this->page->render('Content/Storefront/Document', (new ShopDocumentPageData(
            $kind->key(),
            $found['title'],
            $blocks,
            $images,
            $kind === DocumentKind::Post ? $found['publishedAt']?->format(DATE_ATOM) : null,
            $found['summary'],
            $urls[$found['coverMediaId'] ?? ''] ?? null,
            $found['coverAlt'],
            $found['category'],
            $products['cards'],
            $products['cardFacts'],
            $address,
            $head,
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
        return $this->movedTo($request, $store, $kind, $locale, $slug, $route, $current) ?? throw new NotFoundHttpException;
    }

    /** The permanent move to where the item holding the address now is, or null when it is nowhere now. */
    private function movedTo(Request $request, StoreDto $store, string $kind, string $locale, string $slug, string $route, callable $current): ?RedirectResponse
    {
        foreach ([$locale, self::other($locale)] as $held) {
            $itemId = $this->slugs->holder($store->id, $kind, $held, $slug);
            $now = $itemId === null ? null : $current(strtolower($itemId), $locale);

            if ($now !== null && $now !== $slug) {
                return self::moved($request, $route, $now, $held !== $locale);
            }
        }

        return null;
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
