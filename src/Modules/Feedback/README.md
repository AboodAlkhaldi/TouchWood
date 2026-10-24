# Feedback

Reviews and ratings, product questions and answers, and the wishlist: what customers write and save
about products, moderated and answered by staff. The specification is
[`docs/modules/feedback.md`](../../../docs/modules/feedback.md) (accepted 2026-10-10, the owner's answers
of 2026-10-19 in §9.1b).

The backend was built first; the screens follow (feedback.md §4.1) - the panel's and the shop's below.

## Panel screens (feedback.md §4.1 F1–F4, F9)

| Screen | Page | Server side |
|---|---|---|
| Support › Reviews (F1) | `resources/js/pages/Feedback/Admin/Reviews.tsx` | `Presentation/Http/Controller/SupportController` (`reviews`, the decisions, `bulkReviews`) |
| Support › Product Questions (F2) | `…/Admin/Questions.tsx` | `SupportController` (`questions`, answer / correct / reject / unpublish, `bulkQuestions`) |
| Customers › Favourited Products (F3) | `…/Admin/Favourites.tsx` | `SupportController::favourites` |
| A customer's wishlist on the staff customer page (F4) | `resources/js/sections/customer/feedback-wishlist.tsx` | `Presentation/Http/Resource/WishlistCustomerSection` |
| Support › Rejection Reasons (F9) | `…/Admin/Reasons.tsx` | `Presentation/Http/Controller/ReasonsController` |

Shared parts: `resources/js/components/feedback/parts.tsx` (product cell, stars, author, the language box, the
decision dialog with the ready reasons) and `bulk.tsx` (ticking many, the bar over the list). What a reader may
do comes from `Application/Query/FeedbackActions` - the screens never ask the Authorizer. Tests:
`tests/Modules/Feedback/Feature/SupportScreensTest` (each page's props, decisions, refusals, query counts) and
`tests/Browser/FeedbackScreensTest`.

## Shop screens (feedback.md §4.1 F5–F8)

| Screen | Drawing | Server side |
|---|---|---|
| The product page's rating, Save heart, reviews (breakdown bars as filters) and questions (F5) | `resources/js/components/feedback/shop.tsx`, placed by `Catalog/Storefront/Product.tsx` (catalog.md amendment 33) | `Presentation/Storefront/*Section` (the data), words `feedback::shop` |
| Write a Review, Ask a Question (F6) | the dialogs in `shop.tsx` | `Presentation/Http/Controller/ShopFeedbackController` (`review`, `ask`) |
| The Save heart; a guest sent to sign in and back with it saved (Q18 (a)) | `SaveHeart` in `shop.tsx` | `ShopFeedbackController` (`save`, `remove`, `saveAfterSignIn` - a signed address; access.md amendment 75) |
| Stars on cards, Best Rated (F7) | `resources/js/components/shop/ProductCards.tsx`, `Catalog/Storefront/Category.tsx` | Catalog's (amendment 25) |
| My Reviews, My Questions, My Wishlist (F8) | `resources/js/pages/Feedback/Storefront/My*.tsx` | `MyFeedbackController`, `Presentation/Http/Resource/AccountPages` |

Routes: `Presentation/shop-routes.php`. Tests: `tests/Modules/Feedback/Feature/ShopScreensTest` and `tests/Browser/FeedbackShopTest`.
The Save heart on every card (§4.2 B5, FB-c) comes after the backend's part of it.

## Build steps

| Step | What | Where |
|---|---|---|
| F0 — Sales's contract | `SalesApi` (`deliveredPurchase`, `returnedProducts`) and `ReturnCompleted`, declared in Sales before Sales is built, answered by a placeholder that knows no purchase | `src/Modules/Sales/Public`, sales.md §2.1 |
| F1 — foundation | The `feedback` schema; the eight permissions of spec §3 (every customer's four; reviews and questions under Access's new **Support** group, access.md amendment 73; wishlists under Customers; the admin-only ratings switch); Platform's `support` menu area (platform.md §9.4); the store setting `feedback.ratings.in_lists` (on by default); the errors of §7 in both languages | below |
| F2 — reviews | The reviews and product ratings tables; writing a review (verified purchase through `SalesApi`, a store that sells the product — Catalog's `productsSoldIn`, catalog.md amendment 24 — once per product for ever); approving, rejecting, unpublishing (the writing store's staff, audited, the rating kept in the same transaction); `ReturnCompleted` flipping marks; Catalog's usage check for reviewed products and variants | below |
| F3 — ratings in the shop's lists | Each approval and unpublishing pushes the product's rating into Catalog's listing (`ListingFacts::ratings`, catalog.md amendment 25), in the same transaction; the store's switch reaches Catalog on Platform's `SettingChanged` (`Review/RatingsSwitch`, read under the store's lock, a failure reported; `Infrastructure/Listener/TellCatalogOfRatingsSwitch`), and the repair `feedback:ratings:repush` (`Command/RepushRatings`, the system's reserved `feedback.ratings.repush`) tells it all again; Catalog shows the stars on cards and the weighted rating sort | below |
| F4 — product questions | The questions table; `AskQuestion` (a signed-in customer, in an open store that sells the product, at most five waiting in all under a lock on the customer — answer 16); `AnswerQuestion`, `CorrectAnswer` (the text — and the question's language, §2.4 B3 — who and when kept; answer 20), `RejectQuestion`, `UnpublishQuestion` (answer 18) by the staff of the store asked in, audited `feedback.question.answered` / `…answer_changed` / `…rejected` / `…unpublished`; Catalog's usage check counts questions | below |
| F5 — the wishlist | The wishlist table (product CASCADE — a deleted product leaves the lists); `SaveToWishlist` (a signed-in customer, in an open store that sells the product, once per store, at most 500 items in all under a lock on the customer); `RemoveFromWishlist` (any item, whatever became of it; nothing when not there); an anonymized account's list deleted (`ClearAnonymizedWishlist`, queued) | below |
| F6a — account and staff reads | `ViewMyReviews`, `ViewMyQuestions`, `ViewMyWishlist` (`feedback.own.view`); `ListReviews`, `ListQuestions` (the store's queues); `ViewCustomerWishlist`, `ListFavourites` (`feedback.wishlist.view`); Catalog amendment 26(a) `productCards` | below |
| F6b — the shop's product page | Catalog amendment 26(b) `ShopProductSections`; Feedback's `feedback.reviews` (rating, ten approved reviews, `canWrite`), `feedback.questions` (ten answered questions signed by the store, `canAsk`), `feedback.wishlist` (`saved`, none for a guest) — one statement for all three | below |

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
  changes the text and, if staff set it, the question's language (§2.4 B3) — who answered and when kept, the corrector in the audit (answer 20); audited
  `feedback.question.answered` / `…answer_changed` / `…rejected` / `…unpublished`. SQL in
  `Infrastructure/Eloquent/DatabaseQuestions`. A product Catalog deletes between the check and the
  write is answered as `product` (`Application/RemovedMeanwhile`), never a database error.
- **Wishlist**: **`Command/SaveToWishlist`** (`feedback.wishlist.manage`, a signed-in customer): the
  product sold in the open store, then under an advisory lock on the customer — saving it again is the
  one item, unchanged; a 501st is `WishlistFull`. **`Command/RemoveFromWishlist`**: any item, an off
  store's or an archived product's too; nothing when not there. SQL in
  `Infrastructure/Eloquent/DatabaseWishlist`. **`Infrastructure/Listener/ClearAnonymizedWishlist`**
  (queued, five tries) deletes an anonymized account's list in every store (§1.5), under the save's
  lock — a save under way finishes first and goes with the rest. Left open: a save authorized just
  before the anonymization that reaches the lock only after the listener ran keeps its one item; the
  account can never sign in again, so nobody sees it.
- **Reads** (`Application/Query`): every page's products, variants, authors and staff read in bulk by
  **`FeedbackReader`** — products through Catalog's `productCards` (amendment 26(a)), each in the store
  it belongs to, photos through Platform in one read; authors in their public form only (`AuthorName`:
  "Sara A.", or deleted). **`WishlistGroups`** groups a list by store, unavailable judged there (answer
  17). SQL in `Infrastructure/Eloquent/DatabaseFeedbackQueries`: keyset pages after the last id;
  queues' waiting items oldest first. Customers' own reads through `ReadInputs::customer`; staff lists
  authorize the store, then `ReadInputs::staffIn` (an off store only for store switchers).
- **The shop's product page** (catalog.md amendment 26(b)): **`Presentation/Storefront/{Reviews,Questions,Wishlist}Section`**
  registered with Catalog's `ShopProductSections`, drawn from **`Application/Shop/ShopFeedback`** —
  scoped to the request, it reads all three at once (`Infrastructure/Eloquent/DatabaseShopFeedbackQueries`,
  one statement: the rating, ten approved reviews newest first, ten answered questions newest answer
  first, saved here, reviewed), then the authors and sizes in bulk. Shapes `Presentation/Http/Resource/Shop*Data`
  (TypeScript). "Show more": `after[feedback.reviews]` / `after[feedback.questions]` on the same page.
  The reviews' star breakdown (`stars`, 5 to 1, in the same statement) and the one-star filter
  `filter[feedback.reviews]=1..5` (§2.4 B1; Catalog amendment 31).
- **Reviews**: **`Command/WriteReview`** (`feedback.review.write`, a signed-in customer): the text read
  by the domain's **`FeedbackText`** (trimmed, line breaks kept, Arabic digits as 0-9, length in
  characters; its language from its first letter, `FeedbackText::languageOf`, §2.4 B3); the product sold in the open store (Catalog's `productsSoldIn`); then under
  **`Review/ReviewLock`** — an advisory lock on (customer, product), Feedback having no row of its own
  — one review per product for ever (`AlreadyReviewed`, the unique key the backstop), the verified
  purchase from `SalesApi::deliveredPurchase` (`NotAVerifiedPurchase`), its variant checked to be the
  product's, "Returned" at once if any came back. **Staff set a text's language** (§2.4 B3) with `ApproveReview`, `AnswerQuestion` and `CorrectAnswer`'s optional
  `locale`, in the same audit entry. **`Command/{Approve,Reject,Unpublish}Review`**
  (`feedback.review.moderate`) through **`StaffChange`** (the review's store covered — else
  `ReviewNotFound` — by a staff member, an off store for Super Admins) and **`Review/Moderation`**: the
  row locked, the move its state allows or `InvalidModeration`, a reason only with a refusal, the
  product's rating (`feedback.product_ratings`) kept in the same transaction, audited
  `feedback.review.approved` / `…rejected` / `…unpublished`. **`Review/ReturnedReviews`**, on Sales's
  `ReturnCompleted` (`Infrastructure/Listener/FlipReturnedReviews`, queued): each product the return
  took back, under the same lock, its review flipped to "Returned". **`Infrastructure/Eloquent/
  FeedbackCatalogUsage`**: Catalog refuses to delete a product or variant a review names, or a product a question is about.

- **For the screens** (feedback.md §2.4): `Command/BulkDecideReviews`, `Command/BulkDecideQuestions` (B2 — each id
  through the one-item command, `Moderation/BulkDecision` naming done, already decided, not found); the ready
  reasons (B4) — `Command/{Add,Change,Remove}RejectReason`, `Command/OrderRejectReasons` through
  `Reason/ReasonsChange` (`feedback.settings.update` in All stores, the list's lock), `Query/ListRejectReasons`,
  `Domain/Repository/RejectReasons` with SQL in `Infrastructure/Eloquent/DatabaseRejectReasons`; the Save heart on
  every card (B5) — `Presentation/Storefront/SavedCardFact`, registered as `feedback.saved` with Catalog's `ShopCardFacts`.

Tests: `tests/Modules/Feedback/Integration` — `FeedbackPermissionsTest`, `FeedbackSchemaTest`,
`FeedbackMenuSectionTest`, `FeedbackErrorMessagesTest`, `FeedbackSettingsTest`, `ReviewsTest`, `ReviewTablesTest`, `ReviewRaceTest`, `RatingsPushTest` (with Catalog's `CatalogRatingsTest`), `QuestionsTest`, `QuestionTablesTest`, `QuestionRaceTest`, `BulkDecisionsTest`, `RejectReasonsTest`, `WishlistTest`, `WishlistTablesTest`, `WishlistRaceTest`, `FeedbackReadsTest`;
`tests/Modules/Feedback/Feature/ShopSectionsTest` (the product page, with Catalog's `ShopProductSectionsTest` and `ShopPagesTest`), `SavedHeartsTest` (the hearts on cards: a category page, its Show More, the search results, a product page's related cards); `tests/Modules/Feedback/Unit/RemovedMeanwhileTest`, `FeedbackLanguageTest`;
Sales is faked by `tests/Modules/Feedback/Support/FakeSales` until it is built.
