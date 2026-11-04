# Payments

Each store's online payment gateway - MyFatoorah - its keys, test or live, and the timers of a payment;
the session, the charge, the checks and the notices; the payment requests staff send for what an order is
missing; and each store's bank account for transfers. The specification is
[`docs/modules/payments.md`](../../../docs/modules/payments.md) (accepted 2026-10-28; the build's own
picks in §9.9 to §9.14).

Built in stage 7, steps 8 to 12, backend only; the screens are the screens session's (§4.1) and only the
Gateway page is built so far. Payments takes an order id and an amount, nothing more: it depends on
Platform, and on Access only to declare its permissions and to hear `CustomerAnonymized`
(`tests/Architecture/PaymentsAccessUseTest`). It never reads Sales: Sales calls it and hears its events.

## What is built

| Step | What | Where |
|---|---|---|
| 8 | The permissions (`payments.gateway.manage` reserved for Super Admins, `payments.bank_account.update` admin-only, `payments.payment.view`), the four settings, the errors of §7 in both languages | `Application/PaymentsPermissions`, `Application/PaymentsSettings`, `Domain/Exception`, `Presentation/lang` |
| 8 | **The gateway** (§1.2): a mode's key and notice secret saved after MyFatoorah's three test calls, encrypted, shown by their last 6 characters; the first key sets the gateway up, off; test ↔ live and on/off, each only with a key for the mode; the gateway page's data | `Application/Command/{ChangeKeys,SwitchMode,SwitchGateway}`, `Application/Query/ViewGateway`, `Infrastructure/External/MyFatoorahKeyTester` |
| 8 | **What other modules ask** (§2.1): `availability()` (with why not: no gateway, off, key refused, a company), `inTestMode`, `timers`, `transferCheckHours` | `Application/PaymentsApiImpl` |
| 8 | **The expiry warnings** (§1.2): 30, 7 and 1 days before a current key's date, once each, as `GatewayKeyExpiring` through the outbox for Ops | `Application/Gateway/KeyExpiryWarnings`, `Infrastructure/Queue/WarnExpiringKeysJob` (hourly) |
| 8 | **The timers' guard** (§1.7): the hold limit stays longer than the window and the wait together | `Infrastructure/TimersGuard` (registered with Platform's `SettingGuards`) |
| 8b | **The bank account** (§1.9, moved from B2B): the IBAN, the bank and the holder per store, an admin's alone; `bankAccount()`, null until all three are filled in | `Application/Settings`, `Application/PaymentsApiImpl` |
| 8b | **The settings line** (§1.2, gap 8.8): how the store shown takes money, online and by transfer | `Infrastructure/Settings/PaymentsSettingsLine` |
| 8b | **The one-time grants** (§9.10 #1): whoever views orders gets `payments.payment.view`, whoever held `b2b.settings.update` gets `payments.bank_account.update` | `Infrastructure/PaymentsServiceProvider`, Access's `copied` |
| 9 | **The MyFatoorah client** (§1.1 rules 4, 9, 13): sessions, the charge, status reads, Apple Pay; every try logged with no key, no card and no customer; a 401 is the key's, another 4xx a no, anything else "we do not know" | `Application/Gateway/GatewayClient`, `Infrastructure/External/MyFatoorahClient`, `MyFatoorahScrub` |
| 9 | **The session** (§1.4 step 0): opened for a quote or a request, written down as ours (rule 7), reused while unused; 30 new ones an hour per buyer | `Application/Session/OpenPaymentSession` |
| 9 | **A refused key** (§1.2): the current key marked, online payment stopped, `GatewayKeyRejected` once | `Application/Gateway/GatewayAccesses` |
| 9 | **The notice** (§1.2): `POST /payments/notice/{storeId}/{test,live}`; signature against every secret still kept, stored once; 120 calls a minute per store, 600 per address, 100,000 bytes | `Presentation/notice-routes.php`, `Presentation/Http/Controller/PaymentNoticeController`, `Application/Notice/NoticeReceiver`, `Infrastructure/External/MyFatoorahNoticeReader` |
| 9 | **Apple Pay's domains** (§1.2): registered per mode | `Application/Command/RegisterApplePayDomain` |
| 10 | **The attempt** (§1.4, §4): prepared in Sales's transaction with no call; charged after its commit, claimed first; ended unsent by `abandon` | `Application/Attempt/AttemptPreparation`, `AttemptCharge` |
| 10 | **The answers** (rules 3, 5, 6, 8): one state machine, under the row's lock, with its events through the outbox | `Application/Attempt/AttemptSettlement` |
| 10 | **The checks** (rules 1, 9, 15): booked per attempt, one call at a time, the hold limit by our own clock, then hourly to 24 hours; the hourly backstop for a lost job | `Application/Attempt/AttemptChecks`, `CheckSchedule`, `AttemptSweep`, `Infrastructure/Queue` |
| 10 | **A notice handled** (rules 1, 7): it only makes us ask, and only about its own attempt | `Application/Notice/NoticeHandling` |
| 10 | **Anonymizing** (rule 13): the customer's details leave their attempts | `Infrastructure/Listener/ClearCustomerFromAttempts` |
| 11 | **What Sales's checkout try uses**: `inTestMode`, `timers`, `needsAttention`; the amount comes from Sales, never the page. Later (#331) `lastKnown`, the last answer with the gateway not asked, for a page load | `Application/PaymentsApiImpl` |
| 12 | **Payment requests** (§1.5): staff ask for exactly what is missing, open 60 minutes, one open per order; withdrawn by staff (never while a payment on it is open) or by a cancel; a request's time ends only when it is next looked at; `PaymentRequested` through the outbox | `Application/Request/OrderPaymentRequests`, `Domain/Model/PaymentRequest` |
| 12 | **The reads** (§4.2): `paymentOpen`, `openRequest`, `openRequests` (one read for a page of My Orders), `attempts`, `successes`, `payment`; the staff list `ListPayments` (those needing attention first - the newest 50 with their count - then 50 at a time) and `ViewAttempt`; staff's "Ask the gateway now" `CheckAttempt` | `Application/Query/{ListPayments,ViewAttempt}`, `Application/Command/CheckAttempt`, `Application/Attempt/AttemptViews`, `Infrastructure/Eloquent/DatabasePaymentReads` |
| 12 | **Pruning the logs** (§5): notices past 90 days, calls past a year, daily at 03:45 | `Application/Attempt/PaymentLogPruning`, `Infrastructure/Queue/PrunePaymentLogJob` |

**The bank account** is three per-store settings, `payments.bank.iban`, `payments.bank.name` and `payments.bank.holder`,
changed only by an admin under `payments.bank_account.update`. They start empty, meaning "not set yet" (a text setting
may say so, and its rules then apply only to a value that is not empty; platform.md §1.3). `IbanRule` checks an IBAN's
shape (two letters, two digits, 11 to 30 letters and digits) and its check digits (ISO 13616, the number modulo 97),
spaces ignored; the value is **kept as typed**, so up to 42 characters, 34 in groups of four. No country's length is
written in, and check digits 00, 01 and 99, which no IBAN has, are refused. **Bank transfer is on only while all three
are filled in**: until then `bankAccount()` answers null, checkout offers paying through staff alone, and the settings
line says so. `StoreBankAccount` is the one reader. Not sensitive: every approved company of the store is shown them,
and a changed IBAN must stay readable in the audit log.

## How a payment runs

1. **Session.** Sales's checkout page asks `openSession` for the quote's total (or a request page for the request's
   amount); nothing is held or charged. The session lasts the quote's time plus the window and the wait.
2. **Pay.** In one transaction Sales checks the quote again, calls `prepare` (the attempt is written, `CREATING`, its
   idempotency key made, its checks booked, **no call to the gateway**) and reserves the stock, the coupon and the points.
3. **Charge.** After that commit Sales calls `charge`: under the row's lock the attempt becomes `CHARGING` and is
   committed, only then is the gateway called, so a second `charge` makes no call and nothing can end the attempt as
   "never charged". `charge` and `refresh` are never called inside a transaction.
4. **Answers.** The customer's page (`refresh`, at most one real call each 10 seconds), a notice, and the attempt's own
   booked checks all end in the same place: `AttemptSettlement`. A notice never changes anything by itself, it only makes
   us ask the gateway, and the gateway's answer is the only truth.
5. **Events.** `PaymentSucceeded` (once per transaction), `PaymentFailed`, `PaymentNotTaken` (the hold limit passed, or the
   charge never reached the gateway), `PaymentNotAccepted` (money taken in a currency we refuse), all through the outbox.
   Sales makes the order on a success and releases a try on the others.
6. **Time.** Defaults: the customer has 5 minutes on the bank's page (`payments.timers.window_minutes`, 1 to 30), we
   wait 2 more (`wait_minutes`, 1 to 10), and a try still unanswered 15 minutes after Pay is released (`hold_minutes`,
   10 to 60; always longer than the two together). Checks run at the window's end, the wait's end, 10 and 20 minutes after
   the charge, the hold limit, then hourly for 24 hours after it, so a late success is found and recorded as a refund due.

An attempt's states are `CREATING`, `CHARGING`, `OPEN`, `UNKNOWN`, `SUCCEEDED`, `FAILED`, `REFUSED`, `EXPIRED` and
`ABANDONED` (`Domain/Model/AttemptState`); a request's `OPEN`, `PAID`, `EXPIRED`, `WITHDRAWN`, `NOT_ACCEPTED`.

## How a change runs

`Application/Gateway/GatewayChange` asks for `payments.gateway.manage` in the store (staff only), then runs
the change in one transaction under **the store's gateway lock** (an advisory lock), with its audit entries
(`PaymentsAudit`, "payments.gateway.*"). A key is tested **before** the lock is taken, so nothing waits on
MyFatoorah. The audit log never holds a key or a secret: only "replaced" and the last 6 characters.

## The keys

`payments.gateway_keys` keeps every key a store has had, one **current** per store and mode; an earlier key
is retired, not deleted, because an attempt's `key_id` points at it with `RESTRICT` (rule 14; payments.md §9.14 #13).
Key and secret are encrypted with the app's key (`DatabaseGateways`) and are decrypted only to make a call or to check a notice's signature.
MyFatoorah's addresses are fixed per mode and country in `Infrastructure/External/MyFatoorahAddresses`: Saudi Arabia
and Egypt live, one test address.

## Tables, permissions, events, jobs

- **Tables** (schema `payments`): `gateways`, `gateway_keys`, `sessions`, `calls`, `notices`, `attempts`, `transactions`,
  `requests`. One open attempt per owner and one open request per order are partial unique indexes
  (`attempts_one_open`, `requests_one_open`); a transaction is unique per gateway, mode and gateway payment id.
- **Permissions** (per store, in the role editor's Payments group): `payments.gateway.manage`, `payments.bank_account.update`,
  `payments.payment.view`. The checks, the sweep and the pruning declare no permission: payments.md §3 lists them as
  the system's, "reserved", but only the three above are declared.
- **Settings**: the three timers above, `payments.transfer.check_hours` (1 to 720, default 72), and the bank account's three.
- **Events published** (`Public/Events`, ids only): `PaymentSucceeded`, `PaymentFailed`, `PaymentNotTaken`,
  `PaymentNotAccepted`, `PaymentRequested`, `GatewayKeyExpiring`, `GatewayKeyRejected`. Sales hears the first four
  (`Infrastructure/Listener` in Sales); Ops hears the key's two and `PaymentNotAccepted` (`AlertStaff`).
- **Events heard**: `CustomerAnonymized` (Access). `OrderCancelled` is heard by Sales, which calls `withdrawForCancelled`.
- **Jobs**: `CheckAttemptJob` (one per booked check, 60 a minute across the system, 300 s timeout, no limit on tries),
  `HandleNoticeJob` (300 s, 3 tries), and the schedule: `WarnExpiringKeysJob` and `SweepAttemptsJob` hourly,
  `PrunePaymentLogJob` daily at 03:45. The sweep is the one backstop for a lost check or notice, and takes the oldest 60.
- **Errors**: seventeen `payments.*` types, each in both languages; `GatewayUnreachable` is the 503 (`ErrorCategory::Unavailable`),
  `TooManyPaymentSessions` the 429.

## Not here yet

- The Payments list, an attempt's page and the request's pages have their reads (`ListPayments`, `ViewAttempt`) and no
  routes; only the Gateway page (`payments.admin.gateway`, `Presentation/admin-routes.php`) is built.
- Ops has no listener for `PaymentRequested`, so no customer is sent a request's link yet (ops.md C11).
- Not proven on a real server (payments.md §9.13 #20, §9.11 #13): amounts arriving as text, `ExternalIdentifier` in status
  answers, the charge's body, `Idempotency-Key` on a retried charge, the notice's signature and Apple Pay's registration.
  They are for the staging run.

## Tests

`tests/Modules/Payments`: `Integration/GatewayTest` (the commands, availability, the warnings, the timers) with the key test
faked; `MyFatoorahKeyTesterTest`, `MyFatoorahClientTest` (against `Http::fake`); `PaymentSessionsTest`;
`PaymentAttemptsTest` (the state machine, the checks, the notices' effects); `PaymentRequestsTest` and
`PaymentRequestRaceTest`; `PaymentReadsTest`; `BankAccountSettingsTest`; `Unit/IbanRuleTest`; `Feature/GatewayScreenTest`,
`PaymentNoticesTest`, `PaymentsSettingsLineTest`; `tests/Browser/PaymentsGatewayScreenTest`. Sales's side is in
`tests/Modules/Sales/Integration/{CheckoutTryTest,CheckoutTryRaceTest,PaymentRequestTest,PaymentRequestRaceTest}`.
No test calls MyFatoorah: `Support/FakeMyFatoorah` stands in.

## Go-live

1. Set `DB_QUEUE_RETRY_AFTER=330` at hosting. The check job and the notice job may run 300 s (`$timeout = 300`), a check's
   own claim lasts 320 s, and the queue's `retry_after` must be longer than both or a second worker picks a running job up.
   `config/queue.php` reads it for the default `database` connection (`env('DB_QUEUE_RETRY_AFTER', 90)`); `.env.example`
   does not set it, so it is 90 until hosting sets it.
2. Ops must have its `TransferOverdue` listener before this goes live: Sales marks an order told when it fires the event,
   so an order marked while nobody listens is never told again (sales.md §9.9 #13). Today Ops has the kind and its words
   (`MessageKind::TransferOverdue`) and no listener.
3. The KSA MyFatoorah key, and one real-money test: only a Kuwait test key exists today (the owner's note; nothing in the
   code can show which keys exist). The live addresses are `api-sa.myfatoorah.com` and `sa.myfatoorah.com`.
4. Trusted proxies, so a recorded IP is the visitor's, not the proxy's (HANDOFF §15.4, "Decided when hosting is chosen").
   No trusted-proxy setting is in `bootstrap`, `app`, `config` or `src` today.
5. MyFatoorah's Apple Pay file in `public/.well-known/`, committed when they send it (payments.md §9.9 #6). It isn't there
   yet, and Apple Pay stays off until it is and the domain is registered.
6. Ops must have its `PaymentRequested` listener (ops.md C11): until then a customer gets no bell or email for a payment request, only the note on their order.
7. Cancel every open test order before the staging database becomes the real one (§1.6): a test order can't ship, so its stock stays held and its coupon and points stay used until it is cancelled.
