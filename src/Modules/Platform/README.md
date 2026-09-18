# Platform module

**Build stage 1. Depends on nothing.** Every other module depends on it.

Platform answers the questions every module asks before it can do anything:

- **Which stores exist?** `sa`, `eg`, `ae`: their currency, tax rate and timezone.
- **Which store am I in?** Store context for every request, job and command.
- **What is this value set to?** Settings that modules declare and staff change without a deploy.
- **Where is this file?** Uploaded images and documents, and their resized copies.
- **Who changed what?** The append-only audit log.

The full rules are in the approved specification, [docs/modules/platform.md](../../../docs/modules/platform.md).
This file explains how the code is organised and why, so a developer can find their way around.
The Shared kernel it builds on is described in [src/Shared/README.md](../../Shared/README.md).

---

## Using Platform from another module

Import only `Modules\Platform\Public\**` (Deptrac refuses anything else).

```php
// Read: inject the contract. Every method returns DTOs, never Eloquent models.
public function __construct(private PlatformApi $platform) {}

$store = $this->platform->storeByCode('sa');                 // StoreDto, from cache
$days  = $this->platform->setting('loyalty.points.expiry_days')->int();
$urls  = $this->platform->mediaUrls($product->imageId);      // MediaUrlsDto

// Audit: inside the same transaction as your change, after checking permission.
$this->platform->recordAudit(new AuditEntryDto(
    'b2b.company.approved', 'b2b.company', $company->id, $storeId?->value,   // ?StoreId; null for global resources
    AuditChanges::none()->changed('status', 'PENDING', 'APPROVED')->personal('contact_phone'),
));
```

```php
// Declare settings once, in your module's service provider boot().
$this->app->make(SettingsRegistry::class)->define('loyalty',
    new SettingDefinitionDto('loyalty.points.expiry_days', SettingScope::Global,
        SettingType::Integer, ['min:1'], 365, 'loyalty.settings.update'),
);
```

```php
// Storefront routes: the "store" middleware resolves /{store}/{locale}/..., sets the store context
// and the app locale, and fills both into every link it generates.
Route::prefix('{store}/{locale}')->middleware('store')->group(...);

// A top-level URL your module owns: reserve it in register(), so no store can take it.
$this->app->make(ReservedPaths::class)->reserve('payments', 'webhooks');
```

Listen to `Public/Events/*` (`StoreUpdated`, `SettingChanged`, `MediaVariantsReady`…). They carry
ids only and are dispatched after the transaction commits.

---

## What is inside

| Folder | Contents |
|---|---|
| `Public/` | The contract other modules use: `PlatformApi`, `SettingsRegistry`, `ReservedPaths`, DTOs, enums, events. |
| `Domain/Model` | `Store`, `Currency`, `Media`: plain PHP classes holding the rules, with no Laravel inside. |
| `Domain/ValueObject` | `StoreCode`, `CountryCode`, `CurrencyCode`, `TaxRate`, `Timezone`, `TranslatedText`. Each validates itself when created. |
| `Domain/Exception` | Every expected error, all extending `PlatformError` → `DomainError`. |
| `Domain/Repository` | Interfaces for loading and saving the models. |
| `Application/Command` | One folder per use case: a command (plain data) plus its handler. |
| `Application/Audit` | Builds the audit entry for each kind of change (`StoreAudit`, `CurrencyAudit`, `MediaAudit`). |
| `Application/Settings` | The settings registry, strict type checks and reading with defaults. |
| `Application/Media` | What media needs from the outside world, as interfaces (storage, file inspection, resizing, the queue), plus `MediaSettings` (the upload-limit declarations) and `InspectedFile`. |
| `Application/Query` | The read sides: `StoreDirectory` (stores and currencies, cached) and `MediaReader`. |
| `Infrastructure/` | Eloquent and query-builder repositories, caching, the audit writer, Laravel disks, Intervention Image, the queued job, migrations and the service provider. |
| `Presentation/` | The `store` middleware, the country-choice page, a placeholder store home page, console commands, Arabic and English translations. |

---

## Approaches and why

### Every change goes through a command handler

A handler always runs in this order:

1. **Authorize** with the permission string on the handler (`platform.store.update`) and the
   scope it applies to: `PermissionScope::store($id)` for one store's data, `allStores()` when the
   change reaches every store (a global setting), `global()` when no store is involved (media,
   currencies). An architecture test fails the build if a handler forgets.
2. **Open a transaction** and load the model, locking the row (`lockById`, `byCode`). Reads
   outside a change never lock (`byId`, the read models).
3. **Let the model apply the change.** The model enforces the rules and records which
   attributes really changed (`pullChanges()`).
4. **Save, audit and invalidate the cache**, then **dispatch the event**. All of this happens
   inside the transaction, and the event and cache invalidation only take effect after commit.

A change that alters nothing writes no audit entry and sends no event. Not every handler has all
four parts: only a person's action is audited, so the system's variants job and stuck-image sweep
write no audit entry, and only handlers whose data is cached invalidate a cache.

**Until Access exists,** `SystemOnlyAuthorizer` lets only the system act, and only outside web
requests: console commands, seeders, scheduled tasks and queued jobs. With no login yet,
`SystemActorContext` reports the system for a web request too, so the authorizer refuses every
web request itself. Nothing is accidentally open to staff or customers. Access replaces both
bindings in stage 2.

### Stores have no lifecycle

A store is created complete in one command (`platform:store:create` needs every attribute), is
never deleted, and has no status. Code, country and currency can never change: an attempt to
change them is refused with `StoreAttributeImmutable`, not silently ignored. Tax rates are basis
points (`1500` = 15%), so no float or DECIMAL is ever involved.

A currency's `exponent` is locked once any store uses it: changing it would silently
reinterpret every stored amount. A price shows the currency's sign, or its letters when the sign
is empty. Clearing a sign that a font cannot draw is a data change, not a deploy.

### Resolving a store reads only the cache

`StoreDirectory` loads every store and currency in two queries and caches the snapshot.
`ResolveStore` reads from it on every storefront request. **The cache lives in PostgreSQL** for now
(owner's decision, 2026-09-18 — no Redis until traffic needs it), so a warm request makes two tiny
reads of the cache table (the version, then the snapshot) and never loads the store tables again.
A test counts the queries of a warm request against the real database cache.

**Cache invalidation** uses `VersionedCache`. The snapshot is stored under a version key, and a
change replaces the version:
- **Cache in PostgreSQL (now):** the new version is written **inside the transaction** of the change,
  so it commits or rolls back with it. The cache and the data can never disagree.
- **Cache outside the database (if Redis comes back):** writes there are not transactional, so the
  version is replaced **after the commit**; a request that read the old rows just before the commit
  can only cache them under a version nobody reads any more. Revisit how a version write lost during
  a Redis outage is recovered before switching.

Snapshots are also given a lifetime as a safety net: 6 hours for stores and currencies, 1 hour for
settings. Running migrations invalidates the caches.

Nothing is memoised inside the PHP process. Queue workers run for hours, and an in-memory copy
would go stale when another process edits a store.

### Store context travels by itself

`LaravelStoreContext` stores the current store in Laravel's `Context`. Laravel copies `Context`
into every queued job and restores it when the job runs, so a job dispatched inside the `sa`
store runs in `sa` without passing anything by hand. Every log line is tagged with it too.

### Settings are declared in code, stored in the database

A module declares each key with its scope (global or per store), type, validation rules, default
and the permission needed to change it. `UpdateSettingHandler` checks the permission against that
store for a per-store key, and against **every** store for a global one, because a global key
reaches all of them.

- **Types are checked strictly before any rule runs.** `"5"` is not an integer and `1` is not a
  boolean. Laravel's own rules would accept both.
- **Reading a declared key never fails:** it returns the stored value or the default.
- **No secrets.** API keys and passwords live in server environment variables; a key named like one
  is refused at boot. A **sensitive** setting is audited only as "changed".
- **Values are cached** with the same `VersionedCache`, because modules read settings on hot
  paths such as OTP limits.

### The audit log cannot be edited

- Database triggers reject `UPDATE`, `DELETE` and `TRUNCATE` on `platform.audit_entries`.
- `AuditLog::record()` throws when called outside a transaction, so a change and its entry
  always commit or roll back together.
- Personal fields (names, emails, phones, addresses, uploaded file names) are recorded only as
  `"changed"`: the caller records them with `AuditChanges::personal()`, which accepts no value at
  all, so anonymizing an account never has to rewrite history. `changed()` refuses an attribute
  named like personal data (`email`, `contact_phone`, `billing_address`, `first_name`,
  `national_id`, `iban`…). It goes by the name only: a person's plain `name`, or a name the list
  does not know, must still be marked personal by its module.
- The actor, time and correlation id are filled in automatically. The IP address is filled in
  only for staff, and a CHECK constraint refuses it on any other entry.
- **Every entry has a source** Platform works out itself: `WEB`, `INTEGRATION` (a request made by an
  integration), `CONSOLE`, `JOB` or `IMPORT`. A global middleware (`TrackHttpRequest`) marks the time a
  request is being handled — Laravel's `runningInConsole()` cannot tell, because tests and
  `sync` jobs run in the console.
- **Nothing can be back-dated.** `occurred_at` and `recorded_at` come from PostgreSQL's clock and a
  CHECK keeps them equal. Only `recordImportedAudit` — system only, past dates only — gives old history
  its real date, and `recorded_at` still shows when it was written.
- A queued job acts as the **system**, and its entries also record **who queued it**
  (`requested_by_type`/`requested_by_id`). The payload of every queued job carries the requester
  (`QueuedActor`); while the job runs, `JobAwareActorContext` — which wraps whatever `ActorContext` is
  bound, Access's included — answers "the system, on behalf of X". With the `sync` queue a job runs
  inside the request, so the request's own actor comes back when the job ends.

### Media

```
UploadMedia ──▶ inspect headers (type, displayed size, animation, checksum)
                        │
        public image already stored? ──yes──▶ return that id      (private files: never shared)
                        │ no
                        ▼
   Media::upload() checks type, size, pixels, animation, file name
                        │
   write the original to the PRIVATE disk
                        │
   transaction: insert row (ON CONFLICT DO NOTHING) + audit entry
        │ fails → delete the file again
        │ lost a race → delete our file, return the winner's id
        │ a caller's outer transaction rolls back later → delete the file
        ▼
   public image → queue GenerateMediaVariantsJob (after commit)
                        │
                        ▼
   12 variants (thumb/card/detail/zoom × AVIF/WebP/JPEG) on the PUBLIC disk → READY → MediaVariantsReady
   3 failed attempts → FAILED → staff RetryMediaVariants
   job lost (still PENDING 15 min after queuing) → sweep every 10 min, or staff Retry
```

- **The type comes from the file's bytes** (`finfo`), never the name or the browser's claim.
  Public accepts JPEG, PNG and WebP; private accepts PDF, JPEG and PNG. SVG is never accepted
  because it can carry scripts, and an animated WebP is refused because it cannot be resized.
- **Only headers are read at upload.** Nothing decodes the image, so the pixel limits (12,000 px
  on a side, 50 million pixels) refuse a decompression bomb before it can exhaust memory. Width
  and height are recorded as displayed: a sideways phone photo records its upright size.
- **Size limits are settings** (`platform.media.max_public_bytes`, `max_private_bytes`, 10 MB by
  default).
- **Every original is private.** A phone photo carries metadata such as its GPS location, so
  originals — public images included — stay on the private disk. The public disk behind the CDN
  holds only variants, which carry no metadata. A public image has no URL until its variants are
  ready.
- **Identical public images are stored once; private files never are.** A partial unique index
  on public checksums plus `ON CONFLICT DO NOTHING` handles two identical uploads arriving at
  the same moment without aborting the transaction. Two companies uploading the same receipt get
  two separate media.
- **Every file has its row and every row has its file.** The file is written before the row and
  removed if the row does not commit, including when a caller's own transaction rolls back after
  the upload (`deleteAfterRollBack`). Deleting media removes the files only after the delete
  commits.
- **Variants are generated once, in the background, outside any transaction**, then marked READY
  in a short transaction that checks the media is still PENDING. Running the job twice is
  harmless. If the media was deleted meanwhile, the job removes what it wrote. Only one full-size
  bitmap is held: sizes are made largest first, each shrinking the previous one, and a sideways
  photo is turned upright only after the first shrink (turning it at full size would hold three
  full-size copies: 780 MB for a 50-megapixel photo). Each size keeps
  the whole image, limits only its longest side (200 / 600 / 1200 / 2400 px) and never enlarges.
  Photos are turned upright, metadata is stripped, and transparency becomes white in JPEG.
- **Lost jobs are recovered.** `variants_queued_at` records when generation was last queued. An
  image still PENDING 15 minutes later is queued again by `platform:media:requeue-stuck`, which
  the scheduler runs every 10 minutes, or by staff pressing Retry.
- **Reads never lock.** `PlatformApi::media()` and `mediaUrls()` read without `FOR UPDATE`, so a
  storefront page never waits on, or blocks, a change.
- **Media in use cannot be deleted.** Other modules reference `platform.media(id)` with
  `ON DELETE RESTRICT`. The database refuses the delete and Platform reports `MediaInUse`.
- **Private files** are served only through signed links that expire after 30 minutes. On
  S3-compatible storage they download under their original name; Laravel's local disk ignores that
  and serves them under their object key. They never get variants and never go through the CDN.
  Platform does not know who may see a private file: the module that owns it checks its viewer
  before asking for the link.
- **No media package.** `spatie/laravel-medialibrary` ties files to another module's Eloquent
  models, which the module boundaries forbid. A test fails if it is ever installed.

---

## Configuration

The storage provider and CDN are not chosen yet. The code only names Laravel disks, so choosing a
provider later is configuration, not code:

| Setting | Where | Default |
|---|---|---|
| Disk for variants of public images (behind the CDN) | `MEDIA_PUBLIC_DISK` (`config/platform.php`) | `public` |
| Disk for every original and private file | `MEDIA_PRIVATE_DISK` | `local` |
| Private link lifetime | `platform.media.private_link_minutes` in `config/platform.php` | 30 |
| Upload size limits | Platform settings, changed at runtime | 10 MB each |

When the provider is chosen, define its disks in `config/filesystems.php` (an S3-compatible disk
with the CDN as its `url`, and a private disk that supports temporary URLs), then point the two
variables at them. They must be different disks: the storage refuses to start otherwise. File
visibility (public or private) comes from each disk's own configuration; the code never sets it,
 because many S3 buckets refuse per-object ACLs.

The server needs:
- **PHP extensions** `gd` (built with AVIF and WebP), `exif` and `fileinfo`. `composer.json`
  requires them, and a test fails if GD cannot write AVIF or WebP.
- **A queue worker** for variant generation. The job stops after 80 seconds, below the queue's
  90-second `retry_after`.
- **The scheduler** (`php artisan schedule:work`, or cron running `schedule:run`) for the
  stuck-image sweep.

---

## How it was built

Specification first, approved by the owner before any code. It was then built in small pull
requests, each passing `composer check` (Pint, PHPStan level 8, Deptrac, Pest) in CI before
merging:

| PR | What it added |
|---|---|
| #1 | Project skeleton: Laravel 13, PostgreSQL, the module layout, quality tooling and CI |
| #3 | `Money` in the Shared kernel |
| #2 | This module's specification |
| #4 | Shared foundation: error standard, store context, store scope, correlation id |
| #5 | Stores and currencies: models, handlers, cache, store resolution, console commands, seeder |
| #6 | The audit log |
| #7 | Settings |
| #8 | Fixes from the first independent review, which covered #4–#7 after they had merged |
| this | Media, plus these READMEs |

Since the first review, the rule is that separate review agents, which did not write the code,
read each finished stage and its tests before its pull request merges. Their confirmed findings
are fixed in the same pull request.

## Tests

| Folder | What it covers |
|---|---|
| `tests/Modules/Platform/Unit` | Domain rules with no framework and no database: stores, currencies, media, settings values, audit changes, error types. |
| `tests/Modules/Platform/Integration` | Against the real PostgreSQL test database: schema and constraints, handlers, permissions, audit triggers, cache consistency, store context in jobs, settings, media with real image processing. |
| `tests/Modules/Platform/Feature` | HTTP and console: store resolution (including the check that a warm request reads only the cache), the country page, commands, seeding. |
| `tests/Architecture` | Layering, the error standard, every handler authorizes, no store codes in domain code, where the store-scope opt-out may appear, and the forbidden packages. |

Run everything with `composer check`. The test database is `touchwood_test`.

---

## Known gaps

- **Listing pages ask for media URLs one image at a time.** `PlatformApi::mediaUrls()` takes one
  id. A batch version belongs with Catalog's product listings, where the query-count budget will
  demand it.
- **The event tables in spec §5.6** (`processed_events`, `outbox_messages`) are not created.
  The owner decided they are built with the first module that consumes an event or publishes a
  critical one.
- **Real permissions arrive with Access.** Until then only the system can run handlers, and
  never from a web request.
- **The admin view use cases in spec §3** (`ListStores` / `ViewStore`, `ViewSettings`,
  `ViewAuditLog`) and all admin screens come after Access, because a screen with no login cannot
  be protected (spec §3, "Delivery").
