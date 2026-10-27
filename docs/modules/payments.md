# Payments — module specification

**Status (2026-10-27): draft for the owner.** Payments takes money online for an order, through a
gateway — **MyFatoorah for the KSA store**, the only one chosen (owner, 2026-10-17) — and tells Sales
when money arrived. It also holds each store's **bank account** for transfers (moved from B2B, owner
2026-10-19 P10) and the **target time to check a transfer**. It is built from handoff §12.4, the
contract Sales declared for it (sales.md §2.4, in code since S7b), the owner's answers of 2026-10-17 to
2026-10-27 (§9.1) and MyFatoorah's documentation as read on 2026-10-27
([payments-myfatoorah-notes.md](payments-myfatoorah-notes.md) — every gateway fact below is from there,
with its page). Points marked **[PROPOSED]** are mine, stated for the owner to reject; the questions
are in §9.3.

**The owner's charge for this module (2026-10-17):** "the payment must be so secure and ran over all
the usecases so we dont charge same order twice or charge on nothing or if had a error in connection or
any of these stuff we have to follow a strong methodology". §1.1 is that methodology; §8 tests every
case of it.

**Dependencies (handoff §4.4):** Payments → Platform. **[PROPOSED]** Payments → Access only to declare
its permissions — a handoff §4.4 amendment for the owner, as Catalog, Pricing, Inventory and Sync were
allowed (2026-10-02/07). Payments
**never reads Sales**: Sales hands it an order id, an amount, a deadline and the customer's contact
details, and Payments answers with events. All order decisions (cancel, revive, refund due) stay in
Sales.

## What Payments does not own

| Thing | Owner |
|---|---|
| The order, what is due, what is paid, the payment state the customer sees | Sales (sales.md §1.4, §1.9) |
| Recording a payment by hand, every refund, voiding a record | Sales — **refunds are always done by staff outside the system and recorded in Sales** (handoff §12.4; owner, 2026-10-19 P7) |
| Cancelling an unpaid online order after its time, and reviving it in the grace window | Sales (amendment, §9.2) — Payments tells it what happened |
| The transfer's document (the receipt the company uploads) | Sales (sales.md §1.9) |
| Which ways to pay an account type gets | Sales (sales.md §1.9) — Payments only says whether online payment is available |
| Customer and staff emails or SMS ("payment received") | Ops (stage 8) — until then the order page says it (owner, 2026-10-27 P25) |

---

## 1 · Aggregates and invariants

### 1.1 The safety rules — never twice, never on nothing, never lost

These hold for every gateway, and every one has its tests in §8.

1. **The gateway's own answer is the only truth.** A payment is succeeded only when **we** asked the
   gateway and it said so (MyFatoorah: `GET /v3/payments/{paymentId}`, or `GetPaymentStatus` by invoice —
   notes §4). The page the customer comes back to and the gateway's notice (webhook) are only **signals
   to ask**; neither is ever believed on its own. (MyFatoorah's notice signs five fields and **not the
   amount** — notes §5.)
2. **One live invoice per order at a time.** An order waiting for online payment has **one** gateway
   invoice open, **reused** for every "Pay now" until it expires — the customer is sent back to the same
   page, so a retry or a second tab never creates **a second invoice** (**[PROPOSED]**; MyFatoorah has no
   way to cancel an unpaid invoice — notes §7). This does **not** rule out **two successful tries on the
   same invoice** (two tabs paying the same page — notes §4.6 does not rule it out): rule 5 records every
   successful try, and a second one is flagged **"paid twice"** for staff — the extra money is a refund
   due. A new invoice is created only after the open one expired, or for a staff request (§1.5) — and
   **never while another invoice of the same order is open** (a `FURTHER` request is refused while an
   `ORDER` attempt is open, and the reverse).
   - **An invoice lives 15 minutes** (owner, 2026-10-27, §9.3 #9), renewed on the next "Pay now" while the
     order's 60 minutes last — after a cancellation no invoice of ours stays payable for more than 15
     minutes.
   - **Our side stops offering an invoice** the moment its order is cancelled (any reason) or the
     amount is no longer due; a payment made on it anyway is recorded and becomes a refund due.
3. **Creating an invoice is safe to repeat.** Every create carries an **idempotency key** made once and
   stored before the call — `Idempotency-Key` on `POST /v3/payments`, available in Saudi Arabia, kept 250
   minutes by MyFatoorah (notes §6). A timeout, a crash or a double click repeats **the same key** and
   gets **the same invoice**. A key is **never reused for another amount** (MyFatoorah answers a reused
   key with its first answer even when the body changed — notes §6).
4. **Success is final.** Once the gateway says succeeded, nothing turns it back (MyFatoorah: "the
   Success Payment Status is final" — notes §4). A failure after a success is ignored; a success after a
   failure wins.
5. **Recorded once — per successful try.** Every successful try (a **transaction**, keyed by the
   gateway's payment id, unique per gateway and mode) is recorded once, under the attempt's row lock, so
   two notices, a notice and the customer's return, or two workers record it once. **Events and
   `payment()` are per transaction**, so Sales records each real charge once — a second success on one
   invoice is a second payment in Sales, never swallowed (rule 2).
6. **The attempt is found from the gateway's answer, never from the visitor.** The return page's
   `paymentId` and a notice only tell us **what to ask**; the attempt is the one whose **`invoice_id`**
   is the invoice the gateway names in its answer, and the invoice's **`ExternalIdentifier`** (which we
   set to our attempt id when creating it — signed in the notice, notes §5.4) must match. A visitor
   coming back with someone else's `paymentId` can never get it applied to their own order.
7. **The amount and the currency are checked.** What the gateway says was paid is compared with the
   invoice, the amount parsed exactly as a decimal (never a floating-point number). **A different
   amount** in the right currency is **recorded as it really was** and the order is **flagged for
   staff** (owner, 2026-10-27 P20). **A different currency** (in LIVE the base currency must be SAR) is
   **kept in Payments, flagged, and never sent to Sales**, so foreign money never enters an order's
   books.
8. **Nothing is lost when the gateway cannot be reached.** A status call is retried 3 times, 5 seconds
   apart, with a 30-second timeout (MyFatoorah's own guidance — notes §4.8); then the attempt is
   **`UNKNOWN`** and the background check asks again (rule 9). The customer is told "we are confirming
   your payment", never "failed".
9. **A background check closes every loose end** (owner, 2026-10-27 P27). Every few minutes it asks the
   gateway about every attempt still `CREATING`, `OPEN` or `UNKNOWN` — **by the invoice**, which we
   always have, never by a payment id we may never have received: `GetPaymentStatus` with the
   `InvoiceId` key, or the invoice with its `Transactions` (notes §4.2, §4.3), going through **every
   try**. "No invoices match this InvoiceId" means **no try yet** — never an error, never expired (notes
   §4.2). It asks **once more after each invoice's expiry**, so a payment whose notice was lost is still
   found, and a nightly pass looks again at the last 3 days' attempts that did not succeed
   (**[PROPOSED]**).
10. **No card data ever touches our server** (owner, 2026-10-19 P3): the customer pays on
    **MyFatoorah's hosted page** (owner, 2026-10-27 P24). We keep no card number, no CVV, no card
    token. Payments only sends a customer to a `PaymentURL` whose address is MyFatoorah's own
    (`*.myfatoorah.com`, HTTPS).
11. **Test money is never real money** (owner, 2026-10-27) — §1.6.
12. **The customer never sends an amount.** The amount comes from Sales, from the order (handoff §2).
    Payments refuses to start when the deadline has passed (`payBy`) — Sales never asks for a
    cancelled order, and Payments does not trust that it will not.
13. **Every call to the gateway is logged** — the request and the answer, the time, how long it took —
    on the attempt, for staff (owner, 2026-10-19 P15). **Never logged**: the key, the secret, the
    `Authorization` header (also kept out of exception reports); the card details a notice carries
    are dropped before the notice is stored. The customer's name, email and phone are kept only on the
    attempt and are **cleared when the customer is anonymized** (Access's `CustomerAnonymized` —
    §6), in the calls and the notices too.
14. **A key is kept until nothing needs it.** Changing a key, turning the gateway off or switching the
    mode never strands an open attempt: the old key and secret of a mode are kept (encrypted) until that
    mode's attempts are all closed, the background check goes on while the gateway is off, and notices
    are still accepted for them.

### 1.2 A store's gateway

- A store has **at most one gateway** today; MyFatoorah is the only adapter (owner, 2026-10-17). More
  come as adapters with nothing in Sales changing (sales.md §1.9, answer 38). **Tamara is a separate
  gateway, later** (owner, 2026-10-27 P19), and admins will choose which stores each gateway serves.
- **Only a Super Admin** sets it up (owner, 2026-10-19 P11): the gateway, **the live key**, **the test
  key**, **the notice (webhook) secret** for each, and **test or live** (owner, 2026-10-19 P13: Super
  Admin only).
- **The keys are new MyFatoorah API keys** (owner, 2026-10-27), not the legacy one — a legacy key
  "will remain valid until it expires", then the new kind is required (notes §1.4).
- **The key's expiry date** (owner, 2026-10-27, §9.3 #7): the Super Admin **chooses it in MyFatoorah's portal
  when creating the key** (notes §1.5); MyFatoorah's documentation gives no call that reads it back, so
  **the setup asks for it** when the key is pasted, and it is required. **The people in charge of the
  key — the Super Admins (P11) — are told before it expires**, so a renewal never stops the shop
  (owner, 2026-10-27, §9.4 #2: (a)): 30, 7 and 1 days before, a banner on the gateway page and on their
  admin home, and a staff notification on a new topic, **Payment problems** (access.md amendment 78),
  sent as Sync's are — in the panel and by email once Ops sends notifications.
- **A key MyFatoorah refuses anyway** (expired, switched off or deleted in the portal — a 401):
  **[PROPOSED]** the store's online payment **stops by itself** — `availability()` answers not available
  (`KEY_REJECTED`), so individuals' orders keep coming in as "staff will contact you", as in a store
  with no gateway (owner, 2026-10-18) — and the Super Admins are told at once, by the same banner and
  topic. Open attempts stay `UNKNOWN` and hold their cancellations (§1.7); once a new key is saved, the
  checks resume with it and online payment comes back.
- **The keys and secrets are kept encrypted** with the app's key in Payments' own table, as Sync keeps
  its key (sync.md §1.2) — Platform's settings never hold a secret (platform.md §1.3). **Never shown
  again**: the page shows only **the last 6 characters** (owner, 2026-10-19 P12) and when it was set.
- **Saving tests the key first**: one small call (the methods list — notes §3.1) to that mode's address;
  if it fails, nothing is saved and the reason is shown (as Sync, sync.md §1.2).
- **The addresses are fixed per gateway and mode**, not typed: MyFatoorah test
  `https://apitest.myfatoorah.com`, KSA live `https://api-sa.myfatoorah.com` (notes §1.1). A store's
  country picks its live address.
- **Switching test ↔ live** (owner, 2026-10-27, P17): the Super Admin flips it; the store then uses the
  other key and address. Attempts already open finish in the mode they started in (rule 14). **TEST
  may be used on any store** (owner, 2026-10-27, §9.4 #1 — §1.6).
- **Each store and mode has its own notice address** — `/payments/notice/{store}/{mode}` — sent with
  every invoice (`IntegrationUrls.Webhook`, notes §5.1) and pasted into that mode's MyFatoorah portal,
  so the right secret is known before the signature is checked.
- **A store with no gateway, or a gateway switched off, goes live anyway** (owner, 2026-10-18):
  individuals are offered "staff will contact you" only and staff record the payment by hand, as Sales
  does today (sales.md §1.9). **The settings page says so** in Payments' line (platform.md §1.3, a
  module's line): "Online payment: off — individuals pay through staff until a gateway is set up"
  (owner, 2026-10-18). In test mode it says "Online payment: TEST mode — no real money is taken".

### 1.3 The ways to pay online

- **The methods on the KSA account** (owner, 2026-10-27): Visa, Mastercard, Mada, Apple Pay, Apple Pay
  (Mada), STC Pay. Google Pay is not on. **MyFatoorah's fee is on the vendor** for every method (owner,
  2026-10-27), so the customer pays exactly the order's total (owner, 2026-10-19 P14) — and Payments
  checks it (§1.1 rule 7: with the fee on the customer, MyFatoorah would charge more — notes §3.5).
- **Who picks the method** (owner, 2026-10-27, §9.3 #1): **MyFatoorah's page shows
  every method switched on in the account** and the customer picks there; our checkout shows one "Pay
  online" choice with the methods' logos. (MyFatoorah's create call takes no `MADA` value — Mada is
  under `CARD` — notes §3.2.) MyFatoorah's reference says `PaymentMethod` is "required for redirection
  cases" while also saying that leaving it out shows every method (notes §3.3): **the first build step
  tries both in the test account** before anything is built on it.
- **Online is for individuals only** — companies pay by transfer or through staff, never online (owner,
  2026-10-09; sales.md §1.9).
- **No minimum or maximum per method** (owner, 2026-10-27).

### 1.4 Paying an order — the attempt

Sales places the order first (it holds the stock, the coupon and the points — sales.md §1.3), then asks
Payments to start. **The customer pays right after placing, before any staff approval** (owner,
2026-10-19 P1: "acts like normal shop apps").

1. **`start`** — Sales passes: the order id and number, the amount, **the deadline** (placed + 60
   minutes, Sales's setting — owner, 2026-10-19 P2), the purpose (`ORDER` or `FURTHER`, §1.5), the
   customer's name, email and phone, the language, and where to send the customer back. Refused when
   the deadline has passed (rule 12).
2. **The row first, committed**: if the order has an open attempt that has not expired, it is
   **returned as it is** (rule 2). Otherwise a new attempt is written **with its idempotency key and
   committed before any call** — so a crash during the call never loses the key and a retry never makes
   a second invoice (rule 3). A unique index allows **one open attempt per order**, whatever its purpose.
3. **The call**: `POST /v3/payments` with the amount (in SAR, two decimals — owner, 2026-10-27), our
   attempt id as **`Order.ExternalIdentifier`** (signed in the notice — rule 6) and as
   `Customer.Reference`, the expiry (`PaymentExpiry`, UTC — §9.3 #9), the language, our return address
   and the store and mode's notice address. The answer's `InvoiceId` and `PaymentURL` are saved and the
   attempt is `OPEN`.
   - **A timeout or 5xx** → repeated with the same key (rule 3), up to 3 times; still nothing → the
     attempt is `UNKNOWN`, the customer sees "We could not reach the payment page — try again in a
     minute", and the next "Pay now" repeats the same key.
   - **The gateway refuses the request** (a 4xx — for example a phone it does not accept) → the attempt
     is `REFUSED` (closed: it frees the one-open-attempt index), the reason logged and shown to staff,
     and the customer told to try again or contact us (**[PROPOSED]**: a refused phone is sent once more
     without it).
4. **The customer is sent to `PaymentURL`** (rule 10) and pays there.
5. **The customer comes back** to our return address with `?paymentId=` (notes §2.3). Payments asks
   the gateway about that payment (rule 1), finds the attempt **from the invoice the gateway names**
   (rule 6), applies the answer, and sends the customer to their order page — which shows **Paid**,
   **Not paid — Pay now**, or **We are confirming your payment** (it refreshes by itself). A
   `paymentId` whose invoice is not this customer's attempt changes nothing and shows their own order.
6. **The notice** (webhook v2) arrives — usually before the customer (notes §2). Its signature is
   checked with the secret of the store and mode its address names (HMAC-SHA256, base64, header
   `MyFatoorah-Signature` — notes §5.4). A bad signature → 401, logged, **never stored as seen**, so a
   forged notice cannot make the real one look like a repeat. A good one is **deduplicated on its
   `Event.Reference`** (notes §5.6), stored without its card details, answered 200, and then Payments
   asks the gateway (rule 1). A notice for an invoice we never created (made in the portal by hand) is
   answered 200 and ignored.
7. **The answer is applied** under the attempt's lock, try by try: each successful try is recorded once
   (rule 5) and `PaymentSucceeded` goes to the outbox **for that try**; the attempt is `SUCCEEDED`. A
   second successful try on a succeeded attempt is recorded too, flagged **paid twice**, and published
   the same way — Sales records it and the extra is a refund due. A refused try is recorded and
   `PaymentFailed` goes out once per refused try (**[PROPOSED]**: Sales only shows "the last try
   failed"); the attempt stays `OPEN` and the customer may try again on the same page. A try still
   `INPROGRESS` keeps the attempt open.
8. **Expiry**: after the invoice's expiry Payments asks one last time (rule 9). Nothing paid → the
   attempt is `EXPIRED` and `PaymentExpired` goes out. The next "Pay now", while the order's deadline
   lasts, opens a new invoice (rule 2).

**Pay again**: the order page's "Pay now" calls `start` again — the open invoice is returned and the
customer goes back to the same page (rule 2). As many times as they like within the time (owner,
2026-10-27 P18), within the limits of §1.8.

### 1.5 A further payment — when the order's total goes up

(owner, 2026-10-19 P6; 2026-10-27 P22, P23)

- When staff edit an order and it **holds less than is due** (sales.md §1.7), staff choose:
  **(a) ask the customer to pay the rest online** — a **payment request** on the order — or **(b) handle
  it by hand** (a transfer or whatever they arrange; then they record it in Sales, as today).
- (a) creates an attempt of purpose `FURTHER` for **exactly what is missing**, valid **60 minutes**
  (P22). The customer sees it on their order page ("Pay the remaining 45.00 SAR") — and, from stage 8,
  by email/SMS. Expired → nothing is cancelled; staff may send a new one.
- **Only for an individual's order in a store whose gateway is on**; sent by **staff who can manage
  orders** (P23).
- **Never while the order's first payment is still open**: a `FURTHER` request is refused while an
  `ORDER` attempt is open, and only offered once the order holds something already (rule 2) — or, for a
  "fee to be set" order, once staff set the fee (below). **Staff edits are blocked while the first
  payment is open** (owner, 2026-10-27, §9.3 #10): the edit waits until the order is paid or cancelled, and
  staff are told why.
- A `FURTHER` invoice expires after 60 minutes (`PaymentExpiry`), with the same 15-minute renewal as
  rule 2 (§9.3 #9).
- A request that is still open when the amount changes again is **withdrawn** (it no longer matches) and
  a new one is needed (**[PROPOSED]**). Withdrawn means: no longer shown; if the customer pays it anyway
  (MyFatoorah cannot cancel an invoice — notes §7), the money is recorded and becomes a refund due if
  too much (rule 7).
- An order whose shipping fee is "to be set" (shipping.md §1.2, `NOT_COVERED`/`OUTSIDE`) is **never
  offered "Pay now" at checkout** — its total is not known (owner, 2026-10-19 S10). **Once staff set the
  fee, staff choose as above** (owner, 2026-10-27, §9.3 #5: (b)): **a payment request for the whole amount
  due**, or by hand. The request is valid 60 minutes; expired → nothing is cancelled (the order has no
  payment timer). Only for an individual's order in a store whose gateway is on; a company's order pays
  by transfer (shipping.md §9.3 #7).

### 1.6 Test mode

(owner, 2026-10-27, P17 and the safety rule, confirmed: test payments "never count as real money")

- The Super Admin switches the store's gateway to **TEST**: the test key and `apitest.myfatoorah.com` are
  used, with MyFatoorah's test cards (notes §1.8). The whole flow can be tried: place, pay, fail, retry,
  cancel, revive.
- **Where TEST runs** (owner, 2026-10-27, §9.4 #1): **the system acts as usual** — one code, and TEST is a
  store's gateway setting, allowed on any store, switched on. The testing happens **on the staging site**
  (a subdomain, before the real domain opens); the owner switches each store to LIVE when everything
  passes, then moves the system to the real domain. (The first pick — TEST only while the store is off —
  could not work: an off store takes no order from anyone, platform.md §1.6, §2.7, and staff never order.)
- **A store in TEST says so to everyone** (owner, 2026-10-27): the checkout and the order page show "Test
  mode — no real money is taken", besides the admin banner below. MyFatoorah's test page accepts only
  its public test cards, so nobody can pay real money there.
- **The safety net if a real store is ever left in TEST**: a TEST payment never makes an order paid for
  real (below), so it can never send goods out unpaid.
- **Every attempt and transaction carries its mode.** A TEST payment never counts as real money: it is
  marked TEST on the order, in every list and on the receipt, and **left out of every money figure**
  (home cards, reports). How Sales treats an order placed in TEST — question §9.4 #3.
- While a store is in TEST: a banner on every admin page of that store, and the settings line (§1.2).
- In TEST, MyFatoorah converts to KWD (notes §1.9): the amount check (rule 7) compares the **pay
  currency** value; in TEST the currency rule of rule 7 is checked against that pay currency, in LIVE
  against SAR as the base currency.
- Test and live ids come from two separate systems, so every gateway id is unique **per gateway and
  mode**, never per gateway alone.

### 1.7 The 60 minutes and the grace window — Payments' part

(owner, 2026-10-19 P2, P5; 2026-10-27 P5: 5 minutes, a setting)

- The order's time to pay (60 minutes) and the grace window (5 minutes) are **Sales's settings**, and
  Sales cancels the order when the time runs out (Sales amendment, §9.2).
- **Before the timer cancels, Sales asks Payments for a fresh answer** (`refresh(orderId)`, §2.1): an
  attempt that is `UNKNOWN`, or has a try `INPROGRESS`, **holds the cancellation** until the gateway
  answers (the background check keeps asking); a success found then is simply a paid order.
- **The grace window is judged by when the customer paid** — the try's `TransactionDate` from the
  gateway (notes §4.4) — **not by when we learnt of it** (owner, 2026-10-27, §9.3 #11): a customer
  who paid at minute 59 whose notice was lost is not punished for our delay.
- **Every success is reported**, whenever it arrives, with its payment time. Sales decides: paid inside
  the time or the grace window, and the order was cancelled **by the timer** → revived and paid;
  otherwise → recorded, and a refund due for staff (owner, 2026-10-19 P5).
- What MyFatoorah does with a payment after `PaymentExpiry` is **not in their documentation** (notes
  §7); the owner expects the page to stop taking payment. Either way, rule 9 still asks after the
  expiry, so a late success is never lost.

### 1.8 Our own limits

(owner, 2026-10-27: "we can set something from us as the community does")

- "Pay now": **10 per order per 10 minutes** and **30 per customer per hour** (owner, 2026-10-27, §9.3 #4).
- **The limits only stop abuse; they never multiply work** (the owner, 2026-10-27: ten presses must be
  one operation, not ten). While the order's invoice is open, every press — a double click, two tabs,
  ten presses — gets **the same page** with no new call to MyFatoorah (rule 2); two presses at the same
  instant cannot make two attempts, because the one-open-attempt index lets only one row in and the
  other press gets that row (§1.4 step 2). Ten presses = one attempt, one invoice, one gateway call. A
  press over the limit is answered "Too many tries — wait a few minutes" (429) and changes nothing.
- Status calls: never more than one at a time per attempt (the lock); the background check takes
  **at most 60 attempts a minute** across the system, oldest first (**[PROPOSED]** — MyFatoorah's limit
  has no number in their docs, notes §4.7).
- The notice endpoint: 120 a minute per store (**[PROPOSED]**); over it → 429, MyFatoorah retries
  (notes §5.5).

### 1.9 Bank transfers — the account and the target time

(owner, 2026-10-19 P8, P9, P10; 2026-10-27 P8, P28)

- **The store's bank account moves from B2B to Payments** (P10): the IBAN, the bank's name and the
  holder's name, with B2B's rules unchanged (b2b.md §2.3: IBAN checked; bank transfer on only while all
  three are filled in; shown only to an approved company). `B2BApi::bankAccount` becomes
  `PaymentsApi::bankAccount` (B2B and Sales amendments, §9.2). Its line in the settings moves with it.
- **The target time to check a transfer: 72 hours** (owner, 2026-10-27), **counted from the company's
  upload of its receipt** (P28). Past it, the order is marked **overdue** in the staff's order list
  (P8 (a)); messages to staff come with Ops (stage 8).
- **"Hold stock while verifying" is dropped** (P9): every order already holds its stock from placement
  (handoff §12.1).

---

## 2 · Public contract

The interface Sales declared (sales.md §2.4 — **provisional**) is **changed** as below; Sales's callers
change with it (Sales amendment, §9.2).

### 2.1 `Modules\Payments\Public\Contracts\PaymentsApi`

```php
interface PaymentsApi
{
    /** Whether this store takes online payment now, for this buyer: live or test, and how it is shown (§1.2, §1.3). */
    public function availability(StoreId $store, PaymentAudience $audience): OnlinePaymentDto;

    /** Starts or resumes paying an order online (§1.4). Safe to call again: the open attempt is returned. */
    public function start(PaymentRequestDto $request): PaymentStartDto;

    /** A staff request for the rest of an order (§1.5) — `start` with purpose FURTHER, by a staff member. */
    public function requestFurther(PaymentRequestDto $request, string $staffId): PaymentStartDto;

    /** Withdraws an order's open further-payment request (§1.5). */
    public function withdraw(string $orderId): void;

    /** One successful (or refused) try as Payments recorded it — read by Sales when an event arrives with ids only. */
    public function payment(string $paymentId): ?PaymentDto;

    /** Asks the gateway now about the order's open attempt — Sales calls it before the timer cancels (§1.7). */
    public function refresh(string $orderId): PaymentStateDto;

    /** The order's attempts and their calls, for staff (§1.1 rule 13). */
    public function attempts(string $orderId): array; // list<PaymentAttemptDto>

    /** The store's bank account, null while bank transfer is off (§1.9 — from B2B). */
    public function bankAccount(StoreId $store): ?BankAccountDto;

    /** The hours staff have to check a transfer (§1.9). */
    public function transferCheckHours(StoreId $store): int;
}
```

- **`PaymentRequestDto`**: `orderId`, `orderNumber` (shown on MyFatoorah's page), `storeId`, `amount`
  (Money), `payBy` (the deadline), `purpose` (`ORDER`, `FURTHER`), `customerName`, `customerEmail`,
  `customerPhone`, `locale`, `returnUrl`.
- **`PaymentStartDto`**: `paymentId` (our attempt's id), `redirectUrl` (null when the gateway could not
  be reached — §1.4 step 3), `state`.
- **`PaymentDto`** — **one try**, not one attempt (rule 5): `paymentId` (our transaction's id),
  `attemptId`, `orderId`, `amount` (what was really paid), `expected` (what the invoice asked), `method`
  (as the gateway names it — Mada, Visa, Apple Pay…), `reference` (the gateway's payment id), `mode`
  (`LIVE`, `TEST`), `succeeded`, `paidAt` (the gateway's `TransactionDate` — §1.7), `mismatch` (bool,
  rule 7), `paidTwice` (bool, rule 2).
- **`PaymentStateDto`**: `state` (`NONE`, `OPEN`, `UNKNOWN`, `IN_PROGRESS`, `SUCCEEDED`, `EXPIRED`) —
  whether Sales may cancel now (§1.7).
- **`OnlinePaymentDto`**: `available` (bool), `mode` (`LIVE`, `TEST`, null), `methodLogos` (list), `reason`
  when not available (`NO_GATEWAY`, `GATEWAY_OFF`, `KEY_REJECTED` — §1.2, `COMPANY`).
- **`PaymentAudience`** stays (`INDIVIDUAL`, `COMPANY`). `PaymentMethodDto` and `methods()` go: the
  method is picked on MyFatoorah's page (question §9.3 #1).
- **`BankAccountDto`** moves from B2B unchanged.

### 2.2 Events (`Public/Events`, ids only, through the outbox — handoff §4.5)

| Event | When |
|---|---|
| `PaymentSucceeded` (`eventId`, `paymentId`, `orderId`, `occurredAt`) — exists | The gateway confirmed a successful **try** (§1.4 step 7) — once per try; `paymentId` names the try. **Not** published for a currency mismatch (rule 7) |
| `PaymentFailed` (`eventId`, `paymentId`, `orderId`, `occurredAt`) — exists | A try was refused |
| **`PaymentExpired`** (`eventId`, `paymentId`, `orderId`, `occurredAt`) — new | An attempt's invoice expired with nothing paid (§1.4 step 8) |

### 2.3 What Payments needs from other modules

| From | What | State |
|---|---|---|
| Platform | Stores; encryption with the app's key; the settings line; the audit log; `MenuCount`; the scheduler; the outbox and `processed_events` (built by Sales) | On `main` |
| Access | Declaring Payments' permissions; a **Payments** permission group | access.md amendment (§9.2) |

---

## 3 · Use cases

| Use case | Permission | Scope |
|---|---|---|
| `SetUpGateway`, `ChangeKeys`, `SwitchMode` (test ↔ live), `SwitchGateway` (on/off) | **`payments.gateway.manage`** — reserved, Super Admins only (owner, 2026-10-19 P11, P13) | That store |
| `ChangeBankAccount` (from B2B's `platform.settings.update` rule) | **`payments.bank_account.update`** — Admins (**[PROPOSED]**: as B2B had it) | That store |
| `StartPayment` (from Sales's checkout and the order page's "Pay now") | The customer's own order — Sales checks it, Payments is called inside | That store |
| `RequestFurtherPayment`, `WithdrawRequest` | **`sales.order.edit`** — staff who manage orders (owner, 2026-10-27 P23) — checked by Sales, which calls Payments | That store |
| `ReceiveNotice` (the webhook) | The signature — no staff, no customer | That store and mode |
| `ReturnFromGateway` (the customer's return) | The customer's session — the attempt must be theirs | That store |
| `ListPayments` (all online attempts, by status — owner, 2026-10-27 P26), `ViewAttempt` (its tries and calls — P15) | **`payments.view`** — given wherever `sales.order.view` is (**[PROPOSED]**) | That store |
| `CheckAttempt` (staff's "Ask the gateway now" on an `UNKNOWN` attempt) | **`payments.view`** | That store |
| `CheckOpenAttempts` (every few minutes), `ExpireAttempts`, `NightlyRecheck`, `PrunePaymentLog` | System (reserved) | — |

Every change of a gateway is audited — never a key or a secret, only "changed" and the last 6
characters. The permissions sit in a new **Payments** group in the role editor.

---

## 4 · State machines

**An attempt** (one invoice):

```
CREATING ─▶ OPEN ─▶ SUCCEEDED                 (any try succeeded — final)
CREATING ─▶ REFUSED                           (the gateway refused the request — closed, §1.4 step 3)
CREATING ─▶ UNKNOWN ─▶ OPEN                   (the create call's answer was lost; the same key again)
OPEN ─▶ UNKNOWN ─▶ OPEN | SUCCEEDED | EXPIRED (a status call failed; asked again later)
OPEN ─▶ EXPIRED                               (expiry passed, the gateway confirms nothing paid)
OPEN ─▶ WITHDRAWN                             (a further request replaced — §1.5)
EXPIRED | WITHDRAWN ─▶ SUCCEEDED              (a late success: recorded all the same — §1.7)
SUCCEEDED ─▶ SUCCEEDED                        (a second successful try: recorded, "paid twice" — rule 2)
```

A failed try is a **transaction** of the attempt, not a state: the attempt stays `OPEN`.

**A gateway**: `NOT SET UP` → (SetUpGateway, the key tested) → `ON` ⇄ `OFF`; mode `TEST` ⇄ `LIVE`.

### 4.1 The admin screens (built by the screens session — owner, 2026-10-19)

| Screen | What |
|---|---|
| **Payment gateway** — `/admin/payments/gateway?store=` (Super Admins) | The gateway, mode, keys (last 6 shown), the key's expiry, the notice address to paste into MyFatoorah, on/off; the TEST banner |
| **Payments** — `/admin/payments?store=&state=` | Every online attempt: order, amount, method, state, mode, time; filters (§3) |
| **An attempt** — `/admin/payments/{id}` | Its tries, the gateway's ids, every call made (§1.1 rule 13), "Ask the gateway now" |
| **On the order** (Sales's screen) | The attempts block; "Request payment of the rest" / "Handle by hand" (§1.5); the overdue mark (§1.9) |
| **The bank account** | Moves from B2B's settings section to Payments' (§1.9) |

---

## 5 · Tables

Schema `payments`. Money in the smallest unit, `bigint`. Store-scoped rows name their store.

| Table | Columns |
|---|---|
| `payments.gateways` | `store_id` PK FK `platform.stores` RESTRICT · `gateway` CHECK (`MYFATOORAH`) · `active` bool · `mode` CHECK (`TEST`, `LIVE`) · the **previous** key and secret of each mode, kept until that mode's attempts close (rule 14) · `live_key_encrypted` NULL, `live_key_last6` NULL, `live_key_expires_on` NULL · `test_key_encrypted` NULL, `test_key_last6` NULL, `test_key_expires_on` NULL · `live_secret_encrypted` NULL · `test_secret_encrypted` NULL · `created_by` · `created_at`, `updated_at` |
| `payments.attempts` | `id` ULID PK · `store_id` · `order_id` (Sales's, no key) · `order_number` · `purpose` CHECK (`ORDER`, `FURTHER`) · `mode` · `gateway` · `currency_code` · `amount_minor` · `state` CHECK (§4) · `idempotency_key` uuid UNIQUE · `invoice_id` NULL, **unique per (`gateway`, `mode`)** · `payment_url` NULL · `pay_by` · `expires_at` · `succeeded_at` NULL · `paid_minor` NULL · `mismatch` bool · `paid_twice` bool · `refused_reason` NULL · `customer_name`, `customer_email`, `customer_phone` NULL (cleared on anonymizing) · `requested_by` NULL (staff, for FURTHER) · `created_at`, `updated_at` · **one open attempt per order** (a unique partial index on `order_id` WHERE `state IN ('CREATING','OPEN','UNKNOWN')` — rule 2) |
| `payments.transactions` | `id` bigint PK · `attempt_id` FK · `mode` · `gateway_payment_id`, **unique per (`gateway`, `mode`)** · `status` (as the gateway says) · `succeeded` bool · `method` · `amount_minor`, `currency_code` (the pay currency), `base_currency_code` · `paid_at` NULL (the gateway's `TransactionDate`) · `mismatch` bool · `published` bool (sent to Sales — false for a currency mismatch, rule 7) · `error_code` NULL, `error_message` NULL · `seen_at` |
| `payments.notices` | `id` bigint PK · `store_id` · `mode` · `event_reference` — **only notices whose signature checked out are stored**, unique per (`gateway`, `mode`) (§1.4 step 6); a bad one is only logged · `received_at` · `payload` jsonb (the card details and the customer's contact details removed) · `handled_at` NULL — kept 90 days (**[PROPOSED]**) |
| `payments.calls` | `id` bigint PK · `attempt_id` NULL · `store_id` · `endpoint` · `request` jsonb (the key, the secret and the `Authorization` header never stored; the customer's contact details cleared on anonymizing — rule 13) · `http_status` NULL · `response` jsonb NULL · `error` NULL · `took_ms` · `at` — kept 1 year (**[PROPOSED]**) |

Settings (Platform, declared by Payments): `payments.transfer.check_hours` (STORE, 1–720, default **72**
— owner 2026-10-27), and the bank account's three, moved from B2B (`payments.bank.iban`,
`payments.bank.name`, `payments.bank.holder`, "may be empty", sensitive).

---

## 6 · Events

| Consumed | From | Payments |
|---|---|---|
| `StoreCreated` | Platform | Nothing: a new store has no gateway |
| `OrderCancelled` | Sales | Our side stops offering the order's open invoice (rule 2): "Pay now" is gone; the gateway may still take a payment on it until it expires, which is recorded (rule 9) and is Sales's to judge (§1.7) |
| `CustomerAnonymized` | Access | The customer's name, email and phone cleared from attempts, calls and notices (rule 13) |

Published: §2.2.

---

## 7 · Errors

Every error extends `PaymentsError` → `DomainError` ("payments.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `NoOnlinePayment` | CONFLICT | `start` in a store with no gateway on, or for a company |
| `GatewayUnreachable` | **UNAVAILABLE** — a new category (HTTP 503) in Shared's `ErrorCategory` (**[PROPOSED]**: none of today's six fits a gateway that is down) | The gateway did not answer after the retries (§1.4 step 3) — the customer is told to try again |
| `GatewayRefused` | INVALID | The gateway refused the request (its message logged, a short one shown to staff) |
| `KeyTestFailed` | INVALID | Saving a key whose test call failed — the reason named |
| `KeyPermissionMissing` | INVALID | MyFatoorah's 401 "The token does not have the required permissions!" (notes §9) — names what to switch on |
| `AttemptNotFound` | NOT_FOUND | An attempt that is not there, or not this customer's |
| `AmountChanged` | CONFLICT | `start` with an amount different from the open attempt's for the same purpose — Sales must withdraw first |
| `PayByPassed` | CONFLICT | `start` after the order's deadline (rule 12) |
| `OrderPaymentOpen` | CONFLICT | A `FURTHER` request while the order's first payment is open (§1.5) |
| `InvalidPaymentsInput` | INVALID | A field out of range, named |

A notice with a bad signature is answered `401` with no body: it is not a staff error. The limits of §1.8 are Laravel's rate limiting (HTTP 429), not a domain error.

---

## 8 · Test scenarios

Against a **fake MyFatoorah** (an HTTP fake that can be told to answer, time out, answer twice, answer
late), and a short manual pass in MyFatoorah's test account before going live (§9.3 #6).

1. **The happy path**: start → page → paid → the customer returns → Paid; `PaymentSucceeded` once.
2. **Double click / two tabs**: two `start` calls at once → one attempt, one invoice, the same page.
3. **Create timed out**: the call times out, MyFatoorah did create it → the retry with the same key gets
   the same invoice; no second invoice.
4. **The customer closes the page** after paying: the notice records it; the order is paid without them.
5. **The notice is lost**: the background check finds the success.
6. **The notice comes twice / three times**: recorded once.
7. **The notice and the return at the same second**: recorded once (the lock).
8. **A forged notice**: a bad signature → 401, nothing recorded; a good-looking notice for an unpaid
   invoice → we ask the gateway, it says unpaid, nothing recorded.
9. **Failed, then succeeded**: the order is paid; the failure never turns it back.
10. **Succeeded, then a failure notice**: still paid.
11. **The amount differs** (a fee on the customer, a currency mismatch): recorded as paid, flagged.
12. **The status call fails 3 times**: `UNKNOWN`, the customer sees "confirming"; later found paid or
    not.
13. **Late success**: inside the grace window after the timer cancelled the order → Sales revives it;
    after it → recorded, refund due; after a staff or customer cancellation → refund due.
14. **Further payment**: a request for exactly the missing amount; expired → nothing cancelled; the
    amount changed → withdrawn; a withdrawn request paid anyway → recorded, refund due if too much.
15. **Test mode**: TEST attempts never counted as money; the banner; switching mode leaves open attempts
    in their own mode.
16. **Keys**: tested before saving; never returned; only the last 6 shown; a missing permission named;
    the expiry warning.
17. **No gateway / gateway off / a company**: no online payment offered; the settings line.
18. **Rate limits**: "Pay now" over the limit answered 429; the background check's cap.
19. **Isolation and permissions**: two stores' gateways, keys and notices never cross; a notice signed
    with another store's secret refused; each use case only with its permission.
20. **Logs**: every call logged without the key, the secret or the `Authorization` header; card details
    never stored; contact details cleared on anonymizing; pruned after a year.
21. **Someone else's paymentId** on the return page: nothing applied to the visitor's order (rule 6).
22. **Two successful tries on one invoice**: both recorded, the second flagged "paid twice", Sales gets
    both, the extra is a refund due.
23. **An edit while the first payment is open**: refused, naming why (§9.3 #10); a `FURTHER` request
    refused while an `ORDER` attempt is open.
24. **Test and live ids that are the same number**: both kept, nothing crosses.
25. **The timer at minute 60 with an `UNKNOWN` or `INPROGRESS` attempt**: the cancellation waits; paid
    at minute 59 and learnt at minute 70 → paid, not a refund due (§1.7).
26. **A refused create** (a 4xx): `REFUSED`, the index freed, the next "Pay now" opens a new attempt.
27. **A key changed while attempts are open**: they still close with the old key (rule 14).
28. **A currency mismatch**: kept in Payments, flagged, nothing sent to Sales.
29. **A forged notice sent before the real one**: the real one still processed (only signed notices are
    deduplicated).
30. **A store in TEST**: the checkout and the order page say "Test mode — no real money is taken"; a
    TEST payment never makes the order paid for real (§1.6, §9.4 #3).

---

## 9 · Questions

### 9.1 The owner's answers this spec is built on

| # | Answer | Date |
|---|---|---|
| — | MyFatoorah for KSA only; UAE disabled; Egypt not chosen; more gateways as adapters later | 2026-10-17 |
| — | A store with no gateway goes live for all; individuals pay through staff; the settings line says so | 2026-10-18 |
| P1 | Pay right after placing, before approval | 2026-10-19 |
| P2 | Pay again until 60 minutes (a setting), then the order cancels itself; staff see every order | 2026-10-19 |
| P3, P24 | MyFatoorah's hosted page — no card data with us | 2026-10-19, 2026-10-27 |
| P5 | A late success revives the order only within a 5-minute grace window (a setting) and only when the timer cancelled it; otherwise a refund due | 2026-10-19, 2026-10-27 |
| P6, P22, P23 | Staff choose: a payment request (60 minutes) or by hand; staff who manage orders | 2026-10-19, 2026-10-27 |
| P7 | Refunds are staff's business; only recorded | 2026-10-19 |
| P8, P28 | Overdue transfers flagged in the list; 72 hours from the upload | 2026-10-27 |
| P9, P10 | "Hold stock while verifying" dropped; the bank account moves to Payments | 2026-10-19 |
| P11, P12, P13 | Super Admins set up the gateway and the test/live switch; keys never shown again, last 6 characters | 2026-10-19, 2026-10-27 |
| P14 | The customer pays the order's total only; the fee is ours (on the vendor) | 2026-10-19, 2026-10-27 |
| P15, P26, P27 | Every attempt visible; a Payments list; the background check | 2026-10-19, 2026-10-27 |
| P17 | Test by switching the store's gateway to MyFatoorah's test address; test payments marked and never counted; a warning while in test | 2026-10-27 |
| P18, P20, P25 | Unlimited tries (each safe); a mismatch recorded and flagged; confirmation on the page until stage 8 | 2026-10-27 |
| P19 | MyFatoorah now; Tamara a separate gateway later | 2026-10-27 |
| §9.3 #1, #2, #4, #6, #8–#13 | (a), as recommended | 2026-10-27 |
| §9.3 #4 | The limits must never turn repeated presses into repeated operations (§1.8) | 2026-10-27 |
| §9.3 #5 | (b): a "fee to be set" order may get a payment request once staff set the fee | 2026-10-27 |
| §9.3 #7 | (a), and the people in charge of the key told before it expires; where the date comes from explained (§1.2) | 2026-10-27 |
| §9.3 #3 | The first pick fails: an off store takes no orders — open again as §9.4 #1 | 2026-10-27 |
| §9.4 #1 | The system acts as usual: TEST is a store setting on any store, said at checkout; testing on the staging subdomain; LIVE before the real domain | 2026-10-27 |
| §9.4 #2 | (a): a banner now; the Payment problems notification with Ops | 2026-10-27 |
| — | New API keys, not the legacy one; methods on: Visa, Mastercard, Mada, Apple Pay, Apple Pay (Mada), STC Pay; fee on the vendor; SAR two decimals; no min/max | 2026-10-27 |

### 9.2 What other modules must change (written as their amendments in this PR)

1. **Sales** — after placing an individual's online order, `start` and send the customer to the page;
   the order's **time to pay** (60 minutes) and **grace window** (5 minutes), as Sales settings; the
   timer's cancellation (a reason of its own: "payment not completed"); the **revive** inside the grace
   window (re-hold stock, coupon and points — and if any fails, it stays cancelled and the money is a
   refund due); `PaymentSucceeded` recorded as an `ONLINE` payment (once, by gateway payment id), with
   TEST and mismatch carried; **further-payment requests** on the order; the overdue transfer mark; "Pay
   now" on the order page; online never offered at checkout for a "shipping fee to be set" order — a
   payment request once staff set the fee (§1.5); the ways to pay
   read from `availability()`; `bankAccount` read from Payments.
   Also (the independent review, 2026-10-27): before the timer cancels, `refresh` — an `UNKNOWN` or
   `INPROGRESS` payment holds the cancellation (§1.7); the grace window judged by the payment's own time;
   **the revive does not take the coupon again** — a cancellation keeps its use counted (sales.md §1.6),
   so taking it again would count it twice; the points and the stock are taken again; each successful
   try recorded once by its gateway payment id, a second one on the same order a refund due; **no
   voiding of a real `ONLINE` record** (§9.3 #12); **edits blocked while the first payment is open**
   (§9.3 #10); the orders table's new
   columns and checks (sales.md §9.5).
2. **B2B** — the bank account and its settings line move to Payments (b2b.md §2.3, amendment).
3. **Access** — a **Payments** permission group.

### 9.3 The round of 2026-10-27 — answered

The owner answered (2026-10-27): **(a) for #1, #2, #4, #6 and #8–#13**; **(b) for #5**; **#7 (a) with an
addition** (§1.2); **#3 open again** (§9.4 #1). The questions are kept as they were asked.

1. **Who picks the method?** (a) **MyFatoorah's page** shows every method on the account; our checkout
   shows one "Pay online" with the logos — the owner's lean, nothing to keep in step with MyFatoorah's
   account **[Recommended]**, confirmed in the test account first (§1.3); (b) our checkout lists the
   methods.
2. **Does a paid online order still wait for staff approval before it ships?** Every order passes staff
   approval today (sales.md §1.4). (a) Yes — paying first does not skip approval; staff approve, then
   ship **[Recommended]**; (b) a paid online order is approved by itself.
3. **Test mode and real customers** (§1.6): (a) **TEST only while the store is switched off** — only
   Super Admins see the shop then; their test orders move like paid ones so the whole flow can be
   tried, are marked TEST everywhere, **cannot be marked shipped**, and are left out of every money
   figure; going live = switch to LIVE, then switch the store on **[Recommended]**; (b) TEST on a live
   store too — then real shoppers would meet MyFatoorah's test page, whose test cards are public.
4. **Our limits** (§1.8): 10 "Pay now" per order per 10 minutes, 30 per customer per hour, 60 status
   calls a minute, 120 notices a minute per store — (a) as written **[Recommended]**; (b) other numbers.
5. **An order whose shipping fee is "to be set"**, once staff set the fee: (a) paid through staff only, as
   the owner said for these orders **[Recommended]**; (b) staff may also send a payment request.
6. **Before going live**: (a) a written checklist — the test-account pass of every scenario in §8 that
   can be tried by hand, the notice address set in MyFatoorah, the live key's permissions, the fee on
   the vendor — run with the owner, then the switch to LIVE **[Recommended]**.
7. **The key-expiry warning** 30 days before (§1.2): (a) yes, on the gateway page and the admin home
   **[Recommended]**; (b) no warning.
8. **How long we keep** the gateway call log (1 year) and the notices (90 days): (a) as written
   **[Recommended]**; (b) other.
9. **How long an invoice lives** (rule 2): (a) **15 minutes**, renewed on the next "Pay now" while the
   order's 60 minutes last — a cancelled order's invoice stays payable 15 minutes at most
   **[Recommended]**; (b) one invoice for the whole 60 + 5 minutes. Either way, a payment on an invoice
   our side no longer offers is recorded and is a refund due — MyFatoorah cannot cancel an invoice.
10. **Staff edits while the order's first payment is open** (the 60 minutes after placing): (a)
    **blocked** — the edit waits until the order is paid or cancelled; staff see why **[Recommended]**;
    (b) allowed — the open invoice is withdrawn and the customer pays the new amount on a new one.
11. **The grace window is judged by** (a) **when the customer paid** (the gateway's time)
    **[Recommended]**; (b) when we learnt of it.
12. **May staff void an online payment record in Sales?** (sales.md §1.9 lets staff void any record
    today.) (a) **No** — a real gateway payment cannot be voided (a TEST one can); a mistake is fixed by
    a refund **[Recommended]**; (b) yes, with a reason, as today.
13. **Payments that need a person** — a mismatch, paid twice, `UNKNOWN` for more than an hour: (a) a
    count on the menu and on the admin home until someone opens each **[Recommended]**; (b) only in the
    Payments list.

### 9.4 The second round of 2026-10-27

1. **Where test mode runs** — answered: the system acts as usual, TEST on any store, said at checkout;
   testing on the staging subdomain before the real domain opens (§1.6).
2. **Telling the Super Admins before the key expires** — answered: (a) (§1.2).
3. **Orders placed while a store is in TEST** — open: (a) **every order placed while the store is in
   TEST is a test order** (whatever the way to pay): numbered apart (`TEST-0001`), so real order numbers
   still start at `SA-0001` on launch day; marked TEST everywhere, left out of every figure, and never
   marked shipped **[Recommended]**; (b) only orders paid online in TEST are test orders, numbered as
   usual — the staging orders then take `SA-0001` onwards.
4. **The staging data on launch day** — open: (a) the staging database becomes the real one: products,
   prices, stores and staff are kept; the test orders stay, marked TEST and outside every figure; the
   keys and the MyFatoorah notice address are entered again for the real domain **[Recommended]**;
   (b) a new empty database, and everything entered again.
