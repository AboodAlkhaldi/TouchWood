# Promotions — Module Specification

**Status:** **ACCEPTED by the owner, 2026-10-10** (merged as #101) — sections 1–9 written 2026-10-08
from the owner's answers of 2026-10-07 to 2026-10-10 (§9.1), with the changes Sales's spec needed. The
picks marked "my assumption" were accepted with it.
**Tier:** 2. **Stage:** 6 (handoff §17), the second of the stage's modules to be built (Loyalty →
Promotions → Feedback → Sales).
**Depends on:** Platform, Access, Catalog, Pricing (handoff §4.4; `deptrac.yaml` already says so).
**Never Sales:** Sales passes Promotions the cart and the order facts it needs (handoff §4.4).
**Source:** `docs/HANDOFF.md` §4.2, §4.4, §6, §10.2, §11.1–§11.4, §11.6, §14, §15.2, §16, and its
2026-10-07 amendments (wholesale for companies only, the lowest price wins); `docs/modules/pricing.md`
§2 (the prices Promotions reads); the owner's answers (§9.1); the merged code as of `0fb9de3`.

Promotions owns **coupons**, **customer segments** and **gifts**, and **the store's discount ceiling**.
It answers what a coupon takes off a cart and whether a customer-applied discount stays inside the
ceiling; it records each coupon use and each placed order's fact, inside Sales's checkout.

## What this module does not own

| Concern | Owner |
|---|---|
| Prices, sales, campaign prices, category discounts, wholesale bands — every price **without a code** | Pricing (the lowest applicable price wins, nothing stacks, except a sale or discount marked "always wins while on" — handoff §10.1, amended 2026-10-07 and 2026-10-09) |
| Campaigns' themes, pop-up offers, alert banners, the smart bar | Content (stage 8) |
| Free shipping above an amount, per store per carrier | Shipping (stage 7; handoff §12.5) |
| Points | Loyalty |
| The cart, checkout, orders, cancellations and returns; whether a gift is in stock and holding it | Sales (with Inventory's `availableAsGift` and `hold`) |
| Telling a customer about an assigned coupon | Ops (stage 8) — owner, 2026-10-08: the account centre shows it now |
| **Bundles / kits** | **Nobody yet** — owner, 2026-10-08: its own job after stage 6 (handoff §15.2) |

**"Automatic promotions" (handoff §4.2) are the gift levels alone** (owner, 2026-10-08: "go with 1 …
make sure thats all of it"). Checked against the handoff: every other offer has an owner above —
campaigns, flash sales and "15 SAR offers" are Content's themes with Pricing's campaign prices; pop-ups,
alert banners and the smart bar are Content's; sales, category and quantity prices are Pricing's; free
shipping is Shipping's. Only bundles have none, and wait (§9.2).

---

## 1 · Aggregates and invariants

### 1.1 The coupon

A coupon belongs to **one store** — the same person can use a coupon in KSA and not in Egypt (handoff
§11.1). Each carries:

- **A code**: what the customer types — 3 to 30 Latin letters, digits and hyphens, read without
  regard to case (`summer-15` is `SUMMER-15`), Arabic digits read as 0-9 (the system's rule since
  2026-10-06, frontend.md §1.8). **Unique among the store's coupons, and never changed** once the
  coupon exists — so a code always names one coupon. My assumption, stated for the owner to reject.
- **A name** in Arabic and English, for staff and the account centre.
- **Who may use it** (`eligibility`): `PUBLIC` — anyone who types it; `ASSIGNED` — only the customers
  it was assigned to (§1.4).
- **What it takes off** (owner, 2026-10-08): **a percentage** of the eligible lines, 1 to 100, with an
  **optional cap** in the store's currency ("15% off, up to 100 SAR"); or **a fixed amount** off the
  eligible lines, never more than their total.
- **When**: from `starts_at` until `ends_at` (none: until it is deactivated).
- **How often**: `max_uses_per_customer` and, optionally, **`max_uses_total`** (owner, 2026-10-08) —
  empty means unlimited. **Whichever is reached first ends it** for that customer or for everyone
  (handoff §11.1). Lowering `max_uses_total` below the uses already made is refused; setting it to
  exactly them ends the coupon.
- **A minimum**, optional: the order's `net_subtotal` must reach it (handoff §10.2: the coupon minimum
  binds `net_subtotal`).
- **`allow_with_points`** — whether the order may also use points (handoff §11.1, per coupon).
- **On reduced lines** — off by default: **a coupon skips a line already reduced** (a sale, campaign,
  category discount — a line whose `unit` is below its `listUnit`); the admin may switch it on per
  coupon (owner, 2026-10-08). **A wholesale band's price is a wholesale line's normal price, not a
  reduction** (owner, 2026-10-10): Pricing makes it the line's `listUnit`, so only a sale or discount
  below the band counts as reduced.
- **What it covers** — the rules of §1.2.
- **Active**, a switch: a deactivated coupon cannot be used and can be switched on again.

**Changing a coupon** changes it for every later use; a placed order keeps the discount it had (Sales
snapshots it) — at placement, Promotions checks the coupon again as it is then (§2.1). **A coupon used
once is never deleted** — deactivated instead; one never used may be deleted, its assignments with it
in the same transaction. My assumption, stated for the owner to reject.

**`COMPANY`**, for a coupon or a gift, means **a company account approved in this store** — the only
company that can order there (handoff §6, §8.2; loyalty.md §1). Sales says which audience the buyer is.
Combinations that could never apply are refused when saved: a coupon for `PUBLIC` buyers only that
covers wholesale lines only (only companies buy wholesale, handoff §6 amended 2026-10-07), and an
`INDIVIDUAL` or `COMPANY` segment assigned to a coupon that excludes that audience.

### 1.2 Which lines a coupon covers

**One coupon per order** (handoff §11.1, §16). **It lands only on the lines it is eligible for**; a cart
mixing eligible and other lines is never refused for that (handoff §11.1, §16). A line is eligible when:

1. **Its product is included** — by the product itself, its category **or any category above it**, or
   its brand; a coupon with no include list covers every product;
2. **and not excluded** the same ways — **exclusions win** (owner, 2026-10-08: include and exclude
   lists): "all of Kitchens except brand X";
3. **its sale mode** (`RETAIL`, `WHOLESALE`) is one the coupon allows — both by default;
4. **the buyer's audience** (`PUBLIC`, `COMPANY`) is one the coupon allows — both by default
   (handoff §6). Since 2026-10-07 only a company buys wholesale lines (handoff §6, amended) — Sales
   refuses them to anyone else, so a coupon never sees one;
5. **it is not already reduced**, unless the coupon allows reduced lines (§1.1).

A coupon's lists name **products that are ready, active categories and brands**; a gift names **a
variant that is not archived, of a ready product**. While a coupon rule or a gift level uses a
product, variant, category or brand, **Catalog refuses to delete it** and says what uses it — through
a usage check Promotions registers with Catalog, as modules register theirs for media with Platform
(`MediaUsages`). A rule is never dropped silently: dropping an exclusion would widen what a coupon
covers. (A Catalog addition, §2.3.)

### 1.3 What a coupon takes off

In the store currency's smallest unit (handoff §5.1), on the eligible lines' `total` (Pricing's
`LinePriceDto::$total`, without VAT):

- **A percentage**: `eligible total × percent ÷ 100`, **rounded half up** to the smallest unit, as
  prices are (owner, 2026-10-10), then at most the cap.
- **A fixed amount**: the amount, at most the eligible total.
- **Spread over the eligible lines** in proportion to their totals with `Money::allocate` — no unit
  lost (handoff §5.1) — so each line knows its share for returns (Sales).

**Refused whole, never trimmed** (handoff §11.3; owner, 2026-10-08: coupons must not "break our safe
area"), with a message the checkout shows, when:

- it is not active, not started, ended, used up for everyone or for this customer, or not assigned to
  this customer;
- the order's `net_subtotal` is under its minimum;
- no line is eligible;
- it is worth nothing (a percentage of a small total that rounds to 0);
- the order also uses points and the coupon does not allow it (`allow_with_points`) — at checkout
  the coupon is decided first, so a coupon that does not allow points means the order uses none
  (Sales skips the points and says why);
- **it would pass the store's discount ceiling** (§1.6).

**After a staff edit — the one exception** (sales.md §1.7; owner, 2026-10-10: the coupon is "checked
again; trimmed or removed if [it] no longer fit[s]", and it "can only stay or shrink"). The order's
coupon is worked out again on the edited lines, by the same coupon as it is now — **ignoring its dates,
its switch, its uses and the assignment**, which were settled at placement and whose use already
counts:

- **never more than it is now** — the use's recorded discount, the last edit's after an earlier edit
  (`updateCouponUse` only ever lowers it);
- **removed** (0) if the edited order is under its minimum or no line is eligible any more;
- **trimmed to the room** under **the ceiling the order was placed under** (§1.6; owner, 2026-10-10:
  an edit keeps the order's own rules) — after the points have given way first (handoff
  §11.3: "points are evaluated last and refused first"): Sales lowers the points to fit what is left,
  and only if the coupon alone passes the room is the coupon trimmed to it.

The use stays counted, with its new discount recorded (`updateCouponUse`).

**A guest** has no account and no orders; whether a guest may try a code in the cart is Sales's
question (their cart, handoff §7.5), asked with its spec. Using a coupon always needs an account.

### 1.4 Assigned coupons and segments

An `ASSIGNED` coupon is used only by the customers it was assigned to (handoff §11.2):

- **Assignment is by segment** (§1.5): assigning **writes the segment's members of that moment** as
  the coupon's customers, and **they stay** — a customer who leaves the segment later keeps the coupon
  (handoff §11.2: "a customer in a 'hasn't ordered in 6 months' segment" must not lose it by ordering).
  Assigning again adds the members who are new; nobody is removed.
- **The customer sees it in their account centre** at once (owner, 2026-10-08); an email or SMS about
  it comes with Ops (stage 8).
- My assumption, stated for the owner to reject: an assignment not yet used may be removed by staff.

### 1.5 Customer segments

**Per store** (owner, 2026-10-08): a segment belongs to one store and counts that store's orders. It
has a name in both languages, an **audience** — `INDIVIDUAL` or `COMPANY` (the account's type in
Access) — and is one of two types (handoff §11.2):

- **`STATIC`** — customers picked by hand;
- **`DYNAMIC`** — one rule:

| Rule | Who |
|---|---|
| `NO_ORDERS_EVER` | Never ordered in the store — only customers **registered** in it |
| `NO_ORDER_FOR` *M* months | Their last order in the store is more than *M* months old ("no order in 6 months", "in a year"). Someone who never ordered there is not in it — that is `NO_ORDERS_EVER` |
| `AT_LEAST_ORDERS` *N* in *M* months | *N* or more orders in the store in the last *M* months ("frequent buyers"). **"Monthly buyers" are *N* = *M*** — at least one order a month on average (owner, 2026-10-08) |

- **Who can be in a store's segment** (owner, 2026-10-08): customers **registered in the store, or
  who placed an order in it**. **Staff see such a member's name, as on that store's orders**, and may
  pick them by hand — nothing else of their account (owner, 2026-10-09). This widens Access's rule that
  staff see only their own stores' customers (access.md amendment 44(c)) for exactly this: a customer's
  name, to the staff of a store they ordered in — an Access addition (§2.3).
- **Every placed order counts, whatever happened to it** — cancelled, returned, delivered (owner,
  2026-10-08). Sales tells Promotions of each order when it is placed (§2).
- **Members are kept as a list** — refreshed **nightly** and **on demand** by staff (handoff §11.2).
- Anonymized customers (access.md §1.10) leave every segment at the next refresh, a hand-picked one
  included. My assumption, stated for the owner to reject.
- **Segments are reusable** (handoff §11.2) — not coupons only: newsletters and notifications (Ops,
  which may read every module) read their members later. Pop-up targeting is Content's, and **Content
  may not read Promotions** today (handoff §4.4: Content → Platform, Catalog, Pricing) — the owner's
  call when Content is specified (§9.2).

### 1.6 The store's discount ceiling

**`max_discount_percent`** (handoff §11.3): what a customer may take off an order with a coupon and
points together, measured against `gross_subtotal` — the full reduction from list price. **Promotions
owns the number; Sales applies the check** with the room Promotions gives it.

| Key | Scope | Type | Range | Default | Changed under |
|---|---|---|---|---|---|
| `promotions.ceiling.max_discount_percent` | Store | Integer | 0–100 | **30** (owner, 2026-10-08: "let it for now to be 30%") | `promotions.settings.update` — **admin-only** (§3) |

- **It only ever refuses customer-applied discounts.** An order whose merchant prices alone pass it
  sells normally; the ceiling simply means no coupon or points can stack on top (handoff §11.3).
- **The room left for a customer**: `floor(gross_subtotal × max_discount_percent ÷ 100)` minus
  `gross_subtotal − net_subtotal` (what prices already took), never below 0.
- **The order of evaluation is fixed** (handoff §11.3): the coupon first — it must fit the room, or it
  is refused; then points, which must fit what is left, or the whole redemption is refused and the
  points stay unused (Loyalty §1.7). Sales asks Promotions for the room; Promotions owns the number.
- **An edit keeps the order's ceiling** (owner, 2026-10-10: an edit keeps the rules the order was
  placed under, so an address changed never trims a coupon): `recordOrder` records the ceiling of the
  moment, and `orderDiscountRoom` works an edited order's room with it, by the same formula.

### 1.7 Gifts — the automatic promotion

**Gift levels**, per store (handoff §11.6; owner, 2026-10-08): "over 1,000 SAR a pen, over 2,000 a
mug". Each level has:

- **an amount** in the store's currency, measured against **`goods_total`** (handoff §10.2: gift
  eligibility binds `goods_total` — after coupon and points);
- **its gift**: one Catalog variant, one piece;
- **who gets it**: individuals, companies or both;
- **when**: optional dates, and an active switch.

**An order gets one gift — the highest level it reaches.** If that gift is out of stock, the next level
down; none left, no gift, and checkout says so (owner, 2026-10-08). Promotions answers which levels the
order reaches, highest first; **Sales asks Inventory which is in stock** (`availableAsGift` — a gift
counts on stock in every store, handoff §12.1) and holds it. **A gift is kept after a partial return**
that takes the order below its level (owner, 2026-10-08).

My assumption, stated for the owner to reject: two active levels of one store with the same amount,
for overlapping audiences and dates, are refused — one amount, one gift. Saving a level locks the
store's levels first, so two saved at once cannot both pass that check.

Gift levels sit in the admin menu's **Marketing** section beside Coupons and Segments (handoff §14
lists only those two; gifts are Promotions' too). My assumption, stated for the owner to reject.

### 1.8 Each store's rows, and a store switched off

Coupons, segments, gift levels and order facts are store-scoped (handoff §4.1). As Loyalty and
Catalog: one repository per kind names the store in every call; no Eloquent model; the nightly
refresh is the one pass across stores. Every row that points at another carries the same store, held
by composite keys (§5), so nothing of one store reaches another's.

**A store switched off** has its coupons, segments and gift levels set by Super Admins only — the
panel offers off stores to Super Admins alone (platform.md §9.10) — as its prices and stock are
(pricing.md §1.1 rule 8, inventory.md §1.1 rule 6). My assumption, stated for the owner to reject.

---

## 2 · Public contract

### 2.1 `Modules\Promotions\Public\Contracts\PromotionsApi`

Ids and values in, DTOs out (handoff §4.3). The three writes are Sales's — `useCoupon` and
`recordOrder` made **inside Sales's checkout transaction** (handoff §4.4: "so a one-time coupon can never
be used twice"), `updateCouponUse` inside its edit's — and name **the Sales
permission that allowed the change**; Promotions builds the scope itself — as Loyalty (loyalty.md
§2.1) — from **the coupon's own store** for `useCoupon`, and refuses a store passed in that differs.

| Method | For |
|---|---|
| `applyCoupon(CouponRequest $request): CouponResult` | Sales's cart and quote: the coupon's discount, line by line, or the reason it is refused (§1.3). Reads only |
| `customerDiscountRoom(StoreId $store, Money $grossSubtotal, Money $netSubtotal): Money` | Sales: the room left under the ceiling (§1.6), against which it checks the coupon, then the points |
| `giftLevels(StoreId $store, Audience $audience, Money $goodsTotal): list<GiftOfferDto>` | Sales: the levels the order reaches, highest first (§1.7) |
| `useCoupon(UseCoupon $change): void` | Placing the order: **every check of §1.3 again, as the coupon is now** — dates, switch, uses for everyone and for this customer, the assignment, the minimum, the rules, the points, the ceiling — under a lock on the coupon so two orders never take its last use; **recomputes the discount and refuses (`CouponChanged`) if it is not the one quoted**, so checkout quotes again; then records the use. The same order with the same coupon again does nothing; the same order with another coupon is refused. **A use never comes back**, not even when the order is cancelled (owner, 2026-10-08) |
| `orderDiscountRoom(string $orderId, Money $grossSubtotal, Money $netSubtotal): Money` | Sales, working out a staff edit: the room under the ceiling **recorded with the order** (§1.6) |
| `couponAfterEdit(EditedCouponRequest $request): CouponResult` | Sales, after a staff edit (§1.3, the exception): the coupon's discount on the edited lines, never more than it is now, 0 if it no longer applies. Reads only |
| `updateCouponUse(UpdateCouponUse $change): void` | Sales, after a staff edit: records the use's new discount (never higher). Idempotent by the edit's id |
| `recordOrder(RecordOrder $change): void` | Placing the order, with or without a coupon: the fact segments count (§1.5). The same order again does nothing |
| `segmentMembers(string $segmentId, ?string $after, int $limit): list<string>` | Ops later: a segment's customer ids, keyset-paged (handoff §5.4) |

### 2.2 DTOs and enums (`Public/Dto`, `Public/Enums`)

- `CouponRequest`: store, the code as typed, customer id, `Audience`, and Pricing's `CartPricesDto`
  for the cart (its lines' `kind` and `total`, its `grossSubtotal` and `netSubtotal`).
- `CouponResult`: the coupon's id and names, the total discount, **one share per line of the prices
  passed, in their order** (0 for a line not eligible) — an edited order may hold one variant and mode
  in several parts (pricing.md §1.11), so a share follows its line's place, not its variant and mode —
  `allowWithPoints`; or a `CouponRefusal`.
- `CouponRefusal`: `UNKNOWN_CODE`, `INACTIVE`, `NOT_STARTED`, `ENDED`, `USED_UP`,
  `USED_UP_FOR_YOU`, `NOT_ASSIGNED_TO_YOU`, `BELOW_MINIMUM`, `NOTHING_ELIGIBLE`, `WORTH_NOTHING`,
  `NOT_WITH_POINTS`, `PASSES_CEILING`.
- `UseCoupon`: the order's id and public number, the coupon's id, the `CouponRequest` it was quoted
  for (the cart's prices, customer, audience), the quoted discount, whether points are used, and the
  caller's permission.
- `EditedCouponRequest`: the order's id, the coupon's id, the edited order's prices (Pricing's, over
  its kept and added parts — `pricesForEdit`, pricing.md §1.11), the audience, **the whole room under
  the ceiling** (`orderDiscountRoom` for the edited prices, with the order's own ceiling — the coupon is
  trimmed to it only when it alone passes it; Sales then lowers the points into what is left, sales.md
  §1.7), and the coupon's discount now (the most it may be).
- `UpdateCouponUse`: the order's id, the edit's id, the new discount, the caller's permission.
- `RecordOrder`: the order's id, customer, store, the moment placed, the caller's permission; Promotions
  adds the store's ceiling of that moment. (Not
  named `OrderPlaced`: that is Sales's integration event, handoff §4.5.)
- `GiftOfferDto`: the level's id, amount, the gift's variant id.
- `Audience`: `PUBLIC`, `COMPANY` — Promotions' own, as Loyalty's (loyalty.md §9.2: one Shared enum
  is the owner's call if a third module needs it).
- `SegmentType`, `SegmentAudience`, `SegmentRule`, `CouponEligibility`, `DiscountKind`.

### 2.3 What Promotions needs from other modules

| From | What | State |
|---|---|---|
| Pricing | The cart's prices: each line's `kind` and `total`, `grossSubtotal`, `netSubtotal` | Exists (`PricingApi::prices`, `CartPricesDto`, on `main` since #97) — Sales passes them in |
| Catalog | **For each cart line: its product, brand and every category above the product's** — one call per cart | **A Catalog addition, built with Promotions**: `CatalogApi::lineFacts(list $variantIds)`. Today `ProductDto` gives the product's own category only; "Kitchens" must cover its sub-categories. Catalog's amendment, numbered when it lands |
| Catalog | Products, categories, brands and variants exist and are ready / active, for a coupon's lists and a gift | Exists (`CatalogApi::product`, `variant`); categories and brands by the same addition |
| Catalog | **Refusing to delete what a coupon rule or a gift level uses** (§1.2) | **A Catalog addition, built with Promotions**: a usage check modules register, as Platform's `MediaUsages` — Catalog's deletes of a category, brand, draft product or draft variant ask it first and refuse with what uses it |
| Access | **The customers a segment can hold, in bulk**: those registered in a store, of one account type, not anonymized, keyset-paged; and a batch read of many customers by id (type, home store, name, anonymized) | **An Access addition, built with Promotions.** Today `AccessApi::customer` reads one customer by id and nothing lists customers; reading thousands one at a time is the N+1 handoff §5.4 forbids |
| Access | **A store's staff see the name of a customer who ordered in that store** (owner, 2026-10-09, for segments) — beyond today's "only their stores' customers" (access.md amendment 44(c)) | **An Access addition**, the name only, numbered when it lands |
| Access | The permission catalog, and **a `Marketing` permission group** — the admin menu's "Marketing" section (handoff §14: Coupons · Segments) | Catalog exists; **the group is an Access addition** (and `marketing` in Platform's menu groups), built with Promotions — the same as Loyalty's Points section (loyalty.md §2.3). My assumption, following the owner's answer for Points |
| Platform | A store setting (the ceiling), the audit log, the store's currency | Exists |

### 2.4 What Promotions gives others

`PromotionsApi` above — to Sales for checkout, to Ops for segments later — and the account centre's
read of a customer's coupons (§3).

---

## 3 · Use cases

Every command and query handler authorizes first (`CommandHandlersAuthorizeTest`). Changes by staff are
audited (`promotions.coupon.created`, `…updated`, `…deactivated`, `…activated`, `…deleted`,
`…assigned`, `…assignment_removed`; `promotions.segment.…`; `promotions.gift_level.…`).

| Use case | Permission |
|---|---|
| Create, change, deactivate, activate, delete a coupon; its include and exclude lists | `promotions.coupon.manage` (per store) |
| Assign a coupon to a segment; remove an unused assignment | `promotions.coupon.manage` |
| Create, change, delete a segment; pick a static segment's customers; refresh it now | `promotions.segment.manage` (per store) |
| Create, change, switch off a gift level | `promotions.gift.manage` (per store) |
| Change the store's discount ceiling | `promotions.settings.update` (per store, **admin-only**) — Platform's `UpdateSetting` |
| Refresh every segment, nightly | `promotions.segment.refresh` (reserved, global: the system only) |
| `useCoupon`, `recordOrder` | **The caller's** — Sales's placing an order — in the coupon's store (`useCoupon`), the order's store (`recordOrder`) |
| `updateCouponUse` | The caller's — Sales's editing an order — in the coupon's store |
| `applyCoupon`, `couponAfterEdit`, `customerDiscountRoom`, `orderDiscountRoom`, `giftLevels`, `segmentMembers` | **None of their own**: reads for modules inside flows they have authorized |

| Read (query) | Permission |
|---|---|
| A store's coupons (with their uses — each by the order's **public number**, never its id, handoff §5.3), segments (with their members' names, §1.5) and gift levels | `promotions.marketing.view` (per store) — or any of the three manage permissions |
| **My coupons** in a store, in the account centre: those assigned to me and still usable | `promotions.coupon.view_own` — every customer, their own only |

**The permissions Promotions declares** — every name has three parts, as Access requires
(`InMemoryPermissionCatalog::NAME`):

| Permission | Audience | Kind | Group | Admin-only |
|---|---|---|---|---|
| `promotions.marketing.view` | Role | per store | `Marketing` | no |
| `promotions.coupon.manage` | Role | per store | `Marketing` | no |
| `promotions.segment.manage` | Role | per store | `Marketing` | no |
| `promotions.gift.manage` | Role | per store | `Marketing` | no |
| `promotions.settings.update` | Role | per store | `Marketing` | **yes** |
| `promotions.coupon.view_own` | every customer | global | — | — |
| `promotions.segment.refresh` | Role, reserved | global | — | — |

**The ceiling is admin-only; coupons, segments and gifts are ordinary permissions** an admin may give
to a marketing staff role (owner, 2026-10-08).

---

## 4 · State machines

**A coupon** — derived from its dates, uses and switch, never stored as a state:

```
before starts_at ─▶ SCHEDULED ─▶ ACTIVE ─▶ ENDED   (ends_at passed, or max_uses_total reached)
ACTIVE or SCHEDULED ─(switched off)─▶ INACTIVE ─(switched on)─▶ back to what its dates and uses say
```

**An assignment:** `ASSIGNED` ─▶ used up for that customer (their uses reach the limit). Removed only
while unused.

**A segment's members:** rewritten at each refresh for a `DYNAMIC` segment; changed by hand for a
`STATIC` one; anonymized customers dropped at each refresh.

**A gift level:** active within its dates while switched on.

---

## 5 · Tables

Schema `promotions`. ULID ids, except high-volume facts (`bigint` identity — handoff §5.3). Every
column NOT NULL unless marked NULL; amounts in the smallest unit (`bigint`) with the store's
`currency_code`; every rule also checked in code first (handoff §5.3). **Every CHECK touching a
nullable column says `IS NOT NULL` where a value is needed** — PostgreSQL passes a CHECK that comes out
NULL (lessons 35, 162) — and **every kind column has its own CHECK `IN (…)`** of its values. Every
`store_id` → `platform.stores` RESTRICT; a row pointing at another of the same store does so through a
composite key on (`id`, `store_id`), so one store's rows never reach another's.

| Table | Columns | Rules |
|---|---|---|
| `promotions.coupons` | `id`, `store_id`, `code` (stored upper case), `name_ar`, `name_en`, `eligibility`, `discount_kind`, `percent` NULL, `cap_minor` NULL, `amount_minor` NULL, `currency_code`, `minimum_minor` NULL, `starts_at`, `ends_at` NULL, `max_uses_per_customer` NULL, `max_uses_total` NULL, `uses_total`, `allow_with_points`, `on_reduced_lines`, `for_retail`, `for_wholesale`, `for_public`, `for_company`, `active`, `created_at`, `updated_at` | UNIQUE (`store_id`, `code`); UNIQUE (`id`, `store_id`); CHECK `eligibility IN ('PUBLIC', 'ASSIGNED')`, `discount_kind IN ('PERCENT', 'FIXED')`; CHECK `code ~ '^[A-Z0-9-]{3,30}$'`; CHECK `(discount_kind = 'PERCENT' AND percent IS NOT NULL AND percent BETWEEN 1 AND 100 AND amount_minor IS NULL) OR (discount_kind = 'FIXED' AND amount_minor IS NOT NULL AND amount_minor > 0 AND percent IS NULL AND cap_minor IS NULL)`; CHECK `cap_minor IS NULL OR cap_minor > 0`, `minimum_minor IS NULL OR minimum_minor > 0`; CHECK `ends_at IS NULL OR ends_at > starts_at`; CHECK `max_uses_per_customer IS NULL OR max_uses_per_customer > 0`, the same for `max_uses_total`; CHECK `uses_total >= 0 AND (max_uses_total IS NULL OR uses_total <= max_uses_total)`; CHECK `for_retail OR for_wholesale`, `for_public OR for_company` |
| `promotions.coupon_rules` | `id`, `coupon_id` → coupons CASCADE, `kind`, `product_id` NULL → `catalog.products` RESTRICT, `category_id` NULL → `catalog.categories` RESTRICT, `brand_id` NULL → `catalog.brands` RESTRICT | CHECK `kind IN ('INCLUDE', 'EXCLUDE')`; CHECK exactly one of the three is NOT NULL (`num_nonnulls(product_id, category_id, brand_id) = 1`); UNIQUE (`coupon_id`, `kind`, `product_id`, `category_id`, `brand_id`) NULLS NOT DISTINCT. The Catalog rows are global (slugs and products are, handoff §4.1) |
| `promotions.coupon_assignments` | `coupon_id`, `store_id`, `customer_id` → `access.customers` RESTRICT, `segment_id` NULL, `assigned_at` | PK (`coupon_id`, `customer_id`); (`coupon_id`, `store_id`) → coupons (`id`, `store_id`) CASCADE (an unused coupon's delete takes them, §1.1); (`segment_id`, `store_id`) → segments (`id`, `store_id`) SET NULL (`segment_id`) — a segment of the same store only |
| `promotions.coupon_uses` | `id` bigint identity, `coupon_id`, `store_id`, `customer_id` → `access.customers` RESTRICT, `order_id` (Sales's id, text — no foreign key: Promotions never depends on Sales), `order_number` (its public number), `discount_minor`, `last_edit_id` NULL, `used_at` | (`coupon_id`, `store_id`) → coupons (`id`, `store_id`) RESTRICT; UNIQUE (`order_id`) — one coupon per order; CHECK `discount_minor >= 0` (0 once an edit removed it — the use still counts); never deleted (a use never comes back); the discount only ever lowered, by a staff edit (`last_edit_id`, §1.3) |
| `promotions.order_facts` | `order_id` PK (text), `store_id`, `customer_id` → `access.customers` RESTRICT, `placed_at`, `ceiling_percent` (the ceiling it was placed under, §1.6) | CHECK `ceiling_percent BETWEEN 0 AND 100`. Never changed: a placed order counts whatever happens to it |
| `promotions.segments` | `id`, `store_id`, `name_ar`, `name_en`, `type`, `audience`, `rule` NULL, `rule_months` NULL, `rule_orders` NULL, `refreshed_at` NULL, `created_at`, `updated_at` | UNIQUE (`id`, `store_id`); CHECK `type IN ('STATIC', 'DYNAMIC')`, `audience IN ('INDIVIDUAL', 'COMPANY')`, `rule IS NULL OR rule IN ('NO_ORDERS_EVER', 'NO_ORDER_FOR', 'AT_LEAST_ORDERS')`; CHECK `(type = 'STATIC' AND rule IS NULL AND rule_months IS NULL AND rule_orders IS NULL) OR (type = 'DYNAMIC' AND rule IS NOT NULL)`; CHECK `rule IS DISTINCT FROM 'NO_ORDERS_EVER' OR (rule_months IS NULL AND rule_orders IS NULL)`; CHECK `rule IS DISTINCT FROM 'NO_ORDER_FOR' OR (rule_months IS NOT NULL AND rule_months BETWEEN 1 AND 120 AND rule_orders IS NULL)`; CHECK `rule IS DISTINCT FROM 'AT_LEAST_ORDERS' OR (rule_months IS NOT NULL AND rule_months BETWEEN 1 AND 120 AND rule_orders IS NOT NULL AND rule_orders BETWEEN 1 AND 1000)` |
| `promotions.segment_members` | `segment_id` → segments CASCADE, `customer_id` → `access.customers` RESTRICT, `added_at` | PK (`segment_id`, `customer_id`) |
| `promotions.gift_levels` | `id`, `store_id`, `amount_minor`, `currency_code`, `variant_id` → `catalog.variants` RESTRICT, `for_public`, `for_company`, `starts_at` NULL, `ends_at` NULL, `active`, `created_at`, `updated_at` | CHECK `amount_minor > 0`; CHECK `for_public OR for_company`; CHECK `ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at` |

**Indexes:** coupons by (`store_id`, `code`) (the UNIQUE); coupon uses by (`coupon_id`, `customer_id`)
— uses per customer; order facts by (`store_id`, `customer_id`, `placed_at`) — the segment rules;
assignments by `customer_id` — "my coupons"; segment members by `customer_id`; gift levels by
(`store_id`, `amount_minor` DESC).

---

## 6 · Events

**Published:** none in this stage. Ops adds "a coupon was assigned to you" when it writes to customers
(owner, 2026-10-08: the account centre now, messages with Ops).

**Consumed:** none. Anonymized customers leave segments at the next refresh (§1.5); their uses and
assignments keep only their id.

The `processed_events` and outbox tables are not needed: Sales calls Promotions synchronously inside
its checkout transaction, and both writes are idempotent by the order's id — checked in code before
the insert (the same order and coupon again does nothing; another coupon for the order is refused),
with the unique indexes as the backstop.

---

## 7 · Errors

Each extends `PromotionsError`, which extends `Shared\Domain\Error\DomainError`.

| Error | Status | When |
|---|---|---|
| `CouponRefused` | INVALID | `useCoupon` finds the coupon no longer usable — the same reasons as `CouponRefusal` — or the order already used another coupon |
| `CouponChanged` | CONFLICT | `useCoupon` works the discount out again and it is not the one quoted (the coupon was changed meanwhile) — checkout quotes again |
| `CodeTaken` | CONFLICT | A new coupon's code is another coupon's in the store |
| `CouponInUse` | CONFLICT | Deleting a coupon already used; removing an assignment already used; lowering `max_uses_total` below the uses made |
| `GiftLevelClash` | CONFLICT | Two active levels with the same amount, for overlapping audiences and dates (§1.7) |
| `CouponNotFound`, `SegmentNotFound`, `GiftLevelNotFound` | NOT_FOUND | Unknown, or in a store the staff member does not cover — the same answer for both |
| `InvalidPromotionAttribute` | INVALID | A value refused: a code's characters or length, a percentage, an amount in another currency, dates in the wrong order, a product that is not ready, an inactive category or brand, an archived gift variant, a rule's numbers, a combination that can never apply (§1.1), a store that is not the coupon's or the order's, a caller's permission not its own module's |

---

## 8 · Test scenarios

**Coupons — what they take off**
- 15% of the eligible lines, capped at 100 SAR; 50 SAR fixed on eligible lines worth 30 SAR takes 30.
- The discount spread over the lines adds up exactly, no halala lost.
- A mixed cart: the coupon lands on the eligible lines and leaves the rest, never refusing the cart.
- Include by category reaches its sub-categories; an excluded brand inside an included category is
  skipped — exclusions win.
- Reduced lines (`unit` below `listUnit`) skipped by default, covered when the coupon allows it; a
  wholesale line at its band price is not reduced.
- 15% of 10.03 SAR is 1.50; 15% of 10.10 SAR (1.515) is 1.52 — half up.
- Sale mode and audience lists respected; a company's wholesale line covered only by a coupon that
  allows wholesale.

**Coupons — refused whole**
- Unknown code; inactive; before its start; after its end; used up overall; used up for this customer;
  an `ASSIGNED` coupon by a customer it was not assigned to; under the minimum on `net_subtotal`;
  nothing eligible; worth nothing (1% of 50 halalas); with points when it does not allow them.
- **Passing the ceiling**: a coupon that fits alone but not with the prices' own reductions is
  refused, never trimmed; a 70%-off clearance line under a 30% ceiling sells, with no coupon on top.
- The code typed in lower case or with Arabic digits finds the coupon.

**Coupon uses**
- Two orders at once take the last of `max_uses_total`: one succeeds, the other is refused.
- A use is never given back — the order cancelled, the coupon still counts as used.
- At placement, every check again: the assignment removed, the percentage or rules changed, the
  ceiling lowered, `allow_with_points` switched off since the quote — `CouponRefused` or
  `CouponChanged`, never the old discount.
- The same order and coupon twice: one use; the same order with another coupon: refused — in code,
  and by the database as a backstop.
- After a staff edit: the coupon worked out on the edited lines, never above its discount now (a second
  edit after one that trimmed it stays at the trimmed amount or below); the room by the order's own
  ceiling though the store's changed since; removed under the minimum; trimmed to the room only after the points gave way; its dates, switch, uses and
  assignment not asked again; the same edit recorded once.
- `max_uses_total` lowered below the uses made: refused; set to them: the coupon ends.
- Deleting an unused coupon takes its assignments; a used one cannot be deleted.

**Assignments and segments**
- Assigning writes the members of that moment; a member who orders later and leaves the segment
  keeps the coupon; assigning again adds new members only.
- Each rule: `NO_ORDERS_EVER` holds only customers registered in the store; `NO_ORDER_FOR` 6;
  `AT_LEAST_ORDERS` 5 in 12; "monthly buyers" 6 in 6.
- A cancelled order still counts; a customer registered elsewhere who ordered here can be a member,
  shown to this store's staff by name only; a customer who never ordered is not in `NO_ORDER_FOR`.
- The nightly refresh and the refresh on demand give the same members; anonymized customers drop out.
- A refresh of thousands of customers reads them in pages and batches, never one by one (a query
  count test, handoff §5.4).
- An assignment can only take a segment of the coupon's own store; an `INDIVIDUAL` segment cannot be
  assigned to a coupon for companies only.

**The ceiling**
- 30% when a store opens; only an admin role may change it.
- The room: prices already at 20% off leave 10% for coupon and points; at 35% off, none.

**Gifts**
- An order over 2,000 gets the 2,000 gift, not the 1,000 one; the 2,000 gift out of stock gives the
  1,000 one; none in stock, none — and the answer says so.
- `goods_total` (after coupon and points) decides, not `net_subtotal`.
- Audience and dates respected; two active levels with the same amount refused, even saved at once.

**Catalog**
- Deleting a category, brand, draft product or draft variant that a coupon rule or a gift level uses
  is refused by Catalog with what uses it — never a database error, never a rule dropped.
- A rule naming a draft product, or a gift naming an archived variant, is refused.

**Permissions and the database**
- Each manage permission; the ceiling admin-only; customers read only their own coupons; every
  permission name has three parts and boots.
- `useCoupon` checks the caller's permission in the coupon's store and refuses another store passed
  in; `recordOrder` in the order's store.
- An off store's coupons, segments and gift levels: Super Admins only.
- Every CHECK refused with its nullable columns left NULL and with an unknown kind (lesson 162); a
  code refused twice in a store, whatever its case; a use, an assignment or a segment of another
  store refused by the composite keys.

---

## 9 · Open questions

### 9.1 The owner's answers

**2026-10-07 / 2026-10-08:**

1. "Automatic promotions": **the gift levels alone** — "make sure thats all of it" (checked: see the
   paragraph after "What this module does not own").
2. What a coupon takes off: **a percentage with an optional cap, or a fixed amount** — "make sure that
   doesnt break our safe area of each orders discountable percentage": the ceiling refuses a coupon
   whole (§1.3, §1.6).
3. Reduced lines: **a switch per coupon, off by default.**
4. **An optional total number of uses.**
5. Bundles / kits: **later, as their own job after stage 6.**
6. A coupon use when the order is cancelled: **never comes back.**
7. Which orders segments count: **"every order placed, regardless if it was cancelled, delivered,
   returned, done or any other state."**
8. Gift levels: **several; one gift, the highest level reached** (out of stock: the next down).
9. Assigned coupons: **in the account centre now; messages with Ops.**
10. A partial return below a gift's level: **the customer keeps the gift.**
11. Coupon rules: **include and exclude lists.**
12. Segments: **per store.**
13. "Monthly buyers": **at least one order a month on average.**
14. Who manages: **the ceiling admin-only; coupons, segments and gifts ordinary.**
15. The ceiling when a store starts: **30% — "let it for now".**
16. Who can be in a store's segment: **registered in the store, or ordered in it.**

**2026-10-09:**

17. What a store's staff see of a segment member registered elsewhere who ordered there: **their name,
    as on that store's orders** — and they may pick them by hand.

**2026-10-10:**

18. **A wholesale band's price is a wholesale line's normal price**, not a reduction (via the stage 5
    session): "already reduced" means a `unit` below the `listUnit`.
19. A percentage coupon **rounds half up**, like prices.
20. After a staff edit the coupon is **checked again; trimmed or removed if it no longer fits — and it
    can only stay or shrink** (sales.md §1.7).
21. **An edit keeps the rules the order was placed under** — the ceiling among them (2026-10-10).

### 9.2 Still open

1. **Bundles / kits** — their own spec after stage 6: what a bundle is (Catalog), its price (Pricing),
   its pieces' stock (Inventory), a line of several items (Sales).
2. **The `Marketing` permission group and menu section** — my assumption, following the owner's
   answer for Loyalty's Points section; to confirm.
3. **Promotions' screens** — coupons, segments, gift levels, the account centre's coupons — the
   frontend session's, to confirm (as for Loyalty).
4. **Content and segments** — the handoff wants segments for pop-up targeting (§11.2), but Content may
   not read Promotions (§4.4). The owner's call when Content is specified: add Content → Promotions to
   the graph, or target pop-ups another way.
5. **A guest trying a code in the cart** — Sales's spec asks it (their cart). Using one needs an account.
6. **Every assumption marked above** — codes unique in a store and never changed; a used coupon never
   deleted, an unused one deleted with its assignments; unused assignments removable; anonymized
   customers leaving segments; two levels with one amount refused; gift levels in the Marketing
   section; an off store's promotions for Super Admins only.

### 9.3 The independent review of the draft (2026-10-08/09)

One read-only review; each finding was checked in the code before acting. **Blocker, fixed:** Access
offered no way to list customers, so segments could not be refreshed — an Access addition (§2.3).
**Fixed:** the coupon checked again in full at placement, the discount recomputed (`CouponChanged`) and
the scope taken from the coupon's store; permission names of three parts (Access refuses two); Content
removed as a reader of segments (the graph forbids it, §9.2); store-binding composite keys and store
foreign keys; Catalog's deletes asking Promotions first (a Catalog addition) and ready products only;
segment members' names for staff (the owner's answer 17, an Access addition); a repeated `useCoupon`
handled in code; a coupon worth nothing refused. And the minor points: the ceiling setting declared in
full; NULL-safe CHECKs and `IN` checks on every kind; codes never changed; lowering the total uses;
deleting a coupon with assignments; `COMPANY` defined and useless combinations refused; the gift-level
race; `RecordOrder` renamed from `OrderPlaced`; `NO_ORDER_FOR` and customers who never ordered; the
handoff's §4.4 and §11.2 amended; gift levels' menu place; off stores; order numbers on uses.
