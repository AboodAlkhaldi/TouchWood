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

**Dependencies (handoff §4.4):** Payments → Platform. Access only to declare its permissions. Payments
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
2. **One open invoice per order and amount.** An order waiting for online payment has **one** gateway
   invoice, created once and **reused** for every "Pay now" until it expires — the customer is sent back
   to the same page, so a second tab or a retry can never create a second charge (**[PROPOSED]**; it
   follows from MyFatoorah having no way to cancel an unpaid invoice — notes §7). A new invoice is
   created only for a new amount (a staff "further payment" request, §1.5) or after the old one expired.
3. **Creating an invoice is safe to repeat.** Every create carries an **idempotency key** made once and
   stored before the call — `Idempotency-Key` on `POST /v3/payments`, available in Saudi Arabia, kept 250
   minutes by MyFatoorah (notes §6). A timeout, a crash or a double click repeats **the same key** and
   gets **the same invoice**. A key is **never reused for another amount** (MyFatoorah answers a reused
   key with its first answer even when the body changed — notes §6).
4. **Success is final.** Once the gateway says succeeded, nothing turns it back (MyFatoorah: "the
   Success Payment Status is final" — notes §4). A failure after a success is ignored; a success after a
   failure wins.
5. **Recorded once.** A success is recorded under the attempt's row lock, keyed by the gateway's
   payment id, so two notices, a notice and the customer's return, or two workers record it once. Sales
   records it once too (`processed_events`, and the gateway payment id unique on its side).
6. **The amount and the currency are checked.** What the gateway says was paid is compared with the
   invoice: the same currency and the same amount. A difference is **recorded as it really was** and the
   order is **flagged for staff** (owner, 2026-10-27 P20) — never silently accepted, never thrown away.
7. **Nothing is lost when the gateway cannot be reached.** A status call is retried 3 times, 5 seconds
   apart, with a 30-second timeout (MyFatoorah's own guidance — notes §4.8); then the attempt is
   **`UNKNOWN`** and a background check asks again (rule 8). The customer is told "we are confirming your
   payment", never "failed".
8. **A background check closes every loose end** (owner, 2026-10-27 P27): every few minutes it asks the
   gateway about every attempt still open or `UNKNOWN`, and **once more after each invoice's expiry plus
   the grace window**, so a payment whose notice was lost is still found. A nightly pass looks again at
   the last 3 days' attempts that did not succeed (**[PROPOSED]**).
9. **No card data ever touches our server** (owner, 2026-10-19 P3): the customer pays on **MyFatoorah's
   hosted page** (owner, 2026-10-27 P24). We keep no card number, no CVV, no card token.
10. **Test money is never real money** (owner, 2026-10-27): a payment made while the store's gateway is
    in test mode is saved as **TEST** — see §1.6 and question §9.3 #3.
11. **The customer never sends an amount.** The amount comes from Sales, from the order (handoff §2).
12. **Every call to the gateway is logged** — the request without secrets, the answer, the time, how
    long it took — on the attempt, for staff (owner, 2026-10-19 P15).

### 1.2 A store's gateway

- A store has **at most one gateway** today; MyFatoorah is the only adapter (owner, 2026-10-17). More
  come as adapters with nothing in Sales changing (sales.md §1.9, answer 38). **Tamara is a separate
  gateway, later** (owner, 2026-10-27 P19), and admins will choose which stores each gateway serves.
- **Only a Super Admin** sets it up (owner, 2026-10-19 P11): the gateway, **the live key**, **the test
  key**, **the notice (webhook) secret** for each, and **test or live** (owner, 2026-10-19 P13: Super
  Admin only).
- **The keys are new MyFatoorah API keys** (owner, 2026-10-27), not the legacy one — a legacy key
  "will remain valid until it expires", then the new kind is required (notes §1.4). A new key has an
  expiry date: the setup asks for it, and the panel warns **30 days before** (**[PROPOSED]**).
- **The keys and secrets are kept encrypted** with the app's key in Payments' own table, as Sync keeps
  its key (sync.md §1.2) — Platform's settings never hold a secret (platform.md §1.3). **Never shown
  again**: the page shows only **the last 6 characters** (owner, 2026-10-19 P12) and when it was set.
- **Saving tests the key first**: one small call (the methods list — notes §3.1) to that mode's address;
  if it fails, nothing is saved and the reason is shown (as Sync, sync.md §1.2).
- **The addresses are fixed per gateway and mode**, not typed: MyFatoorah test
  `https://apitest.myfatoorah.com`, KSA live `https://api-sa.myfatoorah.com` (notes §1.1). A store's
  country picks its live address.
- **Switching test ↔ live** (owner, 2026-10-27, P17): the Super Admin flips it; the store then uses the
  other key and address. Attempts already open finish in the mode they started in.
- **A store with no gateway, or a gateway switched off, goes live anyway** (owner, 2026-10-18):
  individuals are offered "staff will contact you" only and staff record the payment by hand, as Sales
  does today (sales.md §1.9). **The settings page says so** in Payments' line (platform.md §1.3, a
  module's line): "Online payment: off — individuals pay through staff until a gateway is set up"
  (owner, 2026-10-18). In test mode it says "Online payment: TEST mode — no real money is taken".

### 1.3 The ways to pay online

- **The methods on the KSA account** (owner, 2026-10-27): Visa, Mastercard, Mada, Apple Pay, Apple Pay
  (Mada), STC Pay. Google Pay is not on. **MyFatoorah's fee is on the vendor** for every method (owner,
  2026-10-27), so the customer pays exactly the order's total (owner, 2026-10-19 P14) — and Payments
  checks it (§1.1 rule 6: with the fee on the customer, MyFatoorah would charge more — notes §3.5).
- **Who picks the method** — question §9.3 #1. The owner's lean (2026-10-27): **MyFatoorah's page shows
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

1. **`start`** — Sales passes: the order id, the amount, **the deadline** (placed + 60 minutes, Sales's
   setting — owner, 2026-10-19 P2), the purpose (`ORDER` or `FURTHER`, §1.5), the customer's name,
   email and phone, the language, and where to send the customer back.
2. Payments, **in one transaction**: if the order already has an open attempt for this amount and
   purpose, it is **returned as it is** (rule 2); otherwise a new attempt row is written — with its
   **idempotency key** — before any call.
3. **The call**: `POST /v3/payments` with the amount (in SAR, two decimals — owner, 2026-10-27), our
   attempt id as `Customer.Reference`, the expiry (`PaymentExpiry`, UTC — the deadline plus the grace
   window, **65 minutes**, owner 2026-10-27 P5), the language, and our return address. The answer's
   `InvoiceId` and `PaymentURL` are saved on the attempt. A timeout or 5xx → the call is repeated with
   the same key (rule 3), up to 3 times; still nothing → the attempt is `UNKNOWN`, the customer sees
   "We could not reach the payment page — try again in a minute", and the next "Pay now" repeats the same
   key.
4. **The customer is sent to `PaymentURL`** and pays there.
5. **The customer comes back** to our return address with `?paymentId=` (notes §2.3): Payments asks
   the gateway (rule 1) and sends the customer to their order page, which shows **Paid**, **Not paid —
   Pay now**, or **We are confirming your payment** (it refreshes by itself).
6. **The notice** (webhook v2) arrives — usually before the customer (notes §2): its signature is
   checked (HMAC-SHA256, base64, header `MyFatoorah-Signature`, the secret of the store and mode —
   notes §5.4); a bad signature is answered 401 and logged; a good one is **deduplicated on its
   `Event.Reference`** (notes §5.6), then Payments asks the gateway (rule 1). The notice is answered 200
   once stored, so MyFatoorah does not retry it.
7. **The answer is applied** under the attempt's lock: succeeded → the transaction is recorded,
   `PaymentSucceeded` goes to the outbox; failed → the attempt stays open (the customer may pay again on
   the same page until it expires) and `PaymentFailed` goes to the outbox once per failed try
   (**[PROPOSED]**: Sales only shows "the last try failed"). An invoice is paid when **any** of its tries
   succeeded (notes §4.6).
8. **Expiry**: at the invoice's expiry Payments asks the gateway one last time (rule 8). Nothing paid →
   the attempt is `EXPIRED` and `PaymentExpired` goes out (Sales has already cancelled the order at 60
   minutes; the 5 extra minutes are the grace window — §1.7).

**Pay again**: the order page's "Pay now" calls `start` again — the same open invoice is returned and
the customer goes back to the same page (rule 2). As many times as they like within the time (owner,
2026-10-27 P18), each limited by a rate limit (§1.8).

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
- A request that is still open when the amount changes again is **withdrawn** (it no longer matches) and
  a new one is needed (**[PROPOSED]**). Withdrawn means: no longer shown; if the customer pays it anyway
  (MyFatoorah cannot cancel an invoice — notes §7), the money is recorded and becomes a refund due if
  too much (rule 6).
- An order whose shipping fee is "to be set" (shipping.md §1.2, `NOT_COVERED`/`OUTSIDE`) is paid
  **through staff only** (owner, 2026-10-19 S10), so no request is offered there (**[PROPOSED]** — see
  §9.3 #5).

### 1.6 Test mode

(owner, 2026-10-27, P17 and the safety rule, confirmed)

- The Super Admin switches the store's gateway to **TEST**: the test key and `apitest.myfatoorah.com` are
  used, with MyFatoorah's test cards (notes §1.8). The whole flow can be tried: place, pay, fail, retry,
  cancel, revive.
- **Every attempt and transaction carries its mode.** A **TEST** payment never counts as real money: it
  is marked TEST on the order, in every list and on the receipt, and is **left out of every money figure**
  (home cards, reports). How Sales treats an order paid in TEST — question §9.3 #3.
- While a store is in TEST: a banner on every admin page of that store, and the settings line (§1.2).
- In TEST, MyFatoorah converts to KWD (notes §1.9): the amount check (rule 6) compares the **pay
  currency** value, not the base currency.

### 1.7 The 60 minutes and the grace window — Payments' part

(owner, 2026-10-19 P2, P5; 2026-10-27 P5: 5 minutes, a setting)

- The order's time to pay (60 minutes) and the grace window (5 minutes) are **Sales's settings**, and
  Sales cancels the order when the time runs out (Sales amendment, §9.2).
- Payments' part: the invoice **expires at the end of the grace window** (`PaymentExpiry` = deadline + 5
  minutes); and **every success is reported**, whenever it arrives — Sales decides: inside the grace
  window and the order was cancelled **by the timer** → revived and paid; otherwise → recorded, and a
  refund due for staff (owner, 2026-10-19 P5).
- What MyFatoorah does with a payment after `PaymentExpiry` is **not in their documentation** (notes
  §7); the owner expects the page to stop taking payment. Either way, rule 8 still asks after the
  expiry, so a late success is never lost.

### 1.8 Our own limits

(owner, 2026-10-27: "we can set something from us as the community does")

- "Pay now": **10 per order per 10 minutes** and **30 per customer per hour** (**[PROPOSED]**).
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

    /** A payment as Payments recorded it — read by Sales when an event arrives with ids only. */
    public function payment(string $paymentId): ?PaymentDto;

    /** The order's attempts and their calls, for staff (§1.1 rule 12). */
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
- **`PaymentDto`**: `paymentId`, `orderId`, `amount` (what was really paid), `expected` (what was asked),
  `method` (as the gateway names it — Mada, Visa, Apple Pay…), `reference` (the gateway's payment id),
  `mode` (`LIVE`, `TEST`), `succeeded`, `succeededAt`, `mismatch` (bool, §1.1 rule 6).
- **`OnlinePaymentDto`**: `available` (bool), `mode` (`LIVE`, `TEST`, null), `methodLogos` (list), `reason`
  when not available (`NO_GATEWAY`, `GATEWAY_OFF`, `COMPANY`).
- **`PaymentAudience`** stays (`INDIVIDUAL`, `COMPANY`). `PaymentMethodDto` and `methods()` go: the
  method is picked on MyFatoorah's page (question §9.3 #1).
- **`BankAccountDto`** moves from B2B unchanged.

### 2.2 Events (`Public/Events`, ids only, through the outbox — handoff §4.5)

| Event | When |
|---|---|
| `PaymentSucceeded` (`eventId`, `paymentId`, `orderId`, `occurredAt`) — exists | The gateway confirmed a success (§1.4 step 7) — once per transaction |
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
CREATING ─▶ UNKNOWN ─▶ OPEN                   (the create call's answer was lost; the same key again)
OPEN ─▶ UNKNOWN ─▶ OPEN | SUCCEEDED           (a status call failed; asked again later)
OPEN ─▶ EXPIRED                               (expiry passed, the gateway confirms nothing paid)
OPEN ─▶ WITHDRAWN                             (a further request replaced — §1.5)
EXPIRED | WITHDRAWN ─▶ SUCCEEDED              (a late success: recorded all the same — §1.7)
```

A failed try is a **transaction** of the attempt, not a state: the attempt stays `OPEN`.

**A gateway**: `NOT SET UP` → (SetUpGateway, the key tested) → `ON` ⇄ `OFF`; mode `TEST` ⇄ `LIVE`.

### 4.1 The admin screens (built by the screens session — owner, 2026-10-19)

| Screen | What |
|---|---|
| **Payment gateway** — `/admin/payments/gateway?store=` (Super Admins) | The gateway, mode, keys (last 6 shown), the key's expiry, the notice address to paste into MyFatoorah, on/off; the TEST banner |
| **Payments** — `/admin/payments?store=&state=` | Every online attempt: order, amount, method, state, mode, time; filters (§3) |
| **An attempt** — `/admin/payments/{id}` | Its tries, the gateway's ids, every call made (§1.1 rule 12), "Ask the gateway now" |
| **On the order** (Sales's screen) | The attempts block; "Request payment of the rest" / "Handle by hand" (§1.5); the overdue mark (§1.9) |
| **The bank account** | Moves from B2B's settings section to Payments' (§1.9) |

---

## 5 · Tables

Schema `payments`. Money in the smallest unit, `bigint`. Store-scoped rows name their store.

| Table | Columns |
|---|---|
| `payments.gateways` | `store_id` PK FK `platform.stores` RESTRICT · `gateway` CHECK (`MYFATOORAH`) · `active` bool · `mode` CHECK (`TEST`, `LIVE`) · `live_key_encrypted` NULL, `live_key_last6` NULL, `live_key_expires_on` NULL · `test_key_encrypted` NULL, `test_key_last6` NULL, `test_key_expires_on` NULL · `live_secret_encrypted` NULL · `test_secret_encrypted` NULL · `created_by` · `created_at`, `updated_at` |
| `payments.attempts` | `id` ULID PK · `store_id` · `order_id` (Sales's, no key) · `order_number` · `purpose` CHECK (`ORDER`, `FURTHER`) · `mode` · `gateway` · `currency_code` · `amount_minor` · `state` CHECK (§4) · `idempotency_key` uuid UNIQUE · `invoice_id` NULL UNIQUE (per gateway) · `payment_url` NULL · `pay_by` · `expires_at` · `succeeded_at` NULL · `paid_minor` NULL · `mismatch` bool · `requested_by` NULL (staff, for FURTHER) · `created_at`, `updated_at` · **one open attempt per order and purpose** (a unique partial index on `order_id, purpose` WHERE `state IN ('CREATING','OPEN','UNKNOWN')`) |
| `payments.transactions` | `id` bigint PK · `attempt_id` FK · `gateway_payment_id` UNIQUE (per gateway) · `status` (as the gateway says) · `succeeded` bool · `method` · `amount_minor`, `currency_code` (the pay currency) · `error_code` NULL, `error_message` NULL · `seen_at` |
| `payments.notices` | `id` bigint PK · `store_id` · `mode` · `event_reference` UNIQUE (per gateway) · `signature_ok` bool · `received_at` · `payload` jsonb (no secrets — MyFatoorah's notice holds none) · `handled_at` NULL — kept 90 days (**[PROPOSED]**) |
| `payments.calls` | `id` bigint PK · `attempt_id` NULL · `store_id` · `endpoint` · `request` jsonb (the key and secrets never stored) · `http_status` NULL · `response` jsonb NULL · `error` NULL · `took_ms` · `at` — kept 1 year (**[PROPOSED]**) |

Settings (Platform, declared by Payments): `payments.transfer.check_hours` (STORE, 1–720, default **72**
— owner 2026-10-27), and the bank account's three, moved from B2B (`payments.bank.iban`,
`payments.bank.name`, `payments.bank.holder`, "may be empty", sensitive).

---

## 6 · Events

| Consumed | From | Payments |
|---|---|---|
| `StoreCreated` | Platform | Nothing: a new store has no gateway |
| `OrderCancelled` | Sales | An `ORDER` attempt still open is left open (the grace window, §1.7) — nothing to do; a `FURTHER` request is withdrawn (**[PROPOSED]**) |

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
20. **Logs**: every call logged without the key or the secret; pruned after a year.

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
| — | New API keys, not the legacy one; methods on: Visa, Mastercard, Mada, Apple Pay, Apple Pay (Mada), STC Pay; fee on the vendor; SAR two decimals; no min/max | 2026-10-27 |

### 9.2 What other modules must change (written as their amendments in this PR)

1. **Sales** — after placing an individual's online order, `start` and send the customer to the page;
   the order's **time to pay** (60 minutes) and **grace window** (5 minutes), as Sales settings; the
   timer's cancellation (a reason of its own: "payment not completed"); the **revive** inside the grace
   window (re-hold stock, coupon and points — and if any fails, it stays cancelled and the money is a
   refund due); `PaymentSucceeded` recorded as an `ONLINE` payment (once, by gateway payment id), with
   TEST and mismatch carried; **further-payment requests** on the order; the overdue transfer mark; "Pay
   now" on the order page; online never offered for a "shipping fee to be set" order; the ways to pay
   read from `availability()`; `bankAccount` read from Payments.
2. **B2B** — the bank account and its settings line move to Payments (b2b.md §2.3, amendment).
3. **Access** — a **Payments** permission group.

### 9.3 Still open — for the owner

1. **Who picks the method?** (a) **MyFatoorah's page** shows every method on the account; our checkout
   shows one "Pay online" with the logos — the owner's lean, nothing to keep in step with MyFatoorah's
   account **[Recommended]**, confirmed in the test account first (§1.3); (b) our checkout lists the
   methods.
2. **Does a paid online order still wait for staff approval before it ships?** Every order passes staff
   approval today (sales.md §1.4). (a) Yes — paying first does not skip approval; staff approve, then
   ship **[Recommended]**; (b) a paid online order is approved by itself.
3. **An order paid in TEST mode**: (a) it moves like a paid order — so the whole flow can be tested — but
   is marked TEST everywhere, **cannot be marked shipped**, and is left out of every money figure
   **[Recommended]**; (b) it stays unpaid in Sales and only the attempt shows the test success.
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
