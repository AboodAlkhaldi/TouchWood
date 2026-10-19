# Sync

Reading a store's provider - Odoo the first - one way: each size's base price and its stock, by product
code. The spec is [docs/modules/sync.md](../../../docs/modules/sync.md); the handoff's §12.2 is its
source.

## Status

Built in six steps, one PR each (stage 5's plan). Everything on our side is built against a pretend
provider; only the adapter that calls Odoo waits for the provider's team (sync.md §9.1). The screens
and their menu entries come with stage 5's screens; the notifications' sending with Ops.

| Step | What | State |
|---|---|---|
| 1 | Foundation: the `sync` schema, the permissions and the role editor's Sync group, the errors | built (#142) |
| 2 | The contracts Pricing and Inventory give (§2.3): `StoreWiring`, `ProviderPrices`, `ProviderStock` | built (#144, #147) |
| 3 | Wiring: the provider adapter, a pretend provider for the tests, Wire / Change / Unwire, the Provider page's read | built (#151) |
| 4a | The read engine: `ReadStoreJob` and `RunRead`; Catalog's amendment 21 (`variantsByCodes`) | built (#153) |
| 4b | The triggers: the provider's signed notice, the hourly and nightly reads, a corrected code read again | built (#154) |
| 5a | What staff read and do: the report and its count, the page of reads, Sync now, Retry, Dismiss, the log kept 90 days | built (#155) |
| 5b | What staff are told of: Access's `SYNC_PROBLEMS` topic (amendment 71) and `SyncNeedsAttention` | built (#157) |
| 6 | The module's own pass: three independent reviews of the whole module (completeness, security, architecture and cost) and their fixes (6a); a skipped discount's entry closed the moment the discount reaches its size again, told by Pricing (6b) | built (#158, #159) |
| Screens 6 | Stage 5's screens step 6, the server side of Sync's screens (§4.1.3): `SyncStatus` (the status line, Not Wired), `ViewSyncReport` (Problems 50 at a time after a cursor, the kind filter with each kind's count, each size's values, a stopped offer and a skipped discount named by Pricing, Q37), `ListSyncReads` (50 at a time, who asked by name, Q36; a failed read counted from what it wrote, Q30), `ViewSyncRead` (each section paged 100 on its own, Q32), `ViewConnection` (the last reads, the notice address, Until the Odoo connection is built, Q38), `SyncActionsForReader`, `SyncReportCount` (the menu's count, one query); Sync's failure reasons kept as codes and written in both languages (Q31); each provider setting refused by its own name (Q34); a code twice in one read skipped and reported (§2.4); the `sync.admin.*` routes and controllers, the menu's two entries, the words. The pages are placeholders until step 7 | **this step** |

## What is inside

| Folder | Holds |
|---|---|
| `Application/Command` | Wiring: `WireStore`, `ChangeConnection`, `UnwireStore` (Super Admins). Reading: `RunRead` (the system's), `QueueScheduledReads`. Staff: `SyncNow`, `RetryEntry`, `DismissEntry`. Housekeeping: `PruneSyncLog` |
| `Application/Query` | `ViewConnection` (the Provider page: never the key or the secret), `SyncStatus` (the status line), `ViewSyncReport`, `CountSyncReport`, `ListSyncReads`, `ViewSyncRead`, `SyncActions/SyncActionsForReader` (what the screens may offer) |
| `Application/Notifications` | `NotificationReceiver`: the provider's notice checked and turned into a read |
| `Application/Report` | `ReportAccess` (the permission per store; another store's entry or read not found), `SyncLog` (the report's and the reads' rows), `ReportLines` and `ReadViews` (rows named for the screens), `FailureView` (a reason: Sync's code written, or the provider's words), `Cursor` (Show More), `SyncReportCount` (the menu's count), `SyncWords`, the views |
| `Application/Connections` | `ConnectionInput`: the fields checked, each refusal naming its field |
| `Application` | `SyncPermissions`: View the Sync Report and Run Sync Reads, per store, any role (access.md amendment 68); wiring, the system's reads and the pruning, reserved |
| `Domain` | `Model/Connection` (key and secret plain in memory only), `Model/Read`, `Provider/ProviderAdapter(s)`, the repositories (`Connections`, `Reads`, `ReportEntries`, `ReadLines`, `ReadReports`), the value objects |
| `Domain/Exception` | `SyncError` and the eight errors of §7 thrown, each a `sync.*` type with a category |
| `Infrastructure` | `SyncServiceProvider` (after Pricing and Inventory in `bootstrap/providers.php`, so its `StoreWiring` binding wins; schedules, listeners, the notice's route, the reserved path `sync`); `Persistence/` the schema and migrations; `Eloquent/` the repositories; `Provider/` the adapters (`OdooAdapter` waits for the provider's documentation and fails every test, calling nothing); `Queue/` the jobs; `Listener/` what follows other modules; `External/ConnectionWiring` |
| `Presentation` | The notice's controller and route; the screens' routes (`admin-routes.php`, `sync.admin.*`), controllers and page data (`Http/Resource/SyncPages`); `lang/{ar,en}` - permissions, audit names, jobs, errors, `admin_report`, `admin_provider`, `menu` |
| `Public/Events` | `SyncNeedsAttention` (for Ops) |

## How it is built

### Boundaries

- Sync's Public may use Shared and Platform's, Catalog's, Pricing's and Inventory's Public; its inside
  also Access's Public, to declare its permissions and for nothing else (owner, 2026-10-07) - deptrac
  cannot say "these classes only", so `tests/Architecture/SyncAccessUseTest.php` does.
- Prices and stock are written only through Pricing's and Inventory's contracts, in their own
  transactions and locks; Sync holds no transaction of its own across them.
- The one call to the provider in a web request is the Super Admin's test when saving a connection, 10
  seconds at most (§9.3 #21); every read is a queued job.

### Wiring (§1.2, §1.8)

- Tested before any lock; a change is rebuilt from the row as locked, so two changes at once never undo
  each other, and writes only what the form holds. Wiring queues a full `NOW` read asked by the Super
  Admin and tells Inventory after its commit; unwiring tells it inside (it moves stock), closes the
  report (`UNWIRED`), fails waiting and running reads, and keeps every price and stock number the
  provider wrote - the panel's prices are staff's again at once.
- The key and the secret are encrypted with the app's key, never returned, logged, audited or put in a
  job; a change audits only that they were replaced. The address is HTTPS, no name or password in it,
  audited as `url` (the audit log refuses an `address` field as personal data).
- A store that is not one is refused (`InvalidSyncInput`); one with a connection, reads or entries
  cannot be deleted.

### What starts a read (§1.3)

- **The provider's notice**, `POST /sync/notify/{storeId}`: its signature (an HMAC-SHA256 of
  "timestamp.body" with the store's secret, 64 hexadecimal characters, any case or spaces read as one,
  compared in constant time) is its only authority. Refused with `401` and no body when not its own,
  more than 5 minutes off, or the store not wired - worked out the same way either way; `413` over
  300 KB; `422` for a body that is not 1-1,000 codes or holds a control character - checked before the
  notice counts as seen, so the same signed notice again is answered `202` without queuing twice.
  Limited before any work: 600 calls a minute from one address, then 60 for the store (`429`,
  `Retry-After`). The route is unnamed, has no session, and its parameter is a ULID (Platform
  constrains `{store}` to a store's code); `/sync` is never a store's code.
- **One read of each kind waits** per store: a notice's or a corrected code's codes join the one
  waiting, up to 5,000; past them, one full read of the store instead (owner, 2026-10-18, §9.3 #27).
- **The hour's and the night's reads** (02:00 Riyadh) for every wired store, off ones too - a job class
  each, since a scheduled event and its one-server lock are named after its job's class. The hourly
  read asks from the start of the last read that covered every change, never moved by a notice's.
- **A corrected code** is read again in every wired store, after Catalog's change commits.
- **Sync now and Retry** queue a read asked by the person.

### A read (§1.3-§1.7)

- One per store at a time (`reads_one_running`); the one that ends starts the store's next - whatever
  happens to it, and never itself before its own retry's wait.
- Batches of 500 values: codes matched in one Catalog read (`variantsByCodes`; a control character in a
  code written "?"), prices then stock written through the contracts, each module's changes written as
  the read's lines as soon as it has written. Before each batch, and for a batch Pricing and Inventory
  never see, the read checks it still runs for the store's present wiring (`connections.created_at`):
  unwired, or wired again, it stops.
- The report's entries are opened per batch in one statement per kind and copied into the read's own
  record (`sync.read_reports`, exact per read; owner, 2026-10-18); the provider's text is kept to 100
  characters, its code to 255. What is right again closes (`READ`).
- **A failed attempt** goes back to the queue (or fails as superseded when the same kind already
  waits), tried after 1, 5 and 15 minutes; the last fails the read, marks the store failing and opens
  `READ_FAILED` - only if the read had not failed already. Staff see the provider's reason (a short
  sentence; the adapter never puts a response or a key in it), never another error's text.
- **A read is given 20 minutes; one running past 30 is taken as dead.** A copy of its job picked up
  while it runs - a second worker, or its worker gone - waits until then and takes it back; another
  read of the store fails a dead one and reports it. A retry reports everything again (its record
  cleared first) and its counts are taken from what it wrote over every attempt.
- **Deploy**: the queue connection's `retry_after` must be longer than 20 minutes (HANDOFF's deploy
  notes).

### The report and the page of reads (§1.6, §3, §9.3 #19)

- A report entry is about one thing: a code we do not have (`UNKNOWN_CODE`), the whole store
  (`READ_FAILED`), or a size of ours (every other kind; its code read from Catalog). Only a stopped
  sale names its sale; no key on a size or a sale, as the audit log names its subjects. One open entry
  per store, kind, code and size.
- Who closed an entry: `READ`, `UNWIRED`, `CATALOG` (an off or draft size switched on and its product
  ready), `PRICING` (a skipped discount reaching its size again - the ceiling raised, the discount
  changed or ended, the provider's price lowered; Pricing's `DiscountReachChecked`, owner, 2026-10-18)
  or `STAFF:{id}` (Dismiss / Leave it, audited - never a failed read's).
- Views by `sync.report.view`, actions by `sync.read.run`, per store (`ReportAccess`). An entry or a
  read is looked up first, to know its store; one of a store the person holds nothing of Sync's in is
  not found (owner, 2026-10-18). The report pages 50 entries, the latest seen first, from a partial
  index; its count is one query; a read's sections page 100 each.
- The log - reads with their lines and records, and closed entries - is deleted after 90 days each
  night at 03:00 Riyadh time, in batches on indexes.

### What staff are told of (§9.3 #18)

When a read ends - done, or failed after its retries - the store's open `SALE_STOPPED`,
`DISCOUNT_SKIPPED` and `READ_FAILED` entries not yet told of are marked (`announced_at`) and one
`SyncNeedsAttention` carries their counts by kind, after the change commits. Each entry is told once;
another sale stopped, or discount skipped, on the same size is told again (§9.3 #25). Marked before it
is sent: Ops's listener must be queued, so a send that fails is retried, not lost. Ops sends it to the
store's holders of `sync.report.view` who chose Access's `SYNC_PROBLEMS` topic (amendment 71).

### Known limits

- Every lookup decrypts the key and the secret; if the app's key were lost without
  `APP_PREVIOUS_KEYS`, the Provider page, a change and unwiring would fail until it is restored.
- A "discount skipped" entry a read opens just after staff raised the ceiling or ended the discount -
  between Pricing's write of that batch and Sync's report of it, a fraction of a second - stays open
  until staff dismiss it: Pricing works out a skip only for a price that changes, so a later read with
  the same price cannot tell it is reached. An entry names the discount that skipped first; another
  discount still skipping the size keeps it open under that name.
- Pricing's `apply` reads the store and its switched-on sizes on every batch of 500 (about 200 times
  for a night's read of 100,000 sizes); cheap per call, measured with stage 5's costs.

## Tests

| File | Proves |
|---|---|
| `tests/Modules/Sync/Integration/SyncSchemaTest.php` | The schema exists, is on the search path, and is created and dropped from nothing; each table's CHECKs, the one open entry per store, kind, code and size, the lines' kinds, and the foreign keys' delete rules |
| `tests/Modules/Sync/Integration/SyncPermissionsTest.php` | The two jobs and the three reserved, by name, kind, group and reservation; a staff role may hold both jobs; the group's and the jobs' names |
| `tests/Modules/Sync/Unit/SyncErrorsTest.php` | Exactly §7's eight thrown errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders; the fields an invalid input names |
| `tests/Modules/Sync/Integration/SyncWiringTest.php` | Wiring tested first and refused when the test fails; the key and secret encrypted and never audited or shown; the store wired for Pricing and Inventory at once, a NOW read queued by the Super Admin, Catalog told; each field refused by name; Odoo's adapter refusing until built; changing (key kept, secret replaced, tested first); unwiring (the count first, the connection, report and waiting reads); Super Admins only |
| `tests/Modules/Sync/Integration/SyncReadsTest.php` | A read: prices and stock written, counts, the connection marked read, audited once; the hourly read asking from the last covering read's start; each report kind opened and closed when right again; one read at a time, one full read of a kind waiting; dead reads taken back or failed and reported; a failed attempt requeued or superseded, the last failing and reporting the store; stopping when unwired or wired again mid-read; staff shown the provider's reason only; the system's and Super Admins' alone |
| `tests/Modules/Catalog/Integration/CatalogVariantsByCodesTest.php` | Amendment 21: by each code as given (spaces, Arabic-Indic digits), archived ones too, a few queries for many |
| `tests/Modules/Sync/Integration/SyncTriggersTest.php` | The notice: 202 and one NOTIFIED read of the trimmed codes, nothing called; 401 with no body for a wrong secret, no signature, another body, a time 6 minutes off, a store not wired or not one; 422 for each body that is not 1-1,000 codes; 429 past 60 a minute. The hour's and night's reads for every wired store, never piled up, scheduled on one server. A corrected code read again |
| `tests/Modules/Sync/Integration/SyncReportTest.php` | The page of reads and a read's sections; the report, a kind or all, and its count; each for the store's holders only, another store's not found; Sync now, Retry, Dismiss (closed by its person, audited; never a failed read's; two at once audited once); an off or draft size's entry closed by Catalog; the prune at 90 days |
| `tests/Modules/Sync/Integration/SyncAttentionTest.php` | One `SyncNeedsAttention` a read, counting its stopped sales, skipped discounts and failed read by kind, nothing else; each told once, another sale or discount on a size told again; a read failed already not reported; the `SYNC_PROBLEMS` topic |
| `tests/Modules/Sync/Integration/SyncPassTest.php` | Step 6's reviews, each proven: the notice's signature written one way, 64 hex characters, control characters, a bad body refused every time, 600 calls a minute an address; 5,000 waiting codes, then one full read; a read that crashes keeps its first batch, starts the store's next read (never itself), and on its retry reports once and counts what it wrote; a copy of a running read waits until it is dead; the jobs' tries, waits and times; provider text kept short; another wired store untouched by a failure; unwiring keeping prices and stock; a store that is not one refused |
| `tests/Modules/Sync/Integration/SyncDiscountReachTest.php` | A skipped discount's entry closed at once by Pricing: the ceiling raised (and not when raised too little), the discount ended - the store's last - and at its end time, the provider's price lowered for one size and not the other; Pricing telling a wired store only; another store's entries untouched |
| `tests/Modules/Sync/Integration/SyncScreenReadsTest.php` | Screens step 6's reads: the status line (Working, Failing with Sync's code written or the provider's words, Not Wired), the codes and the sentences turned into them; Problems paged with each kind's count and named; Reads paged, by name, a failed read's counts; a read's sections; the Provider page's connection; what the screens may offer, an off store; the menu's count; each read's queries counted warm |
| `tests/Modules/Sync/Integration/SyncCodeTwiceTest.php` | A code twice in one read, in one batch or two: both values skipped, the size reported as a bad value with what came; an unknown code twice unknown once |
| `tests/Modules/Sync/Feature/SyncScreensTest.php` | The screens over HTTP: the report's props, a store not wired showing Reads, Sync Now, Retry and Dismiss with their toasts and refusals, a read's page and its not-found, the Provider page (Super Admins), wiring with a setting refused under its box, the menu's entries and count, each page's own queries |
| `tests/Modules/Sync/Integration/SyncAuditNamesTest.php` | Every audited action named in both languages, nothing named that is not recorded |
| `tests/Architecture/SyncAccessUseTest.php` | Sync takes from Access only what declaring its permissions needs |
