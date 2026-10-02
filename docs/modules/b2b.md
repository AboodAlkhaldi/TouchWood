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

**[DECIDED 2026-10-02, owner] A company per store, ordering only where it is approved**
(amendment 18). Each store approves its own companies: an account may hold a company in each store
it applies in, and ordering in a store needs **that store's** company to be `APPROVED` — a Saudi CR
and tax number are checked by Saudi staff, an Egyptian one by Egyptian staff. A company whose store
is turned off orders nowhere until it is approved in another. *(Reverses 2026-09-19's "one company
record is valid in every store; an approved company orders in KSA, Egypt and UAE alike".)*

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `customer_id` | The account (Access). **One company per account and store** (owner, 2026-10-02, amendment 18 — was: one company per account): an account may hold a company in each store it applies in. The account's type must be `COMPANY` (access.md §1.1); an individual account can never have one. |
| `name` | The company's registered name, required: one line, at most 200 characters (amendment 2). Personal-ish data: audited as "changed". |
| `company_type_id`, `company_type_other` | **Exactly one of the two** (amendment 2): one of the types staff manage (§1.3), **or "Other"** — the company's own words for what it is, one line, at most 100 characters. Staff may correct either: rewrite the words, or move the company to a listed type (§3.2). |
| `cr_number` | Commercial Registration number, required (handoff §8.1). **Loose** (amendment 2): one line, at most 50 characters of letters, digits, spaces and dashes; staff check it against the certificate. |
| `tax_number` | Tax number, required (handoff §8.1). Loose, as the CR number (amendment 2). |
| `address` | The registered address. **[DECIDED 2026-09-30] Picked from the account's saved addresses** (amendment 16(f)) — any store's, each in its store's format (access.md §1.9), and only one its format still accepts — **and kept as a copy**: its text as the format writes it, plus which saved address it came from (§5.1). Editing or deleting that address in the address book changes neither the company nor an application; picking another is how the company's address changes. No map pin. *(Replaces amendment 2's "one block of text", typed on the company page, which itself had replaced 2026-09-25's "B2B's own record in Access's address scheme": the owner wants the address in the country's own format, entered once in the address book and chosen from there.)* |
| ~~`contact_name`, `contact_phone`~~ | **Not columns. The responsible person is the account holder** **[DECIDED 2026-09-25]**, read from Access (`AccessApi::customer`). Handoff §8.1 asks registration for "the responsible person and their phone"; the account already carries both, and the phone is **verified by SMS**, which a typed-in second number would not be. Two phone numbers that can disagree is a support case nobody can settle. |
| `status` | `PENDING`, `APPROVED`, `REJECTED` or `SUSPENDED` — **these four only** (handoff §8.2). Controls ordering and pricing, never sign-in. |
| `status_reason` | Why it was rejected, suspended **or reinstated** — **required for all three** **[DECIDED 2026-09-19, 2026-09-26]**; shown to the customer. A reinstatement carries one too, so the history reads as a conversation rather than one side of it. At most 1000 characters, line breaks allowed (amendment 3). |
| `status_changed_at`, `status_changed_by` | When, and which staff member. |
| `home_store_id` | **The store the company applied in** (amendment 18 — was: the account's home store). It decides **which staff may review it** (§3), the lists it chooses from (§1.3, §1.4), its bank account and its clock — and, since a company per store, **where it may order**: ordering in a store needs that store's company to be `APPROVED`. |

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

**[DECIDED 2026-09-29] The personal fields are the name, the CR number, the tax number and the
address** (amendment 12(a)) — a sole proprietor's numbers identify a person. Each is replaced by a
placeholder on the company and on every application it sent, and each sent application's note and
text answers go with them; its papers and answer files are deleted. The company's type, its status,
the flags and requests staff wrote, and the decision record stay. **An unsent draft is deleted
whole**, with the files only it holds, as discarding it would: nothing in it was ever reviewed.

**[DECIDED 2026-09-29] The rest of what anonymizing reaches** (amendment 13(a)). A company still
"Other" gives up **its own words for its type** as well, to the same placeholder: a sole proprietor's
words can name them; the type stays "Other". An application **still waiting** for staff stays in
their queue, emptied, and a reviewer rejects it by hand like any other — the account can never sign
in again, so **approving it is refused** (`CompanyAccountDeleted`, 13(e)). **The work runs from
the queue, retried when it fails, and is done once** however many times it runs: a second run finds
nothing left to change and records nothing.

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

**[DECIDED 2026-09-25, 2026-09-29] Editing while suspended is refused.** A `SUSPENDED` company may
**not** touch the name, CR number, tax number, type or documents, **nor its address** (amendment
9(d), which reverses the 2026-09-25 "may still change its address"); the screen refuses with the
suspension's own reason. Suspension is a deliberate act by staff, and an edit that sent the company
back to `PENDING` would let a rename undo it; with the address frozen too, nothing is ever written
into a suspended company's draft. **Staff do not change a suspended company's type either**
(amendment 10(h)): a correction is refused (`CompanySuspended`), and when its type is deactivated
and replaced on the companies holding it, a suspended company is skipped and keeps the old type, as
a company left with it does — so neither the company nor its draft changes while it is suspended.
The contact details are the account holder's, kept by Access, and stay theirs to change. The way
back is staff reinstating them (§3.2).

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
| `reference` | **[DECIDED 2026-09-29] Its number, given when it is sent** (amendment 14(g)): `TW-CO-`, the year it was sent as two digits — in its home store's time zone —, a dash, and a count that starts again at `0001` each year: `TW-CO-26-0001`, `TW-CO-26-0002`…, then `TW-CO-27-0001`. At least four digits, more past 9999. Unique, counted across every store, and given inside the send, so a send that fails takes no number. A draft has none. The company sees it and so do staff, who can search the company list by it (§3.2). Anonymizing keeps it: it names nobody. |
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
and **a company uses its home store's lists** — the store it applied in (§1.1, amendment 18; was:
the store its account registered in, the company staying valid in every store). Names are unique
**within a store**. Staff manage the
lists of the stores their role covers (`b2b.company_type.*` and `b2b.document_type.*`, per store,
§3.2 and amendment 10).

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
tells its admins the lists were copied from the Saudi store, until **any change to either of that
store's lists** — a type added, renamed, reordered, made required or optional, deactivated or
activated again, a correction that reactivates one included — or until one of them marks the lists
reviewed (amendment 10). Step 3a keeps the mark as data only; step 4 clears it, and the notice is
drawn on the staff screen (step 7).

**[DECIDED 2026-09-28] A company's type comes from its home store's list** (amendment 6(c), (d)).
Sending a draft whose listed type is not one of the home store's is refused as
`InvalidCompanyAttribute`, as an unknown type is (§7). The rule crosses two tables, so it is kept
**in code only**, with nothing in the database behind it — as is the rule that staff flag only a
document the rejected application sent a file under (§1.2). The module's README names every such
rule.

**"Other" is not a type** (amendment 2). The form always offers it last, whatever staff have set up,
and choosing it asks the company to say what it is in its own words. It cannot be deactivated or
deleted by mistake, because there is no row to do it to.

**[DECIDED 2026-09-29] A company is never approved as "Other"** (amendment 13(b)). The words are
the company's hint to the reviewer, not a type: **approving is refused while the company is "Other"**
(`CompanyTypeNotSet`), and staff first correct it to a listed type of its home store — one that
exists, or one an admin adds for it — then approve. For the same reason **an approved company is never
corrected to "Other"**. Other modules see a company that is still "Other" as having **no type set
yet**, never its words (§2.1).

**[DECIDED 2026-09-29] A type is never deleted** (amendment 10), in use or not: staff deactivate it
— hidden or greyed out — and may **activate it again**. The applications that reference a type are
permanent, and a type added by mistake is simply deactivated and hidden.

**[DECIDED 2026-09-28, 2026-09-29] Deactivating a company type that companies hold** (amendments 5
and 11): **the staff member deactivating it decides, once, for every company holding it** — to
**leave** them with it, to **replace** it with another active type of the same store, or to
**replace it with a new type created in the same step** (amendment 11(b)): the new type is added —
at the old type's position on the form unless staff give another —, the old one deactivated, and
every holder moved to the new one, all or nothing. Every company
holding it is moved — approved ones included, a suspended one excepted, which keeps the old type
(amendment 10(h)). A replacement is a staff correction, like `CorrectCompanyType` (§3.2): it changes
the company, never an application it sent, and sends nobody back to `PENDING`; it is audited on each
company. The old type may be activated again later; the companies moved off it stay where they are.

**[DECIDED 2026-09-29] The reviewer follows that decision** (amendment 11(a), which reverses 10(e)
and 10(i)). Approving asks for no choice about the type: the company already carries what the
deactivation gave it — the replacement, or the old type if it was left — and approving keeps it. An
application sent and still waiting whose type was deactivated since is **marked for the reviewer,
for information only**, beside the type the company holds now. A reviewer who disagrees corrects
the type (§3.2) or rejects with a note.

**[DECIDED 2026-09-29] Moving every company of one active type to another** (amendment 11(c)):
staff may move all the companies holding a type to another active type of the same store, neither
being deactivated — a clear transfer between two types that both stay offered. It is the same staff
correction, on every holder at once, suspended ones excepted (10(h)), each audited; it is its own
job, `b2b.company.transfer_type`. It changes no list, so it leaves the store's "copied" notice as it
is.

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
  minutes, to a file of one of the account's own applications and nothing else. Any other file is
  refused as `ApplicationFileNotFound`, the same whether or not it exists (amendment 9(c)).

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
- **[DECIDED 2026-09-28] Company papers stay out of the media library** (amendments 5, 6(b) and
  8(a)). Private files are listed there only to holders of the **admin-only** Platform permission
  `platform.media.private.view` — a Super Admin always, an admin when a Super Admin gives it to
  their role (access.md amendment 49) — and only for someone who can already open the library: the
  permission adds private files to it and opens it to nobody. A private file's row shows its name,
  upload date and where it is used, and it is **never opened there**. **Everyone else does not
  see them at all**: to anyone without the permission, describing, retrying or deleting a private
  file answers exactly as for an id that never existed, so the panel never confirms that it is
  there. **A holder acts on one with the library's usual permissions on top**: describing it
  needs `platform.media.update`, deleting it `platform.media.delete` — so an admin may see only,
  see and describe, or see, describe and delete, as far as a Super Admin gave them. A paper an
  application still holds is refused as in use, as any file a use blocks. A private file has no
  sizes to make or retry (amendment 8(d)). Choosing "private" when uploading in the library is
  offered only to holders and refused from anyone else. **In the audit log** (amendment 8(c)), a
  reader without the permission still sees an entry about a private file — what was done, when and
  by whom — but not which file, nor any of what changed; they can ask an admin who holds it. A
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
| `company(string $customerId, string $storeId): ?CompanyDto` | Sales and the admin screens: the account's company **in that store** (amendment 18 — one per store), or null |
| `companies(string $customerId): list<CompanyDto>` | Every store's company of the account (amendment 20) |
| `status(string $customerId, string $storeId): ?CompanyStatus` | The shop's banner (§4.3) and the staff screens, per store. **Not Pricing** — since 2026-09-26 prices follow the account type, which Access owns, so nothing asks this on a hot path and nothing caches it |
| `isApproved(string $customerId, string $storeId): bool` | Sales's half of `canPlaceOrder` (handoff §7.4): that store's company approved, and the store on (amendments 18, 20) |
| `bankAccount(string $storeId): ?BankAccountDto` | Sales and Payments: the account a company of that store transfers to — **null while bank transfer is temporarily off**, until the store has filled in all three settings (§2.3, amendment 13(c)); checkout then disables paying by transfer, and the server refuses it |

`CompanyDto` carries the id, the account id, the name, the type's name in both languages, the
status, and the reason when there is one. **Both type names are null while the company is still
"Other"**: its type is not set yet, and its own words are for the reviewing staff alone (§1.3,
amendment 13(b)). It never carries documents: a document is reached only through its own signed
link, by staff with the permission (§3). `BankAccountDto` carries the IBAN as the store typed it,
the bank's name and the holder's name.

### 2.2 DTOs and enums (`Public/Dto`, `Public/Enums`)

- `CompanyDto`, `BankAccountDto`, plain `final readonly` (handoff §4.3).
- `CompanyStatus`: `PENDING`, `APPROVED`, `REJECTED`, `SUSPENDED`. A backed enum stored as a
  string, and the only company status anywhere in the system.

### 2.3 What B2B needs from other modules

| From | What | State |
|---|---|---|
| Access | The account: its type and contact details | Exists (`AccessApi::customer`) |
| ~~Access~~ | ~~Its address scheme, for the registered address~~ | **Not needed** (amendment 2): the registered address is one block of text. This row had said "exists", which was wrong — `AccessApi` offers a customer's saved addresses, not a store's form, its check or its printed layout — found while planning step 2, 2026-09-27. |
| Access | **The account's home store**, which decides who may review the company | **Done in B2B step 1** (access.md amendment 48): `CustomerDto::$homeStoreId`. It was found missing on 2026-09-25. |
| Access | A message to the customer when staff **approve, reject or suspend** — **[DECIDED 2026-09-26]**; a reinstatement sends none, being the suspension notice disappearing (`SecurityMessages`, access.md §2.3). The approval carries the staff member's **optional note**; each carries **one plain link to the shop's front door**, the same for everyone (amendment 1) | **Done in B2B step 1** (access.md amendment 48): `companyApproved`, `companyRejected`, `companySuspended`, until Ops |
| Platform | **The bank account to transfer to**, per store **[DECIDED 2026-09-26, 2026-09-29]** — a Saudi and an Egyptian bank account are not the same account. **Three settings** (amendment 12(b)): the **IBAN** (its format and check digits checked), the **bank's name** and the **account holder's name**, changed under Platform's own `platform.settings.update`. They start empty, and are shown only once all three are filled in (a Platform addition: a text setting may be empty, platform.md §9.4). Shown by B2B on the company page **only while `APPROVED`**, since only an approved company can order. **[DECIDED 2026-09-29] Bank transfer is on only while all three are filled in** (amendment 13(c)); until then it is **temporarily off**, and a company pays only through staff: the company's page says so (step 6), checkout disables paying by transfer and the server refuses it (Sales, stage 6, reading `B2BApi::bankAccount`). Filling in the third turns it on. **The settings page shows it in one line** at the top of the Companies section — "Bank transfer: on", or "Bank transfer: temporarily off — fill in all three to turn it on" (a second Platform addition: a module's line in its settings section, platform.md §9.4). Payments owns them from stage 7 and these settings go then | **Platform settings**, declared by B2B |
| Platform | A module uploading a private file for its own use | **Exists** — `PlatformApi::uploadMediaFor(ModuleUploadDto)`, built in stage 2b. Platform checks the permission B2B names, not `platform.media.upload`, which a customer will never hold |
| Platform | A module **deleting** a file it owns — a replaced document, a discarded draft's files, later an anonymized account's | **A Platform addition in B2B step 3** (amendment 4): `PlatformApi::deleteMediaFor`, the mirror of `uploadMediaFor`, checked against the permission the module names. Staff deletion of media is untouched, except that a private file does not exist for someone without the private-files permission (amendment 8(a)) |
| Platform | Media, the audit log, and the permission catalog | Exists |
| Access | The permission catalog, with the **group** each permission belongs to | **Exists** — `PermissionGroup` shipped in stage 2b. B2B's staff permissions join the **`Companies`** group, the one already reserved for this module (amendment 5; the text had said `Customers`). The two automatic customer permissions have no group |
| Platform | A permission to see private files in the media library | **A Platform addition in B2B step 3** (amendments 5 and 8(a)): admin-only, a Super Admin always; the library lists private files only to its holders, who describe or delete one only with the library's usual permissions, and to everyone else a private file does not exist |

### 2.4 What B2B gives others

- `B2BApi` above.
- Events (§6): the company's status changed, so Ops can write to the customer later — not Pricing,
  since prices follow the account type (§1.1) — and an application sent, for staff notifications.

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
  changing the address included — one rule for the whole of B2B. An account Access cannot find is
  refused the same way (amendment 9(b)).
- A draft is **started** (`StartApplicationDraft`): the first empty, a later one from the company as
  it is now plus the files of the last application sent (§1.2). Starting while a draft is open
  returns that draft — unless the company is suspended, when starting is refused even then
  (amendment 9(e)); while a sent application waits it is refused (`ApplicationAlreadyOpen`).
- The draft's actions name no application: they act on **the account's one open application**. No
  draft → `ApplicationNotFound`; one already sent → `ApplicationNotEditable`.
- **Saving changes only the fields sent**; a field left out keeps its value. A newly chosen type
  that is inactive is refused (`CompanyTypeInactive`).
- **While the company is suspended**, starting, saving, uploading and answering are refused
  (`CompanySuspended`), and so is removing a file or an answer from the draft (amendment 9(a)),
  and changing the address (amendment 9(d)): the draft is frozen, and discarding it whole is the one
  thing allowed.
- **While a draft is open**, changing the company's address writes it into the draft too; a staff
  correction of the type goes into the draft only if the draft's type is still the one the company
  had.
- A flagged field counts as replaced when its value differs **exactly, after trimming** (the page's
  and the server's one rule, amendment 17(a)), from what
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
| `UpdateCompanyContact` — the address, picked from the account's saved addresses (amendment 16(f)) | every customer | `b2b.company.update` | Own data |
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
company applied in (amendment 18; was: the store the account registered in, as staff see customers
by home store, access.md §3.3). A company of
another store answers `CompanyNotFound`, exactly as one that does not exist (§7).

**[DECIDED 2026-09-29] One permission per job** (amendment 10), named as every other module's are,
an action and its undo sharing one — as blocking and unblocking a customer share
`access.customer.block`. Every one is **per store** — the account's home store for a company, the
list's own store for a type — and **none is admin-only**: any staff or admin role may be given any
of them. They sit in the **Companies** group (amendment 5(g)). Twelve jobs — eleven in amendment
10, and moving companies between types in amendment 11(c):

| Use case | Permission | Scope |
|---|---|---|
| `ListCompanies` — filtered by status and by store — a store the reader does not cover is refused as not allowed (amendment 10(j)) —, searched by company name, CR number, tax number or the reference of an application it sent (amendment 14(g)); waiting companies first, the oldest sent first, then the others by their latest status change; 25 a page, at most 100, as the customer list (amendment 10) · `ViewCompany` — the company, the account holder read from Access, and the applications it sent, newest first, each with who decided it. **Never a draft**: nothing is reviewed until it is sent (§1.2) | `b2b.company.view` | The account's home store |
| `DownloadCompanyDocument` — a signed link, 30 minutes, to a paper or a file answer of one of the company's sent applications. **Each opening is audited** (amendment 10): who, which company, which paper type or request — never the file's id | `b2b.company_document.view` | The account's home store |
| `ApproveCompany` — an **optional note**, and the screen tells staff it is sent to the customer with the approval email (amendment 1); **no choice about the type**: the company keeps what the type's deactivation gave it (§1.3, amendment 11(a)); **refused while the company is "Other"** (`CompanyTypeNotSet`) — staff correct it to a listed type first (amendment 13(b)); **refused once the account is erased** (`CompanyAccountDeleted`) — staff reject it instead (13(e)) · `RejectCompany` — **a reason is required**; staff may also **flag** items sent wrong and **request** extra text answers or files from this company (§1.2, amendment 4) | `b2b.company.review` | The account's home store |
| `SuspendCompany` — from any status, **a reason is required** (handoff §8.2) · `ReinstateCompany` — ends a suspension, **a reason is required** (§4.1) | `b2b.company.suspend` | The account's home store |
| `CorrectCompanyType` — rewrite an "Other" in the right words, or move the company to a listed type of its home store, when it chose wrongly or did not know (amendment 2). Changes the company, never the application it sent, and does not send it back to `PENDING`. **Choosing a deactivated type** first tells staff that the type becomes active again; confirmed, the type is activated, then assigned (amendment 8(b)) — which needs `b2b.company_type.deactivate` for that store as well (amendment 10). **Refused while the company is suspended** (`CompanySuspended`, amendment 10(h)). **An approved company is never corrected to "Other"** (`InvalidCompanyAttribute`, amendment 13(b)) | `b2b.company.correct_type` | The account's home store |
| `AddCompanyType` | `b2b.company_type.create` | The list's store |
| `RenameCompanyType` · `MoveCompanyType` (its position) | `b2b.company_type.update` | The list's store |
| `DeactivateCompanyType` — hidden or greyed out, and **leave** the companies holding it, **replace** it on every one of them with another active type of the store (§1.3, amendment 5), or **replace it with a new type created in the same step**, which needs `b2b.company_type.create` as well (amendment 11(b)) — a suspended company is skipped and keeps the old type (amendment 10(h)) · `ActivateCompanyType` — offered again (amendment 10) | `b2b.company_type.deactivate` | The list's store |
| `TransferCompanyType` — every company holding one active type moves to another active type of the same store, both staying offered; suspended ones skipped (§1.3, amendment 11(c)) | `b2b.company.transfer_type` | The list's store |
| `AddDocumentType` | `b2b.document_type.create` | The list's store |
| `RenameDocumentType` · `MoveDocumentType` · `RequireDocumentType` (required, or optional again) | `b2b.document_type.update` | The list's store |
| `DeactivateDocumentType` — hidden or greyed out · `ActivateDocumentType` (amendment 10) | `b2b.document_type.deactivate` | The list's store |
| `MarkTypeListsReviewed` — clears the "copied from the starting lists" notice when nothing needs changing (§1.3) | `b2b.company_type.update` or `b2b.document_type.update` | The list's store |

`ApproveCompany` and `RejectCompany` **mark an application whose company type was deactivated after
it was sent** (§1.3, amendment 5), so the reviewer decides with that in front of them — for
information only: approving follows what the deactivation decided (amendment 11(a)).

Every change is audited (Platform). Company name, contact name, phone, address and the documents
are personal data: recorded only as "changed" (access.md §3.3, platform.md §1.5). What staff write
— a reason, a note, a request's label — and what they mark — the flagged fields and document types
— are recorded **by value**, as Access records the reason a customer was blocked.

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

### 4.4 What the shop says, on every page

**[DECIDED 2026-09-29] A company account that cannot order is told why on every page of the shop**
(amendment 14(c)): one line under the header, linking to the company page (§4.5). It follows the
account's stage (§4.3) and then the company's status (§4.1):

| The account | The line |
|---|---|
| Email not confirmed | None of B2B's: Access's own "Confirm your email" mark says it |
| Email confirmed, no application | "Continue your application" |
| A `DRAFT` exists, no company yet | "Finish and send your application" |
| `PENDING` | Under review: browsing and filling the cart are open, and ordering opens once approved — the design's own words |
| `REJECTED` | Not approved: see why, and apply again |
| `SUSPENDED` | Suspended, with the suspension's reason |
| `APPROVED` | **Nothing** — also while an approved company has an unsent change of its details: it can still order |

An individual account never sees one.

### 4.5 The company page

**[DECIDED 2026-09-29] One page, the design's** (amendment 14(a)) — `TouchWood Screens.dc.html`, the
company screen — at `/{store}/{lang}/account/company`, reached from a **Company** entry in the
account's pages and from the strip. Company accounts only. The design is look and behaviour; where
its fields, its company types or its address differ from this spec, the spec holds (§1.1, §1.3).

- **Two columns.** The main one holds a status box and, under it, the form or what was sent; the
  side one holds **the application's lifecycle and nothing else** (amendment 16(e)).
- **Before the first send there is no company, only a draft**, and the page shows the draft alone:
  not sent yet, what is still missing, and Continue or Discard. This is §4.3's third row on the
  page itself.
- **The form** — company details (name; type, "Other" always last and then its words; CR number;
  tax number; the address, **picked from the account's saved addresses**, amendment 16(f)),
  documents (every active type of the home store, required ones marked, one file each to open,
  replace or remove, a greyed type and anything "no longer accepted" marked, §1.3), what the last
  rejection asked for (only when it asked), and the note — then **Send**.
  **It saves itself** (amendment 14(a)): each field when the person leaves it, each file when it is
  uploaded, so a wrong value is shown on its own field at once (amendment 4) and nothing is lost by
  leaving; Send checks that it is complete. **Send waits for a clean form** (amendment 15(a)): it
  cannot be pressed while anything is still saving, or while a field holds a value that was refused
  or not saved yet — the page says to finish the marked fields first — and it cannot be pressed
  twice; what is sent is always what the page shows.
- **Each field says where it stands** (amendment 16(a)), in colour and in words: **yellow** while
  what it holds is not valid — empty when it is required, shorter than its minimum, longer than its
  maximum, or with characters it does not take — and such a value is **never sent**: the page checks
  it first, and the server checks it again on its own; **"Saving…"** while its save is out, which
  never stops the person filling the other fields; **green, "Saved"**, once the server holds it; and
  **red**, with the reason, if the server refuses it all the same — the page's own reason when the
  refusal's answer lets it name one (amendment 17(g)). The page takes each field's minimum, maximum
  and allowed characters from the server, the same rules the server checks, and both trim the same
  characters at either end (17(a)). A field's own answer never overwrites what the person has typed
  since (17(c)). A field the last decision marked, and not yet changed, shows its red mark and never
  green "Saved" (17(f)).
- **Minimums** (amendment 16(b)): the company's name at least 2 characters, the CR number and the
  tax number at least 5, "Other"'s words at least 3, a text answer at least 2; the note has none.
  They are **settings, one set for every store**, in the Companies section of the settings page,
  changed under `platform.settings.update`; each is at least 1 and at most its field's maximum. A
  value is held to them when it is saved and **again when the application is sent**, so a value
  saved before a minimum was raised turns yellow and stops Send until it is changed. Nothing already
  sent is touched. The maximums stay as §1.1 sets them.
- **The same file twice** (amendment 16(c)): a paper whose file name is **exactly** that of a file
  already under another document type of the draft — both as the media library keeps names
  (amendment 17(d)) — is refused — "the same file cannot go into two
  sections" — by the page at once, before uploading, and by the server (`DuplicateDocumentFile`).
  Replacing a section's own file with one of the same name is allowed. Answers to what staff asked
  for are not compared.
- **Send is inactive until everything is complete** (amendment 16(d)): every required value valid
  and saved, a type chosen and still accepted, an address picked, every required paper, nothing
  "no longer accepted", every marked item replaced, every request answered. What is still missing is
  listed beside it. The server refuses an incomplete send on its own as well (§1.2).
- **Rejected** (amendment 14(d)): the reason, and **Apply again**, which opens the form from the
  company as it is now and the files of the last application sent (§1.2), the flagged items marked,
  and a section for what staff asked for. **The reason is the last rejection's** (amendment 15(b)),
  and a company reinstated since sees the reinstatement's words on a line of their own.
- **Suspended**: the suspension's reason; everything read-only; no applying. Discarding an unsent
  draft is the one thing left (§1.1). **The lifecycle is hidden** while suspended (amendment
  16(e)).
- **Approved**: the details, and **Change company details**, which opens the form at once, filled
  in (amendment 14(e)). **The form says, at its top and again above Send**, that the changes go to
  staff as a new application, that the company keeps ordering until it sends them, and that from
  then until they are approved it cannot order (§1.1). **Its bank account** is a card in the main
  column (amendment 16(e)): the IBAN, the bank and the holder, with Copy, while bank transfer is on;
  that it is temporarily unavailable and our team will contact them, while it is off (§2.3,
  amendment 13(c)).
- **The address** has its own small picker, saved at once without review (`UpdateCompanyContact`),
  **for every company but a suspended one — pending, approved or rejected — while no draft is
  open** (amendment 15(c)); while one is, the address is picked in the form, and it becomes the
  company's address when the application is sent (amendment 17(j)), as every value in it does.
- **The address is picked from the account's saved addresses** (amendment 16(f)) — **any store's**,
  each written in its store's format (access.md §1.9) — never typed here. An address its store's
  format no longer accepts cannot be picked. **With none saved**, the section says so; **Add an
  address** — offered with saved addresses too (amendment 17(k)) — opens the account's Addresses
  page and, once one is saved, brings the person back to the application to pick it (access.md
  amendment 51); in the form it waits for the saves still going (17(i)). **The application and the
  company keep a copy** of the address as it was when picked: editing or deleting it in the address
  book changes neither; picking another is how the company's address changes — or picking the same
  one again once it was edited: it then shows unpicked, with a note (17(b)).
- **Every application sent**, newest first (amendment 14(f)): one row each — its reference, the
  date it was sent, its result — opening onto what was sent, its papers (each opened by a
  30-minute link, §1.4), the reason or note it got, and what it flagged or asked for. No staff
  names (§3.1).
- **The lifecycle** (amendment 16(e), which replaces 14(h)'s four steps and its two cards): **three
  steps** — filling and sending the application; **under review**, usually within two business
  days; **the decision**, approved or not approved, sent by email — with a pointer on the step the
  latest application has reached: no application or a draft open, the first; sent and waiting, the
  second; decided, the third, showing its result. A company applying again after a decision starts
  again at the first. **Hidden while the company is suspended.** The side column holds nothing
  else: "How a company pays" and "Before approval" are gone, and an approved company's bank account
  is in the main column (above).
- **Times** on the page, and the year in a reference, are the **home store's** (HANDOFF §4: UTC
  underneath, the store's time zone on the screen; owner, 2026-09-29).
- **After registering**, a company account goes to the confirm-email page as anyone does (amendment
  14(b)); the company page is reached from the strip once the email is confirmed, or from its
  pages at any time.

### 4.6 The staff screens

**[2026-10-02] Step 7, in the admin panel, in Geist** (amendment 21; frontend.md §1.8, §1.10). Every
choice this section makes that no earlier decision made is marked **[PROVISIONAL 2026-10-02 — owner
to confirm]**: it was written in the overnight run while the owner slept, and is the option the
builder would recommend. The use cases behind every button are §3.2's, unchanged; a screen offers
only what the reader may do next, and every handler asks again (handoff §19).

**Where they are.** Three entries in the menu's **Companies** group: **Companies**
(`b2b.company.view`), **Company Types** and **Document Types**. The two type lists are one
"types page" (§1.3) with a tab for each list, and always show **the store in the panel's header**
(frontend.md §2.2): another store's lists are reached by changing the store there. The menu gives an
entry one permission, so **Company Types is offered to holders of `b2b.company_type.update` and
Document Types to holders of `b2b.document_type.update`** [PROVISIONAL]; anyone holding any other job
on a list in that store — adding, deactivating, moving companies between types — may open the page
all the same, but is not offered the entry. Offering it to them needs a Platform addition (a menu
entry offered for any of several permissions), which is the owner's call. Pages: `/admin/companies`,
`/admin/companies/{id}`, `/admin/company-types`, `/admin/document-types` [PROVISIONAL].

**Who may read a type list** [PROVISIONAL]: anyone holding, **in that store**, any job on that list —
for company types `b2b.company_type.create`, `.update`, `.deactivate` or `b2b.company.transfer_type`;
for document types `b2b.document_type.create`, `.update` or `.deactivate`. No new permission: reading
a list is part of every job on it. Anyone else is refused as not allowed, as a store they name is
(§3.2).

**The company list** (`ListCompanies`): a table of **Company · Status · Store · Sent · Last Change**,
the waiting ones first, the oldest sent first, then the rest by their latest status change; 25 a
page with the range and Previous / Next (Geist's table and pager). Filters: a search (name, CR
number, tax number, or an application's reference), the status, and the store — **only the stores
the reader covers, and no store filter at all when that is one store** [PROVISIONAL]. A company whose
waiting application's type was deactivated since it was sent carries an amber **Type Deactivated**
mark beside its name, for information (§1.3, amendment 11(a)). Nothing in the list changes anything;
each row opens the company. Times are the company's home store's (§4.5) [PROVISIONAL].

**The company page** (`ViewCompany`), read only except for its buttons:

- **Status**: the status as a badge, the reason it carries, when it last changed and by whom, whether
  it may order, and its store.
- **Company Details**: name, type — a listed type's name, or **Other** with the company's own words,
  marked as still to be corrected —, CR number, tax number and address, as the company holds them now.
- **Account Holder**, read from Access (§1.1): name, email, phone, and whether the email and phone are
  confirmed; an erased account says so (§1.1, amendment 13(e)).
- **Applications**, newest first — **never a draft** (§3.2) — each with its reference, its state, when
  it was sent and decided and **by whom**, its reason or note, what it sent, its papers, what it
  flagged and asked for, and the answers it gave to the requests of the application before it
  [PROVISIONAL: the newest open, the older ones folded]. A waiting application whose type was
  deactivated since it was sent shows a note naming the type the company holds now — for
  information only (amendment 11(a)).
- **Papers** open through a 30-minute signed link (`DownloadCompanyDocument`), each opening audited
  (amendment 10(f)); without `b2b.company_document.view` a paper shows its type and when it was
  uploaded — not its file's name, which is the company's, nor the file's id, which a reader without
  the private-files permission is never told (amendment 8(c)) — and its Open button is disabled with
  the reason [PROVISIONAL].

Its buttons, each offered only to whoever holds the job **and** only when it can happen next:

| Button | Offered | Asks for | Toast |
|---|---|---|---|
| **Approve Company** | an application waits (`PENDING`) — `b2b.company.review` | an optional note; the modal says it is emailed to the customer with the approval (amendment 1), and shows the type note above when there is one | Company approved |
| **Reject Application** | the same | **a reason** (required), and optionally **marked items** — any of the five fields, and each paper the application sent — and **requests** — each a text answer or a file, under a label staff write (§1.2, amendment 4); the modal says the reason is emailed, and shows the type note above when there is one (§3.2) | Application rejected |
| **Suspend Company…** | any status but suspended — `b2b.company.suspend` | **a reason**; the modal says the company cannot order or change anything until reinstated, and that the reason is emailed | Company suspended |
| **Reinstate Company** | suspended — `b2b.company.suspend` | **a reason**; the modal names the status it returns to (§4.1) and says no email is sent (§2.3) | Company reinstated |
| **Correct Company Type…** | any status but suspended — `b2b.company.correct_type` | a listed type of the home store, or "Other" in words — "Other" not offered once the company is approved (amendment 13(b)); a **deactivated** type is offered, marked, only to someone who may also activate types, and choosing it says the type becomes active again for the whole store before it is assigned (amendments 8(b), 10(b)); nothing is chosen when the modal opens, and the button waits for a type other than the one the company holds, or "Other" | Company type corrected |

- **Approve Company is disabled, with its reason, while the company is "Other"** — correct the type
  first (amendment 13(b)) — **and while the account is erased** — reject it instead (13(e))
  [PROVISIONAL: shown disabled with the reason rather than hidden].
- **Reject and Suspend are destructive**: Geist's destructive Modal, focus starting on Cancel, the
  button disabled until a reason is written. **Not the typed-confirmation modal**: both can be undone —
  a rejected company applies again, a suspended one is reinstated [PROVISIONAL]. Approve and
  Reinstate use a plain Modal.
- **Where they sit** [PROVISIONAL]: Approve and Reject at the top of the page; Reinstate there while
  suspended; Correct Company Type and Suspend in the page's actions menu, Suspend last (Geist's menu
  rules).
- A refusal shows as the module's own error (§7), in a red note at the top of the page and a toast
  (frontend.md §2.1); a reason the domain refuses shows under the reason.

**The types page** (one store's two lists):

- **The "copied" notice** (§1.3, amendments 6(a) and 10(d)), above either list while the store's lists
  are marked copied, not yet reviewed, with **Mark Lists Reviewed** for holders of either list's
  update job [PROVISIONAL wording: "These are the lists every store starts with, written for Saudi
  forms and papers. Change what this store needs, or mark the lists reviewed."].
- **A table per list**: Position · Arabic Name · English Name · Status (Active, or Hidden / Greyed Out
  when deactivated) · for company types, **Companies** — how many hold it — · for document types,
  **Required**. Ordered as the form orders them (§1.3). Each row's actions in a menu.
- **Add Company Type / Add Document Type** (the page's main button): both names and the position — a
  document type also whether it is required [PROVISIONAL: the position defaults to ten after the
  last].
- **Rename…** (both names) and **Change Position…** — the list's update job; for a document type,
  **Make Required** / **Make Optional** at once from the menu.
- **Deactivate Company Type… / Deactivate Document Type…** — how it shows to new applications,
  **Hidden** or **Greyed Out** (amendment 5); for
  a company type that companies hold, what happens to them: **leave them**, **move them to another
  active type**, or **move them to a new type** made in the same step — both names, and a position
  that defaults to the old type's (amendment 11(b)); the last offered only to someone who may also add
  types. The modal says suspended companies keep the old type (10(h)). Destructive Modal, not typed.
- **Activate Company Type / Activate Document Type** at once from the menu.
- **Move Companies…** (`b2b.company.transfer_type`, an active company type that companies hold): to
  another active type, both staying offered; suspended companies stay (amendment 11(c)).
- Toasts: Company type added, renamed, deactivated, activated; Position changed; Companies moved;
  Document type added, renamed, deactivated, activated, made required, made optional; Lists marked
  reviewed.

**Words** — every English and Arabic word on these screens is the builder's, after Geist's writing
rules (frontend.md §1.10) [PROVISIONAL, owner to confirm]. The refusals keep §7's own words; where
those were written for the company (`CompanyNotFound`, `CompanyTypeInactive`), staff read the same
words until they are reworded.

---

## 5 · Tables

All in schema `b2b`. Every id is `char(26)` (ULID); timestamps are `timestamptz`.

| Table | Columns |
|---|---|
| `b2b.companies` | `id` PK · `customer_id` FK → `access.customers` — **unique together with `home_store_id`** (amendment 18; was unique alone) · `name` · `company_type_id` NULL FK · `company_type_other` NULL — exactly one of the two · `cr_number` · `tax_number` · `address` · `address_id` NULL FK → `access.addresses` ON DELETE SET NULL (§5.1, amendment 16(f)) · `home_store_id` FK → `platform.stores` · `status` · `status_before_suspension` NULL · `status_reason` NULL · `status_changed_at` NULL · `status_changed_by` NULL FK → `access.staff_users` · timestamps · CHECK `companies_approved_type_listed`: an approved company, or one suspended from approved, holds a listed type, never "Other" (amendment 13(d)) |
| `b2b.applications` | `id` PK · `customer_id` FK · `company_id` NULL FK · `state` · the snapshot, each NULL while a draft: `name`, `company_type_id`, `company_type_other`, `cr_number`, `tax_number`, `address`, `address_id` (FK → `access.addresses` ON DELETE SET NULL, §5.1) · `note` NULL · `submitted_at` NULL · `reference` NULL — present exactly when the application is no longer a draft (CHECK `applications_reference_when_sent`), shaped `TW-CO-` two digits `-` four or more digits (CHECK `applications_reference_format`) (amendment 14(g)) · `decided_at` NULL · `decided_by` NULL FK · `decision_reason` NULL · timestamps |
| `b2b.application_documents` | `id` PK · `application_id` FK ON DELETE CASCADE · `document_type_id` FK · `media_id` FK → `platform.media` **RESTRICT** · `uploaded_at` |
| `b2b.application_flags` (amendment 4) | `id` PK · `application_id` FK ON DELETE CASCADE — **the rejected application** · `field` NULL (`name`, `company_type`, `cr_number`, `tax_number`, `address`; CHECK `application_flags_field`) · `document_type_id` NULL FK **RESTRICT** — exactly one of the two (CHECK `application_flags_one_item`); one flag per field or document type per application (§5.2) |
| `b2b.application_requests` (amendment 4) | `id` PK · `application_id` FK ON DELETE CASCADE — **the rejected application** · `kind` (`TEXT`, `FILE`; CHECK `application_requests_kind`) · `label` — the staff member's words, one line, at most 200 characters (CHECK `application_requests_label_text`) · `position` 0–10,000 (CHECK `application_requests_position_range`) |
| `b2b.application_request_answers` (amendment 4) | `id` PK · `application_id` FK ON DELETE CASCADE — **the answering application** · `request_id` FK **RESTRICT** · `text` NULL (at most 1000, line breaks allowed; CHECK `application_request_answers_text_text`) · `media_id` NULL FK → `platform.media` **RESTRICT** — exactly one (CHECK `application_request_answers_one_value`), matching the request's kind (in code only, amendment 5(g)); one answer per request per application (§5.2) |
| `b2b.company_types` | `id` PK · `store_id` FK → `platform.stores` **RESTRICT** (amendment 5) · `name_ar`, `name_en` — `varchar(100)`, each unique on (`store_id`, `lower()`) (§5.2) · `position` 0–10,000 · `is_active` · `inactive_display` NULL — how an inactive one shows, `HIDDEN` or `GREYED` (amendment 5), present exactly while the type is inactive (CHECKs `company_types_inactive_display` and `company_types_inactive_display_when_inactive`) · timestamps |
| `b2b.document_types` | `id` PK · `store_id` FK → `platform.stores` **RESTRICT** (amendment 5) · `name_ar`, `name_en` — `varchar(100)`, each unique on (`store_id`, `lower()`) (§5.2) · `position` 0–10,000 · `is_active` · `inactive_display` NULL — `HIDDEN` or `GREYED`, present exactly while inactive (CHECKs `document_types_inactive_display` and `document_types_inactive_display_when_inactive`) · `is_required` · timestamps |
| `b2b.application_reference_counters` (amendment 14(g)) | `year` PK — the four-digit year, of which a reference shows the last two · `last_number` — the last count given that year, 1 or more (CHECK `application_reference_counters_last_number`). One row per year, taken with a row lock inside the send, so two sends at once never share a number and a send that fails gives its number back |
| `b2b.store_type_lists` (amendment 6(a)) | `store_id` PK (`store_type_lists_pkey`), FK → `platform.stores` ON DELETE CASCADE (`store_type_lists_store`) · `copied_not_reviewed` — set when the starting lists are written into the store, cleared once its admins have reviewed them (§1.3); no default · `updated_at`. One row per store the lists were written into |

### 5.1 The address

**[DECIDED 2026-09-30] A saved address of the account, kept as a copy** (amendment 16(f)): the
`address` `text` column holds the picked address as its store's format writes it (Access's
`AddressDto::formatted`) — at most 6,000 characters, the column's bound: Access's own limits keep
a formatted address within it unless its template repeats a field, and a longer one is refused as
too long (amendment 17(e)); the column is widened from
`varchar(500)` —, and `address_id` NULL FK → `access.addresses`
ON DELETE SET NULL says which saved address it was picked from — on the company and, as part of the
snapshot, on each application. The copy is what staff read and what an application keeps; the id
only tells the page which saved address is picked now, and goes when that address is deleted.
Addresses written before amendment 16 keep their text and have no id. No map pin, no
`recipient_name` and no `phone` shown — the responsible person is the account holder (§1.1).

### 5.2 Indexes

| Index | Why |
|---|---|
| `companies (customer_id, home_store_id)` unique | One company per account **and store** (§1.1, amendment 18; was `(customer_id)`), decided by the database rather than by a handler |
| `companies (home_store_id, status)` | The staff list: the companies of my stores, by status |
| `companies (status)` | The queue across every store, for a Super Admin |
| `applications (customer_id, state)` | "Has this account an open application?" — asked on every submit, and for the banner on every shop page |
| `applications (company_id, submitted_at DESC)` | One company's history, newest first |
| **Unique** `application_documents (application_id, document_type_id)`, named `application_documents_one_per_type` | One file per type (amendment 3); its leading column also serves "the documents of one application" |
| **Partial unique** `applications (customer_id) WHERE state IN ('DRAFT','SUBMITTED')` | One open application at a time, enforced where two tabs cannot both win |
| **Unique** `applications (reference)`, named `applications_reference_unique` | One application per number (amendment 14(g)); also serves staff searching the company list by a reference |
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
| `CustomerAnonymized` | Access | Replaces the company's personal fields — name, CR number, tax number, address, and an "Other" company's own words for its type — with placeholders on the company and every application it sent, clears their notes and text answers, **deletes the papers and answer files**, and deletes an unsent draft (§1.1, amendments 12(a), 13(a)). The company row, its type, its status and the decision record stay. **From the queue**: retried when it fails, and done once however many times it runs (13(a)) |
| `StoreCreated` | Platform | **Writes the starting company types and document types into the new store**, each kind only where the store has none, and marks its lists **copied, not yet reviewed** (§1.3, amendment 6(a)). A company still needs nothing here: it is valid in every store (§1.1) |

---

## 7 · Errors

Each extends the module's `B2BError`, which extends `Shared\Domain\Error\DomainError`, with its own
type string and HTTP status (handoff §11).

| Error | Status | When |
|---|---|---|
| `NotACompanyAccount` | FORBIDDEN | An individual account — or one Access cannot find (amendment 9(b)) — reached an application use case (§3.1) |
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
| `DuplicateDocumentFile` | CONFLICT | A paper whose file name is exactly that of a file under another document type of the draft (§4.5, amendment 16(c)) |
| `RequestNotFound` | NOT_FOUND | Answering a request that is not one of the last rejection's (§3.1, amendment 5) |
| `AnswerKindMismatch` | UNPROCESSABLE | A text answer to a file request, or a file to a text request (§3.1, amendment 5) |
| `ApplicationFileNotFound` | NOT_FOUND | Opening a file that is not one of the account's own applications' — answered the same whether or not such a file exists (§1.4, amendment 9(c)) |
| `CompanySuspended` | CONFLICT | Anything the company does while suspended but discard its draft (§1.1, §3.1, amendments 5, 9(a), (d) and (e)): starting, saving, uploading, answering, removing a file or an answer, sending, and changing the address — and a staff correction of its type (amendment 10(h)) |
| `InvalidCompanyStatus` | CONFLICT | A change the company's status does not allow: deciding a company with no application waiting, suspending one already suspended, reinstating one that is not (§4.1, amendment 3) |
| `InvalidCompanyAttribute` | UNPROCESSABLE | A value the domain refuses — a CR number too long, or shorter than today's minimum (amendment 16(b)), an unknown company type, a listed type that is not one of the home store's (§1.3, amendment 6(d)), or an address that is not one of the account's saved addresses or that its store's format no longer accepts (amendment 16(f)) |
| ~~`DocumentTypeInUse`~~ | — | **Removed** (amendment 10): a type is never deleted, so nothing can refuse deleting one (§1.3) |
| `TypeNameTaken` | CONFLICT | Adding or renaming a type to a name another type of its kind already has, in either language, ignoring case (§1.3, amendment 2) |
| `CompanyTypeInactive` | CONFLICT | Submitting a draft whose chosen type staff have deactivated since; choose again (§1.3, amendment 2). Also a staff correction to a deactivated type **not yet confirmed** — the screen then says the type becomes active again (amendment 8(b)) — and a replacement, when deactivating a type, or a type companies are moved from or to (amendment 11(c)), that is itself inactive (amendment 10). A replacement or a transfer's target that is unknown, another store's, or the same type is `InvalidCompanyAttribute`, as everywhere; the type the action is about — the one deactivated, or moved from — answers `TypeNotFound` when it is unknown or another store's (10(k)) |
| ~~`CompanyTypeChoiceRequired`~~ | — | **Removed** (amendment 11(a)): approving asks for no choice about the type |
| ~~`CompanyTypeChoiceNotNeeded`~~ | — | **Removed** (amendment 11(a)), with the choice it refused |
| `TypeNotFound` | NOT_FOUND | A staff action on a company or document type that does not exist, or that belongs to a store the staff member does not cover — the same answer for both (§3.2, amendment 10(k)) |
| `CompanyTypeNotSet` | CONFLICT | Approving a company that is still "Other": correct it to a listed type first (§1.3, amendment 13(b)) |
| `CompanyAccountDeleted` | CONFLICT | Approving the application of a company whose account was erased: nobody can sign in to it again, so staff reject it instead (§1.1, amendment 13(e)) |

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
9l. While suspended, starting (even with a draft open), saving, uploading, answering, removing a file or an answer, and changing the address are refused, and discarding is allowed (amendments 5, 9(a), (d) and (e)).

**The rules that protect somebody**

10. An individual account is refused every application use case by the handler, not only by the screen.
11. A suspended company may not change its name, CR number, tax number, type, documents or address (amendment 9(d)).
12. Reinstating returns the company to the status it held before the suspension, never to `PENDING`.
12a. An approved company cannot be rejected; only suspended (amendment 3).
13. A company sees company prices in every status, rejected and suspended included.
14. The bank account — IBAN, bank and holder — is absent from the page in every status but `APPROVED`, and while any of the three is empty bank transfer is temporarily off: `B2BApi::bankAccount` answers null and the settings page says so in the Companies section; an IBAN whose check digits are wrong is refused when it is saved (amendments 12(b), 13(c)).
15. Staff of another store cannot see, approve, reject or suspend a company whose home store is not theirs — and a Super Admin can.
16. A rejection, a suspension or a reinstatement without a reason is refused; an approval needs none (amendment 1).
17. A document is reachable only through a signed link that expires — by staff given the job of opening a company's papers (amendment 10), or by the account that uploaded it (amendment 5) — and private files appear in the media library only to holders of the private-files permission, never opened there; to anyone else, describing, retrying or deleting one answers as for an id that never existed, and the audit log shows them what was done to a private file, when and by whom, but not which file or what changed (amendment 8(a), (c)).
18. Anonymizing the account replaces the name, CR number, tax number, address and an "Other" company's own words with placeholders on the company and every application sent, clears notes and text answers, deletes the papers and answer files and any unsent draft, and keeps the company row, its type, its status and the decision record — and an application still waiting, emptied, for staff to reject; run twice, from the queue, it changes nothing the second time (amendments 12(a), 13(a)).
19. Every change is audited, and the personal fields are recorded as "changed", never by value.
20. Each staff job is its own permission, per store, and any staff or admin role may hold it: a staff member who may review but not suspend is refused suspending, and one who may view a company but not open its papers is refused its papers (amendment 10).
21. Approving an application whose company type was deactivated since it was sent asks for no choice: the company keeps the replacement the deactivation gave it, or the old type if it was left; the reviewer sees the mark for information (amendment 11(a), which replaced 10(e)).
22. A staff correction to a deactivated type is refused until confirmed, and needs the job that activates types as well; confirmed, the type is active again for the whole store, then assigned (amendments 8(b) and 10).
23. A type is never deleted; a deactivated one can be activated again. Any change to either of a store's lists, or "Reviewed", clears its "copied from the starting lists" notice (amendment 10).
24. Each time staff open a company's paper, the audit log records who, which company and which paper — never the file's id (amendment 10).
25. Staff cannot correct a suspended company's type, and replacing a deactivated type skips a suspended company, which keeps the old one; neither the company nor its draft changes (amendment 10(h)).
26. Filtering the company list by a store the staff member does not cover is refused; a type of another store answers as one that does not exist (amendment 10(j)–(k)).
27. Deactivating a company type and replacing it with a new type is one step: the new type exists, the old one is inactive, and every holder but a suspended one is on the new type — or, if any part is refused, none of it happened; it needs the jobs of adding and of deactivating types (amendment 11(b)).
28. Moving every company of one active type to another moves them all but a suspended one, leaves both types active and the lists' notice as it was, and needs its own job (amendment 11(c)).
29. Approving a company that is still "Other" is refused until staff correct it to a listed type — and the database refuses an approved "Other" too; an approved company cannot be corrected to "Other"; other modules see an "Other" company's type as not set yet, never its words; approving the waiting application of an erased account is refused, and rejecting it works (amendment 13(b), (d), (e)).

**The company's screens** (amendment 14)

30. Before the first send the company page shows the draft alone — not sent yet, what is missing, Continue and Discard — and no company; afterwards the company's status, its details and every application it sent, newest first, each with its reference.
31. The form saves itself: each field when it is left and each file when it is uploaded; a wrong value shows on its own field at once; Send checks that it is complete.
32. The strip, on every shop page, says one line per stage and status for a company account that cannot order, linking to the company page; nothing once approved, also while an approved company has an unsent change; only Access's own mark while the email is unconfirmed; never anything to an individual account.
33. Rejected: the reason and Apply again, which opens the form from the company now and the last files, the flagged items marked and the requests to answer. Suspended: the reason, everything read-only, and only discarding an unsent draft.
34. Change company details opens the filled-in form at once, which says at its top and above Send what sending will do; the address alone saves at once, without review.
35. Each application sent takes the next reference of its year, in its home store's time zone — `TW-CO-26-0001` onwards, `0001` again in a new year; a failed send takes none; a draft has none; two sends at once never share one; staff find the company by it.
36. ~~The payment side card by state~~ — replaced by 42 (amendment 16(e)).
37. Each field is yellow while not valid — required and empty, under its minimum, over its maximum, or with characters it does not take — and is then not sent; green and "Saved" once the server holds it; red with the server's reason if the server refuses it; "Saving…" never stops the other fields (amendment 16(a)).
38. The minimums are settings, one set for every store, changed under `platform.settings.update` and bounded by each field's maximum; a value is held to them when saved and again when sent, so a value saved before a minimum was raised stops the send until it is changed (amendment 16(b)).
39. A paper whose file name is exactly that of a file under another document type of the draft is refused, by the page and by the server; replacing a section's own file with the same name is allowed; answers are not compared (amendment 16(c)).
40. Send is inactive until everything is complete, with what is missing listed; the server refuses an incomplete send on its own (amendment 16(d)).
41. The address is picked from the account's saved addresses, any store's, and only one its format accepts; another account's address is refused as unknown; the application and the company keep a copy that editing or deleting the saved address does not change; with none saved, Add an address goes to the Addresses page and back to the application once one is saved (amendment 16(f); access.md amendment 51).
42. The side column is the lifecycle alone — sending, under review, the decision with its result — with the pointer on the step the latest application has reached, back at the first when a company applies again, hidden while suspended; an approved company's bank account is a card in the main column, or "temporarily unavailable" (amendment 16(e)).

**The staff screens** (amendment 21)

43. The company list shows only the companies of the reader's stores, waiting ones first, 25 a page; filtering by a store the reader does not cover is refused; the store filter offers only their stores.
44. A company of another store answers "not found" on its page and on every button behind it; a reader without a job is refused it, and its button is never drawn.
45. Approve, Reject, Suspend, Reinstate and Correct Company Type each appear only for a reader holding the job and only when it can happen next; Approve is disabled, with the reason, while the company is "Other" or its account is erased.
46. Rejecting needs a reason, and may mark any of the five fields and any paper sent, and ask for texts and files; suspending and reinstating need a reason.
47. Opening a paper goes through a 30-minute link and is audited; without the job of opening papers the page shows the paper and no way to open it.
48. A type list is read by anyone holding a job on it in the header's store, and refused to anyone else; the "copied" notice shows until any change to either list or Mark Lists Reviewed.
49. Deactivating a company type that companies hold asks what happens to them — leave, move to another type, or move to a new one, the last only for someone who may also add types; moving companies between two active types is its own job.

---

## 9 · Open questions

| # | Question | State |
|---|---|---|
| 1 | A document required of **one company alone**, rather than a type required of everyone | ~~Not built (owner, 2026-09-26)~~ — **built as a request on a rejection** (owner, 2026-09-28, amendment 4): staff ask this company for a text answer or a file, and the next draft cannot be sent without it |
| 2 | Uploading **proof of a bank transfer** | **Out of scope**: it belongs to an order, so Sales, stage 6 (owner, 2026-09-26). The owner restated it on 2026-09-29 (amendment 12(c)): a company pays **by bank transfer, and then must upload the transfer's document**, or **through staff, who handle it** — both chosen with the order, in Sales and Payments |
| 3 | Settling with staff over WhatsApp instead | **Out of scope**, and deliberately outside the system (owner, 2026-09-26) — how staff settle a payment stays theirs; that a company pays **through staff** is one of its two ways to pay (#2, amendment 12(c)), for Sales and Payments to record |
| 4 | The bank account settings | B2B's until **Payments**, stage 7, which takes them over (§2.3) |
| 5 | Colleagues sharing one company | **Not built** (§1.1); the design's "Company staff" segment waits for a later stage |
| 6 | `homeStoreId` on Access's `CustomerDto` | **Done** in B2B step 1 (§2.3; access.md amendment 48) |
| 7 | **Paying once, however often the button is pressed** — an idempotency key on the pay request | **For Sales and Payments** (stages 6 and 7), raised by the owner on 2026-09-29 while deciding amendment 13(a): pressing pay twice, or a request processed twice, must pay once |

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
| 8 | §1.4, §2.3, §3.2, §8 (17); platform.md §9.4 | **Step 3a, after its review** — the owner's answers to the questions the review raised. (a) **A private file does not exist in the media library for anyone without `platform.media.private.view`**: describing, retrying or deleting it there answers exactly as for an id that never existed — the review had found the server still accepted a description from staff who could not see the file, and that a refused delete named the application holding it. **A holder acts on a private file with the library's usual permissions on top** — describing needs `platform.media.update`, deleting `platform.media.delete` — so an admin may see only, see and describe, or see, describe and delete, as far as a Super Admin gave them; the private row therefore offers Describe and Delete to whoever may, which replaces amendment 6(b)'s "no Describe, no Delete". The row still shows only the name, upload date and where it is used, and the file is still never opened there; a paper an application holds is still refused as in use. A module's own delete (`deleteMediaFor`) is unchanged. (b) **For step 4**: a staff correction of a company's type to a **deactivated** type first tells them the type becomes active again; confirmed, the type is activated, then assigned. (c) **The audit log**: a reader without `platform.media.private.view` still sees each entry about a private file — the action, the time, who did it and on whose behalf, and the address — but **not which file** (its id) **nor what changed** (type, size, checksum, module, permission, descriptions); an admin who holds the permission sees the whole entry, so a reader who needs it asks one. Which files are private is read from the file while it exists and from its upload entry once it is deleted, since a file's visibility never changes. (d) **A private file never has sizes made** (platform.md §5.4: `variants_status` is NULL for private files): retrying one is refused, and the size generator never writes one to the public disk — the review found nothing stopped it if a private row were ever marked as having sizes. | Only a Super Admin and the admins a Super Admin chooses may deal with a company's papers from the media library, each as far as they were given; a staff member who may manage B2B accounts still does not learn from the library that the papers exist. A correction may not pick something deactivated, so picking one means bringing it back first. The log stays complete for oversight without telling everyone who may read it which company papers exist. | Owner, 2026-09-28; (d) the review of amendment 8 |
| 9 | §1.1, §1.4, §3.1, §7, §8 (9l, 11) | **Step 3b** — the owner's answers to the points the spec left open, (a)–(c) before it was built and (d)–(e) after its review. (a) **While a company is suspended, its open draft is frozen**: removing a file or an answer is refused (`CompanySuspended`) as starting, saving, uploading and answering already are, and discarding the whole draft stays the one thing allowed. (b) **An account Access cannot find** — a session that outlived its account — **is refused as `NotACompanyAccount`**, as an individual account is; no new error. (c) **Opening a file that is not one of the account's own applications'** is refused with a new error, **`ApplicationFileNotFound`**, answered the same whether or not the file exists. (d) **A suspended company may not change its address either** (`CompanySuspended`) — this **reverses** §1.1's 2026-09-25 "may still change its address" and the first half of scenario 11 — so nothing is ever written into a suspended company's draft; the review had found the address change still writing into a draft 9(a) calls frozen. (e) **Starting while suspended is refused even when a draft is already open** (`CompanySuspended`), rather than returning that draft: nothing on the draft side works while suspended but discarding, and the company page still shows the draft. | (a) §1.1 already says a suspended company may not touch its documents, and a draft that can shrink file by file is one that can still be worked on; discarding remains the way to drop it. (b) It cannot happen in practice, and it is not a company account either way. (c) §7 had nothing for it, and an answer that differed for another company's file would say that file exists. (d) "Much easier" (owner): with the address frozen too, a suspended company changes nothing at all but its own draft's existence. (e) One rule — suspended means the draft side is shut — is simpler than an exception for the start button. | Owner, 2026-09-29 |
| 10 | §1.1, §1.3, §3.2, §7, §8 (17, 20–26) | **Step 4, before it is built** — the owner's answers to the points the staff side left open. (a) **One permission per job**, eleven, named as every module's are and an action sharing one with its undo: `b2b.company.view` (list and view), `b2b.company_document.view` (open a company's papers), `b2b.company.review` (approve, reject), `b2b.company.suspend` (suspend, reinstate), `b2b.company.correct_type`, and for each list `b2b.company_type.*` / `b2b.document_type.*` — `create`, `update` (rename, reorder; for a document type also required or optional), `deactivate` (deactivate, activate again). This replaces §3.2's four (`view`, `review`, `suspend`, `types.manage`). All are per store and **none is admin-only**: any staff or admin role may hold any of them. (b) A correction to a deactivated type needs `b2b.company_type.deactivate` as well as `b2b.company.correct_type`. (c) **A type is never deleted**; a deactivated one may be **activated again** — so `DocumentTypeInUse` goes. (d) **Any change to either of a store's lists**, or "Reviewed", clears its "copied from the Saudi store" notice; "Reviewed" goes with either list's `update`. (e) **Approving an application whose company type was deactivated since it was sent needs a choice**: the replacement, the old type for this company alone, or a correction; otherwise `CompanyTypeChoiceRequired`. (f) **Each staff opening of a company's paper is audited**, without the file's id. (g) **The company list** filters by status and store, searches by name, CR number or tax number, shows waiting companies first. (h) **Staff do not change a suspended company's type**: a correction is refused (`CompanySuspended`), and a replacement on deactivating a type skips a suspended company, which keeps the old type — neither the company nor its draft changes while it is suspended. (i) **A type choice is taken only when needed**: approving with one when the type is no longer deactivated is refused (`CompanyTypeChoiceNotNeeded`). (j) **Filtering the list by a store the staff member does not cover is refused** as not allowed. (k) **`TypeNotFound`** answers a staff action on a type that does not exist or is another store's. | (a) "Divide each action into its job", so a role holds exactly the jobs given to it, as the other modules' roles do; suspending is ordinary work, not an admin's alone. (b) A reviewer alone must not change a store's list. (c) A type added by mistake is deactivated and hidden; nothing is lost. (d) An admin who edits the lists has reviewed them. (e) The reviewer says, on the record, which type the approved company carries. (f) They are identity papers. (h) "A suspended company will not have the luxury of such a change" (owner): suspension freezes the company whole, whoever would change it. (i) The reviewer decides again with the facts as they are. | Owner, 2026-09-29 |
| 11 | §1.3, §3.2, §7, §8 (21, 26–28) | **Step 4, after its review** — the owner's answer to what "the replacement" means, and a transfer between types. (a) **The staff member deactivating a type decides for its holders, once; the reviewer follows.** Approving asks for no choice about the type: the company keeps what the deactivation gave it — the replacement, or the old type if it was left. The waiting application's mark stays, for information only. This **reverses 10(e) and 10(i)**: `CompanyTypeChoiceRequired`, `CompanyTypeChoiceNotNeeded` and the approval's choice go. (b) **Deactivating may replace the type with a new one created in the same step** — the new type added, the old one deactivated, every holder moved, all or nothing; it needs `b2b.company_type.create` as well as `b2b.company_type.deactivate`. The old type may still be activated again later; its former holders stay on the new one. (c) **Moving every company of one active type to another active type** (`TransferCompanyType`), both staying offered — suspended companies skipped (10(h)), each audited, the lists' notice untouched — as **a twelfth job of its own**, `b2b.company.transfer_type`. | (a) "The staff who make the deactivation operation will decide what will happen with this type's holders; the staff approving follow the first staff's rules" (owner). The review had found "use the replacement" accepting any type the company happened to hold. (b) A replacement that does not exist yet should not take two steps, one of which could fail alone. (c) A clear transfer between two types that both stay valid; its own job, so a role holds exactly that. | Owner, 2026-09-29 |
| 12 | §1.1, §2.3, §6, §8 (14, 18), §9 (2–4) | **Step 5, before it is built** — the owner's answers for the public contract. (a) **Anonymizing an account**: the company's personal fields are its **name, CR number, tax number and address**, replaced by placeholders on the company and on every application it sent, whose notes and text answers are cleared and whose papers and answer files are deleted; **an unsent draft is deleted whole**. The company row, its type, its status, staff's flags and requests, and the decision record stay. (b) **The bank account shown to an approved company is three per-store settings** — the IBAN (format and check digits checked), the bank's name and the account holder's name — changed under Platform's `platform.settings.update`, no B2B job of its own. **They start empty** — a Platform addition lets a text setting be marked "may be empty" (platform.md §1.3, §9.4) — and an approved company is shown the bank account only once all three are filled in; until then the page says it is not available yet. (c) **How a company pays** — by bank transfer, then uploading the transfer's document, or through staff who handle it — is recorded for Sales and Payments, where it is chosen with the order; B2B only holds the bank details. | (a) A sole proprietor's numbers identify a person; a draft nobody reviewed is no record. (b) A transfer needs the holder's name and the bank as well as the IBAN; the store's settings are one job. (c) "Yes, with orders" (owner). | Owner, 2026-09-29 |
| 13 | §1.1, §1.3, §2.1, §2.2, §2.3, §3.2, §5, §6, §7, §8 (14, 18, 29), §9 (7) | **Step 5, after its review** — the owner's answers to what the independent review of step 5 raised. (a) **Anonymizing, completed**: a company still "Other" gives up its own words for its type too, to the placeholder; an application still waiting stays in staff's queue, emptied, and a reviewer rejects it by hand; B2B's part **runs from the queue, retried when it fails, and is done once** however many times it runs. (b) **"Other" is never approved**: approving is refused while the company is "Other" (`CompanyTypeNotSet`); staff correct it first to a listed type, existing or added by an admin; an approved company is never corrected to "Other"; other modules see an "Other" company's type as **not set yet** — `CompanyDto` carries neither type name and never the words. (c) **Bank transfer is on only while all three bank settings are filled in**, else **temporarily off**: a company then pays only through staff; `B2BApi::bankAccount(store)` answers null, so checkout (Sales, stage 6) disables paying by transfer and the server refuses it; the settings page shows one line in the Companies section, on or temporarily off — a second Platform addition (platform.md §1.3, §9.4). (d) **The database backs (b)**: CHECK `companies_approved_type_listed` refuses an approved company, or one suspended from approved, that is "Other". (e) **Approving the waiting application of an erased account is refused** (`CompanyAccountDeleted`); staff reject it. | (a) B2B's part ran in Access's after-commit callbacks: a failure there was never retried, and Access logged the account as not anonymized although its own part was done (the review of step 5). (b) The words are a hint to the reviewer, not a type: "whatever company types in other, the staff still must replace the other with valid type from us" (owner). (c) Bank transfer stays stopped until the store enters its account, and the server refuses what the button would not offer (owner). (d), (e) Asked after the second review of step 5: the module backs its one-table rules with CHECKs, and an approval nobody can ever use is no decision (owner). | Owner, 2026-09-29 |
| 14 | §1.2, §3.2, §4.4, §4.5, §5, §5.2, §8 (30–36) | **Step 6, before it is built** — the owner's answers for the company's own screens. (a) **One company page, the design's** (`TouchWood Screens.dc.html`), at `/{store}/{lang}/account/company`, with a Company entry in the account's pages: a status box, the form or what was sent, and a side column. Before the first send it shows the draft alone. **The form saves itself**: each field when it is left, each file when it is uploaded. (b) **After registering, a company account goes to the confirm-email page** as anyone does; the page is reached from the strip or the account's pages — the two stay two pages, which replaces frontend.md F3's "one wizard". (c) **A line on every shop page** for a company account that cannot order, one per stage and status, and nothing once approved, even with an unsent change (§4.4). (d) **Rejected: the reason and Apply again**, the form opened from the company now and the last files, flags marked, requests to answer; **suspended: the reason, read-only**, only discarding a draft. (e) **Change company details opens the filled-in form at once**, and the form says at its top and above Send what sending does (§1.1's warning); the address has its own small form, saved at once. (f) **Every application sent is listed**, newest first, each with what was sent, its papers, its reason or note and its flags and requests. (g) **Every application gets a reference when it is sent**: `TW-CO-`, the year as two digits in its home store's time zone, and a count restarting at `0001` each year — shown to the company and to staff, who can search the company list by it; a new column and a counter table, backed by CHECKs and a unique index. (h) **The side column**: the design's four steps, keeping **"usually within two business days"**, the decision said to come **by email**; how a company pays, by state — neutral before approval, the bank account while approved and bank transfer is on, "temporarily unavailable" while it is off. Times on the page, and a reference's year, are the home store's. | (a) The design is look and behaviour; the spec's rules, fields and types hold where they differ from it. (c) A company that cannot order should know why wherever it is in the shop. (f), (g) The owner asked for the whole history and a number a customer can quote (design: `TW-CO-2291`), with the year: "add year as 26". (h) Only email is sent today; the owner chose to keep the two-day promise. The time zone: "each store will have its own time" (owner) — UTC underneath, as HANDOFF §4 already says. | Owner, 2026-09-29 (B2B step 6) |
| 15 | §4.5 | **Step 6, after its review** — the owner's answers to what the independent review of step 6 raised. (a) **Send waits for a clean form**: it cannot be pressed while anything is still saving, or while a field holds a refused or unsaved value — the page says to finish the marked fields first — nor twice; what is sent is what the page shows. (b) **A rejected company is shown the last rejection's reason**, and, when it was reinstated since, the reinstatement's words on a line of their own. (c) **The address form is there for every company but a suspended one** — pending, approved or rejected — while no draft is open. (d) **A suspended company sees no "before approval" card**, and the payment card says ordering is stopped while it is suspended. | (a) The review proved a company could send the old value while the page showed a refused new one — an approved company then went back to review over a change that never happened. (b) The company's reason is overwritten by a reinstatement, which then read as the reason it was rejected. (c) The spec already lets a pending or rejected company change its address. (d) "Once you are approved" is wrong for a suspended company that was approved. | Owner, 2026-09-30 |
| 16 | §1.1, §3.1, §4.5, §5, §5.1, §7, §8 (36–42) | **Step 6, after the owner used the page** — the owner's changes. (a) **Each field says where it stands**: yellow while not valid, and then never sent — the page checks first, the server again; "Saving…" never stops the other fields; green and "Saved" once the server holds it; red with the server's reason if it refuses. The page takes each field's rules from the server. (b) **Minimums**: name 2, CR number 5, tax number 5, "Other"'s words 3, a text answer 2, the note none — **settings, one set for every store**, changed under `platform.settings.update`, bounded by each field's maximum; held on save and again on send. (c) **The same file twice**: a paper named exactly as a file under another document type of the draft is refused (`DuplicateDocumentFile`), by the page and the server; answers are not compared. (d) **Send is inactive until everything is complete**, with what is missing listed; the server refuses an incomplete send too. (e) **The side column is the application's lifecycle alone**: three steps — filling and sending, under review, the decision with its result — the pointer on the latest application's step, hidden while suspended; "How a company pays" and "Before approval" go, and an approved company's bank account is a card in the main column. This replaces amendment 14(h)'s side column and 15(d). (f) **The address is picked from the account's saved addresses**, any store's, and kept as a copy — its formatted text and which saved address it was — which editing or deleting the saved address does not change; with none saved, Add an address goes to the Addresses page and back (access.md amendment 51). This replaces amendment 2's typed address; the copy may be as long as Access writes it (6,000). | The owner, having used the page: a person must see what is saved and what is wrong before sending; the numbers are the owner's, and admins change them; a scanned file put in two sections is a mistake; the side column should show where the application is and nothing else; an address belongs in the country's own format, entered once. | Owner, 2026-09-30 |
| 17 | §1.1, §1.2, §3.1, §4.5, §5.1 | **Step 6, after the review of amendment 16** — the owner's answers ("the recommended fix for each", 2026-10-01). (a) **One rule for what "at either end" means**: the page and the server trim the same characters — tabs, line breaks, the vertical tab and form feed, every Unicode space separator (a no-break space included) and U+FEFF — so a value pasted with an invisible space at its end is never shown valid and then refused, nor the other way round; flags compare after the same trim (§1.2). (b) **An edited saved address can be picked again**: an address shows as picked only when it is the one picked **and** it still reads as the kept copy; once edited in the address book it is shown unpicked, with a note, and picking it again takes the new text. (c) **A field's own answer never overwrites a newer edit**: while the person has typed on since, the field keeps what they typed. (d) **Two papers of one name are compared as the media library keeps names** (Platform's `MediaFilename::kept`): an invisible character or a space at the ends does not make a second name. (e) **The copy's bound is 6,000 characters**, the column's; Access's limits keep a formatted address within it unless its template repeats a field, and a longer one is refused as too long. (f) **The last decision's marks stay red**, and a marked field that has not been changed never shows green "Saved" (owner). (g) **A refusal says why**: when the page, given the rules again with the refusal, knows the reason, it shows that reason in red rather than only "not valid". (h) **The draft is checked before its values**: a suspended company or an account with no draft is told so before any value is weighed; a saved address deleted while it is being picked is refused on the address, not answered with an error page. (i) **Leaving the form waits its turn**: Add an address goes after the saves still waiting, and Discard waits for them. (j) **§4.5 corrected** (owner): while a draft is open, the address picked in the form changes the company's address **when the application is sent**, not before; the address card, while no draft is open, changes it at once (15(c)). (k) **Confirmed as built** (owner, 2026-09-30): an empty required field turns yellow once it is left or its saved value is cleared, and an untouched one is listed beside Send instead; Add an address is offered with saved addresses too. | The independent review of amendment 16 found the page and the server trimming different characters, an edited saved address that could not be picked again, a field's answer overwriting a newer edit, file names Platform cleans slipping past the check, and a wrong reason for the 6,000 bound; the owner took the recommended fix for each, and answered the two open questions. | Owner, 2026-10-01 |
| 18 | §1.1, §1.3, §3, §5, §5.2 | **A company per store, ordering only where it is approved** (owner, 2026-10-02, the new direction). (a) An account may hold **a company in each store it applies in**, each with its own application, approval, status, lists, bank account and clock; `customer_id` is unique together with `home_store_id`, which is now **the store the company applied in**. (b) **Ordering in a store needs that store's company `APPROVED`**; company prices still follow the account type (handoff §8.2). Reverses 2026-09-19's "one company record is valid in every store". (c) **An off store** (platform.md §1.6): its companies cannot order or apply there — its pages are gone — and may apply in another store. (d) **Built later**, as a B2B step of its own before step 7; its questions go to the owner before any code — how a company account chooses the store it applies in, whether its details carry over from a company it holds elsewhere, what the company page, the shop line and the emails show for an account with companies in two stores, and how the public contract answers per store. (e) What it changes in code already built: the unique key, the one-open-application rule (per account **and store**), the company page, the staff screens' scope, `B2BApi`. | The owner: "it can apply a new application to another store to order from, as it's a two-regions company"; each country's registration is checked by that country's staff. | Owner, 2026-10-02 |
| 19 | §1.1, §1.2, §3.1, §3.2, §4.4, §4.5, §5, §5.2 | **A company per store: the owner's answers to amendment 18(d)** (2026-10-02). (a) **A company account applies in the store it is browsing**; the form has no store field. (b) **Applying in a second store**: the form starts with the **name and the company type** of its company in another store, both editable; the address, the CR number, the tax number and the documents are entered fresh — they belong to that country. Company types are per-store lists: when the other store's type has no counterpart in this store's list, the type is left empty. (c) **The company page, the shop line and the banners show the company of the store you are in**; where the account has none there, they offer **"Apply in this store"**. **Emails about a company name the store.** (d) As 18(e) said: the unique key becomes `(customer_id, home_store_id)`; the one-open-application rule is **per account and store**; staff scope follows the company's store; `B2BApi` answers per store — ordering in a store needs that store's company `APPROVED`. | The owner's answers to the four questions 18(d) left open. | Owner, 2026-10-02 |
| 20 | §1.1, §1.2, §2.1, §3.1, §4.4, §4.5, §5, §5.2, §6 | **Building amendments 18 and 19** — the points the answers did not settle, each taken as the builder recommends. (a) **A type's counterpart** in this store's list is an **active** type with the same names, ignoring case and the spaces at either end: one matching both its Arabic and its English name first; failing that, the single type matching either name; two matching by one name each, or none, and the type is left empty (as amended after the independent review, 2026-10-02 — and the company carried from is only ever one in a store that is on, platform.md §1.6); a company still "Other" carries its own words over as "Other". (b) **Which company the form starts from**, when the account has several elsewhere: an approved one first, otherwise the newest. (c) **`b2b.applications.store_id`**, the store the application was made in — a first draft has no company yet; existing rows take their company's store, or the account's home store for a draft with no company yet; one open application per `(customer_id, store_id)` (`applications_one_open_per_store`); one company per `(customer_id, home_store_id)` (`companies_one_per_store`). That an application's company is of its own store is a rule in code only. (d) **The account's lock stays one per account**, across its stores: two stores' writes for one account wait for each other, which costs nothing and keeps B2B's one lock order. (e) **Outside a storefront request** — the console, a test — a company use case acts in the account's home store. (f) **`B2BApi` per store**: `company(customerId, storeId)`, `status(customerId, storeId)`, `isApproved(customerId, storeId)` — false while the store is off, whatever the company's status — and `companies(customerId)`, every store's, for the admin screens; `CompanyDto` carries `storeId`. (g) **The shop line**, where the account has no company and no draft in this store but has one elsewhere: "Apply in this store to order here: each store approves its own companies." (h) **The company page** carries the store it is about, the account's companies in other stores (store, name, status), and what "Apply in this store" would start with. (i) **Emails**: each of the three decision emails gains one line naming the store, in the customer's language; the subject and the rest are unchanged. (j) **Anonymizing an account** reaches its company and its open draft in every store. | Each point needed an answer for the code to be written; the owner was asleep. | **[PROVISIONAL 2026-10-02 — owner to confirm]** |
| 21 | §4.6, §8 (43–49) | **Step 7, the staff screens** — written and built in the overnight run of 2026-10-02 while the owner slept; **every pick below is [PROVISIONAL 2026-10-02 — owner to confirm]**, the option the builder would recommend. (a) **Three menu entries in the Companies group** — Companies, Company Types, Document Types — at `/admin/companies`, `/admin/companies/{id}`, `/admin/company-types`, `/admin/document-types`; the two type lists are one types page with a tab each, **for the store in the panel's header**. (b) **The type entries are offered to holders of each list's update job**; holders of only another job on a list open it by its address — offering them the entry needs a Platform addition (a menu entry for any of several permissions), the owner's call. (c) **Reading a type list is part of every job on it**, in that store; no new permission. (d) **The company list**: Company · Status · Store · Sent · Last Change, 25 a page, the store filter offering only the reader's stores and hidden when they have one; the "type deactivated since sent" mark beside the name. (e) **The company page**: status, details, account holder, applications newest first with who decided each, the newest open; papers open through a 30-minute link; without the job a paper shows its type and date but neither its file's name nor its id, and the Open button is disabled with its reason. (f) **Buttons offered only when they can happen next**; Approve **disabled with its reason** while "Other" or the account is erased. (g) **Reject and Suspend use Geist's destructive Modal, not the typed confirmation** — both can be undone; Approve and Reinstate a plain Modal; Approve and Reject at the top of the page, Correct Company Type and Suspend in its actions menu. (h) **Correct Company Type** offers deactivated types, marked, only to someone who may also activate types, and "Other" only to a company not approved. (i) **Times** on the list and the page are each company's home store's. (j) **The types page**: a table per list — position, both names, status, holders (company types) or required (document types) —, row actions in a menu; Activate, Make Required and Make Optional act at once; a new type's position defaults to ten after the last; the "copied" notice's words are the builder's. (k) **Every English and Arabic word** on the screens is the builder's, after Geist's writing rules. | B2B's last step: the staff side of §3.2, built in Geist as frontend.md §1.8 decided (2026-10-01/02). The owner was asleep and the lead agent asked for the recommended option at every open point, each marked to be confirmed in the morning. | Builder, 2026-10-02 — **provisional, owner to confirm** |
