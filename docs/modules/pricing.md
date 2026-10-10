# Pricing — module specification

**Status (2026-10-10): the full spec, accepted** (#100, 2026-10-09); **amended 2026-10-10** — a
wholesale line's list price is its band (§1.6 step 5, §9.2); **being built** from 2026-10-10 in eight
steps — see [its README](../../src/Modules/Pricing/README.md). §2 — what other modules call — was agreed
first and is on `main` (#97, "interfaces first", owner 2026-10-07). The rest follows from the owner's
answers of 2026-10-07 to 2026-10-09; my proposals of the first draft were accepted on 2026-10-08,
the timing one replaced on 2026-10-09 (§9.2). What waits for the provider's team is §9.1. Handoff
§10, revised 2026-10-07, is the source; this spec says how Pricing keeps it.

## What Pricing does not own

| Thing | Owner |
|---|---|
| Who may buy what: retail for everyone, wholesale for companies (handoff §6) | Sales checks it at checkout; Catalog shows each kind of account its side (catalog.md §1.13) |
| A variant's selling modes in a store, and its product's minimum and maximum for each mode | Catalog (`StoreVariantDto`) |
| Coupons, the discount ceiling, gifts | Promotions (stage 6) |
| Points | Loyalty (stage 6) |
| Shipping amounts | Shipping (stage 7) |
| Quotes and orders, and the snapshot of every price they carry | Sales (stage 6) |
| The campaign record — its name, banners, theme | Content (stage 8); its prices come here then (§9.1 #4) |
| The provider's feed | Sync (stage 5, after its answers); it writes through Pricing's public surface |
| The store's VAT rate, currency and its decimals | Platform (`StoreDto`) |

---

## 1 · Aggregates and invariants

### 1.1 The rules every price follows

1. **One price for everyone** (owner, 2026-10-07: "the prices are same all across system"): a
   guest, an individual and a company pay the same. Pricing never asks who is buying.
2. **Prices are per size** — per variant, per store — never per product and never additive
   (handoff §9.1).
3. **Prices are kept without VAT.** VAT is added once, on the order (§1.6).
4. **A price is always above 0** (owner, 2026-10-07): a free piece is a gift (Promotions).
5. **Never rounded silently.** An amount typed with more decimals than the store's currency has is
   refused; an amount computed from a percentage is rounded **half up to the smallest coin** — the
   halala, piastre or fils (owner, 2026-10-08).
6. **The lowest applicable price wins; nothing stacks** (§1.6) — **unless a sale or category
   discount is marked "always wins"** (§1.3).
7. **No price, no sale.** A size with no retail price in a store is not on sale there (catalog.md
   §1.3, from stage 5) and is on that store's **Needs a Price** list (§1.9).
8. **A store that is off** has its prices set by Super Admins only — whoever may switch stores
   (`platform.store.switch`) — or by its file (owner, 2026-10-07); the panel offers off stores to them
   alone (platform.md §9.10).
9. **A store wired to a provider** (Sync): its retail prices come from the provider and are
   read-only in our panel (handoff §12.2). Whether its sales do too waits for the provider's team
   (§9.1 #1).

### 1.2 Retail price

The price of one piece of a size in a store: **one per size per store**, above 0, in the store's
currency. Set by staff, by the store's file (§1.8) or, in a wired store, by the provider. **Removing
it** (owner, 2026-10-08) takes the size off sale there; it returns to the Needs a Price list. A
changed or removed price is audited from and to.

### 1.3 Sale

A lower price for a size, for a time (owner, 2026-10-08):

- **A new price or a percentage off** — either, on the same form. A percentage is above 0 and below
  100, worked out on the size's retail price whenever that changes, rounded half up to the coin.
- **For one size, or for every size of a product at once.** A sale made for every size is kept as one
  sale over those sizes, so it is changed and ended as one; a size added to the product later is not
  in it (owner, 2026-10-08).
- **A start, and an end only if wanted**: without an end it lasts until it is ended. A sale may be
  scheduled ahead. The end is after the start.
- **Overlapping sales on the same size are allowed**; the lowest wins while both run (owner,
  2026-10-08) — **and staff are told** (owner, 2026-10-08/09): saving a sale or a category discount
  shows a note of **what it affects** — the sizes it reaches — and **every other sale or discount on
  those sizes in the same dates**, so they may change the dates, or keep both knowing the lowest wins.
- **"Always wins while on"** (owner, 2026-10-08): a sale or a category discount may be marked so.
  While it runs it is the price of the sizes it covers **even where another sale or discount is
  cheaper** — as if those were paused. Two "always wins" on the same size at the same time are
  **refused** (owner, 2026-10-09). **It never beats a wholesale band**: a wholesale line pays the
  lower of it and its band (owner, 2026-10-09). Not marked, the lowest wins as above.
- **A sale price must be below the size's retail price when it is saved** — otherwise it is not a sale
  (owner, 2026-10-08). If the retail price later drops below it, the sale simply stops winning.
- Its life (§4.1): **scheduled → running → ended**. A scheduled sale may be changed in full or
  removed; a running one may only have its end changed or be ended now; an ended one is history,
  kept (owner, 2026-10-08).

### 1.4 Wholesale prices

The prices a **wholesale line** pays — companies only buy wholesale (handoff §6) — **an exact price
per size** (owner, 2026-10-08), in **quantity bands**:

- **The first band starts at the product's wholesale minimum** in that store (Catalog's
  `wholesaleMinimum`), e.g. "from 20 = 85"; then as many higher bands as wanted, e.g. "from 100 = 78,
  from 500 = 70" (owner, 2026-10-08).
- Each band starts higher and costs **less** than the one before; every price is above 0.
- Saving needs the size to **sell wholesale** in that store (Catalog). If the store stops selling it
  wholesale, its bands are kept, unused, for the day it sells wholesale again (owner, 2026-10-08).
- **If Catalog's wholesale minimum is later lowered** below the first band, a wholesale line under the
  first band pays the retail prices (§1.6) until the bands are saved again; the panel marks such
  sizes (owner, 2026-10-08).
- Retail lines never get a wholesale price (owner, 2026-10-08: quantity prices are wholesale only).

### 1.5 Category discount

A discount on every product in a category and its sub-categories, in one store (owner, 2026-10-07 and
2026-10-08):

- **A percentage, or a fixed amount**, off each size's **retail** price; a percentage rounded half up
  to the coin. **A fixed amount skips a size it would take to 0 or below** — that size keeps its other
  prices.
- **Applies to retail lines, wholesale lines, or both.** On a wholesale line it competes with the
  wholesale band (lowest wins).
- **Products can be left out** by name.
- **A start, and an end only if wanted**, as a sale; the same life (§4.1); the same note of what it
  affects and what it overlaps, and the same **"always wins"** choice (§1.3).
- **A product moving into or out of the category follows at once**: the discount covers what is in
  the category now, not what was when it was made — a product moved (`ProductChanged`), a draft made
  ready after its category changed (`ProductMadeReady`), or a whole category moved under another
  parent (`CategoryMoved`), §6.2.
- **Its preview** (owner, 2026-10-07): before it is saved, and on its page after, every product it
  reaches in that store — each size's retail price, its new price, the difference — and the sizes it
  skips (a fixed amount at or above their price) or leaves out.
- Overlapping category discounts (a parent's and a child's) are both applicable; the lowest wins.

### 1.6 Resolution — what a line costs

For a line — a size, its sale mode, a quantity — in a store, at a moment:

1. **No retail price → no price.** The line is "unpriced" (`CartPricesDto::unpriced`).
2. The candidates: **the retail price**; **every sale running** on the size; **every category discount
   running** whose lines include this mode and which does not leave the product out (a fixed amount
   not skipping it); and, **on a wholesale line**, the band with the highest start at or below the
   quantity. Campaign prices join in stage 8.
3. **An "always wins" sale or discount running on the size** (§1.3) is the unit price, every other
   sale and discount set aside — on a wholesale line, the lower of it and the band.
4. **Otherwise the cheapest candidate is the unit price**; its kind is reported. Equal candidates: the
   dated one first — sale, category discount — then the wholesale band, then the retail price, so the
   shopper sees why it is cheaper (owner, 2026-10-08).
5. **The list price** — what `gross_subtotal` counts — is the line's normal price: **on a retail line,
   the retail price; on a wholesale line, its band** where one applies, else the retail price. **A
   wholesale price is that line's normal price, not a discount** (owner, 2026-10-10): only a sale or a
   category discount below it reduces a line, so coupons and points reach a band-priced line and the
   discount ceiling (handoff §11.3) measures real reductions only. A card crosses out the retail price
   (it shows retail-line prices, §1.10).
6. **When it stops applying**: the end of the winning sale or discount — or the start of an "always
   wins" one scheduled on the size, which would replace it — or null.

**Materialized, never resolved at request time** (handoff §10.1, §5.4): every change writes the
size's candidates — store, size, mode, the band's start, the amount, the kind, the window — into one
table (`pricing.price_candidates`, §5) inside the change's transaction, with every percentage already
worked out and every category already expanded. `prices()` reads the candidates whose window holds
the moment and takes the cheapest per line (an "always wins" one first) — one indexed read, no
category tree, no percentage, no rounding at request time (owner, 2026-10-08).

### 1.7 Totals — every amount of an order, in one place

The handoff's canonical amounts (§10.2), computed by `PricingApi::totals` (§2) — **a pure function:
no database, no clock** — from the prices (§1.6) and the amounts Sales hands in:

```
gross_subtotal = Σ (list price × quantity)            over the priced lines (§1.6 step 5)
net_subtotal   = Σ (unit price × quantity)
goods_total    = net_subtotal − coupon_discount − points_discount
taxable_base   = goods_total + shipping
vat            = taxable_base × the store's rate      rounded once, half up, to the coin
order_total    = taxable_base + vat
```

It refuses a cart holding an unpriced line, an amount in another currency, a negative amount, and
discounts larger than `net_subtotal`. The rate is the store's at the moment the prices were read
(`CartPricesDto::taxRateBasisPoints`); an order keeps its own snapshot (Sales).

### 1.8 The store's file (catalog.md §2.3)

Pricing's `ImportSection` for a store's file:

- **The file's page** says, per item, the retail price that will be set — or why not: more decimals
  than the store's currency has, or 0 (owner, 2026-10-07: refused, never rounded); in a wired store,
  ignored, the provider being its source (handoff §9.1).
- **When an item is switched on**, the price becomes the retail price of the size its code names,
  audited as the file's. **One code is one size** (owner, 2026-10-09: "each variant has its own
  code"; the shared codes of catalog.md amendment 3(e) go, Catalog's amendment).

### 1.9 Needs a Price

Per store: **every size the store has switched on with no retail price there**, with a count beside
it in the menu — seen by whoever may **Edit Prices and Sales** in that store (owner, 2026-10-08).
Pricing gives the list and the count (Platform's `MenuCount`); the frontend session draws it.

### 1.10 What Pricing pushes into Catalog

`ListingFacts::prices` (catalog.md §2.2, amendment 15): per size, **the price now** — what one piece
costs, the lowest retail-line candidate (never a wholesale band) — and **the price before**, the
retail price, only while the price now is lower. Pushed **inside the change's own transaction** for
the sizes it changed, and **at the moment a sale or discount starts or ends** (owner, 2026-10-09:
"alarms + daily check"): saving one sets a **timed task at its start and one at its end**, which
push the sizes it covers then; a task whose sale was changed or removed meanwhile finds nothing to
do. **Once a day a safety check** pushes every size whose window opened or closed in the last day,
in case a task was lost. Catalog keeps the prices and chooses which size each card shows.

### 1.11 An order edited before it ships

Staff may change an order's lines and quantities before it ships (owner, 2026-10-10, through stage
6). **Pieces already on the order keep the price they were sold at; added pieces take today's**
(owner, 2026-10-10):

- **Added pieces** are priced as a cart line is now (§1.6) — **on a wholesale line, by today's band
  for the line's new total quantity** (the kept pieces and the added ones together), applied **to the
  added pieces only** (owner, 2026-10-10: "band for the new total"). Example: 100 kept at 78; 400
  added; the band "from 500 = 70" → the 400 cost 70, the 100 stay at 78.
- **Pieces taken off** come off a kept part, which keeps its price.
- **VAT** is the order's rate, from its snapshot, never today's: an edit does not re-tax the order.
- Sales hands in the kept parts from the order's snapshot and the added lines; Pricing returns prices
  that `totals` takes as it takes any — **Pricing still does every amount** (§1.7). One order line
  may so hold parts at two prices, after several edits more.

---

## 2 · Public contract

Module to module (handoff §4.3): ids and values in, DTOs out; no permission is checked — the calling
use case checks its own. All amounts are `Shared\Domain\ValueObject\Money`, **without VAT**, in the
store's currency. **On `main` since #97.**

### 2.1 `Modules\Pricing\Public\Contracts\PricingApi`

| Method | For | Says |
|---|---|---|
| `prices(StoreId $store, list<CartLineDto> $lines): CartPricesDto` | Sales (cart, quote), Promotions (via Sales) | Each line's price: its unit price (the lowest applicable), the base price it compares with, the kind that won, the line amounts, and when that price stops applying; the lines with no price, apart; `gross_subtotal` and `net_subtotal` over the priced lines; the store's VAT rate. Reads the materialized prices — never resolves at request time (handoff §10.1). |
| `totals(CartPricesDto $prices, Money $couponDiscount, Money $pointsDiscount, Money $shipping): TotalsDto` | Sales | The canonical amounts (handoff §10.2): `goods_total`, `taxable_base`, `vat` (rounded once, half up), `order_total`. **Pure** — no database, no clock; the same input gives the same answer. Refuses prices holding lines with no price, amounts in another currency, a negative amount, and discounts larger than `net_subtotal`. |
| `pricesForEdit(StoreId $store, list<KeptPartDto> $kept, list<CartLineDto> $added, int $taxRateBasisPoints): CartPricesDto` **[PROPOSED]** | Sales (staff edited an order before it ships) | §1.11: the kept parts as they are, the added lines priced now (a wholesale one by the band for its new total), the order's VAT rate. A variant and mode may appear more than once among the parts; once among the added lines (`pricing.duplicate_lines`). `validUntil` from the added lines only. |

`totals` may be called more than once while Sales builds a quote: with the coupon and points first
(for `goods_total`, which free shipping and points earning bind to), then with the shipping.

**The lines come back in the order given** (stage 6's request, 2026-10-10): `prices` returns its
lines in the order they were passed; `pricesForEdit` the kept parts as passed, then the added lines
as passed — one `LinePriceDto` each. A line with no price is left out of `lines` and named in
`unpriced`, so position `i` answers input `i` exactly when `unpriced` is empty — which `totals`
requires anyway. Callers may match by position (an edited order can hold a variant and mode more
than once, §1.11).

**One line per variant and mode** (stage 6's review, 2026-10-07): `prices` expects each pair of
variant and sale mode once — Sales merges a cart's repeated lines first — and refuses two of the same
(`pricing.duplicate_lines`) rather than guess.

Refusals reach the caller as `Shared\Domain\Error\DomainError` with a stable `type()` key —
`pricing.duplicate_lines`, `pricing.lines_without_price`, `pricing.amounts_invalid` — modules export
no error classes.

### 2.2 The values

| DTO | Fields |
|---|---|
| `CartLineDto` | `variantId` · `mode` (`Catalog\Public\Enums\SaleMode`: `RETAIL`, `WHOLESALE`) · `quantity` (≥ 1) |
| `LinePriceDto` | `variantId` · `mode` · `quantity` · `listUnit` (the line's normal price of one piece, §1.6 step 5: the retail price; on a wholesale line its band where one applies — a line is reduced exactly when `unit` < `listUnit`) · `unit` (what one piece costs on this line) · `kind` (`PriceKind` that won) · `listTotal` (= `listUnit` × quantity) · `total` (= `unit` × quantity) · `?endsAt` (when that price stops applying — the end of its sale, campaign or discount; null for `BASE` and quantity prices without dates) |
| `CartPricesDto` | `storeId` · `currencyCode` · `taxRateBasisPoints` · `lines` (list of `LinePriceDto`) · `unpriced` (variant ids with no price there) · `grossSubtotal` (Σ `listTotal`) · `netSubtotal` (Σ `total`) · `pricedAt` · `?validUntil` (the earliest `endsAt`: a quote built on these prices must not outlive it) |
| `TotalsDto` | `grossSubtotal` · `netSubtotal` · `couponDiscount` · `pointsDiscount` · `goodsTotal` · `shipping` · `taxableBase` · `vat` · `orderTotal` · `taxRateBasisPoints` |
| `PriceKind` (enum) | `BASE` · `SALE` · `CAMPAIGN` · `CATEGORY` · `QUANTITY` |
| `KeptPartDto` **[PROPOSED]** | `variantId` · `mode` · `quantity` (as edited: never above what the order held) · `listUnit` · `unit` · `kind` — as the order's snapshot holds them (§1.11) |

### 2.3 What Pricing gives Catalog

- `ListingFacts::prices` — §1.10 (the shape is catalog.md's amendment 15: `ListingPrice{now, ?before}`).
- An `ImportSection` for the store's file — §1.8.

### 2.4 What Pricing needs from other modules

| From | What | State |
|---|---|---|
| Access | **Declaring Pricing's permissions** (§3), in the `Pricing` group ("Pricing and Campaigns") | Allowed for that only (owner, 2026-10-07; `deptrac.yaml` since #97) |
| Platform | The store (`StoreDto`: currency, its decimals, VAT rate, on or off), the audit log, `MenuCount` for Needs a Price, the scheduler | Exists |
| Catalog | `storeVariant()` (selling modes, wholesale minimum), `variant()`, `product()` (its category) | Exists |
| Catalog | **The products in a category and below it** — for a category discount and its preview | **`productIdsInCategory(string $categoryId): list<string>`** — products in any stage; accepted by the owner, 2026-10-09; Catalog's amendment 16(e) (PR #103), not built yet |
| Catalog | **A product's sizes** (variant ids, in its order) — a sale for every size of a product | **`variantIdsOf(string $productId, bool $includeArchived = false): list<string>`** — Pricing leaves archived sizes out; same |
| Catalog | **The sizes a store has switched on** — Needs a Price | **`switchedOnVariantIds(StoreId $store): list<string>`** — same |
| Catalog | **One code is one size** — the store's file (§1.8); a code's one size, `variantByCode(string $code): ?VariantDto` | Accepted by the owner, 2026-10-09; Catalog's amendment 16(a) (PR #103) |
| Catalog | **Many sizes and products in one read** — names on the discount's preview and the Needs a Price list, within an admin page's 15 queries (frontend.md §5) | **`variants(list<string> $variantIds): array<string, VariantDto>`**, **`products(list<string> $productIds): array<string, ProductDto>`** — keyed by id, an unknown id left out; Catalog's amendment 16(i) (PR #103, after stage 5's review), not built yet |
| Catalog | `ProductChanged` (a product moved category) | Exists (§6.2) |
| Catalog | **`CategoryMoved(categoryId)`** — a category put under another parent; **`ProductMadeReady`** — a draft made ready (a draft's own category change sends nothing before) | `ProductMadeReady` exists; `CategoryMoved` is amendment 16 (PR #103), not built yet (§6.2) |
| Catalog | `ListingFacts` bound; **`prices(StoreId, map variantId → ListingPrice\|null)`** — `ListingPrice{now, ?before}` | Catalog builds it with the shop's pages (amendment 15); the code contract's signature changes with amendment 16's backend (16(i)) |

### 2.5 Later

- **The provider's prices** (Sync → Pricing) and a store's wired state — their shape waits for §9.1.
- **Campaign prices** (Content, stage 8; owner, 2026-10-08): the `CAMPAIGN` kind is reserved.

---

## 3 · Use cases

**Three permissions, per store, any role** — staff or admin (owner, 2026-10-08); in the `Pricing`
group; their names accepted by the owner (2026-10-08).

| Use case | Permission | Scope |
|---|---|---|
| `SetRetailPrice` — one size, or several at once | `pricing.price.edit` — **Edit Prices and Sales** | That store |
| `RemoveRetailPrice` | `pricing.price.edit` | That store |
| `AddSale` — one size, or every size of a product; a price or a percentage; start, optional end; "always wins" or not — with the note of what it affects and overlaps (§1.3) | `pricing.price.edit` | That store |
| `ChangeSale` — a scheduled sale in full; a running one, its end | `pricing.price.edit` | That store |
| `EndSale` (running: ends now) · `RemoveSale` (scheduled only) | `pricing.price.edit` | That store |
| `SetWholesalePrices` — a size's bands, all at once | `pricing.wholesale.edit` — **Edit Wholesale Prices** | That store |
| `PreviewCategoryDiscount` — what a discount would do, before saving | `pricing.category_discount.manage` — **Manage Category Discounts** | That store |
| `AddCategoryDiscount` · `ChangeCategoryDiscount` · `EndCategoryDiscount` · `RemoveCategoryDiscount` | `pricing.category_discount.manage` | That store |
| The screens' reads: a size's prices (`ViewPrices`), the store's sales and category discounts (`ListSales`, `ListCategoryDiscounts`, `ViewCategoryDiscount` with its preview), the note before saving (`OverlapsFor`) | Any of the three, in that store (owner, 2026-10-08) | That store |
| `NeedsAPrice` — the list and its count | `pricing.price.edit` (owner, 2026-10-08) | That store |
| The store's file — Pricing's section (§1.8) | Catalog's `catalog.listing.fill` (admin roles), checked by Catalog's handler | That store |
| `RebuildPriceCandidates` — a repair job, every size rewritten and compared · `ApplyPriceWindow` — the timed task at a sale's or discount's start and end · `CheckPriceWindows` — the daily safety check (owner, 2026-10-09) | System (reserved): `pricing.candidates.rebuild`, `pricing.windows.apply`, `pricing.windows.check` | — |

**A store that is off**: every change above is refused to anyone who may not switch stores
(`pricing.store_off`), as `UpdateSetting` does (platform.md §9.10). **A wired store**: setting or
removing a retail price is refused (`pricing.provider_owns_price`). Every change is **audited by
value**, from and to, under `pricing.*` names in both languages.

---

## 4 · State machines

### 4.1 A sale, a category discount

```
SCHEDULED ──(its start)──► RUNNING ──(its end, or "End now")──► ENDED
    │
    └── Remove (only while scheduled) ── gone
```

The state is read from the dates and the clock, never stored. A scheduled one may be changed in
full; a running one only its end (never before now); an ended one never.

### 4.2 A size's retail price in a store

```
NONE (on Needs a Price) ──Set──► SET ──Set──► SET
                                  └──Remove──► NONE
```

---

## 5 · Tables

Schema `pricing`. Store-scoped rows name their store in every query (as Catalog's repositories do,
amendment 4(h)). Money: `bigint` minor units + `char(3)` currency, CHECK the amount above 0 (handoff
§19). Every CHECK on a nullable column is written so a NULL cannot slip through (lesson 162).

| Table | Columns |
|---|---|
| `pricing.retail_prices` | (`store_id` FK `platform.stores` RESTRICT, `variant_id` FK `catalog.variants` CASCADE) PK · `amount_minor` bigint CHECK > 0 · `currency_code` · `source` CHECK (`STAFF`, `FILE`, `PROVIDER`) · `updated_at` |
| `pricing.sales` | `id` ULID PK · `store_id` · `product_id` FK · `percent_bp` int NULL CHECK 1–9999 · `amount_minor` bigint NULL CHECK > 0 · `currency_code` NULL · exactly one of percent and amount · `starts_at` · `ends_at` NULL CHECK > `starts_at` · `always_wins` boolean · `source` CHECK (`STAFF`, `PROVIDER`) · `created_at` — two "always wins" on one size at once refused under the store's pricing lock, as nothing in a CHECK can see across rows |
| `pricing.sale_variants` | (`sale_id` FK CASCADE, `variant_id` FK CASCADE) PK — the sizes it covers (one, or every size of the product when it was made) |
| `pricing.wholesale_bands` | (`store_id`, `variant_id`, `from_quantity` CHECK ≥ 1) PK · `amount_minor` bigint CHECK > 0 · `currency_code` |
| `pricing.category_discounts` | `id` ULID PK · `store_id` · `category_id` FK `catalog.categories` RESTRICT · `percent_bp` NULL / `amount_minor` NULL (exactly one) · `currency_code` NULL · `lines` CHECK (`RETAIL`, `WHOLESALE`, `BOTH`) · `starts_at` · `ends_at` NULL CHECK > `starts_at` · `always_wins` boolean · `created_at` |
| `pricing.category_discount_exclusions` | (`discount_id` FK CASCADE, `product_id` FK CASCADE) PK |
| `pricing.price_candidates` | `id` bigint PK · `store_id` · `variant_id` · `mode` CHECK (`RETAIL`, `WHOLESALE`) · `from_quantity` · `amount_minor` · `currency_code` · `kind` · `always_wins` boolean · `source_id` NULL (the sale or discount) · `starts_at` NULL · `ends_at` NULL · index (`store_id`, `variant_id`, `mode`) — **the materialized candidates (§1.6), rewritten per size by every change** |

The timed tasks (§1.10) are queued jobs delayed to their moment; they need no table — each reads
the sale or discount as it is when it runs.

`PriceKind`, the sources and the modes are stored as strings.

---

## 6 · Events

### 6.1 Published

**None yet** (owner, 2026-10-08): Catalog is told through `ListingFacts`, Sales asks when it prices.
An event (`PricesChanged`) is added when a consumer appears.

### 6.2 Consumed

| Event | From | Pricing |
|---|---|---|
| `ProductChanged` | Catalog | Rewrites the product's candidates: it may have moved into or out of a discounted category |
| `ProductMadeReady` | Catalog | Rewrites the product's candidates: a draft's category change sends nothing until it is made ready (stage 5's review of amendment 16, 2026-10-10) |
| `CategoryMoved` | Catalog | Rewrites the candidates of every product now in or under the moved category (`productIdsInCategory`), in every store with a category discount — the products' ancestors changed, and so may their discounts (amendment 16, 2026-10-10) |
| `VariantArchived`, `ProductArchived`, `VariantRestored`, `ProductRestored` | Catalog | Nothing: prices are kept; an archived size is not on sale anyway (Catalog) |
| `StoreListingChanged` | Catalog | Nothing to store: Needs a Price is read live |
| `StoreUpdated` (the VAT rate) | Platform | Nothing: the rate is read when prices are |

---

## 7 · Errors

Every error extends `PricingError` → `DomainError` ("pricing.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `PriceNotPositive` | INVALID | a price, band or fixed amount of 0 or less |
| `PriceTooPrecise` | INVALID | more decimals than the store's currency has |
| `PercentOutOfRange` | INVALID | a percentage not above 0 and below 100 |
| `SaleNotBelowRetail` | INVALID | a sale price not below the size's retail price when saved |
| `WindowInvalid` | INVALID | an end not after the start; a running one's end set before now |
| `SaleNotFound`, `CategoryDiscountNotFound` | NOT_FOUND | — |
| `NotChangeable` | CONFLICT | changing an ended one; removing a running one |
| `AlwaysWinsOverlap` | CONFLICT | a second "always wins" sale or discount on a size in the same dates (owner, 2026-10-09) |
| `BandsInvalid` | INVALID | the first band not at the wholesale minimum; a band not higher, or not cheaper, than the one before |
| `NotSoldWholesale` | CONFLICT | wholesale bands for a size the store does not sell wholesale |
| `CategoryNotUsable` | INVALID | a category that does not exist or is off |
| `NoRetailPrice` | CONFLICT | a percentage sale for a size with no retail price |
| `ProviderOwnsPrice` | CONFLICT | changing a retail price in a wired store |
| `StoreOff` | FORBIDDEN | changing an off store's prices without the store switch |
| `DuplicateLines`, `LinesWithoutPrice`, `AmountsInvalid` | INVALID / CONFLICT | §2.1 |

---

## 8 · Test scenarios

1. **Resolution**, as a table of cases (the engine, pure): retail only; a sale wins; a category
   discount wins; two overlapping sales, the lower wins; a wholesale line's bands at each edge; a
   retail line never gets a band; a wholesale category discount on a wholesale line; a retail-only
   discount not on a wholesale line; a left-out product; a fixed amount skipping a cheaper size; ties
   by the order in §1.6; no retail price → unpriced; a scheduled sale not yet applying; an ended one
   no longer; an "always wins" sale beating a cheaper sale and a cheaper category discount; an
   "always wins" on a wholesale line losing to a cheaper band; a running sale's `endsAt` cut short by
   an "always wins" one scheduled on the size; **the list price** — a retail line's is the retail
   price, a wholesale line's its band (retail under the first band), so a band-priced line has
   `unit` = `listUnit` and a sale below the band has `unit` < `listUnit`.
2. **Totals** (pure): every §10.2 amount; VAT rounded once, half up, at .5 exactly; refusals —
   unpriced line, another currency, a negative amount, discounts above `net_subtotal`; the same input
   twice gives the same answer.
3. **Rounding**: a percentage of an odd price, half up to the coin, for currencies with 2 and 3
   decimals.
4. **`prices()` reads candidates only** — a query recorder proves no category, sale or percentage is
   read or worked out at request time; one query for any number of lines.
5. **Candidates stay true**: after each change — retail price set and removed, a sale added, changed,
   ended, removed, bands saved, a discount added and changed, a product moved category — the
   candidates equal what `RebuildPriceCandidates` would write (the repair job compared, as Catalog's
   listing).
6. **Windows**: saving a sale or discount queues its start and end tasks; each pushes exactly the
   sizes it covers; a task whose sale was changed or removed meanwhile pushes what is true then;
   the daily check pushes every size whose window opened or closed in the last day, and a lost task
   is caught by it.
7. **Catalog is told**: every change pushes the changed sizes' now/before inside its transaction, and a
   rolled-back change pushes nothing.
8. **Permissions**: each use case in that store only; a holder in another store is refused; the three
   permissions declared in the `Pricing` group; an off store refused without the store switch; a
   wired store's retail price refused.
9. **Sales**: a price and a percentage; for every size of a product; an end before the start
   refused; a scheduled one changed and removed; a running one's end moved, ended now; an ended one
   unchangeable; a price not below retail refused; a second "always wins" on a size in the same
   dates refused (a sale against a category discount too); the note lists what it affects and every
   overlapping sale and discount.
10. **Wholesale bands**: the first at the minimum; not rising or not falling refused; a size not
    selling wholesale refused; the minimum lowered later → a line under the first band pays retail.
11. **Category discounts**: the preview lists reached, skipped and left-out products with their old
    and new prices; a sub-category's products included; a product moving in or out follows; a draft
    whose category changed follows when made ready; a category moved under a discounted parent (and
    out of one) brings its products' candidates with it; the preview's names read in one call.
12. **The store's file**: the page's lines (set, refused for decimals or 0, ignored in a wired store);
    switching on sets the retail price of the sizes it switched on, audited.
13. **Needs a Price**: a switched-on size with no retail price is listed and counted; setting a price
    removes it; removing the price brings it back; only `pricing.price.edit` holders see it.
14. **Audit**: every change by value, from and to, both languages.
15. **The database's own refusals** behind each rule (the constraints test): every CHECK broken once,
    with the nullable column left NULL where it has one.
16. **An edited order** (§1.11): kept parts unchanged whatever today's prices are; added retail pieces
    at today's price; added wholesale pieces at the band for the new total (100 kept + 400 added →
    the "from 500" band), on the added only; a line edited twice holding three parts; the order's VAT
    rate used, not the store's today; two added lines of one variant and mode refused; an added
    size with no retail price unpriced; `totals` over the result equal to the same amounts worked by
    hand.

---

## 9 · Questions

### 9.1 Open

| # | Question | Waits for |
|---|---|---|
| 1 | **The discount strategy in a wired store**: (A) discounts made only in the provider (Odoo), sent to us with full details and applied here read-only; or (B) both ways, full details, each discount edited only where it was made, never echoed back — which reverses "we never write to the provider" (handoff §12.2). The provider's sales are an amount off or a percentage off its base price (owner, 2026-10-09) | The owner, with the provider's team (asked 2026-10-09) |
| 2 | Does the provider call us when something changes (a webhook), besides our reading every few minutes? | Same |
| 3 | What a company's card shows when its first size sells wholesale only (catalog.md amendment 15) — Pricing would push the same one-piece price | The owner, with Catalog's amendment |
| 4 | Campaign prices | Stage 8 (owner, 2026-10-08) |
| 5 | Pricing's screens: prices, sales, wholesale bands, category discounts and their preview, the note before saving, Needs a Price | The frontend session, after this spec |
| 6 | `pricesForEdit` and `KeptPartDto` — the shapes for an edited order (§1.11, §2.1) | The owner's review of this amendment |

~~A code shared by a product's sizes~~ — closed: one code is one size (owner, 2026-10-09).

### 9.2 My proposals — answered

Accepted by the owner (2026-10-08): **1** the permission names, reading with any of the three;
**2** a sale for every size covers the sizes the product had when it was made; **3** a sale price
below the retail price when saved; **4** a running one only its end changed, an ended one kept,
only a scheduled one removed; **5** wholesale bands kept unused, and a lowered minimum leaving lines
under the first band at retail, marked; **6** ties shown as the offer; **9** no event published yet.
**7** replaced (2026-10-09): materialized candidates stand; the every-minute job became **timed tasks
at each start and end, and a daily safety check**. **8** dropped: one code is one size.

**A wholesale line's list price** (2026-10-10, raised by stage 5's cross-check of Promotions): is a
band a discount? **No — "wholesale is normal"** (owner): a band is the line's normal price (§1.6 step
5), so coupons and points reach band-priced lines and the ceiling counts only sales and discounts.
Before, a wholesale line's list price was the retail price, and a 70 band under a 100 retail price
used up a 30% ceiling on its own.
