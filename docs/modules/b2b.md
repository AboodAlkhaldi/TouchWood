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
| `status_reason` | Why it was rejected, suspended **or reinstated** — **required for all three** **[DECIDED 2026-09-19, 2026-09-26]**; shown to the customer. A reinstatement carries one too, so the history reads as a conversation rather than one side of it. At most 1000 characters, line breaks allowed (amendment 3). |
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
the contact details. Changing the **company name, CR number, tax number, type or documents** puts the
company back to `PENDING` — it cannot order until staff approve again — and the screen says so
before the change is saved.

**[DECIDED 2026-09-27] One path for every change of those details** (amendment 3): a **new
application**, the same wizard — draft → sent → `PENDING` → decided — whether it is the account's
first, a reapplication after a rejection, or new details from an approved company. The company keeps
ordering while the draft is unsent; it goes back to `PENDING` when the application is sent. No other
status is needed, and handoff §8.2 allows none. The **type** is one of these details: a change of
legal form is always seen by staff before the company orders again. A staff correction of the type
(§3.2) is not — it changes the company without sending it back.

**[DECIDED 2026-09-25] Editing while suspended is refused.** A `SUSPENDED` company may still change
its address and contact details, and may **not** touch the name, CR number, tax number, type or documents;
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
| `note` | The customer's note with a reapplication (handoff §8.2). Optional; at most 1000 characters, line breaks allowed (amendment 3). |
| `submitted_at` | Set when it leaves `DRAFT`. |
| `decided_at`, `decided_by`, `decision_reason` | Filled when staff approve or reject it. `decision_reason` is the rejection's **required** reason, or the approval's **optional** note — empty when staff wrote none (amendment 1); at most 1000 characters, line breaks allowed (amendment 3). |
| documents | **One file per document type** (§1.4, amendment 3). |

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
- **[DECIDED 2026-09-27] A draft is kept until it is sent, and the customer may discard it**
  (amendment 3), which deletes the files it holds — those no other application of theirs still
  holds. Nothing sent is ever discarded.
- **One open application at a time** (handoff §8.2), **per account** — not per company, since a
  first draft has no company. Reapplication is unlimited once the last one was decided.
- **[DECIDED 2026-09-26] Submitting requires a confirmed email address.** It is refused until then,
  and the account says which step it is on (§4.3). Nothing else about the account is required:
  the phone belongs to ordering, not to applying.
- **[DECIDED 2026-09-28] Each value is checked when the draft is saved** (amendment 4): a value
  that is there must already be a valid one, and a wrong one is refused on its own field while the
  person is still on that page. Only **completeness** waits for sending — every value filled, every
  required file, every flag replaced, every request answered.
- **[DECIDED 2026-09-28] A note on every application**, the first included (amendment 4): one
  optional note, at most 1000 characters, line breaks allowed.

**[DECIDED 2026-09-26, 2026-09-28] Reapplying starts from what was sent.** The new draft of an
existing company opens with **the details the company holds now** — so an address change or a
staff correction of the type since is not lost — and **the files of the last application it
sent**, already attached under their types (amendment 4). The company replaces only what the
rejection was about. The rejected application keeps its own copies untouched, so the comparison
above still works.

**[DECIDED 2026-09-28] A rejection can say exactly what to fix and what to add** (amendment 4),
and only a rejection can — there is no status for "waiting for the company" (handoff §8.2):

- **Flags.** Staff may flag any item the rejected application sent — the name, the type, the CR
  number, the tax number, the address, or any document. The next draft shows each flagged item
  marked, and **sending is refused until every one is replaced**: a flagged field must hold a
  different value, and a flagged document a newly uploaded file.
- **Requests.** Staff may ask this one company for extra items, each **a text answer or a file**,
  with a label the staff member writes ("A bank letter confirming the account"). The next draft
  shows them as a section of their own, and **every request must be answered before sending**.

Flags and requests belong to the rejected application — they are part of what it was told — and
the answers to the requests belong to the application that answers them, as every value it sends
does. Staff set them when rejecting (step 4); the company meets them on its next draft (step 3).

The form shows **every active document type**, required ones marked. So a type staff added or made
required since the last application appears as a new, empty field — which is how a company rejected
for a paper nobody had asked for before is told to add it: the reason says why, and the field is
there to take it. Per §1.3 that never reaches a company already approved.

*A document required of **one** company alone* was first left unbuilt (owner, 2026-09-26) and is
now built as a **request** on a rejection (above; owner, 2026-09-28, amendment 4). Document types
are the store's (§1.3, amendment 5), the same for every company of that store; what one company
alone is asked for travels with its rejection.
- Submitting takes the company to `PENDING`; approving to `APPROVED`; rejecting to `REJECTED`
  (§4.1). **Reapplying never restores ordering in the meantime** (handoff §8.2).

### 1.3 Company types and document types

Both are **tables staff manage** **[DECIDED 2026-09-19]**, not lists in code (handoff §8.1 already
says document types are configurable).

**[DECIDED 2026-09-28] Both lists are per store** (amendment 5): a legal form or a paper in one
country is not one in another. Each store keeps its own company types and its own document types,
and **a company uses its home store's lists** — the store its account registered in (§1.1). The
company itself stays valid in every store. Names are unique **within a store**. Staff manage the
lists of the stores their role covers (`b2b.types.manage`, per store, §3.2).

| Attribute | Invariant |
|---|---|
| `id` | ULID. **No code or key** (amendment 2): nothing in the system behaves differently for one type, so a type is its id and its names. |
| `name` | Arabic and English, both required, as everywhere in this system: one line, **at most 100 characters** each, and **unique in each language, ignoring case** (amendment 2) — a dropdown never offers two identical choices. A document type and a company type may share a name. |
| `position` | Their order on the form, 0 to 10,000. Two types may share one; the English name then decides. |
| `is_active` | An inactive type cannot be chosen by a new application; applications that already use it keep it. **A draft that chose it before it was deactivated must choose again before it is sent** (amendment 2); nothing submitted is touched. **Deactivating, staff choose how it looks to new applications: hidden, or shown greyed out** (amendment 5), for both kinds of type. |
| `store_id` | The store whose list it is (amendment 5). |
| `is_required` | Document types only. **[DECIDED 2026-09-19, 2026-09-20]** The three known types — VAT certificate, commercial registration certificate, authorised signatory ID — are required and ship required; a type staff add later carries its own switch. |

**What ships** (amendment 2) — **in every store, for now** (amendment 5). These are Saudi forms and
papers; each store's admins change their own store's list when it opens to companies. Company types,
in this order: مؤسسة فردية — Sole Proprietorship /
Individual Establishment; شركة ذات مسؤولية محدودة — Limited Liability Company; شركة مساهمة — Joint
Stock Company; شركة مساهمة مبسطة — Simplified Joint Stock Company; شركة تضامن — General Partnership;
شركة توصية بسيطة — Limited Partnership. Document types, required: شهادة ضريبة القيمة المضافة — VAT
certificate; شهادة السجل التجاري — Commercial registration certificate; هوية المفوّض بالتوقيع —
Authorised signatory ID.

**[DECIDED 2026-09-28] Every store gets them** (amendment 6(a)): they are written into each store
whose list of that kind is empty — the stores that exist when B2B's tables are migrated, and every
store created afterwards, when Platform publishes `StoreCreated` (§6). The launch stores, which the
seeder creates after the migrations, are among the second. Writing only ever adds: a store that
already has company types keeps them exactly as they are, and the same for document types. A store
the lists are written into is marked **copied, not yet reviewed** — one mark for both lists
(`b2b.store_type_lists`, §5) — and a store that already carries the mark keeps it as it is, so a
store whose admins reviewed their lists stays reviewed. While it is set, the store's types page
tells its admins the lists were copied from the Saudi store, until one of them edits a type or
marks the lists reviewed. Step 3a keeps the mark as data only: clearing it comes with the type
screens (step 4), and the notice is drawn on the staff screen (step 7).

**[DECIDED 2026-09-28] A company's type comes from its home store's list** (amendment 6(c), (d)).
Sending a draft whose listed type is not one of the home store's is refused as
`InvalidCompanyAttribute`, as an unknown type is (§7). The rule crosses two tables, so it is kept
**in code only**, with nothing in the database behind it — as is the rule that staff flag only a
document the rejected application sent a file under (§1.2). The module's README names every such
rule.

**"Other" is not a type** (amendment 2). The form always offers it last, whatever staff have set up,
and choosing it asks the company to say what it is in its own words. It cannot be deactivated or
deleted by mistake, because there is no row to do it to.

A type in use is never deleted, only deactivated: the applications that reference it are permanent.

**[DECIDED 2026-09-28] Deactivating a company type that companies hold** (amendment 5): staff choose
to **leave** those companies with it, or to **replace** it with another type of the same store for
every company holding it — approved ones included. A replacement is a staff correction, like
`CorrectCompanyType` (§3.2): it changes the company, never an application it sent, and sends nobody
back to `PENDING`; it is audited on each company. **An application sent and still waiting whose
type was deactivated is marked for the reviewer**, who uses the replacement if one was set, keeps
the type, or rejects with a note.

**[DECIDED 2026-09-28] A draft never sends anything deactivated** (amendment 5). A value or a file
under a type deactivated since the draft chose or received it stays in the draft, **marked "no
longer accepted"**, and the draft cannot be sent until it is removed — a document's file taken
out, a company type chosen again. Something with nothing in it is dropped without a word. This
replaces the earlier rule that a file uploaded before its type was deactivated goes with the
application (amendments 2 and 4).

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

- **[DECIDED 2026-09-27] Exactly one file per document type** (amendment 3): uploading again
  replaces it. A two-sided ID is one PDF. The file types and the size limit are Platform's for every
  private file: PDF, JPEG or PNG, up to 10 MB (platform.md §1.4).
- ~~A document under a type staff have deactivated since it was uploaded goes with the application
  when it is sent.~~ **Replaced by amendment 5:** it is marked "no longer accepted" and must be
  removed before sending (§1.3). Only a type that is both required and still offered is asked for.
- **[DECIDED 2026-09-28] Nothing blocks the company removing a file from its own draft**
  (amendment 5). A file only the draft holds is deleted; one carried from the last application sent
  stays with that application and simply leaves the draft.
- **[DECIDED 2026-09-28] The company may open its own files** (amendment 5): a link that lasts 30
  minutes, to a file of one of the account's own applications and nothing else.

- **Private** Platform media (handoff §5.5: "company registration documents" are named there as
  private files). They are never public, never deduplicated (platform.md §1.4), and are reached
  only through a signed link that lasts 30 minutes.
- The customer uploads them during the application. Uploading needs **the Platform contract method
  for a module to upload a file for its own use** — the same addition the frontend spec asks for
  (`frontend.md` §4.3 P1): a customer holds no media permission.
- B2B registers a `MediaUsage` with Platform (project rule): a company document is a **blocking**
  use — deleting the file is refused while the application exists — because the application is a
  permanent record. A file answering a request (above) is held the same way.
- **[DECIDED 2026-09-28] B2B deletes the files it no longer holds** (amendment 4): the one a new
  upload replaced, and those of a discarded draft — each only if no other application still holds
  it — through **`PlatformApi::deleteMediaFor`**, the mirror of `uploadMediaFor`: a module deletes a
  file it owns, checked against that module's own permission. It is a Platform addition made in B2B
  step 3, and it changes nothing about how staff delete media or about the module boundaries.
  **It deletes only private files** (amendment 5), and **B2B uploads every file inside its own use
  cases**, so every file B2B holds, and may later delete, is one it created. If any use of the file
  remains, the delete is refused; it never detaches another module's use. Platform logs the delete
  as it logs a module's upload.
- **[DECIDED 2026-09-28] Company papers stay out of the media library** (amendments 5 and 6(b)).
  Private files are listed there only to holders of the **admin-only** Platform permission
  `platform.media.private.view` — a Super Admin always, an admin when a Super Admin gives it to
  their role (access.md amendment 49) — and only for someone who can already open the library: the
  permission adds private files to it and opens it to nobody. They are listed **as a list only**:
  name, upload date, where it is used; never opened there, with nothing to describe or delete — any
  change goes through the company's account. Choosing "private" when uploading in the library is
  offered only to holders and refused from anyone else. Everyone else does not see them at all. A
  company's papers are opened from its page in B2B, where the opening is B2B's to record.
- **[DECIDED 2026-09-28] Nothing new is uploaded under a type staff have deactivated** (amendment
  4), and nothing under a type that does not exist.
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
| Platform | A module **deleting** a file it owns — a replaced document, a discarded draft's files, later an anonymized account's | **A Platform addition in B2B step 3** (amendment 4): `PlatformApi::deleteMediaFor`, the mirror of `uploadMediaFor`, checked against the permission the module names. Staff deletion of media is untouched |
| Platform | Media, the audit log, and the permission catalog | Exists |
| Access | The permission catalog, with the **group** each permission belongs to | **Exists** — `PermissionGroup` shipped in stage 2b. B2B's staff permissions join the **`Companies`** group, the one already reserved for this module (amendment 5; the text had said `Customers`). The two automatic customer permissions have no group |
| Platform | A permission to see private files in the media library, as a list only | **A Platform addition in B2B step 3** (amendment 5): admin-only, a Super Admin always; the library lists private files only to its holders |

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

**[DECIDED 2026-09-28] How the company's own side works** (amendment 5):

- **Every** use case below refuses an individual account with `NotACompanyAccount`, reading and
  changing the address included — one rule for the whole of B2B.
- A draft is **started** (`StartApplicationDraft`): the first empty, a later one from the company as
  it is now plus the files of the last application sent (§1.2). Starting while a draft is open
  returns that draft; while a sent application waits it is refused (`ApplicationAlreadyOpen`).
- The draft's actions name no application: they act on **the account's one open application**. No
  draft → `ApplicationNotFound`; one already sent → `ApplicationNotEditable`.
- **Saving changes only the fields sent**; a field left out keeps its value. A newly chosen type
  that is inactive is refused (`CompanyTypeInactive`).
- **While the company is suspended**, starting, saving, uploading and answering are refused
  (`CompanySuspended`); discarding a draft is allowed.
- **While a draft is open**, changing the company's address writes it into the draft too; a staff
  correction of the type goes into the draft only if the draft's type is still the one the company
  had.
- A flagged field counts as replaced when its value differs **exactly, after trimming**, from what
  the rejected application sent — an address change or a staff correction since included. A flag on
  a document type deactivated since stops blocking; its file is then removed (§1.3).
- Answering a request that is not the last rejection's → `RequestNotFound`; with the wrong kind
  (text for a file, a file for text) → `AnswerKindMismatch`.
- **`ViewMyCompany` always answers**: which stage the account is at before a company exists (§4.3),
  the open draft with its values, files, flags, requests and answers, and the types the form offers
  (greyed ones marked); once there is a company, its details, status and reason, and its
  **history** — the applications it sent, newest first, each with its values, papers and dates, its
  state, its reason or note, and its flags and requests. **No staff names.**

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `StartApplicationDraft` — the first draft, or a new one from the company now plus the last files (amendment 5) | company accounts only | `b2b.company.apply` | Own data |
| `SaveApplicationDraft` — the wizard, saved as it goes | company accounts only | `b2b.company.apply` | Global |
| `AttachApplicationDocument` / `RemoveApplicationDocument` / `AnswerApplicationRequest` — the draft's files and answers (amendment 5) | company accounts only | `b2b.company.apply` | Own data |
| `OpenMyApplicationFile` — a 30-minute link to one of the account's own files (amendment 5) | company accounts only | `b2b.company.apply` | Own data |
| `SubmitApplication` — takes the company to `PENDING` | every customer | `b2b.company.apply` | Global |
| `DiscardApplicationDraft` — throws an unsent draft away, with the files only it holds (amendment 3) | company accounts only | `b2b.company.apply` | Own data |
| `ViewMyCompany` — details, status, reason, history | every customer | `b2b.company.apply` | Own data |
| `UpdateCompanyContact` — address and contact details | every customer | `b2b.company.update` | Own data |
| ~~`UpdateCompanyDetails`~~ — **not a use case of its own** (amendment 3): the name, CR number, tax number, type and documents change only by a **new application** — `SaveApplicationDraft`, then `SubmitApplication` — which sends the company back to `PENDING` | — | — | — |

**[DECIDED 2026-09-28] What the company's own actions leave in the audit log** (amendment 4):
**sending an application, discarding a draft, and changing the address** — each by the account,
with personal values recorded only as "changed". Each draft save and each upload is not audited:
the wizard saves as the person types, and every one of those is part of an application that is then
either sent or discarded. **Everything the customer typed is recorded only as "changed"; only the
states, and a listed company type's id, by value** (amendment 5). Sending and discarding sit on the
**application**, the address change on the **company**. Platform keeps its own entries for the
files (§1.4).

### 3.2 Staff

**[DECIDED 2026-09-20] The home store's staff review a company** — whoever covers the store the
account registered in, as staff already see customers by home store (access.md §3.3).

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `ListCompanies` / `ViewCompany` | role | `b2b.company.view` | The account's home store |
| `DownloadCompanyDocument` — a signed link, 30 minutes | role | `b2b.company.view` | The account's home store |
| `ApproveCompany` — an **optional note**, and the screen tells staff it is sent to the customer with the approval email (amendment 1) | role | `b2b.company.review` | The account's home store |
| `RejectCompany` — **a reason is required**; staff may also **flag** items sent wrong and **request** extra text answers or files from this company (§1.2, amendment 4) | role | `b2b.company.review` | The account's home store |
| `SuspendCompany` — from any status, **a reason is required** (handoff §8.2) | role | `b2b.company.suspend` | The account's home store |
| `ReinstateCompany` — ends a suspension (§4.1) | role | `b2b.company.suspend` | The account's home store |
| `CorrectCompanyType` — rewrite an "Other" in the right words, or move the company to a listed type, when it chose wrongly or did not know (amendment 2). Changes the company, never the application it sent, and does not send it back to `PENDING` | role | `b2b.company.review` | The account's home store |
| `ManageCompanyTypes` / `ManageDocumentTypes` — add, rename, reorder, deactivate (hidden or greyed out); for a company type, leave or replace it on the companies holding it (§1.3, amendment 5) | role | `b2b.types.manage` | **Per store** (amendment 5): the lists of the stores the role covers |

`ApproveCompany` and `RejectCompany` **mark an application whose company type was deactivated after
it was sent** (§1.3, amendment 5), so the reviewer decides with that in front of them.

Every change is audited (Platform). Company name, contact name, phone, address and the documents
are personal data: recorded only as "changed" (access.md §3.3, platform.md §1.5).

---

## 4 · State machines

### 4.1 The company

Redrawn as a table by amendment 3; the earlier drawing had an arrow from `APPROVED` to `REJECTED`,
which is gone.

| From | Event | To |
|---|---|---|
| (no company) | `SubmitApplication` — the account's first | `PENDING` |
| `PENDING` | `ApproveCompany` | `APPROVED` |
| `PENDING` | `RejectCompany`, with a reason | `REJECTED` |
| `APPROVED` | `SubmitApplication` — new name, CR number, tax number, type or documents | `PENDING` |
| `REJECTED` | `SubmitApplication` — a reapplication | `PENDING` |
| `PENDING`, `APPROVED`, `REJECTED` | `SuspendCompany`, with a reason | `SUSPENDED` |
| `SUSPENDED` | `ReinstateCompany`, with a reason | the status it held before |

- **No way from `APPROVED` to `REJECTED`** (owner, 2026-09-27, amendment 3). Rejecting decides a
  sent application, and an approved company has none waiting; staff who must stop an approved
  company suspend it.
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
| `b2b.application_flags` (amendment 4) | `id` PK · `application_id` FK ON DELETE CASCADE — **the rejected application** · `field` NULL (`name`, `company_type`, `cr_number`, `tax_number`, `address`; CHECK `application_flags_field`) · `document_type_id` NULL FK **RESTRICT** — exactly one of the two (CHECK `application_flags_one_item`); one flag per field or document type per application (§5.2) |
| `b2b.application_requests` (amendment 4) | `id` PK · `application_id` FK ON DELETE CASCADE — **the rejected application** · `kind` (`TEXT`, `FILE`; CHECK `application_requests_kind`) · `label` — the staff member's words, one line, at most 200 characters (CHECK `application_requests_label_text`) · `position` 0–10,000 (CHECK `application_requests_position_range`) |
| `b2b.application_request_answers` (amendment 4) | `id` PK · `application_id` FK ON DELETE CASCADE — **the answering application** · `request_id` FK **RESTRICT** · `text` NULL (at most 1000, line breaks allowed; CHECK `application_request_answers_text_text`) · `media_id` NULL FK → `platform.media` **RESTRICT** — exactly one (CHECK `application_request_answers_one_value`), matching the request's kind (in code only, amendment 5(g)); one answer per request per application (§5.2) |
| `b2b.company_types` | `id` PK · `store_id` FK → `platform.stores` **RESTRICT** (amendment 5) · `name_ar`, `name_en` — `varchar(100)`, each unique on (`store_id`, `lower()`) (§5.2) · `position` 0–10,000 · `is_active` · `inactive_display` NULL — how an inactive one shows, `HIDDEN` or `GREYED` (amendment 5), present exactly while the type is inactive (CHECKs `company_types_inactive_display` and `company_types_inactive_display_when_inactive`) · timestamps |
| `b2b.document_types` | `id` PK · `store_id` FK → `platform.stores` **RESTRICT** (amendment 5) · `name_ar`, `name_en` — `varchar(100)`, each unique on (`store_id`, `lower()`) (§5.2) · `position` 0–10,000 · `is_active` · `inactive_display` NULL — `HIDDEN` or `GREYED`, present exactly while inactive (CHECKs `document_types_inactive_display` and `document_types_inactive_display_when_inactive`) · `is_required` · timestamps |
| `b2b.store_type_lists` (amendment 6(a)) | `store_id` PK (`store_type_lists_pkey`), FK → `platform.stores` ON DELETE CASCADE (`store_type_lists_store`) · `copied_not_reviewed` — set when the starting lists are written into the store, cleared once its admins have reviewed them (§1.3); no default · `updated_at`. One row per store the lists were written into |

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
| **Unique** `application_documents (application_id, document_type_id)`, named `application_documents_one_per_type` | One file per type (amendment 3); its leading column also serves "the documents of one application" |
| **Partial unique** `applications (customer_id) WHERE state IN ('DRAFT','SUBMITTED')` | One open application at a time, enforced where two tabs cannot both win |
| **Unique** `company_types (store_id, lower(name_ar))` and `(store_id, lower(name_en))`, named `company_types_name_ar_unique` and `company_types_name_en_unique`; the same two on `document_types`, named `document_types_name_ar_unique` and `document_types_name_en_unique` | Names unique in each language, ignoring case, **within one store**; two stores may share a name (§1.3, amendments 2 and 5) |
| **Partial unique** `application_flags (application_id, field) WHERE field IS NOT NULL`, named `application_flags_one_per_field`, and `application_flags (application_id, document_type_id) WHERE document_type_id IS NOT NULL`, named `application_flags_one_per_document` | One flag per field, and one per document type, on a rejected application (amendment 4) |
| **Unique** `application_request_answers (application_id, request_id)`, named `application_request_answers_one_per_request` | One answer per request per application (amendment 4); its leading column also serves "the answers of one application" |
| `application_documents (media_id)`, named `application_documents_media`, and `application_request_answers (media_id)`, named `application_request_answers_media` | "Which applications hold this file?" — asked by B2B's media usage before every delete of a file (§1.4) |

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
| `StoreCreated` | Platform | **Writes the starting company types and document types into the new store**, each kind only where the store has none, and marks its lists **copied, not yet reviewed** (§1.3, amendment 6(a)). A company still needs nothing here: it is valid in every store (§1.1) |

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
| `FlaggedItemNotReplaced` | UNPROCESSABLE | Sending while a field or document the last rejection flagged is unchanged (§1.2, amendment 4). One general message for every item: "Replace every item marked in the last decision before you send the application." (amendment 6(e)) |
| `RequestNotAnswered` | UNPROCESSABLE | Sending while a request of the last rejection has no answer (§1.2, amendment 4) |
| `DocumentTypeInactive` | CONFLICT | Uploading under a document type that is inactive or does not exist (§1.4, amendment 4) |
| `DocumentNoLongerAccepted` | UNPROCESSABLE | Sending a draft that still holds a file under a document type deactivated since (§1.3, amendment 5); a company type deactivated since is `CompanyTypeInactive` |
| `RequestNotFound` | NOT_FOUND | Answering a request that is not one of the last rejection's (§3.1, amendment 5) |
| `AnswerKindMismatch` | UNPROCESSABLE | A text answer to a file request, or a file to a text request (§3.1, amendment 5) |
| `CompanySuspended` | CONFLICT | Editing the name, CR number, tax number or documents while suspended (§1.1) |
| `InvalidCompanyStatus` | CONFLICT | A change the company's status does not allow: deciding a company with no application waiting, suspending one already suspended, reinstating one that is not (§4.1, amendment 3) |
| `InvalidCompanyAttribute` | UNPROCESSABLE | A value the domain refuses — a CR number too long, an unknown company type, or a listed type that is not one of the home store's (§1.3, amendment 6(d)) |
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
9c. An approved company changing its name, CR number, tax number, type or documents does it by a new application: it keeps ordering while the draft is unsent, and is `PENDING` from the moment it is sent (amendment 3).
9d. One file per document type: a second upload replaces the first. A discarded draft takes the files only it held with it, never one an earlier application still holds (amendment 3).
9e. A value is refused on its own field when the draft is saved; completeness is checked only when it is sent (amendment 4).
9f. After a rejection with flags, the next draft cannot be sent until every flagged field holds a different value and every flagged document a new file; after one with requests, until every request is answered with a text or a file as asked (amendment 4).
9g. A new draft of an existing company starts from the company as it is now — an address change or a staff type correction carried — and the files of the last application sent (amendment 4).
9h. Uploading under an inactive or unknown document type is refused; a replaced or discarded file is deleted through Platform only when no other application still holds it (amendment 4).
9i. A draft holding a file or a company type deactivated since shows it "no longer accepted", and cannot be sent until it is removed or chosen again (amendment 5).
9j. Each store has its own company and document types; a company is offered its home store's (amendment 5).
9k. Starting a draft while one is open returns it; while a sent application waits it is refused; the draft's actions act on the one open application (amendment 5).
9l. While suspended, starting, saving, uploading and answering are refused, and discarding is allowed (amendment 5).

**The rules that protect somebody**

10. An individual account is refused every application use case by the handler, not only by the screen.
11. A suspended company may change its address, and may not change its name, CR number, tax number, type or documents.
12. Reinstating returns the company to the status it held before the suspension, never to `PENDING`.
12a. An approved company cannot be rejected; only suspended (amendment 3).
13. A company sees company prices in every status, rejected and suspended included.
14. The IBAN is absent from the page in every status but `APPROVED`.
15. Staff of another store cannot see, approve, reject or suspend a company whose home store is not theirs — and a Super Admin can.
16. A rejection, a suspension or a reinstatement without a reason is refused; an approval needs none (amendment 1).
17. A document is reachable only through a signed link that expires — by staff holding the permission, or by the account that uploaded it (amendment 5) — and private files appear in the media library only, as a list, to holders of the private-files permission.
18. Anonymizing the account deletes the documents and keeps the company row, its status and the decision record.
19. Every change is audited, and the personal fields are recorded as "changed", never by value.

---

## 9 · Open questions

| # | Question | State |
|---|---|---|
| 1 | A document required of **one company alone**, rather than a type required of everyone | ~~Not built (owner, 2026-09-26)~~ — **built as a request on a rejection** (owner, 2026-09-28, amendment 4): staff ask this company for a text answer or a file, and the next draft cannot be sent without it |
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
| 3 | §1.1, §1.2, §1.4, §3.1, §4.1, §5.2, §7, §8 (9c, 9d, 11, 12a) | **The company and its applications, before their tables are built.** (a) **No way from `APPROVED` to `REJECTED`**: the §4.1 drawing had that arrow; rejecting decides a sent application, and staff stop an approved company by suspending it. §4.1 is redrawn as a table. (b) **One path for every change of the registered details** — the first application, a reapplication, new details from an approved company — draft → sent → `PENDING` → decided; `UpdateCompanyDetails` is therefore not a use case of its own. The **type** is one of those details. No new status: handoff §8.2 allows four. (c) **Exactly one file per document type**; uploading again replaces it. (d) **Reasons and notes** — staff's reasons, the approval note, the customer's note — hold **at most 1000 characters, line breaks allowed**. (e) **A draft is kept until it is sent, and the customer may discard it** (`DiscardApplicationDraft`), with the files only it holds. (f) `InvalidCompanyStatus` for a change the status does not allow. | (a) A decision needs something to decide on. (b) An approval is a decision about the values staff saw; every other route would let values change underneath it. (c) The owner's choice: simplest for staff; a two-sided ID becomes one PDF. (e) Nobody loses work by coming back late, and files go only when the customer says so. | Owner, 2026-09-27 (B2B step 2) |
| 4 | §1.2, §1.3 (§9 #1), §1.4, §2.3, §3.1, §3.2, §5, §7, §8 (9e–9h) | **Applying, before step 3 is built.** (a) **Rejections can say exactly what to fix and what to add** — only rejections, no new status: staff may **flag** any item sent (a field or a document), which the next draft must **replace** before it is sent (a different value, a new file); and **request** extra items from this one company, each **a text answer or a file** with a staff-written label, **all required** before sending. Flags and requests belong to the rejected application; answers to the application that gives them. Staff set them in step 4; the company meets them in step 3. This builds §9 #1, left unbuilt on 2026-09-26. (b) **A new draft of an existing company starts from the company as it is now plus the files of the last application sent.** (c) **B2B deletes the files it no longer holds** — a replaced one, a discarded draft's — through a new **`PlatformApi::deleteMediaFor`**, the mirror of `uploadMediaFor`, without touching how staff delete media or the module boundaries. (d) **Each value is checked when a draft is saved**; completeness when it is sent. (e) **A note on every application**, the first included. (f) **Nothing new under an inactive or unknown document type.** (g) **The audit log** gets the company's sending, discarding and address changes — not each draft save or upload. | (a) A reason in prose left the company to guess which item was wrong, and a paper one company alone needed had no place to go. (b) A staff correction or an address change must not be lost to a reapplication. (c) The owner decided files go when replaced or discarded, and Platform had no way for a module to delete its own. (d) A mistake shows where it is made. (g) The decisions are recorded; the typing is not. | Owner, 2026-09-28 (B2B step 3, phase 0) |
| 5 | §1.3, §1.4, §2.3, §3.1, §3.2, §5, §7, §8 (9i–9l, 17) | **Step 3, after the plan was read** — the owner's answers to the 26 questions the step-3 plan raised. (a) **Company and document types are per store**; a company uses its home store's lists; the Saudi lists ship in every store for now; `b2b.types.manage` becomes per store. (b) **Deactivating a type**: hidden or greyed out, for both kinds; for a company type, the companies holding it are left or replaced (staff correction, no `PENDING`, audited per company); a waiting application with a deactivated type is marked for its reviewer. (c) **A draft never sends anything deactivated**: marked "no longer accepted", to be removed or chosen again — replacing the earlier "an old file goes with the application". (d) **The company's side**: one rule refusing individual accounts everywhere; an explicit start; actions on the one open application; saves change only the fields sent; suspended → no draft work but discarding; address changes and staff corrections carried into an open draft; flags "replaced" when exactly different; new errors `RequestNotFound`, `AnswerKindMismatch`, `DocumentNoLongerAccepted`; `ViewMyCompany` always answers, history without staff names; **the company may open its own files**. (e) **Files**: `deleteMediaFor` deletes private files only and refuses while any use remains; B2B uploads inside its own use cases; Platform logs the delete; **private files leave the media library** except, as a list, for a new admin-only permission. (f) **The log**: typed values as "changed", states and type ids by value; sending and discarding on the application, the address on the company. (g) B2B's staff permissions in the **Companies** group; the events stay in step 5; the three two-table rules are code-only, named in the README; anonymizing deletes request answers and their files. | The owner's answers of 2026-09-28, given after workflow 1 of the step-3 pilot read the spec and the code and found where they did not yet meet. | Owner, 2026-09-28 (B2B step 3) |
| 6 | §1.3, §1.4, §6, §7 | **Step 3a, before it is built** — the owner's answers to the questions its re-plan raised. (a) **Every store gets the Saudi lists** through a `StoreCreated` listener, the launch stores (created by the seeder after the migrations) and any store opened later alike, until its admins change them; **the store's types page tells its admins the lists were copied from the Saudi store**, until one of them edits a type or marks the lists reviewed (the notice is data in 3a, drawn on the staff screen in step 7). §6's `StoreCreated → Nothing` no longer holds. (b) **The private-files permission** adds private files to the media library only for someone who can already open it; a private row shows its name, date and where it is used, with nothing to describe or delete — any change goes through the company's account; choosing "private" when uploading in the library is offered only to holders and refused from anyone else. (c) Two more cross-table rules are **code-only**, named in the README with the other three: a company's type comes from its home store's list; staff flag only a document the rejected application sent a file under. (d) A draft sent with another store's company type is refused as `InvalidCompanyAttribute`, as §7 already says for an unknown type. (e) Sending with a flagged item not replaced gets **one general message**: "Replace every item marked in the last decision before you send the application." (f) The spec text 3a makes out of date is corrected in 3a's own PR. | The owner's answers of 2026-09-28, after workflow 2 of the pilot stopped before building to ask them. | Owner, 2026-09-28 (B2B step 3a) |
| 7 | §1.2, §1.3, §1.4, §5, §5.2, §6, §7 | **Step 3a, as built** — the spec text brought up to what step 3a built, as amendment 6(f) asks; no rule changes. (a) §1.2 said document types "stay global"; they are per store since amendment 5(a). (b) **Amendment 6 applied in place**: §1.3 says how every store gets the starting lists and the "copied, not yet reviewed" mark, and that a company's type comes from its home store's list, in code only; §1.4 names the private-files permission, `platform.media.private.view`, and says what the library shows and offers; §6 says what B2B does on `StoreCreated`; §7 gives `FlaggedItemNotReplaced`'s one general message, and another store's type as `InvalidCompanyAttribute`. (c) **§5** gains the table `b2b.store_type_lists`, the types' `inactive_display` column with its two CHECKs, the request tables' named CHECKs, and the types' `RESTRICT` keys to `platform.stores`; **§5.2** gains the per-store unique names, the flags' and answers' uniques, and the two `media_id` indexes. | Amendment 6 had been recorded as a row only, and §5 named only what step 2 had built. | Owner, 2026-09-28 (B2B step 3a: the corrections the integrator writes) |
