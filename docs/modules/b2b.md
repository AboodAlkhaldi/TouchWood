# B2B — Module Specification

> **NOT ACCEPTED — read this first.**
> Drafted in a planning conversation with the owner on 2026-09-19 and 2026-09-20, ahead of the
> build and while Access was still being written. **Nothing here is final acceptance.** Every item,
> including those marked **[DECIDED]**, is the owner's answer *in that conversation* and must be
> **revisited before it is built**: put it to the owner again, section by section, and check it
> against `docs/HANDOFF.md`, `docs/modules/access.md` and the code as they stand then. Where this
> file and another module's approved spec disagree, that module's spec wins. Do not treat this
> document as an instruction to build.

**Status:** DRAFT, sections 1–3. Not accepted.
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
| `name` | The company's registered name, required. Personal-ish data: audited as "changed". |
| `company_type_id` | One of the types staff manage (§1.3). |
| `cr_number` | Commercial Registration number, required (handoff §8.1). |
| `tax_number` | Tax number, required (handoff §8.1). |
| `address` | The registered address: **B2B's own record, in Access's address scheme** **[DECIDED 2026-09-25]** (access.md §1.9). It uses that country's field shapes, so it validates and prints like every other address in the system, but it is B2B's row and not one of the customer's saved addresses. A registered address is the company's and lives as long as the company; a delivery address is the person's and they may delete it. One row with two owners would have to refuse its own owner's delete. |
| ~~`contact_name`, `contact_phone`~~ | **Not columns. The responsible person is the account holder** **[DECIDED 2026-09-25]**, read from Access (`AccessApi::customer`). Handoff §8.1 asks registration for "the responsible person and their phone"; the account already carries both, and the phone is **verified by SMS**, which a typed-in second number would not be. Two phone numbers that can disagree is a support case nobody can settle. |
| `status` | `PENDING`, `APPROVED`, `REJECTED` or `SUSPENDED` — **these four only** (handoff §8.2). Controls ordering and pricing, never sign-in. |
| `status_reason` | Why it was rejected or suspended. **Required for both** **[DECIDED 2026-09-19]**; shown to the customer and emailed. |
| `status_changed_at`, `status_changed_by` | When, and which staff member. |
| `home_store_id` | Taken from the account's home store (access.md §1.1). It decides **which staff may review it** (§3), not where the company may buy. |

- **The ordering rule has no special cases** (handoff §7.4, §8.2): a company account may order only
  while `status == APPROVED`. B2B publishes the status; **Sales** combines it with Access's part.
- **A `PENDING` company already sees company prices** (handoff §8.2) — the one exception to
  "`COMPANY` means approved" (handoff §6), and it applies to prices shown, never to ordering.
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
| `company_id` | The company it belongs to. |
| `state` | `DRAFT`, `SUBMITTED`, `APPROVED` or `REJECTED` (§4.2). |
| `note` | The customer's note with a reapplication (handoff §8.2). Optional. |
| `submitted_at` | Set when it leaves `DRAFT`. |
| `decided_at`, `decided_by`, `decision_reason` | Filled when staff approve or reject it. |
| documents | One row per uploaded document (§1.4). |

- **[DECIDED 2026-09-20] A half-finished wizard is saved as a `DRAFT`**: the customer can leave and
  come back, and their account shows that the application is unfinished. Nothing is reviewed until
  they submit it.
- **One open application at a time** (handoff §8.2): a company with a `DRAFT` or `SUBMITTED`
  application cannot start another. Reapplication is unlimited once the last one was decided.
- Submitting takes the company to `PENDING`; approving to `APPROVED`; rejecting to `REJECTED`
  (§4.1). **Reapplying never restores ordering in the meantime** (handoff §8.2).

### 1.3 Company types and document types

Both are **tables staff manage** **[DECIDED 2026-09-19]**, not lists in code (handoff §8.1 already
says document types are configurable).

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `name` | Arabic and English, both required, as everywhere in this system. |
| `position` | Their order on the form. |
| `is_active` | An inactive type cannot be chosen by a new application; applications that already use it keep it. |
| `is_required` | Document types only. **[DECIDED 2026-09-19, 2026-09-20]** The three known types — VAT certificate, commercial registration certificate, authorised signatory ID — are required and ship required; a type staff add later carries its own switch. |

A type in use is never deleted, only deactivated: the applications that reference it are permanent.

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
| `status(string $customerId): ?CompanyStatus` | The cheap question — Pricing asks it on every price resolution, so it is cached |
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
| Access | The account: its type, contact details, and its address scheme for the registered address | Exists (`AccessApi`) |
| Access | **The account's home store**, which decides who may review the company | **An Access change [FOUND 2026-09-25]**: `CustomerDto` carries the type, status, names, email, phone and locale, but **not `homeStoreId`**. One field on the DTO and one column read. Made in B2B's first build step, not before: Access is finished and nothing needs it yet. |
| Access | A message to the customer when staff reject or suspend (`SecurityMessages`, access.md §2.3) | **An Access change**: B2B's own message type, until Ops |
| Platform | A module uploading a private file for its own use | **A Platform change**, the same one the frontend spec needs (`frontend.md` §4.3 P1) |
| Platform | Media, the audit log, and the permission catalog | Exists |
| Access | The permission catalog, with the **group** each permission belongs to | **The change in `frontend.md` §4.3 P2** |

### 2.4 What B2B gives others

- `B2BApi` above.
- Events (§6): the company's status changed, so Pricing can drop what it cached and Ops can write
  to the customer later.

---

## 3 · Use cases

Permissions follow `{module}.{resource}.{action}`. Audiences as access.md §1.5: `every customer`
means automatic, own data only.

### 3.1 The company's own side

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `SaveApplicationDraft` — the wizard, saved as it goes | every customer (company accounts) | `b2b.company.apply` | Global |
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
| `ApproveCompany` | role | `b2b.company.review` | The account's home store |
| `RejectCompany` — **a reason is required** | role | `b2b.company.review` | The account's home store |
| `SuspendCompany` — from any status, **a reason is required** (handoff §8.2) | role | `b2b.company.suspend` | The account's home store |
| `ReinstateCompany` — ends a suspension (§4.1) | role | `b2b.company.suspend` | The account's home store |
| `ManageCompanyTypes` / `ManageDocumentTypes` — add, rename, reorder, deactivate | role | `b2b.types.manage` | Store-free (access.md amendment 4): the lists belong to no store |

Every change is audited (Platform). Company name, contact name, phone, address and the documents
are personal data: recorded only as "changed" (access.md §3.3, platform.md §1.5).
