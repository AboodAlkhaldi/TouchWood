# Feedback — Module Specification

**Status:** **ACCEPTED by the owner, 2026-10-10** (merged as #104) — sections 1–9 written 2026-10-09
from the owner's answers of the same day (§9.1). The picks marked "my assumption" were accepted with
it.
**Tier:** 2. **Stage:** 6 (handoff §17), the third of the stage's modules to be built (Loyalty →
Promotions → Feedback → Sales).
**Depends on:** Platform, Access, Catalog, Sales (handoff §4.4; `deptrac.yaml`: Feedback → Sales for
the verified purchase).
**Source:** `docs/HANDOFF.md` §4.2, §4.4, §7.9, §13.1, §14, §16, and its 2026-10-02 amendment (reviews
global per product); the owner's answers (§9.1); the merged code as of `0fb9de3`.

Feedback owns **reviews and ratings, product questions and answers, and the wishlist**: what customers
write and save about products, moderated and answered by staff (handoff §4.2: "customer-written,
product-attached, staff-moderated, bilingual content"). There is **no chat, no ticketing and no
channel configuration** — anything beyond one product question goes to WhatsApp support, which is a
link (handoff §13.1).

## What this module does not own

| Concern | Owner |
|---|---|
| Orders, deliveries and returns — whether a customer bought a product | Sales — Feedback asks it, and listens to its returns (§2.3, §6) |
| Products, whether a store sells one, the shop's lists and their sorting | Catalog — Feedback pushes each product's rating into the listing (§1.2) |
| Telling a customer their question was answered, or why something was not published | Ops (stage 8) — the account shows it now (owner, 2026-10-09) |
| Translating a review | Nobody yet — owner, 2026-10-09: "later, when a service is chosen" |
| Customers, staff, the permission catalog | Access |
| Settings, the audit log, staff names | Platform |

---

## 1 · Aggregates and invariants

### 1.1 The review

A **star rating, 1 to 5, plus text** (handoff §13.1) — **no photos** (owner, 2026-10-09).

- **Who may write one:** a signed-in customer with **a verified purchase** — an order holding that
  product **delivered to them**, in any store (owner, 2026-10-09). Companies' accounts count as
  customers. Sales answers the question (§2.3).
- **One review per customer per product, never edited** (owner, 2026-10-09: "1, but not editable") —
  and **a rejected review cannot be written again** (owner, 2026-10-09).
- **Global per product** (owner, 2026-10-02): written in one store, it shows in **every store that
  sells the product**, and its rating counts in each. **One list for the product, shared by all its
  sizes** — no "this size's reviews only"; the size bought is a label on the review (owner confirmed,
  2026-10-19).
- **It shows the variant bought** (handoff §13.1) — the last variant of that product delivered to the
  customer when they wrote it. My assumption, stated for the owner to reject.
- **Its mark:** "Verified purchase" — **flips to "Returned"** once the customer has returned **any of
  that product**, in part or in whole, **including after the review was written** (owner, 2026-10-09:
  "it flips to returned … make sure to be to each product"). It is per product: returning another
  product of the same order changes nothing here. The review stays published. **Writing a review and
  settling a return never miss each other:** both take a lock on (customer, product) first; the writer
  asks Sales after taking it, and the return's listener reads the reviews after taking it, so a return
  completing while the review is written still flips it.
- **The variant shown belongs to the product** — a rule checked in code and tested (Catalog's
  variants carry no unique (id, product) for a composite key).
- **Its language** — the shop's language when it was written — is kept, so a "translate" button can be
  added when a service is chosen (owner, 2026-10-09), shown only when it differs from the reader's
  (handoff §13.1).
- **Its author** reads as their first name and the first letter of their last name, and as **"Deleted
  customer"** once the account is anonymized (handoff §7.9: "reviews and questions survive"). **Staff
  moderating see the same public form and nothing more** — no contact, no link to the account — since
  the author may be another store's customer, whom Access hides from them (access.md amendment
  44(c)). My assumption for the name's form, stated for the owner to reject.
- Text: 1 to 2,000 characters, line breaks kept, no formatting; Arabic digits saved as 0-9
  (frontend.md §1.8).

**Moderation — nothing shows before staff approve it** (owner, 2026-10-09):

- Every new review waits as `PENDING` in a queue for **the staff of the store it was written in**
  (owner, 2026-10-09); they **approve** it — it shows in every store selling the product — or **reject**
  it.
- **A rejection may carry a reason.** With a reason, the customer sees it on their reviews page, and
  later a message about it (Ops). **Without one, no message ever goes out** (owner, 2026-10-09: "the
  sending message [is tied] to if staff wrote reason or not"); the page shows only "Not published".
- An approved review may be **unpublished** later by the same store's staff, the same way, with or
  without a reason. My assumption, stated for the owner to reject.

### 1.2 The rating

**Each product's rating** — the average of its approved reviews, to one decimal, and their count — is
**global** (handoff §13.1: "aggregated rating and count cached on the product"), kept up to date as
reviews are approved or unpublished.

- **In the shop's lists — stars on product cards, and "sort by rating" — only where the store's
  switch is on** (owner, 2026-10-09). Switched off, that store's lists show no stars and offer no
  rating sort; the product page still shows its reviews and rating.
- Feedback **pushes each product's rating into Catalog's listing**, as Pricing pushes prices
  (pricing.md §2.3), and tells Catalog whether a store shows ratings. Catalog never reads Feedback
  (handoff §4.4) — a Catalog addition (§2.3).
- **The switch reaches Catalog through Platform's `SettingChanged`** (published after a setting
  changes): Feedback listens for its own key and pushes that store's flag — the ratings themselves
  are global and pushed with every approval and unpublishing, so nothing more is needed when it turns
  on. Catalog's own default is "shown", matching the setting's, so a store opened later shows ratings
  without any event. A push that fails is reported; `feedback:ratings:repush` (the system's reserved
  `feedback.ratings.repush`, step F3) tells Catalog again every store's flag and every rating.
- **Sorting by rating — a weighted average** (owner, 2026-10-18; numbers 2026-10-19, answer 15): each
  product scores `(5 × C + sum of its ratings) / (5 + its count)`, where **C** is the average of every
  approved review of the products the store sells — as if each product had 5 more reviews at the
  store's average, so one 5★ review never outranks twenty at 4.5. A store with no reviews at all takes
  the plain average. **Products with no reviews come last**; best-rated first only; ties, and the
  no-review group, newest product first. A store whose switch is off offers no rating sort, and a
  request for it gets best-selling. The score is worked out when the list is read, from the sum and
  count Feedback pushes; the stars show the average **to one decimal, half up**. **Built with step
  F3** (catalog.md amendment 25).

| Key | Scope | Type | Default | Changed under |
|---|---|---|---|---|
| `feedback.ratings.in_lists` | Store | Boolean | true | `feedback.settings.update` — **admin-only** (owner, 2026-10-09: "admin and SAdmin switchable") |

My assumption, stated for the owner to reject: the switch starts **on**.

### 1.3 Product questions and answers

**Global, as reviews** (owner, 2026-10-19, answer 16 — replacing "per store" of 2026-10-09): asked in
a store by **a signed-in customer**, answered — or rejected — by **the staff of the store it was asked
in**, and once answered shown on the product **in every store that sells it**. Guests sign in to ask.

- **A question becomes public only once answered.** `PENDING` is staff-only; `REJECTED` never appears
  (handoff §13.1). Staff work a queue filtered by status.
- **The customer sees their questions and the answers in their account** at once; a message about an
  answer comes with Ops (owner, 2026-10-09).
- **A rejection may carry a reason**, exactly as for reviews (§1.1): with one, the customer sees it and
  later gets a message; without one, nothing is sent.
- An answer may be corrected later by the store's staff; each change is audited. My assumption,
  stated for the owner to reject.
- Text: a question 1 to 1,000 characters, an answer 1 to 2,000. A customer may have at most **5
  questions waiting** in all, against spam (questions being global, answer 16).
- Shown on the product page **newest answer first**; correcting an answer does not move it. The asker
  reads as on a review; **the answer is signed "{store}'s team"** — the store that answered, wherever
  it is read — never a staff member's name.
- **An answered question may be unpublished** by the answering store's staff, as a review (§1.1), with
  or without a reason (owner, 2026-10-19, answer 18): it leaves every shop page; the customer still
  sees it, and the reason if one was written.
- **An archived product**'s pending questions and reviews stay in the queues and can still be decided;
  nothing new can be written about it.

### 1.4 The wishlist

**One list per customer, global** (handoff §13.1); guests have none (handoff §7.5: a guest "browses and
keeps a cart, with no favourites").

- **Each item is a product saved in a store**, so the list is grouped by store; saving the same product
  in the same store twice is one item. **An item the store it was saved in no longer sells shows as
  unavailable** rather than disappearing (handoff §13.1) — judged in that store, not the store being
  browsed (owner, 2026-10-19, answer 17); an item of a store switched off shows as unavailable too.
- At most 500 items. My assumption, stated for the owner to reject.
- **Staff see** (the admin menu's "Customers › Favourited products", handoff §14):
  - **a customer's list — only its items saved in stores where the reader holds
    `feedback.wishlist.view`**, as Loyalty shows points by the store they belong to (loyalty.md §3); a
    KSA-only staff member never sees the customer's UAE items;
  - **"Favourited products"** for a store: each product saved there with **how many customers saved
    it**, sortable from most saved (owner, 2026-10-09) — **the handoff's ban on a favourites ranking
    (§16) is lifted**, amended with this spec. Ops' "Favourites" report (§13.3) stays Ops' (stage 8).
- **Caps under load:** saving to the list and asking a question take a lock on the customer first —
  an advisory lock, Feedback having no customer row of its own (answer 20) — so two requests at once
  cannot both pass 500 items or 5 waiting questions.
- **An archived product** stays in lists, shown as unavailable; it leaves "Favourited products".

### 1.5 A deleted customer

When Access anonymizes an account (access.md §1.10): **its reviews and questions stay**, signed
"Deleted customer" (handoff §7.9); **its wishlist is deleted** — something the account had chosen for
itself (handoff §7.9: "anything else the account had chosen for itself cleared").

### 1.6 Each store's rows

Wishlist items belong to a store; a review and a question are global but record the store they were
written in, whose staff moderate them (answer 16). As Loyalty and Promotions: one repository per kind names the store
in every call; no Eloquent model. No Feedback table points at another, so there is no cross-store key
to hold.

**A store switched off**: its review and question queues are reached by Super Admins only — the panel
offers off stores to Super Admins alone (platform.md §9.10) — as its promotions (promotions.md §1.8).

---

## 2 · Public contract

### 2.1 What Feedback offers

**No other module calls Feedback in this stage.** It pushes ratings into Catalog's listing (§1.2),
fills its sections of Catalog's product page through the registry Catalog adds (§2.3), and its own
screens read through its queries (§3). The shop reads reviews and answered questions through shop
readers, as Catalog's shop pages do (`Catalog/Application/Query/Shop`) — public reads with no
permission of their own. Ops adds what it needs when it writes to customers.

**Built before Sales, against Sales's interface.** Handoff §17 says Feedback "cannot be built before"
Sales exists; the owner's order puts it before (2026-10-07). So **Sales's read and its
`ReturnCompleted` event are declared first** — on `main`, as stage 5's interfaces were (#97) — and
faked in Feedback's tests; the real answers come when Sales is built. Handoff §17 is amended with this
spec. **Declared 2026-10-19** (sales.md §2.1), answered until Sales's build by a placeholder that knows
no purchase — so a review is refused as no verified purchase until then.

### 2.2 DTOs and enums

- `ReviewStatus`: `PENDING`, `APPROVED`, `REJECTED`, `UNPUBLISHED`.
- `QuestionStatus`: `PENDING`, `ANSWERED`, `REJECTED` (handoff §13.1), `UNPUBLISHED` (answer 18).
- `ReviewMark`: `VERIFIED_PURCHASE`, `RETURNED`.

### 2.3 What Feedback needs from other modules

| From | What | State |
|---|---|---|
| Sales | **Whether a customer had a product delivered** — across every store; "delivered" per order line, by **the shipment holding that line reaching delivered**, not the order's derived status; **which variant**, the latest by delivery time; and **whether any of that product was returned** — by a completed return only | **Sales's** — `SalesApi::deliveredPurchase(customerId, productId)`, declared on `main` 2026-10-19 (sales.md §2.1), a placeholder answering until Sales's build |
| Sales | **A return completed**: its customer and the products returned, so their reviews flip to "Returned" | Sales's `ReturnCompleted` event (ids only, handoff §4.5's critical events) and `SalesApi::returnedProducts(returnId)` → the customer and the product ids — Sales's spec |
| Catalog | **Reads in bulk, never one id at a time** (handoff §5.4): which of a list of products a store sells now; product cards (name, slug, card photo) for a list of products in a store and language; the values of a list of variants. For asking a question, saving to the wishlist and its "unavailable", the wishlist page, "Favourited products", "My reviews" and the variant a review shows | **A Catalog addition, built with Feedback.** Today `CatalogApi` reads one product (`product`, which lists no variants) or one variant in a store (`storeVariant`) at a time |
| Catalog | **Each product's rating in the listing, and whether a store shows ratings** — for stars on cards and the rating sort (§1.2) | **A Catalog addition, built with Feedback** — beside Pricing's `ListingFacts::prices` (catalog.md amendment 15); the shop's lists are built by the Catalog-screens session, to be agreed with it |
| Catalog | **Feedback's sections on the product page** — its rating and reviews, its answered questions and the ask box, the wishlist button. The page is Catalog's (`ShopReader`), and Catalog may not read Feedback | **A Catalog addition, built with Feedback**: a registry of product-page sections other modules fill — as Access's `ShopperLines` and `CustomerAccountPages` — each section reading its data in one batched query, within the page's query budget and server-side rendering. To agree with the Catalog-screens session, who build the page. **Built with step F6b** (catalog.md amendment 26(b)): `feedback.reviews`, `feedback.questions`, `feedback.wishlist`, one statement for all three, "Show more" as `after[{key}]` on the same page; the product page's queries go up by 1 with no reviews, by 5 with reviews and answers — over the shop's 8, which the owner set as a target, not a cap (2026-10-19) |
| Catalog | **Refusing to delete a product that has reviews or questions** — never a database error | The usage check Promotions adds to Catalog (promotions.md §1.2), which Feedback registers with too — **asked also by the import page's deletion of products made ready or put on sale** (`DeleteImportedProducts`, catalog.md amendment 11(c)), which a reviewed product can reach |
| Access | The author's **first name and last name separately**, and whether anonymized, for many reviews at once | The batch read Promotions adds to Access (promotions.md §2.3) |
| Access | `CustomerAnonymized` | Exists — its docblock already names Feedback |
| Access | The permission catalog, and **a `Support` permission group** — the admin menu's "Support" section (handoff §14: Support › Product questions; reviews beside them) | **Built** with step F1 (access.md amendment 73; `support` in Platform's menu, platform.md §9.4) — the owner's answer of 2026-10-18 |
| Platform | A store setting, the audit log, staff names (`StaffNames::forReader` — who answered or moderated, a Super Admin reading as "System administrator") | Exists |

### 2.4 Built for the screens (§4.2, the owner's answers of 2026-10-24)

The Feedback screens' backend additions (§4.2 in the screens' spec, PR #211), as built:

- **B3 — a text's language from the text** (FB-a): a review's and a question's `locale` is the language
  of its **first letter** — an Arabic letter, `ar`; any other letter, `en`; digits, spaces, signs and
  emoji skipped; no letter at all, `en` (`FeedbackText::languageOf`) — no longer the shop's language
  (§1.1's line kept for history: `WriteReview` and `AskQuestion` lost their `locale`). **Staff may set
  it**: `ApproveReview(reviewId, ?locale)`, `AnswerQuestion(questionId, answer, ?locale)` and
  `CorrectAnswer(questionId, answer, ?locale)` take `ar` or `en` (anything else `InvalidFeedbackAttribute`
  'locale', nothing changed); a change is in the same audit entry (`locale: [old, new]`), the same
  language changes and records nothing; correcting only the language is allowed. The owner may change
  the method later.
- **B1 — the star breakdown** (FB-a): the `feedback.reviews` section carries `stars` — every star, 5 to
  1, with its count of the product's **approved** reviews (all stores, as the rating) — and `star`, the one
  star the reviews are narrowed to: asked as `filter[feedback.reviews]=1..5` (anything else shows every
  review), paged with `after[feedback.reviews]` as before. Read in the section's one statement (the page's
  count unchanged, 11). Catalog's part: amendment 31 (`ShopProductVisitDto::$filters`).

---

## 3 · Use cases

Every command and query handler authorizes first (`CommandHandlersAuthorizeTest`). Staff actions are
audited (`feedback.review.approved`, `…rejected`, `…unpublished`; `feedback.question.answered`,
`…answer_changed`, `…rejected`, `…unpublished`).

| Use case | Permission |
|---|---|
| Write a review (verified purchase, one per product) | `feedback.review.write` — every customer |
| Ask a question in a store | `feedback.question.ask` — every customer |
| Save a product to the wishlist, remove it | `feedback.wishlist.manage` — every customer, their own list |
| Approve, reject, unpublish a review written in the store | `feedback.review.moderate` (per store: the review's store) |
| Answer, correct an answer, reject or unpublish a question asked in the store | `feedback.question.answer` (per store: the question's store) |
| Switch ratings in the store's lists | `feedback.settings.update` (per store, **admin-only**) — Platform's `UpdateSetting` |
| Flip reviews to "Returned" on a completed return; clear an anonymized account's wishlist | Listeners, as the system (§6) |

| Read (query) | Permission |
|---|---|
| My reviews, my questions (with answers and reasons), my wishlist | `feedback.own.view` — every customer, their own only |
| The store's review queue and its reviews; its question queue | `feedback.review.moderate`, `feedback.question.answer` |
| A customer's wishlist — only its items of stores where the reader holds the permission; the store's "Favourited products" with counts | `feedback.wishlist.view` (per store; `storesWith` for the list) |
| A product's approved reviews and rating; its answered questions | Shop readers, public (§2.1) |

**The reads as built (step F6a)** — accepted by the owner, 2026-10-19 ("accept all"):
- **Pages:** 20 rows for a customer's own lists, 50 for staff's, each after the last row shown (a
  keyset, never an offset); a wishlist (at most 500) comes whole, grouped by store in the stores'
  own order. "Favourited products" pages after the last count and product read — at most five
  batches a page past archived products, so a page may come back short with a `next`; a count that
  changes between pages may show a product twice or not at all, as any list ranked by a live count.
- **Order:** "my" lists newest first; a queue's waiting items **oldest first** — the next to decide on
  top — and its other states newest first.
- **A customer's wishlist for staff:** a customer the reader may not see (access.md amendment 44(c)),
  or no such customer, reads as an empty list — the same answer for both, no new error.
- **An off store's queues and "Favourited products"** open only to those who may switch stores, as
  Feedback's changes there (§3); an off store's items in a customer's wishlist stay listed, marked.

**The permissions Feedback declares** (three-part names, as Access requires):

| Permission | Audience | Kind | Group | Admin-only |
|---|---|---|---|---|
| `feedback.review.write` | every customer | global | — | — |
| `feedback.question.ask` | every customer | global | — | — |
| `feedback.wishlist.manage` | every customer | global | — | — |
| `feedback.own.view` | every customer | global | — | — |
| `feedback.review.moderate` | Role | per store | `Support` | no |
| `feedback.question.answer` | Role | per store | `Support` | no |
| `feedback.wishlist.view` | Role | per store | `Customers` | no |
| `feedback.settings.update` | Role | per store | `Support` | **yes** |
| `feedback.ratings.repush` | Role, reserved | global | — | — |

---

## 4 · State machines

**A review:**

```
PENDING ──(approved)──▶ APPROVED ──(unpublished)──▶ UNPUBLISHED   (final)
PENDING ──(rejected)──▶ REJECTED   (final — never written again)
```

Its mark: `VERIFIED_PURCHASE` ──(any of that product returned)──▶ `RETURNED` (final), in any status.

**A question:**

```
PENDING ──(answered)──▶ ANSWERED ──(answer corrected)──▶ ANSWERED
ANSWERED ──(unpublished)──▶ UNPUBLISHED   (final — answer 18; the customer still sees it)
PENDING ──(rejected)──▶ REJECTED   (final)
```

**A wishlist item:** saved ⇄ removed; removed when the account is anonymized.

### 4.1 The screens (stage 6's screens)

**Written 2026-10-20** by the stage 6 screens session, after the backend's steps F0–F7 (#170–#187), as
Loyalty's and Promotions' screens were (loyalty.md §4.1, promotions.md §4.1). Every **[Qn]** is a
question for the owner, listed in §9.4; **the owner answered them in three rounds, 2026-10-24** (§9.4b),
and the text below is written to those answers. Where an answer needs the backend, §4.2 lists it. The use cases behind
every button are §3's; a screen offers only what the reader may do next — asked of an Application
query (`FeedbackActionsForReader`, new, as Promotions' `PromotionsActionsForReader`), never of the
authorizer from the screen (`tests/Architecture/AccessDecisionsTest`) — and every handler asks again.

**What every screen keeps** — as Loyalty's and Promotions', unchanged: Geist's rules on shadcn's code
(frontend.md §1.11), every box checking itself as it is typed with the server's own rules (a review
1–2,000 characters, a question 1–1,000, an answer 1–2,000, a reason 1–500), every number in 0-9, every
moment through `Time` in the store's zone, names in the page's language first, typed text isolated
inside sentences, lists paging with **Show More** (keyset: 50 for staff, 20 for a customer's own, §3),
**its own store filter** (`?store=`) on the panel's lists, a Super Admin's off stores marked Off (§1.6).
Words in `src/Modules/Feedback/Presentation/lang/{ar,en}/`; refusals are §7's.

**Who sees what.** Reviews need `feedback.review.moderate` in the store, questions
`feedback.question.answer`, wishlists `feedback.wishlist.view` (§3). **A reader without the job sees no
button at all; one whom the item or the store stops sees it out of reach, with the reason in its
tooltip** — as Promotions' answer Q16.

**The menu** — Platform's `AdminMenu`; the **Support** group exists (access.md amendment 73), the
**Customers** group too:

| # | Group | Entry | Address | Icon | Offered for |
|---|---|---|---|---|---|
| 10 | Support | Reviews · التقييمات | `/admin/reviews` | `reviews` (Lucide `Star`, new) | `feedback.review.moderate` |
| 20 | Support | Product Questions · أسئلة المنتجات | `/admin/questions` | `questions` (Lucide `MessageCircleQuestion`, new) | `feedback.question.answer` |
| 30 | Customers | Favourited Products · المنتجات المفضلة | `/admin/favourites` | `favourites` (Lucide `Heart`, new) | `feedback.wishlist.view` |
| 40 | Support | Rejection Reasons · أسباب الرفض | `/admin/rejection-reasons` | `reasons` (Lucide `ListChecks`, new) | `feedback.settings.update` |

**No waiting counts in the menu** (owner, Q8 (b): no query more on every page) — the Waiting tab is the default view.

**The settings.** The ratings switch (`feedback.ratings.in_lists`, admin-only) is already on Platform's
settings screen, in its own **Feedback** card (`#settings-feedback`) — nothing new.

#### F1 · Support › Reviews — `/admin/reviews?store=&status=`

- **Tabs by status** **[Q1]**: **Waiting** (the default; oldest first — the next to decide on top),
  **Published**, **Not Published** (rejected), **Unpublished** — newest first (§3).
- **Each review, one row** **[Q5]**: the product (its card photo and name, a link to its page in this
  store; "Archived" or "Not sold here" marked), the size bought, the stars, the text (line breaks kept;
  long text clipped with **Show All**), the author's public name ("Sara A.", "Deleted customer" — never
  more, §1.1), the mark (**Verified purchase** / **Returned**), the date written, and on decided tabs
  who decided and when (`StaffNames::forReader`).
- **Actions on a waiting row** **[Q2]**: **Approve** (at once, a toast) and **Reject…** — a dialog whose
  reason is **optional**: pick one of the **ready reasons**, write one's own, or give none (owner, Q3) —
  saying "With a reason, the customer sees it on their reviews page; without one, they see only "Not
  published"." (§1.1). On a published row: **Unpublish…**, the same dialog.
- **The ready reasons** are a list **admins edit themselves** (owner, round 2 #1 (b)), in Arabic and
  English, the same list for reviews and questions; where it lives and who edits it is §4.2 B4.
- **Many at once** (owner, Q4 (b)): tick boxes on the Waiting tab, up to a page (50): **Approve Selected**
  and **Reject Selected…** (one optional reason for all); on the Published tab **Unpublish Selected…**
  (take several live reviews down at once). A review another staff member decided meanwhile has left
  the Waiting tab; one decided at the very same moment is skipped, and the toast says so ("8 approved,
  2 already decided by someone else").
- **The review's language** (owner, Q24 and round 3 #3): shown on the row as a box **preset to the
  language the text is written in** (§4.2 B3) — Arabic or English, whatever the shop's language — which
  the moderator may switch when approving.
- **Empty**: "No reviews waiting." per tab.

#### F2 · Support › Product Questions — `/admin/questions?store=&status=`

- **Tabs by status**: **Waiting** (default, oldest first), **Answered**, **Rejected**, **Unpublished**.
- **Each question, one row**: the product (as F1), the question, the asker's public name, the date
  asked; on Answered the answer, who answered and when **[Q7]**.
- **Answer…** on a waiting row — a dialog showing the question, **its language as a box preset to the
  language it is written in, which staff may switch** (owner, round 2 #4 — a question in Latin letters
  may be Arabic words), the answer box (1–2,000) **written in that language**, and the line "Signed
  "{store}'s team" in every store that sells the product." (§1.3) **[Q6]**. **Reject…** (optional reason,
  ready or own, as F1). On an answered row: **Correct Answer…** (the same dialog, filled) and
  **Unpublish…**. Bulk actions as F1.
- **Later, with a translation service** (owner, Q5 and round 3 #1 (b) — built last, when the owner says):
  staff write in Arabic, an English version is made for them, both are shown, **Accept** publishes both,
  **Not accepted** lets them write the English themselves.

#### F3 · Customers › Favourited Products — `/admin/favourites?store=`

- **One list, most saved first** (§1.4, answer 8): the product (card photo, name, link; "Not sold here"
  marked, answer 20) and **Saved by** — how many customers saved it in this store. Show More (§3: a page
  may come back short with a next). **[Q9]**

#### F4 · A customer's wishlist, on the staff customer page — `/admin/customers/{customer}`

- **A section of Access's customer page** (`CustomerPageSections`, access.md amendment 72, as Loyalty's
  points) **[Q10]**: one block per store the reader covers with `feedback.wishlist.view`, each saved
  product with its date saved and "Not sold now" marked; nothing for a reader without the job, and for
  a customer the reader may not see (§3).

#### F5 · The product page's Feedback — the shop's `/{store}/{locale}/{slug}`

The page is **Catalog's** (`Catalog/Storefront/Product.tsx`, the Catalog-screens session's); its data
already carries Feedback's three sections (`feedback.reviews`, `feedback.questions`,
`feedback.wishlist`, catalog.md amendment 26(b)). **These screens draw them** — Feedback's own
components, placed by the page in the slots below, agreed with the Catalog-screens session **[Q11]**:

- **Under the title**: the stars, the average ("4.3") and the count ("(12 reviews)"), a link down to the
  reviews — nothing when there are none **[Q22]**.
- **Beside Add to Cart**: the **Save** heart (filled once saved), and on every product card too (F7);
  a guest who presses it is sent to sign in and comes back with it saved (owner, Q17 (b), Q18 (a)).
- **Reviews**, under the description: the average and count again, then each approved review — stars,
  author, "Verified purchase" or "Returned", the size bought, the date **[Q23]**, the text — newest
  first, 10 then **Show More** (`after[feedback.reviews]`). **A breakdown by stars** — five bars, each with
  its count — **each bar a filter** ("4★ only"; again to clear), as the big shops do (owner, Q12, Q13 and
  round 3); no sort, newest first always. **Write a Review…** when `canWrite` **[Q14]**.
- **A review's date** (owner, Q23): "3 days ago" up to 6 days, "a week ago" at 7, the date from 8 on;
  the full date and time on hover. The same on the customer's account pages; the panel keeps exact
  times.
- **A review or an answer in another language** than the page is **shown as written** (owner, round 3
  #4): an English question on the Arabic store stays English, its answer too. A **Translate** button
  comes with the translation service (F2's "later").
- **Questions**, after the reviews: each answered question — the asker, the question, the answer signed
  "{store}'s team", the date answered — newest answer first, 10 then Show More. **Ask a Question…** for
  a signed-in customer (`canAsk`); a guest reads "Sign in to ask" **[Q16]**.

#### F6 · Writing a review, asking a question — dialogs on the product page

- **Write a Review**: five stars to pick (required), the text (1–2,000), Submit. Then **"Thanks — your
  review shows once the store approves it."** and the button gives way to "You reviewed this product"
  **[Q15]**. Refusals are §7's: not a verified purchase (the button is not offered then), already
  reviewed.
- **Ask a Question**: the question (1–1,000), Submit; then "Thanks — the answer shows here and in your
  account." A sixth waiting question is refused with §7's words.

#### F7 · Stars on the shop's product cards, and Best Rated

- On Catalog's lists (category, brand, search) **where the store's switch is on**: each card's stars and
  count (`ShopCardData.rating`, `ratingCount`, catalog.md amendment 25), and **Best Rated** among the
  sorts (`ShopFiltersData.ratingSort`); **the Save heart on every card** (owner, Q17 (b)) — §4.2 B5. The
  lists are Catalog's pages; these screens add the drawing **[Q21]**.

#### F8 · The shop account's Feedback pages

Pages of the account beside its tabs (Access's `CustomerAccountPages`, as My Points and My Coupons),
**always offered** **[Q19]**:

- **My Reviews** — each review: the product (link), the store written in, the stars, the text, the size,
  its state in plain words — **Waiting for approval**, **Published**, **Not published** (with the
  store's reason when one was written), **Unpublished** (with its reason) — and the mark. 20 then Show
  More.
- **My Questions** — each question, its store, its state (**Waiting for an answer**, **Answered** with the
  answer, **Not published** with its reason, **Unpublished**), newest first.
- **My Wishlist** — grouped by store **[Q20]**: each product's card (photo, name, link in that store) with
  **Remove**; one the store no longer sells marked **Not available now** (answer 17); an off store's
  group marked. Empty: "Nothing saved yet."

#### F9 · Support › Rejection Reasons — `/admin/rejection-reasons`

- The ready reasons (§4.2 B4), one list for every store: each reason in Arabic and English, in order;
  **Add Reason…**, **Edit…**, **Delete…**, drag or Move Up/Down to reorder. Offered to admins
  (`feedback.settings.update`).

**Every screen above** is checked at phone width (375 px, nothing scrolling sideways), in Arabic and
English, light and dark; its own query count recorded by a feature test; each page under 60 KB gzipped.

### 4.2 What the screens need from the backend (the owner's answers, 2026-10-24)

| # | Addition | For |
|---|---|---|
| B1 | **The star breakdown** — each star's count of a product's approved reviews, in the reviews section; and the section paged **filtered to one star** (`after` and the star) | F5 |
| B2 | **Bulk decisions** — approve, reject (one optional reason) and unpublish many reviews or questions in one request, each decided on its own under its own lock, the answer saying which were done and which were already decided | F1, F2 |
| B3 | **A text's language from the text** — a review's and a question's language worked out from its first letter (an Arabic letter: Arabic; otherwise English; digits, spaces, signs and emoji skipped), replacing the shop's language (§1.1); and **staff may set it**: on approving a review, on answering a question. The owner may change the method later | F1, F2, F5 |
| B4 | **The ready reasons** — **one list for every store**, edited by admins (owner, 2026-10-24) under `feedback.settings.update`, in both languages, the same for reviews and questions; read by the reject and unpublish dialogs, edited on a small panel page (F9) | F1, F2, F9 |
| B5 | **The Save heart on cards** — which of a list's products the signed-in customer saved in this store, in one query, through a Catalog addition for what other modules add to a card (as the product page's sections, catalog.md amendment 26) | F7 |
| — | **Later, with a translation service**: the automatic English for an answer, and the shoppers' Translate button (owner: built last, when the owner says) | F2, F5 |

---

## 5 · Tables

Schema `feedback`. ULID ids. Every column NOT NULL unless marked NULL; every rule also checked in code
first (handoff §5.3); every CHECK on a nullable column says `IS NOT NULL` where a value is needed, and
every kind column has a CHECK `IN (…)` (lessons 35, 162).

| Table | Columns | Rules |
|---|---|---|
| `feedback.reviews` | `id`, `product_id` → `catalog.products` RESTRICT, `variant_id` → `catalog.variants` RESTRICT (the variant bought), `customer_id` → `access.customers` RESTRICT, `store_id` → `platform.stores` RESTRICT (written in), `rating`, `body`, `locale`, `status`, `mark`, `reason` NULL, `moderated_by` NULL → `access.staff_users` RESTRICT, `moderated_at` NULL, `created_at` | UNIQUE (`customer_id`, `product_id`) — one review per product, for ever (a rejected one keeps its row); CHECK `rating BETWEEN 1 AND 5`; CHECK `char_length(body) BETWEEN 1 AND 2000`; CHECK `locale IN ('ar', 'en')`; CHECK `status IN ('PENDING', 'APPROVED', 'REJECTED', 'UNPUBLISHED')`, `mark IN ('VERIFIED_PURCHASE', 'RETURNED')`; CHECK `(moderated_by IS NULL) = (moderated_at IS NULL)` and `(status = 'PENDING') = (moderated_at IS NULL)`; CHECK `reason IS NULL OR (status IN ('REJECTED', 'UNPUBLISHED') AND char_length(reason) BETWEEN 1 AND 500)`. The variant belongs to the product: a code rule, tested (§1.1) |
| `feedback.product_ratings` | `product_id` PK → `catalog.products` RESTRICT, `rating_sum`, `rating_count`, `updated_at` | CHECK `rating_count >= 0 AND rating_sum BETWEEN rating_count AND 5 * rating_count`; kept in the same transaction as each approval or unpublishing |
| `feedback.questions` | `id`, `product_id` → `catalog.products` RESTRICT, `store_id` → `platform.stores` RESTRICT, `customer_id` → `access.customers` RESTRICT, `body`, `locale`, `status`, `answer` NULL, `answered_by` NULL → `access.staff_users` RESTRICT, `answered_at` NULL, `reason` NULL, `created_at`, `updated_at` | CHECK `char_length(body) BETWEEN 1 AND 1000`, `locale IN ('ar', 'en')`, `status IN ('PENDING', 'ANSWERED', 'REJECTED', 'UNPUBLISHED')`; CHECK `num_nonnulls(answer, answered_by, answered_at) IN (0, 3)` and `(status IN ('ANSWERED', 'UNPUBLISHED')) = (answer IS NOT NULL)`; CHECK `answer IS NULL OR char_length(answer) BETWEEN 1 AND 2000`; CHECK `reason IS NULL OR (status IN ('REJECTED', 'UNPUBLISHED') AND char_length(reason) BETWEEN 1 AND 500)` |
| `feedback.wishlist_items` | `customer_id` → `access.customers` RESTRICT, `product_id` → `catalog.products` CASCADE, `store_id` → `platform.stores` RESTRICT, `saved_at` | PK (`customer_id`, `product_id`, `store_id`). A product Catalog deletes leaves the lists (nothing to keep) |

**Indexes:** reviews by (`product_id`, `status`, `created_at` DESC, `id` DESC) — a product's page; by
(`store_id`, `status`, `created_at`, `id`) — a store's queue; the unique (`customer_id`, `product_id`)
— "my reviews"; by `variant_id` — Catalog's check before deleting a variant;
questions by (`product_id`, `status`, `answered_at` DESC, `id` DESC), by (`store_id`,
`status`, `created_at`, `id`) and by (`customer_id`, `status`) — "my questions" and the waiting count; wishlist items by (`store_id`,
`product_id`) — the counts; by `customer_id` — the key, which starts with it (no second index).

---

## 6 · Events

**Published:** none in this stage. Ops adds "your question was answered" and "your review was not
published, because …" (only with a reason, §1.1) when it writes to customers.

**Consumed:**

| Event | From | What Feedback does |
|---|---|---|
| `ReturnCompleted` | Sales | Reads the return's customer and products; flips those reviews to "Returned" |
| `CustomerAnonymized` | Access | Deletes the account's wishlist; its reviews and questions now read "Deleted customer" (from Access, at read time) |
| `SettingChanged` | Platform | For `feedback.ratings.in_lists`: pushes that store's flag to Catalog (§1.2) |

Every listener does its work once however often it runs — setting a mark already set, deleting a list
already empty, pushing the same flag — so they need no `processed_events` row (as Catalog's and B2B's
listeners).

---

## 7 · Errors

Each extends `FeedbackError`, which extends `Shared\Domain\Error\DomainError`.

| Error | Status | When |
|---|---|---|
| `NotAVerifiedPurchase` | FORBIDDEN | Writing a review with no delivered order of the product |
| `AlreadyReviewed` | CONFLICT | A second review of a product — whatever became of the first, a rejected one included |
| `TooManyWaitingQuestions` | CONFLICT | A sixth question waiting, in any store (§1.3) |
| `WishlistFull` | CONFLICT | A 501st item (§1.4) |
| `InvalidModeration` | CONFLICT | A change its state does not allow: approving a rejected review, answering a rejected question, unpublishing a review not approved or a question not answered (answer 18), repeating an approval (answer 20) |
| `ReviewNotFound`, `QuestionNotFound` | NOT_FOUND | Unknown, or of a store the staff member does not cover — the same answer for both |
| `InvalidFeedbackAttribute` | INVALID | A value refused: a rating outside 1–5, text empty or too long, a product that is not ready, a store that does not sell the product (answer 19), a variant not of the product |

---

## 8 · Test scenarios

**Reviews**
- A customer with a delivered order writes one review; one without is refused; a second review of the
  same product is refused — also after the first was rejected; there is no editing.
- A review waits until the writing store's staff approve it; then it shows in every store selling the
  product, and not in one that does not.
- The variant shown is the last one delivered.
- Returning part of the product flips its review to "Returned"; returning another product of the same
  order does not; a return before the review is written gives it "Returned" at once.
- Rejected with a reason: the customer sees it; without: "Not published", and nothing is queued for
  sending.
- Unpublished: it leaves the product page and its rating.
- An anonymized author reads "Deleted customer"; moderators see only the public form of a name.
- A return completing while a review is being written still flips it (the lock).
- A review naming a variant of another product is refused.

**Ratings**
- The average and count follow approvals and unpublishing, to one decimal, global per product.
- Pushed into Catalog's listing; a store with the switch off pushes no stars and offers no rating sort.
- Turning the switch off and on again (through Platform's settings page) reaches Catalog; a store
  opened later shows ratings with nothing pushed.
- Only an admin role may change the switch.

**Questions**
- Asked in a store by a signed-in customer, answered by that store; public only when answered, then in
  every store selling the product; a rejected one never public; an unpublished one leaves the shop; at
  most 5 waiting in all.
- The answer shows in the customer's account; a correction is audited.

**Wishlist**
- Saving twice in a store is one item; the same product in two stores is two; a product the store it
  was saved in stops selling shows as unavailable, whichever store is browsed.
- Staff's "Favourited products" counts per store, sorted by count; a customer's list shows a KSA-only
  reader the KSA items only.
- An anonymized account's list is deleted; at most 500 items, even with two saves at once.
- Catalog's import page deleting a reviewed product is refused with what uses it — never a database
  error; an archived product stays in lists as unavailable.

**Catalog and the page**
- A product page fills Feedback's sections in a fixed number of queries, however many reviews.
- The wishlist page of 500 items reads Catalog in batches, not 500 calls.

**Permissions and the database**
- Each permission; customers read only their own; moderators only their stores' queues.
- Every CHECK refused with its nullable columns left NULL and with an unknown kind (lesson 162) — an
  approved review missing its moderation time, a pending question holding an answer; a second review
  per product refused by the database too.
- An off store's queues: Super Admins only.

---

## 9 · Open questions

### 9.1 The owner's answers, 2026-10-09

1. Moderation: **a review shows only after staff approve it.**
2. How many: **one review per product, not editable.**
3. The translate button: **later, when a service is chosen** (each review's language kept now).
4. A verified purchase: **an order of it delivered**; returning some or all **flips its mark to
   "Returned"**, also after the review — **per product**.
5. Questions: **signed-in customers, per store** — made global on 2026-10-19 (answer 16).
6. Staff and wishlists: **a customer's list, and how many customers saved each product.**
7. Review photos: **no — rating and text only.**
8. How staff see saved counts: **per store, a list sortable by count** (lifts handoff §16's ban).
9. Ratings in the shop's lists: **stars on cards and sort by rating, switchable by admins and Super
   Admins — off, no stars and no rating sort.**
10. A rejected review: **cannot be written again.**
11. The switch: **ratings in lists only, per store.**
12. Who moderates a review: **the store it was written in.**
13. A rejection's reason: **shown when staff write one; no reason, no message at all.**
14. An answered question: **in the account now; a message with Ops.**

### 9.1b The owner's answers, 2026-10-19 (before the build)

15. **The rating sort's numbers:** a weighted average with a weight of 5 reviews at the store's
    average; no reviews last; best-rated first only; off, best-selling instead (§1.2).
16. **Questions are global, as reviews** — "feedbacks become all global, since all products are same
    across stores": the store a question was asked in answers it; once answered it shows in every
    store selling the product; at most 5 waiting per customer in all (§1.3).
17. **A wishlist item's "unavailable" is judged in the store it was saved in** (§1.4).
18. **An answered question can be unpublished**, as a review (§1.3).
19. **"A store sells a product"** — for reviews, questions and the wishlist — means: the product is
    ready, chosen in that store, and not marked "not available now"; stock does not count, so a
    product out of stock can still be saved and asked about. Asking or saving in a store that does not
    sell it is `InvalidFeedbackAttribute`; a review is written in a store that sells the product, so
    the store that moderates it shows it. My assumption, built with step F2 (catalog.md amendment 24):
    which kind of buyer a store shows a category to (`store_category_audiences`) is not part of "sells"
    — a shopper who cannot see a product never gets it delivered, so never reviews it.
20. With the spec's other picks: a product a store stopped selling stays in "Favourited products",
    marked; a customer's wishlist is read only where Access lets the reader see the customer; a gift
    line counts neither as a delivered nor a returned purchase; only pieces actually received make a
    return; the caps and one-review rule held by transaction-scoped advisory locks (Feedback has no
    customer row of its own); repeating an approval is `InvalidModeration`; a correction keeps who
    answered, the corrector in the audit; removing an item not on the list does nothing.

### 9.2 Still open

1. ~~**What Feedback needs from Sales**~~ — **settled**: declared on `main` with step F0 (#170,
   sales.md §2.1), answered by the placeholder `NothingSoldYet` until Sales's build replaced it (step S10b,
   `DatabaseSalesApi`).
2. ~~**The Catalog additions**~~ — **settled**: built with Feedback, catalog.md amendments 24 (which
   products a store sells), 25 (ratings in the listing and the weighted sort) and 26 (product cards,
   the product page's sections). Was: **The Catalog additions** — bulk reads, ratings in the listing (and how the rating sort places
   products with few or no reviews), the product page's sections — agreed with the Catalog-screens
   session, who build the shop's lists and pages.
3. ~~**The `Support` permission group and menu section**~~ — **settled**: the owner's answer of
   2026-10-18; built with step F1 (access.md amendment 73, platform.md §9.4).
4. ~~**Feedback's screens**~~ — **settled**: §4.1, the owner's answers in §9.4b (2026-10-24).
5. ~~**Every assumption marked above**~~ — **settled**: the variant shown, the author's name (and what
   moderators see), answers signed by the store's team, unpublishing, correcting answers, 5 waiting
   questions, 500 wishlist items, the switch starting on, the reads' pages, orders and empty answer
   (§3, step F6a) — all **accepted by the owner, 2026-10-19** ("accept all"). Where the text above
   still says "my assumption", read "accepted".

### 9.4 The screens' questions, round 1 (2026-10-20)

For §4.1. Each has options; **(a) is the one I recommend** unless said otherwise. Nothing is built
until these are answered.

| Q | About | Options |
|---|---|---|
| Q1 | Moderation pages | (a) **Two pages, Reviews and Product Questions, each with status tabs** (Waiting first). (b) One "Moderation" inbox mixing both |
| Q2 | Approving a review | (a) **Approve right on the row, at once, with a toast**; Reject… and Unpublish… in a dialog. (b) Open each review on its own page first |
| Q3 | A rejection's reason | (a) **Free text, optional**, with the line saying what the customer sees with and without one. (b) A few ready reasons to pick, plus free text. (c) Required |
| Q4 | Many at once | (a) **No bulk approve or reject now** — one by one. (b) Tick boxes and "Approve selected" |
| Q5 | What a moderator sees on a review | (a) **The product (photo, name, link in this store), the size bought, the stars, the text, the author's public name, the mark, the date; on decided tabs who decided and when.** Never the author's contact (§1.1). (b) The same plus the review's language when it differs from the page's |
| Q6 | Answering a question | (a) **A dialog showing the question, the answer box, and "Signed {store}'s team in every store"**; Correct Answer… the same dialog filled. (b) An answer box right in the row |
| Q7 | Who answered | (a) **Shown to staff on the Answered tab: the staff member and the time** (customers only ever see "{store}'s team", §1.3). (b) Not shown |
| Q8 | Waiting counts in the menu | (a) **A count beside Reviews and Product Questions** (Platform's menu counts) — about one query more on every panel page. (b) No counts; the Waiting tab says them |
| Q9 | Favourited Products | (a) **The product (photo, name, link, "Not sold here"), Saved by, most saved first, Show More.** (b) The same plus a search by product name |
| Q10 | A customer's wishlist for staff | (a) **A section on the staff customer page, a block per store the reader covers** (as Points). (b) A page of its own under Customers |
| Q11 | The product page | (a) **I draw Feedback's parts as components; the Catalog-screens session places them on its page** (stars under the title, the heart beside Add to Cart, Reviews then Questions under the description) — I'll agree it with them. (b) I edit their product page myself |
| Q12 | A rating breakdown (how many 5★, 4★…) | (a) **Not now** — the average and the count only; a breakdown needs a new read. (b) Yes — a backend addition first |
| Q13 | Sorting or filtering reviews on the product page | (a) **Newest first only**. (b) Also "Highest" and "Lowest" — a backend addition first |
| Q14 | Where to write a review | (a) **On the product page, "Write a Review…", shown only to a customer who may** (a delivered purchase, none written); later also from the order in My Orders, with Sales's screens. (b) Only from My Orders. Note: until Sales is built nobody has a delivered purchase, so the button shows for nobody yet |
| Q15 | After writing a review | (a) **"Thanks — your review shows once the store approves it."**, then "You reviewed this product" in place of the button; it is in My Reviews as Waiting. (b) Show the customer their own waiting review on the product page too |
| Q16 | Asking as a guest | (a) **"Sign in to ask" with a link that brings them back.** (b) The box shown, asking to sign in on Submit |
| Q17 | The Save heart | (a) **On the product page only, for now** — on product cards it would need each list to know what the customer saved (a new read on every list). (b) Also on every product card |
| Q18 | A guest presses the heart | (a) **Sent to sign in, then back to the product, saved.** (b) Back to the product, not saved — they press again |
| Q19 | The account's Feedback pages | (a) **Three pages in the account's side list — My Reviews, My Questions, My Wishlist — always offered.** (b) One "My Feedback" page with three tabs. (c) Offered only once the customer has something there — one more query on every signed-in shop page |
| Q20 | My Wishlist | (a) **Grouped by store, each with its products, Remove, "Not available now"; the product links to its page in that store.** (b) One list, the store as a label |
| Q21 | Stars on product cards and Best Rated | (a) **I add them to Catalog's list pages (the drawing only; the data is already there), agreed with the Catalog-screens session.** (b) The Catalog-screens session does it |
| Q22 | How the stars read | (a) **Five stars filled to the nearest half, then "4.3" and "(12)"**. (b) Whole stars only. (c) One star and "4.3 (12)" |
| Q23 | A review's date | (a) **The day, written out ("12 October 2026")**, as public pages do. (b) Relative ("3 days ago") |
| Q24 | A review in another language | (a) **Shown as written, nothing more until a translate service is chosen** (§1.1). (b) A small "Written in Arabic" label |
| Q25 | Staff answering from the shop | (a) **No — answering and moderating happen in the panel only.** (b) A "Reply" link on the shop for signed-in staff |

### 9.4b The owner's answers to §9.4 (2026-10-24, three rounds)

(a) for Q1, Q2, Q6, Q7, Q9, Q10, Q11, Q14 (as an order of building), Q15, Q16, Q18, Q19, Q20, Q21, Q22
(the most common), Q25; and:
- **Q3**: optional; a ready reason, one's own, or none; the ready reasons a list admins edit (round 2 #1).
- **Q4**: (b) — bulk approve and reject, and unpublish on the Published tab (round 3: explained, accepted).
- **Q5**: one answer, in the question's language; later, with a translation service, Arabic written and
  English made for staff to accept or rewrite.
- **Q8**: (b) — no menu counts.
- **Q12, Q13**: the breakdown now; each bar a filter; no sort.
- **Q17**: (b) — the heart on cards too (round 2 #6 (a): the cost accepted).
- **Q23**: relative to 6 days, "a week ago" at 7, the date from 8 (round 2 #7 (a): shop and account;
  the panel exact).
- **Q24**: a text's language is the one it is written in, worked out from it (B3); staff may switch it,
  for reviews and questions alike (round 2 #4, round 3 #3).
- **Round 3 #1**: the translation service waits — built last, when the owner says.
- **Round 3 #4**: shown as written; a Translate button with the service.

### 9.3 The independent review of the draft (2026-10-09/10)

One read-only review; each finding was checked in the code before acting. **Blocker, fixed:** the spec
said Catalog could already tell which products a store sells and give many products' cards at once —
it reads one product or one variant at a time — so a Catalog addition of bulk reads (§2.3). **Fixed:**
the ratings switch reaching Catalog through Platform's `SettingChanged`; a lock so a return completing
while a review is written still flips it; the import page's deletion asking Catalog's usage check; a
customer's wishlist shown only for the reader's stores; moderators seeing only the public form of a
name; Feedback's sections on Catalog's product page (a Catalog addition); NULL-safe CHECKs that a
half-filled row could pass; a review's variant belonging to its product; building before Sales against
its declared interface (handoff §17 amended); the handoff's §13.3, §14, §4.1, §13.1 and §16 made
consistent. And the minor points: archived products and off stores, the caps under load, a missing
index, the order of answered questions, the answer's signature, the author's name in two parts.
