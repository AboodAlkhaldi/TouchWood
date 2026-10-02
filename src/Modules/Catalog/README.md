# Catalog module

**Build stage 4. Depends on Platform, and on Access's public surface to declare its permissions**
(handoff §4.4, owner 2026-10-02). Pricing, Inventory, Sync, Shipping, Sales, Feedback and Content
will read it.

Catalog owns **what is sold, and where**: products and their variants, the codes that name them, the
category tree, brands, attributes, labels, warranties, each store's choice of what it sells, search,
and the JSON import. It owns nothing about how many (Inventory) or for how much (Pricing). The rules
are in the specification, [docs/modules/catalog.md](../../../docs/modules/catalog.md); this file says
how the code is organised and why.

**Being built** (from 2026-10-02), backend first, in seven steps: 1 foundation · 2 the shared lists
· 3 products and variants · 4 each store's choice · 5 listing, search and the public contract ·
6 the JSON import · 7 the module's own pass. The admin and storefront screens, with their endpoints,
come after the Geist foundation. This file grows with each step.

---

## Where things are

| Folder | What is in it |
|---|---|
| `Application/CatalogPermissions.php` | The twenty permissions of catalog.md §3, declared into Access's catalog at boot: seventeen jobs a role may hold, and three reserved to a Super Admin and the system. Their handlers arrive with the steps below |
| `Domain/Exception` | `CatalogError`, the base of every error here (step 1); one class per refusal from step 2 |
| `Infrastructure/CatalogServiceProvider.php` | Migrations, the `catalog::` translations, the permission declarations |
| `Infrastructure/Persistence/Migrations` | The `catalog` schema and the `pg_trgm` extension (step 1) |
| `Presentation/lang/{ar,en}` | The permissions' names (step 1) |

## How it is built

**The same shape as Access and B2B.** Every change goes through a command handler that authorizes
first, works inside one transaction and audits what it changed; repositories write with the query
builder; ids are lower-case ULIDs. Every rule is in code first and in the database behind it
(handoff §5.3).

**One permission per job, none admin-only** (catalog.md §3). A store's own row — its choice, selling
terms, "Not available now", labels, ranks — is checked in that store. A product's shared data is
checked in **every store where the product is Active** (`PermissionScope::store()` for each; a product
Active nowhere takes any holder). A shared list — the tree, the brands, the attributes, the labels,
the warranties, the word pairs — reaches every store, so its job is checked with
`PermissionScope::allStores()`: only a Super Admin or someone given the job with All stores changes
it. `CatalogPermissions::sharedLists()` names those six. The import and the two system jobs are
reserved.

**Which step brings which handler:** step 2 the six shared-list jobs and `catalog.category.rank`;
step 3 `product.create`, `product.update`, `variant.correct_code`, `product.publish`,
`product.archive`, `product.view`; step 4 the four `listing.*` jobs; step 5 `listing.rebuild` and
`search_log.prune`; step 6 `import.run`.

**The schema.** `catalog`, on `config/database.php`'s search path so `migrate:fresh` wipes it; the
first migration also creates `pg_trgm`, which the search's nearness ranking needs (catalog.md
§1.11). The extension belongs to the database and stays when the schema is rolled back.

## Tests

| Folder | What it covers |
|---|---|
| `tests/Modules/Catalog/Integration` | `CatalogPermissionsTest`: exactly the spec's permissions, their kinds, groups and reservations, none admin-only. `CatalogSchemaTest`: the schema on the search path, `pg_trgm` reachable |

Run everything with `composer check`. This worktree's test database is `touchwood_test_catalog`.
