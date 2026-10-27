# Pricing

What a size costs in a store, and every amount of an order. The spec is
[docs/modules/pricing.md](../../../docs/modules/pricing.md); the handoff's §10 is its source.

## Status

Built in eight steps, one PR each (stage 5's plan; owner's go 2026-10-10):

| Step | What | Permissions whose handlers arrive | State |
|---|---|---|---|
| 1 | Foundation: the `pricing` schema, the permissions, the errors and their words | — | built (#113) |
| 2 | The engine, pure: `PriceResolution` (§1.6), `PercentOff` and `FixedOff` (§1.3, §1.5), `OrderTotals` (§1.7) | — | built (#117) |
| 3a | Retail prices set and removed, the materialized candidates, the push into Catalog (§1.10), the repair and `pricing:candidates:rebuild`; the off-store guard, the unknown-store rule, the wired-store seam | `pricing.price.edit`, `pricing.candidates.rebuild` | **built** |
| 3b | `PricingApi` bound with all its methods (`prices`, `totals` through `OrderTotals`, `pricesForEdit`): one read of the candidates for any number of lines; an unknown store and a quantity below 1 invalid input | — | **built** |
| 4a | Sales (add, change, end, remove; a price or a percentage; one size, some, or every size of a product, none of an archived one; refused whole naming the sizes; the ceiling's notes name the products; "always wins" twice refused), the store's discount ceiling moved here (setting `pricing.ceiling.max_discount_percent`, job `pricing.ceiling.manage`, admin-only) and held: sales deeper refused, a retail price or a lower ceiling taking a sale past it held back (Platform's setting guard) | `pricing.price.edit`, `pricing.ceiling.manage` | **built** |
| 4b | The timed tasks at a sale's start and end (queued after the change commits, delayed to the moment; one whose sale changed or went does nothing), the daily check at 03:30 over the last 25 hours, the overlap note before saving (`OverlapsFor`, any of the three jobs) | `pricing.windows.apply`, `pricing.windows.check` | **built** |
| 5 | Wholesale bands (`SetWholesalePrices`, all at once, none removes them): the first at the wholesale minimum and below the retail price, each next higher and cheaper, for a size sold wholesale; written as wholesale-line candidates | `pricing.wholesale.edit` | **built** |
| 6a | Category discounts: add, change (scheduled in full, running its end), end, remove; a percentage or a fixed amount off every product in the category and below it now, less those left out, on the lines chosen; refused on a category missing or off (Catalog amendment 17), deeper than the ceiling, or "always winning" twice on a size across sales and discounts; written as CATEGORY candidates; timed tasks and the daily check | `pricing.category_discount.manage` | **built** |
| 6b | Following Catalog (`ProductChanged`, `ProductMadeReady`, `CategoryMoved`: the product's priced sizes repriced in every store with a discount); the ceiling held against discounts (a retail price taking a fixed amount past it, a first price too; a lower ceiling below a percentage) | — | **built**; the preview and the note naming discounts go with 7b's screen reads |
| 7a | The store's file (§1.8): the page's lines, and the price set by a job once Catalog's change commits - the staff writer (`RetailPriceWriter`), the ceiling held, source File; an off store's too, for whoever filled it | the system's, for the file | **built** (ahead of 5-6, to land with Inventory's part) |
| 7b | Needs a Price (the list, and `NeedsAPriceCount` for the menu entry its screen adds); the screens' reads - `ListSales`, `ListCategoryDiscounts`, `ViewCategoryDiscount` and `PreviewCategoryDiscount` (the preview), `ViewPrices`, `OverlapsFor` naming discounts too; an off store only for whoever may switch stores | Edit Prices and Sales (Needs a Price), Manage Category Discounts (preview), any of the three (the rest) | **built** |
| 8 | The module's own pass: the known costs below written down, the status brought up to date | — | **built** with 5-7b |
| Sync 6b | Its first published event, `DiscountReachChecked` (pricing.md §6.1): a wired store's sizes priced again while it has a category discount, or as one is added, changed, ended or removed - with the fixed amounts still left out past the ceiling - so Sync closes a "discount skipped" entry at once (sync.md §9.3 #28). `CandidateWriter::refresh(..., discountsChanged)`; no other price looks at the wiring | — | built (#159) |
| Screens 2 | Stage 5's screens, the server side (pricing.md §4.3.3, "Built"): the reads - `ListPrices`, `ViewSizePrices`, `ViewProductPrices` (and its `ProductPricesSection` in Catalog's product page), `ListSales` (state, product, sale; by state or newest; All Sizes / n of m; archived since; keyset), `ViewSale`, `ListSaleProducts`, `ListCategoryDiscounts` (paths, left-out names, state, keyset), `PreviewCategoryDiscount` (why skipped, unpriced sizes, 20 products a page with totals), `OverlapsFor` (each size's new price, whether it wins, its depth), `ListWholesale`, `NeedsAPrice` (50 a page, search), `PricingActionsForReader`; `PricingApi::salesNamed` / `discountsNamed` for Sync; Needs a Price's count kept per store (`pricing.unpriced_counts`, `CountUnpricedSizes` and its job, nightly, Q43); the rules: errors naming their sizes (Q2), left-out products in the category (Q10), at most ten bands, none past the maximum, the rule named (Q12), a sale only on a ready product switched on (Q13), "All Sizes" the priced ones (Q39), three wordings (Q15) | `pricing.needs_a_price.count` | built (no pages: step 3) |
| Screens 3 | Stage 5's screens, the pages (pricing.md §4.3, "Built (step 3)"): routes `pricing.admin.*` and the Pricing menu group (Needs a Price with its kept count); Prices with its drawer and save bar, Offers with the offer dialog, Change End and View Offer, Category Discounts with its dialog and live preview and a discount's page, Wholesale Prices with the bands dialog, Needs a Price; the product page's Pricing section; the pickers' reads (`FindSaleProducts`, `SaleSizeChoices`, `FindLeftOutProducts`, `FindWholesaleSizes`, `CategoryChoices`); each page's own queries recorded under 15 | - | built |
| Sync 2 | What Pricing gives Sync (§2.6): `StoreWiring` in the Public contracts; `ProviderPrices` - the provider's base prices in (source PROVIDER, audited with the read), off and draft sizes named, a sale the price takes past the ceiling stopped on that size (left, ended or removed); a fixed-amount category discount skips a size it would take past the ceiling, everywhere (sync.md §9.3 #17) | **built** |
| Shop prices | The shop's prices (catalog.md amendment 35, stage 6, 2026-10-27): `CandidateWriter` pushes `ListingPrice.wholesaleFrom` with each price, the least a piece costs on a wholesale line; the product page's `pricing.wholesale` section (`Presentation/Storefront/WholesaleBandsSection`, `ShopWholesaleData`, `ShopBandData`) shows a company or a guest each wholesale size's bands, one read, none for an individual. After deploying, run the repair (`RebuildPriceCandidates`) once to fill the existing sizes | - | **built** |

## Known costs (step 8, from the reviews of steps 6 and 7b)

What each piece reads, counted from the code - to measure against real data before the screens use
them at volume:

- **Writing a size's candidates** (`CandidateWriter::refresh`, every price change): the store's
  discounts not ended (1 read), and for each of them the products under its category (Catalog: about 3
  reads); then per 500 sizes, Catalog's variants (1 read, only when a discount exists) besides Pricing's
  own reads. With a handful of discounts this stays small; dozens would want the expansion kept per
  request.
- **A discount's reach** (`DiscountCoverage::pricedSizes`): every priced size of the store, matched to
  the products, in reads of 1,000 - on adding, changing, ending or removing a discount, its timed task,
  and the daily check.
- **Needs a Price's count** (`NeedsAPriceCount`): kept per store since screens step 2 (Q43) - one query
  for the menu; a store not counted yet is counted live (three queries) and its count queued.
- **The notes and the preview** (`OverlapsFor`, `PreviewCategoryDiscount`, `ViewCategoryDiscount`): the
  note reads the product's category once (`lineFacts`) instead of each discount's products; the preview
  reads the category's own sizes, a read a thousand, no longer the store's whole price list - and still
  about 4 reads of Catalog for each other discount not ended, for its overlap note.
- **The screens' reads** (screens step 2): a fixed few queries however many rows, counted by
  `PricingScreenReadsTest`; the reads spanning stores read the wired stores once (`wiredStores`).
  `PricingActionsForReader` reads each store's ceiling setting (Platform's settings, a read a store).

**Moved from step 1 to step 3a**, where the first handler uses them: the off-store guard
(`pricing.store_off` unless the person may switch stores), the seam that answers "is this store wired"
(no store is, until Sync), and the store check. A store id that is not one is refused as the
permission missing, before anything is looked up; a store that does not exist is invalid input
(owner, 2026-10-10), `pricing.invalid_pricing_input` naming the store.

## What is inside

| Folder | Holds |
|---|---|
| `Public/Contracts` | `PricingApi` — `prices`, `totals`, `pricesForEdit` (pricing.md §2.1); bound to `Application/PricingApiImpl`. For Sync (§2.6): `StoreWiring` (bound to `External/NoStoreWired` until Sync binds its own) and `ProviderPrices`, bound to `Application/ProviderPricesImpl` |
| `Public/Dto`, `Public/Enums` | `CartLineDto`, `LinePriceDto`, `CartPricesDto`, `TotalsDto`, `KeptPartDto`, `ProviderPricesResult`; `PriceKind` |
| `Application` | `PricingPermissions`: three jobs per store, any role, in the Pricing group; the system's three, reserved (§3) |
| `Application/Command` | `SetRetailPrice` (one size or several), `RemoveRetailPrice`, `RebuildPriceCandidates` (the repair: every store, on or off; answers how many sizes were out of step) |
| `Application/Prices` | What every change shares: `PricingChange` (the permission in the store, the store known, an off store only with the store switch; one transaction, the store's lock first, the audit entries), `AmountInput` (a typed amount: refused, never rounded), `SizeInput` (the sizes named, checked in two reads: none unknown, archived or of an archived product), `CandidateWriter` (sizes' candidates written again, then Catalog told their prices, 500 sizes a chunk) |
| `Domain/Exception` | `PricingError` and the nineteen errors of §7, each a `pricing.*` type with a category |
| `Domain/Repository` | `RetailPrices`, `PriceCandidates`, `PricingLocks` |
| `Domain/ValueObject` | `Candidate` (one price a line could take, as the materialized candidates hold it), `ResolvedPrice`, `PriceSource` (staff, the store's file, the provider) |
| `Domain/Service` | The engine, pure - no database, no clock: `PriceResolution` (what a line costs at a moment), `PercentOff` (half up to the coin, once), `FixedOff` (skips a size it would take to 0), `OrderTotals` (every amount, VAT rounded once) |
| `Infrastructure` | `PricingServiceProvider` (after Catalog in `bootstrap/providers.php`); `Persistence/PricingSchema` and the migrations (the schema; `retail_prices` and `price_candidates`, §5); `Eloquent/` the three repositories (the store's lock a transaction-scoped advisory lock); `External/NoStoreWired` (no store is wired until Sync binds its own `Public/Contracts/StoreWiring`, sync.md §2.3) |
| `Presentation/Console` | `pricing:candidates:rebuild`, the repair run by hand |
| `Presentation/lang/{ar,en}` | `permissions.php` (the names the role editor shows), `errors.php` (each error's title and detail, and the fields an invalid input names), `audit.php` (each audited action's name) |

## How it is built

- **Boundaries** (`deptrac.yaml`): Pricing's Public may use Shared, Platform's and Catalog's Public;
  its inside also Access's Public, to declare its permissions and for nothing else (owner,
  2026-10-07) - deptrac cannot say "these five classes only", so
  `tests/Architecture/PricingAccessUseTest.php` does.
- **The schema** is on `config/database.php`'s search path, so `migrate:fresh` wipes it.
- **Permissions** are declared into Access's catalog at boot, as Catalog's are; Access's own test
  checks that every declared permission has a name in both languages. The three jobs' English names
  are the owner's (2026-10-08), their Arabic accepted on 2026-10-10.
- **Errors** reach other modules only as `DomainError` with a stable `pricing.*` type: modules export
  no error classes. §7's "INVALID / CONFLICT" for the contract's three was settled on 2026-10-10:
  duplicate lines and invalid amounts INVALID, lines without a price CONFLICT.

## Tests

| File | Proves |
|---|---|
| `tests/Modules/Pricing/Integration/PricingSchemaTest.php` | The schema exists, is on the search path, and is created and dropped from nothing; every CHECK broken once, NULLs included (§8 #15); a store kept, a size's prices going with it |
| `tests/Modules/Pricing/Integration/RetailPricesTest.php` | Retail prices set and removed per store; refusals (decimals, 0 or less, not a number, a size unknown, archived or of an archived product, one size twice with two prices, unknown store, another store, an off store without the switch, a wired store); audit from and to; candidates on both lines; Catalog told inside the change and nothing after a rollback; the store's lock first; the repair writing the same rows, in every store (§8 #5, #7, #8, #14) |
| `tests/Modules/Pricing/Integration/ProviderPricesTest.php` | The provider's prices (§2.6): source PROVIDER, audited with the read, the same price nothing, Catalog told; off and draft sizes named; archived, not found, impossible and doubled ids named and left alone; a store not wired, or unwired under the lock, left alone; the read reference's limits; a sale stopped on a size - left, ended, removed - a percentage sale left alone; a fixed-amount discount skipping a size and reaching it again |
| `tests/Modules/Pricing/Integration/PricingAuditNamesTest.php` | Every audited action named in both languages, nothing named that is not recorded |
| `tests/Modules/Pricing/Integration/PricingPermissionsTest.php` | The three jobs and the three reserved, by name, kind, group and reservation; a staff role may hold all three; the owner's English names |
| `tests/Modules/Pricing/Unit/PricingErrorsTest.php` | Exactly §7's nineteen errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders |
| `tests/Architecture/PricingAccessUseTest.php` | Pricing takes from Access only what declaring its permissions needs |
| `tests/Modules/Pricing/Unit/PriceResolutionTest.php` | §1.6 case by case (§8 #1): retail, sales, category discounts, bands at each edge, ties, windows at their edges, "always wins" against sales, discounts, bands and the retail price, ends cut short by a scheduled "always wins" |
| `tests/Modules/Pricing/Integration/PricingScreenReadsTest.php` | The screens' reads (screens step 2): each list's rows, filters, order and keyset with the total; the drawer; a product's prices in every store and its section in Catalog; the note before saving; the actions; the names for Sync - each a fixed few queries |
| `tests/Modules/Pricing/Integration/PricingScreenRulesTest.php` | Q2 (refusals name their sizes), Q12 (the bands' rules, named), Q13 and Q39 (which sizes a sale takes), a changed sale keeping its sizes, Q15's words |
| `tests/Modules/Pricing/Integration/PricingUnpricedCountTest.php` | Needs a Price paged, searched, none archived; its count kept per store, counted again after each change, read in one query, nightly, the system's alone |
| `tests/Modules/Pricing/Unit/OrderAmountsTest.php` | A percentage off, half up to the coin with 2 and 3 decimals (§8 #3); a fixed amount skipping; every total, VAT rounded once half up, and each refusal (§8 #2) |
