<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\LowStock;

use Collator;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\InventoryChange;
use Modules\Inventory\Application\Stock\LowRead;
use Modules\Inventory\Application\Stock\LowStockReader;
use Modules\Inventory\Application\Stock\StockViews;
use Modules\Inventory\Application\Stock\Words;
use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\Repository\StockMovements;
use Shared\Application\Unauthorized;

/**
 * **The Low Stock list** (inventory.md §1.7, §3, §4.3.3 I2) of one store, under **Manage Stock** there:
 * every low size from the one reader the count and the Home card read too, so the three never disagree -
 * archived sizes never listed - **the fewest available first, then by product name in the page's
 * language, then the size's place**; 50 at a time, keyset, with the total.
 *
 * Catalog orders by name only among sizes it is given, and this order starts with Inventory's own
 * figure, so the list orders itself: the sizes in brief the reader already read for the archived check
 * give the product names and the sizes' places (amendment 23(h)). Names are compared by ICU's
 * collation for the page's language, punctuation and spaces set aside as PostgreSQL's does - close to
 * Catalog's order, though not promised identical for names differing only in such marks. Then the page's
 * sizes - photo, values, code - in one search that does not count them again, and their holds and the
 * provider's last changes. A fixed
 * few queries however many low sizes. A read: no lock, nothing written.
 */
final readonly class LowStockHandler
{
    public const string PERMISSION = InventoryPermissions::STOCK_MANAGE;

    public function __construct(
        private InventoryChange $change,
        private LowStockReader $reader,
        private CatalogApi $catalog,
        private StockMovements $movements,
        private Holds $holds,
        private StockViews $views,
        private Words $words,
    ) {}

    /**
     * @throws Unauthorized|InvalidInventoryInput|StoreOff
     */
    public function handle(LowStock $query): LowStockList
    {
        $storeId = $this->change->authorize(self::PERMISSION, $query->storeId)->storeId();
        $read = $this->reader->read($storeId);
        $keys = $this->keys($read);
        $collator = $this->collator();
        $compare = static fn (array $a, array $b): int => $a[0] <=> $b[0]
            ?: (int) $collator->compare($a[1], $b[1])
            ?: [$a[2], $a[3], $a[4]] <=> [$b[2], $b[3], $b[4]];

        uasort($keys, $compare);
        $total = count($keys);
        $after = self::cursor($query->after);

        if ($after !== null) {
            $keys = array_filter($keys, static fn (array $key): bool => $compare($key, $after) > 0);
        }

        $page = array_slice($keys, 0, LowStock::PER_PAGE, true);
        $last = $page === [] ? null : $page[array_key_last($page)];
        $ids = array_map('strval', array_keys($page));
        $found = [];

        foreach ($ids === [] ? [] : $this->catalog->searchVariants($storeId, null, $ids, limit: LowStock::PER_PAGE, withTotal: false)->rows as $row) {
            $found[$row->variantId] = $row;
        }

        $sizes = [];

        foreach ($read->sizes as $size) {
            $sizes[$size->variantId] = $size;
        }

        $holds = $ids === [] ? [] : ($this->holds->summaries([$storeId], $ids)[$storeId->value] ?? []);
        $provider = $read->wired && $ids !== [] ? ($this->movements->lastProviderChanges([$storeId], $ids)[$storeId->value] ?? []) : [];
        $rows = [];

        foreach ($ids as $id) {
            if (! isset($found[$id])) {
                continue;
            }

            $rows[] = new LowStockRow(
                $this->views->size($found[$id]),
                $this->views->figures($sizes[$id]->row, $read->wired, $read->defaultThreshold, $provider[$id] ?? null, $holds[$id] ?? null),
            );
        }

        return new LowStockList(
            $rows,
            count($keys) > LowStock::PER_PAGE && $last !== null ? self::encode($last) : null,
            $total,
        );
    }

    /**
     * Each low size's place in the order: available, its product's name in the page's language, the
     * product, the size's place among its product's sizes, the size.
     *
     * @return array<string, array{int, string, string, int, string}> by size id
     */
    private function keys(LowRead $read): array
    {
        $english = $this->words->locale() === 'en';
        $keys = [];

        foreach ($read->sizes as $size) {
            $brief = $read->briefs[$size->variantId] ?? null;
            $name = $brief === null ? '' : ($english ? ($brief->productNameEn ?? $brief->productNameAr) : $brief->productNameAr);
            $keys[$size->variantId] = [$size->free, $name, $brief->productId ?? '', $brief->position ?? 0, $size->variantId];
        }

        return $keys;
    }

    private function collator(): Collator
    {
        $collator = new Collator($this->words->locale() === 'en' ? 'en_US' : 'ar');
        // Punctuation and spaces weigh only on a tie, as PostgreSQL's (glibc's) collation weighs them.
        $collator->setAttribute(Collator::ALTERNATE_HANDLING, Collator::SHIFTED);

        return $collator;
    }

    /**
     * @param  array{int, string, string, int, string}  $key
     */
    private static function encode(array $key): string
    {
        return rtrim(strtr(base64_encode(json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * The place a cursor names, or null for one that is not one - the list starts at the top.
     *
     * @return array{int, string, string, int, string}|null
     */
    private static function cursor(?string $after): ?array
    {
        if ($after === null || $after === '' || preg_match('/\A[A-Za-z0-9_-]{1,2048}\z/', $after) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($after, '-_', '+/'), true);
        $key = $json === false ? null : json_decode($json, true);

        if (! is_array($key) || ! array_is_list($key) || count($key) !== 5
            || ! is_int($key[0]) || ! is_string($key[1]) || ! is_string($key[2]) || ! is_int($key[3]) || ! is_string($key[4])) {
            return null;
        }

        return [$key[0], $key[1], $key[2], $key[3], $key[4]];
    }
}
