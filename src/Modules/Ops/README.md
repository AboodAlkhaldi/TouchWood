# Ops

Ops tells people things and counts things: every message the system sends, to customers and to staff, in
the person's language; the inbox behind the shop's bell and the panel's bell; offer notices and staff
messages; the newsletter's subscribers, copied to Brevo; the reports in Excel and the daily report in PDF.
**The agreed rules are [`docs/modules/ops.md`](../../../docs/modules/ops.md)**; this file says how the module
is built.

The backend is built. The screens were left to the screens agent, from the data shapes of ops.md §4 (owner, 2026-10-28), and are
being built one job at a time: the Waiting table and "Continuing the screens" below say which are in.
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
| 5, 6 — messages on events | C1–C8b, C9, C9b, C10, C12–C17b; S1, S2, S3, S4, S9; the critical SMS path, its listeners, S5 and S5b, in step 10; the morning reminder C14 | `Infrastructure/Listener`, `Application/Alerts`, `Infrastructure/Delivery/ExpiringPointsReminder` |
| 7 — notices, messages, newsletter | Offer notices (every customer of a store, or one of its segments), staff messages (one customer, or every customer of a store), guests' double opt-in, Brevo's lists and webhook, the unsubscribe links | `Application/Command/*Notice*`, `SendStaffMessage`, `Application/Newsletter`, `Application/Delivery`, `Infrastructure/Delivery/BroadcastSender`, `Infrastructure/External/Brevo*` |
| 8 — reports | Revenue, Product sales, Stock, Searches, Favourites: a JSON preview and an Excel file | `Application/Reports`, `Application/Query/{ViewReport,DownloadReport}`, `Infrastructure/Excel` |
| 9 — the daily report | The PDF, every file kept twelve months, a past day, the email at the time the person chose | `Application/DailyReport`, `Application/Command/{MakeDailyReport,SetDailyReportEmail,DeliverDailyReport,SendDailyReportEmail}`, `Infrastructure/Pdf`, `Infrastructure/Files` |
| 10 — the module pass | This file, ops.md §4's data shapes, §5, §7 and §8 brought in line with the code; then the whole-module review's fixes and the reads the screens need (owner, 2026-11-01): the stop-email pages of an off store, 00:00 refused, the SMS countries' routes, a staff message to a segment, S5, C4's Track, a retried daily email, the staff messages list and each form's choices | here |
| After the pass | S10, S10b, the bell alerts that a security email or SMS could not be sent; a form's segments, and the segment's name on what was written (owner, 2026-11-01) | `Infrastructure/Messages/SecurityFailureAlert`, `Application/Query/ListSegments` |
| Stage 7's last events | C11 on `PaymentRequested`, S6 on `TransferOverdue`, C10 checked on a request's online payment, transfers overdue in the daily report and its totals (2026-11-03) | `Infrastructure/Listener/TellCustomerAboutPaymentRequest`, `AlertStaff`, `Application/Alerts/MessageWriter`, `Application/DailyReport/DailyReportReader` |
| After the screens' spec | A whole store's audience, `ops.admin.audience` (ops.md §9.4 Q14), on Access's `customerCountInStore` (access.md amendment 94) | `Application/Query/CountStoreAudience`, `Presentation/Http/Controller/AudienceController` |

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
  and the moment asked), `RevenueSheets` and `ListSheets` (each walking its module's paged read; a total row a `ReportTotal`; each sheet with totals also says how to read them without its rows, for the preview),
  `ColumnType`, `ReportMoney`, `ReportWords`.
- **`Application/DailyReport`** — `DailyReportReader` (every figure from its owning module's read),
  `DailyReportMaker`, `DailyReportPeople` (who it is for, in a request and in the queued email).
- **`Application/Command`, `Application/Query`** — one folder per use case, each authorizing itself.
- **`Infrastructure/OpsServiceProvider`** — the bindings, the permissions, the listeners, the schedule, the
  routes; the unsubscribe links' POST is let through without a CSRF token, its signature being its authority.
- **`Infrastructure/Listener`** — `TellCustomerAboutOrder`, `…Return`, `…Money`, `…PaymentRequest`, `…Points`, `…Feedback`,
  `TellCustomersAboutCoupon`, `AlertStaff`, `StartCampaignNotice`, `RefreshNewsletterSubscription`, `ForgetAnonymizedCustomer`: each on
  the queue, once per event through `Infrastructure/Eloquent/HandledEvents`.
- **`Infrastructure/External`** — `BrevoEmailSender`, `BrevoNewsletterList`, `BrevoAnswers` (the key as pasted, how
  long a call waits, what each refusal means: ops.md §7), the `Log*` adapters for development
  and tests (refused in production), `BuiltSmsSenders`, `DailyReportEmailMailer`. **`Infrastructure/Queue/ProviderFailureRules`** —
  a job's answer to a provider's failure: the failed jobs at once for one address refused, long waits for up to a day when Brevo refuses everybody, Brevo's own wait on a 429, no attempt spent.
  **`Presentation/Console/BrevoCheckCommand`** — `ops:brevo:check`, the real token's read-only check. **`Infrastructure/Delivery`** —
  `BroadcastSender`, batches of 500, safe to run again; `ExpiringPointsReminder`. **`Infrastructure/Queue`** — the
  jobs. **`Infrastructure/Excel`** — `OpenSpoutReportWriter`. **`Infrastructure/Pdf`** —
  `MpdfDailyReportRenderer`. **`Infrastructure/Files`** — `LocalReportFiles`, the app's private files.
- **`Infrastructure/Persistence`** — `OpsSchema` and the migrations of ops.md §5, every CHECK the last line
  behind a rule the code refuses first.
- **`Presentation`** — `admin-routes.php`, `shop-routes.php`, `webhook-routes.php`; the controllers: a built page's list gives the
  page to a plain visit and §4's JSON to whoever asks, a form's address only the page, and every other GET JSON or its download
  (ops.md §4.1); `Http/Middleware/StopEmailStore`, the store and language of the stop-email pages, on or
  off; `Http/Resource`, the `…Data` classes (`#[TypeScript]`) and the three classes that fill
  them (`BellPages`, `WrittenPages`, `DailyReportPages`); `lang/{ar,en}`; `views` (the three mail frames, the
  daily report's PDF and its email).

### The kinds of messages

`MessageKind` holds every row of ops.md §1.5 and §1.6, and three that are neither: `offer_notice`,
`staff_message`, `newsletter_confirmation`. Wording is at `ops::messages.{kind}`, security messages at
`ops::security`, each in Arabic and English with the same placeholders (`MessageKindsTest`, `SecurityMessagesTest`).

- **To customers:** bell and email, in their language, on the queue, consent not asked; an anonymized account
  gets nothing, a switched-off store still sends. C18 is the exception: no bell, codes by SMS and links by email,
  sent at once. One that fails tells the Super Admins, below.
- **A test order:** C1, C6b, C9b, C10 and C11 carry "This is a test order." (`ops::messages.test_mark`) in the bell's line and
  under the email's greeting; the rest have its number alone.
- **A link the person cannot open:** S6 opens the order, under another permission than the one that reaches them. Whoever
  lacks it gets the same words with no link (`AlertLinks`).
- **To staff:** one read, `AccessApi::staffToAlert`, gives every active holder of the topic's permission in the
  store with their switches; the bell by the in-panel switch, the email by the email switch.
- **Critical SMS:** only for the gateway key at 1 day or refused, to super admins with the switch on and a phone,
  checked again when it leaves. `AlertStaff` calls it on `GatewayKeyExpiring` and `GatewayKeyRejected`.
- **S10, S10b:** a security email or SMS that fails puts one bell entry in each active Super Admin's inbox, at most once a clock
  hour per channel, and no switch can stop it (`SecurityFailureAlert`). The bell alone. Written directly, after any transaction the
  caller has open; it waits 250 ms at most on another's claim; a failure here is reported and goes no further.
- **Each email and SMS** has its `ops.sent_messages` row written QUEUED, and its job, with the listener's work; the job calls
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
segments, which each form lists under its own permission (`ListSegments`); what was written shows the segment's name now, null once deleted. Both send through `BroadcastSender`, one transaction per
batch with its cursor on the row, so a batch run twice sends nothing twice; a batch that fails its last attempt
shows `FAILED`, and retrying it from the failed jobs carries on. Each email has its `ops.sent_messages` row and its job
written with its batch, in one transaction, and calls the provider outside any transaction, as an event's does. Bell entries reach everyone in the audience;
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
The screens (ops.md §4.15 O11, O13): the box's words travel in Ops' part of the shop frame (`Presentation/Frame/ShopPart`) for a
guest outside a staff view alone, read from nothing; `StorefrontLayout` loads the box (`components/ops/NewsletterBox`) only once
the page has drawn, so no shop page carries it on first paint. Each link opens `Ops/Storefront/EmailLink` in `EmailLinkLayout`,
which reads no store; `StopEmailStore` shares the address's language again, since nothing else does on those routes.

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
transaction. The delivery is at least once (owner, 2026-11-01). Files and rows go after twelve months (`PruneDailyReports`, 04:50 Riyadh).

### SMS countries

`ops.sms_countries` lists the countries whose phones SMS reaches, each with a calling code and a provider,
starting with Saudi Arabia 966 and Egypt 20 on `log`. `ListedPhoneCountries` reads a number's country by its
calling code, for Access and for Ops' own SMS; a phone of an unlisted country is refused and no code goes to it.
A calling code overlapping another listed one is refused. Reserved to Super Admins (`ops.sms_countries.manage`),
audited (`ops.sms_country.*`). `log` is the only provider built, for development and tests, and it is refused in production when
an SMS would go through it (`BuiltSmsSenders`). Nothing at boot asks for a provider, so a production server with none starts
normally, and every code then fails when it is sent: a FAILED row, an error to the caller, S10b. The owner is deciding whether that
stays (2026-11-05). Its routes are `ops.admin.sms-countries*` (ops.md §4.1), its page `Ops/Admin/SmsCountries` (§4.15 O10).

## Waiting

Each checked against the code on 2026-11-01, the first two on 2026-11-03, C11's again on 2026-11-05.

| What | State here | On whom |
|---|---|---|
| C11 and its link | Built; the bell and the email open `/{store}/{locale}/orders/{number}/pay`, which has no route on main (2026-11-03) and answers 404 until it does. C11 can't fire yet either: `RequestOrderPaymentHandler` has no controller or route until Stage 7's step 12 screens land, so `PaymentRequested` never fires outside the tests. C10 on a request's online payment waits with it (`OpenRequestPayment` and `PayOrderRequest` have no route either) (2026-11-05) | The screens session (sales.md §9.9) |
| S7's and S7b's link | Built; the bell and the email open `/admin/payments/{id}` for whoever holds `payments.payment.view` (`AlertLinks`), which has no route on main (2026-11-05) and answers 404 until it does | The screens session (payments.md §4.2) |
| S8 Campaign expiry | Built on Ops' side (`AlertStaff`, `MessageWriter`); sends nothing until Content is built. Its permission stands in for Content's (ops.md §1.6, §9.5) | Content: to fire `CampaignExpiring` and replace `NoCampaignsYet` |
| A notice sent when a campaign goes live (§1.7) | Built on Ops' side (`StartCampaignNotice`): a waiting notice only. No state makes a notice wait for its campaign (ops.md §9.5) | Content: to fire `CampaignWentLive`; the owner |
| The SMS provider | `log` only | The owner (Mobily being checked) |
| Brevo on the live system | The adapters are built, every failure covered with faked answers (ops.md §7), and in production a web request or a worker refuses to boot on either `log` driver, or without a usable key and `MAIL_FROM_ADDRESS`, and logs loudly without `BREVO_NEWSLETTER_FOLDER_ID` or `BREVO_WEBHOOK_SECRET`, all the server's; other console commands are left alone; the webhook is made in Brevo, unbatched, with the secret as its custom header. Run `php artisan ops:brevo:check` once the key is in, and `php artisan queue:restart` after changing it. Not yet proven against the real Brevo | The owner, at hosting |
| What to do about a lost unsubscribe, a long outage, a refused key on queued mail, a missing folder or secret at boot, staging's `APP_ENV` | ops.md §9.6: left as they are | The owner |
| The words' full review | The reports' and unsubscribe pages' words are marked approved for now, the full review at the end (owner, 2026-10-31) | The owner |
| The screens | Built (ops.md §4.15): SMS Countries, the two bells with their full pages, Customer Messages and Offer Notices with their forms, Subscribers, Reports, Daily Reports, the newsletter box, the consent switch and box, and the confirm, leave and unsubscribe pages. The Home card goes with the Home dashboard job | The screens agent |

## Continuing the screens

For whoever builds the rest of ops.md §4.15. The spec is accepted and every backend read it needs is in main. Read the
page's O-section, and §4.1 and the section of §4 it names, sentence by sentence, before building it.

**The owner's rules.** A question with its effect in plain words before any decision the spec doesn't make. One short branch
and PR per job, squash merge, and the owner decides each merge. An independent review after each job, given the code and the
spec, not your conclusions. No co-author line and no generated-with line in a commit or PR. Geist and shadcn pieces only
(frontend.md §1.11). Latin digits everywhere, both languages, right to left in Arabic. A menu entry lands with its page, never
before, or it opens raw JSON. Before touching `AdminMenu`, `AdminLayout` or `StorefrontLayout`, check with whoever else is working on them.

**How a page is built here.** `SubscribersController` is the smallest whole example.

- The same address answers a page or JSON: `$request->expectsJson()` for a screen's own read, otherwise `Page::render` with the
  page's word files. A GET that answers a page **declares `Inertia\Response` in its return type**, or a signed-out visit isn't
  remembered for after sign-in (#329).
- A change the page sends goes back with its words (`Answers::fromPage`), and anything else keeps §4's JSON. A refusal for one
  box goes on that box (`WrittenRefusals`, `SmsCountriesController::answer`); any other through `App\Http\FormErrors`.
- Each page's words are a file of its own, `ops::admin_*`, in both languages, named in the controller's `WORDS`. Write keys out
  whole: `TranslationKeysTest` can't read `${W}.x`.
- A Show More list uses `useLoadMore`, with Back keeping every row and the place (frontend.md). A list with a row action also uses
  `useKeptDepth`, which asks for the rows below the first page again after a save. `router.post` keeps the page by default, so
  it needs no `preserveState` of its own.
- Nothing reads the server by itself on a clock (Q7, Q15): any request resets a staff session's idle clock.
- O8 is built: `ReportsController::show` answers the page (`Ops/Admin/Reports`) or the JSON; the page reads the preview in its own
  request once the address names choices, the store by its **code** there (`storeOf`) and by its id everywhere else. The kinds
  on offer come from `ReportChoices`, which `ops.admin.choices` shares. `DateRangeField` takes a `timeZone` for store days. The
  menu's `reports` section is in `InMemoryAdminMenu`, Access's `permission_groups` words and `AdminMenuTest`, and Daily Reports
  sits in it too. A browser test of a daily email fakes the queue: a sync queue runs the not-yet-due job at once, and it puts itself back for ever.
- O13's frame reads no store, because an off store must work (§4.11).
- The page limit is 60 KB of JavaScript (`JavaScriptBudgetTest`), and the heaviest pages are at 59.9. Split a shared file by who
  uses it, as `written-list.tsx` and `written-form.tsx` are, before reaching for anything else. `React.lazy` never on first paint
  under SSR; a dialog can be lazy.
- A new panel-frame or shop-frame query changes exact counts in many tests: grep them all (`AdminFrameQueryBudgetTest`,
  `ShopPagesTest`, `AddressFormPlacesTest` among others). The counts are targets recorded in tests and the PR, not hard caps.

**Browser tests: what has bitten.**

- Run each file alone, in the background, under a time limit that stops it, and keep the failure's words. A plain run has hung for
  hours. Never run two on the same test database, and never build during a run.
- The first browser run after a build or restart times out at sign-in. Warm up on a small file first (`CarriersScreenTest`).
- The browser tests keep their rows between runs, and lists sort by `created_at`. **Never plant a far-future `created_at`:** the rows
  stay on top of every later run and hide its rows. Give each run's data its own number.
- A whole-store send goes to every customer of that store, so these tests write in `ae`, where few runs leave customers.
- A product on a shop list needs stock and a price: `CatalogProducts::onSale` (catalog.md amendment 39).
- Inertia saves the scroll place 100 ms after scrolling stops, so a test that clicks at once comes back to the earlier place.
- `$page->script()` calls a value that is a function, so wrap statements in `(() => { ... })()`. A click's selector must match one
  element. `assertSeeIn` has missed Arabic text; read it with `script()`.
- Every test checks a phone at 375 × 812, with `browserSoon` so the width is read after the page settles (#333).
- The test clock is frozen (#335): read `CarbonImmutable::now()`, never `time()`, `date()` or `microtime()`, against anything the app stamped, and never write an unbounded loop. One such loop hung CI for hours (#348).
- A Pest file's functions are global: name each helper after the file's subject.
- The panel opens in English in tests. Read a form's refusal with `AdminBrowser::flashed`.
- `checked()` doesn't wire `onChange`: a hand-built `InputGroup` calls `check.onType()` itself (`SmsCountries.tsx`).
- Lists differ in order: the subscribers list is oldest first (`ORDER BY id`), the others newest first. Read the list's query before a test counts on a row's place.

**Seen once and not explained; don't take these as your own failure without checking.**

- `InventoryScreensTest > it draws the product page's Inventory section…` failed once, then passed three reruns.
- `CatalogListScreensTest > it adds a word pair and deletes another` failed once with a `QueryException`; the message wasn't kept, and
  it has passed every run since.

## Tests

`tests/Modules/Ops/Integration` — `EventMessagesTest` (§1.5, §1.6, the sent-then-marked row, C14, the critical
SMS), `PaymentEventsTest` (C10, C11, S6 and transfers overdue from Stage 7's real flow), `CampaignEventsTest`, `MessageKindsTest`, `SecurityMessagesTest`, `SecurityFailureAlertTest`, `SecurityFailureAlertRaceTest`, `WrittenTextDigitsTest`, `SendersTest`, `BrevoFailuresTest` (every way Brevo can fail, on every path, on the real database queue), `SmsCountriesTest`, `RealEmailDriverTest`,
`BellsTest`, `NoticesTest`, `NoticeSegmentTest`, `StaffMessagesTest`, `NewsletterTest`, `BrevoNewsletterListTest`,
`ReportsTest`, `DailyReportTest`, `DailyReportEmailTest`, `PruneTest`, `OpsPermissionsTest`, `OpsSchemaTest`,
`OpsTablesTest`. `tests/Modules/Ops/Feature` over HTTP — `BellRoutesTest`, `WrittenRoutesTest`, `ReportRoutesTest`,
`DailyReportRoutesTest`, `StopEmailPagesTest`, `SmsCountryRoutesTest`, `SmsCountriesScreenTest`, `BellScreensTest`, `WrittenScreensTest`, `SubscribersScreenTest`, `ReportsScreenTest`, `DailyReportsScreenTest`, `ScreensReadsTest`, `SegmentReadsTest`, `AudienceCountTest`. The consent itself is Access's: `tests/Modules/Access/Integration/MarketingConsentTest`,
`tests/Modules/Access/Feature/CustomerAccountPageTest`. ops.md §8 maps each scenario to its test and says what no
test covers.
