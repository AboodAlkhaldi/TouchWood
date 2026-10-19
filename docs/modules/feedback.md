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
  sells the product**, and its rating counts in each.
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
  changes): Feedback listens for its own key and pushes that store's flag — and, when it turns on, the
  store's ratings. Catalog's own default is "shown", matching the setting's, so a store opened later
  shows ratings without any event.
- **Sorting by rating — a weighted average** (owner, 2026-10-18; numbers 2026-10-19, answer 15): each
  product scores `(5 × C + sum of its ratings) / (5 + its count)`, where **C** is the average of every
  approved review of the products the store sells — as if each product had 5 more reviews at the
  store's average, so one 5★ review never outranks twenty at 4.5. A store with no reviews at all takes
  the plain average. **Products with no reviews come last**; best-rated first only; ties, and the
  no-review group, newest product first. A store whose switch is off offers no rating sort, and a
  request for it gets best-selling. The score is worked out when the list is read, from the sum and
  count Feedback pushes; the stars show the average **to one decimal, half up**.

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
- **Caps under load:** saving to the list and asking a question lock the customer's row first, so two
  requests at once cannot both pass 500 items or 5 waiting questions.
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
| Catalog | **Feedback's sections on the product page** — its rating and reviews, its answered questions and the ask box, the wishlist button. The page is Catalog's (`ShopReader`), and Catalog may not read Feedback | **A Catalog addition, built with Feedback**: a registry of product-page sections other modules fill — as Access's `ShopperLines` and `CustomerAccountPages` — each section reading its data in one batched query, within the page's query budget and server-side rendering. To agree with the Catalog-screens session, who build the page |
| Catalog | **Refusing to delete a product that has reviews or questions** — never a database error | The usage check Promotions adds to Catalog (promotions.md §1.2), which Feedback registers with too — **asked also by the import page's deletion of products made ready or put on sale** (`DeleteImportedProducts`, catalog.md amendment 11(c)), which a reviewed product can reach |
| Access | The author's **first name and last name separately**, and whether anonymized, for many reviews at once | The batch read Promotions adds to Access (promotions.md §2.3) |
| Access | `CustomerAnonymized` | Exists — its docblock already names Feedback |
| Access | The permission catalog, and **a `Support` permission group** — the admin menu's "Support" section (handoff §14: Support › Product questions; reviews beside them) | **Built** with step F1 (access.md amendment 73; `support` in Platform's menu, platform.md §9.4) — the owner's answer of 2026-10-18 |
| Platform | A store setting, the audit log, staff names (`StaffNames::forReader` — who answered or moderated, a Super Admin reading as "System administrator") | Exists |

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

**Indexes:** reviews by (`product_id`, `status`, `created_at` DESC, `id`) — a product's page; by
(`store_id`, `status`, `created_at`, `id`) — a store's queue; the unique (`customer_id`, `product_id`)
— "my reviews"; by `variant_id` — Catalog's check before deleting a variant;
questions by (`product_id`, `status`, `answered_at` DESC, `id`), by (`store_id`,
`status`, `created_at`, `id`) and by `customer_id` — "my questions"; wishlist items by (`store_id`,
`product_id`) — the counts; by `customer_id`.

---

## 6 · Events

**Published:** none in this stage. Ops adds "your question was answered" and "your review was not
published, because …" (only with a reason, §1.1) when it writes to customers.

**Consumed:**

| Event | From | What Feedback does |
|---|---|---|
| `ReturnCompleted` | Sales | Reads the return's customer and products; flips those reviews to "Returned" |
| `CustomerAnonymized` | Access | Deletes the account's wishlist; its reviews and questions now read "Deleted customer" (from Access, at read time) |
| `SettingChanged` | Platform | For `feedback.ratings.in_lists`: pushes that store's flag to Catalog, and its ratings when turned on (§1.2) |

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

1. **What Feedback needs from Sales** — the delivered-purchase read and the completed-return event,
   precisely as §2.3 states them — is defined in Sales's spec, next, and declared on `main` before
   Feedback is built (§2.1).
2. **The Catalog additions** — bulk reads, ratings in the listing (and how the rating sort places
   products with few or no reviews), the product page's sections — agreed with the Catalog-screens
   session, who build the shop's lists and pages.
3. ~~**The `Support` permission group and menu section**~~ — **settled**: the owner's answer of
   2026-10-18; built with step F1 (access.md amendment 73, platform.md §9.4).
4. **Feedback's screens** — the frontend session's, to confirm (as for Loyalty and Promotions).
5. **Every assumption marked above** — the variant shown, the author's name (and what moderators see),
   answers signed by the store's team, unpublishing, correcting answers, 5 waiting questions, 500
   wishlist items, the switch starting on.

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
