# TouchWood Platform

Multi-store retail and wholesale commerce platform for kitchen and wardrobe hardware.
Saudi Arabia, Egypt and the UAE from one codebase and one database.

Laravel 13 · PHP 8.4 · PostgreSQL 17 · Redis · modular monolith, 15 modules.

- **Source of truth:** [`docs/HANDOFF.md`](docs/HANDOFF.md)
- Architecture and boundaries: [`docs/STRUCTURE.md`](docs/STRUCTURE.md)
- Decision records: [`docs/architecture/adr/`](docs/architecture/adr/)
- Module specifications: [`docs/modules/`](docs/modules/)

## Rules that are not negotiable

1. A module imports only `Modules/{Other}/Public/**` and `Shared/**`. Deptrac blocks CI.
2. No country name, currency code, country code or store code in `Domain/` or `Application/`.
3. Money is `(bigint minor_units, currency_code)`. Never DECIMAL, never float.
4. The frontend is never trusted for any amount. Checkout posts a quote id.
5. Out of stock is never a boolean. It is always a stock movement.
6. Never pass an Eloquent model across a module boundary. DTOs only.
7. A feature is not finished until its tests are written.

## Local setup

Requires PHP 8.4 (extensions: intl, mbstring, pdo_pgsql, pgsql), Composer 2 and Docker.

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d --wait
php artisan migrate
```

Postgres is published on port **5433** so it does not collide with a locally installed
PostgreSQL. The `touchwood_test` database is created on the container's first start.

## Quality gates

```bash
composer check
```

Runs, in order: Pint → PHPStan (Larastan level 8) → Deptrac (blocking) → Pest. CI runs the
same four steps.
