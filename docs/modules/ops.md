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
the recipient's language (round 1 #23): every email Ops sends, what staff wrote and the daily report's too
(module pass, 2026-11-01); an all-stores report, TouchWood's own. Every number in Latin digits (owner, 2026-10-06).

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
  with no sign-in, within the request. The link opens **a page with one "Unsubscribe" button** — opening it
  changes nothing; the button (a POST to the same signed address) does it (owner, 2026-10-30). **It works for
  a switched-off store** (owner, 2026-11-01): that page only — everything else of the store stays a 404, and
  a missing or bad signature reveals nothing, whatever the store (§4.11). The same address is the
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
| C18 | Security messages | Codes, links, B2B decisions | Access's `SecurityMessages` (access.md §2.3) | **Ops binds its own implementation** and the temporary one is deleted (round 1 #26). Codes by SMS; links by email. **No bell entry.** **Sent at once, never queued** — a code or link never reaches a job payload, an event or a record (access.md §2.3); a provider failure is reported to the caller, who already offers "send again"; **one that fails also tells the Super Admins (S10, S10b, §1.6)**. Branded with the store when the request has one, otherwise TouchWood's own (owner, 2026-10-28) |

The B2B decisions (approved, rejected, suspended) stay in `SecurityMessages`; Ops does **not** also
listen to `CompanyStatusChanged` for them, so nothing is sent twice.

**Built (steps 5 and 6, 2026-10-31):** C1–C8b, C9, C12–C14, C16–C17b; **C9b, C10, C15 and C1's delivery address
(2026-11-01)**. Each listener is queued and runs once
per `eventId` (`ops.handled_events`); it makes the bell entry in the event's store (both languages, the link
to the customer's page in their own) and queues one email in their language; the email job writes the words
again from the module's read when it leaves. Each email (and SMS) has its `ops.sent_messages` row written
QUEUED, and its job written, with the listener's work, in one transaction (`beforeCommit`, 2026-11-01); the job calls the provider outside any transaction, then marks the row SENT
(FAILED after the queue's last attempt); a retry finds a SENT row and sends nothing - **at least once**: a
provider that took a message just before its row could be marked may get it again (review, 2026-10-31). A
read that names another store or customer than its event sends nothing and is logged by ids only. C2 is
skipped once the order is no longer approved, C8 accepted and S9 once the return was withdrawn; C4 names the
carrier only when the order has one, and the number when it has one; **"Track" is a second button in the email,
opening the stored link, only when the order holds one** (`OrderMessageDto::$trackingLink`, built 2026-11-01), the
number's line staying beside it; the bell opens the order's own page. Service messages: consent is not asked; an anonymized account
gets nothing; a switched-off store still sends. Amounts carry the currency's letters (ر.س, SAR), as the daily
report does. C5's window is read through `PlatformApi::setting('sales.returns.window_days', store)`. C14 runs
every morning at 10:00 Riyadh time for every store's own day a week ahead, once per store, customer and day;
the job carries the moment it was queued, so a late worker reminds of the same day. C9b and C10 read
`SalesReports::moneyRecordForMessage`: a record gone, voided (owner, 2026-10-29), of the other kind or another
customer's sends nothing; the words of a way are `refund_sent.ways`. **C10 has no check of its own for an online
checkout's one email:** `PaymentRecorded` is for money on an order that exists (sales.md §2.2), and today only
`MoneyRecording` fires it - so stage 7 must not fire it for the payment a checkout's order is created with
(sales.md:1603), or that customer gets C10 beside C1; the record does not say which it was. C15 is one
`CouponAssigned` for a whole assignment: its customers are paged with `PromotionsApi::assignedCustomers`, each
told once by an id made of the event and the customer, so a retry carries on where a failure stopped; the code is
read with `couponCode`, a coupon deleted since sends nothing. C1 shows the recipient and the delivery address in
the email only, never the bell; an order with no address has no such line, never a stand-in. C1's "paid" line shows on an order whose way to
pay is online: such an order exists only once its money came (sales.md §9.5 #4, #320). **Not built, waiting on
another module:** C11 (`PaymentRequested`, stage 7).

### 1.6 Alerts to staff

| # | Topic (Access) | When | Event | Permission that receives it | SMS |
|---|---|---|---|---|---|
| S1 | New orders | An order is placed | `OrderPlaced` | `sales.order.view` | — |
| S2 | Company applications | An application is submitted | `CompanyApplicationSubmitted` (exists) | `b2b.company.review` | — |
| S3 | Low stock | Sizes **became** low or ran out at a recount | **new** `LowStockChanged`, one per store per recount (§2.3) | `inventory.stock.manage` (Inventory has no view-only key) | — |
| S4 | Sync problems | A read left problems | `SyncNeedsAttention` (exists) | `sync.report.view` | — (owner, 2026-10-28: an offer stopped by Odoo's price is not SMS-worthy) |
| S5 | Payment problems (access.md amendment 78) | The gateway key expires in 30, 7, 1 days | **new** `GatewayKeyExpiring(eventId, storeId, mode, daysLeft, expiresOn, occurredAt)`, once per threshold, from Payments' daily job (*Stage 7's proposal*) | `payments.gateway.manage` — super admins only | **Yes, at 1 day** |
| S5b | Payment problems | MyFatoorah refused a store's current key: once until a new key is saved; an earlier key refused sends nothing; a refusal on the mode not in use fires too | `GatewayKeyRejected(eventId, storeId, mode, occurredAt)` (#310; payments.md:187-192) | `payments.gateway.manage` — super admins only | **Yes** (owner, 2026-10-28, §9.3 #12) |
| S6 | **Payments to check** (a **new** Access topic, amendment 84) | A bank transfer unchecked 72 h after the upload | **new** `TransferOverdue(eventId, orderId, storeId, occurredAt)`, once per order, from **Sales'** scheduled job (*Stage 7's proposal*; sales.md:1583-1585) | `sales.payment.record` | — |
| S7 | **Payments to check** | A payment not accepted — a refund is owed | `PaymentNotAccepted` + **`storeId`** (stage 7); links to the attempt in Payments' staff lists — for a try the order never existed | `sales.payment.record` | — (owner, round 3 #13: withdrawn) |
| S9 | **Returns** (a **new** Access topic) | A customer asks for a return | **new** `ReturnRequested(eventId, returnId, orderId, storeId, customerId, occurredAt)` | `sales.return.manage` | — (bell opens the return; it stays under Waiting in the panel's Returns) |
| S8 | Campaign expiry | A campaign is about to end, when Content's spec says | `CampaignExpiring(eventId, campaignId, storeId, endsAt, occurredAt)` | **Content's, when it has one.** Until then `ops.message.send_many`, admin-only: Super Admins and the store's admins who hold it (2026-11-03, for the owner to confirm) | — |
| S10 | **None: it cannot be switched off** (owner, 2026-11-01) | A security email (C18) could not be sent: **at most once a clock hour** | None: `SecurityFailureAlert`, called from `OpsSecurityMessages` | `ops.sms_countries.manage`, reserved, used only because it reaches active super admins and no one else | — **The bell alone** |
| S10b | **None: it cannot be switched off** (owner, 2026-11-01) | A security SMS (C18) could not be sent: **at most once a clock hour, its own hour** | The same | The same | — **The bell alone**: a failed SMS never sends an SMS or an email about itself |

- Every permission key above exists in code today (`payments.gateway.manage`: `PaymentsPermissions`, reserved).
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
  or ran out (a recount where sizes only came back alerts no one). **S5 and S5b are built (2026-11-01)**, on Payments'
  `GatewayKeyExpiring` and `GatewayKeyRejected`, with the critical SMS at 1 day and on every refusal (super admins with
  `critical_sms` on and a phone, both checked again when the SMS leaves; the provider of the phone's country). **Their
  words say which key, the test or the live one (`mode`), and never that payments stop**: a key of the mode not in use
  stops nothing (2026-11-01). **S7 is built (2026-11-02, on #314's `PaymentNotAccepted`)**: its entry and
  email carry no link until Payments' staff list of attempts exists. **Not built, waiting on another module:**
  `TransferOverdue` (S6) is not in main. **S8 is built on Ops' side (2026-11-03) and waits on Content to fire
  `CampaignExpiring`.** Its words name the campaign, read through `ContentApi::campaignForMessage`, and its last day in the
  store's zone; no link until Content has a page. Until Content is built the read answers null, and an alert sends nothing.
- **S10 and S10b are built. The owner's decisions, 2026-11-01:** every active Super Admin always gets the entry, **no switch
  applies** (§1.4's third rule does not hold here), and the Payment problems switch means only payment problems. **A failed security
  SMS alerts too**, with its own words and its own hour, so an SMS and an email failing in one hour each tell them.
- **Why:** Access PR #317 (open that day) stops showing the person a failed security email, so a broken setup would otherwise show only
  in the log and a `FAILED` row.
- **The words** fire on any failure, one customer's bad address or a template error included, so they announce no outage and name no
  cause, no person, address, code or link: one could not be sent, and if this repeats, check the settings and the log (owner's blanket
  approval for now). No link: the settings are the server's.
- **At most once:** one claim per clock hour (UTC) and kind in `ops.handled_events`, in one transaction with the entries: of any number of
  failures at once, one writes them and the rest find the hour taken, each at the cost of two statements; so a failure at 10:59 and
  another at 11:00 can write two, in two hours. A Super Admin added mid-hour waits for the next hour's failure.
- **Never in the person's way.** Written directly, not by a job, since the queue may be what is down, after the caller's commit when it
  has a transaction open (none of today's callers has), so a caller that rolls back leaves neither an entry nor a claim, and the next
  failure alerts. A request waits on another's uncommitted claim for **250 ms at most** (`SET LOCAL lock_timeout`, that transaction
  only), then leaves it to them; if they roll back within that wait it takes the claim itself. Anything else that breaks is reported and stops
  here: the claim goes with the failed write, and the next failure tries again.
- **Who, through Access's contract:** `AccessApi::staffToAlert` is the one public read that names Super Admins, and it insists on a
  topic, only to say each one's switches for it. It filters nobody by them, so the code names a topic and reads no switch.
- Filed under the failed message's store, otherwise the first store, on or off; a Super Admin's bell shows every store.

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
  those who may receive offers (§1.3); a member whose home store is another gets their email, and the bell entry is
  filed under the segment's store (owner, 2026-11-01, accepted). A segment deleted while its notice sends fails the batch: the notice
  shows FAILED (as any batch that fails its last attempt). Changing a waiting notice (review, 2026-10-31): no
  `segment` (or `segment_id`) keeps its audience; an empty one makes it every customer of the store; an id makes
  it that segment.
- **Channels:** bell and email, **both ticked by default**, each untickable (owner, R4-6). The email
  goes only to customers who agreed (§1.3); the bell to all.
- **Send now or schedule** a date and time in the store's time zone. A scheduled notice can be edited or
  cancelled until it starts sending; once sending starts it cannot be recalled.
- **With Content:** a campaign may carry "send this notice when the campaign goes live"; Content
  publishes the campaign's going live and Ops sends the notice (owner, round 3 #11). Content never calls
  Ops. **Built on Ops' side (2026-11-03) and waits on Content to fire `CampaignWentLive(eventId, campaignId, storeId,
  noticeId, occurredAt)`:** once per event, a notice of that store still SCHEDULED starts sending, by the same batches, its
  `send_at` moved to now, with no audit row (the scheduler writes none); no `noticeId`, a notice gone, cancelled, already
  started or another store's sends nothing. **No state makes a notice wait for its campaign** (§9.5 #1): it keeps its own time, and
  whichever comes first starts it.
- Sending to thousands goes in batches on the queue; the notice shows how many were sent. A batch that
  fails its last attempt shows the notice **FAILED**; the job waits on the failed jobs screen, and
  retrying it there carries on from where it stopped (step 7: a batch is safe to run again).

### 1.8 Staff messages to a customer (handoff §13.3)

- **To one customer**, found by name, email or phone: staff with `ops.message.send_one` — only a customer
  registered in **the store chosen** (owner, 2026-11-01: the entry lands in a bell they see), where the staff
  member holds `ops.message.send_one` **and** may view customers; the search offers only those, and any other is
  refused as unknown (owner, 2026-10-30).
- **To every customer of a store, or a segment:** `ops.message.send_many`, **admins only** (round 1
  #18). Every customer of a store is every customer registered in it (owner, 2026-10-30). **The segment audience
  is built (owner, 2026-11-01)** as a notice's is (§1.7): one of the store's segments; another store's refused as
  Promotions' `SegmentNotFound`, an empty one as an empty audience (§7); the batches page `segmentMembers`. **A member
  whose home store is another (owner, 2026-11-01, accepted):** their email arrives, and the bell entry is filed under the
  segment's store, the same as a notice's.
- **Bell and email only** (round 3 #10). Free text in both languages; the customer gets their own
  language.
- A one-customer message is a **service** message (it ignores consent); a message to many is
  **marketing** for email (§1.3) (owner, 2026-10-28, §9.3 #2).
- Every message is recorded: who sent it, to whom, the channels, when, and can be read back (§4.3). Nothing can
  be recalled.
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
  the provider's contact is deleted at once, so anonymizing finds nothing left (step 7, review fix) — **with or
  without a row** (module pass, 2026-11-01): someone who withdrew earlier, or a guest who registered without
  agreeing, left a list and kept a contact, and this is the last moment the email is known. The delete alone is
  called, no removal from a list first: Brevo's reference does not say that removal succeeds for a contact no
  longer on the list. Unless another store's row still holds the email; then only the list.
  Staff see a customer's email in the subscribers list only if they may view that customer.
- **A customer is on the list only while they may receive offers by email** (§1.3: agreed, email
  verified, not blocked, no deletion pending): Ops also listens to `CustomerEmailVerified`,
  `CustomerBlocked`, `CustomerUnblocked`, `CustomerDeletionScheduled` and `CustomerDeletionCancelled`
  (owner, 2026-10-30, kept as built in step 7). A contact newly on a list — a customer who agreed again,
  a guest's new confirmed sign-up — is allowed email again at Brevo (`emailBlacklisted: false`), since an
  earlier unsubscribe there left Brevo refusing it.
- **A guest's own unsubscribe link** (§3) is signed and carried by the confirmation email; it opens the
  same one-button page, and the button takes the guest off at once; for a switched-off store too (§1.3). A guest giving an email that belongs to a customer account is told to tick
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

**Built in step 8** (the owner's answers of 2026-10-30): revenue **by category** is net of refunds, so the
categories add up to the net total - built 2026-11-01 from Sales' `productNetSales`, grouped by each product's
category through Catalog (no category, or one Catalog no longer knows: "No category"), over the whole range, most
revenue first, then one total per currency. **Beside it the same "revenue if the waiting refunds go through"
(owner, 2026-11-01)**: net less `waitingRefunds`, blank where none wait, as on the Revenue sheet. Favourites keep archived products, marked;
Searches cover at most 12 months, every other report
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
  2026-10-28; round 3 #8), **any time but 00:00** (owner, 2026-11-01; §7: the day would have only just started).
  Off by default. The email is **exactly the report the button would give at
  that time** — midnight until then. The time is read in the store's time zone; a super admin's
  all-stores report uses the base store's time zone (Riyadh), each store's section still its own day. One time per person per
  store, or one for all stores (owner, 2026-10-28, §9.3 #15). So an all-stores email at 23:00 Riyadh reaches
  Dubai's 00:00: **that store's section shows zeros and the email still goes** (owner, 2026-11-01); its month to
  date still reads from the 1st.

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
**Revenue per category is net of refunds too (owner, 2026-11-01)**, from `productNetSales`, so the table adds up to
the report's own net revenue: a day of refunds and no sales has its rows, a negative figure included; a category
with nothing but a waiting return has no row (its figure is 0, and the waiting one comes off only in the second
figure above). A category's units are the pieces sold - Sales gives no refunded pieces by product. The top 10 sizes
stay as sold (`productSales`). Reports already made keep what they showed: each is its PDF file, opened as it is.
The email runs as a delayed job queued by the person who switched it on and then by itself a day at a time, so
it reads only what that person may see (no Access read answers that for another person; the owner accepted this
design, 2026-10-30). The setting holds the one moment it may go next, moved on by compare-and-set with the next
job queued in the same transaction - a double submit or a race never starts a second email; a late
worker sends the missed report as at its moment, once. **A failed making waits in the failed jobs, and retrying it
makes that moment's report, as at that moment, once** (module pass, 2026-11-01): the moment has moved on already, in
the same version, so the retry skips the move and queues no second next day; each report is kept once per setting
and moment (`email_setting_id`, `email_due_at`), with its delivery in the same transaction, so a retry after it was
made does nothing: one report per moment. **Its delivery is at least once (owner, 2026-11-01):** the job sends, then
marks the report emailed, so a crash in that gap may send the email twice; it is never lost. Retrying the job of a setting switched off or changed since does nothing:
the person asked for something else (another version). A cancelled or missing staff account switches it off; a
disabled one is sent nothing while disabled. Its delivery is a job of its own, tried again on a provider failure.
Its send is recorded on the report (`emailed_at`), not as a message kind.

**Transfers waiting are as they stand now** on any report read after its day, a past day's, a late worker's or a
retry's: Sales keeps no history of them, so the report labels that one figure as current, in both languages (module
pass, 2026-11-01).

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
| **Sales** (Stage 6 session) | **Built (#259):** `OrderApproved`, `OrderRejected`, `OrderShipped`, `OrderCameBack` `{eventId, orderId, storeId, customerId, occurredAt}`; `OrderEdited` adds `editId`; `ReturnRequested`, `ReturnAccepted`, `ReturnRefused`, `ReturnReceived` add `returnId`; `PaymentRecorded`, `RefundRecorded` add `moneyRecordId` (by hand; Stage 7 fires `PaymentRecorded` for an online payment on an order that already exists, never for the payment a checkout's order is created with: that customer gets C1 alone) — ids only, through the outbox. `TransferOverdue` is Stage 7's mark | **Built (#260)** on a new contract `Sales\Public\Contracts\SalesReports` (not `SalesApi`): `orderForMessage(orderId, locale)`, with the tracking link (C4; wired 2026-11-01); `returnForMessage(returnId)`; `counts(store, from, to)` {placed, counted, individuals, companies, cancelled, rejected, cameBack} — `counted` leaves out cancelled, rejected and came back; `moneyOn(store, fromDay, toDay)` — payments and refunds by way, on the store's own dates, voided left out; `transfersWaiting(store)` {orders, owed} (overdue is Stage 7's); `revenueByDay(store, from, to)` — pass the store's midnights, refunds and waiting refunds per day; `productSales(store, from, to, after, limit)` — most sold first, returns not taken off; **wired 2026-11-01:** `moneyRecordForMessage(moneyRecordId)` (C9b, C10) and `productNetSales(store, from, to, after, limit)` (by category, the Excel sheet and the daily report). An unknown store is refused |
| **Loyalty** (Stage 6) — **built (#256)** | `PointsEarned`, `PointsChangedByHand` | Lots expiring on a day; points per day per store **per ledger kind** (`EARNED`, `REDEEMED`, `REDEMPTION_RETURNED`, `EARNING_REVERSED`, `ADDED`, `DEDUCTED`, `EXPIRED`; loyalty.md:62-70) |
| **Promotions** (Stage 6) — **built (#255)** | `CouponAssigned` | `segmentMembers` — store-scoped `segmentMembers(StoreId $store, string $segmentId, ?string $after, int $limit)` (PR #280; another store's segment → `SegmentNotFound`); coupons used per day and their discount; **wired 2026-11-01 (C15):** `assignedCustomers(assignmentId, after, limit)`, `couponCode(couponId)`; **(the forms, §4.3, §4.4):** `storeSegments(store, after, limit)`, `storeSegment(store, segmentId)` (#313) |
| **Feedback** (Stage 6) | **Built (#252):** `QuestionAnswered`, `QuestionNotPublished`, `ReviewNotPublished` `{eventId, <itemId>, productId, storeId, customerId, occurredAt}` — one per item, only with a reason | **Built:** `favouriteCounts(store, after, limit)` (archived products included — Ops filters through Catalog); `reviewForMessage(id)`, `questionForMessage(id)`; `counts(store, from, to)` {newReviews, newQuestions, waitingQuestions (now)} |
| **Catalog** (Stage 6) | — | **Built (#251, catalog.md amendment 37):** `searchSummary(?store, from, to, after, limit)` — {query, storeId, locale, searches, noResults}, most searched first; no person (catalog.md §1.11) |
| **Inventory** (Stage 5 session) | **Built (#267, inventory.md amendment 1):** `LowStockChanged(eventId, storeId, becameLow, ranOut, cameBack, occurredAt)` — after commit, once per store, only when a list is non-empty; a store's first recount sends nothing; a size going from fine straight to out is in `ranOut` only (owner, 2026-10-29) | **Built:** `lowStockChanges(StoreId, from, to)` [from, to), an unknown store refused; `stockLevels(StoreId, after, limit 1–1000)`, paged by size id; the change log kept 12 months |
| **Sync** (Stage 5) | `SyncNeedsAttention` (exists; ids only — the alert links to the Sync Report) | **Built (#262, sync.md amendment 1):** `readsAndProblemsPerDay(stores, fromDay, toDay, until)` → `list<SyncDayDto>` |
| **B2B** (Stage 5) | **Built (#261, b2b.md amendment 34):** `CompanyApplicationSubmitted` carries `storeId` (owner, option (a)) | **Built:** `applicationCounts(storeId, from, to)` → {submitted, approved} over [from, to); Ops never counts from events |
| **Payments** (Stage 7 session) | **Built, in main, and listened to (2026-11-01):** `GatewayKeyExpiring(eventId, storeId, mode, daysLeft, expiresOn, occurredAt)`, `GatewayKeyRejected(eventId, storeId, mode, occurredAt)`. **Not in main:** **`storeId`** on `PaymentSucceeded` and `PaymentNotAccepted`; `PaymentRequested` — Stage 7's proposals, put to the owner as payments.md amendments. **payments.md:387 to read "by bell and email"** (Stage 7 agreed) | — (the money figures are Sales') |
| **Access** (builder session gone; **this session writes it, with an independent review** — owner, 2026-10-28) | — | access.md amendments **79–84** (numbers taken at merge): marketing consent and `withdrawMarketingConsent` (79); `staffToAlert(store, topic, permission)` (80); the critical-SMS switch (81); the **Returns** topic (82); `customersRegistered(store, from, to)` (83); the **Payments to check** topic (84). **Built in Ops step 4 (2026-10-29):** 80, 81, 82, 84, and 88 — `staffStores(staffId)`, the stores a staff member's bell shows. **Added 2026-11-02:** `customerCountInStore(store)`, amendment 94 |
| **Content** (stage 8, later) | **Declared ahead of its build, listened to, fired by nothing yet (2026-11-03):** `CampaignWentLive(eventId, campaignId, storeId, noticeId, occurredAt)`, `noticeId` null for none; `CampaignExpiring(eventId, campaignId, storeId, endsAt, occurredAt)` — ids only, through the outbox | **Declared, answered null until Content's build replaces `NoCampaignsYet`:** `ContentApi::campaignForMessage(id)` → `?CampaignMessageDto` {campaignId, storeId, name} (S8) |

## 3 · Use cases and permissions

New permission group **Messages and Reports** (owner, 2026-10-28); every permission given to admins by
default (roles nest).

| Use case | Permission | Scope |
|---|---|---|
| Read and mark my inbox | none (own) | Own |
| Send a message to one customer | `ops.message.send_one` | That store |
| Send a message to many / a segment | `ops.message.send_many` (admins) | That store |
| See how many a whole store's notice or message would reach | `ops.notice.manage` or `ops.message.send_many` | That store |
| Read the staff messages back | A message to one: `ops.message.send_one` and viewing customers; to many: `ops.message.send_many` | That store |
| Write, schedule, cancel an offer notice | `ops.notice.manage` | That store |
| Manage newsletter subscribers (view, remove a guest) | `ops.newsletter.manage` | That store |
| View and download reports | `ops.report.view`; the Stock report also needs `inventory.stock.manage` there | Stores they may view |
| Make, list and download daily reports; switch on the daily email | `ops.report.daily` | Stores they may view; all stores: super admin |
| Subscribe to the newsletter; confirm; unsubscribe | guest / signed link | — |
| Manage the SMS countries — list, add, change provider or calling code, remove **[ACCEPTED — stage 8, Ops step 3; owner 2026-10-29]** | `ops.sms_countries.manage` (reserved: Super Admins) | Global |

## 4 · What the screens agent gets

Data shapes only; the screens are the screens agent's (owner, 2026-10-28). Every fact here is the code's, as
of the module pass (2026-11-01): the routes in `Presentation/admin-routes.php` and `shop-routes.php`, the
fields in `Presentation/Http/Resource`. What a screen needs and the code does not give is in §4.13, not here.

### 4.1 What holds for every route

- **The answers are JSON, not pages.** Every GET of §4 answers JSON even to a plain visit, except the two
  downloads; no route here renders a page (§4.13). **Changes when §4.15 is built (owner, 2026-11-02):** the same address
  gives a page to a plain visit, and today's JSON to whoever asks for it with `Accept: application/json`. `ops.admin.choices` (§4.1 below) says what each form may offer. The consent switch (§4.8) is Access's and answers with a
  redirect. The panel's route list carries `ops.admin.*`, the shop's `storefront.*` (`config/ziggy.php`).
- **Types.** Each `…Data` class below is `#[TypeScript]`: generated in
  `resources/js/types/generated/Modules/Ops/Presentation/Http/Resource/index.ts`, `MessageKind` in
  `…/Modules/Ops/Public/Enums/index.ts`, `ReportKind` in `…/Modules/Ops/Application/Reports/index.ts`. Fields typed `string` there may hold a fixed list named below.
- **Ask with `Accept: application/json`.** A refusal then comes as a problem document (`ProblemDetails`,
  `application/problem+json`): `{type, title, status, detail, correlation_id}`, the title and detail in the
  page's language. Without that header a refused GET gets the panel's or the shop's error page, and a refused
  POST Laravel's own page.
- **Every POST needs the CSRF token** (the routes are in the `web` group; 419 when it is stale), except the
  two signed unsubscribe POSTs (§4.11).
- **Signed out.** `AdminArea::SIGNED_IN` (`access.staff`): with JSON asked, `401 {message}`, not a problem
  document; otherwise a redirect to `/admin/sign-in`. A staff member who must set up an authenticator app first:
  `403 {message}`. `StorefrontArea::SIGNED_IN` (`access.customer`): a redirect to the store's home, JSON asked
  or not.
- **Common refusals**, not repeated below: `shared.unauthorized` 403 (the permission is not held there; a store
  code not on offer; an inactive staff member). `ops.invalid_ops_input` 422 is one type for every bad value: the
  JSON document **names no field**, and its `detail` carries the field's key unworded (left so by the owner,
  2026-11-01); each section lists the keys it can name. A form that goes back through `App\Http\FormErrors` gets it
  in words: "The Arabic title isn't valid." (`ops::errors.fields`, tested in `ScreensReadsTest`).
- **Moments** are texts in UTC with their offset, in one format, `YYYY-MM-DDTHH:MM:SS.mmm+00:00`
  (`format('Y-m-d\TH:i:s.vP')`). Days are `YYYY-MM-DD`, months `YYYY-MM`. The server converts nothing for a
  screen, except a notice's `sendAtLocal` (§4.4) and a report's own texts.
- **Digits.** Every number shown is in Latin digits (owner, 2026-10-06): format through `intlLocale`
  (`resources/js/lib/digits.ts`). `send_at`, `sendAt`, `day`, `from` and `to` take 0-9 only: a typed date or time
  goes through `toLatinDigits` first.

**Which store a request names:**

| Routes | `store` is | Left out |
|---|---|---|
| Notices list and write, composer lookup and send, subscribers list, the two segment lists | The store's **code**, through `StoreChoices::chosen` | The first store on offer that is on; 403 when there is none |
| Reports preview and download | The store's **id**, or `all` | 422, `store` |
| Daily report make, email switch | The store's **id** | Every store's report: a super admin's alone, 403 for anyone else |

`StoreChoices` offers a Super Admin every store and anyone else the stores that are on. The reports, the daily
reports, and a notice or subscriber named by id check only the permission in its store, on or off. An unknown
store id is 422 `store`; an unknown or unoffered code is 403.

**What each form may offer**, for the reader asking:

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.choices` | GET `admin/ops/choices` | Any active staff; an empty list means that form is not theirs | `OpsChoicesData` |
| `ops.admin.audience` | GET `admin/ops/audience?store={code}` | `ops.notice.manage` or `ops.message.send_many` in the store | `StoreAudienceData`: `{count: int}` |

| `OpsChoicesData` | Type | What |
|---|---|---|
| `notices`, `messagesToOne`, `messagesToMany`, `subscribers`, `dailyReports` | `StoreChoiceData[]` | `StoreChoices::forJobs` for that form's permission: `ops.notice.manage`, `ops.message.send_one`, `ops.message.send_many`, `ops.newsletter.manage`, `ops.report.daily` |
| `dailyReportsAllStores` | bool | Whether every store's report and email may be asked: a super admin alone |
| `reports` | `ReportChoiceData[]` | Only the kinds this reader may open, in `ReportKind`'s order |

`count` is **the bell's reach** for a notice or a message to every customer of the store, for the confirmation (owner,
2026-11-02, §9.4 Q14): those registered in it, of either account type, not anonymized, exactly whom the sender's bell
reaches. Those blocked, with a deletion pending or who never agreed to offers are counted. **Email goes to fewer** (§1.3),
and no route gives that number, so a send by email alone is worded apart (§4.15, O4). As of the moment asked; a segment's is
`memberCount`. `store` is named as on the notices' routes; a refusal is `shared.unauthorized` 403. One
count query, `AccessApi::customerCountInStore` (access.md amendment 94).

`StoreChoiceData` is `{id, code, name, isOn}`, the name in the page's language, so a form takes whichever of id or code
it needs (table above). `ReportChoiceData` is `{kind: ReportKind, stores: StoreChoiceData[], allStores: bool}`: Stock's
stores are those holding both `ops.report.view` and `inventory.stock.manage`, so it is left out where they hold only
one. Offering is not allowing: every handler still checks.

**Paging.** A cursor, never a number of pages and never a total. `next` is null on the last page, otherwise
sent back as `?after=`. An `after` that is no ULID is 422 `after` (the bell and the daily reports ignore one
over 64 characters and show the first page).

| List | Page | Order | A ULID not in the reader's list |
|---|---|---|---|
| A bell | 20 | Newest first | An empty page |
| Notices | 20 | Newest first, by when written | An empty page |
| Subscribers | 50 | By id | Carries on after that id |
| Daily reports | 20 | Newest made first | An empty page |
| Staff messages | 20 | Newest first | An empty page |
| A form's segments | 20 | Newest first, by id | Carries on after that id |
| Composer lookup | At most 20 | None promised; no paging | — |
| A report's preview | First 50 rows of each sheet, and its totals apart | The sheet's own; no paging, the rest is in the file | — |

### 4.2 The two bells (§1.2)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.bell` / `storefront.ops.bell` | GET `admin/notifications` / `{store}/{locale}/account/notifications` | Signed in: staff `ops.panel_inbox.view_own`, customer `ops.inbox.view_own`, each held automatically | `InboxPageData` |
| `ops.admin.bell.unread` / `storefront.ops.bell.unread` | GET `…/notifications/unread` | The same | `UnreadData` |
| `ops.admin.bell.read` / `storefront.ops.bell.read` | POST `…/notifications/{entry}/read` | The same | `UnreadData`, the number after it |
| `ops.admin.bell.read-all` / `storefront.ops.bell.read-all` | POST `…/notifications/read-all` | The same | `UnreadData`, counted again |

Input: `after` on the list. `InboxPageData` is `{entries: InboxEntryData[], next: string|null, unread: int}`,
`unread` over all the reader's entries, not the page's. `UnreadData` is `{unread: int}`.

| `InboxEntryData` | Type | What |
|---|---|---|
| `id` | string | The entry's id |
| `kind` | `MessageKind` | §1.5, §1.6, or `offer_notice`, `staff_message` |
| `storeCode`, `storeName` | string | The store it is about, the name in the page's language; empty if the store is not found |
| `title`, `line` | string | In the page's language: the shop's `{locale}`, the panel's language |
| `link` | string or null | A path from the site's root, never a full address: a customer's in the language they read when it was written, a staff member's under `/admin`. Null when there is nowhere to go (a staff message, S10, S10b). The gateway key alerts (S5, S5b) open `/admin/payments/gateway?store={code}`, the alert's own store (owner, 2026-11-02); their emails carry the same link, their SMS none, and entries written before that stay without one |
| `read` | bool | |
| `createdAt` | moment | |

- A customer's bell is the store being shopped only; a staff member's spans every store `AccessApi::staffStores`
  gives, on or off. Another person's entry never appears.
- **Reading the list marks nothing:** "opening an entry marks it read" (§1.2) is the screen's POST.
- Refusals: `ops.inbox_entry_not_found` 404 on a mark, for an entry unknown, another person's, or of a store the
  bell does not show. An entry read already answers 200, unchanged. 422 key: `after`.

### 4.3 The message composer (§1.8)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.messages.customers` | GET `admin/messages/customers` | `ops.message.send_one` in the store | `{customers: CustomerToMessageData[]}` |
| `ops.admin.messages.send` | POST `admin/messages` | `audience` `ONE`: `ops.message.send_one` in the store. Otherwise: `ops.message.send_many` there, admins only | 201 `{id: string}` |
| `ops.admin.messages` | GET `admin/messages` | `ops.message.send_one` or `ops.message.send_many` in the store | `StaffMessagesPageData` |
| `ops.admin.messages.segments` | GET `admin/messages/segments` | `ops.message.send_many` in the store | `SegmentsPageData` |

Lookup inputs: `store` (code); `search`, 3 to 100 characters after trimming, no control characters, through
`AccessApi::customerIdsMatching`: a name in any store, an email or phone among the customers the reader may
view. `CustomerToMessageData` is `{id, firstName, lastName, email, phone, homeStoreCode}`, strings, `phone` null for
none.

- Only customers a message may reach are offered: not anonymized, registered in **the `store` asked**, where the
  reader may view customers (`Writers::mayWriteToOne`, `AccessApi::customerViewStores`, `access.customer.view`), so
  the email and phone are theirs to read.
- **A known limit:** at most 20, from the first 200 Access matches across every store, so fewer than 20 does not mean
  nobody else matches: a common name in other stores can hide this store's customer.
- Refused: `access.invalid_access_attribute` 422 for a `search` too short, too long or empty. A short text is
  refused, not answered with an empty list.

| Send input | Rules |
|---|---|
| `store` | Code |
| `audience` | **Required:** `ONE`, `STORE` or `SEGMENT`, in capitals. It must match what is named, so a form that lost its segment never reaches a whole store (owner's reviewer, 2026-11-01) |
| `customer` | The customer's id, registered in `store`. With `ONE`, and only with it |
| `segment` or `segment_id` | One of the store's Promotions segments. With `SEGMENT`, and only with it |
| `title_ar`, `title_en` | 1 to 200 characters after trimming; one line, no control characters |
| `body_ar`, `body_en` | 1 to 500 characters after trimming; line breaks allowed, no other control characters |
| `by_bell`, `by_email` | A tick: `true`, `1`, `"1"`, `"true"` or `"on"`; anything else, or left out, is no. At least one |

Sent at once on the queue; nothing can be recalled. The answer carries no counts and no status: read them from the
list. To one customer it is a service message; to the store or a segment, its email goes only to those who agreed,
its bell to all (§1.3). 422 keys: `title_ar`, `title_en`, `body_ar`, `body_en`, `channels`, `customer` (unknown,
anonymized, not of this store, or not one the reader may reach), `audience` (not said, not one of the three, or not
matching what is named: `STORE` with a customer, `SEGMENT` with no segment; a store with no customers, a segment with
no member). `promotions.segment_not_found` 404, a segment unknown or another store's;
`promotions.invalid_promotion_attribute` 422, a `segment` that is no id.

The list takes `store` (code) and `after`. **Each reader sees what they may send:** messages to one under
`ops.message.send_one` while viewing that store's customers (they name them), messages to many under
`ops.message.send_many`. Holding send_one alone, and not viewing customers: an empty list. Neither: 403. 422 key: `after`.

| `StaffMessageData` | Type | What |
|---|---|---|
| `id`, `storeCode` | string | |
| `audience` | string | `ONE`, `STORE` or `SEGMENT` |
| `customerId` | string or null | Set only for `ONE` |
| `customerName` | string or null | Null but for `ONE`; empty if Access no longer knows them |
| `segmentId` | string or null | Set only for `SEGMENT` |
| `segmentNameAr`, `segmentNameEn` | string or null | The segment's name now. **Null with `segmentId` set: it was deleted since** |
| `segmentMemberCount` | int or null | How many it holds **now**, not how many the message reached. Null as the name is |
| `titleAr`, `titleEn`, `bodyAr`, `bodyEn` | string | As written |
| `byBell`, `byEmail` | bool | |
| `status` | string | `SENDING`, `SENT` or `FAILED` (a batch failed its last attempt) |
| `sentBy` | string | As `AccessApi::staffDisplayNames` names them to this reader |
| `bellCount`, `emailCount`, `failedCount` | int | Bell entries made, emails sent, emails failed so far |
| `createdAt` | moment | |
| `finishedAt` | moment or null | Set once `SENT` |

`StaffMessagesPageData` is `{messages: StaffMessageData[], next: string|null}`.

A form's segments, here and in §4.4, take `store` (code) and `after`. `SegmentsPageData` is `{segments: SegmentChoiceData[], next: string|null}`;
`SegmentChoiceData` is `{id, nameAr, nameEn, memberCount}`, `memberCount` how many it holds now. Only the store's own appear. Refused: 403 without the
form's permission in that store, or for another store. 422 key: `after`. **A row with `memberCount` 0 is listed but cannot be sent to:** the write refuses
an empty segment (`Writers::audience`, 422 `audience`), so the form shows such a row disabled.

### 4.4 The notices list and form (§1.7)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.notices` | GET `admin/notices` | `ops.notice.manage` in the store | `{notices: NoticeData[], next: string or null}` |
| `ops.admin.notices.store` | POST `admin/notices` | `ops.notice.manage` in the store | 201 `NoticeData` |
| `ops.admin.notices.segments` | GET `admin/notices/segments` | `ops.notice.manage` in the store | `SegmentsPageData` (§4.3); **disable a row with `memberCount` 0**: the write refuses it, 422 `audience` |
| `ops.admin.notices.show` | GET `admin/notices/{notice}` | `ops.notice.manage` in the notice's store | `NoticeData` |
| `ops.admin.notices.update` | POST `admin/notices/{notice}` | `ops.notice.manage` in the notice's store | `NoticeData` |
| `ops.admin.notices.cancel` | POST `admin/notices/{notice}/cancel` | `ops.notice.manage` in the notice's store | `NoticeData` |

| Input | Rules |
|---|---|
| `store` | Code; list and write only. A change never moves a notice |
| `after` | List only |
| `title_ar`, `title_en`, `body_ar`, `body_en` | As in §4.3 |
| `link` | Optional: a page of the shop **without its store and language**. A leading `/` is dropped; at most 500 characters; no `//`, backslash, `:` or white space |
| `by_bell`, `by_email` | Ticks, as in §4.3; at least one. Left out is **no**, not "as it was" |
| `send_at` | `YYYY-MM-DD HH:MM`, or with a `T`, read in the store's time zone; a real date, still to come. Left out or empty: **it starts sending now** |
| `segment` or `segment_id` | On writing: one of the store's Promotions segments, left out for every customer of the store. On a change: left out keeps the notice's audience; sent empty makes it every customer; an id makes it that segment |

**A change replaces the whole notice.** A field left out is not "unchanged", except the audience: a change sent
without `send_at` sends the notice at once.

| `NoticeData` | Type | What |
|---|---|---|
| `id`, `storeCode` | string | |
| `titleAr`, `titleEn`, `bodyAr`, `bodyEn` | string | As written |
| `link` | string or null | As stored, without store and language |
| `byBell`, `byEmail` | bool | |
| `status` | string | `SCHEDULED`, `SENDING`, `SENT`, `CANCELLED` or `FAILED` (a batch failed its last attempt) |
| `sendAt` | moment | When it sends, or was sent |
| `sendAtLocal` | string | The same in the store's time zone, `YYYY-MM-DD HH:MM`, as `send_at` takes it |
| `changeable` | bool | True only while `SCHEDULED` |
| `bellCount`, `emailCount`, `failedCount` | int | Bell entries made, emails sent, emails failed so far |
| `createdAt` | moment | |
| `segmentId` | string or null | Null: every customer of the store |
| `segmentNameAr`, `segmentNameEn` | string or null | The segment's name now. **Null with `segmentId` set: it was deleted since**; null with `segmentId` null: every customer |
| `segmentMemberCount` | int or null | How many it holds **now**, not how many the notice reached. Null as the name is |
| `writtenBy` | string | As `AccessApi::staffDisplayNames` names them to this reader |
| `startedAt`, `finishedAt` | moment or null | When it started and finished sending. `finishedAt` is set only once `SENT` |

**The cost on a list:** `PromotionsApi` reads one segment at a time and none of several, so each different segment on a page is one read, and one named by many rows is read once. Measured, caches warm: the notices or the staff messages list 11 queries with no segment, 31 with 20 different ones (`SegmentReadsTest`).

Refusals: `ops.notice_not_found` 404, an unknown id, **or a notice of a store the reader does not hold**, the same
answer for both, as the sibling modules give (module pass, 2026-11-01); someone holding `ops.notice.manage` nowhere
is 403 first. `ops.notice_already_started` 409, a change or cancel once it
is not `SCHEDULED`; cancelling twice is refused the same way. `promotions.segment_not_found` 404, a segment
unknown or another store's. `promotions.invalid_promotion_attribute` 422, a `segment` that is no id. 422 keys: `title_ar`, `title_en`, `body_ar`,
`body_en`, `link`, `send_at` (not a date and time, or not to come), `channels`, `audience` (a store with no
customers, a segment with no member), `after`.

### 4.5 The subscribers list and remove (§1.9)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.subscribers` | GET `admin/newsletter/subscribers` | `ops.newsletter.manage` in the store | `{subscribers: SubscriberData[], next: string or null}` |
| `ops.admin.subscribers.remove` | POST `admin/newsletter/subscribers/{subscriber}/remove` | `ops.newsletter.manage` in the subscriber's store | 204, no body |

Inputs: `store` (code) and `after` on the list.

| `SubscriberData` | Type | What |
|---|---|---|
| `id` | string | |
| `email` | string or null | **Null for a customer's row when the reader may not view customers in that store** (`AccessApi::customerViewStores`); a guest's is always shown |
| `locale` | string | `ar` or `en` |
| `kind` | string | `GUEST` or `CUSTOMER` |
| `confirmed` | bool | A guest still waiting is listed too, false; a customer is always true |
| `createdAt` | moment | |
| `confirmedAt` | moment or null | |

Only a `GUEST` can be removed; staff never set a customer's consent (§1.3). Refusals:
`ops.subscriber_not_found` 404, an unknown id, a `CUSTOMER` row, or a guest of a store the reader does not hold, the
same answer for all; someone holding `ops.newsletter.manage` nowhere is 403 first. 422 key: `after`.

### 4.6 The reports page (§1.10)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.reports.show` | GET `admin/reports/{report}` | Active staff; `ops.report.view` in the store; Stock also `inventory.stock.manage` there; `all` a super admin's alone | `ReportPreviewData` |
| `ops.admin.reports.download` | GET `admin/reports/{report}/download` | The same | The `.xlsx` file, as an attachment |

| Input | Rules |
|---|---|
| `{report}` | A `ReportKind`: `revenue`, `product-sales`, `stock`, `searches` or `favourites`; which this reader may open is in `ops.admin.choices` |
| `store` | The store's id, or `all` |
| `from`, `to` | `YYYY-MM-DD`, both included, both required, read in each store's own time zone. **Stock and Favourites ignore them**: they are as they stand now |
| `by` | `day` (or left out) or `month`. Only Revenue reads it |

| `ReportPreviewData` | Type | What |
|---|---|---|
| `report` | string | The kind |
| `locale`, `rightToLeft` | string, bool | **The reader's account language**, not the page's: `ar` is right to left |
| `title`, `subtitle` | string | In that language; the subtitle names the store (or all stores) and the days, or the moment |
| `sheets` | `ReportSheetData[]` | Revenue has four, the others one |
| `filename` | string | The file's name, Latin letters |
| `downloadUrl` | string | A path from the site's root, the same choices, to `…/download` |

`ReportSheetData` is `{name: string, columns: ReportColumnData[], notes: string[], rows, totals, more: bool}`. `columns`
are `{key: string, label: string, type: string}`, type `text`, `count`, `money`, `day` or `month`. `rows` is a list
of lists, **one value per column, in the columns' order**, the sheet's first 50 and never a total. `totals` has the same
shape and holds **every total row, however long the sheet** (owner, 2026-11-02, §9.4 Q21); it is empty on a sheet with no
total **and on a sheet with no rows, which shows no zero total** (owner, 2026-11-02), though the file writes one. `notes` says
what the figures are (the first says "until" when the range includes today). `more` is true when the file holds rows
past the preview's 50; totals never count towards it.

**No sheet is read past the 51st row.** A sheet that ends within its rows gives the totals it summed. One that goes on
reads them apart, to the unit what the file writes: Revenue and By category from the `revenueByDay` already read, no
query; Money received from `moneyOn`, 2 queries a store; Product sales from `productSalesTotals`, 1 a store. So the
queries do not grow with the report's length, with one exception that is not new: By category groups a store's products
under their categories before its first row, so it walks that store's products a thousand a page.

| Sheet, in order | Columns: key (type) |
|---|---|
| Revenue | `store` (text), `day` (day) or `month` (month), `currency` (text), `orders` (count), `revenue` (money), `revenue_if_waiting` (money), `vat` (money), `vat_if_waiting` (money), `shipping` (money), `refunds` (money), `waiting_refunds` (money) |
| Money received | `store` (text), `day` or `month`, `currency` (text), `way` (text), `payments` (count), `received` (money), `refunds_made` (count), `refunded` (money) |
| Waiting refunds | `store` (text), `asked_on` (day), `return` (text, the return's id), `order` (text, its number), `currency` (text), `estimate` (money), `estimate_vat` (money) |
| By category | `store` (text), `category` (text), `currency` (text), `revenue` (money), `revenue_if_waiting` (money) |
| Product sales | `store` (text), `product` (text), `size` (text), `code` (text), `currency` (text), `units` (count), `sold` (money) |
| Stock | `store` (text), `product` (text), `size` (text), `code` (text), `in_stock`, `returned_extra`, `held`, `available`, `threshold` (count), `limited_by_stock`, `low`, `out`, `archived` (text) |
| Searches | `store` (text), `language` (text), `phrase` (text), `searches`, `no_results` (count) |
| Favourites | `store` (text), `product` (text), `archived` (text), `customers` (count) |

Refusals, in the order they are met: 422 key `report`, not a report. 403, staff not active. 422 key `store`,
missing or no store. 403, the permission, or `all` for anyone but a super admin. 422 keys `from`, `to` (not a day;
`to` before `from`). `ops.report_range_refused` 422, whose `detail` says the rule in words: `years` (before
2020 or after next year), `too_long` (more than five years), `searches_too_long` (more than twelve months of
searches). 422 key `by`. A plain visit to the download address gets these as the panel's error page.

### 4.7 The daily reports page (§1.11)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.daily-reports` | GET `admin/daily-reports` | Active staff with `ops.report.daily` in at least one store | `{reports: DailyReportData[], next: string or null}` |
| `ops.admin.daily-reports.make` | POST `admin/daily-reports` | `ops.report.daily` in the store; every store's a super admin's alone | 201 `DailyReportData` |
| `ops.admin.daily-reports.pdf` | GET `admin/daily-reports/{report}/pdf` | `ops.report.daily` in the report's store; every store's a super admin's alone | The PDF, as an attachment |
| `ops.admin.daily-reports.emails` | GET `admin/daily-reports/emails` | As the list | `DailyReportEmailData[]`, a bare list, the reader's own |
| `ops.admin.daily-reports.emails.set` | POST `admin/daily-reports/emails` | As make | `DailyReportEmailData[]`, the whole list after it |

The list shows every report of the reader's stores, whoever made it; every store's reports to super admins alone.

| Input | Rules |
|---|---|
| `after` | List only |
| `store` | Make and email switch: the store's id; left out or empty, every store's |
| `day` | Make only, optional: `YYYY-MM-DD`, a day before today where the report is read and in every store it covers. Left out: today until now |
| `on` | Email switch: a boolean (`true`, `1`, `"1"`, `"on"`, `"yes"`, `"true"`); left out is off |
| `sendAt` | Email switch: `HH:MM`, 00:01 to 23:59 (00:00 is refused, §7). **Required when switching off too**: a switch off with no time is refused |

| `DailyReportData` | Type | What |
|---|---|---|
| `id` | string | |
| `storeId`, `storeCode` | string or null | Null: every store's. `storeId` is what make takes |
| `storeName` | string | In the page's language; every store's reads as the words for "All stores" |
| `day` | string | `YYYY-MM-DD`: the store's own, or the base store's for every store's |
| `pastDay` | bool | |
| `madeBy` | string | As `AccessApi::staffDisplayNames` names them to this reader; empty if not found |
| `madeAt` | moment | |
| `emailedAt` | moment or null | When the scheduled email took it; null for one made on the button |
| `language` | string | `ar` or `en`: the PDF's, the maker's account language |
| `bytes` | int | The file's size |
| `downloadUrl` | string | A **full address**, unlike a report's |

| `DailyReportEmailData` | Type | What |
|---|---|---|
| `storeId`, `storeCode` | string or null | Null: the email of every store's report |
| `storeName` | string | As above |
| `on` | bool | |
| `sendAt` | string | `HH:MM` |
| `timezone` | string | The zone `sendAt` is read in: the store's, or the base store's for every store's |
| `nextDueAt` | moment or null | When it goes next; null while off |

An empty list means no email was ever set; the default is off. Making a report takes as long as the PDF does, in
the request; nothing is queued. The file opens as it was made, whatever later edits do to that day (§1.10).

Refusals: 403, no permission. `ops.daily_report_not_found` 404 on a download: unknown, not the reader's to open
(which is **not** 403 here), or its file gone. 422 keys: `store` (no such store), `day` (not a date, or not a past
day), `send_at` (the time; note the key is `send_at` though the input is `sendAt`), `after`.
`ops.daily_email_at_midnight` 422, `sendAt` 00:00.

### 4.8 The customer's consent switch (§1.3): Access's, not Ops'

The switch lives in **Access** (amendment 79); Ops only reads it. Staff never set it.

| Route | Method, path | Who | Takes | Answers |
|---|---|---|---|---|
| `storefront.account` | GET `{store}/{locale}/account` | Signed-in customer | — | Access's Inertia page; `marketingConsent` (bool) in `CustomerAccountPage` |
| `storefront.account.marketing` | POST `{store}/{locale}/account/marketing` | Signed-in customer, `access.account.update` (`SetOwnMarketingConsentHandler`) | `marketing_consent`, required, boolean | A redirect to the account's profile tab with "Changes saved" |
| `storefront.account.register` | POST `{store}/{locale}/account/register` | A visitor | `marketing_consent`, optional, boolean; left out is off | Access's registration |

Refused: the field missing or not a boolean comes back in the form's errors under `marketing_consent`
(`MarketingConsentRequest`). One account has one switch, whichever store it is changed in (§1.3).

### 4.9 The shop's newsletter box (§1.9)

| Route | Method, path | Who | Takes | Answers |
|---|---|---|---|---|
| `storefront.ops.newsletter.subscribe` | POST `{store}/{locale}/newsletter` | Anyone but a signed-in customer; no permission | `email`: 3 to 254 characters, a valid address, kept in small letters | 202 `{sent: true}` |

The list's language is the address's `{locale}`. **202 means the same whether the email was on the list or not**:
the box never tells who subscribed, and the person is on the list only after the link (§4.10).

Refusals: `ops.subscribe_in_your_account` 409, a signed-in customer or an email that belongs to an account. 422
key `email`. **429 `{retryAfter: int}`**, seconds, not a problem document: more than 5 sign-ups an hour for one
email, or 20 from one address; a refused attempt counts too. `ops.newsletter_unavailable` 503 when the provider did not
take the confirmation email; the sign-up stays and the person may try again. The box's words are in `ops::newsletter.box`
(`title`, `text`, `email`, `action`, `sent`; approved for now, 2026-11-01), for the page to name that file.

### 4.10 The confirmation page (§1.9)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `storefront.ops.newsletter.confirm` | GET `{store}/{locale}/newsletter/confirm/{token}` | Whoever holds the link | `{token, title, text, action, done}`, strings, the words in the page's language (`ops::newsletter.confirm`). **Changes nothing**: mail scanners open links too |
| `storefront.ops.newsletter.confirm.post` | POST the same path | The same; CSRF token needed | `{confirmed: true}` |

`{token}` is 1 to 128 letters and digits; any other address does not match the route (404). Refused:
`ops.confirmation_link_invalid` 404, unknown, used, or over 7 days old, the same answer for all.

### 4.11 The leave and unsubscribe pages (§1.3, §1.9)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `storefront.ops.newsletter.leave` | GET `{store}/{locale}/newsletter/leave/{subscriber}` | A valid signature (`signed:relative`), no sign-in | `{title, text, action, done}`, strings in the page's language. **Changes nothing** |
| `storefront.ops.newsletter.leave.post` | POST the same path, with the same `signature` | The same; no CSRF token | `{unsubscribed: true}`, a guest taken off |
| `storefront.ops.unsubscribe` | GET `{store}/{locale}/unsubscribe/{customer}` | The same | `{title, text, action, done}`. **Changes nothing** |
| `storefront.ops.unsubscribe.post` | POST the same path, with the same `signature` | The same; no CSRF token | `{unsubscribed: true}`; also the mail client's one-click POST (RFC 8058) |

`action` is the one button's label, `done` the line after it (`ops::unsubscribe`). The POST must carry the query
string the page was opened with. Refused: a missing or wrong signature is 403 (`http.403`), **on any store code**, on,
off or unknown, so it reveals nothing. Pressed twice, or for a row already gone, still answers `{unsubscribed: true}`:
nothing tells the two apart.

**These four routes answer for a switched-off store too** (owner, 2026-11-01), at the same addresses. They run outside
the shop's middleware, with no `store` context, no signed-in customer and no shop data (`StopEmailStore` sets the
language alone and remembers no store in a cookie): a page built on them cannot use the shop's frame or `layout`
props. Every other address of that store stays a 404.

### 4.12 What a screen must not assume

- **A `money` cell is not always a number.** In the preview it is a decimal string (Latin digits, no grouping, the
  currency's own decimals, **possibly negative**: a day of refunds and no sales), or null. `ColumnType::Money` also
  allows a text where a figure waits for a module, and the Excel writer prints one as text; no sheet writes one
  today. Show a value that is not a decimal as it stands; never add up what comes back.
- **Null is not 0.** `revenue_if_waiting` and `vat_if_waiting` are null where nothing waits: one figure, not two
  (§1.10). A `day`, `month`, `category` or `product` cell is null on a total row.
- **A total row is in `totals`, never in `rows`.** It holds the word for "Total" in `store` and null where it has no
  period or name: tell one by where it is, never by that word, which a store or a category may be called too.
  Revenue, Product sales and By category have one total per currency, a currency that sold nothing at zero; Money
  received has one per currency and way that moved. Waiting refunds, Stock, Searches and Favourites have none.
- **A sheet with no rows has no `totals` at all** (owner, 2026-11-02): it shows only "Nothing for these days." The file
  still writes its zero totals.
- **The totals are in the preview whatever `more` says;** only the rows past the 50 wait in the file. Their figures are
  the file's to the unit. With `more` true, Money received's may come in another order of ways than the file's.
- **Read a row by column position**; a sheet has no key, only its name in the reader's language, so tell sheets
  apart by order. Column keys are stable; labels are not.
- **Words are words:** `limited_by_stock`, `low`, `out`, `archived` hold the reader's "Yes" or "No", never a boolean;
  `way` and `language` are words too, and `store` carries "(switched off)" in the name. `product`, `size` and `code`
  are empty when Catalog no longer knows the size. A `phrase` is what a shopper typed and may begin with `=`.
- **Money has its currency beside it**, in the row's `currency`; an all-stores report is never converted or summed
  across currencies. A past day's figures may change on the next read; a kept PDF never does (§1.10).
- **The daily report's figures are not in the JSON.** The list gives the file's facts only; "Transfers overdue"
  inside the PDF reads "Waits for Payments (stage 7)" for now. "Transfers waiting" is as it stands now, labelled so on
  a report read after its day.
- **Two kinds of download address:** a report's `downloadUrl` is a path, a daily report's a full address.
- **The language differs by route:** a bell's and the unsubscribe pages' words follow the page; a report's words and
  a daily report's PDF follow the reader's account.
- **`kind` is an open list:** C11, S6 and, once Content fires its event, S8 will start appearing without any change here.
- **`count` on `ops.admin.audience` is the bell's reach, never the email's** (§4.1): a send by email alone reaches fewer, and its confirmation says so in its own words (O4).
- **A segment's name can be gone:** `segmentId` set with `segmentNameAr` null means it was deleted since the notice or message was written. Its `segmentMemberCount` is today's, and never the audience it reached.
- **Ids come in small letters;** send them back as given.

### 4.13 Missing for the screens

Listed, not invented. Each is for the owner or the screens agent to raise. Brought up to date 2026-11-01, when the
reads the screens needed were added; what stays is what the code cannot give yet.

1. **No pages.** No Inertia page, `AdminMenu` entry, Home card or account tab exists for Ops, and the bell is in no
   frame. The three links emails carry (§4.10, §4.11) open JSON until those pages exist. A stop-email page for an off
   store cannot use the shop's frame (§4.11). **Specified in §4.15 (owner, 2026-11-02); leaves this list as each is built.** Built: SMS Countries (#322), the two bells and their full pages, Customer Messages and Offer Notices with their forms.
   The Home card goes with the Home dashboard job (§9.4 Q5).
2. **The consent box is on no page.** Outside the generated types, `resources/js` holds neither `marketingConsent` nor
   `marketing_consent`: the account's switch and the registration box have routes and no screen. **§4.15, O12.**
3. **The lists have no search, filter or total.** Subscribers by email or kind, notices by status, daily reports by
   store, staff messages by audience: none exists, and the bell's number is the only count. **Not now** (owner, 2026-11-01).
4. **No screen ties a notice to a campaign.** Sending it when the campaign goes live is built (§1.7), and waits on Content: to
   fire `CampaignWentLive`, and to hold the notice's id (§9.5). S8 has no link until Content has a campaign page (§1.6).

### 4.14 The SMS countries (§3; access.md amendment 90)

| Route | Method, path | Who | Answers |
|---|---|---|---|
| `ops.admin.sms-countries` | GET `admin/sms-countries` | `ops.sms_countries.manage`: Super Admins alone | `SmsCountriesData` |
| `ops.admin.sms-countries.add` | POST `admin/sms-countries` | The same | 201 `SmsCountriesData` |
| `ops.admin.sms-countries.provider` | POST `admin/sms-countries/{country}/provider` | The same | `SmsCountriesData` |
| `ops.admin.sms-countries.calling-code` | POST `admin/sms-countries/{country}/calling-code` | The same | `SmsCountriesData` |
| `ops.admin.sms-countries.remove` | POST `admin/sms-countries/{country}/remove` | The same | `SmsCountriesData` |

Inputs: `country_code` (ISO, two letters), `calling_code` (digits, as typed), `provider` (one of `providers`); `{country}`
is the ISO code. `SmsCountriesData` is `{countries: SmsCountryData[], providers: string[]}`, the whole list after the
change, sorted by name as the page's language sorts; `SmsCountryData` is `{countryCode, name, callingCode, provider}`, the
name in the page's language. Every change is audited (`ops.sms_country.*`). The page is §4.15 O10: a plain visit gets it, and the JSON stays for whoever asks; a form the page sends (`X-Inertia`) goes back with its words, any other POST answers as above.

Refusals: `ops.sms_country_not_found` 404, a country not listed. `ops.sms_country_taken` 409, `ops.calling_code_taken` 409,
`ops.calling_code_overlaps` 409. 422 keys: `country_code`, `calling_code`, `provider`.

### 4.15 The screens (built by the screens session; owner's answers 2026-11-02, §9.4)

**[ACCEPTED by the owner, 2026-11-02.]** §4.1 to §4.14 say what the server gives; this section says what each page does
with it. Every choice marked Qn or Rn is the owner's answer in §9.4.

#### What holds for every page

- **Design.** shadcn's code under Geist's rules, in TouchWood's tokens (frontend.md §1.11). No colour or background
  outside the tokens: a campaign may change the shop's look later. **Neither system has a notification centre**
  (checked 2026-11-02: shadcn's component list and Geist's), so the bell is put together from shadcn's Button, Badge, Popover,
  Item and Scroll Area, with nothing hand-drawn.
- **Parts already in the project, used as they are:** `AdminLayout`, `StoreFilter`, `LoadMoreButton` with `useLoadMore`,
  `RowMenu`, `Note`, `Time`, `FormError`, `PanelDialog`, `useLeaveGuard`, `DateRangeField`, `DateTimeField`, the
  `use-checks` field checks.
- **Both languages, right to left in Arabic, Latin digits only** (typed digits go through `toLatinDigits`).
- **Phones.** Every page fits 375 × 812 with no sideways page scroll. Wide tables scroll inside their own card, as every list
  does. Every browser test checks this.
- **Who sees what.** A menu entry, button or store is offered only to someone who may use it; every handler still checks.
  Someone who loses a right while a page is open gets the refusal in words.
- **Addresses (Q1).** The same address gives the page to a plain visit and today's JSON to `Accept: application/json`. A form
  sent by the page goes back with its errors in words through `App\Http\FormErrors`; a screen's own read asking for JSON gets
  today's answer unchanged. This changes Ops' Presentation only: controllers, routes, words, and the tests asserting JSON on a
  plain GET. New addresses, all pages: `admin/reports`, `admin/notices/new`, `admin/notices/{notice}/edit`, `admin/messages/new`.
- **A store's page** says the store in its address (`?store=sa`) through `StoreFilter`, drawn only with two or more stores to choose
  from. It opens on the first store on offer that is on, as §4.1 says.
- **Status words,** never colour alone (Geist's Badge): Scheduled (blue), Sending (amber), Sent (green), Cancelled (grey),
  Failed (red).
- **No timers anywhere (Q7, Q15).** Nothing on these pages reads the server by itself on a clock. One read of the bell's number
  is 8 queries with a write (measured 2026-11-02), and any request resets the idle clock, so a timer would stop the 30-minute
  sign-out.
- **Words.** Written by the screens session in both languages and approved for now; the owner checks the whole site's wording
  later.

#### The menu (Q2, Q3, Q4, Q5)

| Section | Entry | Address | Offered with |
|---|---|---|---|
| **Reports**, new, after Customers | Reports | `/admin/reports` | `ops.report.view` |
| | Daily Reports | `/admin/daily-reports` | `ops.report.daily` |
| **Marketing**, after Gift Levels | Offer Notices | `/admin/notices` | `ops.notice.manage` |
| | Customer Messages | `/admin/messages` | `ops.message.send_one` or `ops.message.send_many` |
| | Subscribers | `/admin/newsletter/subscribers` | `ops.newsletter.manage` |
| **Store Settings**, after Places | SMS Countries | `/admin/sms-countries` | `ops.sms_countries.manage` |

No Home card now (Q5): it goes with the Home dashboard job. The sections' order lives in Platform's menu list, which gains `reports`,
named in Access's `permission_groups` words.

#### O1 · The panel's bell (Q6, Q7, Q9)

- **Where.** In `AdminLayout`'s top bar, before View Store: shadcn's ghost icon Button, with the unread number in a Badge, `99+` above
  99, no Badge at 0. Its name says the count: "Notifications, 3 unread".
- **The number comes with each page (Q7):** one query in the page's own request, carried by a new small Platform list of the panel
  frame's parts, as `ShopFrameParts` is for the shop. A partial reload does not ask for it. Every panel page gains that one query,
  recorded in `AdminFrameQueryBudgetTest` and the PR.
- **Coming back to the tab (Q7, staff only).** When the tab comes back into view after at least a minute away, the bell asks for the number
  once (`ops.admin.bell.unread`). A failed or signed-out answer changes nothing on screen.
- **Pressing it** opens shadcn's Popover, as wide as a phone allows: "Notifications", Mark All as Read while anything is unread,
  the newest 20 entries (one read of `ops.admin.bell`, which also corrects the number), and View All. Skeleton rows while it reads;
  "Couldn't load your notifications." with Try Again if it fails; "No notifications yet." when empty.
- **A row** is shadcn's Item: the title, the line cut at three lines, and the time (`Time`, relative). The store's name shows only for someone with two or more stores (Q9). An unread row has a
  sunken background, a heavier title and "Unread" for a screen reader.
- **Pressing a row** marks it read (the POST's answer gives the new number). With a `link` it goes there; a failed mark still goes.
  With no link it stays, and the row opens to show its whole text.
- **Mark All as Read** marks every entry, not only the ones shown. It asks nothing, since it loses nothing.
- **`/admin/notifications`,** the full page behind View All: the same rows, whole text, Show More, Mark All as Read at the top. No store filter (§4.13 #3).
  It has no menu entry. The trail is Home › Notifications.
- **Unknown kinds** need nothing: rows draw from `title` and `line`, so new kinds (§4.12) simply appear.

#### O2 · The shop's bell (Q6, Q7, Q8)

- **Where.** In the shop's header, before the cart, for a signed-in customer only: no bell for a guest or a staff view.
- **The number comes with each page (Q7),** through Ops' part of the shop's frame (`ShopFrameParts`): one query for a signed-in customer, none for a guest. No re-check on
  coming back to the tab. Signed-in shop pages go up by one, recorded in `ShopPagesTest` and the PR (the owner's rule on query
  targets).
- **Pressing it** works as O1, against `storefront.ops.bell`, with no store names: a customer's bell is the store being shopped.
- **`/{store}/{locale}/account/notifications`,** the full page in the account's frame, listed as **Notifications** in the account's menu
  beside the other modules' pages (Q8). Same rows, Show More, Mark All as Read. Reading it marks nothing.

#### O3 · Customer Messages: the list (Q13, Q15, Q16)

`/admin/messages`. The title, "What staff sent to customers. A message can't be recalled.", **New Message** at the top end,
`StoreFilter`, then the table, newest first, Show More.

| Column | What |
|---|---|
| To | The customer's name ("Unknown customer" when empty); "Every customer"; the segment's name in the page's language, "Deleted segment" when gone |
| Title | In the page's language |
| By | "Bell", "Email" or both |
| Status | The badge |
| Reached | Bell entries, emails sent, and emails failed when above 0, each labelled |
| Sent by | `sentBy` |
| Sent | `Time` |

- **Pressing a row (Q13)** opens a dialog, with no new read: both titles and bodies as written, the audience, the channels, the status, the counts,
  who and when. For a segment: "It holds :count members now" and never "reached".
- **Refresh (Q15).** While any row says Sending, a **Refresh** button shows above the table and re-reads the list. Nothing happens by itself.
- **Failed (Q16).** The red badge, and in the dialog "Part of it didn't send; retrying it on Failed Jobs carries on", with the link only when
  the person's menu holds Failed Jobs.
- **Empty.** "No messages yet." with New Message when the person may write. Someone holding `send_one` without viewing customers gets an
  empty list from the server (§4.3) and sees the same words.

#### O4 · New Message (Q10, Q11, Q12, Q14)

`/admin/messages/new`, its own page (Q10), reached from O3. There is no draft and nothing is kept: leaving with something typed asks first (`useLeaveGuard`).

1. **Store**, shown with two or more on offer (`messagesToOne` and `messagesToMany` together). Changing it clears the customer and the segment.
2. **Who gets it**, radios, only those allowed in that store: **One Customer** (`send_one`), **Every Customer of the Store**, **A Segment** (`send_many`). With one allowed it's written as a line, not a choice.
   - **One Customer:** a search box (shadcn Combobox pattern; `useLiveRead`) asking from 3 characters. Each row shows the name, email and phone. Under 3 characters it asks for more; nothing found says "No customers found. Only customers of this
     store you may view are here." Under the box always: "Not here? Type more of the name, or their email or phone." (the 200-match limit, §4.3). The chosen customer shows with Change.
   - **A Segment:** a picker of the store's segments (`ops.admin.messages.segments`), read when opened, newest first, Show More inside. Each shows its name and member count. **A segment with 0 members is listed, disabled, "No members".**
3. **The message (Q11):** Arabic and English side by side, stacked on a phone, in the language's own direction. Title 200 characters on one line, text 500, line breaks allowed, each with its rule under the box and checked as typed.
4. **How it goes:** Bell and Email, both ticked, at least one. Under Email, for one customer: "It goes to their email whatever they chose about offers." For many: "Email goes only to those who agreed to offers. The bell reaches everyone."
5. **Send Message.**
   - To one customer it sends at once (Q12).
   - To many it asks first (Q12), then "It can't be recalled.", Cancel and Send Message. **A whole store (owner, 2026-11-02), with Bell ticked:** "Send to :count customers of :store?" **Email alone:** "Send by email to those of the :count customers of :store who agreed to receive it?" A segment: "Send to :segment (:count members)?" With Bell off: "Send by email to those of the :count members of :segment who agreed to receive it?" The count is `§9.4 Q14`'s read for a whole store (`ops.admin.audience`, §4.1), taken when the dialog opens. A count of 0 says so and offers no Send.
   - The email count is never shown: it depends on consent.

After sending: back to O3 with the toast "Message on its way." Refusals land on their box in words; `audience` on Who Gets It; a segment gone (404) as the form's error, with the picker emptied.

#### O5 · Offer Notices: the list (Q13, Q15, Q16)

`/admin/notices`. "Offers told to a store's customers, now or at a set time.", **New Notice**, `StoreFilter`, the table, newest written first, Show More.

Columns: Title · Audience ("Every customer", the segment, "Deleted segment") · By · Status · Sends (`sendAt` in the store's zone: "Sent" once sent) · Reached · Written by.

- **Pressing a row** opens the same read-back dialog as O3, with the link and `sendAtLocal` and the zone.
- **Row menu** only while `changeable`: **Edit** (O6) and **Cancel Notice…**, which asks "Cancel this notice? It won't be sent." with Keep Notice and Cancel Notice. The row stays, Cancelled.
- **A notice that started meanwhile** answers 409: its words in a toast, and the list re-reads. A Refresh button shows while any row says Sending (Q15). Failed as O3 (Q16).
- **Empty.** "No notices yet." with New Notice.

#### O6 · New and Edit Notice (Q10, Q11, Q12, Q17)

`/admin/notices/new` and `/admin/notices/{notice}/edit`. Parts 3 and 4 of O4 as they are, plus:

- **Store:** chosen on New, written on Edit: a change never moves a notice.
- **Audience:** radios, **Every Customer of the Store** or **A Segment**, picker as O4. The form always sends the audience, so nothing is left to "keeps". A notice whose segment was deleted shows "This notice's segment was deleted. Choose another." and the send waits for a choice.
- **Link (optional, Q17):** "A page of the shop." A whole pasted shop address is cut down to the page part, and that part shows under the box. An address of another store, or another site, says so under the box. Either language's address works, because the shop redirects the other language's to the reader's (§9.4 Q17). The rules of §4.4 are checked as typed.
- **When:** `DateTimeField` with **Now**, in the store's clock with the zone named, `send_at` sent as `YYYY-MM-DD HH:MM`. New opens on Now. Edit opens on its `sendAtLocal`. A past time is said under the box.
- **The button:** **Send Notice** with Now, **Schedule Notice** with a time; on Edit **Save Changes** with a time, **Send Now** with Now.
- **Now asks first (Q12):** as O4's dialog, naming the store or segment and the count, a whole store in the same two wordings: "Send to :count customers of :store?" with Bell ticked, "Send by email to those of the :count customers of :store who agreed to receive it?" by email alone (owner, 2026-11-02). A scheduled save doesn't ask, and tells in the toast when it goes.
- **Edit replaces the whole notice** (§4.4): the form always sends every field, `send_at` included, so nothing starts sending by accident. A notice that started while the form was open answers 409: the words show, what was typed stays, and the page offers the way back to the list. Opening the Edit address of one that has already started goes to the list, with the same words.

#### O7 · Subscribers

`/admin/newsletter/subscribers`. "Who gets the newsletter. Newsletters are written and sent in Brevo.", `StoreFilter`, the table, Show More at 50.

Columns: Email · Language · Kind (Guest, Customer) · State (Confirmed, Waiting for Confirmation) · Joined · Confirmed.

- **A customer's hidden email** (null) reads "Hidden" with a tooltip: "You may not view this store's customers."
- **Row menu, guests only:** **Remove…**, "Remove :email from the newsletter?", Keep and Remove. A customer's row has no menu: the customer's own switch decides (§1.3).
- **Empty.** "No subscribers yet."

#### O8 · Reports (Q4, Q18, Q19, Q20, Q21)

`/admin/reports` goes to the first kind the person may open; `/admin/reports/{kind}` is the page. No kind offered: 403.

- **Tabs along the top:** the kinds in `ops.admin.choices`, each a link, its store list its own (Stock's may be shorter).
- **The choices:** Store (a select, All Stores first for a Super Admin, opening on the first store that is on, off stores marked Off); Days (`DateRangeField`, except Stock and Favourites, which stand as they are now and say so); By Day or By Month (Revenue only). Days open from the 1st of this month to today in that store's time (Q19). For this page `DateRangeField` says the days are read in each store's time, not UTC.
- **Show Report (Q18)** reads the preview and puts the choices in the address, so a reload or a shared link shows it again. **Download Excel** then appears beside it, a plain link to `downloadUrl`. Changing a choice leaves the shown report in place and marks it "Choices changed. Show Report again." and hides Download until shown again, so a file never differs from what's on screen.
- **The report:** its title, subtitle and `notes` as a plain `Note`. Its direction and language follow the reader's account, as the server sends them; when that differs from the page's, one line says so. Sheets as tabs (Q20), in order, kept apart by order not name.
- **The table:** `count` figures with Latin digits, `money` as it stands, right-aligned at the end with its currency in its own column, negative and non-decimal values untouched, null as "—". Nothing is added up on the screen. `phrase` shows as plain text.
- **Totals (Q21)** always show, bold, at the foot of the sheet, from the sheet's `totals` (§4.6, §9.4 Q21), which are empty on a sheet with no rows: it shows only "Nothing for these days.", no zero total (owner, 2026-11-02). With `more`: "Showing the first 50 rows. Every row is in the Excel file."
- **Before Show:** "Choose the store and the days, then Show Report." **An empty sheet:** "Nothing for these days."
- **Refusals** (`ops.report_range_refused` and the 422 keys) show in place, with the choices kept.

#### O9 · Daily Reports (Q22, Q23)

`/admin/daily-reports`, three parts:

- **Make a Report.** Store (select, All Stores for a Super Admin); Day: **Today So Far** or **A Past Day**, a calendar stopping at yesterday. **Make Report** shows it's working: the PDF is made during the request. Then the new row appears at the top with its Download, and the toast "Report ready." (Q22). No automatic download.
- **Daily Email.** One row per store offered, plus All Stores for a Super Admin. Each: the name (off stores marked), a Switch that saves as it's flipped, a 24-hour time box, the zone's name, and "Next: :time" while on. A row never set shows off at **21:00** (Q23). The time saves when the box is left, with the box's own "Saved" state. Under the box its rule; 00:00 is refused as typed, in the words of `ops.daily_email_at_midnight`. A failed save puts the switch back and says why. A time that isn't complete keeps the switch from turning on.
- **The list.** Columns: Store · Day (with "Past day") · Made by · Made · Language · Size · Emailed (`Time`, or "—") · **Download PDF**, a plain link to `downloadUrl`. Newest first, Show More. "No reports yet." A report's figures aren't on the page: they're in the file (§4.12).

#### O10 · SMS Countries (Q24)

`/admin/sms-countries`, Super Admins only. A plain `Note`: "Log writes codes to the server's log and sends nothing. A live system refuses it." **Add Country…** at the top end.

Columns: Country · Code · Calling Code (`+966`, left to right) · Provider · row menu.

- **Add Country…** a dialog: Country (`CountryCombobox`), Calling Code (digits only, `+` shown before the box), Provider (a select; one provider is chosen for you). Taken and overlapping calling codes show under their box in their own words.
- **Change Provider…** and **Change Calling Code…:** a dialog each, one field.
- **Remove…** asks: "Remove :country?" "Phones from this country can't get codes. Staff with one need an admin to change it. A Super Admin with one enters a new number at sign-in. Customers with one can't order until they change it." Keep and Remove.
- **Empty.** "No countries yet. Without one, no phone number can be entered." with Add Country.

#### O11 · The newsletter box (Q26)

In the shop's footer, on every page under a store, for guests only: no box for a signed-in customer or a staff view. It reads nothing: its words travel in Ops' part of the frame (`ops::newsletter.box`). Content may move it later.

Title, text, an email box and **Subscribe**, in a row, stacked on a phone. Sent as JSON:

| Answer | What the box does |
|---|---|
| 202 | Replaces the form with the `sent` line. It says the same whether or not the email was on the list |
| 409 `ops.subscribe_in_your_account` | The problem's words under the box, with a link to sign in |
| 422 `email` | The rule under the box; also checked as typed |
| 429 `{retryAfter}` | "Too many tries. Try again in :minutes minutes." |
| 503 `ops.newsletter_unavailable` | The problem's words; the email stays in the box |

#### O12 · The consent switch and box (Q25)

- **Account, Profile tab:** a card under the details, **Offers and News**, with a Switch labelled "Send me offers and news" and "By email. Messages about your orders and account still come." It saves as flipped through `storefront.account.marketing` and keeps the "Changes saved" toast; a failure flips it back with the words. With the email not confirmed: "These can only reach you once your email is confirmed."
- **Registration:** an unticked Checkbox with the same label, after the terms box, sending `marketing_consent`. It blocks nothing.

Both are Access's page and words (§4.8).

#### O13 · The confirm, leave and unsubscribe pages (Q27)

`…/newsletter/confirm/{token}`, `…/newsletter/leave/{subscriber}`, `…/unsubscribe/{customer}`. All three share one small frame, a new layout from shadcn's Card and our logo: centred, no menus, no cart, no switches, the page's language and direction from the address, and kept out of search engines. It reads no store, because an off store must work (§4.11).

- **The page:** the logo, `title`, `text`, the one button with `action`. Opening it changes nothing.
- **The button** posts to the same address with the query string it was opened with (§4.11), then the button gives way to the `done` line.
- **Confirm's refusal** (`ops.confirmation_link_invalid`, 404, on the button) shows its words in place of the button. Confirm alone adds **Go to the Shop** after `done`, its store being on; the other two offer no way on, because the store may be off.
- **A bad signature** stays the 403 page of §4.11 and says nothing about the store.
- **No JavaScript** isn't provided for: a mail client's one-click POST goes to the address directly and needs no page.

#### Shared files this touches

**Two additions to Platform, which every module's menu and panel page go through; accepting this spec accepts them:**

1. `InMemoryAdminMenu` gains a `reports` section, after `customers`, named in Access's `permission_groups` words. Nothing else moves. It only shows to someone holding a report permission. `AdminMenuTest`, which lists every entry a Super Admin is offered, changes with it.
2. A new small Platform list of the panel frame's parts, the panel's twin of `ShopFrameParts`, read in Access's `ShareAdminPage`. Ops' part is the bell's number, and Platform and Access never name Ops. Nothing else registers in it today. It adds one query to every panel page.

Also touched: `AdminLayout` (the bell), `StorefrontLayout` (the bell, the footer box), `AppSidebar`/`MenuIcon` (new icons). All of these are checked with the sessions "Stage 7 screens" and "Screens" before each is touched.

#### What this doesn't do

No search, filter or total on any list (§4.13 #3). No template editor, no newsletter editor (Brevo's). Nothing about a message can be recalled. No live push: a number or a count moves on the next page, on coming back to the tab (staff), on opening the bell, or on Refresh. Payment messages' links and S6, S8 and the rest come from the other sessions and need no screen change (§4.12).

#### Tests

Each job adds feature tests for the page answers, the JSON still answering beside them, and each permission's menu entry and refusal; and browser tests for the flows above, in both languages, with phone width checked after the page has settled. Recorded query counts go up by one on signed-in pages (Q7): `AdminFrameQueryBudgetTest`, `ShopPagesTest`, and any other test recording a frame's count.

## 5 · Tables (`ops` schema)

| Table | Holds |
|---|---|
| `ops.inbox_entries` | person (`customer_id` or `staff_id`, exactly one), `store_id`, kind, title and line (ar, en; at most 200 and 500 characters), link (a path inside the site, at most 2000), read_at, created_at |
| `ops.sent_messages` | kind, person (`customer_id` or `staff_id`, at most one: a message to an address alone names nobody), `store_id` (none outside a store), channel (`EMAIL`, `SMS`), provider_id, status (`QUEUED`, `SENT`, `FAILED`), created_at — no codes or links ever (access.md §2.3), and no column for a text or an address |
| `ops.handled_events` | `event_id` and `listener`, one key (one event may have two listeners: `OrderPlaced`'s C1 and S1), handled_at — every listener runs once per event |
| `ops.notices` | `store_id`, title and body (ar, en; at most 200 and 500 characters), link (a page of the shop, at most 500), `segment_id` (none: every customer of the store; no foreign key across modules), by_bell, by_email, send_at, status (`SCHEDULED`, `SENDING`, `SENT`, `CANCELLED`, `FAILED`), `cursor_type` (`INDIVIDUAL`, `COMPANY`, `SEGMENT`) and `cursor_after` (where the next batch starts), bell_count, email_count, failed_count, created_by, created_at, updated_at, started_at, finished_at |
| `ops.staff_messages` | `store_id`, `sender_id`, audience (`ONE`, `STORE`, `SEGMENT`), `customer_id` (set only for `ONE`), `segment_id` (set only for `SEGMENT`; no foreign key across modules), title and body (ar, en), by_bell, by_email, status (`SENDING`, `SENT`, `FAILED`), `cursor_type` (`INDIVIDUAL`, `COMPANY`, `SEGMENT`), `cursor_after`, bell_count, email_count, failed_count, created_at, finished_at |
| `ops.subscribers` | `store_id`, email (in small letters, one row per email in a store), locale, kind (`GUEST`, `CUSTOMER`), `customer_id` (null for a guest; a customer on one list only), `token_hash` (the confirmation token's hash, a waiting guest's only), created_at, confirmed_at, `brevo_email` and `brevo_locale` (what the provider holds), brevo_synced_at. A guest whose email belongs to a customer is told to tick the box in their account instead |
| `ops.newsletter_lists` | `store_id`, locale, `brevo_list_id`, created_at — one Brevo list per store and language, made the first time a contact needs it |
| `ops.daily_reports` | `store_id` (none: every store's), day, past_day, made_by, made_at, locale, file, bytes, emailed_at, `email_setting_id` and `email_due_at` (the email's report: one per setting and moment; none on the button's) |
| `ops.daily_report_settings` | `staff_id`, `store_id` (none: all stores; one row per person per store, and one for all), is_on, send_at (`HH:MM`, never 00:00), version, next_due_at (set exactly while on), updated_at |
| `ops.sms_countries` | `country_code` (ISO), `calling_code` (typed, unique), provider, created_at, updated_at — **[ACCEPTED — stage 8, Ops step 3; owner 2026-10-29]**, access.md amendment 90 |

Deleted after 12 months: inbox entries, sent messages, handled events, daily report files (owner, 2026-10-28), and
**notices and staff messages sent, cancelled or failed** (owner, 2026-11-01). Unconfirmed guests: after 7 days (from
created_at).

As built: inbox entries and sent messages by created_at, handled events by handled_at (`PruneOpsLog`, 04:30
Riyadh); staff messages of status `SENT` or `FAILED`, and notices of status `SENT`, `CANCELLED` or `FAILED`, by
created_at, with unconfirmed guests (`PruneWrittenMessages`, 04:40); a daily report's row and file by made_at
(`PruneDailyReports`, 04:50). A notice still waiting, and a notice or a staff message still sending, is kept.

Each email of a notice or staff message has its `ops.sent_messages` row written `QUEUED` with its batch, as an event's
does, the row and its job in one transaction (`beforeCommit`), so a crash after the commit leaves no row waiting with no
job (module pass, 2026-11-01); nothing of theirs goes in `ops.handled_events` any more.

## 6 · Events

**Ops publishes none.** It listens to the events of §1.5–§1.6, and to Access's **`CustomerAnonymized`**: the
customer's subscriber row and Brevo contact are deleted, and their inbox entries and messages addressed to
them are deleted. Also, to keep the Brevo list current (§1.9): **`CustomerRegistered`**, **`GuestBecameCustomer`**,
**`MarketingConsentChanged`**, **`CustomerLocaleChanged`** (access.md amendment 79), **`CustomerEmailVerified`**,
**`CustomerBlocked`**, **`CustomerUnblocked`**, **`CustomerDeletionScheduled`** and **`CustomerDeletionCancelled`**.
Every listener (not C18) is **queued** and **idempotent by `eventId`**, never writes
another module's tables, and never blocks the sender (Stage 5's rules for Sync).

## 7 · Errors

Each is a `DomainError`, answered by `ProblemDetails` (§4.1): its type, its status, and its title and detail in
both languages at `ops::errors.{snake}`. `Tests` names the file in `tests/Modules/Ops` that drives it.

| Type | Status | When | Tests |
|---|---|---|---|
| `ops.invalid_ops_input` | 422 | **A notice with no channel** (`channels`); **a schedule in the past**, or no date and time (`send_at`); **an audience that is empty**: a store with no customer, a segment with no member (`audience`); a text empty or too long (`title_ar`, `title_en`, `body_ar`, `body_en`); a link that leaves the shop (`link`); a message to a customer unknown, anonymized or out of reach (`customer`); not an email (`email`); a cursor that is no id (`after`); a report or store that is not one, days that are not days, a grouping not day or month (`report`, `store`, `from`, `to`, `by`); a past day that is not one (`day`); an SMS country, calling code or provider that is not one (`country_code`, `calling_code`, `provider`). Also the domain's last line for what no request can send (`kind`, `person`, `channel`, `locale`, `token`, `file`, `version`, …) | `NoticesTest`, `NoticeSegmentTest`, `StaffMessagesTest`, `NewsletterTest`, `ReportsTest`, `DailyReportTest`, `DailyReportEmailTest`, `BellsTest`, `SmsCountriesTest`, `OpsTablesTest` |
| `ops.daily_email_at_midnight` | 422 | **A daily report email set for 00:00** (owner, 2026-11-01), on or off, a store's or every store's: the day would have only just started | `DailyReportEmailTest` |
| `ops.report_range_refused` | 422 | **More than 12 months of searches** (`searches_too_long`); more than five years (`too_long`); a year before 2020 or after next year (`years`). The rule's own words in `detail` | `ReportsTest` |
| `ops.inbox_entry_not_found` | 404 | A mark on an entry unknown, another person's, or of a store the bell does not show | `BellsTest`, `BellRoutesTest` |
| `ops.notice_not_found` | 404 | An unknown notice, or one of a store the reader does not hold | `ScreensReadsTest` |
| `ops.notice_already_started` | 409 | Changing or cancelling a notice once sending started, or one cancelled | `NoticesTest`, `WrittenRoutesTest` |
| `ops.subscriber_not_found` | 404 | Removing a subscriber unknown, a customer's row, or a guest of a store the reader does not hold | `NewsletterTest`, `ScreensReadsTest` |
| `ops.subscribe_in_your_account` | 409 | The box given an account's email, or used by a signed-in customer | `NewsletterTest`, `WrittenRoutesTest` (the email; the signed-in customer is untested over HTTP) |
| `ops.confirmation_link_invalid` | 404 | A confirmation link unknown, used, or over 7 days old | `NewsletterTest`, `WrittenRoutesTest` |
| `ops.daily_report_not_found` | 404 | **A daily report the person may not open**, unknown, or its file gone | `DailyReportTest`, `DailyReportRoutesTest` |
| `ops.sms_country_not_found` | 404 | A country not on the list | `SmsCountriesTest` |
| `ops.sms_country_taken` | 409 | A country already listed | `SmsCountriesTest` |
| `ops.calling_code_taken` | 409 | A calling code another country has | `SmsCountriesTest` |
| `ops.calling_code_overlaps` | 409 | A calling code that starts another listed one, or starts with one (owner, 2026-10-30) | `SmsCountriesTest` |
| `ops.newsletter_unavailable` | 503 | The newsletter box: the provider did not take the confirmation email. "Try again" for the person; the provider's failure itself is reported to the logs | `ScreensReadsTest` |

Other modules' errors a request here can meet: `shared.unauthorized` 403, the permission not held, including
**making a daily report for a store the person may not view** (`DailyReportTest`, `NoticesTest`, `ReportsTest`,
`StaffMessagesTest`); `promotions.segment_not_found` 404, a segment unknown or another store's (`NoticeSegmentTest`);
`promotions.invalid_promotion_attribute` 422, a segment that is no id (no test); `access.invalid_access_attribute`
422, a composer search too short or too long (no test here). The newsletter box's 429 `{retryAfter}` is the
controller's own (`WrittenRoutesTest`, the limit by email), and a bad unsubscribe signature is the framework's 403
(`WrittenRoutesTest`).

**A provider failure** is `ProviderFailed`, not a `DomainError`, naming no address or text; it is never lost
silently, and where it ends depends on the path:

| Path | What happens | Tests |
|---|---|---|
| An event's email or SMS (`SendEventEmailJob`, `SendEventSmsJob`) | Retried by the queue; the `ops.sent_messages` row stays `QUEUED`, then `FAILED` after the last attempt | `EventMessagesTest` |
| A notice's or message's email (`SendBroadcastEmailJob`) | The provider is called outside any transaction. Retried; after the last attempt the row is `FAILED` and counted in `failed_count`, once; retried from the failed jobs and sent, no longer counted | `NoticesTest` |
| A notice's or message's batch (`SendBroadcastBatchJob`) | After the last attempt the row shows `FAILED` and the job waits on the failed jobs screen; retrying it carries on | `NoticesTest` |
| C18, security messages (`OpsSecurityMessages`) | Never queued: the row is recorded `FAILED` and the error goes to the caller, who offers "send again". It also alerts the Super Admins (S10, S10b) | `SecurityMessagesTest`, `SecurityFailureAlertTest` |
| S10, S10b, the alert itself (`SecurityFailureAlert`) | Reported and swallowed, never reaching the person's request; the claim of the hour goes with the failed write, so the next failure tries again. Giving up after 250 ms on another's claim is not a failure: they are telling them | `SecurityFailureAlertTest`, `SecurityFailureAlertRaceTest` |
| The newsletter's confirmation (`NewsletterSignUps`) | Never queued: recorded `FAILED`, the failure reported to the logs, answered as `ops.newsletter_unavailable` 503 (§4.9); the person may try again | `NewsletterTest`, `ScreensReadsTest` |
| The provider's lists (`SyncSubscriberJob`, `RemoveNewsletterContactJob`) | Retried; after the last attempt the job waits on the failed jobs screen | `BrevoNewsletterListTest` (the adapter's refusals) |
| The daily report's email (`DeliverDailyReportJob`) | Tried again, then left in the failed jobs; the report itself is made once | No test of the retries by name |
| The daily report's making (`SendDailyReportEmailJob`), any failure, not only a provider's | One try, then the failed jobs; retrying makes that moment's report once (§1.11) | `DailyReportEmailTest` |

## 8 · Test scenarios

Each with where it is tested, in `tests/Modules/Ops` unless named.

| Scenario | Where |
|---|---|
| Every kind of §1.5 and §1.6 is listed once, with its channels, and worded in both languages with the same placeholders | `Integration/MessageKindsTest` |
| C4's Track: only with a stored link, beside the number, in the email alone, in both languages | `Integration/EventMessagesTest`, "offers Track" |
| C1–C8b, C9, C9b, C10, C12, C13, C15–C17b each send on their event: the bell entry in the event's store, one email, recorded. A voided, withdrawn or no-longer-standing moment sends nothing; a read naming another store or customer sends nothing and logs ids only | `Integration/EventMessagesTest`, "messages to customers" |
| **Once per event, a duplicate sends nothing**: C2, C9b, C10, C15 by name; the mechanism for every listener; a failure keeps no record, so it runs again | `Integration/EventMessagesTest`, `Integration/PruneTest` |
| **In the person's language**: an Arabic customer, an Arabic staff member, the bell opening their own page | `Integration/EventMessagesTest` |
| An email's row: QUEUED, then SENT; a retry of a SENT row sends nothing; a provider's failure keeps it QUEUED, then FAILED; a listener rolled back leaves nothing | `Integration/EventMessagesTest`, "each email and SMS" |
| C14: one message a day per customer, a week ahead, in every store's own day, whatever runs it again | `Integration/EventMessagesTest`, "the reminder before points expire" |
| C18: codes by SMS through the phone's country's provider, links by email, no code or link in any record, a failure reported to the caller | `Integration/SecurityMessagesTest` |
| S5 on `GatewayKeyExpiring`, S5b on `GatewayKeyRejected`: to super admins alone, once per event, the email by their switch; S5b says which key, in both languages | `Integration/EventMessagesTest`, "the critical SMS" |
| S8 on `CampaignExpiring`: Super Admins and the store's admins alone, once per event, by their switches, the campaign's name and the store's own day in both languages, no link; **nothing when the read answers null**, names another store, or is gone when the email leaves | `Integration/CampaignEventsTest` |
| The going-live notice on `CampaignWentLive`: a waiting notice starts by the same batches, once; replay, a second event and its own time send nothing more; **no notice, one gone, cancelled, sending, sent, failed or another store's sends nothing**; both events through the outbox, ids only | `Integration/CampaignEventsTest` |
| S1, S2, S3, S4, S9 to the holders of the permission in the store; **staff switches respected**: the bell on by default, email only when switched on; off stores alerted | `Integration/EventMessagesTest`, "alerts to staff"; `tests/Modules/Access/Integration/StaffAlertsTest` |
| S10, S10b: one bell entry per active Super Admin for a burst, each channel with its own hour, the next in the next hour; a waiter gives up, or takes the claim when the holder rolls back; no email, no SMS; Admins and inactive Super Admins not reached; every switch off and still reached; the failed message's store, else the first, off or on; nothing of the message in the words; a failure writing it reported and not thrown; after the caller's commit, nothing on its rollback | `Integration/SecurityFailureAlertTest`, `Integration/SecurityFailureAlertRaceTest` |
| A form's segments: the store's own, empty ones listed with 0, paged, under each form's permission; the name and member count on a notice and a staff message, null once deleted; the queries each costs | `Feature/SegmentReadsTest` |
| **Critical SMS only to super admins** with the switch on and a phone, only at 1 day or a refused key, **their switch read again when it leaves**; never for S4 | `Integration/EventMessagesTest`, "the critical SMS" |
| **Consent off: no marketing email, the bell entry still made**, for a notice, a segment notice and a message to many; checked again as the email leaves; a message to one ignores consent | `Integration/NoticesTest`, `Integration/NoticeSegmentTest`, `Integration/StaffMessagesTest` |
| Notices: now and scheduled in the store's zone, changed or cancelled until they start, started within the minute, audited, a failed batch `FAILED` then carried on | `Integration/NoticesTest`; over HTTP `Feature/WrittenRoutesTest` |
| Staff messages: who may send to one and to many, whom the composer offers, audited; **to a segment** with a notice's refusals; **to one only in their home store** | `Integration/StaffMessagesTest`; over HTTP `Feature/WrittenRoutesTest` |
| A notice's or message's email: its row QUEUED with the batch, the provider outside any transaction, a retry sends nothing, a failure counted once | `Integration/NoticesTest` |
| What the screens read: the staff messages list and who sees what, the choices of each form and report kind, a notice's other facts, the composer's result, the confirmation page's words; another store's row not found; the box's 503 | `Feature/ScreensReadsTest` |
| The bells: own entries only, the store shopped, the stores a staff member may view, paging, marks, none for a guest or the system | `Integration/BellsTest`; over HTTP `Feature/BellRoutesTest` |
| Guests: double opt-in, the same answer on a repeat, an account's email refused, the signed link, the 7-day limit, the box's limit | `Integration/NewsletterTest`; over HTTP `Feature/WrittenRoutesTest` |
| Customers on the lists: joining, moving language, leaving; not while they may not receive offers; forgotten when anonymized; **a contact with no row deleted when a deletion is pending**, both routes; the address encrypted in the queue's tables | `Integration/NewsletterTest` |
| **An unsubscribe switches consent off**: the marketing email's signed link, within the request; Brevo's webhook, from the queue; opening a link changes nothing | `Feature/WrittenRoutesTest`, `Feature/StopEmailPagesTest`, `Integration/NewsletterTest`; Access's side `tests/Modules/Access/Integration/MarketingConsentTest` |
| **The stop-email pages of a switched-off store**: GET and POST work, consent off, a guest off; a bad signature refused alike on a store on, off or unknown; every other page of that store a 404 | `Feature/StopEmailPagesTest` |
| The consent switch and the registration box | `tests/Modules/Access/Integration/MarketingConsentTest`, `tests/Modules/Access/Feature/CustomerAccountPageTest` |
| Reports: figures against hand-counted orders, a cancelled, rejected or came-back order excluded, **a refund on the day its return was asked**, categories adding up, **all stores totalled per currency**, a store's permission, days refused, the preview's 50 rows, the file's cells, fixed queries | `Integration/ReportsTest`; over HTTP `Feature/ReportRoutesTest` |
| **The daily report's figures against hand-counted orders, a cancelled order excluded, a refund on its day**; net VAT; yesterday and month to date; **all stores totalled per currency**, each in its own day, the off ones marked; **a past day's file unchanged**; who may make and open | `Integration/DailyReportTest`; over HTTP `Feature/DailyReportRoutesTest` |
| The daily email: off by default, each store's zone, on the person's behalf, one email however often switched on, stopped when changed, a late worker's one report; **00:00 refused; a store whose day has just started shows zeros and the email goes; a retry makes that moment's report once**; logo and name | `Integration/DailyReportEmailTest` |
| SMS countries: the start list, add, change, remove, overlaps, Super Admins alone, audited | `Integration/SmsCountriesTest`; over HTTP `Feature/SmsCountryRoutesTest` |
| The adapters: Brevo's requests as its reference shows, the log drivers refused on a live system, the newsletter's too, at boot | `Integration/SendersTest`, `Integration/BrevoNewsletterListTest`, `Integration/RealEmailDriverTest` |
| The tables' last lines, the schema, the permissions, the nightly removal of the three log tables, of what staff wrote (sent, cancelled, failed; not what waits or sends) and of the daily reports | `Integration/OpsTablesTest`, `Integration/OpsSchemaTest`, `Integration/OpsPermissionsTest`, `Integration/PruneTest`, `Integration/NewsletterTest`, `Integration/DailyReportTest` |

**Not tested:**

- C11, S6 on their events: no listener exists (§1.5, §1.6), so "every row sends once" holds only
  for the rows built.
- S8 and the going-live notice against the real Content: nothing fires their events yet, so the tests fire them and
  answer `ContentApi` with a stand-in.
- Over HTTP: `ops.admin.subscribers` (its handler is tested in `NewsletterTest`); the staff's
  `ops.admin.bell.unread` and `ops.admin.bell.read-all`; the box's limit by address; a signed-in customer at the box.
- No browser test: there are no screens yet (§4.13).

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

### 9.4 The screens' questions (2026-11-02)

Asked by the screens session for §4.15. **Round 1 answered by the owner, 2026-11-02: (a) in all 27.** Q7 and Q15 were first offered with timers; the owner objected: "we might face db with frequently reads for nothing ( each 15s , or 60s) ... rare for customer to get a notification, we can make it lower cost for db rather than to fill it with unnecessary requests". New options were measured and offered, and (a) below is what the owner took.

| # | Question | Answer |
|---|---|---|
| 1 | The screens' addresses | The same address: a visit gets the page, a screen's read still gets the data |
| 2 | Where the entries go | The agreed tree: a Reports section after Customers; Marketing gains Offer Notices, Customer Messages, Subscribers |
| 3 | SMS Countries | Store Settings, beside Places |
| 4 | The five reports | One menu entry, the kinds as tabs |
| 5 | A Home card | Not now: with the Home dashboard job |
| 6 | The bell's shape | An icon with the number, a small list on press, View All to a full page |
| 7 | The unread number | No timer: it comes with each page, one query in the page's request, none for guests; staff re-check once on coming back to the tab; opening the bell shows the current list. Signed-in shop pages rise by one query, recorded |
| 8 | The customer's account menu | A Notifications link too |
| 9 | A staff entry's store name | Only for someone with two or more stores |
| 10 | The forms | Their own pages, asking before leaving with changes |
| 11 | The two languages | Side by side, stacked on a phone |
| 12 | Confirming a send | A message to many and a notice sent now, naming the audience |
| 13 | Reading a sent message back | A dialog with both languages and the counts |
| 14 | A whole store's audience | Add the number to the confirmation; a new read through Access |
| 15 | Counts while sending | A Refresh button only while a row says Sending; nothing in the background |
| 16 | A failure | The badge and a line, the link to Failed Jobs only for those who can open it |
| 17 | A notice's link across languages | The shop redirects the other language's address to the right one. **Measured 2026-11-02, 12 real requests:** a product, category or brand's slug answers 200 under its own language and 404 under the other, both ways |
| 18 | The preview | On pressing Show Report, the choices kept in the address |
| 19 | The opening dates | The 1st of this month to today |
| 20 | Revenue's four sheets | Tabs |
| 21 | Totals | In the preview, marked, bold, on every sheet that has rows and however long it is (§4.6); a sheet with no rows shows none (owner, 2026-11-02). A small backend change |
| 22 | After Make Report | The new row with its Download; no automatic download |
| 23 | The daily email's first time | 21:00 in the store's time |
| 24 | Changing a country | Row menu and dialogs; Remove says who is affected |
| 25 | The consent switch | The Profile tab, saving as flipped; an unticked box at registration |
| 26 | The newsletter box | The shop's footer, guests only |
| 27 | The confirm, leave and unsubscribe pages | One small frame for all three |

**Round 2, answered by the owner, 2026-11-02.** No questions are left.

| # | Question | Answer |
|---|---|---|
| R1 | The gateway key alerts (S5, S5b) have no link (§4.2), written before the Payments Gateway page existed (#315). Should pressing one open it? | **(a) Yes:** `/admin/payments/gateway?store={code}`, the alert's own store. A small change by Stage 8 specs planning in `MessageWriter`, its test and §4.2. Those two emails gain the Open button other staff alerts have. Entries already written stay without a link |
| R2 | Should a customer's page in the panel have a Send Message button, opening O4 with that customer chosen? | **Answered by the owner, 2026-11-02: (a) not now.** O4's search finds them, and this job stays inside §4 |

**The screens session's own choices in §4.15, accepted by the owner, 2026-11-02:** a new notice opens on Now, the confirmation guarding it; a bell row is cut at three lines, and pressing one with no link opens its whole text; changing a report's choices hides Download Excel until it is shown again; the reports page opens on the first store that is on, a Super Admin's too; the leave and unsubscribe pages offer no way on to the shop.

### 9.5 The campaign links: for the owner (2026-11-03)

Ops' side is built, on assumptions these decide. Nothing here blocks Ops; each is answered before Content's spec is accepted.

1. **A notice cannot wait for its campaign.** A notice is sent now or at a time (§1.7), so one tied to a campaign keeps a time, and the
   scheduler sends it then even if the campaign has not gone live. A new state would make it wait for the campaign alone; none was added.
2. **Which permission receives S8.** No module declares a campaign or Content permission, so `ops.message.send_many` stands in (§1.6).
3. **S8's words.** The approved words named no campaign, and no link can open it yet, so `:campaign` was added to the line, the subject and
   the email (2026-11-03), for approval with the full review of the words.
4. **Where the tie is kept.** `noticeId` comes from Content, and nothing may depend on Ops (handoff §4.4), so Content cannot list the
   notices to choose from. Content's spec has to settle how staff pick one.
