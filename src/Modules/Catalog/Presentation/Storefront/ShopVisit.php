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

    /** @var array<string, list<ShopMenuItemData>> */
    private array $menus = [];

    public function __construct(
        private readonly ShopperType $shoppers,
        private readonly ShopCatalog $catalog,
        private readonly ShopPagesCache $pages,
    ) {}

    public function viewer(): ShopperKind
    {
        return $this->viewer ??= $this->shoppers->current();
    }

    /**
     * @return list<ShopMenuItemData>
     */
    public function menu(StoreId $store, string $locale): array
    {
        $viewer = $this->viewer();
        $key = "menu:{$store->value}:".($this->catalog->baseStore() ?? '-').":{$locale}:{$viewer->value}";

        if (! isset($this->menus[$key])) {
            /** @var list<array{id: string, name: string, slug: string, children: list<mixed>}> $kept */
            $kept = $this->pages->remember($key, fn (): array => array_map(
                static fn (MenuCategory $category): array => self::kept($category),
                $this->catalog->menu($store, $locale, $viewer),
            ));
            $this->menus[$key] = array_map(self::item(...), $kept);
        }

        return $this->menus[$key];
    }

    /**
     * The path to a category, the category itself and its sub-categories, as this viewer's menu has
     * them — nothing, for one the menu does not hold, so no step of a path is a page they cannot open.
     *
     * @return array{path: list<ShopLinkData>, self: ShopLinkData|null, children: list<ShopLinkData>}
     */
    public function around(StoreId $store, string $locale, string $categoryId): array
    {
        return self::find($this->menu($store, $locale), $categoryId, []) ?? ['path' => [], 'self' => null, 'children' => []];
    }

    /**
     * @return array{id: string, name: string, slug: string, children: list<mixed>}
     */
    private static function kept(MenuCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'children' => array_map(self::kept(...), $category->children)];
    }

    /**
     * @param  array<array-key, mixed>  $kept
     */
    private static function item(array $kept): ShopMenuItemData
    {
        /** @var list<array<array-key, mixed>> $children */
        $children = is_array($kept['children'] ?? null) ? array_values($kept['children']) : [];

        return new ShopMenuItemData((string) ($kept['id'] ?? ''), (string) ($kept['name'] ?? ''), (string) ($kept['slug'] ?? ''), array_map(self::item(...), $children));
    }

    /**
     * @param  list<ShopMenuItemData>  $items
     * @param  list<ShopLinkData>  $above
     * @return array{path: list<ShopLinkData>, self: ShopLinkData, children: list<ShopLinkData>}|null
     */
    private static function find(array $items, string $categoryId, array $above): ?array
    {
        foreach ($items as $item) {
            /** @var list<ShopMenuItemData> $children */
            $children = $item->children;

            if ($item->id === $categoryId) {
                return [
                    'path' => $above,
                    'self' => new ShopLinkData($item->name, $item->slug),
                    'children' => array_map(static fn (ShopMenuItemData $child): ShopLinkData => new ShopLinkData($child->name, $child->slug), $children),
                ];
            }

            $found = self::find($children, $categoryId, [...$above, new ShopLinkData($item->name, $item->slug)]);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
