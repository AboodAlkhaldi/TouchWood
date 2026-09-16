# Architecture Decision Records

One file per decision, numbered, never edited after acceptance — superseded by a new
record instead. Format: Context · Decision · Consequences · Alternatives rejected.

Backlog to write, carried from the specification conversation:

| # | Decision |
|---|---|
| 001 | Modular monolith over microservices and over a default layered Laravel app |
| 002 | Selective DDD — core / supporting / generic, rigour applied proportionally |
| 003 | Hybrid Eloquent + pure domain objects behind repository interfaces |
| 004 | Three communication channels; events carry IDs, not payloads |
| 005 | Money as bigint minor units with the exponent from a currencies table |
| 006 | Every product has at least one variant; no separate simple-product path |
| 007 | Generalized price lists (Option D) over per-store product duplication |
| 008 | Two orthogonal axes: `audience` (PUBLIC/COMPANY) and `sale_mode` (RETAIL/WHOLESALE) |
| 009 | Session authentication over JWT |
| 010 | Two actor types, unlimited database roles, store access on the assignment |
| 011 | Role cloning at creation time, never live inheritance |
| 012 | Out of stock is a stock movement, never a boolean flag |
| 013 | Delta-based stock sync; absolute quantities only for reconciliation |
| 014 | Cart → Quote → Order; invariants on the Quote, never on the Cart |
| 015 | Four independent state machines, one derived status for the customer |
| 016 | Postgres FTS + pg_trgm; no Elasticsearch |
| 017 | Translation tables for searchable entities, JSON columns for labels |
| 018 | No invoicing in this system; fetched from the external provider when needed |
| 019 | All refunds manual; no refund method on the gateway interface |
| 020 | Fixed-rate carrier tables; no live rate-shopping APIs |
