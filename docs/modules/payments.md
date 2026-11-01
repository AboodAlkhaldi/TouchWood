# Payments — module specification

**Status (2026-10-28): accepted by the owner — every pick answered.** Payments takes money online through a gateway —
**MyFatoorah for the KSA store**, the only one chosen (owner, 2026-10-17) — **on our own checkout page**
(MyFatoorah's embedded form, owner 2026-10-28), and tells Sales when money arrived. It also holds each
store's **bank account** for transfers (moved from B2B, owner 2026-10-19 P10) and the **target time to
check a transfer**. It is built from handoff §12.4, the contract Sales declared for it (sales.md §2.4,
in code since S7b), the owner's answers of 2026-10-17 to 2026-10-28 (§9.1) and MyFatoorah's
documentation **read in full on 2026-10-28** — all 212 pages
([payments-myfatoorah-notes.md](payments-myfatoorah-notes.md): §0 is that read; every gateway fact below
is from there, with its page). Every point once marked **[PROPOSED]** is accepted (owner, 2026-10-28: the last three — the
staff release for Admins, the checks at 10 and 20 minutes, the 10-second answer); §9.6 holds the last round, answered.

**The owner's charge for this module (2026-10-17):** "the payment must be so secure and ran over all
the usecases so we dont charge same order twice or charge on nothing or if had a error in connection or
any of these stuff we have to follow a strong methodology". §1.1 is that methodology; §8 tests every
case of it. **And (2026-10-28): the payment step is "the most critical page ever"** — going back,
closing the tab, opening it twice, or running out of time must leave nothing behind, "like the user
didn't press the pay button".

**Dependencies (handoff §4.4):** Payments → Platform. **Payments → Access only to declare its
permissions and to hear `CustomerAnonymized`** (§6; owner, 2026-10-28) — a handoff §4.4 amendment, as
Catalog, Pricing, Inventory and Sync were allowed for their permissions (2026-10-02/07). Payments **never reads Sales**: Sales hands it an id
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
   reserved goes back, as if Pay had never been pressed** — no order, no number, nothing counted — **at
   once when nothing was sent to the gateway, otherwise only when the payment can no longer be finished**
   (rule 15).
3. **One payment at a time, and each session pays once.**
   - **One live try per cart** (Sales) and **one open attempt per try or order** (Payments, a unique
     index): a second tab, a duplicated tab or a double click can never start a second payment while one
     is live.
   - **MyFatoorah's session "is valid for only one payment"** (notes §0 #4): the form on a page can
     charge once, whatever the browser does.
   - **One try per quote, and only for the cart as it is now** (the independent review, 2026-10-28): a
     quote that already has a live or paid try is refused, and so is a quote made before the cart last
     changed (Sales stamps the cart's version on the quote). After a paid try the cart has changed, so an
     old checkout in another tab — whatever quote it holds — can never pay again for goods already paid.
   - **A second success is still recorded** if it ever happens (MyFatoorah's invoice is "PAID if there
     is a successful transaction" — notes §4): flagged **paid twice**, the extra a refund due — never
     swallowed.
4. **Charging is safe to repeat.** The charge (`POST /v3/payments`) carries an **idempotency key** made
   once and **committed before the call** — `Idempotency-Key`, available in Saudi Arabia, kept 250
   minutes by MyFatoorah (notes §6). A timeout, a crash or a retry repeats **the same key** and gets
   **the same invoice**. A key is **never reused for another amount** (a reused key returns its first
   answer even when the body changed — notes §6). Opening a session is not idempotent and needs not be:
   a session charges nothing until our server makes the charge with it. **A charge whose answer was lost
   is settled by asking about the session** (`GET /v3/sessions/{id}`: `IsUsed` and its `TransactionResult`
   — notes §0 #6), not by sending it again later: the charge is repeated only within the window, never
   after MyFatoorah's 250 minutes; **only a 4xx to the first call means refused** — a 4xx to a repeat
   (the session already used) is answered by asking about the session. **The charge is claimed before
   the call** (the second review, 2026-10-28): under the attempt's row lock its state becomes `CHARGING`, committed,
   and only then is the call made — a second `charge` (a double click) finds the claim and makes no
   call; nothing can end the attempt as "never charged" once it is claimed (rule 15).
5. **Success is final.** Once the gateway says succeeded, nothing turns it back ("the Success Payment
   Status is final" — notes §4). A failure after a success is ignored; a success after a failure wins.
6. **Recorded once — per successful try.** Every successful transaction (keyed by the gateway's payment
   id, unique per gateway and mode) is recorded once, under the attempt's row lock, so two notices, a
   notice and the page's message, or two workers record it once. **Events and `payment()` are per
   transaction.**
7. **Everything is matched on our side, never taken from the visitor.**
   - **The session** a page pays with must be one **we** opened, for **this** buyer, **this quote** (or
     this request) **and this amount**, in this store and mode, not expired and not used — a session id
     from another page, quote, buyer or store is refused, so a form that showed 100.00 can never be
     charged 500.00.
   - **The attempt** an answer belongs to is found from the invoice the gateway names, and the invoice's
     `ExternalIdentifier` (our attempt id, set when charging — signed in the notice, notes §5.4) must
     match. A visitor bringing someone else's `paymentId` can never get it applied to their own cart or
     order.
   - **The browser message** from the bank-code frame is read only when its sender is exactly
     `MF-3DSecure` (notes §0 #8) and it comes from the address the frame opened (our own addition — the
     address to accept is learnt in the test account) — and even then it only makes our server ask
     (rule 1).
8. **The amount and the currency are checked.** What the gateway says was paid is compared with the
   attempt, parsed exactly as a decimal (never a floating-point number). **A different amount** in the
   right currency is **recorded as it really was** and **flagged for staff** (owner, 2026-10-27 P20).
   **A different currency** (in LIVE the base currency must be SAR) is **kept in Payments, flagged, and
   never sent to Sales**, so foreign money never enters an order's books.
9. **Nothing is lost when the gateway cannot be reached — and nothing polls for nothing.**
   - A status call is retried 3 times, 5 seconds apart, with a 30-second timeout (MyFatoorah's guidance
     for Get Payment Details — notes §0 #16); the charge is repeated the same way only because its
     idempotency key makes a repeat harmless (rule 4); then the attempt is **`UNKNOWN`** and the customer sees "We are confirming your payment",
     never "failed".
   - **Each attempt carries its own few checks**, booked when it is written and stopping as soon as it
     has an answer: at the end of the 5 minutes, at the end of the wait (§1.7), then 10 and 20 minutes
     after the charge (**accepted, owner 2026-10-28**: MyFatoorah stops retrying its notice after at most 5 tries 180
     seconds apart — notes §0 #15 — so 20 minutes covers a notice that never comes).
   - **A page asking again** (checkout reopened, "Confirming…" refreshing) gets the attempt's last answer
     when it is younger than 10 seconds, so pages never multiply calls (**accepted, owner 2026-10-28**). **When nobody is paying,
     nothing runs** (the owner, 2026-10-28: no lookups "hoping to find anything").
   - **One backstop**: once an hour, a single query looks for attempts still unanswered after their
     last check (a lost job) and checks them (owner, 2026-10-28, §9.6 #4). With none, it ends at
     once.
10. **No card data ever reaches our server** (owner, 2026-10-19 P3). The card form is **MyFatoorah's,
    drawn inside our page** ("MyFatoorah handles all PCI DSS requirements" — notes §0 #3); our page and
    server see at most the masked number and the brand, and **keep only the brand and the method**. No
    card number, no CVV, no token is stored. The form's script is loaded only from MyFatoorah's own
    address for the mode (`sa.myfatoorah.com` live, `demo.myfatoorah.com` test), and the bank-code frame
    opens only the `PaymentURL` our server received from MyFatoorah — never an address from the page. The
    bank's own 3-D Secure pages the frame moves to are allowed (the page's security rules are set for it
    in the first build step, §1.3).
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
    mode never strands an open attempt: **every** earlier key and secret of a mode is kept (encrypted)
    until the attempts made with it are all closed — each attempt records the key it was made with; their checks go on while the gateway is off (owner, 2026-10-27,
    §9.5 #4), and notices are still accepted for them.

15. **Released only when nothing can still be paid** (the independent review, 2026-10-28). MyFatoorah
    has **one** status, `InProgress` — "payment attempt not completed" — both for a customer still typing
    the bank's code and for one who walked away (notes §4; `GetPaymentStatus`, `UpdatePaymentStatus`).
    So a try is released:
    - **at once** when nothing was sent to the gateway — the charge refused at its first call, or never
      claimed: Sales asks Payments to **abandon** the attempt, which succeeds only while no charge has
      been claimed (rule 4), under the attempt's lock; if the charge was claimed meanwhile, nothing is
      released (the second review, 2026-10-28);
    - **at once on a decline** the gateway confirms (§1.4 step 6): the first build step proved that no
      other transaction can follow on that invoice from our page — the session is used, charging it
      again is refused, and the old bank page answers "Transaction not found" (§9.8);
    - otherwise **when MyFatoorah answers, or at the hold limit — 15 minutes after Pay — whichever
      comes first** (`payments.timers.hold_minutes`; owner, 2026-10-29, §9.8): **our own clock decides**. The
      first build step proved that MyFatoorah does **not** stop a charged payment at its
      `PaymentExpiry`, nor at its session's expiry — a bank step finished 41 seconds after the one and
      two minutes after the other still succeeded — and that an unfinished payment stays `InProgress`
      for at least an hour (§9.8).

    A success reported after a release is still recorded (rule 5) and is **a refund due — never an
    order made by itself** (§1.7).

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
- **A key MyFatoorah refuses anyway** — **the current key only**; a 401 on an earlier key kept for old
  attempts (rule 14) marks just those attempts `UNKNOWN` for staff (the second review, 2026-10-28) — (expired, switched off or deleted in the portal — a 401, or the
  user who created it disabled, which "will disable the token key" — notes §0 #18): the store's online
  payment **stops by itself** (accepted, 2026-10-28) — `availability()` answers not available
  (`KEY_REJECTED`), so individuals' orders keep coming in as "staff will contact you", as in a store
  with no gateway (owner, 2026-10-18) — and the Super Admins are told at once, by the same banner and
  topic. Open attempts stay `UNKNOWN` and keep their reservations (§1.7); once a new key is saved,
  online payment comes back, and the open attempts are checked again with the new key **when it is of the
  same MyFatoorah account** (the old key no longer answering); staff see the rest.
- **The keys and secrets are kept encrypted** with the app's key in Payments' own table, as Sync keeps
  its key (sync.md §1.2) — Platform's settings never hold a secret (platform.md §1.3). **Never shown
  again**: the page shows only **the last 6 characters** (owner, 2026-10-19 P12) and when it was set.
- **Saving tests the key first**, for each permission this design uses (the second review, 2026-10-28: a key's
  permissions are per endpoint — notes §0 #18): the methods list, **opening a session** (it charges
  nothing) and **asking for a payment that does not exist** (a "not found", never a 401). Any 401 →
  nothing is saved and the missing permission is named (as Sync, sync.md §1.2).
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
  wallet included) and **STC Pay**. Google Pay is not on. **The customer picks inside the form.** The
  session **always names these methods** (`SupportedPaymentMethods`), so a method MyFatoorah switches on
  later never appears by itself — any method the form cannot take on our page "will redirect to the
  hosted payment page" (notes §0 #3), which this design does not allow (the independent review,
  2026-10-28). STC Pay's exact value there is not in the docs — learnt in the test account.
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
  Apple Pay, STC Pay (test mobile and code — notes §0 #10) — in Collect Details mode, before anything is
  built on it. What the docs do not say is learnt there, and anything different from this spec goes
  back to the owner:
  - **blocking**: a payment cannot be finished after `PaymentExpiry`, nor after the session's expiry
    (rule 15); a 5-minute `PaymentExpiry` is accepted;
  - what `InProgress` looks like when the customer abandons the bank's page, and whether a declined card
    can be tried again on the same invoice;
  - where STC Pay asks its code — in the form before our charge, or after it;
  - the redirection address set on the session as well as on the charge (the frame's return —
    notes §0 #8);
  - when a session's `IsUsed` turns true — at collecting the details or at the charge — and that an
    empty `TransactionResult` is what "nothing charged" is judged by (the second review, 2026-10-28);
  - that MyFatoorah's test environment takes no real card, Apple Pay or STC Pay money (its docs say
    only "virtual transactions" — test-cards);
  - the page's security rules (which addresses the frame and the script may load).

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
   and an expiry of the quote's end **plus the window and the wait** (so a Pay at the quote's last second
   still has its full time). The form is drawn in the page. **Nothing
   is reserved and nothing can be charged** — a session charges only when our server charges with it.
   **An unused, unexpired session of the same quote and buyer is reused** when the page is opened again
   (the second review, 2026-10-28: refreshing checkout must not use up the limit of §1.8); a new quote gets a new one.
1. **The customer chooses how to pay in the form and presses Pay.** For a card it is **our own button**
   (notes §0 #9): the page checks the form (`validateCardInputs`), then asks the form to collect the
   details. For **Apple Pay and STC Pay** it is their own button in the form, and "Pay pressed" is the
   moment the form hands the details over. Either way the form tells our page only that the details are
   ready (masked).
2. **Our page sends Sales the quote's id and the session's id** — never an amount (rule 12).
3. **Sales starts the try**, in one transaction (sales.md §9.5 #4):
   - the quote and the cart are locked; **a live try on this cart** → refused, "A payment is already in
     progress in another window" (the same quote and session again — a double click — gets the same
     try). **Placing a "staff will contact you" order on that cart is refused the same way** while the
     try is live (the second review, 2026-10-28);
   - **one try per quote, and the quote must be of the cart as it is now** (rule 3);
   - **the quote is built again from the cart** — prices, stock, coupon, points, gift, shipping, every
     rule of placing (sales.md §1.2, §1.3 step 3) — **and must equal the one shown**: anything changed →
     **nothing is reserved and the customer stays on checkout**, shown what changed, and pays only after
     the cart is right (the owner, 2026-10-28: "the pay button won't let user out to pay until adjusting
     cart");
   - **Payments' attempt is written first, in the same transaction** (`prepare` — no call to the gateway):
     the session checked (rule 7), its idempotency key, its checks booked (rule 9) — **so no try ever
     exists without an attempt that will be checked**, whatever crashes next. A refused session or a
     gateway switched off meanwhile rolls everything back: nothing reserved. (Before the reservations,
     so nothing is locked after Inventory's store lock — sales.md §1.3's order of locks; the second review, 2026-10-28.)
   - the try is written: its quote, its buyer, the order's future id, **its window's end** (§1.7), and
     **a copy of the delivery address and the customer's contact details** — the order is made from the
     try even if the customer deletes the address meanwhile (the second review, 2026-10-28);
   - **the stock, the coupon and the points are reserved** for the try (Inventory last, as at placing —
     sales.md §9.5 #5).
4. **After the commit, Sales asks Payments to charge** (`charge`) the attempt it prepared — its key
   already committed (rule 4); the unique index allows **one open attempt per try** — and Payments calls
   `POST /v3/payments` with `SourceOfFund.SessionId`, the amount (in
   SAR, two decimals — owner, 2026-10-27), our attempt id as `Order.ExternalIdentifier` (rule 7), the
   try's id as `Customer.Reference`, **`PaymentExpiry` at the end of the 5 minutes** (UTC), the
   language, our return address and the store and mode's notice address. The answer's `InvoiceId`,
   `PaymentId` and `PaymentURL` are saved; the attempt is `OPEN`.
   - **A timeout or 5xx** → repeated with the same key (rule 4), 3 times; still nothing → `UNKNOWN`,
     the page says "We are confirming your payment" and the checks of rule 9 settle it through the
     session (rule 4); nothing new can start on this cart until it is answered. A crash before the call
     leaves a prepared attempt whose first check finds the session unused and ends it (rule 15).
   - **MyFatoorah refuses the first call** (a 4xx) → the attempt is `REFUSED`, the reason logged for staff;
     Sales ends the try and releases everything; the customer is back on checkout: "The payment could
     not start — please try again". A phone number is sent only when it matches MyFatoorah's pattern —
     it is not required (notes §0 #14).
5. **The customer finishes in the form**: the bank-code page in its frame for a card (`PaymentURL`),
   the Apple Pay sheet, or STC Pay's code. **The page shows the time left.**
6. **The answer.** The frame's message (rule 7), the return to our address, the form's own callback or
   the notice — whichever comes first — makes Payments **ask MyFatoorah** (rule 1), then:
   - **Paid** → **Sales creates the order from the try** — the order number is taken now, the
     reservations become the order's, the cart loses what was bought — and the page shows "Order
     placed". The page's own answer completes it **at once** (Sales asks Payments, then completes);
     `PaymentSucceeded` through the outbox (relayed once a minute) completes it if the page never came
     back — the same completion, done once (the second review, 2026-10-28). Nothing is ever numbered for a try that does not pay (owner, 2026-10-28: no
     gaps in the order numbers).
   - **Declined** (the bank's reason — notes §9: a wrong code, insufficient funds, a card declined…) →
     `PaymentFailed` → Sales ends the try and releases everything; the customer is back on checkout
     with the reason **in plain words** (accepted, 2026-10-28) and may press Pay again — a new session,
     a new try, a new 5 minutes (owner, 2026-10-28, §9.6 #2). The frame is closed, so the declined
     invoice's page is no longer in the customer's hands; if another transaction on it were ever to
     succeed, it is a refund due (rule 15), never a second order.
7. **The 5 minutes end** with no answer: Payments asks MyFatoorah.
   - **Paid** or **declined** → as step 6.
   - **Anything else** — no transaction yet, or `InProgress`, which MyFatoorah uses alike for a customer
     confirming at 4:59 and for one who walked away (rule 15) → **the wait** (§1.7). The page shows
     "Confirming your payment…" when it saw the customer finish the bank's step (the frame's message,
     the wallet's callback), "Time ran out" otherwise — but the reservations stay either way.
   - **At the end of the wait**, asked again: paid → the order; anything else → the attempt is
     `EXPIRED` (its `PaymentExpiry` has passed — rule 15), `PaymentNotTaken` goes out, Sales releases
     everything, and the page returns to checkout: "No money was taken."
   - **The gateway cannot be reached** → `UNKNOWN`, and §1.7's last paragraphs.
8. **Leaving.** Back, closing the tab, going to another page: **nothing is charged unless the customer
   finished the bank's step**, and nothing at MyFatoorah needs cancelling (it cannot be — notes §0 #13;
   the session can pay once and its payment expires with the 5 minutes). The try ends by step 7 at the
   latest. **Opening the shop again** goes wherever the customer goes — never to a payment page. **Opening
   checkout (or the cart) again** while the try is live:
   - **nothing was sent to the gateway yet** → the try ends there and then, everything released —
     **as if Pay had never been pressed** (the owner, 2026-10-28);
   - **the charge was made** (the bank's step was opened) → MyFatoorah cannot tell us whether the
     customer finished it (rule 15), so the try **cannot be pretended away until its time is over**: the
     page says "Your last payment can still go through until 14:37 — you can change your cart meanwhile;
     Pay opens again after that", with the time left — **at most the window and the wait** (owner,
     2026-10-28, §9.6 #7);
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
  it on their order ("Pay the remaining 45.00 SAR") — and, from stage 8, **by bell and email** (§9.7 #5; SMS carries codes only — ops.md). Expired → nothing
  is cancelled; staff may send a new one.
- **The request has its own page** (owner, 2026-10-28: "a unique page, not as usual"):
  `/{store}/{locale}/orders/{number}/pay` — the order's number and summary, the amount asked, who asked
  it and until when, and **the same MyFatoorah form** (§1.3). **No cart, no checkout, nothing reserved**:
  the order already exists. Pressing Pay opens the same **5 minutes and the wait** (§1.7); paid → Sales
  records an `ONLINE` payment on the order; declined or out of time → the request stays open while its
  60 minutes last, and the customer may try again. The page answers only the order's own customer.
- **One open request per order**, and **one payment at a time on it** (rule 3): a second window shows
  "A payment is already in progress".
- **A request never expires while a payment on it is open** — its 60 minutes wait for that payment's
  answer — and **no new request is sent while any payment on the order is open** (the independent
  review, 2026-10-28).
- **One lock settles every race on a request** (the second review, 2026-10-28): Sales locks the order first (its own
  rule), then Payments locks the request row. `prepare` for a request needs it `OPEN` with no open
  attempt; **withdrawing it** (`withdraw` answers whether it could) **is refused while an attempt on it
  is open**. So **recording a payment by hand** — which withdraws the open request first — and a staff
  edit both wait for a payment in progress, and the same balance is never paid twice.
- **A request's mode must be the gateway's**: an order placed in TEST never gets a LIVE request, and a
  real order never a TEST one (the second review, 2026-10-28: real money must never land on a test order).
- **A currency refused on a request** (`PaymentNotAccepted`) closes the request as `NOT_ACCEPTED`,
  needing attention in the Payments list; nothing is written on the order (the money is foreign —
  rule 8).
- **Only for an individual's order in a store whose gateway is on**; sent by **staff who can manage
  orders** (P23).
- **Staff edits wait while a payment is in progress on the order** (the 5 minutes and the wait at most):
  staff are told why (owner, 2026-10-27, §9.3 #10, as it now applies). **So does the customer's
  cancellation** — "A payment is being confirmed" — until the payment is answered (owner, 2026-10-28,
  §9.6 #5).
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
  environment is for "virtual transactions" (its test-cards page); that no real card, Apple Pay or STC
  Pay money can move there is **checked in the first build step** (§1.3) — its docs do not say it.
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

- **The window — 5 minutes** (`payments.timers.window_minutes`, Super Admins; owner, 2026-10-28: replaces the
  60 minutes of 2026-10-19 P2). It starts **when Pay is pressed** (owner, 2026-10-28, Q4 (a)) — before
  that the checkout may stay open, nothing reserved. Inside it the customer finishes the bank's step;
  the page counts it down. MyFatoorah's own `PaymentExpiry` is still set to its end (it accepts one
  minute), but **it does not stop the payment** (§9.8): our page ends the window at 5 minutes, and the
  hold limit below is what ends the try.
- *(The release at the wait's end in §1.4 step 7 and in §1.4 step 8's "at most the window and the wait" is superseded by the hold limit below: §9.8, gap 10.1. The wait now changes only what the page says.)*
- **The wait — 2 minutes** (`payments.timers.wait_minutes`, Super Admins; owner, 2026-10-28, §9.6 #1). At the end of
  the window our server asks MyFatoorah; **anything but paid or declined is waited for** — because
  MyFatoorah's `InProgress` is the same for the customer who pressed the bank's "confirm" at 4:59 and
  for one who left (rule 15). The page shows "Confirming your payment…" when it saw the customer finish
  the bank's step; the reservations stay. The answer comes → paid or released (§1.4 step 6); none by the
  wait's end → not taken (§1.4 step 7).
- **The hold limit — 15 minutes after Pay** (`payments.timers.hold_minutes`, Super Admins; owner, 2026-10-29,
  §9.8). A try MyFatoorah has not answered by then — `InProgress`, or not reachable — **is released by
  our own clock**: stock, coupon and points go back, `PaymentNotTaken` goes out, the page says the
  payment was not completed. MyFatoorah never ends such a payment by itself (§9.8), so nothing else
  would. A bank step finished after it is a refund due (below). Rule 9's checks go on after the
  release, so a late success is still found and recorded.
- **When MyFatoorah cannot be reached** (the attempt `UNKNOWN` at the wait's end): the page says "We
  are still confirming with your bank. If the payment goes through, your order appears in My Orders —
  please do not pay again." **The reservations stay until MyFatoorah answers or the hold limit ends
  the try** (rule 9's checks go on), so a payment that goes through inside the 15 minutes still finds
  its stock, coupon and points. **Still no answer 20 minutes after the charge** → the attempt is listed
  for staff as needing attention (owner, 2026-10-27, §9.3 #13); staff may ask the gateway again.
- **A success that arrives after the try was released** — a bank step finished after the hold limit
  (§9.8 shows MyFatoorah allows it); rule 5 still records it: **no order is made by itself**. The money is **a refund due**,
  recorded on the try in Sales and shown to staff as needing attention in the Payments list, with the
  customer and the amount (owner, 2026-10-19 P7: refunds are staff's). Staff may contact the customer;
  making an order by hand is the usual "staff will contact you" order. (Making the order again by
  itself would need the stock, the coupon and the points taken twice under one id, which Inventory
  refuses — `AlreadyHeld` — and could sell goods a later try already paid for.)
- **"Needs attention" means**: a mismatch, paid twice, a success after release, a currency refused, no
  answer 20 minutes after the charge, or **a paid try Sales has not turned into an order 10 minutes
  after the payment** (Sales's hourly look at its tries, sales.md §9.5 #4 — the second review, 2026-10-28) — this
  replaces §9.3 #13's "`UNKNOWN` for more than an hour" for the payments of this design.
- **Staff releasing a try by hand** (§9.6 #3) — **superseded by the hold limit** (§9.8): every try
  is released 15 minutes after Pay at the latest, before an attempt could be `UNKNOWN` for the 20
  minutes this use case waited for, so `ReleaseTryByHand` and `payments.attempts.release` are **not
  built** (§9.8 #3).

### 1.8 Our own limits

(owner, 2026-10-27: "we can set something from us as the community does")

- **Pay**: **10 per cart (or request) per 10 minutes** and **30 per customer per hour** (owner, 2026-10-27,
  §9.3 #4). Opening a session (the checkout page): **30 per buyer per hour** (owner, 2026-10-28, §9.6 #6).
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

    /** Writes the attempt inside Sales's transaction (§1.4 step 3): the session checked, the key made, the checks booked. */
    public function prepare(PayRequestDto $request): string; // the attempt's id

    /** Charges the prepared attempt, after Sales's commit (§1.4 step 4): claimed first (rule 4). Safe to call again. */
    public function charge(string $attemptId): PaymentStartDto;

    /** Ends a prepared attempt that was never charged; false when the charge was already claimed (rule 15). */
    public function abandon(string $attemptId): bool;

    /** A staff request for the rest of an order (§1.5), open 60 minutes. */
    public function requestPayment(PaymentRequestDto $request, string $staffId): PaymentRequestStateDto;

    /** Withdraws an order's open request (§1.5); false while a payment on it is open. */
    public function withdraw(string $orderId): bool;

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
  `orderId` (the order's future id for a try, the order's id for a request), `quoteId` (for a try — the
  session must be this quote's, rule 7), `storeId`, `buyerId`, `sessionId`, `amount` (Money — from
  Sales, never the page, and equal to the session's), `customerName`, `customerEmail`,
  `customerPhone`, `locale`, `returnUrl`.
- **`PaymentStartDto`**: `attemptId`, `frameUrl` (the bank-code page, null when the method needs none or
  the gateway could not be reached), `windowEndsAt`, `state`.
- **`PaymentRequestDto`**: `orderId`, `orderNumber`, `storeId`, `amount`, `customerId`, `mode` (the
  order's — a TEST order is `TEST`; refused when it is not the gateway's, §1.5).
  **`PaymentRequestStateDto`**: `requestId`, `orderId`, `amount`, `expiresAt`, `state` (`OPEN`, `PAID`,
  `EXPIRED`, `WITHDRAWN`), `requestedBy`.
- **`PaymentDto`** — **one transaction**, not one attempt (rule 6): `paymentId` (our transaction's id),
  `attemptId`, `ownerId`, `ownerKind`, `orderId`, `amount` (what was really paid), `expected`, `method`
  (as the gateway names it — Mada, Visa, Apple Pay, STC Pay…), `reference` (the gateway's payment id),
  `mode` (`LIVE`, `TEST`), `succeeded`, `paidAt` (the gateway's `TransactionDate`), `failureCode` (the
  gateway's `MF0xx`, null when succeeded), `mismatch` (bool, rule 8), `paidTwice` (bool, rule 3).
- **`PaymentStateDto`**: `state` (`NONE`, `OPEN`, `IN_PROGRESS`, `UNKNOWN`, `SUCCEEDED`, `NOT_ACCEPTED`, `FAILED`,
  `EXPIRED`), `windowEndsAt`, `waitEndsAt`, `holdEndsAt`, `nothingCharged`, `attemptId`, `paymentId` — what the page shows,
  and whether Sales may release (§9.12).
- **`OnlinePaymentDto`**: `available` (bool), `mode` (`LIVE`, `TEST`, null), `reason` when not available
  (`NO_GATEWAY`, `GATEWAY_OFF`, `KEY_REJECTED` — §1.2, `COMPANY`).
- **`PaymentAudience`** stays (`INDIVIDUAL`, `COMPANY`). `PaymentMethodDto` and `methods()` go: the
  method is picked in MyFatoorah's form (§1.3). `start()` goes: `openSession`, `prepare` and `charge`
  replace it.
- **`BankAccountDto`** moves from B2B unchanged.

### 2.2 Events (`Public/Events`, ids only, through the outbox — handoff §4.5)

| Event | When |
|---|---|
| `PaymentSucceeded` (`eventId`, `paymentId`, `orderId`, `storeId`, `occurredAt`) — exists; `storeId` added (§9.7 #1) | The gateway confirmed a successful **transaction** (§1.4 step 6) — once per transaction; `paymentId` names it; `orderId` is the order's id, the future one for a try. **Not** published for a currency mismatch (rule 8) |
| `PaymentFailed` (`eventId`, `paymentId`, `orderId`, `occurredAt`) — exists | A transaction was declined |
| **`PaymentNotTaken`** (`eventId`, `attemptId`, `ownerId`, `orderId`, `occurredAt`) — new (`orderId` so the outbox keeps it in order with the order's other payment events) | An attempt ended with nothing paid: the hold limit passed (§9.8), the charge never reached the gateway, or the gateway refused the first call (§1.4 steps 4, 7) — Sales releases the try |
| **`PaymentNotAccepted`** (`eventId`, `attemptId`, `ownerId`, `orderId`, `storeId`, `occurredAt`) — new; `storeId` for Ops (§9.7 #1) | Money was taken but cannot enter an order — a currency mismatch (rule 8) — Sales releases the try and records a refund due on it (the review, 2026-10-28: otherwise the try would stay live) |
| **`GatewayKeyExpiring`** (`eventId`, `storeId`, `mode`, `daysLeft` — 30, 7 or 1 — `expiresOn`, `occurredAt`) — new (§9.7 #2) | The daily job finds the current key that many days from its expiry date (§1.2); **once per key and threshold** |
| **`GatewayKeyRejected`** (`eventId`, `storeId`, `mode`, `occurredAt`) — new (§9.7 #3) | MyFatoorah refused the **current** key (§1.2: a 401) and online payment stopped by itself; once until a new key is set |
| **`PaymentRequested`** (`eventId`, `requestId`, `orderId`, `storeId`, `customerId`, `expiresAt`, `occurredAt`) — new (§9.7 #4) | Staff created a payment request (§1.5); Ops sends the customer the link, built from the order's number |

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
| `ChangeBankAccount` (from B2B's admin-only `b2b.settings.update`, b2b.md amendment 32; moved by b2b.md amendment 33) | **`payments.bank_account.update`** — Admins (accepted, 2026-10-28) | That store |
| `OpenSession`, `PrepareCharge`, `Charge` (from Sales's checkout and the request page) | The buyer's own checkout or order — Sales checks it, Payments is called inside | That store |
| `RequestPayment`, `WithdrawRequest` | **`sales.order.edit`** — staff who manage orders (owner, 2026-10-27 P23) — checked by Sales, which calls Payments | That store |
| `ReceiveNotice` (the webhook) | The signature — no staff, no customer | That store and mode |
| `ReceiveAnswer` (the frame's message or the return to our address, from the page) | The buyer's session — the attempt must be theirs (rule 7) | That store |
| `ListPayments` (all online attempts, by state — owner, 2026-10-27 P26), `ViewAttempt` (its transactions and calls — P15) | **`payments.payment.view`** (the permission catalog takes three parts — step 8, my pick) — given wherever `sales.order.view` is (accepted, 2026-10-28) | That store |
| `CheckAttempt` (staff's "Ask the gateway now") | **`payments.payment.view`** | That store |
| ~~`ReleaseTryByHand`~~ — **not built**: the hold limit releases every try first (§1.7, §9.8 #3) | ~~`payments.attempts.release`~~ | — |
| `CheckAttempt` (each attempt's booked checks), `HourlyBackstop` (owner, 2026-10-28), `PrunePaymentLog` | System (reserved) | — |

Every change of a gateway is audited — never a key or a secret, only "changed" and the last 6
characters. The permissions sit in a new **Payments** group in the role editor.

---

## 4 · State machines

**An attempt** (one charge with one session — one invoice):

```
CREATING ─▶ ABANDONED                          (prepared, never charged: abandoned, or found unused — rule 15)
CREATING ─▶ CHARGING ─▶ OPEN ─▶ SUCCEEDED      (claimed, charged, paid — final)
CHARGING ─▶ REFUSED                            (the gateway refused the first call — §1.4 step 4)
CHARGING ─▶ UNKNOWN ─▶ OPEN                    (the charge's answer was lost; settled through the session)
CHARGING | UNKNOWN ─▶ REFUSED                  (the first call's refusal, even if a page's check got there first — §9.12)
CHARGING | UNKNOWN ─▶ EXPIRED                  (the session says it was never used, at the wait's end — §9.12 #4)
OPEN ─▶ FAILED                                 (declined — §1.4 step 6)
OPEN ─▶ UNKNOWN ─▶ OPEN | SUCCEEDED | FAILED | EXPIRED   (a status call failed; asked again)
OPEN | UNKNOWN ─▶ EXPIRED                      (the hold limit, 15 minutes after Pay, passed with nothing paid — rule 15, §9.8)
EXPIRED ─▶ FAILED                              (released, then finally declined: the looking stops, no second word — §9.12 #7)
FAILED | EXPIRED | REFUSED ─▶ SUCCEEDED        (a success reported later: recorded — a refund due, rule 15)
SUCCEEDED ─▶ SUCCEEDED                         (a second successful transaction: recorded, "paid twice" — rule 3)
```

**A request** (§1.5): `OPEN` → `PAID` | `EXPIRED` (60 minutes, never while an attempt is open) |
`WITHDRAWN` (never while an attempt is open) | `NOT_ACCEPTED`.

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
| **An attempt** — `/admin/payments/{id}` | Its transactions, the gateway's ids, every call made (§1.1 rule 13), "Ask the gateway now" ("Release the try" dropped: the hold limit releases it — §9.8 #3) |
| **On the order** (Sales's screen) | The attempts block; "Request payment of the rest" / "Handle by hand" (§1.5); the overdue mark (§1.9) |
| **The bank account** | Moves from B2B's settings section to Payments' (§1.9) |

---

## 5 · Tables

Schema `payments`. Money in the smallest unit, `bigint`. Store-scoped rows name their store.

| Table | Columns |
|---|---|
| `payments.gateways` | `store_id` PK FK `platform.stores` RESTRICT · `gateway` CHECK (`MYFATOORAH`) · `active` bool · `mode` CHECK (`TEST`, `LIVE`) · `apple_pay_domains` jsonb (registered — §9.9 #7) · `created_by` · `created_at`, `updated_at` |
| `payments.gateway_keys` | `id` ULID PK · `store_id` FK · `mode` · `key_encrypted` · `key_last6` · `key_expires_on` · `secret_encrypted` · `current` bool (one current per store and mode) · `warned_days` NULL (30, 7 or 1 — the last expiry warning sent) · `rejected_at` NULL (MyFatoorah refused it — §9.9 #3) · `set_by`, `set_at` · `retired_at` NULL — **every earlier key kept until the attempts made with it are closed** (rule 14), then deleted |
| `payments.sessions` | `id` (the gateway's session id) · `store_id` · `mode` · `gateway` · `owner_id` (the quote's or the request's id) · `buyer_id` · `amount_minor` · `currency_code` · `expires_at` · `used_by` NULL (the attempt) · `created_at` · PK (`gateway`, `mode`, `id`) — rule 7 |
| `payments.requests` | `id` ULID PK · `store_id` · `order_id` (Sales's, no key) · `order_number` · `customer_id` · `amount_minor` · `currency_code` · `mode` · `state` CHECK (§4) · `expires_at` · `requested_by` (staff) · `created_at`, `updated_at` · **one open request per order** (a unique partial index WHERE `state = 'OPEN'`) |
| `payments.attempts` | `id` ULID PK · `store_id` · `charge_claimed_at` NULL (rule 4) · `owner_kind` CHECK (`TRY`, `REQUEST`) · `owner_id` (the try's id or the request's) · `order_id` (the order's id — the future one for a try) · `session_id` · `key_id` (the key it was made with — rule 14) · `mode` · `gateway` · `currency_code` · `amount_minor` · `state` CHECK (§4) · `idempotency_key` uuid UNIQUE · `invoice_id` NULL, **unique per (`gateway`, `mode`)** · `gateway_payment_id` NULL · `frame_url` NULL · `window_ends_at` · `wait_ends_at` · `next_check_at` NULL · `succeeded_at` NULL · `paid_minor` NULL · `mismatch` bool · `paid_twice` bool · `needs_attention` bool · `refused_reason` NULL · `customer_name`, `customer_email`, `customer_phone` NULL (cleared on anonymizing) · `created_at`, `updated_at` · **one open attempt per owner** (a unique partial index on `owner_id` WHERE `state IN ('CREATING','CHARGING','OPEN','UNKNOWN')` — rule 3) |
| `payments.transactions` | `id` bigint PK · `attempt_id` FK · `mode` · `gateway_payment_id`, **unique per (`gateway`, `mode`)** · `status` (as the gateway says) · `succeeded` bool · `method` · `card_brand` NULL (nothing else of the card — rule 10) · `amount_minor`, `currency_code` (the pay currency), `base_currency_code` · `paid_at` NULL (the gateway's `TransactionDate`) · `error_code` NULL, `error_message` NULL · `mismatch` bool · `published` bool (sent to Sales — false for a currency mismatch, rule 8) · `seen_at` |
| `payments.notices` | `id` bigint PK · `store_id` · `mode` · `event_reference` — **only notices whose signature checked out are stored**, unique per (`gateway`, `mode`); a bad one is only logged · `received_at` · `payload` jsonb (the card details and the customer's contact details removed) · `handled_at` NULL — kept 90 days (owner, 2026-10-27, §9.3 #8) |
| `payments.calls` | `id` bigint PK · `attempt_id` NULL · `store_id` · `endpoint` · `request` jsonb (the key, the secret and the `Authorization` header never stored; the customer's contact details cleared on anonymizing — rule 13) · `http_status` NULL · `response` jsonb NULL (card details removed) · `error` NULL · `took_ms` · `at` — kept 1 year (owner, 2026-10-27, §9.3 #8) |

Settings (Platform, declared by Payments): `payments.timers.window_minutes` (STORE, 1–30, default **5** —
owner 2026-10-28, Super Admins), `payments.timers.wait_minutes` (STORE, 1–10, default **2** — owner
2026-10-28, Super Admins), `payments.timers.hold_minutes` (STORE, 10–60, default **15** — owner 2026-10-29,
gap 8.3, Super Admins; always longer than the window and the wait together), `payments.transfer.check_hours` (STORE, 1–720, default **72** — owner 2026-10-27), and the bank
account's three, moved from B2B (`payments.bank.iban`, `payments.bank.name`, `payments.bank.holder`,
"may be empty", not sensitive: §9.10 #2).

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
| `AttemptNotFound` | NOT_FOUND | An attempt that is not there (which buyer may ask about it is Sales's to check: `charge`, `abandon` and `refresh` take no buyer) |
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
4. **Back before anything was charged** (the form open, Pay not reached the gateway): reopening checkout
   ends the try at once — checkout as it was, no order, the coupon's use not counted, the points back,
   the stock free. **Back during the bank step**: the try waits for its time (step 8), then the same.
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
19. **A success after the try was released** (§1.7): no order made; a refund due on the try, needing
    attention (as #37).
20. **Two successful transactions on one attempt**: both recorded, the second "paid twice", a refund due.
21. **Apple Pay sheet closed before paying**: nothing charged, back to the form. **STC Pay code wrong or
    timed out**: as a decline (rule 15) — the reservations kept or released by what the build's test
    proves.
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
32. **Keys changed twice while attempts are open**: each still closes with the key it was made with
    (rule 14).
33. **An old checkout after a paid try** (another tab, its own quote): Pay refused — the cart has
    changed; **the same quote twice**: refused (rule 3).
34. **A crash between the try and the charge**: the prepared attempt's first check finds the session
    unused and releases; nothing reserved stays behind.
35. **The charge's answer lost, then a 4xx to the repeat**: settled by the session, never read as
    refused (rule 4).
36. **Abandoned on the bank's page**: the reservations stay until the window and the wait end, then are
    released; the customer sees when Pay opens again (§1.4 step 8).
37. **A success after release**: recorded, a refund due on the try, needing attention, no order made.
38. **A currency refused**: `PaymentNotAccepted`, the try released, a refund due.
39. **Prices changed after the quote** (a price edited, a sale ended): Pay refused with what changed.
40. **A request**: never expires mid-payment; no second request while one is paying; a payment recorded
    by hand withdraws it.
41. **The store switched TEST ↔ LIVE while a try pays**: the order's TEST mark and number follow the
    attempt's mode, not the store's mode at completion (sales.md §9.5 #14).
42. **`ReleaseTry` after the order was created** (events out of order): changes nothing — only a live
    try is released.
43. **Reopening checkout at the moment the charge is sent**: `abandon` loses to the claim — nothing
    released, the payment answered normally (rule 15).
44. **A double click making two `charge` calls**: one claim, one call, the same answer to both (rule 4).
45. **The quote's nightly clean-up while a try is unanswered**: the quote is kept (sales.md §9.5 #11);
    the order is still made from it.
46. **The delivery address deleted while a try pays**: the order is made from the try's copy.
47. **A paid try whose order could not be made** (a failure in Sales): Sales's hourly look completes it,
    or shows it as needing attention.
48. **A payment recorded by hand while the customer is paying a request**: one waits for the other; the
    balance is paid once.
49. **"Stock was reduced" naming a try**: the flag is carried to the order when it is made.
50. **A "staff will contact you" order on a cart with a live try**: refused.

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
| §9.6 #1–8 | (a) for all eight: a 2-minute wait; leaving after the bank's step waits for the try's time; a decline ends the try; an unanswered try waits for staff after 20 minutes; a success after release is a refund due; the hourly backstop; a cancel waits for a request's payment; 30 sessions per buyer per hour — and Promotions' and Loyalty's reserve / confirm / release, and a second independent review | 2026-10-28 |
| Q6 | Every remaining pick accepted: plain-word decline reasons; a request withdrawn when the amount changes; 60 checks and 120 notices a minute; Admins change the bank account; the Payments list wherever orders are seen; the UNAVAILABLE error category; Payments → Access for permissions; the nightly re-check dropped | 2026-10-28 |
| The last three picks | Accepted: `payments.attempts.release` for Admins; each attempt's checks at the window's end, the wait's end, and 10 and 20 minutes after the charge; a page reuses an answer younger than 10 seconds | 2026-10-28 |

### 9.2 What other modules must change (written as their amendments in this PR)

1. **Sales** (sales.md §9.5 #4–#8, #11, #13, #14) — **online checkout without an order until paid**: the
   checkout's payment block opens a session; Pay starts **a checkout try** (one live per cart) that
   checks every rule of placing again, reserves the stock, the coupon and the points under the order's
   future id, `prepare` in the same transaction and `charge` after it; `PaymentSucceeded` **creates the order** — its number taken then — from
   the try; `PaymentFailed`, `PaymentNotTaken` and `PaymentNotAccepted` **release** a live try, nothing
   counted; one try per quote, for the cart's current version; a success after release is a refund due,
   no order; the
   request page (§1.5); the once-only page of §1.10; `ONLINE` payments recorded once per transaction,
   never voided when real (§9.3 #12); staff edits wait while a payment is in progress; the timer's
   cancellation, its `PAYMENT_NOT_COMPLETED` reason and the revive are **dropped**; `bankAccount` read
   from Payments; the ways to pay read from `availability()`.
2. **Inventory** — nothing new: a try holds under the order's future id with no expiry, as placing
   does, and the order keeps that same hold; `release` gives it back. An id is never held twice (a
   success after release makes no order — §1.7).
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

### 9.6 The round of 2026-10-28 — answered: (a) for all eight (owner, 2026-10-28: "Rec for all")

Answered in this round, recorded here: **"authorize, then capture" is not used** (the owner: "we dont
want any held we will take the money"). It was asked only for one rare case — a payment that goes
through **after** the try was released and its stock sold: with the money only held, it could be given
back without a refund. §1.7 now keeps the reservations until MyFatoorah answers, so that case needs a
payment MyFatoorah first said was not in progress; if it ever happens, it is a refund due (§1.7).

1. **How long the wait lasts** (§1.7) — after the 5 minutes, for every charge not yet answered (rule 15):
   (a) **2 minutes** on the page — MyFatoorah's own guidance gives a status call about 1½ minutes (3 tries, 5
   seconds apart, 30-second timeout) — then the "still confirming" page, with the reservations kept until
   MyFatoorah answers **[Recommended]**; (b) another length.
2. **A declined card** (§1.4 step 6): (a) the try ends at once, everything released, the customer back
   on checkout with the reason, and Pay again starts a new 5 minutes **[Recommended]**; (b) the customer
   may try another card inside the same 5 minutes, keeping the reservations. **Answered (a) — on the
   condition the second review added (rule 15): only once the build's test proves a declined invoice
   takes no further transaction.**
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
7. **Leaving after the bank's step was opened** (§1.4 step 8; rule 15). MyFatoorah cannot tell us whether
   a customer who left the bank's page finished it. (a) **The try ends only when its time is over** —
   at most the window and the wait (7 minutes with #1 (a)); meanwhile the customer may change the cart,
   and Pay opens again at the time shown **[Recommended]**; (b) the try ends at once when checkout is
   reopened — and if the bank's step had in fact been finished, the customer pays twice and one payment
   is a refund due **[Not recommended]**.
8. **A success after release** (§1.7): (a) **a refund due**, needing attention, no order made by itself
   **[Recommended]**; (b) the order made by itself when everything can still be taken — needs Inventory,
   Promotions and Loyalty to take the same order twice, and may sell goods a later try already paid for.

### 9.7 What Ops needs (stage 8) — **[ACCEPTED by the owner, 2026-10-28]**

Asked by the Ops spec (ops.md §1.5 C11, §1.6 S5, S5b, S7, §2.3; merged as #249); accepted by the owner
("Accept all six", 2026-10-28). Events carry ids only and go through the outbox (handoff §4.5).

1. **`storeId` on `PaymentSucceeded` and `PaymentNotAccepted`** (§2.2), so Ops can tell the store's staff
   without asking Sales; for a try that never became an order, S7 links to the attempt (built in stage 7
   step 10).
2. **`GatewayKeyExpiring`** — from the daily job that already shows the banner (§1.2): once per key and
   threshold (30, 7, 1 days). Ops tells Super Admins on Payment problems, by SMS at 1 day (ops.md S5)
   (step 8).
3. **`GatewayKeyRejected`** — when MyFatoorah refuses the current key (§1.2); a 401 on an earlier key
   kept for old attempts sends nothing. Ops tells Super Admins, SMS included (ops.md S5b) (step 9).
4. **`PaymentRequested`** — when staff create a request (§1.5). The request is open 60 minutes, so Ops
   sends the link at once (ops.md C11) (step 12).
5. **The request reaches the customer by bell and email**, never SMS (§1.5; SMS carries codes and the
   two critical staff alerts only — ops.md).

Sales's part, the overdue transfer (`TransferOverdue`), is sales.md §9.5 #16.

### 9.8 The first build step's MyFatoorah tests (P0) — **[ACCEPTED by the owner, 2026-10-29]**

Run on MyFatoorah's test server on 2026-10-28 with the published test cards (the key is a Kuwait demo
account's; the full report with every request and answer is kept out of the repository, keys and card
data removed).

| Question (§1.3, rule 15) | Answer |
|---|---|
| A payment refused after `PaymentExpiry`? | **No.** Charged with a 1-minute `PaymentExpiry`, the bank page opened 34 s after it: **paid**. The invoice's own expiry is the **session's**, not `PaymentExpiry` |
| After the session's expiry? | **No** for a charge already made: the bank page opened after the session (and invoice) expired: **paid** two minutes later. A **new** charge on an expired session is refused ("SessionId is expired!") |
| An unfinished payment | `InProgress`, invoice `Pending`, **for at least an hour**: MyFatoorah never ends it by itself |
| A declined card, then another transaction? | **Not from our page**: transaction `Failed` (`MF003`); the session is used ("SessionId is already used!"); the old bank page answers "Transaction not found" |
| When `IsUsed` turns true | At the charge, not when the details are collected; `TransactionResult` then holds the invoice and the transaction |
| The bank frame's message | `MF-3DSecure` says `isSuccess: true, paymentCompleted: true` **even for a declined payment**: only Get Payment Details tells the outcome (as rule 6 already says) |
| STC Pay's value in `SupportedPaymentMethods` | `stcpay` (also `stc_pay`, `STC_PAY`); `stc` is refused |

**The owner's decisions (2026-10-29):**

1. **Our own clock — "we have to take control of everything"**: a try is released at the **hold limit,
   15 minutes after Pay** (`payments.timers.hold_minutes`, Super Admins), whatever MyFatoorah says; a bank step
   finished after it is a refund due, listed for staff as needing attention (rule 15, §1.7). Chosen over
   60 minutes (as WooCommerce shops hold stock) and over holding until MyFatoorah answers, which P0
   showed may be never.
2. **A decline releases at once** (rule 15): the condition the second review set is proven.
3. **`ReleaseTryByHand` and `payments.attempts.release` are not built**: the hold limit releases every
   try first (§1.7).

Also found, for the build: the test account charges Visa/Mastercard, Apple Pay and Google Pay in **KWD**
(only Mada and STC Pay in SAR) — the live KSA account must charge SAR, or rule 8 refuses those payments;
the Mada test card is processed and named as **Visa** (a co-branded card), so nothing should name the
method from the card's brand.

### 9.9 The build's own picks (step 8, 2026-10-31 — simple, taken as recommended under the owner's rule)

1. **Three-part names**, as Access's and Platform's catalogs require: `payments.view` is
   **`payments.payment.view`**; the timers are **`payments.timers.window_minutes`**,
   **`payments.timers.wait_minutes`** and **`payments.timers.hold_minutes`**.
2. **`SetUpGateway` is the first `ChangeKeys`**: the store's first saved key creates its gateway, switched
   off, in that key's mode (audited `payments.gateway.set_up`, then `payments.gateway.key_changed`).
3. **MyFatoorah's refusal is marked on the key** (`payments.gateway_keys.rejected_at`), not on the gateway:
   a new key starts unrefused, and switching to the other mode's key is not blocked by this one.
4. **The expiry warnings run every hour**, so each store's day begins at its own midnight; each key is
   warned once per threshold, and a key saved within 30 days is warned of the threshold it is in
   (e.g. 5 days left → the 7-day warning), never of those behind it. A key saved with its date today is
   taken (its 1-day warning goes at once).
5. **New errors**: `GatewayNotSetUp` (NOT_FOUND — switching a gateway with no key yet), `NoGatewayKey`
   (CONFLICT — a mode, or the gateway, switched on with no key for that mode), `GatewayNotOffered`
   (UNSUPPORTED — a store outside Saudi Arabia and Egypt), `HoldTooShort` (INVALID — a timer change that
   breaks hold > window + wait).
6. **The Apple Pay file is a plain file in `public/.well-known/`**, committed when MyFatoorah sends it: the
   web server serves it, no route (Platform has nothing to build). The host must not refuse dotted
   folders. **`RegisterApplePayDomain` moves to step 9**, with the MyFatoorah client that reads a key back.
7. **`apple_pay_domains` is `jsonb`** (a list), not `text[]`.
8. **The country list is the adapter's** (`Infrastructure/External/MyFatoorahAddresses`, behind
   `Application/Gateway/GatewayAddresses`): no country code sits in Domain or Application (handoff §2.2).
9. **After the review** (2026-10-31): a key and its secret are **printable ASCII only** (an invisible mark copied
   from an Arabic page is refused before any call); a 401 names a missing permission only when MyFatoorah says
   so in its own words, otherwise the key "was refused"; an expiry warning is sent only by the run that marks
   the key, still current; days left are counted as calendar days (a daylight-saving day is a whole one).
10. **Payments' line on the settings page** ("Online payment: … · Bank transfer: …", gap 8.8) is built in
    **step 8b**, with the bank account it names.

### 9.10 The build's own picks (step 8b, 2026-11-01 — simple, taken as recommended under the owner's rule)

1. **A one-time copy in Access** (access.md amendment 92): `PermissionCatalog::copied(module, from, to)`. A rename
   could not do either job: it stays inside one module and takes the old name away. Two pairs use it:
   `sales.order.view` → `payments.payment.view` (gap 8.4 (a)) and `b2b.settings.update` →
   `payments.bank_account.update` (b2b.md amendment 33), after which B2B's name is removed. An exception's stores
   are copied too, so a person's narrowing holds. Each pair runs once: a box unticked later stays unticked.
2. **The bank settings stay not sensitive**, though §5 said "sensitive": §1.9 keeps B2B's rules unchanged, and
   every approved company of the store is shown them. Masking them would also hide a changed IBAN from the audit
   log, where it is most needed.
3. **The stored values move in a migration**: each row keeps its value, who saved it and when. A value already
   under the new name wins.
4. **B2B → Payments** in the dependency map (handoff §4.4), for `bankAccount` alone, as amendment 33 says the
   company page reads it from here. Payments depends on nothing of B2B's, so there is no cycle.
5. **The line's other two states**, beside the two the owner worded in §1.2: "Online payment: on" when live, and
   "Online payment: stopped — MyFatoorah refused the key; individuals pay through staff until a new one is saved".
   A gateway in test mode but switched off says "off". The bank's half keeps B2B's words. The line moves from the
   Companies section to the Payments section, shown with the section as every module's is (platform.md §1.3): to
   anyone who sees one of its settings, so also to a staff role holding the store settings job, for the transfer
   check hours. It names no value, and the three fields stay hidden from them.
6. **The grant reaches only the roles there when it runs** (access.md amendment 92). A production database first
   migrated with this code has no roles yet, so nothing is copied: each role made there has its own Payments box,
   unticked until an admin ticks it.

### 9.11 The build's own picks (step 9, 2026-11-01 — simple, taken as recommended under the owner's rule)

1. **The charge call is built with step 10**, whose claim and idempotency key it needs. The client's logging,
   retries and answers are here, proven on the status calls that share them.
2. **A session is asked for once**, not three times: it is not idempotent, and a page with no answer offers Retry
   (gap 9.3 (a)).
3. **What each answer means.** "We do not know", never "refused": no answer, a 5xx, a redirect, a 4xx saying "not now"
   (408, 409, 423, 425, 429), or an answer we cannot read. A 401 is the key's, unless MyFatoorah's words say one
   permission is missing. Any other 4xx, or `IsSuccess` false (as a boolean or as text, notes §9), is a no. **This
   narrows "a 4xx" of rule 4**, in the safe direction: an attempt stays open and is asked about, rather than released
   while a charge may be going on. A session is "never charged" only when it says unused with nothing to show.
   Apple Pay needs a clear `IsSuccess: true`.
4. **The notice's address uses the store's id**, as Sync's does (Platform binds `{store}` to a store's code), and the
   mode in lowercase: `/payments/notice/{storeId}/{test|live}`. Beside the 120 a minute per store, one address may
   call 600 a minute, so made-up store ids cannot be sprayed; over 100 KB is refused with 413.
5. **A notice of another kind** (a refund, a balance transfer) is answered 200 and dropped without a signature check:
   it has a different signed string, we read none of them, and a 401 would only make MyFatoorah send it again. A
   signed notice we could not store (no `Event.Reference`, one over 200 characters, or a body the database refuses) is
   thanked and dropped too; the attempt's own checks cover it.
6. **What is kept of a request, an answer or a notice**: nothing of the customer and of the card only its brand, also
   dropping the session's `EncryptionKey`, the card's token and hash, and the payer's IP address. So contact details
   never reach the log, which is tighter than rule 13 asks, and clearing them on `CustomerAnonymized` is the attempts'
   alone (step 10).
7. **`apple_pay_domains` holds `{domain, mode}` pairs** (gap 8.5: registered per mode); the domain the session checks
   is the host of `APP_URL`. A domain is asked of MyFatoorah again even when listed, since Apple may need it
   revalidated, but listed and audited once.
8. **Moved out of this step**: the 30-sessions-an-hour limit is Sales' route's, with the checkout page (step 11);
   our plain method names (gap 9.2) and the currency check (gap 9.1) come with recording a payment (step 10).
9. **A key's 401 for one permission.** MyFatoorah's "does not have the required permissions" raises
   `KeyPermissionMissing` naming the endpoint. On a session it stops online payment like any refusal, since that key
   can take no payment. On Apple Pay's registration it only tells the Super Admin, since the key still takes payments.
   A 401 on a mode not in use marks that mode's key and tells the Super Admins, though nothing has stopped yet.
10. **`GatewayRefused`'s words stay out of a page's answer**: they are in the call's log and on the exception for
    staff's screens (§7).
11. **The session is reused** (§1.4 step 0) when it is the same owner, buyer, amount, store and mode, unused and not
    over; nothing else about it is checked at that point, and `used_by` is set by step 10's attempt.
12. **Counted, signed or not**: unsigned calls use up a store's 120 notices a minute, as Sync's do. A flood delays
    notices, never payments, since the attempt's own checks settle them.
13. **Not yet proven on a real server**: the notice's signature follows MyFatoorah's documentation (notes §5.4) and is
   tested against our own signing, since notices need a public address. Apple Pay's registration answer is
   documented, not observed. Both are for the staging run.

### 9.12 The build's own picks (step 10, 2026-11-01 — simple, taken as recommended under the owner's rule)

1. **The charge sends only `Customer.Reference`.** Name, email and phone are kept on the attempt for staff, and sent to
   nobody: none is required (notes §0 #14), and a wrong shape would refuse a payment (the docs' 11-character phone
   number does not fit our `+966…`). P0 never charged with them.
2. **An attempt keeps a few columns §5 does not list**, all for this design: `buyer_id` (anonymizing), `locale` and
   `return_url` (the charge runs after the prepare), `hold_ends_at`, `last_checked_at` (the 10 seconds),
   `checking_until` (one status call at a time, without holding a row lock through a call), and `nothing_charged`.
3. **`nothing_charged` is what may say "No money was taken"** (gap 10.2): true when the charge was never sent, was
   refused at its first call, or its session says it was never used at the wait's end. `PaymentStartDto` and
   `PaymentStateDto` carry it, and `holdEndsAt` for "you can pay again at 14:45" (gap 10.1 (a)).
4. **A charge claimed but never answered, whose session says unused**: it goes back at the wait's end, not at once, so a
   request still on its way can land. No repeat is sent once the window is over (rule 4), so with the 30-second timeout
   nothing can still be on its way by then. If one lands all the same, its answer puts the checks back on and flags it
   for staff. Only then may the page have said "No money was taken" wrongly.
5. **A prepared attempt never claimed** ends at its first check, the end of the window, with no call: nothing was sent,
   so there is nothing to ask (§1.4 step 4 said "finds the session unused"). And `charge` refuses to send once its
   window has passed.
6. **The checks**: the window's end, the wait's end, 10 minutes after the charge, the hold limit, 20 minutes after, then
   hourly for 24 hours after the hold limit (rule 9, gap 10.4). After that an attempt with no final answer needs
   attention. A job is booked only when the next check's time changes.
7. **A decline after a release** is written down, ends the looking, and tells nobody: Sales has already released. After
   a success it changes nothing (rule 5).
8. **The status words**: `SUCCESS` is paid; `FAILED`, `CANCELED`, `CANCELLED` and `EXPIRED` are declined; anything else
   is still going. An invoice saying `PAID` while the transaction asked about does not, declined or not, is flagged for
   staff, and the other transaction's own notice settles it.
9. **The currency check** compares what the customer was shown in both modes, and the account's base currency in live
   (gaps 8.1, 9.1); §1.6's "pay currency" is this. An amount that cannot be read exactly is kept as text, flagged, and read
   back as what was asked. **A success with no amount or currency is no answer yet**, not a mismatch: once written down it
   could never be read again. A currency refused reads `NOT_ACCEPTED` on the page, so it can never make an order. A
   session's answer only names the payment; what it came to is always the payment's own answer.
10. **A notice naming a payment already written as a success** is not asked about again, so a replayed notice with a new
    (unsigned) `Event.Reference` makes no calls. One naming another payment on the invoice is asked about: a second
    success (rule 3).
11. **The limit of 60 checks a minute** is the check job's own: over it, the job comes back when the minute ends. The job
    carries the attempt's id and nothing else.
12. **`PaymentDto.method` is our plain name** (gap 9.2): Card, Apple Pay, STC Pay. `attempts()` for staff comes with the
    lists (step 12).
13. **Notices stay unhandled until something is learned**: MyFatoorah has had its 200 and will not send one twice, so a
    notice that found the gateway away or the attempt busy is tried again by the hourly look, for a day.
14. **A repeat's refusal is never a refusal**, whatever its form: a 4xx, a 401, or an answer saying no with a 200 or a 3xx.
15. **Whatever goes wrong in asking is "we do not know"**, so the hold limit still releases the try. A check job has 150
    seconds, longer than three tries of the gateway; the queue connection's `retry_after` must be longer still, as for the
    other long jobs (HANDOFF's deploy notes). A check that finds another call holding the attempt comes back in 30 seconds.
16. **`charge` and `refresh` must never run inside a transaction**: no lock is held through a call, and the claim must be
    committed first. It is written on the contract for step 11; a code guard would trip on every test's own transaction.
17. **Tables and DTOs beyond §5 and §2.1**: `transactions.amount_text` and `paid_twice`; `attempts.currency_refused` and
    `transaction_id`; `PaymentStartDto.nothingCharged` and `refusedReason`.
18. **Still unproven on a real server**: the charge's body with `Customer.Reference` and `IntegrationUrls`, and how
    `Idempotency-Key` behaves on a retried charge in a KSA account. Both are for the staging run.
