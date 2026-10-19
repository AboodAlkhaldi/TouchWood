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

## What is here

- **`Infrastructure/FeedbackServiceProvider`** — loads the migrations and the words, declares the
  permissions through Access and the setting through Platform.
- **`Application/FeedbackPermissions`**, **`Application/FeedbackSettings`**.
- **`Infrastructure/Persistence/FeedbackSchema`** and its migration — the `feedback` schema, on the
  connection's search path so a fresh migration wipes it.
- **`Domain/Exception`** — the errors of spec §7, each with its type (`feedback.{snake}`), its status,
  and a title and a message in Arabic and English at `feedback::errors.{snake}`.
- **`Presentation/lang/{en,ar}`** — the permissions' names, the setting's name, the errors' words.

Tests: `tests/Modules/Feedback/Integration` — `FeedbackPermissionsTest`, `FeedbackSchemaTest`,
`FeedbackMenuSectionTest`, `FeedbackErrorMessagesTest`, `FeedbackSettingsTest`.
