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
| `Public/Enums` | `CompanyStatus` — the four statuses, and the only company status in the system |
| `Domain/Model` | `Company` and its state machine; `Application`, draft to decision, with its files; `CompanyType`, `DocumentType` — the two lists staff manage |
| `Domain/ValueObject` | The company's values — `CompanyName`, `RegistrationNumber`, `CompanyAddress`, `CompanyTypeChoice` (a listed type or "Other"), `CompanyDetails` (all five, as sent) — and `Remark` for reasons and notes; `CompanyText`, the two ways typed text is accepted; `ApplicationState`, `AttachedDocument`, `TypeName`, `TypePosition` |
| `Domain/Exception` | `B2BError`, the base of every error here, and one class per refusal |
| `Domain/Repository` | One per aggregate: companies, applications (with their files), the two type lists |
| `Infrastructure/Eloquent` | The database repositories; `TypeNames`, the one "is this name taken" query both lists share; `Ulids` |
| `Infrastructure/Persistence/Migrations` | The `b2b` schema; the two type tables with what ships in them; the companies, applications and their files |
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

**One path for every change of the registered details** (b2b.md amendment 3). There is no company
until the first application is sent (`Company::fromFirstApplication`); after that the name, the
numbers, the type and the papers change only by another application (`Company::applyAgain`), which
sends the company back to `PENDING`. Only the address (`moveTo`) and a staff correction of the type
(`correctType`) change the company on their own. So every approval is a decision about the values
the application beside it holds, and `Application` is a snapshot — its own copy of each — never a
pointer to the company.

**Two rules the database holds before their use cases exist.** One company per account (a unique
`customer_id`) and one open application per account (a partial unique index) are in the tables now;
their code halves — checked under a lock, before the insert — come with applying (step 3). Until
then nothing but the tests writes those rows.

**Typed text is accepted two ways** (`CompanyText`): one line for names and numbers; lines, with
the break kept as `\n`, for the address and for reasons and notes. Each column has a CHECK behind
it, and a test that the database takes everything the code takes — Arabic names, and numbers in
Arabic-Indic digits.
