# Shipping

Each store's carriers, their prices by place, free shipping, the tracking links and the optional box
list: Shipping answers Sales's one question - *can this store take this order to this address, with which
carriers, for how much?* The specification is [`docs/modules/shipping.md`](../../../docs/modules/shipping.md)
(accepted 2026-10-28; the owner's answers while building in §9.4, §9.6, §9.8 and §9.10).

Built in stage 7, steps 3 to 6. Its three admin screens (Carriers, a carrier's prices, Boxes) are built (§4.1
parts 3a to 3c); the checkout's and the order's shipping screens are Sales's (part 4). The places and each
store's ship-to countries are Platform's (§9.2). Shipping depends on Platform and Catalog, and on Access only to
declare its permissions; it publishes no event and listens to none (§6).

## What is built

| Step | What | Where |
|---|---|---|
| 3 | **Carriers** (§1.3): create, change, switch on/off, order within a country, delete only while off; the logo upload; the list by ship-to country and one carrier's page | `Application/Command/*Carrier*`, `Application/Query/{ListCarriers,ViewCarrier}` |
| 3 | The ship-to guard: a country leaves a store's list only once the store has no carrier there | `Application/Carrier/CarriersHoldCountry` (registered with Platform's `ShipToCountryGuards`) |
| 3 | A logo deleted from the media library leaves its carriers with none | `Infrastructure/Media/CarrierLogoUsage` |
| 4 | **Prices** (§1.4): a carrier's groups, one amount each (0 to 100,000,000 in the currency's smallest unit), on regions, cities and districts — save (with Move Here, merging, Select all, the version check), delete, list | `Application/Command/{SavePriceGroup,DeletePriceGroup}`, `Application/Query/ListPriceGroups`, `Application/Price/*` |
| 4 | **Free shipping** (§1.5): each carrier's own amount and the store-wide setting, which overrides them while it is above 0 | `Application/Command/SetCarrierFreeAbove`, `ShippingSettings` |
| 4 | **What Sales asks** (§2.1): the quote (the most specific place wins), a carrier, the store's carriers, a tracking link | `Application/ShippingApiImpl` |
| 4 | **Places no carrier serves** (§1.6): the live list and the menu's kept count, counted again after each change and every ten minutes | `Application/Uncovered/*`, `Application/Query/ListUncoveredPlaces`, `Infrastructure/Queue/CountUncoveredPlacesJob` |
| 5 | **Boxes** (§1.7): the list (create, change, switch, order, delete), the padding, sizes packed by hand (only while the store sells the size), and `suggestBox` | `Application/Command/*Box*`, `Application/Command/SetSizePacking`, `Application/Query/{ListBoxes,ListPackingSizes}`, `Application/Box/*` |
| 6 | **Wired into Sales's checkout** (sales.md §9.5 #1 to #3, §9.6; shipping.md §9.10): the quote's option carries the place's price, the free-above amount that applies and whose it is, so Sales keeps the order's rule for later edits; no VAT on shipping; the staff order page and the packing slip ask `suggestBox` as they are shown | `Public/Dto/ShippingOptionDto`, Sales's `Quote/ShippingQuotes` |
| Screens 3a-3c | The admin pages: Carriers (`shipping.admin.carriers`), a carrier's prices (`…carriers.places`, `…prices.save`, `…free_above`), Boxes (`shipping.admin.boxes`); the menu's Carriers entry shows the uncovered count | `Presentation/admin-routes.php`, `Presentation/Http/Controller/{CarriersController,CarrierPricesController,BoxesController}` |

## How a change runs

`Application/Carrier/CarrierChange` asks for the permission in the store (staff only), then runs the change
in one transaction under **the store's carriers' lock** (an advisory lock), with its audit entries
(`ShippingAudit`, "shipping.carrier.*"). Platform's removal of a ship-to country takes the same lock through
`CarriersHoldCountry`, so a carrier is never created in a country being removed. Boxes run the same way through
`Application/Box/BoxChange` under the store's boxes' lock.

## What Sales gets

`ShippingApi::quote(store, destination, goodsTotal)` answers a coverage and, when it is `SERVED`, the carriers:

- `OUTSIDE`: no store of ours ships to the country. `OTHER_STORE`: another store does (refused, naming it).
  `NOT_COVERED`: this store ships to the country and no switched-on carrier prices the place. Sales places an
  `OUTSIDE` or `NOT_COVERED` order with the fee "to be set" by staff.
- `SERVED`: each switched-on carrier of the store in that country, at its price on the most specific place it prices
  (district, then city, then region; a typed city or district falls back to the region's price), cheapest first, then
  by the carrier's place in the list. The goods total (after the coupon and the points, before VAT) reaching the
  free-above amount - equal included - makes the fee 0; the store-wide amount wins over the carrier's while it is set.
  A price that is 0 already is not "free because".
- `carrier`, `carriers` (switched-on first), `trackingLink` (the pattern's `{number}` replaced, encoded) and
  `suggestBox` (the smallest switched-on box by inner volume that takes every piece by size, weight and volume, less the
  padding; a size packed by hand, or with no weight or sizes in Catalog, makes the whole order "pack by hand";
  nothing when no box is on). Never stored, changes no price.

## Rules worth knowing

- A carrier's country is one the store ships to, and fixed once it exists.
- Names are unique in the store ignoring case and outer spaces, in each language (`CarrierNameTaken`).
- The phone is E.164 by the shared rule (`Shared\Domain\Text\PhoneNumbers`); the website and the tracking
  pattern are HTTPS, at most 500 characters; the pattern has exactly one `{number}`. The table's CHECKs hold
  the same rules.
- A place is in one group of a carrier, and a carrier has one group per amount (partial unique keys
  `price_places_one_per_place`, `price_groups_one_per_amount`); a group left with no place is deleted.
- Shipping uses Access only to declare its permissions (`tests/Architecture/ShippingAccessUseTest`).

## Tables, permissions, settings, errors

- **Tables** (schema `shipping`): `carriers`, `price_groups`, `price_places`, `uncovered_counts`, `boxes`, `variant_packing`.
- **Permissions** (§3, per store, the role editor's Shipping group): `shipping.carriers.view` (a plain role permission),
  `shipping.carriers.manage` and `shipping.prices.manage` (admin-only), `shipping.boxes.manage`.
- **Settings**: `shipping.free.above_all` (smallest unit, 0 = off, up to 1,000,000,000; `shipping.prices.manage`) and
  `shipping.boxes.padding_mm` (0 to 100, default 10; `shipping.boxes.manage`); both are edited on Shipping's pages, not
  on Platform's Settings page.
- **Job**: `CountUncoveredPlacesJob`, every ten minutes for every store, and queued for one store after a change.
- **Errors**: thirteen `shipping.*` types in both languages (`Domain/Exception`); `CountryWithoutPlaces` (§7) is
  Platform's.

## Tests

`tests/Modules/Shipping/Integration`: `CarriersTest` (every command and read, the refusals, who may, the ship-to guard
with Platform's real removal, the media library's delete), `PricesTest`, `BoxesTest`. `Feature`: `CarriersScreensTest`,
`PricesScreensTest`, `BoxesScreensTest`; `tests/Browser`: `CarriersScreenTest`, `PricesScreenTest`, `BoxesScreenTest`.
Sales's side: `tests/Modules/Sales/Integration/ShippingInCheckoutTest`, `Feature/ShippingScreensTest`,
`tests/Browser/SalesShippingScreensTest`.
