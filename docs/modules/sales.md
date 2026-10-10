# Sales — Module Specification

**Status:** DRAFT for the owner's review — sections 1–9 written 2026-10-10 from the owner's answers of
2026-10-07 to 2026-10-10 (§9.1), and rewritten after an independent review (§9.3). Nothing is built
before the owner accepts it; all four of stage 6's specs come first (owner, 2026-10-07).
**Tier:** 1. **Stage:** 6 (handoff §17), the last of the stage's modules to be built (Loyalty →
Promotions → Feedback → Sales).
**Depends on:** every module above it except Feedback and Sync (handoff §4.4) — Platform, Access, B2B,
Catalog, Pricing, Inventory, Promotions, Loyalty, Shipping, Payments. **Shipping and Payments are
stage 7** and wait for the owner's vendor data (handoff §15.1): Sales states exactly what it needs from
them and works without them until then (owner, 2026-10-07: "interfaces now, stage 7 fills them").
**Source:** `docs/HANDOFF.md` §1, §2, §4.4, §4.5, §5.1–§5.5, §6, §7.4, §7.5, §7.8, §7.9, §10.2,
§11.3, §12.1–§12.6, §14, §16 and its amendments to 2026-10-10; `docs/modules/pricing.md`,
`inventory.md` (and PR #106), `catalog.md` §1.13, `platform.md` §5.6, `promotions.md`, `loyalty.md`,
`feedback.md`; the owner's answers (§9.1); the merged code as of `4f8fccc`.

Sales owns **the transaction**: the cart, checkout's quote, the order with its four state machines —
the order, its payment, its shipment, its returns — cancellation, returns, staff edits before shipping,
and, until stage 7, a flat shipping fee. It is the widest module by design (handoff §4.4): it asks
Pricing for prices and totals, Inventory to hold stock, Promotions for the coupon and the gift, Loyalty
for points — **and owns none of their rules**.

## What this module does not own

| Concern | Owner |
|---|---|
| Prices, every total of handoff §10.2, VAT and its rounding — the totals of an edited order too | Pricing (owner, 2026-10-07: "totals math in Pricing, one place") |
| Holding, taking, freeing and restocking stock | Inventory |
| Coupons — the edited order's coupon too — the discount ceiling, segments' order facts, gift levels | Promotions |
| Points | Loyalty |
| Which categories an account type may see and buy | Catalog (catalog.md §1.13) |
| Carriers, rate tables, tracking links, packaging (stage 7) | Shipping — **a flat fee per store in Sales until then** (owner, 2026-10-10) |
| Gateways, online payment, bank transfer receipts and their check (stage 7) | Payments — **staff record payments by hand until then** (§1.9) |
| Who a customer is, their addresses, whether they may order | Access (the person) and B2B (the company) — handoff §7.4 |
| Invoices | The external accounting system (handoff §12.6) — Sales prints an order confirmation / packing document only |
| Every message to a customer about an order | Ops (stage 8) — owner, 2026-10-10: "all with Ops later" |

---

## 1 · Aggregates and invariants

### 1.1 The cart

**Mutable, permissive, no invariants** — it must never stop a customer adding something (handoff
§12.3). All checking happens at the quote.

- **One cart per buyer per store** — a customer's or a guest's. A guest's id lives in Access's cookie
  (access.md §1.7): **Sales writes it with the guest's first cart line** (handoff §7.7). Lines: a
  variant, its sale mode (`RETAIL` / `WHOLESALE`), a quantity; the same variant and mode once.
- **Guests see what either kind of account sees** (catalog.md §1.13) **and may add wholesale lines**
  (owner, 2026-10-09).
  **On signing in or registering**, lines the account may not buy **leave the cart with a notice** —
  wholesale lines for an individual (owner, 2026-10-09), and products in categories hidden from the
  account's type (owner, 2026-10-10: "hidden means not for sale to them"); as a company, wholesale lines
  stay.
- **Moving and merging** (access.md §1.7, owner 2026-09-19): on `GuestBecameCustomer`, a guest's cart
  **moves** to a newly registered account and **merges** into an existing account's cart on sign-in —
  the same variant and mode adding their quantities. My assumption, stated for the owner to reject.
- **The cart keeps the coupon code** typed, for signed-in customers only — a guest's code box says "sign
  in to use a coupon" (owner, 2026-10-10).
- A cart survives (handoff §12.3: "30+ days"): one untouched for **90 days** is emptied by a nightly
  job. My assumption, stated for the owner to reject.

### 1.2 The quote — checkout

`StartCheckout` takes **the delivery address, the coupon (from the cart), the points asked for and the
way to pay**, and turns the cart into a **quote: server-computed, itemised, immutable**, valid for **15
minutes** and never past the prices' own end (`CartPricesDto::$validUntil`) (handoff §12.3,
pricing.md §2.1). **The client never sends an amount** — only the quote's id when it places the order
(handoff §2).

**The buyer's audience**: `COMPANY` for a company account approved in this store — the only company
that can order (handoff §7.4) — `PUBLIC` for an individual. Sales passes it to Promotions and Loyalty
and snapshots it on the order.

Built in this fixed order (handoff §10.2, §11.3):

1. **Who may order** — `AccessApi::customerMayOrder` (active, email and phone verified, no deletion
   pending) and, for a company account, `B2BApi::isApproved` in this store (handoff §7.4). An
   unapproved company sees the company view but cannot order (owner, 2026-10-07).
2. **What may be bought** — each line orderable here in its sale mode, within the store's minimum and
   maximum (Catalog `storeVariant`); **a wholesale line only for a company** (handoff §6, amended
   2026-10-07); **only products whose category is open to the buyer's account type in this store**
   (catalog.md §1.13; owner, 2026-10-10 — a Catalog addition, §2.3); stock (`InventoryApi::stock`).
3. **The delivery address** — one of the customer's addresses in this store, its format still accepted
   (access.md §1.9; handoff §7.8: "an order needs an address").
4. **Prices** — `PricingApi::prices`; a line Pricing cannot price (`unpriced`) is refused.
5. **The coupon** — `PromotionsApi::applyCoupon`; it must fit the room left under the ceiling
   (`customerDiscountRoom`) — or it is refused whole (promotions.md §1.3, §1.6).
6. **Points** — skipped, with a message, when the coupon does not allow points; otherwise
   `LoyaltyApi::quoteRedemption`, capped by Loyalty; then they must fit what the coupon left of the
   room, or **the whole redemption is refused and the points stay** (handoff §11.3).
7. **`goods_total`** — `PricingApi::totals` with the coupon and points, no shipping yet.
8. **The gift** — `PromotionsApi::giftLevels` for `goods_total`, highest first; the first whose variant
   is available as a gift (`InventoryApi::stock` → `availableAsGift`) **after the order's own pieces of
   that variant**; none, no gift, and the quote says so (promotions.md §1.7).
9. **Shipping** — until stage 7, **the store's flat fee, free when `goods_total` reaches the store's
   free-above amount** (owner, 2026-10-10; §1.10). From stage 7, Shipping's options for the address.
10. **The totals** — `PricingApi::totals` again with the shipping: `taxable_base`, VAT (rounded once,
    half up), `order_total`.
11. **The ways to pay** — by account type (owner, 2026-10-09; §1.9). The way chosen must be offered.
12. **The points this order would earn** — `LoyaltyApi::earningPreview`, shown in the cart and at
    checkout (owner, 2026-10-07; loyalty.md §2.1).

### 1.3 Placing the order

`PlaceOrder(quoteId)` — a quote of this buyer, not expired. **One transaction**, in this order:

1. **The order's public number** — the store's code and the store's own sequence, **`SA-10428`**
   (owner, 2026-10-09) — taken first, because Promotions and Loyalty record it. A database sequence per
   store: a placement that fails leaves a gap rather than making every placement in the store wait on a
   lock. My assumption, stated for the owner to reject. The internal id is never shown (handoff §5.3).
2. **Every rule of the quote that can change in 15 minutes, again**: `customerMayOrder` and, for a
   company, `isApproved` (a company suspended meanwhile cannot order, handoff §8.2); the address still
   there and accepted; the categories still open to the account type; the bank account still filled in
   when the way to pay is a bank transfer.
3. `PromotionsApi::useCoupon` (every check again; `CouponChanged` if the discount moved) and
   `LoyaltyApi::redeem` (`RedemptionChanged` if the points moved).
4. `InventoryApi::hold` — **all or nothing**: one hold line per variant for the bought pieces (its
   retail and wholesale lines added together) and its own line for the gift (`gift = true`, PR #106);
   `not_enough_stock` names the variants.
5. `PromotionsApi::recordOrder` — the fact segments count.
6. The order is written **with everything snapshotted** (handoff §12.3): the customer's name, email and
   phone; the delivery address; each line's product and variant names and values in both languages,
   its code (staff only — a code is never shown to a customer, catalog.md amendment 5(d)), sale mode,
   quantity, unit and list price, its share of the coupon, of the points and of the VAT (§1.8); the
   gift; every total; the VAT rate; the shipping fee and the free-above amount; the audience; the way
   to pay.
7. `OrderPlaced` is written to the outbox (§6).

**Any refusal rolls everything back** and sends the customer to a new quote with the reason (`QuoteChanged`).
**Placing twice is placing once**: the order keeps its quote's id (unique), and a repeated
`PlaceOrder` answers the order already placed. A stock hold has **no expiry** (`expiresAt` null,
inventory.md §2.1): an unpaid order waits until staff cancel it (owner, 2026-10-09).

### 1.4 The order's four state machines

**Four independent machines** — the order, its payment, its shipment, its returns; **the customer sees
one derived status**, staff see all four (handoff §12.3). Every order — individual or company — **passes
staff approval** (handoff §12.3). **Every change locks the order first**, so a cancellation, a shipping
and an edit can never cross.

- **The order**: `PENDING_APPROVAL` → `APPROVED`, or `REJECTED`; `CANCELLED` (§1.6); `COMPLETED` when
  delivered.
- **The shipment — one per order** (owner, 2026-10-10: "staff will send them all at one shipment"):
  `NOT_SHIPPED` → `SHIPPED` → `DELIVERED`. Shipped only once `APPROVED`; **staff mark it delivered** —
  customers cannot (owner, 2026-10-10).
- **The payment** — derived from the money recorded (§1.9): what is due is 0 for a cancelled or rejected
  order, otherwise `order_total` less the refunds owed for completed returns; against it, what was paid
  less what was refunded gives `UNPAID`, `PARTLY_PAID`, `PAID`, `REFUND_DUE` (more paid than due) or
  `REFUNDED` (a refund due fully paid back).
- **The returns** — each: `REQUESTED` → `ACCEPTED` or `REFUSED` (or `WITHDRAWN` by the customer) →
  `RECEIVED` → `COMPLETED`; staff may `CLOSE` an accepted return whose pieces never came (§1.8).

**What the customer sees** (handoff §12.3: New · Processing · Shipped · Delivered · Cancelled ·
Refunded): New while waiting for approval; Processing once approved; Shipped; Delivered; Cancelled when
cancelled or rejected; Refunded when the money for a cancelled or wholly returned order has come back.

**Shipping unpaid orders**: staff may approve an unpaid order; **whether it may ship before it is paid is
decided by staff per order** (owner, 2026-10-10) — an explicit, audited "may ship unpaid". Without it, an
unpaid or partly paid order cannot be marked shipped.

### 1.5 Shipping and delivery

- **Marking shipped**: the carrier and a tracking number (free text until stage 7);
  `InventoryApi::ship` takes the stock off (in a store with no provider; a wired store's provider is the
  source); `NotShippable` if the order is flagged "not enough stock" (§1.11) or unpaid without "may
  ship unpaid".
- **"Reduced in the provider"**, in a wired store: per variant, for stock-dependent variants and for
  the gift, staff tick once they have reduced its stock in the provider (handoff §12.3) —
  `InventoryApi::reducedInProvider`, which ends that variant's hold lines.
- **Marking delivered** (staff) is what:
  - earns **points** on the order's `goods_total` — `LoyaltyApi::earn` (loyalty.md §1.6);
  - starts **the return window** (§1.8);
  - makes its products **reviewable** (feedback.md §1.1);
  - writes `OrderDelivered` to the outbox (§6).

### 1.6 Cancellation

- **The customer cancels until the order ships** — once shipped it can only be returned (handoff
  §12.3; owner, 2026-10-10: "the shipping is out, it will reach customer"). **A reason from a list, or
  "Other" with text** (owner, 2026-10-09): changed my mind, ordered by mistake, found it cheaper,
  delivery too slow, other. If the order was **already paid**, the cancellation shows a note: **staff
  will contact you about the refund** (owner, 2026-10-09).
- **Staff cancel or reject** before shipping, with a reason.
- Either way: `InventoryApi::release`; `LoyaltyApi::orderCancelled` (points used come back with their
  old dates); the coupon's use **stays counted** (owner, 2026-10-08); what is due becomes 0, so a paid
  order shows a refund due (§1.4); `OrderCancelled` is written to the outbox.

### 1.7 Staff edits before shipping

**Staff contact the customer before shipping** and settle anything missing with them (owner,
2026-10-10); **they may then fully edit the order** (owner, 2026-10-10): add lines, remove lines,
change quantities, change the delivery address to another of the customer's addresses in that store.
Edits are allowed while the order is not shipped and **no line is ticked "reduced in the provider"**
(Inventory then refuses, `inventory.hold_not_editable`). Each edit has its own id, a reason, and is
audited.

- **Prices** — **lines kept keep their order price; added pieces take today's price** (owner,
  2026-10-10). A raised quantity adds a **part** to the line, priced by Pricing for the added pieces
  (pricing.md, the addition for edits — for a wholesale line, today's band for the line's new total
  quantity, applied to the added pieces; my assumption, stated for the owner to reject). A lowered
  quantity removes the newest part's pieces first. **Pricing works out the edited order's totals**
  from its kept and added parts, **at the order's own VAT rate**.
- **Points, then the coupon** (owner, 2026-10-10: "checked again; trimmed or removed if they no longer
  fit"; the coupon "can only stay or shrink"; handoff §11.3: points give way first):
  1. the coupon worked out again on the edited lines — `PromotionsApi::couponAfterEdit`, never more
     than at placement, 0 if it no longer applies;
  2. the points lowered to fit the cap and the room left after the coupon — `LoyaltyApi::orderEdited`
     gives back the points above it, with their old dates;
  3. only if the coupon alone passes the room, the coupon trimmed to it;
  4. the use's new discount recorded — `PromotionsApi::updateCouponUse` (it still counts).
- **Stock** — `InventoryApi::adjustHold(orderId, newLines)`, all or nothing (PR #106).
- **The gift — staff decide** (owner, 2026-10-10): keep it, swap it for the gift of the level the edited
  order now reaches (up or down, stock permitting), or remove it.
- **Shipping** — worked out again by the order's own snapshotted fee and free-above amount, so an edit
  never changes the store's rule for an order already placed. My assumption, stated for the owner to
  reject.
- **The shares are snapshotted again** — each line's coupon, points and VAT shares — so returns settle
  on the edited order.
- **A difference in money is recorded, and staff settle it by hand** (owner, 2026-10-10): paid more than
  the new total → **refund due**; less → **balance due**, and shipping before then follows "may ship
  unpaid" (§1.4).
- Editing clears the "not enough stock" flag when the new hold succeeds (§1.11).

### 1.8 Returns, refunds and their shares

- **The customer asks**, within **the store's return window — 14 days from delivery by default**,
  admin-set per store (owner, 2026-10-09): which pieces and how many, **a reason from a list or
  "Other"** (damaged, wrong item, not as described, changed my mind, other), and **up to 3 photos**,
  kept private for staff (owner, 2026-10-10; Platform's `uploadMediaFor`, registered as a media use so
  a photo in a return is never deleted, handoff §5.5). A request counts the pieces of every return not
  refused, withdrawn or closed, so two requests can never claim the same piece. The customer may
  withdraw a request until staff decide.
- **Staff accept or refuse** it, with a reason. Return shipping is paid by the company (handoff §12.5);
  the refund is manual (handoff §12.4). An accepted return whose pieces never arrive may be **closed**.
- **Staff mark the pieces received**, choosing which go back into stock — a damaged piece may be left
  out — `InventoryApi::returned(orderId, returnId, lines)` (PR #106): restocked in a store with no
  provider and for a wired store's stock-dependent variants; a wired store's ordinary products follow
  the provider (owner, 2026-10-09).
- **Completing it**:
  - **the refund due** = for each returned piece, its goods share (its net amount, less its shares of
    the coupon and the points) **plus its share of the order's VAT**; the shipping fee and its VAT
    share are refunded **only when the whole order has been returned** (my assumption, stated for the
    owner to reject). The shares are fixed at placement (and after an edit) with `Money::allocate`: the
    order's VAT over its lines and its shipping in proportion to their taxable amounts, then each line's
    shares over its pieces — so **returning everything refunds exactly `order_total`**, no halala lost
    (handoff §5.1);
  - `LoyaltyApi::orderReturned` with the returned pieces' shares of `goods_total` and of the points
    discount — by running totals, which on a full return add up exactly to the order's (loyalty.md
    §1.8);
  - `ReturnCompleted` written to the outbox (§6) — Feedback flips its reviews (feedback.md).
- **A gift is kept** after a partial return (promotions.md §1.7).

### 1.9 Money: the ways to pay, payments and refunds

**The ways to pay, by account type** (owner, 2026-10-09):

| Account | Ways |
|---|---|
| Individual | **Online** (Payments, stage 7) **or "staff will contact you"** |
| Company | **Bank transfer** (to the store's account, `B2BApi::bankAccount` — off while the store has not filled it in, b2b.md amendment 13(c)) **or "staff will contact you"** — never online |

**No cash on delivery** in any store (handoff §12.4, §16). Tamara and Tabby are for individuals only
(handoff §12.4) — Payments' (stage 7).

- **"Staff will contact you"**: the order is placed unpaid; staff arrange payment and record it.
- **Until stage 7** there is no online payment and no receipt upload: individuals see "staff will
  contact you"; companies see the bank details and "staff will contact you"; **staff record every
  payment by hand** — amount, way, reference, date.
- **Refunds are all manual** (handoff §12.4): staff record each one — amount, way, reference, date.
- **Sales keeps the money recorded against each order** (`sales.payments`, `sales.refunds`), from which
  the payment state follows (§1.4). From stage 7, Payments keeps its gateway transactions and tells
  Sales of each payment through `PaymentSucceeded` / `PaymentFailed`; Sales records it here.

### 1.10 Store settings

Declared by Sales, per store (owner, 2026-10-10: "a flat shipping fee per store until stage 7"):

| Key | Type | Range | Default | Means |
|---|---|---|---|---|
| `sales.shipping.flat_fee` | Integer, the currency's smallest unit | 0–100,000,000 | 0 | The one fee every order pays (the panel shows it in the currency, e.g. 25.00) |
| `sales.shipping.free_above` | Integer, the smallest unit | 0–1,000,000,000 | 0 | `goods_total` from which shipping is free; **0 means never free** (handoff §10.2: free shipping binds `goods_total`) |
| `sales.returns.window_days` | Integer | 1–365 | **14** (owner, 2026-10-09) | The return window from delivery |

Changed under `sales.settings.update`, **admin-only** — they decide money. My assumption, stated for the
owner to reject, with the defaults 0. **Shipping (stage 7) replaces the two shipping settings** with
carriers and rates per store; the return window stays Sales's.

### 1.11 Stock that runs short after placement

Inventory may let staff remove stock that is held for orders, and then publishes `HoldsShort(storeId,
variantId, orderIds)` (inventory.md). **Sales flags those orders "not enough stock"** for staff — who
contact the customer and edit (§1.7), cancel, or wait. A flagged order cannot be marked shipped; the
flag clears when an edit's new hold succeeds, or when staff clear it after stock is back.

### 1.12 A deleted customer, an off store, each store's rows

- **Orders keep their snapshot of name, phone and delivery address** and are never anonymized (handoff
  §7.9: a financial record). **The email snapshot is cleared**, since §7.9 keeps only those three —
  my assumption, stated for the owner to reject. An anonymized account's carts are deleted.
- **An off store's open orders stay with staff to finish** (handoff §1); its shop and new orders are
  gone with it.
- Carts, quotes, orders and returns belong to a store; one repository per kind names the store in every
  call (loyalty.md §1.12); store-binding composite keys (§5). The nightly jobs are the passes across
  stores.

### 1.13 Best-selling

Catalog's "Best-Selling" sort waits for Sales (catalog.md §2.2: `ListingFacts::salesRanks`). **A nightly
job ranks each store's products by the pieces sold in that store's orders not cancelled over the last
90 days**, and pushes the ranks. My assumption for the measure and the window, stated for the owner to
reject.

---

## 2 · Public contract

### 2.1 `Modules\Sales\Public\Contracts\SalesApi`

**Declared on `main` before Feedback is built** (handoff §17, amended; feedback.md §2.1), with
`ReturnCompleted`:

| Method | Answers |
|---|---|
| `deliveredPurchase(string $customerId, string $productId): ?DeliveredPurchaseDto` | Whether the customer had that product delivered in any store: `variantId` (the latest delivered, by delivery time), `deliveredAt`, `returned` (any of that product in a completed return) |
| `returnedProducts(string $returnId): ReturnedProductsDto` | `customerId`, `storeId`, `productIds` of a completed return |

### 2.2 Events (`Public/Events`, ids only — handoff §4.5)

| Event | Fields |
|---|---|
| `OrderPlaced` | `eventId`, `orderId`, `storeId`, `customerId`, `occurredAt` (handoff §4.5's shape) |
| `OrderCancelled` | `eventId`, `orderId`, `storeId`, `customerId`, `occurredAt` |
| `OrderDelivered` | `eventId`, `orderId`, `storeId`, `customerId`, `occurredAt` |
| `ReturnCompleted` | `eventId`, `returnId`, `orderId`, `storeId`, `customerId`, `occurredAt` |

### 2.3 What Sales needs from other modules

| From | What | State |
|---|---|---|
| Pricing | `prices`, `totals`; **the totals of an edited order over kept and added parts, at the order's VAT rate, and the price of added pieces** | `prices`, `totals` exist (#97); **the edit addition is asked of the stage 5 session** (2026-10-10) for pricing.md |
| Inventory | `stock` (with `availableAsGift`), `hold`, `ship`, `reducedInProvider`, `release`, `HoldsShort`; `adjustHold`, `returned` | Interfaces on `main` (#97); `HoldsShort` specified (#102); **`adjustHold` and `returned` in PR #106** (proposed) — Sales is built against them once merged |
| Promotions | `applyCoupon`, `customerDiscountRoom`, `giftLevels`, `useCoupon`, `recordOrder`, `couponAfterEdit`, `updateCouponUse` | promotions.md (PR #101) |
| Loyalty | `earningPreview`, `quoteRedemption`, `redeem`, `earn`, `orderCancelled`, `orderReturned`, `orderEdited` | loyalty.md (PR #96) |
| Catalog | `storeVariant`, `variant`, `product`; **whether a product's category is open to an account type in a store** (catalog.md §1.13), in bulk; the bulk reads Feedback adds (snapshots); `ListingFacts::salesRanks`; the usage check (a variant on an order is never deleted, promotions.md §1.2) | `storeVariant`… and `ListingFacts` exist; **the category read is a Catalog addition** — to agree with the Catalog-screens session, who own §1.13 |
| Access | `customerMayOrder`, `customer`, `addresses`, `address`, `GuestBecameCustomer`, `CustomerAnonymized` | Exist |
| B2B | `isApproved`, `bankAccount` | Exist |
| Platform | Settings, the audit log, `uploadMediaFor` and media uses (return photos), staff names, the store's code | Exist |
| Shared | **`outbox_messages` and `processed_events`, in the `public` schema** (platform.md §5.6) | **Built with Sales** — the first module to publish through the outbox |

### 2.4 What Sales defines for stage 7 to fill

Declared in those modules' `Public/Contracts`, implemented in stage 7 — **with their own types only**
(handoff §4.4: Shipping → Platform, Catalog; Payments → Platform):

- **`ShippingApi::options(StoreId, ShippingDestinationDto, list<ParcelLineDto>, Money $goodsTotal):
  list<ShippingOptionDto>`** — the destination as Shipping's own DTO (country, city, district, postal
  code — never Access's address); each option a carrier, its fee (free-shipping thresholds applied,
  handoff §12.5) and its tracking URL template. Until stage 7 Sales uses its flat fee (§1.10).
- **`PaymentsApi::methods(StoreId, PaymentAudience, Money $amount)`** and **`start(orderId, method)`** —
  `PaymentAudience` (`INDIVIDUAL`, `COMPANY`) is Payments' own enum; Tamara/Tabby for individuals only.
  Payments publishes `PaymentSucceeded` / `PaymentFailed` (handoff §4.5). Until stage 7 no online method
  exists (§1.9).

---

## 3 · Use cases

Every command and query handler authorizes first (`CommandHandlersAuthorizeTest`); staff actions are
audited. **The permissions Sales declares** (three-part names):

| Permission | Audience | Kind | Group | Admin-only | For |
|---|---|---|---|---|---|
| `sales.cart.manage` | every customer | per store | — | — | Their cart: lines, the coupon code |
| `sales.guest_cart.manage` | every guest | per store | — | — | A guest's cart |
| `sales.order.place` | every customer | per store | — | — | Checkout and placing — **per store**, since Promotions and Loyalty check it in the order's store |
| `sales.order.manage_own` | every customer | per store | — | — | Cancel their order, ask for, withdraw a return, upload its photos (the scope of `uploadMediaFor`) |
| `sales.order.view_own` | every customer | global | — | — | Their carts, orders, returns, their return photos |
| `sales.order.view` | Role | per store | `Orders` | no | A store's orders — all, unpaid, flagged "not enough stock" (handoff §14: Sales › All orders · Store orders · Unpaid orders); its returns queue and the return photos; printing |
| `sales.order.approve` | Role | per store | `Orders` | no | Approve, reject |
| `sales.order.edit` | Role | per store | `Orders` | no | Staff edits (§1.7); clearing the "not enough stock" flag |
| `sales.order.cancel` | Role | per store | `Orders` | no | Cancel before shipping |
| `sales.order.fulfil` | Role | per store | `Orders` | no | Ship, deliver, "reduced in the provider", "may ship unpaid" |
| `sales.payment.record` | Role | per store | `Orders` | no | Record a payment or a refund |
| `sales.return.manage` | Role | per store | `Orders` | no | Accept, refuse, receive, close, complete a return |
| `sales.settings.update` | Role | per store | `Orders` | **yes** | §1.10 |
| `sales.system.run` | Role, reserved | global | — | — | The system's jobs: emptying old carts, deleting expired quotes, relaying the outbox, pushing sales ranks |

---

## 4 · State machines

```
ORDER      PENDING_APPROVAL ─▶ APPROVED ─▶ COMPLETED (delivered)
           PENDING_APPROVAL ─▶ REJECTED
           PENDING_APPROVAL | APPROVED ─▶ CANCELLED      (not shipped)

SHIPMENT   NOT_SHIPPED ─▶ SHIPPED ─▶ DELIVERED            (one per order; shipped only APPROVED,
                                                          not flagged short, paid or "may ship unpaid")

PAYMENT    derived: UNPAID · PARTLY_PAID · PAID · REFUND_DUE · REFUNDED

RETURN     REQUESTED ─▶ ACCEPTED ─▶ RECEIVED ─▶ COMPLETED
           REQUESTED ─▶ REFUSED | WITHDRAWN
           ACCEPTED  ─▶ CLOSED                            (pieces never came)
```

Edits: while not shipped and no line ticked "reduced in the provider".

---

## 5 · Tables

Schema `sales`; the outbox and `processed_events` in `public` (platform.md §5.6). ULID ids;
`bigint` identity for high-volume rows (handoff §5.3); amounts in the smallest unit (`bigint`) with
`currency_code`; **every column NOT NULL unless marked NULL**; every rule also checked in code first
(handoff §5.3); every CHECK touching a nullable column says `IS NOT NULL` where a value is needed, and
every kind column has a CHECK `IN (…)` (lessons 35, 162); every `store_id` → `platform.stores`
RESTRICT, and rows of one order carry its store through composite keys on (`id`, `store_id`).

| Table | Columns | Rules |
|---|---|---|
| `sales.carts` | `id`, `store_id`, `customer_id` NULL → `access.customers` CASCADE, `guest_id` NULL, `coupon_code` NULL, `notice` NULL (the lines removed at sign-in), `created_at`, `updated_at` | CHECK `num_nonnulls(customer_id, guest_id) = 1`; UNIQUE (`store_id`, `customer_id`), UNIQUE (`store_id`, `guest_id`) |
| `sales.cart_lines` | `cart_id` → carts CASCADE, `variant_id` → `catalog.variants` CASCADE, `sale_mode`, `quantity`, `added_at` | PK (`cart_id`, `variant_id`, `sale_mode`); CHECK `sale_mode IN ('RETAIL','WHOLESALE')`, `quantity BETWEEN 1 AND 1000000` |
| `sales.quotes` | `id`, `store_id`, `customer_id` → `access.customers` CASCADE, `address_id`, `coupon_code` NULL, `points_asked` NULL, `way_to_pay`, `result` jsonb (the itemised §1.2), `expires_at`, `created_at` | CHECK `way_to_pay IN ('ONLINE','BANK_TRANSFER','STAFF_CONTACT')`; deleted nightly once expired |
| `sales.orders` | `id`, `store_id`, `number`, `quote_id`, `customer_id` → `access.customers` RESTRICT, `audience`, `locale`, `currency_code`; the snapshot: `customer_name`, `customer_email` NULL (cleared on anonymizing), `customer_phone`, `address` jsonb; `status`, `shipment_status`, `payment_way`, `coupon_id` NULL, `coupon_code` NULL, `coupon_discount_minor`, `points_used`, `points_discount_minor`, `gross_subtotal_minor`, `net_subtotal_minor`, `goods_total_minor`, `shipping_fee_minor`, `shipping_free_above_minor`, `taxable_base_minor`, `vat_minor`, `vat_rate_basis_points`, `order_total_minor`, `due_minor`, `paid_minor`, `refunded_minor`, `may_ship_unpaid`, `short_of_stock_at` NULL, `gift_level_id` NULL, `placed_at`, `decided_by` NULL → `access.staff_users`, `decided_at` NULL, `decision_reason` NULL, `cancel_reason` NULL, `cancel_other` NULL, `cancelled_by` NULL, `cancelled_at` NULL, `carrier` NULL, `tracking_number` NULL, `shipped_at` NULL, `delivered_at` NULL | UNIQUE (`store_id`, `number`); UNIQUE (`quote_id`); UNIQUE (`id`, `store_id`); CHECK `audience IN ('PUBLIC','COMPANY')`, `status IN ('PENDING_APPROVAL','APPROVED','REJECTED','CANCELLED','COMPLETED')`, `shipment_status IN ('NOT_SHIPPED','SHIPPED','DELIVERED')`, `payment_way IN (…)`; CHECK `(shipment_status = 'NOT_SHIPPED') = (shipped_at IS NULL)`, `(shipment_status = 'DELIVERED') = (delivered_at IS NOT NULL)`; CHECK `(status = 'CANCELLED') = (cancelled_at IS NOT NULL)`, a cancellation never after shipping; CHECK `(status IN ('APPROVED','REJECTED','COMPLETED')) = (decided_at IS NOT NULL)` — `REJECTED` also cancels; CHECK every amount `>= 0`; CHECK `(coupon_id IS NULL) = (coupon_discount_minor = 0 AND coupon_code IS NULL)`, `(points_used = 0) = (points_discount_minor = 0)` |
| `sales.order_lines` | `id`, `order_id`, `store_id`, `product_id` → `catalog.products` RESTRICT, `variant_id` → `catalog.variants` RESTRICT, `edit_id` NULL (the part added by an edit), `is_gift`, the snapshot (`name_ar`, `name_en`, `variant_values` jsonb, `code`), `sale_mode`, `quantity`, `unit_minor`, `list_unit_minor`, `total_minor`, `coupon_share_minor`, `points_share_minor`, `vat_share_minor`, `returned_quantity` | (`order_id`, `store_id`) → orders (`id`, `store_id`) RESTRICT; CHECK `quantity >= 1`, `returned_quantity BETWEEN 0 AND quantity`; CHECK a gift line has `unit_minor = 0` and every share 0; one gift line per order (partial UNIQUE) |
| `sales.order_ticks` | `order_id`, `store_id`, `variant_id`, `ticked_by` → `access.staff_users`, `ticked_at` | PK (`order_id`, `variant_id`) — "reduced in the provider" |
| `sales.order_edits` | `id`, `order_id`, `store_id`, `reason`, `by` → `access.staff_users`, `at`, `before` jsonb, `after` jsonb | append-only (trigger); the edit's id is Loyalty's and Promotions' idempotency key |
| `sales.payments`, `sales.refunds` | `id`, `order_id`, `store_id`, `amount_minor`, `currency_code`, `way`, `reference` NULL, `recorded_by` NULL, `recorded_at` | CHECK `amount_minor > 0`; `way IN (…)`; UNIQUE (`way`, `reference`) for gateway references (stage 7); append-only (trigger) |
| `sales.returns` | `id`, `order_id`, `store_id`, `status`, `reason`, `reason_other` NULL, `staff_reason` NULL, `requested_at`, `decided_by` NULL, `decided_at` NULL, `received_at` NULL, `completed_at` NULL, `refund_due_minor`, `shipping_refunded` | CHECK `status IN ('REQUESTED','ACCEPTED','REFUSED','WITHDRAWN','RECEIVED','COMPLETED','CLOSED')`, `reason IN (…)`; CHECK `(reason = 'OTHER') = (reason_other IS NOT NULL)`; CHECK each timestamp present exactly in the states past it |
| `sales.return_lines` | `return_id`, `order_line_id`, `quantity`, `restocked_quantity` | PK (`return_id`, `order_line_id`); CHECK `quantity >= 1`, `restocked_quantity BETWEEN 0 AND quantity` |
| `sales.return_photos` | `return_id`, `media_id` → `platform.media` RESTRICT, `position` | PK (`return_id`, `position`); CHECK `position BETWEEN 1 AND 3` — at most three |
| `sales.order_history` | `id` bigint identity, `order_id`, `store_id`, `kind`, `edit_id` NULL, `by` NULL, `at`, `detail` jsonb | append-only (trigger) — every state change, edit and money record |
| `public.outbox_messages` | `id` bigint identity, `event_id` uuid UNIQUE, `type`, `payload` jsonb (ids only), `created_at`, `dispatched_at` NULL, `attempts` | Written in the change's transaction; a queued relay (`sales.system.run`) dispatches the undispatched in `id` order, marks each dispatched, retries with a growing wait; dispatched rows kept 30 days. **At least once**: every consumer is idempotent |
| `public.processed_events` | (`event_id` uuid, `listener`) PK, `processed_at` | platform.md §5.6 |

**Indexes:** orders by (`store_id`, `placed_at` DESC, `id`) — the lists, keyset-paged (handoff §5.4); by
`store_id` WHERE `paid_minor < due_minor` — unpaid; by `store_id` WHERE `short_of_stock_at IS NOT
NULL`; by (`customer_id`, `placed_at` DESC). Order lines by `order_id`; by (`product_id`) WHERE the
order is delivered — `deliveredPurchase`. Returns by (`store_id`, `status`, `requested_at`); by
`order_id`. Carts by `guest_id`, by `customer_id`, by `updated_at` — the 90-day job. Quotes by
`expires_at`. Outbox by `id` WHERE `dispatched_at IS NULL`.

---

## 6 · Events

**Published, through the outbox** (handoff §4.5 — critical, ids only, §2.2): `OrderPlaced`,
`OrderCancelled`, `OrderDelivered`, `ReturnCompleted`. **Sales builds the shared outbox and
`processed_events`** (platform.md §5.6): the first module publishing through it (B2B's
`CompanyStatusChanged` is published without one, as built).

**Consumed:**

| Event | From | Sales does |
|---|---|---|
| `GuestBecameCustomer` | Access | Moves or merges the guest's cart; lines the account may not buy leave with a notice (§1.1) |
| `CustomerAnonymized` | Access | Deletes the account's carts; clears the email on its orders (§1.12) |
| `HoldsShort` | Inventory | Flags the named orders "not enough stock" (§1.11) |
| `PaymentSucceeded`, `PaymentFailed` | Payments (stage 7) | Records the payment, or leaves the order unpaid |

Each consumer records `processed_events`, so a message delivered twice is acted on once.

---

## 7 · Errors

Each extends `SalesError`, which extends `Shared\Domain\Error\DomainError`. Other modules' refusals at
placement — Promotions' `CouponRefused` / `CouponChanged`, Loyalty's `RedemptionChanged` /
`RedemptionRefused` / `PointsProgrammeOff`, Inventory's `inventory.not_enough_stock` — reach the
customer as `QuoteChanged`, with the reason.

| Error | Status | When |
|---|---|---|
| `MayNotOrder` | FORBIDDEN | `customerMayOrder` false, or a company not approved in this store |
| `NotForYourAccount` | FORBIDDEN | A wholesale line for an individual; a product in a category hidden from the account's type |
| `NotOrderable` | UNPROCESSABLE | A line not orderable here, in its mode, unpriced, or outside its minimum and maximum; not enough stock |
| `AddressNotUsable` | UNPROCESSABLE | No address in this store, or its format no longer accepted |
| `WayToPayNotOffered` | UNPROCESSABLE | A way to pay not offered to this account (online to a company; a bank transfer while the store has none) |
| `SignInToUseCoupon` | UNPROCESSABLE | A guest's coupon code |
| `QuoteNotFound` | NOT_FOUND | A quote that is not this buyer's — the same answer as an unknown one |
| `QuoteExpired` | CONFLICT | Placing after 15 minutes or past the prices' end — checkout quotes again |
| `QuoteChanged` | CONFLICT | Anything of §1.3 step 2–4 no longer as quoted |
| `NotCancellable`, `NotEditable` | CONFLICT | Shipped; or a line ticked "reduced in the provider" |
| `NotShippable` | CONFLICT | Not approved; flagged "not enough stock"; unpaid without "may ship unpaid" |
| `ReturnNotAllowed` | CONFLICT | Outside the window; more pieces than delivered and not already in a return; more than 3 photos |
| `InvalidOrderChange` | CONFLICT | A change its state does not allow |
| `OrderNotFound`, `ReturnNotFound` | NOT_FOUND | Unknown, or of a store the reader does not cover |
| `InvalidSalesAttribute` | UNPROCESSABLE | A value refused |

---

## 8 · Test scenarios

**Cart** — permissive adds; the guest cookie written with the first line; a guest's wholesale lines and
lines of categories hidden from individuals leave on sign-in as an individual, with a notice; wholesale
stays for a company; moving on registration, merging on sign-in; "sign in to use a coupon"; 90 days.

**Quote** — each of §1.2's steps refusing in turn; a line Pricing cannot price refused; the fixed order
(coupon before points; points skipped when the coupon forbids them; points refused whole beyond the
room); free shipping on `goods_total`, 0 meaning never; the gift falling to the next level when out of
stock, counting the order's own pieces; the ways to pay by account type, bank transfer hidden without
an account; 15 minutes and Pricing's `validUntil`.

**Placing** — a company suspended, an address removed, a category hidden, the bank account emptied,
the coupon changed, points spent elsewhere, stock gone — each refuses and nothing is held, used,
recorded or numbered; a retail and a wholesale line of one variant held as one line, the gift as its
own; placing twice answers one order; numbers per store; the snapshot unchanged when the catalog
changes.

**Orders and shipping** — approval; unpaid orders wait and do not ship without "may ship unpaid";
`ship` called on shipping; "reduced in the provider" per variant, the gift included; delivered by
staff earns points, starts the window and makes the products reviewable; `HoldsShort` flags, a flagged
order cannot ship, the flag clears after a successful edit; an off store's open orders still worked by
staff.

**Cancelling** — until shipped; the reason list and "Other"; the paid note; stock freed, points back,
the coupon use kept, what is due 0 and a refund due shown.

**Edits** — each kind; kept prices kept, added pieces at today's with the band rule; Pricing's totals at
the order's VAT rate; points giving way before the coupon; the coupon never growing, removed under its
minimum; stock adjusted all or nothing; refused once a line is ticked; the gift kept, swapped or removed
by staff; shares re-snapshotted; refund due and balance due recorded.

**Returns and money** — within 14 days of delivery; pieces, a reason, up to 3 private photos; two
requests never claiming one piece; withdraw; accept, refuse, close; received with damaged pieces left
out of stock; **the refund including the pieces' VAT, and a full return refunding exactly
`order_total`**, shipping only then; points settled by running totals; `ReturnCompleted` flips
Feedback's reviews; payments and refunds recorded by hand; the payment state through cancellation,
returns and edits.

**Events and the outbox** — each critical event written in the same transaction as its change,
dispatched at least once, acted on once by each consumer; the relay's retries.

**The database** — every CHECK refused with its nullable columns left NULL and an unknown kind (lesson
162); composite keys refusing a line, edit, payment or return of another store's order; append-only
tables refusing UPDATE and DELETE.

---

## 9 · Open questions

### 9.1 The owner's answers

1. **Interfaces now, stage 7 fills them** (2026-10-07).
2. Guests may add wholesale lines; **removed with a notice** on signing in or registering as an
   individual (2026-10-09).
3. **Pay at checkout, staff approve after** — individuals online or staff contact; **companies bank
   transfer or staff contact, never online** (2026-10-09).
4. Returned pieces go back into stock **when staff mark the return received** — in a store with no
   provider and for a wired store's stock-dependent variants (2026-10-09).
5. Order numbers **per store, with its code** (`SA-10428`) (2026-10-09).
6. Unpaid orders **wait until staff cancel them** (2026-10-09).
7. Returns: **the customer asks, staff accept** (2026-10-09).
8. The return window: **14 days from delivery** (2026-10-09).
9. Cancel reasons: **a list or "Other"**, with a note that staff will contact a paid customer
   (2026-10-09).
10. Shipping unpaid orders: **staff decide per order** (2026-10-10).
11. Staff edits before shipping: **full** (2026-10-10).
12. Order messages: **all with Ops later** (2026-10-10).
13. A guest's coupon: **"sign in to use a coupon"** (2026-10-10).
14. Edited prices: **kept lines keep theirs, added pieces at today's** (2026-10-10).
15. Coupon and points after an edit: **checked again; trimmed or removed if they no longer fit**
    (2026-10-10).
16. A money difference after an edit: **recorded; staff settle it by hand** (2026-10-10).
17. The gift after an edit: **staff decide** — keep, swap to the new level's, or remove (2026-10-10).
18. Until stage 7: **a flat shipping fee per store** (2026-10-10).
19. **Staff mark the order delivered** (2026-10-10).
20. A return request: **pieces, a reason, and photos** (2026-10-10).
21. **One shipment per order** — "staff will send them all at one shipment" (2026-10-10).
22. Nothing is cancelled once shipped; **staff contact the customer before shipping** and settle a
    missing item by an edit (2026-10-10).
23. **A category hidden from an account type is not for sale to it** (2026-10-10).
24. After an edit the coupon **can only stay or shrink** (2026-10-10).

### 9.2 Still open

1. **Shipping and Payments** — stage 7, waiting on the owner's vendor data (handoff §15.1); their
   interfaces are written here (§2.4).
2. **Pricing's addition for edited orders** and **Inventory's `adjustHold` / `returned` (PR #106)** —
   the stage 5 session's; Sales is built against them once on `main`.
3. **Catalog's addition** — whether a product's category is open to an account type, in bulk — to
   agree with the Catalog-screens session.
4. **Sales's screens** — the frontend session's, to confirm.
5. **Every assumption marked above** — merging carts by adding quantities; 90-day carts; order numbers
   from a sequence that may leave gaps; the band rule for added wholesale pieces; an edit keeping the
   order's own shipping rule; shipping refunded only on a whole return; the settings' defaults and
   admin-only permission; best-selling as pieces sold in 90 days; the email cleared from orders on
   anonymizing.
6. **Handoff §12.4's "hold stock while verifying" setting** (bank transfers, stage 7): stock is now
   held for every order from placement until it ships or is cancelled (handoff §12.1, owner
   2026-10-07), so the setting has nothing left to switch — for the owner to confirm it goes, with
   Payments.

### 9.3 The independent review of the draft (2026-10-10)

One read-only review; each finding was checked against the code and the other specs before acting.
**Blockers, fixed:** the coupon after an edit had no contract and broke "never trimmed" — Promotions'
exception and its two calls (`couponAfterEdit`, `updateCouponUse`), with the owner's "only stay or
shrink"; the edited order's two prices on one line had no Pricing contract — Pricing's addition, asked;
returns opening per parcel against points earned on the whole order — the owner's one shipment per
order; **refunds without their VAT** — VAT shares allocated so a full return refunds exactly
`order_total`; §5 not a full table spec. **Should-fix, fixed:** the audience derived and passed;
the order number taken first; the permission table with audiences and kinds (per store, as Promotions
and Loyalty check them); the outbox in the shared tables, its relay, at-least-once; every changeable
rule checked again at placement; `allow_with_points` against the fixed order; one hold line per variant
and the gift's own, the gift check counting the order's pieces, the tick ending the gift's hold, `ship`
on shipping; partial shipments (gone: one shipment); the payment state through cancellation and
returns; Loyalty's shares on Sales's side; an off store's open orders with staff; categories hidden
from an account type (the owner's answer 23); a lock on every order change and returns counting open
requests; idempotent placement; stage 7's interfaces with their own types; the best-selling push;
the email cleared on anonymizing; edits refused once ticked; the public contract's fields and
`ReturnCompleted`; the "not enough stock" flag's end; the handoff's sections amended in place. And the
minor points: settings' units and bounds, checkout's inputs, unpriced lines, shipments only once
approved, withdrawing and closing returns, the media use, the guest cookie, error mapping, variant codes
never shown to customers.
