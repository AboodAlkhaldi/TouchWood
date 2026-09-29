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
| `Domain/Model` | `Company` and its state machine; `Application`, draft to decision, with its files, a rejection's flags and requests, and a draft's answers; `CompanyType`, `DocumentType` — the two lists staff manage, one of each per store; `StoreTypeLists`, each store's "copied, not yet reviewed" flag over its two lists |
| `Domain/ValueObject` | The company's values — `CompanyName`, `RegistrationNumber`, `CompanyAddress`, `CompanyTypeChoice` (a listed type or "Other"), `CompanyDetails` (all five, as sent) — and `Remark` for reasons, notes and text answers; `CompanyText`, the two ways typed text is accepted; `ApplicationState`, `AttachedDocument`, `TypeName`, `TypePosition`, `InactiveTypeDisplay` (hidden or greyed); `ApplicationFlag` and `FlaggedField`, `ApplicationRequest` and `RequestKind`, `RequestAnswer` |
| `Domain/Exception` | `B2BError`, the base of every error here, and one class per refusal |
| `Domain/Repository` | One per aggregate: companies, applications (with their files, flags, requests and answers), the two type lists, the stores' "copied" flags |
| `Application/Types` | `StartingTypes`, the lists every store starts with; `GiveEveryStoreTheStartingTypes`, which writes them into a store that has none |
| `Application` | `B2BPermissions` — the two automatic permissions of the company's own side, declared into Access's catalog at boot |
| `Application/Command` | The company's own side (step 3b): `StartApplicationDraft`, `SaveApplicationDraft`, `AttachApplicationDocument`, `RemoveApplicationDocument`, `AnswerApplicationRequest`, `RemoveApplicationAnswer`, `SubmitApplication`, `DiscardApplicationDraft`, `UpdateCompanyContact` |
| `Application/Query` | `ViewMyCompany` and its read classes — what the account is shown; `OpenMyApplicationFile`, a 30-minute link to one of its own files |
| `Application/Account` | `CurrentCompanyAccount`: the signed-in company account every use case above starts from |
| `Application/Draft` | `OpenDrafts`: the account's open draft, read under its locks, refused in the one order every draft action shares |
| `Application/Files` | `ApplicationFiles`: B2B's own uploads (private, under its own permission) and letting go of what no application holds |
| `Application/Audit` | `CompanyAccountAudit`: the three company actions the audit log keeps |
| `Infrastructure/Eloquent` | The database repositories; `TypeNames`, the one "is this name taken" query both lists share; `Ulids` |
| `Infrastructure/Listener` | `WriteStartingTypes`: a store opened later gets the starting lists, on Platform's `StoreCreated` |
| `Infrastructure/Media` | `ApplicationFilesUsage`: B2B's answer when Platform asks where a file is used |
| `Infrastructure/Persistence/Migrations` | The `b2b` schema; the two type tables and the stores' "copied" flags; the companies, applications and their files; a rejection's flags and requests and a draft's answers |
| `Presentation/lang` | The error messages, the permissions' names and the audited actions' names, in Arabic and English |

## How it is built

**The same shape as Access.** Aggregates record what changed (`pullChanges()`) for the audit log;
repositories write with the query builder, never Eloquent models; ids are lower-case ULIDs, and an id
that is not one is "not found" without a query. A module may not reach into another's
Infrastructure, so B2B keeps its own ten-line `Ulids` rather than borrowing Access's.

**Every rule twice: in code first, in the database behind it** (handoff amendment of 2026-09-18).
A type's name is checked by `TypeName` — present, one line, real text, at most 100 characters — and
the table has a CHECK for each. The names are unique in each language ignoring case, **within one
store** (amendment 5): a unique index on `(store_id, lower())` in the database, and `nameTaken()`
asking the same `lower()` question of the same store, so the two can never disagree about a letter.
Two stores may share a name. That question is answered by the screens that add and rename types:
until step 4 (`TypeNameTaken`) only the starting lists write rows. A type an application references
is never deleted; the refusal, `DocumentTypeInUse`, comes in step 4 too.

**Both lists are per store** (amendment 5): a legal form or a paper in one country is not one in
another, and a company uses its home store's lists. Every store starts with the same six company
types and three required document types, in the owner's words (b2b.md §1.3, amendments 2 and 5),
written by `GiveEveryStoreTheStartingTypes` **into a store that has none of that kind** — as Access
writes each store's first address form. The type migration runs it for the stores an installation
already has; `WriteStartingTypes` runs it on `StoreCreated` for every store opened afterwards, the
launch stores the seeder creates included (amendment 6(a)). A store that has any of a kind keeps
them exactly as they are, so it is safe to run again. "Other" is not among them — it is not a row,
so nobody can deactivate it by mistake.

**Copied, not yet reviewed** (amendment 6(a)). The starting lists are one country's, so a store's
types page tells its admins they were copied in, until one of them changes a type or marks the lists
reviewed. That is one flag per store over both lists, `StoreTypeLists`: the writer adds it, set, in
the same transaction as the lists, whenever it writes either list into a store that has no flag;
a flag that exists is never touched again by the writer, so a store that was reviewed stays
reviewed. `markReviewed()` is the one way it clears. **Nothing in step 3a calls it** but the tests:
step 4's type changes and its "mark reviewed" use case call it, and the notice is drawn on the staff
screen in step 7.

**Deactivating a type, staff choose how it looks** to new applications (amendment 5): `HIDDEN` or
`GREYED`, for both kinds. An active type has no such choice and activating one clears it; two CHECKs
hold the list of values and "present if and only if inactive".

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

**One company and one open application per account, decided under the account's lock.** The
database holds both (a unique `customer_id`; a partial unique index on the open states). Their code
halves are the company's own use cases (step 3b): each takes `ApplicationRepository::lockAccount` —
a transaction-scoped advisory lock, since a row lock locks nothing while the account has no row yet
— then reads, so two first starts or two first sends of one account run one after the other, and
the second sees what the first wrote. A caller that skipped the lock and still reached the index is
answered `ApplicationAlreadyOpen`, not a database error (lesson 64). The tests prove the lock is
taken, and taken inside each use case's own transaction — not merely inside the test's — by
recording the transaction level at which every advisory lock is asked for (`B2BFixtures::accountLocks`).
`ViewMyCompany` takes the same lock **shared**, so its several reads see one moment: readers wait
for a writer, never for each other.

**A rejection can say what to fix and what to add** (amendment 4), and only a rejection:
`Application::reject()` takes **flags** — any of the five fields, or a document the application sent
a file under; the same flag twice is kept once — and **requests**, each a text answer or a file
under a label staff write. They are kept only once the rejection itself succeeds, and they belong
to the rejected application. The next draft **answers** the requests (`answer()`, and
`removeAnswer()`, the mirror of `detach()`); the answers are that draft's, as every value it sends.
A second answer replaces the first, and says which file it replaced so the caller can let it go.
Until step 4's `RejectCompany`, nothing but the tests writes flags or requests.

**Sending is refused in one order** (`submit()`, amendments 2, 4, 5 and 6), against every type of
the home store, active or not, and the last application the company sent:

1. a value is missing;
2. a listed company type is not one of the home store's (`InvalidCompanyAttribute`), or is one staff
   have deactivated since (`CompanyTypeInactive`);
3. a file sits under a document type deactivated since (`DocumentNoLongerAccepted`) — a draft never
   sends anything deactivated;
4. a document type that is required and offered has no file (`MissingRequiredDocument`);
5. after a rejection, a flagged field holds what was sent — exactly, after trimming; letter case
   counts (`FlaggedItemNotReplaced`);
6. after a rejection, a flagged document has no file, or the file that was sent — unless its type is
   no longer offered, when the flag stops blocking;
7. after a rejection, a request has no answer (`RequestNotAnswered`).

A last application of another company, or one not yet decided, is the caller's bug, and a
`LogicException` before any of its flags is read.

**Five rules are code-only**, with nothing in the database behind them (amendments 5(g) and 6(c)):
each crosses two tables, which a CHECK cannot see.

- An answer matches its request's kind — text for text, a file for a file (`answer()`).
- Flags and requests exist only on a `REJECTED` application (`reject()`).
- An answer's request belongs to the last rejection (`answer()`, given the last application sent).
- A company's type comes from its home store's list (`submit()`, given that store's types; and
  `Company::correctType()`, a staff correction, which reads the store from the type itself).
- Staff flag only a document the rejected application sent a file under (`reject()`).

**A file an application holds is never deleted from under it** (b2b.md §1.4). B2B registers
`ApplicationFilesUsage` with Platform: every application that holds a file — as a document or as the
answer to a request, drafts included — is one **blocking** use, named `b2b.application {id}`, so a
delete from the media library is refused while any holds it, and Platform never asks B2B to detach
one. B2B lets go of its own files through its own use cases (`ApplicationFiles::release`, step 3b):
after the application's own rows are written, and only a file no application still holds — so one
carried from the last application sent stays with it. The `RESTRICT` keys on `media_id` stay the
backstop.

## The company's own side (step 3b)

**Every use case starts from the signed-in account** (`CurrentCompanyAccount`), never an id from the
request: a guest, a staff member or the system is `Unauthorized`; an individual account — and one
Access cannot find (amendment 9(b)) — is `NotACompanyAccount`, in the handler itself (§3.1). The two
permissions, `b2b.company.apply` and `b2b.company.update`, are automatic for every customer and
store-free; B2B declares them into Access's catalog, being above Access.

**The draft's actions name no application** (amendment 5): they act on the account's one open
application, through `OpenDrafts`, which locks **the account, then the company's row**, reads the
draft (read, not locked: every writer holds the account's lock), and refuses in one order — none
open (`ApplicationNotFound`), the company suspended (`CompanySuspended`: starting, saving,
uploading, answering and removing are all refused, amendment 9(a)), already sent
(`ApplicationNotEditable`). Discarding goes around it, being the one thing a suspended company's
draft allows. **A suspended company changes nothing else either**: starting is refused even with a
draft open (9(e)), and so is the address (9(d), which reversed the 2026-09-25 rule — refused in
the domain, by `Company::moveTo`, so no caller can skip it). **Step 4's staff actions must take the
company's row in the same order** (lesson 37): never the company's row and then the account's lock.

**Files are checked before they are stored.** An upload under a type that is inactive, another
store's, or unknown is `DocumentTypeInactive`, and an answer to a request that is not the last
rejection's, or of the wrong kind, is refused — before Platform stores anything, which the tests
prove by counting Platform's writes, not by looking for a file afterwards (a rolled-back upload
leaves none either).

**Saving changes only the fields sent** (amendment 5), each checked on its own field; a newly chosen
listed type must be the home store's and still offered, while one the draft already holds is left
alone and refused only when the draft is sent (§1.3).

**Three actions are audited** (amendment 4, `CompanyAccountAudit`), each inside its own transaction
and under the account's home store: sending and discarding on the application, the address change
on the company. What the customer typed is only "changed"; the states and a listed type's id are
recorded by value.

**`ViewMyCompany` always answers** (§3.1, §4.3): before a company, which step the account is on —
the email first, then whether a draft is open; the open draft with the last rejection's marks and
requests, its answers, and what is no longer accepted; the home store's types as the form offers
them — greyed ones marked, hidden ones left out; the company; and its history, newest first, with no
staff names.

**Typed text is accepted two ways** (`CompanyText`): one line for names and numbers; lines, with
the break kept as `\n`, for the address and for reasons and notes. Each column has a CHECK behind
it, and a test that the database takes everything the code takes — Arabic names, and numbers in
Arabic-Indic digits.
