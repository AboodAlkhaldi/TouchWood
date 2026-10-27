# Shipping — module specification

**Status (2026-10-27): draft for the owner.** Shipping answers one question for Sales: *can this
store take this order to this address, with which carriers, for how much?* It holds each store's
carriers and their prices by place, the free-shipping amounts, the tracking links, and the optional
box list. It is built from handoff §12.5, the Sales contract that waits for it (sales.md §2.4, in code
since Sales step S7b), and the owner's answers of 2026-10-17, 2026-10-19 and 2026-10-27 (§9.1). Points
marked **[PROPOSED]** are mine, stated for the owner to reject; the questions are in §9.3.

**Dependencies (handoff §4.4):** Shipping → Platform, Catalog. **[PROPOSED]** Shipping → Access **only
to declare its permissions**, as the owner allowed Catalog, Pricing, Inventory and Sync (handoff §4.4,
2026-10-02/07) — not yet allowed for Shipping: a handoff §4.4 amendment for the owner (§9.2). Sales
calls Shipping; Shipping never calls Sales, so **Shipping never knows which orders name a carrier**
(§1.3).

## What Shipping does not own

| Thing | Owner |
|---|---|
| **The places** — the countries a customer may choose in an address, and each country's regions, cities and districts — and **which countries each store ships to** | **Platform** (platform.md amendment, §9.2 here): Access needs them for the address form, Shipping for its prices, and Access may not depend on Shipping |
| The customer's addresses | Access (access.md §1.9, amended — §9.2) |
| The order's carrier, fee, tracking number and link, marking shipped/delivered/came back | Sales — it snapshots what Shipping quoted (sales.md §1.3, §1.5) |
| The return window | Sales (`sales.returns.window_days`) |
| Weights and sizes of a product's sizes | Catalog (catalog.md §1.2: per variant, optional) |
| VAT | Pricing — and **none on shipping** (owner, 2026-10-27, §9.1 S18) |
| Live carrier rates from a carrier's API | Nobody: **no live carrier APIs** (handoff §12.5); may come later |

---

## 1 · Aggregates and invariants

### 1.1 The rules

1. **A carrier belongs to one store and ships in one country** (owner, 2026-10-19 S5; 2026-10-27 R2).
   A store may ship to **several countries** (owner, 2026-10-27: "can a shop ship to multiple
   countries? yes"), each with its own carriers.
2. **Prices are by place only** (owner, 2026-10-19 S4): a city, or a district of a city. Never by
   weight, never by the number of pieces.
3. **One place, one price per carrier** (owner, 2026-10-17): a place never holds two prices in the same
   carrier.
4. **One amount, one group per carrier** (owner, 2026-10-17): every place a carrier charges 20 for is in
   that carrier's one "20" group. Two groups of the same carrier never have the same amount.
5. **A city's price covers its districts unless a district has its own** (owner, 2026-10-27 S14).
   A district's own price is not a second price for the same place: it is the more precise place.
6. **A carrier may leave places out** (owner, 2026-10-19 S10): a place it has no price for is a place
   it does not serve, and it is not offered there.
7. **What the customer sees is the fee for their order only** (owner, 2026-10-17). The price lists
   are staff-only.
8. **The fee carries no VAT** (owner, 2026-10-27 S18): 1,000 of goods + 150 VAT + 30 shipping = 1,180.
9. **The boxes are optional** (owner, 2026-10-17, 2026-10-27 N5): with no boxes, nothing changes and
   nothing waits; the store goes live without them.
10. **Admins manage carriers, prices, free shipping and boxes** (owner, 2026-10-27 S12, N3). The places
    and the countries a store ships to are Platform's, for Super Admins (§9.2).

### 1.2 Where an address stands — the coverage

Sales asks Shipping about **the store** and **the destination** (country, and when the country has a
place list: region, city and district — §2.1). The answer is one of four (owner, 2026-10-27, S10 as
restated):

| Coverage | When | At checkout (Sales) |
|---|---|---|
| `SERVED` | The country is one **this store** ships to, and at least one switched-on carrier has a price for the place | The carriers and their fees; the customer picks one when there are two or more, and sees the only one otherwise (owner, 2026-10-19 S3) |
| `NOT_COVERED` | The country is one this store ships to, but **no switched-on carrier** has a price for the place (a city, or a district, no carrier serves; a typed "Other" city, §9.2) | "We don't deliver to this place yet — our team will contact you about the shipping and the payment." The order can be placed; **no carrier, the fee to be confirmed by staff, and no online payment** |
| `OUTSIDE` | The country is one **no store of ours** ships to (Qatar in the KSA store, today) | The same as `NOT_COVERED`: placed, handled by staff, no online payment |
| `OTHER_STORE` | The country is one **another of our stores** ships to (an Egypt address in the KSA store) | Refused: "Addresses in Egypt are served by our Egypt store — order from there." With a link to that store |

- A country belongs to **one store at most** (owner, 2026-10-27 R3), so `OTHER_STORE` always names
  exactly one store. An off store's countries still count as that store's (**[PROPOSED]**): the
  customer is told the place is served by a store that is closed now, rather than being offered staff
  handling for a country we have a store for.
- **Never at address entry for `NOT_COVERED`** (owner, 2026-10-19 S10): the customer learns it at
  checkout. Whether `OTHER_STORE` is refused already when the address is saved is question §9.3 #1.
- Order of the checks: the country first (`OTHER_STORE`, `OUTSIDE`), then the place.

### 1.3 Carriers

A carrier has (owner, 2026-10-27 S11): **its name** in Arabic and English, **a logo** (Platform media,
public), **a phone**, **a website**, **a tracking link pattern** (optional), **on/off**, its **place in
the list** (drag and drop, as everywhere — catalog.md amendment 16), and **its country** — one of the
countries the store ships to, fixed once the carrier exists (**[PROPOSED]**: moving a carrier to another
country would leave every price pointing at places of the wrong country).

- **The tracking link pattern** is a web address with `{number}` where the tracking number goes
  (`https://carrier.example/track?n={number}`). Optional (owner, 2026-10-17). HTTPS only, at most 500
  characters, exactly one `{number}` (**[PROPOSED]**).
- **Switching a carrier off** takes it out of every new quote at once. Orders already placed keep the
  carrier and the fee they were placed with (owner, 2026-10-27 S16).
- **A carrier is never deleted, only switched off** (**[PROPOSED]**, forced by the dependency rule:
  Shipping cannot see Sales's orders, and an order already shipped with a carrier still needs its
  tracking link). A switched-off carrier stays readable through `carrier()` and `trackingLink()`.
- A store may have **no carrier**: every address in its countries is then `NOT_COVERED`.
- **The countries a store ships to** (Platform's, set by Super Admins — §9.2) — **[PROPOSED]**: the
  store's own country is always one of them; a country is added only once Platform has its place list
  (otherwise its carriers could never be priced, and every address there would stay `NOT_COVERED`); a
  country is removed, or moved to another store, only once the store has no carrier there (they are
  switched off first) — question §9.3 #9.

### 1.4 Prices — the groups

A carrier's prices are kept as **groups**: an **amount** and **the places** it applies to. Staff build
them as the owner described (2026-10-17):

1. Type the amount (in the store's currency, two decimals; 0 allowed = delivered for free there).
2. Pick **cities** — several at once, with **Select all** for the whole country's list — and/or
   **districts** of a city — several at once, with **Select all** for the whole city.
3. Confirm. The group is saved for that carrier.

The rules, checked on every save:

- **No place twice in the same carrier.** A city already in another group of this carrier, or a
  district already in another group, is refused and named ("Riyadh is already at 20 in this carrier").
  Moving a place is done by picking it in its new group: the screen offers **"Move here"**, which takes
  it out of its old group in the same save (owner, 2026-10-27 S13).
- **No amount twice in the same carrier.** Typing an amount another group of this carrier already has
  is refused with "This carrier already has a 20 group — add the places there" and a link to it. When a
  group's amount is changed to one that exists, the two groups are **merged** (owner, 2026-10-27 S13).
- **A city with a district list** may be priced as a whole (its districts follow it) and some of its
  districts priced on their own (rule 5). A district priced on its own while its city is not priced is
  served; the city's other districts are not.
- **A city with no district list** (customers type their district) is priced as a whole only (owner,
  2026-10-27 S15).
- **"Select all" means** (**[PROPOSED]**): for a city's districts, **the city priced whole** — so a
  district the Super Admin adds later is covered too; for the country's cities, **every city not yet
  priced in this carrier** — cities already in another group stay where they are and are shown with
  their own "Move here".
- **A city with a district list whose district is missing** (a new neighbourhood): the customer picks
  **"Other"** and types it — it takes the **city's whole price**, or is `NOT_COVERED` when the city is
  not priced whole (**[PROPOSED]**, question §9.3 #10).
- **A retired place** (Platform, §9.3 #4): it leaves every picker; **its prices stay and still apply**
  to the addresses that already use it, so nothing changes for a customer whose saved address names it.
- A group with no places left is deleted.
- **One save at a time per carrier**: every change of a carrier's prices locks the carrier first, so two
  staff saving at once never break rules 3 and 4 between them; the second is told to reload.
- Every change is **audited** (who, which carrier, which places, from what amount to what).

**The fee for an address** in one carrier: the district's own price if it has one, otherwise its city's
price, otherwise the carrier does not serve it.

### 1.5 Free shipping

(owner, 2026-10-27 S6, as recommended)

- **Each carrier may have its own free-above amount**: when the order's `goods_total` reaches it, that
  carrier's fee is 0. Empty = never free.
- **One store-wide rule can override all carriers**: a free-above amount for the store. While it is
  set, it applies to every carrier and the carriers' own amounts are ignored; cleared, each carrier's
  own amount applies again.
- It compares **`goods_total`** — after the coupon and the points, before shipping and VAT (handoff
  §10.2; sales.md §1.2 step 7).
- It replaces Sales's `sales.shipping.flat_fee` and `sales.shipping.free_above` (sales.md §1.10,
  amended — §9.2).
- Promotions' "free shipping above an amount" (promotions.md: "Shipping, stage 7") is this rule.

### 1.6 Uncovered places — what admins are warned about

(owner, 2026-10-19 S10; 2026-10-27, the four additions accepted)

- The carriers page shows, per country the store ships to, **"Places no carrier serves"**: every city
  with no price in any switched-on carrier (none of its districts either), and every district of a
  district-list city that is not served while its city is not priced as a whole. A count is shown on
  the menu (Platform's `MenuCount`) while the list is not empty (**[PROPOSED]**).
- An order placed for a `NOT_COVERED` or `OUTSIDE` address is marked **"Shipping fee to be set"** in
  Sales's order list (Sales amendment, §9.2), and **never auto-cancels** (it waits for no online
  payment).

### 1.7 Boxes — optional

(handoff §12.5; owner, 2026-10-17, 2026-10-19 S8, 2026-10-27 N5)

- A store's **box list**: name, inner length, width and height (mm), the most weight it takes (g), on/off.
  Admin-set **padding** per store (mm, added on every side; **[PROPOSED]** default 10).
- Each product size is **auto** or **manual** for packing **in each store** (**[PROPOSED]** default
  auto; per store, because a size is the same in every store but each store packs its own). Manual sizes
  skip the engine and the order is marked "pack by hand".
- **The engine only suggests** (owner, 2026-10-19 S8): for each order it picks the **smallest box**
  (by volume) whose inner sizes, less the padding, take every auto size of the order **one at a time
  checked on its own three sizes and their weight added up** — **no 3D bin packing** (handoff §12.5).
  None fits, or a size has no weight or size in Catalog → "pack by hand".
- **It never changes the price** (owner, 2026-10-19 S8: prices are by place only) and **never blocks**
  an order, a shipment or the store going live.
- With **no box in the list**, the suggestion is simply not shown.
- Whether the box part is built now or when the list arrives is question §9.3 #6.

---

## 2 · Public contract

The interface Sales declared (sales.md §2.4, in code since S7b — **provisional**, "stage 7 settles the
fields") is **changed** as below. Sales's callers change with it (Sales amendment, §9.2).

### 2.1 `Modules\Shipping\Public\Contracts\ShippingApi`

```php
interface ShippingApi
{
    /** Where an address stands for this store, and the carriers that serve it (§1.2). */
    public function quote(StoreId $store, ShippingDestinationDto $to, Money $goodsTotal): ShippingQuoteDto;

    /** One carrier as it is now — Sales snapshots it on the order. Null when it was deleted. */
    public function carrier(string $carrierId): ?CarrierDto;

    /** The store's carriers, switched-on ones first, for the staff's "mark shipped" form (§9.1 S17). */
    public function carriers(StoreId $store): array; // list<CarrierDto>

    /** The tracking link for a number, or null when the carrier has no pattern (§1.3). */
    public function trackingLink(string $carrierId, string $trackingNumber): ?string;

    /** The box suggestion for an order's sizes — null when the store has no boxes (§1.7). */
    public function suggestBox(StoreId $store, array $lines): ?BoxSuggestionDto; // list<ParcelLineDto>
}
```

- **`ShippingDestinationDto`**: `countryCode`, `regionId` (null), `cityId` (null), `districtId`
  (null), `cityText` (null), `districtText` (null) — the place's ids from Platform's list when the
  country has one; the typed text otherwise (owner, 2026-10-27 R4; §9.2). Never Access's address.
- **`ShippingQuoteDto`**: `coverage` (`SERVED`, `NOT_COVERED`, `OUTSIDE`, `OTHER_STORE`), `options`
  (list of `ShippingOptionDto`, empty unless `SERVED`), `otherStoreId` (only for `OTHER_STORE`).
- **`ShippingOptionDto`**: `carrierId`, `nameAr`, `nameEn`, `logoUrl` (null), `fee` (Money, free
  shipping applied), `freeBecause` (`CARRIER`, `STORE`, null). Sorted by fee, then the carrier's place in
  the list. (Replaces the provisional `carrierCode` and `trackingUrlTemplate`: the pattern is read when
  the order ships, through `trackingLink`.)
- **`CarrierDto`**: `id`, `storeId`, `countryCode`, `nameAr`, `nameEn`, `logoUrl`, `phone`, `website`,
  `hasTrackingPattern`, `active`.
- **`ParcelLineDto`** stays: `variantId`, `quantity`.
- **`BoxSuggestionDto`**: `boxId` (null when it must be packed by hand), `boxName`, `byHand` (bool),
  `reason` (`MANUAL_SIZE`, `NO_SIZE_FACTS`, `NOTHING_FITS`, null).

### 2.2 What Shipping needs from other modules

| From | What | State |
|---|---|---|
| Platform | **The places** — countries (the address list), regions, cities, districts, by id, in both languages — (including `citiesOf(country)` for the country-wide "Select all") and **each store's countries**
(`PlatformApi::storeCountries`, `countryStore(countryCode)`) | **New** — platform.md amendment (§9.2) |
| Platform | Stores, media (logos), settings, the audit log, `MenuCount` | On `main` |
| Catalog | `variant()` — weight and sizes, for the boxes only | On `main` |
| Access | Declaring Shipping's permissions; a **Shipping** permission group | Allowed; access.md amendment (§9.2) |

---

## 3 · Use cases

| Use case | Permission | Scope |
|---|---|---|
| `CreateCarrier`, `ChangeCarrier`, `SwitchCarrier` (on/off), `MoveCarrier` (order) — never deleted (§1.3) | **`shipping.carriers.manage`** — Admins (owner, 2026-10-27 S12) | That store |
| `SetPriceGroup` (create, change amount, add/move/remove places — §1.4), `DeletePriceGroup` | **`shipping.prices.manage`** — Admins | That store |
| `SetCarrierFreeAbove`, the store-wide free-above setting | **`shipping.prices.manage`** | That store |
| `ListCarriers`, `ViewCarrier` (its groups), `ListUncoveredPlaces` (§1.6) | **`shipping.carriers.view`** — staff who manage orders get it by default (**[PROPOSED]**) | That store |
| `CreateBox`, `ChangeBox`, `SwitchBox`, `DeleteBox`, the padding, a size's auto/manual | **`shipping.boxes.manage`** — Admins | That store |
| `Quote`, `TrackingLink`, `SuggestBox` | — (called by Sales inside its own permission) | — |

The permissions sit in a new **Shipping** group in the role editor (access.md amendment, §9.2). Every
change is audited, in both languages.

---

## 4 · State machines

- **A carrier**: `ON` ⇄ `OFF`; never deleted (§1.3).
- **A box**: `ON` ⇄ `OFF`.
- No other state: prices and groups are plain rows.

### 4.1 The admin screens (built by the screens session — owner, 2026-10-19)

Listed so the screens session has them; each gets its full detail (states, empty states, errors,
both languages, Geist/shadcn parts) in the screens spec round (owner's method, 2026-10-19).

| Screen | What |
|---|---|
| **Carriers** — `/admin/shipping/carriers?store=` | The store's carriers by country, drag to order, on/off; the "Places no carrier serves" panel (§1.6); the store-wide free-above amount |
| **A carrier** — `/admin/shipping/carriers/{id}` | Its details and logo; its **price groups** (amount, places as chips, edit, delete); "Add a price": amount, then the city picker (search, Select all) and, per city with districts, the district picker (search, Select all); "Move here" for places already priced (§1.4) |
| **Boxes** — `/admin/shipping/boxes?store=` | The box list and the padding (§1.7) |

---

## 5 · Tables

Schema `shipping`. Every store-scoped row names its store; money in the currency's smallest unit,
`bigint` (handoff §19).

| Table | Columns |
|---|---|
| `shipping.carriers` | `id` ULID PK · `store_id` FK `platform.stores` RESTRICT · `country_code` (one of the store's countries, checked by the handler) · `name_ar`, `name_en` (1–100) · `logo_media_id` NULL · `phone` NULL (E.164) · `website` NULL (HTTPS) · `tracking_pattern` NULL · `free_above_minor` NULL (≥ 0) · `active` bool · `position` int · `created_at`, `updated_at` · unique (`store_id`, `name_en`), (`store_id`, `name_ar`) |
| `shipping.price_groups` | `id` ULID PK · `carrier_id` FK CASCADE · `store_id` FK `platform.stores` RESTRICT · `amount_minor` bigint ≥ 0 · **unique (`carrier_id`, `amount_minor`)** (rule 4) · unique (`id`, `carrier_id`) for the places' composite key · `created_at`, `updated_at` |
| `shipping.price_places` | `id` bigint PK · `store_id` · (`group_id`, `carrier_id`) **composite FK** → `price_groups (id, carrier_id)` CASCADE, so the copied carrier can never disagree with its group · `city_id` (Platform's) · `district_id` NULL (Platform's) · **unique (`carrier_id`, `city_id`, `district_id`) NULLS NOT DISTINCT** (rule 3; PostgreSQL 17) |
| `shipping.boxes` | `id` ULID PK · `store_id` FK `platform.stores` RESTRICT · `name` · `length_mm`, `width_mm`, `height_mm`, `max_weight_g` int 1–1,000,000 · `active` · `position` |
| `shipping.variant_packing` | `store_id` · `variant_id` (Catalog's, no key) · PK (`store_id`, `variant_id`) — a row only for a size packed by hand in that store |

Settings (Platform, declared by Shipping): `shipping.free.above_all` (STORE, integer smallest unit, 0
= off, 0–1,000,000,000, `shipping.prices.manage`) · `shipping.boxes.padding_mm` (STORE, 0–100,
default 10, `shipping.boxes.manage`).

---

## 6 · Events

| Consumed | From | Shipping |
|---|---|---|
| `StoreCreated` | Platform | Nothing to create: a new store has no carriers |
| — | — | A retired place needs no event: Shipping reads Platform's `retired` flag when it builds its pickers (§1.4) |

Shipping publishes **no integration event**: Sales snapshots what it needs at placement.

---

## 7 · Errors

Every error extends `ShippingError` → `DomainError` ("shipping.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `CarrierNotFound` | NOT_FOUND | A carrier that is not there (or of another store) |
| `CountryNotShipped` | INVALID | A carrier for a country the store does not ship to |
| `CountryHasCarriers` | CONFLICT | Removing a ship-to country, or moving it to another store, while the store has carriers there (Platform asks Shipping through a hook it declares — §9.2) |
| `CountryWithoutPlaces` | CONFLICT | Adding a ship-to country Platform has no place list for (§1.3) |
| `PlaceAlreadyPriced` | CONFLICT | A place already in another group of this carrier (§1.4) — names the place and its amount |
| `AmountAlreadyUsed` | CONFLICT | A second group with the same amount in one carrier — names the group |
| `PlaceNotInCountry` | INVALID | A city or district not in the carrier's country, or a district of another city |
| `CityPricedWhole` | INVALID | A district of a city with no district list (§1.4) |
| `InvalidShippingInput` | INVALID | A field out of range — named by its field and rule (`tracking_pattern_number`, `https`, …) |

---

## 8 · Test scenarios

1. **Coverage**: each of the four answers (§1.2); an off store's country (`OTHER_STORE`); a store with
   no carrier (`NOT_COVERED`); a switched-off carrier never offered; a country no store ships to
   (`OUTSIDE`).
2. **Fees**: the district's own price beats the city's; a district-list city priced whole covers every
   district; a district not priced while the city is not → not served; a typed district → the city's
   price; 0 is a valid price.
3. **The two rules**: the same place twice in a carrier refused (and allowed in two carriers); the same
   amount twice refused; "Move here" moves in one save; changing an amount to an existing one merges;
   an emptied group deleted. Two staff saving the same carrier at once: one wins, the other is told
   (a lock per carrier).
4. **Free shipping**: a carrier's own amount; the store-wide amount overriding every carrier, then
   cleared; compared with `goods_total`, the boundary included (equal is free).
5. **No VAT on the fee**: 1,000 + 150 + 30 = 1,180 (owner's own example, through Sales and Pricing).
6. **Snapshots**: a price changed or a carrier switched off after placement — the order keeps its
   carrier and fee; a switched-off carrier's tracking link still works.
7. **Tracking**: a pattern with one `{number}`; none → no link; a number with spaces trimmed and the
   link encoded.
8. **Boxes**: smallest fit; padding; a manual size → by hand; missing weight → by hand; no boxes → no
   suggestion; never a price change.
9. **Uncovered list**: a city added to Platform's list shows up until a carrier prices it.
10. **Isolation and permissions**: two stores — nothing crosses; each use case only with its
    permission in that store; every change audited.

---

## 9 · Questions

### 9.1 The owner's answers this spec is built on

| # | Answer | Date |
|---|---|---|
| — | Tracking link optional; "Track" shown only when there is one | 2026-10-17 |
| — | Boxes optional, never blocking; the list sent later | 2026-10-17 |
| — | Carriers entered by admins: logo, info, prices; prices by city and district, multi-select with Select all; no place with two prices; one amount = one group per carrier; customers see only their fee; live carrier APIs maybe later | 2026-10-17 |
| S1 | The customer picks the city (and district, when the city has a list) from a list — no typing where a list exists | 2026-10-19 |
| S3 | The customer picks a carrier only when two or more serve the place | 2026-10-19 |
| S4, S5 | Price by place only; each carrier belongs to one store | 2026-10-19 |
| S6 | Free-above per carrier, with one store-wide rule overriding all | 2026-10-27 |
| S7 | A carrier's link pattern and/or a full link typed by staff | 2026-10-19 |
| S8 | The box only suggests | 2026-10-19 |
| S9 | I prepare the city lists; the owner reviews; then they are seeded | 2026-10-19 |
| S10 | Carriers may skip places; admins warned; the customer told at checkout, the order placed and handled by staff, no online payment — with the four additions | 2026-10-19, 2026-10-27 |
| S11–S17, S20 | As recommended (fields, admins, merge, district over city, typed district → city, snapshots, staff may change carrier, return shipping not recorded) | 2026-10-27 |
| S18 | **No VAT on shipping** | 2026-10-27 |
| C1–C6, R1–R4 | Districts seeded; "Other" city → not served; Super Admin may edit the lists, seeded first; the customer picks the region; KSA and Egypt now, UAE later; reset the test addresses; a Super Admin list of address countries; each store's ship-to countries; a country in one store only; free text in a country no store serves | 2026-10-19, 2026-10-27 |

### 9.2 What other modules must change (written as their amendments in this PR)

1. **Platform** — the places (countries for addresses, regions, cities, districts; seeded; Super
   Admins edit; never deleted, retired) and each store's ship-to countries (a country in one store
   only). `PlatformApi` reads for them.
2. **Access** — the address form: the country from Platform's address list; the region, city and
   district picked from Platform's list when the country has one, typed otherwise; "Other" city;
   `AddressDto` carries the ids. A **Shipping** permission group.
3. **Sales** — step 9 of the quote calls `ShippingApi::quote`; the carrier chosen and snapshotted; the
   `NOT_COVERED`/`OUTSIDE` order ("fee to be set", staff only payment, never auto-cancelled, staff set the
   fee later); `OTHER_STORE` refused; the flat fee and free-above settings removed; marking shipped picks
   the carrier from `carriers()` (or changes it), with a number and/or a typed link; **no VAT on
   shipping** in the totals and the returns' refunds.
4. **Pricing** — `totals()`: shipping is outside the taxable base.
5. **The handoff** — §4.4: Shipping → Access and Payments → Access "only to declare permissions", for
   the owner (as Catalog, Pricing, Inventory and Sync were allowed); §10.2: shipping outside the VAT;
   §12.5: "domestic only per store" becomes "a store ships to its countries"; the free-above amount per
   carrier with a store-wide override (§1.5).
6. **Promotions** — nothing to change: its "free shipping above an amount" already names Shipping,
   stage 7; it is §1.5.

### 9.3 Still open — for the owner

1. **`OTHER_STORE` at address entry or at checkout?** (a) Refused **when the address is saved**: "This
   country is served by our Egypt store" with a link — the customer never builds a cart for an address
   that cannot be used. **[Recommended]** (b) Saved, and refused only at checkout.
2. **The carrier picked by default** when two or more serve the place: (a) the cheapest picked, the
   customer may change it **[Recommended]**; (b) none picked, the customer must choose.
3. **Uncovered places count on the menu** (§1.6): (a) yes, a count beside Shipping while the list is not
   empty **[Recommended]**; (b) only on the carriers page.
4. **A place the Super Admin removes from Platform's list**: (a) never deleted — **retired**: hidden from
   new addresses and from price pickers; addresses and prices that use it keep it **[Recommended]**;
   (b) deleted, and refused while anything uses it.
5. **The carrier's country is fixed once created** (§1.3): (a) yes **[Recommended]**; (b) it may change,
   and its prices are cleared.
6. **Build the box part now or when the list arrives?** (a) Now, dormant until boxes are entered — the
   screens and the engine are small **[Recommended]**; (b) later, when the list arrives.
7. **A company's order to a `NOT_COVERED` or `OUTSIDE` address**: the fee is not known at placement, so
   the transfer amount is not either. (a) It becomes "staff will contact you" like an individual's,
   staff set the fee, then the company transfers **[Recommended]**; (b) the company may still choose
   bank transfer and waits for the fee before transferring.
8. **What a "fee to be set" order shows**: (a) "Shipping: to be confirmed" and the total marked "before
   shipping" — on the checkout, the order page and staff's list **[Recommended]**; (b) the total with 0
   shipping.
9. **A ship-to country removed or moved to another store** (§1.3): (a) refused while the store has a
   carrier there — switch them off first; saved addresses stay and become `OUTSIDE` or `OTHER_STORE`
   by the rules **[Recommended]**; (b) its carriers switched off automatically.
10. **"Other" district** in a city that has a district list (§1.4): (a) allowed, typed, priced by the
    city's whole price if it has one, otherwise `NOT_COVERED` **[Recommended]**; (b) not allowed —
    the customer must pick a listed district.
11. **Free shipping on a fee staff set later** (a `NOT_COVERED` / `OUTSIDE` order): (a) no — the staff's
    fee is final, they apply any free shipping themselves **[Recommended]**; (b) the store-wide rule
    still applies.
12. **An order's address changed by staff after placing** (sales.md §1.7): (a) **re-quoted at today's
    prices** for the new place — a place no carrier serves makes it "fee to be set" **[Recommended]**;
    (b) the fee placed with is kept.
13. **VAT on an order shipped abroad** (`OUTSIDE`, e.g. Qatar from the KSA store — exports are often
    0-rated): for the owner's accountant. (a) The store's VAT as usual until the accountant says
    otherwise **[Recommended]**; (b) 0 VAT on goods for an address outside the store's country.
