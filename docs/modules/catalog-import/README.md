# TouchWood product files — how to fill them

> **What this folder is.** The agreed format of the two files that bring products into TouchWood's
> catalog, kept here so the owner — and any agent — can always find it: this guide, and a complete
> example of each file. The owner fills real files from it, often with another AI agent's help. The
> Catalog import (stage 4, step 6) reads **exactly** this format. **Change it only with the owner's
> agreement**, and change the guide, both examples and `catalog.md` §1.12 together.

Two kinds of file go into the catalog (catalog.md §1.12 and §1.3, amendment 6, agreed with the owner
on 2026-10-05). This guide is written so a person, or another AI agent, can fill them exactly.

| File | Who uploads it | What it does |
|---|---|---|
| **Products file** — [`products.example.json`](products.example.json) | A Super Admin | Brings new products in, or updates existing ones. Uploaded **alone** (no photos) or **inside a zip with its photos** |
| **Store file** — [`store-fill.example.json`](store-fill.example.json) | An admin of one store | Switches existing products on in that store, with their prices |

Both files are **UTF-8 JSON**. Write Arabic as Arabic letters, not escapes. Every text is one line
unless this guide says otherwise.

---

## 1 · The products file

```json
{
  "format": "touchwood-products/1",
  "products": [ { … one product … }, { … } ]
}
```

- `format` must be exactly `"touchwood-products/1"`.
- `products` holds **1 to 2,000 products**. The JSON is at most **20 MB**.
- **One entry is one product.** Its sizes, finishes or colours are its **variants**, inside it.

### 1.1 A product

| Field | Required | Type | Rules |
|---|---|---|---|
| `name` | **Yes** | `{"ar": text, "en": text}` | `ar` is **required**; `en` is optional but **needed for the product to be ready**. Each at most 200 characters. |
| `slug` | No | `{"ar": text, "en": text}` | The web address. Left out, it is made from the name. `ar`: Arabic letters, digits and single hyphens; `en`: `a-z`, digits and single hyphens (`soft-close-runner`). |
| `description` | No | `{"ar": text, "en": text}` | Both are **needed for the product to be ready**. At most 20,000 characters each. May span lines — see §1.4. |
| `brand` | No | number or text | The brand's **number** as the panel shows it (`2`) — best, no typo possible — or its name in Arabic or English. Left out, the default brand (TouchWood). The brand must already be in the catalog. |
| `category` | No | text | The path from the top, names separated by ` / ` (space, slash, space): `"Kitchens / Drawers / Runners"`. It must end at a category with **no sub-categories**. **Needed for the product to be ready.** |
| `warranty` | No | text | A warranty's name. It must already be in the catalog. |
| `attribute_set` | When variants have `values` | text | The set whose attributes make the variants (e.g. `"Runner sizes"` = Length + Finish). |
| `variants` | **Yes** | list | **At least one.** See §1.2. |
| `photos` | No | list of text | The gallery, in order: **paths inside the zip**, at most 20, each once. **At least one is needed for the product to be ready.** Leave out when uploading the JSON alone. |
| `search_words` | No | list of text | Extra words shoppers might type, either language. At most 30, each at most 50 characters. |
| `filters` | No | `{attribute: [values]}` | Filter attributes and their values (`"Use": ["Kitchen", "Wardrobe"]`). At most 100 values. |
| `related` | No | list of codes | "You may also like": codes of other products, in this file or already in the catalog. At most 20. |
| `goes_with` | No | list of codes | "Goes with" (accessories), the same way. At most 20. |
| `stores` | No | `{store code: {"price": number, "stock": number}}` | The stores it is switched on in **when you accept it**. Store codes as in the panel (`sa`, `eg`, `ae`). `price` in that store's currency (e.g. `120.5`); `stock` a whole number. Both optional. **Until Pricing and Inventory exist (stage 5) prices and stock are shown but not kept.** |

### 1.2 A variant

Every product has **at least one** variant. A product with no sizes or finishes has **exactly one**,
with no `values`.

| Field | Required | Type | Rules |
|---|---|---|---|
| `code` | **Yes** | text of digits | **1 to 10 digits, written as text**: `"1304"`, never `1304` (a number would lose leading zeros). |
| `values` | When the product has an `attribute_set` | `{attribute: value}` | **One value for each attribute of the set**, by name: `{"Length": "45 cm", "Finish": "Zinc"}`. Two variants of one product never have the same values. |
| `details` | No | `{attribute: …}` | Information-only attributes. Each is **either** text in both languages `{"ar": "فولاذ", "en": "Steel"}` (each at most 200 characters) **or** a number (`35`, at most 9 digits and 3 decimals, read with the attribute's unit). At most 100. |
| `weight_g` | No | whole number | Grams, 1 to 1,000,000. |
| `length_mm`, `width_mm`, `height_mm` | No | whole numbers | Millimetres, 1 to 1,000,000. |
| `photos` | No | list of text | This variant's own photos, paths inside the zip, at most 10. |

**Codes.** Variants of one product **may share a code** — the same runner in 45 and 50 cm, as the
supplier holds them. **Two different products never share a code**: a file where they do is
refused, both listed. (In a spreadsheet, rows with the same code and different sizes are **one
product** with several variants; the same code on two different items is a mistake to fix in the
source.)

### 1.3 Names in the file

Brands, categories, attributes, values, attribute sets and warranties are written **by name**, as they
appear in the panel, in **Arabic or English** — a brand also **by its number**, written as a number:
`2`, not `"2"` (in quotes it is read as a name). A category's name never holds a `/`: it separates the
levels of a path. Names are matched
ignoring upper/lower case, extra spaces, Arabic marks (tashkeel) and letter forms (أ/إ/آ = ا, ة = ه,
ى = ي).

**Brands, warranties and attributes must already be in the catalog** — add them in the panel before
uploading. **Values, categories and attribute sets may be new.**

**A name the catalog does not have yet is not an error.** The import's page lists it, and the Super
Admin decides there: it is a typo for an existing one (pick it), or — for a value, a category or a set
only — create it (fixing the wording and giving its name in the other language), or refuse it. A
refused brand becomes the default brand (TouchWood). A name **several** catalog items answer to — two
warranties both called "Two years" — is asked about the same way, to pick which.

Two products of a file with the same name, or a product named like one already in the catalog, would
get the same web address: the page lists them and asks for an address for each (`slug` in the file
avoids it).

In the example file, the runner names its brand as `"TouchWood"` and the hinge as `2` — the brand the
panel shows as number 2 (Tallsen, in that panel).

### 1.4 Writing a description

Plain text. In JSON, a new line is written `\n`.

| Write | You get |
|---|---|
| A blank line (`\n\n`) | A new paragraph |
| A line starting `- ` | A list item |
| A line starting `# ` | A heading |
| `**words**` | **Bold** words |

Nothing else is special. No HTML.

### 1.5 What makes a product "ready"

A product comes in as a **draft** and is accepted on the import's page. It can be accepted (made
ready) once it has **all** of these — the page shows what each one still misses:

- the Arabic **and** English name;
- the Arabic **and** English description;
- a brand and a category with no sub-categories;
- at least one variant, each with a code;
- at least one gallery photo.

Anything missing can also be completed later in the product page.

### 1.6 Uploading

- **The JSON alone**: products arrive without photos (drafts until each gets one in the panel).
  Leave `photos` out.
- **A zip** with the JSON **at its top, named `products.json`**, and the photos anywhere inside it.
  Paths in the JSON are relative to the zip's top, with forward slashes: `photos/1304-front.jpg`.
  Photos are **JPEG, PNG or WebP**, each at most **10 MB** (an admin setting). The zip is at most
  **500 MB**.

### 1.7 What refuses the whole file

The file is refused, **every problem listed**, if: it is not valid JSON or not this format; a field
has the wrong type; a product has no Arabic name or no variant; a code is not 1–10 digits; two
products share a code; two variants of one product have the same values; a photo named in the JSON is
not in the zip (or photos are named in a JSON uploaded alone); a number is out of range; a list is too
long; the file is over its limits. Nothing is kept from a refused file.

Once the file is in its format, it is read against the catalog, and also refused — every problem of
this second reading listed — if:

- an attribute is used for two jobs in the file (in `values`, `filters` or `details`), or for a job
  the catalog's attribute of that name does not have;
- a `category` path ends at a category that has sub-categories;
- a product's variants do not give exactly the attributes of its catalog `attribute_set`, or two
  products give a new set different attributes;
- a photo is not JPEG, PNG or WebP, or is over the media library's limit (10 MB by default);
- a product's codes belong to two different products already in the catalog.

A name the catalog does not have, or that several catalog items share, is **not** an error: the
import's page asks about it (§1.3).

A file that passes changes **nothing** until the Super Admin decides on its page: new names, codes the
catalog already has (update that product, replace it whole, skip, or give another code). There, all
the products or the selected ones can also be changed at once — brand, warranty, category, the stores
to switch on in with a price and stock, search words, filter values — replacing what they have or only
filling the ones that have none. Then **bring the products in** (the confirm: until then all of it is a
draft), then accept, archive or delete them.

---

## 2 · The store file

```json
{
  "format": "touchwood-store-fill/1",
  "items": [
    { "code": "1304", "price": 120.5, "stock": 40 },
    { "code": "1305", "price": 125 }
  ]
}
```

- `format` must be exactly `"touchwood-store-fill/1"`.
- `items` holds **1 to 1,000** items, **each code once**. The file is at most **2 MB**. The store is
  the one whose page it is uploaded from — the file does not name it.
- `code` — **required**, 1 to 10 digits **as text**.
- `price` — **required**, a number of at least 0, in the store's currency.
- `stock` — optional, a whole number of at least 0.
- **Until Pricing and Inventory exist (stage 5) prices and stock are shown but not kept.**

**It never creates or changes a product.** Every code must belong to a product already in the
catalog. Its page shows each item: ready (switching it on chooses the variants carrying that code),
not ready (what the product lacks — completed in the product page), archived, already on, or an
**unknown code** — corrected or removed there. The admin then switches on the items chosen, or every
ready one.
