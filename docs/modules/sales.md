# Sales — Module Specification

**Status:** DRAFT for the owner's review — sections 1–9 written 2026-10-10 from the owner's answers of
2026-10-07 to 2026-10-10 (§9.1), rewritten after two independent reviews (§9.3). Nothing is built
before the owner accepts it; all four of stage 6's specs come first (owner, 2026-10-07).
**Tier:** 1. **Stage:** 6 (handoff §17), the last of the stage's modules to be built (Loyalty →
Promotions → Feedback → Sales).
**Depends on:** every module above it except Feedback and Sync (handoff §4.4) — Platform, Access, B2B,
Catalog, Pricing, Inventory, Promotions, Loyalty, Shipping, Payments. **Shipping and Payments are
stage 7** and wait for the owner's vendor data (handoff §15.1): Sales states exactly what it needs from
them and works without them until then (owner, 2026-10-07: "interfaces now, stage 7 fills them").
**Source:** `docs/HANDOFF.md` §1, §2, §4.4, §4.5, §5.1–§5.5, §6, §7.4, §7.5, §7.8, §7.9, §10.2,
§11.3, §12.1–§12.6, §14, §16 and its amendments to 2026-10-10; `docs/modules/pricing.md` (and PR #107),
`inventory.md` (and PR #106), `catalog.md` §1.13, §2.2, `b2b.md` §9, `platform.md` §5.6, §9.10,
`promotions.md`, `loyalty.md`, `feedback.md`; the owner's answers (§9.1); the merged code as of
`4f8fccc`.

Sales owns **the transaction**: the cart, checkout's quote, the order with its four state machines —
the order, its payment, its shipment, its returns — cancellation, returns, staff edits before shipping,
the bank transfer's document, and, until stage 7, a flat shipping fee. It is the widest module by
design (handoff §4.4): it asks Pricing for prices and totals, Inventory to hold stock, Promotions for
the coupon and the gift, Loyalty for points — **and owns none of their rules**.

## What this module does not own

| Concern | Owner |
|---|---|
| Prices, every total of handoff §10.2, VAT and its rounding — the totals of an edited order too | Pricing (owner, 2026-10-07: "totals math in Pricing, one place"; pricing.md §1.11) |
| Holding, taking, freeing and restocking stock | Inventory |
| Coupons — the edited order's coupon too — the discount ceiling, segments' order facts, gift levels | Promotions |
| Points — the edited order's points too | Loyalty |
| Which categories an account type may see and buy | Catalog (catalog.md §1.13) |
| Carriers, rate tables, tracking links, packaging (stage 7) | Shipping — **a flat fee per store in Sales until then** (owner, 2026-10-10) |
| Gateways and online payment; checking a transfer within a target time (stage 7) | Payments — **staff record payments by hand until then** (§1.9) |
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
  (owner, 2026-10-09). **On signing in or registering**, lines the account may not buy **leave the
  cart with a notice** — wholesale lines for an individual (owner, 2026-10-09), and products in
  categories hidden from the account's type (owner, 2026-10-10: "hidden means not for sale to them");
  as a company, wholesale lines stay.
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
(each has its own `Audience` enum) and snapshots it on the order.

Built in this fixed order (handoff §10.2, §11.3):

1. **The store is on** (`PlatformApi::store` → `isActive`) and **who may order** —
   `AccessApi::customerMayOrder` (active, email and phone verified, no deletion pending) and, for a
   company account, `B2BApi::isApproved` in this store (handoff §7.4). An unapproved company sees the
   company view but cannot order (owner, 2026-10-07).
2. **What may be bought** — each line orderable here in its sale mode, within the store's minimum and
   maximum (Catalog, the store's variants in bulk — §2.3); **a wholesale line only for a company**
   (handoff §6, amended 2026-10-07); **only products whose category is open to the buyer's account
   type in this store** (catalog.md §1.13; owner, 2026-10-10 — a Catalog addition, §2.3); stock
   (`InventoryApi::stock`).
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

1. **The quote is locked** (`SELECT … FOR UPDATE`) and **an order already placed from it is answered
   as it is** — placing twice is placing once, however often the button is pressed (b2b.md §9 #7).
   The order keeps its quote's id (unique), the backstop.
2. **The order's public number** — the store's code in capitals and the store's own sequence,
   **`SA-10428`** (owner, 2026-10-09; the KSA store's code is `sa`, platform.md §5.2) — taken first,
   because Promotions and Loyalty record it. **A database sequence per store**, created by the
   migration for the stores that exist and by a `StoreCreated` listener for each store opened later: a
   placement that fails leaves a gap rather than making every placement in the store wait on a lock.
   My assumption, stated for the owner to reject. The internal id is never shown (handoff §5.3).
3. **Every rule of the quote that can change in 15 minutes, again**: the store still on;
   `customerMayOrder` and, for a company, `isApproved` (a company suspended meanwhile cannot order,
   handoff §8.2); every line still orderable in its mode and within its minimum and maximum (Catalog —
   archived, "Not available now", or no longer chosen by the store); the categories still open to the
   account type; the address still there and accepted; the bank account still filled in when the way
   to pay is a bank transfer.
4. `PromotionsApi::useCoupon` (every check again; refused if the discount moved) and
   `LoyaltyApi::redeem` (refused if the points moved).
5. `InventoryApi::hold` — **all or nothing**, **with no expiry** (`expiresAt` null): an unpaid order
   waits until staff cancel it (owner, 2026-10-09). One hold line per variant for the bought pieces
   (its retail and wholesale lines added together) and its own line for the gift (`gift = true`,
   `HoldLineDto`); `inventory.not_enough_stock` names the variants.
6. `PromotionsApi::recordOrder` — the fact segments count.
7. The order is written **with everything snapshotted** (handoff §12.3): the customer's name, email and
   phone; the delivery address; each line's product and variant names and values in both languages,
   its code (staff only — a code is never shown to a customer, catalog.md amendment 5(d)), sale mode,
   quantity, unit and list price, **its price kind** (Pricing's `PriceKind`, which an edit hands back,
   pricing.md §2.2), its shares of the coupon, the points and the VAT (§1.8); the gift; every total;
   the VAT rate; the shipping fee and the free-above amount; the audience; the way to pay.
8. `OrderPlaced` is written to the outbox (§6).

**Any refusal rolls everything back** and sends the customer to a new quote with the reason
(`QuoteChanged`).

### 1.4 The order's four state machines

**Four independent machines** — the order, its payment, its shipment, its returns; **the customer sees
one derived status**, staff see all four (handoff §12.3). Every order — individual or company — **passes
staff approval** (handoff §12.3). **Every change locks the order first**, so a cancellation, a shipping
and an edit can never cross.

- **The order**: `PENDING_APPROVAL` → `APPROVED` or `REJECTED`; `PENDING_APPROVAL` or `APPROVED` →
  `CANCELLED` (§1.6); `APPROVED` → `COMPLETED` when delivered, or `UNDELIVERED` when the parcel came
  back (§1.5). **A rejection is not a cancellation**: it records the decision, never a cancellation.
- **The shipment — one per order** (owner, 2026-10-10: "staff will send them all at one shipment"):
  `NOT_SHIPPED` → `SHIPPED` → `DELIVERED`, or `CAME_BACK`. Shipped only once `APPROVED`; **staff mark it
  delivered** — customers cannot (owner, 2026-10-10).
- **The payment** — derived from the money recorded (§1.9). **What is due**: 0 for a cancelled,
  rejected or undelivered order, otherwise `order_total` less the refunds owed for completed returns.
  **What is held**: what was paid less what was refunded. Then: `NOTHING_DUE` (nothing due, nothing
  ever paid), `UNPAID` (held 0, due above 0), `PARTLY_PAID`, `PAID` (held = due, above 0), `REFUND_DUE`
  (held above due), `REFUNDED` (nothing due or held, money having been paid and paid back).
- **The returns** — each: `REQUESTED` → `ACCEPTED`, `REFUSED`, or `WITHDRAWN` by the customer;
  `ACCEPTED` → `RECEIVED` → `COMPLETED`; staff may `CLOSE` an accepted return whose pieces never came
  (§1.8).

**What the customer sees** (handoff §12.3: New · Processing · Shipped · Delivered · Cancelled ·
Refunded): New while waiting for approval; Processing once approved; Shipped; Delivered; Cancelled when
cancelled, rejected or undelivered; Refunded once such an order — or a wholly returned one — has its
money back (`REFUNDED`).

**Shipping unpaid orders**: staff may approve an unpaid order; **whether it may ship before it is paid is
decided by staff per order** (owner, 2026-10-10) — an explicit, audited "may ship unpaid". Without it, an
order not `PAID` cannot be marked shipped.

### 1.5 Shipping, delivery, and a parcel that comes back

- **Marking shipped**: the carrier and a tracking number (free text until stage 7);
  `InventoryApi::ship` takes the stock off (in a store with no provider; a wired store's provider is the
  source); refused (`NotShippable`) if the order is flagged "not enough stock" (§1.11), or not `PAID`
  without "may ship unpaid".
- **"Reduced in the provider"**, in a wired store: per variant, for stock-dependent variants and for
  the gift, staff tick once they have reduced its stock in the provider (handoff §12.3) —
  `InventoryApi::reducedInProvider`, which ends that variant's hold lines.
- **Marking delivered** (staff) is what:
  - earns **points** on the order's `goods_total` — `LoyaltyApi::earn` (loyalty.md §1.6);
  - starts **the return window** (§1.8);
  - makes its products **reviewable** (feedback.md §1.1) — one shipment, so every line is delivered
    with the order;
  - writes `OrderDelivered` to the outbox (§6).
- **The parcel came back** — refused at the door, a wrong address, lost and returned (owner,
  2026-10-10: staff mark it by hand). Staff mark a shipped order **came back** once it is at the
  warehouse, choosing which pieces go back into stock — a damaged one may be left out —
  `InventoryApi::returned(orderId, cameBackId, lines)`. It is settled **as a whole return of an order
  never delivered**: what is due becomes 0, so everything paid, shipping included, is a refund due,
  settled by hand (§1.9); `LoyaltyApi::orderCancelled` gives the points used back (nothing was earned);
  the coupon's use stays counted (promotions.md §2.1). The order is `UNDELIVERED`; the customer sees
  Cancelled, then Refunded.

### 1.6 Cancellation

- **The customer cancels until the order ships** — once shipped it can only be returned (handoff
  §12.3; owner, 2026-10-10: "the shipping is out, it will reach customer"). **A reason from a list, or
  "Other" with text** (owner, 2026-10-09): changed my mind, ordered by mistake, found it cheaper,
  delivery too slow, other. If the order was **already paid**, the cancellation shows a note: **staff
  will contact you about the refund** (owner, 2026-10-09).
- **Staff cancel** an order before it ships, **or reject** one waiting for approval, with a reason.
- Either way: `InventoryApi::release`; `LoyaltyApi::orderCancelled` (points used come back with their
  old dates); the coupon's use **stays counted** (owner, 2026-10-08); what is due becomes 0, so a paid
  order shows a refund due (§1.4). A cancellation writes `OrderCancelled` to the outbox; a rejection
  publishes nothing yet (§2.2).

### 1.7 Staff edits before shipping

**Staff contact the customer before shipping** and settle anything missing with them (owner,
2026-10-10); **they may then fully edit the order** (owner, 2026-10-10): add lines, remove lines,
change quantities, change the delivery address to another of the customer's addresses in that store.
**An order may be edited while it is `PENDING_APPROVAL` or `APPROVED`, not shipped, and no line is
ticked "reduced in the provider"** (Inventory then refuses, `inventory.hold_not_editable`). Each edit
has its own id and a reason, and is audited. **Staff first see the edit worked out** (steps 1–6, reads
only), then confirm it; confirming works it out again in one transaction, under the order's lock.

**Added lines follow checkout's rules** (§1.2 step 2): orderable here in their mode, within their
minimum and maximum, wholesale only for a company, their category open to the account type, priced;
their stock is checked by the hold (step 7).

1. **Prices** — `PricingApi::pricesForEdit` (pricing.md §1.11, PR #107 [PROPOSED]): **the kept parts as
   they were sold** (`KeptPartDto` from the order's lines: variant, mode, quantity as edited, list unit,
   unit, price kind), **the added lines priced now** — a wholesale one by **today's band for the line's
   new total quantity, applied to the added pieces only** (owner, 2026-10-10: "band for the new
   total") — and **the order's own VAT rate**. A raised quantity adds a **part** to the line; **a
   lowered quantity takes the pieces off the newest part first**, and a part left with none goes. The
   result's lines come back in the order given, kept parts then added lines (pricing.md §2.1, PR #107),
   so each matches its order line by place — once Sales has checked that nothing came back `unpriced`
   (an unpriced line is left out of the lines; an unpriced added line is refused).
2. **The room** under the ceiling for the edited prices — `PromotionsApi::customerDiscountRoom`.
3. **The coupon** — `PromotionsApi::couponAfterEdit` with the whole room: never more than at placement,
   0 if the edited order is under its minimum or has no eligible line, **trimmed to the room only when
   the coupon alone passes it** (owner, 2026-10-10: it "can only stay or shrink"; promotions.md §1.3).
4. **The points** — `LoyaltyApi::pointsAfterEdit` (a Loyalty addition, §2.3) with the edited
   `net_subtotal` and the room the coupon left: the points discount becomes the least of what it was,
   Loyalty's cap on the edited `net_subtotal`, and that room — **the points give way before the coupon**
   (handoff §11.3: "points are evaluated last and refused first").
5. **Shipping** — worked out again by the order's own snapshotted fee and free-above amount, so an edit
   never changes the store's rule for an order already placed. My assumption, stated for the owner to
   reject.
6. **The totals** — `PricingApi::totals` over the edited prices, the coupon, the points and the
   shipping. **The gift — staff decide** (owner, 2026-10-10): keep it, swap it for the gift of the level
   the edited order now reaches (up or down, stock permitting), or remove it.
7. **On confirming**: `InventoryApi::adjustHold(orderId, newLines)`, all or nothing (PR #106
   [PROPOSED]); `LoyaltyApi::orderEdited` gives back the points above the new points discount with
   their old dates and answers the points still used; `PromotionsApi::updateCouponUse` records the
   coupon's new discount (the use still counts). Every line's shares are allocated again (§1.8), so
   returns settle on the edited order.
8. **A difference in money is recorded, and staff settle it by hand** (owner, 2026-10-10): paid more than
   the new total → **refund due**; less → **balance due**, and shipping before then follows "may ship
   unpaid" (§1.4).

An edit does not clear the "not enough stock" flag by itself (§1.11).

### 1.8 Returns, refunds and their shares

- **The customer asks**, within **the store's return window — 14 days from delivery by default**,
  admin-set per store (owner, 2026-10-09): which pieces and how many, **a reason from a list or
  "Other"** (damaged, wrong item, not as described, changed my mind, other), and **up to 3 photos**,
  kept private for staff (owner, 2026-10-10; Platform's `uploadMediaFor`, registered as a media use
  that blocks deleting, handoff §5.5). **The gift may be returned too, and refunds nothing** (owner,
  2026-10-10). A request counts the pieces of every return not refused, withdrawn or closed, so two
  requests can never claim the same piece. The customer may withdraw a request until staff decide.
- **Staff accept or refuse** it — a refusal with a reason the customer sees (my assumption, stated for
  the owner to reject). Return shipping is paid by the company (handoff §12.5); the refund is manual
  (handoff §12.4). An accepted return whose pieces never arrive may be **closed**.
- **Staff mark the pieces received** — how many of each came, and which go back into stock (a damaged
  piece may be left out) — `InventoryApi::returned(orderId, returnId, lines)` (PR #106): restocked in a
  store with no provider and for a wired store's stock-dependent variants; a wired store's ordinary
  products follow the provider (owner, 2026-10-09); a gift on gift rules.
- **Completing it** refunds what was received:
  - **each received piece's refund** = its goods share (its line's `total` less its coupon and points
    shares) **plus its share of the order's VAT**; a gift's is 0;
  - **the shipping fee and its VAT share** go with the return that makes **every bought piece** (the
    gift aside) returned — my assumption, stated for the owner to reject;
  - `LoyaltyApi::orderReturned` with the received pieces' goods shares (their part of `goods_total`)
    and points shares (their part of the points discount) (loyalty.md §1.8);
  - `ReturnCompleted` written to the outbox (§6) — Feedback flips its reviews (feedback.md).
- **A gift is kept** after a partial return below its level (promotions.md §1.7).

**The shares** — fixed at placement and after every edit, so returning everything refunds exactly
`order_total`, no halala lost (handoff §5.1):

1. **The coupon's** — Promotions' answer, one share per eligible line (promotions.md §2.2).
2. **The points'** — the points discount spread with `Money::allocate` over the lines by **their total
   less their coupon share**, so no line's goods share goes below 0. None when the points discount is
   0 (Pricing refuses discounts above `net_subtotal`, pricing.md §1.7, so a points discount above 0
   always has a line to land on).
3. **The VAT's** — the order's VAT spread over the lines' taxable amounts (total less both shares) and
   the shipping fee, in proportion; none when the VAT is 0. `taxable_base` = the lines' taxable amounts
   plus shipping, so the shares add up to the VAT.
4. **Per piece, by running totals**: for a line of `n` pieces with a share `S`, the first `k` pieces
   returned carry `floor(S × k ÷ n)`, and each return moves that less what earlier returns moved — so
   the last piece takes what is left, and a line returned in parts lands exactly on `S`.

**A line is each part** (§1.7): an order line edited to hold pieces at two prices shows as two lines,
each at its price, and a return names the part its pieces come from.

The sum over every piece, plus the shipping and its VAT share, is `goods_total` + shipping + VAT =
`order_total` (pricing.md §1.7). The gift has no shares.

### 1.9 Money: the ways to pay, payments and refunds

**The ways to pay, by account type** (owner, 2026-10-09):

| Account | Ways |
|---|---|
| Individual | **Online** (Payments, stage 7) **or "staff will contact you"** |
| Company | **Bank transfer** (to the store's account, `B2BApi::bankAccount` — off while the store has not filled it in, b2b.md amendment 13(c)) **or "staff will contact you"** — never online |

**No cash on delivery** in any store (handoff §12.4, §16). Tamara and Tabby are for individuals only
(handoff §12.4) — Payments' (stage 7).

- **"Staff will contact you"**: the order is placed unpaid; staff arrange payment and record it.
- **Bank transfer — and its document** (b2b.md §9 #2, amendment 12(c): the owner put it in Sales,
  stage 6): the company is shown the store's account, transfers, and **uploads the transfer's
  document** on its order — **up to 3 files**, PDF, JPEG or PNG, private (handoff §5.5; my assumption,
  stated for the owner to reject, for the number), until staff record the payment. Staff open them
  from the order and record the payment. A target time to check transfers is Payments' (stage 7,
  handoff §12.4).
- **Until stage 7** there is no online payment: individuals see "staff will contact you"; **staff record
  every payment by hand** — amount, way, reference, date.
- **Refunds are all manual** (handoff §12.4): staff record each one — amount, way, reference, date —
  never more than the order holds (paid less refunded).
- **Recording once**: each record carries the form's own request id, so pressing twice records once
  (b2b.md §9 #7). **A record is never changed or deleted**; one entered by mistake is **voided** by
  staff with a reason, and no longer counts.
- **The ways recorded** — payments and refunds alike: `BANK_TRANSFER`, `ONLINE` (stage 7), `OTHER` with
  a note. My assumption, stated for the owner to reject.
- **Sales keeps the money recorded against each order**, from which the payment state follows (§1.4).
  From stage 7, Payments keeps its gateway transactions and tells Sales of each payment through
  `PaymentSucceeded` / `PaymentFailed`; Sales records it here.

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
variantId, orderIds)` (inventory.md §6.1). **Sales flags those orders "not enough stock"** for staff — who
contact the customer and edit (§1.7), cancel, or wait for stock. A flagged order cannot be marked
shipped. **Staff clear the flag by hand** once it is settled: Inventory's hold moves only the pieces an
edit adds or frees (inventory.md §1.4, PR #106), so a successful edit does not show that the shortage is gone.

### 1.12 A deleted customer, an off store, each store's rows

- **Orders keep their snapshot of name, phone and delivery address** and are never anonymized (handoff
  §7.9: a financial record); **the email snapshot is cleared** (owner, 2026-10-10). An anonymized
  account's carts are deleted.
- **An off store's unfinished orders stay with its staff to finish** (handoff §1; owner, 2026-10-10):
  **Sales's order and return screens list an off store to its own staff while it has unfinished
  orders** — an exception to platform.md §9.10 #4, for these screens only. Unfinished: an order not
  yet completed, cancelled, rejected or undelivered, one with a return not yet completed, refused,
  withdrawn or closed, or one whose payment is `UNPAID`, `PARTLY_PAID` or `REFUND_DUE` — my reading,
  stated for the owner to reject. Its shop and new orders are gone with it.
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

**Declared on `main` before Feedback is built** (feedback.md §2.1, and its handoff §17 amendment in PR
#104), with `ReturnCompleted`:

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

A rejection and a parcel that came back publish nothing yet; Ops adds what its messages need (stage 8).

### 2.3 What Sales needs from other modules

| From | What | State |
|---|---|---|
| Pricing | `prices`, `totals`; **`pricesForEdit`** with `KeptPartDto`; **the result's lines in the order given**, an unpriced line left out and named | `prices`, `totals` on `main` (#97); `pricesForEdit` and the lines' order in **PR #107 [PROPOSED]** (2e5e9ef) |
| Inventory | `stock` (with `availableAsGift`), `hold`, `ship`, `reducedInProvider`, `release`, `HoldsShort`; `adjustHold`, `returned` | Interfaces on `main` (#97); `HoldsShort` specified (#102); **`adjustHold` and `returned` in PR #106 [PROPOSED]** |
| Promotions | `applyCoupon`, `customerDiscountRoom`, `giftLevels`, `useCoupon`, `recordOrder`, `couponAfterEdit` (with the whole room), `updateCouponUse`; **one share per price line, in the prices' order** | promotions.md (PR #101) — the room and the shares' keys corrected there with this spec |
| Loyalty | `earningPreview`, `quoteRedemption`, `redeem`, `earn`, `orderCancelled`, `orderReturned`; **`pointsAfterEdit`** (reads) and **`orderEdited` answering the points still used** | loyalty.md (PR #96) — the edit's read and answer added there with this spec |
| Catalog | **the store's variants in bulk** (`storeVariant` reads one, `CatalogApi`); `variant`, `product` and the bulk reads Feedback adds (snapshots); **whether a product's category is open to an account type in a store**, in bulk; `ListingFacts::salesRanks`; the usage check (a variant on an order is never deleted, promotions.md §1.2) | `storeVariant`, `variant`, `product`, `ListingFacts` exist; **the bulk store-variant read and the category read are Catalog additions** — to agree with the Catalog-screens session, who own §1.13 |
| Access | `customerMayOrder`, `customer`, `addresses`, `address`, `GuestBecameCustomer`, `CustomerAnonymized` | Exist |
| B2B | `isApproved`, `bankAccount` | Exist |
| Platform | `store` (`isActive`), `StoreCreated`, settings, the audit log, `uploadMediaFor` and media uses (return photos, transfer documents), staff names | Exist |
| Shared | **`outbox_messages` and `processed_events`, in the `public` schema** (platform.md §5.6), and the outbox's relay | **Built with Sales** — the first module to publish through the outbox |

**Other modules' refusals** reach Sales as `DomainError` with a stable `type()` (pricing.md §2.1:
modules export no error classes); Sales catches them by key, following the `{module}.{snake_case}`
convention (e.g. `catalog.attribute_kind_locked`): `promotions.coupon_refused`,
`promotions.coupon_changed`, `loyalty.redemption_changed`, `loyalty.redemption_refused`,
`loyalty.points_programme_off`, `inventory.not_enough_stock`, `inventory.hold_not_editable`,
`inventory.no_hold`, `pricing.duplicate_lines`. Inferred from that convention; each module's build
confirms its keys.

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
audited. **The permissions Sales declares** (three-part names; the store-scoped ones checked in the
order's or cart's store — Promotions and Loyalty build their scope from it, loyalty.md §2.1):

| Permission | Audience | Kind | Group | Admin-only |
|---|---|---|---|---|
| `sales.cart.manage` | every customer | per store | — | — |
| `sales.guest_cart.manage` | every guest | per store | — | — |
| `sales.order.place` | every customer | per store | — | — |
| `sales.order.manage_own` | every customer | per store | — | — |
| `sales.order.view_own` | every customer | global | — | — |
| `sales.order.view` | Role | per store | `Orders` | no |
| `sales.order.approve` | Role | per store | `Orders` | no |
| `sales.order.edit` | Role | per store | `Orders` | no |
| `sales.order.cancel` | Role | per store | `Orders` | no |
| `sales.order.fulfil` | Role | per store | `Orders` | no |
| `sales.payment.record` | Role | per store | `Orders` | no |
| `sales.return.manage` | Role | per store | `Orders` | no |
| `sales.settings.update` | Role | per store | `Orders` | **yes** |
| `sales.system.run` | Role, reserved | global | — | — |

| Use case | Kind | Permission |
|---|---|---|
| `AddCartLine`, `ChangeCartLine`, `RemoveCartLine`, `SetCartCoupon` | Command | `sales.cart.manage` — a guest's cart (no coupon): `sales.guest_cart.manage` |
| `ViewCart` | Query | `sales.cart.manage` / `sales.guest_cart.manage` |
| `StartCheckout`, `PlaceOrder` | Command | `sales.order.place` |
| `CancelOwnOrder`, `RequestReturn`, `WithdrawReturn`, `UploadReturnPhoto`, `UploadTransferDocument` | Command | `sales.order.manage_own` (also the scope of `uploadMediaFor`) |
| `ListOwnOrders`, `ViewOwnOrder`, `ViewOwnReturn` | Query | `sales.order.view_own` |
| `ListOrders` (all, unpaid, flagged short — handoff §14: Sales › All orders · Store orders · Unpaid orders), `ViewOrder`, `ListReturns`, `ViewReturn`, `OpenOrderFile` (return photos, transfer documents), `PrintOrder` | Query | `sales.order.view` |
| `ApproveOrder`, `RejectOrder` | Command | `sales.order.approve` |
| `PreviewOrderEdit`, `EditOrder`, `ClearShortFlag` | Query, Command, Command | `sales.order.edit` |
| `CancelOrder` | Command | `sales.order.cancel` |
| `ShipOrder`, `TickReducedInProvider`, `AllowShipUnpaid`, `MarkDelivered`, `MarkCameBack` | Command | `sales.order.fulfil` |
| `RecordPayment`, `RecordRefund`, `VoidMoneyRecord` | Command | `sales.payment.record` |
| `AcceptReturn`, `RefuseReturn`, `ReceiveReturn`, `CloseReturn`, `CompleteReturn` | Command | `sales.return.manage` |
| `UpdateSalesSettings` (through Platform's settings page) | Command | `sales.settings.update` |
| `EmptyOldCarts`, `DeleteExpiredQuotes`, `PushSalesRanks` | Command, scheduled | `sales.system.run` |
| `MoveGuestCart`, `ForgetCustomer`, `FlagShortOrders`, `CreateOrderSequence`, `RecordGatewayPayment` (stage 7) | Listeners | — (the events of §6, run as the system) |

The outbox's relay is Shared infrastructure, run by the scheduler as the system; it changes no
module's data but the outbox's own rows.

---

## 4 · State machines

```
ORDER      PENDING_APPROVAL ─▶ APPROVED ─▶ COMPLETED            (delivered)
                               APPROVED ─▶ UNDELIVERED          (came back)
           PENDING_APPROVAL ─▶ REJECTED
           PENDING_APPROVAL | APPROVED ─▶ CANCELLED              (not shipped)

SHIPMENT   NOT_SHIPPED ─▶ SHIPPED ─▶ DELIVERED | CAME_BACK        (one per order; shipped only
                                                                   APPROVED, not flagged short,
                                                                   PAID or "may ship unpaid")

PAYMENT    derived: NOTHING_DUE · UNPAID · PARTLY_PAID · PAID · REFUND_DUE · REFUNDED

RETURN     REQUESTED ─▶ ACCEPTED ─▶ RECEIVED ─▶ COMPLETED
           REQUESTED ─▶ REFUSED | WITHDRAWN
           ACCEPTED  ─▶ CLOSED                                    (pieces never came)
```

Edits: `PENDING_APPROVAL` or `APPROVED`, not shipped, no line ticked "reduced in the provider".

---

## 5 · Tables

Schema `sales`; the outbox and `processed_events` in `public` (platform.md §5.6). ULID ids;
`bigint` identity for high-volume rows (handoff §5.3); amounts in the smallest unit (`bigint`) with
`currency_code`; **every column NOT NULL unless marked NULL**; every rule also checked in code first
(handoff §5.3); every CHECK touching a nullable column is written so that NULL fails it where a value
is needed, and every kind column has a CHECK `IN (…)`; every `store_id` → `platform.stores` RESTRICT,
and the rows of one order carry its store through composite keys.

| Table | Columns | Rules |
|---|---|---|
| `sales.carts` | `id`, `store_id`, `customer_id` NULL → `access.customers` CASCADE, `guest_id` NULL, `coupon_code` NULL, `notice` NULL (the lines removed at sign-in), `created_at`, `updated_at` | CHECK `num_nonnulls(customer_id, guest_id) = 1`; CHECK `customer_id IS NOT NULL OR coupon_code IS NULL`; UNIQUE (`store_id`, `customer_id`), UNIQUE (`store_id`, `guest_id`) |
| `sales.cart_lines` | `cart_id` → carts CASCADE, `variant_id` → `catalog.variants` CASCADE, `sale_mode`, `quantity`, `added_at` | PK (`cart_id`, `variant_id`, `sale_mode`); CHECK `sale_mode IN ('RETAIL','WHOLESALE')`, `quantity BETWEEN 1 AND 1000000` |
| `sales.quotes` | `id`, `store_id`, `customer_id` → `access.customers` CASCADE, `address_id`, `coupon_code` NULL, `points_asked` NULL, `way_to_pay`, `result` jsonb (the itemised §1.2), `expires_at`, `created_at` | CHECK `way_to_pay IN ('ONLINE','BANK_TRANSFER','STAFF_CONTACT')`; deleted nightly once expired and not placed |
| `sales.orders` | `id`, `store_id`, `number`, `quote_id`, `customer_id` → `access.customers` RESTRICT, `audience`, `locale`, `currency_code` → `platform.currencies`; the snapshot: `customer_name`, `customer_email` NULL (cleared on anonymizing), `customer_phone`, `address` jsonb; `status`, `shipment_status`, `way_to_pay`, `coupon_id` NULL, `coupon_code` NULL, `coupon_discount_minor`, `points_used`, `points_discount_minor`, `gross_subtotal_minor`, `net_subtotal_minor`, `goods_total_minor`, `shipping_fee_minor`, `shipping_rule_fee_minor`, `shipping_rule_free_above_minor`, `taxable_base_minor`, `vat_minor`, `vat_rate_basis_points`, `order_total_minor`, `due_minor`, `paid_minor`, `refunded_minor`, `may_ship_unpaid`, `short_of_stock_at` NULL, `gift_level_id` NULL, `placed_at`, `decided_by` NULL → `access.staff_users`, `decided_at` NULL, `decision_reason` NULL, `cancel_reason` NULL, `cancel_other` NULL, `cancelled_by` NULL, `cancelled_at` NULL, `carrier` NULL, `tracking_number` NULL, `shipped_at` NULL, `delivered_at` NULL, `came_back_id` NULL, `came_back_at` NULL | UNIQUE (`store_id`, `number`); UNIQUE (`quote_id`); UNIQUE (`id`, `store_id`); UNIQUE (`id`, `store_id`, `currency_code`); CHECK `audience IN ('PUBLIC','COMPANY')`, `status IN ('PENDING_APPROVAL','APPROVED','REJECTED','CANCELLED','COMPLETED','UNDELIVERED')`, `shipment_status IN ('NOT_SHIPPED','SHIPPED','DELIVERED','CAME_BACK')`, `way_to_pay IN ('ONLINE','BANK_TRANSFER','STAFF_CONTACT')`, `cancel_reason IN (…)`; **the decision**: CHECK `(status = 'PENDING_APPROVAL' AND decided_at IS NULL) OR (status IN ('APPROVED','REJECTED','COMPLETED','UNDELIVERED') AND decided_at IS NOT NULL) OR status = 'CANCELLED'` — a cancelled order was cancelled waiting or after approval; **the cancellation**: CHECK `(status = 'CANCELLED') = (cancelled_at IS NOT NULL)`, `status <> 'CANCELLED' OR shipment_status = 'NOT_SHIPPED'`, `(cancel_reason = 'OTHER') = (cancel_other IS NOT NULL)` with both NULL unless cancelled; **the shipment**: CHECK `(shipment_status = 'NOT_SHIPPED') = (shipped_at IS NULL)`, `(shipment_status = 'DELIVERED') = (delivered_at IS NOT NULL)`, `(shipment_status = 'CAME_BACK') = (came_back_at IS NOT NULL AND came_back_id IS NOT NULL)`, `(status = 'COMPLETED') = (shipment_status = 'DELIVERED')`, `(status = 'UNDELIVERED') = (shipment_status = 'CAME_BACK')`, `shipment_status = 'NOT_SHIPPED' OR status IN ('APPROVED','COMPLETED','UNDELIVERED')`; **the coupon**: CHECK `(coupon_id IS NULL) = (coupon_code IS NULL)`, `coupon_id IS NOT NULL OR coupon_discount_minor = 0` (an edit may leave a coupon at 0); **the points**: CHECK `(points_used = 0) = (points_discount_minor = 0)`; CHECK every amount `>= 0`, `due_minor <= order_total_minor`, `refunded_minor <= paid_minor` |
| `sales.order_lines` | `id`, `order_id`, `store_id`, `product_id` → `catalog.products` RESTRICT, `variant_id` → `catalog.variants` RESTRICT, `edit_id` NULL (the part an edit added), `is_gift`, the snapshot (`name_ar`, `name_en`, `variant_values` jsonb, `code`), `sale_mode`, `price_kind` NULL (none for the gift), `quantity`, `unit_minor`, `list_unit_minor`, `total_minor`, `coupon_share_minor`, `points_share_minor`, `vat_share_minor` | (`order_id`, `store_id`) → orders (`id`, `store_id`) RESTRICT; (`edit_id`, `order_id`) → order_edits (`id`, `order_id`) RESTRICT; UNIQUE (`id`, `order_id`); CHECK `sale_mode IN ('RETAIL','WHOLESALE')`, `(price_kind IS NULL) = is_gift`, `price_kind IN ('BASE','SALE','CAMPAIGN','CATEGORY','QUANTITY')`, `quantity >= 1`; CHECK every share `>= 0` and `coupon_share_minor + points_share_minor <= total_minor`; CHECK a gift line has `unit_minor = 0`, `list_unit_minor = 0`, `total_minor = 0` and every share 0; one gift line per order (UNIQUE (`order_id`) WHERE `is_gift`). Rows change only before shipping (edits) |
| `sales.order_ticks` | `order_id`, `store_id`, `variant_id`, `ticked_by` → `access.staff_users`, `ticked_at` | PK (`order_id`, `variant_id`); (`order_id`, `store_id`) → orders RESTRICT — "reduced in the provider" |
| `sales.order_edits` | `id`, `order_id`, `store_id`, `reason`, `by` → `access.staff_users`, `at`, `before` jsonb, `after` jsonb | (`order_id`, `store_id`) → orders RESTRICT; UNIQUE (`id`, `order_id`); CHECK `char_length(reason) BETWEEN 1 AND 500`; append-only (trigger); the edit's id is Loyalty's and Promotions' idempotency key |
| `sales.payments`, `sales.refunds` | `id`, `order_id`, `store_id`, `currency_code`, `amount_minor`, `way`, `reference` NULL, `note` NULL, `request_id` uuid, `recorded_by` NULL → `access.staff_users`, `recorded_at`, `voided_by` NULL, `voided_at` NULL, `void_reason` NULL | (`order_id`, `store_id`, `currency_code`) → orders (`id`, `store_id`, `currency_code`) RESTRICT — the order's currency only; UNIQUE (`request_id`); UNIQUE (`way`, `reference`) WHERE `way = 'ONLINE'` (stage 7's gateway references); CHECK `amount_minor > 0`, `way IN ('BANK_TRANSFER','ONLINE','OTHER')`, `way <> 'OTHER' OR note IS NOT NULL`; CHECK `num_nonnulls(voided_by, voided_at, void_reason) IN (0, 3)`; a trigger refuses DELETE and any UPDATE but setting the three void columns once |
| `sales.transfer_documents` | `order_id`, `store_id`, `media_id` → `platform.media` RESTRICT, `position`, `uploaded_at` | PK (`order_id`, `position`); (`order_id`, `store_id`) → orders RESTRICT; CHECK `position BETWEEN 1 AND 3` |
| `sales.returns` | `id`, `order_id`, `store_id`, `status`, `reason`, `reason_other` NULL, `staff_reason` NULL, `requested_at`, `decided_by` NULL → `access.staff_users`, `decided_at` NULL, `withdrawn_at` NULL, `received_at` NULL, `closed_at` NULL, `completed_at` NULL, `refund_due_minor` NULL, `shipping_refunded` | (`order_id`, `store_id`) → orders RESTRICT; UNIQUE (`id`, `order_id`); CHECK `status IN ('REQUESTED','ACCEPTED','REFUSED','WITHDRAWN','RECEIVED','COMPLETED','CLOSED')`, `reason IN ('DAMAGED','WRONG_ITEM','NOT_AS_DESCRIBED','CHANGED_MIND','OTHER')`, `(reason = 'OTHER') = (reason_other IS NOT NULL)`; CHECK `(decided_at IS NOT NULL) = (status IN ('ACCEPTED','REFUSED','RECEIVED','COMPLETED','CLOSED'))`, `(withdrawn_at IS NOT NULL) = (status = 'WITHDRAWN')`, `(received_at IS NOT NULL) = (status IN ('RECEIVED','COMPLETED'))`, `(closed_at IS NOT NULL) = (status = 'CLOSED')`, `(completed_at IS NOT NULL) = (status = 'COMPLETED')`, `(refund_due_minor IS NOT NULL) = (status = 'COMPLETED')`; CHECK `status <> 'REFUSED' OR staff_reason IS NOT NULL`; CHECK `refund_due_minor >= 0` |
| `sales.return_lines` | `return_id`, `order_id`, `order_line_id`, `quantity` (asked), `received_quantity` NULL, `restocked_quantity` NULL | PK (`return_id`, `order_line_id`); (`return_id`, `order_id`) → returns (`id`, `order_id`) CASCADE; (`order_line_id`, `order_id`) → order_lines (`id`, `order_id`) RESTRICT; CHECK `quantity >= 1`, `(received_quantity IS NULL) = (restocked_quantity IS NULL)`, `received_quantity BETWEEN 0 AND quantity`, `restocked_quantity BETWEEN 0 AND received_quantity` |
| `sales.return_photos` | `return_id`, `media_id` → `platform.media` RESTRICT, `position` | PK (`return_id`, `position`); `return_id` → returns CASCADE; CHECK `position BETWEEN 1 AND 3` |
| `sales.order_history` | `id` bigint identity, `order_id`, `store_id`, `kind`, `edit_id` NULL, `by` NULL, `at`, `detail` jsonb | (`order_id`, `store_id`) → orders RESTRICT; CHECK `kind IN (…)` (each change of §3's commands); append-only (trigger) — every state change, edit and money record |
| `public.outbox_messages` | `id` bigint identity, `event_id` uuid UNIQUE, `type`, `payload` jsonb (ids only), `created_at`, `available_at`, `attempts`, `last_error` NULL, `dispatched_at` NULL | Written in the change's transaction; the relay takes the undispatched rows whose `available_at` has come, in `id` order, `FOR UPDATE SKIP LOCKED`, dispatches each, marks it dispatched — or counts the attempt, keeps the error and moves `available_at` later each time; dispatched rows kept 30 days. **At least once**: every consumer is idempotent |
| `public.processed_events` | (`event_id` uuid, `listener`) PK, `processed_at` | platform.md §5.6 |

**The order number**: one PostgreSQL sequence per store, `sales.order_number_<store id>`, created by the
migration for each store and by the `StoreCreated` listener (§6).

**Indexes:** orders by (`store_id`, `placed_at` DESC, `id`) — the lists, keyset-paged (handoff §5.4);
by `store_id` WHERE `paid_minor - refunded_minor < due_minor` — unpaid; by `store_id` WHERE
`short_of_stock_at IS NOT NULL`; by (`customer_id`, `placed_at` DESC); by `store_id` WHERE `status IN
('PENDING_APPROVAL','APPROVED')` — open orders. Order lines by `order_id`; by (`product_id`,
`order_id`) — `deliveredPurchase` joins the order's delivery. Return lines by `order_line_id`. Returns
by (`store_id`, `status`, `requested_at`); by `order_id`. Carts by `guest_id`, by `customer_id`, by
`updated_at` — the 90-day job. Quotes by `expires_at`. Outbox by (`available_at`, `id`) WHERE
`dispatched_at IS NULL`.

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
| `StoreCreated` | Platform | Creates the store's order-number sequence (§5) |
| `PaymentSucceeded`, `PaymentFailed` | Payments (stage 7) | Records the payment, or leaves the order unpaid |

Each consumer records `processed_events`, so a message delivered twice is acted on once.

---

## 7 · Errors

Each extends `SalesError`, which extends `Shared\Domain\Error\DomainError`, with a status from
`ErrorCategory` (`NOT_FOUND`, `FORBIDDEN`, `CONFLICT`, `INVALID`). Other modules' refusals at placement
(§2.3) reach the customer as `QuoteChanged`, with the reason.

| Error | Status | When |
|---|---|---|
| `MayNotOrder` | FORBIDDEN | `customerMayOrder` false; a company not approved in this store; the store off |
| `NotForYourAccount` | FORBIDDEN | A wholesale line for an individual; a product in a category hidden from the account's type |
| `NotOrderable` | INVALID | A line not orderable here, in its mode, unpriced, or outside its minimum and maximum; not enough stock |
| `AddressNotUsable` | INVALID | No address in this store, or its format no longer accepted |
| `WayToPayNotOffered` | INVALID | A way to pay not offered to this account (online to a company; a bank transfer while the store has none) |
| `SignInToUseCoupon` | INVALID | A guest's coupon code |
| `QuoteNotFound` | NOT_FOUND | A quote that is not this buyer's — the same answer as an unknown one |
| `QuoteExpired` | CONFLICT | Placing after 15 minutes or past the prices' end — checkout quotes again |
| `QuoteChanged` | CONFLICT | Anything of §1.3 steps 3–5 no longer as quoted |
| `NotCancellable` | CONFLICT | Shipped, or already cancelled, rejected or finished |
| `NotEditable` | CONFLICT | Shipped; cancelled, rejected or finished; a line ticked "reduced in the provider" |
| `NotShippable` | CONFLICT | Not approved; flagged "not enough stock"; not `PAID` without "may ship unpaid" |
| `ReturnNotAllowed` | CONFLICT | Outside the window; more pieces than delivered and not already in a return; more than 3 photos |
| `TooManyFiles` | CONFLICT | A fourth transfer document; a document once the payment is recorded |
| `RefundTooLarge` | CONFLICT | A refund above what the order holds |
| `InvalidOrderChange` | CONFLICT | A change its state does not allow |
| `OrderNotFound`, `ReturnNotFound` | NOT_FOUND | Unknown, or of a store the reader does not cover |
| `InvalidSalesAttribute` | INVALID | A value refused |

---

## 8 · Test scenarios

**Cart** — permissive adds; the guest cookie written with the first line; a guest's wholesale lines and
lines of categories hidden from individuals leave on sign-in as an individual, with a notice; wholesale
stays for a company; moving on registration, merging on sign-in; "sign in to use a coupon"; 90 days.

**Quote** — each of §1.2's steps refusing in turn, the store off included; a line Pricing cannot price
refused; the fixed order (coupon before points; points skipped when the coupon forbids them; points
refused whole beyond the room); free shipping on `goods_total`, 0 meaning never; the gift falling to
the next level when out of stock, counting the order's own pieces; the ways to pay by account type,
bank transfer hidden without an account; 15 minutes and Pricing's `validUntil`.

**Placing** — the store switched off, a company suspended, a variant archived or "Not available now",
an address removed, a category hidden, the bank account emptied, the coupon changed, points spent
elsewhere, stock gone — each refuses and nothing is held, used, recorded or numbered; a retail and a
wholesale line of one variant held as one line, the gift as its own, with no expiry; **two `PlaceOrder`
at once on one quote answer one order**; numbers per store, `SA-…`, a store opened later getting its
sequence; the snapshot unchanged when the catalog changes.

**Orders and shipping** — approval; a rejection never recorded as a cancellation; an approved order
cancelled keeps its decision; unpaid orders wait and do not ship without "may ship unpaid"; `ship`
called on shipping; "reduced in the provider" per variant, the gift included; delivered by staff earns
points, starts the window and makes the products reviewable; a parcel that came back: stock back as
staff choose, nothing due, points back, the coupon still counted, Cancelled then Refunded;
`HoldsShort` flags, a flagged order cannot ship, only staff clear the flag; an off store's unfinished
orders listed to its staff, and no longer once finished.

**Cancelling** — until shipped; the reason list and "Other"; the paid note; stock freed, points back,
the coupon use kept, nothing due and a refund due shown.

**Edits** — each kind, waiting for approval or approved; refused once shipped, cancelled, finished or
a line ticked; an added wholesale line for an individual, an added hidden category, an added unpriced
line refused; kept parts at their price, added pieces at today's with the band for the new total; a
lowered quantity taking the newest part first; Pricing's totals at the order's VAT rate; points giving
way before the coupon; the coupon never growing, removed under its minimum, trimmed only when alone it
passes the room; stock adjusted all or nothing; the gift kept, swapped or removed by staff; shares
allocated again; refund due and balance due recorded; the flag left for staff.

**Returns and money** — within 14 days of delivery; pieces, a reason, up to 3 private photos; the gift
returned for nothing; two requests never claiming one piece; withdraw; accept, refuse with a reason,
close; received with fewer pieces than asked and damaged pieces left out of stock; **a full return,
in one or several parts, refunding exactly `order_total`** — with a coupon taking a line to 0, points
on the rest, a VAT of 0, shipping free and not; shipping refunded with the last bought piece; points
settled by running totals; `ReturnCompleted` flips Feedback's reviews; transfer documents up to 3 and
none after the payment is recorded; payments and refunds recorded once per request, voided not
changed, a refund above the held amount refused, another currency refused; the payment state through
`NOTHING_DUE`, `UNPAID`, `PARTLY_PAID`, `PAID`, `REFUND_DUE`, `REFUNDED`.

**Events and the outbox** — each critical event written in the same transaction as its change,
dispatched at least once, acted on once by each consumer; the relay's retries growing apart; two relays
never taking one row.

**The database** — every CHECK refused with its nullable columns left NULL and an unknown kind;
composite keys refusing a line, edit, tick, payment, document or return of another store's order, and
a return line of another order; append-only tables refusing UPDATE and DELETE, and money records
refusing anything but one void.

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
14. Edited prices: **kept lines keep theirs, added pieces at today's** (2026-10-10) — a wholesale line's
    added pieces by **the band for the new total** (2026-10-10, through the stage 5 session, pricing.md
    §1.11).
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
25. **An off store's unfinished orders stay in the order screens for its own staff** until finished
    (2026-10-10).
26. **A parcel that comes back**: staff mark it by hand; it is settled as a whole return of an order
    never delivered (2026-10-10: "staff can handle this state manually, so yes with 1").
27. **Orders lose the email** of an anonymized account; name, phone and address stay (2026-10-10).
28. **The gift may be returned, for nothing** (2026-10-10).
29. Recorded earlier, for B2B: **a company paying by bank transfer uploads the transfer's document — in
    Sales, stage 6** (2026-09-26, 2026-09-29; b2b.md §9 #2, amendment 12(c)), and **pressing pay twice
    pays once** (b2b.md §9 #7).

### 9.2 Still open

1. **Shipping and Payments** — stage 7, waiting on the owner's vendor data (handoff §15.1); their
   interfaces are written here (§2.4).
2. **Pricing's `pricesForEdit` (PR #107)** and **Inventory's `adjustHold` / `returned` (PR #106)** —
   the stage 5 session's, for the owner's review; Sales is built against them once on `main`.
3. **Catalog's additions** — the store's variants in bulk, and whether a product's category is open to
   an account type, in bulk — to agree with the Catalog-screens session.
4. **Promotions' and Loyalty's corrections** that this spec needs — the edited coupon's whole room and
   its shares by price line (promotions.md); `pointsAfterEdit`, `orderEdited`'s answer and an edit
   before delivery in Loyalty's table rules (loyalty.md) — made on those specs' branches with this one.
5. **Sales's screens** — the frontend session's, to confirm.
6. **Every assumption marked above** — merging carts by adding quantities; 90-day carts; order numbers
   from a sequence that may leave gaps; an edit keeping the order's own shipping rule; shipping
   refunded with the last bought piece; a refusal's reason required; up to 3 transfer documents; the
   ways recorded (`BANK_TRANSFER`, `ONLINE`, `OTHER`); what counts as an unfinished order; the
   settings' defaults and admin-only permission; best-selling as pieces sold in 90 days.
7. **Handoff §12.4's "hold stock while verifying" setting** (bank transfers, stage 7): stock is now
   held for every order from placement until it ships or is cancelled (handoff §12.1, owner
   2026-10-07), so the setting has nothing left to switch — for the owner to confirm it goes, with
   Payments.

### 9.3 The independent reviews of the draft (2026-10-10)

**The first review**, each finding checked against the code and the other specs before acting.
**Blockers, fixed:** the coupon after an edit had no contract and broke "never trimmed" — Promotions'
exception and its two calls, with the owner's "only stay or shrink"; the edited order's two prices on
one line had no Pricing contract — now `pricesForEdit` (PR #107); returns opening per parcel against
points earned on the whole order — the owner's one shipment per order; refunds without their VAT —
VAT shares allocated; §5 not a full table spec. **Should-fix, fixed:** the audience derived and passed;
the order number taken first; the permissions' audiences and kinds; the outbox, its relay,
at-least-once; every changeable rule checked again at placement; `allow_with_points` against the fixed
order; one hold line per variant and the gift's own; partial shipments gone; the payment state through
cancellation and returns; Loyalty's shares on Sales's side; an off store's open orders; categories
hidden from an account type; a lock on every order change; idempotent placement; stage 7's interfaces
with their own types; the best-selling push; the email on anonymizing; edits refused once ticked; the
public contract's fields; the "not enough stock" flag; the handoff's sections amended in place; and the
minor points.

**The second review** (of `bb7681b`), each finding checked the same way. **Blockers, fixed:** the
decision CHECK refused a cancellation after approval, and a rejection both was and was not a
cancellation — a rejection records the decision only, and the CHECK allows either for a cancelled
order; the points' shares could take a line below 0 and `Money::allocate` refuses negative or all-zero
ratios — points spread over total less the coupon's share, a CHECK on both shares, no allocation of 0;
the edit flow could not be built on the other contracts — the whole room to Promotions, Loyalty's cap
through `pointsAfterEdit`, shares by price line, `price_kind` kept; **the bank transfer's document
contradicted the owner's answer for B2B** — built here. **Should-fix, fixed:** `NOTHING_DUE`; the short
flag cleared only by staff; an index across tables; an off store's orders (the owner's answer 25); the
quote locked and the order looked up first; the store and each variant checked again at placement;
PR #107 cited and the band rule the owner's; the returns' timestamps, received quantities, the gift
(answer 28) and "whole order"; payments' ways, voiding, the refund cap, the order's currency;
Loyalty's edit before delivery; a parcel that came back (answer 26); edits' added lines, states and
`NotCancellable`. **Minor, fixed:** `INVALID` for `UNPROCESSABLE`; citations (§17 on PR #104,
`HoldLineDto::$gift` on `main`, handoff §5.3, §11.3, §12.4 amended in place); the store's code in
capitals and new stores' sequences; the outbox's retry columns and the relay as Shared; the coupon
CHECK and the unpaid index; Catalog's bulk read; other modules' error keys; per-piece running totals;
the use cases with their permissions; the email asked (answer 27).
