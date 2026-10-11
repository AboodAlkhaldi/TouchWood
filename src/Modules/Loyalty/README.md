# Loyalty

Points: what a customer has in a store, how they are earned on a delivered order, spent as a discount
at checkout, given back when an order is cancelled or returned, changed by hand by admins, and how they
expire. **The agreed rules are `docs/modules/loyalty.md`** (accepted 2026-10-10); this file says how
the module is built.

Loyalty depends on **Platform and Access only** (handoff §4.4). Sales calls it; it never calls Sales.

## Built so far

The module is built in six steps (the execution plan, 2026-10-10). Done:

| Step | What | Where |
|---|---|---|
| 1 — foundation | The `loyalty` schema; the five permissions; the programme's eight settings; the errors; Access's Points group (access.md amendment 66) and Platform's Points menu section (platform.md §9.4). With it, a fix to Platform's settings page: an emptied number field is refused instead of saved as 0 (owner, 2026-10-10) | below |

Still to come: the ledger's tables and the points arithmetic (2), earning, redeeming, changes by hand,
the nightly expiry and `LoyaltyApi` (3), settling cancellations, returns and staff edits (4), the reads
behind the screens (5), the module's own pass (6).

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
- **`Domain/Exception`** — `LoyaltyError` and six of spec §7's errors, each with a `loyalty.*` type,
  its status, and a title and message in Arabic and English (`Presentation/lang`).
  `RedemptionRefused` comes with step 3, with the refusal reasons it carries. A deduction that is too
  large says so without the customer's balance: changing points by hand does not need the right to
  view them (§3).

## Approaches

- **No Eloquent models.** Every read and write goes through a repository that names the store in
  every call (spec §1.12), from step 2 on.
- **Words live in the language files**, both languages, checked by tests: permissions
  (`permissions.php`), settings (`settings.php`, with `module` as the section's name), errors
  (`errors.php`, with `fields` naming a refused value).

## Tests

`tests/Modules/Loyalty/Integration`: the permissions and their admin-only rule, the settings (types,
defaults, ranges at their bounds, one store apart from another, who may change them), the schema from
nothing and the migration file up and down, the error messages (every class in the folder, each type
pinned and unique, Arabic checked without falling back to English), and the Points menu section on a
menu of the test's own. Platform's `SettingsScreenTest` saves the programme's settings through the
page itself.
