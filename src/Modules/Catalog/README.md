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
come after the Geist foundation. This file grows with each step. **Steps 1 to 7 are built.**

**The screens** (from 2026-10-07; catalog.md §4.4, §4.5, amendment 13) come in six steps of their own
(§9.7 P23): 1 the shared lists' screens · 2 the products · 3 a store's rows and the store file · 4 the
import · 5 the shop's audiences (amendment 14) · 6 the shop's pages. **Screens steps 1 to 5 are built.**

---

## Where things are

| Folder | What is in it |
|---|---|
| `Application/CatalogPermissions.php` | The twenty-two permissions of catalog.md §3, declared into Access's catalog at boot: nineteen jobs a role may hold — two only an admin role, declared `adminOnly`: `catalog.listing.fill`, the store file (amendment 6(h)), and `catalog.category.audience`, who sees a category in a store (amendment 14(c)) — and three reserved to a Super Admin and the system |
| `Application/Command` | One folder per change: a command and its handler, which names its `PERMISSION` and authorizes first. Step 2: the six shared lists and each store's order of the menu; step 3: products and variants; step 4: each store's choice (below) |
| `Application/Lists` | What the lists' handlers share: `SharedListChange` (the permission with All stores, the transaction, the list's lock — after the products' for a change that changes products — the audit), `ProductFates` (each product's fate in a deactivation), the forms' parsing (`BrandInput`, `CategoryInput`, `AttributeInput`, `LabelInput`, `WarrantyInput`), `CatalogImages` (a logo or photo must be a public image) and `ListOrder` (an order sent from a list's screen, amendment 16(d)) |
| `Application/Products` | What the product handlers share: `ProductAccess` (who may change a product's shared data), `ProductReferences` (the list rows a product points at, row-locked), `ProductInput`, `VariantInput` and `ProductParts` (the forms' parsing), `Readiness` and `ReadyPhotos` (what a product needs to be shown) |
| `Application/Listing` | `StoreListingChange` — what a store's changes share: the job in that store, the transaction, the products' lock first, the audit in that store; `ListingRows` — the listing's writer, called inside every change that alters a row (step 5, below); `CardPhotoReady` — a photo's sizes ready, its products' rows written again |
| `Application/Import` | The import and the store file (step 6, below): reading the files (`ProductsFile`, `StoreFillFile`, `DescriptionText`, `FileProblems`), checking one against the catalog (`CatalogNames`, `CatalogCheck`), the zip (`ImportArchives`), the import's rows (`Imports`), the changes before bringing in (`ImportedProductsChange`), bringing in (`ImportReferences`, `ImportBringer`, `ImportPhotos`), the steps after it (`BroughtInProducts`) and the store file's (`StoreFills`) |
| `Application/Query/ViewImport`, `ListImports`, `ViewStoreFill`, `ListStoreFills` | The import's and the store file's pages and lists (step 6) |
| `Application/Query/Lists` | What the list screens read (screens step 1, below): `CatalogListReads` (the reads, as an interface) and the rows it answers; `ListReaders` — who may read a shared list and who may change it |
| `Application/Query/ListBrands`, `ListCategories`, `ListAttributes`, `ViewAttribute`, `ListLabels`, `ListWarranties`, `ListWordPairs`, `ListSearchesWithNoResults`, `ProductsReached` | The list screens' queries, each asking its own job (catalog.md §4.4) |
| `Application/Query/Products`, `ListProducts`, `ViewProduct` | The products screens' reads (screens step 2, below): `CatalogProductReads` (as an interface) and what it answers; `ProductReaders` - who reads the products, and what they may do to one, asked as the handlers check it |
| `Application/Command/UploadProductPhoto` | A product's or a variant's photo, uploaded from its page under `catalog.product.update`, checked as the product's other changes (P5) |
| `Application/Command/UploadBrandLogo`, `UploadCategoryImage` | A brand's logo, a category's photo, uploaded from its form into Platform's media library under the list's job with All stores (P5) — one command a job, as every handler names its one `PERMISSION` |
| `Application/Query/Shop` | What a shopper reads (step 5): `ShopCatalog` (the menu, a category's, a brand's and a product's page, suggestions), `ShopSearch`, `ShopReader` (the reads, as an interface), `Cursor` (a page's keyset), and the cards and pages they answer — never a code; **every read for one viewer**, Platform's `ShopperKind` (screens step 5, below) |
| `Application/Command/SetCategoryAudiences`, `Application/Lists/CategoryAudiences` | Who sees a category in a store — its Individuals and Companies switches, a parent's reaching everything under it — and a sub-category added or moved taking its new parent's (P24): screens step 5, below |
| `Application/Search` | `SearchTerms` (what was typed, as search reads it, widened by word pairs) and `SearchLog` |
| `Application/CatalogApiImpl.php` | `Public/Contracts/CatalogApi`, as plain reads |
| `Application/Events/ProductEvents.php` | The nine events of catalog.md §6.1, sent from inside a change and delivered after it commits — for a product that has been ready |
| `Application/Audit/ListAudit.php` | Every list and product change's audit entry, by value: `catalog.{subject}.{what}` |
| `Domain/Model` | Brand, Category, Attribute, AttributeValue, Label, Warranty, WordPair, Product, Variant, StoreListing (one store's choice of one product) — each keeps what one row can know; all but WordPair (added and deleted, never edited) keep a `ChangeLog` of what an edit changed |
| `Domain/ValueObject` | Names in both languages, slugs, the structured text of descriptions and terms, list positions, a label's look (`LabelTone`, the Badge's ten), a warranty's period; a product's name (Arabic required, English optional) and slugs, a code (`ProductCode`), a variant's combination, details and measures, search words; a product's quantity limits in a store (`SellingLimits`). **Every digit typed is saved 0-9** (amendment 12): one-line text (`CatalogText`), codes, a detail's number and each run of structured text turn Arabic and Persian digits with Shared's `LatinDigits` |
| `Domain/Service/ArabicText.php` | Arabic as search compares it (handoff §5.2): marks off, alef and yeh forms folded, digits Latin, lower case |
| `Domain/Exception` | `CatalogError`, the refusals of steps 2 and 3 (`AttributeSetInUse` and `AttributeSetLocked` gone with the sets, amendment 16(b)), step 4's two (`NotChosenInStore`, `InvalidSellingTerms`) and step 6's three (`ImportRefused`, `ImportUndecided`, `ImportClosed`), named in both languages in `lang/{ar,en}/errors.php` |
| `Domain/Repository` | The lists', the products', the variants' and the stores' rows' repositories, `ListLocks`, and `ListOrders` (every ordered list's order, amendment 16(d)) |
| `Infrastructure/Eloquent` | The repositories on the query builder; `SlugHistory`; `DatabaseListLocks`; `DatabaseListOrders`; ids checked by the Shared kernel's `Ulids` (once Catalog's own copy, amendment 1(h); moved by the owner, 2026-10-10); step 5's `DatabaseListingRows`, `DatabaseShopReader` and `DatabaseSearchLog`; the screens' `DatabaseCatalogListReads` and `DatabaseCatalogProductReads` |
| `Infrastructure/Listener`, `Infrastructure/Queue` | `RefreshCardPhotos` (Platform's `MediaVariantsReady`); `PruneSearchLogJob`, queued nightly; `BringInImportJob` and `LaravelImportQueue` (step 6) |
| `Infrastructure/Import` | `DiskImportArchives`: a products file's zip kept on the disk `config/catalog.php` names, read from a local copy, its photos unpacked only into temporary files of its own naming |
| `Infrastructure/Media` | `CatalogImagesUsage`: brand logos and category photos as Platform media; `ProductPhotosUsage`: product and variant photos (below) |
| `Infrastructure/Persistence` | `CatalogSchema` (step 1) and the migrations: step 2's lists, step 3's products, variants and their parts, step 4's store rows, step 5's listing and search log — every rule one row can hold backed by a named CHECK, index or key |
| `Presentation/Console` | `catalog:listing:rebuild` — the listing's repair, run by hand |
| `Presentation/admin-routes.php`, `Presentation/Http` | The panel's Catalog screens (`catalog.admin.*`): the controllers, `CatalogFormRequest` (shape only), `CatalogRefusals` (where a refusal is said) and the pages' data (`Resource`: `ListPages` and one `#[TypeScript]` class per page and row) |
| `Presentation/lang/{ar,en}` | The permissions' names, the errors, the audit log's name for every action, and the queued work's names (`jobs.php`); the panel's menu entries (`menu.php`) and the screens' words (`admin.php`, shared, and one file a screen: `admin_brands.php`, `admin_categories.php`, `admin_attributes.php`, `admin_labels.php`, `admin_warranties.php`, `admin_search_words.php`) |
| `resources/js/pages/Catalog/Admin` (outside the module) | The screens themselves: one folder a screen, and what they share — `parts.tsx`, `FatesDialog.tsx`, `ImageField.tsx` (a module of its own, so only the two dialogs that take a photo load its file input and checkbox), `SortableList.tsx`, `marks.ts` |
| `Public/Contracts`, `Public/Dto`, `Public/Events` | `CatalogApi` and its DTOs (`VariantDto`, `VariantValueDto`, `ProductDto`, `StoreVariantDto`, `ListingPrice`, `LineFactsDto` — amendment 18; `ProductCardDto` — amendment 26(a), Feedback's cards by `productCards()`; `ShopProductSections` / `ShopProductSection` / `ShopProductVisitDto` — amendment 26(b), other modules' parts of the shop's product page, carried as `sections`; `BrandDto` — amendment 20, many at once by `brands()` — amendment 27; `productsSoldIn()` — amendment 24; `ListingFacts::ratings` / `ratingsShown`, the stars and the rating sort — amendment 25; `CatalogUseDto` and the `CatalogItem` enum — amendment 19); `CatalogUsage` and `CatalogUsages`, the usage check before deletes (amendment 19); `ListingFacts` and `ImportSections` with `ImportSection`, bound for stage 5 (amendment 16(i)); the events, `CategoryMoved` among them |
| `Public/Enums` | `AttributeKind`, `AgencyType`, `ProductStage`, `ProductFate`, `SaleMode` — and so their TypeScript types |
| `Public/Events` | `ProductMadeReady`, `ProductArchived`, `ProductRestored`, `ProductChanged`, `VariantAdded`, `VariantArchived`, `VariantRestored`, `VariantCodeCorrected`, `StoreListingChanged`: ids only |

## How it is built

**The same shape as Access and B2B.** Every change goes through a command handler that authorizes
first, works inside one transaction and audits what it changed; repositories write with the query
builder; ids are lower-case ULIDs, and anything that is not one is "not found" without a query. Every
rule is in code first; a rule one row can hold is in the database behind it (handoff §5.3). Rules that
read other rows or another table — a category's loops, a swatch only on a colour attribute's values,
a job locked by values, "at least one default" — are the code's alone, under the
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
and `listing.labels`; step 5 `listing.rebuild` and `search_log.prune`; step 6 `import.run` and `listing.fill`;
screens step 5 `category.audience`.

### The shared lists (step 2)

**One change, one shape** (`SharedListChange`): the permission with All stores before anything is
read; its own transaction, retried on a deadlock, which reads again whatever it changes; **the list's
lock first** — one transaction-scoped advisory lock per list (`catalog:brands`, `catalog:categories`,
`catalog:attributes` for attributes and values together, `catalog:labels`,
`catalog:warranties`, `catalog:word_pairs`) — so every question about the other rows ("is this slug
free?", "does it have values?") is answered under it; and the audit entries inside the same
transaction, none when nothing changed.

| List | What the code keeps |
|---|---|
| Brands | Two slugs, one per language, global — Arabic letters and digits for `ar`, `a-z` and digits for `en` (§5.3) — each kept in the history table while the brand exists, so a slug it once held is never given to another; deleting the brand frees them (owner, amendment 2(a)); the redirect itself arrives with the storefront, step 5; **exactly one default** — the first brand becomes it, moving it un-marks the old one in the same step, the default is never deactivated or deleted; an optional two-letter origin country (amendment 1(j)); a description in both languages or neither. The seed adds TouchWood «تاتش وود» only |
| Categories | One tree, nesting without limit, never under itself or below itself; a new or moved category goes under an **active** parent only, and **last among its siblings, in every store**, on or off — after the place each sibling shows there, its own or, until the store's admins place it, the base store's (amendment 16(d), replacing 1(d) and 2(c)'s place chosen by the adder); each store's admins reorder their own menu (`catalog.category.rank` in that store) with `OrderCategories` — one parent's children, as every list is ordered (below) — or `RankCategories`, places given. Every place written is audited in its store (`catalog.category.ranked`, §3), the one a category takes when added or moved too. Deactivating takes every active category below it, each remembering it went with its parent, so activating brings back exactly that; a category under a deactivated parent cannot be activated. Only an empty category is deleted |
| Attributes | A job — details only, filter, or variant-making — that changes, with being a colour, **only while the attribute has no values** (amendment 1(i)); an attribute a product makes its variants of stays variant-making (amendment 16(b)). Values: never two alike in either language ignoring case; a colour attribute's values need a `#rrggbb` swatch, no other's take one; an attribute is deleted only after its values (RESTRICT, §5.3), which its handler deletes and audits one by one. There are no attribute sets (amendment 16(b)): a product's variants are made of the attributes its Variants tab gives it (below) |
| Labels | «الشارات»: one or two words in each language, at most 30 characters, and one of the Badge's ten looks, chosen by meaning (amendment 1(e), (f)) |
| Warranties | Name and formatted terms in both languages, 1–600 months or for life |
| Word pairs | Kept as search compares words, in byte order, once whichever way they were typed; added and deleted, never edited. The ordering CHECK compares with `COLLATE "C"`: the database's language order ignores spaces and hyphens and would refuse pairs the code accepts |

**Every ordered list is ordered by dragging** (amendment 16(d), P29): the brands, the labels, the
warranties — which gained a `position` with it, the ones already there numbered in name order — the
attributes, and each attribute's values. `OrderBrands`, `OrderLabels`, `OrderWarranties`,
`OrderAttributes` and `OrderAttributeValues` each take **the ids in their new order**, under the
list's own job with All stores and its lock, as every other change to it: those sent come first, in
the order sent, and **every other keeps its order after them** — so two people ordering at once lose
nothing, Move to Top sends one id, and Move to Bottom every other one first. The positions are
**written again 1, 2, 3 …** each time (`ListOrder`, one statement through `ListOrders::write`), and each
item whose place changed is audited (`catalog.{list}.ordered`, from its position to its position) —
not one only numbered again where it stands, as a list whose places were typed ten apart is at its
first order; an order that leaves a list numbered 1, 2, 3 where it was writes and records nothing. An id sent twice is refused, and one not in the list
— a value of another attribute, too — is not found. **No form takes a place any more: a new item goes
last** (`ListOrders::afterLast`, read under the list's lock). Positions are a sort key
(`ListPosition`), and only a list never ordered since its places were typed can reach the cap: then
the list is numbered again 1, 2, 3 … in its order before the new item takes the next — the categories
the same, per store, among the siblings (`DatabaseCategoryRepository::placeLast`). A brand's **fixed
number** is another thing, never its place (amendment 7(b)). A value made from a product's Variants
tab goes last the same way (`AddValueFromProduct`).

**The categories are ordered the same way, store by store** (`OrderCategories`, the review of #122):
one parent's children in one store's menu, the ids sent first and every other sibling after them in
the order that store shows — its own place, else the base store's (amendment 5(a)), then the English
name, then the id, which the screen sorts by too — each written again 1, 2, 3 … as the store's own,
those moved audited there. `RankCategories`, which writes only the places given, stays for whoever
gives places; the screen no longer uses it. A product's variants (`OrderVariants`, #120) refuse an id
sent twice as every list does (`ListOrder::sent`).

**The places typed before** are written again 1, 2, 3 … by
`2026_10_11_120000_renumber_catalog_list_places` — the brands, the labels, the attributes, and each
attribute's values, in the order each list showed (place, English name, id) — so no two items share a
place and Move to Top, which sends one id, moves the row the screen shows. The warranties gained
theirs with `2026_10_11_110000_add_position_to_catalog_warranties`, numbered in name order; both run
after the variants' own renumbering (#120's `2026_10_11_100000`), and none reads another's table.

**What the products changed (step 3).** A list item a product or variant uses is not deleted
(`BrandInUse`, `CategoryNotEmpty`, `ListItemInUse`); a category holding products takes no
sub-category and moves under none (`CategoryHoldsProducts`); an attribute a product makes its
variants of keeps its job and is not deleted; an attribute's job stays once variants carry details of it.
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
`authorizeFor`). A product Active nowhere needs it in some store only. Creating asks no store: the
job in some store that is on (amendment 13(f), replacing 3(j)'s working store); deleting a draft,
making one ready and restoring
reach only products Active nowhere.

| Handler | Permission | What it keeps |
|---|---|---|
| `CreateProduct`, `EditProductDetails` | `product.create`, `product.update` | Arabic name required, English optional in a draft (amendment 3(g)); slugs made from the names, held while the product exists; what it newly points at is active, a category the lowest of its branch |
| `AddVariantAttribute`, `RemoveVariantAttribute`, `OrderVariantAttributes` | `product.update` | **Has Variants** (amendment 16(b), P27): any active variant-making attribute of the library, at most ten, every variant — archived ones too — given its value when one is added, all or nothing; one removed only while the variants stay apart (`DuplicateCombination`), the last only with one variant left (Has Variants: No); their order as sent, the rest after (P29). Kept in `product_attributes`; a variant's values in their attributes' id order, so an order rewrites no variant |
| `AddValueFromProduct` | `product.update` | A value made from the Variants tab (P28, amendment 16(c)): any active variant-making attribute, never named twice, last in its order, under the attributes' lock |
| `AddVariant`, `UpdateVariant`, `DeleteDraftVariant` | `product.update` | One active value of each of the product's variant attributes, kept in their id order — a combination no other variant of the product has, archived ones included; details and measures; a code changed here only in a draft |
| `CorrectVariantCode` | `variant.correct_code` | The one variant takes the new code (amendment 16(a)) |
| `SetProductGallery`, `SetVariantPhotos`, `SetSearchWords`, `SetFilterValues`, `SetRelations` | `product.update` | Public images in order (20 and 10); search words (30, each once as search reads it); values of filter attributes (amendment 3(a)); related products that are ready, at most 20 (amendment 3(d)) |
| `MarkProductReady`, `ArchiveProduct`, `RestoreProduct` | `product.publish`, `product.archive` | Ready only with every rule met; restored to the stage it left (`archived_from`) — to ready only the same way (amendment 3(m)) |
| `ArchiveVariant`, `RestoreVariant` | `product.update` | A variant retired instead of deleted once the product is ready |
| `DeleteDraftProduct` | `product.archive` | Only a draft is deleted (amendment 3(h)) — whole, its slugs and codes free again (`ProductDeletion`, which the import's page also uses, amendment 11(c)) |

**Codes** (amendments 3(e), 16(a)). Digits only, 1 to 10, kept as text so a leading zero stays.
`product_codes` holds every code a product ever held, the code its key: a code is never given to
another product while its holder exists. **Every variant has its own code** — `variants_code_unique`
behind `VariantInput::freeCode`, which refuses a code another variant carries (`CodeTaken`); one the
product held before is taken back. The migration that made codes unique stops, naming them, on a
product whose variants share one and on a products file not brought in whose product does (P34). A code leaves a product only when a draft is deleted, or when a product never ready —
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
`CatalogListingTest` checks, after each of thirty-one changes, that what the change wrote is exactly
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
never a shopper — values in both languages and measures; the one variant carrying a code; a product; a
variant in a store (switched on, orderable by §1.3 and never while hidden, "Not available now", its
modes — kept while it is switched off — and its product's limits there; whether the store is on is
Platform's); and the variant a shopper's picked values name — the server's answer, never the
browser's, a product with no variant attributes named by no values. **For stage 5** (amendment 16(e),
(i)): a product's variants in its order (`variantIdsOf`), the products in a category and below it
(`productIdsInCategory`), the variants switched on in a store, and many variants or products in one
read (`variants`, `products`: `ApiReads`, two queries and one, however many ids). `CategoryMoved` is
sent when a category moves. **`ListingFacts` is bound** (`DatabaseListingFacts`): orderable, ending
soon and prices (`ListingPrice`) kept in `store_variant_facts`, only the facts given, a variant not
Catalog's left out; the cards read them with the shop's pages (P22); Sales's Best-Selling ranks
(amendment 30) kept in `sales_ranks`, a store's whole ranking at a time, which every listing row takes. **`ImportSections` is bound** (`InMemoryImportSections`, a singleton the modules above
register into, as Platform's `MediaUsages`): a store file's page shows each section's lines and
says prices are kept once one is registered; switching an item on gives each section the variant,
its price and stock, inside the same change.

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
   sub-categories; a photo not JPEG, PNG or WebP or
   over the media library's limit; a zip of more than 100,000 entries. Any problem refuses the file
   whole (`ImportRefused`). Otherwise it becomes an import: **each name the catalog lacks listed once**
   with its products (each missing level of a category path its own row; a brand by number as `#N`),
   **each product with the catalog's product already holding its codes**, and the zip kept. **Codes
   that mix catalog products leave those products out** (`CatalogCheck::conflicts`, amendment 11(a)) —
   one whose codes two catalog products hold, two or more whose codes one holds: `REFUSED` with the
   reason, taking no part (`ImportProduct::takingPart`), the rest of the file coming in. Nothing in the
   catalog changes.
2. **Decisions** (`DecideImportNames`, `DecideImportCodes`): a name means an active one of that list
   doing the file's job, or is refused; values and categories may also be created — **brands,
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
   `uploadMediaFor` under `import.run`), updated or replaced — **its variants matched by code** (P33):
   a code the product carries updates that variant; a code it does not carry corrects the code of its
   variant of the same values whose code the file does not name (a draft's; a ready product's waits
   at the confirm, above); any other code adds a variant —, skipped, held back when its set or a
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
`SwitchOnStoreFillItems` chooses, in that store, the variant carrying each code of a ready product —
through `StoreListingChange` and the store's listing, as the store's own choice does, but under the
file's job. Each open item's standing (ready, not ready, archived, already on, unknown) is read on its
page, never stored.

**Prices and stock come only with a store's file** — a products file that names a store is refused
(amendment 9(a)) — and are shown and not kept until stage 5, when Pricing and Inventory register their
`ImportSection`s (declared in `Public/Contracts`).

### The screens: the shared lists (screens step 1)

Seven panel screens (catalog.md §4.4 S1–S7), under **Catalog** in the panel's menu, each offered to
whoever holds its job in any store: Brands, Categories, Attributes (and one attribute's page — its
details as a form, then its values), Labels, Warranties, Search Words (Variations removed with the sets, amendment 16(b)). **S2's audience
switches** (who sees a category in a store, amendment 14) came with screens step 5 and their
permission, `catalog.category.audience` (below).

**Reading needs the list's job in any store; changing it, the job with All stores** (P2). A reader
without All stores sees the list, and every button disabled with that reason ("Changing this list needs
your job in All Stores."). `ListReaders::authorize` answers both from one read of each job, so a page
asks the authorizer once a job. On these screens what the reader may do is that one answer a list
(`mayChange`, the categories' `mayManage` and `mayRank`); each item's own reasons (the default brand,
an item in use) come from its row. §4.4's `CatalogActionsForReader` comes with the product screens,
where the answer differs store by store. The searches that found nothing are read only with
`catalog.search_word.manage` with All stores (§3, P11).

**Every page's reads are Application queries, a fixed few queries each, never one per row.**
`DatabaseCatalogListReads` reads each list in one query (counts and flags as subselects).
`ListPages` only shapes what they answer: the products below each category summed
from the tree (`productsBelow`), the photos of a whole page in one Platform read (`mediaUrlsOf`,
catalog.md §2.4), a description or warranty's terms written back as the marks typed
(`DescriptionText::text`, P1). A label's products are counted once each, whatever the stores showing it.

**The categories' store** is the screen's own filter (`?store=`), among the stores where the reader
orders the menu (`catalog.category.rank`); none asked, the first that is on; another store, or one
that does not exist, is refused the same. Someone who only manages the tree sees no store. The
stores are read once a page (`StoreChoices::forJobs`) and the store chosen among them as
`StoreChoices::chosen()` chooses.

**Deactivating a brand or a category** opens the fates dialog (`FatesDialog.tsx`): it reads the
products reached (`ProductsReached`) through the page's own address with `?reach=` — an Inertia
partial reload, the address kept — only for someone who may deactivate, keeps them through a refused
try, and sends one fate for all with a choice per product over it (§1.5, §1.6). A category's dialog
names the sub-categories going with it and the category each product sits in (S2).

**The lists are put in order by dragging** (amendment 16(d), P8): a handle on each row — shadcn's
`dashboard-01` handles on @dnd-kit, by mouse, touch or keyboard, each move said aloud — and **Move to
Top** and **Move to Bottom** on the row's ⋯ menu; the order is **saved when dropped**, through the
list's `…/order` address, and the rows stay where they were dropped until the answer arrives
(`useSavedOrder`). There is no typed place and no "#" column any more; the brands show their fixed
number as **Brand No.**. `SortableList.tsx` holds what the screens share — `SortableGroups` (the drag
context, a row moving among its own group only, by pointer or by keys), `SortableGroup`,
`useSortableRow`, `DragHandle`, `MoveItems`, and `useSavedOrder`, whose saves go one at a time, each
when the one before it is answered (`useSaveQueue`) — and `SortableList`, the short list a dialog
shows. A product's Variants tab is dragged with the same parts (#120's table, ported). **The
categories' tree** is dragged in its own rows, each parent's children one group, for the store the
page shows (`OrderCategories`: Move to Top sends the one id, as every list does); its old order dialog and "Save Order" are gone, and a
category the store has not placed is marked "Base Store's Place" beside its name. For a reader
without All stores every handle stays in sight, out of reach, with the reason (P2). The brands' and
the categories' add and edit dialogs are modules of their own (`Brands/BrandDialog.tsx`,
`Categories/CategoryDialog.tsx`), loaded when first opened, not with the page: the drag code and the
checks as typed (#121) took both pages past the 60 KB page budget otherwise (61.2 and 60.0 KB by
`JavaScriptBudgetTest`; about 53.7 and 55.2 KB after, frontend.md §5).

**A logo or a category's photo is chosen in its own form** and uploaded when the form is sent, through
`UploadBrandLogo` or `UploadCategoryImage`, before the save (P5). A save refused after the upload leaves that public image
unused in the media library, where it can be deleted. A file that did not arrive whole — larger than
the server accepts — is refused beside the photo, never a 500 (`CatalogRefusals::fileNotArrived`). The
form is sent as multipart only when a file is attached.

**A refusal is said where the panel says it** (`CatalogRefusals`, frontend.md §2.1): beside the field
the domain names, when the form has it, else at the top of the form and in a toast. A refused web
address opens the folded "Web Addresses" by itself.

**The controllers take the pages' data per action, never in their constructors.** Laravel keeps a
controller on its route; one that kept `ListPages` (and so the request's authorizer) answered a
second person's request in a test with the first person's rights.

**The query budget** (frontend.md §5, P21): each page's own queries, warm, are recorded in
`CatalogListScreensTest` — brands 2, categories 3, attributes 1, one attribute 2,
labels 1, warranties 1, search words 4 — and the same again with five more rows of everything.
They fell from 4, 13, 3, 4, 5, 3, 3 and 14 when the panel's frame learned to read the stores and
settings once a request (#108, access.md amendment 65): the stores the pages ask for are now
answered from that request's memory. **The panel's frame around every admin page measured 89 to
100 queries on its own on 2026-10-07** — 52 of them the menu checking 26 jobs, each re-reading the
reader's grants from the cache table — and 9 since #108.

### The screens: the products (screens step 2)

**The products list** (S8, P4): every product to whoever reads products (`catalog.product.view`) in
some store - a product belongs to no store - newest first by its id (a ULID's byte order is its age,
compared `COLLATE "C"`), 50 at a time with Show More; a name in either language or a code finds it
(digits typed in any script read 0-9; a `%` or `_` is a character, not a pattern) - a code the
product gave up too, though each row shows the codes its variants carry now; the stage, the category,
the brand narrow it - any brand or category products are in, an inactive one said so - and the page
shows the filters as they were applied. **All Stores**
first, then the stores the reader covers; each row's stores are only those, and one store chosen shows
its state - On (n of m variants), Off, Not Chosen, Not Available Now - and narrows by it. **Add
Product** asks no store (amendment 13(f)): `CreateProduct` takes the job in some store that is on -
`ProductReaders::storeToCreateIn`, which the list asks too, so Add Product is offered only to whoever
it would let in - and the import makes its drafts the same way.

**A product's page** (S9): its name, stage and codes above **tabs kept in the address** (`?tab=`) -
Details, Variants, Photos, Search and Filters, Related, Stores (step 3). **Each tab is
read when it is opened** (`ViewProduct` answers the open tab's data only), so every tab keeps the
query budget - and **each tab is a page of its own**, the product above it (`Products/shell.tsx`), so
the browser loads the open tab's script only, within the JavaScript budget, and the server renders it
whole (a tab loaded later with `React.lazy` would reach the server as "Loading…": `renderToString`
cannot wait for it). A new choice of brand, category or warranty takes an active one; the product's own stays shown
whatever its state. What a draft lacks to be made ready is `Readiness`'s rules worked out from the page's own
reads - `CatalogProductReadsTest` holds the two to the same answers. What the reader may do is asked as
the handlers check it (`ProductReaders::may`): a product's shared data needs the job in every store
where it is on, a product on nowhere in some store; whoever may not is told once, at the top, and every
control is out of reach.

**Photos** (P5, P8): uploaded on the page (`UploadProductPhoto`, sent as `photos[]` after the ids kept
as `media_ids[]`; a variant's only for a variant of that product, else nothing is uploaded), shown as Attachment tiles with their sizes' state - each read together, one query
for their states (`PlatformApi::mediaOf`, added with this step) and one for their thumbnails
(`Thumbnails`, shared with step 1's pages) - and put in order by dragging (`SortableList`'s grid);
each change saved at once. Search words, related products and a gallery save as they change; the
details and the filters with their own Save, out of reach until something changed.

**The query budget**: each page's own queries are recorded in `CatalogProductScreensTest` - the list 5
(one store chosen 5), a product's Details 4, Variants 5, Photos 3, Search and Filters 5, Related with a
search 5 (finding one or many) - and the same again with more of every row a page shows:
products, variants with photos, gallery photos, filters, search words, related products. They were
15, 15, 12, 13, 11, 13 and 13 until #108 made a request read the stores and the reader's permissions
once (access.md amendment 65); the list was then at the budget, with nothing to spare. The panel's
frame around them is as step 1 says.

### The screens: each store's rows and the store file (screens step 3)

**A product's Stores tab** (S9 "Stores", P6): a card for each store the reader covers - a Super
Admin's off stores too, marked Off. A store that never chose the product offers only **Switch On**
(a ready product only, else the reason). One that did shows the whole product's On switch (on while
every variant not archived is on, saying "n of m"), a variant table with On, Retail, Wholesale and
Not Available Now switches, the product's own Not Available Now, the quantities with Save Quantities,
the labels as their badges with Labels… (10 at most), and the note that prices and stock come with
Pricing and Inventory. Each control works for whoever holds its job in that store; anyone else sees
its state, out of reach, and is told why - the jobs they lack there, or "read only" when they hold none
of the four; a variant's other switches wait until it is on there, and say so. A label deactivated
since it was attached stays on the card, marked Off, and is kept when the labels are saved - it can be
taken off, not put back (§1.8, amendment 4). A switch saves as it flips; a Retail or Wholesale switch sends the stored
quantities with it, as `SetSellingTerms` keeps them all at once. The store is named in the address
by its code (`/admin/products/{p}/stores/{code}/…`) - **an off store's too** (`StoreCodes`): Platform's
`storeByCode` answers stores that are on only, and read alone it left a Super Admin unable to prepare
an off store from either screen (found by the review of this step). The tab reads every store's rows in one query
each - 8 of its own, recorded in `CatalogStoreScreensTest` with more stores, variants and labels.

**Store Files** (S10; amendment 6(g), (h)): the admins' job, `catalog.listing.fill`, in its menu entry.
A store's files, newest first, 25 a page with Show More - name, when, items, items still open
(`ImportSummary::open`) - for the store chosen in the page's own filter among those where the reader
fills stores. **Upload Store File** takes the `.json`; a refused file's problems come back once,
flashed with the list's next view (at most 500), in the format's own words - English, as the format is
- and are listed under the upload in its dialog. A file's page shows each item: number, code, its
product (named, linked to its page), price, stock, and where it stands - Ready, Not Ready and what it
lacks, Archived, Already On, Unknown Code - or what was done (Switched On, Removed), still naming its
product; ticked open items are switched on (ready ones only, said before the press) or removed, every
ready one switched on at once, an unknown code corrected.

**Read in batches** (P13): a store file's page asks its items, then every open code in one query
(`StoreFillCodes`: the product holding it, the variant carrying it, archived or not, on in the store or
not - a variant's code is always one its product holds, by the database's foreign key), the products
holding them through the product page's own core read, many at once (`CatalogProductReads::cores`),
and the drafts' photos in one Platform read. What a draft lacks is the product page's rules, now one
class (`ProductMissing`). `CatalogImportPagesTest` holds a 50-item file to the same queries as a 3-item
one, within 15; the old per-item reads asked five an item. `CatalogStoreScreensTest` records the
pages' own: a file 8, with a ready product's code, a draft's with a photo, an archived product's and
unknown ones, however many; the list 4.

**The import guide** the upload links to came with step 4: one page for both formats.

**Found while building**: the panel's breadcrumb on a phone could not shrink, so a long page name - a
store file's - pushed the page wider than the screen; the breadcrumb and its last crumb may shrink
now, and the name is cut short (`AdminLayout`).

### The screens: the products import (screens step 4)

**Products Import** (S11, menu entry 100): the Super Admin's alone (`catalog.import.run`) - the first
menu entry under a reserved permission (Access's `PermissionGroup` note says so now). The files,
newest first: name, who uploaded it (Platform's `StaffNames`), when, products, state; **Upload
Products File** takes a `.json` or a `.zip` and opens the import's page; a refused file's problems are
listed under the upload, as a store file's are.

**One import** - `/admin/imports/{id}?part=` - in **four parts, each a page of its own** (P12;
lesson 180), the open part's data only (`ImportPages`):
- **Names** - grouped by kind; per row It Means… (a combobox of the active items of that kind; a
  value's, its attribute's values - the attribute decided first), Create It… (a value or a category:
  its names, a category's own addresses) or Refuse It; ticked rows of one kind take It Means… or
  Refuse It together.
- **Codes** - the catalog's product named and linked, on sale or not; Update · Replace Whole · Skip
  · New Codes… and, on sale, Keep on Sale or Take Off Sale, each saved as it is chosen.
- **Before Bringing In** - brand, category, warranty, search words, filter values, to all or the
  ticked products, Replace · Only Fill Empty (· Add); the web addresses that would collide, each
  given its own.
- **Products** - a hundred at a time (P13), each with its state and what it still lacks; once in,
  Accept, Archive…, Delete… (the file's name typed, P10) and Accept Every Ready One.

**The main action follows the state**: Bring Products In while deciding, out of reach with what
still waits; while bringing in, the page asks again every three seconds (P12); failed, the reason.
**Discard Import…** asks for the file's name typed (P10). **What still waits is counted as Bring
Products In counts it**, from the same view (`ImportPages::waiting`) - `CatalogImportScreensTest`
holds the two to the same numbers.

**Read in batches** (P13): `ViewImport` read the catalog three times for every product (its product,
what it lacked, whether the product its codes find is on sale); it now reads the products brought in
through the product page's own core read, many at once, what the drafts lack with their photos in one
Platform read (`ProductMissing::ofDrafts`, shared with the store file's page), and which catalog
products are on sale in one query (`CatalogProductReads::onSaleAmong`). Each part's own queries are
recorded, the same for 3 products and for 120: names 9, codes 6, before 8, products 6, the list 3 - and
the same for 2 products the file updates and for 12 (their codes and attributes read together).

**The files' guide** (`/admin/import-guide`, linked from S10's and S11's uploads): the repository's
own `docs/modules/catalog-import/README.md` rendered on the server (CommonMark, raw HTML stripped),
its links to the two example files pointing at their downloads - whoever fills a file opens it
(`catalog.listing.fill` in some store, or `catalog.import.run`). **Assumption for hosting**: the
`docs/` folder ships with the application, as the guide is read from it.

### The shop's audiences (screens step 5)

Amendment 14 (catalog.md §1.13): in each store, admins choose which categories individuals see and
which companies see; individuals buy retail only.

**Who is looking** is Platform's `ShopperType` (`current(): ShopperKind` — `GUEST`, `INDIVIDUAL`,
`COMPANY`), which **Access binds** from the signed-in customer's account type (access.md amendment 67;
platform.md §9.4): a company account is a company whatever its company's state; nobody signed in, a
staff member viewing the shop and the system are guests. Platform's own answer, unbound, is a guest.
Catalog never reaches into Access for it.

**The switches** (`catalog.store_category_audiences`, one row a store and a category someone switched
there — **no row means both**, so a new category and a store opened later need nothing):
`SetCategoryAudiences` — `catalog.category.audience` **in that store**, declared `adminOnly` (14(c)),
an off store prepared as its menu order is — writes the switch flipped (Individuals, Companies or both;
a switch left null stays) **to the category and to every category below it**; the other switch stays
as each has it, so a sub-category switched on its own keeps it (14(d)). It authorizes as its sibling
store changes do (`StoreListingChange::authorize`), then takes **the products' lock, then the
categories'** (`StoreListingChange::runAfterProducts`): the listing is written under the first, the tree
read under the second. Each category whose switches changed is audited in that store
(`catalog.category.audience_set`, from what was to what is); nothing changed, nothing is written or
recorded. The listing rows of the products in the categories that changed are written in the same
transaction. Each store's rows are written through `CategoryAudienceRepository`, which names the
store in every call (amendment 4(h)) but one: `inEveryStore`, for P24; the listing's writer and the
screen's read take them in their own one query (§5.2 allows reads across stores where a rule needs
them).

**P24** (`CategoryAudiences::takeParents`): a sub-category **added** under a parent, and a branch
**moved** under one, take the parent's switches in every store where either has a row, each store's
change audited there — inside `AddCategory`'s and `MoveCategory`'s own transactions, under the
categories' lock, before a move writes its products' rows.

**The listing** (§5.4) gains, per store and product: `for_individuals` — its own category on for
individuals there **and** a variant sold retail there (14(e)); `for_companies` — its own category on
for companies; `sells_retail`, `sells_wholesale` — any variant on sale there sold that way;
`card_variant_id` — **the first variant, in the product's order (position, then id), on sale there**,
companies' and guests' card (§2.2); `retail_card_variant_id` — the first sold retail, individuals' card
(14(f)), null exactly when nothing is sold retail. Two CHECKs hold the writer's rules
(`listing_individuals_retail`, `listing_retail_card`). **Setting a product's selling terms now writes
its rows** (it did not need to before). The migration fills the rows already there from the stores'
rows as the writer would; nobody has switched anything then.

**The read API for the shop's pages (step 6).** Every method of `ShopCatalog` and `ShopSearch` takes the
viewer right after the language — `ShopperKind $viewer` — and **the page asks Platform's
`ShopperType::current()` for it, once a request**, and passes it in:

| Read | Answers for the viewer |
|---|---|
| `menu($store, $locale, $viewer)` | the categories with something listed **for this viewer** in them or below them |
| `category($store, $locale, $viewer, $slug, …)` | its cards for this viewer; **no page (null)** when it lists nothing for them |
| `brand($store, $locale, $viewer, $slug, …)` | its cards for this viewer; an active brand keeps its page |
| `product($store, $locale, $viewer, $slug)` | available only while this viewer sees it — otherwise the "Not available now" page (P18); its `variants` **retail ones only for an individual** (14(e)); both sides for a company or a guest (§4.5) |
| `relations($store, $locale, $viewer, $productId)` | only what this viewer sees |
| `ShopSearch::suggest(…, $viewer, $typed)`, `results(…, $viewer, $typed)` | only what this viewer sees; a submitted search is logged with how many this viewer found |

An individual sees a row with `for_individuals`, a company one with `for_companies`, **a guest one with
either** (P25) — so a category switched off for both is seen by no one. Every `ProductCard` carries
**`variantId`, the variant its card shows this viewer**: `retail_card_variant_id` for an individual,
`card_variant_id` for a company or a guest — a guest is on the companies' side, both sides and the
companies' card (§2.2, §4.5, §8 #33), wherever P25 lets it see the product (the owner's decision below).
Who is looking costs no query of its own: it narrows the
same reads (`CatalogShopAudiencesTest` records each read's count, the same for every viewer); asking
`ShopperType` costs one query for a signed-in customer, none for a guest.

**The screen** (S2): the categories page's store filter offers the stores where the reader orders the
menu **or** chooses who sees the categories (`ListCategoriesHandler::STORE_JOBS`), and the page says
which they may do there (`mayRank`, `mayChooseAudience`, each asked for its own job). For the store
shown, each row carries its two switches (`forIndividuals`, `forCompanies`; a store that never
switched it, both), read in the tree's one query. **Individuals** and **Companies** are two columns of
switches (`Categories/AudienceSwitch.tsx`), each posting to `catalog.admin.categories.audience` the
moment it flips (P9) with the store shown; the page comes back with every row as the server has it,
so a parent's switch shows on everything under it. Someone who may not choose there sees them in
place, out of reach, the reason in a tooltip and for a screen reader. The page's own queries stay 3
(`CatalogCategoryAudiencesScreenTest`, as `CatalogListScreensTest` records them). **Its script**: with
the switches and #121's checks the page measured 61.9 KB gzipped, over the 60 KB budget; the fates
dialog, opened only when a category is deactivated and never drawn by the server, is now fetched when
it opens (`React.lazy`), and the page is 57.7 KB.

**Left to step 6** (P22, with the shop's pages): the card's price, its old price and "Last pieces" per
side — a guest's on the companies' side, as its card — and the card's variant narrowed by "orderable
now" and "priced" once the listing reads `store_variant_facts`; the Retail/Wholesale filter (P14) from
`sells_retail` / `sells_wholesale`; and the filter values (`value_ids`), which still hold every variant
on sale for every viewer.

**Decided by the owner, 2026-10-11** (asked after the independent review): **a guest on a product only
individuals see** — "Guest sees all individuals+companies". A guest sees every product either kind of
account sees, with its retail and wholesale sizes, and the companies' card (§2.2, §4.5/P17, §8 #33,
sales.md §9.1 #2), a product whose category is on for individuals only included. As built; written
into catalog.md §1.13 beside P25. (The other reading — a guest shown each product as the side that
sees it — was tried on this branch and reverted.)

**My picks** (the spec is silent; each the conservative choice, for the owner):

1. **A category moved to the top keeps its own switches** — it has no parent to take them from (P24
   speaks of a parent), so nothing it hid shows by surprise.
2. **The switches P24 copies are audited** in each store, as the same action as switching there.
3. **The listing keeps each side's card variant** (`card_variant_id`, `retail_card_variant_id`), so a
   card says which variant it shows before prices arrive; step 6 puts that variant's price beside it.
4. **A switch can be set on a deactivated category**, as its menu order can: it shows nothing anyway.
5. **A command with neither switch is refused** (`InvalidCatalogAttribute` on `audience`), not taken
   as "nothing to do".
6. **A submitted search is logged with how many this viewer found** (the log still names no person):
   an individual's search for words whose products are hidden from individuals lands in the
   zero-result list staff read for word pairs. Logging what any side would find instead would take
   one more count a search.

### The shop's pages (screens step 6)

catalog.md §4.5 (P14–P22), amendment 14 as step 5 built it, 16(h) "Last pieces". No price, no stock,
no Add to Cart, no code anywhere.

- **Addresses** `storefront.catalog.{category,brand,product,search,suggest}` (`shop-routes.php`,
  `ShopPagesController`): an old slug 301s to the current one with the query kept; a slug that never
  existed, a draft, a category listing nothing here, an inactive brand — and a made-up filter or page
  cursor — 404 in the shop's frame (`Catalog/Storefront/NotFound`). Every page carries its canonical
  address and the other language's (`ShopAddressData`, drawn in `StorefrontLayout`'s head and its
  language switch).
- **Who is looking**: `ShopVisit` (scoped) asks `ShopperType::current()` once a request and hands it to
  every read. The controller asks for `ShopPages` per action, never in its constructor: the router
  keeps a controller as long as its route, and the builder holds the request's viewer.
- **The frame**: Platform's `ShopFrameParts` / `ShopFramePart` (Public), shared as `frame` by
  `ShareStorefront` on every page under a store; Catalog registers `CatalogFramePart` — the menu and
  the words of the menu and search box. `StorefrontLayout` draws the search box, the Navigation Menu
  bar (All Categories + the top categories) and, on a phone, the Sheet with the tree folding.
- **The cache** (`ShopPagesCache`, scoped, on the kernel's `VersionedCache` with a new optional part
  key and a version a caller may pass): the menu per store, base store, language and viewer; a
  product's page per store, language, viewer and product (its path is drawn from the viewer's menu when
  shown, never kept). The version is read once a request. One version, replaced by
  `DatabaseListLocks::lock()` **before** the advisory lock — every Catalog change takes one (the word
  pairs' lock excepted: search is never kept) — by each `ListingFacts` push, and when migrations end.
  **The price**: every Catalog change also waits on that one row while another holds it, as every
  product change already waited on the products' lock; a path that locks another row first (deleting
  a photo) meets the version where it met the products' lock before. `PruneShopPagesJob` (hourly,
  queued) removes old versions' rows once expired: the database store deletes an expired row only when
  its own key is asked for again.
- **Access**: `RequestCustomers` (scoped) reads the signed-in customer once a request for both the
  frame (`ShareStorefrontPage`) and who is looking (`CustomerShopperType`) — the frame part's question
  made it two reads (ShopFrameTest).
- **The facts** (P22, 16(h)): `catalog.listing` gains `ending_soon_companies`/`_individuals` and each
  side's card price (`price_minor`, `price_before_minor`, `retail_price_*`), written from
  `store_variant_facts` by `DatabaseListingRows`; each push takes the products' lock and writes the
  touched products' rows again.
- **Reads built for the budget**: a category's head with its filters and their counts in one read
  (`categoryHead`), a brand's head in one (`brandHead`), a page of cards with their labels and "Last
  pieces" in one; search results page by a keyset cursor (`SearchCursor`: tier, nearness in
  hundred-thousandths, rank, id), logged on the first page only. An individual is never offered a size
  sold wholesale only, not even as a disabled choice (§1.13).

**Queries, warm** (`ShopPagesTest`, measured 2026-10-12). With the session kept in memory, as the
tests keep it: category 6, brand 6 (a logo adds 1), product 6, search 7, suggestions 4, sign-in 6 (was
4: the menu's 2). **As production serves it** — the session's row read and written in the database:
a guest's category 8, brand 8, product 8, **search 9**; a signed-in individual's or company's **13, 13,
13 and 14** (Access's reads of who they are add 5). Over 8: for the owner (P21).

**JavaScript** (gzipped, own): category 29.3 KB, product 27.8, brand 26.4, search 24.4, not found 21.7;
shared 185.3 of 200. The shop's Sheet and Breadcrumb are the panel's too, and sharing them split them
out of the panel's layout chunk: the panel's Account page went 59.8 → 60.6 KB, over its 60. Its phone
dialog's code field (`CodeInput`, drawn only in that dialog's second step) now loads when it is drawn:
56.3 KB. Nearest the line after this branch: B2B's Company page 59.2, Products' VariantsTab 59.1.

**My picks** (the spec is silent; for the owner):

1. **The search box draws a plain field first** (a form: Enter searches before any script) and loads
   the suggestions' half — shadcn's Command in a Popover — when the shopper reaches for it, so no shop
   page carries it before it shows; "See All Results" is the chosen item, so Enter opens the results.
2. **Filters**: several of one kind show any of them, two kinds both; each choice's count keeps the
   other kinds' choices and leaves its own out; a choice that would show nothing is disabled unless
   picked; a choice the page no longer offers (a brand not listed here, a value switched off, a side
   nothing here is sold on) counts as not made, in the cards as in the chips. Lists are
   comma-separated in the address (`brands`, `values`, `side`, `sort`); a 301 keeps them, never
   `store` or `locale`.
3. **A filtered or sorted category's canonical address is the plain one.**
4. **Search results pages are `noindex`**; their canonical keeps the query.
5. **"Last pieces" on a product's choice** marks the value whose pick leads to an ending-soon size, so
   with one attribute exactly that size's button; with none, beside the name.
6. **The brand's page has no filters or sort**; the menu draws no category images.
7. **Show More keeps the address on the first page** (`useLoadMore`), as the panel's lists do.

**Left** (for the owner): the card's variant is not yet narrowed by "orderable now" or "priced" —
nothing pushes those yet (stage 5); and `value_ids` still hold every variant on sale, so an
individual's filter can offer a value only a wholesale-only size has (the product it shows is theirs).

## Tests

| File | What it covers |
|---|---|
| `Unit/CatalogValuesTest` | Text on one line, names, slugs (Arabic and accented Latin), structured text, Arabic normalisation, a label's words and looks, word pairs, warranty periods; Arabic digits saved 0-9 in each (amendment 12) |
| `Unit/CatalogErrorsTest` | Every error has a unique `catalog.*` type, a category, and a title and detail in both languages |
| `Integration/CatalogBrandsTest` | The seed; who may; slugs and their history; the one default; deactivating and deleting; the database's CHECKs; each brand's number, the lowest free (amendment 10(a)) |
| `Integration/CatalogCategoriesTest` | Who may change the tree and who a store's order; a category added or moved last among its siblings in every store, an off store included, after each store's own places (the base store's where it has none), numbered again at the cap, every place written audited in its store; moving and loops; deactivating and activating exactly what went; deleting; a store opened later starting with no order; `OrderCategories` — the ids sent first and the rest after them as the store showed them, a move made since on a page left open kept, a sibling showing the base store's place given its own, nothing recorded for an order that moves nothing, its refusals; the database's CHECKs |
| `Integration/CatalogAttributesTest` | Jobs locked by values and by products' variant attributes; values alike ignoring case; swatches; the database's CHECKs |
| `Integration/CatalogVariantAttributesTest` | Has Variants: the first attribute, every variant's value (archived too), at most ten, removing while the variants stay apart, their order, a value made from the product, the migration from sets |
| `Integration/CatalogSmallListsTest` | Labels, warranties and word pairs, including a pair the database's language order would sort the other way |
| `Integration/CatalogImagesUsageTest` | Deleting a logo or photo's file: detached and audited, or refused with nothing changed |
| `Integration/CatalogAuditNamesTest` | Every action the code records is named in both languages, and nothing else is |
| `Integration/CatalogProductsTest` | Creating and editing a product: who may, the job in some store that is on (amendment 13(f)), names and slugs, what it points at, deleting a draft, the database's CHECKs |
| `Integration/CatalogVariantsTest` | Combinations, details and measures; codes held, one variant's each, taken, taken back, freed by a draft and corrected on the one variant; the migration to a code per variant; a name, slug, code and detail typed in Arabic digits saved 0-9 |
| `Integration/CatalogListsInUseTest` | A list item a product or variant uses: not deleted, a category's sub-categories, an attribute a product makes its variants of, an attribute's job |
| `Integration/CatalogProductPartsTest` | Gallery and variant photos, search words, filter values, relations; a photo's file deleted from the media library |
| `Integration/CatalogProductStagesTest` | Making ready, archiving and restoring a product and a variant; a ready product keeping every rule; each change's own job; the events; what the audit log keeps |
| `Integration/CatalogProductConstraintsTest` | The products' named CHECKs, indexes and keys that no handler test reaches, each refusing a row written past the code |
| `Integration/CatalogStoreListingsTest` | Who may choose in which store, an off store prepared; a whole product or single variants; selling terms; "Not available now"; labels; archiving switching off everywhere; a product's shared data needing the job in every store that sells it |
| `Integration/CatalogDeactivationsTest` | A category or brand deactivated with each product's fate, all or nothing; activating bringing back what hid with it |
| `Integration/CatalogListGuardsTest` | For every one of the sixty-four list, product and store changes: its lock is the first query inside its own transaction (the products' before its list's, for the eight that change products or their listing rows — choosing who sees a category among them), nothing of the catalog read before it; an id not in its list is answered as not found; and what the audit log keeps reads from what was to what is. For the thirty-nine that can be asked to change nothing: nothing written to the lists, nothing recorded |
| `Integration/CatalogListOrdersTest` | Every ordered list's order (amendment 16(d)): the ids sent first, every other after them, written 1, 2, 3 …; each moved item audited and none that stayed; refused without the job with All stores; an id sent twice refused before anything is read; one attribute's values and no other's; the screen's own read and the order agreeing where places are tied; a deactivated item ordered as any other; a new item last, after an order too; the list numbered again at the cap; the warranties' migration numbering them in name order; the places typed before written again 1, 2, 3 in the order each list showed |
| `Integration/CatalogListConstraintsTest` | The database's named CHECKs, indexes and keys that no handler test reaches, each refusing a row written past the code; an id that is not a ULID never reaching the database |
| `Integration/CatalogListingTest` | Which products have rows, and what a row holds — never a code; each of thirty-one changes writing exactly what the repair writes, eight that alter nothing writing nothing; a row that stays updated in place; the card photo; the repair and its locks |
| `Integration/CatalogShopTest` | The menu per store and its order, a secondary brand's own category in it; a category's page by keyset, ties included, every brand, the brand filter, labels in the list's order, slugs moved; a brand's page; a product's page or "Not available now"; suggestions |
| `Integration/CatalogSearchTest` | The ranking and the total; both languages, the other's name exact; Arabic normalised, a typo near; word pairs only adding; what is found and what never is — a secondary brand's product; the search log and its nightly removal, to the second |
| `Integration/CatalogApiTest` | `CatalogApi`: variants, codes (one corrected away), products, a variant in a store (switched off, hidden), resolving the variant (a product with no set) |
| `Integration/CatalogUsagesTest` | The usage check before deletes (amendment 19): a category, brand, draft product (and its variants) and draft variant another module uses refused, nothing deleted; what nobody uses deleted after asking |
| `Integration/CatalogApiReadsTest` | Stage 5's reads: a product's variants in order (archived when asked), a category's products and those below it, a store's switched-on variants, many variants and products in a fixed number of queries; a cart's line facts in one query (amendment 18); a brand and whether it is on (amendment 20), many brands in one read (amendment 27); which products a store sells now, in one read (amendment 24); `CategoryMoved` |
| `Integration/CatalogListingFactsTest` | `ListingFacts` kept, each fact apart, a price cleared, `ListingPrice`'s rule; `ImportSections`: once each, lines on a store file's page, each item switched on given to the sections |
| `Integration/CatalogSalesRanksTest` | Amendment 30: a store's ranking on its listing rows, the other stores untouched, a product left out losing its rank, a rewritten row taking it back from `sales_ranks`, unknown ids and ranks below 1 left out |
| `Unit/CatalogListLocksTest` | A list's lock is refused outside a transaction |
| `Unit/CatalogListingRowsTest` | The listing is never written outside a transaction |
| `Unit/CatalogSearchTermsTest` | What was typed, as search reads it; word pairs, a run of words before one |
| `Unit/CatalogProductTest` | A product archived remembers the stage it left — archived twice or not — and restoring goes back there |
| `Unit/CatalogImportFilesTest` | Step 6's two files read and checked: the guide's own examples, every rule refused with where, every problem collected, descriptions' markers; Arabic digits in either file read as 0-9 |
| `Integration/CatalogImportUploadTest` | Uploading: who may; names listed once with their counts, matched as search compares words, a brand by number; what refuses a file; a zip kept, its products.json at the top, photos unpacked into the archives' own files |
| `Integration/CatalogImportDecisionsTest` | Deciding names and codes: targets in their list, active, of the right job; what may be created; values under their attribute; new codes free; all or none; closed while bringing in |
| `Integration/CatalogImportChangesTest` | The changes before bringing in: each field, replace and fill-empty (and add), the selected or all, the file's own kept, the names list following |
| `Integration/CatalogImportBringInTest` | The confirm asking again; bringing in: lists made, products created with photos, skipped, held, recoded, updated, replaced; all or nothing with the reason; the locks' order |
| `Integration/CatalogImportAcceptTest` | Accepting (ready, on sale nowhere new, relations), archiving and deleting only what the import created |
| `Integration/CatalogStoreFillTest` | The store file: an admin role's job in that store; switching on the variant carrying each code of a ready product; mending and removing items |
| `Integration/CatalogImportPagesTest` | The pages' reads: a products file's page and list, a store file's page with each item's standing, and its store's list |
| `Integration/CatalogImportSaleTest` | Amendment 9: no store in a products file; keep on sale or take off sale, every time, in every store, cleared with its decision, asked again when put on sale after the confirm; codes two catalog products hold; words added to an updated product kept apart; a new category's taken address counted; a job given up on taking the products' lock first |
| `Integration/CatalogImportConstraintsTest` | The database's named CHECKs of the import's and the listing's tables (steps 5 and 6), each refused by name — those on a nullable column with it left NULL (lesson 35) |
| `Integration/CatalogQueuedWorkTest` | Bringing an import in and pruning the search log run as the queue runs them: on the "sync" queue, the system acting for whoever queued them |
| `Integration/CatalogModulePassTest` | Step 7, the reviews of the whole module (amendment 11): a category picked for a product's path has no sub-categories; archive and delete on the import's page whatever was done since — a product made ready and put on sale since deleted whole, unlinked first; the confirm catching a code a product not a draft keeps, a draft taking the file's; a zip of too many entries |
| `Integration/CatalogImportDiscardTest` | Amendment 10(b): a file not brought in discarded whole with its zip, deciding or failed; one bringing in or brought in stays; a store's file is not one; a Super Admin's |
| `Integration/CatalogImportReviewTest` | After step 6's reviews (amendment 8): ambiguous names and colliding addresses decided on the page; the confirm asking again; holding back, replacing whole, updating and restoring; the page's picks brought in, filling and adding counted against an updated product as it is when brought in; a job given up on; the zip's sizes; linking back on accepting, by any code held; the store file kept to its store |
| `Integration/CatalogPermissionsTest`, `CatalogSchemaTest` | Step 1's permissions and schema; the store file's job and who sees a category, admin roles only (amendments 6(h), 14(c)) |
| `Integration/CatalogShopAudiencesTest` | Screens step 5 (amendment 14): who may switch — an admin role's job in that store only, refusals, an off store prepared, the products' lock then the categories' first; a parent's switch reaching everything under it, the other switch kept, a sub-category alone; the audit, nothing written when nothing changes; P24 on adding and moving, the top keeping its own; a store opened later showing everything to both; each change writing the listing as the repair does; who sees a product and each side's card; the two CHECKs; the menu, pages, search, suggestions and relations for each viewer, P25, a guest on the companies' side even where only individuals see a product (the owner's decision, 2026-10-11), wholesale-only (14(e)), the card's variant (14(f)), a company account through `ShopperType`; each read's recorded queries, the same for every viewer |
| `Feature/CatalogCategoryAudiencesScreenTest` | The categories screen's switches over HTTP: what the page is handed for the store shown, the stores offered and what the reader may do there, the menu entry, the page's recorded queries; a switch saved for the store shown and reaching below, refused without the job there, with no store, no switch or one neither on nor off |
| `tests/Browser/CatalogCategoryAudiencesTest.php` | In a real browser: an admin switches a category off for individuals and the one under it follows; the switches out of reach, with the reason said, for someone who only orders the menu; the fates dialog, now fetched when opened, deactivating a category; Arabic at a phone's width |
| `Feature/ShopPagesTest` | Screens step 6 (§4.5): a category's page — path, cards, filters, both addresses — and the frame's menu; 301 with the query kept; 404 in the frame for a slug never made, a draft, a category listing nothing here or in another store, a made-up filter or cursor, an inactive brand; the menu on the account pages, none on the country page; a product's page with its choices, how it is sold and no code; not available now for a viewer's account type (P18, `noindex`); never stale after a change; "Last pieces" per side, and not when not orderable; the filters' counts, Retail/Wholesale, a value; the sorts; the search logged once, the suggestions never; no wholesale-only size offered to an individual; a choice not offered counted as not made; the 301 never setting the store or language; a product's path from the viewer's menu; the hourly prune; each page's recorded queries, warm, in memory and as production serves it for a guest, an individual and a company |
| `tests/Browser/ShopPagesTest.php` | In a real browser: All Categories to a sub-category, its card to the product, a choice picked; the search box suggesting as the shopper types and Enter opening the results (logged once); Arabic at a phone's width — the menu's Sheet from the right, the pages right to left, nothing wider than the screen |
| `tests/Modules/Access/Feature/ShopperTypeTest.php` | Platform's `ShopperType` as Access binds it: a guest, an individual, a company account that never applied, and one whose company is pending, rejected, suspended or approved; a guest during a staff view over a signed-in customer; one query a customer, none a guest |
| `tests/Architecture/CatalogAccessUseTest.php` | Catalog references nothing of Access beyond the five permission-declaration classes |
| `Integration/CatalogListReadsTest` | The list screens' reads: who may read and who may change, refused before anything is read; each list's rows; a fixed number of queries however long the list; a store's places beside the base store's; the searches that found nothing, grouped and paged; the products a deactivation reaches; Platform's photos read together; descriptions' marks both ways |
| `Integration/CatalogProductReadsTest` | The products screens' reads: every product to a reader in some store, refused before reading to anyone else; where each is on among the stores covered; finding by name, by a code in any digits, by stage, category and brand; one store's state and narrowing by it; pages by keyset in a fixed number of queries; one product above its tabs; what a draft lacks, the same as `Readiness`; each tab's own data - a variant's details, size and photos, the photos' states read together; what the reader may do, as the handlers check it |
| `Feature/CatalogProductScreensTest` | The products screens over HTTP: who opens the list and a product's page, and the menu; the store filter; finding (any digits, `%` and `_` as characters, bytes that are not text) and the next page; the filters as applied; every brand and category offered, active or not; Add Product offered only with the job in a store that is on, and a draft added with no store; the codes the variants carry; what the reader may do; an archived product; every tab's change, said where the panel says it, refused - nothing uploaded - without the job in every store where the product is on; photos uploaded, ordered, removed and refused, a variant's only for that product's variant; making ready, archiving, restoring, deleting a draft; each page's own queries, recorded |
| `Feature/CatalogStoreScreensTest` | A product's Stores tab over HTTP: a card per store covered, a Super Admin's off stores marked, what the reader may do in each; switching on and off, selling, not available now, labels (10 at most), each refused where the reader holds no job; the store files: the list with what is still open, the upload and a refused file's problems said once, a file's page, switching on the selected and every ready one, removing, correcting a code; each page's own queries, recorded |
| `Feature/CatalogImportScreensTest` | The products import over HTTP: the Super Admin's alone; the files newest first with who uploaded each; the upload and a refused file's problems said once; each part a page of its own; a name decided, a code decided with the catalog's product named, a change before bringing in; what still waits counted as Bring Products In counts it; bringing in, the products part a hundred at a time, archiving, discarding; the guide and its examples for whoever fills a file; each part's own queries, recorded |
| `tests/Browser/CatalogImportScreensTest.php` | The products import in a real browser: a name refused from its row, the products brought in once nothing waits, the parts moved between; every page of it, and the guide, in Arabic at a phone's width |
| `tests/Browser/CatalogStoreScreensTest.php` | The store screens in a real browser: a ready product switched on in a store from its Stores tab and off again; a store file's ticked item switched on; both pages and the files list in Arabic at a phone's width |
| `tests/Browser/CatalogProductScreensTest.php` | The products screens in a real browser: adding a draft opens its page saying what it lacks; tabs in the address, each fetched as it opens; details saved once changed; a search word added; a variant added from its dialog, shown in its row; Arabic at a phone's width |
| `Feature/CatalogListScreensTest` | The seven screens over HTTP: the menu; who opens each page; the categories' store filter; every form's success and refusal, said beside its field or at the top; a logo and a photo uploaded from their forms; the fates; each list's order saved as dropped and moved to the top or the bottom, and refused; each page's own queries, recorded |
| `tests/Browser/CatalogListScreensTest.php` | The screens in a real browser: adding from each dialog, the fates dialog reading the products before its button, a branch of the tree opened, a label's look, a warranty for life, a word pair added and deleted, a page in Arabic at a phone's width; a brand dragged by its handle and another moved to the top, and a parent's sub-categories dragged in one store's order, each saved when dropped |

`CatalogListGuardsTest` records every query to check each change takes its lock first, inside its
own transaction (level 2 under `RefreshDatabase`); `CatalogFixtures::lockedTables` reads from the
same record which rows a change locked, in order. `Support/CatalogProducts` makes the products,
variants and the lists they use, as the system; `Support/CatalogImports` writes, uploads, decides and
brings in files for step 6's tests. Run everything with `composer check`.
The test database is `touchwood_test`.
