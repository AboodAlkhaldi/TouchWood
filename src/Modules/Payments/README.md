# Payments

Each store's online payment gateway - MyFatoorah - its keys, test or live, and the timers of a payment;
from steps 10-12, the session, the charge, the notices and the payment requests. The specification is
[`docs/modules/payments.md`](../../../docs/modules/payments.md) (accepted 2026-10-28; the build's own
picks in §9.9).

Built in stage 7, backend only; the screens are the screens session's (§4.1). Payments takes an order id
and an amount, nothing more: it depends on Platform, and on Access only to declare its permissions
(`tests/Architecture/PaymentsAccessUseTest`).

## What is built

| Step | What | Where |
|---|---|---|
| 8 | The permissions (`payments.gateway.manage` reserved for Super Admins, `payments.bank_account.update` admin-only, `payments.payment.view`), the four settings, the errors of §7 in both languages | `Application/PaymentsPermissions`, `Application/PaymentsSettings`, `Domain/Exception`, `Presentation/lang` |
| 8 | **The gateway** (§1.2): a mode's key and notice secret saved after MyFatoorah's three test calls, encrypted, shown by their last 6 characters; the first key sets the gateway up, off; test ↔ live and on/off, each only with a key for the mode; the gateway page's data | `Application/Command/{ChangeKeys,SwitchMode,SwitchGateway}`, `Application/Query/ViewGateway`, `Infrastructure/External/MyFatoorahKeyTester` |
| 8 | **What other modules ask** (§2.1): `availability()` (with why not: no gateway, off, key refused, a company) and `transferCheckHours()` | `Application/PaymentsApiImpl` |
| 8 | **The expiry warnings** (§1.2): 30, 7 and 1 days before a current key's date, once each, as `GatewayKeyExpiring` through the outbox for Ops | `Application/Gateway/KeyExpiryWarnings`, `Infrastructure/Queue/WarnExpiringKeysJob` (hourly) |
| 8 | **The timers' guard** (§1.7): the hold limit stays longer than the window and the wait together | `Infrastructure/TimersGuard` (registered with Platform's `SettingGuards`) |
| 8b | **The bank account** (§1.9, moved from B2B): the IBAN, the bank and the holder per store, an admin's alone; `bankAccount()`, null until all three are filled in | `Application/Settings`, `Application/PaymentsApiImpl` |
| 8b | **The settings line** (§1.2, gap 8.8): how the store shown takes money, online and by transfer | `Infrastructure/Settings/PaymentsSettingsLine` |
| 8b | **The one-time grants** (§9.10 #1): whoever views orders gets `payments.payment.view`, whoever held `b2b.settings.update` gets `payments.bank_account.update` | `Infrastructure/PaymentsServiceProvider`, Access's `copied` |
| 9 | **The MyFatoorah client** (§1.1 rules 4, 9, 13): sessions, status reads, Apple Pay; every try logged with no key, no card and no customer; a 401 is the key's, another 4xx a no, anything else "we do not know" | `Application/Gateway/GatewayClient`, `Infrastructure/External/MyFatoorahClient`, `MyFatoorahScrub` |
| 9 | **The session** (§1.4 step 0): opened for a quote or a request, written down as ours (rule 7) | `Application/Session/OpenPaymentSession` |
| 9 | **A refused key** (§1.2): the current key marked, online payment stopped, `GatewayKeyRejected` once | `Application/Gateway/GatewayAccesses` |
| 9 | **The notice** (§1.2): `POST /payments/notice/{storeId}/{test,live}`; signature against every secret still kept, stored once. Only a signal: step 10 handles it | `Presentation/notice-routes.php`, `Application/Notice/NoticeReceiver`, `Infrastructure/External/MyFatoorahNoticeReader` |
| 9 | **Apple Pay's domains** (§1.2): registered per mode | `Application/Command/RegisterApplePayDomain` |
| 10 | **The attempt** (§1.4, §4): prepared in Sales's transaction with no call; charged after its commit, claimed first; ended unsent by `abandon` | `Application/Attempt/AttemptPreparation`, `AttemptCharge` |
| 10 | **The answers** (rules 3, 5, 6, 8): one state machine, under the row's lock, with its events through the outbox | `Application/Attempt/AttemptSettlement` |
| 10 | **The checks** (rules 1, 9, 15): booked per attempt, one call at a time, the hold limit by our own clock, then hourly to 24 hours; the hourly backstop for a lost job | `Application/Attempt/AttemptChecks`, `CheckSchedule`, `AttemptSweep`, `Infrastructure/Queue` |
| 10 | **A notice handled** (rules 1, 7): it only makes us ask, and only about its own attempt | `Application/Notice/NoticeHandling` |
| 10 | **Anonymizing** (rule 13): the customer's details leave their attempts | `Infrastructure/Listener/ClearCustomerFromAttempts` |

**The bank account** is three per-store settings, `payments.bank.iban`, `payments.bank.name` and `payments.bank.holder`,
changed only by an admin under `payments.bank_account.update`. They start empty, meaning "not set yet" (a text setting
may say so, and its rules then apply only to a value that is not empty; platform.md §1.3). `IbanRule` checks an IBAN's
shape (two letters, two digits, 11 to 30 letters and digits) and its check digits (ISO 13616, the number modulo 97),
spaces ignored; the value is **kept as typed**, so up to 42 characters, 34 in groups of four. No country's length is
written in, and check digits 00, 01 and 99, which no IBAN has, are refused. **Bank transfer is on only while all three
are filled in**: until then `bankAccount()` answers null, checkout offers paying through staff alone, and the settings
line says so. `StoreBankAccount` is the one reader. Not sensitive: every approved company of the store is shown them,
and a changed IBAN must stay readable in the audit log.

Still to come: Sales's checkout try (step 11); payment requests, the lists and pruning the logs (step 12).

## How a change runs

`Application/Gateway/GatewayChange` asks for `payments.gateway.manage` in the store (staff only), then runs
the change in one transaction under **the store's gateway lock** (an advisory lock), with its audit entries
(`PaymentsAudit`, "payments.gateway.*"). A key is tested **before** the lock is taken, so nothing waits on
MyFatoorah. The audit log never holds a key or a secret: only "replaced" and the last 6 characters.

## The keys

`payments.gateway_keys` keeps every key a store has had, one **current** per store and mode; an earlier key
is retired, not deleted, until the attempts made with it close (rule 14). Key and secret are encrypted with
the app's key (`DatabaseGateways`) and nothing in the module returns them in clear yet - the charge (step 9)
is the first reader. MyFatoorah's addresses are fixed per mode and country in
`Infrastructure/External/MyFatoorahAddresses`: Saudi Arabia and Egypt live, one test address.

## Tests

`tests/Modules/Payments/Integration`: `GatewayTest` (the commands, availability, the warnings, the timers),
with the key test faked; `MyFatoorahKeyTesterTest` (the three calls, against `Http::fake`). No test calls
MyFatoorah.
