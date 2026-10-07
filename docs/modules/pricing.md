# Pricing — module specification

**Status: contract first (owner, 2026-10-07: "interfaces first").** Stage 6's Sales is built at the
same time and needs Pricing's answers, so §2 — what other modules call — is written and agreed
before the rest. §1 holds every rule the owner has decided so far; sections 3–8 (use cases, tables,
events, errors, tests) follow once the provider questions (§9) are answered. The handoff's §10,
revised 2026-10-07, is the source; this spec says how Pricing keeps it.

---

## 1 · The rules (owner, 2026-10-07)

1. **One price for everyone.** There are no company prices: a guest, an individual and a company
   see the same prices (owner: "the prices are same all across system"). Pricing never asks who is
   buying — no audience, no account type.
2. **Wholesale is for companies only** — but that is who may *buy*, not what it costs: Sales refuses
   a wholesale line to an individual (handoff §6). Pricing prices a line by its sale mode.
3. **The kinds of price**, per variant per store:

   | Kind | What | Lines |
   |---|---|---|
   | `BASE` | the retail price of one piece; **always above 0**; no dates | every line |
   | `SALE` | a lower price, dated | every line |
   | `CAMPAIGN` | a campaign's price, dated (the campaign itself is Content's, stage 8) | every line |
   | `CATEGORY` | a category discount: **a percentage or a fixed amount** off the base price, dated, for every product in the category and below it, in one store. A fixed amount **skips a product it would take to 0 or below** — the discount's page lists every product it reaches, its base price, its new price and the difference, and the ones it skips | every line |
   | `QUANTITY` | a quantity price: one row per band ("50+ pieces: 7 each") | **wholesale lines only** |

4. **The lowest applicable price wins** — every kind that applies to the line (store, variant, date,
   and on a wholesale line its quantity band) is checked and the cheapest is the line's unit price.
   Nothing stacks: a sale and a category discount are never applied on top of each other. Replaces
   the handoff's "priority DESC, first hit wins".
5. **No price, no sale.** A variant with no `BASE` price in a store is not on sale there (catalog.md
   §1.3, from stage 5).
6. **Prices are kept and shown without VAT.** VAT is added at checkout: `vat = taxable_base × the
   store's rate`, **rounded once, on the order, half up** to the currency's smallest unit (handoff
   §10.2). The rate is the store's (`tax_rate_basis_points`, Platform).
7. **Every total is computed here, in one place** (owner): Sales collects the coupon (Promotions),
   points (Loyalty) and shipping (Shipping) amounts and hands them to Pricing, which computes the
   handoff's canonical amounts (§10.2) and VAT. The arithmetic is a pure function — no database —
   and the most tested code in the system (handoff §10.1).
8. **A product's card** shows the price of its **default variant** — the first variant the store
   sells, in the product's own order; if that one is off, the next — and, during a sale, campaign
   or category discount, **the old price crossed out beside the new**. Pricing pushes, per variant,
   the price now and the base price before it (`ListingFacts::prices`); Catalog picks the variant
   (Catalog's amendment, with the account-type rule, by the Catalog-screens session).
9. **The store file's price** (`docs/modules/catalog-import/`) becomes the variant's `BASE` price in
   that store when its item is switched on. A price with more decimals than the store's currency has
   is **refused on the file's page, never rounded**; a price of 0 is refused.
10. **A store that is off** has its prices set by Super Admins only (or by its file) — the panel
    offers off stores to Super Admins alone (platform.md §9.10).
11. **A wired store** (Sync): the provider's base price arrives as `BASE`, read-only in our panel.
    Whether the provider's discounts arrive too, and whether our staff may add sales there, waits
    for the provider's team (§9).

---

## 2 · Public contract

Module to module (handoff §4.3): ids and values in, DTOs out; no permission is checked — the calling
use case checks its own. All amounts are `Shared\Domain\ValueObject\Money`, **without VAT**, in the
store's currency.

### 2.1 `Modules\Pricing\Public\Contracts\PricingApi`

| Method | For | Says |
|---|---|---|
| `prices(StoreId $store, list<CartLineDto> $lines): CartPricesDto` | Sales (cart, quote), Promotions (via Sales) | Each line's price: its unit price (the lowest applicable), the base price it compares with, the kind that won, the line amounts, and when that price stops applying; the lines with no price, apart; `gross_subtotal` and `net_subtotal` over the priced lines; the store's VAT rate. Reads the materialized prices — never resolves at request time (handoff §10.1). |
| `totals(CartPricesDto $prices, Money $couponDiscount, Money $pointsDiscount, Money $shipping): TotalsDto` | Sales | The canonical amounts (handoff §10.2): `goods_total`, `taxable_base`, `vat` (rounded once, half up), `order_total`. **Pure** — no database, no clock; the same input gives the same answer. Refuses prices holding lines with no price, amounts in another currency, a negative amount, and discounts larger than `net_subtotal`. |

`totals` may be called more than once while Sales builds a quote: with the coupon and points first
(for `goods_total`, which free shipping and points earning bind to), then with the shipping. Its
refusals reach the caller as `Shared\Domain\Error\DomainError` with a stable `type()` key —
`pricing.lines_without_price`, `pricing.amounts_invalid` — modules export no error classes.

### 2.2 The values

| DTO | Fields |
|---|---|
| `CartLineDto` | `variantId` · `mode` (`Catalog\Public\Enums\SaleMode`: `RETAIL`, `WHOLESALE`) · `quantity` (≥ 1) |
| `LinePriceDto` | `variantId` · `mode` · `quantity` · `listUnit` (the `BASE` price of one piece) · `unit` (what one piece costs on this line) · `kind` (`PriceKind` that won) · `listTotal` (= `listUnit` × quantity) · `total` (= `unit` × quantity) · `?endsAt` (when that price stops applying — the end of its sale, campaign or discount; null for `BASE` and quantity prices without dates) |
| `CartPricesDto` | `storeId` · `currencyCode` · `taxRateBasisPoints` · `lines` (list of `LinePriceDto`) · `unpriced` (variant ids with no price there) · `grossSubtotal` (Σ `listTotal`) · `netSubtotal` (Σ `total`) · `pricedAt` · `?validUntil` (the earliest `endsAt`: a quote built on these prices must not outlive it) |
| `TotalsDto` | `grossSubtotal` · `netSubtotal` · `couponDiscount` · `pointsDiscount` · `goodsTotal` · `shipping` · `taxableBase` · `vat` · `orderTotal` · `taxRateBasisPoints` |
| `PriceKind` (enum) | `BASE` · `SALE` · `CAMPAIGN` · `CATEGORY` · `QUANTITY` |

### 2.3 What Pricing gives Catalog

- `ListingFacts::prices(StoreId, map variantId → ListingPrice|null)` — the price now and, when lower
  than the base, the base before it, pushed inside Pricing's own transaction whenever either
  changes (a price edit, the store file, the provider, a window opening or closing — the windows by a
  scheduled job). The change to Catalog's contract (`ListingPrice`, two numbers) is Catalog's
  amendment.
- An `ImportSection` (catalog.md §2.3) for the store file's price.

### 2.4 Later, with Sync and Content

- The provider's prices in (Sync → Pricing) — its shape waits for §9's provider answers.
- Campaign price lists (Content, stage 8).

---

## 9 · Open questions

| # | Question | Waits for |
|---|---|---|
| 1 | Do the provider's (Odoo's) discounts come to us, do our staff add sales, or both? With "lowest wins", both is safe; "two steps by hand" means the provider sends no discount | The owner, with the provider's team |
| 2 | Does the provider call us when something changes (a push), besides our pull every few minutes? | Same |
| 3 | A code shared by a product's variants: which variant does the provider's price (and the file's) belong to? | The provider's sample |
| 4 | What a card shows when its default variant sells wholesale only (company view) | Catalog's amendment |
| 5 | Pricing's screens (prices, sales, quantity prices, category discounts and their preview page) | The frontend session, after this spec |
