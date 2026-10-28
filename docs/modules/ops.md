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
  shows the stores they may view (access.md amendment 64).
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
  with no sign-in.
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
| C1 | Order taken | An order is placed | `OrderPlaced` (exists) | The shop's own email: number, lines, amounts, address, how to pay (owner, 2026-10-28) |
| C2 | Order approved | Staff approve it | **new** `OrderApproved` | |
| C3 | Order rejected | Staff reject it | **new** `OrderRejected` | With staff's reason when given |
| C4 | Order shipped | Marked shipped | **new** `OrderShipped` (Sales — Shipping publishes nothing) | The carrier, the number; **"Track" only when a link is stored on the order** (sales.md §9.5 #11) |
| C5 | Order delivered | Marked delivered | `OrderDelivered` (exists) | Says the store's return window started (a store setting, 14 days by default; `SalesSettings.php:13`) |
| C6 | Order cancelled | By the customer or staff | `OrderCancelled` (exists) | |
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
| C16 | Question answered | Staff answer | **new** `QuestionAnswered` | |
| C17 | Review not published | Staff decline it **with a reason** | **new** `ReviewNotPublished` | **No reason, no message** (feedback.md:75) |
| C18 | Security messages | Codes, links, B2B decisions | Access's `SecurityMessages` (access.md §2.3) | **Ops binds its own implementation** and the temporary one is deleted (round 1 #26). Codes by SMS; links by email. **No bell entry.** **Sent at once, never queued** — a code or link never reaches a job payload, an event or a record (access.md §2.3); a provider failure is reported to the caller, who already offers "send again". Branded with the store when the request has one, otherwise TouchWood's own (owner, 2026-10-28) |

The B2B decisions (approved, rejected, suspended) stay in `SecurityMessages`; Ops does **not** also
listen to `CompanyStatusChanged` for them, so nothing is sent twice.

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
- An alert **links to its page** (the order, the report) and never copies details that may be stale
  (Stage 5's rule for Sync).

### 1.7 Offer notices

A **notice** is staff telling customers about an offer: "Ramadan offers have started".

- Written in **both languages**, with an optional link (a category, a product, a page).
- **Audience:** every customer of the store, or the members of one Promotions segment.
- **Channels:** bell and email, **both ticked by default**, each untickable (owner, R4-6). The email
  goes only to customers who agreed (§1.3); the bell to all.
- **Send now or schedule** a date and time in the store's time zone. A scheduled notice can be edited or
  cancelled until it starts sending; once sending starts it cannot be recalled.
- **With Content:** a campaign may carry "send this notice when the campaign goes live"; Content
  publishes the campaign's going live and Ops sends the notice (owner, round 3 #11). Content never calls
  Ops.
- Sending to thousands goes in batches on the queue; the notice shows how many were sent.

### 1.8 Staff messages to a customer (handoff §13.3)

- **To one customer**, found by name, email or phone: staff with `ops.message.send_one`.
- **To every customer of a store, or a segment:** `ops.message.send_many`, **admins only** (round 1
  #18).
- **Bell and email only** (round 3 #10). Free text in both languages; the customer gets their own
  language.
- A one-customer message is a **service** message (it ignores consent); a message to many is
  **marketing** for email (§1.3) (owner, 2026-10-28, §9.3 #2).
- Every message is recorded: who sent it, to whom, the channels, when. Nothing can be recalled.

### 1.9 Newsletter subscribers and Brevo

- **Who is on the list, per store:** customers who agreed (§1.3), and **guests** who gave an email in the
  shop's newsletter box. A guest **confirms by email first** (double opt-in, round 1 #8); unconfirmed
  sign-ups are deleted after 7 days (owner, 2026-10-28).
- Ops **copies the list to Brevo** — one Brevo list per store and language — and keeps it current:
  joining, leaving, changing language. **Newsletters are designed and sent in Brevo** (round 3 #12 (a)).
- **Unsubscribing** in a Brevo email reaches Ops through Brevo's webhook: a guest is removed, a customer's
  consent switches off (Access).
- *To check against Brevo's documentation before the build:* its contacts and lists API, its webhook and
  how it is signed. Not verified in this session.

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
  all-stores report uses Riyadh time, each store's section still its own day. One time per person per
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

## 2 · Public contract

### 2.1 What Ops offers others

**Nothing callable** — nothing may depend on Ops (handoff §4.4). Ops **implements** Access's
`SecurityMessages` (§1.5 C18) — Ops depends on Access's interface, not the reverse.

### 2.2 Adapters

- `EmailSender` — **Brevo** (transactional email); `log` in development and tests.
- `SmsSender` — `log` until the owner names the provider (Mobily is being checked); then its adapter.
- `NewsletterList` — **Brevo** contacts and lists; `log` in development and tests.

Every adapter is chosen by configuration; a new provider is a new class and a binding.

### 2.3 What Ops needs from each module

Each row is an amendment to that module's spec, with the next free amendment number at its merge.
**The module's builder is asked first** (owner, 2026-10-28); each said it can build its rows on the owner's
go (§9.2).

| Module (builder) | Events | Reads |
|---|---|---|
| **Sales** (Stage 6 session) | `OrderApproved`, `OrderRejected`, `OrderEdited`, `OrderShipped`, `OrderCameBack`, `ReturnRequested`, `ReturnReceived`, `ReturnAccepted`, `ReturnRefused`, `PaymentRecorded`, `RefundRecorded`, `TransferOverdue` — ids only, through the outbox, like its four | `orderForMessage(orderId, locale)` — one value: number, store, lines as sold, totals (goods, discounts, points, shipping, VAT, total), way to pay, carrier, tracking link, reason; `revenueByDay(store, from, to)` — refunds on the return's asked day, and the waiting returns' estimated refund; `productSales(store, from, to, after, limit)` — per size, units and value without VAT after line discounts; the daily report's counts (cancelled, rejected, came back, refunds, individuals/companies); payments in per day by way; transfers waiting and overdue |
| **Loyalty** (Stage 6) | `PointsEarned`, `PointsChangedByHand` | Lots expiring on a day; points per day per store **per ledger kind** (`EARNED`, `REDEEMED`, `REDEMPTION_RETURNED`, `EARNING_REVERSED`, `ADDED`, `DEDUCTED`, `EXPIRED`; loyalty.md:62-70) |
| **Promotions** (Stage 6) | `CouponAssigned` | `segmentMembers` (exists); coupons used per day and their discount |
| **Feedback** (Stage 6) | `QuestionAnswered`, `ReviewNotPublished` (only with a reason) | `favouriteCounts(store, after, limit)`; new reviews and waiting questions per day |
| **Catalog** (Stage 6) | — | `searchSummary(store?, from, to, after, limit)` — grouped, no person (catalog.md §1.11) |
| **Inventory** (Stage 5 session) | `LowStockChanged(eventId, storeId, becameLow, ranOut, cameBack, occurredAt)` — ids only, only when a list is non-empty, after commit; needs a new table of the store's last low set (today only a count is kept, `CountLowStockHandler.php:58`); LOW→OUT is in `ranOut`, OUT→LOW in nothing | `lowStockChanges(store, from, to)` over a small change log; stock per size for the Stock report |
| **Sync** (Stage 5) | `SyncNeedsAttention` (exists; ids only — the alert links to the Sync Report) | Reads and problems per day — a new read over `sync.reads` and `sync.report_entries` |
| **B2B** (Stage 5) | `CompanyApplicationSubmitted` (exists; **carries no store** — add `storeId`, or a read by application) | Applications submitted and approved per day — a new read; Ops never counts from events |
| **Payments** (Stage 7 session) | **`storeId`** on `PaymentSucceeded` and `PaymentNotAccepted`; **new** `GatewayKeyExpiring`, `GatewayKeyRejected`, `PaymentRequested` — Stage 7's proposals, put to the owner as payments.md amendments. **payments.md:387 to read "by bell and email"** (Stage 7 agreed) | — (the money figures are Sales') |
| **Access** (builder session gone; **this session writes it, with an independent review** — owner, 2026-10-28) | — | access.md amendments **79–84** (numbers taken at merge): marketing consent and `withdrawMarketingConsent` (79); `staffToAlert(store, topic, permission)` (80); the critical-SMS switch (81); the **Returns** topic (82); `customersRegistered(store, from, to)` (83); the **Payments to check** topic (84) |
| **Content** (stage 8, later) | A campaign going live; a campaign about to expire | — |

## 3 · Use cases and permissions

New permission group **Messages and reports** (owner, 2026-10-28); every permission given to admins by
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
only to admins and super admins; the daily report's figures against hand-counted orders, a cancelled
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
   changed" (C6b) and "return received" (C8b); a customer cancelling, points taken back and points expired
   send nothing — they show in the account's history.
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
