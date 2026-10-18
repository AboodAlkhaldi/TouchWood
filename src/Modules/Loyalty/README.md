# Loyalty

Points: what a customer has in a store, how they are earned on a delivered order, spent as a discount
at checkout, given back when an order is cancelled or returned, changed by hand by admins, and how they
expire. **The agreed rules are `docs/modules/loyalty.md`** (accepted 2026-10-10); this file says how
the module is built.

Loyalty depends on **Platform and Access only** (handoff §4.4). Sales calls it; it never calls Sales.

## Built

The module was built in six steps (the execution plan, 2026-10-10), every one merged:

| Step | What | Where |
|---|---|---|
| 1 — foundation | The `loyalty` schema; the five permissions; the programme's eight settings; the errors; Access's Points group (access.md amendment 66) and Platform's Points menu section (platform.md §9.4). With it, a fix to Platform's settings page: an emptied number field is refused instead of saved as 0 (owner, 2026-10-10) | below |
| 2 — the ledger | The eight tables of spec §5 with every rule the database holds; the points arithmetic; the accounts repository and its lock. The id check it uses moved to the Shared kernel first (#118) | below |
| 3a — the contract | `LoyaltyApi` for Sales: what an order would earn, a redemption's quote, placing it, earning on delivery, a balance; the caller's permission checked in the order's store | below |
| 3b — by hand and expiry | Admins adding and removing points, audited; the nightly expiry job | below |
| 4a — the settlement rules | Cancellations, returns and staff edits worked out as pure code | below |
| 4b — settlements on the ledger | Those settlements written, Sales's four calls, four more database rules (answer 19) | below |
| 5 — the reads | A customer's own points; a customer's points for staff, with each change by hand's admin and reason; a store's redemptions | below |
| 6 — the module's pass | The spec swept against the code by one whole-module review; its fixes; this file completed | — |

Not built here, by design: events (nothing listens yet, §6). The screens came after, built by the
stage 6 screens session (below).

**The screens** (spec §4.1, 2026-10-19; Access amendment 72, platform.md §9.4 of that day):

- **Admin** (`Presentation/admin-routes.php`, `loyalty.admin.*`, `Http/Controller/PointsAdminController`):
  L1 Points › Customers (`ListPointHolders`: the store's accounts by *live* points — the live lots'
  sum, never the stored balance — keyset on (live points, account id), a search through Access's
  `customerIdsMatching`); L2 a customer's points (`ViewCustomerPoints`, which now answers "not found"
  for a customer with no points in the store unless the reader may view the customer — P7) with L3,
  the change-by-hand dialog posting to `AddPoints`/`DeductPoints`; L4 Redemptions; L6 Settings, a
  redirect to Platform's settings screen at `#settings-loyalty`. What a reader may do comes from
  `Application/Query/LoyaltyActions/LoyaltyActionsForReader`, never from the authorizer in a screen.
- **The customer page's section** (L5): `Presentation/Http/Resource/PointsCustomerSection`, registered
  with Access's `CustomerPageSections`, reading `CustomerPointsInStores` (one query for every store's
  account, one for their live lots), drawn by `resources/js/sections/customer/loyalty-points.tsx`.
- **The shop** (`Presentation/shop-routes.php`, `storefront.loyalty.points`, `MyPointsController`):
  the account's Points tab, offered through Access's `CustomerAccountPages` while
  `Application/Points/PointsTabOffered` says so — the programme on (from the settings the page
  already read), or the customer has an account in the store (one query, only when off).
- **Shared pieces**: `Presentation/Http/Resource/PointsPages` builds every page's data; `MoneyData`
  sends an amount as decimal text and its currency, which `resources/js/lib/money.ts` writes;
  `Application/Query/ExpiringNext` sums the lots ending on the soonest day in the store's zone.
  The pages are `resources/js/pages/Loyalty/Admin/Points/*` and `Loyalty/Storefront/Points.tsx`.
- **Tests**: `LoyaltyScreenReadsTest` (the reads), `Feature/LoyaltyScreensTest` (the pages over
  HTTP), `tests/Browser/LoyaltyScreensTest` (a person using them, at a phone's width too); Access's
  `CustomerSearchForModulesTest` and `Unit/ModuleRegistriesTest`.

The reads (`Application/Query`, spec §3): `ViewMyPoints` (`loyalty.points.view_own`, the signed-in
customer's own account only), `ViewCustomerPoints` and `ListRedemptions` (`loyalty.points.view` in
the store the points belong to; a store not covered, or no such customer → `PointsAccountNotFound`;
the job held nowhere → `Unauthorized`) — through `PointsViews` and `PointsReader`
(`Infrastructure/Eloquent/DatabasePointsReader`): live lots only; the history newest first and
keyset-paged on (`occurred_at`, `id`). Lots carry no ids; a history row carries its entry id (the
page cursor) and an admin's id, except a withheld Super Admin, who has none; a redemption row carries
the customer's id, for the screen to name them through Access — a screen shows none of these ids.
Nothing is written. Tests: `LoyaltyReadsTest`.

## What is here

- **`Infrastructure/LoyaltyServiceProvider`** — loads the migrations and the words, declares the
  permissions into Access's catalog and the settings into Platform's registry. Registered after
  Access in `bootstrap/providers.php`.
- **`Infrastructure/Persistence/LoyaltySchema`** — creates and drops the `loyalty` schema; the first
  migration calls it, and a test runs it from nothing. The schema is on `config/database.php`'s
  `search_path`, so `migrate:fresh` wipes it.
- **`Application/LoyaltyPermissions`** (spec §3):
  - `loyalty.points.view_own` — every customer, store-free: their own points.
  - `loyalty.points.view` — a role job, per store, in the Points group.
  - `loyalty.points.adjust`, `loyalty.settings.update` — role jobs, per store, Points, **admin-only**:
    Access refuses them in a staff role and offers them only for admin roles.
  - `loyalty.points.expire` — reserved, store-free: the system's nightly expiry.
  - The writes Sales makes (redeem, earn, settle) check **Sales's** permission, named by the caller
    (spec §2.1), so Loyalty declares none for them.
- **`Application/Settings/ProgrammeSettings`** (spec §1.5) — eight store settings under
  `loyalty.settings.update`, shown on Platform's settings page in a "Points" section: the programme on
  or off (**off** by default), points earned per unit, points per unit off, months a lot lives, the
  fewest points in a redemption, the most of an order points may pay, and whether individuals and
  companies choose how many points to use. Their ranges are checked by Platform when they are saved.
- **`Domain/Exception`** — `LoyaltyError` and spec §7's seven errors, each with a `loyalty.*` type,
  its status, and a title and message in Arabic and English (`Presentation/lang`).
  `RedemptionRefused` carries its `RedemptionRefusal` in `context()`, for Sales, which reads other
  modules' errors by their type (sales.md §2.3). A deduction that is too
  large says so without the customer's balance: changing points by hand does not need the right to
  view them (§3).
- **`Infrastructure/Persistence/Migrations/…_create_loyalty_ledger_tables`** (spec §5) — `accounts`
  (ULID ids), and the ledger with its working rows (bigint identity ids): `orders` (every fact of an
  order's points, written even when no point moves), `order_returns`, `entries`, `lots`, `takes`,
  `covers`, `lot_movements`. Every rule is a named CHECK written so that NULL fails it where a value
  is needed; composite keys tie every row to its account and store; `entries` and `lot_movements`
  are append-only (a trigger refuses UPDATE, DELETE and TRUNCATE).
- **`Domain/Service/PointsArithmetic`** (spec §1.5) — whole numbers only, in the currency's smallest
  unit and on its own exponent (0 to 6, as Platform's currencies allow): points earned, what points
  are worth, the cap, the most points the cap lets count, the fewest points for a discount. Products
  are worked in `BigInteger`; a result past 64 bits is refused as `InvalidPointsAttribute`.
- **`Domain/Repository/PointsAccounts`**, **`Infrastructure/Eloquent/DatabasePointsAccounts`** — an
  account per customer per store, inserted only if absent; `lockOrCreate` **locks it and expires its
  dead lots in one call** (refused outside a transaction), so no write can lock an account and skip
  its expiry, and answers the balance as it is after; the live lots in the order they are spent; the
  live balance. Customer ids are lowercased before they reach the database. Every moment goes to
  PostgreSQL in UTC with its offset: Laravel binds a date as `Y-m-d H:i:s`, which drops both.
- **`Public/Contracts/LoyaltyApi`**, **`Application/LoyaltyApiImpl`** (spec §2.1) — what Sales calls,
  with `Public/Dto` and `Public/Enums` (`Audience`, `RedemptionMode`, `RedemptionRefusal`,
  `EntryKind`; generated to TypeScript), and the settlements below. `Application/Points/Inputs`
  checks what a caller passes before anything is read or written.
  - **The writes** — `redeem` (`Application/Command/Redeem`) and `earn` (`…/Earn`) — run inside
    Sales's transaction and name **Sales's permission** (`CallerPermission`). `Application/Points/
    CallerCheck` refuses a permission that is not the calling module's own, or is Loyalty's, and
    checks it in **the store recorded on the order** (a store passed in that differs is refused).
  - **Placing** works the redemption out again under the account's lock, with the balance and
    settings of that moment: no longer as quoted — refused by the rules now, or different — →
    `RedemptionChanged`, so checkout quotes again (owner, 2026-10-11); the programme off →
    `PointsProgrammeOff`; a placement no quote could give (no points at all, a discount of 0) →
    `RedemptionRefused` with its reason. It takes from
    the live lots expiring first and records the order with the cap it redeemed under. A customer
    with no account has nothing to redeem: refused without making one.
  - **Earning** reads the programme at delivery and records the delivery even at 0 points; a
    cancelled order is refused. A lot lives `expiry_months` calendar months.
  - **Once per order**: a call repeated for an order already redeemed or delivered changes nothing,
    and is answered before anything that may have changed since (the programme switched off); read
    first, then again under the account's lock. An order Loyalty holds for another customer or
    store, or with other points, is refused — also when a call for another account recorded it
    meanwhile (the order's row is inserted only if absent). Both assume Sales's transaction runs at
    PostgreSQL's default isolation, READ COMMITTED, so the read under the lock sees the first call.
  - **The reads** — `earningPreview`, `quoteRedemption`, `balance` (`Application/Points/SalesReads`) —
    check no permission of their own (spec §3) and write nothing.
- **`Domain/Service/RedemptionRules`** (spec §1.7) — a redemption worked out, in the spec's order;
  the same rules for a quote and for placing it. `Domain/Model/Programme` is a store's programme as
  it reads now (`Application/Points/ProgrammeReader`).
- **`Domain/Repository/PointsLedger`**, **`Infrastructure/Eloquent/DatabasePointsLedger`** — the
  orders' points and the ledger's writes for redeeming and earning, on an account already locked;
  then the order's row, alone (never another account's row with it), then the lots it spends.
  `Infrastructure/Eloquent/Moment` sends every moment in UTC.
- **`Application/Command/AddPoints`**, **`…/DeductPoints`** (spec §1.9) — changes by hand under
  `loyalty.points.adjust` (an admin's job only), checked in the points' store through
  `Application/Points/StaffPointsAction`: the job held nowhere → `Unauthorized`; held, but not in this
  store, or no such customer → `PointsAccountNotFound`, the same answer as no points there. Only a
  staff member may make one (its `staff_id`), with a reason of 1 to 500 characters (trimmed),
  audited as `loyalty.points.added` / `loyalty.points.deducted` with the points and the reason
  (`Application/Points/PointsAudit`). Adding forms a lot that lives the store's period; removing
  takes from the live lots expiring first, never more than the live balance (`DeductionTooLarge`),
  and records no take, so removed points never come back. Both work while the programme is off.
- **`Application/Command/ExpirePoints`**, **`Infrastructure/Queue/ExpirePointsJob`** (spec §1.10) —
  the nightly sweep, queued at 00:30 UTC from one server, unique, one try. The system's alone:
  `loyalty.points.expire` is reserved, and a Super Admin or a job queued for one is refused too.
  It reads the accounts with a dead lot a page at a time (`PointsAccounts::withDeadLots`) and runs
  each through `lockOrCreate` in its own transaction. Not audited: the ledger is its record.
- **`Domain/Service/Settlement`**, **`…/SettlementTargets`**, **`Domain/Model/Settlement/*`** (spec
  §1.3, §1.8) — a settlement worked out on what the account holds, pure. **Give back** from the
  order's takes in the order spent: each point to its lot, which first undoes its covers (lot covers
  oldest first, back to the lots that covered — their own covers first; then covers by expired
  points and drops, which go nowhere); the rest lands with its date, expired at once if it passed.
  **Take back** from the order's earned lot: its live points, then what left it by expiry or by
  hand (counted once — points that came back to it expired included), then cover the rest by the
  order's own expired given-back points, this settlement's given-back points where they landed, the
  customer's other lots expiring first — or drop it. By running totals, so parts land exactly.
  Only points landing on the lot the order took them from count as its own (my reading, step 4a).
- **`Domain/Repository/PointsSettlements`**, **`Infrastructure/Eloquent/DatabasePointsSettlements`**,
  **`Application/Points/OrderSettlement`**, **`Application/Command/Settle*`** — `orderCancelled`,
  `orderReturned`, `orderEdited` and the read `pointsAfterEdit` (in `SalesReads`): the account
  locked, then the order's row, then every lot of the account; the snapshot worked out and written
  in one go (the settlement's `order_returns` row first, each entry with its movements, lots, takes,
  covers, the order's totals, the balance). Once per return, edit or cancellation. A cancellation
  after delivery, a return before it and an edit after it are refused (`OrderNotSettleable`).
  Migration `…_add_loyalty_settlement_rules`: an expiry naming a return names its order; every cover
  names its return; expired points kept never pass the points given back outside covers; an
  entry's return is a settlement of its order.

## Approaches

- **No Eloquent models.** Every read and write goes through a repository that names the store in
  every call (spec §1.12).
- **One lock order**: an account's row first; its dead lots, swept; the order's row; then the live
  lots it spends, the one expiring first first (lesson 37). Every write expires the account's dead
  lots before it does anything else (spec §1.1).
- **Words live in the language files**, both languages, checked by tests: permissions
  (`permissions.php`), settings (`settings.php`, with `module` as the section's name), errors
  (`errors.php`, with `fields` naming a refused value).

## Tests

`tests/Modules/Loyalty/Integration`: the permissions and their admin-only rule, the settings (types,
defaults, ranges at their bounds, one store apart from another, who may change them), the schema from
nothing and the migration file up and down, the error messages (every class in the folder, each type
pinned and unique, Arabic checked without falling back to English), and the Points menu section on a
menu of the test's own. Platform's `SettingsScreenTest` saves the programme's settings through the
page itself. Step 2: `LoyaltyLedgerConstraintsTest` breaks every CHECK, key, index and trigger of the
ledger once, after a valid row in every table, each constraint matched by its quoted name;
`LoyaltyAccountsTest` the accounts, the locks (recorded inside the change's own transaction, the
account's first, then its lots' in spending order), the lots' order and their expiry, and a moment
given in another timezone; `Unit/PointsArithmeticTest` the arithmetic on the spec's cases and its
"never charged a point that bought nothing" rule across rates and exponents; `Unit/LotTest` when a
lot is live. Step 3a: `Unit/RedemptionRulesTest` the rules in their order, the cap, the minimum on
the points charged, and the same answer worked out again; `LoyaltyEarnTest`, `LoyaltyRedeemTest`
and `LoyaltyCallerScopeTest` spec §8's Earning, Redeeming and Permissions cases through `LoyaltyApi`,
with `Support/LoyaltyFixtures` standing in for Sales's permissions (declared in each test, exactly as
sales.md §3 names them — nothing of Sales ships); `LoyaltyPlacementRaceTest` two placements at once
on two connections — the only Loyalty test outside RefreshDatabase's transaction, so it commits its
own rows and empties the test database with `migrate:fresh` before and after itself. Step 3b:
`LoyaltyByHandTest` adding and removing, the reason and points rules, who may, and the audit;
`LoyaltyExpiryTest` the sweep store by store, its paging, its schedule and that it is the system's
alone. Step 4: `Unit/SettlementTest` the rules on an in-memory book (`Support/PointsBook`) — the
owner's examples both ways, the third-order case pinned, answer 16, a three-part return, edits, and
40 random histories that never go below 0 or lose a point; `LoyaltySettlementsTest` the same on the
ledger through `LoyaltyApi`, every lot equal to its movements and the balance to its lots.
