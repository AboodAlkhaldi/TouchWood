# Catalog — Module Specification

> **Written with the owner on 2026-10-02.** The owner's answers are recorded as **[DECIDED
> 2026-10-02]** and listed in §9.1. **[ACCEPTED 2026-10-02, §9.3 #n]** and **[ACCEPTED 2026-10-02,
> §9.5 #n]** mark proposals of mine the owner accepted (§9.3 #10 replaced by the owner's own answer).
> **One section is not written yet:** the JSON import's file format, which follows the product
> tables once they are built; the import is built last (§1.12, §9.2).

**Status:** **APPROVED** by the owner, 2026-10-02 (§9.1 #43), complete but for the import's file
format; **being built** from 2026-10-02 (`src/Modules/Catalog/README.md`). Changes from here on are
amendments and need the owner's agreement.
**Tier:** 1 (commerce core). **Build stage:** 4 (handoff §17).
**Depends on:** Platform, and Access's public surface for declaring permissions only (§2.4,
**[DECIDED 2026-10-02]** — a change to handoff §4.4 and `deptrac.yaml`).
**Needs from shared plumbing:** nothing new. Catalog consumes events (§6.2), but each of its listeners
is written to do its work once however often it runs, as B2B's are, so `processed_events` is still not
needed **[ACCEPTED 2026-10-02, §9.3 #1]**.
**Source:** `docs/HANDOFF.md` §1, §2, §4, §5, §6, §9, §12, §14–§18; `docs/modules/platform.md`;
`docs/modules/access.md`; `docs/modules/b2b.md` (the shape this spec follows); the merged code on
`main` at `d1aa16d`; the owner's answers of 2026-10-02 (§9.1).

Catalog owns **what is sold, and where**: products and their variants, the codes that name them, the
category tree, brands, attributes, labels, warranties, each store's choice of what it sells, search,
and the JSON import. It owns nothing about **how many** (Inventory) or **for how much** (Pricing).

**Delivery [DECIDED 2026-10-02]:** backend first — domain, tables, use cases with their permissions,
read models, the public contract, events, the import's engine, and tests. The admin and storefront
**HTTP endpoints come with their Geist screens**, after the Geist foundation (handoff §17,
frontend.md §1.8), as Access's did (access.md amendment 12), so nothing is built twice.

---

## What this module does not own

| Concern | Owner |
|---|---|
| Every price: base, sale, wholesale tiers, company and campaign prices | Pricing (stage 5) |
| Stock, movements, reservations; the stock-dependent flag, the low-stock threshold and alerts, the "ending soon" switch | Inventory (stage 5) — **[DECIDED 2026-10-02]** |
| The provider's feed (stock and base price by code), the unknown-codes report | Sync (stage 5) |
| Category discounts | Pricing (the design's "Category discounts" section) |
| The smart bar above listings, banners, campaigns and their themes | Content (stage 8) |
| Reviews, ratings, product questions, the wishlist | Feedback (stage 6) |
| Product add-ons ("Product apps"), bundles | Not in this stage (handoff §15.2) — **[DECIDED 2026-10-02]** |
| Carts, orders, the "reduced in the provider" tick | Sales (stage 6) |
| Packaging rules and boxes | Shipping (stage 7) — it reads Catalog's weights and dimensions |
| The reports on searches and stock | Ops (stage 8) — it reads Catalog's search log |
| Stores and their on/off switch, media files, settings, the audit log | Platform |

---

## 1 · Aggregates and invariants

### 1.1 Product

A family of purchasable configurations (handoff §9.1). **Global**: one product, one copy of its data,
for every store. **Every product has at least one variant**; a simple product has exactly one
(handoff §9.1).

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `name` | Arabic and English. **[ACCEPTED 2026-10-02, §9.3 #2]** One line, at most 200 characters each. |
| `slug` | **One Arabic slug and one English slug, used in every store** **[DECIDED 2026-10-02]** — replacing handoff §4.1's "slugs are store-scoped", which disagreed with §9.1. Arabic letters for `ar`, Latin for `en` (handoff §5.2). Unique per language among products. Every slug the product has ever had is kept, so an old address answers with a 301 (handoff §5.2) **[ACCEPTED 2026-10-02, §9.3 #3]**. |
| `description` | Arabic and English. **Simple formatting** **[DECIDED 2026-10-02]**: paragraphs, bullet lists, bold and headings, kept as safe structured text — no raw HTML is stored, so nothing typed can run as a script. **[ACCEPTED 2026-10-02, §9.3 #4]** at most 20,000 characters each. |
| `brand_id` | **Exactly one brand, never none** (handoff §9.4, NOT NULL; **[DECIDED 2026-10-02]**: "no products with no brand"). The form starts on the default brand (§1.6). An inactive brand cannot be chosen. |
| `category_id` | **Exactly one category** **[DECIDED 2026-10-02]**, and only a category with no sub-categories (§1.5). Empty while the product is a draft; required to leave `DRAFT`. |
| `warranty_id` | **At most one warranty**, from the list (§1.9) **[DECIDED 2026-10-02]**, the same in every store. |
| `stage` | `DRAFT`, `READY` or `ARCHIVED` (§4.1) — **one for the product everywhere** **[DECIDED 2026-10-02]**. Each store's own on/off is its choice (§1.3). |
| `search_words` | **Extra words staff type to help search** **[DECIDED 2026-10-02]**, in either language ("slide", "rail"). **[ACCEPTED 2026-10-02, §9.3 #5]** at most 30 words, each one line of at most 50 characters. |
| gallery | **An ordered gallery of photos** (Platform public media) **[DECIDED 2026-10-02]**. **[ACCEPTED 2026-10-02, §9.3 #6]** at most 20. |
| relations | **Hand-picked "Related"** and **hand-picked "Goes with"** products, each ordered **[DECIDED 2026-10-02]** (§1.10). |

**Leaving `DRAFT`** (becoming `READY`) needs **[DECIDED 2026-10-02]**: the name and the slug in both
languages (handoff §5.2), the description in both languages, a brand, a category, at least one
variant that is not archived — each with its code (handoff §9.1) — and at least one gallery photo
whose sizes are ready (Platform `READY`). A product may also carry a **video**: the owner said it may,
optionally, and then **"Leave it for now"** — not in this stage (§9.2).

**While `READY` the same things must stay true** **[ACCEPTED 2026-10-02, §9.3 #7]**: an edit that would take one away
(the last photo, the Arabic description) is refused, so a product shown in a store is never half-made.

**Who may change the product's shared data** — everything in this table, its variants (§1.2), its
gallery, relations and search words — **[DECIDED 2026-10-02]**: **someone holding the permission in
every store where the product is Active** (§1.4), as Access's rule for managing a staff member
(access.md amendment 11). A KSA-only admin edits a product only KSA sells; once Egypt sells it too, it
takes someone covering KSA and Egypt, or a Super Admin. **A product Active in no store** may be
changed by anyone holding the permission in some store. One copy of the data either way; only the
permission check reads which stores sell it.

### 1.2 Variant

One purchasable configuration of a product: a length, a finish.

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `product_id` | Its product; never moves to another. |
| `code` | **The only identifier of a variant** **[DECIDED 2026-10-02]**: it is the SKU customers see on the technical list, the provider's code (handoff §9.1, §12.2), and the import's key. **Unique across every variant of every product** — "no two products will hold the same code even if they were the same product but a different variant" (owner) — **ignoring letter case**, and **never used again**, archived or not **[DECIDED 2026-10-02]**. **Staff may correct a mistyped code** under its own permission, audited; the next provider pull and the next import then match the corrected code; an order keeps the code it was placed with (Sales snapshots it). **[ACCEPTED 2026-10-02, §9.3 #8]** one line, 1 to 64 characters of letters, digits, spaces and `- . _ /`, compared after trimming; **a corrected (mistyped) code stays taken** too: a code that ever named a variant is never given to another. |
| attribute values | One value for each variant-making attribute of the product's attribute set (§1.7), and its informational values. **No two variants of one product have the same combination.** |
| `weight`, `length`, `width`, `height` | **Physical facts, per variant, in Catalog** **[DECIDED 2026-10-02]**, optional now; Shipping reads them and decides later what it requires. **[ACCEPTED 2026-10-02, §9.3 #9]** whole grams and whole millimetres, each 1 to 1,000,000. |
| photos | **Its own ordered photos**, shown when the variant is chosen, falling back to the product's gallery **[DECIDED 2026-10-02]**. They hang off the variant, not its code, so correcting a code leaves them in place. **[ACCEPTED 2026-10-02, §9.3 #6]** at most 10. |
| `is_archived` | **A variant can be archived on its own**, product-wide (a discontinued length), and **restored** **[DECIDED 2026-10-02]**. Archiving makes it Inactive in every store. Its code stays taken. |
| `position` | Its order among the product's variants. |

### 1.3 Store listing — each store's choice

**[DECIDED 2026-10-02]** One shared product table; **each store chooses what it sells** (handoff
§9.1). Price and stock are **not** here: Pricing and Inventory own them, from stage 5.

Per store, per **variant**:

| Attribute | Invariant |
|---|---|
| `is_active` | **The store's choice.** In the panel staff choose **a whole product or single variants** **[DECIDED 2026-10-02]** ("a mix of both … when we add products from the admin panel, not the bulk import"); choosing the whole product makes every variant that is not archived Active. **A variant added later is chosen in no store** until each store chooses it, since it needs its own price and stock there **[DECIDED 2026-10-02]**. Only a `READY` product's variants, not archived, can be Active. |
| `not_available_now` | **"Not available now"** (handoff §9.2's `force_unavailable`): a recall, a pricing error, a legal hold. Its own permission. Does not touch stock. |
| `sells_retail`, `sells_wholesale` | **The selling mode, per variant per store** **[DECIDED 2026-10-02]** (handoff §6: "product enablement per store"): retail, wholesale, or both — at least one. |

Per store, per **product**:

| Attribute | Invariant |
|---|---|
| `not_available_now` | **"Not available now" for the whole product** — every variant, including ones added later **[DECIDED 2026-10-02]**: "both, like the choice". |
| `retail_minimum`, `retail_maximum`, `wholesale_minimum`, `wholesale_maximum` | **Each product has its own minimums and maximums, per store, each selling mode its own** **[DECIDED 2026-10-02]** (the owner's answer to §9.3 #10, which it replaces: "each product has its minimums and maxes"). They limit the quantity of one variant on one order in that mode; Sales refuses outside them (stage 6). Retail's minimum is 1 unless set; each maximum is optional; the wholesale minimum is required while any of the product's variants sells wholesale in the store. Wholesale prices and tiers are Pricing's. **[ACCEPTED 2026-10-02, §9.5 #1]** each a whole number from 1 to 100,000, and a maximum never below its minimum. |
| labels | **Custom labels attached in this store** (§1.8) **[DECIDED 2026-10-02]**. |

**A product is Active in a store** while at least one of its variants is Active there — that is the
row the product page shows per store **[DECIDED 2026-10-02]**: "one single global panel and in it will
show each store's status … from there we can activate, deactivate or deal with the product … a KSA-only
can't edit a product at the Egypt store". **Each store's row is changed only by someone holding the
permission in that store.**

**Archiving a product** makes it Inactive in every store; **restoring it** brings it back `READY`,
Inactive everywhere **[DECIDED 2026-10-02]**.

**Out of stock is never shown** (handoff §9.2). Until Inventory exists (stage 5), "orderable now" in a
store means: the product `READY`, the variant Active there and not archived, and neither the variant
nor the product "Not available now" there **[DECIDED 2026-10-02]**. From stage 5, Inventory pushes
whether each variant is orderable (§2.2).

### 1.4 What a shopper sees — listed, reachable, or neither

A product is **listed** in a store — in its category pages, search results and suggestions — when it
is orderable there now (§1.3) and nothing hides it: its category is active, or the product was
**moved** or **left** when it was deactivated (§1.5); its brand is active, or the product was moved
(§1.6). A product **left** in an inactive category is found by search, its brand page, suggestions and
a direct link, but listed in no category page.

**A direct link** to a product that exists but cannot be ordered in this store — out of stock, "Not
available now", not chosen here, hidden, or archived — shows **one "Not available now" page**: its
name, photo and description, no Add to Cart, hidden from search engines (noindex) **[DECIDED
2026-10-02]**. A slug that never existed answers 404.

### 1.5 Category

**One global tree** (handoff §9.3), nesting without limit.

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `name`, `slug` | Arabic and English, as a product's (one slug per language, all stores, history kept, **[DECIDED 2026-10-02]**). **[ACCEPTED 2026-10-02, §9.3 #2]** name at most 100 characters. |
| `parent_id` | Its parent, or none at the top. Never under itself or anything below it. |
| `is_active` | Deactivated and activated again (below). |
| `rank` per store | **The admin-set order, per store** (handoff §9.3), set by that store's people. |
| image | **[ACCEPTED 2026-10-02, §9.3 #11]** an optional public photo, for category cards (the design's homepage shows them). |

- **Products sit at the end** **[DECIDED 2026-10-02]**: only a category with no sub-categories holds
  products ("a category can hold a subcategory but products come at the end; but also we can make a
  single level, category and products in it"). Adding a sub-category under a category that holds
  products is refused until they are moved.
- **A parent lists everything below it** **[DECIDED 2026-10-02]**.
- **Shown in a store by itself**: a category appears in a store only while something in it, or under
  it, is listed there; an empty one disappears and comes back with its products **[DECIDED
  2026-10-02]** — "Egypt sells no lighting → no Lighting in Egypt's menu".
- **Deactivating a category** **[DECIDED 2026-10-02]**: its sub-categories are deactivated with it,
  and activating it again brings back only what was active before. **For each product in it or under
  it, staff choose** — with an "apply to all" shortcut — to **hide** it (no longer listed, searched or
  suggested; a direct link shows "Not available now"; activating the category brings it back), to
  **leave** it (it keeps the inactive category, so it still has exactly one, unlisted but reachable),
  or to **move** it to another active lowest category. The whole deactivation is **one step: all of
  it happens, or none of it**, and each product's change is audited.
- **Deleting a category** **[DECIDED 2026-10-02]**: only one that holds no product (in any stage) and
  no sub-category; otherwise refused until they are moved. **Moving a category** under another is
  allowed, its old addresses redirect.
- **Changed only by someone holding `catalog.category.manage` with All stores** **[DECIDED
  2026-10-02]**, since the tree is every store's; a store's ranks by that store's people (§3).

### 1.6 Brand

Global, one row per brand (handoff §9.4): `slug` (one per language **[DECIDED 2026-10-02]**), `name`
(Arabic and English), `logo` (a public photo), `description` (Arabic and English), `origin_country`,
`agency_type` (`HOUSE`, `EXCLUSIVE_AGENT`, `DISTRIBUTOR`), `is_default`, `show_in_default_listings`,
`position`, `is_active`. **Do not create a table per brand** (handoff §9.4, §16).

- **Exactly one default brand at any time** **[DECIDED 2026-10-02]**, pre-selected on the product
  form; an admin may make another brand the default, which un-marks the old one. A mark on the row,
  seeded on TouchWood — never a brand name in code (handoff §2 rule 2). **[ACCEPTED 2026-10-02, §9.3 #12]** the
  default brand cannot be deactivated or deleted until another is made the default.
- `show_in_default_listings` is copied into the listing read model (handoff §9.4): the default grid is
  one indexed condition with no join. Changing it re-stamps that brand's rows.
- **Deactivating a brand** **[DECIDED 2026-10-02]**: for each of its products, staff choose — with
  "apply to all" — to **hide** it with the brand, or to **move** it to another active brand; never to
  leave a product without a brand. One step, all or nothing, each change audited. An inactive brand
  leaves the form's choices, the brand filter and its brand page.
- **Deleting a brand** **[DECIDED 2026-10-02]**: only one no product carries, archived ones included.
- Changed only by someone holding `catalog.brand.manage` with All stores.

### 1.7 Attributes, values, attribute sets, colours

**One shared library** **[DECIDED 2026-10-02]**.

- An **attribute** is defined once: its name in both languages, its **kind** — informational,
  filterable, or variant-making (handoff §9.1) — and an optional **unit** (mm, kg).
- A **filterable** or **variant-making** attribute has a **list of values**, each named in both
  languages. **Two values of one attribute never match after trimming, ignoring letter case**:
  "Black" and "black" are one value **[DECIDED 2026-10-02]**.
- A **colour** attribute's values carry a **swatch** — the design's **Colours** library.
- An **attribute set** — the design's **Variations**, "attribute sets, measurement and finish, that
  generate variants" — is a named group of variant-making attributes. A product takes one set; its
  variants are the combinations of values staff pick from those attributes.
- **Price is per combination, never additive**, and **the server resolves the variant** from the values
  a shopper picks; the frontend never does (handoff §9.1).
- **[ACCEPTED 2026-10-02, §9.3 #13]** informational values: per variant, as text in both languages or a number with the
  attribute's unit; a product's set cannot change once it has variants.
- Changed only by someone holding `catalog.attribute.manage` with All stores. **[ACCEPTED 2026-10-02, §9.3 #14]**
  attributes, values and sets, labels, warranties and word pairs are deactivated and deleted by the
  same rule as brands and categories: deactivated (reversible), deleted when nothing uses them.

### 1.8 Custom label

**In this stage** **[DECIDED 2026-10-02]**. A staff-managed list: a name in both languages and a colour
from the theme's tokens (frontend.md §1.8, never a raw colour). **The list is global; each store
attaches labels to products itself** — "Clearance" in KSA need not show in Egypt **[DECIDED
2026-10-02]**. **[ACCEPTED 2026-10-02, §9.3 #2]** a name at most 30 characters.

### 1.9 Warranty

**In this stage** **[DECIDED 2026-10-02]**. A staff-managed list: a name and terms in both languages,
and a period in months or "lifetime". A product carries **at most one**, the same in every store.
**[ACCEPTED 2026-10-02, §9.3 #2]** name at most 100 characters, terms at most 5,000 with simple formatting, a period
of 1 to 600 months.

### 1.10 Relations

**[DECIDED 2026-10-02]** On a product's page:

- **"You may also like"** shows the hand-picked **Related** products; **when staff picked none, it fills
  itself** from the same category, then the same brand.
- **"Goes with"** shows the hand-picked accessories only (a hinge's mounting plate).
- Only products listed in the store being viewed are ever shown.

### 1.11 Search

PostgreSQL full-text search and `pg_trgm` (handoff §3, §9.5), ranked in one expression: exact match,
prefix, nearest to what was typed, then a search word; ties by sales rank (handoff §9.5 — "in stock"
no longer breaks ties, since only orderable products are listed). Arabic is normalised on write and
on query (handoff §5.2).

- **Search words** per product (§1.1) and **shared word pairs** that apply to every product
  ("مفصلة" ↔ "hinge") **[DECIDED 2026-10-02]**: one entry fixes a zero-result search for the whole
  shop. Pairs are changed by `catalog.search_word.manage`, with All stores.
- **The search log** **[DECIDED 2026-10-02]**: every search — the words as normalised, the store, the
  language, how many results, and when. **No customer, no IP.** Entries older than **12 months** are
  removed by a nightly job. Its zero-result list is what staff add word pairs from (handoff §9.5).

### 1.12 The JSON import

**Lives in Catalog** **[DECIDED 2026-10-02]**. Super Admin only (handoff §9.1). A **preview first, then
all or nothing**; a code that exists is **updated, not refused** (handoff §9.1).

- **The file's format follows the product tables we build** **[DECIDED 2026-10-02]** (the owner, after
  first choosing a sample file: "it's gonna be according to the products table that we're gonna
  build … let this job to the end and not block us"). **The import is built last** (plan step 6), its
  format written into this section from the finished tables and shown to the owner before it is built.
- **Prices and stock in the file are ignored for now** **[DECIDED 2026-10-02]** ("any future things it
  must ignore for now"): Catalog offers a registry where **Pricing and Inventory add their sections in
  stage 5**, joining the same preview and the same all-or-nothing transaction ("mostly with 1").
  **[ACCEPTED 2026-10-02, §9.3 #15]** until then the preview says, once, that the file's prices and stock were not
  imported.
- In a store wired to a provider, the file's prices and stock are ignored with a warning (handoff
  §9.1) — Pricing's and Inventory's sections, from stage 5.

---

## 2 · Public contract

### 2.1 `Modules\Catalog\Public\Contracts\CatalogApi`

Ids in, DTOs out (handoff §4.3). **[ACCEPTED 2026-10-02, §9.3 #16]** — the methods the modules after Catalog are known
to need, from the handoff:

| Method | For |
|---|---|
| `variant(string $variantId): ?VariantDto` | Pricing, Inventory, Sales, Shipping — the code, the product, the attribute values, weight and dimensions |
| `variantByCode(string $code): ?VariantDto` | Sync (the provider's codes, ignoring letter case), the import's later sections |
| `product(string $productId): ?ProductDto` | Sales (snapshot), Feedback, Content |
| `storeVariant(StoreId $store, string $variantId): ?StoreVariantDto` | Sales: in that store, whether the variant is Active and orderable (§1.3), "Not available now" (its own or its product's), its selling modes, and its product's minimums and maximums for each mode |
| `resolveVariant(string $productId, array $valueIds): ?string` | Sales: the variant a shopper's picked values name (handoff §9.1) |

### 2.2 Listing facts — pushed in by the modules above **[DECIDED 2026-10-02]**

Catalog's listing read model needs facts it does not own. **The modules that own them push them in**,
inside their own transaction, so a list is never stale and every dependency still points down
(handoff §4.4):

| Fact | Pushed by | From |
|---|---|---|
| Whether a variant is orderable now in a store | Inventory | Stage 5 |
| The price shown, for the price filter and sort | Pricing | Stage 5 |
| The sales rank, for "best-selling" | Sales | Stage 6 |

`ListingFacts` (a `Public/Contracts` interface Catalog implements) **[ACCEPTED 2026-10-02, §9.3 #16]**:
`orderable(StoreId, list<variantId>, bool)`, `prices(StoreId, map variantId → Money|null)`,
`salesRanks(StoreId, map productId → int)`. Until stage 5, orderable follows §1.3.

### 2.3 The import's sections — added by the modules above **[DECIDED 2026-10-02]**

`ImportSections` (a registry, like Platform's `MediaUsages`): a module registers a class that reads its
part of the file, adds its lines to the preview and writes its part inside the import's transaction.
Pricing and Inventory register theirs in stage 5.

### 2.4 What Catalog needs from other modules

| From | What | State |
|---|---|---|
| Access | **Declaring Catalog's permissions** in `PermissionCatalog`, in the `Catalog` group | **[DECIDED 2026-10-02]** — needs handoff §4.4 and `deptrac.yaml` to allow Catalog → Access (Public only). `PermissionGroup::Catalog` exists already |
| Platform | Stores, settings, the audit log, `MediaUsages` (photos and logos are detachable uses), `uploadMediaFor` (staff upload photos under Catalog's own permission) | Exists |
| Platform | **Photo addresses for product cards** — `mediaUrls()` reads one media row per call (`DatabaseMediaReader::urls`) | **No Platform change [ACCEPTED 2026-10-02, §9.3 #17]**: Catalog asks `mediaUrls()` when it writes a listing row and keeps the card photo's addresses in that row, refreshed on `MediaVariantsReady`, so a product grid reads no media at all. A change of CDN address is followed by the repair job (§3) |
| Platform | **On stores only**: the store switch (platform.md §1.6) is in the spec but not the code — `StoreDto` has no `isActive` yet. Catalog's screens list only stores on, once Platform says which | Waits for the store switch build |

### 2.5 DTOs and enums

Plain `final readonly` classes (handoff §4.3). Enums stored as strings: `ProductStage` (`DRAFT`,
`READY`, `ARCHIVED`), `AttributeKind` (`INFORMATIONAL`, `FILTERABLE`, `VARIANT`), `AgencyType`
(`HOUSE`, `EXCLUSIVE_AGENT`, `DISTRIBUTOR`), `SaleMode` (`RETAIL`, `WHOLESALE`).

---

## 3 · Use cases

**[DECIDED 2026-10-02] One permission per job, any role** — staff or admin — may be given any of them;
none is admin-only. **The shared lists use one permission each.** Names below are my proposal
**[ACCEPTED 2026-10-02, §9.3 #18]**; all in the `Catalog` group.

| Use case | Permission | Scope |
|---|---|---|
| `CreateProduct` — a draft, Active nowhere | `catalog.product.create` | The staff member's working store |
| `UpdateProduct` — names, slugs, description, brand, category, warranty, search words, gallery, relations; `AddVariant`, `UpdateVariant`, `ArchiveVariant`, `RestoreVariant` | `catalog.product.update` | **Every store where the product is Active**; any store when it is Active nowhere |
| `CorrectVariantCode` | `catalog.variant.correct_code` | As `UpdateProduct` |
| `MarkProductReady` | `catalog.product.publish` | As `UpdateProduct` |
| `ArchiveProduct` / `RestoreProduct` | `catalog.product.archive` | As `UpdateProduct` |
| `ChooseInStore` — a whole product or single variants, Active or Inactive | `catalog.listing.choose` | That store |
| `SetSellingTerms` — each variant's retail and wholesale switches; the product's minimum and maximum for each mode | `catalog.listing.selling` | That store |
| `MarkNotAvailableNow` / `ClearNotAvailableNow` — product or variant | `catalog.listing.unavailable` (handoff §9.2: "own permission") | That store |
| `AttachLabels` | `catalog.listing.labels` | That store |
| `RankCategories` | `catalog.category.rank` | That store |
| Category tree: add, rename, move, deactivate (with each product's choice), activate, delete | `catalog.category.manage` | All stores |
| Brands: add, edit, make default, deactivate (with each product's choice), activate, delete | `catalog.brand.manage` | All stores |
| Attributes, values, attribute sets, colours | `catalog.attribute.manage` | All stores |
| Labels list | `catalog.label.manage` | All stores |
| Warranties list | `catalog.warranty.manage` | All stores |
| Shared word pairs; reading the zero-result list | `catalog.search_word.manage` | All stores |
| `ListProducts` / `ViewProduct` (admin) — every store's row shown only for the stores the reader covers | `catalog.product.view` | The reader's stores |
| `PreviewImport` / `RunImport` | `catalog.import.run` (reserved: Super Admin only, handoff §9.1) | Global |
| `RebuildListing` — a repair job; `PruneSearchLog` — nightly | System (reserved): `catalog.listing.rebuild`, `catalog.search_log.prune` — *My naming, stated for the owner to reject (step 1, 2026-10-02): after Platform's `platform.media.variants.generate`, reserved and store-free* | — |

Every change is audited (Platform), **by value**: product data names no person.

---

## 4 · State machines

### 4.1 Product stage

| From | Event | To |
|---|---|---|
| (none) | `CreateProduct` | `DRAFT` |
| `DRAFT` | `MarkProductReady` — every §1.1 requirement met | `READY` |
| `READY` | `ArchiveProduct` — Inactive in every store | `ARCHIVED` |
| `ARCHIVED` | `RestoreProduct` — Inactive everywhere | `READY` |

**[ACCEPTED 2026-10-02, §9.3 #19]** no way from `READY` back to `DRAFT` (a store hides a product by making it
Inactive), and a `DRAFT` is archived or deleted when abandoned. **[ACCEPTED 2026-10-02, §9.5 #2]** Deleting a draft
(`DeleteDraftProduct`, under `catalog.product.archive`) removes it whole, with its variants, photos'
links and slugs: it was never shown or sold, so its slugs and codes become free again — the one
exception to "a code is never given to another variant".

### 4.2 A variant in a store

`Inactive ⇄ Active` (the store's choice), independently `"Not available now"` on or off. Archiving
the variant or its product makes it Inactive.

### 4.3 Category and brand

`active ⇄ inactive`, each deactivation carrying each product's choice (§1.5, §1.6); deleted only when
unused.

---

## 5 · Tables

All in schema `catalog`; ULIDs `char(26)`; timestamps `timestamptz`; enums strings. Every CHECK,
unique index and foreign key has a code rule that refuses first (handoff §5.3). Every media id sits in
its own column or link table with a `RESTRICT` foreign key to `platform.media` (handoff §5.5).

The schema is added to `search_path` in `config/database.php` (project rule), and `pg_trgm` is
created by the schema's first migration. Child rows of a product (slugs, words, photos' links,
relations, variants and theirs) are removed with it — which happens only when a **draft** is deleted
(§4.1); everything else uses `RESTRICT`.

**5.1 Products and variants**

| Table | Columns |
|---|---|
| `catalog.products` | `id` PK · `name_ar`, `name_en` `varchar(200)` NOT NULL · `description_ar`, `description_en` `jsonb` NULL — the structured text (§1.1), each at most 20,000 characters of text, CHECK `jsonb_typeof = 'object'` · `brand_id` FK → `brands` RESTRICT NOT NULL · `category_id` FK → `categories` RESTRICT NULL — CHECK `products_category_unless_draft` (present unless `DRAFT`) · `warranty_id` FK → `warranties` RESTRICT NULL · `attribute_set_id` FK → `attribute_sets` RESTRICT NULL · `stage` `varchar(16)` CHECK (`DRAFT`, `READY`, `ARCHIVED`) · `hidden_by_category`, `hidden_by_brand` `boolean` NOT NULL DEFAULT false — set when a deactivation chose "hide" (§1.5, §1.6), cleared when it is undone or the product moves · timestamps |
| `catalog.product_slugs` | (`locale` `char(2)`, `slug` `varchar(200)`) PK — **every slug ever used**, so none is given to another product · `product_id` FK CASCADE · `is_current` — exactly one current per product and locale (partial unique `product_slugs_one_current`) · CHECK the slug's letters: Arabic letters, digits and `-` for `ar`; `a-z`, digits and `-` for `en` |
| `catalog.product_search_words` | (`product_id` FK CASCADE, `normalized` `varchar(50)`) PK · `word` `varchar(50)` — as typed; at most 30 per product (code rule) |
| `catalog.product_photos` | (`product_id` FK CASCADE, `media_id` FK → `platform.media` RESTRICT) PK · `position` — at most 20 per product (code rule) |
| `catalog.product_relations` | (`product_id` FK CASCADE, `related_id` FK → `products` RESTRICT, `kind`) PK — `kind` CHECK (`RELATED`, `GOES_WITH`) · `position` · CHECK `product_id <> related_id` |
| `catalog.variants` | `id` PK · `product_id` FK CASCADE · `code` `varchar(64)` NOT NULL — trimmed, CHECK `variants_code_format` · `combination` `varchar(600)` — the variant's value ids in attribute order; unique (`product_id`, `combination`) `variants_one_per_combination`, archived ones included · `weight_grams`, `length_mm`, `width_mm`, `height_mm` `integer` NULL, each CHECK 1–1,000,000 · `is_archived` · `position` · timestamps |
| `catalog.variant_codes` | `code_key` `varchar(64)` PK — `lower(code)`: **every code a variant ever held**, so a code is never given to another variant (§1.2) · `variant_id` FK CASCADE · `is_current` — exactly one current per variant (partial unique) |
| `catalog.variant_values` | (`variant_id` FK CASCADE, `attribute_id` FK RESTRICT) PK · `value_id` FK → `attribute_values` RESTRICT — the value belongs to that attribute (code rule) |
| `catalog.variant_details` | (`variant_id` FK CASCADE, `attribute_id` FK RESTRICT) PK · `text_ar`, `text_en` `varchar(200)` NULL · `number` `numeric(12,3)` NULL — either both texts or the number (CHECK `variant_details_one_kind`) |
| `catalog.variant_photos` | (`variant_id` FK CASCADE, `media_id` FK → `platform.media` RESTRICT) PK · `position` — at most 10 per variant (code rule) |

**5.2 Each store's choice** — store-scoped models (`BelongsToStore`, handoff §4.1)

| Table | Columns |
|---|---|
| `catalog.store_variants` | (`store_id` FK → `platform.stores` RESTRICT, `variant_id` FK CASCADE) PK · `is_active` · `not_available_now` · `sells_retail`, `sells_wholesale` — CHECK at least one · timestamps |
| `catalog.store_products` | (`store_id`, `product_id` FK CASCADE) PK · `not_available_now` · `retail_minimum` `integer` NOT NULL DEFAULT 1 · `retail_maximum`, `wholesale_minimum`, `wholesale_maximum` `integer` NULL — each 1–100,000, a maximum never below its minimum (CHECKs) · timestamps |
| `catalog.store_product_labels` | (`store_id`, `product_id` FK CASCADE, `label_id` FK RESTRICT) PK |
| `catalog.store_category_ranks` | (`store_id`, `category_id` FK CASCADE) PK · `rank` `integer` |

**5.3 The lists**

| Table | Columns |
|---|---|
| `catalog.categories` | `id` PK · `parent_id` FK → `categories` RESTRICT NULL · `name_ar`, `name_en` `varchar(100)` · `is_active` · `deactivated_with_parent` — so reactivating a parent brings back only what was active before · `image_media_id` FK → `platform.media` RESTRICT NULL · timestamps. A category is never its own ancestor (code rule: it crosses rows) |
| `catalog.category_slugs` | As `product_slugs`, for categories |
| `catalog.brands` | `id` PK · `name_ar`, `name_en` `varchar(100)` · `description_ar`, `description_en` `jsonb` NULL · `logo_media_id` FK → `platform.media` RESTRICT NULL · `origin_country` `char(2)` NULL · `agency_type` CHECK (`HOUSE`, `EXCLUSIVE_AGENT`, `DISTRIBUTOR`) · `is_default` — partial unique where true (`brands_one_default`), CHECK a default brand is active · `show_in_default_listings` · `position` · `is_active` · timestamps |
| `catalog.brand_slugs` | As `product_slugs`, for brands |
| `catalog.attributes` | `id` PK · `name_ar`, `name_en` `varchar(100)` · `kind` CHECK (`INFORMATIONAL`, `FILTERABLE`, `VARIANT`) · `unit_ar`, `unit_en` `varchar(20)` NULL · `is_colour` (only a filterable or variant-making attribute) · `is_active` · `position` |
| `catalog.attribute_values` | `id` PK · `attribute_id` FK RESTRICT · `name_ar`, `name_en` `varchar(100)` — unique per attribute on `lower(name)` in each language, names stored trimmed · `swatch` `char(7)` NULL — `#rrggbb`, only on a colour attribute's values · `is_active` · `position` |
| `catalog.attribute_sets` | `id` PK · `name_ar`, `name_en` `varchar(100)` · `is_active` |
| `catalog.attribute_set_members` | (`attribute_set_id` FK CASCADE, `attribute_id` FK RESTRICT) PK · `position` — variant-making attributes only (code rule) |
| `catalog.labels` | `id` PK · `name_ar`, `name_en` `varchar(30)` · `colour_token` `varchar(40)` — the name of a theme token, never a colour · `is_active` · `position` |
| `catalog.warranties` | `id` PK · `name_ar`, `name_en` `varchar(100)` · `terms_ar`, `terms_en` `jsonb` — structured text, at most 5,000 characters each · `period_months` `smallint` NULL — 1–600, NULL meaning lifetime · `is_active` |
| `catalog.word_pairs` | `id` PK · `word_a`, `word_b` `varchar(50)` — normalised, stored in order (`word_a < word_b`), unique as a pair |

**5.4 Search and the listing**

| Table | Columns |
|---|---|
| `catalog.search_log` | `id` `bigint` identity PK · `store_id` FK RESTRICT · `locale` `char(2)` · `query` `varchar(200)` — normalised · `results` `integer` · `searched_at` `timestamptz` DEFAULT `now()` — **no person** (§1.11). Indexes `(searched_at)` for the nightly removal, `(store_id, results, searched_at)` for the zero-result list |
| `catalog.listing` | **The listing and search read model** (handoff §5.4): one row per store, language and product that is **listed or reachable by search** there (§1.4). `store_id`, `locale`, `product_id` PK · `name`, `slug` · `brand_id`, `brand_visible_by_default` (copied from the brand, handoff §9.4) · `category_id` and `category_path` (the ids above it, for a parent's page) · `in_category_pages` (false while "left" in an inactive category) · `value_ids`, `label_ids` (for filters and cards) · `card_media_id` FK → `platform.media` RESTRICT and `card_photo` (its addresses, §2.4) · `orderable` · `price_minor` `bigint` NULL and `sales_rank` `integer` NULL (pushed, §2.2) · `search_document` `tsvector`, `search_text` (normalised, for trigrams). Indexes: `(store_id, locale, brand_visible_by_default, sales_rank)` and `(store_id, locale, brand_id)` (handoff §9.4); GIN on `search_document`, `category_path`, `value_ids`; trigram GIN on `search_text` |

**Rows of the listing are written inside the transaction of the change that alters them**
**[ACCEPTED 2026-10-02, §9.3 #20]** — never stale, as the owner chose for the pushed facts — and a
**repair job** rebuilds them whole (`RebuildListing`, §3). This departs from handoff §5.4's "rebuilt
by job" for the ordinary case, and keeps the job for repairs.

---

## 6 · Events

### 6.1 Published (ids only, after commit)

**[ACCEPTED 2026-10-02, §9.3 #21]** `ProductMadeReady`, `ProductArchived`, `ProductRestored`, `ProductChanged` (shared
data), `VariantAdded`, `VariantArchived`, `VariantRestored`, `VariantCodeCorrected` (Sync re-matches),
`StoreListingChanged` (`storeId`, variant ids — Pricing and Inventory learn a store took up a variant
that needs a price and stock). None is among handoff §4.5's critical outbox events.

### 6.2 Consumed

| Event | From | What Catalog does |
|---|---|---|
| `MediaVariantsReady` | Platform | Refreshes the card photo in the listing rows using that photo |
| `MediaDeleted` | Platform | Nothing more: the photo was detached through `MediaUsage` inside the delete's transaction |
| `StoreCreated` | Platform | Nothing: a new store chooses nothing (and is created off, platform.md §1.1) |

---

## 7 · Errors

`CatalogError extends DomainError`, each with a stable type `catalog.{name}` and translations in both
languages **[ACCEPTED 2026-10-02, §9.3 #22]**. A thing in a store the reader does not cover answers
exactly as one that does not exist, as B2B's and Access's do.

| Error | Category | When |
|---|---|---|
| `ProductNotFound`, `VariantNotFound`, `CategoryNotFound`, `BrandNotFound` | NOT_FOUND | Unknown, or not one the reader may see |
| `ListItemNotFound` | NOT_FOUND | An attribute, value, set, label, warranty or word pair that does not exist |
| `CodeTaken` | CONFLICT | A code another variant holds or once held, ignoring letter case (§1.2) |
| `SlugTaken` | CONFLICT | A slug another product, category or brand holds or once held (§1.1) |
| `DuplicateCombination` | CONFLICT | A variant with the same values as another of the product, archived ones included |
| `ProductNotReady` | INVALID | Marking ready, or editing a ready product, without every §1.1 requirement — it names what is missing |
| `ProductArchived` | CONFLICT | Changing an archived product other than restoring it |
| `InvalidStageChange` | CONFLICT | A move §4.1 does not allow |
| `NotChosenInStore` | CONFLICT | Selling terms or labels for a product the store does not sell |
| `InvalidSellingTerms` | INVALID | No selling mode, a maximum below its minimum, wholesale on with no wholesale minimum |
| `CategoryNotLowest` | INVALID | Putting a product in a category that has sub-categories |
| `CategoryHoldsProducts` | CONFLICT | Adding a sub-category under a category that holds products |
| `CategoryNotEmpty` | CONFLICT | Deleting a category that holds products or sub-categories |
| `CategoryLoop` | INVALID | Moving a category under itself or anything below it |
| `CategoryInactive`, `BrandInactive`, `ListItemInactive` | CONFLICT | Choosing something deactivated |
| `BrandInUse`, `ListItemInUse` | CONFLICT | Deleting what a product still uses |
| `DefaultBrandRequired` | CONFLICT | Deactivating or deleting the default brand (§1.6) |
| `AttributeSetLocked` | CONFLICT | Changing a product's attribute set once it has variants (§1.7) |
| `TooMany` | CONFLICT | Over a limit: photos, search words |
| `InvalidCatalogAttribute` | INVALID | Any other value the domain refuses — a length, a format, a swatch |
| `ImportRefused` | INVALID | An import whose preview found errors; it lists them all |

---

## 8 · Test scenarios

Every guard below is also mutation-checked (CONVENTIONS, "How a step is done here").

**Products and variants**

1. A product leaves `DRAFT` only with names, slugs and description in both languages, a brand, a
   lowest active category, a variant with a code and a photo whose sizes are ready; each missing
   item is named. A ready product refuses an edit that removes one.
2. A code is unique across every variant ignoring letter case and surrounding spaces; correcting one
   is its own permission and audited; no code a variant ever held — archived or corrected — is given
   to another variant.
3. Two variants of one product never share a combination; the server resolves the variant from
   picked values; price is never added up from values.
4. A slug is unique per language among products (and among categories, among brands); an old slug
   answers with a redirect to the current one.
5. Archiving a product makes it Inactive in every store; restoring brings it back ready and Inactive
   everywhere; a variant archives and restores on its own.

**Stores**

6. A store chooses a whole product or single variants; a variant added later is chosen nowhere; only
   a ready product's variants can be Active.
7. "Not available now" on a product hides every variant there, later ones included; on a variant,
   that variant only; neither touches anything in another store.
8. Selling terms: at least one mode per variant; each mode's minimum and maximum per product per
   store, a maximum never below its minimum, a wholesale minimum while any variant sells wholesale.
9. A KSA-only person cannot change Egypt's row, nor the shared data of a product Egypt sells; a
   product Active nowhere can be changed by any holder; a shared list needs All stores.

**What a shopper sees**

10. Only orderable products are listed; a direct link to anything else that exists shows "Not
    available now", noindex; a slug that never existed answers 404.
11. An empty category disappears from a store and comes back with its products; a parent lists
    everything below it; a store's ranks order its menu.
12. Related products fill in from the same category, then brand, only when none were picked; only
    products listed in the store are suggested.
13. The listing changes in the same transaction as what it shows — a product made Inactive is gone
    from the next query — and the repair job rebuilds it identically.

**The lists**

14. Deactivating a category or brand applies each product's choice in one step, all or nothing,
    each audited; sub-categories go with a category and come back as they were; no product is ever
    left without a brand; the default brand cannot be deactivated or deleted.
15. A category with products cannot take a sub-category; a product goes only into a lowest
    category; a category never moves under itself; deleting needs it empty.
16. "Black" and "black" are one value of an attribute; a set holds only variant-making attributes;
    a product's set is fixed once it has variants.

**Search**

17. Exact, prefix, nearest, then a search word or a word pair; Arabic normalised on both sides; ties
    by sales rank.
18. The search log keeps no person and loses entries after 12 months.

**Contract and architecture**

19. `CatalogApi` answers with DTOs only; the listing facts and the import's sections are accepted
    from the modules above; a store-scoped table refuses another store's row.
20. Catalog imports only Platform's and Access's public surfaces; every handler asserts a
    permission; every CHECK, unique index and foreign key has a code rule that refuses first; no
    country, currency or store name in `Domain/` or `Application/`.
21. A storefront listing page stays within its query budget (frontend.md §5), measured warm, once
    its endpoint exists.

---

## 9 · Questions

### 9.1 Answered by the owner — 2026-10-02

| # | Question | Decision |
|---|---|---|
| 1 | How Catalog declares permissions | Asked back "what's best without messing with the architecture"; on my answer, **Catalog → Platform, Access** — Access's Public only, to declare permissions; any other use asked first (§2.4) |
| 2 | What Catalog owns in this stage | **The store's choice only; price and stock with Pricing and Inventory in stage 5** (§1.3) |
| 3 | Where the JSON import lives | **Catalog**; future parts (prices, stock) ignored for now, added later through a registry (§1.12, §2.3) |
| 4 | How stock and price reach the listings | **Pushed into Catalog** by the modules that own them (§2.2) |
| 5 | Store choice level | **A whole product or single variants**, in the panel; the import's level goes with its format (§1.3) |
| 6 | Slugs | **One per language, every store** — handoff §4.1 to be amended (§1.1) |
| 7 | The code | **Unique across every variant of every product; the only identifier, the SKU; staff may correct one** (§1.2) |
| 8 | A variant added later | **Chosen in no store automatically** (§1.3) |
| 9 | "Not available now" level | **Both, like the choice** — a whole product (later variants included) or one variant (§1.3) |
| 10 | Status | **A product-wide stage (draft, ready, archived) and each store's Active row**, changed only by people covering that store (§1.1, §1.3) |
| 11 | Who changes shared data | First answered "2" on a misunderstanding (per-store copies); asked again: **the permission in every store where the product is Active** (§1.1) |
| 12 | Who may be given Catalog's jobs | **Any role, one permission per job** (§3) |
| 13 | Endpoints | **With the Geist screens**; backend now (header) |
| 14 | Leaving draft also needs | **A category, a photo, the description in both languages**; a video allowed but optional — then "leave it for now" (§1.1) |
| 15 | Categories per product | **Exactly one** (§1.1) |
| 16 | Empty categories | First "I don't get it"; asked again: **hidden by themselves per store** (§1.5) |
| 17 | Where products sit | **At the end of the tree**; a single-level category may hold products (§1.5) |
| 18 | Attributes | **One shared library**; staff search words; "Black" = "black" after trimming (§1.7) |
| 19 | A parent category's page | **Everything below it** (§1.5) |
| 20 | Search helper words | **Per product, plus shared word pairs** (§1.11) |
| 21 | The design's extra sections | **Custom labels and warranty now**; add-ons not now (§1.8, §1.9) |
| 22 | Selling mode | **Per variant, per store** (§1.3); the minimum moved to the product by #40 |
| 23 | Labels | **List global, attached per store** (§1.8) |
| 24 | Warranty | **A list, at most one per product** (§1.9) |
| 25 | Relations | **Hand-picked Related, hand-picked Goes with, and automatic**; automatic fills in only when none is picked (§1.10) |
| 26 | Variant photos | **Gallery plus each variant's own**; asked how with codes — photos hang off the variant, not its code (§1.2) |
| 27 | Description | **Simple formatting** (§1.1) |
| 28 | Weight and dimensions | **Catalog, per variant**, optional (§1.2) |
| 29 | A direct link to what cannot be ordered here | **"Not available now" for every case**, noindex (§1.4) |
| 30 | Search log | **No person, 12 months** (§1.11) |
| 31 | Deleting brands and categories | **Deleted when unused, and deactivated too** (§1.5, §1.6) |
| 32 | Retiring | **Archived products and variants restorable; codes never reused** (§1.1, §1.2) |
| 33 | Default brand | **Movable, always exactly one** (§1.6) |
| 34 | Deactivating a category with products | **Per product: hide, leave (still reachable), or move — one by one or all**; sub-categories go with it (§1.5) |
| 35 | Deactivating a brand with products | **Hide them with it, or move them to another brand; never a product with no brand** (§1.6) |
| 36 | Shared lists | **Changed only by All-stores holders; one permission per list** (§1.5–§1.11, §3) |
| 37 | The import's file format | First "I'll send a sample file"; then **it follows the product tables we build, and the import comes last** so it blocks nothing (§1.12) |
| 38 | Stock flags (stock-dependent, low-stock threshold, ending soon) | Asked what Catalog then contains; on the list, **Inventory, stage 5** |
| 39 | My 22 proposals (§9.3) | **"Anything else is accepted"** — all but #10 |
| 40 | §9.3 #10, the wholesale minimum | **"Each product has its minimums and maxes"**: asked again — **per product, per store**, and **each mode its own minimum and maximum** (§1.3) |
| 41 | The four points of §9.5 | **"Accept all four"** |
| 42 | The changes §9.4 lists for other documents | **Written in this spec's pull request** (`deptrac.yaml` with the first code that needs it) |
| 43 | The spec and the step list | **Approved** ("ok now all good … start building"), with the go for the baseline check and step 1; built solo, never by a workflow (owner, 2026-10-02) |

### 9.2 Left open

| # | What | Waits for |
|---|---|---|
| 1 | The import's file format (§1.12) | The product tables (steps 1–5); written into §1.12 and shown to the owner before step 6 |
| 2 | A product video (§1.1) | The owner: "leave it for now" — a link to a hosted video, or a Platform amendment for uploaded video |
| 3 | Product add-ons ("Product apps"), bundles | A later stage (handoff §15.2) |

### 9.3 My proposals — accepted by the owner, 2026-10-02, except #10

1. No `processed_events` table: each listener is written to do its work once however often it runs.
2. Lengths: product name 200, category name 100, label 30, warranty name 100 and terms 5,000.
3. Slugs made from the name when a product is created, editable; every old slug kept and never given
   to another product.
4. Description at most 20,000 characters per language.
5. At most 30 search words per product, each at most 50 characters.
6. At most 20 gallery photos per product, 10 per variant.
7. A `READY` product keeps every ready requirement: an edit that removes one is refused.
8. A code: one line, 1–64 characters of letters, digits, spaces and `- . _ /`, compared after
   trimming. A corrected typo **keeps the mistyped code taken**, like every code that ever existed —
   one rule, "a code is never given to another variant" (the alternative: free it, since it named
   nothing real).
9. Weight in whole grams, sizes in whole millimetres, each 1 to 1,000,000.
10. ~~The wholesale minimum per variant per store: a whole number 1–100,000, required when wholesale
    is on.~~ **Replaced by the owner's answer (§9.1 #40):** each product's own minimum and maximum
    for each mode, per store.
11. A category may have an optional photo, for category cards.
12. The default brand cannot be deactivated or deleted until another is made the default.
13. Informational values per variant (text in both languages, or a number with the unit); a
    product's attribute set cannot change once it has variants.
14. Attributes, values, sets, labels, warranties and word pairs: deactivated (reversible) and deleted
    only when unused, like brands and categories.
15. Until stage 5 the import's preview says, once, that the file's prices and stock were not imported.
16. The public contract (§2.1) and the listing facts interface (§2.2) as listed.
17. **Catalog keeps the card photo's addresses in its own listing rows**, asked of Platform when a row
    is written and refreshed on `MediaVariantsReady`, so a product grid reads no media at all and
    Platform's contract does not change; a change of CDN address is followed by the repair job. (The
    alternative: a Platform addition, one call returning the addresses of a page of photos.)
18. The permission names of §3, and `CreateProduct` checked in the staff member's working store (the
    panel's store picker, frontend.md §2.2), since a new product belongs to no store yet.
19. No way from `READY` back to `DRAFT`.
20. The listing read model's rows are written inside the transaction of each change (never stale, as
    the owner chose for pushed facts), with a repair job that rebuilds them — handoff §5.4 says
    "rebuilt by job".
21. The events of §6.1.
22. The errors of §7.

### 9.4 Changes to other documents — each needs the owner's word before it is written

| Document | Change |
|---|---|
| `docs/HANDOFF.md` §4.4, `deptrac.yaml` | Catalog → Platform, Access (Access's Public only) |
| `docs/HANDOFF.md` §4.1 | Slugs move from "store-scoped" to "global" |
| `docs/HANDOFF.md` §9.2 | The editorial status becomes a product-wide stage (`DRAFT`, `READY`, `ARCHIVED`) plus each store's Active row |
| `docs/HANDOFF.md` §9.3, §9.4, §9.5, §15.2 | One category per product at the end of the tree; deactivating and deleting categories and brands; shared word pairs; the 12-month search log; labels and warranty in this stage; the video left open |
| `docs/STRUCTURE.md`, `docs/modules/README.md` | Still say Catalog is blocked on the provider's schema; Sync's line still names a conflict log |

### 9.5 Points that surfaced while completing §5–§8 — accepted by the owner, 2026-10-02 ("accept all four")

1. **The quantity limits' range**: each minimum and maximum a whole number from 1 to 100,000, a
   maximum never below its minimum (§1.3).
2. **Deleting a draft** frees its slugs and its variants' codes — it was never shown or sold — the one
   exception to "a code is never given to another variant" (§4.1).
3. **A combination is never made twice**: a variant with the same values as an archived one is
   refused; the archived one is restored instead (§5.1, `DuplicateCombination`).
4. **Creating a product needs both names** (its slugs are made from them, §9.3 #3); everything else
   may wait until it is made ready.
