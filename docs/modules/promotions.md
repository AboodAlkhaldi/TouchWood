# Promotions — Module Specification

**Status:** **ACCEPTED by the owner, 2026-10-10** (merged as #101) — sections 1–9 written 2026-10-08
from the owner's answers of 2026-10-07 to 2026-10-10 (§9.1), with the changes Sales's spec needed. The
picks marked "my assumption" were accepted with it. **Backend built** 2026-10-17 to 2026-10-19 in seven
steps (#143 to #169, and step 7's pass); its screens are the frontend session's (§9.2 #3).
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

**Checked when an entry is added, not again later** (owner, 2026-10-17, answer 22): changing a coupon
checks only the list entries the change adds; one already on it stays though its product was archived
or its category or brand switched off since — it matches no cart line — so a rename is never blocked
by an old entry. **At most 500 entries per coupon** — my assumption, stated for the owner to reject: a
list a screen can show whole.

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
  this customer — **a coupon given to others is checked first and answers as an unknown code**, with
  no name and no other reason, so a guessed code never tells that a private coupon exists (answer 29);
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
points together, measured against `gross_subtotal` — the full reduction from list price. **The number
moved to Pricing** (owner, 2026-10-13), which also holds sales and category discounts to it
(pricing.md §1.12); **Promotions reads it, and Sales applies the check** with the room Promotions
gives it.

| Key | Scope | Type | Range | Default | Changed under |
|---|---|---|---|---|---|
| `pricing.ceiling.max_discount_percent` (was `promotions.ceiling.max_discount_percent`) | Store | Integer | 0–100 | **30** (owner, 2026-10-08: "let it for now to be 30%") | `pricing.ceiling.manage` — **admin-only** (pricing.md §1.12) |

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

A level already ended never clashes — it gives no gift again — so an ended level left switched on
does not block a new one; extending its dates is checked again (owner, 2026-10-19, answer 26).

**"Over 1,000" counts an order of exactly 1,000** — a level is reached when the goods total is at
least its amount, `amount <= goods_total` (owner, 2026-10-19, answer 27).

**A change checks only a new gift** (owner, 2026-10-19, answer 28),
as a change checks only a coupon's new list entries (answer 22) — a level whose variant was archived
since keeps it, so changing its dates is never blocked; Sales finds it out of stock and goes a level
down.

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
Built with step 3b: anyone else is answered `Unauthorized` — §7 has no error of its own for it, and the
panel never offers them the store.

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
| `knowsCoupon(StoreId $store, string $customerId, string $code): bool` | Sales's coupon box: whether the code names a coupon of the store this customer may know of — one for everyone, or one assigned to them — whatever its dates or uses, so Sales counts the codes that name nothing (answer 29). Reads only |
| `customerDiscountRoom(StoreId $store, Money $grossSubtotal, Money $netSubtotal): Money` | Sales: the room left under the ceiling (§1.6), against which it checks the coupon, then the points |
| `giftLevels(StoreId $store, Audience $audience, Money $goodsTotal): list<GiftOfferDto>` | Sales: the levels the order reaches, highest first (§1.7) |
| `giftLevelsAbove(StoreId $store, Audience $audience, Money $goodsTotal): list<GiftOfferDto>` | Sales's cart: the levels on now for the buyer that the goods do not reach yet, lowest first — the next gift (sales.md §4.2 B12, 2026-10-27) |
| `useCoupon(UseCoupon $change): void` | Placing the order: **every check of §1.3 again, as the coupon is now** — dates, switch, uses for everyone and for this customer, the assignment, the minimum, the rules, the points, the ceiling — under a lock on the coupon so two orders never take its last use; **recomputes the discount and refuses (`CouponChanged`) if it is not the one quoted**, so checkout quotes again; then records the use. The same order with the same coupon again does nothing; the same order with another coupon is refused. **A use never comes back**, not even when the order is cancelled (owner, 2026-10-08) |
| `orderDiscountRoom(string $orderId, Money $grossSubtotal, Money $netSubtotal): Money` | Sales, working out a staff edit: the room under the ceiling **recorded with the order** (§1.6) |
| `couponAfterEdit(EditedCouponRequest $request): CouponResult` | Sales, after a staff edit (§1.3, the exception): the coupon's discount on the edited lines, never more than it is now, 0 if it no longer applies. Reads only |
| `updateCouponUse(UpdateCouponUse $change): void` | Sales, after a staff edit: records the use's new discount (never higher). Idempotent by the edit's id — **the latest edit's**: the use keeps only its last edit, so an older edit replayed after a newer one is refused when it would raise the discount (built with step 2b) |
| `recordOrder(RecordOrder $change): void` | Placing the order, with or without a coupon: the fact segments count (§1.5). The same order again does nothing |
| `segmentMembers(StoreId $store, string $segmentId, ?string $after, int $limit): list<string>` | Ops (newsletters, offer notices — ops.md §1.7): **the store's** segment's customer ids, keyset-paged, 1 to 1000 (handoff §5.4). **Another store's segment is refused as unknown** (`SegmentNotFound`), so a notice in one store never reaches another's (store added 2026-10-30, for Ops) |
| `storeSegments(StoreId $store, ?string $after, int $limit): list<SegmentChoiceDto>` | Ops' notice form and message composer (ops.md §4.13; the owner's go 2026-11-01): **the store's** segments, each with its id, both names and how many it holds; newest first by id, keyset-paged, 1 to 1000. Another store's never appear |
| `storeSegment(StoreId $store, string $segmentId): ?SegmentChoiceDto` | Ops' notice, showing its segment's name (ops.md §4.13; the owner's go 2026-11-01): **the store's** segment; null for another store's, one deleted since, or an id that is not one |
| `couponsUsed(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): CouponsUsedDto` | Ops' daily report (ops.md §1.11 item 7, §2.3; added 2026-10-28): **the store's coupon uses** from `from` up to `to` (not included) — `{uses, discount: Money}` in the store's currency, as edits left the discounts; a cancelled order still counts (§9: a use never comes back); a reversed range is none; a store that does not exist refused. One query |
| `assignedCustomers(string $assignmentId, ?string $after, int $limit): list<string>` | Ops' messages for `CouponAssigned` (added 2026-10-28): **the customers one assignment added** (the event's `assignmentId`, kept on each row it wrote: `coupon_assignments.assignment_id`), by id, keyset-paged, 1 to 1000; none for a coupon deleted since |
| `couponCode(string $couponId): ?string` | Ops' message for `CouponAssigned` (ops.md §1.5 C15; the owner's go 2026-10-31): **the code the customers type**, as saved (capitals, Latin digits), switched off, ended or used up alike; null for a coupon deleted since or an id that is not one |

### 2.2 DTOs and enums (`Public/Dto`, `Public/Enums`)

- `CouponRequest`: store, the code as typed, customer id, `Audience`, and Pricing's `CartPricesDto`
  for the cart (its lines' variant, `mode`, `unit` against `listUnit` — reduced when below — and
  `total`, its `grossSubtotal` and `netSubtotal`).
- `CouponResult`: the coupon's id and names, the total discount, **one share per line of the prices
  passed, in their order** (0 for a line not eligible) — an edited order may hold one variant and mode
  in several parts (pricing.md §1.11), so a share follows its line's place, not its variant and mode —
  `allowWithPoints`; or a `CouponRefusal`.
- `CouponRefusal`: `UNKNOWN_CODE`, `INACTIVE`, `NOT_STARTED`, `ENDED`, `USED_UP`,
  `USED_UP_FOR_YOU`, `NOT_ASSIGNED_TO_YOU`, `BELOW_MINIMUM`, `NOTHING_ELIGIBLE`, `WORTH_NOTHING`,
  `NOT_WITH_POINTS`, `PASSES_CEILING`. `applyCoupon` never answers `NOT_ASSIGNED_TO_YOU`: it answers
  `UNKNOWN_CODE` instead (answer 29); placing the order (`useCoupon`) may still, for a customer whose
  assignment was taken back after the quote.
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
| Pricing | The cart's prices: each line's variant, `mode`, `unit`, `listUnit` and `total`, `grossSubtotal`, `netSubtotal` | Exists (`PricingApi::prices`, `CartPricesDto`, on `main` since #97) — Sales passes them in |
| Catalog | **For each cart line: its product, brand and every category above the product's** — one call per cart | **A Catalog addition, built with Promotions**: `CatalogApi::lineFacts(list $variantIds)` — **built**, catalog.md amendment 18 (step 2a). `ProductDto` gives the product's own category only; "Kitchens" must cover its sub-categories |
| Catalog | Products, categories, brands and variants exist and are ready / active, for a coupon's lists and a gift | Exist: `CatalogApi::products` and `variant` for products and gifts; `categoriesWithPaths()` (catalog.md amendment 23(c), Stage 5's) for categories and `brands()` (amendment 27, after `brand()`, amendment 20) for brands — each one read whatever a list's length |
| Catalog | **Refusing to delete what a coupon rule or a gift level uses** (§1.2) | **Built** (catalog.md amendment 19, Promotions step 3): `CatalogUsages` / `CatalogUsage`, as Platform's `MediaUsages` — Catalog's deletes of a category, brand, draft product or draft variant ask it first and refuse with `catalog.item_in_use`; Promotions answers through `PromotionsCatalogUsage` — its coupons' lists, and its gift levels' variants (step 5) |
| Access | **The customers a segment can hold, in bulk**: those registered in a store, of one account type, not anonymized, keyset-paged; and a batch read of many customers by id (type, home store, name, anonymized) | **Built** (access.md amendment 70, Promotions step 4a): `AccessApi::customerIdsInStore` and `AccessApi::customers` — a fixed number of queries however many |
| Access | **A store's staff see the name of a customer who ordered in that store** (owner, 2026-10-09, for segments) — beyond today's "only their stores' customers" (access.md amendment 44(c)) | **Recorded in access.md amendment 70**: Promotions decides who ordered in a store (its order facts) and reads the name through `customers()`; its segment screens show the name only (step 6) |
| Access | The permission catalog, and **a `Marketing` permission group** — the admin menu's "Marketing" section (handoff §14: Coupons · Segments) | Catalog exists; **the group is an Access addition** (and `marketing` in Platform's menu groups), built with Promotions — the same as Loyalty's Points section (loyalty.md §2.3). **Built** with step 1 (access.md amendment 69; the owner approved the build plan, 2026-10-17) |
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
| Change the store's discount ceiling | **Not Promotions'**: `pricing.ceiling.manage` (per store, **admin-only**), Pricing's (pricing.md §3) — `promotions.settings.update` dropped (owner, 2026-10-17) |
| Refresh every segment, nightly | `promotions.segment.refresh` (reserved, global: the system only) |
| `useCoupon`, `recordOrder` | **The caller's** — Sales's placing an order — in the coupon's store (`useCoupon`), the order's store (`recordOrder`) |
| `updateCouponUse` | The caller's — Sales's editing an order — in the coupon's store |
| `applyCoupon`, `knowsCoupon`, `couponAfterEdit`, `customerDiscountRoom`, `orderDiscountRoom`, `giftLevels`, `giftLevelsAbove`, `segmentMembers`, `storeSegments`, `storeSegment`, `couponsUsed`, `assignedCustomers`, `couponCode` | **None of their own**: reads for modules inside flows they have authorized |

| Read (query) | Permission |
|---|---|
| A store's coupons (with their uses — each by the order's **public number**, never its id, handoff §5.3), segments (with their members' names, §1.5) and gift levels | `promotions.marketing.view` (per store) — or any of the three manage permissions |
| **My coupons** in a store, in the account centre: those assigned to me and still usable | `promotions.coupon.view_own` — every customer, their own only |

Built in step 6 (`Application/Query`): `ListCoupons` (newest first; by state now — §4 — and by the
start of the code), `ViewCoupon` (its lists named through Catalog, how many customers have it),
`ListCouponUses` (the order's public number, the customer's name), `ListCouponAssignments` (name,
segment, uses) — a page at a time by keyset, 50 at most; names through `AccessApi::customers` in one
call. A store switched off is read by Super Admins alone, as it is changed (§1.8). Then
`ListSegments` (newest first, with how many each holds), `ListSegmentMembers` (the name only, by
customer id), `ListGiftLevels` (every level, highest first, the gift named through Catalog in two
bulk reads, whether it gives its gift now — and, for the tick box of M4, whether the size already counts
on stock here and whether the store is wired, from Inventory, 2026-10-20), `SearchGiftSizes` (the
dialog's size search under `promotions.gift.manage`: every ready product's sizes by product name or code,
archived ones left out, 20 at most, each with the same two facts — so the screen reads neither Catalog
nor Inventory itself) and, for the customer, `ViewMyCoupons` (the coupons given
to me there that I can use now — on, started, not ended, uses left in all and for me — soonest to end
first; a store switched off has none). My assumption, stated for the owner to reject: **"usable" leaves
out what depends on the cart or the buyer** — sale mode, the minimum (shown on the row), and whether
the customer is now an individual or a company (Promotions never reads B2B; checkout says why a coupon
takes nothing).

**The permissions Promotions declares** — every name has three parts, as Access requires
(`InMemoryPermissionCatalog::NAME`):

| Permission | Audience | Kind | Group | Admin-only |
|---|---|---|---|---|
| `promotions.marketing.view` | Role | per store | `Marketing` | no |
| `promotions.coupon.manage` | Role | per store | `Marketing` | no |
| `promotions.segment.manage` | Role | per store | `Marketing` | no |
| `promotions.gift.manage` | Role | per store | `Marketing` | no |
| `promotions.coupon.view_own` | every customer | global | — | — |
| `promotions.segment.refresh` | Role, reserved | global | — | — |

**Coupons, segments and gifts are ordinary permissions** an admin may give to a marketing staff role
(owner, 2026-10-08). **The ceiling is Pricing's**, admin-only there; Promotions declares no setting
permission of its own (owner, 2026-10-17).

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

### 4.1 The screens (stage 6's screens)

**Written 2026-10-19** by the stage 6 screens session, after the backend's seven steps (#143–#173), as
Loyalty's screens were (loyalty.md §4.1). Every **[Qn]** is a question for the owner, listed in §9.4
with the options and the one I recommended; **the owner answered all eighteen on 2026-10-19** (§9.4) and
the text below is written to those answers. The use cases behind every button are §3's;
a screen offers only what the reader may do next — asked of an Application query
(`PromotionsActionsForReader`, new), never of the authorizer from the screen
(`tests/Architecture/AccessDecisionsTest`) — and every handler asks again.

**What every screen keeps** — as Loyalty's (loyalty.md §4.1), unchanged: Geist's rules on shadcn's
code (frontend.md §1.11), every box checking itself as it is typed (the server's own rules, from
`Coupon/Inputs` and `GiftLevelFields`), every number in 0-9 (Arabic-Indic typed is read as Latin),
every moment through `Time` in the store's zone, names in the page's language first, money through
`resources/js/lib/money.ts` (an amount typed as the store writes it, "149.50", checked against the
currency's decimals), dates, amounts and typed text isolated inside sentences. Lists page with **Show
More** (keyset, 50). **Its own store filter** (`?store=`), a Super Admin's off stores marked Off — an
off store's promotions are set by Super Admins only (§1.8). Words in
`src/Modules/Promotions/Presentation/lang/{ar,en}/admin_coupons.php`, `admin_segments.php`,
`admin_gifts.php`, `shop_coupons.php`, `menu.php`, `account_pages.php`; refusals are §7's.

**Who sees what.** `promotions.marketing.view` — or any of the three manage jobs — reads every list
and page of a store (§3). Each button needs its own job: coupons and their assignments
`promotions.coupon.manage`, segments `promotions.segment.manage`, gift levels
`promotions.gift.manage`. **A reader without the job sees no button at all; one who holds it but whom
the item or the store stops sees it out of reach, with the reason in its tooltip** (owner, Q16 — as
frontend.md: "an action that cannot be done is shown disabled, with the reason"; inside a ⋯ menu the
reason is written under the item instead).

**The menu** — Platform's `AdminMenu`, the **Marketing** group (it exists, access.md amendment 69):

| # | Entry | Address | Icon | Offered for |
|---|---|---|---|---|
| 10 | Coupons · الكوبونات | `/admin/coupons` | `coupons` (Lucide `TicketPercent`'s sibling `Ticket`, new) | any of the four jobs |
| 20 | Segments · الشرائح | `/admin/segments` | `segments` (Lucide `UsersRound`, new) | any of the four |
| 30 | Gift Levels · مستويات الهدايا | `/admin/gift-levels` | `gifts` (Lucide `Gift`, new) | any of the four |

**The reads the screens add** to step 6's (§3): `PromotionsActionsForReader` (which buttons, per
store), `SearchSegmentCandidates` (the backend session's, §9.2 #6, on Access's `customerIdsMatching`),
and for the coupon rules' pickers **brands and categories to choose from**: categories from Catalog's
existing `activeCategories()` (amendment 23(f), each with its path), brands from a new
`CatalogApi::activeBrands()` — every switched-on brand in Catalog's order, one query (**catalog.md
amendment 28**, built with these screens) **[Q4]**. `ViewMyCoupons` needs nothing new.

#### M1 · Marketing › Coupons — `/admin/coupons?store=&state=&code=`

- **Header**: "Coupons", subtitle "Codes customers type at checkout"; the store filter; **New Coupon**
  (a link to M2's new form) for `promotions.coupon.manage`.
- **Filters** [Q2]: Geist Tabs **All · Active · Scheduled · Ended · Off** (`CouponState`), kept in the
  address; a search box "Search by code" — the start of a code, read as typed (§3), from the first
  character.
- **Columns**: **Code** (mono, the row's link to M2) · **Name** (the page's language) · **Discount**
  ("15%, up to 100.00 SAR", "25.00 SAR off") · **Who** (Anyone with the code / Assigned customers) ·
  **Dates** (start – end, or "No end") · **Uses** ("12 / 100", or "12") · **State** (one Geist Badge:
  Active green, Scheduled blue, Ended gray, Off gray).
- **Empty**: "No coupons yet" / "Make a code customers can type at checkout." with New Coupon for a
  reader who may; a filter or a search that finds none: "No coupons match." with Clear Filters.

#### M2 · A coupon — `/admin/coupons/{coupon}` and `/admin/coupons/new?store=`

**One page, three tabs** [Q3]: **Details · Uses · Customers** (Customers only for an `ASSIGNED`
coupon). A new coupon has Details only.

**Details** — a form for a reader who may manage, the same fields read-only for one who may not
[Q1: a full page, not a dialog — too many fields for one]. Sections (shadcn Card each, Geist's
Fieldset, one **Save Coupon** at the foot, out of reach until something changed):

1. **Code and name**: the code — typed only when new, "Letters, digits and hyphens, 3 to 30"; upper
   case shown as typed; **never changed** afterwards (shown, not a box) — and the name in Arabic and in
   English.
2. **Who may use it**: Anyone with the code / Only customers it is given to — fixed while customers
   have it (answer 25): the choice is out of reach, saying "Remove its customers first".
3. **What it takes off**: Percentage (1–100, an optional cap) or Fixed amount; the minimum order,
   optional; the line under it says the store's ceiling ("Coupons and points together may take at most
   30% off an order in this store.") [Q15].
4. **When and how often** [Q7]: starts (date and time, the store's zone; now by default), ends (optional),
   uses per customer, uses in all (both optional, "No limit" when empty); lowering uses in all below
   the uses made is refused (§1.1).
5. **Where it applies**: retail and/or wholesale lines; individuals and/or companies; on lines already
   reduced (off by default); with points (on by default) — each a Geist Switch with its one-line
   meaning.
6. **What it covers** [Q4, Q5]: two lists, **Includes** and **Excludes**, each a list of products,
   categories and brands, each a chip **naming it** ("Category: Kitchens › Drawers", "Brand: Acme",
   "Product: Oak Table") with a remove button, and **Add…** opening one picker with three tabs — a product
   found by name or code; **a category read by its name and its path in the tree**, ticked by name; a
   brand from the list by name (owner, Q4: nobody remembers a category's number, so staff read and
   pick names; an id never shows). Empty Includes reads "Every
   product". At most 500 entries (§1.2). An entry added since that is no longer ready, active or
   switched on is marked "Not sold now" (answer 22: it stays, matching nothing).

**The switch**: **Turn Off** / **Turn On** in the page's header for a manager (`SwitchCoupon`), saved at
once with a toast. **Delete Coupon…** (an AlertDialog naming the code) only while it was never used; a used coupon shows the button out of reach, its tooltip "Used once or more:
turn it off instead" (owner, Q6).

**Uses** — the coupon's uses, newest first: **Date** · **Order** (public number, plain text until Sales's
screens — as loyalty.md answer 12) · **Customer** (the name; "Deleted customer") · **Discount**. Empty:
"Not used yet."

**Customers** (an `ASSIGNED` coupon): who has it, newest first — **Customer** · **Given** (date) ·
**From segment** (its name, or "—" once the segment is gone) · **Uses** · and, for a manager, **Remove**
while unused (a confirm; used: out of reach, "Used: it stays"). At the top, **Give to a Segment…**
(`AssignCoupon`): a dialog choosing one of the store's segments whose audience the coupon allows,
saying "Gives the coupon to its {n} members now; members who join later do not get it unless you give
it again." (§1.4) [Q14].

#### M3 · Marketing › Segments — `/admin/segments?store=` and `/admin/segments/{segment}`

- **List**: **Name** · **Type** (Picked by hand / By rule) · **Who** (Individuals / Companies) · **Rule**
  in words ("No order in 6 months", "At least 3 orders in 3 months", "Never ordered") · **Members** ·
  **Updated** (`refreshedAt`, relative). **New Segment…** for `promotions.segment.manage`: a dialog —
  name in both languages, type and audience (fixed once made, answer 23 — said under the choices), and
  for a rule its numbers.
- **A segment's page**: its facts; for a manager **Edit…** (names, the rule's numbers), **Refresh Now**
  (a rule segment; a toast "Members updated: 120."), **Delete Segment…** (AlertDialog; its assignments
  keep their customers, §5's SET NULL — said in the dialog).
- **Members**: the names, a page at a time (Show More); "Deleted customer" never shows — they leave at
  the next refresh (§1.5).
- **Picking by hand** (a static segment) [Q8]: **Add Customers…** — a search by name, email or phone
  (`SearchSegmentCandidates`: registered in the store or ordered in it; email and phone only among
  customers the reader may view, access.md amendment 72(b)), each result with **Add**; each member
  with **Remove**; **Save Members** sends what was added and taken off (`ChangeSegmentMembers`, merged
  under the segment's lock, so two managers saving at once each keep what they did; only the customers
  added are checked, so a member no longer eligible — an account anonymized since — never blocks a save;
  the backend's review with the screens, 2026-10-20), out of reach until it changed. At most 1,000 shown found [Q9].

#### M4 · Marketing › Gift Levels — `/admin/gift-levels?store=`

- **One list, highest first** [Q10]: **From** (the amount) · **Gift** (the product's name, the size's
  code; "Archived" marked) · **Who** · **Dates** · **State** (Giving now / Not started / Ended / Off —
  `inEffect`, `active`). Under the list, one line: "An order gets one gift — the highest level it
  reaches; out of stock, the next one down." (§1.7)
- **New Level…** / **Edit…** (a dialog for `promotions.gift.manage`): the amount; the gift — a size
  found by product name or code in this store (`SearchGiftSizes`, over Catalog's `searchVariants`), its photo shown; individuals and/or
  companies; dates, optional. **In a wired store**, under the gift (owner, Q11 (c)): a tick box, off by
  default, **"Also limit this size's normal sales to its stock"**, with the line "The gift itself is
  always given from stock. Ticking this also makes customers able to buy this size only while it is in
  stock — it leaves the shop when it runs out." Shown only for a size whose stock-dependency is off —
  since 2026-10-28 every size is stock-dependent by default (inventory.md §9.2 #24), so only a size
  staff switched off shows it, and the tick box rarely applies;
  out of reach, with the reason, for a reader without Inventory's switch job. Saving the level with it
  ticked also turns the size's switch on, **in the level's own save** (one transaction: no level saved
  without its switch) — `CreateGiftLevel` and `ChangeGiftLevel` call Inventory's public
  `makeStockDependent` (inventory.md §9.2 #23, stage 5's), which turns on only and audits as Inventory's
  own switch does. A clash (§7 `GiftLevelClash`) said in the dialog. **Turn Off** / **Turn On** per row.
  No delete — a level is switched off (§3).

#### M5 · The shop: the account's Coupons tab — `/{store}/{locale}/account/coupons`

- **Offered** in the account's side list (Access's `CustomerAccountPages`, `promotions.coupons`) **[Q12]**.
- **One card per coupon** (`ViewMyCoupons`, soonest to end first): the code, large, with the panel's
  own **CopyButton** (Geist's Copy Button, owner Q13): an icon named "Copy coupon code"; pressed, the icon
  turns to a check for about two seconds and back, a screen reader hears "Copied", and a refused copy
  shows a cross and "Couldn't copy"; the name; what it takes off; the minimum order; "Ends on {date}" or "No end
  date"; "Uses left: 2" when limited. Under the list: "Type the code at checkout. Whether it applies to
  your cart is checked there." (answer to §3's "usable" assumption).
- **Empty**: "No coupons for you right now."

**Every screen above** is checked at phone width (375 px, nothing scrolling sideways — wide tables
scroll in their own box), in Arabic and English, light and dark; its own query count recorded by a
feature test; its page under 60 KB gzipped.

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
| `promotions.coupon_assignments` | `coupon_id`, `store_id`, `customer_id` → `access.customers` RESTRICT, `segment_id` NULL, `assigned_at`, `assignment_id` NULL (the assignment that added the row, for `CouponAssigned`; none on rows written before 2026-10-28) | PK (`coupon_id`, `customer_id`); (`coupon_id`, `store_id`) → coupons (`id`, `store_id`) CASCADE (an unused coupon's delete takes them, §1.1); (`segment_id`, `store_id`) → segments (`id`, `store_id`) SET NULL (`segment_id`) — a segment of the same store only; index (`assignment_id`, `customer_id`) where set |
| `promotions.coupon_uses` | `id` bigint identity, `coupon_id`, `store_id`, `customer_id` → `access.customers` RESTRICT, `order_id` (Sales's id, text — no foreign key: Promotions never depends on Sales), `order_number` (its public number), `discount_minor`, `last_edit_id` NULL, `used_at` | (`coupon_id`, `store_id`) → coupons (`id`, `store_id`) RESTRICT; UNIQUE (`order_id`) — one coupon per order; CHECK `discount_minor >= 0` (0 once an edit removed it — the use still counts); never deleted (a use never comes back); the discount only ever lowered, by a staff edit (`last_edit_id`, §1.3); index (`store_id`, `used_at`) for `couponsUsed` |
| `promotions.order_facts` | `order_id` PK (text), `store_id`, `customer_id` → `access.customers` RESTRICT, `placed_at`, `ceiling_percent` (the ceiling it was placed under, §1.6) | CHECK `ceiling_percent BETWEEN 0 AND 100`. Never changed: a placed order counts whatever happens to it |
| `promotions.segments` | `id`, `store_id`, `name_ar`, `name_en`, `type`, `audience`, `rule` NULL, `rule_months` NULL, `rule_orders` NULL, `refreshed_at` NULL, `created_at`, `updated_at` | UNIQUE (`id`, `store_id`); CHECK `type IN ('STATIC', 'DYNAMIC')`, `audience IN ('INDIVIDUAL', 'COMPANY')`, `rule IS NULL OR rule IN ('NO_ORDERS_EVER', 'NO_ORDER_FOR', 'AT_LEAST_ORDERS')`; CHECK `(type = 'STATIC' AND rule IS NULL AND rule_months IS NULL AND rule_orders IS NULL) OR (type = 'DYNAMIC' AND rule IS NOT NULL)`; CHECK `rule IS DISTINCT FROM 'NO_ORDERS_EVER' OR (rule_months IS NULL AND rule_orders IS NULL)`; CHECK `rule IS DISTINCT FROM 'NO_ORDER_FOR' OR (rule_months IS NOT NULL AND rule_months BETWEEN 1 AND 120 AND rule_orders IS NULL)`; CHECK `rule IS DISTINCT FROM 'AT_LEAST_ORDERS' OR (rule_months IS NOT NULL AND rule_months BETWEEN 1 AND 120 AND rule_orders IS NOT NULL AND rule_orders BETWEEN 1 AND 1000)` |
| `promotions.segment_members` | `segment_id` → segments CASCADE, `customer_id` → `access.customers` RESTRICT, `added_at` | PK (`segment_id`, `customer_id`) |
| `promotions.gift_levels` | `id`, `store_id`, `amount_minor`, `currency_code`, `variant_id` → `catalog.variants` RESTRICT, `for_public`, `for_company`, `starts_at` NULL, `ends_at` NULL, `active`, `created_at`, `updated_at` | CHECK `amount_minor > 0`; CHECK `for_public OR for_company`; CHECK `ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at` |

**Indexes:** coupons by (`store_id`, `code`) (the UNIQUE); coupon uses by (`coupon_id`, `customer_id`)
— uses per customer; order facts by (`store_id`, `customer_id`, `placed_at`) — the segment rules;
assignments by `customer_id` — "my coupons"; segment members by `customer_id`; gift levels by
(`store_id`, `amount_minor` DESC). For the staff screens' pages (step 6): coupons by (`store_id`,
`created_at` DESC, `id` DESC); uses by (`coupon_id`, `used_at` DESC, `id` DESC); assignments by
(`coupon_id`, `assigned_at` DESC, `customer_id` DESC); segments by (`store_id`, `created_at` DESC,
`id` DESC).

---

## 6 · Events

**Published** (added for Ops, 2026-10-28; ops.md §1.5 C15, §2.3) — through the outbox, after commit, ids only:

| Event | When |
|---|---|
| `CouponAssigned` (`eventId`, `assignmentId`, `couponId`, `storeId`, `occurredAt`) | `AssignCoupon` gives the coupon to a segment's customers: **one event for each assignment that adds anyone**, none when everyone had it already (§1.4). `assignmentId` is on every row it added: Ops pages them with `assignedCustomers` and says "a coupon was assigned to you" to each (the Ops session's choice, 2026-10-28: a segment of thousands is one outbox row). The account centre shows it at once either way (owner, 2026-10-08) |

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
| `CouponRefused` | INVALID | `useCoupon` finds the coupon no longer usable — the same reasons as `CouponRefusal`, in its context's `reason` — or the order already used another coupon (`reason`: `ANOTHER_COUPON`, which no quote gives; built with step 2b) |
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
  nothing eligible; worth nothing (1% of 49 halalas — 1% of 50 rounds half up to 1, answer 19); with points when it does not allow them.
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

**2026-10-17:**

22. A coupon's list entry whose product was archived (or whose category or brand was switched off)
    after it was added: **check new entries only** — an edit checks just what it adds (§1.2).

**2026-10-18:**

23. A segment's **type** (hand-picked or rule-based) and **audience** (individuals or companies) are
    **fixed when it is made**; its name and its rule's numbers change. To change type or audience,
    make a new segment (§1.5).
24. **Only an `ASSIGNED` coupon is assigned to a segment** — a public coupon works for anyone who types
    it (§1.4).
25. **An assigned coupon is not made public while customers have it** — staff remove the assignments
    first, or make a new public coupon; its customers never silently lose or regain it (§1.4).

Owner, 2026-10-19 ("accept for all", after step 5's review):

26. **A gift level already ended never clashes** with another — an ended level left switched on does
    not block a new one; extending its dates is checked again (§1.7).
27. **A level is reached at exactly its amount** — "over 1,000" counts an order of 1,000
    (`amount <= goods_total`, §1.7).
28. **A change of a gift level checks only a new gift** against Catalog, as answer 22 for a coupon's
    lists (§1.7).

Owner, 2026-10-28 (security audit run-2):

29. **A coupon given to others answers as an unknown code** — "some of them are secret and some are
    public for everyone": `applyCoupon` checks the assignment before anything else and answers
    `UNKNOWN_CODE` with no name; a public coupon keeps its reason (ended, under the minimum…). Sales
    limits the codes that name nothing (sales.md answer 50) through the new read `knowsCoupon` (§1.3, §2.1).

### 9.2 Still open

1. **Bundles / kits** — their own spec after stage 6: what a bundle is (Catalog), its price (Pricing),
   its pieces' stock (Inventory), a line of several items (Sales).
2. ~~**The `Marketing` permission group and menu section**~~ — **settled**: the owner approved the
   build plan that adds them (2026-10-17); built with step 1 (access.md amendment 69, platform.md §9.4).
3. ~~**Promotions' screens**~~ — **the stage 6 screens session builds them** (owner, 2026-10-18/19,
   as for Loyalty); the draft is §4.1, its questions §9.4.
4. **Content and segments** — the handoff wants segments for pop-up targeting (§11.2), but Content may
   not read Promotions (§4.4). The owner's call when Content is specified: add Content → Promotions to
   the graph, or target pop-ups another way.
5. **A guest trying a code in the cart** — Sales's spec asks it (their cart). Using one needs an account.
6. ~~**Finding customers to pick by hand for a segment**~~ — **settled**: `SearchSegmentCandidates
   (segmentId, text)` (2026-10-19, after access.md amendment 72): Access's `customerIdsMatching` — names
   in any store, email and phone among the customers the staff member may view — kept to whom the
   segment may hold (`SegmentMembership::eligible`, the rule a save enforces), by name only, at most 50,
   each marked when already a member; `more` when there were more.
7. ~~**A category's name and switch, read one at a time**~~ — **settled**: read in bulk through Stage 5's
   `categoriesWithPaths` (catalog.md amendment 23(c)) since step 7, with brands (amendment 27).
8. **Every assumption marked above** — codes unique in a store and never changed; a used coupon never
   deleted, an unused one deleted with its assignments; unused assignments removable; anonymized
   customers leaving segments; two levels with one amount refused; gift levels in the Marketing
   section; an off store's promotions for Super Admins only. (The three gift-level picks of step 5 are
   settled: §9.1, answers 26–28.)

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

### 9.4 The screens — the owner's questions, round 1 (asked and answered 2026-10-19)

Asked by the stage 6 screens session for §4.1; the first option of each was the recommendation.
**The owner's answers (2026-10-19): (a) for every question, with these additions** — Q4: staff read and
pick categories (and brands) **by name**, a category with its path, never a number; Q6: the reason in the
greyed button's tooltip (the references' rule); Q13: the panel's CopyButton as it is (a check for a
moment, then back); Q16: hidden without the job, greyed with the reason when the item or store stops a
reader who holds it; **Q11: (c) — a tick box in the gift dialog, off by default, also making the size's
normal sales depend on stock, the dialog saying in plain words what that does** (the gift itself always
counts on stock, Inventory's code: `! wired || gift || stockDependent`; stage 5 and the backend session,
2026-10-19). That needs **an Inventory amendment** (`InventoryApi::makeStockDependent`, stage 5's) and
**a new edge Promotions → Inventory** (handoff §4.4, `deptrac.yaml`), called **inside the gift level's own
save** (`CreateGiftLevel`, `ChangeGiftLevel` — the backend session's). Whether ticking also needs
Inventory's own switch job (`inventory.stock.manage`) is asked of the owner by stage 5 (recommended: yes,
so a promotions manager cannot change a stock setting); with it, the box is out of reach, with the reason,
for a reader without that job.

| # | Question | Options (recommended first) |
|---|---|---|
| Q1 | Making and changing a coupon: where? | (a) **A page of its own** — about twenty fields in six groups, room for the rule lists and the Uses and Customers tabs. (b) A dialog over the list — quicker for small changes, cramped for the rule lists |
| Q2 | The coupons list's filters | (a) **Tabs by state — All, Active, Scheduled, Ended, Off — and a search by the start of the code.** (b) Only the search. (c) A state drop-down and the search |
| Q3 | A coupon's page | (a) **Three tabs: Details · Uses · Customers** (Customers only for an assigned coupon). (b) Everything on one long page |
| Q4 | Choosing the categories and brands a coupon covers needs them listed, and Catalog's public reads give them only by id | (a) **A Catalog addition**: `CatalogApi::categoryTree()` (the active categories with their paths, both languages) and `CatalogApi::brandsList(?text)` (active brands, by name) — a small amendment, built with the screens, one read each. (b) Products only on the screens for now; categories and brands chosen later. (c) Type a category's or brand's exact name |
| Q5 | How the rule lists look | (a) **Two lists, Includes and Excludes, each entry a chip with its kind ("Category: Kitchens") and a remove button; Add… opens one picker with three tabs (Product, Category, Brand).** (b) Three separate lists per side |
| Q6 | Deleting a coupon used before (§1.1 refuses it) | (a) **The button stays, out of reach, saying "Used once or more: turn it off instead".** (b) No Delete button at all on a used coupon |
| Q7 | A coupon's start and end | (a) **Date and time, in the store's time zone** — starts now by default, ends optional. (b) Dates only (from 00:00 to 23:59 of the store's zone) |
| Q8 | Picking a hand-picked segment's customers | (a) **A search (name, email or phone) with Add per result, the members with Remove, and one Save Members** — nothing changes until saved. (b) Each Add and Remove saved at once |
| Q9 | A search that finds too many customers | (a) **Show the first 50 by name and say "Type more to narrow it"** — picking needs exactness, not a complete list. (b) As Loyalty's: "Too many match", none shown, past 1,000 |
| Q10 | Gift levels' screen | (a) **One short list (highest first) with New Level… and Edit… as dialogs** — a store has a handful. (b) A page per level |
| Q11 | Choosing a level's gift | (a) **A size found by product name or code among the store's sizes** (Catalog's `searchVariants`), its photo shown. (b) The size's code typed |
| Q12 | When the shop's account shows **My Coupons** | (a) **Always, for every signed-in customer** — no extra query on every shop page; "No coupons for you right now" when empty. (b) Only while the customer has a usable coupon — one more query on every signed-in shop page, as Loyalty's Points tab costs while off (accepted for Points "for now", 2026-10-19). (c) Not in the menu at all; reached from checkout only |
| Q13 | Copying a code | (a) **A Copy button beside each code** (Geist's Copy). (b) None |
| Q14 | Giving a coupon to a segment | (a) **From the coupon's Customers tab: Give to a Segment…, choosing among the store's segments of an audience the coupon allows.** (b) From the segment's page: Give a Coupon… (c) Both |
| Q15 | Showing the store's discount ceiling on the coupon form | (a) **One line under "What it takes off"**: "Coupons and points together may take at most 30% off an order in this store." (b) Not shown |
| Q16 | A reader who may read but not change | (a) **No buttons at all**, the form read-only — as Loyalty's Change Points. (b) Buttons shown out of reach, saying which job they need |
| Q17 | Menu entries and their order | (a) **Coupons, Segments, Gift Levels — in that order, under Marketing**, gift levels there as §1.7 already assumes. (b) Gift levels elsewhere |
| Q18 | A deleted customer on Uses, Customers and Members | (a) **"Deleted customer", no other detail** — as Loyalty's screens. (b) Leave such rows out |

**What the screens need from other modules**: Catalog's `activeCategories()` (exists) and a new
`activeBrands()` (Q4, catalog.md amendment 28); Promotions `SearchSegmentCandidates` (the backend session's, on Access's
`customerIdsMatching`, which ships with Loyalty's screens, PR #168) and `PromotionsActionsForReader`;
Platform's menu icons `coupons`, `segments`, `gifts`.

### 9.5 Stage 7 — **[ACCEPTED by the owner 2026-10-28; BUILT in stage 7 step 7 — §9.6]**: a coupon reserved while the customer pays online

Accepted by the owner on 2026-10-28 with payments.md. **Online, no order exists until the money is
confirmed** (owner, 2026-10-28; sales.md §9.5 #4): Pay starts a checkout try that must hold the coupon
for its few minutes, and **a try that does not pay must leave nothing counted** — "as if Pay had never
been pressed". The built `useCoupon` cannot: it needs the order's number, and "a use never comes back".
Three new operations, beside `useCoupon` (which stays for placing as built):

1. **`reserveCoupon(orderId, couponId, …)`** — at Pay, under the order's future id: every check of
   `useCoupon`, under the same lock; refused or changed exactly as `useCoupon` is. **A reserved use counts
   toward the coupon's limits** (in total and per customer) while it lasts, so two buyers can never both
   take the last use. The same id again does nothing.
2. **`confirmCoupon(orderId, orderNumber)`** — when the order is created: the reservation becomes a use,
   recorded with the order's number, and from then on it is the use `useCoupon` would have made ("a use
   never comes back" applies). Once.
3. **`releaseCoupon(orderId)`** — the try did not pay: the reservation goes, **nothing counted**, nothing
   in the coupon's history but the reservation's own record. Once; nothing to release does nothing.

`recordOrder` (the fact segments count) is called only when the order is created. A reservation is never
older than a try (Sales releases or confirms every one); the reports count uses, never reservations.

### 9.6 Stage 7 step 7 — built, with the owner's answers (2026-10-29/31)

Built as §9.5 says, with the gap answers 7.1, 7.4 and 7.5 (scratchpad `stage7-gap-answers.md`):

1. **`reserveCoupon(ReserveCoupon)`, `confirmCoupon(SettleReservation)`, `releaseCoupon(SettleReservation)`**
   on `PromotionsApi`; a hold is a row of `promotions.coupon_reservations` under the order's future id.
2. **A hold counts toward the limits** — in total and per customer (`CouponUsage::$heldTotal`,
   `$heldByCustomer`) — wherever a coupon is quoted or taken, so two buyers never both take the last use.
3. **Staff may change or delete a coupon under a hold** (gap 7.1 (b)): nothing is blocked. A confirm records
   the use even past a limit lowered meanwhile ("100 of 99"), so `coupons_uses` no longer caps the count at
   the limit (it still never falls). My pick: a coupon deleted under a hold takes the hold with it; the
   confirm then records nothing and the order keeps its own copy of the discount and the code.
4. **A confirm is never checked again** (gap 7.4): a coupon switched off or ended meanwhile is still recorded.
5. **Staff see uses, never holds** (gap 7.5): the coupon's figures and its Uses tab are unchanged.
