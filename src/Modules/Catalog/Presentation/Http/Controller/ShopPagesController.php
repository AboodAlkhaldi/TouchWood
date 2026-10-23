<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Application\Query\Shop\Moved;
use Modules\Catalog\Application\Query\Shop\ShopFilters;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Presentation\Storefront\ShopPages;
use Shared\Application\StoreContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop's pages (catalog.md §4.5), under the store and the language: a category's, a brand's, a
 * product's, the search results, and the suggestions while a shopper types — JSON, never logged
 * (amendment 5(e)). An old slug answers 301 to the current one, the shopper's choices kept; a slug
 * that never existed, a draft, a category listing nothing here, an inactive brand — or an address
 * that is not one: a sort or a side that is not one, more brands or values than a page offers, a page
 * cursor made up — 404 in the shop's frame (amendment 5(m)). An id in `brands` or `values` that names
 * nothing the page offers is left out, as a choice not made.
 */
final readonly class ShopPagesController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'catalog::shop'];

    /**
     * The pages' builder is asked for in each action, never kept here: the router keeps a controller
     * for as long as its route lives, and the builder holds this request's viewer (ShopVisit).
     */
    public function __construct(
        private Page $page,
        private StoreContext $stores,
    ) {}

    public function category(Request $request, ShopPages $pages): Response
    {
        try {
            $filters = ShopFilters::of(self::list($request, 'brands'), self::list($request, 'values'), self::text($request, 'side'), self::text($request, 'sort'));
            $page = $pages->category($this->stores->current(), app()->getLocale(), self::slug($request), $filters, self::text($request, 'after'));
        } catch (InvalidCatalogAttribute) {
            return $this->notFound($request);
        }

        return match (true) {
            $page === null => $this->notFound($request),
            $page instanceof Moved => self::moved($request, 'storefront.catalog.category', $page),
            default => $this->page->render('Catalog/Storefront/Category', $page->toArray(), self::WORDS)->toResponse($request),
        };
    }

    public function brand(Request $request, ShopPages $pages): Response
    {
        try {
            $page = $pages->brand($this->stores->current(), app()->getLocale(), self::slug($request), self::text($request, 'after'));
        } catch (InvalidCatalogAttribute) {
            return $this->notFound($request);
        }

        return match (true) {
            $page === null => $this->notFound($request),
            $page instanceof Moved => self::moved($request, 'storefront.catalog.brand', $page),
            default => $this->page->render('Catalog/Storefront/Brand', $page->toArray(), self::WORDS)->toResponse($request),
        };
    }

    public function product(Request $request, ShopPages $pages): Response
    {
        try {
            $page = $pages->product($this->stores->current(), app()->getLocale(), self::slug($request), self::after($request), self::sectionWords($request, 'filter'));
        } catch (InvalidCatalogAttribute) {
            return $this->notFound($request);
        }

        return match (true) {
            $page === null => $this->notFound($request),
            $page instanceof Moved => self::moved($request, 'storefront.catalog.product', $page),
            default => $this->page->render('Catalog/Storefront/Product', $page, self::WORDS)->toResponse($request),
        };
    }

    public function search(Request $request, ShopPages $pages): Response
    {
        try {
            $page = $pages->search($this->stores->current(), app()->getLocale(), self::query($request), self::text($request, 'after'));
        } catch (InvalidCatalogAttribute) {
            return $this->notFound($request);
        }

        return $this->page->render('Catalog/Storefront/Search', $page->toArray(), self::WORDS)->toResponse($request);
    }

    public function suggest(Request $request, ShopPages $pages): JsonResponse
    {
        try {
            $cards = $pages->suggest($this->stores->current(), app()->getLocale(), self::query($request));
        } catch (InvalidCatalogAttribute) {
            $cards = [];
        }

        return new JsonResponse(['products' => array_map(static fn ($card): array => $card->toArray(), $cards)]);
    }

    /**
     * Its sentence is the shop's one not-found sentence, "This page isn't available." (errors.shop.sentence.404,
     * owner 2026-10-20), so it ships the shop error page's file too.
     */
    private function notFound(Request $request): Response
    {
        return $this->page->render('Catalog/Storefront/NotFound', [], [...self::WORDS, 'errors'])->toResponse($request)->setStatusCode(404);
    }

    /** The current address, the shopper's choices kept: a permanent move (catalog.md §1.1). */
    private static function moved(Request $request, string $route, Moved $moved): RedirectResponse
    {
        return new RedirectResponse(route($route, [...self::strings($request->query()), 'slug' => $moved->slug]), 301);
    }

    private static function slug(Request $request): string
    {
        $slug = $request->route('slug');

        return is_string($slug) ? $slug : '';
    }

    /** What was typed, as one line of at most 200 characters (the search log's column). */
    private static function query(Request $request): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', self::text($request, 'q') ?? '') ?? ''), 0, 200);
    }

    /**
     * Where each of the page's sections goes on from, as `after[{module}.{key}]` (amendment 26(b)), at
     * most 64 characters a place - more is no place any section gives; anything else left out.
     *
     * @return array<string, string>
     */
    private static function after(Request $request): array
    {
        return self::sectionWords($request, 'after');
    }

    /**
     * A word per section from the address — `after[{key}]`, or `filter[{key}]` (amendment 31) — at most 64
     * characters each, anything else left out.
     *
     * @return array<string, string>
     */
    private static function sectionWords(Request $request, string $name): array
    {
        $words = $request->query($name);

        return is_array($words) ? array_filter($words, static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value) && $value !== '' && strlen($value) <= 64, ARRAY_FILTER_USE_BOTH) : [];
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A list the address keeps as one comma-separated value.
     *
     * @return list<string>
     */
    private static function list(Request $request, string $key): array
    {
        return array_values(array_filter(explode(',', self::text($request, $key) ?? ''), static fn (string $id): bool => $id !== ''));
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return array<string, string>
     */
    private static function strings(array $query): array
    {
        $strings = [];

        foreach ($query as $key => $value) {
            // The store, the language and the slug are the address's own: a query never sets them.
            if (is_string($key) && is_string($value) && ! in_array($key, ['slug', 'store', 'locale'], true)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }
}
