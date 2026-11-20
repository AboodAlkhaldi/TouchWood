# Content

Content says what the shop says and how it is dressed: the home page, the frame's announcement bar and footer, banners,
pop-ups, smart bars, pages, the blog, projects, policies, campaigns and their runs, themes, SEO, the sitemaps and robots file, and the
redirects from the old site. **The agreed rules are [`docs/modules/content.md`](../../../docs/modules/content.md)**; this file
says how the module is built.

The backend, the admin screens W1–W11 and the shop pages H1–H7 are built (content.md's status line: built 2026-11-10 to
2026-11-15); policies, W12 and H8, in build 4 (2026-11-20 and 2026-11-21). Content depends on Platform, Catalog and Pricing, and on Access only to declare its permissions
([handoff §4.4](../../../docs/HANDOFF.md); content.md §9.4 A1; `deptrac.yaml`: `Content: [+ContentPublic, AccessPublic,
AppHttp]`). Only Ops depends on Content: it hears `CampaignWentLive` and `CampaignExpiring`, reads `ContentApi`, and binds
`CampaignNotices`, the port Content declares so that Content never depends on Ops. Content answers Pricing's `CampaignNames`
port and registers into Platform's and Catalog's registries (`ShopFrameParts`, `UnmatchedAddresses`, `MediaUsages`,
`AdminMenu`, `CatalogUsages`, `ShopListingSlots`), so none of them depends on Content either; it answers Platform's `ShopPolicies` for Sales, Access and B2B the same way. The
contracts are content.md §2.

## Built

Thirteen planned PRs (PR 7 in two), numbered "Content n/13" in their titles, and the changes made during the run that touched
Content.

| PR | What | Where |
|---|---|---|
| #364 | The spec: rules, screens, contracts, the owner's answers | `docs/modules/content.md` |
| #365 — 1/13 | The amendments to other documents (A1 to A12, content.md §9.4) | `docs/HANDOFF.md`, `docs/modules/*.md`, `resources/css/themes.css`'s header |
| #366 — 2/13 | The foundation: the translator, the unmatched addresses, the permissions, rich text; the `content` schema and its slug history | `Application/ContentPermissions`, `Application/Text`, `Domain/ValueObject/RichText`, `Command/TranslateText`; Platform's `TextTranslator` and `UnmatchedAddresses` |
| #368 — 3/13 | Content's own items: home, frame (bar, footer), banners, pop-ups, smart bars, pages, blog, projects, their uploads; Platform's `MediaUsage` and Catalog's `CatalogUsage`; the shop's cached reads | `Domain/Model`, `Application/Command/*`, `Application/Documents`, `Application/Query/Shop`, `Infrastructure/Eloquent`, `Infrastructure/Cache`, `Infrastructure/Media` |
| #369 — 4/13 | SEO, sitemaps, robots, the canonical host, redirects | `Application/Sitemap`, `Application/Redirects`, `Infrastructure/Queue`, `Presentation/public-routes.php`, `Presentation/Http/RedirectAnswer`; Platform's `CanonicalHost` |
| #367 — 5/13 | Campaign prices in Pricing, Catalog and Sync | `src/Modules/Pricing` (`CampaignPrices`), `src/Modules/Catalog`, `src/Modules/Sync`; no file of Content's |
| #382 — 6/13 | Campaigns, their runs going live, and themes | `Application/CampaignRules`, `Application/ThemeRules`, `Domain/Service/CampaignAlerts`, `Infrastructure/Queue/AdvanceCampaignsJob`, `Infrastructure/External` |
| #373 — 7a/13 | Admin screens W1 Home, W2 Bars, W3 Banners, W6 Pop-Ups | `Presentation/Http/Controller/{HomePage,Bars,Banners,Popups,Uploads}Controller`, `resources/js/pages/Content/Admin/{Home,Bars,Banners,Popups}` |
| #386 — 7b/13 | Admin screens W7 Pages, W8 Blog, W9 Projects (and W10 SEO, W11 Redirects, though the title names only W7–W9), and translated text marked until read (P14) | `DocumentsController`, `BlogCategoriesController`, `SeoController`, `RedirectsController`, `Application/TranslationMarks`, `resources/js/pages/Content/Admin/{Pages,Blog,Projects,Seo.tsx,Redirects}` |
| #388 — 8/13 | Admin screens W4 Campaigns and W5 Themes, and the 7b review's fixes | `CampaignsController`, `ThemesController`, `ThemePreviewController`, `resources/js/pages/Content/Admin/{Campaigns,Themes}` |
| #387 — 9/13 | The shop's frame: announcement bar, phone drawer, tab bar, footer, pop-up, themes | `Presentation/Frame`, `Presentation/Http/ShopFrameOfRequest`, `Presentation/Http/Middleware/StorefrontTheme`, `resources/js/components/content`, `StorefrontLayout` |
| #389 — 10/13 | The shop's pages H1–H7, the home moved to Content, and `/` answering 302 | `ShopPagesController`, `Presentation/shop-routes.php`, `resources/js/pages/Content/Storefront`; Platform's `ChooseStoreController` |
| #390 | The campaign page's full web address on W4b, per store and language | `Presentation/Http/Resource/CampaignAddressData`, the W4b page |
| #370 — 11/13 | Panel labels, counted tabs, whole-row orders, one-press language, phone top bar | Panel-wide: `resources/js/components`, `layouts`, other modules' pages |
| #371 — 13/13 | Brevo's list jobs last a day; the bell alert for a refused queued email | `src/Modules/Ops` |
| #372 | Translate keeps bold and links | `Application/Text/TranslationTags`, Platform's `DeepLTextTranslator` |
| #391 — 12/13 | Admin lists as cards on phones, text-copy-12 swept, a guard for undefined text sizes | `resources/js/components/PhoneCards.tsx`, every module's list pages, Content's among them |
| #375 | Re-uploading a failed image makes its sizes again | `Application/ContentImages` |
| #376 | A normal and a long queue connection, so long jobs never run twice | `Infrastructure/Queue/{BuildSitemapsJob,CheckRedirectsJob,RunOnce}` |
| #381 | The owner's small fixes: the sitemap keeps sold-out products, among others | `Application/Sitemap/SitemapBuilder` |
| #392 | Pricing's follow-up to #388's review (fixed sizes only on a ceiling change; skips reopened on re-wiring) | `src/Modules/Pricing`; Content's `CampaignsScreenTest` |
| `48946f0e` | The shop pages send Catalog's card words, so product cards on the home, posts, projects and campaigns read in words, not keys | `ShopPagesController` |
| `f9cf9aed` | #391's review: a recode's codes, one rule for the offer menu, a type's comments, two more tables named in frontend.md | Catalog, Pricing and B2B pages, `docs/modules/frontend.md`; no file of Content's |
| `fbd14c05` — build 4 step 1 | Policies: their data, rules, versions and permission; Platform's `ShopPolicies` with its stand-in | `Domain/Model/Policy*`, `Application/Policies`, `Command/{Save,Publish,Unpublish,Delete}Policy`, `UploadPolicyImage`; Platform's `Public/Contracts/ShopPolicies` |
| `cd1e262f` — build 4 steps 2-4 | Policies in the panel (Content › Policies) and the shop (the page, the footer row, the sitemap); `ContentShopPolicies` binds Platform's contract | `PoliciesController`, `ShopPagesController::policy`, `Infrastructure/External/ContentShopPolicies`, `resources/js/pages/Content/{Admin/Policies,Storefront/Policy.tsx}` |
| `02e7e344` — build 4 steps 5-7 | The Terms ticked at checkout, sign-up and a company application, kept by Sales, Access and B2B | `src/Modules/{Sales,Access,B2B}`; no file of Content's |
| Build 4, the turn-off | Per-store Unpublish and Delete, the switched-off copy, `content.policy_periods`, old texts readable on the shop, `ShopPolicies::versionAddresses` | `PolicyWriter`, `DatabasePolicies::settlePeriods`, `DatabaseShopDocuments`, `components/content/policy-stores-confirm.tsx` |

## What is here

- **`Public/Contracts`** — `ContentApi` (`campaignForMessage`: a **run's** id; null unless the run is live), `CampaignNotices`
  (the offer notices a campaign may carry: `drafts`, `named`; declared here, bound by Ops). **`Public/Dto`** —
  `CampaignMessageDto`, `NoticeChoiceDto`. **`Public/Events`** — `CampaignWentLive`, `CampaignExpiring`, after commit, ids only.
- **`Domain/Model`** — `HomeSection` and `HomeSectionType`, `HomeLogo`, `Bar`, `Footer`, `Banner` and `BannerPlacement`, `Popup`,
  `SmartBar` and `SmartBarScope`, `Document`, `DocumentKind` and `DocumentText` (pages, posts and projects), `BlogCategory`,
  `Campaign`, `CampaignRun` and `RunState`, `Theme`, `ThemePart` and `ThemePartKind`, `Publication`, `ScheduleState`, `Redirect`,
  `SeoDefaults`. **`Domain/ValueObject`** — `RichText` and `RichTextReader`, `Slug`, `RedirectPath`, `Schedule`, `Words`,
  `PriceChoices`. **`Domain/Service`** — `CampaignAlerts` (the 72- and 24-hour table, §1.7 #11), `HomeSectionSettings`.
  **`Domain/Repository`** — one interface per subject (`Banners`, `Campaigns`, `Themes`, `Documents`, `Redirects`,
  `UnreadTranslations`, `WaitingJobs`, `ContentLocks`, `FrameItems`…), SQL in `Infrastructure/Eloquent`. **`Domain/Exception`** —
  the errors of content.md §7, each extending `ContentError`, words at `content::errors.{snake}`.
- **`Application`**, shared rules — `ContentPermissions`, `ContentAccess` (store off, unknown id), `ContentAudit`, `ContentImages`,
  `ContentCache`, `ContentJobs`, `EditCheck` (the version and the edit's checks), `Moments` (the store's zone), `Ordering`,
  `BannerRules`, `PopupRules`, `CampaignRules`, `ThemeRules`, `TranslationMarks`, `RefusalWords`; `Documents/{DocumentInput,
  DocumentWriter}`; `Text/{MarkText, MarkTextReader, TranslationLimits, TranslationPieces, TranslationTags}`.
- **`Application/Command` and `Application/Query`**, one folder per use case, by subject:
  - home: `AddHomeSection`, `FillHomeSection`, `OrderHomeSections`, `RemoveHomeSection`, `ShowHomeSection`, `UploadHomeImage`;
    query `HomePage`;
  - frame: `SaveBar` (the announcement bar), `SaveFooter`; query `Bars`;
  - smart bars: `SaveSmartBar`, `DeleteSmartBar`;
  - banners: `SaveBanner`, `ScheduleBanner`, `EndBanner`, `BannerBackToDraft`, `DeleteBanner`, `OrderBanners`, `UploadBannerImage`;
    query `Banners`;
  - pop-ups: `SavePopup`, `SchedulePopup`, `EndPopup`, `PopupBackToDraft`, `DeletePopup`, `UploadPopupImage`; query `Popups`;
  - pages: `SavePage`, `PublishPage`, `UnpublishPage`, `DeletePage`, `UploadPageImage`;
  - blog: `SavePost`, `PublishPost`, `UnpublishPost`, `DeletePost`, `UploadBlogImage`, `SaveBlogCategory`, `DeleteBlogCategory`,
    `OrderBlogCategories`;
  - projects: `SaveProject`, `PublishProject`, `UnpublishProject`, `DeleteProject`, `UploadProjectImage`; pages, blog and
    projects share the query `Documents` (with `LinkMatch`);
  - SEO: `SaveSeoDefaults`, `UploadSeoImage`; query `Seo`;
  - sitemaps: `BuildSitemaps`, `QueueWantedSitemaps`, `RequestSitemapRebuild`; `Application/Sitemap/{SitemapBuilder,
    SitemapSources, SitemapFiles, RobotsFile}`;
  - redirects: `AddRedirect`, `ChangeRedirect`, `DeleteRedirect`, `ResetRedirectCount`, `UseFinalAddress`, `RequestRedirectCheck`,
    `CheckRedirects`, `UploadRedirectSheet`, `ApplyRedirectSheet`, `PruneRedirectUploads`; query `Redirects`;
    `Application/Redirects/{RedirectLookup, RedirectRules, RedirectSheet, SheetReader, AddressProbe, RedirectAudit}`;
  - campaigns: `SaveCampaign`, `ScheduleCampaign`, `CancelCampaign`, `EndCampaign`, `DeleteCampaign`, `SetRunPrices`,
    `ChooseRunNotice`, `UseCampaignDates`, `AdvanceCampaigns`; queries `Campaigns`, `CampaignOfRun`, `PreviewRunPrices`;
  - themes: `SaveTheme`, `ScheduleTheme`, `EndTheme`, `ThemeBackToDraft`, `DeleteTheme`, `UploadThemeImage`; queries `Themes`,
    `PreviewTheme`;
  - translation marks: `TranslateText`, with `TranslationMarks` and `UnreadTranslations`;
  - uploads: the `Upload…Image` commands above, under each item's own permission, through `ContentImages`;
  - the shop: `Query/Shop/{ContentFrame, ContentHome, HomeAlternates, ShopDocuments, ShopReads, ThemeStyle}`; the screens' lists,
    `Query/ScreenReads`; what each screen may offer, `Query/ContentActions/{ContentActionsForReader, StoreContentActions}`.
- **`Infrastructure/ContentServiceProvider`** — the bindings, the permissions, the listeners, the menu, the schedule, the
  frame part, the listing slot, the theme middleware, the routes.
- **`Infrastructure/Eloquent`** — `Database…` for every repository, `DatabaseScreenReads`, `DatabaseShopReads`,
  `DatabaseShopDocuments`, `DatabaseSitemapSources`, `ContentCatalogUsage`, `DocumentTables`, `ForeignKeyRefusals`, `Rows`.
- **`Infrastructure/Cache/VersionedContentCache`** — `ContentCache` on the kernel's `VersionedCache` (below).
- **`Infrastructure/Listener`** — `WriteStartingSections` (`StoreCreated`: eight hidden sections), `ForgetShopCopiesOfFile`
  (`MediaVariantsReady`: invalidates the cache when Content uses the file), `QueueSitemapBuild` (a store switched on or off;
  Catalog's `ProductArchived`, `ProductMadeReady`, `StoreListingChanged`).
- **`Infrastructure/Queue`** — the jobs (below), `QueuedContentJobs`, and the long jobs' middleware `RunOnce` and `OneSitemapBuild`.
- **`Infrastructure/External`** — `ContentApiImpl`, `ContentCampaignNames` (Pricing's `CampaignNames`), `NoCampaignNotices`
  (Content's default answer, "none", until Ops binds `OpsCampaignNotices`), `HttpAddressProbe` (Check Addresses' requests).
  **`Infrastructure/Files/LocalSitemapFiles`**, **`Infrastructure/Media/ContentMediaUsage`**,
  **`Infrastructure/Sheets/OpenSpoutSheetReader`**.
- **`Infrastructure/Persistence`** — `ContentSchema` and the migrations of content.md §5.
- **`Presentation`** — `admin-routes.php` (`content.admin.*`), `shop-routes.php` (H1–H7 and the shop's Stop Preview),
  `public-routes.php` (`/robots.txt`, `/sitemap.xml`, `/{store}/sitemap.xml`, `/{store}/sitemap-{part}.xml`, no middleware
  group); `Http/Controller` (one per screen, plus `ShopPagesController`, `SitemapController`, `RobotsController`,
  `TranslateController`, `UploadsController`, `CampaignRunController`, `ThemePreviewController`); `Http/Resource`, the `…Data`
  classes of the screens and the shop pages; `Frame/{ContentFramePart, ContentListingSlot}`; `Http/ShopFrameOfRequest`,
  `Http/ThemePreviewCookie`, `Http/Middleware/StorefrontTheme`, `Http/RedirectAnswer`, `Http/FormRefusalWords`;
  `lang/{ar,en}`: `admin_*` per screen, `audit`, `errors`, `jobs`, `menu`, `permissions`, `shop`, `shop_frame`.
- **Screens** under `resources/js/pages/Content/{Admin,Storefront}`, their parts in `resources/js/components/content`.

### The shop frame and its cache

`ContentFrame` reads one cached copy per store and language, `frame:{store}:{locale}` (content.md §1.5, §9.3d D1): the bar,
the footer, the pop-ups, the themes with their parts, the smart bars and the listing banners, **each kept with its start and
end and filtered by the clock on each request**, so nothing waits for the cache at a start or an end. The home's own copy is
`home:{store}:{locale}` (`ContentHome`, §1.4); best sellers, category tiles and the Points figures are read per request.

`VersionedContentCache` (`content:shop`) keeps **one version for all of it** in PostgreSQL on the app's connection: every
change that alters what a shopper sees invalidates it inside its own transaction, and a file's sizes becoming ready does too
(`ForgetShopCopiesOfFile`). The hour's snapshot is a safety net only. `ShopFrameOfRequest` keeps the copy on the request, so
the frame part (`ContentFramePart`, `frame.content`), the theme middleware and a product list's slot (`ContentListingSlot`)
share it: **warm, 2 queries a page** (the version and the copy), and every shop page's recorded count rose by 2. The SEO
defaults ride in the same copy (§9.3f F7). The pop-up shows only on `ContentFramePart::POPUP_PAGES` (home, the catalogue's
pages, Content's own), never in a staff view.

### Campaigns, their runs, going live, and Pricing

A campaign picks its stores; each store is a **run** (`content.campaign_runs`) with its own id, dates, prices, notice and
state (§1.7, §4.1). A draft's price choices stay in Content (`price_choices`, `PriceChoices`) and reach Pricing only at
Schedule, through Pricing's `CampaignPrices::save`; from then Pricing is the record (§1.7 #14). Schedule writes **one store
at a time in its own transaction, in store-id order**; a store that refuses part way leaves the earlier ones scheduled and
says so (`CampaignPartlyScheduled`, §1.7 #15). Categories and brands a live or waiting run names are kept with RESTRICT keys
in `content.campaign_run_targets` (§9.3b C3).

**Going live** is `AdvanceCampaignsJob`, every minute on one server (§1.7 #9): one conditional update makes a run `LIVE`, and
only the update that changed the row writes `CampaignWentLive` (with its `noticeId`), so a retry or a second copy fires nothing.
At most 200 runs a tick (`AdvanceCampaignsHandler::BATCH`), one try. The same job writes the ending alerts, `CampaignExpiring`,
by `CampaignAlerts`' table, each stamp (`warned_72_at`, `warned_24_at`) in the transaction that sends it. A run of an off
store waits. Cancel and End lock the run's row first; the loser is told (`CampaignNotChangeable`). **Pricing starts and stops
the prices on its own timed tasks**, so a late tick never delays a price. A run's shop address is made at Schedule
(`slug_ar`, `slug_en`, §9.3f F6) and W4b shows its full web address (#390). The sizes a run skips for the ceiling are read
from Pricing (`CampaignPrices::skippedSizes`, §1.7 #17).

### Themes and Preview Theme

A theme is the 11 parts of §1.8 (`ThemePartKind`) and nothing else: no colour, radius or shadow. One live per store; an
overlap is refused on save under each store's lock (`ThemeClash`). The parts drawn as images reach the frame part; the
background parts become `theme.style`, custom properties built by the server from media sizes only (`ThemeStyle`), which
`app.blade.php` prints. **`content.storefront-theme`** (`StorefrontTheme`, in `StorefrontArea::MIDDLEWARE` after the store)
shares `theme.campaign` and `theme.style` on the shop's pages alone; the panel and error pages stay `base`.

**Preview Theme** (W5b, §9.3d D5, §9.3e E15): `ThemePreviewController` sets a signed, HTTP-only cookie for two hours
(`ThemePreviewCookie`) and opens the staff view of the theme's first store. The shop reads past the frame's cache only during a
staff view of someone who may see off stores, asked again on every page, and answers `noindex` and `no-store`. Stop Preview is
`DELETE /admin/content/themes/preview` in the panel and the unnamed `POST /{store}/{locale}/theme-preview/stop` on the shop.

### "Not read yet" translation marks (P14)

Translate (`TranslateText`, `TranslateController`, Platform's `TextTranslator`) fills an English box and marks it (§1.3).
**An item saved while a box carries the mark and nobody ticked "I read the translated English."** keeps a row in
`content.unread_translations` (`TranslationMarks`, §1.3 #4, §9.5 row 1): pages, posts, projects, banners, pop-ups, home
sections, the announcement bar, and campaigns and themes (migration `2026_11_14_100000_mark_campaigns_and_themes_unread`).
Its form opens marked, by anyone, in any later session; Publish, Schedule, Show and switching the bar on ask for the tick again
and are refused without it (`TranslationUnread`). Only the tick clears it. Rich text goes block by block, bold and links as XML
tags (`TranslationTags`, #372); the limits are `TranslationLimits`.

### SEO, sitemaps, robots, the canonical host and redirects

- **SEO** (§1.14): per-store defaults (`SaveSeoDefaults`, `content.seo_defaults`), each page's head through the store's title
  pattern (§9.3f F7), canonical and alternates on every page; only the homes name each other across stores (`HomeAlternates`).
- **Sitemaps**: `/sitemap.xml` indexes each on store's `/{store}/sitemap.xml`, split at 50,000 addresses, built by
  `SitemapBuilder` into private files (`LocalSitemapFiles`, `content.sitemaps`), never in a shopper's request (§9.3a B2).
- **Robots**: one generated `/robots.txt` with each on store's lines (`RobotsFile`); `Disallow: /` anywhere but production.
- **The canonical host**: Platform's `CanonicalHost` middleware: without `www`; the other host answers 301 to a read and
  308 to anything else (§9.3a B1).
- **Redirects** (§1.15): `content.redirects`, Super Admins only. `RedirectAnswer`, registered with Platform's
  `UnmatchedAddresses`, answers 301 **only where nothing else answers** (no route, or an unknown single word that matched
  `{store}`), so no shop page pays a query. A sheet is checked first (`UploadRedirectSheet`, `content.redirect_uploads`) and
  written only by `ApplyRedirectSheet`; Check Addresses (`CheckRedirectsJob`) marks lines gone stale.

### The shop pages and `/`

`ShopPagesController`, under `{store}/{locale}` with `StorefrontArea::MIDDLEWARE` (§4.5, §9.3f): H1 the home
(`storefront.home`, moved here from Platform), H2 `pages/{slug}`, H3 `blog` and `blog/categories/{slug}`, H4 `blog/{slug}`,
H5 `projects`, H6 `projects/{slug}`, H7 `campaigns/{slug}`. Not published, or a campaign before its run is live: the shop's
own 404. A campaign past its end: 301 to the store's home. An old or other-language slug: 301 to the current address when it
is published (`content.slug_history`). Pages under `resources/js/pages/Content/Storefront`.

**`/` with no remembered store answers 302** to the base store's home, in the remembered language or else Arabic
(Platform's `ChooseStoreController`; §1.14, Q20 (a), §9.3f F1); a remembered store goes to it.

### Policies (build 4)

The rules are content.md §1.11b; the owner's decisions of 2026-11-20 and the builder's picks still waiting on the owner are
there and in §9.3g.

- **A policy serves a list of stores** (`content.policy_stores`), one of nine kinds (`PolicyKind`, fixed once made), at most
  one published of each kind per store but Other (`PolicyKindTaken`, checked in `PolicyRules` under
  `ContentLocks::POLICIES`). Its words per language as a page's; addresses unique per store and language, claimed per store
  in `content.slug_history` under the kind `POLICY`.
- **`PolicyWriter`** does every change, each under the policies' lock and at the version the form read: **save** changes
  only the stores ticked (changed words with a store left unticked **split** it into a new policy); **publish** acts on
  every store it serves; **unpublish** on the stores picked (some of them: a switched-off draft copy for those stores);
  **delete** only while never published (`PolicyNotDeletable`), on the stores picked.
- **Versions**: `text_version`, and each published text kept in `content.policy_versions` and `policy_version_images`
  (F14), never changed. **`content.policy_periods`** records which version was in force in which store, at which address,
  from when to when (`DatabasePolicies::settlePeriods`, after each change).
- **Other modules** read policies only through Platform's `ShopPolicies`, answered by `ContentShopPolicies`: a store's
  published policies from the cache (`policies:{store}`, one query cold, 2 warm), and `versionAddresses` from the periods
  (one query, never cached) for staff's order page.
- **The shop**: `ShopPagesController::policy`, `/{store}/{locale}/policies/{slug}` (H8): the current text with "Last
  updated", a kept version at `?version=N`, and a text no longer valid in the store, labelled with its dates there and a
  link to the current policy, at `?version=N&policy=<id>` or at its old address (`ShopDocuments::pastPolicy`,
  `policyPeriods`). The footer's bottom line carries the store's published policies, in the frame's cached copy; the
  sitemap lists them (`SitemapSources::POLICY`).
- **The panel**: Content › Policies (W12), `PoliciesController`, `content.policy.manage`; Unpublish… and Delete… ask
  which stores (`components/content/policy-stores-confirm.tsx`, loaded when asked).
- **Media**: a published policy's images block their file's delete; an image a version keeps always does
  (`ContentMediaUsage`).

### The admin screens

W1–W11 (§4.4), and W12 Policies (build 4), routes `content.admin.*`, pages under `resources/js/pages/Content/Admin`, one menu section, Content, with its
eleven entries registered in the provider (§4.3): W1 Home (`HomePageController`, sections and footer), W2 Bars
(`BarsController`), W3 Banners, W4 Campaigns (and W4b, `/runs/{run}` answering 302 to it), W5 Themes, W6 Pop-Ups, W7 Pages,
W8 Blog (`DocumentsController`, `BlogCategoriesController`), W9 Projects, W10 SEO, W11 Redirects (and W11b, the checked
sheet). A screen asks `ContentActionsForReader` what it may offer; every handler checks again. Lists are cards on phones
since #391.

## The permissions

From `ContentPermissions`, declared into Access's catalog at boot (content.md §3):

| Permission | What | Kind |
|---|---|---|
| `content.homepage.manage` | Home sections and the footer (W1) | Per store, any role |
| `content.bar.manage` | The announcement bar and smart bars (W2) | Per store, any role |
| `content.page.manage` | Pages (W7) | Per store, any role |
| `content.banner.manage` | Banners (W3) | Per store, any role |
| `content.campaign.manage` | Campaigns, their notices, linking a theme (W4); S8's audience | Per store, any role |
| `content.popup.manage` | Pop-ups (W6) | Per store, any role |
| `content.blog.manage` | Blog posts and categories (W8) | Per store, any role |
| `content.project.manage` | Projects (W9) | Per store, any role |
| `content.policy.manage` | Policies (W12, build 4): a save in each store it ticks or takes off, Publish in every store the policy serves, Unpublish and Delete in each store picked | Per store, any role |
| `content.seo.manage` | SEO defaults, robots lines, rebuilding the sitemap (W10) | Per store, any role |
| `content.theme.manage` | Themes (W5) | **Reserved**, Super Admins, checked in every store |
| `content.redirect.manage` | Redirects (W11) | **Reserved**, Super Admins, store-free |
| `content.campaigns.advance` | `AdvanceCampaignsJob` | **Reserved**, the system's, store-free |
| `content.sitemap.build` | `BuildSitemapsJob` | **Reserved**, the system's, store-free |
| `content.redirects.check` | `CheckRedirectsJob` | **Reserved**, the system's, store-free |

Translate has none of its own: it takes the permission of the kind being typed (`ContentPermissions::forKind`). A campaign's
prices also need Pricing's `pricing.price.edit` in that store; `ops.notice.manage` is named only to offer W4b's link to Offer
Notices. Neither is declared here.

## Jobs and the schedule

Scheduled in `ContentServiceProvider`, always as jobs, each `onOneServer()`:

| Job | When | Connection |
|---|---|---|
| `AdvanceCampaignsJob` | Every minute | Normal; 1 try, 50 s |
| `BuildSitemapsJob` | Nightly 03:10 Riyadh, and when `QueueWantedSitemapsJob` or W10's Rebuild Sitemap queues it | **`LongQueue`**; 600 s, 240 tries a minute apart; `RunOnce` and `OneSitemapBuild` |
| `QueueWantedSitemapsJob` | Every ten minutes: queues the build a change wants (a store switched on or off, at the next tick; Catalog's changes at most once a UTC day beyond the nightly) | Normal; 1 try |
| `PruneRedirectUploadsJob` | Hourly: checked sheets nobody applied, after 24 hours | Normal; 1 try |
| `CheckRedirectsJob` | Not scheduled: W11's Check Addresses, a page of lines a job, each queuing the next | **`LongQueue`**; 1200 s, 3 tries; `RunOnce` |

`ContentJobs` (`QueuedContentJobs`) queues the two long ones after the asking transaction commits. `content.waiting_jobs`
holds one row per long job (queued, wanted), so a second ask queues nothing; `content.job_runs` lets a copy a second worker
takes delete itself. Each job is named at `content::jobs`, for the failed jobs screen.

## Deploy notes

- **DeepL**: `TEXT_TRANSLATOR=deepl` and `DEEPL_API_KEY` (`config/services.php`, `.env.example`), read by Platform's
  `TextTranslator` binding. With no key Translate is offered disabled; the fake translator is refused in production. Staging
  must set the driver by hand, or it gets the fake ([HANDOFF §15.4](../../../docs/HANDOFF.md)).
- **Clear the cache once after #389** (`php artisan cache:clear`): Catalog's menus cached before it carry no per-category
  counts or tile photos (HANDOFF §15.4).
- **Run the long worker** (`php artisan queue:work database-long --queue=long --timeout=60`) beside the normal one, or the
  sitemaps and Check Addresses wait in the `jobs` table for ever. **Both connect to PostgreSQL directly, never through a
  transaction-mode pooler**: `RunOnce` and `OneSitemapBuild` hold session advisory locks (HANDOFF §15.4).
- **`public/robots.txt` is gone**: a static file would win over the generated route (content.md §1.14).
- **The canonical host**: an `APP_URL` on the `www.` host is refused at boot when serving HTTP (§9.3a B1).
- **Search Console's reading of the new sitemap** cannot be tested before a real account (content.md §8).

## Waiting

| What | State here | On whom |
|---|---|---|
| The end-of-stage checks | The shop pages with no design (blog, post, project, campaign: drawn from its look, frontend.md §2.1); the whole site's wording, approved for now; the build's picks in content.md §9.3a–§9.3f | The owner |
| Lists still without phone cards | [frontend.md](../../../docs/modules/frontend.md)'s browser tests, "Not yet cards" (around line 1427), names them; all are other modules'. Content's campaign price preview (`components/content/campaign-prices.tsx`) is listed there as not a list | Follow-ups |
| Toast placement | frontend.md §2.1 says success toasts show at the bottom centre; `components/Toasts.tsx` puts them at the bottom end. Not changed | The owner |
| Policies: the builder's picks | Built (build 4, §1.11b). Four picks wait on the owner: an address taken from a switched-off policy, the switched-off copy starting at version 1 with no history, the `&policy=` parameter, periods of no length shown (content.md §9.3g G1-G4) | The owner |
| The real themes | Made with the owner in the panel, as the stage's last step (§1.16) | The owner |
| The redirect sheet | Parked until the final product list exists; before launch (§1.15) | The owner |

## Tests

`tests/Modules/Content/Integration` — `CampaignsTest`, `ThemesTest`, `BannersPopupsSmartBarsTest`, `DocumentsTest`,
`HomeAndFrameTest`, `ShopReadsTest`, `TranslateTextTest`, `TranslationMarksTest`, `SeoDefaultsTest`, `RedirectLinesTest`,
`RedirectSheetTest`, `CheckRedirectsTest`, `SitemapQueueTest`, `RunOnceTest`, `ContentMediaUsageTest`, `ContentLocksTest`,
`ContentRulesTest`, `ContentFoundationTest`, `ContentAuditNamesTest`, `ContentMenuSectionTest`, `ContentItemsConstraintsTest`,
`SeoRedirectsConstraintsTest`, `PoliciesTest`, `PolicyTurnOffTest`, `PoliciesConstraintsTest`, `ShopPoliciesTest`. `tests/Modules/Content/Feature` over HTTP — `ShopPagesTest`, `ShopFrameTest`, `SitemapTest`,
`RobotsTest`, `RedirectAnswerTest`, `TranslateRouteTest`, `ThemePreviewRouteTest`, `ScheduleRefusalAnswerTest`, and each
screen's: `HomeScreenTest`, `BarsScreenTest`, `BannersScreenTest`, `PopupsScreenTest`, `DocumentsScreensTest`,
`CampaignsScreenTest`, `ThemesScreenTest`, `SeoRedirectsScreensTest`, `PoliciesScreenTest`, `ShopPolicyPagesTest`. `tests/Modules/Content/Unit` — `RichTextTest`,
`MarkTextTest`, `MarkTextCasesTest`, `RichTextLinkCasesTest`, `LinkMatchTest`, `TranslationTagsTest`, `ContentValuesTest`, with
the case tables the editors read too (`link-cases.json`, `mark-cases.json`). Helpers in `tests/Modules/Content/Support`.
Browser tests: `tests/Browser/Content*` (Home, Bars, Banners, Popups, Pages, Blog, Projects, Campaigns, Themes,
SeoRedirects screens; ShopFrame; ShopPages; Policies and PolicyStores screens; ShopPolicies). Boundaries: `tests/Architecture/ContentAccessUseTest`.

**The queries**, warm, for a guest, recorded in `ShopPagesTest` (8 a page is a target, not a cap; owner, 2026-10-19): home 9,
page 8, blog 9, post 10, projects 9, project 9, campaign 11, and a campaign with a category target 12. Each is the frame's 6
(the store 2, Catalog's menu 2, Content's frame 2), then the page's own. content.md §8 lists the scenarios and what no test
covers.
