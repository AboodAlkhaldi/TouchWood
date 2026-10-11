# Pricing

What a size costs in a store, and every amount of an order. The spec is
[docs/modules/pricing.md](../../../docs/modules/pricing.md); the handoff's §10 is its source.

## Status

Built in eight steps, one PR each (stage 5's plan; owner's go 2026-10-10):

| Step | What | Permissions whose handlers arrive | State |
|---|---|---|---|
| 1 | Foundation: the `pricing` schema, the permissions, the errors and their words | — | built (#113) |
| 2 | The engine, pure: `PriceResolution` (§1.6), `PercentOff` and `FixedOff` (§1.3, §1.5), `OrderTotals` (§1.7) | — | **built** |
| 3 | Retail prices, the materialized candidates; `PricingApi` bound with all its methods (`prices`, `totals` through `OrderTotals`, `pricesForEdit`); the off-store guard, the wired-store seam, the module's id check | `pricing.price.edit`, `pricing.candidates.rebuild` | — |
| 4 | Sales, and the timed tasks at their start and end | `pricing.price.edit`, `pricing.windows.apply`, `pricing.windows.check` | a sale for one size: buildable; for every size of a product: after Catalog's `variantIdsOf` is built |
| 5 | Wholesale bands | `pricing.wholesale.edit` | — |
| 6 | Category discounts | `pricing.category_discount.manage` | after Catalog's amendment 16 is built |
| 7 | Needs a Price, the store's file, the screens' reads | any of the three, to read | after Catalog's amendment 16 is built |
| 8 | The module's own pass | — | — |

**Moved from step 1 to step 3**, where the first handler uses them: the off-store guard
(`pricing.store_off` unless the person may switch stores), the seam that answers "is this store wired"
(no store is, until Sync), and the module's own id check. The guard needs the owner's rule for a store
id that does not exist - §7 has no error for it yet; the question goes to the owner with step 3.

## What is inside

| Folder | Holds |
|---|---|
| `Public/Contracts` | `PricingApi` — `prices`, `totals`, `pricesForEdit` (pricing.md §2.1); not bound yet |
| `Public/Dto`, `Public/Enums` | `CartLineDto`, `LinePriceDto`, `CartPricesDto`, `TotalsDto`, `KeptPartDto`; `PriceKind` |
| `Application` | `PricingPermissions`: three jobs per store, any role, in the Pricing group; the system's three, reserved (§3) |
| `Domain/Exception` | `PricingError` and the eighteen errors of §7, each a `pricing.*` type with a category |
| `Domain/ValueObject` | `Candidate` (one price a line could take, as the materialized candidates hold it), `ResolvedPrice` |
| `Domain/Service` | The engine, pure - no database, no clock: `PriceResolution` (what a line costs at a moment), `PercentOff` (half up to the coin, once), `FixedOff` (skips a size it would take to 0), `OrderTotals` (every amount, VAT rounded once) |
| `Infrastructure` | `PricingServiceProvider` (after Catalog in `bootstrap/providers.php`); `Persistence/PricingSchema` and the migration that creates the schema |
| `Presentation/lang/{ar,en}` | `permissions.php` (the names the role editor shows), `errors.php` (each error's title and detail) |

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
| `tests/Modules/Pricing/Integration/PricingSchemaTest.php` | The schema exists, is on the search path, and is created and dropped from nothing |
| `tests/Modules/Pricing/Integration/PricingPermissionsTest.php` | The three jobs and the three reserved, by name, kind, group and reservation; a staff role may hold all three; the owner's English names |
| `tests/Modules/Pricing/Unit/PricingErrorsTest.php` | Exactly §7's eighteen errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders |
| `tests/Architecture/PricingAccessUseTest.php` | Pricing takes from Access only what declaring its permissions needs |
| `tests/Modules/Pricing/Unit/PriceResolutionTest.php` | §1.6 case by case (§8 #1): retail, sales, category discounts, bands at each edge, ties, windows at their edges, "always wins" against sales, discounts, bands and the retail price, ends cut short by a scheduled "always wins" |
| `tests/Modules/Pricing/Unit/OrderAmountsTest.php` | A percentage off, half up to the coin with 2 and 3 decimals (§8 #3); a fixed amount skipping; every total, VAT rounded once half up, and each refusal (§8 #2) |
