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
| 2b — coupons: Sales's writes | `useCoupon` (every check again under the coupon's lock; `CouponRefused`, `CouponChanged`; once per order), `recordOrder` (the ceiling of the moment), `orderDiscountRoom`, `couponAfterEdit`, `updateCouponUse`; the caller's permission in the coupon's or the order's store | below |
| 3a — what Catalog may not delete | Catalog's usage check before deletes (catalog.md amendment 19), and Promotions' answer: the products, categories and brands its coupons' lists name | below |
| 3b — coupons for staff | Create, change, switch, delete if unused, with the include and exclude lists checked against Catalog (and its `brand()` read, catalog.md amendment 20); audited; in the staff member's own stores, an off store's for Super Admins alone | below |
| 4a — segments | The segment tables; create, change (name and rule — type and audience fixed, answer 23), delete, pick by hand, refresh now; the nightly refresh job; Access's bulk customer reads (access.md amendment 70) | below |
| 4b — assignments | Assigning an `ASSIGNED` coupon to a segment's members of that moment (answer 24); removing an unused assignment; a coupon assigned from a segment may not leave its audience out; `PromotionsApi::segmentMembers` | below |

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
  through `Ceiling` (Pricing's setting, read through Platform), both via `Coupon/Quotes`. A fixed
  number of queries per quote. `customerDiscountRoom`; `orderDiscountRoom` (the ceiling recorded with
  the order); `couponAfterEdit` (`CouponRules::work` on the edited lines, ignoring dates, switch, uses
  and assignment; at most the recorded use's discount and the caller's figure, then the room; refused
  — 0 — under the minimum, with nothing eligible, or with no room or nothing left).
- **`Application/Command`** — Sales's three writes, each through `Coupon/CallerCheck` (the calling
  module's own permission, in the coupon's or the order's store, as Loyalty's): **`UseCoupon`** (the
  coupon's store must be the one passed; the order's use read before and again under the coupon's
  lock — the same coupon again does nothing, another is `CouponRefused::anotherCoupon()`; every check
  of a quote asked again, then points not allowed, then `CouponChanged` unless the discount is the one
  quoted; the use recorded and counted), **`RecordOrder`** (once per order, the store's ceiling of the
  moment, the first of two at once wins), **`UpdateCouponUse`** (never higher; once per edit; to 0 with
  the use still counted).
- **`Infrastructure/Eloquent/DatabaseCoupons`** — a coupon by code or id (locked for placing) with its
  rules; a customer's uses and assignment in one query; a use by its order; recording and lowering a
  use. **`DatabaseOrderFacts`** — an order's fact, written once (`ON CONFLICT DO NOTHING`).
- **Staff's coupon commands** (`Application/Command/{Create,Change,Switch,Delete}Coupon`, all under
  `promotions.coupon.manage`), through **`MarketingChange`** (shared with the segments' commands) as Pricing's `PricingChange`: the
  permission in the store first; an existing coupon's store must be one the staff member covers, or it
  is `CouponNotFound` as an unknown one is; a switched-off store is the Super Admins' alone
  (`Unauthorized`); its own transaction with a per-store advisory lock, and the audit entries
  (`Coupon/CouponAudit`: `promotions.coupon.created`, `…updated` with only what changed,
  `…deactivated`, `…activated`, `…deleted`). Terms are checked by the domain's **`CouponTerms`**
  (names, kind and amounts, dates, uses, sale modes and buyers — the public only on wholesale lines
  never applies — the rules, deduplicated, at most 500); the lists by **`Coupon/RuleTargets`** (ready
  products, active categories and brands, through `CatalogApi`) — on a change, only the entries it adds
  (owner, 2026-10-17, answer 22). Dates are kept in UTC to the second and written with their offset
  (`Infrastructure/Eloquent/Moment`). The code is read as typed and unique in
  the store (`CodeTaken`, the unique index the backstop); never changed. A change locks the coupon's row
  first, so an order being placed never reads it half changed; the total of uses below those made is
  `CouponInUse`. A used coupon is never deleted (`CouponInUse`).
- **Segments** (`Application/Command/{Create,Change,Delete}Segment`, `SetSegmentMembers`,
  `RefreshSegment`, all under `promotions.segment.manage`, audited `promotions.segment.created`,
  `…updated`, `…deleted`, `…members_set`, `…refreshed`; `RefreshSegments` the system's, nightly at
  01:00 through `Infrastructure/Queue/RefreshSegmentsJob`, one segment's failure logged and the rest
  refreshed). The domain's **`SegmentTerms`** checks a segment as the database does; a segment's type
  and audience never change (answer 23). **`Segment/SegmentMembership`** works out who a segment
  holds: `NO_ORDERS_EVER` from Access's registered customers less those with an order fact there,
  `NO_ORDER_FOR` and `AT_LEAST_ORDERS` from the store's order facts — every placed order counts — each
  kept to live customers of the audience through `AccessApi::customers`, in pages of a thousand; a
  hand-picked segment keeps its picks but loses the anonymized; a pick must be of the audience, not
  anonymized, and registered in the store or have ordered there. `DatabaseSegments::replaceMembers`
  rewrites the members as a set — staying members keep when they were added.
- **Assignments** (`Application/Command/AssignCoupon`, `RemoveAssignment`, under
  `promotions.coupon.manage`, audited `promotions.coupon.assigned` — the segment and how many new —
  and `…assignment_removed`): the coupon's row locked, then the segment's; only an `ASSIGNED` coupon
  (answer 24), a segment of its own store, an audience it allows; the stored members of that moment
  written through `Domain/Repository/Assignments` (`insertOrIgnore`, in chunks — those who have it keep
  their row, a customer who leaves the segment keeps the coupon). A used assignment is never removed
  (`CouponInUse`). Changing a coupon refuses leaving out the audience of a segment it was assigned
  from (§1.1). `PromotionsApi::segmentMembers` pages a segment's members by id, 1 to 1000.
- **`Infrastructure/Eloquent/CouponRulesUsage`** — registered with Catalog's `CatalogUsages`: every
  coupon whose include or exclude list names a product, category or brand Catalog is about to delete,
  in one query; Catalog then refuses the delete (`catalog.item_in_use`). Variants answer nothing until
  gift levels (step 5).

Tests: `tests/Modules/Promotions/Unit/CouponRulesTest` (the rules and the room, every number of §8);
`tests/Modules/Promotions/Integration` — `AssignmentsTest`, `SegmentsTest`, `SegmentTablesTest`, `CouponManageTest` (staff's commands), `CouponRulesUsageTest`, `ApplyCouponTest`, `CouponWritesTest`, `CouponRaceTest` (two
orders at once on the last use; outside RefreshDatabase, skipped under --parallel), `CouponTablesTest`,
`PromotionsPermissionsTest`, `PromotionsSchemaTest`, `PromotionsErrorMessagesTest`,
`PromotionsMenuSectionTest`.
