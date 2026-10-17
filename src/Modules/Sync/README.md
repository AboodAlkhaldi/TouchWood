# Sync

Reading a store's provider - Odoo the first - one way: each size's base price and its stock, by product
code. The spec is [docs/modules/sync.md](../../../docs/modules/sync.md); the handoff's §12.2 is its
source.

## Status

Built in six steps, one PR each (stage 5's plan). Everything on our side is built against a pretend
provider; only the adapter that calls Odoo waits for the provider's team (sync.md §9.1).

| Step | What | State |
|---|---|---|
| 1 | Foundation: the `sync` schema and its three tables, the permissions and the role editor's Sync group, the errors and their words | built (#142) |
| 2 | The contracts Pricing and Inventory give (§2.3): `StoreWiring` in their Public, `ProviderPrices::apply`, `ProviderStock::apply`, `wired` and `unwired` | built (#144, #147) |
| 3 | Wiring: the provider adapter, a pretend provider for the tests, Wire / Change / Unwire, the Provider page's read (its screen comes with stage 5's screens) - `sync.connection.manage` | **this step** |
| 4 | Reads (the job that runs the read wiring queues): one per store at a time, the provider's notices, the hourly and nightly reads, one code - `sync.read.scheduled` | — |
| 5 | The report and its count, Retry, Sync now, dismissing; the log pruned after 90 days - `sync.report.view`, `sync.read.run`, `sync.log.prune` | — |
| 6 | The module's own pass | — |

## What is inside

| Folder | Holds |
|---|---|
| `Application/Command` | `WireStore` (tested first, then saved, a full read queued, Inventory told - §1.2), `ChangeConnection` (the same, a key or secret left empty kept), `UnwireStore` (§1.8: the connection gone, the report closed, waiting reads dropped, Inventory told; answers the count) |
| `Application/Query` | `ViewConnection`: the Provider page - never the key or the secret, only that they are set and when; the count before unwiring |
| `Application/Connections` | `ConnectionInput`: the fields checked, each refusal naming its field |
| `Application` | `SyncPermissions`: View the Sync Report and Run Sync Reads, per store, any role, in the Sync group (access.md amendment 68); wiring, the system's reads and the pruning, reserved (§3) |
| `Domain` | `Model/Connection` (key and secret plain in memory only), `Provider/ProviderAdapter` and `ProviderAdapters` (§2.1), `Repository/Connections`, `Reads`, `ReportEntries`; `ProviderKind`, `ConnectionState`, `ReadKind`, `ProviderValue` |
| `Domain/Exception` | `SyncError` and the five errors of §7 thrown, each a `sync.*` type with a category (`ReadRunning` is never thrown: a second read waits) |
| `Infrastructure` | `SyncServiceProvider` (after Pricing and Inventory in `bootstrap/providers.php`, so its `StoreWiring` binding wins); `Persistence/SyncSchema` and the migrations; `Eloquent/` the three repositories (the key and the secret encrypted with the app's key); `Provider/` the adapters - `OdooAdapter` waits for the provider's documentation and fails every test, calling nothing; `External/ConnectionWiring`: a store is wired while it has a connection, for Pricing and Inventory both |
| `Presentation/lang/{ar,en}` | `permissions.php` (the names the role editor shows), `audit.php`, `errors.php` (each error's title and detail, and the fields an invalid input names) |

## How it is built

- **Boundaries** (`deptrac.yaml`): Sync's Public may use Shared, Platform's, Catalog's, Pricing's and
  Inventory's Public; its inside also Access's Public, to declare its permissions and for nothing else
  (owner, 2026-10-07) - deptrac cannot say "these five classes only", so
  `tests/Architecture/SyncAccessUseTest.php` does.
- **The schema** is on `config/database.php`'s search path, so `migrate:fresh` wipes it.
- **The tables** (§5) refuse behind the code what the code must never write:
  - a connection to a provider we do not have, at an address that is not HTTPS, a failing one that
    does not say why;
  - a read of an unknown kind or state, a count below 0, times out of step with its state, a failure
    with no reason;
  - a second open report entry for the same store, kind, code and size.
- **Who asked for a read** is two columns, `requested_by_type` and `requested_by_id`, as Inventory's
  ledger names its asker (§5 writes one, `requested_by`). Only staff ask - Sync now, Retry, wiring;
  the provider's notices and the hourly read are never anyone's. Wiring's first read is a `NOW` read,
  asked by the Super Admin who wired the store. A failed read may never have started (a job that gave up waiting, or one dropped
  when its store was unwired).
- **A report entry is about one thing**: a code we do not have (`UNKNOWN_CODE`, the code and no
  size), the whole store (`READ_FAILED`, neither), or a size of ours (every other kind, the size and no
  code - its code is read from Catalog, so a corrected code is still the same entry). Only a stopped
  sale names its sale. The provider's code is kept up to 255 characters; step 4 cuts a longer one.
  Who closed an entry (`closed_by`) is free text until step 5 defines it.
- **A store** with a connection, reads or report entries cannot be deleted. A size or a sale keeps its
  report entries when it goes: no key on them, as the audit log names its subjects.
- **The one call to the provider in a web request** is the Super Admin's test when saving a
  connection, 10 seconds at most (owner, 2026-10-17, sync.md §9.3 #21); every read is a queued job.
  The 10 seconds are the adapter's to keep: Odoo's, when it is written, sets its HTTP client's connect
  and total timeouts at or below them.
- **Wiring and changing**: tested before any lock; a change is rebuilt from the row as locked, so two
  changes at once never undo each other (what is saved is tested again if the other landed
  meanwhile), and it writes only what the form holds - the state and the last read are the reads'.
  Wiring tells Inventory after its commit, so the store's stock is not locked for the whole pass;
  unwiring does it inside, as it moves stock (the store's checkouts wait for that pass).
- **The address**: HTTPS, the scheme kept in lower case, no name or password in it (it is audited and
  shown).
- **Known limit**: every lookup decrypts the key and the secret; if the app's key were lost without
  `APP_PREVIOUS_KEYS`, the Provider page, a change and unwiring would fail until it is restored.
- **The key and the secret** are encrypted with the app's key, never returned, logged or audited -
  a change audits only that they were replaced. The audit names the provider's address `url`: the
  audit log refuses an `address` field as personal data.
- **The permissions** are declared into Access's catalog at boot, as Inventory's are.
- **Errors** reach other modules only as `DomainError` with a stable `sync.*` type.

## Tests

| File | Proves |
|---|---|
| `tests/Modules/Sync/Integration/SyncSchemaTest.php` | The schema exists, is on the search path, and is created and dropped (with what is in it) from nothing; each table's CHECKs, the one open entry per store, kind, code and size, and the foreign keys' delete rules |
| `tests/Modules/Sync/Integration/SyncPermissionsTest.php` | The two jobs and the three reserved, by name, kind, group and reservation; a staff role may hold both jobs; the group's and the jobs' names |
| `tests/Modules/Sync/Unit/SyncErrorsTest.php` | Exactly §7's five thrown errors, each with §7's type and category; a non-empty title and detail in both languages with the same placeholders; the fields an invalid input names |
| `tests/Modules/Sync/Integration/SyncWiringTest.php` | Wiring tested first and refused when the test fails; the key and secret encrypted and never audited or shown; the store wired for Pricing and Inventory at once, a NOW read queued by the Super Admin, Catalog told; each field refused by name; Odoo's adapter refusing until built; changing (key kept, secret replaced, tested first); unwiring (the count first, the connection, report and waiting reads); Super Admins only |
| `tests/Modules/Sync/Integration/SyncAuditNamesTest.php` | Every audited action named in both languages, nothing named that is not recorded |
| `tests/Architecture/SyncAccessUseTest.php` | Sync takes from Access only what declaring its permissions needs |
