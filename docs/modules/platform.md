# Platform — Module Specification

**Status:** **APPROVED** by the owner, 2026-09-16. Changes from here on are amendments and need
the owner's agreement.
**Tier:** 3 (foundation). **Depends on:** nothing. **Needs from shared plumbing:** nothing (it
publishes events but consumes none, so the §5.6 event tables are not needed yet). **Build stage:** 1.
**Source:** `docs/HANDOFF.md` §1, §4, §5, §7.5, §10.3, §14, §17–§20.

Platform owns the things every other module needs before it can do anything: which stores
exist, what currency they use, their tax rate, configurable settings, uploaded media, and the
audit log. It also owns **store context** — how a request or a job knows which store it is in.

Items marked **[PROPOSED]** are design choices where the handoff is silent. Items marked
**[DECIDED]** are the owner's answers to this spec's questions; §9 records them. Items marked
**[QUESTION n]** still need an answer.

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
| `code` | 2–8 lowercase letters, never a reserved top-level path (`up`, `admin`, `api`, `build`, `storage` — such a store could never be reached). Unique. **Immutable** — it is the URL segment (`brand.com/sa`) and part of every slug history. |
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
| `name` | Arabic and English both required. |
| `abbreviation` | Arabic and English both required — the letters, e.g. `ر.س` / `SAR`. Always available. |
| `sign` | Optional — the official currency sign as one Unicode character, e.g. `U+20C1`. |

`Money` never assumes an exponent (handoff §5.1); it is always read from this row.

**[DECIDED] How a price shows its currency:** the `sign` when the currency has one; otherwise
the `abbreviation` in the page's language. If a sign cannot be displayed — no Unicode character
yet, or the site's font has no glyph for it — the `sign` is cleared on the currency row and every
price falls back to the letters. That is a data change, never a code change or a deploy.

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
- `checksum` is the SHA-256 of the original bytes. **[DECIDED 2026-09-16]** Uploading an
  identical **public** image returns the existing Media instead of creating a duplicate.
  **Private files are never deduplicated:** two companies uploading the same receipt get two
  separate Media, so neither ever sees the other's file name or shares its document.
- **[DECIDED 2026-09-16] Every original is kept private**, public images included: a phone photo
  carries metadata such as its GPS location. A public image is shown only through its variants,
  which carry no metadata. Until its variants are ready, it has no URL at all.
- Images get width and height recorded at upload, **as displayed**: a sideways phone photo
  records its upright size.
- Image variants — `thumb`, `card`, `detail`, `zoom`, each as AVIF, WebP and JPEG — are generated
  **once, after upload, by a queued job**. Never at request time.
  - **[DECIDED 2026-09-16]** Each size keeps the whole image and its shape (no cropping) and
    limits only the longest side: thumb 200px, card 600px, detail 1200px, zoom 2400px. A smaller
    original is never enlarged. Photos are turned upright and their metadata is stripped.
  - **[PROPOSED — added in implementation]** Only **public** images get variants. Private files
    (documents, receipts), including private JPEG and PNG, are served as uploaded: variants exist
    for CDN display, and private files never go through the CDN.
  - The job tries three times; then the image is `FAILED` and staff can retry it.
  - **[DECIDED 2026-09-16] A lost job is recovered.** If the job never runs — the queue was down
    at upload, or a worker died — the image would stay `PENDING` forever. So an image still
    `PENDING` **15 minutes** after it was queued is queued again, both by a scheduled sweep every
    10 minutes and by staff pressing Retry. Queuing twice is harmless: a finished job does nothing.
- **[PROPOSED — added in implementation]** An image over 12,000px on a side or 50 million pixels
  is refused as too large, whatever its file size — a small file can decode into a bitmap that
  exhausts memory. An animated WebP is refused: it cannot be resized.
- **[PROPOSED — added in implementation]** Only the base name of the uploaded file is kept (no
  directories, no control or invisible formatting characters such as a right-to-left override),
  at most 255 characters. On S3-compatible storage a private file downloads under this name.
- A Media row referenced by another module cannot be deleted. The referencing tables hold a
  foreign key with `ON DELETE RESTRICT` (cross-schema foreign keys are allowed, handoff §4.3),
  so the database refuses and Platform reports `MediaInUse`.
- Deleting removes the original and all variants from object storage **after** the database
  commit.
- **[DECIDED]** Every file lives in this one table, with a visibility:
  - `PUBLIC` — catalog and content images. Their variants are served from the CDN.
  - `PRIVATE` — company registration documents and bank-transfer receipts. Stored on a private
    disk and served **only** through short-lived signed URLs, never the CDN.
    **[DECIDED 2026-09-16]** A link works for **30 minutes**. Platform does not know who may see
    a private file: the module that owns it (B2B, Payments) checks its viewer before asking for
    the link.
- **[DECIDED]** Upload limits, checked before anything is stored:

  | Visibility | Accepted types | Maximum size |
  |---|---|---|
  | `PUBLIC` | JPEG, PNG, WebP | 10 MB |
  | `PRIVATE` | PDF, JPEG, PNG | 10 MB |

  The type is detected from the file's contents, not its name or the browser's claim. The size
  limits are global settings (`platform.media.max_public_bytes`, `platform.media.max_private_bytes`)
  so they can change without a deploy; the accepted types are fixed in code for safety.
  **[PROPOSED — added in implementation]** Each limit can be set between 1 byte and 100 MB, and
  changing one needs `platform.settings.update`.
- **[DECIDED]** No third-party media package: `spatie/laravel-medialibrary` links each file to
  another module's database model, which the boundary rule forbids (handoff §4.3). Resizing uses
  Intervention Image.

### 1.5 Audit entry

A permanent record of who changed what.

- **Append-only.** Database triggers reject every `UPDATE`, `DELETE` and `TRUNCATE`.
- Recording an entry outside a transaction throws: the change and its entry must commit together.
- Written **in the same transaction** as the change it records. If the change rolls back, so
  does the entry. There is no audited change without its entry.
- Every entry has: when, action, actor (staff, customer, guest, integration or system), subject,
  and the store when the change is store-scoped.
- **[DECIDED 2026-09-18]** A queued job acts as the **system**; when a person's or an integration's
  action queued it, the entry also records that **requester**. Their permission is checked when they
  start the action. Every actor id is a ULID: a guest's is the token their cart carries, an
  integration's is its settings record.
- **[PROPOSED]** `changes` never stores the *values* of personal fields (name, email, phone,
  address) — only that the field changed. Account anonymization (handoff §7.9) then never has
  to rewrite audit history.
- **[DECIDED]** Entries are **kept forever**. Nothing deletes or archives them.
- **[DECIDED 2026-09-18] Every entry has a source**, worked out by Platform and never passed in:
  `WEB` (a browser or admin request), `INTEGRATION` (a request made by an integration, e.g. a
  payment webhook), `CONSOLE` (an artisan command), `JOB` (a queued job or scheduled task) or
  `IMPORT` (history from the old system).
- **[DECIDED 2026-09-18] Nothing can be back-dated.** `occurred_at` and `recorded_at` both come from
  PostgreSQL's clock and must be equal, except for an import: only `recordImportedAudit` may give a
  past date (with the original actor), only the system may call it, and `recorded_at` still shows
  when the row was really written.
- **[DECIDED]** When the actor is a staff member, the entry records their **IP address**.
  Customer and system entries never record an IP.

### 1.6 Store context

Not an aggregate — a rule that holds for every request, job and command.

- A storefront request resolves exactly one store from the first path segment:
  `brand.com/sa/...` → the `sa` store. An unknown code returns 404.
- Visiting a store remembers it in a cookie.
- **[DECIDED]** `brand.com/` with no store segment: if the cookie names a store that still
  exists, redirect there; otherwise show a page to choose a country. No IP-based detection.
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
    // Stores and currencies — served from the cache; a warm request reads only the cache table.
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

    // Data migration only: old history with its real date and actor, marked IMPORT. System only.
    public function recordImportedAudit(AuditEntryDto $entry, Actor $actor, DateTimeImmutable $occurredAt): void;
}
```

### 2.2 `Modules\Platform\Public\Contracts\SettingsRegistry`

Used once, at boot, by each module's service provider.

```php
interface SettingsRegistry
{
    public function define(string $module, SettingDefinitionDto ...$definitions): void;
}
```

`$module` was added during implementation: without it the registry cannot enforce §1.3's rule
that a key's prefix is the declaring module. A malformed, foreign or duplicate key, or a default
that fails its own rules, throws `InvalidSettingDefinition` (a `LogicException`, not a
`DomainError`) at boot.

### 2.3 DTOs (`Public/Dto`)

DTOs that carry Platform's data out (`StoreDto`, `CurrencyDto`, `TranslatedTextDto`) are
spatie/laravel-data objects. The ones other modules build and pass in (`AuditEntryDto`,
`AuditChanges`, `SettingDefinitionDto`) and `SettingValueDto` are plain readonly classes: they
hold a builder, Laravel validation rules, or a `mixed` value that laravel-data would try to cast.

| DTO | Fields |
|---|---|
| `StoreDto` | `id`, `code`, `name` (ar, en), `countryCode`, `currencyCode`, `currencyExponent`, `currencySign`, `currencyAbbreviation` (ar, en), `taxRateBasisPoints`, `timezone`, `position` |
| `CurrencyDto` | `code`, `exponent`, `name` (ar, en), `abbreviation` (ar, en), `sign`; `displaySymbol(locale)` returns the sign, or the abbreviation when there is no sign |
| `SettingDefinitionDto` | `key`, `scope`, `type` (checked strictly before any rule), `rules` (further Laravel validation rules), `default`, `permission` |
| `SettingValueDto` | `key`, `storeId`, `isDefault`; typed readers `int()`, `bool()`, `string()`, `list()` that throw if the stored type does not match |
| `MediaDto` | `id`, `visibility`, `mime`, `bytes`, `width`, `height`, `originalFilename`¹, `altAr`, `altEn`¹, `variantsStatus` |
| `MediaUrlsDto` | `original` (PRIVATE only: the expiring link; null for a public image, whose original is never served), `variants` (size slug → format extension → CDN URL, empty until ready), `expiresAt` (PRIVATE only) |
| `AuditEntryDto` | `action`, `subjectType`, `subjectId`, `storeId`, `changes`. The actor and correlation id are filled in by Platform from the current context. |

`StoreDto` carries the currency exponent, sign and abbreviation so a price can be formatted with one call.

¹ Changed in implementation. `originalFilename` is the download name of a private document (it is
already a §5.4 column). Alt text is two separate nullable fields instead of a `TranslatedTextDto`,
because a name needs both languages while alt text may be written in one language or none yet.

### 2.4 Enums (`Public/Enums`, stored as strings)

`SettingScope` (`GLOBAL`, `STORE`) · `SettingType` (`INTEGER`, `BOOLEAN`, `TEXT`, `LIST`) · `MediaVisibility` (`PUBLIC`, `PRIVATE`) ·
`MediaVariantsStatus` (`PENDING`, `READY`, `FAILED`) · `MediaSize` (`THUMB`, `CARD`, `DETAIL`, `ZOOM`) ·
`ImageFormat` (`AVIF`, `WEBP`, `JPEG`)

### 2.5 Additions to the Shared kernel

Every module needs these, and Platform cannot own them because modules below it in the graph
cannot import Platform's interior. They count toward the ~20-class ceiling.

| Type | Where | Purpose |
|---|---|---|
| `StoreId` | `Shared/Domain/ValueObject` | Already listed in handoff §4.5. |
| `StoreContext` (interface) | `Shared/Application` | `current(): StoreId` (throws `MissingStoreContext`), `has()`, `runIn(StoreId, callable)`. Implemented by Platform. |
| `Authorizer` (interface) + `PermissionScope` | `Shared/Application` | `authorize(string $permission, PermissionScope $scope): void`, throws `Unauthorized`; `storesWith(string $permission): ?array` returns the stores the actor may use it in (`null` = every store) for admin listings. `PermissionScope` is `global()` (nothing store-related), `store($id)` (one store) or `allStores()` (the change reaches every store). **[DECIDED 2026-09-18]** — the earlier `?StoreId` meant both "no store" and "all stores", which Access could not tell apart. Implemented by Access. |
| `ActorContext` (interface) + `Actor` | `Shared/Application` | Who is acting: staff, customer, guest, integration or system, and their ULID. Inside a queued job the actor is the system with `requestedBy` — whoever queued it (**[DECIDED 2026-09-18]**); Platform wraps every `ActorContext` binding to do this, so it must be registered with `bind()`/`scoped()`. Implemented by Access. |
| `BelongsToStore` trait + `StoreScope` | `Shared/Infrastructure/Persistence` | The Eloquent global scope every store-scoped model uses. It also refuses to create, move, save or delete a row of another store (`CrossStoreWrite`). |
| `DomainError` + `ErrorCategory` | `Shared/Domain` | The base of every expected business error, and the short list of error kinds. See §7. |

Until Access exists, Platform's own code runs with a system actor from console commands, and
tests bind fakes for `Authorizer` and `ActorContext`.

---

## 3 · Use cases

Permissions follow `{module}.{resource}.{action}`. **Reserved** means Super Admin only: the
permission exists so every handler asserts one, but it is never offered in the role editor.

| Use case | Who | Permission | Store-checked |
|---|---|---|---|
| `CreateStore` — all attributes at once | Super Admin | `platform.store.create` (reserved) | Global |
| `UpdateStore` — name, tax rate, timezone, position | Staff | `platform.store.update` | That store |
| `ListStores` / `ViewStore` (admin) | Staff | `platform.store.view` | Only stores in the actor's scope |
| `CreateCurrency` | Super Admin | `platform.currency.create` (reserved) | Global |
| `UpdateCurrency` — name, abbreviation, sign (including clearing it); exponent only while no store uses it | Super Admin | `platform.currency.update` (reserved) | Global |
| `ViewSettings` | Staff | `platform.settings.view` | That store; ``GLOBAL` keys need all-stores access |
| `UpdateSetting` | Staff | **The permission in the setting's definition**, e.g. `loyalty.settings.update`. Platform's own settings (the media upload limits) use `platform.settings.update` **[PROPOSED]** | That store; `GLOBAL` keys need all-stores access |
| `UploadMedia` | Staff | `platform.media.upload` | Global (media belongs to no store) |
| `UpdateMediaAltText` | Staff | `platform.media.update` **[PROPOSED]** — handoff lists only upload and delete | Global |
| `DeleteMedia` | Staff | `platform.media.delete` | Global |
| `RetryMediaVariants` — a `FAILED` image, or one `PENDING` for 15 minutes | Staff | `platform.media.upload` | Global |
| `GenerateMediaVariants` | Queued job | System — `platform.media.variants.generate` (reserved) | Global |
| `RequeueStuckMediaVariants` — every 10 minutes | Scheduler | System — `platform.media.variants.generate` (reserved) | Global |
| `ViewAuditLog` | Staff | `platform.audit.view` | Entries for stores in scope; entries with no store need all-stores access |
| `RecordAuditEntry` | Other modules | System — called inside an already-authorized handler | Global |
| `ResolveStoreContext` | Every storefront request | None | — |
| `ChooseStore` — the country page at `brand.com/`, and the cookie redirect | Any visitor | None | — |

**Delivery [PROPOSED].** Stage 1 builds the domain, persistence, store resolution, the public
contract, and console commands to create stores and currencies (seeding `sa`, `eg`, `ae`).
Admin screens for these use cases arrive once Access (login, permissions) and the frontend
exist, because a screen with no login cannot be protected.

---

## 4 · State machines

### 4.1 Media variants

```
          upload (public image)
                   │
                   ▼
             ┌─────────┐    job succeeds     ┌───────┐
      ┌────▶ │ PENDING │ ──────────────────▶ │ READY │   (final — originals never change)
      │      └─────────┘                     └───────┘
      │        │     ▲
      │        │     │
      │        │     │ RetryMediaVariants (staff)
      │        ▼     │
      │      ┌────────┐
      │      │ FAILED │ ◀── entered when 3 attempts fail
      │      └────────┘
      │
      └── still PENDING 15 minutes after queuing: queued again
          (scheduled sweep every 10 minutes, or staff Retry)
```

A `PENDING` image queued again keeps its status and gets a new queue time
(`variants_queued_at`), so it is not stuck again for another 15 minutes.

Files without variants — PDFs and every private file — have no status (empty).

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
| `abbreviation` | `jsonb` NOT NULL | `{"ar": "...", "en": "..."}` |
| `sign` | `varchar(8)` NULL | One Unicode character; NULL means show the abbreviation |
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
| `visibility` | `varchar(16)` NOT NULL | `PUBLIC` or `PRIVATE` |
| `disk` | `varchar(32)` NOT NULL | The private disk that keeps the original. Variants of public images are on the configured public disk |
| `object_key` | `varchar(255)` NOT NULL | The original's key |
| `original_filename` | `varchar(255)` NOT NULL | **[PROPOSED]** download name for private documents |
| `mime` | `varchar(100)` NOT NULL | |
| `bytes` | `bigint` NOT NULL | |
| `width`, `height` | `integer` NULL | Required for images, NULL for PDF |
| `checksum` | `char(64)` NOT NULL | SHA-256, hex |
| `alt_ar`, `alt_en` | `varchar(255)` NULL | |
| `variants_status` | `varchar(16)` NULL | `PENDING`, `READY`, `FAILED`; NULL for files without variants (PDFs and private files) **[PROPOSED]** |
| `variants_queued_at` | `timestamptz` NULL | When generation was last queued; set exactly when `variants_status` is. Finds images whose job was lost (§4.1) |
| `variants_generated_at` | `timestamptz` NULL | **[PROPOSED]** |
| `uploaded_by` | `char(26)` NULL | Staff user id |
| `created_at`, `updated_at` | `timestamptz` | |

Indexes:
- `UNIQUE (disk, object_key)`
- `UNIQUE (checksum) WHERE visibility = 'PUBLIC'` (`media_public_checksum_unique`) — the dedupe
  rule, for public images only
- `(created_at DESC, id DESC)` — keyset pagination for the media library screen
- `(variants_status, variants_queued_at) WHERE variants_status <> 'READY'` — finds failed
  generation, and stuck `PENDING` images oldest first

Variant object keys are derived, not stored: `{object_key without extension}/{size}.{format}`.

CHECK constraints (added in implementation): `media_visibility`, `media_variants_status`,
`media_variants_queued` (a queue time exactly when there is a status), `media_bytes_positive`,
`media_dimensions` (width and height both set and positive, or both NULL),
`media_checksum_format` (64 lowercase hex characters).

**[DECIDED 2026-09-16]** Object keys contain the media's ULID (`media/01j8z3….jpg`). Handoff
§5.3's "never expose the ULID" is about identifiers people read and type, such as order numbers;
a file path is not one.

### 5.5 `platform.audit_entries`

| Column | Type | Rules |
|---|---|---|
| `id` | `bigint` identity PK | High-volume, append-only — `bigint` like the other ledgers (handoff §5.3) |
| `occurred_at` | `timestamptz` NOT NULL DEFAULT `now()` | When the change happened. Equal to `recorded_at` except for an import |
| `recorded_at` | `timestamptz` NOT NULL DEFAULT `now()` | **[DECIDED 2026-09-18]** When the row was written, always from the database clock |
| `source` | `varchar(16)` NOT NULL | **[DECIDED 2026-09-18]** `WEB`, `INTEGRATION`, `CONSOLE`, `JOB`, `IMPORT`. CHECKs: `audit_entries_source`; `audit_entries_job_acts_as_system` (a `JOB` entry's actor is `SYSTEM`); `audit_entries_backdated_import_only` (`occurred_at = recorded_at`, or an import dated in the past); `requested_by_*` only on `JOB` entries |
| `store_id` | `char(26)` NULL | FK → `platform.stores(id)` |
| `actor_type` | `varchar(16)` NOT NULL | `STAFF`, `CUSTOMER`, `GUEST`, `INTEGRATION`, `SYSTEM` |
| `actor_id` | `char(26)` NULL | A ULID; NULL only for `SYSTEM` |
| `requested_by_type`, `requested_by_id` | `varchar(16)`, `char(26)` NULL | **[DECIDED 2026-09-18]** Only on `SYSTEM` entries written by a queued job: whose action queued it. Both set or both NULL (CHECK `audit_entries_requested_by`); indexed for "everything one person asked for". |
| `action` | `varchar(100)` NOT NULL | e.g. `platform.store.updated`, `b2b.company.approved` |
| `subject_type` | `varchar(100)` NOT NULL | e.g. `platform.store`, `b2b.company` |
| `subject_id` | `varchar(64)` NOT NULL | |
| `changes` | `jsonb` NOT NULL DEFAULT `'{}'` | `{"tax_rate_basis_points": [1500, 1600]}`; personal fields record only `"changed"` |
| `correlation_id` | `varchar(64)` NULL | Links the entry to the request's logs |
| `ip_address` | `inet` NULL | Staff actors only. `CHECK (ip_address IS NULL OR actor_type = 'STAFF')` |

No retention job: entries are kept forever.

Indexes:
- `(subject_type, subject_id, occurred_at DESC)` — history of one thing
- `(store_id, occurred_at DESC)` — the audit screen for a store
- `(actor_type, actor_id, occurred_at DESC)` — everything one person did
- `(action, occurred_at DESC)`

Trigger: `BEFORE UPDATE OR DELETE` raises an exception.

### 5.6 Shared infrastructure tables — **[PROPOSED]** in the `public` schema, not `platform`

Laravel's own tables and the event plumbing every module uses. They belong to
`Shared/Infrastructure`, not to Platform.

**[DECIDED 2026-09-16]** `processed_events` and `outbox_messages` are **not** built in Stage 1.
They are built together with the first module that consumes an event or publishes a critical
one, when the real need shapes them. Each module's spec lists what it needs from other modules
and from this plumbing, so the need is visible before its code starts.

| Table | Purpose |
|---|---|
| `processed_events` | `(event_id uuid, listener varchar)` PK, `processed_at` — the idempotent-consumer rule (handoff §4.5) |
| `outbox_messages` | The transactional outbox for the ~8 critical events |
| `failed_jobs`, `job_batches` | Laravel queue bookkeeping |
| `sessions`, `cache`, `cache_locks`, `jobs` | **[DECIDED 2026-09-18]** Sessions, cache and queues run on PostgreSQL — no Redis until traffic needs it. The cache version is written inside the transaction of each change, so cached data is never stale. `sessions.user_id` is a ULID, like every account id. |

**[DECIDED 2026-09-18]** Laravel's starter `users` and `password_reset_tokens` tables, the
`App\Models\User` model and its factory are removed: Access creates its own customer and staff
tables (handoff §7.1). Laravel merges a default `users` auth provider back into the configuration,
so `config/auth.php` overrides it with no model; until Access exists, nobody can log in.

### 5.7 Seed data

| Currency | Exponent | Sign | Abbreviation (ar / en) |
|---|---|---|---|
| SAR | 2 | **[DECIDED]** Saudi Riyal sign, `U+20C1` (Unicode 17.0) | ر.س / SAR |
| EGP | 2 | none — Egypt has no official sign | ج.م / EGP |
| AED | 2 | **[DECIDED]** UAE Dirham sign, `U+20C3` (Unicode 18.0, September 2026) | د.إ / AED |

**Font check:** both signs are very new — `U+20C3` was published this month — so few fonts
include them yet. In the frontend milestone, each sign is checked on a real price in the chosen
font before that milestone is merged. Any sign the font cannot draw is cleared, and that
currency shows its letters until a font update supports it.

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
| `CurrencyUpdated` | `eventId`, `currencyCode`, `changed` (attribute names), `occurredAt` | Content, Ops |
| `SettingChanged` | `eventId`, `key`, `storeId`, `occurredAt` | Whichever module owns the key |
| `MediaVariantsReady` | `eventId`, `mediaId`, `occurredAt` | Catalog (refresh image URLs in `product_search`), Content |
| `MediaDeleted` | `eventId`, `mediaId`, `occurredAt` | Catalog, Content |

### 6.2 Consumed

None. Platform depends on nothing.

### 6.3 Notifications

None. Failed variant generation shows on the media library screen instead.

---

## 7 · Error hierarchy

**[DECIDED] One global standard; each module names its own errors.** Platform is the first
module, so this section fixes the pattern every later module follows.

### 7.1 The global standard — in `Shared`, written once

- **`DomainError`** — the abstract base of every *expected* business error in the system (a rule
  was broken, something was not found). It carries:
  - `type()` — a stable machine key, e.g. `platform.store_code_taken`
  - `category()` — one `ErrorCategory`
  - `context()` — the values the translated message needs, e.g. `['code' => 'sa']`
- **`ErrorCategory`** — the short, fixed list of error kinds. Domain code picks a category; it
  never knows HTTP.

  | Category | HTTP status |
  |---|---|
  | `NOT_FOUND` | 404 |
  | `FORBIDDEN` | 403 |
  | `CONFLICT` | 409 |
  | `INVALID` | 422 |
  | `UNSUPPORTED` | 415 |
  | `TOO_LARGE` | 413 |

- **One exception handler** (`bootstrap/app.php`) turns every `DomainError` into the same
  RFC 7807 response. The category-to-status table above exists only there. Anything that is not
  a `DomainError` is a bug: it is logged with the correlation id and answered with a generic 500
  that reveals nothing internal.
- **Global errors** — only the ones that genuinely belong to no module:
  `Unauthorized` (`FORBIDDEN`), `MoneyException` (`INVALID`), and Laravel's own validation
  errors, which use the same envelope. `MissingStoreContext` is a programming error, not a
  `DomainError`, so it always becomes a 500.

### 7.2 Why module errors instead of one global list of errors

A single global list would be one file every module edits for every new rule. It would grow
without limit, break the ~20-class ceiling on `Shared`, and let one module's change touch every
other module. Keeping the *standard* global and the *errors* local gives consistent responses
everywhere, while each module owns the meaning of its own failures — and its own translations,
under the module's namespace (`platform::errors.store_code_taken`).

### 7.3 Platform's errors

```
PlatformError  extends DomainError        (abstract, module base)
├── StoreNotFound                         NOT_FOUND
├── StoreCodeTaken                        CONFLICT
├── StoreAttributeImmutable               INVALID     code, country or currency
├── InvalidStoreAttribute                 INVALID     code, country or position format  ¹
├── InvalidTaxRate                        INVALID
├── InvalidTimezone                       INVALID
├── MissingTranslation                    INVALID     an Arabic or English value is empty  ¹
├── CurrencyNotFound                      NOT_FOUND
├── CurrencyAlreadyExists                 CONFLICT  ¹
├── InvalidCurrencyAttribute              INVALID     code, exponent or sign format  ¹
├── CurrencyExponentLocked                CONFLICT
├── UnknownSetting                        INVALID
├── InvalidSettingValue                   INVALID
├── SettingScopeMismatch                  INVALID
├── MediaNotFound                         NOT_FOUND
├── UnsupportedMediaType                  UNSUPPORTED
├── MediaTooLarge                         TOO_LARGE
├── MediaInUse                            CONFLICT
├── InvalidMediaVariantsTransition        CONFLICT    e.g. retrying an image that did not fail  ¹
└── InvalidMediaAttribute                 INVALID     file name or alt text too long / empty  ¹
```

¹ Added during implementation. The approved list had no error for some rules in §1 and §8 (a
malformed code, a missing translation, a duplicate currency, an invalid variant transition, an
over-long file name or alt text); these name them.

### 7.4 The response

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
the locale of the page they are on. `type` is the error's stable key. `detail` never contains
stack traces, SQL, or another customer's data.

---

## 8 · Test scenarios

### Unit (no framework, no database)

- Store: rejects bad `code` format, missing Arabic or English name, tax rate outside 0–10000,
  invalid timezone; refuses to change `code`, `country_code` or `currency_code`.
- Currency: rejects bad code and exponent; refuses exponent change when in use; allows it when not.
- Currency display: shows the sign when set; shows the abbreviation in the page's language when
  the sign is empty; clearing the sign switches every price to letters.
- SettingDefinition: rejects a key whose prefix is not the declaring module; rejects duplicate keys.
- Setting write: rejects undeclared key, wrong scope, value failing the definition's rules.
- Setting read: returns the default when nothing is stored; typed reader throws on type mismatch.
- Media variants: `PENDING → READY`, `PENDING → FAILED`, `FAILED → PENDING`; `READY` cannot change.
- Media upload rules: public accepts JPEG, PNG, WebP; private accepts PDF, JPEG, PNG; the type
  comes from the file's contents, so a PDF renamed `.jpg` is rejected as public.
- Audit changes: personal fields are recorded as `"changed"`, never their values.
- Every Platform error has a unique `type` and a category.

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
- The database refuses an IP address on a customer or system audit entry.

### Feature (HTTP and console)

- `/sa/...` resolves the KSA store and sets the store cookie; `/xx/...` returns 404.
- `/` with a valid store cookie redirects to that store; with no cookie, or a cookie naming a
  store that does not exist, it shows the country page.
- **A warm request resolves the store from the cache table alone** — two tiny reads, never the store tables — measured against the real database cache (the query-count guard).
- `platform:store:create` refuses an incomplete store and creates a complete one with its audit entry.
- Seeding creates `sa`, `eg`, `ae` with the values in §5.7.
- Upload: an identical public image returns the existing media; identical private files stay
  separate; unsupported type → 415; oversize → 413.
- Upload inside a caller's transaction that rolls back leaves no file behind.
- Variants job writes all 12 variant objects, each really in its format, to the public disk and
  publishes `MediaVariantsReady`; deleting the media mid-run leaves no variant behind.
- An image stuck in `PENDING` 15 minutes is queued again by the sweep and by staff Retry; a
  recent one is not.
- A private media URL is signed, asks the storage to download under the original file name, and
  stops working after it expires; no original and no private file is ever on the public disk.
- Reading media never locks its row.
- A staff action's audit entry records the staff member's IP address.
- Error responses match the RFC 7807 shape in both Arabic and English; each category gets its
  HTTP status; an unexpected exception returns a generic 500 with no internal detail.
- Admin-permission scenarios (403 for a staff member scoped to `sa` editing `ae`) are written in
  Stage 2, when Access provides real roles.

### Architecture

- Every Platform command handler asserts a permission (the first real use of this §19 rule).
- The store-scope opt-out appears only in `Application/Query` and Ops.
- Platform enum columns are stored as strings.
- Every class in any module's `Domain/Exception` extends `DomainError` (applies to all later modules).
- `ErrorCategory` → HTTP status mapping appears only in the exception handler, never in `Domain/`.
- No `spatie/laravel-medialibrary` dependency exists.

---

## 9 · Questions

### 9.1 Answered by the owner — 2026-09-16

| # | Question | Decision |
|---|---|---|
| 1 | Errors: one global list, or errors per module? | **One global standard, errors per module** (§7). Recommended in review and accepted by the owner. |
| 2 | `spatie/laravel-medialibrary` or our own `media` table? | **Our own table.** The package is dropped; Intervention Image does the resizing. |
| 3 | Where private files live | **Same `media` table**, `PRIVATE` visibility, served only through expiring signed URLs. |
| 4 | What `brand.com/` does | **Redirect to the store in the cookie; otherwise show the country page.** No IP detection. |
| 5 | Upload limits | **10 MB.** Public: JPEG, PNG, WebP. Private: PDF, JPEG, PNG. |
| 6 | Audit retention and staff IP | **Kept forever. Staff IP address recorded.** |
| 7 | Saudi Riyal symbol | **The new official sign, `U+20C1`**, with fonts chosen to include it. |
| 8 | UAE Dirham symbol | **The new official sign, `U+20C3`** (Unicode 18.0). |
| — | Fallback | **Any currency whose sign has no Unicode character, or which the font cannot draw, shows its letters instead** — same rule for every store (§1.2). |
| 9 | Image variant sizes | **Keep the whole image, limit the longest side** (200 / 600 / 1200 / 2400 px), never enlarge (§1.4). |
| 10 | How long a private file link works | **30 minutes** (§1.4). |
| 11 | Build the event tables (`processed_events`, `outbox_messages`) now? | **No — with the first module that needs them** (§5.6). |
| 12 | Deduplicate identical private files? | **No — public images only.** Companies never share a document (§1.4). |
| 13 | Recover an image whose resize job was lost | **Both:** a sweep every 10 minutes and staff Retry, for images `PENDING` 15 minutes (§1.4, §4.1). |
| 14 | Public originals carry photo metadata (GPS) | **Keep every original private;** public images are shown only through their variants (§1.4). |
| 15 | ULIDs in image file paths vs handoff "never expose the ULID" | **Keep them:** the rule is about human-facing identifiers (§5.4). |

### 9.2 Still open

None.
