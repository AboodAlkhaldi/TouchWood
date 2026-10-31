# Ops — Module Specification

**Status:** **DRAFT, 2026-10-28** — written from the owner's answers of 2026-10-28 (rounds 1–4, §9.1)
and the answers of the sessions that built the modules Ops listens to (§9.2). Not accepted. The picks
marked "my assumption" are for the owner to reject.
**Stage:** 8 (handoff §17), the first of the stage's two modules (owner, 2026-10-28: Ops
before Content — Content waits for the owner's real content and the old site's SEO).
**Depends on:** every module's `Public` surface; **nothing depends on Ops** (handoff §4.4; `deptrac.yaml`
already says so).
**Source:** `docs/HANDOFF.md` §4.4, §5.2, §7.6, §13.3, §15.1; access.md §1.4, §2.1, §2.3; the specs of
Sales, Loyalty, Promotions, Feedback, Inventory, Sync, Catalog; Payments' and Shipping's specs in PR #240
(`27959b3a`); the merged code as of `029d41a1`.

Ops **tells people things and counts things**. It sends every message the system sends — to customers
and to staff, in the person's language — keeps the inbox behind the shop's bell and the panel's bell,
lets staff write to customers and send offer notices, keeps the newsletter subscribers and hands them
to Brevo, and produces the reports, the daily report among them.

Four rules of the owner's (2026-10-28) shape everything below:

- **SMS is for codes, and the gateway key's critical alerts.** Sign-in and phone-verification codes, and
  the gateway key expiring in 1 day or refused, to super admins (§1.6) — nothing else goes by SMS. Every other message is the bell and email.
- **Marketing reaches only those who agreed.** Service messages (orders, codes, points) always go;
  offers and newsletters go by email only to a customer who ticked the consent box (§1.3).
- **Providers are adapters.** Email is **Brevo**; the SMS provider is not chosen (the owner is checking
  Mobily). Either is replaced by changing one binding.
- **Revenue is the total without VAT, and nothing else.** There is no cost, no profit, no margin
  (handoff §13.3). A cancelled, rejected or undelivered (came back) order never counts (sales.md:211).

## What this module does not own

| Concern | Owner |
|---|---|
| The moments themselves — an order placed, points earned, a question answered | Each module publishes its event (§2.3); Ops only listens. **No module calls Ops** |
| The staff's notification switches per topic | Access stores them (access.md §1.4); Ops reads them |
| Whether a customer agreed to marketing | Access stores it on the customer (§1.3, an Access amendment); Ops reads it and asks Access to switch it off on an unsubscribe |
| Segments — who is in one | Promotions (`PromotionsApi::segmentMembers`); Ops reads members |
| Campaigns, banners, the store home, the smart bar | Content (stage 8, after Ops). A campaign's "send a notice when it goes live" is Content's event, Ops' notice (§1.7) |
| Designing and sending a newsletter | **Brevo's own editor** (owner, 2026-10-28, round 3 #12 (a)); Ops keeps the list and copies it to Brevo |
| What every screen looks like | The screens agent (owner, 2026-10-28); Ops gives it the data shapes of §4 |
| Visitor counts | **Not built** (owner, 2026-10-28, R4-3) — Google Analytics, outside the system |
| Payment receipts sent by MyFatoorah | MyFatoorah, if it sends any; Ops sends its own "order taken" email regardless (owner, 2026-10-28) |

## 1 · Rules

### 1.1 A message, and how it reaches someone

A **message** is one thing told to one person: its kind (§1.5, §1.6), the person, the store, the
language (the person's `locale`, access.md §1.2 and §1.4), and the **channels** it went by:

| Channel | Who | What |
|---|---|---|
| **Bell** | Customers (the shop) and staff (the panel) | An entry in the person's inbox (§1.2) |
| **Email** | Customers and staff | Through the email adapter — Brevo |
| **SMS** | Codes to anyone; the gateway key's critical alerts to super admins (§1.6) | Through the SMS adapter — `log` until the provider is chosen |

The channels of each kind are **fixed in this spec** (§1.5, §1.6) — not switchable per store (owner,
round 1 #3). A staff member's own switches (Access) still choose bell and email per topic.

The **wording** of every kind is fixed in the language files, in Arabic and English, approved with this
spec (owner, round 1 #22) — no template editor. Emails carry the store's logo and name and are written in
the recipient's language (round 1 #23). Every number in Latin digits (owner, 2026-10-06).

### 1.2 The inbox — the bell

- **Customers** get a bell in the shop and **staff** a bell in the panel (frontend.md: "the bell with
  Ops"). Each entry: the kind, the title and a line in the person's language, an optional link (the
  order, the question, the report), the time, **read or unread**.
- Opening an entry marks it read; "Mark all as read" marks every entry. The bell shows the unread count.
- A customer's inbox is **per store** — the shop shows the store's own entries. A staff member's inbox
  shows the stores they may view (access.md amendment 64), read through `AccessApi::staffStores` (access.md
  amendment 88, built in Ops step 4): every store they are assigned to, on or off (owner, 2026-10-30); a Super
  Admin every store.
- Entries older than **12 months** are deleted (owner, round 3 #14).

### 1.3 Marketing consent (an Access amendment)

- The customer gets one switch, **"Send me offers and news"**: **off by default**, a box at
  registration left **unticked**, changeable in the account (owner, round 2 #6 — agreed).
- It is stored with **when it changed and how** (registration, the account, an unsubscribe link, Brevo).
  Staff never set it (owner, 2026-10-28). It is personal data: anonymizing an account clears it (Stage 5's
  rule; `AnonymizeDueAccounts`). Every existing customer starts **off**.
- **Service messages ignore it** — orders, codes, points, coupons assigned to them, answers. **Marketing
  email obeys it** — notices (§1.7) and newsletters (§1.9). A marketing **bell** entry goes to every
  customer (owner, R4-6): the bell is on our own site.
- Marketing email goes only to a customer whose **email is verified**, who is **not blocked** and has **no
  deletion pending** (owner, 2026-10-28, §9.3 #14).
- Consent is **one switch for the person** (identity is global, access.md §1.1); the customer is on their
  **home store's** list only, and unsubscribing anywhere switches it off everywhere (owner, 2026-10-28, §9.3 #13).
- Every marketing email carries an **unsubscribe link**; following it switches the consent off at once,
  with no sign-in. The link opens **a page with one "Unsubscribe" button** — opening it changes nothing;
  the button (a POST to the same signed address) does it (owner, 2026-10-30). The same address is the
  email's RFC 8058 one-click unsubscribe (`List-Unsubscribe`, `List-Unsubscribe-Post`) — written on
  every marketing email; **not passed to Brevo**, whose send reference says "standard email headers
  are not supported" (checked 2026-10-30; Brevo's help centre says it adds its own).
- **Company accounts:** the same switch, on the customer account (owner, 2026-10-28, §9.3 #1).

### 1.4 Who receives a staff alert

A staff alert of a topic, in store S, goes to every staff member who:

1. **may act on it in S** — holds the topic's permission (§1.6) in S; roles nest, so admins and super
   admins hold every staff permission (owner's rule); a super admin is seen only by super admins;
2. **is active**; and
3. **switched the topic on** — the bell by the in-panel switch, email by the email switch (access.md
   §1.4: new staff start with every in-panel switch on and every email switch off).

Access gives this as **one bulk read** (§2.3, Access).

### 1.5 Messages to customers

Channels: **bell and email** for every row except C18 (SMS never, owner round 2 #4). "New event" means the module
must publish it (§2.3).

| # | Kind | When | Event | Notes |
|---|---|---|---|---|
| C1 | Order taken | An order is placed | `OrderPlaced` (exists) | The shop's own email: number, lines, amounts, address, how to pay (owner, 2026-10-28; the address confirmed 2026-10-31) |
| C2 | Order approved | Staff approve it | **new** `OrderApproved` | |
| C3 | Order rejected | Staff reject it | **new** `OrderRejected` | With staff's reason when given |
| C4 | Order shipped | Marked shipped | **new** `OrderShipped` (Sales — Shipping publishes nothing) | The carrier, the number; **"Track" only when a link is stored on the order** (sales.md §9.5 #11) |
| C5 | Order delivered | Marked delivered | `OrderDelivered` (exists) | Says the store's return window started (a store setting, 14 days by default; `SalesSettings.php:13`) |
| C6 | Order cancelled | By the customer or staff | `OrderCancelled` (exists) | A customer cancelling their own order is told too (owner, 2026-10-31; §9.3 #9) |
| C6b | Order changed | Staff edit a placed order | **new** `OrderEdited` (`EditOrder`) | The new lines and total (owner, 2026-10-28, §9.3 #9) |
| C7 | Order came back | The parcel came back | **new** `OrderCameBack` | |
| C8 | Return accepted / refused | Staff decide | **new** `ReturnAccepted`, `ReturnRefused` | Refused: staff's words (sales.md, #243) |
| C8b | Return received | Staff receive the pieces | **new** `ReturnReceived` | (owner, 2026-10-28, §9.3 #9) |
| C9 | Return completed | Staff complete the return | `ReturnCompleted` (exists) | "A refund of X is owed" — completing moves no money (`CompleteReturnHandler.php:30-33`) |
| C9b | Refund sent | Staff record money sent back | **new** `RefundRecorded` (`RecordRefund`) | The amount and the way |
| C10 | Payment received | Staff record a payment, or Sales records an online payment on an order that already exists (a payment request) | **new** `PaymentRecorded` — Sales records both (payments.md:390-391), so one event | An online **checkout** creates its order on `PaymentSucceeded` (payments.md:643): it gets **one** email, C1, saying it is paid |
| C11 | Pay the remaining amount | Staff create a payment request | **new** `PaymentRequested(eventId, requestId, orderId, storeId, customerId, expiresAt, occurredAt)` (Payments, *Stage 7's proposal*) | **Sent at once** — the request lives 60 minutes (payments.md:386); the link `/{store}/{locale}/orders/{number}/pay` (payments.md:389) |
| C12 | Points earned | Points credited on delivery | **new** `PointsEarned` | |
| C13 | Points changed by hand | An admin adds or removes | **new** `PointsChangedByHand` | The admin's reason when given |
| C14 | Points expiring | **7 days** before a lot expires | A nightly Ops job over a **new** Loyalty read (§2.3) | One message per customer per day, summing every lot that expires that day |
| C15 | Coupon assigned | Staff assign one | **new** `CouponAssigned` | |
| C16 | Question answered | Staff answer a waiting question (a corrected answer sends nothing) | `QuestionAnswered` (feedback.md §2.5, #252) | Read through `FeedbackApi::questionForMessage`; **sent only if the question is still answered** — events are kept in order per order only |
| C17 | Review not published | Staff reject a review, or take an approved one down, **with a reason** | `ReviewNotPublished` (#252) | **No reason, no message** (feedback.md:75); read through `reviewForMessage`, sent only if it is still not published |
| C17b | Question not published | Staff reject a question, or take an answered one down, **with a reason** | `QuestionNotPublished` (#252) | Added by the owner, 2026-10-28 (feedback.md §1.3). **No reason, no message**; sent only if it is still not published |
| C18 | Security messages | Codes, links, B2B decisions | Access's `SecurityMessages` (access.md §2.3) | **Ops binds its own implementation** and the temporary one is deleted (round 1 #26). Codes by SMS; links by email. **No bell entry.** **Sent at once, never queued** — a code or link never reaches a job payload, an event or a record (access.md §2.3); a provider failure is reported to the caller, who already offers "send again". Branded with the store when the request has one, otherwise TouchWood's own (owner, 2026-10-28) |

The B2B decisions (approved, rejected, suspended) stay in `SecurityMessages`; Ops does **not** also
listen to `CompanyStatusChanged` for them, so nothing is sent twice.

**Built (steps 5 and 6, 2026-10-31):** C1–C8b, C9, C12–C14, C16–C17b. Each listener is queued and runs once
per `eventId` (`ops.handled_events`); it makes the bell entry in the event's store (both languages, the link
to the customer's page in their own) and queues one email in their language; the email job writes the words
again from the module's read when it leaves. Each email (and SMS) has its `ops.sent_messages` row written
QUEUED with the listener's work; the job calls the provider outside any transaction, then marks the row SENT
(FAILED after the queue's last attempt); a retry finds a SENT row and sends nothing - **at least once**: a
provider that took a message just before its row could be marked may get it again (review, 2026-10-31). A
read that names another store or customer than its event sends nothing and is logged by ids only. C2 is
skipped once the order is no longer approved, C8 accepted and S9 once the return was withdrawn; C4 names the
carrier only when the order has one. Service messages: consent is not asked; an anonymized account
gets nothing; a switched-off store still sends. Amounts carry the currency's letters (ر.س, SAR), as the daily
report does. C5's window is read through `PlatformApi::setting('sales.returns.window_days', store)`. C14 runs
every morning at 10:00 Riyadh time for every store's own day a week ahead, once per store, customer and day;
the job carries the moment it was queued, so a late worker reminds of the same day. **Not built,
waiting on another module:** C9b and C10 (Sales has no read of a money record's amount and way by
`moneyRecordId`); C15 (Promotions has no read of the coupon's code by `couponId`); C11 (`PaymentRequested`,
stage 7); C1's delivery address - shown once Sales adds it to `OrderMessageDto` (owner, 2026-10-31: asked of
Sales); until then the email has no address line, never a stand-in - and its "paid" line (online checkout is
stage 7's).

### 1.6 Alerts to staff

| # | Topic (Access) | When | Event | Permission that receives it | SMS |
|---|---|---|---|---|---|
| S1 | New orders | An order is placed | `OrderPlaced` | `sales.order.view` | — |
| S2 | Company applications | An application is submitted | `CompanyApplicationSubmitted` (exists) | `b2b.company.review` | — |
| S3 | Low stock | Sizes **became** low or ran out at a recount | **new** `LowStockChanged`, one per store per recount (§2.3) | `inventory.stock.manage` (Inventory has no view-only key) | — |
| S4 | Sync problems | A read left problems | `SyncNeedsAttention` (exists) | `sync.report.view` | — (owner, 2026-10-28: an offer stopped by Odoo's price is not SMS-worthy) |
| S5 | Payment problems (access.md amendment 78) | The gateway key expires in 30, 7, 1 days | **new** `GatewayKeyExpiring(eventId, storeId, mode, daysLeft, expiresOn, occurredAt)`, once per threshold, from Payments' daily job (*Stage 7's proposal*) | `payments.gateway.manage` — super admins only | **Yes, at 1 day** |
| S5b | Payment problems | MyFatoorah refused the key | **new** `GatewayKeyRejected(eventId, storeId, mode, occurredAt)` (*Stage 7's proposal*; payments.md:187-192) | `payments.gateway.manage` — super admins only | **Yes** (owner, 2026-10-28, §9.3 #12) |
| S6 | **Payments to check** (a **new** Access topic, amendment 84) | A bank transfer unchecked 72 h after the upload | **new** `TransferOverdue(eventId, orderId, storeId, occurredAt)`, once per order, from **Sales'** scheduled job (*Stage 7's proposal*; sales.md:1583-1585) | `sales.payment.record` | — |
| S7 | **Payments to check** | A payment not accepted — a refund is owed | `PaymentNotAccepted` + **`storeId`** (stage 7); links to the attempt in Payments' staff lists — for a try the order never existed | `sales.payment.record` | — (owner, round 3 #13: withdrawn) |
| S9 | **Returns** (a **new** Access topic) | A customer asks for a return | **new** `ReturnRequested(eventId, returnId, orderId, storeId, customerId, occurredAt)` | `sales.return.manage` | — (bell opens the return; it stays under Waiting in the panel's Returns) |
| S8 | Campaign expiry | With Content | Content's event | Content's | — |

- Every permission key above exists in code today (the reviewer checked the `*Permissions.php` files).
- **Critical SMS** (owner, round 3 #13; §9.3 #12): **only S5 at 1 day and S5b (the key refused)**, to **super admins only** (owner,
  2026-10-28: §9.3 #10 — S4, an offer stopped by Odoo's price, is bell and email only; §9.3 #11 — only
  super admins can renew the key). Each super admin may switch it off (access.md amendment 81: a switch
  on the staff account, **on by default**, shown to super admins only).
- `HoldsShort` sends nothing — the orders are flagged already (Stage 5's recommendation, round 1 #14).
- **A switched-off store still sends** (owner, 2026-10-29): a customer whose question a Super Admin answers there is told, as anywhere.
- An alert **links to its page** (the order, the report) and never copies details that may be stale
  (Stage 5's rule for Sync).
- **Built (steps 5 and 6, 2026-10-31):** S1, S2, S3, S4, S9 - one queued listener, once per event, recipients
  from `AccessApi::staffToAlert` with each one's bell and email switches; S3 alerts only when a size became low
  or ran out (a recount where sizes only came back alerts no one). The critical SMS path (S5 at 1 day, S5b; super
  admins with `critical_sms` on and a phone, both checked again when the SMS leaves; the provider of the phone's country) is built and tested, but **no
  listener calls it yet**: `GatewayKeyExpiring`, `GatewayKeyRejected`, `TransferOverdue` (S6) and
  `PaymentNotAccepted` (S7) are not in main, and Payments declares no `payments.gateway.manage` yet (so the line
  above, "every permission key above exists in code today", no longer holds for it). S8 waits for Content.

### 1.7 Offer notices

A **notice** is staff telling customers about an offer: "Ramadan offers have started".

- Written in **both languages**, with an optional link (a category, a product, a page).
- **Audience:** every customer of the store, or the members of one Promotions segment. "Every customer
  of the store" is **every customer registered in that store** — their home store (owner, 2026-10-30,
  as built in step 7). **The segment audience lands after Stage 6 makes `PromotionsApi::segmentMembers`
  store-scoped** (`segmentMembers(StoreId $store, string $segmentId, ?string $after, int $limit)`, another
  store's segment refused as `SegmentNotFound`): without the store, a notice of one store could reach
  another store's segment. Built in step 7: the store audience only. **The segment audience is built (2026-10-31,
  with steps 5 and 6)**: a notice may name one of its store's segments (`segment_id`); another store's segment is
  refused as Promotions' `SegmentNotFound`, an empty one as an empty audience (§7); the batches page
  `segmentMembers` (`cursor_type` SEGMENT) under the same rules - the bell to every member, the email only to
  those who may receive offers (§1.3). A segment deleted while its notice sends fails the batch: the notice
  shows FAILED (as any batch that fails its last attempt). Changing a waiting notice (review, 2026-10-31): no
  `segment` (or `segment_id`) keeps its audience; an empty one makes it every customer of the store; an id makes
  it that segment.
- **Channels:** bell and email, **both ticked by default**, each untickable (owner, R4-6). The email
  goes only to customers who agreed (§1.3); the bell to all.
- **Send now or schedule** a date and time in the store's time zone. A scheduled notice can be edited or
  cancelled until it starts sending; once sending starts it cannot be recalled.
- **With Content:** a campaign may carry "send this notice when the campaign goes live"; Content
  publishes the campaign's going live and Ops sends the notice (owner, round 3 #11). Content never calls
  Ops.
- Sending to thousands goes in batches on the queue; the notice shows how many were sent. A batch that
  fails its last attempt shows the notice **FAILED**; the job waits on the failed jobs screen, and
  retrying it there carries on from where it stopped (step 7: a batch is safe to run again).

### 1.8 Staff messages to a customer (handoff §13.3)

- **To one customer**, found by name, email or phone: staff with `ops.message.send_one` — only a customer
  registered in a store where the staff member holds `ops.message.send_one` **and** may view customers;
  the search offers only those, and any other is refused as unknown (owner, 2026-10-30).
- **To every customer of a store, or a segment:** `ops.message.send_many`, **admins only** (round 1
  #18). Every customer of a store is every customer registered in it (owner, 2026-10-30); the segment
  audience of a staff message is **not built yet** (only the notice's was, 2026-10-31, §1.7).
- **Bell and email only** (round 3 #10). Free text in both languages; the customer gets their own
  language.
- A one-customer message is a **service** message (it ignores consent); a message to many is
  **marketing** for email (§1.3) (owner, 2026-10-28, §9.3 #2).
- Every message is recorded: who sent it, to whom, the channels, when. Nothing can be recalled.
- **Audited** (owner, 2026-10-30): writing, changing and cancelling an offer notice, and sending a staff
  message — to one or to many — each write a Platform audit entry in the same transaction
  (`ops.notice.created|changed|cancelled`, `ops.staff_message.sent`).

### 1.9 Newsletter subscribers and Brevo

- **Who is on the list, per store:** customers who agreed (§1.3), and **guests** who gave an email in the
  shop's newsletter box. A guest **confirms by email first** (double opt-in, round 1 #8); unconfirmed
  sign-ups are deleted after 7 days (owner, 2026-10-28).
- Ops **copies the list to Brevo** — one Brevo list per store and language — and keeps it current:
  joining, leaving, changing language. **Newsletters are designed and sent in Brevo** (round 3 #12 (a)).
- **Unsubscribing** in a Brevo email reaches Ops through Brevo's webhook: a guest is removed, a customer's
  consent switches off (Access).
- **While a customer may not receive offers, every guest row of their email, in every store, goes too**
  — including at registration (`CustomerRegistered`, `GuestBecameCustomer`); with a **deletion pending**
  the provider's contact is deleted at once, so anonymizing finds nothing left (step 7, review fix).
  Staff see a customer's email in the subscribers list only if they may view that customer.
- **A customer is on the list only while they may receive offers by email** (§1.3: agreed, email
  verified, not blocked, no deletion pending): Ops also listens to `CustomerEmailVerified`,
  `CustomerBlocked`, `CustomerUnblocked`, `CustomerDeletionScheduled` and `CustomerDeletionCancelled`
  (owner, 2026-10-30, kept as built in step 7). A contact newly on a list — a customer who agreed again,
  a guest's new confirmed sign-up — is allowed email again at Brevo (`emailBlacklisted: false`), since an
  earlier unsubscribe there left Brevo refusing it.
- **A guest's own unsubscribe link** (§3) is signed and carried by the confirmation email; it opens the
  same one-button page, and the button takes the guest off at once. A guest giving an email that belongs to a customer account is told to tick
  the box in the account instead (`AccessApi::isCustomerEmail`, access.md amendment 79 as built).
- *Checked against Brevo's documentation, 2026-10-29 (step 7):* contacts and lists — create a list
  (`POST /v3/contacts/lists`, `name` + `folderId`), create or update a contact (`POST /v3/contacts`,
  `listIds`, `updateEnabled`), remove from a list (`POST /v3/contacts/lists/{id}/contacts/remove`),
  delete a contact (`DELETE /v3/contacts/{email}`), allow a contact email again (`PUT /v3/contacts/{email}`,
  `emailBlacklisted: false`; checked 2026-10-30); the marketing webhook's `unsubscribed` event, whose body
  carries `"event": "unsubscribe"` and the `email`. **Brevo signs nothing**: the webhook is authenticated by
  a custom header Brevo sends with each call, carrying our secret (`X-TouchWood-Secret` =
  `BREVO_WEBHOOK_SECRET`). The URLs are cited in `BrevoNewsletterList` and `BrevoWebhookController`.

### 1.10 Reports

All store-scoped (handoff §13.3). The person picks a store they may view (a super admin: one or all) and
a date range. Downloaded as **Excel** (R4-5).

| Report | What | From |
|---|---|---|
| Revenue | Per day (or month) and store: orders, revenue without VAT, VAT, shipping, refunds; by category | Sales (new reads), Catalog for categories |
| Product sales | Per size: units and revenue without VAT | Sales (new read), Catalog for names |
| Stock | Per size: on hand, held, low, out | Inventory (`inventory.stock.manage` reaches it there) |
| Searches | Per phrase: searches, searches with no results | Catalog (new read). **The log keeps 12 months** — no report reaches further |
| Favourites | Per product: customers who saved it | Feedback (new read) |

**What counts** (owner, round 3 #9 and #15; §9.3 #6–#8 answered 2026-10-28 in the Stage 6 session):

- Orders are **counted**. A **cancelled, rejected or undelivered (came back) order never counts**, as in
  Sales (sales.md:211).
- **Revenue is what the customer pays for the goods after the coupon and the points, without VAT and
  without shipping** — "we record only what users pay as the final order price".
- **A refund comes off on the day the customer asked for the return.** Until the return is finished
  its exact refund is not known (staff may receive fewer pieces), so a day with returns waiting shows
  **two figures**: revenue, and "revenue if the waiting refunds go through", estimated with Sales'
  refund arithmetic (`ReturnRefunds`) over the pieces asked; a **Waiting refunds** list names them by return
  and order number only (no customer), and
  the report says its total is before them. With none waiting, one figure.
- **A past day's figures may change** when staff later edit an order of that day ("must be, yes"). A
  daily report's PDF keeps what it showed when it was made.
A sale counts on the day it was **placed**; "money received" is shown beside it, by the day the payment
arrived (§1.11, round 3 #9).

**All stores together:** each store, then **one total per currency** — never converted (round 3 #4).

**Built in step 8** (the owner's answers of 2026-10-30): revenue **by category** must be net of refunds, so the
categories add up to the net total — until Sales gives refunds by product, that sheet says it waits for Sales,
with no figures; Favourites keep archived products, marked; Searches cover at most 12 months, every other report
at most 5 years, from 2020 to next year. A range that includes today is read until the moment it was asked,
the same for every page, and says so; an all-stores report names that moment in the base store's time zone.
**VAT is net of refunds** (owner, 2026-10-30; Sales #293): each day's VAT less the goods' VAT of the refunds asked
that day (`refundsVat`), with "VAT if the waiting refunds go through" beside revenue's (`waitingRefundsVat`) and
each waiting refund's VAT on its sheet (`estimateVat`). VAT is the goods' alone (owner: no VAT on shipping) - Ops
takes Sales' `vat` as it is (Stage 7 makes it leave shipping out). **Money received is read day by day** in one
read per store however long the range (`moneyByDay`, #295); by month, each month's days are summed.

### 1.11 The daily report

The owner's report "of everything that happened today, to help business decisions" (2026-10-28).

- **The day:** each store's section uses **its own** day, from **midnight in the store's time zone** (`timezone`, platform.md) **until the moment
  the button is pressed** (R4-1). A **past day** can be made too — midnight to midnight.
- **Who:** an admin for their stores; a super admin for one store or all; other staff with
  `ops.report.daily` (round 3 #7).
- **A PDF** (R4-5). **Every file made is kept** (12 months; owner, 2026-10-28) and listed, to open again
  exactly as it was.
- **Sent by email automatically** if the person switches it on, **at the time they choose** (owner,
  2026-10-28; round 3 #8). Off by default. The email is **exactly the report the button would give at
  that time** — midnight until then. The time is read in the store's time zone; a super admin's
  all-stores report uses the base store's time zone (Riyadh), each store's section still its own day. One time per person per
  store, or one for all stores (owner, 2026-10-28, §9.3 #15).

**Contents**, in this order:

1. **Today against yesterday, same hours** — one small table: orders, revenue without VAT, new customers
   (R4-2; visitors dropped with R4-3).
2. **Sales:** orders placed, cancelled, rejected, came back; revenue without VAT, VAT, revenue with VAT;
   shipping charged; refunds; average order (without VAT); individuals against companies.
3. **Money received** (Sales' figures — its payment records; test-mode payments never count, payments.md:443): payments in (online, transfers, other); transfers waiting and overdue.
4. **Products:** the 10 sizes sold most (units, revenue); revenue per category.
5. **Customers:** new individuals, new companies; company applications submitted, approved.
6. **Stock:** sizes that became low, ran out, came back; stock reads from Sync and their problems.
7. **Promotions and points:** coupons used and their discount; points given and spent.
8. **Feedback:** new reviews; questions waiting for an answer.
9. **Searches:** the top searches; searches with no results.
10. **Month to date** (R4-1), the last section: orders, revenue without VAT, new customers, from the 1st.

All stores: each store's report, then the totals per currency.

**Built (step 9, 2026-10-30).** The PDF in IBM Plex Sans Arabic (owner, 2026-10-30; the font's TTF files and OFL
licence in `resources/fonts/ibm-plex-sans-arabic`, from IBM's release `@ibm/plex-sans-arabic@1.1.0`); amounts with
the currency's letters (the new riyal and dirham signs are in no font mPDF reads); points given = earned + added,
spent = redeemed, the other ledger kinds as rows only when they happened (owner, 2026-10-30). Kept in
`ops.daily_reports` with the file in the app's private files; the email in `ops.daily_report_settings`. **Each
store's section uses its own day and time zone**; the base store's zone (Riyadh) is used only for the all-stores
report's header date, its past-day check (a past day must also be before today in every store it covers) and the
time of an all-stores email. **Revenue is net of refunds** (§1.10) everywhere it is shown - cards, today,
yesterday, month to date, change, average order (net revenue ÷ orders that count) and the totals per currency;
refunds stay as their own row, and "revenue if the waiting refunds go through" is net less the waiting ones.
**VAT and revenue with VAT are net of refunds too** (owner, 2026-10-30; Sales #293): VAT is the day's less the
goods' VAT of the refunds asked that day (`refundsVat`), revenue with VAT is net revenue plus net VAT - in the
store's Sales table and the totals per currency. Where refunds wait, "revenue with VAT if the waiting refunds go
through" takes off their estimate and its VAT (`waitingRefundsVat`), and each waiting refund shows its VAT
(`estimateVat`). VAT is the goods' alone (owner: no VAT on shipping); Ops takes Sales' `vat` as it is.
The email runs as a delayed job queued by the person who switched it on and then by itself a day at a time, so
it reads only what that person may see (no Access read answers that for another person; the owner accepted this
design, 2026-10-30). The setting holds the one moment it may go next, moved on by compare-and-set with the next
job queued in the same transaction - a double submit, a race or a retried job never starts a second email; a late
worker sends the missed report as at its moment, once. A cancelled or missing staff account switches it off; a
disabled one is sent nothing while disabled. Its delivery is a job of its own, tried again on a provider failure.
Its send is recorded on the report (`emailed_at`), not as a message kind.

**Every store's report includes the switched-off stores** (owner, 2026-10-30): each marked "switched off", its
figures in the totals per currency (`PlatformApi::allStores()`, which returns the stores on and off).

## 2 · Public contract

### 2.1 What Ops offers others

**Nothing callable** — nothing may depend on Ops (handoff §4.4). Ops **implements** Access's
`SecurityMessages` (§1.5 C18) — Ops depends on Access's interface, not the reverse.

### 2.2 Adapters

- `EmailSender` — **Brevo** (transactional email); `log` in development and tests.
- `SmsSender` — `log` until the owner names the provider (Mobily is being checked); then its adapter. **Which
  provider sends an SMS is the phone's country's**, on the SMS countries list (access.md amendment 90).
- `NewsletterList` — **Brevo** contacts and lists; `log` in development and tests.

Every adapter is chosen by configuration; a new provider is a new class and a binding.

### 2.3 What Ops needs from each module

Each row is an amendment to that module's spec, with the next free amendment number at its merge.
**The module's builder is asked first** (owner, 2026-10-28); each said it can build its rows on the owner's
go (§9.2).

| Module (builder) | Events | Reads |
|---|---|---|
| **Sales** (Stage 6 session) | **Built (#259):** `OrderApproved`, `OrderRejected`, `OrderShipped`, `OrderCameBack` `{eventId, orderId, storeId, customerId, occurredAt}`; `OrderEdited` adds `editId`; `ReturnRequested`, `ReturnAccepted`, `ReturnRefused`, `ReturnReceived` add `returnId`; `PaymentRecorded`, `RefundRecorded` add `moneyRecordId` (by hand; Stage 7 fires `PaymentRecorded` for online payments) — ids only, through the outbox. `TransferOverdue` is Stage 7's mark | **Built (#260)** on a new contract `Sales\Public\Contracts\SalesReports` (not `SalesApi`): `orderForMessage(orderId, locale)` (the tracking link is Stage 7's); `returnForMessage(returnId)`; `counts(store, from, to)` {placed, counted, individuals, companies, cancelled, rejected, cameBack} — `counted` leaves out cancelled, rejected and came back; `moneyOn(store, fromDay, toDay)` — payments and refunds by way, on the store's own dates, voided left out; `transfersWaiting(store)` {orders, owed} (overdue is Stage 7's); `revenueByDay(store, from, to)` — pass the store's midnights, refunds and waiting refunds per day; `productSales(store, from, to, after, limit)` — most sold first, returns not taken off. An unknown store is refused |
| **Loyalty** (Stage 6) — **built (#256)** | `PointsEarned`, `PointsChangedByHand` | Lots expiring on a day; points per day per store **per ledger kind** (`EARNED`, `REDEEMED`, `REDEMPTION_RETURNED`, `EARNING_REVERSED`, `ADDED`, `DEDUCTED`, `EXPIRED`; loyalty.md:62-70) |
| **Promotions** (Stage 6) — **built (#255)** | `CouponAssigned` | `segmentMembers` — store-scoped `segmentMembers(StoreId $store, string $segmentId, ?string $after, int $limit)` (PR #280; another store's segment → `SegmentNotFound`); coupons used per day and their discount |
| **Feedback** (Stage 6) | **Built (#252):** `QuestionAnswered`, `QuestionNotPublished`, `ReviewNotPublished` `{eventId, <itemId>, productId, storeId, customerId, occurredAt}` — one per item, only with a reason | **Built:** `favouriteCounts(store, after, limit)` (archived products included — Ops filters through Catalog); `reviewForMessage(id)`, `questionForMessage(id)`; `counts(store, from, to)` {newReviews, newQuestions, waitingQuestions (now)} |
| **Catalog** (Stage 6) | — | **Built (#251, catalog.md amendment 37):** `searchSummary(?store, from, to, after, limit)` — {query, storeId, locale, searches, noResults}, most searched first; no person (catalog.md §1.11) |
| **Inventory** (Stage 5 session) | **Built (#267, inventory.md amendment 1):** `LowStockChanged(eventId, storeId, becameLow, ranOut, cameBack, occurredAt)` — after commit, once per store, only when a list is non-empty; a store's first recount sends nothing; a size going from fine straight to out is in `ranOut` only (owner, 2026-10-29) | **Built:** `lowStockChanges(StoreId, from, to)` [from, to), an unknown store refused; `stockLevels(StoreId, after, limit 1–1000)`, paged by size id; the change log kept 12 months |
| **Sync** (Stage 5) | `SyncNeedsAttention` (exists; ids only — the alert links to the Sync Report) | **Built (#262, sync.md amendment 1):** `readsAndProblemsPerDay(stores, fromDay, toDay, until)` → `list<SyncDayDto>` |
| **B2B** (Stage 5) | **Built (#261, b2b.md amendment 34):** `CompanyApplicationSubmitted` carries `storeId` (owner, option (a)) | **Built:** `applicationCounts(storeId, from, to)` → {submitted, approved} over [from, to); Ops never counts from events |
| **Payments** (Stage 7 session) | **`storeId`** on `PaymentSucceeded` and `PaymentNotAccepted`; **new** `GatewayKeyExpiring`, `GatewayKeyRejected`, `PaymentRequested` — Stage 7's proposals, put to the owner as payments.md amendments. **payments.md:387 to read "by bell and email"** (Stage 7 agreed) | — (the money figures are Sales') |
| **Access** (builder session gone; **this session writes it, with an independent review** — owner, 2026-10-28) | — | access.md amendments **79–84** (numbers taken at merge): marketing consent and `withdrawMarketingConsent` (79); `staffToAlert(store, topic, permission)` (80); the critical-SMS switch (81); the **Returns** topic (82); `customersRegistered(store, from, to)` (83); the **Payments to check** topic (84). **Built in Ops step 4 (2026-10-29):** 80, 81, 82, 84, and 88 — `staffStores(staffId)`, the stores a staff member's bell shows |
| **Content** (stage 8, later) | A campaign going live; a campaign about to expire | — |

## 3 · Use cases and permissions

New permission group **Messages and Reports** (owner, 2026-10-28); every permission given to admins by
default (roles nest).

| Use case | Permission | Scope |
|---|---|---|
| Read and mark my inbox | none (own) | Own |
| Send a message to one customer | `ops.message.send_one` | That store |
| Send a message to many / a segment | `ops.message.send_many` (admins) | That store |
| Write, schedule, cancel an offer notice | `ops.notice.manage` | That store |
| Manage newsletter subscribers (view, remove a guest) | `ops.newsletter.manage` | That store |
| View and download reports | `ops.report.view`; the Stock report also needs `inventory.stock.manage` there | Stores they may view |
| Make, list and download daily reports; switch on the daily email | `ops.report.daily` | Stores they may view; all stores: super admin |
| Subscribe to the newsletter; confirm; unsubscribe | guest / signed link | — |
| Manage the SMS countries — list, add, change provider or calling code, remove **[ACCEPTED — stage 8, Ops step 3; owner 2026-10-29]** | `ops.sms_countries.manage` (reserved: Super Admins) | Global |

## 4 · What the screens agent gets

Data shapes only; the screens are the screens agent's (owner, 2026-10-28). Listed in full in the build
plan: the two bells (unread count, entries, mark read), the message composer, the notices list and form,
the subscribers list, the reports page (filters, preview rows, Excel download), the daily reports page
(make, list, download PDF, the email switch), the customer's consent switch, the shop's newsletter box.

## 5 · Tables (`ops` schema)

| Table | Holds |
|---|---|
| `ops.inbox_entries` | person (customer or staff), store, kind, title and line (ar, en), link, read_at, created_at |
| `ops.sent_messages` | kind, person, store, channel, provider id, status (queued, sent, failed), created_at — no codes or links ever (access.md §2.3) |
| `ops.handled_events` | event id — every listener runs once per event |
| `ops.notices` | store, title and body (ar, en), link, audience (all / segment), channels, send_at, status, counts, created_by |
| `ops.staff_messages` | store, sender, audience, channels, body (ar, en), counts, created_at |
| `ops.subscribers` | store, email, locale, kind (guest / customer), `customer_id` (null for a guest), confirmation token **hash**, created_at, confirmed_at, brevo_synced_at. A guest whose email belongs to a customer is told to tick the box in their account instead |
| `ops.daily_reports` | store (or all), day, made_by, made_at, file, emailed |
| `ops.daily_report_settings` | staff, store (or all), auto email on/off, the time |
| `ops.sms_countries` | country code (ISO), calling code (typed, unique), provider — **[ACCEPTED — stage 8, Ops step 3; owner 2026-10-29]**, access.md amendment 90 |

Deleted after 12 months: inbox entries, sent messages, handled events, staff messages, sent notices,
daily report files (owner, 2026-10-28). Unconfirmed guests: after 7 days (from created_at).

## 6 · Events

**Ops publishes none.** It listens to the events of §1.5–§1.6, and to Access's **`CustomerAnonymized`**: the
customer's subscriber row and Brevo contact are deleted, and their inbox entries and messages addressed to
them are deleted. Also to **`MarketingConsentChanged`** and **`CustomerLocaleChanged`** (access.md amendment 79)
to keep the Brevo list current. Every listener (not C18) is **queued** and **idempotent by `eventId`**, never writes
another module's tables, and never blocks the sender (Stage 5's rules for Sync).

## 7 · Errors

To write with the use cases: a notice with no channel; a schedule in the past; an audience that is empty;
a report range over 12 months for searches; a daily report for a store the person may not view; a
provider failure (retried by the queue, then recorded as failed — never lost silently).

## 8 · Test scenarios

To write with the build plan; at least: every row of §1.5 and §1.6 sends once per event, in the
person's language, by its channels; a duplicate event sends nothing; consent off → no marketing email,
the bell entry still made; an unsubscribe switches consent off; staff switches respected; critical SMS
only to super admins (§1.6), their switch read again when it leaves; the daily report's figures against hand-counted orders, a cancelled
order excluded, a refund on its day; all stores totalled per currency; a past day's file unchanged.

## 9 · Open questions and answers

### 9.1 The owner's answers, 2026-10-28

Rounds 1–4 in this session: Ops first; email Brevo, SMS adapter (Mobily being checked); SMS for codes and
critical SMS only for the gateway key (1 day before expiry, or refused), to super admins; consent box unticked; staff only for transfers overdue; wording fixed; our own
order email regardless of MyFatoorah; the daily report (calendar day, month to date at the end, small
yesterday table, PDF, every file kept, auto email optional); no visitor counter; list reports in Excel;
notices manual, scheduled, later tied to campaigns, bell and email ticked by default; newsletters in
Brevo; retention 12 months; screens by the screens agent.

### 9.2 The builders' answers, 2026-10-28

Stage 5 session (Pricing, Inventory, Sync, B2B): low stock has no event — recommends one per store per
recount; `SyncNeedsAttention`'s rules; `HoldsShort` needs no alert; Access's rules for any change
(CHECK migration for a topic, bulk read beside `AccessApi.php:92`, consent cleared on anonymizing).
Stage 6 session (Catalog backend, Loyalty, Promotions, Feedback, Sales): the missing events of §2.3; no
inbox anywhere today; `segmentMembers`; proposed `favouriteCounts`, `revenueByDay`, `productSales`,
`searchSummary`; the search log keeps no person, ever. Stage 7 session: Payments' four events; Shipping
publishes none; the four moments handed to stage 8.

### 9.3 Questions

1. ~~Consent for company accounts~~ — **answered** (owner, 2026-10-28): the same switch.
2. ~~A staff message to many: does its email obey consent~~ — **answered**: yes.
3. ~~Who makes Access's changes~~ — **answered**: this session, as access.md amendments 79–84, with an
   independent review; the owner is asked when unsure.
4. The **events' and reads' exact shapes** — each builder confirms its rows of §2.3 (Stage 5, 6 and 7 done;
   Stage 7's events are its proposals, for the owner with payments.md / sales.md amendments).
5. Brevo's API and webhook — checked against its documentation before the build.
6.–8. **Answered** (owner, 2026-10-28, in the Stage 6 session) — written into §1.10.
9. **Answered** (owner, 2026-10-28): a return asked → staff (S9, Returns topic); the customer hears "order
   changed" (C6b) and "return received" (C8b); points taken back and points expired send nothing — they show
   in the account's history. **A customer cancelling their own order does get C6** (owner, 2026-10-31,
   replacing "a customer cancelling … sends nothing" here).
10. **Answered**: an offer stopped by Odoo's price is bell and email only; the only critical SMS is the
    gateway key at 1 day.
11. **Answered**: S6 and S7 under a new topic **Payments to check** (access.md amendment 84); the key's SMS
    to super admins only.
12. **Answered** (owner, 2026-10-28): the key refused (S5b) also sends the critical SMS to super admins.
13. **Answered**: a customer is on their home store's list only; unsubscribing anywhere switches consent off
    everywhere.
14. **Answered**: marketing email only to a verified email, never to a blocked account or one with a deletion
    pending.
15. **Answered** (owner, 2026-10-28): the email is the button's report at the chosen time; store time zone,
    all stores in Riyadh time — written into §1.11.
16. **Answered** (owner, 2026-10-29): a voided payment sends no message of its own.
17. **[ACCEPTED — stage 8, Ops step 3; owner 2026-10-29]** **SMS countries** (access.md amendment 90): the SMS
    provider is chosen by the phone number's country (its calling code), for staff and customers, not by store.
    A list of countries — ISO country, calling code typed by a Super Admin, provider (one may serve many) —
    starting with Saudi Arabia 966 and Egypt 20 on `log`; reserved to Super Admins and audited; provider keys
    in .env only, a new provider a new adapter. A phone from an unlisted country is refused when any account
    phone is entered and no code goes to it (address phones are their own job after this step). Removing a
    country is allowed: a Super Admin with such a phone enters a new one at sign-in, other staff get theirs
    changed by an admin, a customer reads as phone-unverified until they change it. Email stays global
    (Brevo). C18 is bound here (§1.5): Access's temporary sender is deleted. Owner, 2026-10-30: removing a
    country stays allowed even under a Super Admin's phone, whose account is emailed "your phone number was
    changed" when a new number is set at sign-in; a calling code overlapping another listed one (96 beside
    966, 2 beside 20) is refused.
