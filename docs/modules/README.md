# Module specifications

One file per module, written and reviewed before the module's first line of code.
Platform comes first in the build order (handoff §17), and its specification is the shape every
later module follows (handoff §18).

Each specification contains, in this order:

1. Aggregates and invariants
2. Public contract interface
3. Use cases, each with its permission string
4. State machines
5. Tables, columns, indexes
6. Events published and consumed
7. Error hierarchy
8. Test scenarios
9. Open questions raised by this module

Each specification's header also says what the module **needs from other modules** and from
shared plumbing (for example, the event tables in Platform spec §5.6), so a missing piece shows up
before the module's code starts. Shared plumbing is built with the first module that needs it,
not in advance (owner, 2026-09-16).

Once a module is built, its developer guide lives next to its code:
`src/Modules/{Name}/README.md` (what it contains, the approaches it follows, how it was built).
The Shared kernel's guide is [src/Shared/README.md](../../src/Shared/README.md).

## Status

| Module | Spec | Blocked by |
|---|---|---|
| Platform | [approved](platform.md) — **Stage 1 delivery implemented** (spec §3); admin view use cases come with Access. B2B step 3 adds `PlatformApi::deleteMediaFor` and the admin-only private-files permission, `platform.media.private.view` (platform.md §9.4). See [its README](../../src/Modules/Platform/README.md) | — |
| Access | [approved](access.md), amendments 1–58 — **built and merged** (2026-09-21): all seven steps; 47 (a staff member's own sessions), 48 (what B2B needs), 49 (Platform's admin-only private-files permission, B2B step 3) and 50–58 (what other modules add to the shop's frame, the way back from the addresses, off stores, invisible Super Admins, and their reviews and settled picks) since. See [its README](../../src/Modules/Access/README.md) | — |
| Frontend foundation (stage 2b, not a module) | [approved](frontend.md) — revised 2026-09-22 against the merged Access; built; **rebuilt on shadcn's code with Geist's rules** (§1.11, 2026-10-03/04): every screen, its words and its error messages | — |
| B2B | [accepted](b2b.md) 2026-09-26, amendments 1–25 — **built and merged** (seven steps, from 2026-09-27; its screens rebuilt on shadcn, amendments 22–25). See [its README](../../src/Modules/B2B/README.md) | — |
| Feedback | not started | Catalog, Sales — built with Sales (stage 6) |
| Catalog | [approved](catalog.md) 2026-10-02 — **being built** (from 2026-10-02) in seven steps, backend first; the JSON import's file format was agreed on 2026-10-05 — the format, a guide and examples in [`catalog-import/`](catalog-import/README.md) — and the import is built last. See [its README](../../src/Modules/Catalog/README.md) | — |
| Pricing | [spec](pricing.md) — its public contract on `main` (#97, 2026-10-07); the full spec accepted and merged (#100, 2026-10-09); built next (stage 5) | Catalog |
| Inventory | [spec](inventory.md) — its public contract on `main` (#97, 2026-10-07); the full spec, its questions answered by the owner 2026-10-09 (stage 5) | Catalog |
| Sync | not started — waits for the provider's team: API access, a sample, whether discounts come from it, change notifications (pricing.md §9.1) | Pricing, Inventory, the provider's answers |
| Sales | [draft](sales.md) 2026-10-10 — **for the owner's review**; the last of stage 6 to be built | Pricing, Inventory (built in stage 5); Shipping and Payments (stage 7) through the interfaces it defines |
| Promotions | not started | Pricing |
| Loyalty | not started | Pricing |
| Payments | not started | MyFatoorah API docs + sandbox |
| Shipping | not started | box list + carrier list + rate tables |
| Content | not started | Catalog |
| Ops | not started | SMS + email provider |
