<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Contracts;

use DateTimeImmutable;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Dto\LowStockChangesDto;
use Modules\Inventory\Public\Dto\StockDto;
use Modules\Inventory\Public\Dto\StockLevelsDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Inventory (inventory.md §2.1). Module to module, so no permission is
 * checked here - the calling use case checks its own.
 *
 * Stock is held when an order is placed and taken off when it ships; a cancel frees it (owner,
 * 2026-10-07). In a store with no provider every product counts on its stock; in a wired one only
 * stock-dependent products and gifts do, and a stock-dependent line's hold ends when staff tick
 * "reduced in the provider" (§1.2, §1.3). Refusals arrive as `Shared\Domain\Error\DomainError` with a
 * stable `type()` key.
 */
interface InventoryApi
{
    /**
     * For each variant in the store: whether its stock limits ordering here, whether it can be ordered
     * now, how many can be (null where stock does not limit), and whether it is "ending soon". Never
     * shown to a customer as a number (§2.1).
     *
     * @param  list<string>  $variantIds
     * @return array<string, StockDto> by variant id
     */
    public function stock(StoreId $store, array $variantIds): array;

    /**
     * Holds every line that counts on stock, all or nothing: one line short and nothing is held
     * (`inventory.not_enough_stock`, its context naming the variants and what is available). Lines
     * whose stock does not limit ordering are noted, not held. One hold per order
     * (`inventory.already_held`). After `expiresAt`, if given, a scheduled job frees it.
     *
     * @param  list<HoldLineDto>  $lines
     */
    public function hold(StoreId $store, string $orderId, array $lines, ?DateTimeImmutable $expiresAt): void;

    /**
     * The shipped pieces leave the stock and the hold - in a store with no provider. In a wired store
     * nothing is taken (the provider is the source); a stock-dependent line's hold waits for the tick.
     * Safe to repeat: what is already shipped is left as it is.
     *
     * Never refused for the stock (sales.md answer 41): a shipment larger than what is in stock - a hand
     * removal took the pieces - takes the stock to 0, never below, its ledger line naming the shortfall
     * for a recount; the line is still shipped in full.
     *
     * @param  list<HoldLineDto>  $lines
     */
    public function ship(string $orderId, array $lines): void;

    /**
     * Staff ticked "reduced in the provider" for these lines (a wired store): their holds end - a
     * size's bought line and its gift line both, as staff reduce the provider by the size. Safe to
     * repeat.
     *
     * @param  list<string>  $variantIds
     */
    public function reducedInProvider(string $orderId, array $variantIds): void;

    /**
     * Frees whatever the order still holds - a cancel, or the hold's expiry. Safe to repeat.
     */
    public function release(string $orderId): void;

    /**
     * Staff edited the order before it ships: its hold becomes exactly these lines, all or nothing
     * (§1.4). The extra pieces are held as at placement (`inventory.not_enough_stock`), lowered or
     * removed lines are freed, and the expiry stays. `inventory.no_hold` when the order holds nothing;
     * `inventory.hold_not_editable` once a line is shipped or ticked. The same edit twice changes
     * nothing.
     *
     * @param  list<HoldLineDto>  $newLines
     */
    public function adjustHold(string $orderId, array $newLines): void;

    /**
     * Staff marked a return received: these pieces go back (§1.9) - in a store with no provider into
     * stock; in a wired store only for stock-dependent variants and gifts, provisionally on our side
     * until the provider's number next changes. Only the pieces going back (a damaged one is left
     * out). The store is the order's. A return id counts once.
     *
     * @param  list<HoldLineDto>  $lines
     */
    public function returned(string $orderId, string $returnId, array $lines): void;

    /**
     * Turns these sizes' stock-dependent switch **on** in a wired store, as staff's switch does - the
     * same rows, audit entry and Catalog facts - for Promotions' gift level (owner, 2026-10-19). Never
     * off. A size that does not exist or is archived (or of an archived product) is refused in every
     * store, nothing changed (`inventory.invalid_inventory_input`, `variant`, naming them). Then nothing
     * happens in a store with no provider, where every size counts on its stock already - unwired while
     * it waited for the store's lock too - nor for a size already on. The switch is on by default
     * (owner, 2026-10-28; inventory.md §9.2 #24), so this changes only a size staff switched off.
     *
     * Runs in the caller's transaction when one is open (a savepoint in it), in its own otherwise. Call
     * it last in that transaction, take no lock of the caller's own after it, and keep the transaction
     * short: Inventory's store lock and Catalog's facts lock are held until the caller commits. No
     * permission is checked here: the caller checks `InventoryPermissions::STOCK_MANAGE` in the store.
     *
     * @param  list<string>  $variantIds
     * @return list<string> the sizes it turned on, by their own ids, in id order
     */
    public function makeStockDependent(StoreId $store, array $variantIds): array;

    /**
     * **The sizes that became low, ran out and came back** in the store from `from` up to but not including
     * `to` (inventory.md §1.7, §2.1, amendment 1) - for Ops' daily report. Each list holds the sizes whose
     * change of that kind happened in the range, each once, in id order; a size that changed several times
     * may be in more than one. Changes are written by the recount, about half a minute after the change of
     * stock; a store's first recount writes none. The log keeps 12 months. `to` before `from` throws (the
     * caller's bug). One query, no lock; no permission is checked here.
     *
     * @throws \InvalidArgumentException when `to` is before `from`
     */
    public function lowStockChanges(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): LowStockChangesDto;

    /**
     * **Each size's stock in the store** (inventory.md §2.1, amendment 1) - for Ops' Stock report: the sizes
     * switched on there or with a stock row, in size-id order, keyset-paged by the size id (`after`, the
     * last page's `next`), `limit` 1 to 1000. In stock, the returned extra, held, available, the threshold
     * that applies, whether its stock limits ordering, archived, low and out - an archived size neither, as
     * the Low Stock list leaves it off. A store that does not exist is `inventory.invalid_inventory_input`
     * (`store`). A fixed number of queries; no permission is checked here.
     */
    public function stockLevels(StoreId $store, ?string $after = null, int $limit = 100): StockLevelsDto;
}
