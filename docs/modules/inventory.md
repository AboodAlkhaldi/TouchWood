# Inventory — module specification

**Status (2026-10-10): the full spec, accepted** (#102); **returns (§1.9) and order edits (§1.4,
`adjustHold`) added and accepted** (#106); **being built** from 2026-10-10 in five steps — see
[its README](../../src/Modules/Inventory/README.md). §2 — what
other modules call — was agreed first and is on `main` (#97, "interfaces first", owner 2026-10-07). The
rest follows from the owner's answers of 2026-10-01 to 2026-10-10 (§9.2). What waits for the
provider's team or another session is §9.1. Handoff §12.1, revised 2026-10-07, 2026-10-09 and
2026-10-10, is the source.

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
   "reduced in the provider"** (handoff §12.1's worked example) — plus, provisionally, pieces returned
   and not yet in the provider's number (§1.9). **Gifts** count on stock in every store.
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
| low-stock threshold | Per size per store (owner, 2026-10-07); empty → **the store's default, 10 pieces** (owner, 2026-10-09; a Platform setting per store, `inventory.low_stock.default`, accepted 2026-10-09). **From 0 to 100,000**, the default and each size's own alike (owner, 2026-10-10). |
| stock-dependent | **Only in a wired store** (owner, 2026-10-07), off by default, **per size**: staff pick a product's sizes — all of them, some, or one (owner, 2026-10-09: "so its customized"). **A size added later starts off**; staff pick it (owner, 2026-10-09). |

**"Ending soon" ("last pieces")** for customers (owner, 2026-10-07/09), when a size's available stock
is at or below its threshold: **in a store with no provider**, where staff switch it on for the
product; **in a wired store**, automatically on stock-dependent sizes only — other products there
never show it. **It shows on cards and lists too** (owner, 2026-10-09): a card says "last pieces"
when **any of its product's sizes that this viewer can buy and order now** is ending soon (owner,
2026-10-10) — a size sold only wholesale never lights an individual's card, and a sold-out size
lights none. **On a product's page, the size options mark the ending-soon size itself**, not the
other sizes (owner, 2026-10-10). Inventory pushes it per size into Catalog's listing, as it does
orderable (§2.3); Catalog decides each card per viewer.

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
  a bank transfer being verified — Sales sets it); a scheduled job frees expired holds, **every minute**
  (owner, 2026-10-11).
- **Staff edit an order before it ships** (owner, 2026-10-10, through stage 6): lines added, removed,
  quantities changed. The hold **moves to exactly the edited lines, all or nothing** — the extra
  pieces held as at placement (one line short and nothing changes, `inventory.not_enough_stock`), the
  fewer freed; the same rules decide which lines count on stock; the expiry stays. A line already
  shipped or ticked is not editable (`inventory.hold_not_editable`).
- Shipping, ticking, freeing and editing are safe to repeat.

### 1.5 Hand changes — a store with no provider

Under **Manage Stock** in that store (owner, 2026-10-09):

- **"Set to"** a counted number — a stocktake. **A count that finds the number right is recorded
  too** — a stocktake line with change 0, so the history shows when a size was last counted — and is
  not audited, as nothing changed (owner, 2026-10-11).
- **"Add" or "Remove"** an amount, with a reason: **Received**, **Damaged / lost**, **Offline sale**, or
  **Correction** with a short note (owner, 2026-10-09). **Each reason goes one way** (owner,
  2026-10-11): Received only adds; Damaged / lost and Offline sale only remove; a Correction goes
  either way, with its note. So the history always reads right.
- **An archived size — or a size of an archived product — is read-only until it is restored** (owner,
  2026-10-11): its stock is kept as it is; every hand change to it is refused. The rule covers hand
  changes only: an order already holding such a size still ships, is cancelled or comes back as usual
  (owner, 2026-10-11).
- **Removing pieces held for orders is allowed** (owner, 2026-10-09): in stock may fall below held, but
  never below 0 — by a removal or a stocktake alike. **No placed order is blocked or cancelled**: staff
  sort it out (owner, 2026-10-11). **Every placed order still holding that size is marked** "stock was
  reduced", so staff see which to check — Inventory names them all (§6.1), not only those the pieces
  left cannot cover, and Sales shows the mark; a shopper still before checkout sees how many are left
  (`stock()`).
- In a wired store, stock is the provider's and read-only here.

### 1.6 The ledger

`inventory.stock_movements` is **append-only**: every change of in stock — a stocktake, an addition or
removal with its reason, a shipment, a return, the store's file, the provider's number — with its before and
after, who or what made it, and a unique reference, so a repeated message is never counted twice
(handoff §12.1). Holds and their ends are kept on the holds themselves (§5).

### 1.7 Low stock

A size is **low** when its available stock is at or below its threshold — in a wired store, the
provider's number for an ordinary product. Per store, the **Low Stock list** — every low size — with a
**count beside it in the menu** (Platform's `MenuCount`) and a **Home card** (Platform's `HomeCards`;
the handoff's dashboard "low stock"), seen by holders of **Manage Stock** there (owner, 2026-10-09). In
the panel only until Ops (stage 8) adds email and SMS (owner, 2026-10-07). *(Step 4b:)* the menu's count
adds up the stores the reader holds Manage Stock in; its entry comes with the list's screen, as the menu
links every entry to its route.

**A size given only as a gift** — one the store does not sell (Promotions may give any ready size) —
(accepted with #106): staff may set its stock like any other, and once it has a stock row it is on the Low
Stock list too. The list covers every size the store has switched on **and** every size with a stock
row there. Without stock it gives nothing (`availableAsGift` 0). **An archived size — or a size of an
archived product — is left off** the list and its count: staff cannot change its stock until it is
restored, and then it is back if it is low (owner, 2026-10-13).

### 1.8 The store's file (catalog.md §2.3)

Inventory's `ImportSection`: the file's page says, per item, the stock that will be set — or that it
is ignored in a wired store, the provider being its source. **When an item is switched on**, the stock
**sets** the size's in stock (a "set to" movement, reason **File**), audited as the file's. **One code is
one size** (owner, 2026-10-09; Catalog's amendment 16). *(Step 4b:)* the stock is set by a job queued
once Catalog's change commits, never inside it — Catalog holds its products' lock there, and Inventory
takes its store's lock before telling Catalog, so the two orders would meet; the job runs for whoever
switched the item on.

### 1.9 Returns

**Returned pieces go back into stock when staff mark the return received** (owner, 2026-10-09,
through stage 6: "1 yes"). Sales passes only the pieces going back — **staff may leave a damaged piece
out** — and each return once (its id makes it safe to repeat).

| Store, size | What happens |
|---|---|
| A store with no provider | **In stock rises** — a movement, reason **Returned** |
| A wired store, an ordinary product | **Nothing**: the provider's number rules ("otherwise it depends on odoo") |
| A wired store, a **stock-dependent** size, or a **gift** (a gift counts on stock in every store) | **PROVISIONAL** (owner, 2026-10-10) — see below |

**The provisional rule** — to be discussed again when the provider's team says whether we may write
to it (owner, 2026-10-10: "keep it in mind that we're gonna rediscuss this again"). Until then we
assume we may not: the returned pieces are **added on our side at once** — the size's **returned
extra** — so the size's figure is **the provider's number + returned extra − held**. **The next time
the provider's number changes, we follow the provider**: the returned extra goes back to 0. A reading
with the same number changes nothing. If staff add the piece in the provider, its number changes and
nothing is counted twice; if the number changes for another reason first, the piece is undercounted
until staff add it there. **The one way to overcount**: the piece is added in the provider and another
taken there between two of our readings, so the number comes back the same — then one piece too many
shows until the provider's number next changes. A known limit of the provisional rule, for the
discussion.

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
| `release(string $orderId): void` | Sales (a cancel; a shipped parcel that came back, sales.md §1.5), Inventory's own expiry job | Frees whatever the order still holds — and only that: after a shipment, what was shipped or ticked is not held any more and is left alone (in a store with no provider a fully shipped order frees nothing; in a wired store, the stock-dependent and gift lines not yet ticked). |
| `adjustHold(string $orderId, list<HoldLineDto> $newLines): void` | Sales (staff edited an order before it ships) | Moves the order's hold to exactly `newLines`, all or nothing (§1.4): the extra held, refused with `inventory.not_enough_stock` as at placement; lowered or removed lines freed; the expiry kept. No hold for the order: `inventory.no_hold`; a line already shipped or ticked: `inventory.hold_not_editable`. (stage 6's shape; accepted with #106) |
| `returned(string $orderId, string $returnId, list<HoldLineDto> $lines): void` | Sales (staff marked a return received; a shipped parcel marked "came back", settled as a whole return — sales.md §1.5, owner 2026-10-10 — after `release`, with its own id in `returnId`) | Puts the lines' pieces back as §1.9 says, by kind of store and size; a gift line on gift rules. Only the pieces going back — for a parcel that came back, those whose stock was taken: every shipped piece in a store with no provider, the ticked lines in a wired store. The store is the order's (its hold). (agreed with stage 6; accepted with #106) |

`ship`, `reducedInProvider`, `release`, `adjustHold` and `returned` are safe to repeat: a line already
shipped, ticked or freed is left as it is; the same edit twice changes nothing; a return id counts once.
`ship` has no shipment id: a line shipped in full is left as it is, but a part sent twice would be
taken twice - Sales sends each part once (step 3).

**Step 3's details:** a store that does not exist, or an order id that is not one, is invalid input
(fields `store`, `order`); `inventory.not_enough_stock`'s context is `variants` and `available`, each
comma-separated in the same order (a context holds plain values); shipping more pieces than are in
stock (a hand removal took them) is `inventory.stock_below_zero`. A hold is one conditional update per
size, all in one transaction, in size-id order (`StockRows`); the race for the last piece is decided by
that update - the test suite proves the update's form and the second order refused, not two processes.

**A line is a size and whether it is a gift** (stage 5's review, 2026-10-10): an order may hold a
bought line and a gift line of the same size — in a wired store the bought one may be only noted while
the gift is held. `HoldLineDto`'s `gift` says which line is meant in `hold`, `ship`, `adjustHold` and
`returned`; `reducedInProvider` names sizes and ends **both** lines of a size, as staff reduce the
provider by the size. A refusal reaches the caller as `Shared\Domain\Error\DomainError` with a stable
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
  sizes **this viewer can buy and order now** is (owner, 2026-10-10), and on the ending-soon size
  among a product page's options. **Catalog's amendment 16(h)** (PR #103), with the owner's rules of
  2026-10-09 and 2026-10-10.
- An `ImportSection` for the store's file — §1.8.

### 2.4 What Inventory needs from other modules

| From | What | State |
|---|---|---|
| Access | Declaring **Manage Stock** in the `Catalog` group (accepted 2026-10-09) — no stock group exists; the role editor's groups are the design's | Allowed for that only (owner, 2026-10-07; `deptrac.yaml` since #97) |
| Platform | The store (on or off), the settings registry (the default threshold), the audit log, `MenuCount`, `HomeCards`, the scheduler | Exists |
| Catalog | `variant()` — a size's product, kept on its stock row; `switchedOnVariantIds(store)` — the Low Stock list over what a store sells | Both on `main`: the first from the start, the second with Catalog's amendment 16 (#114, 5bffba6) |
| Catalog | **Many sizes and products in one read** — the Low Stock list's and the stock screens' names, within an admin page's 15 queries (frontend.md §5): `variants(list<string> $variantIds)`, `products(list<string> $productIds)`, keyed by id | On `main` with Catalog's amendment 16(i) (#114, 5bffba6) |
| Catalog | `ListingFacts` bound, with `endingSoon` added (§2.3) | With the shop's pages (catalog.md amendment 15); `endingSoon` is amendment 16(h) (PR #103) — kept in `catalog.store_variant_facts.ending_soon` |
| Sales | Calls §2.1; shows the "not enough stock" flag from §6.1 | Stage 6 |
| Sync | The provider's stock in, and a store's wired state — **`ProviderStock`** and `StoreWiring` in Inventory's Public contracts (sync.md §2.3, §1.5, §1.8) | §2.5; built with Sync step 2a (2026-10-17). Sync binds `StoreWiring` with its step 3; until then no store is wired |

### 2.5 What Inventory gives Sync

Module to module, as §2.1: no permission is checked here — Sync's read checks its own — and nothing
here is audited: Sync audits each read once, the detail being in the ledger (sync.md §1.6).

| Contract | Says |
|---|---|
| `StoreWiring::isWired(StoreId): bool` | Moved from Inventory's Application layer to its Public contracts, so Sync can answer it. Inventory binds "no store is wired" until Sync binds its own. |
| `ProviderStock::apply(StoreId, array<variantId, int>, string $readRef): ProviderStockResult` | Each size's in stock set to the provider's number (§1.1 rule 3, §1.9; sync.md §1.5 #3): the same number as now changes nothing — a size with no row that the provider has at 0 gets none, so sizes the store does not sell stay off its Low Stock list; a different one is a `PROVIDER` movement to it, its reference the read's and the size's, so the same read run again is counted once; the size's returned extra goes back to 0; orderable and "last pieces" pushed to Catalog. A size switched off in the store, or of a draft product, takes it too. 500 sizes a transaction, under the store's lock, the wiring asked again in each: a store unwired while the read ran gets nothing from then on (`wired` false). **Not written, and named in the result** for Sync to report: an archived size or one of an archived product, a size not found, a number below 0 or above the most pieces a size holds (Sync drops fractions and keeps a number below 0 as 0 first, sync.md §1.5 #2), a second id written for the same size. `HoldsShort` is not sent: §6.1 names hand changes only. **Sync's part**: `readRef` is the read's own id (letters, digits, `-` and `_`, at most 64 — the ledger's reference holds 120), never reused; one read per store at a time, a retry asking the provider again (sync.md §1.3, §1.7) — an old read's numbers are never applied after a later read's. |
| `ProviderStock::wired(StoreId): void` | Sync calls it once the store is wired (`StoreWiring` answers true; refused otherwise; sync.md §1.2): every size switched on or with a row is told to Catalog again for a wired store — an ordinary product orderable whatever its number (§1.3). Nothing is written. *(Added in step 2a's review: without it, a size the provider reports at the number it had, or never reports, kept the unwired figures.)* |
| `ProviderStock::stopsWhenUnwired(StoreId): int` | How many sizes switched on in the store would stop being orderable once it is unwired — orderable now, nothing free with no provider: ordinary ones at 0 or all held, sizes never counted — for the Super Admin's confirmation (sync.md §1.8). Reads only. |
| `ProviderStock::unwired(StoreId): int` | Sync calls it once the store is unwired (`StoreWiring` answers false; refused otherwise): each size's returned extra — a stock-dependent size's or a gift's (§1.9) — is added into its in stock, a `CORRECTION` movement noted "unwired" (sync.md §9.2 #12); every size switched on or with a row is told to Catalog again for a store with no provider, a size with no row as 0. Nothing else is cleared. Answers how many sizes switched on there stopped being orderable. |

`ProviderStockResult`: `wired` · `changed`, `archived`, `notFound`, `invalid` — lists of the variant ids
as given · `changes` (size, `before`, `after`, whole numbers — for Sync's page of reads, sync.md §9.3 #19).

---

## 3 · Use cases

**One permission, per store, any role** — **Manage Stock** (owner, 2026-10-09), `inventory.stock.manage`
(the name accepted 2026-10-09).

| Use case | Permission | Scope |
|---|---|---|
| `SetStock` — a stocktake: one size, or several at once | `inventory.stock.manage` | That store (no provider) |
| `AdjustStock` — one size: add or remove, with a reason; a correction with its note | `inventory.stock.manage` | That store (no provider) |
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
wired store is **NOTED** and never held. **While HELD and nothing shipped or ticked**, an edit
(`adjustHold`) may change its quantity, or end it as **RELEASED** when the line is removed; a line the
edit adds starts HELD (or NOTED).

### 4.2 The stock-dependent switch (wired store)

`OFF` ⇄ `ON`, per size, by hand; off by default, a size added later too.

---

## 5 · Tables

Schema `inventory`. Store-scoped rows name their store in every query. Every CHECK on a nullable
column is written so a NULL cannot slip through (lesson 162).

| Table | Columns |
|---|---|
| `inventory.stock` | (`store_id` FK `platform.stores` RESTRICT, `variant_id` FK `catalog.variants` CASCADE) PK · `product_id` FK `catalog.products` CASCADE (from Catalog's `variant()`; a size never moves to another product, catalog.md §1.2) · `in_stock` int CHECK ≥ 0 · `held` int CHECK ≥ 0 · `threshold` int NULL CHECK 0–100,000 · `stock_dependent` boolean (wired) · `returned_extra` int NOT NULL DEFAULT 0 CHECK ≥ 0 — pieces returned in a wired store, not yet in the provider's number (§1.9, provisional) · `ending_soon` boolean — the last value pushed to Catalog · `orderable` boolean NULL — the same for orderability, null when never pushed (step 4: only a flip is pushed; sizes switched on, the default threshold's change and the repair tell Catalog too) · `updated_at` |
| `inventory.store_products` | (`store_id` FK `platform.stores` RESTRICT, `product_id` FK `catalog.products` CASCADE) PK · `ending_soon` boolean — "last pieces" switched on for the product (no provider) · `updated_at` |
| `inventory.holds` | `id` ULID PK · `store_id` · `order_id` unique · `expires_at` NULL · `created_at` |
| `inventory.hold_lines` | (`hold_id` FK CASCADE, `variant_id`, `gift`) PK — a bought line and a gift line of the same size are two lines · `quantity` CHECK ≥ 1 · `gift` boolean · `counts_on_stock` boolean · `shipped` int CHECK ≥ 0 · `ended` CHECK (`OPEN`, `SHIPPED`, `REDUCED`, `RELEASED`, `EXPIRED`) · `ended_at` NULL |
| `inventory.stock_movements` | `id` bigint PK (handoff §5.3) · `store_id` FK RESTRICT · `variant_id` — **no key**, so a deleted draft size keeps its history, as the audit log names its subjects (step 2) · `reason` CHECK (`STOCKTAKE`, `RECEIVED`, `DAMAGED_LOST`, `OFFLINE_SALE`, `CORRECTION`, `SHIPPED`, `RETURNED`, `FILE`, `PROVIDER`) · `change` int · `before` int ≥ 0 · `after` int ≥ 0 = `before` + `change` · `note` NULL, up to 500 characters (required, not blank, for `CORRECTION`) · `reference` unique · `actor_type` + `actor_id` NULL — the system's alone — and `requested_by_type` + `requested_by_id` NULL — whoever queued the job the system runs — as the audit log records its actor, with the same CHECKs (step 2; one `actor` column before) · each reason's direction CHECKed: Received and Returned in, Damaged / lost, Offline sale and Shipped out, a Correction either way and never 0, a stocktake, the file and the provider any number (owner, 2026-10-11) · `created_at` — never updated, never deleted (a trigger refuses UPDATE, DELETE and TRUNCATE) |
| `inventory.return_lines` | (`return_id`, `variant_id`, `gift`) PK · `store_id` · `order_id` · `quantity` CHECK ≥ 1 · `gift` boolean · `created_at` — each return counted once, and its history |

---

## 6 · Events

### 6.1 Published (ids only, after commit)

| Event | When | For |
|---|---|---|
| `HoldsShort(eventId, storeId, variantId, orderIds, occurredAt)` | A hand change — a removal or a stocktake — left a size's in stock below its holds: **every** placed order still holding it is named (§1.5; owner, 2026-10-11) | Sales marks them "stock was reduced" for staff; none is blocked (owner, 2026-10-09 and 2026-10-11) (its shape accepted 2026-10-09) |

A low-stock event for Ops comes with Ops (stage 8).

### 6.2 Consumed

| Event | From | Inventory |
|---|---|---|
| `StoreListingChanged` | Catalog | The sizes switched on are told to Catalog at once - with no stock row as 0 (step 4); nothing stored |
| `SettingChanged` (`inventory.low_stock.default`) | Platform | The store's sizes told again: "last pieces" may flip (step 4) |
| `VariantArchived`, `ProductArchived` | Catalog | Nothing: stock is kept; an archived size is not on sale anyway, and read-only by hand until restored (owner, 2026-10-11) — read from `VariantDto::isArchived` and `ProductDto::stage` when a change names it |

---

## 7 · Errors

Every error extends `InventoryError` → `DomainError` ("inventory.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `NotEnoughStock` | CONFLICT | a hold one line short (its context names the variants and what is available) |
| `AlreadyHeld` | CONFLICT | a second hold for an order |
| `QuantityInvalid` | INVALID | a quantity below 1; a stocktake below 0; a number of pieces above 2,147,483,647, what the column keeps |
| `StockBelowZero` | CONFLICT | a removal larger than in stock |
| `NoteRequired` | INVALID | a correction with no note |
| `ShipMoreThanHeld` | CONFLICT | shipping more of a line than it holds |
| `ProviderOwnsStock` | CONFLICT | a hand change or "ending soon" in a wired store |
| `NotWired` | CONFLICT | the stock-dependent switch in a store with no provider |
| `ThresholdInvalid` | INVALID | a threshold below 0 or above 100,000 |
| `StoreOff` | FORBIDDEN | changing an off store's stock without the store switch |
| `NoHold` | NOT_FOUND | editing or returning for an order with no hold |
| `HoldNotEditable` | CONFLICT | editing a hold whose line is already shipped or ticked |
| `InvalidInventoryInput` | INVALID | a store, a size or a product that does not exist (owner, 2026-10-10: an unknown store is invalid input); an archived size or product (owner, 2026-10-11); one size given twice with two numbers; a direction that is not add or remove; a reason a hand change does not take, or one going the other way (owner, 2026-10-11); a note over 500 characters — named by its field (`store`, `variant`, `product`, `order`, `direction`, `reason`, `note`), as Catalog's invalid attribute is |

---

## 8 · Test scenarios

1. **Holding**: all or nothing; one short → nothing held, the shortfall named; two orders racing for
   the last piece — one wins (the atomic update, proved with two connections); a second hold for an
   order refused; an ordinary line of a wired store noted, not held; a gift held in a wired store; a
   bought line and a gift line of the same size in one order, each held, shipped and freed on its own.
2. **Shipping**: in stock and held fall together, in parts too; repeating changes nothing; a wired
   store's shipment takes nothing; more than held refused.
3. **The tick**: a wired stock-dependent line's hold ends; the worked example of handoff §12.1, step by
   step (20 − 2 = 18 … 68), with the provider's numbers arriving in between.
4. **Cancel and expiry**: frees what is left; the scheduled job frees only expired holds; repeating
   changes nothing; after a shipment it leaves shipped and ticked lines alone. **A parcel that came
   back**: `release` then `returned` — a store with no provider puts every shipped piece back; a wired
   store frees its unticked stock-dependent and gift lines and puts back the ticked ones (§1.9).
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
14. **Editing an order's hold**: a quantity raised — all or nothing, one line short and nothing
    changes; lowered and removed lines freed; a line added; the same edit twice changes nothing; the
    expiry kept; a wired ordinary line noted; refused after a shipment or a tick; no hold refused.
15. **Returns**: a store with no provider — in stock rises, a Returned movement; a wired ordinary
    product — nothing; a wired stock-dependent size and a gift — the returned extra rises, the figure
    is the provider's number + extra − held, a differing provider number sets the extra to 0, an equal
    one keeps it; a damaged piece not passed is not counted; the same return id twice counted once;
    "ending soon" and orderable pushed when they flip.

---

## 9 · Questions

### 9.1 Open

| # | Question | Waits for |
|---|---|---|
| 1 | Which warehouse / location counts for the online store, and "On hand" or "Available"? | The provider's team (asked 2026-10-09) |
| 2 | ~~Does the provider call us when stock changes?~~ **Answered** (owner, 2026-10-13): it notifies us of price and stock changes; we also read hourly, nightly and on Sync now (sync.md §1.3) | — |
| 3 | **Returns in a wired store** (§1.9): the provisional rule stands until the provider's team says whether we may write to it — then the owner and I discuss it again | The provider's team (owner, 2026-10-10) |
| 4 | `ListingFacts::endingSoon` (§2.3) | Catalog's amendment 16(h), accepted (#103, 50e9991); its backend being built |
| 5 | Inventory's screens: stock and its history, hand changes, the Low Stock list, the switches | The frontend session, after this spec |

### 9.2 Answered (owner, 2026-10-09, 2026-10-10 and 2026-10-11)

| # | Question | Answer |
|---|---|---|
| 1 | A wired ordinary product the provider reports at 0 | **Stays orderable** — "since its not stock-dependent"; staff mark it "Not available now" (§1.3). Replaces handoff §9.2's provisional rule |
| 2 | The stock-dependent switch's level | **Per size**: all of a product's sizes, some, or one — "customized"; **a size added later starts off** (§1.2) |
| 3 | "Last pieces" on cards and lists too? | **Yes** — a card shows it when **any** of its sizes is ending soon (§1.2, §2.3); narrowed to the sizes the viewer can buy and order now (#12) |
| 4 | The permission's name `inventory.stock.manage`, in the role editor's **Catalog** group ("Catalog and variants"), as no stock group exists | Accepted |
| 5 | **`HoldsShort(storeId, variantId, orderIds)`**, published after a removal — how Sales learns which orders to flag | Accepted |
| 6 | **A size with no stock row** in a store with no provider counts as 0 — not orderable until stock is set | Accepted |
| 7 | **Holds' ends kept on the holds**, the ledger keeping only changes of in stock | Accepted |
| 8 | **The setting's name** `inventory.low_stock.default` for the store's default threshold | Accepted |
| 9 | Returns: does a returned piece go back into stock, and when? (asked in Sales's spec) | **Yes, when staff mark the return received** — in a store with no provider and for a wired store's stock-dependent sizes; a wired ordinary product follows the provider; staff may leave a damaged piece out (owner, 2026-10-09) (§1.9) |
| 10 | A wired stock-dependent size's returned piece | **Provisional** (owner, 2026-10-10): added on our side at once, dropped when the provider's number next changes (§1.9) |
| 11 | Staff editing an order before it ships | **Allowed in full** — lines, quantities, address (owner, 2026-10-10, through stage 6); the hold follows (§1.4) |
| 12 | "Last pieces" on a card: which sizes count? | **The sizes this viewer can buy and order now**; on a product's page the size options mark the ending-soon size itself (owner, 2026-10-10) (§1.2) |
| 13 | `adjustHold` and `returned`, and a gift-only size's stock (§1.7, §2.1) | **Accepted** with #106 (2026-10-10) |
| 14 | An upper limit on the low-stock threshold? | **100,000**, for the store's default and each size's own (owner, 2026-10-10) (§1.2) |
| 15 | Do the hand reasons fix the direction? | **Yes**: Received only adds; Damaged / lost and Offline sale only remove; Correction either way, with its note (owner, 2026-10-11) (§1.5) |
| 16 | A stocktake that finds the number right | **Recorded** as a stocktake line with change 0; not audited (owner, 2026-10-11) (§1.5) |
| 17 | An archived size | **Read-only until restored**, in Inventory and Pricing alike — a size of an archived product too (owner, 2026-10-11) (§1.5) |
| 18 | An archived size in an order already placed | **The order still goes through**: shipped, cancelled, returned as usual — the rule covers hand changes only (owner, 2026-10-11) (§1.5) |
| 19 | Stock reduced by hand below what placed orders hold: which orders? | **None is blocked**; staff sort it out; **every** order still holding the size is marked for staff; a shopper before checkout sees how many are left (owner, 2026-10-11) (§1.5, §6.1) |
| 20 | How often expired holds are freed | **Every minute** (owner, 2026-10-11) (§1.4) |
