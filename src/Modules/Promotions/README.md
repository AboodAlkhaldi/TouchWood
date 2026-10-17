# Promotions

Coupons, customer segments and gifts: what a coupon takes off, who may use it and how often, which
customers a segment holds, which gift an order earns — checked inside Sales's checkout, with every use
and every placed order's fact recorded. **The agreed rules are `docs/modules/promotions.md`**
(accepted 2026-10-10); this file says how the module is built.

The store's discount ceiling is a Pricing setting (`pricing.ceiling.max_discount_percent`, changed
under `pricing.ceiling.manage`): Promotions reads it and declares no setting of its own (owner,
2026-10-17).

## Built

The module is built in seven steps (the execution plan, approved 2026-10-17):

| Step | What | Where |
|---|---|---|
| 1 — foundation | The `promotions` schema; the six permissions of spec §3; the errors of §7 in both languages; Access's Marketing group (access.md amendment 69) and Platform's Marketing menu section (platform.md §9.4) | below |
| 2a — coupons: the rules and the reads | The coupon tables of §5 (coupons, rules, assignments, uses, order facts) with every rule the database holds; the coupon's rules as pure code; `PromotionsApi::applyCoupon` and `customerDiscountRoom`; Catalog's `lineFacts` (catalog.md amendment 18) | below |

## What is here

- **`Infrastructure/PromotionsServiceProvider`** — loads the migrations and the words, declares the
  permissions through Access.
- **`Application/PromotionsPermissions`** — the four jobs a role may hold, per store, under Marketing,
  none admin-only (owner, 2026-10-08); a customer's own coupons, every customer's; the nightly refresh
  of segments, reserved to the system.
- **`Infrastructure/Persistence/PromotionsSchema`** and its migration — the `promotions` schema, on
  the connection's search path so a fresh migration wipes it.
- **`Domain/Exception`** — the errors of spec §7 built so far, each with its type
  (`promotions.{snake}`), its status, and a title and a message in Arabic and English at
  `promotions::errors.{snake}`. `CouponRefused` and its reasons come with the coupon checks (step 2).
- **`Presentation/lang/{en,ar}`** — the permissions' names and the errors' words.
- **`Infrastructure/Persistence/Migrations/…_create_coupon_tables`** — §5's coupon tables. Every CHECK
  of the spec, composite keys carrying the coupon's store, `UNIQUE (order_id)` on uses (one coupon per
  order), and two triggers: a use is never deleted and its discount only lowered by an edit (with
  `last_edit_id`); an order's fact never changes. The assignment's `segment_id` gets its foreign key
  with the segments table (step 4).
- **`Domain/Service/CouponRules`** — pure: `covers` (§1.2: include by product, category or any above
  it, or brand; exclusions win; sale mode, buyer, reduced lines), `work` (§1.3: the minimum on
  `net_subtotal`, nothing eligible, a percentage half up then the cap, a fixed amount at most the
  eligible total, worth nothing; shares by `Money::allocate`), `refusalNow` (switch, dates, uses, the
  assignment, in `CouponRefusal`'s order) and `quote` (those, then the ceiling's room).
  **`DiscountRoom`** — §1.6's formula, the ceiling's part rounded down.
- **`Application/PromotionsApiImpl`** — `applyCoupon`: the code read as typed (trim, Latin digits,
  upper case; one that cannot be a code is `UNKNOWN_CODE`), prices of another store or currency
  refused, the cart's lines through `CartLines` (one `CatalogApi::lineFacts` call), the ceiling
  through `Ceiling` (Pricing's setting, read through Platform). A fixed number of queries per quote.
  `customerDiscountRoom`. The writes Sales makes come with step 2b.
- **`Infrastructure/Eloquent/DatabaseCoupons`** — a coupon by code with its rules; a customer's uses
  and assignment in one query.

Tests: `tests/Modules/Promotions/Unit/CouponRulesTest` (the rules and the room, every number of §8);
`tests/Modules/Promotions/Integration` — `ApplyCouponTest`, `CouponTablesTest`,
`PromotionsPermissionsTest`, `PromotionsSchemaTest`, `PromotionsErrorMessagesTest`,
`PromotionsMenuSectionTest`.
