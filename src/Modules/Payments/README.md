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

Still to come: the bank account moved from B2B and the one-time `payments.payment.view` grant (step 8b);
the session, the charge, `RegisterApplePayDomain` and the notices (steps 9-10); payment requests (step 11).

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
