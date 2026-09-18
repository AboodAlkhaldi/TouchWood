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
| Platform | [approved](platform.md) — **Stage 1 delivery implemented** (spec §3); admin view use cases come with Access. See [its README](../../src/Modules/Platform/README.md) | — |
| Access | [draft](access.md) — awaiting the owner's review | — |
| B2B | not started | — |
| Feedback | not started | Catalog, Sales — built with Sales (stage 6) |
| Catalog | not started | external provider schema + real product sample |
| Pricing | not started | Catalog |
| Inventory | not started | Catalog |
| Sync | not started | provider credentials + webhook capability |
| Sales | not started | Catalog, Pricing, Inventory |
| Promotions | not started | Pricing |
| Loyalty | not started | Pricing |
| Payments | not started | MyFatoorah API docs + sandbox |
| Shipping | not started | box list + carrier list + rate tables |
| Content | not started | Catalog |
| Ops | not started | SMS + email provider |
