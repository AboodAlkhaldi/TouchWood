<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Storefront;

use Modules\Catalog\Application\Query\Shop\MenuCategory;
use Modules\Catalog\Application\Query\Shop\ShopCatalog;
use Modules\Catalog\Application\Query\Shop\ShopPagesCache;
use Modules\Catalog\Presentation\Http\Resource\ShopLinkData;
use Modules\Catalog\Presentation\Http\Resource\ShopMenuItemData;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * One shop request as Catalog sees it (catalog.md §4.5): **who is looking, asked of Platform's
 * `ShopperType` once** and handed to every read, and the store's menu for them, read once — the
 * frame's part and the page both draw it. Scoped: it lasts one request, never longer.
 *
 * The menu comes from the never-stale cache, per store, language and viewer — and the base store,
 * whose order a store follows until its admins place it (P20, P21). The cache holds plain arrays
 * only (config/cache.php: no objects are read back), which are made the menu's items again here.
 */
final class ShopVisit
{
    private ?ShopperKind $viewer = null;

    private ?object $readFor = null;

    /** @var array<string, list<ShopMenuItemData>> */
    private array $menus = [];

    /** @var array<string, list<array<array-key, mixed>>> the menus as the cache keeps them, photos included */
    private array $kept = [];

    public function __construct(
        private readonly ShopperType $shoppers,
        private readonly ShopCatalog $catalog,
        private readonly ShopPagesCache $pages,
    ) {}

    public function viewer(): ShopperKind
    {
        $this->sameRequest();

        return $this->viewer ??= $this->shoppers->current();
    }

    /**
     * What it holds belongs to the request that read it: a server that keeps the application between
     * requests without forgetting its scoped instances (the browser tests') starts afresh here.
     */
    private function sameRequest(): void
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($request !== $this->readFor) {
            $this->viewer = null;
            $this->menus = [];
            $this->kept = [];
            $this->readFor = is_object($request) ? $request : null;
        }
    }

    /**
     * @return list<ShopMenuItemData>
     */
    public function menu(StoreId $store, string $locale): array
    {
        $viewer = $this->viewer(); // starts afresh on a new request
        $key = $this->key($store, $locale);

        if (! isset($this->menus[$key])) {
            /** @var list<array{id: string, name: string, slug: string, image: string|null, products: int, children: list<mixed>}> $kept */
            $kept = $this->pages->remember($key, fn (): array => array_map(
                static fn (MenuCategory $category): array => self::kept($category),
                $this->catalog->menu($store, $locale, $viewer),
            ));
            $this->kept[$key] = $kept;
            $this->menus[$key] = array_map(self::item(...), $kept);
        }

        return $this->menus[$key];
    }

    /**
     * The path to a category, the category itself and its sub-categories, as this viewer's menu has
     * them — nothing, for one the menu does not hold, so no step of a path is a page they cannot open.
     *
     * `ids` is the category's own id and those above it, from the top (for the slots above its list, amendment 41(a)).
     *
     * @return array{path: list<ShopLinkData>, self: ShopLinkData|null, children: list<ShopLinkData>, ids: list<string>}
     */
    public function around(StoreId $store, string $locale, string $categoryId): array
    {
        return self::find($this->menu($store, $locale), $categoryId, [], []) ?? ['path' => [], 'self' => null, 'children' => [], 'ids' => [$categoryId]];
    }

    /**
     * The top categories as this viewer's menu offers them, in its order, each with its photo's media id (C4, amendment
     * 46(b)): read from the menu's cached copy, so they cost no query of their own.
     *
     * @return list<array{name: string, slug: string, image: string|null}>
     */
    public function topCategories(StoreId $store, string $locale, int $limit): array
    {
        $this->menu($store, $locale);
        $key = $this->key($store, $locale);

        return array_slice(array_map(static fn (array $top): array => ['name' => (string) ($top['name'] ?? ''), 'slug' => (string) ($top['slug'] ?? ''), 'image' => is_string($top['image'] ?? null) ? $top['image'] : null], $this->kept[$key] ?? []), 0, max($limit, 0));
    }

    private function key(StoreId $store, string $locale): string
    {
        return "menu:{$store->value}:".($this->catalog->baseStore() ?? '-').":{$locale}:{$this->viewer()->value}";
    }

    /**
     * @return array{id: string, name: string, slug: string, image: string|null, products: int, children: list<mixed>}
     */
    private static function kept(MenuCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'image' => $category->imageMediaId, 'products' => $category->products, 'children' => array_map(self::kept(...), $category->children)];
    }

    /**
     * @param  array<array-key, mixed>  $kept
     */
    private static function item(array $kept): ShopMenuItemData
    {
        /** @var list<array<array-key, mixed>> $children */
        $children = is_array($kept['children'] ?? null) ? array_values($kept['children']) : [];

        return new ShopMenuItemData((string) ($kept['id'] ?? ''), (string) ($kept['name'] ?? ''), (string) ($kept['slug'] ?? ''), array_map(self::item(...), $children), (int) ($kept['products'] ?? 0));
    }

    /**
     * @param  list<ShopMenuItemData>  $items
     * @param  list<ShopLinkData>  $above
     * @param  list<string>  $aboveIds
     * @return array{path: list<ShopLinkData>, self: ShopLinkData, children: list<ShopLinkData>, ids: list<string>}|null
     */
    private static function find(array $items, string $categoryId, array $above, array $aboveIds): ?array
    {
        foreach ($items as $item) {
            /** @var list<ShopMenuItemData> $children */
            $children = $item->children;

            if ($item->id === $categoryId) {
                return [
                    'path' => $above,
                    'self' => new ShopLinkData($item->name, $item->slug),
                    'children' => array_map(static fn (ShopMenuItemData $child): ShopLinkData => new ShopLinkData($child->name, $child->slug), $children),
                    'ids' => [...$aboveIds, $item->id],
                ];
            }

            $found = self::find($children, $categoryId, [...$above, new ShopLinkData($item->name, $item->slug)], [...$aboveIds, $item->id]);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
