# Platform — Module Specification

**Status:** DRAFT — waiting for owner review. No code is written until this is approved.
**Tier:** 3 (foundation). **Depends on:** nothing. **Build stage:** 1.
**Source:** `docs/HANDOFF.md` §1, §4, §5, §7.5, §10.3, §14, §17–§20.

Platform owns the things every other module needs before it can do anything: which stores
exist, what currency they use, their tax rate, configurable settings, uploaded media, and the
audit log. It also owns **store context** — how a request or a job knows which store it is in.

Items marked **[PROPOSED]** are my design choices where the handoff is silent. Items marked
**[QUESTION n]** need your answer and are listed in §9.

---

## What this module does not own

| Concern | Owner |
|---|---|
| Staff users, roles, who may do what | Access |
| Per-store address formats | Access |
| Payment gateway configuration | Payments |
| Carriers and rate tables | Shipping |
| Loyalty configuration, discount ceiling, bank-transfer settings | Their modules, stored through Platform settings (§1.3) or their own tables |
| Invoices | The external accounting system (§12.6) |

---

## 1 · Aggregates and invariants

### 1.1 Store

A country storefront: `sa`, `eg`, `ae`, and any added later.

| Attribute | Invariant |
|---|---|
| `code` | 2–8 lowercase letters. Unique. **Immutable** — it is the URL segment (`brand.com/sa`) and part of every slug history. |
| `name` | Arabic and English both required and non-empty. |
| `country_code` | ISO 3166-1 alpha-2. **Immutable.** |
| `currency_code` | Must reference an existing currency. **Immutable** — every price, order and point balance in the store is denominated in it. |
| `tax_rate_basis_points` | 0–10000 (15% = `1500`). **[PROPOSED]** stored as basis points, an integer, so no DECIMAL and no float ever holds it. Changing it affects only quotes computed afterwards; orders keep their own snapshot (Sales). |
| `timezone` | A valid IANA identifier (`Asia/Riyadh`). Used at the presentation edge to show local times. |
| `position` | Display order in store switchers. |

- **No status column and no lifecycle** (handoff §1, §16). Because of that, a store is created
  **complete, in one command** — every required attribute at once — so a store can never exist
  half-configured. The moment the row exists, the store is live.
- Stores are never deleted.

### 1.2 Currency

Global, not store-scoped.

| Attribute | Invariant |
|---|---|
| `code` | ISO 4217, three uppercase letters. Primary key. Immutable. |
| `exponent` | 0–6. **Immutable once any store uses the currency** — changing it would silently reinterpret every stored minor-unit amount. |
| `name`, `symbol` | Arabic and English both required. |

`Money` never assumes an exponent (handoff §5.1); it is always read from this row.

### 1.3 Setting

A configurable value, either global or for one store: OTP resend limits, points expiry days,
hours to verify a bank transfer, and so on.

- **Every key is declared in code by exactly one module** through a `SettingDefinition`:
  key, scope (`GLOBAL` or `STORE`), value type and validation rules, default value, and the
  permission required to change it. An undeclared key cannot be written.
- Keys are named `{module}.{area}.{name}` — for example `loyalty.points.expiry_days`. The prefix
  must be the declaring module.
- Values are validated against the definition on every write.
- Reading a declared key that has no stored row returns the definition's default. Reading a
  declared key never fails.
- A `STORE` key is never stored without a store, and a `GLOBAL` key never with one.
- **[PROPOSED] Rule of thumb for what is a setting:** a single tunable value is a setting.
  Anything with rows, relationships or its own lifecycle (carriers, gateways, address formats)
  is a table in its owning module.

### 1.4 Media

An uploaded file in object storage (handoff §5.5). Global, not store-scoped.

- The original file is immutable. Replacing an image means uploading a new Media.
- `checksum` is the SHA-256 of the original bytes. Uploading identical bytes returns the
  existing Media instead of creating a duplicate.
- Images get width and height recorded at upload.
- Image variants — `thumb`, `card`, `detail`, `zoom`, each as AVIF, WebP and JPEG — are generated
  **once, after upload, by a queued job**. Never at request time.
- A Media row referenced by another module cannot be deleted. The referencing tables hold a
  foreign key with `ON DELETE RESTRICT` (cross-schema foreign keys are allowed, handoff §4.3),
  so the database refuses and Platform reports `MediaInUse`.
- Deleting removes the original and all variants from object storage **after** the database
  commit.
- **[QUESTION 2, 3]** Visibility: `PUBLIC` (catalog images served from the CDN) or `PRIVATE`
  (company registration documents, bank-transfer receipts — served only through short-lived
  signed URLs, never the CDN).

### 1.5 Audit entry

A permanent record of who changed what.

- **Append-only.** A database trigger rejects every `UPDATE` and `DELETE`.
- Written **in the same transaction** as the change it records. If the change rolls back, so
  does the entry. There is no audited change without its entry.
- Every entry has: when, action, actor (staff, customer or system), subject, and the store when
  the change is store-scoped.
- **[PROPOSED]** `changes` never stores the *values* of personal fields (name, email, phone,
  address) — only that the field changed. Account anonymization (handoff §7.9) then never has
  to rewrite audit history.

### 1.6 Store context

Not an aggregate — a rule that holds for every request, job and command.

- A storefront request resolves exactly one store from the first path segment:
  `brand.com/sa/...` → the `sa` store. An unknown code returns 404.
- **[QUESTION 4]** What `brand.com/` with no store segment does.
- A queued job runs in the store it was dispatched from. The store travels with the job
  automatically; the job does not have to pass it by hand.
- A console command that touches store-scoped data takes an explicit `--store=` option or
  loops over all stores.
- **Querying a store-scoped model with no store context throws.** It never silently returns
  every store's rows. This is the guard against the "forgotten `where store_id`" bug from
  handoff §4.1.
- Reading across stores is an explicit opt-out, allowed only in read models (`Application/Query`)
  and Ops reports. An architecture test enforces where the opt-out may appear.

---

## 2 · Public contract

### 2.1 `Modules\Platform\Public\Contracts\PlatformApi`

```php
interface PlatformApi
{
    // Stores and currencies — served from cache; zero queries once warm.
    public function store(StoreId $id): ?StoreDto;
    public function storeByCode(string $code): ?StoreDto;

    /** @return list<StoreDto> ordered by position */
    public function stores(): array;

    public function currency(string $code): ?CurrencyDto;

    // Settings — the definition's default when nothing is stored.
    public function setting(string $key, ?StoreId $store = null): SettingValueDto;

    // Media
    public function media(string $mediaId): ?MediaDto;

    /** Signed, expiring URLs for PRIVATE media; CDN URLs for PUBLIC media. */
    public function mediaUrls(string $mediaId): ?MediaUrlsDto;

    // Audit — called by other modules inside their own command-handler transaction.
    public function recordAudit(AuditEntryDto $entry): void;
}
```

### 2.2 `Modules\Platform\Public\Contracts\SettingsRegistry`

Used once, at boot, by each module's service provider.

```php
interface SettingsRegistry
{
    public function define(SettingDefinitionDto ...$definitions): void;
}
```

### 2.3 DTOs (`Public/Dto`, spatie/laravel-data)

| DTO | Fields |
|---|---|
| `StoreDto` | `id`, `code`, `name` (ar, en), `countryCode`, `currencyCode`, `currencyExponent`, `currencySymbol` (ar, en), `taxRateBasisPoints`, `timezone`, `position` |
| `CurrencyDto` | `code`, `exponent`, `name` (ar, en), `symbol` (ar, en) |
| `SettingDefinitionDto` | `key`, `scope`, `rules` (Laravel validation rules for the value), `default`, `permission` |
| `SettingValueDto` | `key`, `storeId`, `isDefault`; typed readers `int()`, `bool()`, `string()`, `list()` that throw if the stored type does not match |
| `MediaDto` | `id`, `visibility`, `mime`, `bytes`, `width`, `height`, `alt` (ar, en), `variantsStatus` |
| `MediaUrlsDto` | `original`, `variants` (size → format → URL), `expiresAt` (PRIVATE only) |
| `AuditEntryDto` | `action`, `subjectType`, `subjectId`, `storeId`, `changes`. The actor and correlation id are filled in by Platform from the current context. |

`StoreDto` carries the currency exponent and symbol so a price can be formatted with one call.

### 2.4 Enums (`Public/Enums`, stored as strings)

`SettingScope` (`GLOBAL`, `STORE`) · `MediaVisibility` (`PUBLIC`, `PRIVATE`) ·
`MediaVariantsStatus` (`PENDING`, `READY`, `FAILED`) · `MediaSize` (`THUMB`, `CARD`, `DETAIL`, `ZOOM`) ·
`ImageFormat` (`AVIF`, `WEBP`, `JPEG`)

### 2.5 Additions to the Shared kernel

Every module needs these, and Platform cannot own them because modules below it in the graph
cannot import Platform's interior. They count toward the ~20-class ceiling.

| Type | Where | Purpose |
|---|---|---|
| `StoreId` | `Shared/Domain/ValueObject` | Already listed in handoff §4.5. |
| `StoreContext` (interface) | `Shared/Application` | `current(): StoreId` (throws `MissingStoreContext`), `has()`, `runIn(StoreId, callable)`. Implemented by Platform. |
| `Authorizer` (interface) | `Shared/Application` | `authorize(string $permission, ?StoreId $store): void`, throws `Unauthorized`. Implemented by Access. |
| `ActorContext` (interface) + `Actor` | `Shared/Application` | Who is acting: staff, customer or system, and their id. Implemented by Access. |
| `BelongsToStore` trait + `StoreScope` | `Shared/Infrastructure/Persistence` | The Eloquent global scope every store-scoped model uses. |

Until Access exists, Platform's own code runs with a system actor from console commands, and
tests bind fakes for `Authorizer` and `ActorContext`.

---

## 3 · Use cases

Permissions follow `{module}.{resource}.{action}`. **Reserved** means Super Admin only: the
permission exists so every handler asserts one, but it is never offered in the role editor.

| Use case | Who | Permission | Store-checked |
|---|---|---|---|
| `CreateStore` — all attributes at once | Super Admin | `platform.store.create` (reserved) | — |
| `UpdateStore` — name, tax rate, timezone, position | Staff | `platform.store.update` | That store |
| `ListStores` / `ViewStore` (admin) | Staff | `platform.store.view` | Only stores in the actor's scope |
| `CreateCurrency` | Super Admin | `platform.currency.create` (reserved) | — |
| `UpdateCurrency` — name, symbol; exponent only while no store uses it | Super Admin | `platform.currency.update` (reserved) | — |
| `ViewSettings` | Staff | `platform.settings.view` | That store; `GLOBAL` keys need all-stores access |
| `UpdateSetting` | Staff | **The permission in the setting's definition**, e.g. `loyalty.settings.update` | That store; `GLOBAL` keys need all-stores access |
| `UploadMedia` | Staff | `platform.media.upload` | — (media is global) |
| `UpdateMediaAltText` | Staff | `platform.media.update` **[PROPOSED]** — handoff lists only upload and delete | — |
| `DeleteMedia` | Staff | `platform.media.delete` | — |
| `RetryMediaVariants` | Staff | `platform.media.upload` | — |
| `GenerateMediaVariants` | Queued job | System | — |
| `ViewAuditLog` | Staff | `platform.audit.view` | Entries for stores in scope; entries with no store need all-stores access |
| `RecordAuditEntry` | Other modules | System — called inside an already-authorized handler | — |
| `ResolveStoreContext` | Every storefront request | None | — |

**Delivery [PROPOSED].** Stage 1 builds the domain, persistence, store resolution, the public
contract, and console commands to create stores and currencies (seeding `sa`, `eg`, `ae`).
Admin screens for these use cases arrive once Access (login, permissions) and the frontend
exist, because a screen with no login cannot be protected.

---

## 4 · State machines

### 4.1 Media variants

```
            upload (image)
                 │
                 ▼
            ┌─────────┐   job succeeds    ┌───────┐
            │ PENDING │ ────────────────▶ │ READY │   (final — originals never change)
            └─────────┘                   └───────┘
              │     ▲
 3 attempts   │     │ RetryMediaVariants
 fail         ▼     │
            ┌────────┐
            │ FAILED │
            └────────┘
```

Non-image files (PDF) have no variants; their status is empty.

### 4.2 No other state machines

- **Store:** none, by design (handoff §16 rejects a per-store lifecycle).
- **Currency:** the exponent lock is derived from "is any store using it", not a stored state.
- **Setting, audit entry:** no states.

---

## 5 · Tables

All in the `platform` PostgreSQL schema. Timestamps are `timestamptz` in UTC. Enum columns are
strings. IDs are ULIDs (`char(26)`) except where noted.

### 5.1 `platform.currencies`

| Column | Type | Rules |
|---|---|---|
| `code` | `char(3)` PK | `CHECK (code ~ '^[A-Z]{3}$')` — **[PROPOSED]** the natural ISO code is the key rather than a ULID, because `Money` carries the code and it never changes |
| `exponent` | `smallint` NOT NULL | `CHECK (exponent BETWEEN 0 AND 6)` |
| `name` | `jsonb` NOT NULL | `{"ar": "...", "en": "..."}` |
| `symbol` | `jsonb` NOT NULL | `{"ar": "...", "en": "..."}` |
| `created_at`, `updated_at` | `timestamptz` | |

### 5.2 `platform.stores`

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` PK | ULID |
| `code` | `varchar(8)` NOT NULL | UNIQUE; `CHECK (code ~ '^[a-z]{2,8}$')` |
| `name` | `jsonb` NOT NULL | ar and en |
| `country_code` | `char(2)` NOT NULL | `CHECK (country_code ~ '^[A-Z]{2}$')` |
| `currency_code` | `char(3)` NOT NULL | FK → `platform.currencies(code)` ON DELETE RESTRICT |
| `tax_rate_basis_points` | `integer` NOT NULL | `CHECK (tax_rate_basis_points BETWEEN 0 AND 10000)` |
| `timezone` | `varchar(64)` NOT NULL | |
| `position` | `smallint` NOT NULL DEFAULT 0 | |
| `created_at`, `updated_at` | `timestamptz` | |

Indexes: unique `(code)`.

### 5.3 `platform.settings`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint` identity PK | |
| `store_id` | `char(26)` NULL | FK → `platform.stores(id)`; NULL means a global setting |
| `key` | `varchar(150)` NOT NULL | |
| `value` | `jsonb` NOT NULL | |
| `updated_by` | `char(26)` NULL | Staff user id. No foreign key: Access comes later in the build order |
| `updated_at` | `timestamptz` NOT NULL | |

Indexes: `UNIQUE NULLS NOT DISTINCT (store_id, key)` — one row per key per store, and one global row per key.

### 5.4 `platform.media`

Columns from handoff §5.5, plus the ones marked **[PROPOSED]**.

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` PK | ULID |
| `visibility` | `varchar(16)` NOT NULL | `PUBLIC` or `PRIVATE` **[QUESTION 3]** |
| `disk` | `varchar(32)` NOT NULL | |
| `object_key` | `varchar(255)` NOT NULL | |
| `original_filename` | `varchar(255)` NOT NULL | **[PROPOSED]** download name for private documents |
| `mime` | `varchar(100)` NOT NULL | |
| `bytes` | `bigint` NOT NULL | |
| `width`, `height` | `integer` NULL | Required for images, NULL for PDF |
| `checksum` | `char(64)` NOT NULL | SHA-256, hex |
| `alt_ar`, `alt_en` | `varchar(255)` NULL | |
| `variants_status` | `varchar(16)` NULL | `PENDING`, `READY`, `FAILED`; NULL for non-images **[PROPOSED]** |
| `variants_generated_at` | `timestamptz` NULL | **[PROPOSED]** |
| `uploaded_by` | `char(26)` NULL | Staff user id |
| `created_at`, `updated_at` | `timestamptz` | |

Indexes:
- `UNIQUE (disk, object_key)`
- `UNIQUE (visibility, checksum)` — the dedupe rule
- `(created_at DESC, id DESC)` — keyset pagination for the media library screen
- `(variants_status) WHERE variants_status <> 'READY'` — finds stuck or failed generation

Variant object keys are derived, not stored: `{object_key without extension}/{size}.{format}`.

### 5.5 `platform.audit_entries`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint` identity PK | High-volume, append-only — `bigint` like the other ledgers (handoff §5.3) |
| `occurred_at` | `timestamptz` NOT NULL | |
| `store_id` | `char(26)` NULL | FK → `platform.stores(id)` |
| `actor_type` | `varchar(16)` NOT NULL | `STAFF`, `CUSTOMER`, `SYSTEM` |
| `actor_id` | `char(26)` NULL | NULL only for `SYSTEM` |
| `action` | `varchar(100)` NOT NULL | e.g. `platform.store.updated`, `b2b.company.approved` |
| `subject_type` | `varchar(100)` NOT NULL | e.g. `platform.store`, `b2b.company` |
| `subject_id` | `varchar(64)` NOT NULL | |
| `changes` | `jsonb` NOT NULL DEFAULT `'{}'` | `{"tax_rate_basis_points": [1500, 1600]}`; personal fields record only `"changed"` |
| `correlation_id` | `varchar(64)` NULL | Links the entry to the request's logs |

Indexes:
- `(subject_type, subject_id, occurred_at DESC)` — history of one thing
- `(store_id, occurred_at DESC)` — the audit screen for a store
- `(actor_type, actor_id, occurred_at DESC)` — everything one person did
- `(action, occurred_at DESC)`

Trigger: `BEFORE UPDATE OR DELETE` raises an exception.

### 5.6 Shared infrastructure tables — **[PROPOSED]** in the `public` schema, not `platform`

Laravel's own tables and the event plumbing every module uses. Listed here because they are
created in Stage 1; they belong to `Shared/Infrastructure`, not to Platform.

| Table | Purpose |
|---|---|
| `processed_events` | `(event_id uuid, listener varchar)` PK, `processed_at` — the idempotent-consumer rule (handoff §4.5) |
| `outbox_messages` | The transactional outbox for the ~8 critical events |
| `failed_jobs`, `job_batches` | Laravel queue bookkeeping (queues, cache and sessions run on Redis) |

### 5.7 Seed data

| Currency | Exponent | Symbol (ar / en) |
|---|---|---|
| SAR | 2 | **[QUESTION 7]** |
| EGP | 2 | ج.م / EGP |
| AED | 2 | د.إ / AED |

| Store | Country | Currency | Tax | Timezone | Position |
|---|---|---|---|---|---|
| `sa` | SA | SAR | 1500 (15%) | Asia/Riyadh | 1 |
| `eg` | EG | EGP | 1400 (14%) | Africa/Cairo | 2 |
| `ae` | AE | AED | 500 (5%) | Asia/Dubai | 3 |

These values live only in the seeder, never in `Domain/` or `Application/` (handoff §2.2).

---

## 6 · Events

### 6.1 Published — integration events (`Public/Events`)

IDs only, dispatched after commit, consumers idempotent on `eventId`. None of these are among
the critical outbox events.

| Event | Fields | Expected consumers |
|---|---|---|
| `StoreCreated` | `eventId`, `storeId`, `occurredAt` | Ops |
| `StoreUpdated` | `eventId`, `storeId`, `changed` (attribute names), `occurredAt` | Pricing (tax rate), Content (cached homepage), Ops |
| `CurrencyUpdated` | `eventId`, `currencyCode`, `occurredAt` | Content, Ops |
| `SettingChanged` | `eventId`, `key`, `storeId`, `occurredAt` | Whichever module owns the key |
| `MediaVariantsReady` | `eventId`, `mediaId`, `occurredAt` | Catalog (refresh image URLs in `product_search`), Content |
| `MediaDeleted` | `eventId`, `mediaId`, `occurredAt` | Catalog, Content |

### 6.2 Consumed

None. Platform depends on nothing.

### 6.3 Notifications

None. Failed variant generation shows on the media library screen instead.

---

## 7 · Error hierarchy

```
PlatformException                         (abstract, module base)
├── StoreNotFound                         404
├── StoreCodeTaken                        409
├── StoreAttributeImmutable               422   code, country or currency
├── InvalidTaxRate                        422
├── InvalidTimezone                       422
├── CurrencyNotFound                      404
├── CurrencyExponentLocked                409
├── UnknownSetting                        422
├── InvalidSettingValue                   422
├── SettingScopeMismatch                  422
├── MediaNotFound                         404
├── UnsupportedMediaType                  415
├── MediaTooLarge                         413
└── MediaInUse                            409

Shared (not Platform-owned, used everywhere)
├── MissingStoreContext                   500   a programming error, never user-facing text
├── Unauthorized                          403
└── MoneyException                        422   already implemented
```

**HTTP envelope [PROPOSED]** — Platform is the first module, so it fixes the RFC 7807 shape every
module uses:

```json
{
  "type": "platform.store_code_taken",
  "title": "رمز المتجر مستخدم",
  "status": 409,
  "detail": "Another store already uses the code \"sa\".",
  "correlation_id": "01J…"
}
```

`title` is translated into the signed-in person's **stored** locale (handoff §5.2); for a guest,
the locale of the page they are on. `type` is a stable machine key, one per exception class. **[QUESTION 1]** whether this exception style is
what you meant by the "error/result pattern".

---

## 8 · Test scenarios

### Unit (no framework, no database)

- Store: rejects bad `code` format, missing Arabic or English name, tax rate outside 0–10000,
  invalid timezone; refuses to change `code`, `country_code` or `currency_code`.
- Currency: rejects bad code and exponent; refuses exponent change when in use; allows it when not.
- SettingDefinition: rejects a key whose prefix is not the declaring module; rejects duplicate keys.
- Setting write: rejects undeclared key, wrong scope, value failing the definition's rules.
- Setting read: returns the default when nothing is stored; typed reader throws on type mismatch.
- Media variants: `PENDING → READY`, `PENDING → FAILED`, `FAILED → PENDING`; `READY` cannot change.
- Audit changes: personal fields are recorded as `"changed"`, never their values.

### Integration (PostgreSQL `touchwood_test`)

- Migrations create the `platform` schema, every table, check constraint and index above.
- Audit trigger rejects `UPDATE` and `DELETE` on `audit_entries`.
- Settings uniqueness holds for both store rows and global (NULL store) rows.
- A currency used by a store cannot be deleted.
- Media referenced through a `RESTRICT` foreign key cannot be deleted → `MediaInUse`.
- `StoreScope`: throws with no store context; filters to the current store with one.
- Store context travels into a queued job dispatched inside it.
- `PlatformApi` returns DTOs, never Eloquent models.
- Store and currency caches are invalidated on update.
- An audit entry recorded inside a transaction that rolls back does not exist afterwards.

### Feature (HTTP and console)

- `/sa/...` resolves the KSA store; `/xx/...` returns 404; `/` behaves as decided in Question 4.
- **Resolving the store costs zero database queries with a warm cache** (the query-count guard).
- `platform:store:create` refuses an incomplete store and creates a complete one with its audit entry.
- Seeding creates `sa`, `eg`, `ae` with the values in §5.7.
- Upload: identical bytes return the existing media; unsupported type → 415; oversize → 413.
- Variants job writes all 12 variant objects to fake storage and publishes `MediaVariantsReady`.
- A private media URL is signed and stops working after it expires.
- Error responses match the RFC 7807 shape in both Arabic and English.
- Admin-permission scenarios (403 for a staff member scoped to `sa` editing `ae`) are written in
  Stage 2, when Access provides real roles.

### Architecture

- Every Platform command handler asserts a permission (the first real use of this §19 rule).
- The store-scope opt-out appears only in `Application/Query` and Ops.
- Platform enum columns are stored as strings.

---

## 9 · Open questions

1. **Errors.** In the chat you mentioned an "error/result pattern". The handoff (§5.3) specifies
   exceptions: each module has its own exception classes, and one error format at the HTTP edge,
   shown in §7 above. Is that what you meant? My recommendation is to keep exceptions: PHP has
   no built-in Result type, and Laravel turns exceptions into error responses in one central place.
2. **Media library package.** The handoff lists `spatie/laravel-medialibrary` (§3) but also
   defines its own `media` table (§5.5). They don't fit together: the Spatie package links
   every file to another module's database model, which breaks the "no cross-module model
   relationships" rule (§4.3). **I recommend our own `media` table as specified above**, with an
   image library (Intervention Image) only for resizing. Do you agree to drop the Spatie media package?
3. **Private files.** Company registration documents and bank-transfer receipts must not be
   publicly reachable. Should they live in the same `media` table with `PRIVATE` visibility and
   expiring signed links (my recommendation), or should B2B and Payments store them separately?
4. **The root URL.** What should `brand.com/` (no `/sa`, `/eg`, `/ae`) do? Options:
   (a) a simple page to pick a country; (b) remember the last store in a cookie, otherwise show
   the picker; (c) detect the country from the visitor's IP. I recommend (b) — no third-party
   IP lookup to maintain.
5. **Upload limits.** Maximum file size and accepted types. I propose images up to 10 MB
   (JPEG, PNG, WebP) and private documents up to 10 MB (PDF, JPEG, PNG). Change these if needed.
6. **Audit log retention.** Keep audit entries forever (my assumption, matching orders), or delete
   them after a period? Also: should an entry store the staff member's IP address? I left it out
   because IP addresses are personal data under PDPL, and the correlation id already links the
   entry to the request logs.
7. **Riyal symbol.** Your storefront mockups use `﷼`. Saudi Arabia introduced a new official
   Riyal symbol in 2025; it now has its own Unicode character, but many fonts don't include it
   yet. Which should we show — `﷼`, the new symbol, or the letters `ر.س`? Whichever you choose
   is just data on the currency row, so it can change later without code changes.
