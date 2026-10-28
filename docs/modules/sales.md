# Sales — Module Specification

**Status:** **ACCEPTED by the owner, 2026-10-10** (merged as #110) — sections 1–9 written 2026-10-10
from the owner's answers of 2026-10-07 to 2026-10-10 (§9.1), rewritten after two independent reviews
and a final check (§9.3). The picks marked "my assumption" were accepted with it, and **every "as built"
assumption written during the build was accepted by the owner on 2026-10-26** (§9.1c, 46).
**Tier:** 1. **Stage:** 6 (handoff §17), the last of the stage's modules to be built (Loyalty →
Promotions → Feedback → Sales).
**Depends on:** every module above it except Feedback and Sync (handoff §4.4) — Platform, Access, B2B,
Catalog, Pricing, Inventory, Promotions, Loyalty, Shipping, Payments. **Shipping and Payments are
stage 7** and wait for the owner's vendor data (handoff §15.1): Sales states exactly what it needs from
them and works without them until then (owner, 2026-10-07: "interfaces now, stage 7 fills them").
**Source:** `docs/HANDOFF.md` §1, §2, §4.4, §4.5, §5.1–§5.5, §6, §7.4, §7.5, §7.8, §7.9, §10.2,
§11.3, §12.1–§12.6, §14, §16 and its amendments to 2026-10-10; `docs/modules/pricing.md` (with #107, merged),
`inventory.md` (with #106, merged), `catalog.md` §1.13, §2.2, `b2b.md` §9, `platform.md` §5.6, §9.10,
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
§12.3). All checking happens at the quote. **One exception, the account's own view** (owner,
2026-10-19, answer 32): a signed-in individual never sees wholesale and cannot add a wholesale line
(`NotForYourAccount` at adding); a guest may, and when the guest signs in or registers as an individual
those lines leave the merged cart, each with why.

- **One cart per buyer per store** — a customer's or a guest's. A guest's id lives in Access's cookie
  (access.md §1.7): **Sales has it written with the guest's first cart line** (handoff §7.7), through
  Access's `GuestIdentity` (amendment 74). Two first lines at once from one browser may make two guests;
  the browser keeps one, and the other cart is left to the 90-day emptying. Lines: a
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
- **Five codes that name no coupon in ten minutes** (two settings for every store, §1.10), per customer, and the box takes no code —
  a right one included — until the ten minutes have passed (`TooManyCouponTries`, with the minutes
  left); the code is then not kept. A code "names no coupon" when Promotions' `knowsCoupon` says so: no
  coupon of the store by it, or one given to other customers. Taking the code off is never stopped
  (answer 50).
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
   company view but cannot order (owner, 2026-10-07). **And fewer orders waiting for approval in this
   store than allowed** — 3 unless changed (§1.10) — else `TooManyWaitingOrders` (answer 51).
2. **What may be bought** — each line orderable here in its sale mode, within the store's minimum and
   maximum (Catalog, the store's variants in bulk — §2.3); **a wholesale line only for a company**
   (handoff §6, amended 2026-10-07); **only products whose category is open to the buyer's account
   type in this store** (catalog.md §1.13; owner, 2026-10-10 — a Catalog addition, §2.3); stock
   (`InventoryApi::stock`).
3. **The delivery address** — one of the customer's addresses in this store, its format still accepted
   (access.md §1.9; handoff §7.8: "an order needs an address").
4. **Prices** — `PricingApi::prices`; a line Pricing cannot price (`unpriced`) is refused.
5. **The coupon** — `PromotionsApi::applyCoupon`; it must fit the room left under the ceiling
   (`customerDiscountRoom`) — or it is refused whole (promotions.md §1.3, §1.6). **A refused coupon,
   or points refused, never fails the quote**: it is built without them and carries the reason
   (owner, 2026-10-19, answer 31). The quote records whether points were asked (`use_points`) apart
   from how many, so "no points" and "as many as allowed" stay distinct.
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

**As built (step S5)** — my assumptions, stated for the owner to reject:
- **The reasons a quote carries**: a refused coupon, Promotions' `CouponRefusal` (`UNKNOWN_CODE`, …,
  `PASSES_CEILING`); points not used, Loyalty's `RedemptionRefusal` (`NOTHING_TO_REDEEM`, …) or Sales's
  own two — `NOT_WITH_COUPON` (step 6's skip) and `PASSES_CEILING` (more than the coupon left of the
  room). The screens show each its own words.
- **A refusal that fails the quote names what failed**: `NotOrderable` carries the sizes (`variants`),
  so the cart can mark each; an empty cart is `NotOrderable` with none. An off store is `MayNotOrder`
  before anything of the cart is read. A size Inventory gives no answer for is not orderable.
- **The quote keeps what the checkout shows**: the coupon's names (also when refused, so the reason
  names it) — its id only when taken —, Loyalty's mode and most usable points, the prices' moments to
  the microsecond; the quote's own made and expiry times in whole seconds, as every table keeps them, the
  expiry rounded down. Prices that end while the quote is built are asked again once; ending again,
  `QuoteExpired`. At most 2,147,483,647 points may be named.
- **Online is not offered until stage 7** (§1.9): an individual is offered "staff will contact you" only.
- A quote is kept whole as itemised (`result`) and **expired quotes are deleted nightly at 03:30
  (Riyadh)**, after the carts' 03:15.

### 1.3 Placing the order

`PlaceOrder(quoteId)` — a quote of this buyer, not expired. **One transaction**, in this order:

1. **The quote is locked** (`SELECT … FOR UPDATE`) and **an order already placed from it is answered
   as it is** — before the quote's expiry is checked, so a retry after 15 minutes still gets its order — placing twice is placing once, however often the button is pressed (b2b.md §9 #7).
   The order keeps its quote's id (unique), the backstop. Then, **under a lock per customer and store**
   (an advisory lock held to the transaction's end), **the orders already waiting for approval are
   counted**: as many as allowed (§1.10) is `TooManyWaitingOrders`, shown as itself, not as
   `QuoteChanged` — two placements sent at once cannot both pass (answer 51).
2. **The order's public number** — the store's code in capitals and the store's own sequence,
   (owner, 2026-10-09; the KSA store's code is `sa`, platform.md §5.2), **each store's sequence starting
   at 1, shown four digits at least** — `SA-0001` … `SA-9999`, then `SA-10000` (owner, 2026-10-26,
   answer 35 as changed; the old shop's numbers are in another format, so none clash) — taken first,
   because Promotions and Loyalty record it. **A database sequence per store**, created by the
   migration for the stores that exist and by a `StoreCreated` listener for each store opened later: a
   placement that fails leaves a gap rather than making every placement in the store wait on a lock
   (accepted, owner 2026-10-26). The internal id is never shown (handoff §5.3).
3. **Every rule of the quote that can change in 15 minutes, again**: the store still on;
   `customerMayOrder` and, for a company, `isApproved` (a company suspended meanwhile cannot order,
   handoff §8.2); every line still orderable in its mode and within its minimum and maximum (Catalog —
   archived, "Not available now", or no longer chosen by the store); the categories still open to the
   account type; the address still there and accepted; the bank account still filled in when the way
   to pay is a bank transfer; **the gift level still as quoted** (quote step 8 again — another gift, or
   none, is `QuoteChanged`); **the coupon and points still within `customerDiscountRoom`** — a ceiling
   lowered meanwhile is `QuoteChanged` (owner, 2026-10-19, answer 37).
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

**As built (step S6b)** — my assumptions, stated for the owner to reject:
- **The locks in one order**: Sales's own first (the quote, then the cart), then Promotions' and Loyalty's,
  Inventory's last (stage 5: its hold takes Inventory's store lock, then Catalog's) — after it only new
  rows are written, no existing row locked. The order is written before the other modules are called, and
  `recordOrder` comes before `hold`; all in one transaction, so the outcome is the order above. A deadlock
  with a Catalog write meanwhile is tried again, up to 3 times (a new number each time; gaps allowed).
- **The re-checks run on the quote's own lines**, never the cart's (the cart may have changed since).
- **`QuoteChanged` names what moved** (`reason`): a rule of the quote (`audience`, `way_to_pay`, `gift`,
  `ceiling`), something gone since (`store`, `customer`, `lines`, `phone`), or the refusing error's type —
  only these refusals: `sales.may_not_order`, `sales.not_for_your_account`, `sales.not_orderable`,
  `sales.address_not_usable`, `promotions.coupon_refused`, `promotions.coupon_changed`,
  `loyalty.redemption_refused`, `loyalty.redemption_changed`, `loyalty.points_programme_off`,
  `inventory.not_enough_stock` (its sizes passed on as `variants`). Any other error is a fault and goes
  on as itself.
- **Placing empties the cart of what was bought**: each quoted line's pieces leave the cart (a line
  raised since keeps the rest; one with none left goes), and the coupon is cleared when it is still the
  one quoted; lines added since stay.
- **The order keeps the coupon's code as Promotions keeps codes**: upper case, Latin digits.
- **Anonymizing** (§1.12) is one queued listener, `ForgetCustomer`: the carts deleted and the orders' email
  cleared; the name, phone and address stay.
- `PlaceOrder` takes the store as well as the quote's id: the permission is checked in that store, and a
  quote of another store is `QuoteNotFound`.

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
order cannot be marked shipped until what it holds covers what is due — `PAID`, `REFUND_DUE` or
`NOTHING_DUE`.

### 1.5 Shipping, delivery, and a parcel that comes back

- **Marking shipped**: the carrier, and a tracking number when there is one (free text until stage 7;
  the number optional — my assumption, stated for the owner to reject);
  `InventoryApi::ship` takes the stock off (in a store with no provider; a wired store's provider is the
  source); refused (`NotShippable`) only if it holds less than is due without "may ship unpaid" —
  **an order flagged "stock was reduced" ships** (answer 36), and in a store with no provider a
  shipment larger than the counted stock takes it to 0 and records the shortfall for a recount, never
  refused (answer 41; Inventory's `ship`, stage 5).
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
  warehouse — or lost for good, with nothing to put back — choosing which pieces go back into stock (a
  damaged one may be left out). `InventoryApi::release` first frees what the order still holds (a wired
  store's lines not yet ticked); then `InventoryApi::returned(orderId, cameBackId, lines)` gets only the
  pieces put back **whose stock was taken** — every piece in a store with no provider, the ticked ones in
  a wired store — so nothing is counted back twice (inventory.md §2.1, the came-back callers, PR #109 merged). It is settled **as a whole return of an order
  never delivered**: what is due becomes 0, so everything paid, shipping included, is a refund due,
  settled by hand (§1.9); `LoyaltyApi::orderCancelled` gives the points used back (nothing was earned);
  the coupon's use stays counted (promotions.md §2.1). The order is `UNDELIVERED`; the customer sees
  Cancelled, then Refunded.

**As built (step S8)** — my assumptions, stated for the owner to reject:
- **Shipping** needs the order approved and not yet shipped; the carrier is 1–100 characters, the
  tracking number optional (up to 100). Inventory's `ship` is called once per order, last, after the
  shipment is written under the order's lock (stage 5: a repeated partial shipment would be taken twice).
- **"Reduced in the provider"** is ticked only on an order placed in a wired store, approved or completed,
  for its gift and its sizes whose stock counted when it was placed; once each. **The order keeps the
  facts Inventory's hold fixed at placement** — `orders.wired`, `order_lines.counts_on_stock` (the gift
  always) — and ticks and a parcel that came back go by them, never by how the store is set today.
- **Delivered**: the return deadline is the delivery plus the store's window then; `OrderDelivered` goes
  into the outbox; Loyalty's `earn` comes last.
- **Came back**: staff name the pieces put back, a size and gift at a time, never more than the parcel
  held; none for a parcel lost for good. For an order placed wired only the ticked sizes are given back
  to Inventory. Loyalty, then Inventory's `release` and `returned`, last.
- The return deadline is cut to the minute. The carrier and the tracking number, typed by staff, are
  audited as written only, never in the history (§1.12).

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

**As built (step S7a)** — my assumptions, stated for the owner to reject:
- **Staff's words are required** for a rejection and a staff cancellation (1–500 characters); a customer's
  "Other" needs words, a listed reason takes none.
- **Each change locks the order first**, then Loyalty's `orderCancelled`, then — last — Inventory's
  `release`; tried again on a deadlock. A staff change is audited (`sales.order.approved`, `…rejected`,
  `…cancelled`, `…short_cleared`, with what changed); every change is in the order's history. **Words
  typed by staff stay on the order's row only** — the audit says they were written, never what — so
  anonymizing has nothing to rewrite in the history or the audit log.
- **An order of a store the reader does not cover is no order** (`OrderNotFound`); holding the job
  nowhere is not allowed (`Unauthorized`); a customer's other order is no order.
- **"Stock was reduced"** (§1.11) flags only the named orders of the event's store still waiting or
  approved and unshipped, once each, each event acted on once (`processed_events`), from the queue
  (`FlagShortOrders`); clearing an order with no flag changes nothing.
- An off store's orders are decided and cancelled as any other (answer 39; §1.12).

### 1.7 Staff edits before shipping

**Staff contact the customer before shipping** and settle anything missing with them (owner,
2026-10-10); **they may then fully edit the order** (owner, 2026-10-10): add lines, remove lines,
change quantities, change the delivery address to another of the customer's addresses in that store.
**An order may be edited while it is `PENDING_APPROVAL` or `APPROVED`, not shipped, and no line is
ticked "reduced in the provider"** (Inventory then refuses, `inventory.hold_not_editable`). Each edit
has its own id and a reason, and is audited. **Staff first see the edit worked out** (steps 1–6, reads
only), then confirm it; confirming works it out again in one transaction, under the order's lock.

**An edit keeps the rules the order was placed under** (owner, 2026-10-10): the discount ceiling, the
points cap and the shipping rule — so changing only the address never trims a coupon or points.

**Added lines follow checkout's rules** (§1.2 step 2): orderable here in their mode, within their
minimum and maximum, wholesale only for a company, their category open to the account type, priced;
their stock is checked by the hold (step 7). **Every changed line's new quantity is held to its
minimum and maximum too**, and **staff may add a gift** when the edited order now reaches a level and
had none (owner, 2026-10-19, answer 33).

1. **Prices** — `PricingApi::pricesForEdit` (pricing.md §1.11, #107 merged as `89c07e9`): **the kept parts as
   they were sold** (`KeptPartDto` from the order's lines: variant, mode, quantity as edited, list unit,
   unit, price kind), **the added lines priced now** — a wholesale one by **today's band for the line's
   new total quantity, applied to the added pieces only** (owner, 2026-10-10: "band for the new
   total") — and **the order's own VAT rate**. A raised quantity adds a **part** to the line; **a
   lowered quantity takes the pieces off the newest part first**, and a part left with none goes. The
   result's lines come back in the order given, kept parts then added lines (pricing.md §2.1),
   so each matches its order line by place — once Sales has checked that nothing came back `unpriced`
   (an unpriced line is left out of the lines; an unpriced added line is refused).
2. **The room** under **the ceiling the order was placed under**, for the edited prices —
   `PromotionsApi::orderDiscountRoom` (promotions.md §1.6).
3. **The coupon** — `PromotionsApi::couponAfterEdit` with the whole room: never more than it is now (after an
   earlier edit, that edit's),
   0 if the edited order is under its minimum or has no eligible line, **trimmed to the room only when
   the coupon alone passes it** (owner, 2026-10-10: it "can only stay or shrink"; promotions.md §1.3).
4. **The points** — `LoyaltyApi::pointsAfterEdit` (a Loyalty addition, §2.3) with the edited
   `net_subtotal` and the room the coupon left: the points discount becomes the least of what it was,
   Loyalty's cap on the edited `net_subtotal` (by the percent the order redeemed under), and that room — **the points give way before the coupon**
   (handoff §11.3: "points are evaluated last and refused first").
5. **Shipping** — worked out again by the order's own snapshotted fee and free-above amount.
6. **The totals** — `PricingApi::totals` over the edited prices, the coupon, the points and the
   shipping. **The gift — staff decide** (owner, 2026-10-10): keep it, swap it for the gift of the level
   the edited order now reaches (up or down, stock permitting), or remove it.
7. **On confirming**: `InventoryApi::adjustHold(orderId, newLines)`, all or nothing (inventory.md §1.4,
   #106 merged as `4cca6fc`); `LoyaltyApi::orderEdited` gives back the points above the new points discount with
   their old dates and answers the points still used; `PromotionsApi::updateCouponUse` records the
   coupon's new discount (the use still counts). Every line's shares are allocated again (§1.8), so
   returns settle on the edited order.
8. **A difference in money is recorded, and staff settle it by hand** (owner, 2026-10-10): paid more than
   the new total → **refund due**; less → **balance due**, and shipping before then follows "may ship
   unpaid" (§1.4).

An edit does not clear the "stock was reduced" flag by itself (§1.11).

**As built (step S9)** — my assumptions, stated for the owner to reject:
- **Staff give the order's lines as they should be** (a size, its mode, its quantity; the gift aside): a
  size and mode not named goes; at least one line stays. A raised quantity adds one part of this edit; the
  order keeps each part's own price and the edit that added it.
- **The gift**: `KEEP` keeps it as it is, even below its level now (as after a partial return, §1.8);
  `REMOVE` (either in any case); or a level the edited order reaches whose gift is in stock — what is free
  now covers the gift and the pieces of its size the edit holds beyond what the order holds already —
  added when it had none, swapped when it had one. `KEEP` on an order with no gift keeps none.
- **An edit's checks are checkout's** (§1.2 step 2) on every added or raised line's new total; **a lowered
  line only within its limits** (answer 33) — a product since marked not available now may still be
  lowered or removed, never raised; a line left as it is, or removed, is not checked. Their stock is the
  hold's (`adjustHold` all or nothing — a refusal leaves the order as it was).
- **An edit that leaves every line as it is** (the address or the gift only) keeps the coupon, the points
  and each line's coupon share as they are — neither is worked out again.
- **Each line counts on stock as the hold counts it**: with no provider when placed, every piece; wired, by
  the size's switch today — kept parts too, since `adjustHold` works every line out again.
- The edit keeps, with its own id: the reason (staff's words, on the edit's row only — the audit says it was
  written), who, when, and the order's lines and totals before and after with the money difference (never
  an email or a phone). The address changed is audited as personal. Promotions' `updateCouponUse` and Loyalty's `orderEdited` are told
  of every edit of an order that has a coupon or points; Inventory's `adjustHold` last.
- `PreviewOrderEdit` works the same out without writing; both refuse an order unless it is waiting or
  approved, unshipped, with nothing ticked (`NotEditable`).

### 1.8 Returns, refunds and their shares

- **The customer asks**, within **the store's return window — 14 days from delivery by default**,
  admin-set per store (owner, 2026-10-09) — **fixed when the order is delivered**: delivery time plus
  the window's days to the minute; changing the setting later moves no delivered order's deadline
  (owner, 2026-10-19, answer 34): which pieces and how many, **a reason from a list or
  "Other"** (damaged, wrong item, not as described, changed my mind, other), and **up to 3 photos**,
  kept private for staff (owner, 2026-10-10; Platform's `uploadMediaFor`, registered as a media use
  that blocks deleting, handoff §5.5). **The gift may be returned too, and refunds nothing** (owner,
  2026-10-10). A request counts the pieces asked in every return requested or accepted, and the pieces
  received in every return received or completed — refused, withdrawn and closed ones count none — so
  two requests never claim one piece, and a piece that never came may be asked again. The customer may withdraw a request until staff decide.
- **Staff accept or refuse** it — a refusal with a reason the customer sees (my assumption, stated for
  the owner to reject). Return shipping is paid by the company (handoff §12.5); the refund is manual
  (handoff §12.4). An accepted return whose pieces never arrive may be **closed**.
- **Staff mark the pieces received** — how many of each came, and which go back into stock (a damaged
  piece may be left out) — `InventoryApi::returned(orderId, returnId, lines)` (inventory.md §2.1): restocked in a
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

**As built (step S10a — asking and deciding)** — my assumptions, stated for the owner to reject:
- A request names **order lines** — a part (§1.7) or the gift — and how many pieces of each; a line of
  another order, or more pieces than the line holds less those other returns count, is `ReturnNotAllowed`,
  and so is an order not delivered or a request after its deadline (the deadline itself still in time).
- **Photos only while the return waits for staff** (`REQUESTED`); a fourth is `ReturnNotAllowed`.
- Each move is in the order's history (`RETURN_…`, the return's id; the customer's reason as its code,
  never their words) and, when staff make it, audited on the order; staff's refusal words are kept on the
  return only — the audit says only that they were written (`personal`). A photo is kept on the return
  with no history row of its own (no such kind in §5).
- The deadline is kept to the minute; a request is checked in whole seconds, so the deadline's own second
  is still in time.

**As built (step S10b — receiving and completing)** — my assumptions, stated for the owner to reject:
- `ReceiveReturn` names **every line of the return once**: how many came (0 to all asked) and how many of
  those go back into stock; only those going back are told to Inventory (`returned`, last; not called when
  none go back).
- `CompleteReturn` refunds by `Domain/Service/ReturnRefunds` (the running totals above). The shipping's VAT
  share is the order's VAT less what its lines carry. The refund comes off `due`; the money itself is
  paid back by hand (`RecordRefund`, §1.9) — an order holding more than it is now due shows `REFUND_DUE`
  until then. It answers the refund, so staff see it. Each piece's goods share moves as **one** share
  (total less coupon and points shares), never the three rounded apart — so no return's goods go below 0 —
  in whole numbers of any size. Loyalty is told the pieces' goods shares and points shares; `ReturnCompleted`
  goes to the outbox. A return of which nothing came completes, refunding nothing.
- `SalesApi` (§2.1) now reads Sales's own rows (`Infrastructure/Eloquent/DatabaseSalesApi`; the placeholder
  is gone): the latest delivery by delivery time (then line); `returned` when any piece of the product came
  back in a completed return of any of the customer's orders.
- Staff accept or refuse a waiting return; close an accepted one; the customer withdraws a waiting one;
  any other move is `InvalidOrderChange`.

**The shares** — fixed at placement and after every edit, so returning everything refunds exactly
`order_total`, no halala lost (handoff §5.1):

1. **The coupon's** — Promotions' answer, one share per line of the prices, in their order, 0 where a
   line is not eligible (promotions.md §2.2).
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

**No cash on delivery** in any store (handoff §12.4, §16). **Every gateway is an adapter behind
`PaymentsApi`** (owner, 2026-10-19, answer 38): MyFatoorah (KSA) first; Tamara and Tabby — individuals
only (handoff §12.4) — and an Egypt gateway added later as adapters, with nothing in Sales changing.
Payments' (stage 7).

- **"Staff will contact you"**: the order is placed unpaid; staff arrange payment and record it.
- **Bank transfer — and its document** (b2b.md §9 #2, amendment 12(c): the owner put it in Sales,
  stage 6): the company is shown the store's account, transfers, and **uploads the transfer's
  document** on its order — **up to 3 files**, PDF, JPEG or PNG, private (handoff §5.5; my assumption,
  stated for the owner to reject, for the number), while the order holds less than is due — a balance
  after an edit included. Staff open them
  from the order and record the payment. A target time to check transfers is Payments' (stage 7,
  handoff §12.4).
- **Until stage 7** there is no online payment: individuals see "staff will contact you"; **staff record
  every payment by hand** — amount, way, reference, date.
- **Refunds are all manual** (handoff §12.4): staff record each one — amount, way, reference, date —
  never more than the order holds (paid less refunded).
- **Recording once**: each record carries the form's own request id, so pressing twice records once
  (b2b.md §9 #7). **A record is never changed or deleted**; one entered by mistake is **voided** by
  staff with a reason, and no longer counts — **a payment only while what was paid without it still
  covers what was refunded** (answer 42; refunds are recorded per order, not against one payment).
- **The ways recorded** — payments and refunds alike: `BANK_TRANSFER`, `ONLINE` (stage 7), `OTHER` with
  a note. My assumption, stated for the owner to reject.
- **Sales keeps the money recorded against each order**, from which the payment state follows (§1.4).
  From stage 7, Payments keeps its gateway transactions and tells Sales of each payment through
  `PaymentSucceeded` / `PaymentFailed`; Sales records it here.

**As built (step S7b)** — my assumptions, stated for the owner to reject:
- **The ways staff record by hand** are `BANK_TRANSFER` and `OTHER` (with a note); `ONLINE` is Payments'
  own, from stage 7. **A payment is always the whole balance still owed** — due less what is held, filled
  in by Sales under the order's lock, never typed; refused (`InvalidOrderChange`) when nothing is owed —
  **no partial payments** (owner, 2026-10-27, §9.1c 49): an edit that raises a paid order's total leaves a
  balance, paid as one payment more, as is what a refund leaves short. `RecordPayment` may carry the balance
  the screen showed (`shown`): refused (`InvalidOrderChange`) when what is owed is no longer it — an edit or
  a refund meanwhile — so a transfer is never recorded against another amount (my assumption). A refund's amount is typed as a
  decimal in the order's own currency, never more decimals than it has, up to what the order holds. The
  reference is optional, up to 100 characters.
- **"The date"** is the day the money moved — a column `happened_on`, not after today in the store's own
  time, with no earliest day — apart from when it was recorded (`recorded_at`). One record holds at most
  1,000,000,000,000 in the smallest unit.
- **Pressing twice records once**: the same form id answers the record it made; a form id of another
  order is refused.
- A payment may be recorded on any order (money can arrive late); what is held above what is due shows
  as a refund due (§1.4).
- **"May ship unpaid"** is switched on or off by staff on an approved, unshipped order
  (`sales.order.fulfil`); each switch is in the history and audited. What staff type — a reference, a
  note, a void's reason — is audited as written only (§1.12).
- **The bank-transfer documents** (step S7c): `UploadTransferDocument` under `sales.order.manage_own`
  (also the scope of Platform's `uploadMediaFor`, in the order's store) — the customer's own order paid by
  bank transfer, while it holds less than is due; at most 3, numbered 1–3 in the order they came; PDF,
  JPEG or PNG judged by the file's content, not its name; private; in the order's history. Platform
  keeps every one an order holds (`SalesFilesUsage`: each use blocks the delete). Staff open them
  from the order (step S11). The order stays locked while the file is stored, as B2B's documents are; a
  placement rolled back afterwards removes the stored file (Platform). The file's name the customer gave
  stays with it after anonymizing — an order is a financial record (§1.12).
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

And **once for every store** (answers 50 and 51; owner, 2026-10-28), the coupon box's limit (§1.1) and
the orders waiting a customer may have:

| Key | Type | Range | Default | Means |
|---|---|---|---|---|
| `sales.coupons.wrong_codes` | Integer | 1–20 | **5** | Codes naming no coupon a customer may type in the window |
| `sales.coupons.window_minutes` | Integer | 1–60 | **10** | The window, in minutes, from the first such code |
| `sales.orders.waiting_most` | Integer | 1–20 | **3** | Orders waiting for approval a customer may have in one store (§1.2 step 1, §1.3 step 1; answer 51) |

Changed under `sales.settings.update` in **every store** — an admin of all stores or a Super Admin.

### 1.11 Stock that runs short after placement

Inventory may let staff remove stock that is held for orders, and then publishes `HoldsShort(storeId,
variantId, orderIds)` (inventory.md §6.1). **Sales flags those orders "stock was reduced"** for staff — who
contact the customer and edit (§1.7), cancel, or wait for stock. **The flag only marks the order —
it never blocks shipping** (owner, 2026-10-11, inventory.md §1.5: "no placed order is blocked";
`HoldsShort` names every order still holding the size, short or not; answer 36). **Staff clear the flag
by hand** once it is settled: Inventory's hold moves only the pieces an
edit adds or frees (inventory.md §1.4), so a successful edit does not show that the shortage is gone.

### 1.12 A deleted customer, an off store, each store's rows

- **Orders keep their snapshot of name, phone and delivery address** and are never anonymized (handoff
  §7.9: a financial record); **the email snapshot is cleared** (owner, 2026-10-10). An anonymized
  account's carts are deleted.
- **An off store's unfinished orders stay with its staff to finish** (handoff §1; owner, 2026-10-10):
  **Sales's order and return screens list an off store to its own staff while it has unfinished
  orders** — an exception to platform.md §9.10 #4, for these screens only. Sales builds that store filter
  itself, from `PlatformApi::allStores` and the stores where the reader holds the permission: Access
  keeps an off store a staff member holds (inferred from `GrantRules::requireKnownStores`, access.md
  amendment 58(b); checked again at build). Unfinished: an order not
  yet completed, cancelled, rejected or undelivered, one with a return not yet completed, refused,
  withdrawn or closed, or one whose payment is `UNPAID`, `PARTLY_PAID` or `REFUND_DUE` — my reading,
  stated for the owner to reject. Its shop and new orders are gone with it; its customers may still
  cancel, ask a return and upload a transfer document for orders already placed (answer 39).
- Carts, quotes, orders and returns belong to a store; one repository per kind (loyalty.md §1.12). **As
  built** (S12): a cart's, a quote's and a list's calls name the store; a change to one order or return
  takes its id alone — the row's store is read under its lock and authorized once (`OrderAccess`,
  `ReturnAccess`), and the store-binding composite keys (§5) tie every row written for it to that store.
  The nightly jobs are the passes across stores.

### 1.13 Best-selling

Catalog's "Best-Selling" sort is fed by Sales (catalog.md §2.2: `ListingFacts::salesRanks`; built in S11a,
catalog.md amendment 30). **A nightly
job ranks each store's products by the pieces sold in that store's orders approved or later, not cancelled, rejected or
undelivered, over the last 90 days**, and pushes the ranks. My assumption for the window, stated for the
owner to reject; **a waiting order is not yet sold** (owner, 2026-10-28, answer 51).

**As built (step S11a)** — `PushSalesRanks` (nightly, 03:45 Riyadh, the system only): per store, the pieces
of non-gift lines in its orders **placed** within the last 90 days (to the second) whose status is not
`PENDING_APPROVAL` (answer 51), `CANCELLED`, `REJECTED` or `UNDELIVERED` — returned pieces still count (kept, owner 2026-10-28) — ranked 1 the most, a
tie to the newer product (the shop's own tie-break); **every store, on and off, gets its whole ranking**, so a
product that sold nothing loses its rank. Catalog keeps it (catalog.md amendment 30).

---

## 2 · Public contract

### 2.1 `Modules\Sales\Public\Contracts\SalesApi`

**Declared on `main` before Feedback is built** (feedback.md §2.1, and its handoff §17 amendment in PR
#104), with `ReturnCompleted` — on 2026-10-19: `Public/Contracts/SalesApi`,
`Public/Dto/DeliveredPurchaseDto`, `ReturnedProductsDto`, `Public/Events/ReturnCompleted`, bound until
the build to `Infrastructure/External/NothingSoldYet` (nothing delivered, no return) so Feedback runs
before Sales exists; Sales's build replaces the binding. **Replaced in step S10b** by `Infrastructure/Eloquent/DatabaseSalesApi`.

| Method | Answers |
|---|---|
| `deliveredPurchase(string $customerId, string $productId): ?DeliveredPurchaseDto` | Whether the customer had that product delivered in any store: `variantId` (the latest delivered, by delivery time), `deliveredAt`, `returned` (any of that product in a completed return); gift lines never (feedback.md §9.1b, 20) |
| `returnedProducts(string $returnId): ?ReturnedProductsDto` | `customerId`, `storeId`, `productIds` of a completed return — only products with pieces received, gift lines never (feedback.md §9.1b, 20); null for a return not completed or unknown |

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
| Pricing | `prices`, `totals`; **`pricesForEdit`** with `KeptPartDto`; **the result's lines in the order given**, an unpriced line left out and named | `prices`, `totals` on `main` (#97); `pricesForEdit` and the lines' order on `main` (#107, `89c07e9`) |
| Inventory | `stock` (with `availableAsGift`), `hold`, `ship`, `reducedInProvider`, `release`, `HoldsShort`; `adjustHold`, `returned` | Interfaces on `main` (#97); `HoldsShort` specified (#102); `adjustHold` and `returned` on `main` (#106, `4cca6fc`); `release` and `returned` for a parcel that came back on `main` (#109, `906fca62`) |
| Promotions | `applyCoupon`, `knowsCoupon` (the coupon box's limit, answer 50), `customerDiscountRoom`, `giftLevels`, `useCoupon`, `recordOrder`; for edits `orderDiscountRoom` (the order's own ceiling), `couponAfterEdit` (with the whole room, never above the discount now), `updateCouponUse`; **one share per price line, in the prices' order** | promotions.md (PR #101) — corrected there with this spec |
| Loyalty | `earningPreview`, `quoteRedemption`, `redeem`, `earn`, `orderCancelled`, `orderReturned`; **`pointsAfterEdit`** (reads, by the cap the order redeemed under) and **`orderEdited` answering the points still used** | loyalty.md (PR #96) — added there with this spec |
| Catalog | **the store's variants in bulk** (`storeVariant` reads one, `CatalogApi`); `variant`, `product` and the bulk reads Feedback adds (snapshots); **whether a product's category is open to an account type in a store**, in bulk; `ListingFacts::salesRanks`; the usage check (a variant on an order is never deleted, promotions.md §1.2) | `storeVariant`, `variant`, `product`, `ListingFacts` exist; **the bulk store-variant read and the category read** — Catalog additions, built in S3 (catalog.md amendment 29) |
| Access | `customerMayOrder`, `customer`, `addresses`, `address`, `GuestBecameCustomer`, `CustomerAnonymized` | Exist |
| B2B | `isApproved`, `bankAccount` | Exist |
| Platform | `store` (`isActive`), `allStores`, `StoreCreated`, settings, the audit log, `uploadMediaFor` and media uses (return photos, transfer documents), staff names | Exist |
| Shared | **`outbox_messages` and `processed_events`, in the `public` schema** (platform.md §5.6), and the outbox's relay | **Built with Sales** — the first module to publish through the outbox |

**Other modules' refusals** reach Sales as `DomainError` with a stable `type()` (pricing.md §2.1:
modules export no error classes); Sales catches them by key, following the `{module}.{snake_case}`
convention (e.g. `catalog.attribute_kind_locked`): `promotions.coupon_refused`,
`promotions.coupon_changed`, `loyalty.redemption_changed`, `loyalty.redemption_refused`,
`loyalty.points_programme_off`, `inventory.not_enough_stock`, `inventory.hold_not_editable`,
`inventory.no_hold`, `pricing.duplicate_lines`; and, confirmed against the modules' code before the
build, `inventory.stock_below_zero` (no longer thrown by `ship` since answer 41's change, built), `inventory.ship_more_than_held`,
`inventory.already_held`, `inventory.quantity_invalid`, `loyalty.order_not_settleable`.

### 2.4 What Sales defines for stage 7 to fill

Declared in those modules' `Public/Contracts`, implemented in stage 7 — **with their own types only**
(handoff §4.4: Shipping → Platform, Catalog; Payments → Platform):

- **`ShippingApi::options(StoreId, ShippingDestinationDto, list<ParcelLineDto>, Money $goodsTotal):
  list<ShippingOptionDto>`** — the destination as Shipping's own DTO (country, city, district, postal
  code — never Access's address); each option a carrier, its fee (free-shipping thresholds applied,
  handoff §12.5) and its tracking URL template. Until stage 7 Sales uses its flat fee (§1.10).
- **`PaymentsApi::methods(StoreId, PaymentAudience, Money $amount)`**, **`start(orderId, method, Money
  $amount)`** — Payments takes an order id and an amount, nothing more (handoff §4.4) — and
  **`payment(paymentId): ?PaymentDto`** (its order, amount and the gateway's reference), which Sales reads
  when `PaymentSucceeded` arrives (the event carries ids only) —
  `PaymentAudience` (`INDIVIDUAL`, `COMPANY`) is Payments' own enum; each gateway an adapter
  (MyFatoorah first; Tamara/Tabby, for individuals only, and an Egypt gateway later — answer 38).
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
| `AddCartLine`, `ChangeCartLine`, `RemoveCartLine`, `SetCartCoupon`, `DismissCartNotice` (§4.2 B17) | Command | `sales.cart.manage` — a guest's cart (no coupon): `sales.guest_cart.manage` |
| `ViewCart` | Query | `sales.cart.manage` / `sales.guest_cart.manage` |
| `StartCheckout`, `PlaceOrder` | Command | `sales.order.place` |
| `CancelOwnOrder`, `RequestReturn`, `WithdrawReturn`, `UploadReturnPhoto`, `UploadTransferDocument` | Command | `sales.order.manage_own` (also the scope of `uploadMediaFor`) |
| `ListOwnOrders`, `ViewOwnOrder`, `ViewOwnReturn` | Query | `sales.order.view_own` |
| `ListOrders` (all, unpaid, flagged short — handoff §14: Sales › All orders · Store orders · Unpaid orders), `ViewOrder`, `ListReturns`, `ViewReturn`, `OpenOrderFile` (return photos, transfer documents), `PrintOrder`, `ListOrderStores` (the screens' store filter, §1.12) | Query | `sales.order.view` |
| `ApproveOrder`, `RejectOrder` | Command | `sales.order.approve` |
| `PreviewOrderEdit`, `EditOrder`, `ClearShortFlag` | Query, Command, Command | `sales.order.edit` |
| `CancelOrder` | Command | `sales.order.cancel` |
| `ShipOrder`, `TickReducedInProvider`, `AllowShipUnpaid`, `MarkDelivered`, `MarkCameBack` | Command | `sales.order.fulfil` |
| `RecordPayment`, `RecordRefund`, `VoidMoneyRecord` | Command | `sales.payment.record` |
| `AcceptReturn`, `RefuseReturn`, `ReceiveReturn`, `CloseReturn`, `CompleteReturn` | Command | `sales.return.manage` |
| Platform's `UpdateSetting`, on its settings page (Sales declares the three settings, no command of its own) | Command | `sales.settings.update` |
| `EmptyOldCarts`, `DeleteExpiredQuotes`, `PushSalesRanks` | Command, scheduled | `sales.system.run` |
| `MoveGuestCart`, `ForgetCustomer`, `FlagShortOrders`, `CreateOrderSequence`, `RecordGatewayPayment` (stage 7) | Listeners | — (the events of §6, run as the system) |

The outbox's relay is Shared infrastructure, run by the scheduler as the system; it changes no
module's data but the outbox's own rows.

---

### 3.1 The reads, as built (step S11b — the customer's own)

The spec names the reads, not their fields; the fields below are my assumptions, for the owner and the
screens session to change (an addition request, as with Promotions). Every moment is a `DateTimeImmutable`,
every amount `Money`, with its currency's `exponent` beside it; **the customer is shown order numbers, never
ids** — the order's id and its lines' ids are carried only to act on them (cancel, ask a return, upload a
document), the store, product and size ids only to link and resolve names; a return, having no number of
its own, is known by its id (my assumption). **An order is found by the customer and its number**, which is
unique because a number carries its store's code (`SA-0001`); a later format must keep it unique per
customer, or these reads take the store too.

- **The statuses** (§1.4), worked out by `Domain/Service/OrderStatuses`: `PaymentStatus` — `NOTHING_DUE`,
  `UNPAID`, `PARTLY_PAID`, `PAID`, `REFUND_DUE`, `REFUNDED` — and the one a customer sees,
  `CustomerOrderStatus` — `NEW`, `PROCESSING`, `SHIPPED`, `DELIVERED`, `CANCELLED` (cancelled, rejected,
  undelivered), `REFUNDED` (such an order, or a wholly returned one, paid back). Public enums.
- **`ListOwnOrders(?after)`** (`sales.order.view_own`, every store): newest first, **20 a page**, `after` the
  last number shown (a number not theirs ends the list) → `OwnOrdersPage{orders, next}`; each
  `OwnOrderRow{number, storeId, placedAt, status, total, pieces, exponent}` (pieces bought, the gift left out).
- **`ViewOwnOrder(number)`** (any case) → `OwnOrderView{orderId, number, storeId, placedAt, status, payment,
  wayToPay, lines, grossSubtotal, netSubtotal, couponCode, couponDiscount, pointsUsed, pointsDiscount,
  goodsTotal, shipping, vat, total, due, paid, refunded, shipment, carrier, trackingNumber, shippedAt,
  deliveredAt, returnDeadline, address, returns, transferDocuments, mayCancel, mayReturn,
  mayUploadTransferDocument, exponent}` — `lines`: `OrderLineView{lineId, productId, variantId, nameAr, nameEn,
  values, code, mode, gift, quantity, unit, listUnit, total, returnable}` (the placed parts first, then each
  edit's, the gift last; `returnable` the pieces still to ask now, 0 outside the window); `returns`:
  `OwnReturnRow{returnId, status, requestedAt, refundDue}`. `mayCancel` while waiting or approved and
  unshipped; `mayReturn` within the window (in whole seconds) while a piece is left; `mayUpload…` paid by
  bank transfer, holding less than is due, under 3 documents. Another customer's number: `OrderNotFound`.
- **`ViewOwnReturn(returnId)`** → `OwnReturnView{returnId, orderNumber, status, reason, reasonOther,
  staffReason, requestedAt, decidedAt, receivedAt, completedAt, withdrawnAt, closedAt, refundDue, lines, photos,
  exponent}` — `lines`:
  `ReturnLineView{nameAr, nameEn, values, code, gift, asked, received}`; `photos` a count (the files open
  through staff's `OpenOrderFile`). Another customer's return: `ReturnNotFound`.
- **`BuyAgain(orderNumber)`** (the screens' B6; `sales.order.view_own`, then `sales.cart.manage` in the order's
  store) → `BuyAgainResult{storeId, added, skipped}`: the customer's own order, of any state, put back in that
  store's cart — each line but the gift, the parts of one size and mode as one, added to what the cart holds
  as the cart adds (§1.1). A line not sold there now (`NOT_AVAILABLE`, the store off too) or not to this
  account (`NOT_FOR_YOUR_ACCOUNT`) is left out: `BuyAgainSkipped{variantId, nameAr, nameEn, mode, quantity,
  reason}`, its names as sold; every other goes in as it was, the cart marking what checkout would refuse
  (a minimum or a most passed, stock, a price).
- **`ViewCart(storeId, locale)`** (step S11b-2; `sales.cart.manage`, a guest `sales.guest_cart.manage`) →
  `CartView{storeId, lines, mayOrder, couponCode, notice, subtotal, exponent}` — `mayOrder` whether checkout
  would take this account at all (§1.2 step 1: a guest signs in first; an unapproved company or a customer
  who may not order cannot — said once here, not on each line); `notice` the lines that left at a sign-in
  or a merge, named: `CartNoticeLine{variantId, name, code, values, mode, quantity, reason}` (`CartNoticeReason`
  `WHOLESALE` / `NOT_FOR_YOUR_ACCOUNT`); `lines` in the order added:
  `CartLineView{variantId, productId, name (the page's language), slug, photo, code, values, mode, quantity,
  unit, listUnit, total (today's prices, null unpriced), problem, minimum, maximum}`. **The checkout's own
  rules marked on each line** instead of refusing (§1.2 step 2), the first that applies: `NOT_AVAILABLE`
  (switched off, not orderable, not available now, not in that mode), `NOT_FOR_YOUR_ACCOUNT` (wholesale for
  one not an approved company, or its category closed to the account), `BELOW_MINIMUM`, `ABOVE_MAXIMUM`,
  `OUT_OF_STOCK` (the pieces of a size over both modes), `NOT_PRICED` — `CartLineProblem`, a public enum.
  A guest's wholesale line is marked only by what is true for any account — the account's own rule waits
  for the sign-in (§1.1: guests may add wholesale lines). `subtotal` the sum of those lines' totals at
  today's prices (sale prices applied), null with none — before any coupon, points, shipping or VAT, which
  the quote works out (§1.2). No cart yet: an empty one. One read of the cart costs the same queries
  however many lines it holds (tested).

### 3.2 The reads, as built (step S11c — staff's)

The same rules as §3.1 (fields my assumptions; moments, `Money` with `exponent`); **staff see ids** (they act
on them), **a customer by name only** — `CustomerName{customerId, name, deleted}`, "Deleted customer" once
anonymized — and **staff by the name the reader may see** (`StaffNames`). Every read is `sales.order.view`, in
the order's store.

- **The stores listed** (§1.12; `OrderStores`): the stores where the reader holds the job — **an off store only
  while it holds an unfinished order** (§1.12's reading), every off store to a Super Admin (platform.md §9.10
  #4). **`ListOrderStores()`** → `list<OrderStoreChoice{storeId, code, name, isActive}>`, the screens' store
  filter, an off one marked by `isActive`. `ListOrders` and `ListReturns` list only these; an order or a return
  opened by its id is shown to whoever covers its store, off or finished (a link from the history still
  opens).

- **`ListOrders(?storeId, filter, ?after, ?q, ?customerId)`** (handoff §14: All orders · Store orders · Unpaid
  orders): one store (refused when not covered or not listed) or every store listed; `filter` `ALL`, `UNPAID`
  (holding less than is due — an order waiting for approval included, its total due), `SHORT` (flagged short
  of stock), `WAITING_APPROVAL`, `TO_SHIP` (approved, not shipped), `SHIPPED` (shipped, not yet delivered nor
  come back) or `TRANSFERS_TO_CHECK` (paid by bank transfer, a document uploaded, still holding less than is
  due); **`q`** one box — any part of the order number or of the customer's name as placed (any case, its
  digits read as Latin), or — when the box holds a phone (digits, spaces, `+ - ( )`, 4 digits at least) —
  of the phone by its digits, leading zeros dropped (`0501234567` finds `+966501234567`) — 2 to 100
  characters, shorter none;
  **`customerId`** one customer's orders (the customer page's "See all"); every part with the others; no
  counts (owner: tabs without badges); newest first, **50 a page**, `after` the last order id →
  `StaffOrdersPage{orders, next}`, each `StaffOrderRow{orderId, number, storeId, placedAt, customer, status,
  shipment, payment, customerStatus, total, exponent, shortOfStock, mayShipUnpaid}`.
- **`ListCarriers(storeId)`** (`sales.order.fulfil` in the store) → `list<string>`: the carriers the store's
  orders were shipped with, each once, the most lately shipped with first, at most 20 — the Ship dialog's
  suggestions; the carrier is still typed freely (§1.5).
- **`ListCustomerOrders(customerId)`** → `list<StaffOrderRow>`: the customer's 10 latest orders in the stores
  listed to the reader — the staff customer page's **`sales.orders`** section (access.md amendment 72(a)):
  `CustomerOrdersSectionData{customerId, orders: list<OrderRowData>}` — each order as the panel's list draws it,
  its total written by the server in the store's own way (§4.1 P7; the screens' change of PR A's first shape); no section
  for a reader who views no orders.
- **`ViewOrder(orderId)`** → `StaffOrderView`: the own view's facts (§3.1) plus the customer by name and **the
  contact the order was placed with** (name and phone — to call them before shipping, §1.7; **the email only
  to staff who may view the customer**, as Loyalty's names: access.md amendment 72(e); owner, 2026-10-26,
  §9.1c 47), the audience, may ship unpaid,
  short of stock since, the decision (by, at, reason) and the cancellation (reason, words, by, at), came back
  at; `money` — `MoneyRow{recordId, kind PAYMENT/REFUND, amount, way, reference, note, happenedOn, recordedBy,
  recordedAt, voidedBy, voidedAt, voidReason}` (`happenedOn` a `Y-m-d` day, never a moment), payments and
  refunds together in the order recorded; `history` — `HistoryRow{kind, at, by, editId, detail}` (codes
  and ids only); `edits` — `EditRow{editId, reason, by, at}`; `ticks` (size, by, at); `returns`; `transferDocuments`
  (media id, position, uploaded at — opened with `OpenOrderFile`); **`wired`** — placed in a store wired to a
  provider — and **`tickable`**, the sizes that may be ticked "reduced in the provider": a wired order's sizes
  whose stock counted when it was placed, and its gift (§1.5; empty when not wired). Another store's order:
  `OrderNotFound`.
  **A deleted customer's order**: `customer` reads "Deleted customer" while the contact keeps the name and
  phone placed with — the order a financial record (§1.12), its email cleared; the screen shows `customer`
  and keeps the contact for the packing slip.
- **`PrintOrder(orderId)`** → `PrintOrderView{storeId, number, placedAt, contactName, contactPhone, address,
  lines, grossSubtotal, netSubtotal, couponDiscount, pointsDiscount, goodsTotal, shipping, vat,
  vatRateBasisPoints, total, wayToPay, exponent}` — a packing slip's and an invoice's facts, the layout the
  screen's. Each line carries the variant's `code`, staff-only (catalog.md amendment 5(d)): an invoice handed
  to the customer leaves it out.
- **`ListReturns(?storeId, ?status, ?after)`** (handoff §14: Returns): one store (refused when not covered or not
  listed) or every store listed; one state (`REQUESTED` … `CLOSED`, any case), `CLOSED_ALL` — refused,
  withdrawn and closed together, the "Closed" tab (owner R1-50) — or all; newest asked first, **50 a
  page**, `after` the last return id → `StaffReturnsPage{returns, next}`, each `StaffReturnRow{returnId, orderId,
  orderNumber, storeId, customer, status, requestedAt, refundDue, exponent}`.
- **`ViewReturn(returnId)`** → `StaffReturnView{returnId, orderId, orderNumber, storeId, customer, status, reason,
  reasonOther, staffReason, requestedAt, decidedBy, decidedAt, withdrawnAt, receivedAt, closedAt, completedAt,
  refundDue, shippingRefunded, lines, photos, exponent}`; each line `{orderLineId, variantId, nameAr, nameEn,
  values, code, gift, asked, received, restocked}`; `photos` `{mediaId, position}`. Another store's return:
  `ReturnNotFound`.
- **`OpenOrderFile(mediaId)`** → `OrderFile{mediaId, url, expiresAt}`: a transfer document or a return photo,
  through Platform's expiring link, to staff who view the orders of its store; any other file, or one of a store
  not covered: `OrderNotFound` (Platform asks the module to check its reader first: catalog.md §2.4).
- **`PreviewReturnRefund(returnId)`** (the screens' B10; `sales.return.manage`) → `ReturnRefundPreview{goods,
  vat, shipping, shippingVat, total, exponent}`: what `CompleteReturn` would refund for a received return —
  the pieces' goods, their VAT share, the shipping and its VAT when it is the return that makes every bought
  piece returned — by the same `ReturnRefunds` on the same facts, written nowhere (owner R2-40: the
  Complete confirmation). Any other state: `InvalidOrderChange`.
- **`SearchEditSizes(orderId, q)`** (the screens' B7; `sales.order.edit`) → `list<EditSizeRow{variantId,
  productId, productNameAr, productNameEn, code, values, photoMediaId, retail {now, before}, wholesale {now,
  before, minimum, bands [{fromQuantity, unit}]}, available, exponent}>`: the order's store's sizes switched on
  there and not archived, by a product's name or a size's code (Catalog's `searchVariants`), 20 at most,
  less those the order has and **less those the edit would refuse** (§1.7, checkout's rules: not orderable
  now, not available now, not open to the order's account, no stock record or not orderable in Inventory) —
  today's one-piece retail price when it sells retail and is priced (`before` only while `now` is lower);
  on a company's order only, its price at its wholesale minimum and its bands (`PricingApi::wholesaleBands`)
  when it sells wholesale; the stock available now when its stock counts (null: the provider's).

## 4 · State machines

```
ORDER      PENDING_APPROVAL ─▶ APPROVED ─▶ COMPLETED            (delivered)
                               APPROVED ─▶ UNDELIVERED          (came back)
           PENDING_APPROVAL ─▶ REJECTED
           PENDING_APPROVAL | APPROVED ─▶ CANCELLED              (not shipped)

SHIPMENT   NOT_SHIPPED ─▶ SHIPPED ─▶ DELIVERED | CAME_BACK        (one per order; shipped only
                                                                   APPROVED, held ≥ due or
                                                                   "may ship unpaid" — a flag of
                                                                   reduced stock never blocks it)

PAYMENT    derived: NOTHING_DUE · UNPAID · PARTLY_PAID · PAID · REFUND_DUE · REFUNDED

RETURN     REQUESTED ─▶ ACCEPTED ─▶ RECEIVED ─▶ COMPLETED
           REQUESTED ─▶ REFUSED | WITHDRAWN
           ACCEPTED  ─▶ CLOSED                                    (pieces never came)
```

Edits: `PENDING_APPROVAL` or `APPROVED`, not shipped, no line ticked "reduced in the provider".


### 4.1 The screens (stage 6's screens)

**Written 2026-10-27** by the stage 6 screens session, after the backend (S1–S12, tagged `v0.8.0`), as
Loyalty's, Promotions' and Feedback's screens were (loyalty.md §4.1, promotions.md §4.1, feedback.md
§4.1). The owner answered three rounds of questions, 2026-10-26 and 2026-10-27 (§9.4), and the text below
is written to those answers. Where an answer needs the backend, §4.2 lists it. The use cases behind every
button are §3's; a screen offers only what the reader may do next — asked of an Application query
(`SalesActionsForReader`, new, as the other modules'), never of the authorizer from the screen — and every
handler asks again.

**What every screen keeps** — as the other modules': Geist's rules on shadcn's code (frontend.md §1.11),
every box checking itself as it is typed with the server's own rules (§1.6–§1.9: a reason 1–500, a carrier
1–100, a reference up to 100), every number in 0-9, every moment through `Time` in the store's zone, money
through `formatMoney` in the order's own currency and decimals, names in the page's language first, typed
text isolated inside sentences, lists paging with **Show More** (keyset: 50 for staff, 20 for a customer's
own, §3), **the store filter** (`?store=`) on the panel's lists — an off store listed while it holds an
unfinished order (§1.12) and marked **Off** — and every page at a phone's width with nothing scrolling
sideways. **A reader without the job sees no button at all; one whom the order's state stops sees it out of
reach, with the reason** (Feedback's rule). Words in `src/Modules/Sales/Presentation/lang/{ar,en}/`;
refusals are §7's, each in its own words.

**Prices are kept and shown without VAT** (handoff, 2026-10-07): the shop says so once, at checkout, where
VAT is added (§9.4 R2-1). **No order shows "paid x of y"** (§9.4 R3-1): Record Payment takes only the whole
amount still owed (§4.2 B1).

#### The shop

**S1 · Prices** — on the product page and on every card (§4.2 B8):
- **A size's price**; on a sale, **the old price struck through beside it and a percent badge** ("−15%",
  R2-2). The badge takes the system's tones for now; **a single style for every flag, tag and badge —
  sales, labels, campaigns' looks — is settled before launch** (owner, 2026-10-27; §9.2).
- **A card** shows **"From 85.00"**, the lowest price among its sizes this viewer sees, when they differ;
  the price itself when they do not (R2-4). **A guest's card** shows the retail price and, when it also
  sells wholesale, **"Wholesale from 70.00"** under it (R2-5).
- **Wholesale bands** on the product page, under the Wholesale choice (companies and guests): "From 20
  pieces: 85.00 · From 100 pieces: 78.00", the band that applies to the quantity typed highlighted (R2-3).
- **The screens session's picks, for the owner to change** (2026-10-27): the bands start with the wholesale
  minimum, at the band that starts there or below, else at the size's own wholesale price; a size sold
  wholesale only shows, under the name, what a piece costs at the wholesale minimum (not below it, where it
  cannot be bought). The page opens on its first size, so its own price shows, never "From".

**S2 · Add to Cart** — on the product page only, never on a card (R1-9):
- **Retail or Wholesale** — a choice above the quantity, each with its own minimum and maximum, for a
  viewer who may buy both (a company; a guest, who fills a cart but signs in to order — §1.1); an
  individual sees retail only (R1-7).
- **The quantity box** starts at the minimum and stays within the minimum and maximum (R1-8).
- **Add to Cart works before a size is chosen**: pressed, it points at the choices — "Choose a size" — and
  adds nothing (R2-6). A size out of stock says **Out of stock** and is not added (R1-2); a "Not available
  now" page has no Add to Cart (catalog.md §1.4).
- **Added**: a message, "Added to cart · 2 pieces", with **View Cart**, for a few seconds (R2-7), and the
  header's cart count goes up (R1-6).

**S3 · The header's cart** — a cart icon beside the account's, with **the count of pieces** in it (R1-10).

**S4 · The cart** — `/{store}/{locale}/cart` (`ViewCart`, §3.1):
- **Each line**: photo, name, size, a Retail / Wholesale badge, unit price (old struck on a sale), the
  quantity's − and + within its minimum and maximum, the line's total, **Remove** (R1-11).
- **A line with a problem** says so on the line, with its one fix (R2-10): below the minimum → **Set to
  20**; above the maximum → **Set to 100**; out of stock, not available, not for your account or not
  priced → **Remove**.
- **Lines that left at a sign-in** (wholesale for an individual, a category hidden from the account): a
  notice at the top naming each and why, with Close (R2-8).
- **The coupon box** (signed in): the code kept on the cart; checkout shows it applied, or refused with
  the reason, and lets it be changed (R1-15). A guest's box says **"Sign in to use a coupon"** (§1.1).
- **Free shipping**: "Add 120.00 more for free shipping", with a bar — nothing when the store has no
  free-above amount (R1-12). **The gift**: "Spend 80.00 more for a free gift" — the next level (R1-13).
  **Points**: "You'll earn 45 points with this order" (R1-14).
- **The summary**: subtotal, "Shipping: 25.00 (free above 300.00)", and a line that the coupon, points and
  VAT are worked out at checkout (R2-12).
- **Checkout** is out of reach while a line has a problem — "Fix the marked lines first" (R2-11) — and for
  a customer who may not order yet, with **one note saying what is missing and a link to fix it** (email
  or phone to verify, a company not yet approved, §1.2 step 1; R1-17). **A guest** pressing Checkout signs
  in or registers and comes straight back to the cart, the guest's cart joined to the account's
  (access.md amendment 75's return; R1-16).
- **Empty**: "Your cart is empty", Continue Shopping, and **the store's best-selling products** under it
  (R2-9, R3).
- **The screens session's picks, for the owner to change** (2026-10-27): eight best sellers; once the goods
  reach the free-above amount, "Your order ships free" with the bar full and "Shipping: Free" in the summary;
  the gift said as "Spend about 80.00 more" (the amount is measured after a percent coupon, B12); each
  reason an account may not order with its own link — Verify Email or Verify Phone to the account page,
  View Your Company to the company's page, none for an account closed to orders; the header's count shows
  "99+" above 99.

**S5 · Checkout** — `/{store}/{locale}/checkout`, **one page** (R1-18), its quote worked out by
`StartCheckout` (§1.2) each time something on it changes:
1. **Delivery address** — the account's default in this store chosen first; any other of its addresses
   in this store; **a new one added right there** with the account's own address form (R1-19).
2. **Coupon and points** — the coupon as the cart left it (applied, or refused with its reason); **"Use my
   points"**, as many as allowed, with an optional box to use fewer (R1-20); points refused or skipped
   say why (§1.2 steps 5–6).
3. **Way to pay** — an individual sees **"Our team will contact you to arrange payment"** as information,
   not a choice (R2-13); a company chooses **Bank transfer** or **Staff will contact you**, nothing chosen
   first (R2-14), bank transfer offered only while the store's account is filled in. **Bank transfer shows
   the store's bank details with copy buttons** (R1-24). Online payment is not shown until stage 7 (R1-23).
4. **The summary** (R2-15) — Subtotal · Sale savings · Coupon · Points · Shipping · VAT (15%) · **Total**,
   **a line with nothing in it not shown** (no "Points: 0"), **the free gift as its own line at 0.00**
   when there is one, the points this order earns, and here — once — **"Prices exclude VAT; VAT is added
   above"** (R2-1).
5. **Place order · 1,240.00** — a spinner while it goes; pressed twice, placed once (§1.3; R2-17). No
   terms tick until Content writes the terms (R1-25).
- **The 15 minutes**: no timer; an expired quote is worked out again by itself (R2-16). **Anything that
  changed** — at the new quote or at placing (`QuoteChanged`) — keeps the customer on checkout, shows what
  changed with the new totals, and asks them to press Place order again (R1-21, R1-22).

**S6 · Order placed** — `/{store}/{locale}/orders/{number}/placed` (R1-26, R2-18): the order number; "Our
team will review your order and contact you to arrange payment and delivery." For a bank transfer: the
bank details again and **an Upload Transfer Receipt box** — "Upload your transfer receipt so we can
confirm your payment" (up to 3 files, §1.9). A link to the order.

**S7 · My Orders** — an account page beside its tabs, always offered (Access's `CustomerAccountPages`):
newest first, 20 then Show More; each order's number, date, status badge, total and pieces; no filter
(R1-27).

**S8 · An order** — `/{store}/{locale}/account/orders/{number}` (`ViewOwnOrder`, §3.1):
- **The status** a customer sees (§1.4) — New blue · Processing amber · Shipped purple · Delivered green ·
  Cancelled gray · Refunded gray (R2-19) — and **a timeline**: Placed → Approved → Shipped → Delivered,
  or Cancelled / Refunded (R1-28).
- **Payment, in plain words** (R2-20, R3-1): **Awaiting payment** · **Paid** · **Balance due: 248.00**
  (after a change to the order — paid as one payment) · **Refund due: 80.00 — our team will contact you**
  · **Refunded** · **Nothing to pay**. Never "paid x of y".
- **The lines** (the gift last, at 0.00), **the totals** (no line with nothing in it), the delivery
  address, the way to pay — a bank transfer with the store's details and **the Upload Transfer Receipt
  box while something is owed** (up to 3, §1.9).
- **Shipment**: the carrier and the tracking number as text (a link comes with stage 7; R1-29).
- **Cancel…** — the reason list or "Other" with words, and "Our team will contact you about the refund"
  when paid (R1-30). Out of reach once shipped, with **"Shipped orders can't be cancelled. You can ask for a
  return after delivery."** (R2-21).
- **Returns**: **"You can ask for a return until 9 November"**, and Ask for a Return… until then (R2-22);
  the order's returns listed with their state, refund and the store's reason when refused, each opening
  its page (R1-32).
- **Buy Again** — adds every line still available to the cart, then the cart says which could not be added
  and why (§4.2 B6; R1-33, R2-24). On the order page only.
- **No print, no download** — the invoice comes from the accounting system by SMS (handoff §12.6; R1-34).

**S9 · Ask for a return** — its own page (R1-31): each line with what may still be returned (`returnable`),
how many pieces; a reason from the list or "Other" with words; **up to 3 photos, optional** (R2-23).
**S10 · A return** — its state, the pieces asked and received, the refund, the store's reason when refused,
Withdraw while it waits (`ViewOwnReturn`, §3.1).

#### The panel

**The menu** — the **Sales** group: **Orders** and **Returns** (R1-35); **no counts** in the menu (R1-48).
The three settings (§1.10) are already on Platform's settings screen.

**P1 · Orders** — `/admin/orders?store=&tab=&q=` (`ListOrders`, §3.2): the store filter; **tabs** — **Waiting
for Approval** (opened first, R2-25) · To Ship · Shipped · Unpaid · Short of Stock · Transfers to Check · All
(R1-36; §4.2 B2); **search** by order number, customer name or phone (R1-37; B3); columns — number, date,
customer, store (all stores shown), the order's, payment's and shipment's badges, total, the short-of-stock
mark (R1-38). **On a phone, each order a card** — number, total, customer, when, its badges (R2-34).

**P2 · An order** — `/admin/orders/{id}` (`ViewOrder`, §3.2), **one page** (R1-39):
- **The header**: the number, the four state badges, the **next step as the main button** — Approve while
  waiting, Ship once approved, Mark Delivered once shipped — and the rest in a ⋯ menu: Edit, Reject,
  Cancel, Allow Shipping Unpaid, Came Back, Print (R2-26). An action out of reach says why.
- **Short of stock**: a banner — "Stock was reduced on 26 Oct — check with the customer" — with **Clear
  flag** (R2-32). **An off store**: marked Off here and in the filter (R2-33).
- **The customer and contact**: the name (linked when the reader may view the customer), the phone as text
  with **Copy** and **WhatsApp** (R1-40, the owner: staff work from a computer), the email only to staff who
  may view the customer (answer 47).
- **Lines** (each part of an edit apart, the gift last), **totals**, the address, the way to pay.
- **Money** (R2-27): Due · Paid · Refunded · **Balance due** or **Refund due**, then the records, payments
  and refunds together, a voided one struck through with its reason. **Record Payment** — the amount is
  **the whole amount still owed, shown, not typed** (R3-1; B1); the way, reference, note, the day it
  arrived (today first). **Record Refund** — up to what the order holds. **Void…** with a reason (§1.9).
- **Shipment**: **Ship…** — the carrier (free text, offering the carriers used before in this store, B4)
  and an optional tracking number (R1-43); **Mark Delivered**; **Came Back…** — for each line, the pieces
  going back to stock, or **Lost for good** (R1-44). **Allow Shipping Unpaid** on an approved, unpaid order.
- **"Reduced in the provider"** — in a provider-linked store only: a tick on each counted size and the
  gift, who and when (R1-46).
- **Transfer receipts**: the files, each opening in a new tab through Platform's expiring link (R2-30).
- **Returns** of the order, each linked. **Edits**: each with its reason, who and when; opening one shows
  before → after (R2-29). **History**: plain sentences, newest first — "Approved by Ali · 2 hours ago",
  "Payment recorded: 500.00 (bank transfer) by Mona" (R2-28).
- **Approve** is one click; **Reject…** asks for the reason (R1-42); **Cancel…** too (§1.6).

**P3 · Print** — `/admin/orders/{id}/print` (`PrintOrder`): **one packing slip, with the codes**, for the
warehouse (R1-47); the browser's own print. A customer gets no copy (§S8).

**P4 · Edit an order** — `/admin/orders/{id}/edit`, **its own page** (R1-49): the lines with quantity boxes
and Remove; **Add Product** — search by name or code in the order's store, each size with its values,
today's price and the stock available (R2-35; B7); the address — another of the customer's in that store;
the gift — keep, swap to the level now reached, or remove (§1.7); **a reason** (required). **Preview**
(`PreviewOrderEdit`) shows before → after, every total, and **the money in plain words** — "The customer
will owe 248.00 more" · "A refund of 80.00 will be due" · "No change in money" (R2-37); **Confirm** stays
out of reach until the preview matches what is on screen (R2-36).

**P5 · Returns** — `/admin/returns?store=&status=` (`ListReturns`): the store filter; **tabs** —
**Requested** (opened first, R2-38) · Accepted · Received · Completed · Closed (refused, withdrawn and closed
together) (R1-50).

**P6 · A return** — `/admin/returns/{id}` (`ViewReturn`, R1-51): the order linked; the photos, opening full
size; each line asked / received / back in stock; the actions by its state — **Accept**, **Refuse…** (a
reason), **Mark Received…** (each line: how many came — what was asked first — and how many go back to
stock — what came first; R2-39), **Close**, **Complete…** — a confirm showing the refund worked out: the
pieces, their VAT, and the shipping when it is the last piece (R2-40). Then **Record Refund**, filled in
with the refund due, recording on the order (R1-52).

**P7 · A customer's orders on the staff customer page** — a section under the others (access.md amendment
72): their latest 10 orders, each linked, and "See all of this customer's orders" (R1-5; B5).

### 4.2 What the screens need from the backend (the owner's answers, 2026-10-26 and 2026-10-27)

Built by the backend session (owner, 2026-10-26: "let the backend session build them").

| # | Addition | For |
|---|---|---|
| B1 | **Record Payment takes only the whole amount still owed** — what is due less what is held; any other amount refused. An edit that raises a paid order's total leaves a balance due, paid as one new payment (R3-1) | P2 |
| B2 | **`ListOrders` filters** beyond `ALL`, `UNPAID`, `SHORT`: `WAITING_APPROVAL`, `TO_SHIP` (approved, not shipped), `SHIPPED`, `TRANSFERS_TO_CHECK` (a bank transfer with a document, still holding less than is due) | P1 |
| B3 | **`ListOrders` search** — the order number, the customer's name or phone | P1 |
| B4 | **The carriers used before in a store** — distinct, the most recent first | P2 |
| B5 | **A customer's latest orders** for staff — a `CustomerPageSection`, in the reader's stores | P7 |
| B6 | **Buy again** — one command adding an own order's lines still available to the cart, answering those it could not add, with why | S8 |
| B7 | **The edit's product search** — a store's sizes by name or code, with their values, today's price and the stock available | P4 |
| B8 | **Prices in the shop** — on the cards (now, before, for the viewer's side, and the lowest among the sizes for "From"; a guest's wholesale "from") and on the product page (each size's price and old price; wholesale bands), without VAT; Catalog keeps the listing prices Pricing pushes (catalog.md amendment 15) | S1 |
| B9 | **What may be ticked "reduced in the provider"** — the staff view says whether the order was placed wired (`orders.wired`) and, for each line, whether it counts on stock (`order_lines.counts_on_stock`, the gift always) | P2 |
| B10 | **A return's refund worked out before it is completed** — `PreviewReturnRefund`: the pieces' goods, their VAT share, and the shipping with its VAT when it is the last bought piece, without writing | P6 |
| B11 | **`ListReturns` by a group of states** — Closed: refused, withdrawn and closed together | P5 |
| B12 | **What the cart page says beside the lines** (`ViewCart`, owner's go 2026-10-27) — `mayNotOrder`, the first reason checkout would not take the account: `SIGN_IN`, `ACCOUNT_NOT_ACTIVE` (blocked, deletion asked for, emptied — before the confirmations, which would not help), `EMAIL_UNVERIFIED`, `PHONE_UNVERIFIED`, `COMPANY_NOT_APPROVED`; null exactly when `mayOrder` (an off store has no cart, so no reason for it). `shippingFee` and `freeAbove` (null when shipping is never free); `nextGift` {amount, missing}: the lowest level on now for this buyer above the goods, whose gift is in stock after the cart's own pieces (Promotions' `giftLevelsAbove`); `pointsToEarn` (Loyalty's preview; 0 for a guest). These compare with `goodsTotal`: the orderable lines' subtotal **less the coupon typed** (owner, 2026-10-27) — the points are chosen at checkout, so using them can still move it | S4 |
| B13 | **The header's piece count** — `CountCartPieces`: the pieces on every line of the shopper's cart in a store (one that cannot be ordered too), a customer's or a guest's; one query; 0 with no cart, for a browser not yet a guest, in an off store, and for staff | S3 |
| B14 | **Best sellers for an empty cart** — Catalog's `ShopBestSellers::read(store, locale, limit)` (catalog.md amendment 36(a)): the shop's own cards (`ShopCardData`, with their prices) and `cardFacts`, what this shopper can order, best-selling first | S4 |
| B15 | **Add to Cart on the product page** — section `sales.cart` (`ShopCartData{sizes: [ShopCartSizeData{variantId, retail, wholesale, outOfStock, inCart}]}`), on an available page only, for a customer or a guest (nothing for staff), for each size the page shows in its order: each mode the size is sold in now and this viewer may buy it in — an individual retail only; a company both, **an unapproved company too** (owner, 2026-10-27: the cart marks its wholesale lines); a guest both, wholesale only where companies may buy the product — as `ShopCartLimitsData{minimum, maximum, inCart, outOfStock}`: the mode's line's limits, what that line holds (an add joins it, so the limits are on the two together), and whether too few are free for the least add that leaves the line within them, the size's pieces already in the cart counted first (the exact check, owner, 2026-10-27). The size's `outOfStock`: not one more piece free; its `inCart`: its pieces over both modes | S2 |
| B16 | **The store's reason on the order's returns** — `ViewOwnOrder`'s `OwnReturnRow.staffReason`: the words staff wrote when they refused the return, as written (one text, not per language); null for any other state. The panel's `ViewOrder` carries it too. The pages' data (`OwnReturnRowData`, `OrderReturnData`) take it with the screens | S8 |
| B17 | **Close on the cart's notice** — `DismissCartNotice(storeId)`: the current buyer's cart in that store loses its notice of the lines that left at a sign-in, for good (owner, 2026-10-27); nothing when there is no cart or no notice, and no cart is made. A customer's or a guest's, as the cart's other commands | S4 |

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
| `sales.quotes` | `id`, `store_id` → `platform.stores` RESTRICT, `customer_id` → `access.customers` CASCADE, `address_id`, `coupon_code` NULL, `use_points`, `points_asked` NULL (with `use_points`: none is as many as allowed — answer 44), `way_to_pay`, `result` jsonb (the itemised §1.2), `expires_at`, `created_at` | CHECK `way_to_pay IN ('ONLINE','BANK_TRANSFER','STAFF_CONTACT')`, `points_asked IS NULL OR (use_points AND points_asked >= 1)`, `coupon_code` 1–64 characters, `expires_at > created_at`; indexes on `customer_id` and `expires_at`; deleted nightly once expired and not placed |
| `sales.orders` | `id`, `store_id`, `number`, `quote_id`, `customer_id` → `access.customers` RESTRICT, `audience`, `locale`, `currency_code` → `platform.currencies`; the snapshot: `customer_name`, `customer_email` NULL (cleared on anonymizing), `customer_phone`, `address` jsonb; `status`, `shipment_status`, `way_to_pay`, `coupon_id` NULL, `coupon_code` NULL, `coupon_discount_minor`, `points_used`, `points_discount_minor`, `gross_subtotal_minor`, `net_subtotal_minor`, `goods_total_minor`, `shipping_fee_minor`, `shipping_rule_fee_minor`, `shipping_rule_free_above_minor`, `taxable_base_minor`, `vat_minor`, `vat_rate_basis_points`, `order_total_minor`, `due_minor`, `paid_minor`, `refunded_minor`, `may_ship_unpaid`, `short_of_stock_at` NULL, `gift_level_id` NULL, `placed_at`, `decided_by` NULL → `access.staff_users`, `decided_at` NULL, `decision_reason` NULL, `cancel_reason` NULL, `cancel_other` NULL, `cancelled_by` NULL, `cancelled_at` NULL, `carrier` NULL, `tracking_number` NULL, `shipped_at` NULL, `delivered_at` NULL, `return_deadline_at` NULL (fixed at delivery — answers 34, 44), `came_back_id` NULL, `came_back_at` NULL | UNIQUE (`store_id`, `number`); UNIQUE (`quote_id`); UNIQUE (`id`, `store_id`); UNIQUE (`id`, `store_id`, `currency_code`); CHECK `audience IN ('PUBLIC','COMPANY')`, `status IN ('PENDING_APPROVAL','APPROVED','REJECTED','CANCELLED','COMPLETED','UNDELIVERED')`, `shipment_status IN ('NOT_SHIPPED','SHIPPED','DELIVERED','CAME_BACK')`, `way_to_pay IN ('ONLINE','BANK_TRANSFER','STAFF_CONTACT')`, `cancel_reason IN ('CHANGED_MIND','ORDERED_BY_MISTAKE','FOUND_CHEAPER','TOO_SLOW','OTHER','BY_STAFF')`; **the decision**: CHECK `(status = 'PENDING_APPROVAL' AND decided_at IS NULL) OR (status IN ('APPROVED','REJECTED','COMPLETED','UNDELIVERED') AND decided_at IS NOT NULL) OR status = 'CANCELLED'` — a cancelled order was cancelled waiting or after approval; CHECK `(decided_by IS NULL) = (decided_at IS NULL)`, `status <> 'REJECTED' OR decision_reason IS NOT NULL`; **the cancellation**: CHECK `(status = 'CANCELLED') = (cancelled_at IS NOT NULL)`, `(status = 'CANCELLED') = (cancel_reason IS NOT NULL)`, `status <> 'CANCELLED' OR shipment_status = 'NOT_SHIPPED'`, `COALESCE(cancel_reason IN ('OTHER','BY_STAFF'), false) = (cancel_other IS NOT NULL)`, `(cancel_reason IS NOT DISTINCT FROM 'BY_STAFF') = (cancelled_by IS NOT NULL)` — a customer's cancellation names a reason from the list, a staff member's is `BY_STAFF` with their words in `cancel_other`; **the shipment**: CHECK `(shipment_status = 'NOT_SHIPPED') = (shipped_at IS NULL)`, `(shipment_status = 'DELIVERED') = (delivered_at IS NOT NULL)`, `num_nonnulls(came_back_at, came_back_id) IN (0, 2)`, `(shipment_status = 'CAME_BACK') = (came_back_at IS NOT NULL)`, `(shipment_status = 'NOT_SHIPPED') = (carrier IS NULL)`, `carrier IS NOT NULL OR tracking_number IS NULL`, `(status = 'COMPLETED') = (shipment_status = 'DELIVERED')`, `(status = 'UNDELIVERED') = (shipment_status = 'CAME_BACK')`, `shipment_status = 'NOT_SHIPPED' OR status IN ('APPROVED','COMPLETED','UNDELIVERED')`; **the coupon**: CHECK `(coupon_id IS NULL) = (coupon_code IS NULL)`, `coupon_id IS NOT NULL OR coupon_discount_minor = 0` (an edit may leave a coupon at 0); **the points**: CHECK `(points_used = 0) = (points_discount_minor = 0)`; CHECK every amount `>= 0`, `due_minor <= order_total_minor`, `refunded_minor <= paid_minor` |
| `sales.order_lines` | `id`, `order_id`, `store_id`, `product_id` → `catalog.products` RESTRICT, `variant_id` → `catalog.variants` RESTRICT, `edit_id` NULL (the part an edit added), `is_gift`, the snapshot (`name_ar`, `name_en` — the Arabic name where the product has no English one, answer 44 —, `variant_values` jsonb, `code`), `sale_mode`, `price_kind` NULL (none for the gift), `quantity`, `unit_minor`, `list_unit_minor`, `total_minor`, `coupon_share_minor`, `points_share_minor`, `vat_share_minor` | (`order_id`, `store_id`) → orders (`id`, `store_id`) RESTRICT; (`edit_id`, `order_id`) → order_edits (`id`, `order_id`) RESTRICT; UNIQUE (`id`, `order_id`); CHECK `sale_mode IN ('RETAIL','WHOLESALE')`, `(price_kind IS NULL) = is_gift`, `price_kind IN ('BASE','SALE','CAMPAIGN','CATEGORY','QUANTITY')`, `quantity >= 1`; CHECK every share `>= 0` and `coupon_share_minor + points_share_minor <= total_minor`; CHECK a gift line has `sale_mode = 'RETAIL'`, `unit_minor = 0`, `list_unit_minor = 0`, `total_minor = 0` and every share 0; one gift line per order (UNIQUE (`order_id`) WHERE `is_gift`). Rows change only before shipping (edits) |
| `sales.order_ticks` | `order_id`, `store_id`, `variant_id`, `ticked_by` → `access.staff_users`, `ticked_at` | PK (`order_id`, `variant_id`); (`order_id`, `store_id`) → orders RESTRICT — "reduced in the provider" |
| `sales.order_edits` | `id`, `order_id`, `store_id`, `reason`, `by` → `access.staff_users`, `at`, `before` jsonb, `after` jsonb | (`order_id`, `store_id`) → orders RESTRICT; UNIQUE (`id`, `order_id`); CHECK `char_length(reason) BETWEEN 1 AND 500`; append-only (trigger); the edit's id is Loyalty's and Promotions' idempotency key |
| `sales.payments`, `sales.refunds` | `id`, `order_id`, `store_id`, `currency_code`, `amount_minor`, `way`, `reference` NULL, `note` NULL, `happened_on` date (the day the money moved — S7b), `request_id` uuid, `recorded_by` NULL → `access.staff_users`, `recorded_at`, `voided_by` NULL, `voided_at` NULL, `void_reason` NULL | (`order_id`, `store_id`, `currency_code`) → orders (`id`, `store_id`, `currency_code`) RESTRICT — the order's currency only; UNIQUE (`request_id`); UNIQUE (`way`, `reference`) WHERE `way = 'ONLINE'` (stage 7's gateway references); CHECK `amount_minor > 0`, `way IN ('BANK_TRANSFER','ONLINE','OTHER')`, `way <> 'OTHER' OR note IS NOT NULL`; CHECK `num_nonnulls(voided_by, voided_at, void_reason) IN (0, 3)`; a trigger refuses DELETE, TRUNCATE and any UPDATE but setting the three void columns once |
| `sales.transfer_documents` | `order_id`, `store_id`, `media_id` → `platform.media` RESTRICT, `position`, `uploaded_at` | PK (`order_id`, `position`); (`order_id`, `store_id`) → orders RESTRICT; CHECK `position BETWEEN 1 AND 3` |
| `sales.returns` | `id`, `order_id`, `store_id`, `status`, `reason`, `reason_other` NULL, `staff_reason` NULL, `requested_at`, `decided_by` NULL → `access.staff_users`, `decided_at` NULL, `withdrawn_at` NULL, `received_at` NULL, `closed_at` NULL, `completed_at` NULL, `refund_due_minor` NULL, `shipping_refunded` | (`order_id`, `store_id`) → orders RESTRICT; UNIQUE (`id`, `order_id`); CHECK `status IN ('REQUESTED','ACCEPTED','REFUSED','WITHDRAWN','RECEIVED','COMPLETED','CLOSED')`, `reason IN ('DAMAGED','WRONG_ITEM','NOT_AS_DESCRIBED','CHANGED_MIND','OTHER')`, `(reason = 'OTHER') = (reason_other IS NOT NULL)`; CHECK `(decided_at IS NOT NULL) = (status IN ('ACCEPTED','REFUSED','RECEIVED','COMPLETED','CLOSED'))`, `(withdrawn_at IS NOT NULL) = (status = 'WITHDRAWN')`, `(received_at IS NOT NULL) = (status IN ('RECEIVED','COMPLETED'))`, `(closed_at IS NOT NULL) = (status = 'CLOSED')`, `(completed_at IS NOT NULL) = (status = 'COMPLETED')`, `(refund_due_minor IS NOT NULL) = (status = 'COMPLETED')`; CHECK `(decided_by IS NULL) = (decided_at IS NULL)`; CHECK `status <> 'REFUSED' OR staff_reason IS NOT NULL`; CHECK `refund_due_minor >= 0`; CHECK `NOT shipping_refunded OR status = 'COMPLETED'`, and one per order (UNIQUE (`order_id`) WHERE `shipping_refunded`) |
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

**As built (step S6a)** — my assumptions, stated for the owner to reject: `order_history.kind` is one
of `PLACED`, `APPROVED`, `REJECTED`, `CANCELLED`, `EDITED`, `SHORT_FLAGGED`, `SHORT_CLEARED`,
`TICKED_REDUCED`, `SHIP_UNPAID_ALLOWED`, `SHIPPED`, `DELIVERED`, `CAME_BACK`, `PAYMENT_RECORDED`,
`REFUND_RECORDED`, `MONEY_VOIDED`, `TRANSFER_DOCUMENT_UPLOADED`, `RETURN_REQUESTED`, `RETURN_WITHDRAWN`,
`RETURN_ACCEPTED`, `RETURN_REFUSED`, `RETURN_RECEIVED`, `RETURN_CLOSED`, `RETURN_COMPLETED`. Beyond the
rows above: CHECK `orders.locale IN ('ar','en')`; CHECK a return deadline exactly when delivered, never
before the delivery; CHECK a line's unit, list and total prices `>= 0`; FKs `orders.cancelled_by` →
`access.staff_users` and (`order_history.edit_id`, `order_id`) → `order_edits`; indexes on order lines by
variant, edits by order and history by (order, id); `order_history.id` an identity column. A store's
number sequence missing (a listener before Sales's failed) is made at its next number. The quotes' nightly
deletion leaves a placed quote with its order (placing again answers that order, §1.3), and skips one
being placed at that moment. **The history and the edits never hold the customer's email** — they cannot
be changed, and anonymizing clears the order's copy (§1.12).
The order number's format is the owner's (2026-10-26): the store's code and its own number from 1, four
digits at least (`SA-0001`).

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
| `HoldsShort` | Inventory | Flags the named orders "stock was reduced" (§1.11; answer 36) |
| `StoreCreated` | Platform | Creates the store's order-number sequence (§5) |
| `PaymentSucceeded`, `PaymentFailed` | Payments (stage 7) | Records the payment, or leaves the order unpaid |

Each consumer records `processed_events`, so a message delivered twice is acted on once — save the
cart's two, which are done once however often told by what they do (a guest's carts are gone after the
first move; an account with no carts has none to delete), as Feedback's and Catalog's listeners are
(step S4).

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
| `TooManyCouponTries` | FORBIDDEN | Five codes that name no coupon in ten minutes; carries the minutes left (answer 50) |
| `TooManyWaitingOrders` | CONFLICT | As many orders waiting for approval in the store as allowed, at the quote or at placing; carries the number (answer 51) |
| `QuoteNotFound` | NOT_FOUND | A quote that is not this buyer's — the same answer as an unknown one |
| `QuoteExpired` | CONFLICT | Placing after 15 minutes or past the prices' end — checkout quotes again |
| `QuoteChanged` | CONFLICT | Anything of §1.3 steps 3–5 no longer as quoted |
| `NotCancellable` | CONFLICT | Shipped, or already cancelled, rejected or finished |
| `NotEditable` | CONFLICT | Shipped; cancelled, rejected or finished; a line ticked "reduced in the provider" |
| `NotShippable` | CONFLICT | Not approved; holding less than is due without "may ship unpaid" |
| `ReturnNotAllowed` | CONFLICT | Outside the window; more pieces than delivered and not already in a return; more than 3 photos |
| `TooManyFiles` | CONFLICT | A fourth transfer document; a document while nothing is owed |
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
elsewhere, stock gone — each refuses and nothing is held, used or recorded (the number taken is
skipped); a retry after 15 minutes answering its order; a retail and a
wholesale line of one variant held as one line, the gift as its own, with no expiry; **two `PlaceOrder`
at once on one quote answer one order**; numbers per store, `SA-…`, a store opened later getting its
sequence; the snapshot unchanged when the catalog changes.

**Orders and shipping** — approval; a rejection never recorded as a cancellation; an approved order
cancelled keeps its decision; unpaid orders wait and do not ship without "may ship unpaid"; `ship`
called on shipping; "reduced in the provider" per variant, the gift included; delivered by staff earns
points, starts the window and makes the products reviewable; a parcel that came back: a wired store's
unticked holds freed, only taken stock put back as staff choose, a lost one with nothing put back,
nothing due, points back, the coupon still counted, Cancelled then Refunded; a `REFUND_DUE` or
`NOTHING_DUE` order shipping without "may ship unpaid"; a staff cancellation and a rejection needing
their reason;
`HoldsShort` flags, a flagged order still ships, only staff clear the flag; an off store's unfinished
orders listed to its staff, and no longer once finished.

**Cancelling** — until shipped; the reason list and "Other"; the paid note; stock freed, points back,
the coupon use kept, nothing due and a refund due shown.

**Edits** — each kind, waiting for approval or approved; refused once shipped, cancelled, finished or
a line ticked; an added wholesale line for an individual, an added hidden category, an added unpriced
line refused; kept parts at their price, added pieces at today's with the band for the new total; a
lowered quantity taking the newest part first; Pricing's totals at the order's VAT rate; the order's own ceiling,
points cap and shipping rule though the store's changed, an address-only edit trimming nothing; points giving
way before the coupon; the coupon never growing — a second edit capped by the first's trim — removed under its minimum, trimmed only when alone it
passes the room; stock adjusted all or nothing; the gift kept, swapped or removed by staff; shares
allocated again; refund due and balance due recorded; the flag left for staff.

**Returns and money** — within 14 days of delivery; pieces, a reason, up to 3 private photos; the gift
returned for nothing; two requests never claiming one piece, and a piece that never came asked again; withdraw; accept, refuse with a reason,
close; received with fewer pieces than asked and damaged pieces left out of stock; **a full return,
in one or several parts, refunding exactly `order_total`** — with a coupon taking a line to 0, points
on the rest, a VAT of 0, shipping free and not; shipping refunded with the last bought piece; points
settled by running totals; `ReturnCompleted` flips Feedback's reviews; transfer documents up to 3, while
something is owed — a balance after an edit included; payments and refunds recorded once per request, voided not
changed, a payment's void refused once the rest no longer covers the refunds (answer 42), a refund above the held amount refused, another currency refused; the payment state through
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
30. **An edit keeps the rules the order was placed under** — the discount ceiling, the points cap, the
    shipping rule (2026-10-10).

### 9.1b The owner's answers, 2026-10-19 (before the build)

31. **A coupon or points refused at the quote**: the quote is built without them, with the reason
    (§1.2 step 5).
32. **A signed-in individual never sees wholesale**; a guest's wholesale lines leave the cart at
    sign-in, each with why (§1.1).
33. **Edits**: every changed line within its minimum and maximum; staff may add a gift a level now
    reached (§1.7).
34. **The return window is fixed at delivery** — delivery plus the days, to the minute (§1.8).
35. **Order numbers start at 1, four digits at least**: `SA-0001` (§1.3; changed by the owner 2026-10-26 from
    1001).
36. **Stock reduced under placed orders only marks them** — following inventory.md §1.5 (2026-10-11),
    newer than this spec (§1.11).
37. **Placement asks the gift and the discount room again** — a change is `QuoteChanged` (§1.3).
38. **Payments: every gateway an adapter** — MyFatoorah now; Tamara, Tabby and an Egypt gateway later,
    not cancelled (§1.9, §2.4).
39. **An off store's placed orders**: their customers may still cancel, ask a return and upload a
    transfer document; only new orders stop (§1.12).
40. With the spec's other picks: ~~a payment record is voided only once no refund rests on it (the
    refund voided first)~~ — replaced by answer 42; the outbox relay runs every minute, backing off to at most an hour, never
    dropping a message, logged after 10 tries; the stage 7 interfaces declared as §2.4 states, their
    unstated types marked provisional for stage 7.

**2026-10-19, after the build plan** (the owner, before step S1):

41. **Shipping after stock was reduced**, in a store with no provider: the shipment goes through; the
    stock stops at 0, never below, and the shortfall is recorded for a recount (answer 36: reduced
    stock only marks, never blocks). Inventory's `ship` changes accordingly — built by stage 5.
42. **Voiding a payment record** (answer 40, refunds being recorded per order, not per payment):
    allowed while what was paid without it still covers what was refunded; refused otherwise
    (`InvalidOrderChange`).
43. **The outbox lives in `Shared/Infrastructure`** as platform.md §5.6 decided; the ~20-class ceiling
    of Shared gets a recorded exception for it, as for the versioned cache.
44. **Two columns added and one rule** (§5): `quotes.use_points` (whether points were asked for — Loyalty reads
    none as "as many as allowed"), `orders.return_deadline_at` (fixed at delivery, answer 34), and an
    order line's English name falls back to the Arabic one. **A customer without a phone never
    orders** — `customerMayOrder` asks for a verified phone (handoff §7.4) — so `orders.customer_phone`
    stays required.

### 9.1c The owner's answers, 2026-10-26 (after the build)

45. **Order numbers** start at 1, four digits at least: `SA-0001` (answer 35 changed; §1.3).
46. **Every "as built" assumption** in §1–§3 and §5 is accepted as written (the owner read them all).
47. **The contact email** on staff's order view only for staff who may view the customer; the phone for
    every reader (§3.2).
48. **No data from the previous shop** — none of its orders appears here; a customer starts with no
    order history (whether their accounts and sign-ins move over is the migration stage's to decide).
49. **No partial payments** (2026-10-27): a payment is the whole balance owed, filled in, not typed (§1.9).
    With the Sales screens' answers relayed by their session (their spec PR lists their own picks): the
    order lists' four filters, one search box, one customer's orders, the carriers used before, the
    customer page's orders, a wired order's tickable sizes (§3.2).
50. **Coupon codes may not be guessed** (2026-10-28, security audit run-2): five codes that name no
    coupon in ten minutes per customer, then none until the ten minutes pass (§1.1); a coupon given to
    others answers as unknown (promotions.md answer 29). **Both numbers are settings, one pair for every
    store**, changed only by an admin of all stores or a Super Admin: codes 1–20, minutes 1–60 (§1.10).
51. **Waiting orders capped, and not yet sold** (2026-10-28, security audit run-2): a customer may have
    at most **3 orders waiting for approval in one store** — a fourth is refused at the quote and at
    placing (`TooManyWaitingOrders`), counted under a lock per customer and store; one more is allowed
    once staff approve or reject one, or the customer cancels one. **The number is a setting, one for
    every store**, 1–20, changed only by an admin of all stores or a Super Admin (§1.10). **Best-Selling
    counts orders approved or later** — a waiting order is not yet sold; cancelled, rejected and
    undelivered (came back) ones stay out, returned pieces still count (§1.13).

### 9.2 Still open

1. **Shipping and Payments** — stage 7, waiting on the owner's vendor data (handoff §15.1); their
   interfaces are written here (§2.4).
2. **Pricing's `pricesForEdit`** and **Inventory's `adjustHold` / `returned`** — on `main` since #107
   and #106 (2026-10-10), the stage 5 session's.
3. ~~**Catalog's additions**~~ — **settled**: built with step S3 as catalog.md amendment 29 —
   `storeVariants` and `productsOpenTo` (by Platform's `ShopperKind`, as deptrac allows Catalog's public
   contract) — and the guest's id as access.md amendment 74 (`GuestIdentity`).
4. **Promotions' and Loyalty's corrections** that this spec needs — the edited coupon's whole room, its
   cap at the discount now, `orderDiscountRoom` with the order's recorded ceiling and the shares by
   price line (promotions.md); `pointsAfterEdit` by the recorded cap, `orderEdited`'s answer and an edit
   before delivery in Loyalty's table rules (loyalty.md) — made on those specs' branches with this one.
   **Inventory's** `release` then `returned` for a parcel that came back — PR #109, the stage 5
   session's.
5. ~~**Sales's screens**~~ — **written** (§4.1, the owner's answers §9.4), built by the stage 6 screens
   session with the backend's additions (§4.2).
6. **Every assumption marked above** — merging carts by adding quantities; 90-day carts; order numbers
   from a sequence that may leave gaps; a tracking number optional; shipping refunded with the last
   bought piece; a refusal's reason required; up to 3 transfer documents; the
   ways recorded (`BANK_TRANSFER`, `ONLINE`, `OTHER`); what counts as an unfinished order; the
   settings' defaults and admin-only permission; best-selling as pieces sold in 90 days.
7. **Handoff §12.4's "hold stock while verifying" setting** (bank transfers, stage 7): stock is now
   held for every order from placement until it ships or is cancelled (handoff §12.1, owner
   2026-10-07), so the setting has nothing left to switch — for the owner to confirm it goes, with
   Payments.
8. **An off store's unfinished orders in the other modules** — whether Inventory's, Promotions' and
   Loyalty's calls accept an order of a store that is off (Sales's edits, returns, cancellations):
   checked at build and tested (`OrderDecisionsTest`, `ReturnRequestsTest`: an off store's staff decide,
   its customers cancel and ask returns).
9. **Answer 41's change to Inventory** — `ship` taking a store with no provider to 0 and recording the
   shortfall instead of refusing — the stage 5 session's to build (their answer, 2026-10-19), before
   Sales's shipping step (S8). Both built (S8; `InventoryApi::ship`).
10. **One style for every flag, tag and badge** — sales, labels, campaigns' looks — the owner's, to be
    settled before launch (§9.4, R2-2); the screens use the system's tones until then.

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

**The final check** (of `5203d65`): no blocker; most of the second review confirmed fixed, the money
re-added (every piece's shares plus shipping and its VAT = `order_total`), every citation real. Fixed
from it: the coupon after a second edit capped by its discount now, not at placement (Promotions only
lowers a use); a parcel that came back freeing what is still held before restocking only taken stock
(Inventory names both callers, PR #109); shipping allowed whenever what is held covers what is due;
the cancellation and rejection CHECKs needing their reasons, and a staff cancellation's reason; transfer
documents while something is owed; **an edit keeping the order's own ceiling, points cap and shipping
rule** (the owner's answer 30 — Promotions' `orderDiscountRoom` and Loyalty's recorded cap); and the
minor points — the coupon's shares' wording, best-selling without rejected or undelivered orders,
completed returns counting the pieces received, the paired and shipping CHECKs, a retry after 15
minutes, the off store's filter, the handoff row's order, a lost parcel, the merged PRs cited.

### 9.4 The screens' questions (2026-10-26 and 2026-10-27)

Asked in three rounds by the stage 6 screens session; each answer is in §4.1 where it is used (R1-n is
round 1's question n, R2-n round 2's, R3 round 3's). **Everything not named below took the recommended
option (a).**

- **Round 1** (52 questions): checkout built now, online payment and shipping rates in stage 7 (R1-3, the
  owner correcting (b) to (a)); shop prices drawn now from what Pricing already pushes (R1-1); a card per
  colour discussed and left as it is (R1-9); the cart count in pieces (R1-10); checkout the common one
  page (R1-18); the transfer receipt only with bank transfer, companies only (R1-24); Buy Again built now
  (R1-33); one Orders page with tabs, and Returns (R1-35); the backend's additions built by the backend
  session (R1-36); the phone as text with WhatsApp — staff work from a computer (R1-40); one staff packing
  slip with codes, nothing for the customer (R1-47); no counts in the menu (R1-48); the edit on its own
  page (R1-49); tabs for status lists, a filter for the store (R1-50). The Home dashboard follows the Sales
  screens; downloadable reports stay with Ops, stage 8.
- **Round 2** (40 questions): "Excl. VAT" said only at checkout (R2-1); a sale's percent badge, and
  **one style for every flag, tag and badge — campaigns' looks included — settled before launch**
  (R2-2); a guest's card retail with "Wholesale from" (R2-5); Add to Cart pressed before a size asks for it
  (R2-6); best sellers under an empty cart (R2-9, R3); no summary line with nothing in it (R2-15); the
  short-of-stock banner (R2-32); orders as cards on a phone (R2-34); the money after an edit in plain
  words (R2-37).
- **Round 3**: **no order is ever paid in parts** — Record Payment takes only the whole amount still
  owed; an order that grows after it was paid shows "Balance due" and is paid once more, whole (R3-1);
  best sellers under an empty cart.
- **The screens session's picks, for the owner to change** (answering the backend session's detail
  questions, 2026-10-27): a company account's card shows the retail price with "Wholesale from", as a
  guest's; Buy Again from an order in any state, a line added at its old quantity and marked in the cart
  when outside its limits now, one no longer available left out with why; the edit's search leaving out
  sizes already on the order; search from 2 characters; no counts on the tabs.

### 9.5 Stage 7's changes — **[ACCEPTED by the owner, 2026-10-28]** (proposed 2026-10-27, with shipping.md and payments.md)

Each becomes an amendment applied in place once the owner accepts the two specs. The Stage 6 session
agreed (2026-10-27) that stage 7 makes them, so the two sessions never edit the same code.

1. **No VAT on shipping** (owner, 2026-10-27: "1000 riyals, the vat will be 15% so 150 riyals, and
   shipping 30 riyals, so total 1180 not 1184.5"). §1.2 step 10: `taxable_base` is the lines only. The
   returns' refunds (§1.8: the shipping and its VAT share go with the last piece) lose the VAT share, so
   only the shipping itself is refunded. Pricing's `totals()` changes with it (pricing.md §9.5). As built
   today, the Stage 6 session names: Pricing's `totals()`, QuoteBuilder step 10, OrderEditing,
   ReturnRefunds and PreviewReturnRefund, and their tests (QuoteTest, ReturnCompletionTest, the edit
   tests); the independent review (2026-10-27) adds `Domain/Service/OrderShares` (it takes a VAT share
   for the shipping), the `shippingVat` field of `ReturnRefundPreview` and of §5's tables, §1.8's shares
   (item 3) and the sum under it, and handoff §10.2.
2. **Shipping from Shipping** (shipping.md §2.1): step 9 calls `ShippingApi::quote`.
   - `SERVED`: the carriers; the customer picks one when two or more serve the place (the cheapest
     picked first — shipping.md §9.3 #2); the carrier and its fee are snapshotted.
   - `NOT_COVERED` / `OUTSIDE`: placed with **no carrier and the fee "to be set"**; the way to pay is
     forced to "staff will contact you"; the order is marked **"Shipping fee to be set"**; staff set the
     fee later as an edit (§1.7).
   - `OTHER_STORE`: refused, naming the store.

   §1.10's `sales.shipping.flat_fee` and `sales.shipping.free_above` are removed.
3. **Marking shipped** (§1.5): the carrier is picked from `ShippingApi::carriers()` (staff may change
   the one ordered — owner, 2026-10-27 S17), with the tracking number and/or a full tracking link typed
   by staff (S7). The customer sees **Track** only when there is a link (the carrier's pattern with the
   number, or the typed link).
4. **Online payment — no order until it is paid** (owner, 2026-10-28; payments.md §1.4). `PlaceOrder`
   stays as built for "staff will contact you" and bank transfer. For an individual in a store whose
   gateway is on (`PaymentsApi::availability`), the checkout shows **MyFatoorah's form** in its payment
   block (`openSession` for the quote — it lives as long as the quote's 15 minutes plus the window and the wait, payments.md §1.4 step 0), and **Pay** is a new
   use case, **`PayOnline(quoteId, sessionId)`**:
   - **One transaction**: the quote and the cart locked; **a live try on this cart** →
     `PaymentInProgress` (the same quote and session again — a double click — answers the same try);
     **a quote with a live or paid try, or made before the cart last changed** (the cart's version is
     stamped on the quote) → refused (payments.md §1.1 rule 3); **the quote built again from the cart**
     (§1.2 — prices, stock, coupon, points, gift, shipping) **must equal it**, else `QuoteChanged` naming
     what moved — **nothing reserved**, the customer stays on checkout; then **`PaymentsApi::prepare`**
     (no call to the gateway: the session checked, the attempt written with its key and checks); **the
     try** written — `sales.checkout_tries` (#11) — `LIVE`, with its window's end and **a copy of the
     delivery address and the contact details**; then **the reservations** (#5) under **the order's
     future id** (a ULID made now), Inventory last. Committed. (`PlaceOrder` on a cart with a live try →
     `PaymentInProgress` too — the second review, 2026-10-28.)
   - Then `PaymentsApi::charge` (the prepared attempt). The page follows payments.md §1.4 steps 5–9.
   - **`PaymentSucceeded` for a try → the order is created** (`CompleteOnlineOrder`), once, under the
     try's lock: §1.3 steps 2, 6, 7 and 8 from the try's quote **and the try's copy of the address and
     contact details** — **the order number is taken now**, the fact segments recorded, everything
     snapshotted, `OrderPlaced` — any "stock was reduced" flag the try received carried to the order
     (§1.11), the reservations **confirmed** to the
     order (#5), the cart emptied of what was bought (as built), the try `PAID`, and the `ONLINE` payment
     recorded (#7). **The quote's own 15 minutes do not matter here** (the money was taken on it; §1.3
     step 1 already answers a quote after its expiry), and §1.3 step 3 is **not** run again — it ran at
     Pay and the reservations hold. **A store switched off meanwhile still gets the order**
     (payments.md §1.10). The page's own answer completes it at once (`CompleteOnlineOrder` asks
     `PaymentsApi::refresh` first); the event completes it otherwise — once either way.
   - **Sales's hourly look at its tries** (the second review, 2026-10-28): a `LIVE` try past its window
     and wait is asked about (`refresh`) — paid → completed; nothing taken → released; whatever cannot
     be settled is marked needing attention, shown in the Payments list's needing-attention block.
   - **`PaymentFailed` or `PaymentNotTaken` for a try → the try is released** (`ReleaseTry`), once and
     **only while it is `LIVE`** — a release arriving after the order was created changes nothing (the
     order shares the try's id for its stock): every reservation given back with **nothing counted** (#5),
     the try `RELEASED`, the cart untouched. **`PaymentNotAccepted`** (a currency refused) → released the
     same way, with a refund due recorded on the try.
   - **Checkout or the cart opened again while a try is live** (payments.md §1.4 step 8): nothing sent to
     the gateway yet (`PaymentsApi::abandon` answers true) → the try released there and then — as if Pay
     had never been pressed; the charge
     made → "Your last payment can still go through until …", the cart may be changed, Pay opens again
     when the try's time is over; paid → the new order. A second window while a try is live shows no
     form (step 9).
   - **A success for a try already released** (payments.md §1.7, rule 15): **no order is made by
     itself** — the try keeps the payment as **a refund due** (`refund_due_minor`), and Payments lists the
     attempt as needing attention.
   - **Staff never see a try as an order** (the owner, 2026-10-28: "his order won't be created and shown
     to staff until it's paid"); tries are seen only in Payments' list.
5. **Reservations for a try** (payments.md §9.2 #2–#4):
   - **Stock** — `InventoryApi::hold` under the order's future id, **with no expiry**, as at placing; the
     order keeps the same hold when it is created (same id), and `release` gives it back. Nothing new in
     Inventory: an id is never held twice (a success after release makes no order — #4).
   - **The coupon** — Promotions' **reserve / confirm / release** (promotions.md §9.5): reserved at Pay
     (every check of `useCoupon`, counted toward its limits while reserved), **confirmed with the order's
     number** when the order is created, **released with nothing counted** otherwise. The built
     `useCoupon` cannot do it: it needs the order's number and "a use never comes back".
   - **The points** — Loyalty's **reserve / confirm / release** (loyalty.md §9.6), the same way; the
     built `redeem` needs the order's number.
   - **The order of the locks** stays §1.3's (Sales's own first, then Promotions, Loyalty, Inventory last).
6. **The timers are Payments'** (payments.md §1.7): the 5-minute window from Pay and the wait for an
   answer in flight, as Payments' settings. **No timer cancels an order** — none exists until it is paid —
   so the planned `sales.payment.minutes_to_pay` (60) and `sales.payment.grace_minutes`, the cancel
   reason `PAYMENT_NOT_COMPLETED` and **the revive are dropped** (owner, 2026-10-28, replacing 2026-10-19
   P2 and P5).
7. **Recording**: `PaymentSucceeded` → an `ONLINE` payment (`reference` = **Payments' own transaction
   id** — the gateway's ids are unique only per gateway and mode — unique, so twice is once; the
   gateway's id shown through Payments; the second review, 2026-10-28). **An `ONLINE` record is never
   refused**: it is recorded at what the gateway took, whatever the order owes — a cancelled or already
   paid order shows the excess as a refund due (§1.4) — unlike staff's records, which are always the
   balance owed (§1.9 as built). carrying the TEST mark and the mismatch flag (payments.md §1.1 rules 6, 8,
   §1.6) — on the new order (#4) or, for a payment request, on its order. A second success on one attempt
   is a second payment — a refund due (payments.md §1.1 rule 3).
8. **Payment requests** (payments.md §1.5; owner, 2026-10-19 P6, 2026-10-28 Q5): when an edit leaves the
   order holding less than is due, staff choose **"Request payment"** (open 60 minutes) or **"Handle by
   hand"**. The customer pays on **the request's own page** — `/{store}/{locale}/orders/{number}/pay` —
   with the same form and timers; paid → #7. **Staff edits — and recording a payment by hand — wait while
   a payment is in progress on the order** (`PaymentsApi::refresh` says so), with the reason shown;
   **recording a payment by hand withdraws the order's open request** (payments.md §1.5). **A customer's cancellation waits
   too** while a request is being paid (owner, 2026-10-28, payments.md §9.6 #5).
9. **Bank transfers**: `PaymentsApi::bankAccount` replaces `B2BApi::bankAccount`. An order whose receipt
   was uploaded more than `payments.transfer.check_hours` (**72**) ago and is not yet paid is marked
   **overdue** in the staff list (owner, 2026-10-27 P8, P28).
10. **Contract changes**: `ShippingApi` and `PaymentsApi` change as in shipping.md §2.1 and payments.md
    §2.1: `options` becomes `quote`; `methods` and `start` go — `openSession`, `prepare` and `charge` replace
    them; and `PaymentNotTaken` and `PaymentNotAccepted` are consumed.
11. **The orders table** (the review found the built schema cannot hold a stage 7 order):
    - a **`carrier_id`** (Shipping's, no key) and the carrier's names, **snapshotted at placement**; the
      check `orders_carrier` (carrier set exactly when shipped) becomes: the **shipping carrier** is set
      when shipped, the **ordered carrier** may be set from placement; staff may change it (S17);
    - **"fee to be set"**: a `shipping_fee_pending` flag, with `shipping_fee_minor` 0 while it is set,
      cleared when staff set the fee (never inferred from a 0 fee — 0 is a real fee);
    - the free-above amount snapshotted is **the one that applied** (the carrier's or the store-wide
      one), with which it was (`CARRIER`, `STORE`);
    - the tracking link typed by staff (S7), beside the number; **the full link is stored on the order
      when it is marked shipped** (made from the carrier's pattern then, or typed), and the carrier's
      names at placing — so a carrier deleted later (shipping.md §1.3, owner 2026-10-28) breaks no order;
    - **`sales.checkout_tries`** (#4): `id` ULID PK · `store_id` · `buyer` (customer or guest, as the cart)
      · `cart_id` · `cart_version` · `quote_id` · `order_id` (the order's future id) UNIQUE · `state`
      CHECK (`LIVE`, `PAID`, `RELEASED`) · `window_ends_at` · `refund_due_minor` NULL (#4: a success after
      release, a currency refused) · `created_at`, `ended_at` NULL · **one live try per cart** (a unique
      partial index on `cart_id` WHERE `state = 'LIVE'`) · **one try per quote that is not released** (a
      unique partial index on `quote_id` WHERE `state <> 'RELEASED'`);
    - **the cart's version** (`sales.carts.version`, raised by every change of its lines, coupon or
      points) and **the quote's copy of it** (`sales.quotes.cart_version`) — payments.md §1.1 rule 3;
    - **the nightly clean-up of quotes** (§1.2: expired quotes deleted at 03:30) **keeps a quote that has
      a try not released** — the order may still be made from it (the second review, 2026-10-28);
    - the try's copy of **the delivery address and the contact details**, and its **"stock was reduced"
      flag** (§1.11: `FlagShortOrders` flags a try by the order's future id as it flags an order).
12. **Edits after placing** (§1.7 step 5 works shipping out again from the order's snapshot): **staff
    set the fee** of a "fee to be set" order as an edit of its own ("Set the shipping fee"); a changed
    **address** re-quotes at today's prices (shipping.md §9.3 #12); the carrier's own free-above amount
    snapshotted is the one used again.
13. **Voiding an `ONLINE` record** (owner, 2026-10-27, payments.md §9.3 #12): **a real one is never
    voided** — a mistake is fixed by a refund; a TEST one may be.
14. **Test orders numbered apart** (owner, 2026-10-27; payments.md §1.6, §9.4 #3): an order placed while
    its store's gateway is in TEST — **an online one by the mode its payment was made in**, not the
    store's mode when it is paid (#4; the review, 2026-10-28: a store switched to LIVE mid-payment must
    not turn a test payment into a real order, nor the reverse) — takes the number `TEST-0001`,
    `TEST-0002`… from a counter of its own
    per store, so the real `SA-0001` counter is untouched; the order carries `is_test`, is left out of
    every figure, and is never marked shipped.
15. **Order numbers** (owner, 2026-10-28: "ok for the no gaps"): **an online order takes its number only
    when it is paid** (#4), so a try that never pays leaves no gap. The rare gap of §1.3 step 2 (a
    placement that fails after taking its number) stays, as the owner accepted on 2026-10-26.
