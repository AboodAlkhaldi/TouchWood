# Feedback

Reviews and ratings, product questions and answers, and the wishlist: what customers write and save
about products, moderated and answered by staff. The specification is
[`docs/modules/feedback.md`](../../../docs/modules/feedback.md) (accepted 2026-10-10, the owner's answers
of 2026-10-19 in §9.1b).

Backend only in this stage's build; the screens are another session's.

## Build steps

| Step | What | Where |
|---|---|---|
| F0 — Sales's contract | `SalesApi` (`deliveredPurchase`, `returnedProducts`) and `ReturnCompleted`, declared in Sales before Sales is built, answered by a placeholder that knows no purchase | `src/Modules/Sales/Public`, sales.md §2.1 |
| F1 — foundation | The `feedback` schema; the eight permissions of spec §3 (every customer's four; reviews and questions under Access's new **Support** group, access.md amendment 73; wishlists under Customers; the admin-only ratings switch); Platform's `support` menu area (platform.md §9.4); the store setting `feedback.ratings.in_lists` (on by default); the errors of §7 in both languages | below |
| F2 — reviews | The reviews and product ratings tables; writing a review (verified purchase through `SalesApi`, a store that sells the product — Catalog's `productsSoldIn`, catalog.md amendment 24 — once per product for ever); approving, rejecting, unpublishing (the writing store's staff, audited, the rating kept in the same transaction); `ReturnCompleted` flipping marks; Catalog's usage check for reviewed products and variants | below |
| F3 — ratings in the shop's lists | Each approval and unpublishing pushes the product's rating into Catalog's listing (`ListingFacts::ratings`, catalog.md amendment 25), in the same transaction; the store's switch reaches Catalog on Platform's `SettingChanged` (`Review/RatingsSwitch`, read under the store's lock, a failure reported; `Infrastructure/Listener/TellCatalogOfRatingsSwitch`), and the repair `feedback:ratings:repush` (`Command/RepushRatings`, the system's reserved `feedback.ratings.repush`) tells it all again; Catalog shows the stars on cards and the weighted rating sort | below |
| F4 — product questions | The questions table; `AskQuestion` (a signed-in customer, in an open store that sells the product, at most five waiting in all under a lock on the customer — answer 16); `AnswerQuestion`, `CorrectAnswer` (text only, who and when kept — answer 20), `RejectQuestion`, `UnpublishQuestion` (answer 18) by the staff of the store asked in, audited `feedback.question.answered` / `…answer_changed` / `…rejected` / `…unpublished`; Catalog's usage check counts questions | below |

## What is here

- **`Infrastructure/FeedbackServiceProvider`** — loads the migrations and the words, declares the
  permissions through Access and the setting through Platform.
- **`Application/FeedbackPermissions`**, **`Application/FeedbackSettings`**.
- **`Infrastructure/Persistence/FeedbackSchema`** and its migration — the `feedback` schema, on the
  connection's search path so a fresh migration wipes it.
- **`Domain/Exception`** — the errors of spec §7, each with its type (`feedback.{snake}`), its status,
  and a title and a message in Arabic and English at `feedback::errors.{snake}`.
- **`Presentation/lang/{en,ar}`** — the permissions' names, the setting's name, the errors' words.
- **Questions**: **`Command/AskQuestion`** (`feedback.question.ask`, a signed-in customer): the text
  read by `FeedbackText` (1 to 1,000), the product sold in the open store, then under an advisory lock
  on the customer at most five waiting in all (`TooManyWaitingQuestions`, answer 16).
  **`Command/{AnswerQuestion,CorrectAnswer,RejectQuestion,UnpublishQuestion}`**
  (`feedback.question.answer`) through `StaffChange` (the store asked in — another store's question is
  `QuestionNotFound`): the row locked, the move its state allows or `InvalidModeration`; a correction
  changes the text only — who answered and when kept, the corrector in the audit (answer 20); audited
  `feedback.question.answered` / `…answer_changed` / `…rejected` / `…unpublished`. SQL in
  `Infrastructure/Eloquent/DatabaseQuestions`. A product Catalog deletes between the check and the
  write is answered as `product` (`Application/RemovedMeanwhile`), never a database error.
- **Reviews**: **`Command/WriteReview`** (`feedback.review.write`, a signed-in customer): the text read
  by the domain's **`FeedbackText`** (trimmed, line breaks kept, Arabic digits as 0-9, length in
  characters); the product sold in the open store (Catalog's `productsSoldIn`); then under
  **`Review/ReviewLock`** — an advisory lock on (customer, product), Feedback having no row of its own
  — one review per product for ever (`AlreadyReviewed`, the unique key the backstop), the verified
  purchase from `SalesApi::deliveredPurchase` (`NotAVerifiedPurchase`), its variant checked to be the
  product's, "Returned" at once if any came back. **`Command/{Approve,Reject,Unpublish}Review`**
  (`feedback.review.moderate`) through **`StaffChange`** (the review's store covered — else
  `ReviewNotFound` — by a staff member, an off store for Super Admins) and **`Review/Moderation`**: the
  row locked, the move its state allows or `InvalidModeration`, a reason only with a refusal, the
  product's rating (`feedback.product_ratings`) kept in the same transaction, audited
  `feedback.review.approved` / `…rejected` / `…unpublished`. **`Review/ReturnedReviews`**, on Sales's
  `ReturnCompleted` (`Infrastructure/Listener/FlipReturnedReviews`, queued): each product the return
  took back, under the same lock, its review flipped to "Returned". **`Infrastructure/Eloquent/
  FeedbackCatalogUsage`**: Catalog refuses to delete a product or variant a review names, or a product a question is about.

Tests: `tests/Modules/Feedback/Integration` — `FeedbackPermissionsTest`, `FeedbackSchemaTest`,
`FeedbackMenuSectionTest`, `FeedbackErrorMessagesTest`, `FeedbackSettingsTest`, `ReviewsTest`, `ReviewTablesTest`, `ReviewRaceTest`, `RatingsPushTest` (with Catalog's `CatalogRatingsTest`), `QuestionsTest`, `QuestionTablesTest`;
Sales is faked by `tests/Modules/Feedback/Support/FakeSales` until it is built.
