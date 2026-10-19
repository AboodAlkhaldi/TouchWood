# Pricing — module specification

**Status (2026-10-10): the full spec, accepted** (#100, 2026-10-09); **amended 2026-10-10** — a
wholesale line's list price is its band (§1.6 step 5, §9.2); **built** in eight steps from 2026-10-10 to
2026-10-14 — see [its README](../../src/Modules/Pricing/README.md); its screens are the frontend
session's (§9.1 #5). §2 — what other modules call — was agreed
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
   read-only in our panel (handoff §12.2). **Its sales and discounts are ours, as in any store**
   (owner, 2026-10-13: §9.1 #1 settled): the provider gives the base price only; nothing is written
   back to it; the order page, confirmation email and receipt show the base price and the price
   after discount (a receipt, not an invoice; owner, 2026-10-13).
10. **The store's discount ceiling** limits every sale and category discount, as well as coupons and
   points (owner, 2026-10-11; §1.12).

### 1.2 Retail price

The price of one piece of a size in a store: **one per size per store**, above 0, in the store's
currency. Set by staff, by the store's file (§1.8) or, in a wired store, by the provider. **Removing
it** (owner, 2026-10-08) takes the size off sale there; it returns to the Needs a Price list. A
changed or removed price is audited from and to. **An archived size — or a size of an archived
product — is read-only until it is restored** (owner, 2026-10-11): its price is kept as it is, and
setting or removing one is refused.

### 1.3 Sale

A lower price for a size, for a time (owner, 2026-10-08):

- **A new price or a percentage off** — either, on the same form. A percentage is above 0 and below
  100, worked out on the size's retail price whenever that changes, rounded half up to the coin —
  **the price paid is what is rounded** (owner, 2026-10-10). **A percentage that would bring a size's
  price to 0 is refused** when the sale is saved (owner, 2026-10-10).
- **For one size, some of a product's sizes (owner, 2026-10-13), or every size of a product at
  once** - never sizes of two products. A sale over several sizes is kept as one sale over them, so
  it is changed and ended as one; a size added to the product later is not in it (owner, 2026-10-08).
  None of an archived product, nor an archived size (§7). **Only a ready product switched on in the
  store, and only its sizes switched on there** (owner Q13, 2026-10-19). **"All Sizes" is every size of
  the product switched on in the store that has a price there, saved as that list** (owner Q39): a size
  with no price is left out, not a reason to refuse; a product with none priced is refused
  (`no_retail_price`). Changing a sale keeps its product and sizes.
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
  (owner, 2026-10-08). If the retail price later drops below it, the sale simply stops winning — an
  "always wins" one too, which then **pauses nothing: the other sales and discounts count again**
  (owner, 2026-10-10).
- Its life (§4.1): **scheduled → running → ended**. A scheduled sale may be changed in full or
  removed; a running one may only have its end changed or be ended now; an ended one is history,
  kept (owner, 2026-10-08).

### 1.4 Wholesale prices

The prices a **wholesale line** pays — companies only buy wholesale (handoff §6) — **an exact price
per size** (owner, 2026-10-08), in **quantity bands**:

- **The first band starts at the product's wholesale minimum** in that store (Catalog's
  `wholesaleMinimum`), e.g. "from 20 = 85"; then as many higher bands as wanted, e.g. "from 100 = 78,
  from 500 = 70" (owner, 2026-10-08).
- Each band starts higher and costs **less** than the one before; every price is above 0; **the first
  band costs less than the retail price** when saved (owner, 2026-10-10). If the retail price later
  drops below a band, the line's normal price is the retail price (§1.6 step 5). **At most 10 bands, and
  none starting above the size's wholesale maximum** when it has one (owner Q12, 2026-10-19); a refusal
  names the rule it broke (§7).
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
- **Products can be left out** by name - **only the category's products and its children's** (owner Q10,
  2026-10-19; `invalid_pricing_input`, field `left_out`); one left out already stays allowed when the
  discount is changed, though it moved out of the category since.
- **A start, and an end only if wanted**, as a sale; the same life (§4.1); the same note of what it
  affects and what it overlaps, and the same **"always wins"** choice (§1.3) — **two "always wins"
  discounts on lines that differ (one retail only, one wholesale only) may run together**, as they
  never price the same line (owner, 2026-10-14).
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
   sale and discount set aside — on a wholesale line, the lower of it and the band — **while it is at or
   below the retail price**; above it, it has stopped winning and the others count again (owner,
   2026-10-10).
4. **Otherwise the cheapest candidate is the unit price**; its kind is reported. Equal candidates: the
   dated one first — sale, category discount — then the wholesale band, then the retail price, so the
   shopper sees why it is cheaper (owner, 2026-10-08); equal in amount and kind, **the one lasting
   longest**, so a quote stays valid as long as the price does (owner, 2026-10-10).
5. **The list price** — what `gross_subtotal` counts — is the line's normal price: **on a retail line,
   the retail price; on a wholesale line, its band** where one applies — **never above the retail price**
   (owner, 2026-10-10) — else the retail price. **A
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
Pricing gives the list and the count (Platform's `MenuCount`); the frontend session draws it. An archived
size, or one of an archived product, is left out: it can't be priced until it is restored. **The count
is kept per store** (owner Q43, 2026-10-19, as Inventory's Low Stock): `pricing.unpriced_counts`, counted
again by a queued job (`CountUnpricedSizes`, the system's) about 30 seconds after any retail price is set
or removed in the store, sizes are switched on or off there, any size or product is archived or
restored, and every night at 03:40 Riyadh time; the menu reads the kept counts in one query, a store not
counted yet live once. The list and the count read the same sizes (`UnpricedSizes`).

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

### 1.12 The store's discount ceiling (moved from Promotions, owner 2026-10-13)

**`max_discount_percent`** (handoff §11.3), per store, 0–100, **30** unless changed: how deep any
reduction from the price may go. **One Platform setting**, `pricing.ceiling.max_discount_percent`,
declared by Pricing and changed under **Set the Discount Ceiling** (`pricing.ceiling.manage`,
admin-only, per store; owner, 2026-10-13); Promotions reads it for coupons and points (promotions.md
§1.6). One stored value, so the settings screen and any Pricing screen always show the same.

- **A sale or a category discount deeper than it is refused when saved** (owner, 2026-10-11):
  a percentage above it, or a price more than it below the size's retail price (`pricing.over_ceiling`).
- **A retail price that would take a running or scheduled sale past it is held back** with a note
  naming the sales; staff pick another price or change the sale first (owner, 2026-10-11/13).
- **A lower ceiling is held back** while running or scheduled sales go deeper than it, naming them
  (`pricing.ceiling_below_sales`; owner, 2026-10-13) - through Platform's setting guard (platform.md
  §2.2), so every screen that changes it gets the same refusal.
- **Every one of these notes names the products** that hit the ceiling, in the language of whoever
  is refused (a product with no English name yet shows its Arabic one), and the lower-ceiling note
  how many sales (owner, 2026-10-13).

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
| `salesNamed(list<string> $saleIds): list<OfferNameDto>` · `discountsNamed(list<string> $discountIds): list<OfferNameDto>` | Sync's report (stage 5's screens step 2) | Each id in the order asked: whether the sale or discount still exists and, while it does, its short description in the reader's language - "−10% · 60 cm, 80 cm" (a percentage or its price, then its sizes by their values, "One Size" for a product with one), "Kitchens: 50.00 off" with the currency's sign. A fixed few queries however many. |

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

**A store that does not exist** is invalid input (`pricing.invalid_pricing_input`, field `store`; owner,
2026-10-11), as is **a quantity below 1 or above 2,147,483,647** (field `quantity`); a line's total beyond
what minor units keep is `pricing.amounts_invalid`.

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
| `OfferNameDto` | `id` (as asked) · `exists` · `?description` (none once it is gone: Sync says "Sale removed") |
| `KeptPartDto` **[PROPOSED]** | `variantId` · `mode` · `quantity` (as edited: never above what the order held; 1 or more — a part whose pieces were all taken off is left out) · `listUnit` · `unit` · `kind` — as the order's snapshot holds them, in the store's currency, above 0 (§1.11) |

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

- **Campaign prices** (Content, stage 8; owner, 2026-10-08): the `CAMPAIGN` kind is reserved.

### 2.6 What Pricing gives Sync

Built with Sync step 2 (2026-10-17; sync.md §1.4, §2.3, §9.3 #17). Module to module, as §2.1: no
permission is checked here — Sync's read checks its own.

| Contract | Says |
|---|---|
| `StoreWiring::isWired(StoreId): bool` · `wiredStores(): list<string>` | Moved from Pricing's Application layer to its Public contracts, so Sync can answer it. Pricing binds "no store is wired" until Sync binds its own. `wiredStores` (stage 5's screens step 2): every wired store in one read, for the reads spanning stores (`ViewProductPrices`, `PricingActionsForReader`); Sync answers it from its connections. |
| `ProviderPrices::apply(StoreId, array<variantId, mixed>, string $readRef): ProviderPricesResult` | Each size's retail price set to the provider's base price (§1.1 rule 9), **as the provider wrote it** — a decimal in the store's currency, checked by the store's own rules (§1.2: refused, never rounded) rather than handed over as `Money`, the provider giving no currency (sync.md §9.1). The same price changes nothing; a different one is saved with source `PROVIDER`, audited from and to with the read (`pricing.retail_price.provided`). A size switched off in the store, or of a draft product, takes it too and, when its price changed, is named. **The ceiling** (§1.12): a running or scheduled **sale** the new price takes past it **stops on that size** — the size leaves the sale; a sale left with none ends if running, or is removed if not started (or started this very moment, having no time to end at) — audited as the system's (`pricing.sale.changed` / `.ended` / `.removed`); a **fixed-amount category discount** the price takes past it **skips the size** (below), named. 500 sizes a transaction, under the store's lock, the wiring asked again in each. **Not written, and named**: archived sizes and sizes of archived products, sizes not found, prices that are not one (not text, not a number, 0 or below, too many decimals) — one bad value never fails the read — and a second id for the same size. Sync passes a read in calls of at most 10,000 sizes (PostgreSQL's bind limit on the size reads). A store not wired, or unwired while the read ran, gets nothing from then on (`wired` false). |

`ProviderPricesResult`: `wired` · `changed`, `offOrDraft` · `salesStopped` (sale, size, `left` / `ended`
/ `removed`) · `discountsSkipped` (discount, size) · `archived`, `notFound`, `invalid` · `changes` (size,
`before` — a decimal in the store's currency, or null where it had no price — and `after`, for Sync's
page of reads, sync.md §9.3 #19) — sizes by the variant id as given.

**In a wired store, a fixed-amount category discount never goes deeper than the ceiling on a size**
(owner, 2026-10-17; sync.md §9.3 #17, #20): where the provider's price would let it, the discount
skips that size — as it skips a size it would take to 0 — and reaches it again once the price, or a
raised ceiling, allows (the ceiling's change re-prices the store's fixed amounts). Such a size is
**skipped, never a reason to refuse**: the ceiling may be lowered, and a fixed amount saved or added,
the sizes it cannot reach shown as skipped in its preview and named in the Sync report. A percentage is
held to the ceiling as before. **In a store not wired nothing changes** (§1.12): staff are refused.

---

## 3 · Use cases

**Three permissions, per store, any role** — staff or admin (owner, 2026-10-08); in the `Pricing`
group; their names accepted by the owner (2026-10-08).

| Use case | Permission | Scope |
|---|---|---|
| `SetRetailPrice` — one size, or several at once | `pricing.price.edit` — **Edit Prices and Sales** | That store |
| `RemoveRetailPrice` | `pricing.price.edit` | That store |
| `AddSale` — one size, some of a product's, or every size of a product; a price or a percentage; start, optional end; "always wins" or not — with the note of what it affects and overlaps (§1.3) | `pricing.price.edit` | That store |
| `ChangeSale` — a scheduled sale in full; a running one, its end | `pricing.price.edit` | That store |
| `EndSale` (running: ends now) · `RemoveSale` (scheduled only) | `pricing.price.edit` | That store |
| `SetWholesalePrices` — a size's bands, all at once | `pricing.wholesale.edit` — **Edit Wholesale Prices** | That store |
| `PreviewCategoryDiscount` — what a discount would do, before saving | `pricing.category_discount.manage` — **Manage Category Discounts** | That store |
| `AddCategoryDiscount` · `ChangeCategoryDiscount` · `EndCategoryDiscount` · `RemoveCategoryDiscount` | `pricing.category_discount.manage` | That store |
| The screens' reads: a size's prices (`ViewPrices`), the store's sales and category discounts (`ListSales`, `ListCategoryDiscounts`, `ViewCategoryDiscount` with its preview), the note before saving (`OverlapsFor`) | Any of the three, in that store (owner, 2026-10-08) | That store |
| `NeedsAPrice` — the list and its count | `pricing.price.edit` (owner, 2026-10-08) | That store |
| The screens' reads of §4.3.3 (`ListPrices`, `ViewSizePrices`, `ViewProductPrices`, `ListSales`, `ViewSale`, `ListSaleProducts`, `ListCategoryDiscounts`, `ListWholesale`, `OverlapsFor`) and `PricingActionsForReader` | Any of the three, in that store; `PreviewCategoryDiscount` Manage Category Discounts; `NeedsAPrice` Edit Prices and Sales | That store (`ViewProductPrices`: every store of the page where the reader holds one) |
| `CountUnpricedSizes` — Needs a Price's count kept per store (Q43) | System (reserved): `pricing.needs_a_price.count` | — |
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

### 4.3 The admin screens (stage 5's screens)

**Written 2026-10-18, detailed 2026-10-19.** The owner chose (2026-10-18): the screens **by module** in
the menu; a product's prices edited **on its page as well as on the store-wide pages**; **every detail
settled before building, then built without stopping** (2026-10-19). Proposals S1-S8 accepted
(2026-10-19). **Every question of §9.4 is answered** (owner, 2026-10-19): the points marked **(Qn)** are
written as answered. The use cases behind every button are §3's. These
rules hold for Inventory's (inventory.md §4.3) and Sync's (sync.md §4.1) screens too.

#### 4.3.1 What every screen keeps (Pricing, Inventory, Sync)

**Who may do what**
- A screen offers only what the reader may do next, answered by the module's `…ActionsForReader` query
  (never the page asking the authorizer, `tests/Architecture/AccessDecisionsTest`); every handler asks
  again. A button the reader may not press is **not drawn**; one they may press but cannot now is **drawn
  disabled with its reason** - a tooltip on a button (`ActionButton disabledReason`), written under the
  item in a ⋯ menu (Catalog's `Guarded`). A link to another module's page is drawn only when the reader may
  open it (the product page needs `catalog.product.view`; the actions query answers `mayViewProducts`).
- **Off stores (Q1)**: someone who may not switch stores is never offered one (the store filter leaves it
  out) and the reads keep refusing them (`StoreOff`). A Super Admin sees an off store marked **Off**
  (`StoreOffBadge`) and works in it as usual.

**The frame** (frontend.md §2.2, §1.11)
- `AdminLayout`: a title, a one-line subtitle, **one main action** at the top end; everything else in a
  row's ⋯ menu (`MoreButton`, `align="end"`, `min-w-56`), destructive items last after a separator, each
  item that opens a dialog ending with "…".
- **The store filter** (`StoreFilter`, `StoreChoices::forJobs`): the stores where the reader holds the
  screen's job; none drawn for one store; a store outside the list refused (403). The page opens on **the
  first store that is on** (frontend.md §2.2, owner 2026-10-07).
- **Tables** in `material-base`, small headings on `bg-surface-sunken`; a row that opens something has
  its name as the link (stretched over the row, `after:absolute after:inset-0`) - **except rows holding
  boxes**: there only the product's name is a link, so a click in a box never opens anything.
- **Show More** (`LoadMoreButton`, `useLoadMore`): 50 more under the rows shown, keyset paged; above the
  table **"Showing 50 of 312"** (Q6).
- **Loading**: a page arrives whole (Inertia); the drawer shows `Spinner` "Loading" until its read
  arrives; a live note or preview keeps its last content dimmed with a `Spinner` beside its title while
  it refreshes; Show More's button is `loading`.
- **Phones** (frontend.md §2.1, §6): a table scrolls sideways inside its card; a box keeps 128 px; the
  drawer is full width under 640 px; the selection checkbox is the first column; touch targets 24 px.

**Rows that hold boxes** (P1, P5, I1, the product page)
- A box holds today's value; a box typed with a different value is **changed** - marked by a dot and
  "Changed" (screen readers: "changed"). **Escape** puts the box back; **Tab / Shift+Tab** move box to box
  in row order; **Enter** saves every changed box.
- **The save bar (Q40)**: while any box is changed, a bar fixed to the bottom of the page - `material-menu`,
  full width of the content - says "3 prices changed" with **Cancel** and **Save Prices** (the verb and
  noun of the page: Save Prices, Save Counts). It is not the page's main action, which stays as it is.
- **Leaving with changed boxes** - a link, a filter, the search, the store filter, the browser's back -
  asks first (`PanelDialog`): "Leave Without Saving" · "3 changed prices will be lost." · **Leave Page** /
  **Stay**. Show More and the drawer keep the boxes.
- **A batch** is one command (`SetRetailPrice`, `SetStock`): all saved or none. **A refusal marks its rows
  (Q2)**: the commands' errors carry the sizes they concern (`variants`), `App\Http\FormErrors` passes them
  on as one key a row - `errors["rows.<size id>"]`, the message (Inertia hands a page each key's first
  message, so a nested map can't travel) - read with `lib/row-errors.ts`, each marked row shows its message under its box, focus moves to
  the first; nothing is saved; the other boxes keep what was typed. A refusal about no row (the store off,
  wired since) shows at the top and reloads the page's actions.
- **After a save**: the toast; the rows reload (they show any change another person made meanwhile);
  focus stays where it was. **Two people editing** (Q41): the last save wins, as everywhere in the panel;
  both are in the audit log.
- **The network fails** during a save: the boxes keep what was typed; a red toast "Failed to save prices.
  Check the connection and try again."; Save stays pressable. An expired session or page (419) is the
  panel's own handling.

**Money and numbers**
- Every amount reaches the page **already written** by the server (S4) - `Money::format` with the store's
  decimals, Latin digits, then the currency's sign where set, else its letters (ر.س / SAR; frontend.md
  §1.8, HANDOFF §5.1) - as Platform's public `MoneyData {text, minor}` (`MoneyData::of(Money, StoreDto,
  locale)`), drawn by `components/Money.tsx`. In `<bdi dir="ltr">`, Geist figures (`tw-figure`).
- **A price box**: `TextField` + `input-group`, `inputMode="decimal"`, `dir="ltr"`, the currency's letters
  after it; `toLatinDigits` as typed; checked as typed with **the store's decimals** (`checks.ts` `number:
  {decimals, min}`): "Price must be a number like 149.50." (the example in the store's decimals), "Price
  can have at most 2 decimals.", "Price must be more than 0." No thousands separator.
- **A percentage box**: "%" after it; "Percent must be from 0.01 to 99.99.", at most 2 decimals.
- **Comparisons as typed** (a band below the one before, below retail) compare the typed numbers to help;
  the server decides (HANDOFF §2.4). A depth against the ceiling is answered by the server's note or
  preview, never computed on the page.
- **Signs and arrows**: "−12.5%", "+3", "−2" in `<bdi dir="ltr">`; "from → to" is Lucide's `ArrowRight`,
  mirrored on Arabic pages (`rtl:rotate-180`), its label read out ("to").

**Moments** (frontend.md §1.10)
- Shown with `Time` in the store's zone ("in 3 days"; the full moment on hover; on a detail page the full
  moment).
- **Typed (Q4)** with a date (shadcn `Calendar` in a `Popover`, as `DateRangeField` uses it) and a time box
  (24-hour HH:MM), the store's zone named beside it in the browser's own words for it ("Saudi Arabia
  Time") - `components/DateTimeField.tsx`; sent as ISO 8601 with the store's offset.
  A start offers **Now** (the default; sends no start) and refuses a past moment as typed ("Start must be
  now or later."); a start that passes while the dialog is open is taken as now by the server, said under
  it: "Starts when saved if this time has passed." An end is optional (**No End**), after the start ("End
  must be after the start.").

**Words**
- **"Offer" in the panel** (owner, 2026-10-19): the screens say **Offer** wherever this spec says sale - the
  menu entry, the page, every button, badge, toast and text ("Add Offer", "Offer ended", the badge
  **Offer**, Sync's **Offer Stopped** and **Open Offer**) - so it never meets the orders section, which
  handoff §14 calls Sales. In Arabic **عرض / العروض**. The code, the audit log and this spec keep "sale".
- Writing rules (frontend.md §1.10): buttons and menu items Title Case Verb + Noun; a destructive button
  and its toast share the verb; toasts "{Noun} {past participle}", no period; validation names the field
  and its rule, a sentence with a period; "Couldn't …" for the person's state, "Failed to …" for the
  system's.
- **Arabic (Q42)**: every word in both languages in the module's `lang/{ar,en}/admin_*.php` and
  `menu.php`, formal Arabic; the terms the owner chooses are listed in §9.4 Q42. **Plurals** with
  `trans_choice` - Arabic's six forms ("3 prices", "1 archived since", "12 sizes").
- Refusals are §7's words (Q15 fixes three).

**Budget**: every page, drawer and dialog read **15 queries at most, its own** (frontend.md §5),
asserted by its feature test; JavaScript 60 KB a page.

#### 4.3.2 Where Pricing's screens are

Admin routes in `Presentation/admin-routes.php`, named `pricing.admin.*`, loaded by the module's provider
through `App\Http\AdminArea`, `Page` and `FormErrors` (as Catalog's, catalog.md amendment 13(d));
`config/ziggy.php`'s admin group gains `pricing.admin.*`; deptrac lets Pricing's Presentation use
`AppHttp`. One controller per screen in `Presentation/Http/Controller/`; page data as `spatie/laravel-data`
classes in `Presentation/Http/Resource/`; pages under `resources/js/pages/Pricing/Admin/`.

**The menu** - Platform's `AdminMenu`, the **Pricing** group, icon `pricing`:

| # | Entry | Address | Offered for |
|---|---|---|---|
| 10 | Prices · الأسعار | `/admin/prices` | any of the three jobs |
| 20 | Offers · العروض | `/admin/offers` | any of the three |
| 30 | Category Discounts · خصومات الأقسام | `/admin/category-discounts` | any of the three |
| 40 | Wholesale Prices · أسعار الجملة | `/admin/wholesale-prices` | any of the three |
| 50 | Needs a Price · بلا سعر | `/admin/needs-a-price` | `pricing.price.edit`, with its count **kept per store** as Low Stock's is (Q43) |

**The ceiling**: each Pricing screen shows it under its subtitle - "The store's discount ceiling: 30%" -
linked to Platform's settings screen for whoever may change it (Q11).

**A wired store**: a `Note` at the top of each Pricing screen - "This store's prices come from its
provider. Sales, wholesale prices and category discounts are set here." The retail price is shown, never
a box (S2), with its source.

#### 4.3.3 The reads (Pricing's, and what they need of Catalog)

| Query | Job | Answers |
|---|---|---|
| `ListPrices` (new) | any of the three | P1's rows, 50 at a time, keyset, with the total |
| `ViewSizePrices` (new) | any of the three | one size: retail with source and when, today's price for one piece retail and wholesale and **why**, every sale, discount and band reaching it with state and dates, names included |
| `ViewProductPrices` (new) | any of the three, per store | each of a product's sizes **in every store the reader prices in**, in one read - a fixed few queries for the whole Stores tab |
| `ListSales` (extended) | any of the three | filters by state, product and sale (`?sale=`); P2's order; names; "All sizes" or "3 of 5"; a size archived since; keyset |
| `ViewSale` (new) | any of the three | one sale, every field, for its read-only dialog and Sync's link |
| `ListCategoryDiscounts` (extended) | any of the three | the category's path and names, left-out names, a state filter; keyset |
| `ViewCategoryDiscount`, `PreviewCategoryDiscount` (extended) | as built | the preview says **why** a size is skipped (Q9), counts the category's sizes with no price, and pages its products 20 at a time with totals for all |
| `OverlapsFor` (extended) | any of the three | takes the sale's price or percentage: per size the new price, whether it wins (today, or at its start), its depth against the ceiling, the names of what it overlaps |
| `ListWholesale` (new) | any of the three | P4's rows |
| `NeedsAPrice` (extended) | `pricing.price.edit` | paged 50 with the total (Q14), photo, size names, the search |
| `PricingActionsForReader` (new) | - | per store: the three jobs, wired, off, `mayViewProducts`, the reasons |
| `PricingApi::salesNamed`, `::discountsNamed` (new, public) | - | for Sync's report (§2.1 grows): a sale's or discount's short description ("−10% · 60 cm, 80 cm", "Kitchens: 50.00 off") and whether it still exists |

**Built (stage 5's screens step 2, 2026-10-19)** - the reads as Application queries answering page-facing
DTOs (step 3 maps them to the pages' data), every amount already written (`MoneyData`), every moment ISO
8601, names and size values in the reader's language; each a fixed few queries however many rows, its
feature test counting them:

- **Shared shapes** (`Application/Query/PriceViews`): `SizeView` (names, photo media id, values, code,
  archived, switched on), `RetailPriceView` (amount, source `STAFF`/`FILE`/`PROVIDER`, `updatedAt`),
  `TodayPrice` (price, `before` while lower, `why` - `BASE`, `SALE`, `CATEGORY`, `QUANTITY` -, always wins,
  the sale's or discount's id, its end), `BandView`.
- **Keyset**: Catalog's cursor for the size lists (`ListPrices`, `ListWholesale`, `NeedsAPrice`); Pricing's
  own for sales and discounts - the state's rank, a number from the dates, the id - one ascending key for
  both orders (`WindowListing`). By state, an offer whose state changes between two **Show More** clicks
  can be skipped or shown twice (accepted, 2026-10-19). The preview pages its products 20 at a time after the last product's id.
- `ListPrices` filters `ALL` · `NO_PRICE` · `ON_SALE_NOW` (one piece costs less than retail now, resolved
  from the candidates of the sizes a sale or discount reaches) · `HAS_WHOLESALE`; **Wholesale Only** is
  the store selling the size wholesale and not one piece at a time (Catalog's `wholesaleOf`, which gains
  `soldRetail`).
- `ViewSizePrices`: the wholesale line priced at the product's wholesale minimum (`wholesaleQuantity`);
  the sales and discounts **running or scheduled** (an ended one is on P2); a discount's skip reason read
  from the candidates (`zero_or_below`, `past_ceiling`).
- `ViewProductPrices`: per store `mayEditPrices`, `mayEditWholesale`, `mayManageDiscounts`, `wired`, each
  size's retail, today and bands, the product's running and scheduled sales, the discounts reaching it;
  registered with Catalog as `Pricing/ProductPrices` (`Infrastructure/Catalog/ProductPricesSection`, plain
  arrays). The wired stores are read once for all (`StoreWiring::wiredStores`, §2.6).
- `ListSales` (`state`, `productId`, `saleId`, `order` `BY_STATE`/`NEWEST`, `after`): **All Sizes** when the
  sale covers every size of its product switched on in the store now (archived ones not counted), else
  `sizesOn` of `productSizesOn`; `archivedSince`; `unpricedSizeNames`; `createdAt` (a sale keeps when it was
  added). `ViewSale` adds each size with its retail and sale price. `ListSaleProducts` answers P2's product
  filter (`searchProducts` with `only`).
- `ListCategoryDiscounts` (`state`, `order`, `after`): `categoryPath`, `leftOutNames`.
- `PreviewCategoryDiscount` / `ViewCategoryDiscount` (`after`): `reachedProducts`, `reachedSizes`,
  `skippedSizes`, `unpricedSizes` (the category's sizes switched on with no price), `deepestBasisPoints`,
  each size's `skipped` reason (`past_ceiling` only in a wired store); it reads the category's own products'
  sizes (`variantIdsOfProducts`), no longer the store's whole price list; an archived size, or a size of an
  archived product, is not reached.
- `OverlapsFor` gains `percent`, `price`, `alwaysWins`: per size `OverlapSize` (new price, `wins`,
  `notWinning` - `lower_price`, `always_wins`, `not_below_retail`, `zero`, `no_price` -, depth, over the
  ceiling), judged at the start of a scheduled sale (`atStart`); the overlapping sales and discounts gain
  a `description`. With `saleId` (Change Offer) it reads that sale's own product and sizes, unchecked - a
  product made a draft or a size switched off since still has its note; another store's sale is not found.
- `ViewSale` and `ViewCategoryDiscount` read the record's store first, ask the reader's permission there,
  then read it: an existing id of a store the reader may not read is refused (403), an unknown one not found.
- `PricingActionsForReader`: `stores()` / `forStore()` - per store the three jobs, `maySetCeiling`,
  `mayViewProducts`, `wired`, `off`, `ceilingPercent`, `retailPriceRefusal` - and `reasons()` (no price,
  not sold wholesale, archived, wired), words in `lang/{ar,en}/admin_reasons.php`.

**What Pricing needs of Catalog** (catalog.md amendment 23, written there in full): `searchVariants` -
the store's variants by name or code, **ordered by product name then the size's place**, keyset-paged,
with the total - taking `only` (these sizes) and `except` (not these) so a filter owned by Pricing pages
by Catalog's order: Pricing works out the sizes its filter keeps (one query of its own), Catalog pages
them by name; `searchProducts` (the store's ready, switched-on products, for pickers; and "products with
sales here" for P2's filter); `categoriesWithPaths(ids)`; a category's products by name for **Left Out**;
`wholesaleOf(store, sizes)` (minimum, maximum, sold wholesale). A sold-wholesale filter on
`searchVariants` serves P4's picker.

**Built (stage 5's screens step 3, 2026-10-19)** - the pages, as §4.3.1-§4.3.3 and P1-P5 write them, with
these shapes:

- **Routes** (`pricing.admin.*`, `Presentation/admin-routes.php`): each page; every change a POST that answers with
  its toast or its refusal (`PricingForms::act`, `App\Http\FormErrors` - rows a batch names as `rows.{size}`); the
  dialogs' reads as they are typed are GETs answering JSON, the page data classes: `prices/size` (a size's prices,
  the bands dialog's facts), `offers/note`, `offers/products`, `offers/sizes`, `offers/filter-products`,
  `offers/{sale}` (View Offer), `category-discounts/preview`, `category-discounts/categories`,
  `category-discounts/left-out`, `wholesale-prices/sizes`. **Change End** posts the end alone (`…/end-date`); the
  server sends the running offer's or discount's own values back with it (§4.1). Remove on a discount's own page
  goes to its store's list.
- **Page data** (`Presentation/Http/Resource`, `PricingPages`): every page carries `storeFrame` (the store, the
  filter's stores, Off, wired, the ceiling and whether the reader may change it, the jobs, the currency's
  decimals and letters, the reasons) and `storeTimezone`; its props are read only when asked, so Show More and the
  drawer (`?size=`, a partial visit) read their own alone.
- **Reads added** (Application, each asking its job): `FindSaleProducts` (the offer dialog's Product, Q13),
  `SaleSizeChoices` (its Sizes with their prices, Q39), `FindLeftOutProducts` (Q10), `FindWholesaleSizes` (Add
  Wholesale Prices), `CategoryChoices` (Catalog's `activeCategories`, amendment 23(f)). `PriceRow` gains the first
  band (Q8's warning) and `soldWholesale` (Edit Wholesale Prices' reason); `ProductStorePrices` the store's
  decimals, currency letters and zone; `CategoryDiscountView` its store; `PricingActionsForReader::forStore` reads
  one store's ceiling.
- **Budgets, recorded** (the page's own, `tests/Modules/Pricing/Feature/PricingScreensTest`): Prices 10, Offers 10,
  Category Discounts 5, a discount's page 15, Wholesale Prices 8, Needs a Price 5; the drawer 13, the offer note 15,
  the preview 15, the pickers 2-4, View Offer 12. To fit: `ViewCategoryDiscount` reads the discount and then asks
  (one read, not two); the preview checks its category through `categoriesWithPaths` (one read, not two);
  Catalog's `productIdsInCategory` no longer loads the category. *Not covered*: the note and the preview still
  read about two queries for each other discount not ended (the known cost, README) - with several, past 15.
- **Words**: `pricing::admin` (the frame, the rows' marks, the save bar, the offer and bands dialogs, Remove
  Price, the toasts), `admin_prices`, `admin_offers`, `admin_discounts`, `admin_wholesale`, `admin_needs_a_price`,
  `menu`. The panel says **Offer** throughout (§9.4): P1's filter **On Offer Now**, the badge **Offer**, **Add
  Offer…**. A count's forms (zero to other) are chosen on the page by the language's plural rules (`plural`, as
  Catalog's warranties) - the save bar's count changes as boxes are typed, so one rule serves every count.
- **The product page's section** (`resources/js/sections/Pricing/ProductPrices.tsx`): its words travel in its data
  (`words`, `ProductPricesSection`) - Catalog's page carries none of Pricing's; the sizes' names are the product
  page's own; its offers' ⋯ and Add Offer… are Offers' (`OfferActions`), its bands Wholesale Prices' dialog.
- **The menu**: the Pricing group's five entries (`PricingServiceProvider`); Needs a Price's kept count is one more
  query in the panel's frame for whoever edits prices (`AdminFrameQueryBudgetTest`, frontend.md §5) - a store not
  counted yet is counted live once, through Catalog's reads, while its count is queued.
- **Pickers** that search the server - P2's Product filter, P3's Left Out, P4's size - are shadcn's combobox with the
  server's answer in place of cmdk's own filter (`picker.tsx`); the offer dialog's Product is a `SearchField` with
  the products listed under it, each with its photo and codes.

#### P1 · Prices - `/admin/prices?store=&q=&filter=&size=`

- **Subtitle**: "Retail prices for each size, and what customers pay today."
- **Main action**: none.
- **Filters** (a `Card`, as Catalog's products list): **search** (`SearchField`, name in either language or
  code, 300 ms) and a `ToggleGroup` **All** · **No Price** · **On Sale Now** · **Has Wholesale Prices**;
  **Clear Filters** when any is set.
- **Order** (Q16): product name in the page's language, then the size's place.
- **Rows**: the store's sizes switched on, and those with a price here though switched off.
- **Columns**: photo (40 px) · **Product** (the page's language; the other under it, secondary) · **Size**
  (its values, "60 cm · Oak"; "One Size" for a product with one) · **Code** (mono, `dir="ltr"`) · **Retail
  Price** · **Today** · **Wholesale**.
- **Retail Price**: the box (`pricing.price.edit`, no provider) or the amount; under it its **source** -
  **Set Here**, **From File**, **From Provider** (Q42 words) - and when it last changed (`Time`); "—" with
  "No price" when it has none.
- **Today**: what one piece costs retail now; when a sale or discount wins, the retail struck through
  above, a badge **Sale** or **Discount** (and **Always Wins** beside it when it is one) and "until 30 Oct";
  "—" with no price; **Wholesale Only** for a size not sold one piece at a time.
- **Wholesale**: "3 bands"; "Kept, not sold wholesale" (gray) when bands remain but the size is not sold
  wholesale here (§1.4); "—".
- **Marks on a row**: **Not Switched On** (gray) for a size priced here though switched off; **Archived**
  (amber) for an archived size or product - read-only, the reason "Archived in the catalog. Restore it to
  change its price."
- **Warnings under a box as typed (Q8)**, never refusals: "Below the running sale's price, 90.00. The sale
  stops winning." · "Below the first wholesale band, 95.00. The band stops winning." A price taking a
  running sale past the ceiling is refused on Save, naming the sale (`over_ceiling`).
- **The ⋯ menu**: **View Prices** · **Add Sale…** (`pricing.price.edit`; disabled with no price: "Add a
  price first.") · **Edit Wholesale Prices…** (`pricing.wholesale.edit`; "Not sold wholesale in this store."
  / "Add a price first.") · separator · **Remove Price…** (`pricing.price.edit`; with a price; wired: "The
  provider sets this price."). **Remove Price** asks: "Remove Price" · "Customers can't order 60 cm · Oak in
  KSA until it has a price again. Its running sale stops, and its wholesale bands are kept unused." ·
  **Remove Price** / **Cancel** → "Price removed".
- **The drawer (Q3)** - **View Prices** or the product's name: shadcn `Sheet` from the end side, 480 px,
  `material-menu`, in the address (`?size=`): the product, size and code; **Retail** (amount, source, when;
  the box for whoever may type it, with its own **Save Price**); **Today** (one piece retail, one piece
  wholesale, and why each); **What Reaches It** - every sale, discount and band with its state badge, amount,
  dates and a link (a sale → P2 `?sale=`, at `/admin/offers`, a discount → its page, bands → P4's dialog).
- **Empty**: "No sizes yet" · "Sizes appear here once the store switches them on." Filtered: "No sizes match
  these filters" · "Clear the filters to see every size."

#### P2 · Offers - `/admin/offers?store=&state=&sort=&product=&sale=`

- **Subtitle**: "Lower prices for chosen sizes, for a time."
- **Main action**: **Add Sale** (`pricing.price.edit`).
- **Filters**: `ToggleGroup` **All** · **Running** · **Scheduled** · **Ended**; **Product** (a picker of the
  products that have sales in this store, any state). `?sale=` shows that one sale (from a link), with
  "Show All Sales".
- **Order (Q7; owner, 2026-10-19)**: a **Sort** switch above the list - **By State** (the default): running
  (ending soonest first), scheduled (starting soonest first), ended (ended last first); or **Newest Added**
  (the date each was added, newest first). The state filter works with either.
- **Columns**: **Product** (names; a link when the reader may view products) · **Sizes** - "All Sizes" when
  the sale covers every size the store has switched on now, else "3 of 5" (of those switched on now), the
  sizes in a tooltip; "1 archived since" (amber) · **Price** (the sale price, or "−12.5%") · **Starts** ·
  **Ends** ("No End") · **State** - **Scheduled** (blue), **Running** (green), **Ended** (gray) · **Always
  Wins** (a badge, or nothing).
- **The ⋯ menu by state**: scheduled - **Change Sale…**, separator, **Remove Sale…**; running - **Change
  End…**, separator, **End Sale…**; ended - **View Sale…**. A product archived or switched off since: the
  same items; a scheduled sale whose size lost its price since: **Change Sale…** disabled, "60 cm · Oak has no
  price now. Remove the sale or price the size."
  - **Remove Sale** asks "Remove Sale" · "It never started; nothing changes for customers." · **Remove
    Sale** → "Sale removed".
  - **End Sale** asks "End Sale" · "Customers pay the usual price from now on." · **End Sale** → "Sale
    ended".
  - **View Sale** - a read-only `PanelDialog` of its fields (`ViewSale`), **Close**.
- **The sale dialog** - Add, and Change of a scheduled sale (`PanelDialog wide`):
  - **Product** (Add only; on Change read-only - the command keeps product and sizes): a `SearchField` in the
    dialog listing **the store's ready, switched-on products** (Q13), each with photo and code, 300 ms.
  - **Sizes** (Add only): **All Sizes** - **every size switched on in this store that has a price**, saved as
    that list (Q39) - or **Some Sizes**, a checkbox per size switched on, with its retail price; a size with
    no price listed disabled, "No price yet."
  - **Sale Price** or **Percent Off** (`ToggleGroup`, no default chosen), its box.
  - **Starts** · **Ends** · **Always Wins** (`Switch`; helper "This sale beats every other price on these
    sizes while it runs.").
  - **The note** (`OverlapsFor`, refreshed 300 ms after a change): per size the new price and "Wins today"
    (a scheduled sale: "Wins at its start") or why not ("A lower sale runs" · "An Always Wins discount
    runs"); what it overlaps, named and dated; "Takes 60 cm · Oak 42% off - the ceiling is 30%." (a warning;
    the server refuses on Save).
  - **Add Sale** / **Change Sale**; refusals in the dialog (`DialogError`) and as a toast → "Sale added" /
    "Sale changed".
  - **Change End** (running): the end only, the rest read-only; **No End** allowed; a past end refused as
    typed → "Sale changed".
- **Empty**: "No sales" · "Add a sale to lower prices for a time."

#### P3 · Category Discounts - `/admin/category-discounts?store=&state=`

- **Subtitle**: "A percentage or an amount off every product in a category."
- **Main action**: **Add Discount** (`pricing.category_discount.manage`).
- **Filters and order**: as P2's.
- **Columns**: **Category** (its path, "Kitchens › Cabinets") · **Off** ("10%" or "50.00 ر.س off") ·
  **Lines** - **Retail**, **Wholesale**, **Both** · **Starts** · **Ends** · **State** · **Always Wins** ·
  **Left Out** ("3 products", names in a tooltip). The row opens the discount's page.
- **The discount dialog** (Add; Change of a scheduled one):
  - **Category**: Catalog's category combobox (`SearchCombobox`), the categories switched on.
  - **Percent Off** or **Amount Off** (`ToggleGroup`, no default), its box.
  - **Lines** (`RadioGroup`, **no default** - Geist: a choice with consequences): **Retail** · **Wholesale** ·
    **Both**; helper "Which prices it lowers: one piece, wholesale bands, or both."
  - **Starts** · **Ends** · **Always Wins**.
  - **Left Out**: a picker of **the category's products and its children's** (Q10), chosen ones as chips;
    changing the category clears them, said once: "Left-out products were cleared for the new category."
  - **The preview** (`PreviewCategoryDiscount`, refreshed 300 ms after a change): "Reaches 124 sizes in 38
    products" · "Up to 31% off" · "12 sizes have no price and are left out" · the products, 20 at a time with
    **Show More**, each collapsible with its sizes: retail → new price, or **Skipped** and why: "Would be 0
    or below." / "Past the ceiling in this wired store." (Q9) · what it overlaps. **An empty category** is
    allowed: "Reaches no sizes now. Products added to the category later are reached."
  - A percentage above the ceiling, or a fixed amount deeper than it (a store not wired): said under its box
    from the preview, refused on Save (`over_ceiling`, naming its products).
  - **Add Discount** / **Change Discount** → "Discount added" / "Discount changed".
- **The discount's page** - `/admin/category-discounts/{id}`: breadcrumb Category Discounts › its category;
  its fields (`Description`); its preview as of today while scheduled or running ("Ended - nothing is reached
  now." when ended); the state's actions - scheduled: **Change Discount** (main), ⋯ **Remove Discount…**;
  running: **Change End** (main), ⋯ **End Discount…**; ended: none. Remove / End ask as P2's → "Discount
  removed" / "Discount ended".
- **Empty**: "No category discounts" · "Add one to lower every price in a category."

#### P4 · Wholesale Prices - `/admin/wholesale-prices?store=&q=`

- **Subtitle**: "Lower prices for buying in quantity, per size."
- **Main action**: **Add Wholesale Prices** (`pricing.wholesale.edit`) - a size picker (`searchVariants`,
  sold wholesale here, with no bands yet), then the bands dialog.
- **Rows**: the sizes with bands in this store.
- **Columns**: photo · **Product** · **Size** · **Code** · **Retail Price** · **Bands** ("From 10: 95.00 ·
  From 50: 90.00") · **Minimum** (the size's wholesale minimum here). Marks: **Kept, Not Sold Wholesale**
  (gray, §1.4: kept unused); **No Retail Price** (amber: "The bands don't apply until the size has a
  price."); **Minimum Changed** (amber: "The minimum is now 10; the first band starts at 5.").
- **The ⋯**: **Edit Wholesale Prices…** · separator · **Remove Wholesale Prices…** ("Remove Wholesale
  Prices" · "Wholesale customers pay the retail price for 60 cm · Oak." · **Remove Wholesale Prices** →
  "Wholesale prices removed").
- **The bands dialog**: rows of **From** (whole pieces) and **Price**; **Add Band** under them, **at most 10**
  (Q12); each row's **Remove Band** (an icon button with its label). Checked as typed: "First band must start
  at 5, the wholesale minimum." · "From must be more than the band before." · "From can't be more than 50,
  the wholesale maximum." (Q12) · "Band price must be below the band before." · "First band price must be
  below 120.00, the retail price." The server names which rule a refusal broke (`bands_invalid` gains its
  rule). Opened on a **Minimum Changed** row, the first band's message shows at once. **Save Wholesale
  Prices** → "Wholesale prices saved"; every row removed, the button reads **Remove Wholesale Prices**.
- **Empty**: "No wholesale prices" · "Add bands to sell a size for less in quantity."

#### P5 · Needs a Price - `/admin/needs-a-price?store=&q=`

- **Subtitle**: "Sizes switched on in this store with no price. Customers can't order them."
- Its count beside the menu entry, **kept per store** (Q43).
- **Columns**: photo · Product · Size · Code · **Price** (a box). The save bar as P1's ("Save Prices"); a
  saved row leaves the list; 50 at a time with the total (Q14); the search.
- **A wired store**: read-only - "The provider's next read prices these." - no boxes.
- **Empty**: "Every size has a price" · "Sizes the store switches on without a price appear here."

#### The product page's Pricing section (Catalog's S9, Stores tab)

Through Catalog's `ProductStoreSections` (amendment 23(b)), **one `ViewProductPrices` read for every store
card on the tab**. In each store's card, for whoever holds a Pricing job in that store (nothing for the
rest):
- A table: **Size** · **Retail Price** (a box with `pricing.price.edit`, no provider; else the amount) ·
  **Today** · **Wholesale** ("3 bands", opening P4's dialog with `pricing.wholesale.edit`).
- The save bar inside the card ("Save Prices").
- Under it: the product's running and scheduled sales, one line each ("−10%, until 30 Oct") with P2's ⋯;
  **Add Sale…** (P2's dialog, the product chosen); the discounts reaching it ("Kitchens: 10% off, until 15
  Nov", linked).

---

## 5 · Tables

Schema `pricing`. Store-scoped rows name their store in every query (as Catalog's repositories do,
amendment 4(h)). Money: `bigint` minor units + `char(3)` currency, CHECK the amount above 0 (handoff
§19). Every CHECK on a nullable column is written so a NULL cannot slip through (lesson 162).

| Table | Columns |
|---|---|
| `pricing.retail_prices` | (`store_id` FK `platform.stores` RESTRICT, `variant_id` FK `catalog.variants` CASCADE) PK · `amount_minor` bigint CHECK > 0 · `currency_code` · `source` CHECK (`STAFF`, `FILE`, `PROVIDER`) · `updated_at` |
| `pricing.sales` | `id` ULID PK · `store_id` · `product_id` FK · `percent_bp` int NULL CHECK 1–9999 · `amount_minor` bigint NULL CHECK > 0 · `currency_code` NULL · exactly one of percent and amount · `starts_at` · `ends_at` NULL CHECK > `starts_at` · `always_wins` boolean · `created_at` (no `source`: every sale is ours, owner 2026-10-13) — two "always wins" on one size at once refused under the store's pricing lock, as nothing in a CHECK can see across rows |
| `pricing.sale_variants` | (`sale_id` FK CASCADE, `variant_id` FK CASCADE) PK — the sizes it covers (one, some of the product's, or every size it had when the sale was made) |
| `pricing.wholesale_bands` | (`store_id`, `variant_id`, `from_quantity` CHECK ≥ 1) PK · `amount_minor` bigint CHECK > 0 · `currency_code` |
| `pricing.category_discounts` | `id` ULID PK · `store_id` · `category_id` FK `catalog.categories` CASCADE (Catalog deletes only an empty category: its discounts reach nothing and go with it, the audit log keeping them; step 6 review) · `percent_bp` NULL / `amount_minor` NULL (exactly one) · `currency_code` NULL · `lines` CHECK (`RETAIL`, `WHOLESALE`, `BOTH`) · `starts_at` · `ends_at` NULL CHECK > `starts_at` · `always_wins` boolean · `created_at` |
| `pricing.category_discount_exclusions` | (`discount_id` FK CASCADE, `product_id` FK CASCADE) PK |
| `pricing.unpriced_counts` | `store_id` PK FK `platform.stores` RESTRICT · `unpriced` int NULL CHECK ≥ 0 · `counted_at` NULL (both or neither) · `recount_queued` boolean - Needs a Price's count kept per store (owner Q43; as Inventory's `low_counts`): one recount waits per store, its mark taken after the change commits |
| `pricing.price_candidates` | `id` bigint PK · `store_id` · `variant_id` · `mode` CHECK (`RETAIL`, `WHOLESALE`) · `from_quantity` · `amount_minor` · `currency_code` · `kind` · `always_wins` boolean · `source_id` NULL (the sale or discount) · `starts_at` NULL · `ends_at` NULL · index (`store_id`, `variant_id`, `mode`) — **the materialized candidates (§1.6), rewritten per size by every change** |

The timed tasks (§1.10) are queued jobs delayed to their moment; they need no table — each reads
the sale or discount as it is when it runs.

`PriceKind`, the sources and the modes are stored as strings.

---

## 6 · Events

### 6.1 Published

Catalog is told through `ListingFacts`, Sales asks when it prices (owner, 2026-10-08: an event is
added when a consumer appears). The first consumer is Sync:

| Event | When | For |
|---|---|---|
| `DiscountReachChecked` (`eventId`, `storeId`, `variantIds` - the sizes priced again, `skipped` - of them, each a fixed-amount discount still leaves out past the ceiling, `occurredAt`) | A **wired** store's sizes have their candidates written again while it has a category discount, or as a discount is added, changed, ended or removed, and at the daily check and the repair - so the last one ending is told too. After the change commits. No other price costs a look at the wiring | Sync closes a "discount skipped" entry the moment the discount reaches its size again (sync.md §9.3 #28; owner, 2026-10-18) |

### 6.2 Consumed

| Event | From | Pricing |
|---|---|---|
| `ProductChanged` | Catalog | Rewrites the product's candidates: it may have moved into or out of a discounted category |
| `ProductMadeReady` | Catalog | Rewrites the product's candidates: a draft's category change sends nothing until it is made ready (stage 5's review of amendment 16, 2026-10-10) |
| `CategoryMoved` | Catalog | Rewrites the candidates of every product now in or under the moved category (`productIdsInCategory`), in every store with a category discount — the products' ancestors changed, and so may their discounts (amendment 16, 2026-10-10) |
| `VariantArchived`, `ProductArchived`, `VariantRestored`, `ProductRestored` | Catalog | Prices are kept; an archived size is not on sale anyway (Catalog). Every store's Needs a Price count is counted again (Q43): an archived size leaves the list |
| `StoreListingChanged`, `StoreListingWithdrawn` | Catalog | The store's Needs a Price count is counted again (Q43) |
| `StoreUpdated` (the VAT rate) | Platform | Nothing: the rate is read when prices are |

---

## 7 · Errors

Every error extends `PricingError` → `DomainError` ("pricing.*"), with both languages.

Every refusal's **words** say **Offer** where this spec says sale (in Arabic عرض; "Always Wins" «يتقدّم دائمًا»,
§9.4 Q42) - the panel's word (§4.3.1, owner 2026-10-19); the error types and the code keep "sale".

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
| `BandsInvalid` | INVALID | the first band not at the wholesale minimum, or not below the retail price (owner, 2026-10-10); a band not higher, or not cheaper, than the one before; more than 10 bands, or one above the wholesale maximum (Q12). **Names the rule** in its context (`rule`: `first_band` the first band not at the minimum, `minimum` a band below it, `maximum`, `order` not cheaper than the band before, `below_retail`, `too_many`), with `minimum`, `maximum`, `retail`, `most` and the size (`variants`); each rule has its own words (`errors.bands_invalid.rules.<rule>`), which `App\Http\FormErrors` and `ProblemDetails` say in place of the detail |
| `NotSoldWholesale` | CONFLICT | wholesale bands for a size the store does not sell wholesale |
| `CategoryNotUsable` | INVALID | a category that does not exist or is off |
| `NoRetailPrice` | CONFLICT | any sale for a size with no retail price (stage 5's screens: its words say "any offer", not "a percentage sale"); wholesale bands for one (the first band is measured against it, step 5); "All Sizes" of a product with none priced |
| `ProviderOwnsPrice` | CONFLICT | changing a retail price in a wired store |
| `StoreOff` | FORBIDDEN | changing an off store's prices without the store switch |
| `DuplicateLines`, `LinesWithoutPrice`, `AmountsInvalid` | INVALID / CONFLICT | §2.1 |
| `OverCeiling` | INVALID | deeper than the store's discount ceiling: a sale saved, or a retail price taking a sale past it - naming the ceiling and the sales or sizes (§1.12); a retail price refused names the sizes whose new price does it (`variants`, Q2). Its words name "the price, the offer or the discount" (Q15; the panel's word, §4.3.1) |
| `CeilingBelowSales` | CONFLICT | a lower ceiling while sales and discounts go deeper, naming them (§1.12); its words say "offers and discounts" (Q15) |
| `InvalidPricingInput` | INVALID | a store or a size that does not exist (owner, 2026-10-10: an unknown store is invalid input); an archived size or a size of an archived product (owner, 2026-10-11: read-only until restored); one size given twice with two prices; an amount that is not a number; a line's quantity below 1 (§2.1) — named by its field (`store`, `variant`, `amount`, `quantity`; for the screens also `filter`, `state`, `order`, `left_out`), as Catalog's invalid attribute is. A price below 0 is `PriceNotPositive`. **Errors about sizes name them** (owner Q2): `InvalidPricingInput`, `PriceTooPrecise` and `PriceNotPositive` carry `variants` (comma-separated, as the caller named them) when they concern sizes, so `App\Http\FormErrors` marks those rows |

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
| 1 | **SETTLED 2026-10-13 (owner): the discounts are ours only** - base prices from the provider, nothing written back (§1.1 rule 9). Was: **The discount strategy in a wired store**: (A) discounts made only in the provider (Odoo), sent to us with full details and applied here read-only; or (B) both ways, full details, each discount edited only where it was made, never echoed back — which reverses "we never write to the provider" (handoff §12.2). The provider's sales are an amount off or a percentage off its base price (owner, 2026-10-09) | The owner, with the provider's team (asked 2026-10-09) |
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

**The engine's edges** (2026-10-10, step 2's build and review; owner):

| # | Question | Answer |
|---|---|---|
| 1 | A percentage off: is the price paid rounded, or the amount off? | **The price paid**, half up to the coin (§1.3) |
| 2 | An "always wins" sale above a lowered retail price | **The retail price**: it has stopped winning (§1.3, §1.6 step 3) |
| 3 | …and while it has stopped winning, do other sales count? | **Yes**: it pauses nothing (§1.3) |
| 4 | Two equal prices of the same kind, ending at different times | **The one lasting longest** (§1.6 step 4) |
| 5 | A percentage that would bring a price to 0 | **Refused** when the sale is saved (§1.3) |
| 6 | A wholesale band above a lowered retail price | **The line's normal price is the retail price**; the first band must cost less than the retail price when saved (§1.4, §1.6 step 5) |

### 9.3 Proposals for the screens (2026-10-18) - built as written unless the owner says otherwise

| # | Proposal | Why |
|---|---|---|
| S1 | **Catalog's `searchVariants`** (catalog.md amendment 23): a store's switched-on variants, and those named, found by name or code, keyset-paged - for every store-wide list in Pricing and Inventory | Pricing and Inventory keep no names; one Catalog read a page keeps each list within its 15 queries |
| S2 | **A wired store's retail price is shown, never a box**: "From the provider", with the last read's time | The provider owns it (§1.1 rule 9); a box that always refuses would only mislead |
| S3 | **Catalog's product page gains the modules' store sections** through a registry (catalog.md amendment 23), as its store files gained `ImportSections` | The owner chose prices and stock on the product page (2026-10-18); Catalog's page shows them without Catalog reading Pricing or Inventory |
| S4 | **One money formatter** shared by every Pricing screen: the store's currency, its own decimals, Latin digits | Prices look the same on every screen; frontend.md has none yet |

### 9.4 Questions for stage 5's screens - answered (owner, 2026-10-19), one list for Pricing, Inventory and Sync

**Round 1**: every question as recommended, except **Q7** - a **Sort** switch, **By State** the default and
**Newest Added** the other - and Q10, Q17 and Q27, explained and then answered. **Round 2**: Q7's default
**By State**; Pricing's menu entry **renamed "Offers"** (العروض), "Offer" the panel's word for a sale
(§4.3.1); **Q17 - out of stock in the shop stays as decided on 2026-10-02 (option A)**: what can't be ordered
leaves lists, search and suggestions, a direct link says "Not available now" - stock decides that for every
product in a store with no provider, and only for stock-dependent sizes in a store wired to a provider;
**Q10 and Q27 confirmed** as recommended. Nothing is left open.

| # | Question | Recommended | What it changes |
|---|---|---|---|
| **All three** ||||
| Q1 | Someone who may not switch stores, and an off store | **Never offered** (the store filter leaves it out); the reads keep refusing it | §4.3 first said "read-only"; the built reads refuse |
| Q2 | Saving several prices or counts at once, one of them wrong | **Nothing saved; the wrong rows marked**, each with its message under its box | Some errors already name their sizes; the rest gain them, and the panel passes them to the page |
| Q3 | A size's details (prices, stock, history) | **A side panel** (shadcn's `Sheet`, from the end side), kept in the address | The other choice is a page per size |
| Q4 | Typing a start or an end | **A date** (shadcn's `Calendar` in a popover, as the audit log's date range uses it) **and a time box** (24-hour), in the store's zone; "Now" the default start; no past | Geist has no date-and-time picker; this is built from shadcn's parts |
| Q5 | Catalog's products list | With a store chosen, it gains **Price** ("from 120.00", the lowest one-piece price of its sizes switched on there) and **Can't Order** ("2 of 5 sizes") - from the facts Catalog already keeps, so Catalog reads neither Pricing nor Inventory | Low stock isn't in Catalog's facts, so the list can't say "Low" |
| Q6 | Long lists | **Show More**, 50 at a time, with "Showing 50 of 312" | - |
| Q40 | Saving typed boxes on a long list | **A bar fixed to the bottom of the page** while boxes are changed: "3 prices changed · Cancel · Save Prices" | Neither Geist nor shadcn has this bar; it is built from their parts |
| Q41 | Two people editing the same price | **The last save wins** (as everywhere in the panel); both are in the audit log; rows reload after each save | The other choice is refusing a save over a newer change |
| Q42 | The Arabic words (table below) | **As proposed** | Words only |
| **Pricing** ||||
| Q7 | Offers and discounts: order and filter | **Answered**: a Sort switch - **By State** (default: running, then scheduled, then ended) or **Newest Added**; the filter **All · Running · Scheduled · Ended** | The built order is latest start first |
| Q8 | A retail price typed below a running sale's price, or below the first wholesale band | **A warning under the box, never a refusal** ("The sale stops winning.") | Today nothing is said |
| Q9 | The discount preview's skipped sizes | **Say why**: "Would be 0 or below" or "Past the ceiling" | Today both look the same |
| Q10 | A discount's "products left out" | **Only the category's products** (and its children's); the server refuses others | Today any product passes |
| Q11 | The ceiling in the sale and discount dialogs | **Shown** ("The store's discount ceiling: 30%") **and checked as typed** by the server's note; still refused on Save | Today it is met only on Save |
| Q12 | Wholesale bands | **At most 10**; a band's quantity **no more than the size's wholesale maximum** when it has one | Today no limit |
| Q13 | Which products a sale can be put on | **Products switched on in this store, and ready** | Today drafts and products not switched on pass |
| Q39 | A sale on "All sizes" | **Every size switched on in this store that has a price**, saved as that list | Today "all" means every size of the product, so one size with no price refuses the sale |
| Q14 | Needs a Price | **Paged** 50 at a time, with the total | Today one unbounded list |
| Q43 | Needs a Price's count beside the menu | **Kept per store**, counted again by a job after each change - as you chose for Low Stock | Today it is counted live on every admin page |
| Q15 | Three wording fixes | the ceiling refusal names "the price, the sale or the discount"; its count says "sales and discounts"; Arabic audit words say **قسم**, as the menu | Words only |
| Q16 | The Prices list's order | **By product name**, then the size's place | The other choice: newest priced first |
| **Inventory** ||||
| Q17 | "Out of Stock" | **Available 0 on a size that counts on stock**; a wired ordinary size at 0 shows **Orderable** instead | It stays orderable (§1.3) |
| Q18 | Orders holding more than there is | **"Short by 3"** in red, first of the badges | Available never shows below 0 |
| Q19 | Counts saved from the screen | **Only rows typed with a new number** - no "counted, no change" ledger lines | Each sent row writes a ledger line |
| Q20 | A count or removal below what orders hold | **Said before saving** ("2 orders hold 5 of these"), not refused; an amber toast after | Order numbers come once Sales has orders |
| Q21 | Holds in a size's panel | **A summary**: "Held by 2 orders: 5 pieces (3 bought, 2 gift)"; each order listed once Sales exists | No order pages yet |
| Q22 | A size's history | **The ledger only**, who did it by name; holds and a wired store's returned extra aren't ledger lines and aren't shown | They are kept elsewhere |
| Q23 | A wired store's stock and the provider's time | **"From Provider"** with when the provider last **changed** this size's number; Pricing the same for prices | Neither can read Sync's read times; a number sent again unchanged leaves no trace |
| Q24 | A size the store doesn't sell (gifts only) | **"Add Size"** on the Stock page: pick any ready size, then count it | Today no screen can start one |
| ~~Q25~~ | ~~Low Stock opening on the store with the most low sizes~~ | **Withdrawn**: every store page opens on the first store that is on (your rule, 2026-10-07); the subtitle says "12 low in KSA" | - |
| **Sync** ||||
| Q26 | Sync Now while a read runs | **Allowed** - one more read follows it; disabled only while a Now read already waits | The first spec said disabled while one runs |
| Q27 | Retry on "Offer Stopped" and "Off or Draft" | **Not offered** (a read never closes them); **Open Offer** / **Open Product** instead | Today Retry is accepted and changes nothing |
| Q28 | The closing word | **"Dismiss Entry"** for every kind | "Leave it" was the off-or-draft word |
| Q29 | "Last read" | **The newest finished read of any kind**, and **Last full read** beside it | Today's field is the last hourly or full read's start |
| Q30 | A failed read's counts | **Counted from what it wrote** | Today a failed read shows 0 |
| Q31 | Failure text on an Arabic page | **Sync's own reasons kept as codes and written in both languages**; the provider's reason shown as it came, under "The provider said:" | Today English text is stored |
| Q32 | A read's page | **Each section a tab with its own paging** (Prices, Stock, Reported, Codes Asked) | Today one page number pages all three |
| Q33 | "Key and secret changed" | **"Last Changed"** - any change of the connection | A separate time needs a new column |
| Q34 | A provider setting refused | **By its own name** | Today every setting's error is named "provider" |
| Q35 | A store not wired, on the report | **"Not Wired"**, the reads kept (90 days) read-only, no Problems tab; **Wire Store** for a Super Admin | - |
| Q36 | Who asked for a read | **By name** ("Asked By"), "The System" for the rest; no separate cause | Wiring, Sync Now and Retry on a failed read make Now reads; Retry on an entry makes a One Code read |
| Q37 | A stopped sale's entry once the sale is gone | **"Sale removed"**, no link | - |
| Q38 | The Odoo connection isn't built yet | **The Provider page says so** above the form; the form stays (its test refuses) | - |

**Q42 - the Arabic words proposed** (formal Arabic; Latin digits; "%" as written in English):

| English | Arabic | English | Arabic |
|---|---|---|---|
| Offers (menu) | العروض | Discount (badge) | خصم |
| Offer (badge) | عرض | Always Wins | يتقدّم دائمًا |
| Scheduled · Running · Ended | مجدول · سارٍ · منتهٍ | Retail · Wholesale · Both | التجزئة · الجملة · كلاهما |
| Set Here · From File · From Provider | مُدخل هنا · من ملف · من المزوّد | Wholesale Only | جملة فقط |
| Out of Stock · Low · Short by 3 · Orderable | نفد · منخفض · ناقص 3 · متاح للطلب | Counts on Stock | يعتمد على المخزون |
| Last Pieces | آخر القطع | Received · Damaged or Lost · Offline Sale · Correction | استلام · تالف أو مفقود · بيع خارج المتجر · تصحيح |
| Unknown Code · Not at Provider · Archived · Off or Draft | رمز غير معروف · غير موجود لدى المزوّد · مؤرشف · متوقف أو مسودة | Offer Stopped · Bad Value · Read Failed · Discount Skipped | عرض متوقف · قيمة غير صالحة · فشلت القراءة · خصم مُتجاوَز |
| Notice · Hourly · Nightly · Now · One Code | إشعار · كل ساعة · ليلية · الآن · رمز واحد | Waiting · Running · Done · Failed | بالانتظار · قيد التشغيل · تمّت · فشلت |
| Working · Failing · Not Wired | يعمل · متعثّر · غير مربوط | Wire Store · Unwire Store · Sync Now · Dismiss Entry | ربط المتجر · فكّ ربط المتجر · مزامنة الآن · إغلاق البند |
