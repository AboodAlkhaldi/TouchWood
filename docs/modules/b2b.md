# B2B — Module Specification

> **NOT ACCEPTED — read this first.**
> Drafted in a planning conversation with the owner on 2026-09-19 and 2026-09-20, ahead of the
> build and while Access was still being written. **Nothing here is final acceptance.** Every item,
> including those marked **[DECIDED]**, is the owner's answer *in that conversation* and must be
> **revisited before it is built**: put it to the owner again, section by section, and check it
> against `docs/HANDOFF.md`, `docs/modules/access.md` and the code as they stand then. Where this
> file and another module's approved spec disagree, that module's spec wins. Do not treat this
> document as an instruction to build.

**Status:** COMPLETE, sections 1–9. Reviewed with the owner section by section on 2026-09-25 and
2026-09-26 and **accepted**; the header above describes how it was drafted, not where it stands now.
**Tier:** 2. **Stage:** 3 (handoff §17), after Access and the frontend foundation.
**Depends on:** Platform, Access.
**Source:** `docs/HANDOFF.md` §6, §7.2, §7.4, §8, §14; `docs/modules/access.md`;
`docs/modules/platform.md`; the owner's answers of 2026-09-19 and 2026-09-20 (§9).

B2B owns **the company behind a company account**: its details, its documents, and the approval
that decides whether it may order and at which prices. It owns nothing about who the person is
(Access), what anything costs (Pricing) or how an order is placed (Sales).

## What this module does not own

| Concern | Owner |
|---|---|
| The account, its sign-in, its verification and its addresses | Access |
| Company prices and price lists (`audience = COMPANY`) | Pricing |
| Carts, checkout and the order itself | Sales |
| The IBAN shown for a bank transfer, and every payment | Payments (handoff §8.3) |
| Files in storage, the audit log, settings | Platform |
| Emails and SMS long term | Ops — Access's sender until then (access.md §2.3) |

---

## 1 · Aggregates and invariants

### 1.1 Company

**[DECIDED 2026-09-19] One customer account has at most one company, and a company belongs to
exactly one account.** Colleagues with their own logins under one company are not built (the
design's "Company staff" segment is not this stage).

**[DECIDED 2026-09-19] One company record is valid in every store.** It is not registered per
country: an approved company orders in KSA, Egypt and UAE alike.

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `customer_id` | The account (Access). Unique — one company per account. The account's type must be `COMPANY` (access.md §1.1); an individual account can never have one. |
| `name` | The company's registered name, required: one line, at most 200 characters (amendment 2). Personal-ish data: audited as "changed". |
| `company_type_id`, `company_type_other` | **Exactly one of the two** (amendment 2): one of the types staff manage (§1.3), **or "Other"** — the company's own words for what it is, one line, at most 100 characters. Staff may correct either: rewrite the words, or move the company to a listed type (§3.2). |
| `cr_number` | Commercial Registration number, required (handoff §8.1). **Loose** (amendment 2): one line, at most 50 characters of letters, digits, spaces and dashes; staff check it against the certificate. |
| `tax_number` | Tax number, required (handoff §8.1). Loose, as the CR number (amendment 2). |
| `address` | The registered address: **one block of text** **[DECIDED 2026-09-27, amendment 2]** — required, at most 500 characters, line breaks allowed and no other control characters, read by staff as typed. No map pin. It is the company's and lives as long as the company; a delivery address is the person's, a separate thing, and keeps Access's structured form. *(Replaces 2026-09-25's "B2B's own record in Access's address scheme": nothing downstream reads the address — invoices come from the external accounting system, handoff §12.6 — so its shape is the screen's business, and the screen may change it later.)* |
| ~~`contact_name`, `contact_phone`~~ | **Not columns. The responsible person is the account holder** **[DECIDED 2026-09-25]**, read from Access (`AccessApi::customer`). Handoff §8.1 asks registration for "the responsible person and their phone"; the account already carries both, and the phone is **verified by SMS**, which a typed-in second number would not be. Two phone numbers that can disagree is a support case nobody can settle. |
| `status` | `PENDING`, `APPROVED`, `REJECTED` or `SUSPENDED` — **these four only** (handoff §8.2). Controls ordering and pricing, never sign-in. |
| `status_reason` | Why it was rejected, suspended **or reinstated** — **required for all three** **[DECIDED 2026-09-19, 2026-09-26]**; shown to the customer. A reinstatement carries one too, so the history reads as a conversation rather than one side of it. |
| `status_changed_at`, `status_changed_by` | When, and which staff member. |
| `home_store_id` | Taken from the account's home store (access.md §1.1). It decides **which staff may review it** (§3), not where the company may buy. |

- **The ordering rule has no special cases** (handoff §7.4, §8.2): a company account may order only
  while `status == APPROVED`. B2B publishes the status; **Sales** combines it with Access's part.
**[DECIDED 2026-09-26] Every company account sees company prices, from the moment it exists.**
Before the email is confirmed, before an application is started, and in every status afterwards —
`PENDING`, `APPROVED`, `REJECTED`, `SUSPENDED` alike.

> **This widens handoff §8.2**, which grants company prices to `PENDING` and calls it "the one
> exception". The owner's reason: a company that can see its prices has something to finish the
> approval *for*. Handoff §6 and §8.2 were amended to match in B2B's first build step (handoff
> §0.1, 2026-09-26).

It leaves a cleaner rule than the one it replaces, and the two halves no longer share a source:

| Question | Answered by | Owner |
|---|---|---|
| Which prices do they see? | `customers.account_type == COMPANY` | **Access** |
| May they place an order? | `companies.status == APPROVED` | **B2B** |

So **Pricing never asks B2B anything.** It asks Access for the account type, which Access already
answers, and which cannot change for the life of an account — nothing to cache, nothing to
invalidate.
- **Blocking a person is Access's** `customers.status = BLOCKED`, never a company status
  (handoff §8.2).

**[DECIDED 2026-09-25] There is no company until an application is submitted.** A half-finished
wizard is a `DRAFT` application and nothing else (§1.2): the name, the CR number, the tax number,
the address and the documents live on the application until it is sent. Submitting creates the
company, `PENDING`.

Because `PENDING` is what grants company prices, a company row that existed from the first keystroke
would put somebody who opened the wizard and wandered off on company prices, and would fill the
review queue with nothing to review. Everything in that queue is something a person actually sent.

**[DECIDED 2026-09-25] What anonymizing an account reaches.** A company is never deleted. When
Access anonymizes its account (access.md §1.10) the company's personal fields go, **and so do its
uploaded documents**: a commercial registration certificate and a signatory's identity photograph
are that person's papers, and nothing defends keeping them once the account is emptied.

What stays is the company row, its status, and the decision record — who approved or rejected it,
when, and why. Deliberately unlike an **order**, which keeps its copy of an address because an
order is a commercial record of what was agreed; a document uploaded to prove who somebody is, is
not.

**[DECIDED 2026-09-19] Editing after approval.** The customer may freely change the address and
the contact details. Changing the **company name, CR number, tax number or documents** puts the
company back to `PENDING` — it cannot order until staff approve again — and the screen says so
before the change is saved.

**[DECIDED 2026-09-25] Editing while suspended is refused.** A `SUSPENDED` company may still change
its address and contact details, and may **not** touch the name, CR number, tax number or documents;
the screen refuses with the suspension's own reason. Suspension is a deliberate act by staff, and
an edit that sent the company back to `PENDING` would let a rename undo it. The way back is staff
reinstating them (§3.2).

### 1.2 Application

**[DECIDED 2026-09-19] Every application is kept**: what was sent, by whom, when, and what staff
decided. Staff can compare what was rejected with what has been sent now.

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `customer_id` | **The account**, always. A first draft has no company yet (§1.1), so the account is what owns an application. |
| `company_id` | The company, once there is one. Null on a first draft; set when it is submitted. |
| `name`, `company_type_id`, `company_type_other`, `cr_number`, `tax_number`, `address` | **[DECIDED 2026-09-26] A snapshot of what was sent**, copied onto the application, not read through the company. A draft may hold any of them empty; submitting needs them all, and a type staff have deactivated since the draft chose it must be chosen again (amendment 2). A staff correction of the type (§3.2) changes the company, never the application: it is what was sent. |
| `state` | `DRAFT`, `SUBMITTED`, `APPROVED` or `REJECTED` (§4.2). |
| `note` | The customer's note with a reapplication (handoff §8.2). Optional. |
| `submitted_at` | Set when it leaves `DRAFT`. |
| `decided_at`, `decided_by`, `decision_reason` | Filled when staff approve or reject it. `decision_reason` is the rejection's **required** reason, or the approval's **optional** note — empty when staff wrote none (amendment 1). |
| documents | One row per uploaded document (§1.4). |

**[DECIDED 2026-09-26] An application is a snapshot, not a pointer.** It carries its own copy of
every value sent with it.

Three reasons, and only the first is bookkeeping. It is the only way §1.2's own promise works —
*staff can compare what was rejected with what has been sent now* is impossible if the application
holds a note and the company holds one mutable set of values. It keeps a decision honest: an
approval is a decision about **particular values**, and values that can change underneath it
afterwards make the approval mean nothing. And a draft has nowhere else to live, now that there is
no company until submission.

The company row holds the **latest submitted** values — which is what "back to `PENDING`" means: the
new name is showing, and they cannot order until it is approved again. The application beside it is
the immutable record.

The company's `status_reason` and the application's `decision_reason` are not duplicates: one is
what the customer is told **today**, the other is what **that application** was told. Neither is to
be tidied away into the other.

- **[DECIDED 2026-09-20] A half-finished wizard is saved as a `DRAFT`**: the customer can leave and
  come back, and their account shows that the application is unfinished. Nothing is reviewed until
  they submit it.
- **One open application at a time** (handoff §8.2), **per account** — not per company, since a
  first draft has no company. Reapplication is unlimited once the last one was decided.
- **[DECIDED 2026-09-26] Submitting requires a confirmed email address.** It is refused until then,
  and the account says which step it is on (§4.3). Nothing else about the account is required:
  the phone belongs to ordering, not to applying.

**[DECIDED 2026-09-26] Reapplying starts from what was sent.** The new draft opens holding the
previous application's documents, already attached under their types, and the company replaces only
what the rejection was about. The rejected application keeps its own copies untouched, so the
comparison above still works.

The form shows **every active document type**, required ones marked. So a type staff added or made
required since the last application appears as a new, empty field — which is how a company rejected
for a paper nobody had asked for before is told to add it: the reason says why, and the field is
there to take it. Per §1.3 that never reaches a company already approved.

*Not built: a document required of **one** company alone.* Every requirement is a document type, and
types are global. If staff need something from a single company, the rejection reason asks for it
and the company adds it against an optional type. Raised with the owner, 2026-09-26 (§9).
- Submitting takes the company to `PENDING`; approving to `APPROVED`; rejecting to `REJECTED`
  (§4.1). **Reapplying never restores ordering in the meantime** (handoff §8.2).

### 1.3 Company types and document types

Both are **tables staff manage** **[DECIDED 2026-09-19]**, not lists in code (handoff §8.1 already
says document types are configurable).

| Attribute | Invariant |
|---|---|
| `id` | ULID. **No code or key** (amendment 2): nothing in the system behaves differently for one type, so a type is its id and its names. |
| `name` | Arabic and English, both required, as everywhere in this system: one line, **at most 100 characters** each, and **unique in each language, ignoring case** (amendment 2) — a dropdown never offers two identical choices. A document type and a company type may share a name. |
| `position` | Their order on the form, 0 to 10,000. Two types may share one; the English name then decides. |
| `is_active` | An inactive type cannot be chosen by a new application; applications that already use it keep it. **A draft that chose it before it was deactivated must choose again before it is sent** (amendment 2); nothing submitted is touched. |
| `is_required` | Document types only. **[DECIDED 2026-09-19, 2026-09-20]** The three known types — VAT certificate, commercial registration certificate, authorised signatory ID — are required and ship required; a type staff add later carries its own switch. |

**What ships** (amendment 2). Company types, in this order: مؤسسة فردية — Sole Proprietorship /
Individual Establishment; شركة ذات مسؤولية محدودة — Limited Liability Company; شركة مساهمة — Joint
Stock Company; شركة مساهمة مبسطة — Simplified Joint Stock Company; شركة تضامن — General Partnership;
شركة توصية بسيطة — Limited Partnership. Document types, required: شهادة ضريبة القيمة المضافة — VAT
certificate; شهادة السجل التجاري — Commercial registration certificate; هوية المفوّض بالتوقيع —
Authorised signatory ID.

**"Other" is not a type** (amendment 2). The form always offers it last, whatever staff have set up,
and choosing it asks the company to say what it is in its own words. It cannot be deactivated or
deleted by mistake, because there is no row to do it to.

A type in use is never deleted, only deactivated: the applications that reference it are permanent.

**[DECIDED 2026-09-26] A change to the types is never retroactive.** Deactivating a type, or making
an optional one required, changes **the next application and nothing else**. A company already
approved is never asked for a new paper and never stops being approved because a setting moved.

Deliberately unlike Access's address formats, where an address that no longer fits its country's
form cannot be used for an order (access.md amendment 41). The difference is what the record is: an
address is a statement of where to deliver **now**, and a stale one misdirects a parcel; a document
is evidence a human being **already examined and accepted**, and a settings change is not a reason
to un-accept it.

### 1.4 Documents

An uploaded file belonging to one application and one document type.

- **Private** Platform media (handoff §5.5: "company registration documents" are named there as
  private files). They are never public, never deduplicated (platform.md §1.4), and are reached
  only through a signed link that lasts 30 minutes.
- The customer uploads them during the application. Uploading needs **the Platform contract method
  for a module to upload a file for its own use** — the same addition the frontend spec asks for
  (`frontend.md` §4.3 P1): a customer holds no media permission.
- B2B registers a `MediaUsage` with Platform (project rule): a company document is a **blocking**
  use — deleting the file is refused while the application exists — because the application is a
  permanent record.
- **[DECIDED 2026-09-19] No expiry is tracked.** A document is a file with its type and the date it
  was uploaded. Nothing reminds anyone, and nothing lapses on its own.

---

## 2 · Public contract

### 2.1 `Modules\B2B\Public\Contracts\B2BApi`

Everything another module needs, and nothing more. Ids in, DTOs out; never an Eloquent model
(handoff §2).

| Method | For |
|---|---|
| `company(string $customerId): ?CompanyDto` | Sales and the admin screens: the company behind an account, or null for an individual |
| `status(string $customerId): ?CompanyStatus` | The shop's banner (§4.3) and the staff screens. **Not Pricing** — since 2026-09-26 prices follow the account type, which Access owns, so nothing asks this on a hot path and nothing caches it |
| `isApproved(string $customerId): bool` | Sales's half of `canPlaceOrder` (handoff §7.4) |

`CompanyDto` carries the id, the account id, the name, the type's name in both languages, the
status, and the reason when there is one. It never carries documents: a document is reached only
through its own signed link, by staff with the permission (§3).

### 2.2 DTOs and enums (`Public/Dto`, `Public/Enums`)

- `CompanyDto`, plain `final readonly` (handoff §4.3).
- `CompanyStatus`: `PENDING`, `APPROVED`, `REJECTED`, `SUSPENDED`. A backed enum stored as a
  string, and the only company status anywhere in the system.

### 2.3 What B2B needs from other modules

| From | What | State |
|---|---|---|
| Access | The account: its type and contact details | Exists (`AccessApi::customer`) |
| ~~Access~~ | ~~Its address scheme, for the registered address~~ | **Not needed** (amendment 2): the registered address is one block of text. This row had said "exists", which was wrong — `AccessApi` offers a customer's saved addresses, not a store's form, its check or its printed layout — found while planning step 2, 2026-09-27. |
| Access | **The account's home store**, which decides who may review the company | **Done in B2B step 1** (access.md amendment 48): `CustomerDto::$homeStoreId`. It was found missing on 2026-09-25. |
| Access | A message to the customer when staff **approve, reject or suspend** — **[DECIDED 2026-09-26]**; a reinstatement sends none, being the suspension notice disappearing (`SecurityMessages`, access.md §2.3). The approval carries the staff member's **optional note**; each carries **one plain link to the shop's front door**, the same for everyone (amendment 1) | **Done in B2B step 1** (access.md amendment 48): `companyApproved`, `companyRejected`, `companySuspended`, until Ops |
| Platform | **The IBAN to transfer to**, a per-store setting **[DECIDED 2026-09-26]** — a Saudi and an Egyptian bank account are not the same account. Shown by B2B on the company page **only while `APPROVED`**, since only an approved company can order. Payments owns it from stage 7 and this setting goes then | **A Platform setting**, declared by B2B |
| Platform | A module uploading a private file for its own use | **Exists** — `PlatformApi::uploadMediaFor(ModuleUploadDto)`, built in stage 2b. Platform checks the permission B2B names, not `platform.media.upload`, which a customer will never hold |
| Platform | Media, the audit log, and the permission catalog | Exists |
| Access | The permission catalog, with the **group** each permission belongs to | **Exists** — `PermissionGroup` shipped in stage 2b; B2B's permissions join the `Customers` group |

### 2.4 What B2B gives others

- `B2BApi` above.
- Events (§6): the company's status changed, so Pricing can drop what it cached and Ops can write
  to the customer later.

---

## 3 · Use cases

Permissions follow `{module}.{resource}.{action}`. Audiences as access.md §1.5: `every customer`
means automatic, own data only.

### 3.1 The company's own side

**[DECIDED 2026-09-26] `b2b.company.apply` never reaches an individual account.** The audience is
`account_type == COMPANY`, and the **handler refuses** an individual — not merely a screen that does
not offer the link. An account type cannot change (handoff §7.2), so this is a fact about the
account, checked where it cannot be walked around.

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `SaveApplicationDraft` — the wizard, saved as it goes | company accounts only | `b2b.company.apply` | Global |
| `SubmitApplication` — takes the company to `PENDING` | every customer | `b2b.company.apply` | Global |
| `ViewMyCompany` — details, status, reason, history | every customer | `b2b.company.apply` | Own data |
| `UpdateCompanyContact` — address and contact details | every customer | `b2b.company.update` | Own data |
| `UpdateCompanyDetails` — name, CR number, tax number, documents; **sends it back to `PENDING`** | every customer | `b2b.company.update` | Own data |

### 3.2 Staff

**[DECIDED 2026-09-20] The home store's staff review a company** — whoever covers the store the
account registered in, as staff already see customers by home store (access.md §3.3).

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `ListCompanies` / `ViewCompany` | role | `b2b.company.view` | The account's home store |
| `DownloadCompanyDocument` — a signed link, 30 minutes | role | `b2b.company.view` | The account's home store |
| `ApproveCompany` — an **optional note**, and the screen tells staff it is sent to the customer with the approval email (amendment 1) | role | `b2b.company.review` | The account's home store |
| `RejectCompany` — **a reason is required** | role | `b2b.company.review` | The account's home store |
| `SuspendCompany` — from any status, **a reason is required** (handoff §8.2) | role | `b2b.company.suspend` | The account's home store |
| `ReinstateCompany` — ends a suspension (§4.1) | role | `b2b.company.suspend` | The account's home store |
| `CorrectCompanyType` — rewrite an "Other" in the right words, or move the company to a listed type, when it chose wrongly or did not know (amendment 2). Changes the company, never the application it sent, and does not send it back to `PENDING` | role | `b2b.company.review` | The account's home store |
| `ManageCompanyTypes` / `ManageDocumentTypes` — add, rename, reorder, deactivate | role | `b2b.types.manage` | Store-free (access.md amendment 4): the lists belong to no store |

Every change is audited (Platform). Company name, contact name, phone, address and the documents
are personal data: recorded only as "changed" (access.md §3.3, platform.md §1.5).

---

## 4 · State machines

### 4.1 The company

```
                    (no company)
                         │  SubmitApplication
                         ▼
   ┌──────────────────► PENDING ◄──────────────────┐
   │                      │                        │
   │ UpdateCompanyDetails │ Approve      Reject    │ SubmitApplication
   │ (name, CR, tax, docs)│                        │ (reapplication)
   │                      ▼                        │
   │                  APPROVED ──── Reject ───► REJECTED
   │                      │                        ▲
   └──────────────────────┤                        │
                          │                        │
              Suspend     │     Suspend            │
                          ▼                        │
                      SUSPENDED ── Reinstate ──────┘
                                   (to the status it held before)
```

- **`SUSPENDED` remembers where it came from.** Reinstating returns the company to the status it
  held when it was suspended — an approved company comes back approved, and one suspended while
  pending is pending again. Anything else would either grant ordering nobody decided to grant, or
  withdraw an approval nobody decided to withdraw. The column is `status_before_suspension`.
- **There is no way out of `SUSPENDED` but `Reinstate`** (§1.1): a suspended company cannot edit
  its way back to `PENDING`.
- Nothing reaches `APPROVED` except a staff decision on a submitted application.

### 4.2 The application

```
DRAFT ──Submit──► SUBMITTED ──Approve──► APPROVED
                      │
                      └────Reject─────► REJECTED ──(a new draft)──► DRAFT
```

- `DRAFT` is the only state the customer can change. Once submitted it is the staff's.
- `APPROVED` and `REJECTED` are terminal: a decided application is never reopened, only succeeded
  by another.

### 4.3 What the account is told, before there is a company

Three states that are not company statuses — they are the **absence** of a company (§1.1) — and the
shop says which one the account is in:

| The account | Shown | May order |
|---|---|---|
| Email not confirmed | "Confirm your email" — Access's own banner (frontend.md F4) | No |
| Email confirmed, no application | **"Continue your application"** | No |
| A `DRAFT` exists | "Finish and submit your application" | No |

Company prices are shown in all three (§1.1).

---

## 5 · Tables

All in schema `b2b`. Every id is `char(26)` (ULID); timestamps are `timestamptz`.

| Table | Columns |
|---|---|
| `b2b.companies` | `id` PK · `customer_id` **unique** FK → `access.customers` · `name` · `company_type_id` NULL FK · `company_type_other` NULL — exactly one of the two · `cr_number` · `tax_number` · `address` (§5.1) · `home_store_id` FK → `platform.stores` · `status` · `status_before_suspension` NULL · `status_reason` NULL · `status_changed_at` NULL · `status_changed_by` NULL FK → `access.staff_users` · timestamps |
| `b2b.applications` | `id` PK · `customer_id` FK · `company_id` NULL FK · `state` · the snapshot, each NULL while a draft: `name`, `company_type_id`, `company_type_other`, `cr_number`, `tax_number`, `address` · `note` NULL · `submitted_at` NULL · `decided_at` NULL · `decided_by` NULL FK · `decision_reason` NULL · timestamps |
| `b2b.application_documents` | `id` PK · `application_id` FK ON DELETE CASCADE · `document_type_id` FK · `media_id` FK → `platform.media` **RESTRICT** · `uploaded_at` |
| `b2b.company_types` | `id` PK · `name_ar`, `name_en` — `varchar(100)`, each unique on `lower()` · `position` 0–10,000 · `is_active` · timestamps |
| `b2b.document_types` | `id` PK · `name_ar`, `name_en` — `varchar(100)`, each unique on `lower()` · `position` 0–10,000 · `is_active` · `is_required` · timestamps |

### 5.1 The address

One `text` column, at most 500 characters, line breaks allowed (§1.1, amendment 2). No map pin, no
`recipient_name` and no `phone` — the responsible person is the account holder (§1.1). It lives on
the company, and as part of the snapshot on each application.

### 5.2 Indexes

| Index | Why |
|---|---|
| `companies (customer_id)` unique | One company per account (§1.1), decided by the database rather than by a handler |
| `companies (home_store_id, status)` | The staff list: the companies of my stores, by status |
| `companies (status)` | The queue across every store, for a Super Admin |
| `applications (customer_id, state)` | "Has this account an open application?" — asked on every submit, and for the banner on every shop page |
| `applications (company_id, submitted_at DESC)` | One company's history, newest first |
| `application_documents (application_id)` | The documents of one application |
| **Partial unique** `applications (customer_id) WHERE state IN ('DRAFT','SUBMITTED')` | One open application at a time, enforced where two tabs cannot both win |

---

## 6 · Events

### Published

| Event | When | Who listens |
|---|---|---|
| `CompanyStatusChanged` — `customerId`, `companyId`, `from`, `to`, `reason` | Every status change | **Ops**, later, to write to the customer. **Not Pricing**: prices follow the account type (§1.1) |
| `CompanyApplicationSubmitted` — `companyId`, `applicationId` | An application leaves `DRAFT` | Staff notifications, once Ops exists |

### Consumed

| Event | From | What B2B does |
|---|---|---|
| `CustomerAnonymized` | Access | Clears the company's personal fields and **deletes the uploaded documents** (§1.1). The company row, its status and the decision record stay |
| `StoreCreated` | Platform | Nothing. A company is valid in every store (§1.1), so a new country needs no company data |

---

## 7 · Errors

Each extends the module's `B2BError`, which extends `Shared\Domain\Error\DomainError`, with its own
type string and HTTP status (handoff §11).

| Error | Status | When |
|---|---|---|
| `NotACompanyAccount` | FORBIDDEN | An individual account reached an application use case (§3.1) |
| `CompanyNotFound` | NOT_FOUND | No company for that account — or not one this staff member may see |
| `ApplicationNotFound` | NOT_FOUND | — |
| `ApplicationAlreadyOpen` | CONFLICT | A draft or submitted application already exists for the account |
| `EmailNotVerified` | CONFLICT | Submitting before the address is confirmed (§1.2) |
| `MissingRequiredDocument` | UNPROCESSABLE | Submitting without every active required type |
| `ApplicationNotEditable` | CONFLICT | Changing an application that is no longer `DRAFT` |
| `CompanySuspended` | CONFLICT | Editing the name, CR number, tax number or documents while suspended (§1.1) |
| `InvalidCompanyAttribute` | UNPROCESSABLE | A value the domain refuses — a CR number too long, an unknown company type |
| `DocumentTypeInUse` | CONFLICT | Deleting a type an application references; deactivate it instead (§1.3) |
| `TypeNameTaken` | CONFLICT | Adding or renaming a type to a name another type of its kind already has, in either language, ignoring case (§1.3, amendment 2) |
| `CompanyTypeInactive` | CONFLICT | Submitting a draft whose chosen type staff have deactivated since; choose again (§1.3, amendment 2) |

---

## 8 · Test scenarios

**The lifecycle**

1. A company account with a confirmed email and no application: sees company prices, cannot order, is told to continue its application.
2. A draft saved, left and reopened: everything typed is still there, and there is still no company row.
3. Submitting creates the company `PENDING`; the application becomes `SUBMITTED`.
4. Submitting without a required document is refused, and nothing is created.
5. Submitting before the email is confirmed is refused.
6. A second submit while one is open is refused — including two requests at once, which the partial unique index has to decide.
7. Approving lets them order, rejecting does not, and each is emailed — the rejection with its reason, the approval with the staff member's note when they wrote one.
8. Reapplying after a rejection carries the previous documents; the rejected application keeps its own copies unchanged.
9. A document type made required since the last application appears on the new one — and an approved company is never asked for it.
9a. A draft whose company type staff deactivated since it was chosen must choose again before it is sent; a company already submitted keeps its type (amendment 2).
9b. Choosing "Other" asks for the type in words, and the company carries exactly one of a listed type or its own words; staff may correct either, which changes the company and never the application it sent (amendment 2).

**The rules that protect somebody**

10. An individual account is refused every application use case by the handler, not only by the screen.
11. A suspended company may change its address, and may not change its name, CR number, tax number or documents.
12. Reinstating returns the company to the status it held before the suspension, never to `PENDING`.
13. A company sees company prices in every status, rejected and suspended included.
14. The IBAN is absent from the page in every status but `APPROVED`.
15. Staff of another store cannot see, approve, reject or suspend a company whose home store is not theirs — and a Super Admin can.
16. A rejection, a suspension or a reinstatement without a reason is refused; an approval needs none (amendment 1).
17. A document is reachable only through a signed link, only by staff holding the permission, and the link expires.
18. Anonymizing the account deletes the documents and keeps the company row, its status and the decision record.
19. Every change is audited, and the personal fields are recorded as "changed", never by value.

---

## 9 · Open questions

| # | Question | State |
|---|---|---|
| 1 | A document required of **one company alone**, rather than a type required of everyone | **Not built** (owner, 2026-09-26). The rejection reason asks for it and the company adds it against an optional type. Revisit if staff find themselves writing the same sentence over and over |
| 2 | Uploading **proof of a bank transfer** | **Out of scope**: it belongs to an order, so Sales, stage 6 (owner, 2026-09-26) |
| 3 | Settling with staff over WhatsApp instead | **Out of scope**, and deliberately outside the system (owner, 2026-09-26) |
| 4 | The IBAN setting | B2B's until **Payments**, stage 7, which takes it over (§2.3) |
| 5 | Colleagues sharing one company | **Not built** (§1.1); the design's "Company staff" segment waits for a later stage |
| 6 | `homeStoreId` on Access's `CustomerDto` | **Done** in B2B step 1 (§2.3; access.md amendment 48) |

### Amendments during the build

Changes to what was accepted on 2026-09-26, each with the owner's agreement. Each is applied in
place in the sections named; this table records what changed and why.

| # | Where | Change | Why | Source |
|---|---|---|---|---|
| 1 | §1.2, §2.3, §3.2, §8 (7, 16) | **Approving takes an optional note, and the emails carry one plain link.** (a) The spec disagreed with itself: §1.1 and §3.2 required a reason for rejecting, suspending and reinstating, while scenario 16 refused "every staff action without a reason", approving included. Approving takes an **optional note**; the screen tells staff that the note is sent to the customer with the approval email. (b) The three decision emails carry **one link, the same for every customer: the shop's front door** (`APP_URL`), with no store, language or page in it. | (a) A reason on the normal path is typing nobody reads, but a word of welcome is worth being able to send — and staff must know it leaves the building. (b) The owner asked for the plainest link. | Owner, 2026-09-27 (B2B step 1) |
| 2 | §1.1, §1.2, §1.3, §2.3, §3.2, §5, §5.1, §7, §8 (9a, 9b) | **The company's details and the two type lists, before the tables are built.** (a) **The registered address is one block of text** — required, at most 500 characters, line breaks allowed, no map pin — replacing 2026-09-25's "B2B's own record in Access's address scheme"; §2.3 had said Access already offered that scheme, which was wrong. (b) **Six company types ship**, in the owner's words and order, and **"Other" is built into the form**, not a row: the company then says what it is (at most 100 characters), and carries exactly one of a listed type or its own words. **Staff may correct the type** (`CorrectCompanyType`). (c) **Types have no code**, only an id and two names; names are **at most 100 characters and unique in each language, ignoring case** (`TypeNameTaken`). (d) The three document types ship with the Arabic names شهادة ضريبة القيمة المضافة, شهادة السجل التجاري, هوية المفوّض بالتوقيع. (e) **A draft whose type was deactivated must choose again** before it is sent (`CompanyTypeInactive`). (f) **Name, CR number and tax number are checked loosely** — one line; the name at most 200 characters, the two numbers at most 50 of letters, digits, spaces and dashes — and staff check them against the documents. | (a) The owner's rule: if it is only shape, keep it plain; if business logic depends on it, structure it. Nothing downstream reads the address — invoices come from the external accounting system (handoff §12.6), deliveries use the person's own addresses — so it is shape, and the screen may change it later. (b) A company that does not fit the list still has to be able to apply, and staff know the legal forms better than the person filling the form. (f) Three countries' number formats are three rule sets to keep right; the documents are what staff trust anyway. | Owner, 2026-09-27 (B2B step 2) |
