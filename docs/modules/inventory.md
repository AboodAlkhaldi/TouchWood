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

**A line's note** (owner, 2026-10-20; stage 5 step 10): one typed by staff is kept and shown as typed. The
notes Inventory writes itself are kept as **a code and its values** (`LedgerNote`; `note_code`,
`note_values`) and written in the page's language when the history shows them, as Sync keeps its own reasons
(sync.md Q31): `SHIPPED_BEYOND_COUNT` with `count` - "Shipped 2 more than counted. Recount." / "شُحن 2 أكثر
من المعدود. أعد الجرد." (§2.1 `ship`) - and `UNWIRED` - "The store was unwired: the pieces returned while it
was wired are added." / "فُكّ ربط المتجر: أُضيفت القطع المرتجعة أثناء ربطه." (§2.5 `ProviderStock::unwired`).
A line written before the codes keeps its English text ("2 more shipped than counted - recount",
"unwired") and is shown as it is: the ledger is append-only. Words: `admin_stock.history_notes`.

### 1.7 Low stock

A size is **low** when its available stock is at or below its threshold — in a wired store, the
provider's number for an ordinary product. Per store, the **Low Stock list** — every low size — with a
**count beside it in the menu** (Platform's `MenuCount`) and a **Home card** (Platform's `HomeCards`;
the handoff's dashboard "low stock"), seen by holders of **Manage Stock** there (owner, 2026-10-09). In
the panel only until Ops (stage 8) adds email and SMS (owner, 2026-10-07). *(Step 4b:)* the menu's count
adds up the stores the reader holds Manage Stock in; its entry comes with the list's screen, as the menu
links every entry to its route. *(Step 5; owner, 2026-10-18:)* each store's count is **kept**
(`inventory.low_counts`) and the menu reads it in **one query**: counted again by a job with the list's
own reader - so the list, the count and the Home card never disagree - after every change of stock,
holds, thresholds, wiring or the store's default, when a store switches sizes off (catalog.md amendment
22), when a size or a product is archived or restored, and each night for every store. One recount waits per store and runs 30 seconds after the change, so the
count is about half a minute behind the list at most; a store not counted yet is counted live once. *(Owner, 2026-10-20:)* the stores counted live
ask whether they are wired **once between them** (`StoreWiring::wiredStores`), not once each - asked in that
request, never kept (sync.md §1.8); a Super Admin's frame with no count kept read 3 such queries, now 1
(`AdminFrameQueryBudgetTest`).

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
| A wired store, a **stock-dependent** size, or a **gift** (a gift counts on stock in every store) | **The returned extra** (owner, 2026-10-10; final 2026-10-19) — see below |

**The rule** — final (owner, 2026-10-19: nothing is written to the provider; it was provisional until
that answer, 2026-10-10): the returned pieces are **added on our side at once** — the size's **returned
extra** — so the size's figure is **the provider's number + returned extra − held**. **The next time
the provider's number changes, we follow the provider**: the returned extra goes back to 0. A reading
with the same number changes nothing. If staff add the piece in the provider, its number changes and
nothing is counted twice; if the number changes for another reason first, the piece is undercounted
until staff add it there. **The one way to overcount**: the piece is added in the provider and another
taken there between two of our readings, so the number comes back the same — then one piece too many
shows until the provider's number next changes. A known limit of the rule.

---

## 2 · Public contract

Module to module (handoff §4.3): no permission is checked — the calling use case checks its own.
Quantities are whole pieces (≥ 1). **On `main` since #97.**

### 2.1 `Modules\Inventory\Public\Contracts\InventoryApi`

| Method | For | Says |
|---|---|---|
| `stock(StoreId $store, list<string> $variantIds): array<string, StockDto>` | Sales (cart, checkout) | For each variant: whether its stock limits ordering here, whether it can be ordered now, how many can be ordered (null where stock does not limit), and whether it is "ending soon". Never shown to a customer as a number. |
| `hold(StoreId $store, string $orderId, list<HoldLineDto> $lines, ?DateTimeImmutable $expiresAt): void` | Sales (placing an order) | Holds every line that counts on stock — **all or nothing**: one line short and nothing is held (`inventory.not_enough_stock`, its context naming the variants and what is available). Lines whose stock does not limit ordering are noted, not held. One hold per order (`inventory.already_held`). |
| `ship(string $orderId, list<HoldLineDto> $lines): void` | Sales (an order or part of it shipped) | Takes the shipped pieces off the stock and off the hold — in a store with no provider. In a wired store nothing is taken: the provider is the source; a stock-dependent line's hold waits for the tick. **Never refused for the stock** (owner, 2026-10-19; sales.md answer 41; §9.2 #22): a shipment larger than what is in stock — a hand removal took the pieces — takes what there is, so the stock stops at 0, never below: one `SHIPPED` line, before → 0, its note naming the shortfall for a recount (Inventory's own code `SHIPPED_BEYOND_COUNT` with the count, shown "Shipped 2 more than counted. Recount." / "شُحن 2 أكثر من المعدود. أعد الجرد." - §1.6); the line is still shipped by its full quantity and the hold lets go of every piece. Stock already at 0 takes nothing, and the shortfall is still recorded for a recount: a `SHIPPED` line 0 → 0 with its note (owner, 2026-10-19) — the only shipped line that changes nothing, and only with a note (§5's direction check). The size then shows on Low Stock at 0 as usual. |
| `reducedInProvider(string $orderId, list<string> $variantIds): void` | Sales (staff ticked "reduced in the provider") | Ends those lines' holds in a wired store. |
| `release(string $orderId): void` | Sales (a cancel; a shipped parcel that came back, sales.md §1.5), Inventory's own expiry job | Frees whatever the order still holds — and only that: after a shipment, what was shipped or ticked is not held any more and is left alone (in a store with no provider a fully shipped order frees nothing; in a wired store, the stock-dependent and gift lines not yet ticked). |
| `adjustHold(string $orderId, list<HoldLineDto> $newLines): void` | Sales (staff edited an order before it ships) | Moves the order's hold to exactly `newLines`, all or nothing (§1.4): the extra held, refused with `inventory.not_enough_stock` as at placement; lowered or removed lines freed; the expiry kept. No hold for the order: `inventory.no_hold`; a line already shipped or ticked: `inventory.hold_not_editable`. (stage 6's shape; accepted with #106) |
| `returned(string $orderId, string $returnId, list<HoldLineDto> $lines): void` | Sales (staff marked a return received; a shipped parcel marked "came back", settled as a whole return — sales.md §1.5, owner 2026-10-10 — after `release`, with its own id in `returnId`) | Puts the lines' pieces back as §1.9 says, by kind of store and size; a gift line on gift rules. Only the pieces going back — for a parcel that came back, those whose stock was taken: every shipped piece in a store with no provider, the ticked lines in a wired store. The store is the order's (its hold). (agreed with stage 6; accepted with #106) |
| `makeStockDependent(StoreId $store, list<string> $variantIds): list<string>` | Promotions (a gift level saved with its tick box, promotions.md §4.1 M4) | Turns the sizes' stock-dependent switch **on, never off**, in a **wired** store, as `SetStockDependent` does — the row made, the flag set, the same audit entry (`inventory.stock.stock_dependent_set`), Catalog's facts refreshed, the store's lock then the rows in size-id order (one shared piece of code). A store that does not exist is refused (`store`); then a size that does not exist or is archived (or of an archived product), in every store alike, as staff's switch refuses it — `inventory.invalid_inventory_input`, `variant`, naming them — and nothing changes. Then nothing happens in a store with no provider (every size counts on its stock there already) nor for a size already on. The wiring is asked again under the store's lock, which unwiring takes too: a store unwired while the call waited for it is left as it is (staff's switch is refused there, `inventory.not_wired`). Runs in the caller's transaction when one is open (a savepoint in it), its own otherwise. **Call it last in that transaction, take no lock of the caller's own module after it, and keep the transaction short**: Inventory's store lock and Catalog's products lock (the facts) are held until the caller commits. Answers the sizes it turned on, by their own ids, in id order. **No permission inside**: Promotions checks `inventory.stock.manage` in the store itself, by its public name `Modules\Inventory\Public\InventoryPermissions::STOCK_MANAGE` (owner, 2026-10-19; §9.2 #23) |

`ship`, `reducedInProvider`, `release`, `adjustHold` and `returned` are safe to repeat: a line already
shipped, ticked or freed is left as it is; the same edit twice changes nothing; a return id counts once.
`ship` has no shipment id: a line shipped in full is left as it is, but a part sent twice would be
taken twice - Sales sends each part once (step 3). **`ship` must be called once per real shipment**: a
part sent twice is shipped twice, and one that runs short notes its shortfall twice.

**Step 3's details:** a store that does not exist, or an order id that is not one, is invalid input
(fields `store`, `order`); `inventory.not_enough_stock`'s context is `variants` and `available`, each
comma-separated in the same order (a context holds plain values); shipping more pieces than are in
stock (a hand removal took them) is no longer refused - the stock stops at 0 and the shortfall is
noted, from 0 too (owner, 2026-10-19; §9.2 #22); `inventory.stock_below_zero` stays a hand removal's. A hold is one conditional update per
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
| `StoreWiring::wiredStores(): list<string>` | *(Stage 5's screens step 4:)* every store wired now, by id, in one read - for a screen asking of many stores at once (a product's stock in every store, what the screens may offer), as Pricing's `StoreWiring` answers it. Sync's `ConnectionWiring` answers both modules' with one method. |
| `ProviderStock::apply(StoreId, array<variantId, int>, string $readRef): ProviderStockResult` | Each size's in stock set to the provider's number (§1.1 rule 3, §1.9; sync.md §1.5 #3): the same number as now changes nothing — a size with no row that the provider has at 0 gets none, so sizes the store does not sell stay off its Low Stock list; a different one is a `PROVIDER` movement to it, its reference the read's and the size's, so the same read run again is counted once; the size's returned extra goes back to 0; orderable and "last pieces" pushed to Catalog. A size switched off in the store, or of a draft product, takes it too. 500 sizes a transaction, under the store's lock, the wiring asked again in each: a store unwired while the read ran gets nothing from then on (`wired` false). **Not written, and named in the result** for Sync to report: an archived size or one of an archived product, a size not found, a number below 0 or above the most pieces a size holds (Sync drops fractions and keeps a number below 0 as 0 first, sync.md §1.5 #2), a second id written for the same size. `HoldsShort` is not sent: §6.1 names hand changes only. **Sync's part**: `readRef` is the read's own id (letters, digits, `-` and `_`, at most 64 — the ledger's reference holds 120), never reused; one read per store at a time, a retry asking the provider again (sync.md §1.3, §1.7) — an old read's numbers are never applied after a later read's. |
| `ProviderStock::wired(StoreId): void` | Sync calls it once the store is wired (`StoreWiring` answers true; refused otherwise; sync.md §1.2): every size switched on or with a row is told to Catalog again for a wired store — an ordinary product orderable whatever its number (§1.3). Nothing is written. *(Added in step 2a's review: without it, a size the provider reports at the number it had, or never reports, kept the unwired figures.)* |
| `ProviderStock::stopsWhenUnwired(StoreId): int` | How many sizes switched on in the store would stop being orderable once it is unwired — orderable now, nothing free with no provider: ordinary ones at 0 or all held, sizes never counted — for the Super Admin's confirmation (sync.md §1.8). Reads only. |
| `ProviderStock::unwired(StoreId): int` | Sync calls it once the store is unwired (`StoreWiring` answers false; refused otherwise): each size's returned extra — a stock-dependent size's or a gift's (§1.9) — is added into its in stock, a `CORRECTION` movement noted with Inventory's own code `UNWIRED` (sync.md §9.2 #12; §1.6); every size switched on or with a row is told to Catalog again for a store with no provider, a size with no row as 0. Nothing else is cleared. Answers how many sizes switched on there stopped being orderable. |

`ProviderStockResult`: `wired` · `changed`, `archived`, `notFound`, `invalid` — lists of the variant ids
as given · `changes` (size, `before`, `after`, whole numbers — for Sync's page of reads, sync.md §9.3 #19).

---

## 3 · Use cases

**One permission, per store, any role** — **Manage Stock** (owner, 2026-10-09), `inventory.stock.manage`
(the name accepted 2026-10-09). The names are public, `Modules\Inventory\Public\InventoryPermissions`,
so another module can check Manage Stock for itself — Promotions' gift tick box (§9.2 #23); what each
is (group, kind, reserved) is still declared by `Application\InventoryPermissions`.

| Use case | Permission | Scope |
|---|---|---|
| `SetStock` — a stocktake: one size, or several at once | `inventory.stock.manage` | That store (no provider) |
| `AdjustStock` — one size: add or remove, with a reason; a correction with its note | `inventory.stock.manage` | That store (no provider) |
| `SetLowStockThreshold` — one size or several; empty for the store's default | `inventory.stock.manage` | That store |
| `SetEndingSoon` — on or off for a product | `inventory.stock.manage` | That store (no provider) |
| `SetStockDependent` — on or off for the sizes picked: all of a product's, some, or one | `inventory.stock.manage` | That store (wired) |
| The store's default threshold | Platform's settings screen, the setting's own permission | That store |
| The screens' reads: a size's stock and history (`ViewStock`, `StockHistory`), the Low Stock list and its count (`LowStock`); with stage 5's screens step 4 the Stock list (`ListStock`), the holds before saving (`HoldsOnSizes`), Add Size's picker (`AddSizeChoices`) | `inventory.stock.manage` | That store |
| A product's stock in every store (`ViewProductStock`), what the screens may offer (`InventoryActionsForReader`) | `inventory.stock.manage` | Each store the reader holds it in |
| The store's file — Inventory's section (§1.8) | Catalog's `catalog.listing.fill` (admin roles), checked by Catalog's handler | That store |
| `ReleaseExpiredHolds` — scheduled · `RebuildOrderable` — a repair job, every size's orderability pushed again and compared · `CountLowStock` — a store's Low Stock count kept, or every store's (step 5) | System (reserved): `inventory.holds.expire`, `inventory.low_stock.count`, `inventory.orderable.rebuild` | — |

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

A line may be shipped in parts; each part takes its pieces off — the stock stopping at 0, never below
(§2.1 `ship`). A line of an ordinary product in a
wired store is **NOTED** and never held. **While HELD and nothing shipped or ticked**, an edit
(`adjustHold`) may change its quantity, or end it as **RELEASED** when the line is removed; a line the
edit adds starts HELD (or NOTED).

### 4.2 The stock-dependent switch (wired store)

`OFF` ⇄ `ON`, per size, by hand; off by default, a size added later too. `OFF` → `ON` also by
Promotions' gift level (`InventoryApi::makeStockDependent`, §2.1), never back.

### 4.3 The admin screens (stage 5's screens)

**Written 2026-10-18, detailed 2026-10-19**, with Pricing's and Sync's, on the owner's answers (by module
in the menu; a product's stock on its page as well as on the store-wide pages; every detail settled
before building; S1-S8 accepted). Every **(Qn)** is answered (owner, 2026-10-19; pricing.md
§9.4). **Everything pricing.md §4.3.1 says holds here**: who may do what,
off stores, the frame, the store filter, rows that hold boxes with the save bar, a batch's refusal
marking its rows, numbers and moments, the words and plurals, 15 queries a page. Every Inventory page is
**one job**: Manage Stock (`inventory.stock.manage`) in that store.

#### 4.3.1 What every Inventory screen keeps

- **A number box** (a count, a quantity, a threshold): `TextField`, `inputMode="numeric"`, `dir="ltr"`,
  `tw-figure`, `toLatinDigits`; whole numbers, checked as typed: "Count must be a whole number from 0 to
  2,147,483,647." · "Quantity must be 1 or more." · "Threshold must be from 0 to 100,000." The server's
  `quantity_invalid`, `threshold_invalid` and `stock_below_zero` now name their sizes (Q2).
- **The figures** (§1.2-§1.3, `StockFigures`), each its own column, never computed on the page: **In
  Stock**, **Held** (by orders not yet shipped; in a wired store, gifts and sizes that count on stock),
  **Available** (what can be ordered or given now, never below 0), **Threshold** (its own, or "10 (Default)").
- **One badge a row** (Q17, Q18), the first that applies:
  1. **Short by 3** (red) - orders hold more than there is: held above in stock (in a wired store, above in
     stock plus its returned extra);
  2. **Out of Stock** (red) - available 0 on a size that counts on stock;
  3. **Orderable** (gray) - a wired ordinary size at 0: it stays orderable (§1.3);
  4. **Low** (amber) - available at or below the threshold.
- **A wired store** (§1.5): the in-stock number is the provider's - never a box - with **From Provider** and
  when the provider last **changed** it (the size's last `PROVIDER` ledger line: a number sent again
  unchanged writes none) (Q23); a size with no `PROVIDER` line shows "From Provider" with **no time** -
  never "not sent": the provider may have sent the same number, or 0 for a size with no row (2026-10-19).
  Counting, Add or Remove and Last Pieces are not offered ("The provider sets this store's stock."); the
  threshold and **Counts on Stock** are. A `Note` at the top of each page: "This store's stock comes from its
  provider. Thresholds and which sizes count on stock are set here."
- **An archived size** (or a size of an archived product) - listed while it has stock, with **Archived**
  (amber), read-only: "Archived in the catalog. Restore it to change its stock."
- **Toasts**: "Counts saved" · "Stock added" · "Stock removed" · "Threshold saved" · "Thresholds saved" ·
  "Last pieces shown" / "Last pieces hidden" · "Stock dependency turned on" / "Stock dependency turned off"
  · and a second, amber toast when a save leaves orders short: "2 orders are short of 60 cm · Oak".
- **Words**: `src/Modules/Inventory/Presentation/lang/{ar,en}/admin_stock.php`, `admin_low.php`, `menu.php`
  (Q42 for the Arabic terms).

#### 4.3.2 Where they are

`Presentation/admin-routes.php`, `inventory.admin.*` (Ziggy's admin group; deptrac `AppHttp` for the
Presentation); controllers and `spatie/laravel-data` resources; pages under
`resources/js/pages/Inventory/Admin/`. **The menu**: Platform's `AdminMenu`, the **Catalog** group, icon
`catalog`:

| # | Entry | Address | Offered for |
|---|---|---|---|
| 110 | Stock · المخزون | `/admin/stock` | `inventory.stock.manage` |
| 120 | Low Stock · المخزون المنخفض | `/admin/low-stock` | `inventory.stock.manage`, with `LowStockCount` beside it (step 5) |

#### 4.3.3 The reads

| Query | Answers |
|---|---|
| `ListStock` (new) | I1's rows, 50 at a time with the total. Names, photos, size values and the order (product name, then the size's place) from Catalog's `searchVariants`; a filter owned by Inventory - **Low**, **Out of Stock**, **Held**, **Short** - works out its sizes in one query of Inventory's own and hands them to `searchVariants(only:)`, so Catalog pages them by name; the figures in one query of Inventory's rows |
| `ViewStock` (new) | one size: the figures and badge, its threshold (own or default), the product's Last Pieces (no provider) or the size's Counts on Stock (wired), the provider's last change (wired), its holds - **how many orders hold how many pieces, bought and gift apart** (Q21) |
| `StockHistory` (new) | one size's ledger, newest first, 50 at a time: when, the reason, before → after, the change (+3 / −2), the note, **who** - a staff member by name (Platform's `StaffNames`), **The Provider**, **A Store File** (with the staff member who switched it on, the requester), **An Order** (its number once Sales has orders), **The System** (Q22). No link to Sync's read: Inventory does not read Sync |
| `ViewProductStock` (new) | each of a product's sizes **in every store the reader manages stock in**, one read for the whole Stores tab |
| `LowStock` (extended) | photo, size values, own-or-default threshold; paged 50 with the total; order: fewest available, then product name, then the size's place |
| `InventoryActionsForReader` (new) | per store: manage stock, wired, off, `mayViewProducts`, the reasons |
| `HoldsOnSizes` (new, step 4) | the holds said before saving (Q20): for each size asked, how many orders hold how many pieces, bought and gift apart - one query however many sizes |
| `AddSizeChoices` (new, step 4) | Add Size's picker (Q24): every ready size not archived, less those listed here (switched on, or with a stock row) - `searchVariants(anyReady:, except:)` - 50 at a time with the total; a search also names up to 5 listed sizes it finds, for "Already listed". Refused in a wired store (`provider_owns_stock`) |

**As built (stage 5's screens step 4, 2026-10-19).** Every read answers a size as Catalog's `searchVariants`
finds it (names, photo, values in the page's language, code, archived, switched on) and **its figures**
(`StockFiguresView`): in stock, held, available, a wired store's returned extra, the threshold that applies
and the size's own (none: the default), the one badge (`SHORT` with how many, `OUT_OF_STOCK`, `ORDERABLE`,
`LOW`, or none - `StockFigures::badge`), whether its stock limits ordering, a wired store's Counts on
Stock, the provider's last change (wired only; none: no `PROVIDER` line - "From Provider" with no time)
and how many orders hold it. Measured, warm (the feature tests): `ListStock` 7 queries, `ViewStock` 6,
`StockHistory` 2 (and `StaffNames`' own read when a line names staff), `ViewProductStock` 4, `LowStock` 7,
`AddSizeChoices` 6, `HoldsOnSizes` one of its own - each the same for one row as for many. **In a wired
store**, the wiring asked as Sync asks it (a query a question), each page's whole server side: the Stock
page (`ListStock` with `InventoryActionsForReader`) 9, the drawer 5, the Low Stock page (`LowStock` with
`InventoryActionsForReader`) 8 - the review of step 4 cut the Low Stock reader's Catalog reads to one
(`variantBriefs`, catalog.md amendment 23(h)) and the searches that know their total ask no count
(`withTotal: false`).

- **`ListStock`'s filters** are conditions, not badges: **Out of Stock** keeps a size also **Short**; **Low**
  leaves archived sizes out, as the Low Stock list does; the others list them, marked. The figures come from
  the one read of the store's rows the filter used.
- **`LowStock`'s order**: Catalog orders by name only among the sizes it is given, and this order starts
  with Inventory's own figure, so the list orders itself - each product's sizes in their order from one read
  of Catalog (`variantIdsOfProducts`), names compared by ICU's collation for the page's language with
  punctuation and spaces set aside, as PostgreSQL's does. Close to `searchVariants`' order, not promised
  identical for names differing only in such marks. The list, its count and the Home card still share one
  reader, which now reads the store's rows in one query.
- **`StockHistory`'s who**: `STAFF` (named), `PROVIDER`, `FILE` (with the requester, named), `ORDER` (a
  shipment's order from its reference, a return's from its return lines, found by the return id the
  reference names) or `SYSTEM`; a Super Admin hidden as `StaffNames` says. **Paged by the ledger's id**,
  newest first: the id rises with every line written, so it orders as the time does and breaks a tie of
  one transaction's moment (accepted in the review of step 4).
- **`ViewStock`** answers any size Catalog has, switched on or not, archived or not; an unknown size is
  `invalid_inventory_input` naming it.
- **`ViewProductStock`** is registered with Catalog's `ProductStoreSections` as `ProductStockSection`,
  component `Inventory/ProductStock` (step 5 draws it - until then a card draws nothing for it), each card's
  part carrying `inventory::admin_stock`'s words.
- **The saves' answer** (the amber toast): `SetStock` and `AdjustStock` answer `StockSaved` - each size the
  change left with in stock below what is held, how many orders hold it and by how many pieces. `HoldsShort`
  is still sent only when the change took the size down (§6.1); a change that raised a short size but left
  it short names it with no event.
- **Add Size** (Q24) needs no new command: `SetStock` counts a size switched on whatever its product's
  stage, and a size the store does not sell **only when its product is ready** (2026-10-19) - a draft's size
  not switched on is refused, `invalid_inventory_input` naming its row; the stocktake makes its row, and the
  Stock list lists it from then on. The store's file (§1.8) is not held to it.
- **A batch refusal marks every bad row**: `SetStock` and `SetLowStockThreshold` name every count or
  threshold out of bounds, and both rows of a size written twice with two values, in one refusal. A count
  that finds the number right on a size already short names it in `StockSaved` too, with no event.
- **`ViewProductStock`'s defaults** are read once for all its stores, from Platform's one snapshot of its
  settings - no query a store.

**As built (stage 5's screens step 5, 2026-10-19).** The pages: `Presentation/admin-routes.php`
(`inventory.admin.*`, in Ziggy's admin group), `StockController` (Stock, the drawer's and Add Size's reads,
every change) and `LowStockController`; the menu's Catalog group gains **Stock** (110) and **Low Stock**
(120, with `LowStockCount`), both for Manage Stock. The pages are `resources/js/pages/Inventory/Admin/`
(`Stock`, `LowStock`; `counts.tsx` the boxes and their save bar, `dialogs.tsx` Add or Remove Stock, Set
Threshold and Add Size, loaded when first opened; `parts.tsx`; `checks.ts` the boxes' rules) and the
product page's part `resources/js/sections/Inventory/ProductStock.tsx` (each store card's ids, refusals and
save bar its own, the store named in each box's label).

- **The amber toast** is a new flash, `warning`, beside `status` (`HandleInertiaRequests`, `Toasts.tsx`):
  one sentence a size left short, the size named by its values ("1 order is short of 60 cm").
- **`ListStock`** answers each row's product's **Last Pieces** (none in a wired store), for the row's menu:
  one query more (8). **`ProductStoreStock`** carries the store's default threshold, for Set Threshold's
  helper. `HoldsOnSizes` is not used by the pages: each row's figures already say how many orders hold how
  many pieces.
- **The drawer is deferred** (Inertia's deferred props `drawer`, `drawerFailed`, `history`): Stock opened
  with a size in its address answers the rows first and the drawer in a request of its own, so neither
  passes 15 however the history reads (its staff named through Platform's `StaffNames`). A size the store
  cannot show - unknown, or gone since the address was kept - says so in the drawer (`drawerFailed`,
  "failed.size" with Try Again), never a refused page. The size's JSON read is gone: nothing asked for it.
- **Measured, warm** (`tests/Modules/Inventory/Feature/InventoryScreensTest.php`, the page's own queries,
  the frame and the menu's count apart, a staff member's line in the history): Stock 9, Stock with a size
  in its address 9 and its drawer after it 8, Low Stock 8, the history's next page 2, Add Size's picker 8;
  in a wired store (the wiring answered without a query) 7, 7, 7 and 7. The panel's frame for whoever
  manages stock: one query more, the kept Low Stock count. `ViewProductStock` reads which sizes are
  archived (Catalog's `variantBriefs`): 5.
- Shared with Pricing's pages, moved out of them: `components/RowMenu.tsx` (the ⋯ button, an item with
  its reason) and `lib/live-read.ts` (a read as typed, its failure and retry, stale answers ignored).
- **The review of step 5** (2026-10-19), for every screen: the flash is an always-sent prop, so a partial
  reload (Show More, a drawer) never says the last toast again; a page's leave guards share one question
  (a product's store cards ask once); a store chosen in the filter drops the drawer's size. For Inventory's:
  a change keeps the rows Show More had shown (`lib/kept-depth.ts`, the next pages asked again), the
  selection keeps only rows still shown and starts again only once a new list is shown; select all has its
  mixed state and the count is said politely; archived sizes are read-only everywhere (no box, no
  selection, Counts on Stock off, their changes drawn with the reason - the product page's cards too, from
  each size's `archived`); a wired store's Add or Remove Stock and Last Pieces are drawn with their reason;
  the amber words are made after the change, so a saved change is never said refused.

#### I1 · Stock - `/admin/stock?store=&q=&filter=&size=`

- **Subtitle**: "What each size has in this store, and what orders hold."
- **Main action**: **Add Size** (Q24) - stock a size the store does not sell (one only given as a gift,
  §1.7): a picker of every ready product's sizes not listed here yet (`searchVariants`, any ready size; a size
  already listed shows "Already listed" and opens its row), then **Count Size** - its count, saved as a
  stocktake. Not offered in a wired store.
- **Filters**: search (name or code, 300 ms); `ToggleGroup` **All** · **Low** · **Out of Stock** · **Held** ·
  **Short**; **Clear Filters**.
- **Rows**: the store's sizes switched on, and those with a stock row here.
- **Columns**: a selection checkbox · photo · **Product** · **Size** ("One Size" for a product with one) ·
  **Code** · **In Stock** (a box when the reader may count; wired: the number, "From Provider" and its
  `Time`) · **Held** · **Available** with its badge · **Threshold** · wired: **Counts on Stock** (a `Switch`,
  saving as it flips - `SetStockDependent`) · ⋯.
- **Counting in the row** (S5; no provider): the save bar ("3 counts changed" · Cancel · **Save Counts**).
  **Only the rows typed with a new number are sent** (Q19). A count leaving a size short says so under its
  box before saving (Q20): "2 orders hold 5 of these. This count leaves them short." Not refused (§9.2
  #19); after saving, the amber toast names them.
- **Selecting rows** (shadcn's data-table pattern): the loaded rows only; a filter, search or store change
  clears the selection; above the table "4 selected" with **Set Threshold for 4 Sizes…** and, wired, **Count
  on Stock** / **Don't Count on Stock** (one `SetStockDependent` each).
- **The ⋯ menu**: **View Stock** · **Add or Remove Stock…** · **Set Threshold…** · for the product (no
  provider) **Show Last Pieces** / **Hide Last Pieces** (helper under the item: "Marks the product 'last
  pieces' while its sizes are at or below their threshold.") · nothing destructive.
- **Add or Remove Stock** (`PanelDialog`): `ToggleGroup` **Add** / **Remove** (no default); **Quantity** (1 or
  more); **Reason** (`RadioGroup`, no default, filtered by the direction - Add: **Received**, **Correction**;
  Remove: **Damaged or Lost**, **Offline Sale**, **Correction**); **Note** (`TextareaField`, up to 500
  characters; "Note is required for a correction."). Above the button: "In stock becomes 12." - and, in red
  when it would, "2 orders hold 5 of these. This leaves them short." Removing more than there is: "Quantity
  can't be more than 4, what's in stock." **Add Stock** / **Remove Stock** → "Stock added" / "Stock removed".
- **Set Threshold** (`PanelDialog`): one number box, empty for the store's default ("Empty uses the store's
  default, 10."); several sizes at once from the selection. **Save Threshold** → "Threshold saved" /
  "Thresholds saved".
- **The drawer** (Q3; `?size=`): the size, code, figures and badge; **Holds** - "Held by 2 orders: 5 pieces
  (3 bought, 2 gift)" (Q21); **History** - `StockHistory`, Show More; its own **Add or Remove Stock…** and
  **Set Threshold…**.
- **Empty**: "No sizes in this store" · "Sizes appear here once the store switches them on." Filtered:
  "No sizes match these filters" · "Clear the filters to see every size."

#### I2 · Low Stock - `/admin/low-stock?store=`

- **Subtitle**: "Sizes at or below their threshold, the fewest left first."
- The store filter as every store screen (the first store that is on); the subtitle adds "12 low in KSA".
- **Columns**: photo · Product · Size · Code · **Available** with its badge · **Threshold**. The product's
  name opens I1's drawer; ⋯: **Add or Remove Stock…**, **Set Threshold…** (as I1's). Archived sizes never
  listed (§1.7). 50 at a time; order as `LowStock`.
- **A wired store**: the figures read-only; the threshold settable.
- **Empty**: "Nothing is low" · "Sizes at or below their threshold appear here."
- The Home card and the menu's count are built (steps 4b, 5).

#### The product page's Inventory section (Catalog's S9, Stores tab)

**One `ViewProductStock` read for every store card on the tab** (amendment 23(b)). In each store's card,
for whoever manages stock in that store (nothing for the rest):
- **Last Pieces** for the product - a `Switch` (no provider), saving as it flips.
- A table: **Size** · **In Stock** (a box, no provider; wired: the provider's number) · **Held** ·
  **Available** with its badge · **Threshold** · wired: **Counts on Stock** (a `Switch`).
- The save bar inside the card ("Save Counts"); each size's ⋯: **Add or Remove Stock…**, **Set Threshold…**,
  **View Stock** (I1's drawer).

---

## 5 · Tables

Schema `inventory`. Store-scoped rows name their store in every query. Every CHECK on a nullable
column is written so a NULL cannot slip through (lesson 162).

| Table | Columns |
|---|---|
| `inventory.stock` | (`store_id` FK `platform.stores` RESTRICT, `variant_id` FK `catalog.variants` CASCADE) PK · `product_id` FK `catalog.products` CASCADE (from Catalog's `variant()`; a size never moves to another product, catalog.md §1.2) · `in_stock` int CHECK ≥ 0 · `held` int CHECK ≥ 0 · `threshold` int NULL CHECK 0–100,000 · `stock_dependent` boolean (wired) · `returned_extra` int NOT NULL DEFAULT 0 CHECK ≥ 0 — pieces returned in a wired store, not yet in the provider's number (§1.9, provisional) · `ending_soon` boolean — the last value pushed to Catalog · `orderable` boolean NULL — the same for orderability, null when never pushed (step 4: only a flip is pushed; sizes switched on, the default threshold's change and the repair tell Catalog too) · `updated_at` |
| `inventory.store_products` | (`store_id` FK `platform.stores` RESTRICT, `product_id` FK `catalog.products` CASCADE) PK · `ending_soon` boolean — "last pieces" switched on for the product (no provider) · `updated_at` |
| `inventory.low_counts` | `store_id` PK FK `platform.stores` RESTRICT · `low` NULL CHECK ≥ 0 · `counted_at` NULL (both NULL until first counted) · `recount_queued` boolean — the menu's Low Stock count per store, kept (step 5); one recount waits per store |
| `inventory.holds` | `id` ULID PK · `store_id` · `order_id` unique · `expires_at` NULL · `created_at` |
| `inventory.hold_lines` | (`hold_id` FK CASCADE, `variant_id`, `gift`) PK — a bought line and a gift line of the same size are two lines · `quantity` CHECK ≥ 1 · `gift` boolean · `counts_on_stock` boolean · `shipped` int CHECK ≥ 0 · `ended` CHECK (`OPEN`, `SHIPPED`, `REDUCED`, `RELEASED`, `EXPIRED`) · `ended_at` NULL |
| `inventory.stock_movements` | `id` bigint PK (handoff §5.3) · `store_id` FK RESTRICT · `variant_id` — **no key**, so a deleted draft size keeps its history, as the audit log names its subjects (step 2) · `reason` CHECK (`STOCKTAKE`, `RECEIVED`, `DAMAGED_LOST`, `OFFLINE_SALE`, `CORRECTION`, `SHIPPED`, `RETURNED`, `FILE`, `PROVIDER`) · `change` int · `before` int ≥ 0 · `after` int ≥ 0 = `before` + `change` · `note` NULL, up to 500 characters (required, not blank, for `CORRECTION`) · `note_code` NULL CHECK (`SHIPPED_BEYOND_COUNT`, `UNWIRED`) and `note_values` jsonb NULL, an object, only with a code - Inventory's own note (§1.6; stage 5 step 10, migration `2026_10_23_100000`): a line holds a typed note or a code, never both, and either is the note a `CORRECTION`, or a `SHIPPED` 0 → 0, needs · `reference` unique · `actor_type` + `actor_id` NULL — the system's alone — and `requested_by_type` + `requested_by_id` NULL — whoever queued the job the system runs — as the audit log records its actor, with the same CHECKs (step 2; one `actor` column before) · each reason's direction CHECKed: Received and Returned in, Damaged / lost, Offline sale and Shipped out, a Correction either way and never 0, a stocktake, the file and the provider any number (owner, 2026-10-11) — and Shipped 0 only as 0 → 0 with a note, not blank: a shipment from a stock already at 0 recording its shortfall (owner, 2026-10-19; migration `2026_10_20_100000`, the check added NOT VALID then validated) · `created_at` — never updated, never deleted (a trigger refuses UPDATE, DELETE and TRUNCATE) |
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
| `VariantArchived`, `VariantRestored`, `ProductArchived`, `ProductRestored` | Catalog | Every store's Low Stock count counted again: an archived size leaves the list, a restored one may come back (step 5) |
| `StoreListingWithdrawn` | Catalog | The store's Low Stock count counted again: sizes switched off with no stock row leave the list (step 5) |

---

## 7 · Errors

Every error extends `InventoryError` → `DomainError` ("inventory.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `NotEnoughStock` | CONFLICT | a hold one line short (its context names the variants and what is available) |
| `AlreadyHeld` | CONFLICT | a second hold for an order |
| `QuantityInvalid` | INVALID | a quantity below 1; a stocktake below 0; a number of pieces above 2,147,483,647, what the column keeps |
| `StockBelowZero` | CONFLICT | a removal by hand larger than in stock (`ship` never refuses for the stock, §2.1) |
| `NoteRequired` | INVALID | a correction with no note |
| `ShipMoreThanHeld` | CONFLICT | shipping more of a line than it holds |
| `ProviderOwnsStock` | CONFLICT | a hand change or "ending soon" in a wired store |
| `NotWired` | CONFLICT | the stock-dependent switch in a store with no provider |
| `ThresholdInvalid` | INVALID | a threshold below 0 or above 100,000 |
| `StoreOff` | FORBIDDEN | changing an off store's stock without the store switch |
| `NoHold` | NOT_FOUND | editing or returning for an order with no hold |
| `HoldNotEditable` | CONFLICT | editing a hold whose line is already shipped or ticked |
| `InvalidInventoryInput` | INVALID | a store, a size or a product that does not exist (owner, 2026-10-10: an unknown store is invalid input); an archived size or product (owner, 2026-10-11); one size given twice with two numbers; a direction that is not add or remove; a reason a hand change does not take, or one going the other way (owner, 2026-10-11); a note over 500 characters; a screen's filter that is not one — named by its field (`store`, `variant`, `product`, `order`, `direction`, `reason`, `note`, `filter`), as Catalog's invalid attribute is |

**The sizes a refusal names (Q2; stage 5's screens step 4).** From a screen's command, `quantity_invalid`,
`threshold_invalid`, `stock_below_zero` and `invalid_inventory_input` about a size carry `variants` - the
sizes as the caller named them, comma-separated - so `App\Http\FormErrors` marks those rows. Their words name
the box (§4.3.1): `quantity_invalid` with the rule `count` - "Count must be a whole number from 0 to
2,147,483,647." - `quantity` - "Quantity must be 1 or more." - or `too_many`; `stock_below_zero` from a
removal by hand, the rule `removal` with `in_stock` - "Quantity can't be more than 4, what's in stock.";
`threshold_invalid` - "Threshold must be from 0 to 100,000." Refusals from `InventoryApi` carry neither:
their one detail stands.

---

## 8 · Test scenarios

1. **Holding**: all or nothing; one short → nothing held, the shortfall named; two orders racing for
   the last piece — one wins (the atomic update, proved with two connections); a second hold for an
   order refused; an ordinary line of a wired store noted, not held; a gift held in a wired store; a
   bought line and a gift line of the same size in one order, each held, shipped and freed on its own.
2. **Shipping**: in stock and held fall together, in parts too; repeating changes nothing; a wired
   store's shipment takes nothing; more than held refused; more than in stock takes it to 0, one
   `SHIPPED` line naming the shortfall (0 → 0 from a stock already at 0), the line shipped in full,
   counted once; a 0-change shipped line without a note refused by the database.
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
   refused in a store with no provider. `makeStockDependent`: on only, audited and told as the
   switch; nothing in a store with no provider or for a size already on; inside the caller's
   transaction and alone, its audit entry always in a transaction.
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
| 1 | ~~Which warehouse / location counts, "On hand" or "Available"?~~ **Answered** (owner, 2026-10-19): **available to order, per size**; warehouses are not ours to see (sync.md §9.1 #6) | — |
| 2 | ~~Does the provider call us when stock changes?~~ **Answered** (owner, 2026-10-13): it notifies us of price and stock changes; we also read hourly, nightly and on Sync now (sync.md §1.3) | — |
| 3 | ~~Returns in a wired store~~ **Answered** (owner, 2026-10-19): nothing is ever written to the provider, so **§1.9's rule is final** (no longer provisional) | — |
| 4 | `ListingFacts::endingSoon` (§2.3) | Catalog's amendment 16(h), accepted (#103, 50e9991); its backend being built |
| 5 | Inventory's screens: stock and its history, hand changes, the Low Stock list, the switches | The frontend session, after this spec |

### 9.2 Answered (owner, 2026-10-09, 2026-10-10, 2026-10-11, 2026-10-18 and 2026-10-19)

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
| 21 | The menu's Low Stock count in one query | **A count kept per store**, counted again by a job after each change and each night - about half a minute behind the list at most (owner, 2026-10-18; §1.7) |
| 22 | Shipping more than is in stock, in a store with no provider (a hand removal took the pieces) | **Never refused** (owner, 2026-10-19; sales.md answer 41): the stock stops at 0, never below; one `SHIPPED` line, before → 0, notes the shortfall for a recount — **always**, from a stock already at 0 too, as a 0 → 0 line with its note (a shipped line changing nothing is otherwise refused); the line is shipped in full and its hold freed. A removal by hand is still refused below 0 (§2.1, §7) |
| 23 | Promotions' gift-level tick box ("Also limit this size's normal sales to its stock") | **`InventoryApi::makeStockDependent`** turns the switch on, never off, in a wired store, inside the gift level's own save; **the tick box also needs `inventory.stock.manage`** in that store, which Promotions checks itself by the permission's public name (owner, 2026-10-19; promotions.md §4.1 M4, §9.4 Q11) (§2.1, §3) |

### 9.3 Proposals for the screens (2026-10-18) - built as written unless the owner says otherwise

| # | Proposal | Why |
|---|---|---|
| S5 | **A stocktake typed in the row**, several then **Save Changes** (one `SetStock`), as Pricing's prices are typed (pricing.md §9.3 S1-S4 hold here too) | A stocktake walks many sizes at once; a dialog per size would be slow |
