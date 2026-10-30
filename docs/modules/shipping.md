# Shipping — module specification

**Status (2026-10-27): draft for the owner.** Shipping answers one question for Sales: *can this
store take this order to this address, with which carriers, for how much?* It holds each store's
carriers and their prices by place, the free-shipping amounts, the tracking links, and the optional
box list. It is built from handoff §12.5, the Sales contract that waits for it (sales.md §2.4, in code
since Sales step S7b), and the owner's answers of 2026-10-17, 2026-10-19 and 2026-10-27 (§9.1). Points
marked **[ACCEPTED 2026-10-28]** were mine; **the owner accepted every one of them on 2026-10-28** ("accept rec for
other shippings"), except the carrier's deletion and the padding, which the owner set (§1.3, §1.7).

**Dependencies (handoff §4.4):** Shipping → Platform, Catalog. **[ACCEPTED 2026-10-28]** Shipping → Access **only
to declare its permissions**, as the owner allowed Catalog, Pricing, Inventory and Sync (handoff §4.4,
2026-10-02/07) — allowed for Shipping by the owner on 2026-10-28 (handoff §4.4). Sales
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
2. **Prices are by place only** (owner, 2026-10-19 S4): a region, a city, or a district of a city (a
   whole region, in every country — owner, 2026-10-29). Never by weight, never by the number of pieces.
3. **One place, one price per carrier** (owner, 2026-10-17): a place never holds two prices in the same
   carrier.
4. **One amount, one group per carrier** (owner, 2026-10-17): every place a carrier charges 20 for is in
   that carrier's one "20" group. Two groups of the same carrier never have the same amount.
5. **A place's price covers what is under it unless that has its own** (owner, 2026-10-27 S14;
   2026-10-29): **the most specific place wins — the district, then the city, then the region**. A
   district's own price is not a second price for the same place: it is the more precise place.
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
| `NOT_COVERED` | The country is one this store ships to, but **no switched-on carrier** has a price for the place, nor for one above it (a region, a city or a district no carrier serves; a typed city in a region no carrier prices — §1.4) | "We don't deliver to this place yet — our team will contact you about the shipping and the payment." The order can be placed; **no carrier, the fee to be confirmed by staff, and no online payment** |
| `OUTSIDE` | The country is one **no store of ours** ships to (Qatar in the KSA store, today) | The same as `NOT_COVERED`: placed, handled by staff, no online payment |
| `OTHER_STORE` | The country is one **another of our stores** ships to (an Egypt address in the KSA store) | Refused: "Addresses in Egypt are served by our Egypt store — order from there." With a link to that store |

- A country belongs to **one store at most** (owner, 2026-10-27 R3), so `OTHER_STORE` always names
  exactly one store. An off store's countries still count as that store's (**[ACCEPTED 2026-10-28]**): the
  customer is told the place is served by a store that is closed now, rather than being offered staff
  handling for a country we have a store for.
- **Never at address entry for `NOT_COVERED`** (owner, 2026-10-19 S10): the customer learns it at
  checkout. **`OTHER_STORE` is refused already when the address is saved** (owner, 2026-10-27, §9.3 #1), and
  again at checkout for an address saved before this rule.
- Order of the checks: the country first (`OTHER_STORE`, `OUTSIDE`), then the place.

### 1.3 Carriers

A carrier has (owner, 2026-10-27 S11): **its name** in Arabic and English, **a logo** (Platform media,
public), **a phone**, **a website**, **a tracking link pattern** (optional), **on/off**, its **place in
the list** (drag and drop, as everywhere — catalog.md amendment 16), and **its country** — one of the
countries the store ships to, fixed once the carrier exists (**[ACCEPTED 2026-10-28]**: moving a carrier to another
country would leave every price pointing at places of the wrong country).

- **The tracking link pattern** is a web address with `{number}` where the tracking number goes
  (`https://carrier.example/track?n={number}`). Optional (owner, 2026-10-17). HTTPS only, at most 500
  characters, exactly one `{number}` (**[ACCEPTED 2026-10-28]**).
- **Switching a carrier off** takes it out of every new quote at once. Orders already placed keep the
  carrier and the fee they were placed with (owner, 2026-10-27 S16).
- **A carrier is deleted only while it is switched off** (owner, 2026-10-28: "switch then remove").
  Deleting removes its price groups too, and is audited. **Old orders never need it**: Sales keeps on
  the order its own copy of the carrier's names (at placing) and **the full tracking link** (made from
  the pattern, or typed, when the order is marked shipped — sales.md §9.5 #11), so Shipping, which
  cannot see Sales's orders, never has to ask whether an order still names the carrier.
- A store may have **no carrier**: every address in its countries is then `NOT_COVERED`.
- **The countries a store ships to** (Platform's, set by Super Admins — §9.2) — **[ACCEPTED 2026-10-28]**: the
  store's own country is always one of them; a country is added only once Platform has its place list —
  a region in use (owner, 2026-10-29) — otherwise its carriers could never be priced, and every address
  there would stay `NOT_COVERED`; a
  country is removed, or moved to another store, only once the store has no carrier there (they are
  switched off first) (owner, 2026-10-27, §9.3 #9).

### 1.4 Prices — the groups

A carrier's prices are kept as **groups**: an **amount** and **the places** it applies to. Staff build
them as the owner described (2026-10-17):

1. Type the amount (in the store's currency, two decimals; 0 allowed = delivered for free there; at most
   1,000,000.00 — 100,000,000 in the smallest unit, gap 4.4).
2. Pick **regions**, **cities** — several at once, with **Select all** for the whole country's list —
   and/or **districts** of a city — several at once, with **Select all** for the whole city. **A whole
   region** may be picked in every country (owner, 2026-10-29, built in step 4): where a country's list
   stops at its regions (the UAE's emirates, Qatar's municipalities, Bahrain's governorates — the
   customer types the city), the region is what a carrier is priced by.
3. Confirm. The group is saved for that carrier (`SavePriceGroup`, at most 10,000 places a save).

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
- **"Select all" means** (**[ACCEPTED 2026-10-28]**; regions, owner 2026-10-29, gap 4.2): for a city's districts,
  **the city priced whole** — so a district the Super Admin adds later is covered too; for a region's
  cities, **the region priced whole**, likewise; for the country, **every city not yet priced in this
  carrier, nor under a region it prices** — cities already in another group stay where they are and are
  shown with their own "Move here" — or, **where the country has no city** (the UAE, Qatar, Bahrain),
  **every region not yet priced**.
- **A typed city or district** — the customer's own "Other" in Saudi Arabia or Egypt, and every city in
  the UAE, Qatar and Bahrain: it takes the price of the place above it — **"Other" district: the city's
  price, else the region's; a typed city: the region's** — or is `NOT_COVERED` when nothing above it is
  priced (owner, 2026-10-27, §9.3 #10; 2026-10-29, gap 4.1).
- **A retired place** (Platform; owner, 2026-10-27, §9.3 #4): it leaves every picker; **its prices stay and still apply**
  to the addresses that already use it, so nothing changes for a customer whose saved address names it.
  It may stay in the group that has it when that group is saved again; it cannot be added anew.
- A group with no places left is deleted.
- **One save at a time per carrier**: every change of a carrier's prices locks the carrier first, so two
  staff saving at once never break rules 3 and 4 between them; the second is told to reload: a save or a delete sends back the version of the carrier's prices it read (`ListPriceGroups`), and is refused with `PricesChanged` when another came first.
- Every change is **audited** on the carrier (`shipping.carrier.prices_changed`): who, which carrier, and
  for each amount that changed ("price 20.00") the places that left it and the places that came, named in English — the first
  30 and how many more.

**The fee for an address** in one carrier: the district's own price if it has one, otherwise its city's,
otherwise its region's, otherwise the carrier does not serve it. The address's city and region are read
from Platform from its most specific place, never taken from the caller.

### 1.5 Free shipping

(owner, 2026-10-27 S6, as recommended)

- **Each carrier may have its own free-above amount** (`SetCarrierFreeAbove`, 0–1,000,000,000 in the
  smallest unit): when the order's `goods_total` reaches it — equal included — that carrier's fee is 0.
  Empty = never free. A price that is 0 already is not "free because" (`freeBecause` null).
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

- The carriers page shows, per country the store ships to, **"Places no carrier serves"**
  (`ListUncoveredPlaces`): **the biggest places no switched-on carrier serves any part of** (owner,
  2026-10-29, gap 4.3) — a region with nothing in it served is one row; a city is a row only when its
  region is partly served; a district only when its city is partly served. Before any price, Saudi
  Arabia shows its 13 regions, not about 8,000 places. Read live.
- A count of those rows is shown on the menu (`UncoveredPlacesCount`, Platform's `MenuCount`, on the
  Carriers entry) while the list is not empty (owner, 2026-10-27, §9.3 #3). It is kept per store
  (`shipping.uncovered_counts`) and counted again after every change of the store's carriers or prices,
  and every ten minutes for every store — a place Platform adds or retires, or a ship-to country added,
  tells Shipping nothing (§6) — so the count follows the list within ten minutes.
- An order placed for a `NOT_COVERED` or `OUTSIDE` address is marked **"Shipping fee to be set"** in
  Sales's order list (Sales amendment, §9.2), and **never auto-cancels** (it waits for no online
  payment).

### 1.7 Boxes — optional

(handoff §12.5; owner, 2026-10-17, 2026-10-19 S8, 2026-10-27 N5)

- A store's **box list**: name, inner length, width and height (mm), the most weight it takes (g), on/off.
  **The padding** is a store setting (mm, added on every side, default 10 — `shipping.boxes.padding_mm`),
  **changed in the panel by admins and staff** (owner, 2026-10-28) — whoever holds `shipping.boxes.manage`.
- Each product size is **auto** or **manual** for packing **in each store** (**[ACCEPTED 2026-10-28]** default
  auto; per store, because a size is the same in every store but each store packs its own). Manual sizes
  skip the engine and the order is marked "pack by hand".
- **The engine only suggests** (owner, 2026-10-19 S8): for each order it picks the **smallest box**
  (by volume) whose inner sizes, less the padding, take every auto size of the order **one at a time
  checked on its own three sizes and their weight added up** — **no 3D bin packing** (handoff §12.5).
  None fits, or a size has no weight or size in Catalog → "pack by hand".
- **It never changes the price** (owner, 2026-10-19 S8: prices are by place only) and **never blocks**
  an order, a shipment or the store going live.
- With **no box in the list**, the suggestion is simply not shown.
- **The box part is built now** and stays unused until boxes are entered (owner, 2026-10-27, §9.3 #6).

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
- Built in step 4: `quote`, `carrier`, `carriers`, `trackingLink` and their DTOs, with the enums
  `ShippingCoverage` and `FreeShippingReason`; `suggestBox` and `BoxSuggestionDto` join in step 5. A
  logo is the media's thumbnail, best format first; none until its sizes are ready.
- **`ParcelLineDto`** stays: `variantId`, `quantity`.
- **`BoxSuggestionDto`**: `boxId` (null when it must be packed by hand), `boxName`, `byHand` (bool),
  `reason` (`MANUAL_SIZE`, `NO_SIZE_FACTS`, `NOTHING_FITS`, null).

### 2.2 What Shipping needs from other modules

| From | What | State |
|---|---|---|
| Platform | **The places** — countries (the address list), regions, cities, districts, by id, in both languages — (including `placesOf(country)` — every place in use, one read — for the country-wide "Select all" and the uncovered places, step 4) and **each store's countries**
(`PlatformApi::storeCountries`, `countryStore(countryCode)`) | **New** — platform.md amendment (§9.2) |
| Platform | Stores, media (logos), settings, the audit log, `MenuCount` | On `main` |
| Catalog | `variant()` — weight and sizes, for the boxes only | On `main` |
| Access | Declaring Shipping's permissions; a **Shipping** permission group | Allowed; access.md amendment (§9.2) |

---

## 3 · Use cases

| Use case | Permission | Scope |
|---|---|---|
| `CreateCarrier`, `ChangeCarrier`, `SwitchCarrier` (on/off), `OrderCarriers` (order within a country — §9.4 #5), `DeleteCarrier` (only when off — §1.3) | **`shipping.carriers.manage`** — Admins (owner, 2026-10-27 S12) | That store |
| `SavePriceGroup` (create, change amount, add/move/remove places, the country's Select all — §1.4), `DeletePriceGroup` | **`shipping.prices.manage`** — Admins | That store |
| `SetCarrierFreeAbove`, the store-wide free-above setting | **`shipping.prices.manage`** | That store |
| `ListCarriers`, `ViewCarrier`, `ListPriceGroups` (a carrier's groups, step 4), `ListUncoveredPlaces` (§1.6) | **`shipping.carriers.view`** — a plain role permission, ticked per role (owner, 2026-10-29, §9.4 #1) | That store |
| `CreateBox`, `ChangeBox`, `SwitchBox`, `DeleteBox`, the padding, a size's auto/manual | **`shipping.boxes.manage`** — Admins by default, and any staff role it is given to (owner, 2026-10-28: admins and staff) | That store |
| `Quote`, `TrackingLink`, `SuggestBox` | — (called by Sales inside its own permission) | — |

The permissions sit in a new **Shipping** group in the role editor (access.md amendment, §9.2). Every
change is audited, in both languages.

---

## 4 · State machines

- **A carrier**: `ON` ⇄ `OFF`; deleted only from `OFF` (§1.3).
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

#### Part 1 — the carriers (screens round 1, 2026-10-29) — **[ACCEPTED by the owner, 2026-10-29]**

Written by the screens session on the owner's word of 2026-10-29 ("start the Shipping screens spec now: the
carriers part and its questions; price groups and boxes when steps 4 and 5 merge"). It uses only what step 3
built (§9.4; merged 1cbaea2d). Its questions are §9.5; option **(a)** is the recommended one in each, and the
Q numbers below point at them. **Part 2** (price groups, free shipping, places no carrier serves — step 4) and
**Part 3** (boxes, the padding — step 5) are written when those steps merge.

**What every screen keeps** — as every panel screen: Geist's rules on shadcn's code (frontend.md §1.11),
nothing built that either has (`PanelDialog`, `DestructiveActionDialog`, `Empty`, `Badge`, `Switch`, `RowMenu`,
`ActionButton` with its disabled reason, Catalog's `SortableList` for dragging), every box checking itself as
it is typed with the server's own rules (names 1–100 characters in each language, both required; phone E.164;
website and tracking link HTTPS, at most 500 characters, the link with exactly one `{number}`), digits made
Latin as typed, names in the page's language first and the other language under them, every page at a phone's
width with nothing scrolling sideways, both languages, and **each refusal in its own words** (the six errors of
step 3, already worded in Shipping's `lang/{ar,en}/errors.php`). The store is the panel's store filter
(`?store=`, frontend.md §2.2), as on every store-scoped page. A button the item's state stops is **shown out
of reach, with the reason** (Feedback's rule); a button the person's permissions do not allow is **not shown**
(Q12). Every change is audited by step 3; the audit log shows it.

##### C1 · Carriers — `/admin/shipping/carriers?store=` (Q1, Q2, Q11, Q16)

**The menu**: an entry **Carriers** in the **Shipping** group (access.md, stage 7 step 2: Shipping and Payments
groups after Orders), offered for `shipping.carriers.view` (Q1, Q16).

**The page** — one section per country the store ships to, **the store's own country first**, then by name
(as `ListCarriers` returns them) (Q2):
- the section's heading: the country's name, **Own** on the store's own country;
- **Add Carrier** in each section (Q3) — the carrier's country is the section's and is fixed once it exists
  (§1.3);
- its carriers, in their order, as a table (a card each on a phone): the **drag handle**, the **logo** (or the
  carrier's initials when it has none — Q15), the **name** in the page's language and the other under it, the
  **phone** and the **website** (a link, opening in a new tab), **Tracking link** or **No tracking link**,
  the **on/off switch** (Q5), and the row menu: **Edit**, **Move to Top**, **Move to Bottom**, **Delete** (Q6,
  Q7);
- an off carrier keeps its place in the order, greyed, marked **Off**;
- **a country with no carrier** (Q11): `Empty` — "No carriers in :country yet. Until one is added and priced,
  orders to :country are handled by staff: no carrier, the fee confirmed later, no online payment." with Add
  Carrier.

**Order** (Q7): dragged by the handle within its country, saved when dropped (`OrderCarriers`, the country's
full order); **Move to Top / Move to Bottom** in the row menu do the same without dragging (catalog.md
amendment 16, as everywhere). A carrier never moves to another country.

**On/off** (Q5): the switch in the row. **Switching off** asks first — "Switch off ":carrier"? It leaves every
new checkout at once. Orders already placed keep it and their fee." (`PanelDialog`); **switching on** asks
nothing. A toast says which it did.

**Delete** (Q6): in the row menu; **out of reach while the carrier is on**, with the reason "Switch it off
first". Off: `DestructiveActionDialog` — "Delete ":carrier"? Its prices go with it. Orders already placed keep
its name and their tracking links. This can't be undone." A refusal the page could not foresee (switched on by
someone else meanwhile: `CarrierStillOn`) comes back in its own words.

**Part 2 adds to this page**: the store-wide free-above amount, each carrier's free-above amount, and
**Places no carrier serves** per country (§1.5, §1.6).

##### C2 · Add Carrier / Edit Carrier — a dialog (Q3, Q8, Q9, Q10)

A `PanelDialog`, loaded when it is first opened (its own file, so C1 stays within its page budget — frontend.md
§5), as Catalog's brand dialog:
- **Country** — shown, not editable: the section's on Add, the carrier's on Edit (§1.3);
- **Arabic name**, **English name** — both required, 1–100 characters; a name another carrier of the store
  uses, ignoring case, is refused under the box (`CarrierNameTaken`, naming it — §9.4 #4);
- **Logo** (Q10) — optional: pick an image (the media library's rules: type and size, as a brand logo),
  shown as it will look, with **Remove**; uploaded when the dialog is saved (`UploadCarrierLogo`, then its
  media id with the carrier);
- **Phone** — optional, the panel's phone box as on the staff account page (E.164, Latin digits) (Q9);
- **Website** — optional, `https://` only, at most 500 characters;
- **Tracking link** — optional (Q8): a web address with `{number}` where the tracking number goes; under it,
  as it is typed, "A tracking number 12345 opens: https://…12345" — or what is wrong (not https, no
  `{number}`, two of them, too long);
- on Add only, **Switched on** — on at first (`CreateCarrier`'s `active`);
- **Save** / **Cancel**; a server refusal is shown at the top of the dialog in its own words and the boxes
  keep what was typed.

##### C3 · A carrier — `/admin/shipping/carriers/{id}?store=` (Q4)

Opened from the carrier's name on C1. The trail **Carriers › :carrier**; its header: logo, names, country,
**On/Off** with the switch, **Edit** (C2) and the row menu's **Delete**. Its details card: phone, website,
tracking link (with the example above). **Part 2 fills the rest of this page with the carrier's price groups**
(§4.1's table: amount, places as chips, Add a price, Move here); until then, a note: "Prices come with the next
step of the shipping screens." — **not shown in production**: part 2 merges before the store goes live (Q4).
A carrier of another store, or none, is the panel's not-found page.

##### The ship-to refusal on the Stores page (Q14)

Removing a country from a store's ship-to list while the store has carriers there is refused by Platform with
`CountryHasCarriers` (§9.4 #3: on or off). The remove dialog already shows a refusal inside it, in its own
words (`PanelDialog`'s `DialogError`). Added under it: a link **Open the carriers of :country** to C1 for that
store, where they are switched off and deleted (Q14).

##### What these screens need from the backend

Everything is in step 3's Application layer (§9.4): `ListCarriers`, `ViewCarrier`, `CreateCarrier`,
`ChangeCarrier`, `SwitchCarrier`, `OrderCarriers`, `DeleteCarrier`, `UploadCarrierLogo`. The routes,
controllers, page resources and words are the screens session's, in Shipping's Presentation layer (Q13). The
logo's address is read from Platform's media (`PlatformApi`) in Presentation, as Catalog's brand list does —
**confirmed by the backend session, 2026-10-29**: `CarrierRow` stays as it is (deptrac already allows it).

#### Part 2 — prices, free shipping, places no carrier serves (screens round 2, 2026-10-30) — **[DRAFT for the owner]**

Written by the screens session once step 4 merged (658b4c01, #282), on the owner's word of 2026-10-29 ("add
price groups and boxes when steps 4 and 5 merge"). It uses only what step 4 built (§1.4–§1.6, §9.6). Its
questions are §9.7; option **(a)** is the recommended one in each, and the R numbers below point at them.
**Part 3** (boxes, the padding — step 5) is written when step 5 merges.

**What every part keeps** — part 1's rules hold here too (Geist on shadcn, checks as typed, Latin digits,
both languages, phone width, each refusal in its own words — step 4's five new errors are already worded in
Shipping's `lang/{ar,en}/errors.php`). **Amounts** are typed and shown in the store's currency with its
decimals (two for SAR and EGP), as the Pricing screens' price box does (R15): a price 0–1,000,000.00, a
free-above 0–10,000,000.00 (§9.6 #5); a price of 0 reads **Free**. Prices are changed by whoever holds
`shipping.prices.manage` (Admins, §3); everyone who may open the carriers screens sees them read-only, with
no button they cannot use (R14, as part 1's Q12).

##### C3 addition · A carrier's prices (R1–R10)

Part 1's note "Prices come with the next step" gives way to two cards under the carrier's details (R1):

**Prices** — the carrier's groups (`ListPriceGroups`), cheapest first:
- each group a row: its **amount** (or **Free**), its **places** as chips, each named with what it is under
  when that helps ("Olaya · Riyadh", "Riyadh · Riyadh Region") and a **Retired** mark on a retired one
  (R16); a group with many places shows its first 12 chips and **+:count more**, opening the rest (R9);
  **Edit** and **Delete** for a price manager;
- **Add Price** at the top; empty: "No prices yet. Until this carrier has a price for a place, it isn't
  offered there." with Add Price.

**Add Price / Edit Price** — a wide dialog (a sheet on a phone) (R2):
- **Amount** — the price box; on Edit, changing it to an amount another group of this carrier already has
  says under the box, before saving: "This joins the :amount price: its places and these become one
  group." (R7). On Add, the same amount is refused by the server (`AmountAlreadyUsed`), in its own words,
  with **Open the :amount price**, which opens that group's Edit instead.
- **Places** — the place picker, one level at a time with a trail (R3), as the places pages are:
  **:country › :region › :city**. At the country's level, its regions; opening a region, its cities;
  opening a city, its districts. Each row has a tick box: ticking a region prices it whole (its cities and
  districts follow it); ticking a city prices it whole (its districts follow it).
  - A **filter box** narrows the open level as it is typed, in either language (R4).
  - **Select all** on the country's level: every place not yet priced in this carrier (`allUnpriced` —
    every city not yet priced nor under a priced region, or every unpriced region where the country has no
    city, §1.4) (R6). On a region's or a city's level, **Select all** ticks the region or the city itself —
    priced whole — rather than every row under it (§1.4 "Select all means").
  - A place **in another group** of this carrier shows **At :amount** and stays unticked; ticking it asks
    **Move here?** under its name — taking it out of its old group in the same save (`moveIds`) (R5).
  - A place **covered by one above it** — a city whose region is in a group, a district whose city is —
    shows **Covered by :place at :amount**: ticking it gives it its own price, the more precise place
    winning (rule 5).
  - A **retired** place is not offered (step 1's pickers): one already in the group stays as a chip, with
    its ✕ to take it out (R16).
  - Under the picker: **:count places in this price** and the chips of what is ticked, each with ✕.
- **Save Price** / **Cancel**; a refusal is said at the top of the dialog in its own words, what was ticked
  kept. `PlaceAlreadyPriced` names the place and its group ("Riyadh is already at 20.00 in this carrier"),
  ticked meanwhile by someone else.

**Delete Price** — a dialog saying what follows (R8): "Delete the :amount price? Its :count places stop
being served by :carrier, unless another of its prices covers them." Deleting it asks no typed name: a
price is re-made in a minute, and nothing outside the carrier names it.

**Someone else changed the prices meanwhile** (R10): every save and delete sends the version the page read;
`PricesChanged` comes back as "The prices changed while you were editing. Reload to see them." with
**Reload**, which reads the page again (what was ticked is lost — the list it was ticked against is gone).

**Free Shipping** — a card under Prices (R11): **Free above** — the carrier's own amount, the price box with
**Save** and **Clear** ("never free"); `SetCarrierFreeAbove`. While the store-wide amount is set (below), the
card says "The store's :amount for every carrier applies now; this amount is kept for when it is cleared."
and the box is shown, not hidden. C1's rows show **Free above :amount** under the carrier's name when one
applies.

##### C1 additions · The store-wide amount and the places no carrier serves (R12, R13)

**Free shipping for every carrier** — a card at the top of C1 (R12): "Orders of :amount or more ship free
with every carrier." with **Edit** (a small dialog: the price box, **Save**, **Turn Off**), or "Off — each
carrier's own amount applies." It is Platform's store setting `shipping.free.above_all` (0 = off), saved
through `UpdateSetting` under `shipping.prices.manage`. The Settings page lists every setting, this one
too, as a plain number in the currency's smallest unit (halalas) — see R12.

**Places no carrier serves** — in each country's section on C1, under its carriers (R13): the biggest places
nothing switched on serves (`ListUncoveredPlaces`, live): a whole region, else a city, else a district,
each named with what it is under. Its heading carries the count; the first 10 are shown, with **Show all**.
It says what that means: "Orders to these places are handled by staff: no carrier, the fee confirmed later,
no online payment." None: "Every place in :country is served." The menu's count on Carriers is
`UncoveredPlacesCount` (§1.6), kept by the backend.

##### What these screens need from the backend

Everything is in step 4's Application layer: `ListPriceGroups`, `SavePriceGroup`, `DeletePriceGroup`,
`SetCarrierFreeAbove`, `ListUncoveredPlaces`; the store-wide amount through Platform's `UpdateSetting`. The
picker's levels are read in Shipping's Presentation layer from Platform's own picker reads
(`PlatformApi::regions`, `cities`, `districts` — nothing retired), one level at a time, as the address
form's (frontend.md §3.6, F9) — no new Application read. Which group a listed place is in, or what covers it,
is worked out on the page from the carrier's groups it already has.

---

## 5 · Tables

Schema `shipping`. Every store-scoped row names its store; money in the currency's smallest unit,
`bigint` (handoff §19).

| Table | Columns |
|---|---|
| `shipping.carriers` | `id` ULID PK · `store_id` FK `platform.stores` RESTRICT · `country_code` (one of the store's countries, checked by the handler) · `name_ar`, `name_en` (1–100) · `logo_media_id` NULL · `phone` NULL (E.164) · `website` NULL (HTTPS) · `tracking_pattern` NULL · `free_above_minor` NULL (≥ 0) · `active` bool · `position` int · `created_at`, `updated_at` · unique (`store_id`, `name_en`), (`store_id`, `name_ar`) |
| `shipping.price_groups` | `id` ULID PK · `carrier_id` FK CASCADE · `store_id` FK `platform.stores` RESTRICT · `amount_minor` bigint 0–100,000,000 · **unique (`carrier_id`, `amount_minor`)** (rule 4) · unique (`id`, `carrier_id`) for the places' composite key · `created_at`, `updated_at` |
| `shipping.price_places` | `id` bigint PK · `store_id` · (`group_id`, `carrier_id`) **composite FK** → `price_groups (id, carrier_id)` CASCADE, so the copied carrier can never disagree with its group · `place_id` FK `platform.places` RESTRICT — a region, a city or a district (step 4: one place column, since a whole region may be priced) · **unique (`carrier_id`, `place_id`)** (rule 3) |
| `shipping.uncovered_counts` | `store_id` PK FK `platform.stores` · `count` ≥ 0 · `counted_at` — the menu's kept count (§1.6, step 4) |
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
| `CarrierStillOn` | CONFLICT | Deleting a carrier that is switched on (§1.3) |
| `CarrierNameTaken` | CONFLICT | A name another carrier of the store has, in either language, ignoring case and outer spaces — names it in both languages (§9.4 #4) |
| `CountryNotShipped` | INVALID | A carrier for a country the store does not ship to |
| `CountryHasCarriers` | CONFLICT | Removing a ship-to country, or moving it to another store, while the store has carriers there (Platform asks Shipping through a hook it declares — §9.2) |
| `CountryWithoutPlaces` | CONFLICT | Adding a ship-to country Platform has no place list for — no region in use (§1.3; owner, 2026-10-29) — raised by Platform, which owns the ship-to list (platform.md, stage 7 step 1) |
| `PlaceAlreadyPriced` | CONFLICT | A place already in another group of this carrier (§1.4) — names the place and its amount |
| `AmountAlreadyUsed` | CONFLICT | A second group with the same amount in one carrier — names the group |
| `PriceGroupNotFound` | NOT_FOUND | A price group that is not there, or another carrier's (step 4) |
| `PricesChanged` | CONFLICT | The carrier's prices changed since the page read them — reload (§1.4, §8 #3; step 4) |
| `PlaceNotInCountry` | INVALID | A place that is not Platform's, not in the carrier's country, or retired and not already in that group (§1.4) |
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
6. **Snapshots**: a price changed, a carrier switched off or deleted after placement — the order keeps
   its carrier's names, its fee and its full tracking link; deleting a carrier that is on is refused.
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
| §9.3 #1–13 | **(a) for all thirteen**, as recommended | 2026-10-27 |
| — | A carrier is deleted only while switched off; the padding is a setting admins and staff change; every other pick accepted | 2026-10-28 |
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

### 9.3 The round of 2026-10-27 — answered

The owner answered **(a) for all thirteen** (2026-10-27, "others as rec"). The questions are kept as
they were asked. Nothing in Shipping is open.

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

### 9.4 Stage 7 step 3 — the owner's answers (2026-10-29, "Rec all")

Asked while building the carriers (plan-stage7 step 3); each is now the rule.

1. **`shipping.carriers.view` is a plain role permission** in the Shipping group: admins have it, a
   staff role has it when it is ticked. There is no "by default" — the code has no default roles — and
   order staff do not need it: Sales's Ship dialog lists the store's carriers from its own orders under
   `sales.order.fulfil`. This replaces "staff who manage orders get it by default" in §3.
2. **One phone rule for every module**: E.164, kept in the shared kernel (`Shared\Domain\Text\PhoneNumbers`);
   Access's `PhoneNumber` and the carrier's phone both use it, each refusing in its own error. A carrier's
   phone is audited as "changed" only, as every phone is.
3. **The ship-to guard is filled with the carriers** (step 3, not step 4): Platform's removal of a country
   asks `CarriersHoldCountry`, which takes the store's carriers' lock — the lock every carrier change takes —
   and refuses with `CountryHasCarriers` while any carrier, on or off, is there.
4. **Carrier names are unique in the store ignoring case and outer spaces**, in each language: a clash is
   `CarrierNameTaken` (CONFLICT), naming the other carrier in both languages. Another store may use the
   same name.
5. **Carriers are ordered within their country**: a new one goes last in its country; `OrderCarriers`
   takes one country's ids in their new order, those not sent after them.
6. **The website is HTTPS only, at most 500 characters**, as the tracking pattern.
7. **Deleting a carrier** (only while off) deletes its row; its logo stays in the media library, no longer
   named by it; the audit keeps its names in both languages. A logo deleted from the media library leaves
   its carriers with none (`CarrierLogoUsage`, audited `shipping.carrier.logo_detached`).

### 9.5 The carriers screens' questions — round 1 (2026-10-29)

Asked by the screens session for §4.1 part 1. Option **(a)** is the recommended one in each. **Answered by the
owner, 2026-10-29, one by one: (a) in all sixteen.** No round 2. Price groups, free shipping and places no carrier
serves (step 4), and boxes (step 5), come in their own rounds when those steps merge.

| # | Question | Options and their effect |
|---|---|---|
| 1 | Where do the carriers live in the menu? | **(a)** An entry **Carriers** in the **Shipping** group (made in stage 7 step 2, after Orders) — Boxes joins it in part 3. (b) In Store Settings — beside the places, but Shipping's own group would stay empty. |
| 2 | How is the carriers page laid out? | **(a)** One section per ship-to country, the store's own first, each with its carriers and its own Add Carrier — dragging stays within a country, as the rule is (§9.4 #5). (b) One table with a country filter — one country at a time, and a country with no carrier is easy to miss. |
| 3 | Adding and editing a carrier? | **(a)** A dialog (as a brand's): names, logo, phone, website, tracking link — quick, the list stays in view. (b) A page of its own — more room, one more page load each time. |
| 4 | A carrier's own page (`/admin/shipping/carriers/{id}`), which part 2 fills with its price groups? | **(a)** Built now with its details, so part 2 only adds the groups; until part 2 merges it says prices come next (never seen live: part 2 merges before the store goes live). (b) Built with part 2 — until then a carrier is only its row and its dialog. |
| 5 | The on/off switch? | **(a)** In the row; **switching off asks first** ("it leaves every new checkout at once; orders already placed keep it"), switching on asks nothing. (b) Both at once, no question — one slip takes a carrier out of checkout. (c) Both ask. |
| 6 | Delete while the carrier is on (the server refuses it — §1.3)? | **(a)** Delete shown out of reach with "Switch it off first" — Feedback's rule; off, a dialog says its prices go with it and placed orders keep its name and tracking links. (b) Offered, and refused by the server. |
| 7 | Ordering the carriers? | **(a)** Drag by the handle within the country, saved when dropped, plus **Move to Top / Move to Bottom** in the row menu — catalog.md amendment 16, as everywhere. (b) Move to Top / Bottom only — no dragging. |
| 8 | The tracking link box? | **(a)** A web address with `{number}`, and under it as it is typed what a tracking number would open ("12345 opens: https://…12345"), or what is wrong — staff see the link works before saving. (b) A plain box, checked on Save. |
| 9 | The carrier's phone box? | **(a)** The panel's phone box, as on the staff account page — E.164, Latin digits, the same checks as every phone (§9.4 #2). (b) A plain text box — any format, refused on Save if not E.164. |
| 10 | The carrier's logo? | **(a)** Uploaded in the dialog (pick an image, see it, Remove), as a brand's logo. (b) Uploaded, or chosen from the media library — a second picker for a rare need. |
| 11 | A ship-to country with no carrier? | **(a)** Its section stays, with "No carriers in :country yet — until one is added and priced, orders there are handled by staff: no carrier, the fee confirmed later, no online payment" and Add Carrier. (b) A plain "No carriers yet". (c) The country is hidden until it has a carrier. |
| 12 | A person who may only view (`shipping.carriers.view` without `.manage`)? | **(a)** Sees the page read-only: Add, Edit, the switch, the drag handles and Delete are not shown. (b) Sees them out of reach, with "Admins only" — the page looks the same for everyone, cluttered for those who can't act. |
| 13 | Who builds the routes, controllers and page data? | **(a)** The screens session, in Shipping's Presentation layer, with the screens — step 3's Application layer has every read and command they call. (b) The backend session — one more hand-off before the screens start. |
| 14 | Removing a ship-to country that still has carriers (`CountryHasCarriers`)? The remove dialog already shows the refusal in its own words. | **(a)** Add under it a link **Open the carriers of :country**, to the carriers page for that store — one click to where they are switched off and deleted. (b) The words only. |
| 15 | A carrier with no logo? | **(a)** Its initials in a circle (shadcn's `Avatar` fallback) — rows line up and stay told apart. (b) A plain truck icon for all. (c) Nothing — the name only. |
| 16 | The menu entry's and page's name? | **(a)** **Carriers** / **شركات الشحن**. (b) **Shipping Companies** / **شركات التوصيل**. (c) **Couriers** / **المناديب**. |

### 9.6 Stage 7 step 4 — the owner's answers and my picks (2026-10-29/30)

The owner's answers to the gap round (scratchpad `stage7-gap-questions.md`, "rec all" with the changes
noted), and the small picks the owner left to me ("simple — handle it with rec"):

1. **A whole region may be priced, in every country; the most specific place wins** — district, then city,
   then region (owner, 2026-10-29). Rule 2 and rule 5 read so; `price_places` has one place column.
2. **A typed city or district takes the price of the place above it** (gap 4.1): every UAE, Qatar and
   Bahrain address, and the customer's "Other" in Saudi Arabia and Egypt.
3. **"Select all"** (gap 4.2): inside a region, the region priced whole; the country's, every city not yet
   priced nor under a priced region — or every unpriced region where the country has no city.
4. **"Places no carrier serves" lists the biggest places nothing serves** (gap 4.3), and the menu counts
   those rows.
5. Mine: the limits — a price 0–100,000,000 and a free-above 0–1,000,000,000 in the smallest unit (gap 4.4,
   Sales's old limits); at most 10,000 places a save.
6. Mine: `CityPricedWhole` is dropped — a district always belongs to a city with a district list, so a
   district of a city without one cannot be named.
7. Mine: a carrier's groups are their own read, `ListPriceGroups`, beside `ViewCarrier` (which the
   screens session already calls), not inside it.
8. Mine: the menu's count is kept per store and counted again after each change and every ten minutes
   (§1.6); the list itself is live.
9. Mine: the price changes are audited on the carrier, one attribute per amount that changed (§1.4).
10. Mine (the review): a save or delete carries the version it read and a later one is refused (`PricesChanged`), as §1.4 says ("the second is told to reload"); Select all leaves out the cities under a region picked in the same save; a place already in the group a changed one merges into is not refused.

### 9.7 The prices screens' questions — round 2 (2026-10-30)

Asked by the screens session for §4.1 part 2. Option **(a)** is the recommended one in each. Boxes (step 5)
come in their own round when that step merges.

| # | Question | Options and their effect |
|---|---|---|
| 1 | Where are a carrier's prices? | **(a)** On its own page (C3), under its details: a Prices card and a Free Shipping card — everything about one carrier in one place. (b) A page of their own per carrier — one more page, the details a click away. |
| 2 | Adding and editing a price? | **(a)** A wide dialog (a sheet on a phone): the amount, then the places — the prices list stays behind it. (b) A page of its own — more room for a long place list, one more page load each time. |
| 3 | The place picker? | **(a)** One level at a time with a trail, as the places pages: the country's regions, a region's cities, a city's districts, a tick box on each — ticking a region or a city prices it whole. (b) One tree that opens level by level in place — long once a region with hundreds of cities is open. |
| 4 | Finding a place in the picker? | **(a)** A filter box on the open level, in either language — the level is already loaded. (b) One search across the whole country — needs a new backend read (Saudi Arabia has 4,354 places). |
| 5 | A place already in another of this carrier's prices? | **(a)** Shown with **At :amount**, unticked; ticking it asks **Move here?** and moves it in the same save. (b) Hidden from the picker — moving means editing the other price first. |
| 6 | **Select all** on the country's level? | **(a)** Every place not yet priced in this carrier (the backend's "all unpriced": the cities, or the regions where the country lists none) — places in other prices stay where they are. (b) Every place on the level, moving the ones in other prices too. |
| 7 | Changing a price's amount to one this carrier already has (the two merge, §1.4)? | **(a)** Said under the amount box before saving: "This joins the :amount price: its places and these become one group." (b) Merged on save with only the toast saying so. |
| 8 | Deleting a price? | **(a)** A dialog saying its :count places stop being served by this carrier unless another of its prices covers them — no typed name (a price is quickly re-made). (b) The typed-name dialog, as a carrier's delete. (c) No dialog. |
| 9 | A price with many places (a Select all can make hundreds)? | **(a)** Its first 12 places as chips and **+:count more**, opening the rest. (b) Only a count, with **Show places**. |
| 10 | Someone else changed this carrier's prices meanwhile (the save is refused)? | **(a)** "The prices changed while you were editing. Reload to see them." with **Reload** — what was ticked is lost. (b) Reload by itself, without a word. |
| 11 | A carrier's own free-shipping amount? | **(a)** On its page, a Free Shipping card: **Free above**, Save and Clear; C1's row shows **Free above :amount** when one applies. (b) In the carrier's Edit dialog — but that dialog is for `carriers.manage`, this amount for `prices.manage`. |
| 12 | The store-wide amount for every carrier (Platform's setting)? | **(a)** A card at the top of the carriers page with Edit, in the store's currency; and the Settings page stops listing it (one line for the backend session: today it would show there as a plain number in halalas). (b) The card, and the Settings page keeps listing it in halalas. (c) Only on the Settings page, in halalas. |
| 13 | Places no carrier serves? | **(a)** In each country's section of the carriers page, under its carriers: the biggest places nothing serves, the first 10 and **Show all**, with what it means for their orders. (b) A page of its own, from the menu's count. |
| 14 | Whoever may see the carriers but not change prices? | **(a)** Sees the prices and the free-shipping amounts read-only; Add, Edit, Delete, Save and Select all are not shown — as part 1's Q12. (b) Sees them out of reach with "Admins only". |
| 15 | The amount box? | **(a)** The Pricing screens' price box: the store's currency and decimals, Latin digits as typed; 0 reads **Free** in the list. (b) A plain number box in the currency's smallest unit (halalas). |
| 16 | A retired place still in a price (its price still applies to saved addresses, §1.4)? | **(a)** Its chip stays, marked **Retired**, with ✕ to take it out; it is never offered in the picker again. (b) Hidden from the list — its price still applies, unseen. |
