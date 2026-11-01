# Ops

Ops tells people things and counts things: every message the system sends, to customers and to staff, in
the person's language; the inbox behind the shop's bell and the panel's bell; offer notices and staff
messages; the newsletter's subscribers, copied to Brevo; the reports in Excel and the daily report in PDF.
**The agreed rules are [`docs/modules/ops.md`](../../../docs/modules/ops.md)**; this file says how the module
is built.

Backend only: the screens are the screens agent's (owner, 2026-10-28), from the data shapes of ops.md §4.
Nothing depends on Ops (handoff §4.4): it listens to the other modules' events, reads their `Public`
surfaces, and offers nothing callable. Its `Public` holds two enums, `MessageKind` and `MessageChannel`. The
one contract it fulfils is Access's: `SecurityMessages` and `PhoneCountries`, bound in its provider, so
Access never depends on Ops.

## Built

The module was built in the ten steps of its execution plan. Steps 1 and 4 land in Access.

| Step | What | Where |
|---|---|---|
| 1 — Access, part A | Marketing consent (the fields, the registration box, the account switch, `withdrawMarketingConsent`, `MarketingConsentChanged`, `CustomerLocaleChanged`) and `customersRegistered`: access.md amendments 79, 83 | `src/Modules/Access` |
| 2 — the foundation | The `ops` schema; `inbox_entries`, `sent_messages`, `handled_events`; the permissions; the email and SMS adapters; the message kinds and their wording in both languages; the two bells; the nightly removal | below |
| 3 — security messages | C18 in place of Access's temporary sender, sent at once, never queued; the SMS countries (access.md amendment 90) | `Infrastructure/Messages/OpsSecurityMessages`, `Application/Command/*SmsCountry*`, `Application/Query/ListSmsCountries` |
| 4 — Access, part B | `staffToAlert`, the critical-SMS switch, the Returns and Payments to check topics, `staffStores`: access.md amendments 80, 81, 82, 84, 88 | `src/Modules/Access` |
| 5, 6 — messages on events | C1–C8b, C9, C9b, C10, C12–C17b; S1, S2, S3, S4, S9; the critical SMS path, with no listener yet; the morning reminder C14 | `Infrastructure/Listener`, `Application/Alerts`, `Infrastructure/Delivery/ExpiringPointsReminder` |
| 7 — notices, messages, newsletter | Offer notices (every customer of a store, or one of its segments), staff messages (one customer, or every customer of a store), guests' double opt-in, Brevo's lists and webhook, the unsubscribe links | `Application/Command/*Notice*`, `SendStaffMessage`, `Application/Newsletter`, `Application/Delivery`, `Infrastructure/Delivery/BroadcastSender`, `Infrastructure/External/Brevo*` |
| 8 — reports | Revenue, Product sales, Stock, Searches, Favourites: a JSON preview and an Excel file | `Application/Reports`, `Application/Query/{ViewReport,DownloadReport}`, `Infrastructure/Excel` |
| 9 — the daily report | The PDF, every file kept twelve months, a past day, the email at the time the person chose | `Application/DailyReport`, `Application/Command/{MakeDailyReport,SetDailyReportEmail,DeliverDailyReport,SendDailyReportEmail}`, `Infrastructure/Pdf`, `Infrastructure/Files` |
| 10 — the module pass | This file, ops.md §4's data shapes, §5, §7 and §8 brought in line with the code; then the whole-module review's fixes and the reads the screens need (owner, 2026-11-01): the stop-email pages of an off store, 00:00 refused, the SMS countries' routes, a staff message to a segment, S5, C4's Track, a retried daily email, the staff messages list and each form's choices | here |

## What is here

- **`Public/Enums`** — `MessageKind`, every kind of message with the row of the spec it is (`code()`) and the
  channels the spec fixes for it (`channels()`); `MessageChannel` (`BELL`, `EMAIL`, `SMS`).
- **`Domain/Model`** — what Ops keeps: `InboxEntry`, `SentMessage`, `Notice`, `StaffMessage`, `Subscriber`,
  `DailyReportFile`, `DailyReportEmail`, `SmsCountry`; the states `BroadcastStatus` and `SendStatus`.
  **`Domain/ValueObject`** — `InboxOwner`, whose bell it is; `WrittenText`, what staff write (a title of 200
  characters, a text of 500, both languages, an optional page of the shop). **`Domain/Repository`** — one
  interface per table, SQL in `Infrastructure/Eloquent`. **`Domain/Exception`** — the errors of ops.md §7, each
  with its type (`ops.{snake}`), its status, and a title and a message in both languages at
  `ops::errors.{snake}`.
- **`Application/OpsPermissions`** — the six jobs a role may hold, per store, under Messages and Reports,
  `ops.message.send_many` for admins only; each person's own bell, held automatically; the nightly removal and
  the SMS countries, reserved.
- **`Application/Alerts`** — a message on an event: `MessageWriter` writes it in one language from what the
  owning module says now, or nothing when it no longer stands; `CustomerMessages` and `StaffAlerts` make the
  bell entry and queue the email; `EventSender` sends it when its job runs.
- **`Application/Delivery`** — what notices and staff messages share: `Writers` (who writes, which store, a
  time in the store's zone, whether the audience is empty), `WrittenAudit`, `Broadcast`, `BroadcastQueue`.
- **`Application/Inbox`** — the bells' reads: `InboxReaders`, whose bell and over which stores.
- **`Application/Messages`** — the adapters' interfaces (`EmailSender`, `SmsSender`, `SmsSenders`,
  `NewsletterList`) and what passes through them; `MarketingReach`, who an email may reach; `WrittenEmails`, the
  emails of notices, staff messages and the newsletter's confirmation; `ListedPhoneCountries`, Access's
  `PhoneCountries`.
- **`Application/Newsletter`** — `NewsletterSignUps` (the box and its links), `CustomerSubscriptions`
  (customers on the lists, from Access's events; a contact deleted when a deletion is pending, with or without a
  row), `NewsletterSync` (the provider kept as the rows say).
- **`Application/Reports`** — `ReportReaders` (who reads which store), `ReportBuilder`, `ReportPeriod` (the days,
  and the moment asked), `RevenueSheets` and `ListSheets` (each walking its module's paged read),
  `ColumnType`, `ReportMoney`, `ReportWords`.
- **`Application/DailyReport`** — `DailyReportReader` (every figure from its owning module's read),
  `DailyReportMaker`, `DailyReportPeople` (who it is for, in a request and in the queued email).
- **`Application/Command`, `Application/Query`** — one folder per use case, each authorizing itself.
- **`Infrastructure/OpsServiceProvider`** — the bindings, the permissions, the listeners, the schedule, the
  routes; the unsubscribe links' POST is let through without a CSRF token, its signature being its authority.
- **`Infrastructure/Listener`** — `TellCustomerAboutOrder`, `…Return`, `…Money`, `…Points`, `…Feedback`,
  `TellCustomersAboutCoupon`, `AlertStaff`, `RefreshNewsletterSubscription`, `ForgetAnonymizedCustomer`: each on
  the queue, once per event through `Infrastructure/Eloquent/HandledEvents`.
- **`Infrastructure/External`** — `BrevoEmailSender`, `BrevoNewsletterList`, the `Log*` adapters for development
  and tests (refused in production), `BuiltSmsSenders`, `DailyReportEmailMailer`. **`Infrastructure/Delivery`** —
  `BroadcastSender`, batches of 500, safe to run again; `ExpiringPointsReminder`. **`Infrastructure/Queue`** — the
  jobs. **`Infrastructure/Excel`** — `OpenSpoutReportWriter`. **`Infrastructure/Pdf`** —
  `MpdfDailyReportRenderer`. **`Infrastructure/Files`** — `LocalReportFiles`, the app's private files.
- **`Infrastructure/Persistence`** — `OpsSchema` and the migrations of ops.md §5, every CHECK the last line
  behind a rule the code refuses first.
- **`Presentation`** — `admin-routes.php`, `shop-routes.php`, `webhook-routes.php`; the controllers, JSON for the
  screens agent's pages; `Http/Middleware/StopEmailStore`, the store and language of the stop-email pages, on or
  off; `Http/Resource`, the `…Data` classes (`#[TypeScript]`) and the three classes that fill
  them (`BellPages`, `WrittenPages`, `DailyReportPages`); `lang/{ar,en}`; `views` (the three mail frames, the
  daily report's PDF and its email).

### The kinds of messages

`MessageKind` holds every row of ops.md §1.5 and §1.6, and three that are neither: `offer_notice`,
`staff_message`, `newsletter_confirmation`. Wording is at `ops::messages.{kind}`, security messages at
`ops::security`, each in Arabic and English with the same placeholders (`MessageKindsTest`, `SecurityMessagesTest`).

- **To customers:** bell and email, in their language, on the queue, consent not asked; an anonymized account
  gets nothing, a switched-off store still sends. C18 is the exception: no bell, codes by SMS and links by email,
  sent at once.
- **To staff:** one read, `AccessApi::staffToAlert`, gives every active holder of the topic's permission in the
  store with their switches; the bell by the in-panel switch, the email by the email switch.
- **Critical SMS:** only for the gateway key at 1 day or refused, to super admins with the switch on and a phone,
  checked again when it leaves. `AlertStaff` calls it on `GatewayKeyExpiring`; the refused key's event is not in main.
- **Each email and SMS** has its `ops.sent_messages` row written QUEUED with the listener's work; the job calls
  the provider outside any transaction and marks it SENT, or FAILED after the last attempt. At least once: a
  provider that took a message just before its row was marked may get it again.

### Bells

`InboxReaders` decides whose bell it is. A customer reads their own entries in the store being shopped; a staff
member reads their own over `AccessApi::staffStores`, every store assigned to them, on or off, a Super Admin
every store. Twenty entries a page, newest first, with the unread number. Marking is not audited. Entries go
after twelve months (`PruneOpsLog`, 04:30 Riyadh).

### Notices and staff messages

A notice waits (`SCHEDULED`) or sends at once; `StartDueNoticesJob` starts the due ones every minute. It can be
changed or cancelled only while it waits, the row locked, so a change and the minute never both win. A staff
message always sends at once and is never recalled; to one customer, in their home store alone, so the entry lands
in a bell they see. A notice or a message to many goes to every customer of the store or the members of one of its
segments. Both send through `BroadcastSender`, one transaction per
batch with its cursor on the row, so a batch run twice sends nothing twice; a batch that fails its last attempt
shows `FAILED`, and retrying it from the failed jobs carries on. Each email has its `ops.sent_messages` row written
QUEUED with its batch and calls the provider outside any transaction, as an event's does. Bell entries reach everyone in the audience;
marketing email only those `MarketingReach` allows; a message to one customer is a service message. Writing,
changing and cancelling a notice, and sending a message, are audited in the same transaction. Sent, cancelled and
failed ones go after twelve months (`PruneWrittenMessages`, 04:40 Riyadh).

### Newsletter

One row per email per store in `ops.subscribers`: a guest who gave an email in the shop's box, on the list once
confirmed; or a customer who may receive offers, on their home store's list only. The confirmation email is sent
at once, never queued, its token kept only as a hash. `NewsletterSync` copies each row to one Brevo list per store
and language, from the queue. An unsubscribe comes three ways: a marketing email's signed link, a guest's signed
link from the confirmation email, and Brevo's webhook (`BrevoWebhookController`, authenticated by the
`X-TouchWood-Secret` header). A customer's consent is withdrawn through Access by `WithdrawMarketingConsentJob`,
because only the system may: run within the request for a person's link, so it is off when the page answers; on the
queue for the webhook. The two signed links work for a switched-off store too (`StopEmailStore`, outside the
shop's `store` middleware), and the signature comes first, so a bad one tells nothing about the store. The removal
job is encrypted: it alone carries an address. Unconfirmed guests go after seven days (`PruneWrittenMessages`, 04:40 Riyadh).

### Reports

Five kinds (`ReportKind`), each previewed as JSON (the first 50 rows of each sheet) and downloaded as Excel, the
rows read as the file is written. One moment for the whole request: a range that includes today ends there, and
says so. Revenue, Product sales and By category end with one total per currency, never converted. Days from 2020
to next year, at most five years, twelve months for searches. Under `ops.report.view` in the store, Stock also
under `inventory.stock.manage`; every store's report a super admin's alone.

### Daily report

`DailyReportMaker` reads every figure from its owning module, writes the PDF in the person's language, and keeps
the file and its row together, so a report opens exactly as it was made. The email is the same report at the time
the person chose, one setting per person per store or one for all stores: a delayed job queued by the person who
switched it on, then by itself a day at a time, so it reads only what they may see; `version` and `next_due_at`
stop a second email from ever starting. Any time but 00:00. A job retried from the failed jobs makes that moment's
report, once: `email_setting_id` and `email_due_at` keep one per setting and moment, with its delivery in the same
transaction. Files and rows go after twelve months (`PruneDailyReports`, 04:50 Riyadh).

### SMS countries

`ops.sms_countries` lists the countries whose phones SMS reaches, each with a calling code and a provider,
starting with Saudi Arabia 966 and Egypt 20 on `log`. `ListedPhoneCountries` reads a number's country by its
calling code, for Access and for Ops' own SMS; a phone of an unlisted country is refused and no code goes to it.
A calling code overlapping another listed one is refused. Reserved to Super Admins (`ops.sms_countries.manage`),
audited (`ops.sms_country.*`). `log` is the only provider built, and it is refused on a live system when an SMS
would go through it. Its routes are `ops.admin.sms-countries*` (ops.md §4.1); no screen yet.

## Waiting

Each checked against the code on 2026-11-01.

| What | State here | On whom |
|---|---|---|
| C11 Pay the remaining amount | The kind and its words exist; `MessageWriter` has no branch for it, no listener | No `PaymentRequested` event exists: Stage 7 (Payments step 11) |
| C1's "paid" line | The words exist (`ops::messages.order_taken.optional.paid`); nothing fills them | Online checkout: Stage 7 |
| C10 and a checkout's one email | Ops has no check of its own (§1.5): `PaymentRecorded` must not be fired for the payment a checkout's order is created with | Stage 7, where it fires the event |
| S5b The key refused | The words, the writer's branch and the critical SMS path exist; no listener | No `GatewayKeyRejected` event exists: Stage 7 |
| S6 Payments to check: a transfer overdue | The kind and its words exist; no writer's branch, no listener | No `TransferOverdue` event exists: Stage 7 (Sales' scheduled job) |
| S7 Payments to check: a payment not accepted | The kind and its words exist; no writer's branch, no listener | No `PaymentNotAccepted` event exists: Stage 7 |
| "Transfers overdue" in the daily report | The cell prints "Waits for Payments (stage 7)" (`views/daily-report.blade.php`); `SalesReports::transfersWaiting` gives orders and owed only | Stage 7 |
| S8 Campaign expiry | The kind and its words exist; no writer's branch, no listener | Content: `src/Modules/Content` holds only empty folders |
| A notice sent when a campaign goes live (§1.7) | Not built | Content |
| A segment's name, and the list a form picks one from | `PromotionsApi` gives only `segmentMembers`, and Ops may call only `Public` | Promotions: a read of a store's segments (ops.md §4.13) |
| A notice's audience size | No public read counts a store's customers or a segment's members | Access, Promotions (ops.md §4.13) |
| The SMS provider | `log` only | The owner (Mobily being checked) |
| Brevo on the live system | The adapters are built, and production refuses to boot on either `log` driver; `OPS_EMAIL_DRIVER`, `OPS_NEWSLETTER_DRIVER`, `BREVO_API_KEY`, `BREVO_NEWSLETTER_FOLDER_ID`, `BREVO_WEBHOOK_SECRET` are the server's; the webhook is made in Brevo, unbatched, with the secret as its custom header | The owner, at hosting |
| The words' full review | The reports' and unsubscribe pages' words are marked approved for now, the full review at the end (owner, 2026-10-31) | The owner |
| Every screen | No page, menu entry or Home card exists (ops.md §4.13) | The screens agent |

## Tests

`tests/Modules/Ops/Integration` — `EventMessagesTest` (§1.5, §1.6, the sent-then-marked row, C14, the critical
SMS), `MessageKindsTest`, `SecurityMessagesTest`, `SendersTest`, `SmsCountriesTest`, `RealEmailDriverTest`,
`BellsTest`, `NoticesTest`, `NoticeSegmentTest`, `StaffMessagesTest`, `NewsletterTest`, `BrevoNewsletterListTest`,
`ReportsTest`, `DailyReportTest`, `DailyReportEmailTest`, `PruneTest`, `OpsPermissionsTest`, `OpsSchemaTest`,
`OpsTablesTest`. `tests/Modules/Ops/Feature` over HTTP — `BellRoutesTest`, `WrittenRoutesTest`, `ReportRoutesTest`,
`DailyReportRoutesTest`, `StopEmailPagesTest`, `SmsCountryRoutesTest`, `ScreensReadsTest`. The consent itself is Access's: `tests/Modules/Access/Integration/MarketingConsentTest`,
`tests/Modules/Access/Feature/CustomerAccountPageTest`. ops.md §8 maps each scenario to its test and says what no
test covers.
