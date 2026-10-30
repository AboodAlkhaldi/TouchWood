# Shipping

Each store's carriers, their prices by place, free shipping, the tracking links and the optional box
list: Shipping answers Sales's one question - *can this store take this order to this address, with which
carriers, for how much?* The specification is [`docs/modules/shipping.md`](../../../docs/modules/shipping.md)
(accepted 2026-10-28; the owner's answers while building in §9.4).

Built in stage 7, backend only; the screens are the screens session's (§4.1). The places and each store's
ship-to countries are Platform's (§9.2).

## What is built

| Step | What | Where |
|---|---|---|
| 3 | **Carriers** (§1.3): create, change, switch on/off, order within a country, delete only while off; the logo upload; the list by ship-to country and one carrier's page | `Application/Command/*Carrier*`, `Application/Query/{ListCarriers,ViewCarrier}` |
| 3 | The ship-to guard: a country leaves a store's list only once the store has no carrier there | `Application/Carrier/CarriersHoldCountry` (registered with Platform's `ShipToCountryGuards`) |
| 3 | A logo deleted from the media library leaves its carriers with none | `Infrastructure/Media/CarrierLogoUsage` |
| 4 | **Prices** (§1.4): a carrier's groups, one amount each, on regions, cities and districts — save (with Move Here, merging, Select all, the version check), delete, list | `Application/Command/{SavePriceGroup,DeletePriceGroup}`, `Application/Query/ListPriceGroups`, `Application/Price/*` |
| 4 | **Free shipping** (§1.5): each carrier's own amount and the store-wide setting | `Application/Command/SetCarrierFreeAbove`, `ShippingSettings` |
| 4 | **What Sales asks** (§2.1): the quote (the most specific place wins), a carrier, the store's carriers, a tracking link | `Application/ShippingApiImpl` |
| 4 | **Places no carrier serves** (§1.6): the live list and the menu's kept count, counted again after each change and every ten minutes | `Application/Uncovered/*`, `Application/Query/ListUncoveredPlaces`, `Infrastructure/Queue/CountUncoveredPlacesJob` |
| 5 | Boxes | not built yet |

## How a change runs

`Application/Carrier/CarrierChange` asks for the permission in the store (staff only), then runs the change
in one transaction under **the store's carriers' lock** (an advisory lock), with its audit entries
(`ShippingAudit`, "shipping.carrier.*"). Platform's removal of a ship-to country takes the same lock through
`CarriersHoldCountry`, so a carrier is never created in a country being removed.

## Rules worth knowing

- A carrier's country is one the store ships to, and fixed once it exists.
- Names are unique in the store ignoring case and outer spaces, in each language (`CarrierNameTaken`).
- The phone is E.164 by the shared rule (`Shared\Domain\Text\PhoneNumbers`); the website and the tracking
  pattern are HTTPS, at most 500 characters; the pattern has exactly one `{number}`. The table's CHECKs hold
  the same rules.
- Shipping uses Access only to declare its permissions (`tests/Architecture/ShippingAccessUseTest`).

## Permissions (§3)

`shipping.carriers.view` (a plain role permission), `shipping.carriers.manage` and `shipping.prices.manage`
(admin-only), `shipping.boxes.manage` - all per store, in the role editor's Shipping group.

## Tests

`tests/Modules/Shipping/Integration/CarriersTest` - every command and read, the refusals, who may, the
ship-to guard with Platform's real removal, the media library's delete.
