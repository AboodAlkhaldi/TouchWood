# Module specifications

One file per module. Access is written first and defines the shape every other
module follows.

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

## Status

| Module | Spec | Blocked by |
|---|---|---|
| Platform | not started | — |
| Access | not started | — |
| B2B | not started | — |
| Feedback | not started | — |
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
