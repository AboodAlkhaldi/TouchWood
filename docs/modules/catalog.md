# Catalog — Module Specification

> **DRAFT — being written with the owner.** The owner's answers of 2026-10-02 are recorded below as
> **[DECIDED 2026-10-02]** and listed in §9.1. Everything marked **[TO CONFIRM n]** is a proposal of
> mine, listed in §9.3 with its options; none of it is built on until the owner answers. The JSON
> import's file format waits for the owner's sample file (§1.11, §9.2).

**Status:** DRAFT. **Tier:** 1 (commerce core). **Build stage:** 4 (handoff §17).
**Depends on:** Platform, and Access's public surface for declaring permissions only (§2.4,
**[DECIDED 2026-10-02]** — a change to handoff §4.4 and `deptrac.yaml`).
**Needs from shared plumbing:** nothing new. Catalog consumes events (§6.2), but each of its listeners
is written to do its work once however often it runs, as B2B's are, so `processed_events` is still not
needed **[TO CONFIRM 1]**.
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
| `name` | Arabic and English. **[TO CONFIRM 2]** One line, at most 200 characters each. |
| `slug` | **One Arabic slug and one English slug, used in every store** **[DECIDED 2026-10-02]** — replacing handoff §4.1's "slugs are store-scoped", which disagreed with §9.1. Arabic letters for `ar`, Latin for `en` (handoff §5.2). Unique per language among products. Every slug the product has ever had is kept, so an old address answers with a 301 (handoff §5.2) **[TO CONFIRM 3]**. |
| `description` | Arabic and English. **Simple formatting** **[DECIDED 2026-10-02]**: paragraphs, bullet lists, bold and headings, kept as safe structured text — no raw HTML is stored, so nothing typed can run as a script. **[TO CONFIRM 4]** at most 20,000 characters each. |
| `brand_id` | **Exactly one brand, never none** (handoff §9.4, NOT NULL; **[DECIDED 2026-10-02]**: "no products with no brand"). The form starts on the default brand (§1.6). An inactive brand cannot be chosen. |
| `category_id` | **Exactly one category** **[DECIDED 2026-10-02]**, and only a category with no sub-categories (§1.5). Empty while the product is a draft; required to leave `DRAFT`. |
| `warranty_id` | **At most one warranty**, from the list (§1.9) **[DECIDED 2026-10-02]**, the same in every store. |
| `stage` | `DRAFT`, `READY` or `ARCHIVED` (§4.1) — **one for the product everywhere** **[DECIDED 2026-10-02]**. Each store's own on/off is its choice (§1.4). |
| `search_words` | **Extra words staff type to help search** **[DECIDED 2026-10-02]**, in either language ("slide", "rail"). **[TO CONFIRM 5]** at most 30 words, each one line of at most 50 characters. |
| gallery | **An ordered gallery of photos** (Platform public media) **[DECIDED 2026-10-02]**. **[TO CONFIRM 6]** at most 20. |
| relations | **Hand-picked "Related"** and **hand-picked "Goes with"** products, each ordered **[DECIDED 2026-10-02]** (§1.10). |

**Leaving `DRAFT`** (becoming `READY`) needs **[DECIDED 2026-10-02]**: the name and the slug in both
languages (handoff §5.2), the description in both languages, a brand, a category, at least one
variant that is not archived — each with its code (handoff §9.1) — and at least one gallery photo
whose sizes are ready (Platform `READY`). A product may also carry a **video**: the owner said it may,
optionally, and then **"Leave it for now"** — not in this stage (§9.2).

**While `READY` the same things must stay true** **[TO CONFIRM 7]**: an edit that would take one away
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
| `code` | **The only identifier of a variant** **[DECIDED 2026-10-02]**: it is the SKU customers see on the technical list, the provider's code (handoff §9.1, §12.2), and the import's key. **Unique across every variant of every product** — "no two products will hold the same code even if they were the same product but a different variant" (owner) — **ignoring letter case**, and **never used again**, archived or not **[DECIDED 2026-10-02]**. **Staff may correct a mistyped code** under its own permission, audited; the next provider pull and the next import then match the corrected code; an order keeps the code it was placed with (Sales snapshots it). **[TO CONFIRM 8]** one line, 1 to 64 characters of letters, digits, spaces and `- . _ /`, compared after trimming; and whether a corrected (mistyped) code is freed or stays taken. |
| attribute values | One value for each variant-making attribute of the product's attribute set (§1.7), and its informational values. **No two variants of one product have the same combination.** |
| `weight`, `length`, `width`, `height` | **Physical facts, per variant, in Catalog** **[DECIDED 2026-10-02]**, optional now; Shipping reads them and decides later what it requires. **[TO CONFIRM 9]** whole grams and whole millimetres, each 1 to 1,000,000. |
| photos | **Its own ordered photos**, shown when the variant is chosen, falling back to the product's gallery **[DECIDED 2026-10-02]**. They hang off the variant, not its code, so correcting a code leaves them in place. **[TO CONFIRM 6]** at most 10. |
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
| `wholesale_minimum` | **The wholesale minimum quantity**, per variant per store **[DECIDED 2026-10-02]**; wholesale prices and tiers are Pricing's. **[TO CONFIRM 10]** a whole number from 1 to 100,000, required when wholesale is on. |

Per store, per **product**:

| Attribute | Invariant |
|---|---|
| `not_available_now` | **"Not available now" for the whole product** — every variant, including ones added later **[DECIDED 2026-10-02]**: "both, like the choice". |
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
| `name`, `slug` | Arabic and English, as a product's (one slug per language, all stores, history kept, **[DECIDED 2026-10-02]**). **[TO CONFIRM 2]** name at most 100 characters. |
| `parent_id` | Its parent, or none at the top. Never under itself or anything below it. |
| `is_active` | Deactivated and activated again (below). |
| `rank` per store | **The admin-set order, per store** (handoff §9.3), set by that store's people. |
| image | **[TO CONFIRM 11]** an optional public photo, for category cards (the design's homepage shows them). |

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
  seeded on TouchWood — never a brand name in code (handoff §2 rule 2). **[TO CONFIRM 12]** the
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
- **[TO CONFIRM 13]** informational values: per variant, as text in both languages or a number with the
  attribute's unit; a product's set cannot change once it has variants.
- Changed only by someone holding `catalog.attribute.manage` with All stores. **[TO CONFIRM 14]**
  attributes, values and sets, labels, warranties and word pairs are deactivated and deleted by the
  same rule as brands and categories: deactivated (reversible), deleted when nothing uses them.

### 1.8 Custom label

**In this stage** **[DECIDED 2026-10-02]**. A staff-managed list: a name in both languages and a colour
from the theme's tokens (frontend.md §1.8, never a raw colour). **The list is global; each store
attaches labels to products itself** — "Clearance" in KSA need not show in Egypt **[DECIDED
2026-10-02]**. **[TO CONFIRM 2]** a name at most 30 characters.

### 1.9 Warranty

**In this stage** **[DECIDED 2026-10-02]**. A staff-managed list: a name and terms in both languages,
and a period in months or "lifetime". A product carries **at most one**, the same in every store.
**[TO CONFIRM 2]** name at most 100 characters, terms at most 5,000 with simple formatting, a period
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

- **The file's format waits for the owner's sample file** **[DECIDED 2026-10-02]**: "I'll send a sample
  file". This section is completed from it.
- **Prices and stock in the file are ignored for now** **[DECIDED 2026-10-02]** ("any future things it
  must ignore for now"): Catalog offers a registry where **Pricing and Inventory add their sections in
  stage 5**, joining the same preview and the same all-or-nothing transaction ("mostly with 1").
  **[TO CONFIRM 15]** until then the preview says, once, that the file's prices and stock were not
  imported.
- In a store wired to a provider, the file's prices and stock are ignored with a warning (handoff
  §9.1) — Pricing's and Inventory's sections, from stage 5.

---

## 2 · Public contract

### 2.1 `Modules\Catalog\Public\Contracts\CatalogApi`

Ids in, DTOs out (handoff §4.3). **[TO CONFIRM 16]** — the methods the modules after Catalog are known
to need, from the handoff:

| Method | For |
|---|---|
| `variant(string $variantId): ?VariantDto` | Pricing, Inventory, Sales, Shipping — the code, the product, the attribute values, weight and dimensions |
| `variantByCode(string $code): ?VariantDto` | Sync (the provider's codes, ignoring letter case), the import's later sections |
| `product(string $productId): ?ProductDto` | Sales (snapshot), Feedback, Content |
| `storeVariant(StoreId $store, string $variantId): ?StoreVariantDto` | Sales: Active, "Not available now", selling modes, wholesale minimum in that store |
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

`ListingFacts` (a `Public/Contracts` interface Catalog implements) **[TO CONFIRM 16]**:
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
| Platform | **Photo addresses for a page of product cards** — `mediaUrls()` reads one media row per call (`DatabaseMediaReader::urls`), and Platform's README lists the batch read as belonging with Catalog | **[TO CONFIRM 17]** |
| Platform | **On stores only**: the store switch (platform.md §1.6) is in the spec but not the code — `StoreDto` has no `isActive` yet. Catalog's screens list only stores on, once Platform says which | Waits for the store switch build |

### 2.5 DTOs and enums

Plain `final readonly` classes (handoff §4.3). Enums stored as strings: `ProductStage` (`DRAFT`,
`READY`, `ARCHIVED`), `AttributeKind` (`INFORMATIONAL`, `FILTERABLE`, `VARIANT`), `AgencyType`
(`HOUSE`, `EXCLUSIVE_AGENT`, `DISTRIBUTOR`), `SaleMode` (`RETAIL`, `WHOLESALE`).

---

## 3 · Use cases

**[DECIDED 2026-10-02] One permission per job, any role** — staff or admin — may be given any of them;
none is admin-only. **The shared lists use one permission each.** Names below are my proposal
**[TO CONFIRM 18]**; all in the `Catalog` group.

| Use case | Permission | Scope |
|---|---|---|
| `CreateProduct` — a draft, Active nowhere | `catalog.product.create` | The staff member's working store |
| `UpdateProduct` — names, slugs, description, brand, category, warranty, search words, gallery, relations; `AddVariant`, `UpdateVariant`, `ArchiveVariant`, `RestoreVariant` | `catalog.product.update` | **Every store where the product is Active**; any store when it is Active nowhere |
| `CorrectVariantCode` | `catalog.variant.correct_code` | As `UpdateProduct` |
| `MarkProductReady` | `catalog.product.publish` | As `UpdateProduct` |
| `ArchiveProduct` / `RestoreProduct` | `catalog.product.archive` | As `UpdateProduct` |
| `ChooseInStore` — a whole product or single variants, Active or Inactive | `catalog.listing.choose` | That store |
| `SetSellingTerms` — retail, wholesale, the wholesale minimum | `catalog.listing.selling` | That store |
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
| `RebuildListing` — a repair job; `PruneSearchLog` — nightly | System (reserved) | — |

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

**[TO CONFIRM 19]** no way from `READY` back to `DRAFT` (a store hides a product by making it
Inactive), and a `DRAFT` is archived or deleted when abandoned.

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

| Table | Columns (outline — completed once §9.3 is answered) |
|---|---|
| `catalog.products` | `id`, `name_ar`, `name_en`, `slug_ar`, `slug_en`, `description_ar`, `description_en` (structured), `brand_id` FK RESTRICT NOT NULL, `category_id` FK NULL (required unless `DRAFT`), `warranty_id` FK NULL, `stage`, `attribute_set_id` FK NULL, `hidden_by_category`, `hidden_by_brand`, timestamps |
| `catalog.product_slugs` | `product_id`, `locale`, `slug`, `is_current` — every slug ever used, unique per locale |
| `catalog.product_search_words` | `product_id`, `word`, `normalized` |
| `catalog.product_photos` | `product_id`, `media_id` FK RESTRICT, `position` |
| `catalog.product_relations` | `product_id`, `related_id`, `kind` (`RELATED`, `GOES_WITH`), `position` |
| `catalog.variants` | `id`, `product_id`, `code`, unique on `lower(trim(code))` across all rows, `weight`, `length`, `width`, `height`, `is_archived`, `position`, timestamps |
| `catalog.variant_values` | `variant_id`, `attribute_id`, `value_id` (variant-making and filterable) |
| `catalog.variant_details` | `variant_id`, `attribute_id`, `text_ar`, `text_en`, `number` (informational) |
| `catalog.variant_photos` | `variant_id`, `media_id` FK RESTRICT, `position` |
| `catalog.store_variants` | `store_id`, `variant_id`, `is_active`, `not_available_now`, `sells_retail`, `sells_wholesale`, `wholesale_minimum`, timestamps — store-scoped (`BelongsToStore`) |
| `catalog.store_products` | `store_id`, `product_id`, `not_available_now` — store-scoped |
| `catalog.store_product_labels` | `store_id`, `product_id`, `label_id` — store-scoped |
| `catalog.categories` | `id`, `parent_id`, `name_ar`, `name_en`, `slug_ar`, `slug_en`, `is_active`, `deactivated_with_parent`, `image_media_id` FK NULL RESTRICT, timestamps |
| `catalog.category_slugs` | as `product_slugs` |
| `catalog.store_category_ranks` | `store_id`, `category_id`, `rank` — store-scoped |
| `catalog.brands` | handoff §9.4's columns; one `is_default` (partial unique); `logo_media_id` FK RESTRICT |
| `catalog.attributes`, `catalog.attribute_values`, `catalog.attribute_sets`, `catalog.attribute_set_members` | §1.7; values unique per attribute on `lower(trim(name))` in each language |
| `catalog.labels`, `catalog.warranties` | §1.8, §1.9 |
| `catalog.word_pairs` | `id`, `word_a`, `word_b`, normalised, unique as a pair |
| `catalog.search_log` | `id` bigint, `store_id`, `locale`, `normalized_query`, `results`, `searched_at` — no person |
| `catalog.listing` | the listing and search read model, one row per store, language and product: names, slug, brand and its default-listing flag, category path, value ids for filters, label ids, the card's photo, orderable, price and sales rank (pushed, §2.2), the search document and trigram text. Indexes from handoff §9.4: `(store_id, locale, brand_visible_by_default, sales_rank)`, `(store_id, locale, brand_id)` **[TO CONFIRM 20]** |

---

## 6 · Events

### 6.1 Published (ids only, after commit)

**[TO CONFIRM 21]** `ProductMadeReady`, `ProductArchived`, `ProductRestored`, `ProductChanged` (shared
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
languages. **[TO CONFIRM 22]** — the first list, from the rules above: `ProductNotFound`,
`VariantNotFound`, `CategoryNotFound`, `BrandNotFound`, `AttributeNotFound`, `CodeTaken`,
`SlugTaken`, `ProductNotReady` (what is missing), `ProductNotEditable` (archived), `InvalidStageChange`,
`CategoryHoldsProducts`, `CategoryNotLowest`, `CategoryInUse`, `BrandInUse`, `DefaultBrandRequired`,
`BrandInactive`, `CategoryInactive`, `DuplicateCombination`, `InvalidCatalogAttribute` (a value the
domain refuses), `ImportRefused` (the preview's reasons).

---

## 8 · Test scenarios

Written in full once §9.3 is answered. Already fixed by the decisions above:

1. A product leaves `DRAFT` only with names, slugs and description in both languages, a brand, a
   lowest active category, a variant with a code and a photo whose sizes are ready.
2. A code is unique across every variant ignoring letter case; correcting one is its own permission,
   audited; an archived variant's code is never given to another.
3. A store chooses a whole product or single variants; a variant added later is chosen nowhere.
4. "Not available now" on a product hides every variant there, later ones included; on a variant,
   that variant only.
5. A KSA-only person cannot change Egypt's row, nor the shared data of a product Egypt sells.
6. Only orderable products are listed; a direct link to anything else that exists shows "Not
   available now", noindex.
7. An empty category disappears from a store and comes back with its products; a parent lists
   everything below it.
8. Deactivating a category or brand applies each product's choice in one step, all or nothing; no
   product is ever left without a brand.
9. "Black" and "black" are one value of an attribute.
10. The search log keeps no person and loses entries after 12 months.

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
| 22 | Selling mode | **Per variant, per store**, with the wholesale minimum (§1.3) |
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
| 37 | The import's file format | **The owner sends a sample file** (§1.12) |
| 38 | Stock flags (stock-dependent, low-stock threshold, ending soon) | Asked what Catalog then contains; on the list, **Inventory, stage 5** |

### 9.2 Left open

| # | What | Waits for |
|---|---|---|
| 1 | The import's file format (§1.12) | The owner's sample file |
| 2 | A product video (§1.1) | The owner: "leave it for now" — a link to a hosted video, or a Platform amendment for uploaded video |
| 3 | Product add-ons ("Product apps"), bundles | A later stage (handoff §15.2) |

### 9.3 To confirm — my proposals, each waiting for the owner

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
10. The wholesale minimum: a whole number 1–100,000, required when wholesale is on.
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
