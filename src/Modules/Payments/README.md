# Payments

Each store's online payment gateway - MyFatoorah - its keys, test or live, and the timers of a payment;
from steps 9-11, the session, the charge, the notices and the payment requests. The specification is
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

**The bank account** is three per-store settings, `payments.bank.iban`, `payments.bank.name` and `payments.bank.holder`,
changed only by an admin under `payments.bank_account.update`. They start empty, meaning "not set yet" (a text setting
may say so, and its rules then apply only to a value that is not empty; platform.md §1.3). `IbanRule` checks an IBAN's
shape (two letters, two digits, 11 to 30 letters and digits) and its check digits (ISO 13616, the number modulo 97),
spaces ignored; the value is **kept as typed**, so up to 42 characters, 34 in groups of four. No country's length is
written in, and check digits 00, 01 and 99, which no IBAN has, are refused. **Bank transfer is on only while all three
are filled in**: until then `bankAccount()` answers null, checkout offers paying through staff alone, and the settings
line says so. `StoreBankAccount` is the one reader. Not sensitive: every approved company of the store is shown them,
and a changed IBAN must stay readable in the audit log.

Still to come: the session, the charge, `RegisterApplePayDomain` and the notices (steps 9-10); payment requests (step 11).

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
