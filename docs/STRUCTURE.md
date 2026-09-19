# TouchWood Platform — Repository Structure

Laravel 13 / PHP 8.4 · Inertia + React + shadcn (SSR) · PostgreSQL (also sessions, cache, queues — no Redis for now)
Modular monolith, 15 modules, boundaries enforced by Deptrac in CI from commit one.

---

## Top level

```
touchwood/
├── src/                      ← ALL domain code. Not app/.
│   ├── Shared/               Kernel. ~20 classes, hard ceiling.
│   └── Modules/              The 15 modules.
├── app/                      Laravel's own bootstrap only. Providers, Kernel.
│                             No business logic ever lands here.
├── resources/js/             Inertia + React + shadcn (not scaffolded yet)
├── lang/ar/, lang/en/        Backend-generated text: emails, SMS, validation,
│                             status labels. Resolved from the CUSTOMER's stored
│                             locale, never the request locale.
├── database/seeders/         Super Admin, currencies, permission catalog
├── routes/                   Thin. Each module registers its own routes.php.
├── config/
├── docs/
│   ├── HANDOFF.md            The source of truth. Wins over this file.
│   ├── architecture/adr/     Architecture Decision Records
│   └── modules/              One specification per module
├── tests/
│   ├── Shared/               Unit · Integration · Feature
│   ├── Modules/{Name}/       Unit · Integration · Feature
│   └── Architecture/         Pest architecture tests (see below)
├── compose.yaml              Local Postgres 17 (port 5433)
├── docker/postgres/init/     Creates touchwood_test on first start
├── .github/workflows/ci.yml  pint → phpstan → deptrac → pest
├── deptrac.yaml              Boundary rules. BLOCKING.
├── phpstan.neon              Larastan level 8
└── composer.json             `composer check` runs the same four gates as CI
```

---

## The modules

```
src/Modules/
│
│  TIER 3 — foundation
├── Platform/     Stores, currencies, tax rate, settings, media, audit log
├── Content/      CMS pages, banners, homepage config, campaigns & themes,
│                 pop-ups, alert banners, smart bar, blog, projects, SEO
├── Ops/          Notifications (email/SMS), newsletters, subscribers,
│                 reports, exports
│
│  TIER 2 — identity & support
├── Access/       Customer + StaffUser identity, auth, verification, OTP,
│                 roles, permissions, staff invitations, addresses, sessions
├── B2B/          Company registration, documents, approval lifecycle
├── Promotions/   Coupons, customer segments, automatic promotions, gifts
├── Loyalty/      Points: earn, redeem, expiry, per-store config
├── Shipping/     Carriers, fixed rate tables, packaging engine, shipments,
│                 tracking links
├── Payments/     Gateway adapters, transactions, manual refunds,
│                 bank transfer verification
├── Feedback/     Reviews, ratings, product questions & answers
├── Sync/         External inventory provider adapters, mapping, outbox,
│                 conflict log, reconciliation
│
│  TIER 1 — commerce core
├── Catalog/      Products, variants, categories, brands, attributes,
│                 per-store availability, search read model, synonyms,
│                 search query log, product relations
├── Pricing/      Price lists, tiers, company prices, campaign prices,
│                 resolution engine, tax application
├── Inventory/    Stock, reservations, movement ledger, display bands
└── Sales/        Cart, quote, order, cancellation, return
```

**Why 15 and not 14.** `Feedback` is new: reviews, ratings and product Q&A are all
customer-written, product-attached, staff-moderated, bilingual content needing
translate-on-demand. They share an axis of change with each other and none with
"what we sell and how it's found," so they do not belong inside Catalog — which is
already the largest module on the critical path.

---

## Inside every module — identical, no exceptions

```
src/Modules/{Name}/
│
├── Public/                 ← THE ONLY NAMESPACE OTHER MODULES MAY IMPORT
│   ├── Contracts/          {Name}Api.php — the module's interface
│   ├── Dto/                Plain final readonly classes crossing the boundary
│   ├── Events/             Integration events. Carry IDs, never payloads.
│   └── Enums/              Backed enums other modules need to read
│
├── Domain/                 Framework-free. No Eloquent, no facades, no container.
│   ├── Model/              Aggregates and entities
│   ├── ValueObject/
│   ├── Repository/         INTERFACES ONLY
│   ├── Event/              Internal domain events (never leave the module)
│   ├── Service/            Domain services — PricingEngine, PackagingEngine
│   └── Exception/          Module-owned hierarchy, one base class
│
├── Application/
│   ├── Command/            One directory per use case:
│   │                         PublishProduct/{Command,Handler}.php
│   │                       Transactions and authorization live HERE.
│   ├── Query/              Read models. Raw SQL / query builder → DTO out.
│   │                       Never hydrates Eloquent for listings. The interface
│   │                       lives here; a cached or database implementation
│   │                       lives in Infrastructure.
│   ├── Listener/           Reacts to other modules' Public\Events
│   ├── {Area}/             Optional. What one area of the module needs from the
│   │                       outside world, as interfaces, plus small helpers
│   │                       its handlers share — Platform: Audit/, Settings/, Media/
│   └── {Name}ApiImpl.php   Implements Public\Contracts\{Name}Api
│
├── Infrastructure/
│   ├── Eloquent/           Persistence: models, repository and read-model
│   │                       implementations — Eloquent or the query builder
│   ├── Persistence/
│   │   └── Migrations/     Module-owned. Own schema: catalog.products
│   ├── Listener/           Infrastructure-level subscribers
│   ├── Queue/              Queued jobs, each only calling an Application handler,
│   │                       and the adapters that dispatch them
│   └── External/           Third-party adapters (gateways, providers, file storage)
│
└── Presentation/
    ├── Http/
    │   ├── Controller/     Storefront + Admin
    │   ├── Request/        Form requests — shape and type validation only
    │   └── Resource/       Inertia props
    └── routes.php
```

### The boundary rule

> A module may import **only** `Modules/{Other}/Public/**` and `Shared/**`.

In `deptrac.yaml` each module is two layers that never overlap: `{Name}Public` (the
`Public/` directory) and `{Name}` (everything else). `{Name}` is allowed `+{Name}Public`,
meaning its own surface plus whatever that surface may see, so each module's arrows are
written once. Framework namespaces are a `Vendor` layer; a dependency on any namespace not
listed there is reported as uncovered and fails the build until it is added on purpose.

Consequences that are easy to get wrong:

- **Never pass an Eloquent model across a boundary.** DTOs only.
- **No cross-module Eloquent relationships.** No `Order::product()`. Call `CatalogApi`.
- **Never leak `ModelNotFoundException`.** Return `null` or throw a module-owned exception.
- **Foreign keys across schemas are allowed** for referential integrity. The ban is on
  the ORM relationship, not the database constraint.
- `Domain/` never imports Laravel. If it needs the time, it takes a `Clock`.

---

## Shared kernel — and its ceiling

What exists today:

```
src/Shared/
├── Domain/
│   ├── Error/            DomainError · ErrorCategory
│   └── ValueObject/      Money · MoneyException · StoreId
├── Application/          StoreContext · MissingStoreContext · CrossStoreWrite
│                         Authorizer · PermissionScope · Unauthorized
│                         ActorContext · Actor · ActorType · CorrelationId
└── Infrastructure/
    ├── Persistence/      BelongsToStore · StoreScope
    └── Cache/            VersionedCache
```

`VersionedCache` moved here from Platform when Access needed it for staff permissions (owner,
2026-09-19): every module that caches follows the same never-stale rule. 18 classes.

The error renderer (`ProblemDetails`) and the correlation-id middleware are framework glue and live
in `app/Http` (owner, 2026-09-18); only the correlation id's Context key stays in the kernel.

Candidates from the handoff, each added only when three or more modules need it — otherwise it
stays in its module: `Sku`, `Quantity`, `Locale`, `Percentage`, `Weight`, `Dimensions`,
`DomainEvent`, `IntegrationEvent`, `AggregateRoot`, `Clock`. No `CommandBus` or `EventBus`:
Laravel's own dispatcher does that job (owner, 2026-09-18).

**Hard rule: if Shared grows past ~20 classes, something has leaked into it.**
A type belongs here only if three or more modules genuinely need it and it will
essentially never change. When in doubt, duplicate the type in both modules —
duplication is cheaper than a shared kernel that becomes a dumping ground.

### Money

```php
Money = (int minorUnits, string currencyCode)
```

Never `DECIMAL`, never `float`, never a hardcoded `/100` or `number_format($x, 2)`.
The exponent is read from the `currencies` row. Must expose `format()`, `add()`,
`multiply()` and **`allocate()`** — splitting a 100.00 discount across three lines
must not lose a halala.

---

## Dependency graph

Every arrow in `deptrac.yaml` is deliberate. Reading it top to bottom:

```
Platform  →  (nothing)

Access    →  Platform
B2B       →  Platform, Access

Catalog   →  Platform
Pricing   →  Platform, Catalog, B2B          ← B2B for company approval status
Inventory →  Platform, Catalog

Promotions→  Platform, Access, Catalog, Pricing   ← Sales passes it the cart and the order facts
Loyalty   →  Platform, Access
Shipping  →  Platform, Catalog
Payments  →  Platform                         ← takes an order id + amount, nothing more
Feedback  →  Platform, Access, Catalog, Sales ← Sales for verified-purchase
Sync      →  Platform, Catalog, Pricing, Inventory

Sales     →  everything above                 ← widest surface, by design
             except Feedback and Sync:        Feedback depends on Sales (verified purchase),
                                              so the reverse arrow would be a cycle; Sales
                                              never needs the inventory provider directly
Content   →  Platform, Catalog, Pricing
Ops       →  every Public surface             ← listens everywhere, depended on by nothing
```

Two modules are allowed a wide fan-in and for opposite reasons. **Sales** is the
transaction: it legitimately needs price, stock, discount, points, shipping and payment
in one flow. **Ops** notifies and reports on everything, so it subscribes to every
module's events — and nothing is ever allowed to depend on Ops in return.

---

## Communication — three channels

**A · Synchronous public contracts.** For anything needing an answer now.

```php
interface PricingApi
{
    public function quoteCart(QuoteRequest $request): PriceQuote;
    public function unitPrice(VariantId $v, StoreId $s, Audience $a, Quantity $q): ?Money;
}
```

Bound interface → implementation in each module's ServiceProvider. This is the
anti-corruption layer; it also makes future extraction cheap.

**B · Asynchronous integration events.** Carry IDs, not payloads — fat events go stale
and couple schemas.

```php
final readonly class OrderPlaced
{
    public function __construct(
        public string $eventId,        // UUID — the consumer's idempotency key
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public CarbonImmutable $occurredAt,
    ) {}
}
```

Three non-negotiables: **publish after commit** (`'after_commit' => true`);
**idempotent consumers** (a `processed_events(event_id, listener)` table with a unique
constraint); **transactional outbox for the ~8 critical events only**, not all of them.

**C · Shared kernel.** Tiny and stable. See above.

---

## Architecture tests

`tests/Architecture/` — Pest architecture tests that fail the build:

| Test | Catches | Status |
|---|---|---|
| `Domain/` imports nothing from `Illuminate\*` and calls no Laravel helper function (`app()`, `now()`, `config()`…) | Framework leaking into the domain | In place |
| No `Public/` class references Eloquent | Models crossing boundaries | In place |
| `Domain/Repository` contains interfaces only | Persistence leaking into the domain | In place |
| No `Domain/` or `Application/` file contains a country or currency literal | Hardcoded store assumptions | In place |
| Every class in `Domain/Exception` extends `DomainError` | A module inventing its own error shape | In place |
| `Domain/` and `Application/` never import HTTP classes | Business code deciding HTTP statuses | In place |
| Reading across stores (`acrossStores()`, removing global scopes) only in `Application/Query` and Ops | Accidental cross-store reads | In place |
| Every store-scoped Eloquent model declares the store global scope | A forgotten `where store_id` | With the first store-scoped model |
| Every command handler asserts a permission (comments ignored) | An unprotected use case | In place |
| No storefront endpoint exceeds N queries | The N+1 that made the old system take 5 seconds | Started: a warm request resolves the store from the cache table alone (2 tiny reads); a general per-endpoint budget comes with Catalog |
| Every money column is `bigint` | A `DECIMAL` sneaking in | With the first money column |
| Enums are stored as strings, never integers | Unreadable rows at 2am | In place for the `platform` schema (`PlatformSchemaTest`); each new module schema adds the same check |

The query-count test is the single highest-value guard in the list. The whole project
exists because the current system takes five seconds; that test is what stops it
happening again.

---

## Conventions

| | |
|---|---|
| Primary keys | ULID. `bigint` for high-volume ledgers (`stock_movements`, `point_entries`). |
| Public identifiers | Separate human-facing codes — `TW-10428`. Never expose the ULID as an identifier people read or type; file paths such as media object keys may contain it. |
| Timestamps | `timestamptz`, UTC in the database, converted at the presentation edge using the store's timezone. |
| Soft deletes | Only where genuinely needed. **Never** on ledgers or orders. |
| Transactions | One aggregate per transaction. `DB::transaction()` in the command handler, **never** in a repository. |
| Errors | Module-owned hierarchy under one base class. Single RFC 7807-style envelope at the HTTP edge. |
| Enums | PHP 8 backed enums, stored as **strings**. |
| Naming | Tables plural snake_case under the module schema. Commands imperative (`PublishProduct`). Events past tense (`ProductPublished`). |
| Config | Fully env-driven. **Zero hardcoded store, currency or country values in `Domain/` or `Application/`.** Compliance test: grepping for `SA`, `saudi`, `SAR`, `EGP` in those directories returns nothing. |
| Tests | Pest. Pricing and packaging engines at near-100% unit coverage. Integration tests per module contract. E2E on checkout and payment only. **A feature is not finished until its tests are written.** |

---

## Build order

```
STAGE 1   Platform      stores, currencies, tax, settings, media, audit
STAGE 2   Access        identity, auth, verification, RBAC, staff, addresses, 2FA
STAGE 2b  Frontend      Inertia + React + shadcn with SSR; auth pages, admin sign-in,
          foundation    Platform's admin screens
STAGE 3   B2B           company lifecycle
──────────── everything above depends on nothing external ────────────
STAGE 4   Catalog       BLOCKED on the external provider schema
STAGE 5   Pricing · Inventory · Sync
STAGE 6   Sales · Promotions · Loyalty · Feedback
STAGE 7   Payments · Shipping        (blocked on vendor data)
STAGE 8   Content · Ops
STAGE 9   Migration, hardening, launch
```

Platform comes before Access because store context is a parameter of nearly everything
in Access — staff store scoping, per-store settings, per-store verification config.
Feedback is built with Sales, because reviews need a verified purchase (owner, 2026-09-18).

---

## Per-module deliverable

Every module specification, without exception, contains:

1. Aggregates and their invariants
2. The public contract interface
3. Every use case with its permission string
4. Complete state machines
5. Tables with columns and indexes
6. Published and consumed events
7. The error type hierarchy
8. The test scenario list
9. A register of open questions that module raised

`docs/modules/{name}.md`. Platform was written first (`docs/modules/platform.md`) and every
later module follows its shape.
