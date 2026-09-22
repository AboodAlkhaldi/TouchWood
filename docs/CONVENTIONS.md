# TouchWood — working conventions

`docs/HANDOFF.md` is the source of truth. Read it before changing anything.

- §2 rules and the §16 rejection table are decided. Do not re-propose what they reject.
- §15 items are open. Ask the owner when you reach one; never invent an answer.
- The owner's latest word overrides the handoff; update the handoff when that happens.

## How work proceeds

1. A module is specified in `docs/modules/{name}.md` (the nine sections in handoff §18)
   and reviewed by the owner **before** its first line of code.
2. Build order is handoff §17. Platform, then Access, then the frontend foundation, then B2B; Feedback
   comes with Sales.
3. `composer check` must pass before a commit: config:clear → pint → phpstan → deptrac → pest.

## How a step is done here

Settled with the owner on 2026-09-22, after Access. The spec is agreed **whole, first**; the steps
are cut from it; each step is then built without re-opening decisions. Questions during a build are
allowed but rare, and batched.

**Before any code — the specification.**

1. Read the merged code, not the older spec. A spec written against what a module was supposed to be
   is wrong by the time the module is merged. Record what actually changed as a numbered list at the
   top of the phase spec.
2. Write the phase spec to `docs/modules/{phase}.md`, in the nine sections of handoff §18.
3. Every open question goes to the owner **before** the spec is finished, in batches, each with its
   options and **what each option costs later** — never a bare question. An answer that is not clear
   enough to build on is asked again; a guess is never built on.
4. Write the owner's answers into the spec in their own words, dated. The spec, not the chat, is what
   the next person reads.
5. The owner approves the spec, then the step list cut from it. Nothing is built before both.

**Each step.**

6. A branch per step off the phase branch; the phase branch off `main`. A step is one PR.
7. Build the whole step: code, tests, translations in both languages, and the doc updates the step
   makes true.
8. `composer check` must pass — all of it, read, not skimmed. A run that "passes" in seconds passed
   over nothing: check the tool actually ran.
9. **An independent review of the step**, reading the diff against the spec, with no memory of having
   written it. Every finding is then **verified in the code before it is acted on** — a review that
   is wrong about the code is common, and fixing what is not broken is worse than the finding.
10. **A mutation run** over what the step changed: break one line at a time, run the tests that cover
    it, put it back. A mutant that survives means the tests do not hold that line — fix the tests, or
    write down why the line cannot be tested. Equivalent mutants (a line that cannot change
    behaviour) are a signal the line is dead: delete it.
11. The owner is told what the step did, what the review found, and what the mutation run showed.
    **The owner's go is needed to merge.**

**What is written down, always.**

- A decision the owner took, in the spec, dated and in their words.
- An assumption, marked `My assumption, stated for the owner to reject:`.
- Something a guard **cannot** prove, said plainly where the guard is — a claim that overstates a
  check is worse than no check, because the next person trusts it.
- Anything left open, in the step's doc under **Left open**, with who it waits on.

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
- A module that stores media ids registers a `MediaUsage` with `MediaUsages` in its provider: it reports
  each use as detachable (a product photo) or blocking (a legal document), and its `detach()` checks
  the person's own permission for that change and audits it. Every media id sits in its own column or
  a link table with a `RESTRICT` foreign key to `platform.media` — never inside JSON (owner, 2026-09-18).

## Code catches mistakes before the database (owner, 2026-09-18)

- Database CHECKs, unique indexes and foreign keys stay, but only as the last line of defence. Every
  rule they enforce is first checked in code (the domain object or handler), which refuses the value
  with a clear, translated error. The database should never receive a row it would refuse.
- A new constraint ships with its code-level rule and a test that the code refuses the value first.

## Cache, sessions and queues: PostgreSQL only (owner, 2026-09-18)

- **No Redis for now.** Sessions, cache and queues use the `database` drivers; Redis comes back only
  when real traffic needs it. Do not add Redis, Horizon or `predis` without the owner.
- Cached data must never be served stale. `VersionedCache` (Shared kernel, `Shared\Infrastructure\Cache`) writes the new version *inside* the
  transaction of the change, because the cache table shares the connection — so cache and data
  commit or roll back together. Any new cache follows the same rule.
- Cache reads are database queries: never promise "zero queries". State what a warm request reads
  and test it against the real `database` cache store (tests use it, see `phpunit.xml`).
- Background jobs run from the `jobs` table: a worker must run (`php artisan queue:work`), and the
  scheduler (`php artisan schedule:work`) for scheduled tasks.
- Schedule work with `$schedule->job(...)`, never `command()` or `call()`: scheduled work is queued as
  a job, so its audit source is JOB (owner, 2026-09-18).
- If Redis is ever reintroduced, `VersionedCache` falls back to replacing the version after commit;
  first decide with the owner how a version write lost during a Redis outage is recovered.

## Permissions

- Every permission a module checks is declared once, in its provider's `boot()`, through Access's
  `PermissionCatalog` — `{module}.{resource}.{action}`, with its audience and whether it is reserved —
  and named in Arabic and English in the module's translations at `{module}::permissions`. A test
  fails if a handler checks an undeclared permission or a permission has no name in either language.
  Platform, below Access, publishes its list in `PlatformPermissions` instead.
- Each permission is **per store** or **store-free** (`PermissionKind::Global`: media, roles — nothing
  that belongs to one store) (owner, 2026-09-19). Check a store-free one with `PermissionScope::global()`
  and a per-store one with `store()` or `allStores()`; any other check throws. "Every store"
  (`allStores()`) passes only with the All stores choice. A setting's permission is a per-store one.
- A permission renamed or removed in a later version is declared with `renamed()` / `removed()`; every
  `php artisan migrate` carries it into the roles (owner, 2026-09-19). Never just delete a declaration.
- Management actions (inviting, editing and disabling staff, assigning roles, managing roles) go only
  into admin roles; only a Super Admin manages admins; an admin manages a staff member only when
  covering all of their stores (owner, 2026-09-19).

## Who acts (Access, from step 3b)

- The `Authorizer` is Access's `RoleAuthorizer`; the `ActorContext` is Access's `RequestActorContext`.
  A web request acts as the staff member signed in, else as a guest — **never as the system**;
  outside a web request (console, queue worker) it is the system. Platform wraps the binding so a
  queued job acts as the system on behalf of whoever queued it: bind with `bind()`/`scoped()`, never
  `instance()`.
- Admin panel routes live under `/admin` with the middleware
  `[UseAdminSession::ALIAS, 'web', IdentifyStaff::ALIAS]` — the admin session cookie is set before
  `web` starts the session — and `RequireStaff::ALIAS` on what needs someone signed in. Access's
  `Presentation/routes.php` shows the pattern.
- Tests that need a session across requests set `session.driver` to `database` (phpunit.xml's
  `array` keeps nothing between requests). A test that binds an actor (`Fx::actAs*`) makes later
  HTTP requests act as that actor too; `Fx::asSystem()` puts the previous binding back.
- Run one test process at a time: two runs against `touchwood_test` at once break each other's
  migrations and transactions.

## Actors

- Actor types: staff, customer, guest, integration, system. Every actor id is a ULID, and an id is
  never a secret: whatever proves a guest's cart is theirs is kept apart from the guest id.
- Check a person's permission when they start an action; the queued job then acts as the system,
  and the audit log records the requester (`requested_by_*`). A job therefore may finish work its
  requester could not have started — generating image variants is reserved to Super Admins, and
  every upload queues it.
- **But scope still follows the requester** (owner, 2026-09-22). `Authorizer::storesWith()` and
  `isUnlimited()` answer for whoever queued the job, not for the system, because they decide which
  **rows** are shown. A report queued by someone who works in one store lists that store. Only
  `authorize()` — "may this proceed" — answers for the system.
- Secrets (API keys, passwords, credentials) live only in server environment variables — never in a
  setting, a table or code. A setting whose value must not reach the audit log is declared with
  `sensitive: true`.
- A link or code that proves who someone is is stored only as a hash, and the message carrying it
  is sent after the commit, never through the queue (a queued job keeps its payload in the `jobs`
  table). Access's `SecurityMessages` sends them until Ops binds its own.
- Never pass an audit entry's source or date: Platform sets both. Old history goes only through
  `PlatformApi::recordImportedAudit`.
- Personal fields go into the audit log with `AuditChanges::personal()` (only "changed"). `changed()`
  refuses names like `email`, `phone`, `address`, `first_name` (camelCase too), but not a plain `name`,
  `city` or `postal_code`: a person's name and a customer's city and postal code must be marked
  personal by their module.
- Nothing a caller sends becomes an id we record: the correlation id is always generated by us, and a
  caller's `X-Correlation-Id` is ignored.

## Tests

- Use the `Pest\Laravel\*` functions (`get()`, `artisan()`, `seed()`) rather than `$this->…`, so
  PHPStan can check the tests.
- A guard over files or modules must also assert it found something, so a moved directory cannot
  make it pass over nothing.
- To prove the code refuses a value before a unique index does, drop the index inside the test (its
  transaction rolls the drop back): a repository that maps the index's error to the same domain
  error would otherwise pass the test with the code check deleted.
- Functions and constants declared in a Pest file are global to the whole suite: name them after
  the file's subject, or two files declaring the same name stop every test run.

## Local environment (Windows)

- `docker compose up -d --wait` starts Postgres 17 on port 5433 (the only service; no Redis).
