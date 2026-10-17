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
| 1 — foundation | The `promotions` schema; the six permissions of spec §3; the errors of §7 in both languages; Access's Marketing group (access.md amendment 68) and Platform's Marketing menu section (platform.md §9.4) | below |

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

Tests: `tests/Modules/Promotions/Integration` — `PromotionsPermissionsTest`, `PromotionsSchemaTest`,
`PromotionsErrorMessagesTest`, `PromotionsMenuSectionTest`.
