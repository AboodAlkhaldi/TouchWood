# TouchWood Platform

`docs/HANDOFF.md` is the source of truth. Read it before changing anything.

- §2 rules and the §16 rejection table are decided. Do not re-propose what they reject.
- §15 items are open. Ask the owner when you reach one; never invent an answer.
- The owner's latest word overrides the handoff; update the handoff when that happens.

## How work proceeds

1. A module is specified in `docs/modules/{name}.md` (the nine sections in handoff §18)
   and reviewed by the owner **before** its first line of code.
2. Build order is handoff §17. Platform, then Access, then B2B; Feedback comes with Sales.
3. `composer check` must pass before a commit: config:clear → pint → phpstan → deptrac → pest.

## Layout

- Domain code lives in `src/`, never `app/`. `app/` is Laravel bootstrap only.
- `src/Modules/{Name}/{Public,Domain,Application,Infrastructure,Presentation}` — see
  `docs/STRUCTURE.md`. Other modules may import only `Public/`.
- `src/Shared/` is the kernel, ~20 classes hard ceiling.
- Every PHP file declares `strict_types` (Pint enforces it).
- Tests: `tests/Shared/{Unit,Integration,Feature}`, `tests/Modules/{Name}/{Unit,Integration,Feature}`,
  `tests/Architecture`. Integration and Feature tests run against PostgreSQL (`touchwood_test`),
  never SQLite.
- Each module owns a PostgreSQL schema (`platform.stores`). A new schema must be added to
  `search_path` in `config/database.php`, or `migrate:fresh` will not wipe it.
- Each module registers its own bindings, migrations, routes, views and translations in
  `Infrastructure/{Name}ServiceProvider.php`, listed in `bootstrap/providers.php`. Anything that
  depends on the acting user is bound `scoped`, never as a singleton.

## Stores

- Storefront routes: `Route::prefix('{store}/{locale}')->middleware('store')` — the store, then the
  language (`ar`/`en`); the middleware sets both, and generated links keep both. The `{store}` pattern is
  registered globally by Platform and already excludes reserved paths such as `/admin/...`, so
  modules never import Platform's interior to do this.
- A module that owns a top-level URL (`/webhooks`, `/feeds`…) reserves it with
  `ReservedPaths::reserve('module', 'segment')` in its provider's **`register()`**, never `boot()`:
  Platform builds the `{store}` pattern at boot and refuses later reservations.
- The current store: `StoreContext::current()` (Shared), then `PlatformApi::store($id)` for its
  details. Both are cached: a warm request reads only the cache table, never the store tables.
- Store-scoped models use `BelongsToStore`. Create their rows through the model — raw `insert()`
  and `upsert()` skip the store stamping and cross-store guards.
- Handlers invalidate cached data *inside* their transaction (it takes effect on commit, before
  after-commit events reach listeners).

## Code catches mistakes before the database (owner, 2026-09-18)

- Database CHECKs, unique indexes and foreign keys stay, but only as the last line of defence. Every
  rule they enforce is first checked in code (the domain object or handler), which refuses the value
  with a clear, translated error. The database should never receive a row it would refuse.
- A new constraint ships with its code-level rule and a test that the code refuses the value first.

## Cache, sessions and queues: PostgreSQL only (owner, 2026-09-18)

- **No Redis for now.** Sessions, cache and queues use the `database` drivers; Redis comes back only
  when real traffic needs it. Do not add Redis, Horizon or `predis` without the owner.
- Cached data must never be served stale. `VersionedCache` writes the new version *inside* the
  transaction of the change, because the cache table shares the connection — so cache and data
  commit or roll back together. Any new cache follows the same rule.
- Cache reads are database queries: never promise "zero queries". State what a warm request reads
  and test it against the real `database` cache store (tests use it, see `phpunit.xml`).
- Background jobs run from the `jobs` table: a worker must run (`php artisan queue:work`), and the
  scheduler (`php artisan schedule:work`) for scheduled tasks.
- If Redis is ever reintroduced, `VersionedCache` falls back to replacing the version after commit;
  first decide with the owner how a version write lost during a Redis outage is recovered.

## Interim until Access

- `ActorContext` and `Authorizer` are Platform bindings that allow only the system actor. Access
  replaces them — with `bind()`/`scoped()`, never `instance()`: Platform wraps the `ActorContext`
  binding so a queued job acts as the system on behalf of whoever queued it.

## Actors

- Actor types: staff, customer, guest, integration, system. Every actor id is a ULID.
- Check a person's permission when they start an action; the queued job then acts as the system,
  and the audit log records the requester (`requested_by_*`).
- Secrets (API keys, passwords, credentials) live only in server environment variables — never in a
  setting, a table or code. A setting whose value must not reach the audit log is declared with
  `sensitive: true`.
- Never pass an audit entry's source or date: Platform sets both. Old history goes only through
  `PlatformApi::recordImportedAudit`.
- Personal fields go into the audit log with `AuditChanges::personal()` (only "changed"). `changed()`
  refuses names like `email`, `phone`, `address`, `first_name`, but not a plain `name`: a person's
  name must be marked personal by its module.
- Nothing a caller sends becomes an id we record: the correlation id is always generated by us, and a
  caller's `X-Correlation-Id` is ignored.

## Tests

- Use the `Pest\Laravel\*` functions (`get()`, `artisan()`, `seed()`) rather than `$this->…`, so
  PHPStan can check the tests.
- A guard over files or modules must also assert it found something, so a moved directory cannot
  make it pass over nothing.

## Local environment (Windows)

- `docker compose up -d --wait` starts Postgres 17 on port 5433 (the only service; no Redis).
