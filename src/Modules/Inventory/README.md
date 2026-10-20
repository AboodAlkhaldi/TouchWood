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
| 5 | The menu's Low Stock count kept per store (owner, 2026-10-18): `inventory.low_counts`, `CountLowStock` (`inventory.low_stock.count`, the system's) run by `CountLowStockJob` 30 seconds after every change `StockFacts` passes, when Catalog archives or restores, and each night at 03:30 Riyadh; one recount waiting per store (`recount_queued`, set in one statement - never a cache lock inside the change's transaction); `LowStockCount` reads the kept counts in one query; the recount marked after the change commits (never a row held through a checkout); Catalog's `StoreListingWithdrawn` (amendment 22) followed; an older count never written over a newer one. Known limit: a draft abandoned and archived sends no event, so a draft size's stock row leaves the count at the night's recount | **built** (#161) |
| Screens 4 | Stage 5's screens step 4, the server side of Inventory's screens (§4.3.3): `ListStock` (I1, its filters worked out from one read of the store's rows, paged by Catalog's `searchVariants(only:)`), `ViewStock` (the drawer), `StockHistory` (the ledger, who by name), `ViewProductStock` (the product page, one read for every card, registered with Catalog's `ProductStoreSections`), `LowStock` paged in its own order with photos and values, `InventoryActionsForReader`, `HoldsOnSizes` (Q20), `AddSizeChoices` (Q24); one badge a size (`StockFigures::badge`); refusals naming their sizes and their box (Q2); `SetStock` and `AdjustStock` answering the sizes left short (`StockSaved`); `StoreWiring::wiredStores`. The review's points: a wired store's pages counted within 15 (Catalog's `variantBriefs` and `searchVariants(withTotal: false)`, amendment 23(h)); every bad row of a batch named at once; a draft's size not switched on refused a stocktake; a size already short named by a count that finds it right; no time where the provider wrote no line | **built** |
| Screens 5 | Stage 5's screens step 5, Inventory's pages (§4.3): Stock (I1) - counts typed in the rows and saved from the save bar, the amber toast when orders are left short, Add Size, Add or Remove Stock, Set Threshold for one size or the selected rows, Show / Hide Last Pieces, Counts on Stock, the size's side panel with its holds and History - Low Stock (I2), and the product page's section in each store card; `inventory.admin.*` routes, the menu's Stock and Low Stock (with its count); every word in English and Arabic. The review's points: the drawer deferred (a size the store cannot show said in it), a change keeping Show More's rows, archived sizes read-only everywhere, a wired store's changes drawn with their reason, toasts said once | **this step** |
| Stage 6 hooks | For Sales and Promotions (owner, 2026-10-19; inventory.md §9.2 #22, #23): `ship` never refused for the stock in a store with no provider - the stock stops at 0, one `SHIPPED` line noting the shortfall for a recount (sales.md answer 41) - from a stock already at 0 too, a 0 → 0 line, which the ledger's direction check now lets through for a shipped line with a note only (migration `2026_10_20_100000`); `InventoryApi::makeStockDependent` - on only, wired stores only, the switch's own code (`Stock/StockDependentSwitch`), in the caller's transaction or its own; the permissions' names public (`Public/InventoryPermissions`) | **built** |

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
| `Public` | `InventoryPermissions`: the permissions' names, so another module checks Manage Stock for itself (Promotions' gift tick box, §9.2 #23) |
| `Application` | `InventoryPermissions`: Manage Stock, per store, any role, in the Catalog group; the system's two, reserved (§3) - declared here, its names from `Public/InventoryPermissions`. `InventorySettings`: the store's default low-stock threshold, 10 (§1.2) |
| `Application/Command` | `SetStock` (a stocktake, one size or several; a count that finds the number right is recorded, not audited), `AdjustStock` (one size, add or remove with a reason) - both answer `StockSaved`, the sizes they left short - `SetLowStockThreshold`, `SetEndingSoon` (a product, no provider), `SetStockDependent` (wired stores only) |
| `Application/Query` | The screens' reads (§4.3.3): `ListStock`, `ViewStock`, `StockHistory`, `ViewProductStock`, `LowStock`, `HoldsOnSizes`, `AddSizeChoices`, `InventoryActions/InventoryActionsForReader`; `StockViews/` the shapes they share (a size, its figures, its holds) |
| `Application/Stock` | What every change shares: `InventoryChange` (the permission in the store, the store known, an off store only with the store switch; one transaction, the store's lock first, the audit entries), `SizeInput` (the sizes named, checked in two reads: none unknown, archived or of an archived product - or, for the provider's numbers, sorted into those three - a refusal naming them), `StockDependentSwitch` (the switch itself - rows, audit entries, Catalog told - shared by `SetStockDependent` and `InventoryApi::makeStockDependent`). For the reads: `ReaderStores` (the stores a reader holds a job in, off ones only for whoever may switch stores), `StockViews` (a size and its figures written for the page), `Words` (bound to `Infrastructure/LangWords`) |
| `Domain/Exception` | `InventoryError` and the thirteen errors of §7, each an `inventory.*` type with a category |
| `Domain/Repository` | `StockRows` (made when missing and locked FOR UPDATE, in one order), `StoreProducts`, `StockMovements`, `InventoryLocks` |
| `Domain/Service` | `HandAdjustment`: an add or a remove as a signed change — each reason one way, a correction with its note (owner, 2026-10-11) |
| `Domain/ValueObject` | `MovementReason`, `StockRow` |
| `Infrastructure` | `InventoryServiceProvider` (after Catalog in `bootstrap/providers.php`); `Persistence/InventorySchema` and the migrations (the schema; `stock`, `store_products`, `stock_movements` with its append-only trigger, §5); `Eloquent/` the four repositories (the store's lock a transaction-scoped advisory lock; the ledger's actor read as the audit log reads it); `External/NoStoreWired` (no store is wired until Sync) |
| `Presentation` | `admin-routes.php` (`inventory.admin.*`); `Http/Controller` `StockController`, `LowStockController`, `InventoryForms` (a change's toast or refusal); `Http/Resource` the pages' data (`InventoryPages` builds them) |
| `Presentation/lang/{ar,en}` | `permissions.php`, `settings.php` (the names the panel shows), `errors.php` (each error's title and detail, the rules a screen's refusal names, and the fields an invalid input names), `audit.php` (each audited action's name), `admin_stock.php` (the screens' words: step 4 the reasons a button is disabled; step 5 the rest), `admin_low.php` (Low Stock's), `menu.php` (the two entries) |

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
| `tests/Modules/Inventory/Integration/InventoryLowCountTest.php` | The kept count in step with the list after stock, a threshold and an archived size; the menu's count in one query, Catalog never read; a store not counted yet counted live, its recount queued 30 seconds later; one recount queued however many changes; every store each night at 03:30 Riyadh on one server, the system's alone |
| `tests/Modules/Inventory/Integration/InventorySchemaTest.php` | The schema exists, is on the search path, and is created and dropped (with what is in it) from nothing; its kinds stored as strings; every CHECK broken once, NULLs included (§8 #13); each key's delete rule |
| `tests/Modules/Inventory/Integration/InventoryStockTest.php` | Stocktakes (a right count recorded, not audited), adds and removes with each reason one way, a correction's note, never below 0, thresholds 0–100,000 or the default, "last pieces", the stock-dependent switch; who may (per store, unknown and off stores, wired refusals, archived sizes and products); the store's lock and the rows' lock inside the change; nothing written after a rollback; the ledger append-only, a reference counted once (§8 #6, #9, #11, #12) |
| `tests/Modules/Inventory/Integration/InventoryProviderStockTest.php` | The provider's numbers (§2.5): a PROVIDER movement, the same number nothing (no row made for 0), the same read run again counted once, the returned extra followed, Catalog told, no `HoldsShort`; off and draft sizes taken; archived, not found, out-of-bounds and doubled ids named and left alone; a store not wired, or unwired under the lock, left alone; the read reference's limits. Wiring: every size told again as a wired store's. Unwiring: the count first, the returned extra a CORRECTION noted "unwired", every size told again; both refused in the wrong state |
| `tests/Modules/Inventory/Integration/InventoryHoldsTest.php` | Holds and the contract (§8 #1-#4, #14, #15), and a shipment larger than the stock taken to 0 with its shortfall noted - a 0 → 0 line from a stock already at 0 - counted once, a removal by hand still refused (§9.2 #22) |
| `tests/Modules/Inventory/Integration/InventoryMakeStockDependentTest.php` | `makeStockDependent` (§9.2 #23): on in a wired store, audited and told to Catalog, the ids answered; nothing in a store with no provider or for a size already on, never off; unknown and archived sizes refused; rolled back with the caller's transaction; alone, its audit entry in a transaction of its own (lesson 220); no permission checked inside |
| `tests/Modules/Inventory/Integration/InventoryAuditNamesTest.php` | Every audited action named in both languages, nothing named that is not recorded |
| `tests/Modules/Inventory/Integration/InventoryPermissionsTest.php` | The one job and the two reserved, by name, kind, group and reservation; a staff role may hold Manage Stock; its English name; the default threshold's scope, type, default, bounds and permission |
| `tests/Modules/Inventory/Unit/InventoryErrorsTest.php` | Exactly §7's thirteen errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders |
| `tests/Modules/Inventory/Integration/InventoryScreenReadsTest.php` | The screens' reads (§4.3.3): the Stock list's rows, figures, badges and filters, paged with the total; a wired store's Orderable, Out of Stock and provider's last change; the drawer's holds, bought and gift apart; the history's who; a product's stock in every store in one read, registered with Catalog; the Low Stock list's order and paging; what the screens may offer; the holds before saving in one query; Add Size; refusals naming their sizes and box (Q2); the sizes a save left short. Each read's queries counted, the same for one row as for many |
| `tests/Modules/Inventory/Feature/InventoryScreensTest.php` | The pages over HTTP: what Stock, its drawer and Low Stock are handed; the stores offered, another refused, an off store a Super Admin's only; a wired store; each change's toast (the amber one too) and refusal; the guards (a store outside the reader's, a change naming none); the menu's two entries and Low Stock's count; the product page's section; each page's own queries recorded under 15 |
| `tests/Browser/InventoryScreensTest.php` | The pages in a real browser: a count typed and saved with the amber toast; Add or Remove Stock; Set Threshold for the selected rows; the side panel and its History; Low Stock; the product page's section in two store cards; Arabic right to left |
| `tests/Frontend/inventory-checks.test.ts` | The boxes' rules as typed: a count, a quantity, a threshold, a correction's note, each direction's reasons, a change leaving orders short |
| `tests/Architecture/InventoryAccessUseTest.php` | Inventory takes from Access only what declaring its permission needs |

## On deploy

Run `php artisan inventory:orderable:rebuild` once when step 4 first reaches a server: sizes switched
on before it have never been told to Catalog. Run it again whenever Sync changes a store's wiring.
