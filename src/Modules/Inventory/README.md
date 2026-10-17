# Inventory

How many pieces a store has, what is held for orders, and the history of every change. The spec is
[docs/modules/inventory.md](../../../docs/modules/inventory.md); the handoff's §12.1 is its source.

## Status

Built in five steps, one PR each (stage 5's plan). Steps 1–3 are built alongside Pricing (owner,
2026-10-10); step 4 waits for what Catalog's amendment 16 builds.

| Step | What | State |
|---|---|---|
| 1 | Foundation: the `inventory` schema, Manage Stock, the store's default threshold, the errors and their words | built (#115) |
| 2 | Stock and the ledger: stocktakes, hand changes with their reasons, thresholds, the switches — `inventory.stock.manage` | **built** |
| 3 | Holds and the contract: `InventoryApi` (stock, hold, adjustHold, ship, reducedInProvider, release, returned), expired holds freed every minute, `HoldsShort` — `inventory.holds.expire` | **built** |
| 4a | Facts to Catalog: orderable and ending soon, only on a flip, inside each change; sizes switched on and the default threshold's change told; `RebuildOrderable` and `inventory:orderable:rebuild` — `inventory.orderable.rebuild` | **built** |
| 4b | Low Stock: the list (`LowStock`), its count (`LowStockCount`, for the menu entry the list's screen adds) and its Home card, from one reader; the store's file: the page's lines, and the stock set by a job once Catalog's change commits (a File movement, audited as the file's) | **built** |
| Sync 2a | What Inventory gives Sync (§2.5): `StoreWiring` in the Public contracts; `ProviderStock` - the provider's numbers in (a PROVIDER movement, a read counted once, the returned extra followed), wiring (every size told again), the count before unwiring, and unwiring (the returned extra taken in as a correction, every size told again) | **built** |
| 5 | The module's own pass - first: **`LowStockCount` in one query** before its menu entry goes in (step 4b's review: today about 3 queries a store, plus a read per 500 rows, on every admin page once the entry exists) | — |

Moved from step 1 to step 2, where the first handler uses them: the off-store guard, the seam that
answers "is this store wired" (no store is, until Sync), and the store check. A store id that is not
one is refused as the permission missing, before anything is looked up; a store that does not exist
is invalid input (owner, 2026-10-10), `inventory.invalid_inventory_input` naming the store.

**Not in step 2:** pushing orderable and "ending soon" into Catalog comes with step 4, as the plan
says; until then a hand change leaves Catalog's facts as they were.

## What is inside

| Folder | Holds |
|---|---|
| `Public/Contracts` | `InventoryApi` (inventory.md §2.1); bound to `Application/InventoryApiImpl`. For Sync (§2.5): `StoreWiring` (bound to `External/NoStoreWired` until Sync binds its own) and `ProviderStock`, bound to `Application/ProviderStockImpl` |
| `Public/Events` | `HoldsShort`: every order still holding a size a hand change took below its holds, after commit |
| `Public/Dto` | `StockDto`, `HoldLineDto`, `ProviderStockResult` |
| `Application` | `InventoryPermissions`: Manage Stock, per store, any role, in the Catalog group; the system's two, reserved (§3). `InventorySettings`: the store's default low-stock threshold, 10 (§1.2) |
| `Application/Command` | `SetStock` (a stocktake, one size or several; a count that finds the number right is recorded, not audited), `AdjustStock` (one size, add or remove with a reason), `SetLowStockThreshold`, `SetEndingSoon` (a product, no provider), `SetStockDependent` (wired stores only) |
| `Application/Stock` | What every change shares: `InventoryChange` (the permission in the store, the store known, an off store only with the store switch; one transaction, the store's lock first, the audit entries), `SizeInput` (the sizes named, checked in two reads: none unknown, archived or of an archived product - or, for the provider's numbers, sorted into those three) |
| `Domain/Exception` | `InventoryError` and the thirteen errors of §7, each an `inventory.*` type with a category |
| `Domain/Repository` | `StockRows` (made when missing and locked FOR UPDATE, in one order), `StoreProducts`, `StockMovements`, `InventoryLocks` |
| `Domain/Service` | `HandAdjustment`: an add or a remove as a signed change — each reason one way, a correction with its note (owner, 2026-10-11) |
| `Domain/ValueObject` | `MovementReason`, `StockRow` |
| `Infrastructure` | `InventoryServiceProvider` (after Catalog in `bootstrap/providers.php`); `Persistence/InventorySchema` and the migrations (the schema; `stock`, `store_products`, `stock_movements` with its append-only trigger, §5); `Eloquent/` the four repositories (the store's lock a transaction-scoped advisory lock; the ledger's actor read as the audit log reads it); `External/NoStoreWired` (no store is wired until Sync) |
| `Presentation/lang/{ar,en}` | `permissions.php`, `settings.php` (the names the panel shows), `errors.php` (each error's title and detail, and the fields an invalid input names), `audit.php` (each audited action's name) |

## How it is built

- **Boundaries** (`deptrac.yaml`): Inventory's Public may use Shared, Platform's and Catalog's
  Public; its inside also Access's Public, to declare its permission and for nothing else (owner,
  2026-10-07) — deptrac cannot say "these five classes only", so
  `tests/Architecture/InventoryAccessUseTest.php` does.
- **The schema** is on `config/database.php`'s search path, so `migrate:fresh` wipes it.
- **The permission** is declared into Access's catalog at boot, as Catalog's are; **the setting**
  into Platform's registry, changed with Manage Stock, as every threshold is (owner, 2026-10-09).
- **Errors** reach other modules only as `DomainError` with a stable `inventory.*` type.

## Tests

| File | Proves |
|---|---|
| `tests/Modules/Inventory/Integration/InventorySchemaTest.php` | The schema exists, is on the search path, and is created and dropped (with what is in it) from nothing; its kinds stored as strings; every CHECK broken once, NULLs included (§8 #13); each key's delete rule |
| `tests/Modules/Inventory/Integration/InventoryStockTest.php` | Stocktakes (a right count recorded, not audited), adds and removes with each reason one way, a correction's note, never below 0, thresholds 0–100,000 or the default, "last pieces", the stock-dependent switch; who may (per store, unknown and off stores, wired refusals, archived sizes and products); the store's lock and the rows' lock inside the change; nothing written after a rollback; the ledger append-only, a reference counted once (§8 #6, #9, #11, #12) |
| `tests/Modules/Inventory/Integration/InventoryProviderStockTest.php` | The provider's numbers (§2.5): a PROVIDER movement, the same number nothing (no row made for 0), the same read run again counted once, the returned extra followed, Catalog told, no `HoldsShort`; off and draft sizes taken; archived, not found, out-of-bounds and doubled ids named and left alone; a store not wired, or unwired under the lock, left alone; the read reference's limits. Wiring: every size told again as a wired store's. Unwiring: the count first, the returned extra a CORRECTION noted "unwired", every size told again; both refused in the wrong state |
| `tests/Modules/Inventory/Integration/InventoryAuditNamesTest.php` | Every audited action named in both languages, nothing named that is not recorded |
| `tests/Modules/Inventory/Integration/InventoryPermissionsTest.php` | The one job and the two reserved, by name, kind, group and reservation; a staff role may hold Manage Stock; its English name; the default threshold's scope, type, default, bounds and permission |
| `tests/Modules/Inventory/Unit/InventoryErrorsTest.php` | Exactly §7's thirteen errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders |
| `tests/Architecture/InventoryAccessUseTest.php` | Inventory takes from Access only what declaring its permission needs |

## On deploy

Run `php artisan inventory:orderable:rebuild` once when step 4 first reaches a server: sizes switched
on before it have never been told to Catalog. Run it again whenever Sync changes a store's wiring.
