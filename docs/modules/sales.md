# Sales — Module Specification

**Status:** DRAFT for the owner's review — sections 1–9 written 2026-10-10 from the owner's answers of
2026-10-07 to 2026-10-10 (§9.1). Nothing is built before the owner accepts it; all four of stage 6's
specs come first (owner, 2026-10-07).
**Tier:** 1. **Stage:** 6 (handoff §17), the last of the stage's modules to be built (Loyalty →
Promotions → Feedback → Sales).
**Depends on:** every module above it except Feedback and Sync (handoff §4.4) — Platform, Access, B2B,
Catalog, Pricing, Inventory, Promotions, Loyalty, Shipping, Payments. **Shipping and Payments are
stage 7** and wait for the owner's vendor data (handoff §15.1): Sales states exactly what it needs from
them and works without them until then (owner, 2026-10-07: "interfaces now, stage 7 fills them").
**Source:** `docs/HANDOFF.md` §4.4, §4.5, §6, §7.4, §7.5, §7.8, §7.9, §10.2, §11.3, §12.1–§12.6, §14,
§16, and its amendments to 2026-10-09; `docs/modules/pricing.md` §2, `inventory.md` §2,
`promotions.md`, `loyalty.md`, `feedback.md`; the owner's answers (§9.1); the merged code as of
`4f8fccc`.

Sales owns **the transaction**: the cart, checkout's quote, the order with its four state machines —
the order, its payment, each shipment, each return — cancellations, returns, staff edits before
shipping, and, until stage 7, a flat shipping fee. It is the widest module by design (handoff §4.4):
it asks Pricing for prices and totals, Inventory to hold stock, Promotions for the coupon and the gift,
Loyalty for points — **and owns none of their rules**.

## What this module does not own

| Concern | Owner |
|---|---|
| Prices, every total of handoff §10.2, VAT and its rounding | Pricing (owner, 2026-10-07: "totals math in Pricing, one place") |
| Holding, taking, freeing and restocking stock | Inventory |
| Coupons, the discount ceiling, segments' order facts, gift levels | Promotions |
| Points | Loyalty |
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

- **One cart per buyer per store** — a customer's or a guest's (a guest's id lives in Access's cookie,
  access.md §1.7). Lines: a variant, its sale mode (`RETAIL` / `WHOLESALE`), a quantity; the same
  variant and mode once (Pricing refuses duplicates, `pricing.duplicate_lines`).
- **Guests may add wholesale lines** — guests see everything (the client, via the owner 2026-10-09)
  — but **a guest who signs in or registers as an individual loses them, with a notice** ("wholesale
  is for company accounts"); as a company they stay (owner, 2026-10-09).
- **Moving and merging** (access.md §1.7, owner 2026-09-19): on `GuestBecameCustomer`, a guest's cart
  **moves** to a newly registered account and **merges** into an existing account's cart on sign-in —
  the same variant and mode adding their quantities. My assumption, stated for the owner to reject.
- **A coupon in the cart is for signed-in customers only** — a guest's code box says "sign in to use a
  coupon" (owner, 2026-10-10).
- A cart survives (handoff §12.3: "30+ days"): one untouched for **90 days** is emptied. My
  assumption, stated for the owner to reject.

### 1.2 The quote — checkout

`StartCheckout` turns the cart into a **quote: server-computed, itemised, immutable**, valid for **15
minutes** and never past the prices' own end (`CartPricesDto::$validUntil`) (handoff §12.3,
pricing.md §2.1). **The client never sends an amount** — only the quote's id when it places the order
(handoff §2).

Built in this fixed order (handoff §10.2, §11.3):

1. **Who may order** — `AccessApi::customerMayOrder` (active, email and phone verified, no deletion
   pending) and, for a company account, `B2BApi::isApproved` in this store (handoff §7.4). An
   unapproved company sees the company view but cannot order (owner, 2026-10-07).
2. **What may be bought** — each line orderable here and in its sale mode, within its store's minimum
   and maximum (Catalog `storeVariant`); **a wholesale line only for a company account** (handoff §6,
   amended 2026-10-07); stock (`InventoryApi::stock`: orderable, and enough where stock counts).
3. **The delivery address** — one of the customer's addresses in this store, its format still accepted
   (access.md §1.9; handoff §7.8: "an order needs an address").
4. **Prices** — `PricingApi::prices` (each line's price, `gross_subtotal`, `net_subtotal`).
5. **The coupon** — `PromotionsApi::applyCoupon`, then it must fit the room left under the ceiling
   (`customerDiscountRoom`) — or it is refused whole (promotions.md §1.3, §1.6).
6. **Points** — `LoyaltyApi::quoteRedemption`, capped by Loyalty; then they must fit what the coupon
   left of the room, or **the whole redemption is refused and the points stay** (handoff §11.3).
7. **`goods_total`** — `PricingApi::totals` with the coupon and points, no shipping yet.
8. **The gift** — `PromotionsApi::giftLevels` for `goods_total`, highest first; the first whose
   variant `InventoryApi::stock` says is available as a gift (`availableAsGift`); none, no gift, and
   the quote says so (promotions.md §1.7).
9. **Shipping** — until stage 7, **the store's flat fee, free when `goods_total` reaches the store's
   free-above amount** (owner, 2026-10-10; §1.10). From stage 7, Shipping's options for the address.
10. **The totals** — `PricingApi::totals` again with the shipping: `taxable_base`, VAT (rounded once,
    half up), `order_total`.
11. **The ways to pay** — by account type (owner, 2026-10-09; §1.9).
12. **The points this order would earn** — `LoyaltyApi::earningPreview` (owner, 2026-10-07: shown at
    checkout).

### 1.3 Placing the order

`PlaceOrder(quoteId)` — a quote not expired, by the buyer it was made for. **One transaction**:

1. The quote's rules once more: Promotions' `useCoupon` (every check again, `CouponChanged` if the
   discount moved) and Loyalty's `redeem` (`RedemptionChanged` if the points moved); either refusal
   sends the customer back to a new quote.
2. `InventoryApi::hold` for every line and the gift — **all or nothing**; `not_enough_stock` names the
   variants, and checkout says what is left.
3. `PromotionsApi::recordOrder` — the fact segments count.
4. The order is written **with everything snapshotted** (handoff §12.3: "historical orders never change
   when the catalog changes"): the customer's name, email and phone; the delivery address; each line's
   product and variant names and values in both languages, its code, sale mode, quantity, unit and list
   price and its share of the coupon and the points; the gift; every total; the VAT rate; the shipping
   fee; the way to pay.
5. **Its public number** — the store's code and its own sequence, **`SA-10428`** (owner, 2026-10-09);
   the internal id is never shown (handoff §5.3).
6. `OrderPlaced` is published through the outbox (§6).

A stock hold has **no expiry**: an unpaid order waits until staff cancel it (owner, 2026-10-10).

### 1.4 The order's four state machines

**Four independent machines** — the order, its payment, each shipment, each return; **the customer
sees one derived status**, staff see all four (handoff §12.3). Every order — individual or company —
**passes staff approval** (handoff §12.3).

- **The order**: `PENDING_APPROVAL` → `APPROVED`, or `REJECTED`; `CANCELLED` (§1.6); `COMPLETED` once
  every shipment is delivered.
- **The payment** — from the money recorded against the order total (§1.9): `UNPAID`, `PARTLY_PAID`,
  `PAID`, `REFUND_DUE` (paid more than the total), `REFUNDED`.
- **Each shipment**: `PREPARING` → `SHIPPED` → `DELIVERED`. An order may ship in several shipments.
  **Staff mark a shipment delivered** — customers cannot (owner, 2026-10-10).
- **Each return**: `REQUESTED` → `ACCEPTED` or `REFUSED` → `RECEIVED` → `COMPLETED` (§1.8).

**What the customer sees** (handoff §12.3: New · Processing · Shipped · Delivered · Cancelled ·
Refunded): New while waiting for approval; Processing once approved; Shipped from the first shipment
sent; Delivered when every shipment is delivered; Cancelled when cancelled or rejected; Refunded when
the money for a cancelled or wholly returned order has come back.

**Approval and shipping unpaid orders**: staff may approve an unpaid order; **whether it may ship
before it is paid is decided by staff per order** (owner, 2026-10-10) — an explicit, audited "may ship
unpaid" on that order. Without it, an unpaid order cannot be marked shipped.

### 1.5 Delivery

Marking a shipment delivered (staff) is what:

- starts **points** for the delivered lines once the whole order is delivered —
  `LoyaltyApi::earn` with the order's `goods_total` (loyalty.md §1.6). My assumption, stated for the
  owner to reject: points are earned when **every** shipment is delivered, on the whole order;
- starts **the return window** for those pieces (§1.8);
- makes those products **reviewable** (feedback.md §1.1);
- publishes `OrderDelivered` (§6) when the last shipment is delivered.

In a wired store, an order holding stock-dependent lines carries **"reduced in the provider"** per
line, which staff tick once they have reduced its stock in the provider (handoff §12.3) —
`InventoryApi::reducedInProvider`.

### 1.6 Cancellation

- **The customer cancels until the order ships** — once any shipment is shipped it becomes a return
  (handoff §12.3). **A reason from a list, or "Other" with text** (owner, 2026-10-09): changed my mind,
  ordered by mistake, found it cheaper, delivery too slow, other. If the order was **already paid**,
  the cancellation shows a note: **staff will contact you about the refund** (owner, 2026-10-09).
- **Staff cancel or reject** before shipping, with a reason.
- Either way: `InventoryApi::release`, `LoyaltyApi::orderCancelled` (points used come back with their
  old dates), the coupon's use **stays counted** (promotions.md, owner 2026-10-08), a paid order shows
  a refund due (§1.9), and `OrderCancelled` is published.

### 1.7 Staff edits before shipping

**Staff may fully edit an order before anything of it ships** (owner, 2026-10-10): add lines, remove
lines, change quantities, change the delivery address to another of the customer's addresses in that
store. Each edit is audited with its reason.

- **Prices** — lines kept keep their order price; **added pieces take today's price** (owner,
  2026-10-10). A quantity raised adds the extra pieces at today's unit price for that line, kept beside
  the original pieces. My assumption for the mechanics, stated for the owner to reject.
- **Coupon and points are checked again** on the edited order: a coupon worked out anew on the edited
  lines, **trimmed or removed if it no longer fits** (its use still counts); points above the new cap
  **come back with their old dates** (owner, 2026-10-10) — a Loyalty settlement like a partial return
  (loyalty.md, amended with this spec). Everything stays inside the ceiling.
- **Stock** — `InventoryApi::adjustHold(orderId, newLines)`, all or nothing (inventory.md, the stage 5
  session's addition for this).
- **The gift — staff decide** (owner, 2026-10-10): keep it, swap it for the gift of the level the edited
  order now reaches (up or down, stock permitting), or remove it.
- **Shipping and totals** are worked out again (the flat fee's free-above rule on the new
  `goods_total`; `PricingApi::totals`).
- **A difference in money is recorded, and staff settle it by hand** (owner, 2026-10-10): paid more
  than the new total → **refund due**; less → **balance due**, and shipping it before then follows
  "may ship unpaid" (§1.4).

### 1.8 Returns

- **The customer asks**, within **the store's return window — 14 days from delivery by default**,
  admin-set per store (owner, 2026-10-09): which pieces and how many, **a reason from a list or
  "Other"** (damaged, wrong item, not as described, changed my mind, other), and **up to 3 photos**,
  kept private for staff (owner, 2026-10-10; Platform's private files, `uploadMediaFor`).
- **Staff accept or refuse** it, with a reason. Return shipping is paid by the company (handoff
  §12.5); the refund is manual (handoff §12.4).
- **Staff mark the pieces received**, choosing which go back into stock — a damaged piece may be left
  out — `InventoryApi::returned(orderId, returnId, lines)`: restocked in a store with no provider and
  for a wired store's stock-dependent variants; a wired store's ordinary products follow the provider
  (owner, 2026-10-09; inventory.md).
- **Completing it**: the refund due is recorded (§1.9) — the returned pieces' allocated net amounts,
  less their shares of the coupon and the points; the shipping fee is refunded only when the whole
  order is returned (my assumption, stated for the owner to reject); `LoyaltyApi::orderReturned` with
  the returned shares (loyalty.md §1.8); **`ReturnCompleted`** is published (§6) — Feedback flips its
  reviews (feedback.md).
- **A gift is kept** after a partial return (owner, 2026-10-08).

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
- **Refunds are all manual** (handoff §12.4): staff record each one against the order — amount, way,
  reference, date.
- The payment state follows from the order total, what was paid and what was refunded (§1.4).

### 1.10 The flat shipping fee until stage 7

Per store, Platform settings declared by Sales (owner, 2026-10-10: "a flat shipping fee per store until
stage 7"):

| Key | Scope | Type | Default | Means |
|---|---|---|---|---|
| `sales.shipping.flat_fee` | Store | Integer (the currency's smallest unit) | 0 | The one fee every order pays |
| `sales.shipping.free_above` | Store | Integer (the smallest unit) | 0 (never free) | `goods_total` from which shipping is free (handoff §10.2: free shipping binds `goods_total`) |
| `sales.returns.window_days` | Store | Integer 1–365 | **14** (owner, 2026-10-09) | The return window from delivery |

Changed under `sales.settings.update`, **admin-only**. My assumption, stated for the owner to reject:
the defaults 0 and the admin-only permission. **Shipping (stage 7) replaces the two shipping settings**
with carriers and rates per store; the return window stays Sales's.

### 1.11 Stock that runs short after placement

Inventory may let staff remove stock that is held for orders, and then publishes `HoldsShort(storeId,
variantId, orderIds)` (inventory.md, owner 2026-10-09). **Sales flags those orders "not enough stock"**
for staff to sort out — edit, cancel or wait.

### 1.12 A deleted customer, and each store's rows

- **Orders keep their snapshot** of name, phone and address and are never anonymized (handoff §7.9) —
  they are a financial record. An anonymized account's carts are deleted.
- Carts, orders and returns belong to a store; one repository per kind names the store in every call,
  as the other stage 6 modules (loyalty.md §1.12). An off store's orders are reached by Super Admins
  only (platform.md §9.10).

---

## 2 · Public contract

### 2.1 `Modules\Sales\Public\Contracts\SalesApi`

For **Feedback** (feedback.md §2.3) — **declared on `main` before Feedback is built** (handoff §17,
amended) — and Ops later:

| Method | For |
|---|---|
| `deliveredPurchase(string $customerId, string $productId): ?DeliveredPurchaseDto` | Feedback: whether the customer had that product delivered in any store — per order line, by **the shipment holding it** reaching delivered; the latest variant by delivery time; whether any of it was returned by a **completed** return |
| `returnedProducts(string $returnId): ReturnedProductsDto` | Feedback, on `ReturnCompleted`: the return's customer and product ids |

### 2.2 What Sales defines for stage 7 to fill

**Shipping** (`Modules\Shipping\Public\Contracts\ShippingApi`, declared with Sales, implemented in stage
7): `options(StoreId, address, lines with weights and sizes, goods_total): list<ShippingOptionDto>` —
carrier, fee (free-shipping thresholds applied, handoff §12.5), tracking URL template. Until stage 7
Sales uses its flat fee (§1.10) and never calls it.

**Payments** (`Modules\Payments\Public\Contracts\PaymentsApi`, the same way): `methods(StoreId,
audience, amount)` — gateways, Tamara/Tabby for individuals only — and `start(orderId, method)` → where
to send the customer. Payments tells Sales of a payment through `PaymentSucceeded` / `PaymentFailed`
(handoff §4.5's critical events). Until stage 7 no online method exists, and payments are recorded by
staff (§1.9).

### 2.3 What Sales needs from other modules

| From | What | State |
|---|---|---|
| Pricing | `prices`, `totals` | Exists (#97) |
| Inventory | `stock` (with `availableAsGift`), `hold`, `ship`, `reducedInProvider`, `release`; **`returned`** and **`adjustHold`**; `HoldsShort` | `stock`…`release` exist as interfaces on `main` (#97); `HoldsShort` is specified (inventory.md, #102), not yet built; **`returned` and `adjustHold` are the stage 5 session's next Inventory addition** (agreed 2026-10-10) — Sales is built against them once they are on `main` |
| Promotions | `applyCoupon`, `customerDiscountRoom`, `giftLevels`, `useCoupon`, `recordOrder` | promotions.md (PR #101) |
| Loyalty | `earningPreview`, `quoteRedemption`, `redeem`, `earn`, `orderCancelled`, `orderReturned`, and **a settlement for a staff edit** (§1.7) | loyalty.md (PR #96) — **the edit settlement is added to Loyalty's spec with this one** |
| Access | `customerMayOrder`, `customer`, `addresses`, `address`, `GuestBecameCustomer`, `CustomerAnonymized`; private files for return photos (Platform) | Exist |
| B2B | `isApproved`, `bankAccount` | Exist |
| Catalog | `storeVariant`, `variant`, `product`; the bulk reads Feedback adds (for snapshots) | Exist; bulk reads with Feedback (feedback.md §2.3) |
| Platform | Settings, the audit log, private files, staff names, the store's code for order numbers | Exist |

---

## 3 · Use cases

Every command and query handler authorizes first (`CommandHandlersAuthorizeTest`); staff actions are
audited.

| Use case | Permission |
|---|---|
| Add, change, remove cart lines; enter a coupon (signed in only) | `sales.cart.manage` — every customer; `sales.guest_cart.manage` — every guest |
| Start checkout; place the order | `sales.order.place` — every customer (and `customerMayOrder`) |
| Cancel my order; ask for a return | `sales.order.manage_own` — every customer, their own orders |
| Approve or reject an order | `sales.order.approve` (per store) |
| Edit an order before shipping | `sales.order.edit` (per store) |
| Cancel an order | `sales.order.cancel` (per store) |
| Create shipments; mark shipped, delivered; tick "reduced in the provider"; allow shipping unpaid | `sales.order.fulfil` (per store) |
| Record a payment or a refund | `sales.payment.record` (per store) |
| Accept, refuse, receive, complete a return | `sales.return.manage` (per store) |
| Change the store's flat fee, free-above amount and return window | `sales.settings.update` (per store, **admin-only**) |
| Empty untouched carts | `sales.cart.prune` (reserved: the system, nightly) |

| Read (query) | Permission |
|---|---|
| My carts, orders, returns | `sales.order.view_own` — every customer |
| A store's orders — all, the unpaid ones, those flagged "not enough stock" (admin menu: Sales › All orders · Store orders · Unpaid orders, handoff §14) | `sales.order.view` (per store) |
| Print an order's confirmation / packing document (handoff §12.6) | `sales.order.view` |

**Groups:** staff permissions in the existing `Orders` group (handoff §14's "Sales" section).

---

## 4 · State machines

```
ORDER      PENDING_APPROVAL ─▶ APPROVED ─▶ COMPLETED (every shipment delivered)
           PENDING_APPROVAL ─▶ REJECTED
           PENDING_APPROVAL | APPROVED ─▶ CANCELLED   (nothing shipped)

PAYMENT    derived: UNPAID · PARTLY_PAID · PAID · REFUND_DUE · REFUNDED

SHIPMENT   PREPARING ─▶ SHIPPED ─▶ DELIVERED          (staff; a shipment may ship unpaid only
                                                       when staff allowed it for the order)

RETURN     REQUESTED ─▶ ACCEPTED ─▶ RECEIVED ─▶ COMPLETED
           REQUESTED ─▶ REFUSED
```

Edits: allowed while the order is `PENDING_APPROVAL` or `APPROVED` and no shipment is `SHIPPED`.

---

## 5 · Tables

Schema `sales`. ULID ids; `bigint` for high-volume rows (handoff §5.3); amounts in the smallest unit
with the store's currency; every rule also checked in code first; NULL-safe CHECKs and an `IN` check on
every kind (lessons 35, 162); store-binding composite keys as the other stage 6 modules.

| Table | Holds |
|---|---|
| `sales.carts` | id, store, customer **or** guest (exactly one), updated_at; UNIQUE (store, customer) and (store, guest) |
| `sales.cart_lines` | cart, variant, sale mode, quantity ≥ 1; UNIQUE (cart, variant, sale mode) |
| `sales.quotes` | id, store, buyer, the full itemised result of §1.2 (lines, discounts, gift, shipping, totals, ways to pay), expires_at; deleted once used or expired |
| `sales.orders` | id, store, **number** (UNIQUE per store), customer, the snapshot (name, email, phone, address), status, totals (`gross_subtotal`, `net_subtotal`, coupon, points, `goods_total`, shipping, `taxable_base`, VAT, `order_total`, VAT rate), way to pay, may_ship_unpaid, short_of_stock, placed_at, decided_by/at, cancelled reason and note |
| `sales.order_lines` | order, variant, product and variant names and values (both languages), code, sale mode, quantity, unit and list price, line total, coupon share, points share, gift flag; a line's pieces added by an edit kept as their own part with their own price (§1.7) |
| `sales.order_number_sequences` | store, the next number — taken under a lock in the placing transaction, so numbers never repeat or skip |
| `sales.shipments`, `sales.shipment_lines` | a shipment's status, carrier and tracking (free text until stage 7), its lines and quantities, shipped_at, delivered_at, by whom |
| `sales.payments`, `sales.refunds` | money recorded by staff (later by Payments): amount, way, reference, date, by whom; never deleted |
| `sales.returns`, `sales.return_lines`, `sales.return_photos` | a return's status, reason, staff's reason, its lines (quantity, restocked quantity), up to 3 private photo media ids (RESTRICT to `platform.media`) |
| `sales.order_history` | append-only: every state change and edit, by whom, why |
| **`sales.outbox_messages`**, **`processed_events`** | The transactional outbox for Sales's critical events, and the idempotent consumers' record (handoff §4.5) — **built now: Sales is the first module that publishes a critical event** |

Every column's CHECKs are listed when each table is built, step by step; the rules above are the
contract.

---

## 6 · Events

**Published, through the outbox** (handoff §4.5 — the critical ones, ids only): `OrderPlaced`,
`OrderCancelled`, `OrderDelivered`, `ReturnCompleted`. Payments later publishes `PaymentSucceeded` /
`PaymentFailed`, which Sales consumes.

**Consumed:**

| Event | From | Sales does |
|---|---|---|
| `GuestBecameCustomer` | Access | Moves or merges the guest's cart (§1.1) |
| `CustomerAnonymized` | Access | Deletes the account's carts; orders keep their snapshot |
| `HoldsShort` | Inventory | Flags the named orders "not enough stock" (§1.11) |
| `PaymentSucceeded`, `PaymentFailed` | Payments (stage 7) | Records the payment, or leaves the order unpaid |

Consumers are idempotent by `processed_events` (handoff §4.5), built with this module.

---

## 7 · Errors

Each extends `SalesError`, which extends `Shared\Domain\Error\DomainError`.

| Error | Status | When |
|---|---|---|
| `MayNotOrder` | FORBIDDEN | `customerMayOrder` false, or a company not approved in this store |
| `WholesaleForCompaniesOnly` | FORBIDDEN | A wholesale line in an individual's quote |
| `NotOrderable` | UNPROCESSABLE | A line not orderable here, in its mode, or outside its minimum and maximum; not enough stock |
| `AddressNotUsable` | UNPROCESSABLE | No address in this store, or its format no longer accepted |
| `QuoteExpired` | CONFLICT | Placing after 15 minutes or past the prices' end — checkout quotes again |
| `QuoteChanged` | CONFLICT | The coupon, points or stock no longer match the quote at placement |
| `NotCancellable`, `NotEditable` | CONFLICT | A shipment has shipped |
| `ShipUnpaidNotAllowed` | CONFLICT | Marking an unpaid order shipped without staff's per-order allowance |
| `ReturnNotAllowed` | CONFLICT | Outside the window, more pieces than delivered and not yet returned, more than 3 photos |
| `InvalidOrderChange` | CONFLICT | A change its state does not allow |
| `OrderNotFound`, `ReturnNotFound` | NOT_FOUND | Unknown, or of a store the reader does not cover |
| `InvalidSalesAttribute` | UNPROCESSABLE | A value refused |

---

## 8 · Test scenarios

**Cart** — permissive adds; a guest's wholesale lines leave on sign-in as an individual, stay as a
company; moving on registration, merging on sign-in; "sign in to use a coupon"; 90 days.

**Quote** — each of §1.2's steps refusing in turn; the fixed order (coupon before points; points
refused whole beyond the room); free shipping on `goods_total`; the gift falling to the next level when
out of stock; an individual offered online or staff contact, a company bank transfer or staff contact,
bank transfer hidden while the store has no account; 15 minutes and Pricing's `validUntil`.

**Placing** — one transaction: a coupon changed, points spent elsewhere, stock gone — each refuses and
nothing is held, used or recorded; numbers per store, never repeated, under concurrency; the snapshot
unchanged when the catalog changes.

**Orders** — approval; unpaid orders wait; shipping unpaid only when allowed; partial shipments; staff
marking delivered starts points (whole order), the return window and reviews; "reduced in the
provider" in a wired store; `HoldsShort` flags.

**Cancelling** — until shipped; the reason list; the paid note; stock freed, points back, the coupon
use kept.

**Edits** — each kind; kept prices kept, added pieces at today's; the coupon trimmed or removed; points
back above the cap; stock adjusted all or nothing; the gift kept, swapped or removed by staff; refund
due and balance due recorded; no edit once anything shipped.

**Returns** — within 14 days of delivery; pieces, reason, 3 photos private; accept, refuse, receive
with damaged pieces left out of stock; refund due; points settled; `ReturnCompleted` flips Feedback's
reviews.

**Events and the outbox** — each critical event written in the same transaction and delivered once;
each consumer idempotent.

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
19. **Staff mark a shipment delivered** (2026-10-10).
20. A return request: **pieces, a reason, and photos** (2026-10-10).

### 9.2 Still open

1. **Shipping and Payments** — stage 7, waiting on the owner's vendor data (handoff §15.1); their
   interfaces are written here (§2.2).
2. **Inventory's `returned` and `adjustHold`** — the stage 5 session's next addition; Sales is built
   against them once on `main`.
3. **Loyalty's settlement for a staff edit** — added to loyalty.md (PR #96).
4. **Sales's screens** — the frontend session's, to confirm.
5. **Every assumption marked above** — merging carts by adding quantities; 90-day carts; points earned
   when the whole order is delivered; the mechanics of an edited line's added pieces; shipping refunded
   only on a whole return; the settings' defaults and admin-only permission.
