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
come after the Geist foundation. This file grows with each step. **Steps 1 to 4 are built.**

---

## Where things are

| Folder | What is in it |
|---|---|
| `Application/CatalogPermissions.php` | The twenty permissions declared so far of catalog.md §3's twenty-one (`catalog.listing.fill` comes with the admins' store file, step 6), declared into Access's catalog at boot: seventeen jobs a role may hold, and three reserved to a Super Admin and the system |
| `Application/Command` | One folder per change: a command and its handler, which names its `PERMISSION` and authorizes first. Step 2: the six shared lists and each store's order of the menu; step 3: products and variants; step 4: each store's choice (below) |
| `Application/Lists` | What the lists' handlers share: `SharedListChange` (the permission with All stores, the transaction, the list's lock — after the products' for a change that changes products — the audit), `ProductFates` (each product's fate in a deactivation), the forms' parsing (`BrandInput`, `CategoryInput`, `AttributeInput`, `LabelInput`, `WarrantyInput`, `SetMembers`) and `CatalogImages` (a logo or photo must be a public image) |
| `Application/Products` | What the product handlers share: `ProductAccess` (who may change a product's shared data), `ProductReferences` (the list rows a product points at, row-locked), `ProductInput`, `VariantInput` and `ProductParts` (the forms' parsing), `Readiness` and `ReadyPhotos` (what a product needs to be shown) |
| `Application/Listing/StoreListingChange.php` | What a store's changes share: the job in that store, the transaction, the products' lock first, the audit in that store |
| `Application/Events/ProductEvents.php` | The nine events of catalog.md §6.1, sent from inside a change and delivered after it commits — for a product that has been ready |
| `Application/Audit/ListAudit.php` | Every list and product change's audit entry, by value: `catalog.{subject}.{what}` |
| `Domain/Model` | Brand, Category, Attribute, AttributeValue, AttributeSet, Label, Warranty, WordPair, Product, Variant, StoreListing (one store's choice of one product) — each keeps what one row can know; all but WordPair (added and deleted, never edited) keep a `ChangeLog` of what an edit changed |
| `Domain/ValueObject` | Names in both languages, slugs, the structured text of descriptions and terms, list positions, a label's look (`LabelTone`, the Badge's ten), a warranty's period; a product's name (Arabic required, English optional) and slugs, a code (`ProductCode`), a variant's combination, details and measures, search words; a product's quantity limits in a store (`SellingLimits`) |
| `Domain/Service/ArabicText.php` | Arabic as search compares it (handoff §5.2): marks off, alef and yeh forms folded, digits Latin, lower case |
| `Domain/Exception` | `CatalogError`, the fourteen refusals of step 2, the thirteen of step 3 and step 4's two (`NotChosenInStore`, `InvalidSellingTerms`), named in both languages in `lang/{ar,en}/errors.php` |
| `Domain/Repository` | The lists', the products', the variants' and the stores' rows' repositories, and `ListLocks` |
| `Infrastructure/Eloquent` | The repositories on the query builder; `SlugHistory`; `DatabaseListLocks`; Catalog's own `Ulids` (amendment 1(h)) |
| `Infrastructure/Media` | `CatalogImagesUsage`: brand logos and category photos as Platform media; `ProductPhotosUsage`: product and variant photos (below) |
| `Infrastructure/Persistence` | `CatalogSchema` (step 1) and the migrations: step 2's lists, step 3's products, variants and their parts, step 4's store rows — every rule one row can hold backed by a named CHECK, index or key |
| `Presentation/lang/{ar,en}` | The permissions' names, the errors, and the audit log's name for every action |
| `Public/Enums` | `AttributeKind`, `AgencyType`, `ProductStage`, `ProductFate` — and so their TypeScript types |
| `Public/Events` | `ProductMadeReady`, `ProductArchived`, `ProductRestored`, `ProductChanged`, `VariantAdded`, `VariantArchived`, `VariantRestored`, `VariantCodeCorrected`, `StoreListingChanged`: ids only |

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
step 3 `product.create`, `product.update`, `variant.correct_code`, `product.publish` and
`product.archive`; `product.view`'s reads come with the screens, as the lists' do; step 4 `listing.choose`, `listing.selling`, `listing.unavailable`
and `listing.labels`; step 5 `listing.rebuild` and `search_log.prune`; step 6 `import.run` and `listing.fill`.

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

**What the products changed (step 3).** A list item a product or variant uses is not deleted
(`BrandInUse`, `CategoryNotEmpty`, `ListItemInUse`); a category holding products takes no
sub-category and moves under none (`CategoryHoldsProducts`); a set that variants are built on keeps
its members (`AttributeSetInUse`); an attribute's job stays once variants carry details of it.
Each product's fate when its category or brand is deactivated arrived with step 4 (below).

**Photos as Platform media.** A brand's logo and a category's photo must be public images.
`CatalogImagesUsage` reports them to Platform as uses that never block a delete: deleting the file
leaves the brand without a logo and the category without a photo, for someone who may change that
list with All stores, under the list's lock, each audited (`logo_detached`, `image_detached`);
anyone else is refused and nothing changes.

**A store opened later** starts with no order of its menu: its admins set it (owner, amendment
2(b)), while it is still off if they like (amendment 4(f)). A category added after it opened gets its
place there as everywhere else.

### Products and variants (step 3)

**One change, one shape**, the lists' (`SharedListChange::run`), under the products' own lock,
`catalog:products`: a product's codes and slugs are decided across products under it. **No lock
cycle with the lists:** a product change reads the list rows it points at with a row lock
(`ProductReferences`), and a list's change locks the same row before it asks "is it in use?" but
never takes the products' lock — except the four that change products, which take it first (step 4,
below) — so a brand deleted while a product takes it is either seen gone, or sees the product. Where a change locks an attribute and one of its values, **the attribute comes
first**, on both sides. And a product change writes no row whose key would lock a row it has not
locked itself: archiving a variant writes the variant's row alone, and a photo that stays in a
gallery is moved, never written again — deleting its file holds the media row while it waits for
the products' lock.

**Who may.** `ProductAccess` asks for the permission in every store where the product is Active
(catalog.md §1.1): first in some store, before anything is read; then, inside the change once the
product's row is locked, in each store where any of its variants is switched on (step 4,
`authorizeFor`). A product Active nowhere needs it in some store only. Creating checks the creator's
working store, which must be on (amendment 3(j)); deleting a draft, making one ready and restoring
reach only products Active nowhere.

| Handler | Permission | What it keeps |
|---|---|---|
| `CreateProduct`, `EditProductDetails` | `product.create`, `product.update` | Arabic name required, English optional in a draft (amendment 3(g)); slugs made from the names, held while the product exists; what it newly points at is active, a category the lowest of its branch; the attribute set fixed once it has a variant |
| `AddVariant`, `UpdateVariant`, `DeleteDraftVariant` | `product.update` | One active value of every attribute of the set, in the set's order — a combination no other variant of the product has, archived ones included; details and measures; a code changed here only in a draft |
| `CorrectVariantCode` | `variant.correct_code` | Every variant of the product carrying the code takes the new one |
| `SetProductGallery`, `SetVariantPhotos`, `SetSearchWords`, `SetFilterValues`, `SetRelations` | `product.update` | Public images in order (20 and 10); search words (30, each once as search reads it); values of filter attributes (amendment 3(a)); related products that are ready, at most 20 (amendment 3(d)) |
| `MarkProductReady`, `ArchiveProduct`, `RestoreProduct` | `product.publish`, `product.archive` | Ready only with every rule met; restored to the stage it left (`archived_from`) — to ready only the same way (amendment 3(m)) |
| `ArchiveVariant`, `RestoreVariant` | `product.update` | A variant retired instead of deleted once the product is ready |
| `DeleteDraftProduct` | `product.archive` | Only a draft is deleted (amendment 3(h)) — whole, its slugs and codes free again |

**Codes** (amendment 3(e)). Digits only, 1 to 10, kept as text so a leading zero stays.
`product_codes` holds every code a product ever held, the code its key: a code is never given to
another product while its holder exists. Sizes of one product may share a code, as the owner's
sheet does. A code leaves a product only when a draft is deleted, or when a product never ready —
a draft, archived or not — gives it up (amendment 3(c), (m)); the
variants' key to `product_codes` is `NO ACTION`, so deleting a draft cascades through both without
the key refusing midway.

**Readiness** (`Readiness`, catalog.md §1.1). A product is shown only with its English name (and so
its English slug), the description in both languages, an active lowest category, a variant not
archived, and a gallery photo whose sizes are ready. A ready product keeps every rule: a change that
would take one away is refused, naming what it would leave missing (`ProductNotReady`) — except
that **a category deactivated after it was placed there stays** (amendment 3(m)). **An archived
product may be edited**, so it can be made whole, and is restored to the stage it left; while
archived it is not made ready, deleted, nor are its variants deleted (`ProductArchived`). The
database holds the category and the English name **while ready** (`products_category_when_ready`,
`products_english_when_ready`): a draft abandoned is archived as it is (amendment 3(l)).

**Product and variant photos.** `ProductPhotosUsage` reports them to Platform. Deleting a photo's
file takes it out of the gallery or the variant's photos, audited, and the product is
`ProductChanged` — **except the last ready photo of a ready product**, which blocks the delete
(amendment 3(b)). The blocking question is asked again under the products' lock, since the product
may have been made ready in between.

**Events** (catalog.md §6.1): ids only, implementing `ShouldDispatchAfterCommit`, so a change that
rolls back sends none. **Only for a product that has been ready** (`Product::hasBeenReady`,
amendment 3(m)): a draft, and a draft archived when abandoned, is Catalog's alone.

### Each store's choice (step 4)

**One store's rows of one product** (`StoreListing`, `store_products` / `store_variants` /
`store_product_labels`): which variants the store sells, how — retail, wholesale or both — and how
many to one order, "Not available now" on the product or a variant, and the labels it shows. Price
and stock are Pricing's and Inventory's (stage 5); nothing goes on sale before it has a price there
(amendment 4(c)).

**One change, one shape** (`StoreListingChange`): **the job in that store** before anything is read
— a store id that is not one is refused as the permission would be, a store that does not exist is
no store, **a store that is off is prepared** before it opens (amendment 4(e)); its own transaction
with **the products' lock first**, since a store's choice reads the product's stage and variants;
the audit in that store (`catalog.listing.*`).

| Handler | Permission | What it keeps |
|---|---|---|
| `ChooseInStore` | `listing.choose` | A whole product (its variants not archived) or single variants; only a ready product's are switched on; a variant first chosen sells retail only; switched off, its rows stay; a variant added later is chosen nowhere. Variants taken up are sent as `StoreListingChanged` |
| `SetSellingTerms` | `listing.selling` | A mode for every variant; each limit 1–100,000, a maximum never below its minimum, a wholesale minimum while any variant sells wholesale |
| `MarkNotAvailableNow`, `ClearNotAvailableNow` | `listing.unavailable` | On the whole product, later variants too, or one variant; in that store only |
| `AttachLabels` | `listing.labels` | At most ten, each once; a label newly attached active, one held may stay deactivated; shown in the list's order |

Selling terms, labels and "Not available now" need a product the store has chosen
(`NotChosenInStore`). **Archiving a product, or a variant, switches it off in every store**, each
store's change audited there; restoring leaves it off. A label a store shows is not deleted. The
admins' file that fills a store with codes, and its "needs completion" list, come in step 6.

**Deactivating a category or a brand** gives **every product it reaches, in any stage**, its fate
(`ProductFates`): its own choice or the one for all — **hide** (`hidden_by_category`,
`hidden_by_brand`), **leave** (categories only), or **move** to an active lowest category outside
what goes, or an active brand. A product with no choice, or a choice for a product not reached,
refuses the whole step. Products under a sub-category switched off before are asked again
(amendment 4(g)): "leave" now brings one hidden then back to unlisted but reachable.
Activating brings back what hid with it, except under a sub-category still off; a hidden product
that moves to another category or brand is no longer hidden. **These four changes take the
products' lock before their list's** (`SharedListChange::runAfterProducts`): a product change holds
the products' lock before it row-locks a category or brand, so the two never wait in a circle;
deleting a photo's file takes them in the same order (`CatalogImagesUsage`).

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
| `Integration/CatalogProductsTest` | Creating and editing a product: who may, the working store on, names and slugs, what it points at, the set fixed once it has a variant, deleting a draft, the database's CHECKs |
| `Integration/CatalogVariantsTest` | Combinations, details and measures; codes held, shared by sizes, taken, freed by a draft and corrected on every variant carrying them |
| `Integration/CatalogListsInUseTest` | A list item a product or variant uses: not deleted, a category's sub-categories, a set's members, an attribute's job |
| `Integration/CatalogProductPartsTest` | Gallery and variant photos, search words, filter values, relations; a photo's file deleted from the media library |
| `Integration/CatalogProductStagesTest` | Making ready, archiving and restoring a product and a variant; a ready product keeping every rule; each change's own job; the events; what the audit log keeps |
| `Integration/CatalogProductConstraintsTest` | The products' named CHECKs, indexes and keys that no handler test reaches, each refusing a row written past the code |
| `Integration/CatalogStoreListingsTest` | Who may choose in which store, an off store prepared; a whole product or single variants; selling terms; "Not available now"; labels; archiving switching off everywhere; a product's shared data needing the job in every store that sells it |
| `Integration/CatalogDeactivationsTest` | A category or brand deactivated with each product's fate, all or nothing; activating bringing back what hid with it |
| `Integration/CatalogListGuardsTest` | For every one of the sixty-two list, product and store changes: its lock is the first query inside its own transaction (the products' before its list's, for the four that change products); an id not in its list is answered as not found; a change that changes nothing writes nothing and records nothing; and what the audit log keeps reads from what was to what is |
| `Integration/CatalogListConstraintsTest` | The database's named CHECKs, indexes and keys that no handler test reaches, each refusing a row written past the code; an id that is not a ULID never reaching the database |
| `Unit/CatalogListLocksTest` | A list's lock is refused outside a transaction |
| `Unit/CatalogProductTest` | A product archived remembers the stage it left — archived twice or not — and restoring goes back there |
| `Integration/CatalogPermissionsTest`, `CatalogSchemaTest` | Step 1's permissions and schema |
| `tests/Architecture/CatalogAccessUseTest.php` | Catalog references nothing of Access beyond the five permission-declaration classes |

`CatalogListGuardsTest` records every query to check each change takes its lock first, inside its
own transaction (level 2 under `RefreshDatabase`); `CatalogFixtures::lockedTables` reads from the
same record which rows a change locked, in order. `Support/CatalogProducts` makes the products,
variants and the lists they use, as the system. Run everything with `composer check`.
The test database is `touchwood_test`.
