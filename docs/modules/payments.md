# Payments — module specification

**Status (2026-10-28): draft for the owner.** Payments takes money online through a gateway —
**MyFatoorah for the KSA store**, the only one chosen (owner, 2026-10-17) — **on our own checkout page**
(MyFatoorah's embedded form, owner 2026-10-28), and tells Sales when money arrived. It also holds each
store's **bank account** for transfers (moved from B2B, owner 2026-10-19 P10) and the **target time to
check a transfer**. It is built from handoff §12.4, the contract Sales declared for it (sales.md §2.4,
in code since S7b), the owner's answers of 2026-10-17 to 2026-10-28 (§9.1) and MyFatoorah's
documentation **read in full on 2026-10-28** — all 212 pages
([payments-myfatoorah-notes.md](payments-myfatoorah-notes.md): §0 is that read; every gateway fact below
is from there, with its page). Points marked **[PROPOSED]** are mine, stated for the owner to reject;
the open questions are in §9.6.

**The owner's charge for this module (2026-10-17):** "the payment must be so secure and ran over all
the usecases so we dont charge same order twice or charge on nothing or if had a error in connection or
any of these stuff we have to follow a strong methodology". §1.1 is that methodology; §8 tests every
case of it. **And (2026-10-28): the payment step is "the most critical page ever"** — going back,
closing the tab, opening it twice, or running out of time must leave nothing behind, "like the user
didn't press the pay button".

**Dependencies (handoff §4.4):** Payments → Platform. **Payments → Access only to declare its
permissions** (accepted by the owner, 2026-10-28) — a handoff §4.4 amendment, as Catalog, Pricing,
Inventory and Sync were allowed (2026-10-02/07). Payments **never reads Sales**: Sales hands it an id
(a checkout try's or an order's), an amount and the customer's contact details, and Payments answers
with events. Every order decision (creating it, releasing what a try reserved, a refund due) stays in
Sales.

## What Payments does not own

| Thing | Owner |
|---|---|
| The order, what is due, what is paid, the payment state the customer sees | Sales (sales.md §1.4, §1.9) |
| **The checkout try** — what it reserves (stock, coupon, points), the order created when it is paid, everything released when it is not | Sales (sales.md §9.5 #4–#6) — Payments tells it what happened |
| Recording a payment by hand, every refund, voiding a record | Sales — **refunds are always done by staff outside the system and recorded in Sales** (handoff §12.4; owner, 2026-10-19 P7) |
| The transfer's document (the receipt the company uploads) | Sales (sales.md §1.9) |
| Which ways to pay an account type gets | Sales (sales.md §1.9) — Payments only says whether online payment is available |
| Customer and staff emails or SMS ("payment received") | Ops (stage 8) — until then the pages say it (owner, 2026-10-27 P25) |

---

## 1 · Aggregates and invariants

### 1.1 The safety rules — never twice, never on nothing, never lost

These hold for every gateway, and every one has its tests in §8.

1. **The gateway's own answer is the only truth.** A payment is succeeded only when **our server** asked
   the gateway and it said so (MyFatoorah: `GET /v3/payments/{paymentId}`, or the invoice with its
   transactions — notes §4). The form's message on our page, the customer's return to our address and
   the gateway's notice (webhook) are only **signals to ask**; none is ever believed on its own.
   (MyFatoorah's notice signs five fields and **not the amount** — notes §5.)
2. **No order until the money is confirmed** (owner, 2026-10-28). Pressing Pay starts **a checkout try**
   (Sales's): it reserves the stock, the coupon and the points for **5 minutes** and asks the gateway to
   charge. **Paid → the order is created**, with the next order number. **Not paid → everything the try
   reserved goes back, as if Pay had never been pressed** — no order, no number, nothing counted.
3. **One payment at a time, and each session pays once.**
   - **One live try per cart** (Sales) and **one open attempt per try or order** (Payments, a unique
     index): a second tab, a duplicated tab or a double click can never start a second payment while one
     is live.
   - **MyFatoorah's session "is valid for only one payment"** (notes §0 #4): the form on a page can
     charge once, whatever the browser does.
   - **After a paid try the cart no longer holds what was bought**, so another tab has nothing left to
     pay for.
   - **A second success is still recorded** if it ever happens (MyFatoorah's invoice is "PAID if there
     is a successful transaction" — notes §4): flagged **paid twice**, the extra a refund due — never
     swallowed.
4. **Charging is safe to repeat.** The charge (`POST /v3/payments`) carries an **idempotency key** made
   once and **committed before the call** — `Idempotency-Key`, available in Saudi Arabia, kept 250
   minutes by MyFatoorah (notes §6). A timeout, a crash or a retry repeats **the same key** and gets
   **the same invoice**. A key is **never reused for another amount** (a reused key returns its first
   answer even when the body changed — notes §6). Opening a session is not idempotent and needs not be:
   a session charges nothing until our server makes the charge with it.
5. **Success is final.** Once the gateway says succeeded, nothing turns it back ("the Success Payment
   Status is final" — notes §4). A failure after a success is ignored; a success after a failure wins.
6. **Recorded once — per successful try.** Every successful transaction (keyed by the gateway's payment
   id, unique per gateway and mode) is recorded once, under the attempt's row lock, so two notices, a
   notice and the page's message, or two workers record it once. **Events and `payment()` are per
   transaction.**
7. **Everything is matched on our side, never taken from the visitor.**
   - **The session** a page pays with must be one **we** opened, for **this** buyer's checkout (or this
     order's request), in this store and mode, not expired and not used — a session id from another
     page, buyer or store is refused.
   - **The attempt** an answer belongs to is found from the invoice the gateway names, and the invoice's
     `ExternalIdentifier` (our attempt id, set when charging — signed in the notice, notes §5.4) must
     match. A visitor bringing someone else's `paymentId` can never get it applied to their own cart or
     order.
   - **The browser message** from the bank-code frame is accepted only from MyFatoorah's address and
     only when its sender is exactly `MF-3DSecure` (notes §0 #8) — and even then it only makes our
     server ask (rule 1).
8. **The amount and the currency are checked.** What the gateway says was paid is compared with the
   attempt, parsed exactly as a decimal (never a floating-point number). **A different amount** in the
   right currency is **recorded as it really was** and **flagged for staff** (owner, 2026-10-27 P20).
   **A different currency** (in LIVE the base currency must be SAR) is **kept in Payments, flagged, and
   never sent to Sales**, so foreign money never enters an order's books.
9. **Nothing is lost when the gateway cannot be reached — and nothing polls for nothing.**
   - A call is retried 3 times, 5 seconds apart, with a 30-second timeout (MyFatoorah's guidance — notes
     §0 #16); then the attempt is **`UNKNOWN`** and the customer sees "We are confirming your payment",
     never "failed".
   - **Each attempt carries its own few checks**, booked when it starts and stopping as soon as it has
     an answer: at the end of the 5 minutes, at the end of the wait (§1.7), then 6 and 20 minutes after
     the charge (**[PROPOSED]**: MyFatoorah stops retrying its notice after at most 5 tries 180 seconds
     apart — notes §0 #15 — so 20 minutes covers a notice that never comes). **When nobody is paying,
     nothing runs** (the owner, 2026-10-28: no lookups "hoping to find anything").
   - **One backstop**: once an hour, a single query looks for attempts still unanswered after their
     last check (a lost job) and checks them (**[PROPOSED]**, question §9.6 #4). With none, it ends at
     once.
10. **No card data ever reaches our server** (owner, 2026-10-19 P3). The card form is **MyFatoorah's,
    drawn inside our page** ("MyFatoorah handles all PCI DSS requirements" — notes §0 #3); our page and
    server see at most the masked number and the brand, and **keep only the brand and the method**. No
    card number, no CVV, no token is stored. The form's script is loaded only from MyFatoorah's own
    address for the mode (`sa.myfatoorah.com` live, `demo.myfatoorah.com` test), and the bank-code frame
    opens only addresses on `*.myfatoorah.com` over HTTPS.
11. **Test money is never real money** (owner, 2026-10-27) — §1.6.
12. **The customer never sends an amount.** The amount comes from Sales — the checkout's quote, or the
    order's request — and is set by our server on the session and on the charge (handoff §2).
13. **Every call to the gateway is logged** — the request and the answer, the time, how long it took —
    on the attempt, for staff (owner, 2026-10-19 P15). **Never logged**: the key, the secret, the
    `Authorization` header (also kept out of exception reports); the card details a notice or an answer
    carries are dropped before anything is stored. The customer's name, email and phone are kept only on
    the attempt and are **cleared when the customer is anonymized** (Access's `CustomerAnonymized` —
    §6), in the calls and the notices too.
14. **A key is kept until nothing needs it.** Changing a key, turning the gateway off or switching the
    mode never strands an open attempt: the old key and secret of a mode are kept (encrypted) until that
    mode's attempts are all closed; their checks go on while the gateway is off (owner, 2026-10-27,
    §9.5 #4), and notices are still accepted for them.

### 1.2 A store's gateway

- A store has **at most one gateway** today; MyFatoorah is the only adapter (owner, 2026-10-17). More
  come as adapters with nothing in Sales changing (sales.md §1.9, answer 38). **Tamara is a separate
  gateway, later** (owner, 2026-10-27 P19), and admins will choose which stores each gateway serves.
- **Only a Super Admin** sets it up (owner, 2026-10-19 P11): the gateway, **the live key**, **the test
  key**, **the notice (webhook) secret** for each, and **test or live** (owner, 2026-10-19 P13: Super
  Admin only).
- **The keys are new MyFatoorah API keys** (owner, 2026-10-27), not the legacy one — a legacy key
  "will remain valid until it expires", then the new kind is required (notes §1.4). The key needs the
  permissions for sessions, payments and invoices; MyFatoorah answers a missing one with 401 "The token
  does not have the required permissions!" (notes §0 #18).
- **The key's expiry date** (owner, 2026-10-27, §9.3 #7): the Super Admin **chooses it in MyFatoorah's
  portal when creating the key** (notes §1.5); MyFatoorah gives no call that reads it back, so **the
  setup asks for it** when the key is pasted, and it is required. **The Super Admins are told before it
  expires**, so a renewal never stops the shop (owner, 2026-10-27, §9.4 #2: (a)): 30, 7 and 1 days
  before, a banner on the gateway page and on their admin home, and a staff notification on a new topic,
  **Payment problems** (access.md amendment 78), sent as Sync's are — in the panel and by email once Ops
  sends notifications.
- **A key MyFatoorah refuses anyway** (expired, switched off or deleted in the portal — a 401, or the
  user who created it disabled, which "will disable the token key" — notes §0 #18): the store's online
  payment **stops by itself** (accepted, 2026-10-28) — `availability()` answers not available
  (`KEY_REJECTED`), so individuals' orders keep coming in as "staff will contact you", as in a store
  with no gateway (owner, 2026-10-18) — and the Super Admins are told at once, by the same banner and
  topic. Open attempts stay `UNKNOWN` and keep their reservations (§1.7); once a new key is saved, the
  checks resume with it and online payment comes back.
- **The keys and secrets are kept encrypted** with the app's key in Payments' own table, as Sync keeps
  its key (sync.md §1.2) — Platform's settings never hold a secret (platform.md §1.3). **Never shown
  again**: the page shows only **the last 6 characters** (owner, 2026-10-19 P12) and when it was set.
- **Saving tests the key first**: one small call (the methods list — notes §3.1) to that mode's address;
  if it fails, nothing is saved and the reason is shown (as Sync, sync.md §1.2).
- **The addresses are fixed per gateway and mode**, not typed: MyFatoorah test
  `https://apitest.myfatoorah.com` with the script from `https://demo.myfatoorah.com`, KSA live
  `https://api-sa.myfatoorah.com` with the script from `https://sa.myfatoorah.com` (notes §0 #3, §1.1). A
  store's country picks its live addresses.
- **Switching test ↔ live** (owner, 2026-10-27, P17): the Super Admin flips it; the store then uses the
  other key and addresses. Attempts already open finish in the mode they started in (rule 14). **TEST
  may be used on any store** (owner, 2026-10-27, §9.4 #1 — §1.6).
- **Each store and mode has its own notice address** — `/payments/notice/{store}/{mode}` — sent with
  every charge (`IntegrationUrls.Webhook`, notes §5.1) and pasted into that mode's MyFatoorah portal,
  so the right secret is known before the signature is checked. Its retries are set in the portal: the
  most MyFatoorah allows — 5 retries, 180 seconds apart (notes §0 #15).
- **Apple Pay on our page needs each domain verified** (notes §0 #11): a file MyFatoorah's support
  (tech@myfatoorah.com) sends, served at `/.well-known/apple-developer-merchantid-domain-association`
  on the staging domain and on the real domain, **kept there** ("Apple might need to revalidate the
  domain on a yearly basis"), then registered with MyFatoorah's `RegisterApplePayDomain`. Until a
  domain is verified, the form there shows no Apple Pay; nothing else changes. The file is a public
  file of MyFatoorah's, not a secret; the gateway page shows whether the current domain is registered.
- **A store with no gateway, or a gateway switched off, goes live anyway** (owner, 2026-10-18):
  individuals are offered "staff will contact you" only and staff record the payment by hand, as Sales
  does today (sales.md §1.9). **The settings page says so** in Payments' line (platform.md §1.3, a
  module's line): "Online payment: off — individuals pay through staff until a gateway is set up"
  (owner, 2026-10-18). In test mode it says "Online payment: TEST mode — no real money is taken".

### 1.3 The ways to pay online — on our checkout page

(owner, 2026-10-28: "we can go with the embedded way"; MyFatoorah calls it "Embedded Payment
(Recommended)" — notes §0 #1)

- **MyFatoorah's form is drawn inside our checkout page** (and inside the payment-request page, §1.5):
  the customer never leaves our site. **The bank's code step** (3-D Secure, "OTP") opens **in a frame
  inside our page** (notes §0 #8); Apple Pay opens the phone's own sheet; STC Pay asks its code inside
  the form.
- **The methods on the KSA account** (owner, 2026-10-27): **cards — Visa, Mastercard, Mada** (the form's
  card networks are set to `visa`, `masterCard`, `mada` — notes §0 #10), **Apple Pay** (Mada cards in the
  wallet included) and **STC Pay**. Google Pay is not on. **The customer picks inside the form**; our page
  shows nothing to keep in step with MyFatoorah's account.
- **MyFatoorah's fee is on the vendor** for every method (owner, 2026-10-27), so the customer pays exactly
  the total (owner, 2026-10-19 P14) — and Payments checks it (rule 8).
- **The money is taken at once** (owner, 2026-10-28) — `OperationType` `PAY`. MyFatoorah's "authorize,
  then capture or release" is **not used** (owner, 2026-10-28; why it was asked: §9.6, answered).
- **Collect Details mode** (notes §0 #7): the form only **collects** the payment details; **our server
  makes the charge** afterwards, with the session. This puts our checks between "Pay" and the money:
  nothing is charged unless the cart, the prices and the stock are still exactly what the customer saw
  (§1.4 step 3).
- **Online is for individuals only** — companies pay by transfer or through staff, never online (owner,
  2026-10-09; sales.md §1.9).
- **No minimum or maximum per method** (owner, 2026-10-27).
- **The first build step tries every method in MyFatoorah's test account** — cards (the Mada test card),
  Apple Pay, STC Pay (test mobile and code — notes §0 #10) — in Collect Details mode, with a 5-minute
  `PaymentExpiry`, before anything is built on it: what the docs do not say (notes §0 #5, #13) is
  learnt there, and anything different from this spec goes back to the owner.

### 1.4 Paying at checkout — the try

```
 checkout opens ──▶ a session (nothing held)
       │  customer fills the form, presses Pay
       ▼
 our checks again ── something changed ──▶ stays on checkout, shown what changed
       │ all as quoted
       ▼
 reserve stock · coupon · points (5 minutes) ──▶ charge with the session
       │
       ├─▶ paid ─────────────────────▶ the order is created (its number now), cart emptied
       ├─▶ declined ─────────────────▶ everything released, back on checkout with the reason
       ├─▶ 5 minutes, nothing started ▶ everything released, "time ran out — no money was taken"
       └─▶ 5 minutes, bank still answering ─▶ the wait (§1.7) ─▶ paid or released
```

0. **The checkout page opens** (Sales has a quote — sales.md §1.2). Sales asks Payments to **open a
   session** for the quote's total: Collect Details mode, the card networks, the methods, the language
   and an expiry with the quote (15 minutes — sales.md §1.2). The form is drawn in the page. **Nothing
   is reserved and nothing can be charged** — a session charges only when our server charges with it.
   Each opening of the page has its own session; a new quote gets a new one.
1. **The customer chooses how to pay in the form and presses Pay** — our own button (notes §0 #9): the
   page checks the form (`validateCardInputs`), then asks the form to collect the details. The form
   tells our page only that the details are ready (masked).
2. **Our page sends Sales the quote's id and the session's id** — never an amount (rule 12).
3. **Sales starts the try**, in one transaction (sales.md §9.5 #4):
   - the quote and the cart are locked; **a live try on this cart** → refused, "A payment is already in
     progress in another window" (the same quote and session again — a double click — gets the same
     try);
   - **every rule of placing is checked again** (sales.md §1.3 step 3: the store on, may order, every
     line still orderable, the address, the gift, the ceiling) — **anything changed → nothing is
     reserved and the customer stays on checkout**, shown what changed, and pays only after the cart
     is right (the owner, 2026-10-28: "the pay button won't let user out to pay until adjusting cart");
   - **the stock, the coupon and the points are reserved** for the try (Inventory, Promotions, Loyalty
     — sales.md §9.5 #5);
   - the try is written: its quote, its buyer, the order's future id, and **its 5 minutes** (§1.7).
4. **Sales asks Payments to charge** (`pay`). Payments checks the session (rule 7), writes the attempt
   **with its idempotency key, committed before any call** (rule 4) — the unique index allows **one
   open attempt per try** — and calls `POST /v3/payments` with `SourceOfFund.SessionId`, the amount (in
   SAR, two decimals — owner, 2026-10-27), our attempt id as `Order.ExternalIdentifier` (rule 7), the
   try's id as `Customer.Reference`, **`PaymentExpiry` at the end of the 5 minutes** (UTC), the
   language, our return address and the store and mode's notice address. The answer's `InvoiceId`,
   `PaymentId` and `PaymentURL` are saved; the attempt is `OPEN`.
   - **A timeout or 5xx** → repeated with the same key (rule 4), 3 times; still nothing → `UNKNOWN`,
     the page says "We are confirming your payment" and the checks of rule 9 go on; nothing new can
     start on this cart until it is answered.
   - **MyFatoorah refuses the charge** (a 4xx) → the attempt is `REFUSED`, the reason logged for staff;
     Sales ends the try and releases everything; the customer is back on checkout: "The payment could
     not start — please try again". A phone number is sent only when it matches MyFatoorah's pattern —
     it is not required (notes §0 #14).
5. **The customer finishes in the form**: the bank-code page in its frame for a card (`PaymentURL`),
   the Apple Pay sheet, or STC Pay's code. **The page shows the time left.**
6. **The answer.** The frame's message (rule 7), the return to our address, the form's own callback or
   the notice — whichever comes first — makes Payments **ask MyFatoorah** (rule 1), then:
   - **Paid** → `PaymentSucceeded` → **Sales creates the order from the try** — the order number is
     taken now, the reservations become the order's, the cart loses what was bought — and the page
     shows "Order placed". Nothing is ever numbered for a try that does not pay (owner, 2026-10-28: no
     gaps in the order numbers).
   - **Declined** (the bank's reason — notes §9: a wrong code, insufficient funds, a card declined…) →
     `PaymentFailed` → Sales ends the try and releases everything; the customer is back on checkout
     with the reason **in plain words** (accepted, 2026-10-28) and may press Pay again — a new session,
     a new try, a new 5 minutes (**[PROPOSED]**, question §9.6 #2).
7. **The 5 minutes end** with no answer: Payments asks MyFatoorah.
   - **Nothing started, or nothing left in progress** → the attempt is `EXPIRED`, `PaymentNotTaken`
     goes out, Sales releases everything, and the page returns to checkout: "Time ran out — no money was
     taken."
   - **A payment still in progress** (the bank is answering) → **the wait** (§1.7).
8. **Leaving.** Back, closing the tab, going to another page: **nothing is charged unless the customer
   finished the bank's step**, and nothing at MyFatoorah needs cancelling (it cannot be — notes §0 #13;
   the session can pay once and its payment expires with the 5 minutes). The try ends by step 7 at the
   latest. **Opening the shop again** goes wherever the customer goes — never to a payment page. **Opening
   checkout again** asks MyFatoorah at once about the live try:
   - nothing in progress → **the try ends there and then**, everything released, and checkout is as it
     was — **as if Pay had never been pressed** (the owner, 2026-10-28);
   - still in progress → "Your last payment is being confirmed" with the time left — it cannot be
     pretended away while a bank may be moving money (rule 3);
   - paid → their new order.
9. **A second window.** Checkout opened in another tab, or the tab duplicated, **while a try is live**:
   the page shows "A payment is already in progress in another window" and **no form** — it cannot pay
   (rule 3). Once the try is over, either window works normally.

### 1.5 A payment request — when the order's total goes up

(owner, 2026-10-19 P6; 2026-10-27 P22, P23; 2026-10-28 Q5)

- When staff edit an order and it **holds less than is due** (sales.md §1.7), staff choose:
  **(a) ask the customer to pay the rest online** — a **payment request** — or **(b) handle it by hand**
  (a transfer or whatever they arrange; then they record it in Sales, as today).
- (a) creates a **request** for **exactly what is missing**, open **60 minutes** (P22). The customer sees
  it on their order ("Pay the remaining 45.00 SAR") — and, from stage 8, by email/SMS. Expired → nothing
  is cancelled; staff may send a new one.
- **The request has its own page** (owner, 2026-10-28: "a unique page, not as usual"):
  `/{store}/{locale}/orders/{number}/pay` — the order's number and summary, the amount asked, who asked
  it and until when, and **the same MyFatoorah form** (§1.3). **No cart, no checkout, nothing reserved**:
  the order already exists. Pressing Pay opens the same **5 minutes and the wait** (§1.7); paid → Sales
  records an `ONLINE` payment on the order; declined or out of time → the request stays open while its
  60 minutes last, and the customer may try again. The page answers only the order's own customer.
- **One open request per order**, and **one payment at a time on it** (rule 3): a second window shows
  "A payment is already in progress".
- **Only for an individual's order in a store whose gateway is on**; sent by **staff who can manage
  orders** (P23).
- **Staff edits wait while a payment is in progress on the order** (the 5 minutes and the wait at most):
  staff are told why (owner, 2026-10-27, §9.3 #10, as it now applies).
- A request still open when the amount changes again is **withdrawn** (it no longer matches) and a new
  one is needed (accepted, 2026-10-28). Withdrawn means: no longer shown; a payment already in progress
  on it is still answered and recorded, a refund due if too much (rule 8).
- An order whose shipping fee is "to be set" (shipping.md §1.2, `NOT_COVERED`/`OUTSIDE`) is **never
  offered online payment at checkout** — its total is not known (owner, 2026-10-19 S10). **Once staff set
  the fee, staff choose as above** (owner, 2026-10-27, §9.3 #5: (b)): a request for the whole amount due,
  or by hand. Only for an individual's order in a store whose gateway is on; a company's order pays by
  transfer (shipping.md §9.3 #7).

### 1.6 Test mode

(owner, 2026-10-27, P17 and the safety rule, confirmed: test payments "never count as real money")

- The Super Admin switches the store's gateway to **TEST**: the test key, `apitest.myfatoorah.com` and
  the demo script are used, with MyFatoorah's test cards (notes §0 #10, §1.8). The whole flow can be
  tried: pay, decline, retry, leave, run out of time, wait.
- **Where TEST runs** (owner, 2026-10-27, §9.4 #1): **the system acts as usual** — one code, and TEST is a
  store's gateway setting, allowed on any store, switched on. The testing happens **on the staging site**
  (a subdomain, before the real domain opens); the owner switches each store to LIVE when everything
  passes, then moves the system to the real domain.
- **A store in TEST says so to everyone** (owner, 2026-10-27): the checkout, the request page and the
  order page show "Test mode — no real money is taken", besides the admin banner below. MyFatoorah's test
  environment takes only its public test cards, so nobody can pay real money there.
- **The safety net if a real store is ever left in TEST**: a TEST payment never makes an order paid for
  real (below), so it can never send goods out unpaid.
- **Every attempt and transaction carries its mode.** A TEST payment never counts as real money: it is
  marked TEST on the order, in every list and on the receipt, and **left out of every money figure**
  (home cards, reports).
- **Every order placed while the store is in TEST is a test order** (owner, 2026-10-27, §9.4 #3: (a)),
  whatever the way to pay: numbered apart (`TEST-0001`, sales.md §9.5 #14), so real order numbers still
  start at `SA-0001` on launch day; marked TEST everywhere; left out of every figure; **never marked
  shipped**.
- **Launch day** (owner, 2026-10-27, §9.4 #4: (a)): the staging database becomes the real one —
  products, prices, stores and staff are kept, the test orders stay marked TEST. The go-live checklist
  (§9.3 #6) holds: the LIVE key and secret entered again on the real domain; the notice address in
  MyFatoorah's portal changed to the real domain's, with its retries set; **the Apple Pay file on the
  real domain and the domain registered** (§1.2); **the staging site locked from the public while it is
  used** (the owner's reminder, 2026-10-27) — **with `/payments/notice/…` and the Apple Pay file left
  open**; the scheduler and the queue worker running on the server (every module's jobs already need
  them); `TRUSTED_PROXIES` and `SESSION_SECURE_COOKIE` set for the host (handoff §15.4); **the MyFatoorah
  user who created the key never disabled** (notes §0 #18).
- While a store is in TEST: a banner on every admin page of that store, and the settings line (§1.2).
- In TEST, MyFatoorah converts to KWD (notes §1.9): the amount check (rule 8) compares the **pay
  currency** value; in TEST the currency rule of rule 8 is checked against that pay currency, in LIVE
  against SAR as the base currency.
- Test and live ids come from two separate systems, so every gateway id is unique **per gateway and
  mode**, never per gateway alone.

### 1.7 The two timers

(owner, 2026-10-28: "a 5 m for all the window, and another timer for the request when it flies and we
wait for myfatoorah to tell us, so if user clicks pay at 4.59 he can wait and still be able to see if
request is successful")

- **The window — 5 minutes** (`payments.window_minutes`, Super Admins; owner, 2026-10-28: replaces the
  60 minutes of 2026-10-19 P2). It starts **when Pay is pressed** (owner, 2026-10-28, Q4 (a)) — before
  that the checkout may stay open, nothing reserved. Inside it the customer finishes the bank's step;
  the page counts it down. MyFatoorah's own `PaymentExpiry` is set to its end, so the payment cannot be
  finished after it — **whether MyFatoorah accepts an expiry this short is not in its docs** (notes §0
  #5), and the first build step tries it (§1.3). Our page ends the window at 5 minutes either way.
- **The wait** (`payments.wait_minutes`, Super Admins; its length is question §9.6 #1). At the end of
  the window our server asks MyFatoorah: **a payment in progress** — the customer pressed the bank's
  "confirm" at 4:59 — is **waited for**: the page shows "Confirming your payment…" and the reservations
  stay. The answer comes → paid or released (§1.4 step 6).
- **When the wait ends with no answer**: the page says "We are still confirming with your bank. If the
  payment goes through, your order appears in My Orders — please do not pay again." **The reservations
  stay until MyFatoorah answers** (rule 9's checks go on), so a payment that does go through still finds
  its stock, coupon and points. **Still no answer 20 minutes after the charge** → the attempt is listed
  for staff as needing attention (owner, 2026-10-27, §9.3 #13); staff may ask the gateway again, or
  release the try by hand once they have confirmed with MyFatoorah that nothing was taken
  (**[PROPOSED]**, question §9.6 #3).
- **A success that arrives after the try was released** (MyFatoorah said nothing was in progress, and
  later reports a payment — it should not happen; rule 1 still records it): Sales tries to create the
  order as usual, taking the stock, the coupon and the points again; **if all can be taken → the order
  exists and is paid; if any cannot → no order, and the money is a refund due**, shown to staff as
  needing attention in the Payments list with the customer and the amount (owner, 2026-10-19 P7:
  refunds are staff's).

### 1.8 Our own limits

(owner, 2026-10-27: "we can set something from us as the community does")

- **Pay**: **10 per cart (or request) per 10 minutes** and **30 per customer per hour** (owner, 2026-10-27,
  §9.3 #4). Opening a session (the checkout page): **30 per buyer per hour** (**[PROPOSED]**).
- **The limits only stop abuse; they never multiply work** (the owner, 2026-10-27: ten presses must be
  one operation, not ten). While a try is live, every press — a double click, two tabs, ten presses —
  gets **the same try** or "already in progress", with no new call to MyFatoorah (rule 3); two presses
  at the same instant cannot make two tries, because only one live try per cart can be written. A press
  over the limit is answered "Too many tries — wait a few minutes" (429) and changes nothing.
- Status calls: never more than one at a time per attempt (the lock); the checks of rule 9 take **at
  most 60 attempts a minute** across the system, oldest first (accepted, 2026-10-28 — MyFatoorah's limit
  has no number in its docs: "contact the account manager to increase the rate limit", notes §4.7).
- The notice endpoint: 120 a minute per store (accepted, 2026-10-28); over it → 429, MyFatoorah retries
  (notes §5.5).

### 1.9 Bank transfers — the account and the target time

(owner, 2026-10-19 P8, P9, P10; 2026-10-27 P8, P28)

- **The store's bank account moves from B2B to Payments** (P10): the IBAN, the bank's name and the
  holder's name, with B2B's rules unchanged (b2b.md §2.3: IBAN checked; bank transfer on only while all
  three are filled in; shown only to an approved company). `B2BApi::bankAccount` becomes
  `PaymentsApi::bankAccount` (B2B and Sales amendments, §9.2). Its line in the settings moves with it.
  **Admins change it** (accepted, 2026-10-28, as B2B had it).
- **The target time to check a transfer: 72 hours** (owner, 2026-10-27), **counted from the company's
  upload of its receipt** (P28). Past it, the order is marked **overdue** in the staff's order list
  (P8 (a)); messages to staff come with Ops (stage 8).
- **"Hold stock while verifying" is dropped** (P9): every placed order already holds its stock
  (handoff §12.1).

### 1.10 A store switched off while someone is paying

(owner, 2026-10-27/28, §9.5 #2: accepted)

An off store's shop pages answer 404 to everyone (platform.md §1.6). **A try or a request already
paying when its store is switched off is still finished**: the few addresses that finish it — the
charge, the bank-code frame's return, the answer to the page — keep answering **for that try or request
only**. If it is paid, **the order is still created** (the money was taken; Sales, sales.md §9.5 #4) and
staff finish it, as for any order of an off store (platform.md §1.6: "its open orders stay with staff
to finish"). The customer is shown **one page, once**:

- paid — "Your order SA-0051 is recorded. This store is closed for now; our team will contact you.";
- not paid — "This store is closed for now. No money was taken.";

with a button to **the main store** (Platform's base store, which can never be switched off —
platform.md: `is_base`, refused with `BaseStoreAlwaysActive`). The page is never shown again for that try; every other page
of the off store stays a 404.

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

    /** A session for the form on a checkout page or a request page (§1.4 step 0). Nothing is held or charged. */
    public function openSession(SessionRequestDto $request): PaymentSessionDto;

    /** Charges with a session the page collected (§1.4 step 4). Safe to call again: the open attempt is returned. */
    public function pay(PayRequestDto $request): PaymentStartDto;

    /** A staff request for the rest of an order (§1.5), open 60 minutes. */
    public function requestPayment(PaymentRequestDto $request, string $staffId): PaymentRequestStateDto;

    /** Withdraws an order's open request (§1.5). */
    public function withdraw(string $orderId): void;

    /** The order's open request, for its page (§1.5); null when none. */
    public function openRequest(string $orderId): ?PaymentRequestStateDto;

    /** One successful (or refused) transaction as Payments recorded it — read by Sales when an event arrives with ids only. */
    public function payment(string $paymentId): ?PaymentDto;

    /** Asks the gateway now about a try's or an order's open attempt (§1.4 step 8, §1.7). */
    public function refresh(string $ownerId): PaymentStateDto;

    /** The attempts of a try or an order and their calls, for staff (§1.1 rule 13). */
    public function attempts(string $ownerId): array; // list<PaymentAttemptDto>

    /** The store's bank account, null while bank transfer is off (§1.9 — from B2B). */
    public function bankAccount(StoreId $store): ?BankAccountDto;

    /** The hours staff have to check a transfer (§1.9). */
    public function transferCheckHours(StoreId $store): int;
}
```

- **`SessionRequestDto`**: `storeId`, `ownerId` (the quote's id for checkout, the request's id for a
  request page), `buyerId`, `amount` (Money — from the quote or the request), `locale`, `expiresAt`.
- **`PaymentSessionDto`**: `sessionId`, `scriptUrl` (MyFatoorah's, for the mode), `mode` (`LIVE`,
  `TEST`), `expiresAt`, `applePay` (bool — the domain registered, §1.2).
- **`PayRequestDto`**: `ownerId` (the try's id, or the request's), `ownerKind` (`TRY`, `REQUEST`),
  `orderId` (the order's future id for a try, the order's id for a request), `storeId`, `buyerId`,
  `sessionId`, `amount` (Money — from Sales, never the page), `customerName`, `customerEmail`,
  `customerPhone`, `locale`, `returnUrl`.
- **`PaymentStartDto`**: `attemptId`, `frameUrl` (the bank-code page, null when the method needs none or
  the gateway could not be reached), `windowEndsAt`, `state`.
- **`PaymentRequestDto`**: `orderId`, `orderNumber`, `storeId`, `amount`, `customerId`.
  **`PaymentRequestStateDto`**: `requestId`, `orderId`, `amount`, `expiresAt`, `state` (`OPEN`, `PAID`,
  `EXPIRED`, `WITHDRAWN`), `requestedBy`.
- **`PaymentDto`** — **one transaction**, not one attempt (rule 6): `paymentId` (our transaction's id),
  `attemptId`, `ownerId`, `ownerKind`, `orderId`, `amount` (what was really paid), `expected`, `method`
  (as the gateway names it — Mada, Visa, Apple Pay, STC Pay…), `reference` (the gateway's payment id),
  `mode` (`LIVE`, `TEST`), `succeeded`, `paidAt` (the gateway's `TransactionDate`), `failureCode` (the
  gateway's `MF0xx`, null when succeeded), `mismatch` (bool, rule 8), `paidTwice` (bool, rule 3).
- **`PaymentStateDto`**: `state` (`NONE`, `OPEN`, `IN_PROGRESS`, `UNKNOWN`, `SUCCEEDED`, `FAILED`,
  `EXPIRED`), `windowEndsAt`, `waitEndsAt` — what the page shows, and whether Sales may release.
- **`OnlinePaymentDto`**: `available` (bool), `mode` (`LIVE`, `TEST`, null), `reason` when not available
  (`NO_GATEWAY`, `GATEWAY_OFF`, `KEY_REJECTED` — §1.2, `COMPANY`).
- **`PaymentAudience`** stays (`INDIVIDUAL`, `COMPANY`). `PaymentMethodDto` and `methods()` go: the
  method is picked in MyFatoorah's form (§1.3). `start()` goes: `openSession` and `pay` replace it.
- **`BankAccountDto`** moves from B2B unchanged.

### 2.2 Events (`Public/Events`, ids only, through the outbox — handoff §4.5)

| Event | When |
|---|---|
| `PaymentSucceeded` (`eventId`, `paymentId`, `orderId`, `occurredAt`) — exists | The gateway confirmed a successful **transaction** (§1.4 step 6) — once per transaction; `paymentId` names it; `orderId` is the order's id, the future one for a try. **Not** published for a currency mismatch (rule 8) |
| `PaymentFailed` (`eventId`, `paymentId`, `orderId`, `occurredAt`) — exists | A transaction was declined |
| **`PaymentNotTaken`** (`eventId`, `attemptId`, `ownerId`, `occurredAt`) — new | An attempt ended with nothing paid and nothing in progress: the window passed, or the gateway refused the charge (§1.4 steps 4, 7) — Sales releases the try |

### 2.3 What Payments needs from other modules

| From | What | State |
|---|---|---|
| Platform | Stores and the base store; encryption with the app's key; the settings line; the audit log; `MenuCount`; the scheduler and the queue (delayed jobs, rule 9); the outbox and `processed_events` (built by Sales); serving the Apple Pay file (§1.2) | On `main`, except the Apple Pay file (Platform amendment, §9.2) |
| Access | Declaring Payments' permissions; a **Payments** permission group | access.md amendment (§9.2) |

---

## 3 · Use cases

| Use case | Permission | Scope |
|---|---|---|
| `SetUpGateway`, `ChangeKeys`, `SwitchMode` (test ↔ live), `SwitchGateway` (on/off), `RegisterApplePayDomain` | **`payments.gateway.manage`** — reserved, Super Admins only (owner, 2026-10-19 P11, P13) | That store |
| `ChangeBankAccount` (from B2B's `platform.settings.update` rule) | **`payments.bank_account.update`** — Admins (accepted, 2026-10-28) | That store |
| `OpenSession`, `Pay` (from Sales's checkout and the request page) | The buyer's own checkout or order — Sales checks it, Payments is called inside | That store |
| `RequestPayment`, `WithdrawRequest` | **`sales.order.edit`** — staff who manage orders (owner, 2026-10-27 P23) — checked by Sales, which calls Payments | That store |
| `ReceiveNotice` (the webhook) | The signature — no staff, no customer | That store and mode |
| `ReceiveAnswer` (the frame's message or the return to our address, from the page) | The buyer's session — the attempt must be theirs (rule 7) | That store |
| `ListPayments` (all online attempts, by state — owner, 2026-10-27 P26), `ViewAttempt` (its transactions and calls — P15) | **`payments.view`** — given wherever `sales.order.view` is (accepted, 2026-10-28) | That store |
| `CheckAttempt` (staff's "Ask the gateway now") | **`payments.view`** | That store |
| `CheckAttempt` (each attempt's booked checks), `HourlyBackstop` (**[PROPOSED]**), `PrunePaymentLog` | System (reserved) | — |

Every change of a gateway is audited — never a key or a secret, only "changed" and the last 6
characters. The permissions sit in a new **Payments** group in the role editor.

---

## 4 · State machines

**An attempt** (one charge with one session — one invoice):

```
CREATING ─▶ OPEN ─▶ SUCCEEDED                  (paid — final)
CREATING ─▶ REFUSED                            (the gateway refused the charge — §1.4 step 4)
CREATING ─▶ UNKNOWN ─▶ OPEN                    (the charge's answer was lost; the same key again)
OPEN ─▶ FAILED                                 (declined — §1.4 step 6)
OPEN ─▶ UNKNOWN ─▶ OPEN | SUCCEEDED | FAILED | EXPIRED   (a status call failed; asked again)
OPEN ─▶ EXPIRED                                (the window passed, the gateway confirms nothing in progress)
FAILED | EXPIRED ─▶ SUCCEEDED                  (a success reported later: recorded all the same — rule 5, §1.7)
SUCCEEDED ─▶ SUCCEEDED                         (a second successful transaction: recorded, "paid twice" — rule 3)
```

**A request** (§1.5): `OPEN` → `PAID` | `EXPIRED` (60 minutes) | `WITHDRAWN`.

**A session**: `OPENED` → `USED` (an attempt charged with it) | `EXPIRED`.

**A gateway**: `NOT SET UP` → (SetUpGateway, the key tested) → `ON` ⇄ `OFF`; mode `TEST` ⇄ `LIVE`.

### 4.1 The screens (built by the screens session — owner, 2026-10-19)

| Screen | What |
|---|---|
| **Checkout — the payment block** (Sales's checkout page) | MyFatoorah's form; our Pay button; the time left; the bank-code frame; "Confirming your payment…"; "A payment is already in progress in another window"; "Time ran out — no money was taken"; the decline's reason; the TEST notice (§1.4, §1.6, §1.7) |
| **The payment request** — `/{store}/{locale}/orders/{number}/pay` | The order, the amount asked, until when; the same form and timers (§1.5) |
| **Store closed while paying** — shown once | §1.10 |
| **Payment gateway** — `/admin/payments/gateway?store=` (Super Admins) | The gateway, mode, keys (last 6 shown), the key's expiry, the notice address to paste into MyFatoorah, Apple Pay's domain state, on/off; the TEST banner |
| **Payments** — `/admin/payments?store=&state=` | Every online attempt: owner (a try or an order), amount, method, state, mode, time; needing attention first (§1.7); filters (§3) |
| **An attempt** — `/admin/payments/{id}` | Its transactions, the gateway's ids, every call made (§1.1 rule 13), "Ask the gateway now", "Release the try" (§9.6 #3) |
| **On the order** (Sales's screen) | The attempts block; "Request payment of the rest" / "Handle by hand" (§1.5); the overdue mark (§1.9) |
| **The bank account** | Moves from B2B's settings section to Payments' (§1.9) |

---

## 5 · Tables

Schema `payments`. Money in the smallest unit, `bigint`. Store-scoped rows name their store.

| Table | Columns |
|---|---|
| `payments.gateways` | `store_id` PK FK `platform.stores` RESTRICT · `gateway` CHECK (`MYFATOORAH`) · `active` bool · `mode` CHECK (`TEST`, `LIVE`) · the **previous** key and secret of each mode, kept until that mode's attempts close (rule 14) · `live_key_encrypted` NULL, `live_key_last6` NULL, `live_key_expires_on` NULL · `test_key_encrypted` NULL, `test_key_last6` NULL, `test_key_expires_on` NULL · `live_secret_encrypted` NULL · `test_secret_encrypted` NULL · `apple_pay_domains` text[] (registered) · `created_by` · `created_at`, `updated_at` |
| `payments.sessions` | `id` (the gateway's session id) · `store_id` · `mode` · `gateway` · `owner_id` (the quote's or the request's id) · `buyer_id` · `amount_minor` · `currency_code` · `expires_at` · `used_by` NULL (the attempt) · `created_at` · PK (`gateway`, `mode`, `id`) — rule 7 |
| `payments.requests` | `id` ULID PK · `store_id` · `order_id` (Sales's, no key) · `order_number` · `customer_id` · `amount_minor` · `currency_code` · `state` CHECK (§4) · `expires_at` · `requested_by` (staff) · `created_at`, `updated_at` · **one open request per order** (a unique partial index WHERE `state = 'OPEN'`) |
| `payments.attempts` | `id` ULID PK · `store_id` · `owner_kind` CHECK (`TRY`, `REQUEST`) · `owner_id` (the try's id or the request's) · `order_id` (the order's id — the future one for a try) · `session_id` · `mode` · `gateway` · `currency_code` · `amount_minor` · `state` CHECK (§4) · `idempotency_key` uuid UNIQUE · `invoice_id` NULL, **unique per (`gateway`, `mode`)** · `gateway_payment_id` NULL · `frame_url` NULL · `window_ends_at` · `wait_ends_at` · `next_check_at` NULL · `succeeded_at` NULL · `paid_minor` NULL · `mismatch` bool · `paid_twice` bool · `needs_attention` bool · `refused_reason` NULL · `customer_name`, `customer_email`, `customer_phone` NULL (cleared on anonymizing) · `created_at`, `updated_at` · **one open attempt per owner** (a unique partial index on `owner_id` WHERE `state IN ('CREATING','OPEN','UNKNOWN')` — rule 3) |
| `payments.transactions` | `id` bigint PK · `attempt_id` FK · `mode` · `gateway_payment_id`, **unique per (`gateway`, `mode`)** · `status` (as the gateway says) · `succeeded` bool · `method` · `card_brand` NULL (nothing else of the card — rule 10) · `amount_minor`, `currency_code` (the pay currency), `base_currency_code` · `paid_at` NULL (the gateway's `TransactionDate`) · `error_code` NULL, `error_message` NULL · `mismatch` bool · `published` bool (sent to Sales — false for a currency mismatch, rule 8) · `seen_at` |
| `payments.notices` | `id` bigint PK · `store_id` · `mode` · `event_reference` — **only notices whose signature checked out are stored**, unique per (`gateway`, `mode`); a bad one is only logged · `received_at` · `payload` jsonb (the card details and the customer's contact details removed) · `handled_at` NULL — kept 90 days (owner, 2026-10-27, §9.3 #8) |
| `payments.calls` | `id` bigint PK · `attempt_id` NULL · `store_id` · `endpoint` · `request` jsonb (the key, the secret and the `Authorization` header never stored; the customer's contact details cleared on anonymizing — rule 13) · `http_status` NULL · `response` jsonb NULL (card details removed) · `error` NULL · `took_ms` · `at` — kept 1 year (owner, 2026-10-27, §9.3 #8) |

Settings (Platform, declared by Payments): `payments.window_minutes` (STORE, 1–30, default **5** —
owner 2026-10-28, Super Admins), `payments.wait_minutes` (STORE, its default per §9.6 #1, Super
Admins), `payments.transfer.check_hours` (STORE, 1–720, default **72** — owner 2026-10-27), and the bank
account's three, moved from B2B (`payments.bank.iban`, `payments.bank.name`, `payments.bank.holder`,
"may be empty", sensitive).

---

## 6 · Events

| Consumed | From | Payments |
|---|---|---|
| `StoreCreated` | Platform | Nothing: a new store has no gateway |
| `OrderCancelled` | Sales | The order's open request is withdrawn (§1.5); a payment already in progress on it is still answered and recorded — a refund due, Sales's to show |
| `CustomerAnonymized` | Access | The customer's name, email and phone cleared from attempts, calls and notices (rule 13) |

Published: §2.2.

---

## 7 · Errors

Every error extends `PaymentsError` → `DomainError` ("payments.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `NoOnlinePayment` | CONFLICT | A session or a charge in a store with no gateway on, or for a company |
| `PaymentInProgress` | CONFLICT | A charge while another attempt of the same try or request is open (rule 3) — "A payment is already in progress" |
| `SessionNotUsable` | INVALID | A session id we did not open for this buyer and page, of another store or mode, expired, or used (rule 7) |
| `GatewayUnreachable` | **UNAVAILABLE** — a new category (HTTP 503) in Shared's `ErrorCategory` (accepted, 2026-10-28: none of today's six fits a gateway that is down) | The gateway did not answer after the retries (§1.4 step 4) |
| `GatewayRefused` | INVALID | The gateway refused the charge (its message logged, a short one shown to staff) |
| `KeyTestFailed` | INVALID | Saving a key whose test call failed — the reason named |
| `KeyPermissionMissing` | INVALID | MyFatoorah's 401 "The token does not have the required permissions!" — names what to switch on |
| `AttemptNotFound` | NOT_FOUND | An attempt that is not there, or not this buyer's |
| `RequestNotOpen` | CONFLICT | Paying a request that expired, was paid or was withdrawn (§1.5) |
| `AmountChanged` | CONFLICT | A request for an amount different from the open request's — Sales must withdraw first |
| `InvalidPaymentsInput` | INVALID | A field out of range, named |

A notice with a bad signature is answered `401` with no body: it is not a staff error. The limits of §1.8
are Laravel's rate limiting (HTTP 429), not a domain error. "Something changed in your cart" at Pay is
Sales's `QuoteChanged` (sales.md §1.3), raised before Payments is called.

---

## 8 · Test scenarios

Against a **fake MyFatoorah** (an HTTP fake that can be told to answer, time out, answer twice, answer
late, report in progress), browser tests for the checkout page, and a manual pass in MyFatoorah's test
account before going live (§9.3 #6).

1. **The happy path**: checkout → session → Pay → reserved → charged → bank step → paid → the order is
   created with the next number; `PaymentSucceeded` once; the cart loses what was bought.
2. **Declined**: the bank's reason shown in plain words; everything released; the cart as it was; Pay
   again starts a new session and try.
3. **Something changed at Pay** (a price, the stock, the coupon, the ceiling, the gift): nothing
   reserved, nothing charged, the change named; Pay works once the cart is right.
4. **Back during the bank step / the tab closed**: nothing charged; reopening checkout within the
   window with nothing in progress ends the try at once — checkout as it was, no order, the coupon's use
   not counted, the points back, the stock free.
5. **The same, but the bank step was finished before closing**: reopening shows "being confirmed", then
   the order.
6. **Opening the shop again** after closing a paying tab: goes where the customer goes; no payment page.
7. **A second tab / a duplicated tab** while a try is live: "already in progress", no form, no charge;
   after the try both tabs work.
8. **Double click on Pay / ten presses**: one try, one attempt, one charge, one call (rule 3, §1.8).
9. **Pay at 4:59 of the window** (the owner's case): the bank still answering at 5:00 → the wait → paid
   → the order; or declined → released.
10. **The window passes with nothing started**: released; "Time ran out — no money was taken";
    `PaymentNotTaken`.
11. **The wait passes with no answer**: the "still confirming" page; reservations kept; paid later →
    the order; 20 minutes → needing attention.
12. **The charge times out**: the retry with the same key gets the same invoice; no second invoice.
13. **The notice is lost**: a booked check finds the success.
14. **The notice comes twice / three times; the notice and the frame's message at the same second**:
    recorded once (the lock).
15. **A forged notice; a forged frame message** (wrong address, wrong sender): nothing believed; at most
    we ask the gateway, and it says the truth.
16. **Someone else's session id / paymentId** on our page: refused; nothing applied to the visitor (rule 7).
17. **Failed, then succeeded**: paid; **succeeded, then a failure notice**: still paid.
18. **The amount differs; the currency differs**: recorded and flagged; a currency mismatch kept in
    Payments, nothing sent to Sales.
19. **A success after the try was released** (§1.7): the order created when everything can be taken
    again; otherwise a refund due, needing attention.
20. **Two successful transactions on one attempt**: both recorded, the second "paid twice", a refund due.
21. **Apple Pay sheet closed; STC Pay code wrong; STC Pay code timed out**: nothing charged, back to the
    form within the window.
22. **The payment request page**: only the order's customer; the same timers; paid → an `ONLINE`
    payment; expired, withdrawn, amount changed; one at a time; staff edits wait while it is paying.
23. **A store switched off mid-payment** (§1.10): paid → the order exists, the once-only page, the main
    store; not paid → "No money was taken"; the page never again.
24. **Test mode**: TEST attempts never counted as money; the banners and the customer notice; switching
    mode leaves open attempts in their own mode; test orders numbered `TEST-0001`.
25. **Keys**: tested before saving; never returned; only the last 6 shown; a missing permission named;
    the expiry warnings at 30, 7 and 1 days; a key refused → online payment off by itself, back with a
    new key.
26. **No gateway / gateway off / a company**: no online payment offered; the settings line.
27. **Rate limits**: Pay and sessions over the limit answered 429; the checks' cap.
28. **Isolation and permissions**: two stores' gateways, keys, sessions and notices never cross; a
    notice signed with another store's secret refused; each use case only with its permission.
29. **Logs**: every call logged without the key, the secret, the `Authorization` header or card details;
    contact details cleared on anonymizing; pruned after a year.
30. **No polling**: with no attempt open, no status call is made; each attempt's checks stop at its
    answer; the hourly backstop finds an attempt whose check job was lost.
31. **Test and live ids that are the same number**: both kept, nothing crosses.
32. **A key changed while attempts are open**: they still close with the old key (rule 14).

---

## 9 · Questions

### 9.1 The owner's answers this spec is built on

| # | Answer | Date |
|---|---|---|
| — | MyFatoorah for KSA only; UAE disabled; Egypt not chosen; more gateways as adapters later | 2026-10-17 |
| — | A store with no gateway goes live for all; individuals pay through staff; the settings line says so | 2026-10-18 |
| P1 | Pay right after checkout, before approval | 2026-10-19 |
| P2 | ~~Pay again until 60 minutes~~ — replaced 2026-10-28 by the 5-minute window and no order before payment | 2026-10-19, 2026-10-28 |
| P3, P24 | No card data with us — ~~MyFatoorah's hosted page~~ replaced 2026-10-28 by MyFatoorah's embedded form on our page | 2026-10-19, 2026-10-27, 2026-10-28 |
| P5 | ~~The revive in a grace window~~ — gone with the timer's cancellation (2026-10-28): no order exists until it is paid | 2026-10-19, 2026-10-28 |
| P6, P22, P23 | Staff choose: a payment request (60 minutes) or by hand; staff who manage orders | 2026-10-19, 2026-10-27 |
| P7 | Refunds are staff's business; only recorded | 2026-10-19 |
| P8, P28 | Overdue transfers flagged in the list; 72 hours from the upload | 2026-10-27 |
| P9, P10 | "Hold stock while verifying" dropped; the bank account moves to Payments | 2026-10-19 |
| P11, P12, P13 | Super Admins set up the gateway and the test/live switch; keys never shown again, last 6 characters | 2026-10-19, 2026-10-27 |
| P14 | The customer pays the total only; the fee is ours (on the vendor) | 2026-10-19, 2026-10-27 |
| P15, P26, P27 | Every attempt visible; a Payments list; the checks | 2026-10-19, 2026-10-27 |
| P17 | Test by switching the store's gateway to MyFatoorah's test address; test payments marked and never counted; a warning while in test | 2026-10-27 |
| P18, P20, P25 | Unlimited tries (each safe); a mismatch recorded and flagged; confirmation on the page until stage 8 | 2026-10-27 |
| P19 | MyFatoorah now; Tamara a separate gateway later | 2026-10-27 |
| §9.3, §9.4 | As recorded there | 2026-10-27 |
| — | New API keys, not the legacy one; methods on: Visa, Mastercard, Mada, Apple Pay, Apple Pay (Mada), STC Pay; fee on the vendor; SAR two decimals; no min/max | 2026-10-27 |
| §9.5 #2–#4 | A store switched off mid-payment: a once-only page, then the main store (#2); disputes outside the system (#3); open attempts still checked when the gateway is off (#4) | 2026-10-27, 2026-10-28 |
| — | **The embedded form on our checkout**; **the money taken at once**, never held; **no order until the money is confirmed**; **the order number given only when paid** — no gaps from tries | 2026-10-28 |
| — | **Two timers**: a 5-minute window from Pay, and a wait for an answer already in flight | 2026-10-28 |
| Q1, Q2, Q4, Q5 | (a): the embedded form; the try's own reference, the number at payment; the 5 minutes from Pay; the request's own page with the same form | 2026-10-28 |
| Q6 | Every remaining pick accepted: plain-word decline reasons; a request withdrawn when the amount changes; 60 checks and 120 notices a minute; Admins change the bank account; the Payments list wherever orders are seen; the UNAVAILABLE error category; Payments → Access for permissions; the nightly re-check dropped | 2026-10-28 |

### 9.2 What other modules must change (written as their amendments in this PR)

1. **Sales** (sales.md §9.5 #4–#8, #11, #13, #14) — **online checkout without an order until paid**: the
   checkout's payment block opens a session; Pay starts **a checkout try** (one live per cart) that
   checks every rule of placing again, reserves the stock, the coupon and the points under the order's
   future id, and calls `pay`; `PaymentSucceeded` **creates the order** — its number taken then — from
   the try; `PaymentFailed` and `PaymentNotTaken` **release** the try, nothing counted; reopening
   checkout asks `refresh`; a success after release is placed again or becomes a refund due; the
   request page (§1.5); the once-only page of §1.10; `ONLINE` payments recorded once per transaction,
   never voided when real (§9.3 #12); staff edits wait while a payment is in progress; the timer's
   cancellation, its `PAYMENT_NOT_COMPLETED` reason and the revive are **dropped**; `bankAccount` read
   from Payments; the ways to pay read from `availability()`.
2. **Inventory** — nothing new: a try holds under the order's future id with no expiry, as placing
   does, and the order keeps that same hold; `release` gives it back. **To check in the build**: a hold
   again for an id it released (a success after release — §1.7).
3. **Promotions** (promotions.md §9.5) — **a coupon reserved for a try**, confirmed with the order's
   number when it is paid, and **released with nothing counted** when it is not ("as if Pay had never
   been pressed" — the built `useCoupon` keeps every use for good and needs the order's number).
4. **Loyalty** (loyalty.md §9.6) — **points reserved for a try**, confirmed with the order's number when
   it is paid, and released when it is not (the built `redeem` needs the order's number).
5. **Platform** — serving MyFatoorah's Apple Pay file at `/.well-known/…` (§1.2), and letting the few
   addresses that finish a try answer for an off store (§1.10).
6. **B2B** — the bank account and its settings line move to Payments (b2b.md §2.3, amendment).
7. **Access** — a **Payments** permission group and the Payment problems topic (amendment 78).

### 9.3 The round of 2026-10-27 — answered

The owner answered (2026-10-27): **(a) for #1, #2, #4, #6 and #8–#13**; **(b) for #5**; **#7 (a) with an
addition** (§1.2); **#3 open again** (§9.4 #1). The questions are kept as they were asked; **#1, #9, #10
and #11 were about MyFatoorah's hosted page and the 60-minute order, both replaced on 2026-10-28** —
what is left of them is in §1.3, §1.5 and §1.7.

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
3. **Orders placed while a store is in TEST** — answered (a): every order placed while the store is in
   TEST is a test order, numbered apart (`TEST-0001`), marked TEST everywhere, left out of every figure,
   never marked shipped.
4. **The staging data on launch day** — answered (a): the staging database becomes the real one; the
   keys and the MyFatoorah notice address are entered again for the real domain.

### 9.5 The gap check of 2026-10-27

1. **A cancellation while a payment is being checked** — **dropped with the 60-minute order**
   (2026-10-28): no order exists while a checkout try pays. What remains — an order with a payment
   request in progress — is §9.6 #5.
2. **A store switched off while payments are open** — answered: accepted, with the owner's once-only
   page and the main store (§1.10).
3. **A customer disputes the charge with their bank** — answered: **nothing in the system**; staff
   record a refund by hand if they choose; MyFatoorah's dispute notices are answered and ignored.
4. **A gateway switched off while attempts are open** — answered (a): the open attempts are still
   checked until each closes; only new payments stop.

### 9.6 Still open — the round of 2026-10-28

Answered in this round, recorded here: **"authorize, then capture" is not used** (the owner: "we dont
want any held we will take the money"). It was asked only for one rare case — a payment that goes
through **after** the try was released and its stock sold: with the money only held, it could be given
back without a refund. §1.7 now keeps the reservations until MyFatoorah answers, so that case needs a
payment MyFatoorah first said was not in progress; if it ever happens, it is a refund due (§1.7).

1. **How long the wait lasts** (§1.7) — after the 5 minutes, for an answer already in flight: (a) **2
   minutes** on the page — MyFatoorah's own guidance gives a status call about 1½ minutes (3 tries, 5
   seconds apart, 30-second timeout) — then the "still confirming" page, with the reservations kept until
   MyFatoorah answers **[Recommended]**; (b) another length.
2. **A declined card** (§1.4 step 6): (a) the try ends at once, everything released, the customer back
   on checkout with the reason, and Pay again starts a new 5 minutes **[Recommended]**; (b) the customer
   may try another card inside the same 5 minutes, keeping the reservations.
3. **An answer that never comes** (MyFatoorah unreachable for long): (a) the reservations stay; after 20
   minutes the attempt needs attention; staff may release the try by hand once they have checked in
   MyFatoorah's portal that nothing was taken **[Recommended]**; (b) released by the system after a set
   time, the payment a refund due if it later goes through.
4. **The hourly backstop** (rule 9): (a) one query an hour for attempts whose own checks were lost (a
   queue failure) — nothing runs when there are none **[Recommended]**; (b) none — the booked checks only.
5. **A customer cancelling an order while a payment request on it is being paid** (§1.5): (a) the cancel
   waits until the payment is answered (at most the 5 minutes and the wait) — "A payment is being
   confirmed" **[Recommended]**; (b) the cancel goes ahead, and money that then arrives is a refund due.
6. **Opening a session** (§1.8): (a) at most 30 per buyer per hour **[Recommended]**; (b) another number.
