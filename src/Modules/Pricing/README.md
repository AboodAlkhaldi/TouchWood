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
| 8 | The module's own pass | — | — |

**Moved from step 1 to step 3a**, where the first handler uses them: the off-store guard
(`pricing.store_off` unless the person may switch stores), the seam that answers "is this store wired"
(no store is, until Sync), and the store check. A store id that is not one is refused as the
permission missing, before anything is looked up; a store that does not exist is invalid input
(owner, 2026-10-10), `pricing.invalid_pricing_input` naming the store.

## What is inside

| Folder | Holds |
|---|---|
| `Public/Contracts` | `PricingApi` — `prices`, `totals`, `pricesForEdit` (pricing.md §2.1); bound to `Application/PricingApiImpl` |
| `Public/Dto`, `Public/Enums` | `CartLineDto`, `LinePriceDto`, `CartPricesDto`, `TotalsDto`, `KeptPartDto`; `PriceKind` |
| `Application` | `PricingPermissions`: three jobs per store, any role, in the Pricing group; the system's three, reserved (§3) |
| `Application/Command` | `SetRetailPrice` (one size or several), `RemoveRetailPrice`, `RebuildPriceCandidates` (the repair: every store, on or off; answers how many sizes were out of step) |
| `Application/Prices` | What every change shares: `PricingChange` (the permission in the store, the store known, an off store only with the store switch; one transaction, the store's lock first, the audit entries), `AmountInput` (a typed amount: refused, never rounded), `SizeInput` (the sizes named, checked in two reads: none unknown, archived or of an archived product), `CandidateWriter` (sizes' candidates written again, then Catalog told their prices, 500 sizes a chunk), the `StoreWiring` port |
| `Domain/Exception` | `PricingError` and the nineteen errors of §7, each a `pricing.*` type with a category |
| `Domain/Repository` | `RetailPrices`, `PriceCandidates`, `PricingLocks` |
| `Domain/ValueObject` | `Candidate` (one price a line could take, as the materialized candidates hold it), `ResolvedPrice`, `PriceSource` (staff, the store's file, the provider) |
| `Domain/Service` | The engine, pure - no database, no clock: `PriceResolution` (what a line costs at a moment), `PercentOff` (half up to the coin, once), `FixedOff` (skips a size it would take to 0), `OrderTotals` (every amount, VAT rounded once) |
| `Infrastructure` | `PricingServiceProvider` (after Catalog in `bootstrap/providers.php`); `Persistence/PricingSchema` and the migrations (the schema; `retail_prices` and `price_candidates`, §5); `Eloquent/` the three repositories (the store's lock a transaction-scoped advisory lock); `External/NoStoreWired` (no store is wired until Sync) |
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
| `tests/Modules/Pricing/Integration/PricingAuditNamesTest.php` | Every audited action named in both languages, nothing named that is not recorded |
| `tests/Modules/Pricing/Integration/PricingPermissionsTest.php` | The three jobs and the three reserved, by name, kind, group and reservation; a staff role may hold all three; the owner's English names |
| `tests/Modules/Pricing/Unit/PricingErrorsTest.php` | Exactly §7's nineteen errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders |
| `tests/Architecture/PricingAccessUseTest.php` | Pricing takes from Access only what declaring its permissions needs |
| `tests/Modules/Pricing/Unit/PriceResolutionTest.php` | §1.6 case by case (§8 #1): retail, sales, category discounts, bands at each edge, ties, windows at their edges, "always wins" against sales, discounts, bands and the retail price, ends cut short by a scheduled "always wins" |
| `tests/Modules/Pricing/Unit/OrderAmountsTest.php` | A percentage off, half up to the coin with 2 and 3 decimals (§8 #3); a fixed amount skipping; every total, VAT rounded once half up, and each refusal (§8 #2) |
