# Inventory — module specification

**Status: contract first (owner, 2026-10-07: "interfaces first").** Stage 6's Sales is built at the
same time and holds, takes and frees stock through Inventory, so §2 — what other modules call — is
written and agreed before the rest. §1 holds every rule the owner has decided so far; sections 3–8
follow once the provider questions (§9) are answered. The handoff's §12.1, revised 2026-10-07, is the
source.

---

## 1 · The rules

1. **One stock pool per store, per variant.** No warehouses, branches, bins or transfers (handoff
   §12.1, §16). Out of stock is never a flag: it is always a movement (handoff §2 rule 5).
2. **Two kinds of store** (owner, 2026-10-01; revised 2026-10-07):
   - **No provider** — our system is the only source and **every product counts on its stock**,
     always: nobody orders what is not there.
   - **Wired to a provider** (Sync) — the provider's stock is read in by code. An **ordinary** product's
     stock does not limit ordering. A **stock-dependent** product (a switch per product per store,
     **only in a wired store**, off by default) counts on **the provider's stock minus the quantities
     of this store's orders not yet ticked "reduced in the provider"** (handoff §12.1's worked
     example).
   - **Gift products** count on stock in every store.
3. **Held, then taken** (owner, 2026-10-07). Placing an order **holds** the pieces that count on
   stock; shipping **takes** them off; a cancelled order **frees** what it still holds. A hold may
   carry an expiry (an unpaid order), after which a scheduled job frees it. In a wired store a
   stock-dependent product's hold ends when staff tick **"reduced in the provider"** — the provider's
   own number then already shows it — never by shipping.
4. **Available = in stock − held.** The safety buffer is dropped (owner, 2026-10-07). Holding is an
   **atomic conditional update** — no rows changed means not enough — with no lock, race or
   deadlock (handoff §12.1).
5. **The ledger.** `stock_movements` is append-only, each with a unique reference: a hand change, a
   shipment, the store file, the provider's number. Nothing is ever edited silently.
6. **Hand changes** (a store with no provider; owner, 2026-10-07): **"Set to"** a counted number
   (stocktake) and **"Add / Remove"** an amount **with a reason** (received, damaged, offline sale,
   correction). In a wired store, stock is the provider's and read-only in our panel.
7. **Low stock**: a threshold **per variant per store** (owner, 2026-10-07). At or below it, the
   variant is on that store's **Low Stock list**, counted in the menu and on a Home card, for whoever
   handles stock there; Ops (stage 8) later sends email/SMS from the same signal. Nothing temporary.
8. **"Ending soon"** for customers, when a variant's stock is at or below its threshold: in a store
   with no provider, where an admin switches it on for the product; in a wired store, automatically on
   stock-dependent products only — other products there never show it (owner, 2026-10-07).
9. **Customers never see a number.** Out of stock is not listed at all (handoff §9.2); a direct link
   says "Not available now". Inventory pushes whether each variant is orderable into Catalog's listing
   (`ListingFacts::orderable`), inside its own transaction.
10. **The store file's stock** (`docs/modules/catalog-import/`) sets the variant's count when its item
    is switched on (a "set to" movement), except in a wired store, where it is ignored with a warning.
11. **A store that is off** has its stock set by Super Admins only (or by its file).

---

## 2 · Public contract

Module to module (handoff §4.3): no permission is checked — the calling use case checks its own.
Quantities are whole pieces (≥ 1).

### 2.1 `Modules\Inventory\Public\Contracts\InventoryApi`

| Method | For | Says |
|---|---|---|
| `stock(StoreId $store, list<string> $variantIds): array<string, StockDto>` | Sales (cart, checkout) | For each variant: whether its stock limits ordering here, whether it can be ordered now, how many can be ordered (null where stock does not limit), and whether it is "ending soon". Never shown to a customer as a number. |
| `hold(StoreId $store, string $orderId, list<HoldLineDto> $lines, ?DateTimeImmutable $expiresAt): void` | Sales (placing an order) | Holds every line that counts on stock — **all or nothing**: one line short and nothing is held (`inventory.not_enough_stock`, its context naming the variants and what is available). Lines whose stock does not limit ordering are noted, not held. One hold per order (`inventory.already_held`). |
| `ship(string $orderId, list<HoldLineDto> $lines): void` | Sales (an order or part of it shipped) | Takes the shipped pieces off the stock and off the hold — in a store with no provider. In a wired store nothing is taken: the provider is the source; a stock-dependent line's hold waits for the tick. |
| `reducedInProvider(string $orderId, list<string> $variantIds): void` | Sales (staff ticked "reduced in the provider") | Ends those lines' holds in a wired store. |
| `release(string $orderId): void` | Sales (a cancel), Inventory's own expiry job | Frees whatever the order still holds. |

`ship`, `reducedInProvider` and `release` are safe to repeat: a line already shipped, ticked or freed
is left as it is. A refusal reaches the caller as `Shared\Domain\Error\DomainError` with a stable
`type()` key and its `context()` — modules export no error classes.

### 2.2 The values

| DTO | Fields |
|---|---|
| `StockDto` | `variantId` · `countsOnStock` (stock limits ordering here) · `orderable` · `?available` (how many can be ordered now; null where stock does not limit) · `endingSoon` |
| `HoldLineDto` | `variantId` · `quantity` · `gift` (a gift counts on stock in every store) |

### 2.3 What Inventory gives Catalog

- `ListingFacts::orderable(StoreId, list<variantId>, bool)` — pushed whenever a variant's
  orderability changes in a store.
- An `ImportSection` (catalog.md §2.3) for the store file's stock.

### 2.4 Later, with Sync

The provider's stock in (Sync → Inventory), and the store's wired state — its shape waits for §9.

---

## 9 · Open questions

| # | Question | Waits for |
|---|---|---|
| 1 | A code shared by a product's variants: which variant does the provider's stock (and the file's) belong to? | The provider's sample |
| 2 | Returns: does a returned piece go back into stock in a store with no provider, and when? | Sales's spec (stage 6) |
| 3 | Who "handles stock" in a store — the permission that sees the Low Stock list and gets the alerts | This spec's permissions, next |
| 4 | Inventory's screens (stock, hand changes, the Low Stock list, the switches) | The frontend session, after this spec |
