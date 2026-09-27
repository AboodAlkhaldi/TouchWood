# B2B module

**Build stage 3. Depends on Platform and Access** (handoff §4.4). Pricing and Sales will read it.

B2B owns **the company behind a company account**: its details, its documents, and the approval
that decides whether it may order. It owns nothing about who the person is (Access), what anything
costs (Pricing) or how an order is placed (Sales). The rules are in the accepted specification,
[docs/modules/b2b.md](../../../docs/modules/b2b.md), with its amendments at the end; this file says
how the code is organised and why.

**Being built** (from 2026-09-27), in seven steps: 1 what B2B needs from Access · 2 domain and
persistence (2a the two type lists, 2b the company and its applications) · 3 applying · 4 staff
decisions · 5 the public contract · 6 the customer's screens · 7 the staff screens. This file grows
with each step.

---

## Where things are

| Folder | What is in it |
|---|---|
| `Domain/Model` | `CompanyType`, `DocumentType` — the two lists staff manage |
| `Domain/ValueObject` | `TypeName` (Arabic and English, one line, at most 100 each), `TypePosition` (0–10,000) |
| `Domain/Exception` | `B2BError`, the base of every error here, and `InvalidCompanyAttribute` |
| `Domain/Repository` | `CompanyTypeRepository`, `DocumentTypeRepository` |
| `Infrastructure/Eloquent` | The database repositories; `TypeNames`, the one "is this name taken" query both lists share; `Ulids` |
| `Infrastructure/Persistence/Migrations` | The `b2b` schema, and the two type tables with what ships in them |
| `Presentation/lang` | The error messages, in Arabic and English |

## How it is built

**The same shape as Access.** Aggregates record what changed (`pullChanges()`) for the audit log;
repositories write with the query builder, never Eloquent models; ids are lower-case ULIDs, and an id
that is not one is "not found" without a query. A module may not reach into another's
Infrastructure, so B2B keeps its own ten-line `Ulids` rather than borrowing Access's.

**Every rule twice: in code first, in the database behind it** (handoff amendment of 2026-09-18).
A type's name is checked by `TypeName` — present, one line, real text, at most 100 characters — and
the table has a CHECK for each. The names are unique in each language ignoring case: a unique
index on `lower()` in the database, and `nameTaken()` asking the same `lower()` question, so the two
can never disagree about a letter. That question is answered by the screens that add and rename
types (step 4); until then only the migration writes rows.

**What ships is written by the migration**, as Access writes each store's first address form: six
company types and three required document types, in the owner's words (b2b.md §1.3, amendment 2).
"Other" is not among them — it is not a row, so nobody can deactivate it by mistake.

**A change to a type is never retroactive** (owner, 2026-09-26). Nothing on `DocumentType` reaches
an application; an application reads the types when it is submitted, and an approved company is
never asked for a paper that became required later.
