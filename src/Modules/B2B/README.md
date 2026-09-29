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
| `Public/Contracts` | `B2BApi` — what other modules may ask: the company behind an account, its status, whether it may order, and a store's bank account while bank transfer is on |
| `Public/Dto` | `CompanyDto` — a company as other modules see it, never its documents nor an "Other" company's words; `BankAccountDto` |
| `Public/Enums` | `CompanyStatus` — the four statuses, and the only company status in the system |
| `Public/Events` | `CompanyStatusChanged`, `CompanyApplicationSubmitted` — ids only, after commit |
| `Domain/Model` | `Company` and its state machine; `Application`, draft to decision, with its files, a rejection's flags and requests, and a draft's answers; `CompanyType`, `DocumentType` — the two lists staff manage, one of each per store; `StoreTypeLists`, each store's "copied, not yet reviewed" flag over its two lists |
| `Domain/ValueObject` | The company's values — `CompanyName`, `RegistrationNumber`, `CompanyAddress`, `CompanyTypeChoice` (a listed type or "Other"), `CompanyDetails` (all five, as sent) — and `Remark` for reasons, notes and text answers; `CompanyText`, the two ways typed text is accepted; `ApplicationState`, `AttachedDocument`, `TypeName`, `TypePosition`, `InactiveTypeDisplay` (hidden or greyed); `ApplicationFlag` and `FlaggedField`, `ApplicationRequest` and `RequestKind`, `RequestAnswer` |
| `Domain/Exception` | `B2BError`, the base of every error here, and one class per refusal |
| `Domain/Repository` | One per aggregate: companies, applications (with their files, flags, requests and answers), the two type lists, the stores' "copied" flags |
| `Application/Types` | `StartingTypes`, the lists every store starts with; `GiveEveryStoreTheStartingTypes`, which writes them into a store that has none; `StaffTypeAction`, what every staff change to a list shares; `TypeListsNotice`, which clears a store's "copied" notice |
| `Application` | `B2BPermissions` — the two automatic permissions of the company's own side and the twelve staff jobs, declared into Access's catalog at boot; `B2BApiImpl` |
| `Application/Settings` | `BankAccountSettings`: the three per-store bank settings, declared into Platform's registry at boot; `IbanRule`, an IBAN's shape and check digits; `StoreBankAccount`, the one reader of the three — no account while any is empty |
| `Application/Events` | `CompanyEvents`: the two events, dispatched inside a use case's transaction |
| `Application/Command` | The company's own side (step 3b): `StartApplicationDraft`, `SaveApplicationDraft`, `AttachApplicationDocument`, `RemoveApplicationDocument`, `AnswerApplicationRequest`, `RemoveApplicationAnswer`, `SubmitApplication`, `DiscardApplicationDraft`, `UpdateCompanyContact`. Staff (step 4): `ApproveCompany`, `RejectCompany`, `SuspendCompany`, `ReinstateCompany`, `CorrectCompanyType`, `TransferCompanyType`, `DownloadCompanyDocument`; the lists: `Add`/`Rename`/`Move`/`Deactivate`/`ActivateCompanyType`, the same for `DocumentType` with `RequireDocumentType`, and `MarkTypeListsReviewed` |
| `Application/Query` | `ViewMyCompany` and its read classes — what the account is shown; `OpenMyApplicationFile`, a 30-minute link to one of its own files; staff's `ListCompanies` and `ViewCompany`; `ApplicationViews`, how an application is shown to both |
| `Application/Staff` | `StaffCompanyAction` (which store, what a stranger is told, who decides), `CompanyTypeCorrection` (every change of a company's type), `CompanyTypeHolders` (every holder of a type moved to another), `CompanyMessages` (the decision emails, after commit) |
| `Application/Account` | `CurrentCompanyAccount`: the signed-in company account every use case above starts from; `CompanyAnonymizer`: what anonymizing an account reaches here |
| `Application/Draft` | `OpenDrafts`: the account's open draft, read under its locks, refused in the one order every draft action shares |
| `Application/Files` | `ApplicationFiles`: B2B's own uploads (private, under its own permission) and letting go of what no application holds |
| `Application/Audit` | `CompanyAccountAudit`: the three company actions the audit log keeps, and the company emptied when its account is anonymized; `StaffCompanyAudit` and `TypeAudit`: staff's |
| `Infrastructure/Eloquent` | The database repositories; `DatabaseCompanyReader`, the staff company list; `DatabaseCompanyStandings`, the shop line's one query; `DatabaseApplicationReferenceCounter`, the year's count of application numbers; `TypeNames`, the one "is this name taken" query both lists share; `Ulids` |
| `Infrastructure/Listener` | `WriteStartingTypes`: a store opened later gets the starting lists, on Platform's `StoreCreated`; `AnonymizeCompany`, on Access's `CustomerAnonymized`, from the queue |
| `Infrastructure/Media` | `ApplicationFilesUsage`: B2B's answer when Platform asks where a file is used |
| `Infrastructure/Settings` | `BankTransferLine`: the line at the top of the Companies settings section — bank transfer on, or temporarily off |
| `Infrastructure/Persistence/Migrations` | The `b2b` schema; the two type tables and the stores' "copied" flags; the companies, applications and their files; a rejection's flags and requests and a draft's answers |
| `Presentation/Http` | The company's own page (step 6): `MyCompanyController`, its two form requests, and the page's data (`CompanyPage` and its parts, built by `CompanyPages` in the home store's clock) |
| `Presentation/Storefront` | `CompanyShopperLine`: the line under the shop's header while a company account cannot order |
| `Presentation/routes.php` | The page and its posts, under `/{store}/{locale}/account/company`, signed-in customers only |
| `Presentation/lang` | The error messages, the permissions' names, the audited actions' names, the settings' names, and the company page's and its line's words, in Arabic and English |

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
Two stores may share a name. Staff adding or renaming a type are refused `TypeNameTaken`, asked
under the store's type-list lock so two admins cannot both win (step 4). **A type is never deleted**
(amendment 10(c)) — deactivated, and activated again — so nothing needs a "type in use" refusal.

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
reviewed. `markReviewed()` is the one way it clears: **any staff change to either list** calls it,
through `TypeListsNotice`, and so does "Reviewed" (step 4, amendment 10(d)); the notice is drawn on
the staff screen in step 7.

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
Staff write them when rejecting (step 4, `RejectCompany`).

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
the domain, by `Company::moveTo`, so no caller can skip it). Staff take the company's row in the
same order (step 4, below).

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

## The staff side (step 4)

**One permission per job** (amendments 10 and 11(c)): twelve, in `B2BPermissions::staff()`, named as every
module's are, an action sharing one with its undo (suspend and reinstate, deactivate and activate).
Every one is per store and none is admin-only: any staff or admin role may be given any of them.
Holding one job never grants another — viewing a company does not open its papers, reviewing does
not suspend.

**Which store, and what a stranger is told.** A company's jobs are checked in **the account's home
store**, a type's in **the type's own store** (`StaffCompanyAction`, `StaffTypeAction`, after
Access's `StaffCustomerAction`). Someone holding the job in no store is `Unauthorized` before
anything is read; a company or a type of a store they do not cover answers exactly as one that
does not exist — `CompanyNotFound`, `TypeNotFound` — so the panel never confirms which ids are real.
A store they name themselves (adding a type, "Reviewed", the list's store filter) is refused as not
allowed. **A decision needs a staff member** (`decided_by`, `status_changed_by`), so the system —
which the permission check lets through — is refused approving, rejecting, suspending and
reinstating.

**One lock order for all of B2B** (lesson 37), each step inside the use case's own transaction:

1. the store's type-list lock, `b2b:types:<store>` — every change to a list, a correction that may
   **activate** a type, and a transfer of companies between types;
2. the account's lock, `b2b:account:<customer>` — several accounts in account order (moving a type's
   holders, on a deactivation or a transfer);
3. the company's row;
4. the application, read, not locked.

The tests record every advisory lock with its key and transaction level (`B2BFixtures::accountLocks`)
and prove both the level and the order. **A type's own row is read, never row-locked**: the store's
lock already serialises every writer of the lists, and a `FOR UPDATE` on a type would block the
foreign-key check of a company saving a draft that points at it — while a deactivation holding it
waits for that company's account lock, a deadlock the first review of step 4 found. A test records
every row lock on the type tables and expects none.

**Decisions** (`ApproveCompany`, `RejectCompany`, `SuspendCompany`, `ReinstateCompany`) run the domain's
state machine (§4.1): only a `PENDING` company has something to decide, asked first so a suspended
company's waiting application is refused for what it is; suspending remembers the status to return
to. Each is audited — deciding on the application, a status change on the company — with **what
staff wrote or marked by value** (a reason, a note, flags, requests' labels), as Access records a
block's reason. **The customer's email goes only once the decision has committed**
(`CompanyMessages`, `afterCommit`): approved with the note, rejected with the reason, suspended
with the reason; reinstating sends none. A rolled-back decision emails nobody.

**The staff member deactivating a type decides for its holders; the reviewer follows** (amendment
11(a), which reversed 10(e) and 10(i)). Approving asks for no choice about the type: the company
already carries what the deactivation gave it — the replacement, or the old type if it was left — and
approving keeps it. A waiting application whose type was deactivated since is marked for the reviewer,
for information only.

**Every change of a company's type goes one way** (`CompanyTypeCorrection`): the correction itself,
and moving a type's holders (`CompanyTypeHolders`) — when the type is deactivated with a replacement,
or transferred to another active type. `Company::correctType` refuses a **suspended** company in the
domain (10(h)), so no path changes one — moving holders skips it. A
**deactivated** type is taken only once staff confirm it becomes active again, and only by someone who
may also deactivate and activate that store's company types (8(b), 10(b)). An open draft follows only
while it still holds the company's type (§3.1). It never touches an application sent.

**The type lists** (`Add…`, `Rename…`, `Move…`, `RequireDocumentType`, `Deactivate…`, `Activate…`):
a type is **never deleted** (10(c)). Every change runs through `StaffTypeAction::change()` — its own
transaction, the store's lock first, the work, then, if anything changed, **the store's "copied"
notice cleared** (`TypeListsNotice`, 10(d)) and the audit, by value (a type's names are the store's
words, not personal data). **Deactivating a company type decides for its holders** (11): leave them,
move every one — approved ones included, suspended ones not — to another active type, or to **a new
type created in the same step** (which needs the job of adding types too), all in one transaction:
the new type, the deactivation and every move, or none of them. Each move is audited as
`b2b.company.type_replaced`. **`TransferCompanyType`** moves every holder of one active type to
another, both staying active — its own job, `b2b.company.transfer_type`; it changes neither list, so
it runs in its own transaction under the store's lock, not through `change()`, and leaves the notice.
Known limit: a company sending an application in the same instant its type is deactivated with a
replacement can still send it — sending takes the account's lock, not the store's, and the holders
are read once — so it may keep the old type; its reviewer then sees the mark above, and since
approving follows the company's type (11(a)), the reviewer corrects it if the replacement should apply.

**What staff read.** `ListCompanies` pages in SQL with every filter in the WHERE clause, so the total
never counts what the reader may not see (lesson 69): waiting companies first, the oldest sent first,
then the latest status change; search by name, CR number or tax number, a `%` meaning itself.
`ViewCompany` reads under the account's lock, shared, as the company's own page does, and shows the
applications sent — **never a draft** — through the same `ApplicationViews` the company sees, plus
who decided each. `DownloadCompanyDocument` opens a paper or a file answer of a **sent** application
only, and **audits each opening** without the file's id (10(f)), so the log tells a reader without
the private-files permission nothing about which file exists.

## The public contract (step 5)

**`B2BApi`** (§2.1) answers other modules with ids in and DTOs out, and checks no permission — the
calling use case checks its own. `company()` gives a `CompanyDto` with the type's names from the
home store's list — **both null while the company is still "Other"**: its type is not set yet, and
its own words are for the reviewing staff alone (amendment 13(b)) — and **never its documents**.
`isApproved()` is true only while the company is `APPROVED`, Sales's half of "may place an order".
An individual account, or a company account whose first application is still a draft, has no
company: null, null, false. `bankAccount(store)` gives the store's account, or null while bank
transfer is temporarily off (below). Plain reads: a type's row is read, never locked.

**A company is never approved as "Other"** (amendment 13(b)): `Company::approve` refuses it with
`CompanyTypeNotSet`, after the status check, and staff correct the type to a listed one first — one
that exists, or one an admin adds for it. `Company::correctType` never makes an approved company
"Other".

**Two events** (§6), both `ShouldDispatchAfterCommit` and dispatched inside the use case's own
transaction (`CompanyEvents`), so a change rolled back — or refused — tells nobody.
`CompanyStatusChanged` goes on every status change: a send (from no status when the first
application creates the company), an approval, a rejection, a suspension, a reinstatement, with the
reason the company now carries. `CompanyApplicationSubmitted` goes when an application leaves its
draft. A type corrected or an address changed is not a status change and tells nobody.

**The bank account an approved company transfers to** (amendment 12(b)) is three **per-store
Platform settings** B2B declares — `b2b.bank.iban`, `b2b.bank.name`, `b2b.bank.holder` — changed under
Platform's own `platform.settings.update`, with no B2B job. They **start empty**, which means "not set
yet": a Platform addition lets a text setting say it may be empty, and then its rules apply only to a
value that is not (platform.md §1.3, §9.4). `IbanRule` checks an IBAN's shape (two letters, two
digits, 11 to 30 letters and digits) and its check digits (ISO 13616, the number modulo 97), with
spaces ignored; the value is **kept as typed**, so up to 42 characters — 34 in groups of four. No
country's length is written in: the store knows its own bank; check digits 00, 01 and 99, which no
IBAN has, are refused though the remainder comes out right for them. **Bank transfer is on only while
all three are filled in** (amendment 13(c)); until then it is temporarily off and a company pays
through staff. `StoreBankAccount` is the one reader: `ViewMyCompany` shows the home store's account
**only while the company is `APPROVED`**, `B2BApi::bankAccount` gives it to Sales (null while off),
and `BankTransferLine` says which, in one line at the top of the Companies section of the settings
page — a Platform addition, `SettingsSectionLines`.

**Anonymizing an account** (amendments 12(a), 13(a), scenario 18) runs on Access's
`CustomerAnonymized`, **from the queue** (`AnonymizeCompany` → `CompanyAnonymizer`), in one
transaction under the account's lock. **The company is never deleted**: it and every application it
sent give up the name, CR number, tax number, address and an "Other" company's own words to
placeholders — "Deleted company", "Deleted", as Access leaves "Deleted customer" — and each sent
application its note, its answers and its papers
(`Company::anonymize`, `Application::anonymize`). The type, the status and its reason, staff's flags
and requests and every decision stay. **An unsent draft is deleted whole**, as discarding it would,
and recorded as `b2b.application.discarded`. The files are deleted through Platform last, once no row
holds them; the company is recorded once as `b2b.company.anonymized`, by the system, with how many
applications and files went. **A second time changes and records nothing**; an individual account has
nothing here.

**Why from the queue** (the review of step 5, amendment 13(a)): run inside Access's after-commit
callbacks, a failure here reached Access's nightly sweep, which then logged the account as not
anonymized although its own part was done, and B2B's part was never tried again. Queued, Access only
hands it over: five attempts over about 36 minutes (10 s, 1 min, 5 min, 30 min apart), then
`failed_jobs`. That needs an asynchronous connection — the database queue the application runs on
(handoff §3); on `sync`, as the tests run, the job runs at once and a failure reaches the sweep as
before. **Done once however many times it runs** — no unique-job lock is needed: the account's lock
lets one run at a time, and a run that finds the placeholders in place changes and records nothing; an
attempt that fails partway leaves nothing done, so the next one starts clean (tested with a trigger
that refuses the company's row). An application still **waiting** when its account is anonymized is
kept, emptied, in staff's queue: approving it is refused (`CompanyAccountDeleted`, 13(e)), and a
reviewer rejects it by hand.

**"Never approved as Other" is backed by the database** (13(d)): CHECK
`companies_approved_type_listed` refuses an approved company, or one suspended from approved, that is
"Other".

## The company's own screens (step 6)

**One page, the design's** (b2b.md §4.5, amendment 14), at `/{store}/{locale}/account/company`:
a status box and, under it, the form or what was sent, and a side column — what happens after
sending, how a company pays, what it may do before approval. Before the first send there is no
company, only a draft, and the page shows the draft alone. The design is look and behaviour: its
own fields, company types and structured address lose to the spec.

**It reaches the shop's frame through Access** (access.md amendment 50), never by being written into
it: `CustomerAccountPages` lists it beside the account's tabs for company accounts, and
`CompanyShopperLine` says one line under the header on every shop page while the company cannot
order — continue, finish, under review, not approved, suspended with its reason — and nothing once
approved. It answers from what Access hands it before reading anything (an individual account, or
an email not confirmed yet, costs no query), and then asks `CompanyStandings` for one row.

**The form saves itself.** Each field is posted alone when the person leaves it, each file the
moment it is chosen; `SaveApplicationDraft` changes only the fields sent. A value the domain
refuses comes back on its own field (`InvalidCompanyAttribute`'s attribute), a paper's refusal
beside its document type, an answer's beside its request, and anything else at the top of the form
— the shop's usual toast and message. Send checks that it is complete.

**Every application is numbered when it is sent** (§1.2, amendment 14(g)): `TW-CO-26-0001` — the
year as the home store's clock reads it, and a count restarting at `0001` each year, across every
store. The count is a row per year in `b2b.application_reference_counters`, moved on by one
statement inside the send's own transaction (`DatabaseApplicationReferenceCounter`, which refuses to
run outside one): the row stays locked until the send commits, so two sends never share a number,
and a refused send gives its number back — a year has no gaps. The company sees every number in its
history; staff see them on their screens (step 7) and find a company by one, whole and ignoring
case. The database backs it: a unique index, and CHECKs that a sent application has a number, a
draft none, all of one shape. Anonymizing keeps the number: it names nobody.

**Times are the home store's.** `CompanyPages` writes every time in the home store's time zone
(HANDOFF §4): UTC underneath, the store's clock on the screen, and the page never converts again.

**Uploads cannot be tested in a real browser here**: the browser plugin's test server drops the
files of a multipart body. They are tested over HTTP (`MyCompanyPageTest`); the browser test puts
the papers in through the use case and checks the page around them.

