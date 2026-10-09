# Inventory — module specification

**Status (2026-10-09): the full spec, its questions answered by the owner.** §2 — what other modules
call — was agreed first and is on `main` (#97, "interfaces first", owner 2026-10-07). The rest follows
from the owner's answers of 2026-10-01 to 2026-10-09; the last round, my proposals included, is §9.2.
What waits for the provider's team or another session is §9.1. Handoff §12.1, revised 2026-10-07 and
2026-10-09, is the source.

## What Inventory does not own

| Thing | Owner |
|---|---|
| Whether a product is listed, "Not available now", or chosen by a store | Catalog |
| Orders, the "reduced in the provider" tick, cancelling, shipping, returns | Sales (stage 6) — it calls Inventory |
| Gifts — which piece is given | Promotions (stage 6) — a gift line counts on stock here |
| Reading the provider's stock | Sync (after its answers) — it writes through Inventory's public surface |
| Email and SMS alerts | Ops (stage 8), from the same low-stock signal |

---

## 1 · Aggregates and invariants

### 1.1 The rules

1. **One stock pool per store, per size** (variant). No warehouses, branches, bins or transfers
   (handoff §12.1, §16). Out of stock is never a flag: it is always a movement (handoff §2 rule 5).
2. **A store with no provider counts every product on its stock**, always: nobody orders what is
   not there (owner, 2026-10-07).
3. **A store wired to a provider** (Sync, KSA to be): the provider's stock is **read, never held or
   reduced** (owner, 2026-10-09). An **ordinary** product's stock **does not limit ordering**. A
   **stock-dependent** size counts on **the provider's stock minus this store's holds not yet ticked
   "reduced in the provider"** (handoff §12.1's worked example). **Gifts** count on stock in every store.
4. **The low-stock threshold only ever alerts staff; it never changes what can be ordered** (owner,
   2026-10-09). Ordering is limited only by stock running out — in a store with no provider always; in
   a wired store only for stock-dependent sizes and gifts.
5. **Available = in stock − held.** The safety buffer is dropped (owner, 2026-10-07).
6. **A store that is off** has its stock set by Super Admins only — whoever may switch stores — or by
   its file (owner, 2026-10-07).

### 1.2 A size's stock in a store

| Part | Invariant |
|---|---|
| in stock | A whole number, **never below 0**. In a store with no provider it is ours; in a wired store it is the provider's last number (Sync). |
| held | The sum of the open holds' lines that count on stock (§1.4). It **may exceed in stock** after a hand removal (§1.5): those orders are flagged. |
| low-stock threshold | Per size per store (owner, 2026-10-07); empty → **the store's default, 10 pieces** (owner, 2026-10-09; a Platform setting per store, `inventory.low_stock.default`, accepted 2026-10-09). |
| stock-dependent | **Only in a wired store** (owner, 2026-10-07), off by default, **per size**: staff pick a product's sizes — all of them, some, or one (owner, 2026-10-09: "so its customized"). **A size added later starts off**; staff pick it (owner, 2026-10-09). |

**"Ending soon" ("last pieces")** for customers (owner, 2026-10-07/09), when a size's available stock
is at or below its threshold: **in a store with no provider**, where staff switch it on for the
product; **in a wired store**, automatically on stock-dependent sizes only — other products there
never show it. **It shows on cards and lists too** (owner, 2026-10-09): a card says "last pieces"
when **any** of its product's sizes there is ending soon; a product's page says it for the size the
shopper picks. Inventory pushes it into Catalog's listing, as it does orderable (§2.3).

### 1.3 Orderable

A size can be ordered now in a store (as far as stock goes; Catalog decides the rest) when its stock
does not limit ordering there, or its available stock is above 0. Every change of orderability — and
of "ending soon" — is pushed into Catalog's listing (`ListingFacts::orderable`, `::endingSoon`) inside
the change's own transaction.

**A wired ordinary product the provider reports at 0 stays orderable** (owner, 2026-10-09: "since
its not stock-dependent"); staff mark it "Not available now" (Catalog) when it is really gone. This
replaces handoff §9.2's provisional "or the provider reports 0 — automatically" (2026-10-02).

### 1.4 Holds

- **Placing an order holds** the pieces of its lines that count on stock — **all or nothing**, as an
  atomic conditional update: no rows changed means not enough, with no lock, race or deadlock
  (handoff §12.1). Lines whose stock does not limit ordering are noted, not held. One hold per order.
- **Shipping takes them off** (a store with no provider): in stock and held both fall. In a wired store
  nothing is taken — the provider is the source.
- **"Reduced in the provider"** (a wired store's stock-dependent lines and gifts): the tick ends the
  line's hold; the provider's own number then shows it.
- **A cancel frees** whatever the order still holds. A hold may carry an **expiry** (an unpaid order,
  a bank transfer being verified — Sales sets it); a scheduled job frees expired holds.
- Shipping, ticking and freeing are safe to repeat.

### 1.5 Hand changes — a store with no provider

Under **Manage Stock** in that store (owner, 2026-10-09):

- **"Set to"** a counted number — a stocktake.
- **"Add" or "Remove"** an amount, with a reason: **Received**, **Damaged / lost**, **Offline sale**, or
  **Correction** with a short note (owner, 2026-10-09).
- **Removing pieces held for orders is allowed** (owner, 2026-10-09): in stock may fall below held, but
  never below 0. The orders whose holds no longer fit are **flagged "not enough stock"** for staff to
  sort out — Inventory names them (§6.1) and Sales shows the flag.
- In a wired store, stock is the provider's and read-only here.

### 1.6 The ledger

`inventory.stock_movements` is **append-only**: every change of in stock — a stocktake, an addition or
removal with its reason, a shipment, the store's file, the provider's number — with its before and
after, who or what made it, and a unique reference, so a repeated message is never counted twice
(handoff §12.1). Holds and their ends are kept on the holds themselves (§5).

### 1.7 Low stock

A size is **low** when its available stock is at or below its threshold — in a wired store, the
provider's number for an ordinary product. Per store, the **Low Stock list** — every low size — with a
**count beside it in the menu** (Platform's `MenuCount`) and a **Home card** (Platform's `HomeCards`;
the handoff's dashboard "low stock"), seen by holders of **Manage Stock** there (owner, 2026-10-09). In
the panel only until Ops (stage 8) adds email and SMS (owner, 2026-10-07).

### 1.8 The store's file (catalog.md §2.3)

Inventory's `ImportSection`: the file's page says, per item, the stock that will be set — or that it
is ignored in a wired store, the provider being its source. **When an item is switched on**, the stock
**sets** the size's in stock (a "set to" movement, reason **File**), audited as the file's. **One code is
one size** (owner, 2026-10-09; Catalog's amendment 16).

---

## 2 · Public contract

Module to module (handoff §4.3): no permission is checked — the calling use case checks its own.
Quantities are whole pieces (≥ 1). **On `main` since #97.**

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
| `StockDto` | `variantId` · `countsOnStock` (stock limits ordering here) · `orderable` · `?available` (how many can be ordered now; null where stock does not limit) · `availableAsGift` (how many can be given as a gift — in stock minus held, always a number, since a gift counts on stock in every store; stage 6's review, 2026-10-07) · `endingSoon` |
| `HoldLineDto` | `variantId` · `quantity` · `gift` (a gift counts on stock in every store) |

### 2.3 What Inventory gives Catalog

- `ListingFacts::orderable(StoreId, list<variantId>, bool)` — §1.3.
- **`ListingFacts::endingSoon(StoreId, list<variantId>, bool)`** — new, the same shape as
  orderable: whether each size is "ending soon" there (§1.2). Catalog keeps it in its own table, as
  the contract asks of every pushed fact, and shows "last pieces" on a card when any of the product's
  sizes is. **Catalog's amendment** (the Catalog-screens session), with the owner's rule of 2026-10-09.
- An `ImportSection` for the store's file — §1.8.

### 2.4 What Inventory needs from other modules

| From | What | State |
|---|---|---|
| Access | Declaring **Manage Stock** in the `Catalog` group (accepted 2026-10-09) — no stock group exists; the role editor's groups are the design's | Allowed for that only (owner, 2026-10-07; `deptrac.yaml` since #97) |
| Platform | The store (on or off), the settings registry (the default threshold), the audit log, `MenuCount`, `HomeCards`, the scheduler | Exists |
| Catalog | `variant()` — a size's product, kept on its stock row; `switchedOnVariantIds(store)` — the Low Stock list over what a store sells | The first exists; the second accepted by the owner, 2026-10-09 (Catalog's amendment 16, being written), not built yet |
| Catalog | `ListingFacts` bound, with `endingSoon` added (§2.3) | With the shop's pages (catalog.md amendment 15); `endingSoon` in the Catalog-screens session's next amendment |
| Sales | Calls §2.1; shows the "not enough stock" flag from §6.1 | Stage 6 |
| Sync | The provider's stock in, and a store's wired state — their shape waits for §9.1 | After the provider's answers |

---

## 3 · Use cases

**One permission, per store, any role** — **Manage Stock** (owner, 2026-10-09), `inventory.stock.manage`
(the name accepted 2026-10-09).

| Use case | Permission | Scope |
|---|---|---|
| `SetStock` — a stocktake: one size, or several at once | `inventory.stock.manage` | That store (no provider) |
| `AdjustStock` — add or remove, with a reason; a correction with its note | `inventory.stock.manage` | That store (no provider) |
| `SetLowStockThreshold` — one size or several; empty for the store's default | `inventory.stock.manage` | That store |
| `SetEndingSoon` — on or off for a product | `inventory.stock.manage` | That store (no provider) |
| `SetStockDependent` — on or off for the sizes picked: all of a product's, some, or one | `inventory.stock.manage` | That store (wired) |
| The store's default threshold | Platform's settings screen, the setting's own permission | That store |
| The screens' reads: a size's stock and history (`ViewStock`, `StockHistory`), the Low Stock list and its count (`LowStock`) | `inventory.stock.manage` | That store |
| The store's file — Inventory's section (§1.8) | Catalog's `catalog.listing.fill` (admin roles), checked by Catalog's handler | That store |
| `ReleaseExpiredHolds` — scheduled · `RebuildOrderable` — a repair job, every size's orderability pushed again and compared | System (reserved): `inventory.holds.expire`, `inventory.orderable.rebuild` | — |

**A store that is off**: every change refused to anyone who may not switch stores
(`inventory.store_off`). **A wired store**: hand changes and "ending soon" refused
(`inventory.provider_owns_stock`); **a store with no provider**: the stock-dependent switch refused
(`inventory.not_wired`). Every change is audited by value, from and to, in both languages.

---

## 4 · State machines

### 4.1 A hold's line

```
HELD ──ship (no provider)──────────────► SHIPPED
  ├──"reduced in the provider" (wired)─► REDUCED
  ├──cancel──────────────────────────► RELEASED
  └──its expiry──────────────────────► EXPIRED
```

A line may be shipped in parts; each part takes its pieces off. A line of an ordinary product in a
wired store is **NOTED** and never held.

### 4.2 The stock-dependent switch (wired store)

`OFF` ⇄ `ON`, per size, by hand; off by default, a size added later too.

---

## 5 · Tables

Schema `inventory`. Store-scoped rows name their store in every query. Every CHECK on a nullable
column is written so a NULL cannot slip through (lesson 162).

| Table | Columns |
|---|---|
| `inventory.stock` | (`store_id` FK `platform.stores` RESTRICT, `variant_id` FK `catalog.variants` CASCADE) PK · `product_id` (from Catalog's `variant()`; a size never moves to another product, catalog.md §1.2) · `in_stock` int CHECK ≥ 0 · `held` int CHECK ≥ 0 · `threshold` int NULL CHECK ≥ 0 · `stock_dependent` boolean (wired) · `ending_soon` boolean — the last value pushed to Catalog · `updated_at` |
| `inventory.store_products` | (`store_id`, `product_id` FK `catalog.products` CASCADE) PK · `ending_soon` boolean — "last pieces" switched on for the product (no provider) |
| `inventory.holds` | `id` ULID PK · `store_id` · `order_id` unique · `expires_at` NULL · `created_at` |
| `inventory.hold_lines` | (`hold_id` FK CASCADE, `variant_id`) PK · `quantity` CHECK ≥ 1 · `gift` boolean · `counts_on_stock` boolean · `shipped` int CHECK ≥ 0 · `ended` CHECK (`OPEN`, `SHIPPED`, `REDUCED`, `RELEASED`, `EXPIRED`) · `ended_at` NULL |
| `inventory.stock_movements` | `id` bigint PK (handoff §5.3) · `store_id` · `variant_id` · `reason` CHECK (`STOCKTAKE`, `RECEIVED`, `DAMAGED_LOST`, `OFFLINE_SALE`, `CORRECTION`, `SHIPPED`, `FILE`, `PROVIDER`) · `change` int · `before` int · `after` int · `note` NULL (required for `CORRECTION`) · `reference` unique · `actor` · `created_at` — never updated, never deleted |

---

## 6 · Events

### 6.1 Published (ids only, after commit)

| Event | When | For |
|---|---|---|
| `HoldsShort(storeId, variantId, orderIds)` | A hand removal left a size's in stock below its holds: these orders no longer fit (§1.5) | Sales flags them "not enough stock" (owner, 2026-10-09) (its shape accepted 2026-10-09) |

A low-stock event for Ops comes with Ops (stage 8).

### 6.2 Consumed

| Event | From | Inventory |
|---|---|---|
| `StoreListingChanged` | Catalog | Nothing to store: a size with no stock row counts as 0 in a store with no provider |
| `VariantArchived`, `ProductArchived` | Catalog | Nothing: stock is kept; an archived size is not on sale anyway |

---

## 7 · Errors

Every error extends `InventoryError` → `DomainError` ("inventory.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `NotEnoughStock` | CONFLICT | a hold one line short (its context names the variants and what is available) |
| `AlreadyHeld` | CONFLICT | a second hold for an order |
| `QuantityInvalid` | INVALID | a quantity below 1; a stocktake below 0 |
| `StockBelowZero` | CONFLICT | a removal larger than in stock |
| `NoteRequired` | INVALID | a correction with no note |
| `ShipMoreThanHeld` | CONFLICT | shipping more of a line than it holds |
| `ProviderOwnsStock` | CONFLICT | a hand change or "ending soon" in a wired store |
| `NotWired` | CONFLICT | the stock-dependent switch in a store with no provider |
| `ThresholdInvalid` | INVALID | a threshold below 0 |
| `StoreOff` | FORBIDDEN | changing an off store's stock without the store switch |

---

## 8 · Test scenarios

1. **Holding**: all or nothing; one short → nothing held, the shortfall named; two orders racing for
   the last piece — one wins (the atomic update, proved with two connections); a second hold for an
   order refused; an ordinary line of a wired store noted, not held; a gift held in a wired store.
2. **Shipping**: in stock and held fall together, in parts too; repeating changes nothing; a wired
   store's shipment takes nothing; more than held refused.
3. **The tick**: a wired stock-dependent line's hold ends; the worked example of handoff §12.1, step by
   step (20 − 2 = 18 … 68), with the provider's numbers arriving in between.
4. **Cancel and expiry**: frees what is left; the scheduled job frees only expired holds; repeating
   changes nothing.
5. **Orderable**: per kind of store; the provider's 0 for an ordinary product leaves it orderable;
   pushed into Catalog on every change, nothing pushed by a rolled-back change.
6. **Hand changes**: a stocktake; add and remove with each reason; a correction's note required; a
   removal below 0 refused; a removal below held allowed and `HoldsShort` naming exactly the orders
   that no longer fit; refused in a wired store and in an off store without the store switch.
7. **Low stock**: the threshold or the store's default (10); the list and its count; a wired ordinary
   product low by the provider's number, still orderable; only Manage Stock holders see it.
8. **Ending soon**: a store with no provider — only with the product's switch; a wired store —
   automatic on stock-dependent sizes, never on ordinary ones; pushed into Catalog when it turns on
   or off (a hold, a shipment, a cancel, the provider's number, a threshold, a switch), only then.
9. **Stock-dependent switch**: all of a product's sizes, some, one; a size added later starts off;
   refused in a store with no provider.
10. **The store's file**: sets the stock when switched on (a File movement); ignored in a wired store,
    the page saying so.
11. **The ledger**: append-only (an update or delete refused by the database); a repeated reference
    counted once.
12. **Permissions and audit**: Manage Stock in that store only; every change by value, both languages.
13. **The database's own refusals**: every CHECK broken once, nullable columns left NULL.

---

## 9 · Questions

### 9.1 Open

| # | Question | Waits for |
|---|---|---|
| 1 | Which warehouse / location counts for the online store, and "On hand" or "Available"? | The provider's team (asked 2026-10-09) |
| 2 | Does the provider call us when stock changes (a webhook), besides our reading every few minutes? | Same |
| 3 | Returns: does a returned piece go back into stock in a store with no provider, and when? | Sales's spec (stage 6) |
| 4 | `ListingFacts::endingSoon` (§2.3) | The Catalog-screens session's amendment |
| 5 | Inventory's screens: stock and its history, hand changes, the Low Stock list, the switches | The frontend session, after this spec |

### 9.2 Answered (owner, 2026-10-09)

| # | Question | Answer |
|---|---|---|
| 1 | A wired ordinary product the provider reports at 0 | **Stays orderable** — "since its not stock-dependent"; staff mark it "Not available now" (§1.3). Replaces handoff §9.2's provisional rule |
| 2 | The stock-dependent switch's level | **Per size**: all of a product's sizes, some, or one — "customized"; **a size added later starts off** (§1.2) |
| 3 | "Last pieces" on cards and lists too? | **Yes** — a card shows it when **any** of its sizes is ending soon (§1.2, §2.3) |
| 4 | The permission's name `inventory.stock.manage`, in the role editor's **Catalog** group ("Catalog and variants"), as no stock group exists | Accepted |
| 5 | **`HoldsShort(storeId, variantId, orderIds)`**, published after a removal — how Sales learns which orders to flag | Accepted |
| 6 | **A size with no stock row** in a store with no provider counts as 0 — not orderable until stock is set | Accepted |
| 7 | **Holds' ends kept on the holds**, the ledger keeping only changes of in stock | Accepted |
| 8 | **The setting's name** `inventory.low_stock.default` for the store's default threshold | Accepted |
