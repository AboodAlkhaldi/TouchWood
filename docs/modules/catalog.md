# Catalog — Module Specification

> **Written with the owner on 2026-10-02.** The owner's answers are recorded as **[DECIDED
> 2026-10-02]** and listed in §9.1. **[ACCEPTED 2026-10-02, §9.3 #n]** and **[ACCEPTED 2026-10-02,
> §9.5 #n]** mark proposals of mine the owner accepted (§9.3 #10 replaced by the owner's own answer).
> The JSON import's file format, which waited for the product tables, is written in §1.12 from the
> finished tables (amendment 6, 2026-10-05).

**Status:** **APPROVED** by the owner, 2026-10-02 (§9.1 #43); the import's file format written with
the owner on 2026-10-05 (amendment 6); **being built** from 2026-10-02 (`src/Modules/Catalog/README.md`). Changes from here on are
amendments and need the owner's agreement. **The backend is built** (steps 1–7, `main` since #91, and
amendment 12); **its screens** — the admin's and the shop's — are specified in §4.4 and §4.5
(amendment 13, 2026-10-07), with the owner's rule on individuals and companies (§1.13, amendment 14),
and built from 2026-10-07. **Amendment 16** (2026-10-09) — one code per variant, variants with free
values and no attribute sets, every list ordered by dragging, Pricing's reads, every box checked as it
is typed — is written for the owner's review before it is built.
**Tier:** 1 (commerce core). **Build stage:** 4 (handoff §17).
**Depends on:** Platform, and Access's public surface for declaring permissions only (§2.4,
**[DECIDED 2026-10-02]** — a change to handoff §4.4 and `deptrac.yaml`); its screens, from its
Presentation layer, on the framework glue in `app/Http` as every module's screens do (owner,
2026-10-07, amendment 13(d)).
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
| `name` | Arabic and English. **[ACCEPTED 2026-10-02, §9.3 #2]** One line, at most 200 characters each. **A draft may have its Arabic name only**; the English name — and so the English slug — is required to be made ready: every product shown has both (owner, 2026-10-03, amendment 3(g)). |
| `slug` | **One Arabic slug and one English slug, used in every store** **[DECIDED 2026-10-02]** — replacing handoff §4.1's "slugs are store-scoped", which disagreed with §9.1. Arabic letters for `ar`, Latin for `en` (handoff §5.2). Unique per language among products. Every slug the product has ever had is kept, so an old address answers with a 301 (handoff §5.2) **[ACCEPTED 2026-10-02, §9.3 #3]**. |
| `description` | Arabic and English. **Simple formatting** **[DECIDED 2026-10-02]**: paragraphs, bullet lists, bold and headings, kept as safe structured text — no raw HTML is stored, so nothing typed can run as a script. **[ACCEPTED 2026-10-02, §9.3 #4]** at most 20,000 characters each. |
| `brand_id` | **Exactly one brand, never none** (handoff §9.4, NOT NULL; **[DECIDED 2026-10-02]**: "no products with no brand"). The form starts on the default brand (§1.6). An inactive brand cannot be chosen. |
| `category_id` | **Exactly one category** **[DECIDED 2026-10-02]**, and only a category with no sub-categories (§1.5). Empty while the product is a draft; required to leave `DRAFT`. **A ready product keeps a category deactivated after it was placed there**; making a product ready, restoring it or moving it still needs an active one (owner, 2026-10-04, amendment 3(m)). |
| `warranty_id` | **At most one warranty**, from the list (§1.9) **[DECIDED 2026-10-02]**, the same in every store. |
| `stage` | `DRAFT`, `READY` or `ARCHIVED` (§4.1) — **one for the product everywhere** **[DECIDED 2026-10-02]**. Each store's own on/off is its choice (§1.3). |
| `search_words` | **Extra words staff type to help search** **[DECIDED 2026-10-02]**, in either language ("slide", "rail"). **[ACCEPTED 2026-10-02, §9.3 #5]** at most 30 words, each one line of at most 50 characters. A word typed twice, or two spellings search reads as one ("مفصلة", "مفصله"), is kept once, quietly (amendment 3(f)). |
| gallery | **An ordered gallery of photos** (Platform public media) **[DECIDED 2026-10-02]**. **[ACCEPTED 2026-10-02, §9.3 #6]** at most 20, each photo once. Deleting a photo's file from the media library takes it out of the gallery, audited — except **the last ready photo of a `READY` product, whose delete is refused** (owner, 2026-10-03, amendment 3(b)). |
| relations | **Hand-picked "Related"** and **hand-picked "Goes with"** products, each ordered **[DECIDED 2026-10-02]** (§1.10). **Only `READY` products can be picked**, at most 20 in each list; one archived later stays linked and is not shown (owner, 2026-10-03, amendment 3(d)). |
| filter values | **Values of filter attributes, set on the product** for all its variants, **several for one attribute allowed** ("Suitable for: Kitchen, Bathroom"); each variant's variant-making values count as filters too (owner, 2026-10-03, amendment 3(a)). |

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
| `code` | The SKU — **for staff and admins only, never shown to customers** (owner, 2026-10-04, amendment 5(d)) — the provider's internal reference (handoff §9.1, §12.2), and the import's key. **Digits only, 1 to 10 of them** (owner, 2026-10-03, amendment 3(j)), kept as text, compared after trimming (owner, 2026-10-03: "they are numbers only", "they will still be numbers only"). **Every variant has its own code: two variants never share one** — the drawer in 60, 80 and 90 cm has three codes (owner, 2026-10-09, amendment 16(a): "each variant has its own code … item contains multiple variants and each one may have its own price"). Every code a product's variants ever held — a corrected typo included — **stays with that product until the product is deleted**, which only a draft can be (§4.1), so no other product takes it; one of the product's own variants may take a code back that none of its variants carries now; once the product is deleted the code is free again (owner, 2026-10-03, amendment 3(e), its "variants may share it" replaced by amendment 16(a)). **Staff may correct a mistyped code** under its own permission, audited: the correction changes the one variant. **In a draft**, a variant's code is edited, and a variant deleted, with the product's own permission, and a code given up is free again (amendment 3(c)). An order keeps the code it was placed with (Sales snapshots it). |
| attribute values | **One value for each of the product's variant attributes** — the variant-making attributes its variants use, chosen in the product's Variants tab, not from an attribute set (owner, 2026-10-09, amendment 16(b), §1.7) — and its informational values. **Every variant of a product uses the same attributes**, and **no two variants of one product have the same combination** — the same size in two colours is two variants. **They stay editable, a `READY` product's too** (owner, 2026-10-03, amendment 3(j)); an order keeps the values it was placed with (Sales snapshots them). |
| `weight`, `length`, `width`, `height` | **Physical facts, per variant, in Catalog** **[DECIDED 2026-10-02]**, optional now; Shipping reads them and decides later what it requires. **[ACCEPTED 2026-10-02, §9.3 #9]** whole grams and whole millimetres, each 1 to 1,000,000. |
| photos | **Its own ordered photos**, shown when the variant is chosen, falling back to the product's gallery **[DECIDED 2026-10-02]**. They hang off the variant, not its code, so correcting a code leaves them in place. **[ACCEPTED 2026-10-02, §9.3 #6]** at most 10. |
| `is_archived` | **A variant can be archived on its own**, product-wide (a discontinued length), and **restored** **[DECIDED 2026-10-02]**. Archiving makes it Inactive in every store. Its code stays its own. |
| `position` | Its order among the product's variants, **set by dragging** in the Variants tab (amendment 16(d)). |

### 1.3 Store listing — each store's choice

**[DECIDED 2026-10-02]** One shared product table; **each store chooses what it sells** (handoff
§9.1). Price and stock are **not** here: Pricing and Inventory own them, from stage 5.

Per store, per **variant**:

| Attribute | Invariant |
|---|---|
| `is_active` | **The store's choice.** In the panel staff choose **a whole product or single variants** **[DECIDED 2026-10-02]** ("a mix of both … when we add products from the admin panel, not the bulk import"); choosing the whole product makes every variant that is not archived Active. **A variant added later is chosen in no store** until each store chooses it, since it needs its own price and stock there **[DECIDED 2026-10-02]**. Only a `READY` product's variants, not archived, can be Active. |
| `not_available_now` | **"Not available now"** (handoff §9.2's `force_unavailable`): a recall, a pricing error, a legal hold. Its own permission. Does not touch stock. |
| `sells_retail`, `sells_wholesale` | **The selling mode, per variant per store** **[DECIDED 2026-10-02]** (handoff §6: "product enablement per store"): retail, wholesale, or both — at least one. **A variant first chosen in a store sells retail only**; wholesale is switched on with its minimum (owner, 2026-10-04, amendment 4). |

Per store, per **product**:

| Attribute | Invariant |
|---|---|
| `not_available_now` | **"Not available now" for the whole product** — every variant, including ones added later **[DECIDED 2026-10-02]**: "both, like the choice". |
| `retail_minimum`, `retail_maximum`, `wholesale_minimum`, `wholesale_maximum` | **Each product has its own minimums and maximums, per store, each selling mode its own** **[DECIDED 2026-10-02]** (the owner's answer to §9.3 #10, which it replaces: "each product has its minimums and maxes"). They limit the quantity of one variant on one order in that mode; Sales refuses outside them (stage 6). Retail's minimum is 1 unless set; each maximum is optional; the wholesale minimum is required while any of the product's variants sells wholesale in the store. Wholesale prices and tiers are Pricing's. **[ACCEPTED 2026-10-02, §9.5 #1]** each a whole number from 1 to 100,000, and a maximum never below its minimum; **a wholesale maximum needs its wholesale minimum** (owner, 2026-10-04, amendment 4(j)). |
| labels | **Custom labels attached in this store** (§1.8) **[DECIDED 2026-10-02]**: at most 10 per product per store; a label deactivated since stays where it is attached, none is attached anew (amendment 4). |

**A product is Active in a store** while at least one of its variants is Active there — a store that is
switched off included, so a store being prepared counts among those whose people must hold a job to
change the product's shared data (owner: no preference; the recommendation kept, amendment 4(i)) — that is the
row the product page shows per store **[DECIDED 2026-10-02]**: "one single global panel and in it will
show each store's status … from there we can activate, deactivate or deal with the product … a KSA-only
can't edit a product at the Egypt store". **Each store's row is changed only by someone holding the
permission in that store.**

**How a store is filled** (owner, 2026-10-04, amendment 4; 2026-10-05, amendment 6(g)): **one by one**,
under `catalog.listing.choose` in that store — staff's everyday work; or, for admins, **a JSON file of
codes for one store**, under its own job, `catalog.listing.fill`, **held only through an admin role** (amendment 6(h)) (the
product import stays Super Admin only, §1.12). **The file holds codes and prices, stock optional**
(`touchwood-store-fill/1`: `{"format": …, "items": [{"code": "1304", "price": 120, "stock": 15}]}`) —
**[ACCEPTED 2026-10-05]** at most 1,000 items, each code once, the file at most 2 MB; a price at least
0 with at most 6 decimal places, a stock a whole number from 0 to 2,147,483,647 (amendment 11(d)). A
file not in this format is refused whole, every error listed. **Every product must already exist**: the file **never creates or edits a product**. **Its
page** lists each item with its state: **ready** — its product is ready, and switching it on chooses
the variant carrying the code in that store (amendment 16(a)); **not ready** — what the product lacks, completed in the
product page and then switched on here; **archived**; **already on**; **unknown code** — a typo or a
miss: **corrected** (checked again) or **removed**. The admin **switches on** the items selected, or
every ready one. Prices and stock are shown and, until stage 5, not kept. **Nothing goes on sale in a
store until it has a price there**, by an API or in the file (Pricing, stage 5). A store that is
switched off can be filled, its terms and labels set, so a new store is prepared before it opens.
Selling terms, labels and "Not available now" are refused for a product the store has never chosen
(`NotChosenInStore`); a store that switched all of a product's variants off still edits them.

**Archiving a product** makes it Inactive in every store; **restoring it** brings it back to the
stage it left — `READY` if it was ready — Inactive everywhere **[DECIDED 2026-10-02]**, amendment 3(m).

**Out of stock is never shown** (handoff §9.2). Until Inventory exists (stage 5), "orderable now" in a
store means: the product `READY`, the variant Active there and not archived, and neither the variant
nor the product "Not available now" there **[DECIDED 2026-10-02]**. From stage 5, Inventory pushes
whether each variant is orderable (§2.2). **Until Pricing exists (stage 5), a product a store chose
counts as on sale without a price**, so the shop can be built and tried; from stage 5, no price there
means not on sale there (owner, 2026-10-04, amendment 5(b); amendment 4(c)).

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
| `rank` per store | **The admin-set order, per store** (handoff §9.3), among a category's siblings, set by that store's people **by dragging** (amendment 16(d)). **A category added goes last among its siblings**, the same in every store; each store's admins drag it into place there afterwards (owner, 2026-10-09, amendment 16(d), replacing amendment 1(d)'s place chosen when adding). **Moving it** under another parent puts it last among the new siblings, written the same way (replacing amendment 2(c)'s place chosen when moving). **A store opened later starts with no order**: its admins set it; a category added after it opened gets its place there too (owner, 2026-10-03, amendment 2(b), (c)). |
| image | **[ACCEPTED 2026-10-02, §9.3 #11]** an optional public photo, for category cards (the design's homepage shows them). |

- **Products sit at the end** **[DECIDED 2026-10-02]**: only a category with no sub-categories holds
  products ("a category can hold a subcategory but products come at the end; but also we can make a
  single level, category and products in it"). Adding a sub-category under a category that holds
  products is refused until they are moved.
- **A parent lists everything below it** **[DECIDED 2026-10-02]**.
- **Shown in a store by itself**: a category appears in a store only while something in it, or under
  it, is listed there; an empty one disappears and comes back with its products **[DECIDED
  2026-10-02]** — "Egypt sells no lighting → no Lighting in Egypt's menu". **It takes its place in
  that store's menu from the store's own order; until the store's admins place it — a store opened
  later — the base store's place** (owner, 2026-10-04, amendment 5(a)). A category deactivated by hand
  is not in any menu; its products follow the fate each was given (§1.5 above).
- **Deactivating a category** **[DECIDED 2026-10-02]**: its sub-categories are deactivated with it,
  and activating it again brings back only what was active before. **For each product in it or under
  it, staff choose** — every product, in any stage, those under a sub-category switched off before
  included, their earlier choice asked again (amendment 4(d), (g)) — with an "apply to all" shortcut — to **hide** it (no longer listed, searched or
  suggested; a direct link shows "Not available now"; activating the category brings it back) —
  **a hidden product is inactive: it cannot be ordered**, and the deactivation screen tells staff so
  (owner, 2026-10-05, amendment 5(j)) — to
  **leave** it (it keeps the inactive category, so it still has exactly one, unlisted but reachable),
  or to **move** it to another active lowest category, outside what is being deactivated. Activating
  the category brings back the products hidden with it, except those under a sub-category that stays
  off (amendment 4). A product left, or hidden with its category,
  is still edited as before (owner, 2026-10-04, amendment 3(m)). The whole deactivation is **one step: all of
  it happens, or none of it**, and each product's change is audited.
- **Deleting a category** **[DECIDED 2026-10-02]**: only one that holds no product (in any stage) and
  no sub-category; otherwise refused until they are moved. Its addresses are freed with it: another
  category may take them later (owner, 2026-10-03, amendment 2(a)). **Moving a category** under another
  is allowed; its addresses do not change, since a slug names the category, not its path. A category
  that went with its parent and is moved away stays deactivated, now on its own, so activating its new
  parent does not bring it back (amendment 2(g)).
- **Changed only by someone holding `catalog.category.manage` with All stores** **[DECIDED
  2026-10-02]**, since the tree is every store's; a store's ranks by that store's people (§3).

### 1.6 Brand

Global, one row per brand (handoff §9.4): `slug` (one per language **[DECIDED 2026-10-02]**), `name`
(Arabic and English), `logo` (a public photo), `description` (Arabic and English), `origin_country`,
`agency_type` (`HOUSE`, `EXCLUSIVE_AGENT`, `DISTRIBUTOR`), `is_default`, `show_in_default_listings`,
`position` — **the brands' order, set by dragging**, a new brand last (amendment 16(d)) —, `is_active`.
**Do not create a table per brand** (handoff §9.4, §16).

- **Every brand has a fixed number** (owner, 2026-10-05, amendment 7(b)) — 1, 2, 3 … given when it is
  added, shown in the panel as **Brand No.** (amendment 16(d)) — never its place in a list —, never changed while the brand exists — so a products file names a brand by
  its number, without a typo (§1.12). **A deleted brand's number is free again: a new brand takes the
  lowest number no brand holds — no gaps** (owner, 2026-10-06, amendment 10(a)).

- **Exactly one default brand at any time** **[DECIDED 2026-10-02]**, pre-selected on the product
  form; an admin may make another brand the default, which un-marks the old one. A mark on the row,
  seeded on TouchWood — never a brand name in code (handoff §2 rule 2). **[ACCEPTED 2026-10-02, §9.3 #12]** the
  default brand cannot be deactivated or deleted until another is made the default.
- **The seed creates TouchWood alone** (owner, 2026-10-03, amendment 1(c)): Arabic «تاتش وود»,
  English "TouchWood", the house brand of Saudi Arabia, shown in default listings, the default.
  Every other brand, and every category, is entered by staff or brought by the import. The name lives
  in the seeder only, never in `Domain/` or `Application/`.
- `show_in_default_listings` is copied into the listing read model (handoff §9.4): the default grid is
  one indexed condition with no join. Changing it re-stamps that brand's rows. **A brand hidden from
  default listings is a secondary brand** (owner, 2026-10-05, amendment 5(k), replacing 5(h)): the
  store is TouchWood's; staff put a secondary brand's products under **its own category** — "Tallsen"
  beside Kitchens and Wardrobes, with sub-categories of its own — and **that category is the only way
  to them**: the menu and the category pages show it as any other, while **the search, the home page
  and every shop-wide grid show only the brands shown in default listings**. Its brand page stays
  (§1.6). Suggestions keep it to its own products' pages (§1.10).
- **Deactivating a brand** **[DECIDED 2026-10-02]**: for each of its products, staff choose — with
  "apply to all" — to **hide** it with the brand, or to **move** it to another active brand; never to
  leave a product without a brand. One step, all or nothing, each change audited. An inactive brand
  leaves the form's choices, the brand filter and its brand page.
- **Deleting a brand** **[DECIDED 2026-10-02]**: only one no product carries, archived ones included.
  Its addresses are freed with it (owner, 2026-10-03, amendment 2(a)).
- Changed only by someone holding `catalog.brand.manage` with All stores.

### 1.7 Attributes, values, colours

**One shared library** **[DECIDED 2026-10-02]**.

- An **attribute** is defined once: its name in both languages, its **kind** — informational,
  filterable, or variant-making (handoff §9.1) — and an optional **unit** (mm, kg).
- A **filterable** or **variant-making** attribute has a **list of values**, each named in both
  languages. **Two values of one attribute never match after trimming, ignoring letter case**:
  "Black" and "black" are one value **[DECIDED 2026-10-02]**.
- A **colour** attribute's values carry a **swatch** — the design's **Colours** library.
- **A value's order is set by dragging** on the attribute's page (amendment 16(d)), a new value last:
  it is the order the shop's pickers show (45 → 60 → 80 cm).
- **A product's variant attributes** (owner, 2026-10-09, amendment 16(b), replacing the attribute sets
  — the design's **Variations**): **the variant-making attributes its variants use, chosen in the
  product's Variants tab** — no set chosen first, no Variations screen. **Every variant of a product
  uses the same attributes**, in the product's own order (the order of the shop's pickers), and **no
  two of its variants have the same value of every one** — 45 cm in white and 45 cm in black are two
  variants. **An attribute added to a product asks each of its variants — archived ones too — for its
  value**; **one is removed only while its variants, archived ones included, stay different without
  it**. A product with no variant attribute has
  one variant; adding an attribute is how it gets more — a ready product's too. **A new value may be
  made there, by whoever may change the product** (`catalog.product.update`, amendment 16(c)), named in
  both languages (a colour with its swatch) under the same rules as on the Attributes screen, last in
  the attribute's order; the attribute must be active.
- **Price is per combination, never additive**, and **the server resolves the variant** from the values
  a shopper picks; the frontend never does (handoff §9.1).
- **[ACCEPTED 2026-10-02, §9.3 #13]** informational values: per variant, as text in both languages or a number with the
  attribute's unit.
- Changed only by someone holding `catalog.attribute.manage` with All stores — but for a value made
  from a product's Variants tab (above). **[ACCEPTED 2026-10-02, §9.3 #14]**
  attributes, values, labels, warranties and word pairs are deactivated and deleted by the
  same rule as brands and categories: deactivated (reversible), deleted when nothing uses them.

### 1.8 Custom label

**In this stage** **[DECIDED 2026-10-02]**. A staff-managed list: a name in both languages and a tone.
**The list is global; each store attaches labels to products itself** — "Clearance" in KSA need not
show in Egypt **[DECIDED 2026-10-02]**. In Arabic the screens call them **«الشارات»** (owner,
2026-10-03, amendment 1(b)).

- **A label is drawn with Geist's Badge** (vercel.com/geist/badge; frontend.md §1.8), and **its colour
  follows Geist's meanings** (owner, 2026-10-03, amendment 1(e)): "green is always healthy". Staff
  choose a **meaning** — neutral (gray), information (blue), healthy (green), warning (amber) or error
  (red) — and whether it is **strong or subtle**: exactly the ten variants the project's Badge has. The
  screen names each choice by its meaning, never by a bare colour.
- **A name is one or two words** in each language, at most 30 characters (owner, 2026-10-03,
  amendment 1(f), following Geist: "one word when possible, two max").
- **Every label attached shows on the product's card**, in the list's order — set by dragging on the
  Labels screen, a new label last (amendment 16(d)) — (owner, 2026-10-03,
  amendment 1(g): "for now … we might change it later"). Geist would show one badge a row; the owner
  chose all, for now.

### 1.9 Warranty

**In this stage** **[DECIDED 2026-10-02]**. A staff-managed list: a name and terms in both languages,
and a period in months or "lifetime". A product carries **at most one**, the same in every store.
**[ACCEPTED 2026-10-02, §9.3 #2]** name at most 100 characters, terms at most 5,000 with simple formatting, a period
of 1 to 600 months. **The list has an order, set by dragging**, a new warranty last — the order of the
product form's choice (owner, 2026-10-09, amendment 16(d)).

### 1.10 Relations

**[DECIDED 2026-10-02]** On a product's page:

- **"You may also like"** shows the hand-picked **Related** products; **when staff picked none, it fills
  itself** from the same category, then the same brand — a secondary brand's products (§1.6) only on
  its own products' pages (amendment 5(l), my reading of the owner's "reached only through their
  section", to confirm).
- **"Goes with"** shows the hand-picked accessories only (a hinge's mounting plate).
- Only products listed in the store being viewed are ever shown.

### 1.11 Search

**A search looks in the product's name, its search words, the shared word pairs and its category's
name — never its brand, its code or its description** (owner, 2026-10-04, amendment 5(c), (d)).
**Both languages' names are searched on every page**, the results shown in the page's language, and
**the category's name is that of its category and of every category above it** (owner, 2026-10-05,
amendment 5(f), (g)). **Only the brands shown in default listings are searched**: a secondary
brand's products are reached through their own category, never by search (amendment 5(k)).
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
  **A search is logged when it is submitted** — Enter, or the results page — never each suggestion
  shown while the shopper types (owner, 2026-10-04, amendment 5(e)).

### 1.12 The JSON import

**Lives in Catalog** **[DECIDED 2026-10-02]**. Super Admin only (handoff §9.1). **The file's format
follows the product tables we built** **[DECIDED 2026-10-02]**, written here from the finished tables
and agreed with the owner before step 6 (2026-10-05, amendment 6). **Each uploaded file gets its own
page**, where the Super Admin decides what the file could not say and accepts its products one by
one or together (owner, 2026-10-05, amendment 6(a)) — replacing "a preview, then all or nothing".

**What is uploaded** (amendment 6(b)): **the JSON alone** — its products arrive without photos and
stay drafts until each is given one in the panel — **or one zip holding the JSON and its photos**,
the JSON naming each photo by its path inside the zip. **[ACCEPTED 2026-10-05]** at most 2,000
products a file; the JSON at most 20 MB, a zip at most 500 MB and 100,000 entries (amendment 11(d));
photos as the media library takes them.

**The format** (`touchwood-products/1`) **[ACCEPTED 2026-10-05]** — with a guide to filling it and
both files as complete examples in [`catalog-import/`](catalog-import/README.md), for a person or another
AI agent to fill (owner, 2026-10-05) — **the guide and its examples follow amendment 16 with the backend
that reads them**; until then they still show a set and a shared code:

```json
{
  "format": "touchwood-products/1",
  "products": [
    {
      "name": { "ar": "درج معدني", "en": "Metal drawer" },
      "slug": { "en": "metal-drawer" },
      "description": { "ar": "…", "en": "…" },
      "brand": "TouchWood",
      "category": "Kitchens / Drawers",
      "warranty": "Two years",
      "variants": [
        { "code": "1304", "values": { "Width": "60 cm" }, "weight_g": 2500,
          "details": { "Material": { "ar": "فولاذ", "en": "Steel" } }, "photos": ["1304/60.jpg"] },
        { "code": "1314", "values": { "Width": "80 cm" } }
      ],
      "photos": ["1304/front.jpg", "1304/side.jpg"],
      "search_words": ["سحاب", "slide"],
      "filters": { "Use": ["Kitchen", "Wardrobe"] },
      "related": ["1305"],
      "goes_with": ["2001"]
    }
  ]
}
```

| Field | Required | What it holds |
|---|---|---|
| `name` | `ar` | The names (§1.1): the Arabic one always, the English one needed to be ready |
| `slug` | No | Either language; made from the name when left out (§1.1) |
| `description` | No | Plain text in each language: a blank line starts a paragraph, a line starting `- ` a list item, `# ` a heading, `**…**` bold — kept as the structured text of §1.1 |
| `brand` | No | The brand's **fixed number** (§1.6) or its name in either language; the default brand when left out (amendment 7(b)) |
| `category` | No | The path of names from the top, ` / ` between them, ending at a lowest category (§1.5) |
| `warranty` | No | A name in either language |
| `variants` | At least one | Each: `code` (digits, §1.2) — **its own: two variants never share one** (amendment 16(a)); `values` — attribute name → value name, **every variant of the product naming the same variant-making attributes**, the first variant's order being the product's (§1.7, amendment 16(b)); `details` — attribute name → text in both languages or a number (§1.7); `weight_g`, `length_mm`, `width_mm`, `height_mm`; its own `photos` (at most 10) |
| `photos` | No | The gallery, in order: paths inside the zip, at most 20 |
| `search_words` | No | At most 30 (§1.1) |
| `filters` | No | Filter attribute name → its value names (amendment 3(a)) |
| `related`, `goes_with` | No | Codes of other products — in the file or already in the catalog (§1.10) |

**Names** — of brands, categories, attributes, values and warranties — are matched as search
compares words (letter case, Arabic marks and letter forms ignored), in either language. **Brands,
warranties and attributes must be in the catalog before the file is uploaded** (owner, 2026-10-05,
amendment 7(a)): a name of one the catalog lacks is picked as one it has, or refused. **Values and
categories may be created on the import's page.** **A name several catalog items answer to**
— two warranties both "Two years" — is listed there too, with how many it matches, for the Super
Admin to pick which (owner, 2026-10-06, amendment 8(d)).

**Checking the file.** A file that is not this format — not JSON, a field of the wrong kind, a
product with no Arabic name or no variant, a code not digits, **two products sharing a code**, **two
variants sharing a code** (amendment 16(a)), two variants of one product with the same values, **variants of
one product naming different attributes** (amendment 16(b)), a photo not in the zip, a number out of range, over the
limits — and, read against the catalog (amendment 8(e)), an attribute used for two jobs or for a job
the catalog's attribute of that name does not have, a category path ending at a category with
sub-categories, a photo that is not JPEG, PNG or WebP or is over the media library's limit
— **is refused whole**, every error listed (`ImportRefused`). A file that passes becomes **an import**
with its page, and nothing in the catalog has changed yet.

**Codes that mix catalog products are left out, the rest of the file coming in** (owner, 2026-10-06,
amendment 11(a)): a product whose codes two of the catalog's products hold, and two or more products
whose codes one catalog product holds — the file gives a product once, with all its variants. They are
shown on the import's page as **refused, with the reason**, and take no part in it: no names asked
for them, no decision, no change, never brought in. A name matched to something deactivated is listed
as found; bringing in then stops at the product using it, naming it, until it is active again or
another is picked (part 3 below).

**The import's page** (amendment 6(a), (c)–(f)):

1. **Names the catalog does not have** — a category path, a brand, an attribute, a value, a
   warranty — each listed once with the products using it. For each, the Super Admin decides: **it
   means one we have** (a typo: pick it), **create it** — a value or a category only (amendment
   7(a); sets gone with amendment 16(b)): its wording corrected first if need be, and its name in the other language given, the lists
   holding both; a value under an attribute the catalog has or the one picked for it; a category given
   its own web address when the one made from its names is taken (amendment 8(c)) —, or **refuse
   it** — a product then comes in without it where it is optional (a refused brand: the default brand),
   and is held back where it is required (a variant's value, or its attribute). A category picked for
   a product of the file must have no sub-categories (§1.5). One name at a time, or several together.
2. **Codes the catalog already has** — each product whose code another product holds: **update** that
   product with what the file gives (the rest stays as it is), **replace it whole** (what the file
   leaves out is cleared; its variants the file does not name are archived), **skip** the product, or
   **it was a typo** — give it another code, checked again. **A product updated or replaced that is on
   sale** in any store also needs, every time, **keep on sale** — a small change, shown at once — or
   **take off sale** — switched off in every store when brought in, still ready, its page "Not
   available now", orders keeping their own copy, back on sale when a store's admins switch it on
   again (owner, 2026-10-06, amendment 9(c)). **A product not a draft keeps its codes** (amendment
   11(b)): one the file would update or replace with a variant of the same values but another code is
   listed, and the confirm waits — skip it, or upload the file corrected. One product at a time, or
   several together.
   **Changes to all of them, or the selected** (owner, 2026-10-05, amendment 7(c), (d); 2026-10-06,
   amendments 8(a), 9(a)) — while they wait, before they are brought in: the **brand**, **warranty** or
   **category**; **search words** and **filter values**. Each asks whether to **replace** what the
   products have or **only fill the ones that have none**; search words and filter values may also be
   **added** to what each has. A product the file **updates** counts what the catalog's product has,
   as it is when the products are brought in.
   "All of this is just a draft": nothing reaches the catalog until the products are brought in. The
   file as uploaded is kept beside the products as changed.

   **Web addresses that would collide** — two products of the file, or one and a product of the
   catalog, made the same address from the same name — are listed, and **each is given its own address
   on the page** before bringing in (owner, 2026-10-06, amendment 8(c)).
3. **Bringing the products in**, once every name and code is decided and every address free: one step, all or nothing,
   **creates them as drafts** — with their photos added to the media library — and updates or replaces
   the existing ones as decided — an update or a replacement that would leave a ready product without
   what it needs (§1.1) fails the step, naming it. From the queue, since a zip of photos takes
   minutes; the page says when it is done, or why it failed and that nothing was kept.
4. **The import's products**, each with its state: **ready to accept**, **missing** what it lacks (a
   photo, the English name, a description, a category …), updated or replaced, skipped, or held back.
   Each opens in the product page to be completed like any other. The Super Admin **accepts** one,
   several or every ready one — **made ready** ("ready to publish"), **switched on in no store**:
   the products file only brings products into the catalog, and **a store's admins publish them
   with their store file** (§1.3) or one by one (owner, 2026-10-06, amendment 9(b)), and linked to
   the ready products the file names as related or goes-with — one named that is not ready yet is
   linked when it is accepted here: the file's links are made on this page only — **archives** it, or
   **deletes** it — gone whole, its codes and addresses free again (§4.1) (amendment 6(e)). Archive and
   delete reach what the import created and nobody accepted **whatever was done to it since**: the
   Super Admin's word is carried out — one made ready meanwhile is archived, or deleted whole even if a
   store put it on sale, switched off there first (owner, 2026-10-06, amendment 11(c)).

Every change the import makes is audited as its own action. **The import's page stays** as the
record of what came from which file. **An import not brought in may be discarded** by the Super Admin
— its page, its names and products waiting, and its zip, gone; one bringing its products in, or
brought in, is not (owner, 2026-10-06, amendment 10(b)).

- **Prices and stock come only with a store's file** (§1.3; owner, 2026-10-06, amendment 9(a)): the
  products file names no store. A store file's are shown and, **until Pricing and Inventory exist
  (stage 5), not kept** **[DECIDED 2026-10-02]**: its page says so once (§9.3 #15). Catalog offers a
  registry where Pricing and Inventory add their sections in stage 5 (§2.3); in a store wired to a
  provider they are then ignored with a warning (handoff §9.1).
- **The admins' file that fills one store** (§1.3) is a separate, smaller page, under its own job.

### 1.13 Individuals and companies (amendment 14)

**The owner's rule** (2026-10-07, relayed from the stage 5 session and confirmed by the owner to this
one): "we dont have two kind of pricings, the individuals can only buy retail only, the wholesale is
only for companies, so we dont have a special companies prices ... the prices are same all across
system but the individual person can only purchase from retail products (and also he can only see
selected categories) but companies can see and buy both retail and wholesale with also selected
categories, so we can as admins select the individual and company accounts buyable things and also
what also reachable". The handoff's side of it — one price for everyone, wholesale for companies only,
reversing handoff §6's "wholesale is public" — is written by the stage 5 pull request (#97, handoff
§0.1 and §6; not yet merged when this was written, so handoff §6 on `main` still reads the old rule);
Catalog's side is here.

- **In each store, admins choose which categories individuals see and which companies see**
  (owner's clarification: "by categories, per store … a category brings everything under it").
  Two switches per category per store, **Individuals** and **Companies**, under a new job,
  **`catalog.category.audience`, admin roles only** (owner, 2026-10-07, #4: "admins pick"; declared
  `adminOnly`, as `catalog.listing.fill` is).
- **A category nobody has switched is seen by both** — a new category, and every category of a store
  opened later (owner, 2026-10-07, #3: nothing disappears by surprise). **Switching a parent switches
  everything under it the same way; a sub-category may then be switched on its own** (#5). A product
  is seen by a kind of account where **its own category** is on for it. **A sub-category added, or
  moved, under a parent takes that parent's switches in every store**, as switching the parent would
  have given it — otherwise one added later under a parent switched off for individuals would show
  to them **[PROPOSED P24]**.
- **Individuals buy retail only; companies buy retail and wholesale.** A company that is pending,
  rejected or suspended **sees the company side** — the companies' categories and the wholesale side
  — and orders only once that store approves it (B2B's rule, unchanged). **Guests see everything**,
  individuals' and companies' alike ("he can see everything … but when registering or login he will
  be limited to his profile") — what either kind of account sees, so a category switched off for both
  is seen by no one **[PROPOSED P25]**; a staff member viewing the shop is a guest (access.md §1.11). What a
  guest may put in a cart, and what happens to it at sign-in, is Sales' question (stage 6), not
  Catalog's.
- **What an individual is shown** (#6): only a product with at least one variant on sale **retail**
  in that store, and of it only those variants; **a product whose variants there all sell wholesale
  only is not shown to individuals at all** — not listed, searched or suggested; a link to it shows
  "Not available now" (§1.4) **[PROPOSED P18]**. Its retail minimum and maximum apply; the wholesale
  ones are not shown.
- **What a card shows** (#7): the price of **the first variant this viewer can buy** — switched on,
  orderable now, priced, in the product's own order (§2.2, amendment 15). An individual's card may
  therefore show another variant's price than a company's.
- **Who is looking** is asked of **Platform's `ShopperType`** — guest, individual or company — which
  Access fills from the signed-in customer's account type (owner, 2026-10-07, #2), as Access fills
  Platform's `StaffNames`: Catalog never reaches into Access for it (handoff §4.4).
- **Search, the menu, category and brand pages, suggestions and a product's page** all answer for the
  viewer's kind, from the listing's own rows (§5.4): each row says whether individuals and companies
  see it, and holds each side's card price.

---

## 2 · Public contract

### 2.1 `Modules\Catalog\Public\Contracts\CatalogApi`

Ids in, DTOs out (handoff §4.3). **[ACCEPTED 2026-10-02, §9.3 #16]** — the methods the modules after Catalog are known
to need, from the handoff:

| Method | For |
|---|---|
| `variant(string $variantId): ?VariantDto` | Pricing, Inventory, Sales, Shipping — the code, the product, the attribute values, weight and dimensions. **The code is never shown to a shopper**, an order's own pages included (amendment 5(d)) |
| `variantByCode(string $code): ?VariantDto` | Sync (the provider's codes), Pricing, Inventory, the import's later sections — **the one variant carrying the code**, archived or not, or none (amendment 16(a), replacing 3(e)'s `variantsByCode`; §9.2 #6 closed). A code a product held once and no variant carries now answers none |
| `product(string $productId): ?ProductDto` | Sales (snapshot), Feedback, Content |
| `storeVariant(StoreId $store, string $variantId): ?StoreVariantDto` | Sales: in that store, whether the variant is Active and orderable (§1.3 — never while its product is hidden, amendment 5(j)), "Not available now" (its own or its product's), its selling modes — a variant switched off keeps its own — and its product's minimums and maximums for each mode. Whether the store is on is Platform's to say |
| `resolveVariant(string $productId, array $valueIds): ?string` | Sales: the variant a shopper's picked values name (handoff §9.1) — one value of each of the product's variant attributes (§1.7) |
| `productIdsInCategory(string $categoryId): list<string>` | Pricing — a category discount and its preview: **the products in the category and in every category below it**, in any stage (amendment 16(e); pricing.md §2.4, PR #100) |
| `variantIdsOf(string $productId, bool $includeArchived = false): list<string>` | Pricing — a sale on every size of a product: **the product's variants in their own order** (§1.2), archived ones only when asked (amendment 16(e)) |
| `switchedOnVariantIds(StoreId $store): list<string>` | Pricing — its "Needs a Price" list — and Inventory: **the variants switched on in the store** (Active there, §1.3), in no particular order (amendment 16(e)) |

### 2.2 Listing facts — pushed in by the modules above **[DECIDED 2026-10-02]**

Catalog's listing read model needs facts it does not own. **The modules that own them push them in**,
inside their own transaction, so a list is never stale and every dependency still points down
(handoff §4.4):

| Fact | Pushed by | From |
|---|---|---|
| Whether a variant is orderable now in a store | Inventory | Stage 5 |
| Whether a variant is **ending soon** in a store — "last pieces" (amendment 16(h)) | Inventory | Stage 5 |
| The price shown, for the price filter and sort | Pricing | Stage 5 |
| The sales rank, for "best-selling" | Sales | Stage 6 |

`ListingFacts` (a `Public/Contracts` interface Catalog implements) **[ACCEPTED 2026-10-02, §9.3 #16]**:
`orderable(StoreId, list<variantId>, bool)`, `endingSoon(StoreId, list<variantId>, bool)` (amendment
16(h)), `prices(StoreId, map variantId → Money|null)`, `salesRanks(StoreId, map productId → int)`. Until stage 5, orderable follows §1.3. **Step 5 declares
the interface only; where these facts are kept, and which price a card shows, come with stage 5,
which first calls it** (owner, 2026-10-05, amendment 5(i)) — **now proposed below** (amendment 15):
Catalog keeps them and picks the card's variant, built with the shop's pages (P22). Every change rewrites a product's
listing rows from Catalog's own tables, so a pushed fact is kept where that writer reads it — its
own table — never only in the listing's columns, which the next change would write over.

**The prices, as stage 5 proposes them** (amendment 15, 2026-10-07 — the stage 5 session's
proposal, for the owner's OK with Pricing's own spec, #97) **[PROPOSED P22]**:

- `prices(StoreId $store, array $prices)` takes, per **variant**, a `ListingPrice` or `null` (no price:
  not on sale there, §1.3 from stage 5). `ListingPrice` — a new `Public/Dto` — holds `Money $now`,
  what one piece costs now in that store (the lowest applicable price — base, sale, campaign, category
  discount — never a quantity price), and `?Money $before`, the base price **only while `$now` is
  lower** (the owner: during a sale, the old price crossed out beside the new one); both without VAT.
- Catalog keeps what is pushed in its own table (`store_variant_facts`, §5.2) and **chooses the card's
  variant** itself: **the first, in the product's own order, switched on in the store, orderable now and
  priced — and buyable by the viewer** (owner, 2026-10-07, #7; amendment 14). The listing keeps that
  price, and its old price, **for each side** — companies and guests; individuals (§5.4) — and the
  price filter and sort read them.
- Pricing pushes inside its own transaction, only the variants that changed, one store a call. Catalog
  builds the receiving side — the table, the listing's columns, `ListingFacts` bound — with the shop's
  pages (§4.5), so stage 5 has something to call; until then every price is empty and nothing shows
  one (amendment 5(b)). **Open, for the owner** (from stage 5): what a company's card shows when its
  first variant sells wholesale only — stage 5 would push the same one-piece price.

### 2.3 The import's sections — added by the modules above **[DECIDED 2026-10-02]**

`ImportSections` (a registry, like Platform's `MediaUsages`): a module registers a class that reads its
part of a store's file, adds its lines to the file's page and writes its part when an item is switched
on in that store (amendments 6, 9(a): only a store's own file brings prices and stock). Pricing and
Inventory register theirs in stage 5; until then the registry is empty and a store file's page says,
once, that prices and stock were not kept.

### 2.4 What Catalog needs from other modules

| From | What | State |
|---|---|---|
| Access | **Declaring Catalog's permissions** in `PermissionCatalog`, in the `Catalog` group | **Done in step 1** (PR #77): `deptrac.yaml` lets Catalog's interior use Access's public surface, and `tests/Architecture/CatalogAccessUseTest.php` holds it to the five permission classes. Access itself is not changed by Catalog (owner, 2026-10-03: "the access is well working so we don't have to mess with it") — **but for one binding**: Access fills Platform's `ShopperType` (below; owner, 2026-10-07, amendment 14(a)), as it fills `StaffNames`; Catalog still uses nothing of Access beyond declaring its permissions |
| Platform | Stores, settings, the audit log, `MediaUsages` (photos and logos are detachable uses), `uploadMediaFor` (staff upload photos under Catalog's own permission) | Exists |
| Platform | **Photo addresses for product cards** — `mediaUrls()` reads one media row per call (`DatabaseMediaReader::urls`) | **No Platform change [ACCEPTED 2026-10-02, §9.3 #17]**: Catalog asks `mediaUrls()` when it writes a listing row and keeps the card photo's addresses in that row, refreshed on `MediaVariantsReady`, so a product grid reads no media at all. A change of CDN address is followed by the repair job (§3) |
| Platform | **Photo addresses for a page of photos at once** — the shop's product page (§4.5) | **A Platform addition** (owner, 2026-10-07, #10): one read answering the addresses of a list of media ids, so a product page reads its gallery in one query, not one per photo (today `mediaUrls()` reads one row a call). Platform's own amendment (`PlatformApi::mediaUrlsOf`), built with the first screens, step 1: the lists' logos and photos are read the same way |
| Platform | **Who is looking in the shop** — a guest, an individual or a company (§1.13) | **A Platform contract, `ShopperType`, that Access fills** from the signed-in customer's account type (owner, 2026-10-07, #2), as Access fills `StaffNames`; Platform's own answer, until Access binds it, is "guest". Platform's and Access's amendments, built with amendment 14 |
| Platform | **The shop frame's parts** — the category menu and the search box on every shop page (§4.5) | **A small Platform list** a module adds to, shared with every shop page (owner, 2026-10-07, #9), as B2B's shopper lines are added through Access. Platform's amendment, built with the shop's pages |
| Platform | **Staff names** on the import's pages (§4.4 S11) | **Exists**: `StaffNames::forReader`, which Access binds — a Super Admin named only to another Super Admin (owner, 2026-10-07, Q5) |
| Platform | **On stores only**: the store switch (platform.md §1.6) | **Exists** since the overnight stack reached `main` (#76, 2026-10-03): `StoreDto::$isActive` and `$isBase`, and the `StoreActivated` / `StoreDeactivated` events. Shoppers see only stores that are on; **staff prepare a store that is off** — its products, selling terms, labels and menu order (owner, 2026-10-04, amendment 4(e), (f)); an off store's rows stay, as its history does |

### 2.5 DTOs and enums

Plain `final readonly` classes (handoff §4.3). Enums stored as strings: `ProductStage` (`DRAFT`,
`READY`, `ARCHIVED`), `AttributeKind` (`INFORMATIONAL`, `FILTERABLE`, `VARIANT`), `AgencyType`
(`HOUSE`, `EXCLUSIVE_AGENT`, `DISTRIBUTOR`), `SaleMode` (`RETAIL`, `WHOLESALE`).

---

## 3 · Use cases

**[DECIDED 2026-10-02] One permission per job, any role** — staff or admin — may be given any of them;
none is admin-only — **but two since**: the store file's `catalog.listing.fill` (amendment 6(h)) and
who sees a category, `catalog.category.audience` (amendment 14(c)). **The shared lists use one permission each.** Names below are my proposal
**[ACCEPTED 2026-10-02, §9.3 #18]**; all in the `Catalog` group.

| Use case | Permission | Scope |
|---|---|---|
| `CreateProduct` — a draft, Active nowhere | `catalog.product.create` | ~~The staff member's working store, which must be on (amendment 3(j))~~ **Some store that is on** — no store is asked: the panel has no store worked in any more (owner, 2026-10-07, Q6, amendment 13(f)) |
| `UpdateProduct` — names, slugs, description, brand, category, warranty, search words, gallery, relations; `AddVariant`, `UpdateVariant`, `ArchiveVariant`, `RestoreVariant`; **the product's variant attributes** — `AddVariantAttribute` (each variant's value given), `RemoveVariantAttribute` — **the attributes' and the variants' order** (`OrderVariantAttributes`, `OrderVariants`), and **a new value made from the Variants tab** (`AddValueFromProduct`) (amendment 16(b)–(d)) | `catalog.product.update` | **Every store where the product is Active**; any store when it is Active nowhere |
| `CorrectVariantCode` — the one variant's code (amendment 16(a)) | `catalog.variant.correct_code` | As `UpdateProduct` |
| `MarkProductReady` | `catalog.product.publish` | Some store: a draft is Active nowhere (amendment 4(l)) |
| `ArchiveProduct` | `catalog.product.archive` | As `UpdateProduct` |
| `RestoreProduct` | `catalog.product.archive` | Some store: an archived product is Active nowhere (amendment 4(l)) |
| `ChooseInStore` — a whole product or single variants, Active or Inactive | `catalog.listing.choose` | That store |
| `SetSellingTerms` — each variant's retail and wholesale switches; the product's minimum and maximum for each mode | `catalog.listing.selling` | That store |
| `MarkNotAvailableNow` / `ClearNotAvailableNow` — product or variant | `catalog.listing.unavailable` (handoff §9.2: "own permission") | That store |
| `AttachLabels` | `catalog.listing.labels` | That store |
| The admins' store file (§1.3, amendment 6(g)) — `UploadStoreFill`, `CorrectStoreFillCode`, `RemoveStoreFillItems`, `SwitchOnStoreFillItems`; reading its page and the store's files (`ViewStoreFill`, `ListStoreFills`) | `catalog.listing.fill`, **admin roles only, enforced** (amendment 6(h); access.md amendment 62) | That store |
| `RankCategories` | `catalog.category.rank` | That store |
| `SetCategoryAudiences` — which categories individuals see and which companies see in the store, a parent's switch carried to everything under it (§1.13, amendment 14) | `catalog.category.audience`, **admin roles only** (declared `adminOnly`, as `catalog.listing.fill`) | That store |
| `UploadCatalogImage` — a photo chosen on a screen, uploaded to Platform's library before the form that uses it is saved (§4.4) | The job of what it is for: a list's job with All stores (a logo, a category's photo); `catalog.product.update` (a product's or a variant's) | A list's: All stores. A product's: Catalog checks the job in every store where the product is Active itself, as `UpdateProduct` does, then gives Platform's `uploadMediaFor` — which takes one scope — one of those stores, or a store where the reader holds the job when it is Active nowhere |
| The screens' reads (§4.4): `ListBrands`, `ListCategories`, `ListAttributes`, `ViewAttribute`, `ListLabels`, `ListWarranties`, `ListWordPairs`, `ProductsReached` (`ListVariations` gone with amendment 16(b)) | The list's job | Any store; `ProductsReached` All stores |
| `ListSearchesWithNoResults` | `catalog.search_word.manage` | All stores |
| Category tree: add, rename, move, deactivate (with each product's choice), activate, delete | `catalog.category.manage` | All stores |
| Brands: add, edit, make default, deactivate (with each product's choice), activate, delete, **their order** (`OrderBrands`, amendment 16(d)) | `catalog.brand.manage` | All stores |
| Attributes, values, colours, **their orders** (`OrderAttributes`, `OrderAttributeValues`, amendment 16(d)) — but for a value made from a product's Variants tab, `catalog.product.update` (above) | `catalog.attribute.manage` | All stores |
| Labels list, **its order** (`OrderLabels`) | `catalog.label.manage` | All stores |
| Warranties list, **its order** (`OrderWarranties`) | `catalog.warranty.manage` | All stores |
| Shared word pairs; reading the zero-result list | `catalog.search_word.manage` | All stores |
| `ListProducts` / `ViewProduct` (admin) — every store's row shown only for the stores the reader covers | `catalog.product.view` | The reader's stores |
| The import (§1.12, amendments 6, 7, 10) — `UploadImport`, `DecideImportNames`, `DecideImportCodes`, the changes before bringing in (`SetImportedBrand`, `SetImportedWarranty`, `SetImportedCategory`, `SetImportedSearchWords`, `SetImportedFilters`, `SetImportedSlugs`), `BringInImport` (its queued work `BringInImportProducts`, the system's on the Super Admin's behalf), `AcceptImportedProducts`, `ArchiveImportedProducts`, `DeleteImportedProducts`, `DiscardImport` (amendment 10(b)); reading its page and the list of files (`ViewImport`, `ListImports`) | `catalog.import.run` (reserved: Super Admin only, handoff §9.1) | Global |
| `RebuildListing` — a repair job; `PruneSearchLog` — nightly | System (reserved): `catalog.listing.rebuild`, `catalog.search_log.prune` — named in step 1, kept by the owner (2026-10-03, amendment 1(a)) | — |

Every change is audited (Platform), **by value**: product data names no person.

---

## 4 · State machines

### 4.1 Product stage

| From | Event | To |
|---|---|---|
| (none) | `CreateProduct` | `DRAFT` |
| `DRAFT` | `MarkProductReady` — every §1.1 requirement met | `READY` |
| `DRAFT` | `ArchiveProduct` — abandoned | `ARCHIVED` |
| `READY` | `ArchiveProduct` — Inactive in every store | `ARCHIVED` |
| `ARCHIVED` | `RestoreProduct` — back to the stage it was archived from; to `READY` only with every §1.1 requirement met, Inactive everywhere | `DRAFT` or `READY` |

**[ACCEPTED 2026-10-02, §9.3 #19]** no way from `READY` back to `DRAFT` (a store hides a product by making it
Inactive), and a `DRAFT` is archived or deleted when abandoned. **[ACCEPTED 2026-10-02, §9.5 #2]** Deleting a draft
(`DeleteDraftProduct`, under `catalog.product.archive`) removes it whole, with its variants, photos'
links and slugs: it was never shown or sold, so its slugs and codes become free again. A draft also
lets go of a code none of its variants carries any more (amendment 3(c)); otherwise a code stays with
the product that held it (amendment 3(e)). **One exception** (owner, 2026-10-06, amendment 11(c)):
the import's page deletes a product the import created and nobody accepted whatever was done to it
since — made ready, or put on sale by a store (switched off there first, each store's change audited)
— gone whole, its codes and addresses free again.

**An archived product is shown nowhere but may still be edited**, so it can be made whole before it
is restored; while archived it is not made ready, not deleted, and its variants are not deleted —
it is restored first. Restoring brings it back to the stage it left, so making an abandoned draft
ready stays `catalog.product.publish`'s (owner, 2026-10-04, amendment 3(m)).

### 4.2 A variant in a store

`Inactive ⇄ Active` (the store's choice), independently `"Not available now"` on or off. Archiving
the variant or its product makes it Inactive.

### 4.3 Category and brand

`active ⇄ inactive`, each deactivation carrying each product's choice (§1.5, §1.6); deleted only when
unused.

### 4.4 The admin screens (amendment 13)

**Written 2026-10-07** (amendment 13). What the owner answered is cited as such; everything marked
**[PROPOSED Pn]** is mine, listed in §9.7 — **built as recommended on the owner's word** ("B",
2026-10-07: build straight away, the owner reviewing the specs with the built screens, any change
coming as a fix). The use cases
behind every button are §3's, unchanged unless a line here says so. A screen offers only what the
reader may do next — **what a reader may do is answered by an Application query**, never by the
screen asking the authorizer (`tests/Architecture/AccessDecisionsTest`) — and every handler asks
again (handoff §19). A page someone may not open is the error page; a button someone may not press
is refused where they pressed it.

**What every screen keeps** (frontend.md §1.8–§1.11, §2.2):

- **Geist's rules on shadcn's code**, never rebuilt: the page frame (a title, a one-line subtitle,
  one main action), tables in `material-base`, a row's ⋯ menu with the destructive item last, a
  dialog's main button repeating its title's verb, a toast answering a destructive button with the
  same verb ("Delete Brand" → "Brand deleted"), a button that cannot be pressed shown disabled with
  its reason. Status badges in words as well as colour: **Active** green-subtle, **Inactive**
  gray-subtle; a product's stage **Draft** gray-subtle, **Ready** green-subtle, **Archived**
  amber-subtle **[PROPOSED P3]**.
- **Every box checks itself as it is typed** (owner, 2026-10-09, amendment 16(f); frontend.md
  §1.7): a number box refuses letters at once, a range, a length, a code's digits said under the box
  while typing, Save out of reach while a box is wrong — the server still checks everything.
- **Every number in 0-9**, typed Arabic digits turned into 0-9 as they are typed (frontend.md §1.8,
  `toLatinDigits`); **every moment through `Time`**, in the zone of the store the screen shows, else
  the base store's (frontend.md §1.10).
- **Every store screen has its own store filter** (`?store=<code>`, frontend.md §2.2; `StoreChoices`):
  the stores where the reader holds that screen's job, a Super Admin's off stores marked Off; no
  filter at all for one store.
- **Names in the page's language first**, the other language beside it.
- **Every ordered list is ordered by dragging** — a handle on each row, by mouse, touch or keyboard,
  saved when dropped — with **Move to Top** and **Move to Bottom** in each row's ⋯ menu; **no typed
  place and no "#" column** (owner, 2026-10-09, amendment 16(d), replacing b2b.md amendment 25's "#"
  for Catalog's lists) **[PROPOSED P8]**.
- **The words** in `src/Modules/Catalog/Presentation/lang/{ar,en}/admin_*.php` and `menu.php`,
  formal Arabic (frontend.md §1.10); **refusals are §7's**, shown as the built screens show them
  (frontend.md §2.1: under the field they name, else at the top of the form, and as a toast).
- **A product's code is staff's**: shown in the panel in Geist's mono figures, never on a shop page
  (amendment 5(d)).

**Where they are.** Admin routes in `Presentation/admin-routes.php`, named `catalog.admin.*` — the
panel's route list carries them (`config/ziggy.php`'s admin group gains `catalog.admin.*`), loaded by
the module's provider — through `App\Http\AdminArea`, `Page` and `FormErrors`, which Catalog may now
use from its Presentation layer (owner, 2026-10-07, amendment 13(d); `deptrac.yaml`
`Catalog: [+CatalogPublic, AccessPublic, AppHttp]`, `tests/Architecture/AppHttpTest` keeping it to
Presentation). **The menu** — Platform's `AdminMenu`, in the **Catalog** group, its icon `catalog`;
each entry offered to anyone holding any of its jobs in any store, the screen deciding the rest (as
B2B's type lists, b2b.md amendment 23(a)):

| # | Entry | Address | Offered for |
|---|---|---|---|
| 10 | Products · المنتجات | `/admin/products` | `catalog.product.view` — the products' read (§3): every other product and listing job is used from these screens, so a role giving one gives this too |
| 20 | Categories · الأقسام | `/admin/categories` | `catalog.category.manage`, `catalog.category.rank`, `catalog.category.audience` (amendment 14) |
| 30 | Brands · الماركات | `/admin/brands` | `catalog.brand.manage` |
| 40 | Attributes · الخصائص | `/admin/attributes` | `catalog.attribute.manage` |
| 60 | Labels · الشارات | `/admin/labels` | `catalog.label.manage` |
| 70 | Warranties · الضمانات | `/admin/warranties` | `catalog.warranty.manage` |
| 80 | Search Words · كلمات البحث | `/admin/search-words` | `catalog.search_word.manage` |
| 90 | Store Files · ملفات المتاجر | `/admin/store-files` | `catalog.listing.fill` (admin roles only) |
| 100 | Products Import · استيراد المنتجات | `/admin/imports` | `catalog.import.run` (Super Admin) — the first entry under a reserved permission. The menu offers it, as it asks `storesWith()`, which answers every store for a Super Admin; the entry names its own group, so the permission still needs none. `PermissionGroup`'s note that reserved actions "never appear in the menu" stops being true and is corrected — a comment in Access — with step 4 |

**Attributes are one screen**, with their values and colour swatches edited inside each; ~~and the
attribute sets under the design's own name, "Variations" (owner, 2026-10-07, amendment 13, #12)~~ —
**no Variations screen**: a product's variants pick their attributes in its Variants tab (owner,
2026-10-09, amendment 16(b)); there is no separate Colours screen: a colour attribute's values carry
their swatch. **No search in the panel's header yet** — it comes with Sales, one search for products
and orders; the products list has its own (owner, 2026-10-07, Q8). **No Catalog card on Home** until
the owner's Home ideas arrive (Q8).

**Reading a shared list** **[PROPOSED P2]**: anyone holding the list's job in any store reads it;
every change on it still needs the job with All stores (§1.5–§1.11), so for a reader without All
stores each button is disabled with that reason. Reading needs no new permission: it is part of every
job on the list, as B2B's type lists read (b2b.md §4.6).

**The admin reads the screens add** (amendment 11(e): "read with their screens") — Application
queries, each asking its own job, each **one query or a fixed few, never one per row** (frontend.md
§5, 15 queries an admin page):

| Query | Job | What it answers |
|---|---|---|
| `ListBrands` | `catalog.brand.manage`, any store | every brand, in the brands' order (dragged, amendment 16(d)), with its fixed number, logo's address, names, agency, default and default-listings marks, state, and how many products carry it |
| `ListCategories` | `.category.manage`, `.rank` or `.audience`, any store | the whole tree in one read, each with its state, photo, how many products sit in it and below it — and, for the store chosen, its place in that store's menu and who sees it there (amendment 14) |
| `ListAttributes`, `ViewAttribute` | `catalog.attribute.manage`, any store | the attributes with their value counts; one attribute with its values |
| ~~`ListVariations`~~ | — | **Gone with the attribute sets** (amendment 16(b)) |
| `ListLabels`, `ListWarranties` | their jobs, any store | the lists, with how many products use each |
| `ListWordPairs`, `ListSearchesWithNoResults` | `catalog.search_word.manage`, any store to read the pairs; **All stores** for the searches (§3) | the pairs; the submitted searches that found nothing, grouped by words, store and language, with how many times and when last (P11) |
| `ProductsReached` | the deactivating list's job, All stores | the products a brand's or a category's deactivation reaches, with each one's stage and where it sits — for the fates dialog |
| `ListProducts` | `catalog.product.view`, any store | the products list (S8) |
| `ViewProduct` | `catalog.product.view`, any store | one product, whole (S9), with each covered store's row and what the reader may do there |
| `CatalogActionsForReader` | — (asks the authorizer for the reader) | for each screen, which buttons the reader may press, and why not (as Access's `StaffActionsForReader`) |

**Photos chosen on a screen** — a brand's logo, a category's photo, a product's and a variant's — are
**uploaded on that screen** and go to Platform's media library through `uploadMediaFor`, under the
screen's own job in its scope: there is **no picker of files already in the library** (none exists;
Platform keeps one copy of an identical public image, so uploading the same file again reuses it)
**[PROPOSED P5]**. A new Catalog command, `UploadCatalogImage` (the job and scope of what the photo is
for), uploads and answers the photo's id; the form then saves with it, as every handler already takes
media ids.

#### S1 · Brands — `/admin/brands`

A table, in the brands' own order — **dragged into it**, with Move to Top and Move to Bottom on the
row's ⋯ menu (amendment 16(d)): **Brand No.** (the brand's fixed number, amendment 7(b) — not its place) · logo ·
name · **Agency** (House Brand · Exclusive Agent · Distributor) · **Listings** (Shown everywhere, or
**Secondary**: reached only through its own category, amendment 5(k)) · **Products** · state, with a
**Default** badge on the default brand. **Add Brand** is the page's main action.

- **Add Brand / Edit Brand** (a dialog): both names (100 characters each); the web addresses, folded
  under "Web Addresses" and made from the names when left empty; the agency; "Show this brand's
  products in search, on the home page and in shop-wide lists" (on by default — off makes it
  secondary, and the line says what that means); the origin country (the shared country picker,
  optional); the description in both languages or neither (5,000 characters, P1); the
  logo (upload, or remove). A new brand goes last (no place typed, amendment 16(d)).
- **⋯ menu**: Edit…, Make Default (an active brand, not the default), Move to Top, Move to Bottom,
  Activate or Deactivate…, Delete… (only a brand no product carries, archived ones included; the default brand neither deactivated nor
  deleted — its items disabled, with the reason).
- **Deactivate Brand…** (the fates dialog, shared with categories): every product of the brand, in
  any stage (`ProductsReached`), with one choice for all — **Hide** or **Move to** another active
  brand — and a choice per product to override it, in a table that opens from "Choose Product by
  Product". The dialog says, before the button, that **a hidden product cannot be ordered** (amendment
  5(j)) and comes back when the brand is switched on again (amendment 5(m)). All or nothing, as the
  handler is.

#### S2 · Categories — `/admin/categories?store=`

**The tree**, every category in one table, a parent's rows folded under it **[PROPOSED P7]**: name ·
photo · **Products** (in it and below it) · state. The **store filter** chooses whose columns the rest
of the table shows (its menu order, who sees it — amendment 14): the stores where the reader holds
`catalog.category.rank` or `catalog.category.audience`, a Super Admin's off ones marked Off.

- **Add Category** (main action) and, on a row's ⋯ menu, **Add Sub-Category…**: both names (100); the
  parent (none, or an active category holding no products — a category holding products takes no
  sub-category, `CategoryHoldsProducts`); the web addresses (folded, made from the names); the photo —
  **it goes last among its siblings, in every store** (amendment 16(d), replacing 1(d)'s place typed
  when adding).
- **⋯ menu**: Edit…, Move… (another parent; last among its new siblings, amendment 16(d)), Move to
  Top, Move to Bottom (among its siblings, in the store shown — `catalog.category.rank` in that store,
  as dragging there), Activate or Deactivate…, Delete… (only
  one with no product and no sub-category).
- **Deactivate Category…** — the fates dialog of S1, with three choices: **Hide** (cannot be ordered,
  comes back with the category), **Leave** (stays in the closed category: found by search, its brand
  page and a link, listed in no category page) or **Move to** another active lowest category outside
  what goes; every product in it and under it, in any stage, those under a sub-category switched off
  before asked again (amendment 4(d), (g)); the sub-categories going with it are named.
- **The store's menu order** (`catalog.category.rank`, that store): the rows of one parent dragged
  into their order — shadcn's `dashboard-01` drag handles on `@dnd-kit`, as the address formats'
  fields (by mouse, touch or keyboard) — **saved when dropped**, as every ordered list (amendment
  16(d), replacing **Save Order**) **[PROPOSED P8]**. A
  category the store has not placed shows the base store's place, marked as such (amendment 5(a)).
- **Who sees it in this store** — two switches a row, **Individuals** and **Companies**
  (`catalog.category.audience`, admin roles only, amendment 14), each saving the moment it flips
  **[PROPOSED P9]**: switching a parent switches everything under it the same way; a sub-category may
  then be switched on its own (owner, 2026-10-07, #5).

#### S3 · Attributes — `/admin/attributes`, `/admin/attributes/{id}`

**The list**, dragged into its order (the order of the shop's filters, amendment 16(d)): name ·
**Kind** (Details Only · Filter · Makes Variants) · unit · **Colour** · values · state; **Add
Attribute** (a dialog: both names, the kind, the unit in both languages or neither (20), "Colour" for a
filter or variant-making attribute; it goes last); Move to Top and Move to Bottom on the row's ⋯
menu. **The whole row opens the attribute.**

**One attribute**: its details as a form — **the kind and Colour locked**, with the reason, once it
has values or variants carry details of it (amendment 1(i), 3(k)) — then **its values**, dragged into
their order (the order of the shop's pickers, amendment 16(d)): name · swatch (a colour attribute's: `#rrggbb`, chosen
with the browser's colour input beside the hex field) · state · ⋯ (Edit…, Activate or Deactivate,
Move to Top, Move to Bottom, Delete… — only one no variant and no product's filters carry). **Add
Value** (both names, 100, never the same as another value of this attribute in either language ignoring
case — `NameTaken`; it goes last) — the same rules as a value made from a product's Variants tab (S9,
amendment 16(c)). A "Details
Only" attribute has no values: its row says so. ⋯ on the attribute: Activate or Deactivate, Delete…
(only one nothing carries; its values go with it).

#### S4 · Variations — removed

**No longer a screen** (owner, 2026-10-09, amendment 16(b)): attribute sets are gone; a product's
variants pick their attributes in its Variants tab (S9). The number S4 is kept so the others keep
theirs.

#### S5 · Labels — `/admin/labels`

«الشارات» (amendment 1(b)), dragged into their order — the order on a card (amendment 16(d)):
**the label as a shopper sees it** (Geist's Badge, in its look) ·
name in the other language · **Meaning** · products · state. **Add Label**: both names — one or two
words, 30 characters (amendment 1(f)) —, **the meaning** (Neutral · Information · Healthy · Warning ·
Error) and **Strong or Subtle** — the Badge's ten looks, named by meaning, never by a bare colour
(amendment 1(e)); a live preview of the badge; it goes last. Move to Top, Move to Bottom on the ⋯
menu. Delete only a label no store shows.

#### S6 · Warranties — `/admin/warranties`

Dragged into their order — the order of the product form's choice (amendment 16(d)): name ·
**Period** ("24 months", or "Lifetime") · products · state. **Add Warranty**: both names (100), the
period — months from 1 to 600, or "Lifetime" — and the terms in both languages (5,000 each, P1); it
goes last. Move to Top, Move to Bottom on the ⋯ menu. Delete only one no product carries.

#### S7 · Search Words — `/admin/search-words?store=`

**Word Pairs**: each pair as "word ↔ word", **Delete** on its row (pairs are added and deleted, never
edited, amendment 2(d)); **Add Word Pair** (two words, 50 characters each, not the same once search
reads them). **Searches That Found Nothing** (`catalog.search_word.manage` with All stores, §3): the
submitted searches that found nothing in the last 12 months, grouped by the words, the store and the
language — **times searched** and **last searched** — the most searched first, 50 at a time with
**Show More**; a store filter (All Stores, or one); **Add Word Pair…** on a row opens the form with
those words in it **[PROPOSED P11]**. The log keeps no person (§1.11).

#### S8 · Products — `/admin/products?store=`

The design's "All products", without what is not Catalog's: no price, stock or tax column (Pricing and
Inventory, stage 5), no "Import CSV" (the import is its own screen, S11). **[PROPOSED P4]**

- **Columns**: photo · name (the other language under it) · **codes** (mono) · brand · category ·
  stage · **In Stores** — the stores the reader covers where the product is on, as their codes · the
  number of variants. **The whole row opens the product.**
- **The store filter** offers **All Stores** first (the stores the reader covers for
  `catalog.product.view`); one store chosen, the **In Stores** column becomes that store's state —
  **On** (n of m variants), **Off**, **Not Chosen**, **Not Available Now** — and a filter by it.
- **Filters**: a search by name, in either language, or by code (a code typed finds the product
  holding it); the stage; the category (the shared combobox); the brand. 50 at a time, newest first,
  with **Show More** (keyset, handoff §5.4).
- **Every product is listed** to whoever holds `catalog.product.view` in some store — a product
  belongs to no store; what each store does with it is shown only for the stores the reader covers
  **[PROPOSED P4]**.
- **Add Product** (main action, `catalog.product.create`): the Arabic name, the English name
  (optional while a draft), the brand (the default brand chosen) → a draft, and its page opens.
  **No store is asked** (owner, 2026-10-07, Q6, amendment 13(f)).

#### S9 · A product — `/admin/products/{id}?tab=`

**Tabs, the tab in the address** (owner, 2026-10-07, #11): **Details · Variants · Photos · Search and
Filters · Related · Stores**. Above them: the name, the stage badge, the codes; **a draft's Note**
naming what it still lacks to be made ready, in words (`Readiness`: the English name, the description
in each language, a lowest active category, a variant, a ready photo).

- **The main action** follows the stage: **Make Ready** (a draft; disabled, with the missing items,
  until it is complete — `catalog.product.publish`), **Restore** (an archived product,
  `catalog.product.archive`). **⋯**: Archive Product… (a ready or draft product, switched off in every
  store — the dialog says so), and last, **Delete Draft…** (a draft only, gone whole with its codes and
  addresses free again).
- **Details** (`catalog.product.update` in every store where it is on, §1.1 — else read only, the
  reason given once at the top): both names (200), the web addresses (folded), the brand, the category
  (the lowest active ones, each shown with its path), the warranty (or none), the description in
  both languages (20,000, P1) — no variation any more: the variants' attributes are the Variants tab's
  (amendment 16(b)). **Save Details**, out of reach until something changes (Geist's Fieldset).
- **Variants** (owner, 2026-10-09, amendment 16(b)–(d)): **the product's variant attributes** above
  the table, in their order — **Add Attribute…** (an active "Makes Variants" attribute, and **each
  variant's value of it**, archived variants' too, all saved together), **Remove** on one (only while
  the variants stay different without it), their order by drag, with Move to Top / Move to Bottom. A product with none has one variant; the tab says so
  and offers Add Attribute… — a ready product's too, so nothing has to be deleted to add a size or a
  colour. The table, **dragged into the variants' order**: **code** · one column per attribute (a
  colour's swatch beside its name) · details · weight and size · photos · Archived. **Add Variant…** (a
  dialog): **its own code** (1–10 digits, never another variant's, amendment 16(a)); **a value for each
  of the product's attributes** — a combobox of the attribute's active values, with **New value…**
  when it lacks one: both names (and a colour's swatch), made there under `catalog.product.update`
  (amendment 16(c)); the details of each "Details Only" attribute — text in both languages, or a number
  with its unit —; weight in grams and length, width and height in millimetres (each optional,
  1–1,000,000). It goes last. ⋯ on a row: Edit… (its code only while the product is a draft — once
  ready a code is **corrected**, amendment 3(c)), **Correct Code…** (`catalog.variant.correct_code`, a
  ready product; the one variant takes the new code), **Photos…** (its own, up to 10), Move to Top,
  Move to Bottom, Archive or Restore, and **Delete…** (a draft's variant only).
- **Photos**: the gallery as tiles (shadcn's `Attachment`, as the media library's grid), **the first
  being the card's photo**, ordered by drag; each tile's size state (Waiting · Ready · Failed — a
  product is shown only with a ready one); **Add Photos** (upload: JPEG, PNG or WebP, the media
  library's limit), Remove on a tile. At most 20.
- **Search and Filters**: **search words** as removable badges with an input and **Add Word** (30
  kept, 50 characters each; a word typed twice is kept once, quietly, amendment 3(f)); **filters** —
  for each filter attribute, its values as checkboxes, several allowed (amendment 3(a)) — **Save
  Filters**.
- **Related**: two ordered lists, **You May Also Like** (picked; left empty, the shop fills it from
  the same category, then brand — §1.10) and **Goes With** (picked only) — each a list of
  products (name and code) with Remove and drag to order, **Add Product…** searching the ready
  products by name or code (the products list's search). At most 20 each.
- **Stores** — **one row per store**, as the owner described the product page ("one single global
  panel and in it will show each store's status", §1.3), **not a store filter**: every store the
  reader covers — where they hold `catalog.product.view` (§3) —, a Super Admin's off stores too,
  marked Off **[PROPOSED P6]**.
  Each store's card holds:
  - **On in This Store** — the whole product, and a table of its variants, each with an **On**
    switch (`catalog.listing.choose`: a whole product or single variants, a ready product only) and
    **Retail** and **Wholesale** switches (`catalog.listing.selling`), a variant first switched on
    selling retail only (amendment 4);
  - **Quantities** — retail minimum and maximum, wholesale minimum and maximum (1–100,000; a
    maximum never below its minimum; a wholesale minimum while any variant sells wholesale) with
    **Save Quantities**;
  - **Not Available Now** — on the whole product, and on each variant (`catalog.listing.unavailable`),
    each saving as it flips;
  - **Labels** — up to 10 of the active labels, shown as their badges (`catalog.listing.labels`);
  - **Prices and stock** — not in this stage: the card says once that they come with Pricing and
    Inventory (§1.3).

  Each control is drawn for someone holding its job in that store; anyone else sees the state, read
  only. A product the store has never chosen shows only **Switch On** (`NotChosenInStore` otherwise).

#### S10 · Store Files — `/admin/store-files?store=`

**Admin roles only** (`catalog.listing.fill`, amendment 6(h)); the store filter offers the stores
where the reader holds it. **The store's files**, newest first: the file's name · when · items · items
still open; **Upload Store File** (a `.json` of `touchwood-store-fill/1`, 2 MB, 1,000 items; the
format's guide linked). A refused file lists every problem — where, and what — under the upload
(`ImportRefused`, at most 500).

**One file** — `/admin/store-files/{id}` — **with no uploader shown** (Catalog README; choice #19 of
step 6, accepted by the owner in #88): a Note, once — **the prices and stock are shown and not kept
until Pricing and Inventory exist** (§1.3, §9.3 #15) —, then the items: # · **code** · the product
(its name, a link to its page) · price · stock · **standing**: **Ready** · **Not Ready** (what it lacks)
· **Archived** · **Already On** · **Unknown Code** · then what the admin did, **Switched On** or
**Removed**. Ticked rows: **Switch On Selected**, **Remove Selected…**; and **Switch On Every Ready
One**. An unknown code's row: **Correct Code…** (checked again). A product not ready opens in its page
to be completed, then is switched on here.

#### S11 · Products Import — `/admin/imports`

**The Super Admin's only** (`catalog.import.run`). **The files**, newest first: name · **uploaded by**
(Platform's `StaffNames`: a Super Admin reads as named to another Super Admin — owner, 2026-10-07,
Q5) · when · products · state (**Deciding** · **Bringing In** · **In** · **Failed**). **Upload Products
File** — a `.json` (20 MB), or a `.zip` with `products.json` at its top and its photos (500 MB,
100,000 entries); 2,000 products a file; the guide (`docs/modules/catalog-import/`) linked. A refused
file lists every problem, as S10.

**One import** — `/admin/imports/{id}?part=` — the import's page of §1.12 in four **tabs in the
address** **[PROPOSED P12]** — its parts 1 and 2, the changes and addresses part 2 ends with, and its
part 4 — each tab with how many still wait:

1. **Names** — each name the catalog lacks, once, grouped by kind (category path, brand, attribute,
   value, warranty — sets gone with amendment 16(b)), with how many products use it and, when several catalog items answer to it,
   how many (amendment 8(d)). Per row, or for the ticked rows: **It Means…** (one of that list, of the
   right job — a combobox), **Create It** (a value or a category only: its names corrected and
   completed, a category's own web address when its own is taken, amendment 8(c)) or **Refuse It**.
2. **Codes** — each product of the file whose codes the catalog holds: the catalog's product (a
   link) and whether it is on sale; **Update** · **Replace Whole** · **Skip** · **New Codes…**, and for
   one on sale, **Keep on Sale** or **Take Off Sale**, every time, no default (amendment 9(c)); a
   product that would change a code its ready product keeps is marked, to skip or to correct in the
   file (amendment 11(b)).
3. **Before Bringing In** — the changes to all the products, or the ticked ones: brand, warranty,
   category, search words, filter values, each with **Replace** · **Only Fill Empty** (· **Add**, for
   words and values) (amendments 7(c), (d), 8(a)); and **the web addresses that would collide**, each
   given its own (amendment 8(c)).
4. **Products** — every product of the file with its state: Waiting · **Left Out**, with why (amendment
   11(a)) · In · Updated · Replaced · Skipped · Held Back · Accepted · Archived · Deleted — and, brought
   in, what each still lacks, its name a link to its page. Ticked rows, once brought in: **Accept**
   (the ready ones: made ready, on sale nowhere, amendment 9(b)), **Archive…**, **Delete…** — the
   dialogs saying that the Super Admin's word is carried out over anything done since, a product on
   sale switched off first (amendment 11(c)) —; and **Accept Every Ready One**.

**The main action follows the state**: **Bring Products In** while deciding — disabled, with how many
names, codes, addresses, sales and code changes still wait (`ImportUndecided`), until none does; while
bringing in, a line saying so, the page asking again every few seconds until it is done
**[PROPOSED P12]**; failed, the reason and that nothing was kept. **Discard Import…** (⋯, deciding or
failed: the page, its decisions and its zip gone, amendment 10(b)) — a typed confirmation
**[PROPOSED P10]**.

**The import's reads are made in batches** — the products of a file read together, not one at a time,
and the products part shown 100 at a time — so the page keeps the admin budget **[PROPOSED P13]**:
`ViewImport` and `ViewStoreFill` today read the catalog once or more per row, which for a file of
2,000 products is thousands of queries.

### 4.5 The shop's pages (amendment 13)

Built **last**, now, **with no price, no stock and no Add to Cart** until Pricing, Inventory and Sales
bring them (owner, 2026-10-07, Q7; amendment 5(b): a product a store chose counts as on sale without a
price until then). The shop's design (`docs/design/v2/TouchWood Home.dc.html`, `TouchWood
Screens.dc.html`) is Arabic only and has no search results page and no brand page: those are drawn
from its look (frontend.md §2.1); **where it shows a product code, nothing is shown** (amendment 5(d)).

**Addresses** (owner, 2026-10-07, #8), under the store and the language, named `storefront.catalog.*`:

| Page | Address |
|---|---|
| A category | `/{store}/{locale}/categories/{slug}` |
| A brand | `/{store}/{locale}/brands/{slug}` |
| A product | `/{store}/{locale}/products/{slug}` |
| Search results | `/{store}/{locale}/search?q=` |
| Suggestions while typing | `/{store}/{locale}/search/suggest?q=` — answers JSON, never logged (amendment 5(e)) |

An old slug answers **301** to the current one; a slug that never existed, a draft, a category that
lists nothing here, an inactive brand, **404** in the shop's frame — an active brand always has its
page (amendment 5(m)). **Every page carries its canonical address and
the same page's address in the other language** **[PROPOSED P19]**.

**Who is looking** (amendment 14): the page asks Platform's `ShopperType` — a guest, an individual or a
company — and shows that person's side: **individuals** the categories ticked for individuals and the
retail side only; **companies** — pending, rejected and suspended ones too — the categories ticked for
companies, retail and wholesale; **guests**, and staff viewing the shop (access.md §1.11), **everything**.

**On every shop page** — the header gains **the category menu** and **the search box**, put there by
Catalog through a small Platform list of the shop frame's parts (owner, 2026-10-07, #9), so the
account pages carry them too:

- **The menu**: shadcn's Navigation Menu — "All Categories" opening the top categories with their
  sub-categories (the design's mega menu), and the top categories in a row; on a phone, a Sheet with
  the tree folding **[PROPOSED P20]**. A category is in it by itself once the store lists something in
  it or under it for this viewer, in the store's order (§1.5). Kept per store, language and viewer
  type in the never-stale cache (CONVENTIONS, `VersionedCache`), invalidated inside every change that
  alters it.
- **The search box**: as the shopper types (two letters or more, a moment after they stop), up to
  eight suggestions — photo and name — asked of the server and shown under the box (shadcn's Command
  in a Popover, as its combobox examples are built; the panel's `SearchCombobox` filters a list it is
  given and is not this); **Enter**, or "See All Results", opens the results page, which logs the
  search (amendment 5(e)).

**A category's page**: the path to it (breadcrumbs), its name, its sub-categories as links, then the
products below it — cards of photo, name and labels (every label attached, in the list's order,
amendment 1(g)), and **"Last pieces"** while any of the product's sizes here is ending soon (amendment
16(h)) — 24 at a time with **Show More** (keyset, as built). **Filters** **[PROPOSED P14]**:
the brands, the values of the filter attributes present in what is listed, and, for a company or a
guest, **Retail** or **Wholesale** — each with how many products it would show, all from one read;
chosen filters as chips with **Clear All**; the address keeps them. **Sort** **[PROPOSED P15]**:
**Best-Selling** (the default — newest first until Sales ranks products) and **Newest**; price filters
and sorts come with Pricing. The design's technical list view waits (handoff §15.2).

**A brand's page**: its logo, name and description, then its products as a category page shows them,
with Show More. A secondary brand keeps its page (§1.6).

**A product's page** — available: the path to its category, the brand (a link to its page), the
labels, the name; **the gallery** — a large photo and its thumbnails: a chosen variant's own photos,
falling back to the product's gallery (§1.2); **the choices** — one row per variant attribute of the product, in its order (amendment 16(b)), its values as buttons, in the attribute's order, a
colour's with its swatch; values no variant on sale has disabled; the chosen variant's details and
weight and size in a table; **"Last pieces"** while the chosen size is ending soon here (amendment
16(h)); **the description**; **the warranty** — its name, period and terms;
**how it is sold** here, for this viewer — retail, and wholesale for a company or a guest, with the
minimums and maximums (§1.3) **[PROPOSED P17]**; then **Goes With** and **You May Also Like** as cards
(§1.10). No code, no price, no Add to Cart. The variant shown is matched in the browser from the list
the server sent, for display only; **Sales resolves the variant on the server** when there is a cart
(handoff §9.1).

**Not available now** — anything that exists here but cannot be ordered by this viewer now (§1.4;
amendment 14: also a product this viewer's account type does not see **[PROPOSED P18]**): its name,
photos and description, a Note "Not available now", nothing to choose or order, and `noindex`.

**Search results**: "N results for …", the cards, 48 at a time with **Show More** **[PROPOSED P16]**;
none found: a short line and the way to the categories. Only the brands shown in default listings
(amendment 5(k)), only what this viewer may see.

**The budget** (frontend.md §5: 8 queries a shop page, warm, each page's real count recorded). Counted
from the code on 2026-10-07, not yet measured: the frame already reads 4 (the store, twice, through
the cache); the menu as built 3 more; a category page as built 11–12 in all; a product page — the
frame, the menu, its own reads, its suggestions — about 19–21, and one more per photo. **The pages
are built towards 8** **[PROPOSED P21]**: the menu from the cache (2); a category's or brand's page in
two reads (its owner, name and whether it lists anything, in one; the cards with their labels, in
one) — 8 in all; a product page's photos in **one Platform read for all of them** (owner, 2026-10-07,
#10: a Platform addition, §2.4), and the page itself kept, like the menu, in the never-stale cache per
store, language and viewer — as the handoff keeps the home page ("cached homepage payload with explicit
invalidation", §5.4) — invalidated inside every change that alters it: the frame, the menu and the
page, 8. Suggestions as built (2–3). A page that still cannot keep 8 is brought to the owner with its
measured count, never raised quietly.

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
| `catalog.products` | `id` PK · `name_ar` `varchar(200)` NOT NULL · `name_en` `varchar(200)` NULL — present while `READY`, CHECK `products_english_when_ready` (amendment 3(g), (l)) · `description_ar`, `description_en` `jsonb` NULL — the structured text (§1.1), each at most 20,000 characters of text, CHECK `jsonb_typeof = 'object'` · `brand_id` FK → `brands` RESTRICT NOT NULL · `category_id` FK → `categories` RESTRICT NULL — CHECK `products_category_when_ready` (present while `READY`: a draft abandoned is archived as it is, §9.3 #19; amendment 3(l)) · `warranty_id` FK → `warranties` RESTRICT NULL · ~~`attribute_set_id`~~ (dropped with amendment 16(b): the product's attributes are `product_attributes`) · `stage` `varchar(16)` CHECK (`DRAFT`, `READY`, `ARCHIVED`) · `archived_from` `varchar(16)` NULL — the stage it was archived from, `DRAFT` or `READY`, set exactly while `ARCHIVED` (CHECK `products_archived_from`, amendment 3(m)) · `hidden_by_category`, `hidden_by_brand` `boolean` NOT NULL DEFAULT false — set when a deactivation chose "hide" (§1.5, §1.6), cleared when it is undone or the product moves · timestamps |
| `catalog.product_slugs` | (`locale` `char(2)`, `slug` `varchar(200)`) PK — **every slug ever used**, so none is given to another product · `product_id` FK CASCADE · `is_current` — exactly one current per product and locale (partial unique `product_slugs_one_current`) · CHECK the slug's letters: Arabic letters, digits and `-` for `ar`; `a-z`, digits and `-` for `en` |
| `catalog.product_search_words` | (`product_id` FK CASCADE, `normalized` `varchar(50)`) PK · `word` `varchar(50)` — as typed; at most 30 per product (code rule) |
| `catalog.product_photos` | (`product_id` FK CASCADE, `media_id` FK → `platform.media` RESTRICT) PK · `position` — at most 20 per product (code rule) |
| `catalog.product_relations` | (`product_id` FK CASCADE, `related_id` FK → `products` RESTRICT, `kind`) PK — `kind` CHECK (`RELATED`, `GOES_WITH`) · `position` · CHECK `product_id <> related_id` |
| `catalog.variants` | `id` PK · `product_id` FK CASCADE · `code` `varchar(10)` NOT NULL — digits only, CHECK `variants_code_format`; **unique** (`variants_code_unique`): one variant a code (amendment 16(a)); FK (`product_id`, `code`) → `product_codes` (amendment 3(e)) · `combination` `varchar(600)` — the variant's value ids **in the order of their attributes' ids**, so reordering the product's attributes rewrites nothing (P27); unique (`product_id`, `combination`) `variants_one_per_combination`, archived ones included · `weight_grams`, `length_mm`, `width_mm`, `height_mm` `integer` NULL, each CHECK 1–1,000,000 · `is_archived` · `position` — the variants' order, set by dragging (amendment 16(d)) · timestamps |
| `catalog.product_codes` | `code` `varchar(10)` PK — **every code the product's variants ever held**, so a code is never given to another product while this one exists (§1.2, amendment 3(e)); one variant carries it at a time (amendment 16(a)) · `product_id` FK CASCADE · unique (`product_id`, `code`) for the variants' key |
| `catalog.product_attributes` | (`product_id` FK CASCADE, `attribute_id` FK RESTRICT) PK · `position` — **the product's variant attributes, in their order** (the shop's pickers), variant-making attributes only (code rule) — new with amendment 16(b), replacing a product's attribute set |
| `catalog.product_filter_values` | (`product_id` FK CASCADE, `value_id` FK → `attribute_values` RESTRICT) PK · `attribute_id` FK RESTRICT — a filter attribute's value (code rule), belonging to that attribute: FK (`attribute_id`, `value_id`) → `attribute_values` (`attribute_id`, `id`) `product_filter_values_value` (amendment 3(a)) |
| `catalog.variant_values` | (`variant_id` FK CASCADE, `attribute_id` FK RESTRICT) PK · `value_id` — FK (`attribute_id`, `value_id`) → `attribute_values` (`attribute_id`, `id`) RESTRICT `variant_values_value`: the value belongs to that attribute |
| `catalog.variant_details` | (`variant_id` FK CASCADE, `attribute_id` FK RESTRICT) PK · `text_ar`, `text_en` `varchar(200)` NULL · `number` `numeric(12,3)` NULL — either both texts or the number (CHECK `variant_details_one_kind`), a text present and on one line (CHECK `variant_details_text_present`) |
| `catalog.variant_photos` | (`variant_id` FK CASCADE, `media_id` FK → `platform.media` RESTRICT) PK · `position` — at most 10 per variant (code rule) |

**5.2 Each store's choice** — each store's rows, written by one repository that names the store in every call, as the menu order's (owner, 2026-10-04, amendment 4(h)) — read across stores only where a rule needs every store: where a product is Active, switching it off everywhere, the listing's rows (amendment 11(e)); handoff §4.1's `BelongsToStore` guard is for Eloquent models, which Catalog does not use

| Table | Columns |
|---|---|
| `catalog.store_variants` | (`store_id` FK → `platform.stores` RESTRICT, `variant_id` FK CASCADE) PK · `is_active` · `not_available_now` · `sells_retail`, `sells_wholesale` — CHECK at least one · timestamps |
| `catalog.store_products` | (`store_id`, `product_id` FK CASCADE) PK · `not_available_now` · `retail_minimum` `integer` NOT NULL DEFAULT 1 · `retail_maximum`, `wholesale_minimum`, `wholesale_maximum` `integer` NULL — each 1–100,000, a maximum never below its minimum (CHECKs) · timestamps |
| `catalog.store_product_labels` | (`store_id`, `product_id` FK CASCADE, `label_id` FK RESTRICT) PK |
| `catalog.store_category_ranks` | (`store_id`, `category_id` FK CASCADE) PK · `rank` `integer` |
| `catalog.store_category_audiences` | (`store_id` FK → `platform.stores` RESTRICT, `category_id` FK CASCADE) PK · `for_individuals`, `for_companies` `boolean` NOT NULL — **no row means both** (amendment 14: a category nobody switched is seen by both), so a new category and a store opened later need no rows · timestamps |
| `catalog.store_variant_facts` | (`store_id`, `variant_id` FK CASCADE) PK · `orderable` `boolean` NULL — Inventory's (null: §1.3's rule until it pushes) · `ending_soon` `boolean` NOT NULL DEFAULT false — Inventory's "last pieces" (amendment 16(h)) · `price_minor`, `price_before_minor` `bigint` NULL and `currency` `char(3)` NULL — Pricing's `ListingPrice` (amendment 15), the before only with a now, and a currency with either · timestamps. **[PROPOSED P22]** The pushed facts kept where the listing's writer reads them (§2.2). Sales' ranks (stage 6) get their own table when Sales first pushes them |

**5.3 The lists**

| Table | Columns |
|---|---|
| `catalog.categories` | `id` PK · `parent_id` FK → `categories` RESTRICT NULL · `name_ar`, `name_en` `varchar(100)` · `is_active` · `deactivated_with_parent` — so reactivating a parent brings back only what was active before · `image_media_id` FK → `platform.media` RESTRICT NULL · timestamps. A category is never its own ancestor (code rule: it crosses rows) |
| `catalog.category_slugs` | As `product_slugs`, for categories |
| `catalog.brands` | `id` PK · `number` `integer` unique, CHECK > 0 — the brand's fixed number: a new brand takes the lowest one no brand holds, under the brands' lock, so a deleted brand's is free again and a failed add leaves no gap (amendments 7(b), 10(a)) · `name_ar`, `name_en` `varchar(100)` · `description_ar`, `description_en` `jsonb` NULL · `logo_media_id` FK → `platform.media` RESTRICT NULL · `origin_country` `char(2)` NULL · `agency_type` CHECK (`HOUSE`, `EXCLUSIVE_AGENT`, `DISTRIBUTOR`) · `is_default` — partial unique where true (`brands_one_default`), CHECK a default brand is active · `show_in_default_listings` · `position` — the brands' order, set by dragging (amendment 16(d)) · `is_active` · timestamps |
| `catalog.brand_slugs` | As `product_slugs`, for brands |
| `catalog.attributes` | `id` PK · `name_ar`, `name_en` `varchar(100)` · `kind` CHECK (`INFORMATIONAL`, `FILTERABLE`, `VARIANT`) · `unit_ar`, `unit_en` `varchar(20)` NULL · `is_colour` (only a filterable or variant-making attribute) · `is_active` · `position` — the attributes' order (the shop's filters), set by dragging (amendment 16(d)) |
| `catalog.attribute_values` | `id` PK · `attribute_id` FK RESTRICT · `name_ar`, `name_en` `varchar(100)` — unique per attribute on `lower(name)` in each language, names stored trimmed · `swatch` `char(7)` NULL — `#rrggbb`, only on a colour attribute's values · `is_active` · `position` — the values' order (the shop's pickers), set by dragging (amendment 16(d)) |
| ~~`catalog.attribute_sets`~~, ~~`catalog.attribute_set_members`~~ | **Dropped** with amendment 16(b): each product's set's members, in the set's order, become its `product_attributes` first |
| `catalog.labels` | `id` PK · `name_ar`, `name_en` `varchar(30)` — one or two words each (code rule) · `tone` `varchar(16)` CHECK one of Geist Badge's ten: `gray`, `blue`, `green`, `amber`, `red` and each `-subtle` (amendment 1(e)) · `is_active` · `position` — the order on a card, set by dragging (amendment 16(d)) |
| `catalog.warranties` | `id` PK · `name_ar`, `name_en` `varchar(100)` · `terms_ar`, `terms_en` `jsonb` — structured text, at most 5,000 characters each · `period_months` `smallint` NULL — 1–600, NULL meaning lifetime · `is_active` · `position` — the list's order, set by dragging; new with amendment 16(d), the existing ones numbered in name order |
| `catalog.word_pairs` | `id` PK · `word_a`, `word_b` `varchar(50)` — normalised, stored in order (`word_a < word_b`), unique as a pair |

**5.4 Search and the listing**

| Table | Columns |
|---|---|
| `catalog.search_log` | `id` `bigint` identity PK · `store_id` FK RESTRICT · `locale` `char(2)` · `query` `varchar(200)` — normalised · `results` `integer` · `searched_at` `timestamptz` DEFAULT `now()` — **no person** (§1.11). Indexes `(searched_at)` for the nightly removal, `(store_id, results, searched_at)` for the zero-result list |
| `catalog.listing` | **The listing and search read model** (handoff §5.4): one row per store, language and product that is **listed or reachable by search** there (§1.4). `store_id`, `locale`, `product_id` PK · `name`, `slug` · `brand_id`, `brand_visible_by_default` (copied from the brand, handoff §9.4) · `category_id` and `category_path` (the ids above it, for a parent's page) · `in_category_pages` (false while "left" in an inactive category) · `value_ids`, `label_ids` (for filters and cards) · `card_media_id` FK → `platform.media` RESTRICT and `card_photo` (its addresses, §2.4) · `orderable` · `price_minor` `bigint` NULL and `sales_rank` `integer` NULL (pushed, §2.2) · `search_document` `tsvector`, `search_text` (normalised, for trigrams: the page's name, then the other language's, a line apart — amendment 5(f)). Indexes: `(store_id, locale, brand_visible_by_default, sales_rank)` and `(store_id, locale, brand_id)` (handoff §9.4); GIN on `search_document`, `category_path`, `value_ids`; trigram GIN on `search_text`. **Gains, with the shop's pages** (amendments 14, 15, 16(h)): `ending_soon` `boolean` — **any** of the product's sizes switched on here ending soon (the card's "Last pieces"); `for_individuals`, `for_companies` `boolean` — whether each kind of account sees the product here (its own category's switches, and for individuals a variant sold retail, §1.13); `sells_retail`, `sells_wholesale` `boolean` — any variant here, for the shop's Retail and Wholesale filter; `price_before_minor` beside `price_minor` (companies' and guests' card), and `retail_price_minor`, `retail_price_before_minor` (individuals' card) — each from the card's variant for that side (§2.2) |

**5.5 The import and the store file** **[ACCEPTED 2026-10-05; as built, accepted 2026-10-06]**
(amendments 6, 8(f)) — the owner accepted the tables as built in step 6, listed column by column.

| Table | Columns |
|---|---|
| `catalog.imports` | `id` PK · `kind` CHECK (`PRODUCTS`, `STORE_FILL`) · `store_id` FK → `platform.stores` RESTRICT NULL — the store a store file fills, set exactly for one (CHECK) · `file_name` · `archive` NULL — where a products file's zip waits until its products are brought in, on the disk `config/catalog.php` names (a products file only, CHECK) · `state` CHECK — a products file `DECIDING`, `BRINGING_IN`, `IN`, `FAILED`; a store file `OPEN` · `failure` NULL — set exactly when `FAILED` (CHECK) · `uploaded_by` — the staff member, for the page (an admin record, not product data) · timestamps |
| `catalog.import_names` | `id` PK · `import_id` FK CASCADE · `kind` CHECK (`CATEGORY`, `BRAND`, `ATTRIBUTE`, `VALUE`, `WARRANTY`) — `SET` gone with amendment 16(b) · `written` `text` — as the file first wrote it, a category as its whole path (each level the catalog lacks is its own row) · `key` `char(64)` — a SHA-256 of it as names are compared (a value's with its attribute's): a path has no length limit · `attribute` — a value's attribute as written, set exactly for a value (CHECK) · `attribute_kind` — an attribute's job as the file uses it, set exactly for an attribute (CHECK) · `decision` NULL CHECK (`EXISTING`, `CREATE`, `REFUSE`) · `target_id` NULL — the one it means, or the one created; set for `EXISTING`, never for `REFUSE` (CHECK) · `name_ar`, `name_en` — the wording to create, set exactly for `CREATE` (CHECK) · `products` — how many of the file's products use it · `matches` — how many catalog items answer to the name, when several do (amendment 8(d)) · `slug_ar`, `slug_en` NULL — a new category's own address, when the one made from its names is taken (amendment 8(c)) · unique (`import_id`, `kind`, `key`) |
| `catalog.import_products` | `id` PK · `import_id` FK CASCADE · `number` — its place in the file, from 1 · `data` `jsonb` — the product as the file gave it, checked · `edited` `jsonb` NULL — the product as the page's changes left it, before bringing in (amendment 7(c)); where the file gives none, what was asked to fill the empty or to add is kept apart in it and given when the product is brought in, to what it then has (§1.12) · `codes` `text[]` · `conflict_product_id` FK → `products` SET NULL NULL — the product already holding a code · `decision` NULL CHECK (`UPDATE`, `REPLACE`, `SKIP`, `RECODE`) · `new_codes` `jsonb` — each code it gives up → its new one, set exactly for `RECODE` (CHECK) · `sale` NULL CHECK (`KEEP`, `TAKE_OFF`) — for a product updated or replaced that is on sale, set only with `UPDATE` or `REPLACE` (amendment 9(c)) · `product_id` FK → `products` SET NULL NULL — the product it became · `state` CHECK (`WAITING`, `REFUSED`, `IN`, `UPDATED`, `REPLACED`, `SKIPPED`, `HELD`, `ACCEPTED`, `ARCHIVED`, `DELETED`) · `refusal` `text` NULL — why a product of the file was left out at upload, set exactly for `REFUSED` (CHECK; amendment 11(a)) · unique (`import_id`, `number`) |
| `catalog.store_fill_items` | `id` PK · `import_id` FK CASCADE · `number` · `code` CHECK digits · `price` — as the file wrote it, `stock` NULL — shown, not kept until stage 5 · `state` CHECK (`OPEN`, `ON`, `REMOVED`) — what the admin did with it; whether an open item is ready, not ready, archived, already on or an unknown code is read when the page is, since it changes with the product · unique (`import_id`, `number`) |

**The photos of a zip** are not rows: the zip itself is kept (`imports.archive`), its photos unpacked
into temporary files of the server's own naming when the products are brought in — never by a name
inside the zip — added to the media library, and the zip let go. **No uploaded file is kept beyond
its work** (owner, 2026-10-06, amendment 10(b)): a JSON file and a store file are let go once read,
their data in these tables; a zip only until its products are brought in, or its import discarded.

**Rows of the listing are written inside the transaction of the change that alters them**
**[ACCEPTED 2026-10-02, §9.3 #20]** — never stale, as the owner chose for the pushed facts — and a
**repair job** rebuilds them whole (`RebuildListing`, §3). This departs from handoff §5.4's "rebuilt
by job" for the ordinary case, and keeps the job for repairs.

---

## 6 · Events

### 6.1 Published (ids only, after commit)

**[ACCEPTED 2026-10-02, §9.3 #21]** `ProductMadeReady`, `ProductArchived`, `ProductRestored`, `ProductChanged` (shared
data), `VariantAdded`, `VariantArchived`, `VariantRestored`, `VariantCodeCorrected` (Sync re-matches the one variant, amendment 16(a)),
`StoreListingChanged` (`storeId`, variant ids — Pricing and Inventory learn a store took up a variant
that needs a price and stock). None is among handoff §4.5's critical outbox events. **Only for a
product that has been ready** (owner, 2026-10-04, amendment 3(m)): a draft, and a draft archived when
abandoned, is Catalog's alone and sends nothing.

### 6.2 Consumed

| Event | From | What Catalog does |
|---|---|---|
| `MediaVariantsReady` | Platform | Refreshes the card photo in the listing rows using that photo |
| `MediaDeleted` | Platform | Nothing more: the photo was detached through `MediaUsage` inside the delete's transaction |
| `StoreCreated` | Platform | Nothing: a new store chooses nothing, and its menu has no order until its admins set one (and is created off, platform.md §1.1; owner, 2026-10-03, amendment 2(b)) |

---

## 7 · Errors

`CatalogError extends DomainError`, each with a stable type `catalog.{name}` and translations in both
languages **[ACCEPTED 2026-10-02, §9.3 #22]**. A thing in a store the reader does not cover answers
exactly as one that does not exist, as B2B's and Access's do.

| Error | Category | When |
|---|---|---|
| `ProductNotFound`, `VariantNotFound`, `CategoryNotFound`, `BrandNotFound` | NOT_FOUND | Unknown, or not one the reader may see |
| `ListItemNotFound` | NOT_FOUND | An attribute, value, label, warranty or word pair that does not exist |
| `CodeTaken` | CONFLICT | A code another product holds or once held (§1.2, amendment 3(e)), or another variant of the product carries now (amendment 16(a)) |
| `SlugTaken` | CONFLICT | A slug another product, category or brand holds or once held (§1.1) |
| `DuplicateCombination` | CONFLICT | A variant with the same values as another of the product, archived ones included — also when a variant attribute removed would leave two the same (amendment 16(b)) |
| `ProductNotReady` | INVALID | Marking ready, or editing a ready product, without every §1.1 requirement — it names what is missing |
| `ProductArchived` | CONFLICT | Making an archived product ready, deleting it, or deleting its variant — it is restored first (amendment 3(m)) |
| `InvalidStageChange` | CONFLICT | A move §4.1 does not allow |
| `NotChosenInStore` | CONFLICT | Selling terms, labels or "Not available now" for a product, or a variant, the store has never chosen (amendment 4(e)) |
| `InvalidSellingTerms` | INVALID | No selling mode, a maximum below its minimum, wholesale on with no wholesale minimum |
| `CategoryNotLowest` | INVALID | Putting a product in a category that has sub-categories |
| `CategoryHoldsProducts` | CONFLICT | Adding a sub-category under a category that holds products |
| `CategoryNotEmpty` | CONFLICT | Deleting a category that holds products or sub-categories |
| `CategoryLoop` | INVALID | Moving a category under itself or anything below it |
| `CategoryInactive`, `BrandInactive`, `ListItemInactive` | CONFLICT | Choosing something deactivated |
| `BrandInUse`, `ListItemInUse` | CONFLICT | Deleting what a product still uses |
| `DefaultBrandRequired` | CONFLICT | Deactivating or deleting the default brand (§1.6) |
| `AttributeKindLocked` | CONFLICT | Changing an attribute's job once it has values (amendment 1(i)) |
| `NameTaken` | CONFLICT | A value of one attribute, or a word pair, the list already has — matched trimmed, ignoring letter case (§1.7, §1.11); a new value a file would make twice (§1.12) |
| ~~`AttributeSetLocked`~~, ~~`AttributeSetInUse`~~ | — | **Gone with the attribute sets** (amendment 16(b)): a product's attributes change in its Variants tab, each variant given its value |
| `TooMany` | CONFLICT | Over a limit: photos, search words, filter values, related products |
| `InvalidCatalogAttribute` | INVALID | Any other value the domain refuses — a length, a format, a swatch |
| `ImportRefused` | INVALID | A product or store file not in its format; it lists every error (§1.12, §1.3) |
| `ImportUndecided` | CONFLICT | Bringing an import's products in while a name or a code still waits for a decision, a web address collides, or a product on sale waits for keep on sale or take off sale (§1.12; amendments 8(c), 9(c)) |
| `ImportClosed` | CONFLICT | Deciding or changing an import's products once bringing them in has started, or accepting them before they are in (§1.12; built in step 6) |

---

## 8 · Test scenarios

Every guard below is also mutation-checked (CONVENTIONS, "How a step is done here").

**Products and variants**

1. A product leaves `DRAFT` only with names, slugs and description in both languages, a brand, a
   lowest active category, a variant with a code and a photo whose sizes are ready; each missing
   item is named. A ready product refuses an edit that removes one.
2. A code is digits only and **one variant's: two variants never share one** (amendment 16(a)) — and no code another
   product holds or held is given to this one, until that product (a draft) is deleted; correcting one
   is its own permission and audited; a code stays with its product — archived or corrected — except
   that a product never ready, a draft archived or not, lets go of a code none of its variants carries
   any more (amendment 3(c), (m)).
3. Two variants of one product never share a combination; **every variant of a product uses the
   product's variant attributes, each given its value when one is added; one is removed only while the
   variants stay different** (amendment 16(b)); the server resolves the variant from picked values;
   price is never added up from values.
4. A slug is unique per language among products (and among categories, among brands); an old slug
   answers with a redirect to the current one.
5. Archiving a product makes it Inactive in every store; restoring brings it back to the stage it
   left, a ready one Inactive everywhere; a variant archives and restores on its own.

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
16. "Black" and "black" are one value of an attribute; a product's variant attributes are
    variant-making ones only; **a value made from a product's Variants tab** follows the Attributes
    screen's rules, under `catalog.product.update` in every store where the product is on (amendment
    16(c)); **every ordered list keeps the order dragged, an item not sent keeping its place after those
    sent, a new item last** (amendment 16(d)).

**Search**

17. Exact, prefix, nearest, then a search word or a word pair; Arabic normalised on both sides; ties
    by sales rank.
18. The search log keeps no person and loses entries after 12 months.

**Contract and architecture**

19. `CatalogApi` answers with DTOs only — `variantByCode` one variant or none, and Pricing's three
    reads (amendment 16(a), (e)); the listing facts and the import's sections are accepted
    from the modules above — the listing facts' receiving side tested with the shop's pages (amendment
    15, P22), the facts themselves with stage 5, which pushes them; the import's sections with stage 5
    (declared in step 6); a store's rows are
    written only with that store named (amendment 4(h)), read across stores only as §5.2 says.
20. Catalog imports only Platform's and Access's public surfaces — and, from its Presentation layer
    only, the framework glue (amendment 13(d)); every handler asserts a
    permission; every CHECK, unique index and foreign key has a code rule that refuses first; no
    country, currency or store name in `Domain/` or `Application/`.
21. A storefront listing page stays within its query budget (frontend.md §5), measured warm, once
    its endpoint exists.

**The import and the store file** (amendment 6)

22. A file not in its format is refused whole, every error listed, and nothing is kept; a file that
    passes changes nothing in the catalog until its products are brought in.
23. Every name the catalog lacks waits for a decision — an existing one, created, or refused — and
    every code it already has, for one of update, replace, skip or another code; nothing is brought in
    before all are decided; bringing in is all or nothing, photos included.
24. Brought-in products are drafts until accepted; accepting makes the ready ones ready, switched on
    nowhere; an updated or replaced product on sale is kept on sale or taken off sale, as chosen for
    it; archive and delete reach what the import created and nobody accepted, whatever was done to it
    since (amendment 11(c)); a product left out at upload takes no part (11(a)); a product not a draft
    keeps its codes (11(b)); prices and stock come only with a store's file, not kept until stage 5.
25. The store file never creates or edits a product: an unknown code waits to be corrected or removed;
    switching on chooses the variant carrying each code (amendment 16(a)), only for ready products,
    under the job in that store.
26. Brands, warranties and attributes are never created from a file; a brand is named by its fixed
    number or its name; changes made on the page before bringing in reach the catalog only when the
    products are brought in (amendment 7).

**The screens** (amendment 13)

27. Every page route: who may open it, what its data holds — **never a code in anything a shop page
    receives** —, and the error page where it must not open; a store screen refuses a store outside
    the reader's, opens on the first store that is on, and shows no filter for one store.
28. Every form endpoint: a refusal lands under the field it names or at the top of the form; success
    flashes its toast and redirects; a reader without the job sees the button disabled with its reason,
    and the handler refuses it anyway. **In a browser, every box says what is wrong as it is typed** —
    a letter in a number box, out of range, too long — with Save out of reach meanwhile (amendment 16(f)).
29. Each page's queries, warm, recorded and held: 15 an admin page, 8 a shop page (frontend.md §5); the
    import's and the store file's pages within 15 for a file at its limit.
30. In a browser (Pest's plugin), in both languages and on a phone: a brand added, edited, made the
    default, deactivated with its products' fates and deleted, **dragged into a new order and moved to
    the top** (amendment 16(d)); a category tree with a store's order dragged and saved; a product
    created, **given a second attribute and a new value from its Variants tab** (amendment 16(b), (c)),
    completed through its tabs, made ready, switched on in a
    store with its terms and labels; a store file uploaded (through the use case — the plugin's server
    takes no file) and switched on; an import decided, brought in and accepted.
31. The shop: the menu, a category's page with its filters and Show More, a brand's page, a product's
    page and its "Not available now" twin (`noindex`), a search's suggestions and results, an old
    address answering 301 — and no code, price or Add to Cart anywhere.

**Individuals and companies** (amendment 14)

32. A category switched off for individuals in a store leaves their menu, pages, search and
    suggestions there, and only there; companies and guests still see it; a parent's switch reaches
    everything under it; a store opened later shows everything to both.
33. An individual never sees a product whose variants all sell wholesale only, nor the wholesale
    side of another; a pending company sees the company side; a guest and a staff view see both.
34. The switches are an admin role's job only, in that store; every change writes the listing in the
    same transaction, and the repair job writes the same rows.

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
| 7 | The code | **Unique across every variant of every product; the only identifier, the SKU; staff may correct one** (§1.2) — loosened by amendment 3(e) (sizes sharing a code), **restored by amendment 16(a)** (2026-10-09): one variant a code, a product keeping every code it held |
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
| 1 | **Written 2026-10-05 (amendment 6, §1.12)**: the import's file format, from the finished tables | — |
| 2 | A product video (§1.1) | The owner: "leave it for now" — a link to a hosted video, or a Platform amendment for uploaded video |
| 3 | Product add-ons ("Product apps"), bundles | A later stage (handoff §15.2) |
| 4 | **Decided 2026-10-04 (amendment 4, §1.3): one by one, or the admins' file of codes, built in step 6.** Asked first as **filling a new store in bulk** (owner, 2026-10-03, amendment 2(b)): when a store is created, its admins may bring in the existing products (and their categories) instead of choosing them one by one; a Super Admin may upload a JSON of the product codes to add; admins may pick products by code in bulk. Prices and stock then come from Odoo where the store is wired, or are entered by hand — "just an option" beside choosing each product | Step 4 (each store's choice) and step 6 (the import): its rules asked then |
| 5 | **Decided 2026-10-04 (amendment 5(a), §1.5): the base store's place, until the store's admins place it.** Asked as: how a store's menu orders a category it has no place for yet (a store opened later, amendment 2(b)) | — |
| 6 | ~~**Telling sizes apart in the provider's data**: a code shared by a product's variants cannot say, alone, which size the provider's stock or price is for (amendment 3(e)); the provider's own id for each item is the likely key~~ | **Closed by amendment 16(a)** (owner, 2026-10-09): each variant has its own code, which says which size; Pricing's and Inventory's open points on shared codes close with it |
| 7 | **The owner's product sheet** (2026-10-03, read, not kept: 678 items under 8 groups, Arabic names only, codes of 3–4 digits): rows sharing a code are one product's sizes — **under amendment 16(a) each of those sizes needs a code of its own in the source**; **codes shared by different items are mistakes to fix in the source** (owner) — 1002, 1011, 1076, 1098, 1372 (two rows named «فارغ»), 1496, 1596, 1603, 1815; three group headings count more rows than they hold | **Done in step 6**: in the import's format a code belongs to one product, its variants listed under it; two products sharing a code refuse the file (§1.12) |

### 9.3 My proposals — accepted by the owner, 2026-10-02, except #10

1. No `processed_events` table: each listener is written to do its work once however often it runs.
2. Lengths: product name 200, category name 100, label 30, warranty name 100 and terms 5,000.
3. Slugs made from the name when a product is created, editable; every old slug kept and never given
   to another product. An edit that leaves a slug empty makes a new one from the name, the old one
   kept (owner, 2026-10-03, amendment 2(e)); a deleted brand's or category's slugs are freed (2(a)).
4. Description at most 20,000 characters per language.
5. At most 30 search words per product, each at most 50 characters.
6. At most 20 gallery photos per product, 10 per variant.
7. A `READY` product keeps every ready requirement: an edit that removes one is refused. A category
   deactivated since it was placed there stays (amendment 3(m)).
8. ~~A code: one line, 1–64 characters of letters, digits, spaces and `- . _ /`, compared after
   trimming.~~ **Replaced by amendment 3(e)**: digits only, a code belongs to one product. A corrected typo **keeps the mistyped code taken**, like every code that ever existed —
   one rule, "a code is never given to another variant" (the alternative: free it, since it named
   nothing real). (**Since amendment 3(c)**: a draft lets go of a code none of its variants carries
   any more, §4.1.)
9. Weight in whole grams, sizes in whole millimetres, each 1 to 1,000,000.
10. ~~The wholesale minimum per variant per store: a whole number 1–100,000, required when wholesale
    is on.~~ **Replaced by the owner's answer (§9.1 #40):** each product's own minimum and maximum
    for each mode, per store.
11. A category may have an optional photo, for category cards.
12. The default brand cannot be deactivated or deleted until another is made the default.
13. Informational values per variant (text in both languages, or a number with the unit); ~~a
    product's attribute set cannot change once it has variants~~ — no attribute sets since amendment
    16(b): a product's variant attributes change in its Variants tab.
14. Attributes, values, ~~sets,~~ labels and warranties: deactivated (reversible) and deleted only when
    unused, like brands and categories. **Word pairs are only added and deleted** — no switch; a
    product turned off or on never touches them (owner, 2026-10-03, amendment 2(d)).
15. Until stage 5 a store file's page says, once, that its prices and stock were not kept (amendment 6(a)
    replaced the preview with the page; amendment 9(a): only a store's own file brings them).
16. The public contract (§2.1) and the listing facts interface (§2.2) as listed.
17. **Catalog keeps the card photo's addresses in its own listing rows**, asked of Platform when a row
    is written and refreshed on `MediaVariantsReady`, so a product grid reads no media at all and
    Platform's contract does not change; a change of CDN address is followed by the repair job. (The
    alternative: a Platform addition, one call returning the addresses of a page of photos.)
18. The permission names of §3, and `CreateProduct` checked in the staff member's working store (the
    panel's store picker, frontend.md §2.2), since a new product belongs to no store yet. (**Replaced
    by amendment 13(f)**: the panel has no store worked in since 2026-10-06 — some store that is on.)
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
| `docs/HANDOFF.md` §9.1, §9.5 (the storefront's filters) | **Codes are never shown to customers** (owner, 2026-10-04, amendment 5(d)) — written with step 5 |
| `docs/HANDOFF.md` §4.1 | Slugs move from "store-scoped" to "global" |
| `docs/HANDOFF.md` §9.2 | The editorial status becomes a product-wide stage (`DRAFT`, `READY`, `ARCHIVED`) plus each store's Active row |
| `docs/HANDOFF.md` §9.3, §9.4, §9.5, §15.2 | One category per product at the end of the tree; deactivating and deleting categories and brands; shared word pairs; the 12-month search log; labels and warranty in this stage; the video left open |
| `docs/STRUCTURE.md`, `docs/modules/README.md` | Still say Catalog is blocked on the provider's schema; Sync's line still names a conflict log |

### 9.5 Points that surfaced while completing §5–§8 — accepted by the owner, 2026-10-02 ("accept all four")

1. **The quantity limits' range**: each minimum and maximum a whole number from 1 to 100,000, a
   maximum never below its minimum (§1.3).
2. **Deleting a draft** frees its slugs and its variants' codes — it was never shown or sold — the one
   exception to "a code is never given to another variant" (§4.1). (Amended since: codes belong to a
   product, and a draft also lets go of a code it no longer uses — amendment 3(c), (e).)
3. **A combination is never made twice**: a variant with the same values as an archived one is
   refused; the archived one is restored instead (§5.1, `DuplicateCombination`).
4. ~~**Creating a product needs both names**~~ **Replaced by amendment 3(g)**: a draft may have its Arabic name only. Formerly: **Creating a product needs both names** (its slugs are made from them, §9.3 #3); everything else
   may wait until it is made ready.

### 9.6 Amendments during the build

Changes to the spec approved on 2026-10-02, each with the owner's agreement, applied in place in the
sections named — amendment 15 still waits for it, with Pricing's own spec; amendment 16's decisions
are the owner's, its picks (P26–P34) wait for the owner's review of it.

| # | Where | Change | Source |
|---|---|---|---|
| 1 | §1.5, §1.6, §1.8, §2.4, §3, §5.3 | **Before step 2** (owner, 2026-10-03). (a) The two system permissions keep their step-1 names, `catalog.listing.rebuild` and `catalog.search_log.prune`. (b) Labels are **«الشارات»** in Arabic. (c) **The seed creates TouchWood alone**, «تاتش وود» / "TouchWood", the default brand; every other brand and every category comes from staff or the import. (d) **A new category's place among its siblings is chosen by whoever adds it**, starting the same in every store; each store's admins change it afterwards. (e) **A label's colour follows Geist's meanings** — green always healthy, red error, amber warning, blue information, gray neutral — strong or subtle: Geist Badge's ten variants, named on screen by meaning. (f) **A label name is one or two words** in each language (Geist), at most 30 characters. (g) **Every attached label shows on the card**, for now. (h) **Catalog keeps its own copy of `Ulids`**: Access and B2B are not touched ("the access is well working so we don't have to mess with it"). (i) **An attribute's job** — information only, filter, or making variants — **can change only while it has no values**; after that it stays. (j) **A brand's origin country is optional** ("brand must not require an origin country"); when given, a two-letter country code, checked for its shape only. Also corrected: §2.4's store-switch row, now built (#76). | Owner, 2026-10-03 |
| 2 | §1.5, §1.6, §6.2, §9.2, §9.3 | **The review of step 2** (owner, 2026-10-03). (a) **Deleting a brand or a category frees its slugs**: another may take them later. (b) **A store opened later starts with no menu order**; its admins set it (§6.2 stands: a new store chooses nothing). The owner's **bulk filling of a new store** — existing products brought in, a JSON of codes, picking by code; prices and stock from Odoo or by hand — is written as open item §9.2 #4, for steps 4 and 6. (c) **Moving a category**: the mover chooses its place among the new siblings, written into every store as when adding. (d) **Word pairs are added and deleted only**; §9.3 #14 corrected. (e) **An empty slug on an edit is made from the name.** (f) **My choices in the build, accepted**: the first brand becomes the default when none is; a brand description at most 5,000 characters, in both languages or neither; an attribute set holds 1–10 attributes, and one deactivated later may stay but is never added again; a colour attribute's values need a `#rrggbb` swatch, and being a colour is locked with the job; an attribute a set holds stays variant-making; a category is added or moved under an active parent only; a store's order changes only while the store is on, at most 500 categories at once; deleting an attribute deletes and audits its values first, refused while a set holds it; a value may be added to a deactivated attribute; a word pair already listed is refused as "already in the list"; names of attributes, labels, warranties and sets need not be unique (an attribute's values must); deleting a logo's or photo's file needs that list's job with All stores; a typed Arabic slug takes the digits 0–9; two errors not in §7: `NameTaken` ("already in the list") and `AttributeKindLocked`. (g) Conforming fixes found by the review: a category moved away from the parent it went with stays off; `attribute_values.attribute_id` is RESTRICT as §5.3 says; Arabic slugs take Arabic letters and digits only, as §5.3 says. | Owner, 2026-10-03 |
| 3 | §1.1, §1.2, §2.1, §5.1, §7, §8, §9.2, §9.3, §9.5 | **Before step 3: products and variants** (owner, 2026-10-03). (a) **Filter values sit on the product**, several per attribute allowed; each variant's variant-making values count as filters too (new table `product_filter_values`). (b) **The last ready photo of a `READY` product cannot be deleted** from the media library; any other product or variant photo is detached when its file is deleted. (c) **In a draft**, variant codes are edited and variants deleted under `catalog.product.update`, a code given up free again; once ready, only `catalog.variant.correct_code` and archiving. (d) **Relations pick `READY` products only**, at most 20 per list. (e) **The code** — read from the owner's sheet, "the sheet is what Odoo contains": **digits only; it belongs to one product, whose variants may share it or have their own; two products never share one; every code a product ever held stays with it until the product (a draft) is deleted**; a correction changes it on every variant holding it (`variant_codes` becomes `product_codes`; `variantByCode` becomes `variantsByCode`; §9.2 #6 opened for Sync). (f) **Search words**: a duplicate, as typed or as search reads it, is kept once, quietly. (g) **A draft may have its Arabic name only; the English name is required to be made ready** ("all products must have English names"). (h) **Only a draft is ever deleted** (§4.1 stands). (i) **Accepted**: a product's attribute set is chosen among active sets and fixed once it has a variant; each variant takes one active value of every attribute of the set; its details (text in both languages or a number with the unit) use active "details only" attributes; a `READY` product refuses an edit that would break a readiness rule, naming it — its last variant archived, its last ready photo removed, its category cleared; photos are public images, each once, a variant's needing no ready sizes; a product or variant photo's file deleted is detached and audited under `catalog.product.update`; product changes are split into several commands under that one permission (details, gallery, search words, filter values, relations, variants); until step 4 creates store rows, "every store where the product is Active" is "any store where the person holds the job"; and step 2's deferred refusals arrive — a category holding products takes no sub-category and is not deleted, a brand, warranty, attribute, value or set a product uses is not deleted. (j) **On the plan** (owner, 2026-10-03): step 3 is **one PR**; **a variant's values stay editable**, a ready product's too, its combination still unique; **a product is created from a store that is on**; **a code is 1 to 10 digits**. (k) **While building** (owner, 2026-10-03, asked with the drawer 1304 as the example): **an attribute set's attributes stay while any variant is built on it** — its name may still change; for other attributes, a new set (`AttributeSetInUse`); and **an attribute's job is fixed once variants carry details of it**, as once it has values (amendment 1(i)). (l) **Found while building**: §5.1's `products_category_unless_draft` would refuse archiving a draft abandoned without a category, which §9.3 #19 allows — the category and the English name are now required **while ready** (`products_category_when_ready`, `products_english_when_ready`); an archived product changes only by being restored, ready again with both. (m) **On PR #79** (owner, 2026-10-04, asked with the drawer 1304): **restoring brings a product back to the stage it left** — an archived draft comes back a draft, so making it ready stays `catalog.product.publish`'s (`products.archived_from`); **an archived product may be edited**, shown nowhere, and restored once whole — while archived it is not made ready, not deleted, and its variants are not deleted (this replaces (l)'s "changes only by being restored"); **a ready product keeps a category deactivated since it was placed there** — making ready, restoring or moving still needs an active lowest one; step 4's "leave" keeps a product in the closed category, and a product hidden with its category is still edited; **events only for a product that has been ready** — a draft is Catalog's alone; **the limits** ("a lot but it works"): at most 100 details per variant, 100 filter values per product, 300 search words sent at once (30 kept), positions 0–10,000; **accepted**: archiving an archived product or restoring a ready one changes nothing, restoring a draft is refused; correcting a code in a draft frees the old one; a code changed through the variant form, or a variant deleted, once ready answers `InvalidStageChange`; a product change locks the list rows it points at and a list change never takes the products' lock (instead of the plan's list-then-products order) — except, since step 4, the four list changes that change products, which take it first (amendment 4(k)); two rare races are left as they are — a photo added to a gallery the instant its file is deleted fails with a server error, the data intact; a store switched off the instant a product is created from it does not stop the create. After the review (owner, 2026-10-04): **a product never ready — a draft, archived or not — lets go of a code it gives up by a correction**, as a draft does. | Owner, 2026-10-03; (m) 2026-10-04 |
| 4 | §1.3, §1.5, §1.12, §2.4, §3, §5.2, §7, §8, §9.2 | **Before step 4: each store's choice** (owner, 2026-10-04, asked with the drawer 1304 and a UAE store being prepared). (a) **A store is filled one by one** (`catalog.listing.choose`, staff's work) **or by the admins' JSON file of codes** — prices and stock optional fields — under `catalog.listing.fill`, given to admin roles (roles nest: what staff may do, admins and super admins may; "only admins" includes super admins); not "every ready product" nor "copy another store". (b) ~~**The file is all or nothing**: an unknown code refuses it, each listed; a ready product's code chooses the variants carrying it; a product not ready joins the store's **"needs completion"** list, completed and published there or dropped from that store; the answer counts both.~~ **Replaced by amendment 6(g)**: a store file's page, an unknown code corrected or removed there. (c) **Nothing goes on sale in a store until it has a price there** (stage 5). (d) **Deactivating a category gives every product in it or under it, in any stage, the hide / leave / move choice.** (e) **Accepted**: a switched-off store is filled and set up before it opens; a variant first chosen sells retail only; selling terms, labels and "Not available now" need a product the store has chosen; at most 10 labels per product per store, a deactivated one staying where attached; at most 1,000 codes a file; activating a category brings back what hid with it, except under a sub-category still off; a product moved in a deactivation goes to an active lowest category outside it, or to an active brand. (f) **A store's menu order is set while the store is off too** — the whole store is prepared before it opens; step 2's "a store that is on" for `RankCategories` is lifted. **After the review of step 4** (owner, 2026-10-04): (g) **a category deactivated asks again about the products under a sub-category switched off before** — their earlier choice may change; (h) **each store's rows stay read and written by one repository that names the store in every call**, as the menu order's, not through the Eloquent store guard; (i) a store that is off counts among those that sell a product — the owner had no preference, the recommendation kept; (j) **a wholesale maximum needs its wholesale minimum**. (k) **Found while building**: deactivating or activating a category or a brand changes products, so it takes the products' lock first, then its list's; deleting a photo's file takes them in the same order — still no two changes wait on each other in a circle. (l) **On PR #81** the owner accepted the choices made while building ("accept all"): switching on a draft or archived product is refused as an invalid `product`; `StoreListingChanged` fires only when a store takes variants up; a variant switched off still counts as selling wholesale for the wholesale minimum; create, deleting a draft or its variant, making ready and restoring check "some store" only (they reach products Active nowhere); a brand's deactivation reaches every product of it, in any stage; labels show on a card in the list's order; each store's rows through one repository naming the store. | Owner, 2026-10-04 |
| 5 | §1.2, §1.3, §1.4, §1.5, §1.6, §1.10, §1.11, §2.1, §2.2, §5.4, §8, §9.2, §9.4 | **Before step 5: the listing, search and the contract** (owner, 2026-10-04, asked with the drawer 1304 and a UAE store opened later). (a) **A category shows in a store's menu by itself** once the store sells something in it or under it, **in the store's own order — and, until the store's admins place it, at the base store's place** (the owner first chose "hidden until placed", reading "placed" as "chosen to show"; asked again with the meaning made plain: "we can place them implicitly like a default placing"); a category deactivated by hand is in no menu, its products following their fate. (b) **Until Pricing (stage 5), a product a store chose counts as on sale without a price**; from stage 5, no price means not on sale. (c) **A search looks in the name, the search words, the word pairs and the category's name** — not the brand, the code or the description. (d) **Codes are never shown to customers** — "it's only for us, the staff and admins": no code in anything a shopper reads or searches. (e) **The search log keeps submitted searches only**, not the suggestions shown while typing. **Asked again on 2026-10-05, before the search was built**, all four as recommended: (f) **both languages' names are searched on every page**, results in the page's language; (g) **the category's name searched is its category's and every category's above it**; (h) ~~a brand hidden from default listings is hidden from the default grids only — found by search, its brand page, and a category page once its brand is picked in the filter; a category holding only such products is not in the main menu~~ — **replaced by (k)**; (i) **`ListingFacts` is declared in step 5 and kept with stage 5**, which decides, for one, which price a card shows. **Asked during step 5's review, 2026-10-05:** (j) **a product hidden with its category or brand is inactive**: "hidden means we're gonna deactivate the product itself … can't be ordered or shown in storefront at all" — not orderable, not listed, searched or suggested; an old link shows "Not available now" (the owner chose this over a 404); the deactivation screen tells staff, rather than a new product state ("which I don't recommend"); (k) **secondary brands**: "the store is for TouchWood … any other brand is only reachable by its category … not in storefront, not in search" — staff put a secondary brand's products under its own category (Kitchens, Wardrobes, …, Tallsen); the menu and the category pages show it as any other; the search, the home page and the shop-wide grids show only the brands shown in default listings; (l) a secondary brand's products are suggested on its own products' pages only — my reading of (k), **confirmed with (m)**. **(m) On PR #82 the owner accepted the eleven choices made while building ("accept all"):** a product never made ready has no page (not found); a category listing nothing in a store has no page there, an active brand always has one; a card holds the name, address, card photo and labels; the default order is best-selling then newest, other sorts, filters beyond brand, their counts and search paging coming with the screens; up to 48 results a search (100 at most) and 8 suggestions, "nearest" at PostgreSQL's default 0.6; picked "Related" products a store does not sell are not replaced by filling in; (l); the repair runs by hand and product changes wait for it; a product page asks Platform for each gallery photo's addresses until the screens' query budget settles it; punctuation dropped from what is searched and logged; at most 50 brands in a filter. Hidden products still come back by themselves when their category or brand is switched on again — explained to the owner with the alternative, and kept. | Owner, 2026-10-04, 2026-10-05 |
| 6 | §1.3, §1.12, §2.3, §3, §5.5, §7, §8, §9.2 | **Before step 6: the import's file and the store file** (owner, 2026-10-05). (a) **Each uploaded product file gets its own page** — "it lists all the uploaded products, then he (Super Admin) can manage and see if there's a not-ready product to fill its photos or prices … the same functionalities of the products page" — **replacing "a preview, then all or nothing"**; its parts may sit on one page or several ("if you divided them to be more easy, no problem"). (b) **Two uploads**: the JSON alone (products without photos, drafts until given one) or one zip with the JSON and its photos. (c) **A name the catalog lacks** — "if this was a typo, he can choose from existing categories … or it's a new category, so it's created", or its creation refused; one at a time, or several together. (d) **A code the catalog already has**: update the existing product, replace it whole, skip the row, or give it another code (a typo) — all four. (e) **Products not wanted**: archive or delete, both offered. (f) **Accepting** makes the ready ones ready ("ready to publish but not published") ~~and switches them on in the stores the file names~~ (**changed by amendment 9(b)**: on sale nowhere — a store's admins publish them); incomplete ones are completed in the product page first. (g) **The admins' store file** — "just codes and prices, and stock is optional … the product must actually exist … any code that's not existing, flag it … corrected or removed … not editing the main products, it's just select this product to be active in this store": a page of its own per store, **replacing amendment 4's "an unknown code refuses the whole file" and "completes and publishes it there"**. Prices and stock, ~~in either file~~ (**only a store's own file, amendment 9(a)**), are shown and not kept until stage 5 (§9.3 #15). **[PROPOSED 2026-10-05], for the owner's go:** the file's format and field names (§1.12, §1.3), its limits (2,000 products, 20 MB JSON, 500 MB zip; 1,000 store items), names matched as search compares words, the description's plain-text markers, bringing in from the queue, and the tables of §5.5 — **accepted by the owner ("all good"), who asked for the exact format as an example file to fill with another AI agent: written as `catalog-import/` (a guide and two complete examples)**. (h) **The store file's job is admin roles only, enforced** — asked because it needs a small change in Access, which the owner keeps closed ("enforce it — small Access change"): an optional `adminOnly` on a declared permission (access.md amendment 62). | Owner, 2026-10-05 |
| 7 | §1.6, §1.12, §3, §5.3, §5.5 | **While building step 6: what a file may create, brands by number, and changes before bringing in** (owner, 2026-10-05, asked because a brand needs its agency type and its place in default listings, and a warranty its terms and period, which a file's names do not give). (a) **Brands, warranties and attributes are never created from a file** — "the brand must be earlier created then we can upload products"; on the import's page a name of one the catalog lacks is picked as one it has, or refused. **Values, categories and sets are still created there** ("attributes first, values from file"). (b) **Every brand has a fixed number**, and the file's `brand` is that number or the brand's name: "in case brand was not there it must be treated as Touchwood, and if it was written Touchwood it must also be assigned, unless I typed 2 for an existing brand or its name then it's gonna be assigned" — for brands only, for now; the number is fixed, never the place in a list. (c) **Changes to all the products of an import, or the selected, before they are brought in** — ~~stock and price in a store, the stores to switch on in,~~ (withdrawn by amendment 9(a)) the brand, warranty or category, search words and filter values: "there must be at the end a confirm button to save all of that at system … all of this just draft, once confirmed the system starts acting"; the confirm is bringing the products in. (d) **Each change asks** whether to replace what the products have or only fill the ones that have none ("choose each time"). Search words and filter values may also be added to what each has — proposed, **accepted by amendment 8(a)**. | Owner, 2026-10-05 |
| 8 | §1.12, §3, §5.5, §8, §9.3 | **After step 6's reviews** (owner, 2026-10-06). (a) **Adding to what each product has**, for search words and filter values, **kept** — beside replacing and only filling the empty. (b) ~~**The stores, replaced, become exactly those chosen** (the others taken away); filling gives them only to the products with none; **a price and stock is a change of its own, in one store, for the products switched on there**.~~ **Withdrawn by amendment 9(a)**: the products file names no store. (c) **Web addresses that would collide are decided on the page** ("decide on the page"): two products of the file, or one and the catalog's, made the same address from the same name — each given its own address before bringing in; a new category given its own when the one made from its names is taken. (d) **A name several catalog items answer to is asked on the page** — "matches 2 warranties: pick which" — instead of taking the first found (brand names are not unique either: only a brand's number never is ambiguous). (e) **The guide's list of what refuses a file names the checks against the catalog** too, and says that a brand number in quotes is read as a name and that a category name holds no "/". (f) **The import's tables accepted as built** — after the owner had them listed column by column ("list them to me"). | Owner, 2026-10-06 |
| 9 | §1.12, §2.3, §3, §5.5, §8, §9.3 | **The products file only brings products in** (owner, 2026-10-06, asked whether "replace" should switch off a product's other stores): "the super admin bulk upload [is] only to upload products themselves, not in exact store, not published … its only for filling products; if I wanna let these products active, I must enter the store upload page then upload there the prices and stocks". (a) **The `stores` field, with its price and stock, leaves the products file** — a file that still has it is refused, saying prices and stock come with each store's file — **and the import page's "stores" and "price and stock" changes go** (amendment 8(b) withdrawn): stores, prices and stock come only through a store's file (§1.3) or the panel. (b) **Accepting makes complete products ready, switched on nowhere** — "ready" is complete, not published; a store's admins publish them, store by store. (c) **A product the file updates or replaces that is on sale** in any store needs **keep on sale** ("maybe it's a small update") or **take off sale** ("maybe it's a big one"), chosen per product or several at once, **every time — no default** ("must choose each time"); taken off sale, it is switched off in every store when brought in, still ready, its page "Not available now", orders keeping their own copy, back on sale when a store's admins switch it on again. | Owner, 2026-10-06 |
| 10 | §1.6, §1.12, §3, §5.5 | **The owner's word on step 6's choices** (owner, 2026-10-06, after reading the 19 choices: "all good", but for these). (a) **A deleted brand's number is free again**: "a deleted brand number is reusable, once brand is deleted its number gets free, no gaps" — a new brand takes the lowest number no brand holds; a brand's own number still never changes while it exists. (b) **Uploaded files are not kept**: "once we read the data and finish the operation we're done with it and file is no longer at our db or sys" — a JSON file and a store file are let go once read; a zip only until its products are brought in; **an import not brought in may be discarded** by the Super Admin, its zip with it (the one case the owner was asked about: "a discard action"). (c) The hosting items found while building step 6 — the import job's queue, the zip's disk, PHP's zip support — go to handoff §15.4 for the agent who sets up the hosting. | Owner, 2026-10-06 |
| 11 | §1.3, §1.12, §3, §4.1, §5.2, §5.5, §8 | **After the reviews of the whole module (step 7)** (owner, 2026-10-06, asked one by one). (a) **Codes that mix catalog products are left out, the rest of the file coming in**: a product whose codes two of the catalog's products hold, and two or more products whose codes one catalog product holds — "that must be defined in the file": the file gives that product once, with all its variants — no longer refuse the whole file; they are shown on the import's page as refused, with the reason ("note Sadmin that we did so"), and take no part in it. (b) **A product not a draft keeps its codes**: the confirm lists one the file would update or replace with a variant of the same values but another code, and waits — skipped, or the file uploaded corrected — before anything runs. (c) **The Super Admin's word on the import's page is carried out**: "3 overwrites what have been done by other staff" — archiving or deleting a product the import created and nobody accepted, whatever was done to it since; one made ready meanwhile is archived, or **deleted whole even if a store put it on sale** ("Delete it anyway"), switched off there first — replacing §4.1's "only a draft is deleted" for this one case. (d) **Three refusals** for files no one would mean: a stock over 2,147,483,647, a price with more than 6 decimal places, a zip of more than 100,000 entries. (e) Written down from the reviews, nothing changed: the file's related and goes-with products are linked when products are accepted on the import's page; the product list and page and the zero-result searches list are read with their screens; cross-store reads (§5.2). | Owner, 2026-10-06 |
| 12 | §1.1, §1.2, §1.3, §1.11, §1.12, §5.3 | **Every number in Latin digits** (owner, 2026-10-06: "all numbers inside system gonna be latin"; the rule for the whole system, frontend.md §1.8, Shared's `LatinDigits`, platform.md §2.5): Arabic-Indic and Persian digits typed anywhere in Catalog — a name, a value, a detail, a search word, a word pair, a web address, a description, a code; in the panel, a products file or a store file — are saved as 0-9. A typed Arabic slug's `١١٠` is now saved as `110` rather than refused (amendment 2(f): the slug still holds 0–9 only), and a detail's number typed `٢٥` is `25`. Names and searches were already compared with digits read that way (§1.11). Catalog's tables held no data to convert when this was written. | Owner, 2026-10-06 |
| 13 | Status, §2.4, §3, §4.4, §4.5, §8, §9.3, §9.7; frontend.md §2.2, §2.3 | **The screens** (owner, 2026-10-07, answering in two batches in the conversation — a first, Q1–Q11, on setup and structure; a second, #1–#12, on the screens and the rule below — "all else approved as recommended", "all else accepted"). (a) Their own worktree, databases and port. (b) **Written into this file**, as B2B's screens are in b2b.md. (c) **Built in this order**: the six shared lists (seven screens — attributes and variations apart), then the products list and a product's page, then each store's rows and the store file, then the import, then the shop. (d) **Catalog may use the shared web glue** (`App\Http`), as B2B does. (e) **The import's pages name their uploader through Platform's `StaffNames`** — Access untouched. (f) **`CreateProduct` asks no store**: the job in some store that is on — the panel has no store worked in any more (frontend.md §2.2, 2026-10-06). (g) **The shop is built now, last, with no price, stock or Add to Cart** until their modules. (h) **No search in the panel's header and no Catalog card on Home yet**; the products list searches by name or code. (i) **Catalog first**: stage 5's and 6's screens later, as their backends land. (j) The owner, asked whether to write every spec before building: "what about writing all specs and then let you work alone till finish?" — then, asked again, **"B": build straight away on the recommendations** (§9.7), the owner reviewing the specs and the built screens together, a change coming as a fix. (k) **Each step's pull request merged by this session once its checks are green** — the full check, the browser suite and an independent review first ("Q1.B": "let you merge then fixes if there"). (l) Shop addresses `/products/`, `/categories/`, `/brands/`, `/search`; the menu and the search box on every shop page through a small Platform list; a product's photos in **one Platform read** ("Q10. as recommened", after asking whether it was the best way); a product's admin page in **tabs, the tab in the address**; **Attributes and Variations** two screens, no Colours screen. If stuck on the backend, ask its builder's session (owner). | Owner, 2026-10-07 |
| 14 | §1.13, §2.2, §2.4, §3, §4.4, §4.5, §5.2, §5.4, §8 | **Individuals and companies** (owner, 2026-10-07, relayed by the stage 5 session and confirmed here: "you massage the session and tell it with our new rules then it applies the correct fixing of it"). In each store, admins choose which categories individuals see and which companies see; individuals buy retail only, companies retail and wholesale; a pending, rejected or suspended company sees the company side; guests see everything. On the owner's answers: (a) who is looking is asked of **a Platform contract Access fills**; (b) a category nobody switched is **seen by both**; (c) **a new job, admin roles only**; (d) **a parent's switch reaches everything under it, a sub-category may then change alone**; (e) **a product all wholesale-only in a store is not shown to individuals**, a mixed one shows them its retail variants; (f) **a card shows the first variant this viewer can buy**. The handoff's side — one price for everyone, wholesale for companies — is the stage 5 pull request's (#97). | Owner, 2026-10-07 |
| 15 | §2.2, §5.2, §5.4 | **The prices pushed into the listing** — the stage 5 session's proposal (2026-10-07), for the owner's OK with Pricing's spec (#97): `prices()` takes per variant a `ListingPrice` (now, and the old price while lower, without VAT); Catalog keeps them and picks each side's card variant (amendment 14(f)). Open: a company's card whose first variant sells wholesale only. | Stage 5 session, 2026-10-07 — **[PROPOSED P22]** |
| 16 | Status, §1.2, §1.3, §1.5–§1.9, §1.12, §2.1, §2.2, §3, §4.4, §4.5, §5.1, §5.3, §5.5, §6.1, §7, §8, §9.1, §9.2, §9.3, §9.7; `catalog-import/` (with the backend); frontend.md §1.7, §1.11, §2.1, §7; b2b.md amendment 31; HANDOFF §5.3, §9.1; AGENT-BRIEF | **Codes, variants and orders — after the owner tested the product page** (owner, 2026-10-09: stuck on "With no variation, a product has one variant only", and on the brands' two number columns; the stage 5 session's two questions answered in the same conversation). (a) **One code per variant**: "each variant has its own code right, but variants share the same product or let's name it item, so item contains multiple variants and each one may have its own price" — two variants never share a code, replacing amendment 3(e)'s "its variants may share it"; a product still keeps every code it ever held, so no other product takes it, and one of its own variants may take one back; a correction changes the one variant; `variantsByCode` becomes `variantByCode`; §9.2 #6 closed, and with it Pricing's and Inventory's open points on shared codes. (b) **No attribute sets**: "just make variants has or not instead of current, so we can select the sizes as we want … we can create new one from this tab" — a product's variants pick their values from any variant-making attribute in its Variants tab; the attribute sets, a product's set and the Variations screen go; kept, as recommended: **every variant of a product uses the same attributes** (the product's own ordered list, `product_attributes`, the shop's pickers in its order) and **no two of its variants have the same value of every one** ("can add two same values but different codes, in case we have X colors from same size"); an attribute added to a product asks each variant for its value, one removed only while the variants stay different. (c) **Whoever may change a product may make a new value** from its Variants tab (`catalog.product.update`), as on the Attributes screen. (d) **Every ordered list is ordered by dragging**, with **Move to Top** and **Move to Bottom** in each row's ⋯ menu — brands, labels, warranties, attributes, attribute values, a product's variant attributes and its variants, a category among its siblings; the typed places and the "#" columns go, a new item last; brands' fixed number shown as **Brand No.** ("we only need one single column for ordering not 2"); warranties gain an order; a store's category order saved when dropped; a category added or moved goes last (replacing amendments 1(d) and 2(c)). **My reading, for the owner to confirm**: the owner listed brands, labels, warranties, values and variants; attributes, a product's variant attributes and categories' places are ordered the same way. Catalog's lists only: B2B's type lists and Platform's stores keep their typed positions until the owner says otherwise. (e) **Pricing's three reads** (the stage 5 session, accepted as named): `productIdsInCategory`, `variantIdsOf`, `switchedOnVariantIds` (§2.1; pricing.md §2.4). (f) **Every form checks its boxes as they are typed, in every module, now** ("we dont wanna wait until request returns red to a numeric value that was entered character, it must be from the moment typed"): frontend.md §1.7 — a number box refuses letters at once, ranges and lengths said under the box while typing, Save out of reach while a box is wrong, the server still checking everything; B2B's company page, whose boxes were checked once left (b2b.md amendment 22(a)), checks them while typing too. (g) **How it is built**: spec first, the owner reviewing it; then the backend (`feat/catalog-free-variants`) — with it the guide `catalog-import/` and its two examples **brought in line with §1.12** (no `attribute_set`, sets gone from its names, every variant its own code — the second runner `1314` —, the new refusals), since the import and its tests read them and a file filled from a changed guide would be refused until then —, the checks as typed (`feat/forms-type-checks`), and the screens reworked; the picks this needed are P26–P34 (§9.7), for the owner's review with this amendment. (h) **"Last pieces" on cards** — the owner's decision of 2026-10-09 in the stage 5 session (inventory.md §1.2, §2.3, PR #102), relayed here, for the owner to confirm in this review: Inventory pushes `ListingFacts::endingSoon(StoreId, list<variantId>, bool)` inside its own transaction whenever a size's state flips, as `orderable`; Catalog keeps it in its own table (`store_variant_facts.ending_soon`) so a listing rewrite never loses it; **a card shows "Last pieces" while any of its product's sizes in that store is ending soon** ("any size", over "the shown size"); a product's page, for the size the shopper picks. **The migration**: each product's set's members, in the set's order, become its variant attributes; the sets' tables dropped; a product whose variants share a code stops the migration, naming it (no shared code is known to exist); warranties numbered in name order. | Owner, 2026-10-09 |

### 9.7 Proposed with the screens — built as recommended (amendment 13(j))

Mine — but for P22's price, which is the stage 5 session's — written 2026-10-07 while the owner was
away; the owner chose to have them **built as recommended** and to review them with the built screens
("B"). Each says what it costs to change later. **P26–P34 are amendment 16's (2026-10-09) and wait for
the owner's review of it before they are built**, unlike the rest.

| # | Proposal | Built as | Changing it later |
|---|---|---|---|
| P1 | **A description and a warranty's terms are typed as plain text with the products file's own marks** — a blank line starts a paragraph, `- ` a list item, `# ` a heading, `**…**` bold (§1.12) — with the formatted text shown under the box as it is typed. Neither Geist nor shadcn has a text editor; the import already reads these marks (`DescriptionText`) | A shadcn Textarea and a preview | An editor with buttons is a component of our own — the owner's word first (frontend.md §1.11) |
| P2 | **A shared list is read by anyone holding its job in any store**; its changes need All stores, so for others every button is disabled with that reason | As the menu offers it | One line in each list's read |
| P3 | **A product's stage**: Draft gray, Ready green, Archived amber — each subtle, in words | Geist's Badge | A colour map |
| P4 | **The products list shows every product** to a holder of `catalog.product.view` (a product belongs to no store), each store's state only for the stores the reader covers; All Stores first, a store chosen showing its state; newest first, 50 at a time | S8 | The read's filter |
| P5 | **Photos are uploaded on the screen that uses them** — no picker of the library's files; the same file uploaded twice is one file (Platform) | `UploadCatalogImage` | A picker needs a Platform read of the library |
| P6 | **A product's Stores tab**: one card per covered store, as the owner described the page (§1.3); a Super Admin also sees off stores, marked Off, as every panel filter does (frontend.md §2.2) | S9 | Which stores the read returns |
| P7 | **The category tree is the table's rows**, a parent's folding under it — shadcn's Table and Collapsible. Geist's only tree, File Tree, is for "illustrating project layouts", with no columns or actions | S2 | A drawing, not data |
| P8 | **Orders are set by dragging** — a store's menu among siblings, ~~a variation's attributes,~~ a gallery, the related products — shadcn's `dashboard-01` handles on `@dnd-kit`, as the address formats (mouse, touch, keyboard); **since amendment 16(d), every ordered list**: brands, labels, warranties, attributes and their values, a product's variant attributes and variants, each saved when dropped, with Move to Top / Move to Bottom on the row's menu | S1–S3, S5, S6, S9 | Per screen |
| P9 | **Who sees a category** — two switches a row, saving as each flips (Geist: a toggle takes effect at once) | S2 | Per screen |
| P10 | **Deleting a list item**: a confirm dialog (it is refused while used); **discarding an import and deleting imported products**: Geist's Destructive Action Modal, the file's name typed | S1–S7, S11 | Per dialog |
| P11 | **Searches that found nothing**: the last 12 months, grouped by words, store and language, most searched first, 50 at a time; All Stores or one; a row opens Add Word Pair with its words | S7 | The read |
| P12 | **The import's parts are tabs in the address**; while bringing in, the page asks again every few seconds | S11 | Per screen |
| P13 | **The import's and the store file's pages read in batches**, the import's products 100 at a time, to keep 15 queries | `ViewImport`, `ViewStoreFill` | The reads |
| P14 | **The shop's filters**: brands, the filter attributes' values present, and Retail or Wholesale for a company or a guest, each with its count, from one read; price with Pricing | §4.5 | The read |
| P15 | **The shop's sorts**: Best-Selling (newest until Sales ranks) and Newest; price sorts with Pricing | §4.5 | The read |
| P16 | **Search results**, 48 at a time with Show More (the search gains paging) | §4.5 | The read |
| P17 | **A product's page says how it is sold** to the viewer — retail, and wholesale for a company or a guest — with the minimums and maximums | §4.5 | The page |
| P18 | **A link to a product this viewer's account type does not see** shows "Not available now", as anything else not orderable here does (§1.4) | §4.5 | One answer of the read |
| P19 | **Every shop page carries its canonical address and the other language's** | §4.5 | The frame |
| P20 | **The shop's menu**: shadcn's Navigation Menu, "All Categories" and the top categories; on a phone a Sheet with the tree; kept in the never-stale cache per store, language and viewer | §4.5 | The frame |
| P21 | **The budgets are kept**: 8 a shop page, 15 an admin page, each page's count recorded — the shop's menu, and a product's page, kept in the never-stale cache to get there; a page that cannot keep it is brought to the owner with its measured count | §4.4, §4.5 | — |
| P22 | **The receiving side of `ListingFacts`** — `store_variant_facts`, the listing's price columns, the interface bound — built with the shop's pages so stage 5 has something to call; the contract's final shape is the owner's with Pricing's spec (#97). The price's shape is the stage 5 session's proposal, not mine | §2.2, §5.2, §5.4 | Stage 5's call |
| P24 | **A sub-category added or moved takes its new parent's switches** for individuals and companies, in every store (found by the review of this spec: "no row means both" would otherwise show it to those its parent is off for) | §1.13 | One rule in the add and move handlers |
| P25 | **A guest sees what either kind of account sees** — the owner's "everything the individuals and companies products" read literally: a category switched off for both is seen by no one | §1.13 | One condition in the listing's read |
| P26 | **A code is carried by one variant in the whole catalog** (a unique index on the variants' codes); `product_codes` stays the product's: a code it held keeps out every other product while it exists, and one of its own variants may take it back once no variant carries it | §1.2, §5.1 | A unique index |
| P27 | **A product's variant attributes are kept, in order**, in `product_attributes` — the order of the shop's pickers and of the Variants tab's columns; changed by **Add Attribute…** (each variant, archived ones too, given its value in the same dialog, all or nothing), **Remove** (refused with `DuplicateCombination` while two variants would become the same) and **its order dragged** (`OrderVariantAttributes`); a variant's `combination` is kept in its attributes' id order, so a reorder rewrites nothing; a product with none has one variant | §1.7, S9 | A table and three commands |
| P28 | **A new value from the Variants tab** is made inside Add Variant… / Edit… / Add Attribute… ("New value…": both names, a colour's swatch), active, last in its attribute's order, audited as on the Attributes screen; never under a deactivated attribute | S9 | One command |
| P29 | **An order is saved as the ids in their new order**, as `RankCategories` saves a store's menu: those not sent keep their order after those sent (two people ordering at once lose nothing), a new item goes last; Move to Top and Move to Bottom send an order from the row's menu; positions written again 1, 2, 3 … on each order, so they never run past amendment 3(m)'s cap | §3, §4.4 | The commands |
| P30 | **Pricing's reads**: `variantIdsOf(productId, includeArchived = false)` — the variants in their order, archived ones when asked; `productIdsInCategory(categoryId)` — the category and everything below it, any stage; `switchedOnVariantIds(store)` — the variants Active there | §2.1 | The contract |
| P31 | **Checks as typed**: each box declares its rules (required, whole number, range, length, digits for a code); a letter in a number box is said at once ("Numbers only."); "required" once the box was touched or Save pressed, not on a fresh form; Save out of reach while any box is wrong; the words are the module's own, as the server's; said to a screen reader with the box (frontend.md §1.7) | frontend.md §1.7 | One shared helper |
| P33 | **The import against a product the catalog has**: its variants are matched **by code** (codes are one variant's now) — a code the product carries updates that variant, a new code adds one; **Update** needs the file to name the product's own attributes, else the product is listed on the import's page to **Replace** whole (taking the file's attributes) or **Skip**; amendment 11(b)'s rule stays — a ready product keeps its codes, a variant of the same values under another code waits for a decision | §1.12 | The import's matching |
| P34 | **The migration and the imports still being decided**: an import not brought in whose names hold a set, or whose products name a set or share a code, **stops the migration, named** — the Super Admin discards it (amendment 10(b)) or brings it in first; nothing is deleted by the migration | §5.5 | One check in the migration |
| P32 | **The order of the work**: this amendment reviewed; then the backend, then the checks as typed (every module), then the screens — the Variants tab, the lists' drag, the Variations screen and its menu entry removed — then step 3 (S9's Stores, S10) continued on top | §4.4 | — |
| P23 | **The steps, each a short branch and one pull request into `main`** (step 1's Variations screen removed again by amendment 16(b)): 1 `feat/catalog-lists-screens` (the six shared lists' seven screens, their menu entries, the web glue, Platform's read of many photos); 2 `feat/catalog-products-screens` (S8, S9 but Stores); 3 `feat/catalog-store-screens` (S9's Stores, S10); 4 `feat/catalog-import-screens` (S11); 5 `feat/catalog-shop-audiences` (amendment 14: Platform's and Access's parts, the switches, the listing); 6 `feat/catalog-shop-pages` (§4.5, P22) | §4.4, §4.5 | — |
