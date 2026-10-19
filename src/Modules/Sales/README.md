# Sales

The cart, checkout, orders and their money, shipping and delivery, staff edits and returns: the
transaction. The specification is [`docs/modules/sales.md`](../../../docs/modules/sales.md) (accepted
2026-10-10, the owner's answers of 2026-10-19 in §9.1b, 31–44).

Backend only in this stage's build; the screens are another session's. Shipping and Payments are stage
7's: Sales declares what it needs of them (`Modules\Shipping\Public`, `Modules\Payments\Public`,
provisional) and, until they exist, uses its flat shipping fee and offers no online payment.

## Build steps

| Step | What | Where |
|---|---|---|
| F0 — the contract Feedback reads | `SalesApi` (`deliveredPurchase`, `returnedProducts`), `ReturnCompleted`, answered by the placeholder `Infrastructure/External/NothingSoldYet` until S10 | `Public`, built with Feedback |
| S1 — foundation | The `sales` schema; the fourteen permissions of §3 (every customer's cart and orders and every guest's cart, per store; the staff jobs under Orders; `sales.settings.update` admin-only; the system's reserved `sales.system.run`); the three store settings of §1.10 (flat fee 0, free from 0 — never free —, return window 14 days); the errors of §7 in both languages; `OrderPlaced`, `OrderCancelled`, `OrderDelivered`; stage 7's `ShippingApi` and `PaymentsApi` (with `PaymentAudience`, `PaymentSucceeded`, `PaymentFailed`), provisional and unbound; the owner's answers 41–44 in the spec | below |
| S2 — the outbox | `Shared/Infrastructure/Outbox` (platform.md §5.6; a recorded exception to Shared's ceiling, answer 43): `Outbox::record` in the change's transaction; `OutboxRelay` every minute from one server (queued as Platform's `RelayOutboxJob`) — claimed under `FOR UPDATE SKIP LOCKED` and leased before dispatch, retried 1, 2, 4 … minutes up to an hour, never dropped, logged from the tenth attempt, dispatched rows kept 30 days; `ProcessedEvents::once` for idempotent consumers; tables `public.outbox_messages`, `public.processed_events` | `src/Shared/Infrastructure/Outbox`, `database/migrations` |
| S3 — what Sales reads of Catalog and Access | catalog.md amendment 29: `storeVariants` (many sizes in a store in one read) and `productsOpenTo` (which products the category switches leave open to a shopper); access.md amendment 74: `GuestIdentity` (`current`, `ensure` — the guest cart's id, its cookie Access's) | `src/Modules/Catalog/Public`, `src/Modules/Access/Public` |

## What is here

- **`Infrastructure/SalesServiceProvider`** — loads the migrations and the words, declares the
  permissions through Access and the settings through Platform; binds `SalesApi` to the placeholder.
- **`Application/SalesPermissions`**, **`Application/SalesSettings`**.
- **`Infrastructure/Persistence/SalesSchema`** and its migration — the `sales` schema, on the
  connection's search path so a fresh migration wipes it.
- **`Domain/Exception`** — the errors of spec §7, each with its type (`sales.{snake}`), its status, and a
  title and a message in Arabic and English at `sales::errors.{snake}`.
- **`Public/Events`** — the critical events (handoff §4.5), ids only, after commit; published through the
  outbox once it exists (step S2).

Tests: `tests/Modules/Sales/Integration` — `SalesPermissionsTest`, `SalesSettingsTest`,
`SalesErrorMessagesTest`, `SalesSchemaTest`.
