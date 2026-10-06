# Catalog module

**Build stage 4. Depends on Platform, and on Access's public surface to declare its permissions**
(handoff §4.4, owner 2026-10-02). Pricing, Inventory, Promotions, Sync, Shipping, Sales, Feedback
and Content will read it.

Catalog owns **what is sold, and where**: products and their variants, the codes that name them, the
category tree, brands, attributes, labels, warranties, each store's choice of what it sells, search,
and the JSON import. It owns nothing about how many (Inventory) or for how much (Pricing). The rules
are in the specification, [docs/modules/catalog.md](../../../docs/modules/catalog.md); this file says
how the code is organised and why. **The import's file format** — a guide and a complete example of
each file, which the owner fills real files from — is in
[docs/modules/catalog-import/](../../../docs/modules/catalog-import/README.md): the import reads exactly
that, so a change to it changes the guide and both examples too.

**Being built** (from 2026-10-02), backend first, in seven steps: 1 foundation · 2 the shared lists
· 3 products and variants · 4 each store's choice · 5 listing, search and the public contract ·
6 the JSON import · 7 the module's own pass. The admin and storefront screens, with their endpoints,
come after the Geist foundation. This file grows with each step. **Steps 1 to 6 are built.**

---

## Where things are

| Folder | What is in it |
|---|---|
| `Application/CatalogPermissions.php` | The twenty-one permissions of catalog.md §3, declared into Access's catalog at boot: eighteen jobs a role may hold — `catalog.listing.fill`, the store file, only an admin role (amendment 6(h), declared `adminOnly`) — and three reserved to a Super Admin and the system |
| `Application/Command` | One folder per change: a command and its handler, which names its `PERMISSION` and authorizes first. Step 2: the six shared lists and each store's order of the menu; step 3: products and variants; step 4: each store's choice (below) |
| `Application/Lists` | What the lists' handlers share: `SharedListChange` (the permission with All stores, the transaction, the list's lock — after the products' for a change that changes products — the audit), `ProductFates` (each product's fate in a deactivation), the forms' parsing (`BrandInput`, `CategoryInput`, `AttributeInput`, `LabelInput`, `WarrantyInput`, `SetMembers`) and `CatalogImages` (a logo or photo must be a public image) |
| `Application/Products` | What the product handlers share: `ProductAccess` (who may change a product's shared data), `ProductReferences` (the list rows a product points at, row-locked), `ProductInput`, `VariantInput` and `ProductParts` (the forms' parsing), `Readiness` and `ReadyPhotos` (what a product needs to be shown) |
| `Application/Listing` | `StoreListingChange` — what a store's changes share: the job in that store, the transaction, the products' lock first, the audit in that store; `ListingRows` — the listing's writer, called inside every change that alters a row (step 5, below); `CardPhotoReady` — a photo's sizes ready, its products' rows written again |
| `Application/Import` | The import and the store file (step 6, below): reading the files (`ProductsFile`, `StoreFillFile`, `DescriptionText`, `FileProblems`), checking one against the catalog (`CatalogNames`, `CatalogCheck`), the zip (`ImportArchives`), the import's rows (`Imports`), the changes before bringing in (`ImportedProductsChange`), bringing in (`ImportReferences`, `ImportBringer`, `ImportPhotos`), the steps after it (`BroughtInProducts`) and the store file's (`StoreFills`) |
| `Application/Query/ViewImport`, `ListImports`, `ViewStoreFill`, `ListStoreFills` | The import's and the store file's pages and lists (step 6) |
| `Application/Query/Shop` | What a shopper reads (step 5): `ShopCatalog` (the menu, a category's, a brand's and a product's page, suggestions), `ShopSearch`, `ShopReader` (the reads, as an interface), `Cursor` (a page's keyset), and the cards and pages they answer — never a code |
| `Application/Search` | `SearchTerms` (what was typed, as search reads it, widened by word pairs) and `SearchLog` |
| `Application/CatalogApiImpl.php` | `Public/Contracts/CatalogApi`, as plain reads |
| `Application/Events/ProductEvents.php` | The nine events of catalog.md §6.1, sent from inside a change and delivered after it commits — for a product that has been ready |
| `Application/Audit/ListAudit.php` | Every list and product change's audit entry, by value: `catalog.{subject}.{what}` |
| `Domain/Model` | Brand, Category, Attribute, AttributeValue, AttributeSet, Label, Warranty, WordPair, Product, Variant, StoreListing (one store's choice of one product) — each keeps what one row can know; all but WordPair (added and deleted, never edited) keep a `ChangeLog` of what an edit changed |
| `Domain/ValueObject` | Names in both languages, slugs, the structured text of descriptions and terms, list positions, a label's look (`LabelTone`, the Badge's ten), a warranty's period; a product's name (Arabic required, English optional) and slugs, a code (`ProductCode`), a variant's combination, details and measures, search words; a product's quantity limits in a store (`SellingLimits`) |
| `Domain/Service/ArabicText.php` | Arabic as search compares it (handoff §5.2): marks off, alef and yeh forms folded, digits Latin, lower case |
| `Domain/Exception` | `CatalogError`, the fourteen refusals of step 2, the thirteen of step 3, step 4's two (`NotChosenInStore`, `InvalidSellingTerms`) and step 6's three (`ImportRefused`, `ImportUndecided`, `ImportClosed`), named in both languages in `lang/{ar,en}/errors.php` |
| `Domain/Repository` | The lists', the products', the variants' and the stores' rows' repositories, and `ListLocks` |
| `Infrastructure/Eloquent` | The repositories on the query builder; `SlugHistory`; `DatabaseListLocks`; Catalog's own `Ulids` (amendment 1(h)); step 5's `DatabaseListingRows`, `DatabaseShopReader` and `DatabaseSearchLog` |
| `Infrastructure/Listener`, `Infrastructure/Queue` | `RefreshCardPhotos` (Platform's `MediaVariantsReady`); `PruneSearchLogJob`, queued nightly; `BringInImportJob` and `LaravelImportQueue` (step 6) |
| `Infrastructure/Import` | `DiskImportArchives`: a products file's zip kept on the disk `config/catalog.php` names, read from a local copy, its photos unpacked only into temporary files of its own naming |
| `Infrastructure/Media` | `CatalogImagesUsage`: brand logos and category photos as Platform media; `ProductPhotosUsage`: product and variant photos (below) |
| `Infrastructure/Persistence` | `CatalogSchema` (step 1) and the migrations: step 2's lists, step 3's products, variants and their parts, step 4's store rows, step 5's listing and search log — every rule one row can hold backed by a named CHECK, index or key |
| `Presentation/Console` | `catalog:listing:rebuild` — the listing's repair, run by hand |
| `Presentation/lang/{ar,en}` | The permissions' names, the errors, the audit log's name for every action, and the queued work's names (`jobs.php`) |
| `Public/Contracts`, `Public/Dto` | `CatalogApi` and its DTOs (`VariantDto`, `VariantValueDto`, `ProductDto`, `StoreVariantDto`); `ListingFacts`, and `ImportSections` with `ImportSection`, declared for stage 5 |
| `Public/Enums` | `AttributeKind`, `AgencyType`, `ProductStage`, `ProductFate`, `SaleMode` — and so their TypeScript types |
| `Public/Events` | `ProductMadeReady`, `ProductArchived`, `ProductRestored`, `ProductChanged`, `VariantAdded`, `VariantArchived`, `VariantRestored`, `VariantCodeCorrected`, `StoreListingChanged`: ids only |

## How it is built

**The same shape as Access and B2B.** Every change goes through a command handler that authorizes
first, works inside one transaction and audits what it changed; repositories write with the query
builder; ids are lower-case ULIDs, and anything that is not one is "not found" without a query. Every
rule is in code first; a rule one row can hold is in the database behind it (handoff §5.3). Rules that
read other rows or another table — a category's loops, a swatch only on a colour attribute's values,
a set's members, a job locked by values, "at least one default" — are the code's alone, under the
list's lock.

**One permission per job; one of them, the store file's `catalog.listing.fill`, admin roles only** (catalog.md §3, amendment 6(h)). A store's own row — its choice, selling
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
| `DeleteDraftProduct` | `product.archive` | Only a draft is deleted (amendment 3(h)) — whole, its slugs and codes free again (`ProductDeletion`, which the import's page also uses, amendment 11(c)) |

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
admins' file that fills a store with codes came in step 6 (below), with a page of its own instead of
amendment 4's "needs completion" list (amendment 6(g)).

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

### The listing, search and the contract (step 5)

**One row per store, language and product a shopper can find there** (`catalog.listing`, §5.4):
ready, not hidden with its category, its brand active (a brand's deactivation hides or moves every
product of it), not "Not available now" there, and at least one variant switched on there, not
archived and not "Not available now". **A hidden product is inactive** (amendment 5(j)). A product
**left** in an inactive category keeps its rows, marked out of the category pages (§1.4). A row
holds the name and slug in its language, the brand and whether it shows in default listings, the
category and every id above it, the filter values (the product's and those of the variants on sale
there), the labels, the card photo (the first ready one, its addresses asked of Platform as the row
is written) and the search's text. **Never a code** (amendment 5(d)). Until stage 5 every row is
orderable, with no price and no rank (§2.2) — and since every change writes a product's rows again
from Catalog's own tables, stage 5 keeps what it pushes in a table the writer reads.

**Written inside every change that alters it** (`ListingRows::refresh`, §9.3 #20), under the
products' lock: the product changes that touch a row (details, a variant's values, the gallery,
search words, filter values, archiving a product or a variant), the four store changes (choosing,
"Not available now" on and off, labels), the four deactivations and activations, **a category
renamed or moved** and **a brand's place in default listings** — those three now take the products'
lock before their list's — and a photo's file deleted. **A photo's sizes becoming ready** writes its
products' rows from the queue (`RefreshCardPhotos`, three tries), taking the products' lock before it
reads which galleries hold the photo, so a gallery saved meanwhile is read once it is saved. A row
that stays is **updated in place**, never deleted and written again: a new row takes a key lock on
its card photo's media row, which deleting that file holds while it waits for the products' lock (a
change that makes a photo being deleted the card at that very moment can still meet the delete;
PostgreSQL ends one and both retry, as for a gallery). An unchanged row is not written at all.
`catalog:listing:rebuild` (`RebuildListing`, reserved to the system) writes every row again the same
way, in one transaction holding the products' lock — product changes wait while it runs.
`CatalogListingTest` checks, after each of twenty-nine changes, that what the change wrote is exactly
what the repair writes, and that eight changes which alter nothing a row holds write nothing.

**What a shopper reads** (`ShopCatalog`, no permission — what a shopper may not see is not in the
listing): **the menu** — a category shown by itself once the store lists something in it or below
it, of any brand, in the store's order, else the base store's (amendment 5(a)); a category
deactivated by hand in none. **A secondary brand** — one hidden from default listings — **is reached
through its own category** (amendment 5(k)), shown as any other. **A category's page** — everything
below it, of every brand, best-selling then newest, a page at a time by keyset (`Cursor`); the
shopper's brand filter narrows it and never takes it away; a category off, or listing nothing here,
has none. A card's labels follow the list's order as it is now: moving a label rewrites no row. **A
brand's page.** **A product's page** — available while a shopper can find it here, with the variants
on sale and the labels; otherwise **one "Not available now" page** (name, photos, description,
noindex); nothing for a slug that never existed or a product never made ready; an old slug answers
with the current one (`Moved`). It asks Platform for each gallery photo's addresses — up to twenty
reads — which the storefront screens' query budget will settle. **Suggestions** — the hand-picked
"Related", or, when none was picked, the same category's then the same brand's, a secondary brand's
products only on its own products' pages (5(l)); "Goes with" as picked; only what the store lists.

**The search** (`ShopSearch`, §1.11): both languages' names, the search words, the shared word
pairs and the names of every category from the product's up (amendment 5(c), (f), (g)) — never the
brand, the code or the description, and **only the brands shown in default listings** (5(k)).
Normalised as the rows are, its words one space apart, so "drawer." and "drawer" are one search;
each word matches the start of a word; a pair widens a word, or a run of words, by its partner — the
words typed in any order, or the partner's side by side — so a pair only ever adds results
(`SearchTerms`). Ranked exact, prefix, nearest (pg_trgm's word similarity, at its default 0.6), a
search word or pair, a category's name; ties by sales rank, then newest. A product left in an
inactive category is found. **Only a submitted search is logged** — the words as read, the store,
the language, how many, no person (5(e)) — and `PruneSearchLogJob` removes entries older than twelve
months, queued at 01:00 UTC (`PruneSearchLog`, reserved to the system). The results come as one list
of up to a hundred; paging them comes with the screens.

**The contract** (`CatalogApi`, §2.1): a variant with its code — for staff and the modules above,
never a shopper — values in both languages and measures; every variant holding a code; a product; a
variant in a store (switched on, orderable by §1.3 and never while hidden, "Not available now", its
modes — kept while it is switched off — and its product's limits there; whether the store is on is
Platform's); and the variant a shopper's picked values name — the server's answer, never the
browser's, a product with no attribute set named by no values. `ListingFacts` is declared; stage 5
implements it and decides which price a card shows (amendment 5(i)).

**The schema.** `catalog`, on `config/database.php`'s search path so `migrate:fresh` wipes it; the
first migration also creates `pg_trgm`, which the search's nearness ranking needs (catalog.md
§1.11), pinned to the `public` schema so no module's rollback can take it. **It needs a privilege the
other modules did not:** the application's database user must own the database (as `touchwood` does
locally and in CI), or a superuser creates the extension once beforehand. The PHP `intl` extension
is required (`composer.json`): slugs fold accented Latin letters with it.

**What Catalog takes from Access** is five classes — the catalog contract, the definition DTO and
its three enums — and `tests/Architecture/CatalogAccessUseTest.php` holds it to that. Catalog's own
public surface never references Access.

### The import and the store file (step 6)

**A products file** (catalog.md §1.12, amendments 6 and 7; the format in `docs/modules/catalog-import/`)
is a Super Admin's (`catalog.import.run`), from upload to acceptance:

1. **Upload** (`UploadImport`): the JSON alone, or a zip with `products.json` at its top — told apart by
   the file's own first bytes. `ProductsFile` checks every rule of the guide and collects every problem
   (at most 500 listed); then `CatalogCheck` checks it against the catalog — an attribute used for two
   jobs, or for a job the catalog's attribute of that name does not have; a category with
   sub-categories; a set whose attributes the variants do not match; a photo not JPEG, PNG or WebP or
   over the media library's limit; a zip of more than 100,000 entries. Any problem refuses the file
   whole (`ImportRefused`). Otherwise it becomes an import: **each name the catalog lacks listed once**
   with its products (each missing level of a category path its own row; a brand by number as `#N`),
   **each product with the catalog's product already holding its codes**, and the zip kept. **Codes
   that mix catalog products leave those products out** (`CatalogCheck::conflicts`, amendment 11(a)) —
   one whose codes two catalog products hold, two or more whose codes one holds: `REFUSED` with the
   reason, taking no part (`ImportProduct::takingPart`), the rest of the file coming in. Nothing in the
   catalog changes.
2. **Decisions** (`DecideImportNames`, `DecideImportCodes`): a name means an active one of that list
   doing the file's job, or is refused; values, categories and sets may also be created — **brands,
   warranties and attributes never** (amendment 7(a)); a category picked for a path a product sits in
   has no sub-categories (`CategoryNotLowest`). A code the catalog has: update, replace, skip, or new
   codes free in the catalog and in the file.
3. **Changes before bringing in** (`SetImported…`, amendments 7(c), (d), 8(a), 8(c), 9(a)): brand (by
   its fixed number), warranty, category (kept by id), search words, filter values — for all or the
   selected, replacing or only filling the empty (lists may also add). A product the file updates
   counts what the catalog's has **when it is brought in**: where the file gives none, what is filled
   (`fill_…`) or added (`added_…`) is kept apart, whatever the decision on its codes is then, and given
   to what the product has at that moment (`FileProduct::asBroughtIn`, `ImportBringer::parts`).
   `SetImportedSlugs` gives a product its own web address when its own would collide
   (`ImportAddresses`). The file's own product stays in `import_products.data`; the changed one in
   `edited`. The names list follows the products. **A name several catalog items answer to** is listed
   with how many (`matches`): `CatalogNames` answers only when one item does (amendment 8(d)).
4. **Bringing in** (`BringInImport`, the confirm): names, codes, new codes and new categories'
   addresses asked again against the catalog as it is, what changed kept and audited; while anything
   waits — a name, a code, a product's address, keep or take off sale, or **a code a product not a
   draft keeps** (`ImportCodeChanges`, amendment 11(b)) —, `ImportUndecided`; else `BringInImportJob` is
   queued. A job the queue gives up on leaves the import failed, never bringing in (`failed()`). Its work
   (`BringInImportProducts` → `ImportBringer`) unpacks the zip's photos first, then is **one transaction
   under the products' lock, then the lists'**: the names decided "create it" made through the lists' handlers, then each product **through
   the product handlers** — created as a draft with its photos (`ImportPhotos`, Platform's
   `uploadMediaFor` under `import.run`), updated, replaced, skipped, held back when its set or a
   variant's value was refused, or made with its new codes. Any refusal rolls everything back, and the
   import is `FAILED` with where and why (`ImportStepFailed`); a fault also stays on the failed jobs
   screen.
5. **After** (`AcceptImportedProducts`, `ArchiveImportedProducts`, `DeleteImportedProducts`, through
   `BroughtInProducts`): accepting makes ready — **on sale nowhere**: the products file only brings
   products in, and a store's admins publish them with their store file (amendment 9(b)) — and relates
   to the ready products the file named; archive and delete only what the import created and nobody
   accepted — **whatever was done to it since** (amendment 11(c)): one made ready meanwhile is archived,
   or deleted whole (`ProductDeletion`) after archiving switched it off everywhere and the products
   linking to it let go of it.

**A file not brought in may be discarded** (`DiscardImport`, amendment 10(b)): one deciding, or whose
bringing in failed, goes whole — its rows by their foreign keys' CASCADE, its zip after the commit;
one bringing its products in, or brought in, stays (`ImportClosed`). **No uploaded file is kept
beyond its work**: a JSON file and a store file are let go once read, a zip once its products are
brought in or its import discarded.

**Brands' numbers** (amendments 7(b), 10(a)): a new brand takes the lowest number no brand holds
(`DatabaseBrandRepository::add`, under the brands' lock), so a deleted brand's is free again and a
failed add leaves no gap; a trigger refuses changing a brand's number while it exists.

**A product the file updates or replaces that is on sale** needs **keep on sale** or **take off
sale** (`DecideImportCodes`' `sale`), every time — the confirm counts those still waiting
(`ImportUndecided` sales); taken off sale, bringing in switches it off in every store, as archiving
does, still ready (amendment 9(c)).

**The zip** (`DiskImportArchives`) is trusted no further than it can be checked: two entries for one
path are refused, `products.json` is read by its own entry and never past 20 MB, and each photo is
copied no further than its declared size — one that does not come out exactly that size is refused.

**For the screens:** the store file's pages answer no uploader: a Super Admin is named to a store's
admins only as "System administrator" (access.md amendment 54).

**The admins' store file** (catalog.md §1.3, amendment 6(g), (h)) is `catalog.listing.fill` in that
store — declared `adminOnly`, so only an admin role holds it. It never creates or changes a product:
`UploadStoreFill` keeps its items open; `CorrectStoreFillCode` and `RemoveStoreFillItems` mend the file;
`SwitchOnStoreFillItems` chooses, in that store, the variants carrying each code of a ready product —
through `StoreListingChange` and the store's listing, as the store's own choice does, but under the
file's job. Each open item's standing (ready, not ready, archived, already on, unknown) is read on its
page, never stored.

**Prices and stock come only with a store's file** — a products file that names a store is refused
(amendment 9(a)) — and are shown and not kept until stage 5, when Pricing and Inventory register their
`ImportSection`s (declared in `Public/Contracts`).

## Tests

| File | What it covers |
|---|---|
| `Unit/CatalogValuesTest` | Text on one line, names, slugs (Arabic and accented Latin), structured text, Arabic normalisation, a label's words and looks, word pairs, warranty periods |
| `Unit/CatalogErrorsTest` | Every error has a unique `catalog.*` type, a category, and a title and detail in both languages |
| `Integration/CatalogBrandsTest` | The seed; who may; slugs and their history; the one default; deactivating and deleting; the database's CHECKs; each brand's number, the lowest free (amendment 10(a)) |
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
| `Integration/CatalogListGuardsTest` | For every one of the sixty-two list, product and store changes: its lock is the first query inside its own transaction (the products' before its list's, for the seven that change products or their listing rows); an id not in its list is answered as not found; a change that changes nothing writes nothing and records nothing; and what the audit log keeps reads from what was to what is |
| `Integration/CatalogListConstraintsTest` | The database's named CHECKs, indexes and keys that no handler test reaches, each refusing a row written past the code; an id that is not a ULID never reaching the database |
| `Integration/CatalogListingTest` | Which products have rows, and what a row holds — never a code; each of twenty-nine changes writing exactly what the repair writes, eight that alter nothing writing nothing; a row that stays updated in place; the card photo; the repair and its locks |
| `Integration/CatalogShopTest` | The menu per store and its order, a secondary brand's own category in it; a category's page by keyset, ties included, every brand, the brand filter, labels in the list's order, slugs moved; a brand's page; a product's page or "Not available now"; suggestions |
| `Integration/CatalogSearchTest` | The ranking and the total; both languages, the other's name exact; Arabic normalised, a typo near; word pairs only adding; what is found and what never is — a secondary brand's product; the search log and its nightly removal, to the second |
| `Integration/CatalogApiTest` | `CatalogApi`: variants, codes (one corrected away), products, a variant in a store (switched off, hidden), resolving the variant (a product with no set) |
| `Unit/CatalogListLocksTest` | A list's lock is refused outside a transaction |
| `Unit/CatalogListingRowsTest` | The listing is never written outside a transaction |
| `Unit/CatalogSearchTermsTest` | What was typed, as search reads it; word pairs, a run of words before one |
| `Unit/CatalogProductTest` | A product archived remembers the stage it left — archived twice or not — and restoring goes back there |
| `Unit/CatalogImportFilesTest` | Step 6's two files read and checked: the guide's own examples, every rule refused with where, every problem collected, descriptions' markers |
| `Integration/CatalogImportUploadTest` | Uploading: who may; names listed once with their counts, matched as search compares words, a brand by number; what refuses a file; a zip kept, its products.json at the top, photos unpacked into the archives' own files |
| `Integration/CatalogImportDecisionsTest` | Deciding names and codes: targets in their list, active, of the right job; what may be created; values under their attribute; new codes free; all or none; closed while bringing in |
| `Integration/CatalogImportChangesTest` | The changes before bringing in: each field, replace and fill-empty (and add), the selected or all, the file's own kept, the names list following |
| `Integration/CatalogImportBringInTest` | The confirm asking again; bringing in: lists made, products created with photos, skipped, held, recoded, updated, replaced; all or nothing with the reason; the locks' order |
| `Integration/CatalogImportAcceptTest` | Accepting (ready, on sale nowhere new, relations), archiving and deleting only what the import created |
| `Integration/CatalogStoreFillTest` | The store file: an admin role's job in that store; switching on the variants carrying each code of a ready product; mending and removing items |
| `Integration/CatalogImportPagesTest` | The pages' reads: a products file's page and list, a store file's page with each item's standing, and its store's list |
| `Integration/CatalogImportSaleTest` | Amendment 9: no store in a products file; keep on sale or take off sale, every time, in every store, cleared with its decision, asked again when put on sale after the confirm; codes two catalog products hold; words added to an updated product kept apart; a new category's taken address counted; a job given up on taking the products' lock first |
| `Integration/CatalogModulePassTest` | Step 7, the reviews of the whole module (amendment 11): a category picked for a product's path has no sub-categories; archive and delete on the import's page whatever was done since — a product made ready and put on sale since deleted whole, unlinked first; the confirm catching a code a product not a draft keeps, a draft taking the file's; a zip of too many entries |
| `Integration/CatalogImportDiscardTest` | Amendment 10(b): a file not brought in discarded whole with its zip, deciding or failed; one bringing in or brought in stays; a store's file is not one; a Super Admin's |
| `Integration/CatalogImportReviewTest` | After step 6's reviews (amendment 8): ambiguous names and colliding addresses decided on the page; the confirm asking again; holding back, replacing whole, updating and restoring; the page's picks brought in, filling and adding counted against an updated product as it is when brought in; a job given up on; the zip's sizes; linking back on accepting, by any code held; the store file kept to its store |
| `Integration/CatalogPermissionsTest`, `CatalogSchemaTest` | Step 1's permissions and schema |
| `tests/Architecture/CatalogAccessUseTest.php` | Catalog references nothing of Access beyond the five permission-declaration classes |

`CatalogListGuardsTest` records every query to check each change takes its lock first, inside its
own transaction (level 2 under `RefreshDatabase`); `CatalogFixtures::lockedTables` reads from the
same record which rows a change locked, in order. `Support/CatalogProducts` makes the products,
variants and the lists they use, as the system; `Support/CatalogImports` writes, uploads, decides and
brings in files for step 6's tests. Run everything with `composer check`.
The test database is `touchwood_test`.
