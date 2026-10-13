# Inventory

How many pieces a store has, what is held for orders, and the history of every change. The spec is
[docs/modules/inventory.md](../../../docs/modules/inventory.md); the handoff's §12.1 is its source.

## Status

Built in five steps, one PR each (stage 5's plan). Steps 1–3 are built alongside Pricing (owner,
2026-10-10); step 4 waits for what Catalog's amendment 16 builds.

| Step | What | State |
|---|---|---|
| 1 | Foundation: the `inventory` schema, Manage Stock, the store's default threshold, the errors and their words | **this step** |
| 2 | Stock and the ledger: stocktakes, hand changes with their reasons, thresholds, the switches — `inventory.stock.manage` | next |
| 3 | Holds and the contract: `InventoryApi` (stock, hold, adjustHold, ship, reducedInProvider, release, returned), expired holds freed — `inventory.holds.expire` | — |
| 4 | Facts to Catalog (orderable, ending soon), Low Stock, the store's file — `inventory.orderable.rebuild` | after Catalog's amendment 16 is built |
| 5 | The module's own pass | — |

Moved from step 1 to step 2, where the first handler uses them: the off-store guard, the seam that
answers "is this store wired" (no store is, until Sync), and the module's own id check. The guard
needs the owner's rule for a store id that does not exist, which §7 does not cover yet.

## What is inside

| Folder | Holds |
|---|---|
| `Public/Contracts` | `InventoryApi` (inventory.md §2.1); not bound yet |
| `Public/Dto` | `StockDto`, `HoldLineDto` |
| `Application` | `InventoryPermissions`: Manage Stock, per store, any role, in the Catalog group; the system's two, reserved (§3). `InventorySettings`: the store's default low-stock threshold, 10 (§1.2) |
| `Domain/Exception` | `InventoryError` and the twelve errors of §7, each an `inventory.*` type with a category |
| `Infrastructure` | `InventoryServiceProvider` (after Catalog in `bootstrap/providers.php`); `Persistence/InventorySchema` and the migration that creates the schema |
| `Presentation/lang/{ar,en}` | `permissions.php`, `settings.php` (the names the panel shows), `errors.php` (each error's title and detail) |

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
| `tests/Modules/Inventory/Integration/InventorySchemaTest.php` | The schema exists, is on the search path, and is created and dropped (with what is in it) from nothing |
| `tests/Modules/Inventory/Integration/InventoryPermissionsTest.php` | The one job and the two reserved, by name, kind, group and reservation; a staff role may hold Manage Stock; its English name; the default threshold's scope, type, default, bounds and permission |
| `tests/Modules/Inventory/Unit/InventoryErrorsTest.php` | Exactly §7's twelve errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders |
| `tests/Architecture/InventoryAccessUseTest.php` | Inventory takes from Access only what declaring its permission needs |
