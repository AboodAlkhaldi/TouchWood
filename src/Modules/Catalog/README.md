# Catalog module

**Build stage 4. Depends on Platform, and on Access's public surface to declare its permissions**
(handoff §4.4, owner 2026-10-02). Pricing, Inventory, Promotions, Sync, Shipping, Sales, Feedback
and Content will read it.

Catalog owns **what is sold, and where**: products and their variants, the codes that name them, the
category tree, brands, attributes, labels, warranties, each store's choice of what it sells, search,
and the JSON import. It owns nothing about how many (Inventory) or for how much (Pricing). The rules
are in the specification, [docs/modules/catalog.md](../../../docs/modules/catalog.md); this file says
how the code is organised and why.

**Being built** (from 2026-10-02), backend first, in seven steps: 1 foundation · 2 the shared lists
· 3 products and variants · 4 each store's choice · 5 listing, search and the public contract ·
6 the JSON import · 7 the module's own pass. The admin and storefront screens, with their endpoints,
come after the Geist foundation. This file grows with each step. **Steps 1 and 2 are built.**

---

## Where things are

| Folder | What is in it |
|---|---|
| `Application/CatalogPermissions.php` | The twenty permissions of catalog.md §3, declared into Access's catalog at boot: seventeen jobs a role may hold, and three reserved to a Super Admin and the system |
| `Application/Command` | One folder per change: a command and its handler, which names its `PERMISSION` and authorizes first. Step 2: the six shared lists and each store's order of the menu (below) |
| `Application/Lists` | What the lists' handlers share: `SharedListChange` (the permission with All stores, the transaction, the list's lock, the audit), the forms' parsing (`BrandInput`, `CategoryInput`, `AttributeInput`, `LabelInput`, `WarrantyInput`, `SetMembers`) and `CatalogImages` (a logo or photo must be a public image) |
| `Application/Audit/ListAudit.php` | Every list change's audit entry, by value: `catalog.{list}.{what}` |
| `Domain/Model` | Brand, Category, Attribute, AttributeValue, AttributeSet, Label, Warranty, WordPair — each keeps what one row can know; all but WordPair (added and deleted, never edited) keep a `ChangeLog` of what an edit changed |
| `Domain/ValueObject` | Names in both languages, slugs, the structured text of descriptions and terms, list positions, a label's look (`LabelTone`, the Badge's ten), a warranty's period |
| `Domain/Service/ArabicText.php` | Arabic as search compares it (handoff §5.2): marks off, alef and yeh forms folded, digits Latin, lower case |
| `Domain/Exception` | `CatalogError` and the fourteen refusals of step 2, named in both languages in `lang/{ar,en}/errors.php` |
| `Domain/Repository` | The lists' repositories and `ListLocks` |
| `Infrastructure/Eloquent` | The repositories on the query builder; `SlugHistory`; `DatabaseListLocks`; Catalog's own `Ulids` (amendment 1(h)) |
| `Infrastructure/Media/CatalogImagesUsage.php` | Brand logos and category photos as Platform media (below) |
| `Infrastructure/Persistence` | `CatalogSchema` (step 1) and the migrations: step 2's lists, every rule one row can hold backed by a named CHECK, index or key |
| `Presentation/lang/{ar,en}` | The permissions' names, the errors, and the audit log's name for every action |
| `Public/Enums` | `AttributeKind`, `AgencyType` — and so their TypeScript types |

## How it is built

**The same shape as Access and B2B.** Every change goes through a command handler that authorizes
first, works inside one transaction and audits what it changed; repositories write with the query
builder; ids are lower-case ULIDs, and anything that is not one is "not found" without a query. Every
rule is in code first; a rule one row can hold is in the database behind it (handoff §5.3). Rules that
read other rows or another table — a category's loops, a swatch only on a colour attribute's values,
a set's members, a job locked by values, "at least one default" — are the code's alone, under the
list's lock.

**One permission per job, none admin-only** (catalog.md §3). A store's own row — its choice, selling
terms, "Not available now", labels, ranks — is checked in that store. A product's shared data is
checked in **every store where the product is Active**. A shared list — the tree, the brands, the
attributes, the labels, the warranties, the word pairs — reaches every store, so its job is checked
with `PermissionScope::allStores()`: only a Super Admin or someone given the job with All stores
changes it. `CatalogPermissions::sharedLists()` names those six.

**Which step brings which handler:** step 2 the six shared-list jobs and `catalog.category.rank`;
step 3 `product.create`, `product.update`, `variant.correct_code`, `product.publish`,
`product.archive`, `product.view`; step 4 `listing.choose`, `listing.selling`, `listing.unavailable`
and `listing.labels`; step 5 `listing.rebuild` and `search_log.prune`; step 6 `import.run`.

### The shared lists (step 2)

**One change, one shape** (`SharedListChange`): the permission with All stores before anything is
read; its own transaction, retried on a deadlock, which reads again whatever it changes; **the list's
lock first** — one transaction-scoped advisory lock per list (`catalog:brands`, `catalog:categories`,
`catalog:attributes` for attributes, values and sets together, `catalog:labels`,
`catalog:warranties`, `catalog:word_pairs`) — so every question about the other rows ("is this slug
free?", "does it have values?") is answered under it; and the audit entries inside the same
transaction, none when nothing changed.

| List | What the code keeps |
|---|---|
| Brands | Two slugs, one per language, global — Arabic letters and digits for `ar`, `a-z` and digits for `en` (§5.3) — each kept in the history table while the brand exists, so a slug it once held is never given to another; deleting the brand frees them (owner, amendment 2(a)); the redirect itself arrives with the storefront, step 5; **exactly one default** — the first brand becomes it, moving it un-marks the old one in the same step, the default is never deactivated or deleted; an optional two-letter origin country (amendment 1(j)); a description in both languages or neither. The seed adds TouchWood «تاتش وود» only |
| Categories | One tree, nesting without limit, never under itself or below itself; a new or moved category goes under an **active** parent only, and **its place among its siblings is chosen by whoever adds or moves it** and written into every store, on or off (amendment 1(d)); each store's admins reorder their own menu with `RankCategories` (`catalog.category.rank` in that store). Deactivating takes every active category below it, each remembering it went with its parent, so activating brings back exactly that; a category under a deactivated parent cannot be activated. Only an empty category is deleted |
| Attributes | A job — details only, filter, or variant-making — that changes, with being a colour, **only while the attribute has no values** (amendment 1(i)); an attribute a set holds stays variant-making. Values: never two alike in either language ignoring case; a colour attribute's values need a `#rrggbb` swatch, no other's take one; an attribute is deleted only after its values (RESTRICT, §5.3), which its handler deletes and audits one by one. Sets: one to ten variant-making attributes in order; a member deactivated later may stay, so the set can still be renamed |
| Labels | «الشارات»: one or two words in each language, at most 30 characters, and one of the Badge's ten looks, chosen by meaning (amendment 1(e), (f)) |
| Warranties | Name and formatted terms in both languages, 1–600 months or for life |
| Word pairs | Kept as search compares words, in byte order, once whichever way they were typed; added and deleted, never edited. The ordering CHECK compares with `COLLATE "C"`: the database's language order ignores spaces and hyphens and would refuse pairs the code accepts |

**What waits for the products.** "Holds a product" (deleting a category, a brand, a warranty, a
value), "a category with products takes no sub-category", and each product's fate when its category
or brand is deactivated join with the products in steps 3 and 4; each handler's comment says which.

**Photos as Platform media.** A brand's logo and a category's photo must be public images.
`CatalogImagesUsage` reports them to Platform as uses that never block a delete: deleting the file
leaves the brand without a logo and the category without a photo, for someone who may change that
list with All stores, under the list's lock, each audited (`logo_detached`, `image_detached`);
anyone else is refused and nothing changes.

**A store opened later** starts with no order of its menu: its admins set it (owner, amendment
2(b)). A category added after it opened gets its place there as everywhere else.

**The schema.** `catalog`, on `config/database.php`'s search path so `migrate:fresh` wipes it; the
first migration also creates `pg_trgm`, which the search's nearness ranking needs (catalog.md
§1.11), pinned to the `public` schema so no module's rollback can take it. **It needs a privilege the
other modules did not:** the application's database user must own the database (as `touchwood` does
locally and in CI), or a superuser creates the extension once beforehand. The PHP `intl` extension
is required (`composer.json`): slugs fold accented Latin letters with it.

**What Catalog takes from Access** is five classes — the catalog contract, the definition DTO and
its three enums — and `tests/Architecture/CatalogAccessUseTest.php` holds it to that. Catalog's own
public surface never references Access.

## Tests

| File | What it covers |
|---|---|
| `Unit/CatalogValuesTest` | Text on one line, names, slugs (Arabic and accented Latin), structured text, Arabic normalisation, a label's words and looks, word pairs, warranty periods |
| `Unit/CatalogErrorsTest` | Every error has a unique `catalog.*` type, a category, and a title and detail in both languages |
| `Integration/CatalogBrandsTest` | The seed; who may; slugs and their history; the one default; deactivating and deleting; the database's CHECKs |
| `Integration/CatalogCategoriesTest` | Who may change the tree and who a store's order; places chosen by the adder in every store, an off store included; moving and loops; deactivating and activating exactly what went; deleting; a store opened later starting with no order; the database's CHECKs |
| `Integration/CatalogAttributesTest` | Jobs locked by values and by sets; values alike ignoring case; swatches; sets' members; the database's CHECKs |
| `Integration/CatalogSmallListsTest` | Labels, warranties and word pairs, including a pair the database's language order would sort the other way |
| `Integration/CatalogImagesUsageTest` | Deleting a logo or photo's file: detached and audited, or refused with nothing changed |
| `Integration/CatalogAuditNamesTest` | Every action the code records is named in both languages, and nothing else is |
| `Integration/CatalogListGuardsTest` | For every one of the forty list changes: its list's lock is the first query inside its own transaction; an id not in its list is answered as not found; a change that changes nothing writes nothing to the lists and records nothing; and what the audit log keeps reads from what was to what is |
| `Integration/CatalogListConstraintsTest` | The database's named CHECKs, indexes and keys that no handler test reaches, each refusing a row written past the code; an id that is not a ULID never reaching the database |
| `Unit/CatalogListLocksTest` | A list's lock is refused outside a transaction |
| `Integration/CatalogPermissionsTest`, `CatalogSchemaTest` | Step 1's permissions and schema |
| `tests/Architecture/CatalogAccessUseTest.php` | Catalog references nothing of Access beyond the five permission-declaration classes |

`CatalogListGuardsTest` records every query to check each change takes its list's lock first, inside
its own transaction (level 2 under `RefreshDatabase`). Run everything with `composer check`.
The test database is `touchwood_test`.
