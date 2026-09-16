# TouchWood Platform

`docs/HANDOFF.md` is the source of truth. Read it before changing anything.

- §2 rules and the §16 rejection table are decided. Do not re-propose what they reject.
- §15 items are open. Ask the owner when you reach one; never invent an answer.
- The owner's latest word overrides the handoff; update the handoff when that happens.

## How work proceeds

1. A module is specified in `docs/modules/{name}.md` (the nine sections in handoff §18)
   and reviewed by the owner **before** its first line of code.
2. Build order is handoff §17. Platform, then Access, then B2B, then Feedback.
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

- Storefront routes: `Route::prefix('{store}')->middleware('store')`. The `{store}` pattern is
  registered globally by Platform and already excludes reserved paths such as `/admin/...`, so
  modules never import Platform's interior to do this.
- The current store: `StoreContext::current()` (Shared), then `PlatformApi::store($id)` for its
  details. Both are cached; resolving a store costs no queries.
- Store-scoped models use `BelongsToStore`. Create their rows through the model — raw `insert()`
  and `upsert()` skip the store stamping and cross-store guards.
- Handlers invalidate cached data *inside* their transaction (it takes effect on commit, before
  after-commit events reach listeners).

## Interim until Access

- `ActorContext` and `Authorizer` are Platform bindings that allow only the system actor. Access
  replaces them.

## Tests

- Use the `Pest\Laravel\*` functions (`get()`, `artisan()`, `seed()`) rather than `$this->…`, so
  PHPStan can check the tests.
- A guard over files or modules must also assert it found something, so a moved directory cannot
  make it pass over nothing.

## Local environment (Windows)

- `docker compose up -d --wait` starts Postgres 17 on port 5433 and Redis on 6379.
- Redis client is `predis` (no phpredis on Windows).
