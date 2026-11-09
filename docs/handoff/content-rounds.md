# Content: the rounds of questions so far

For the agent who writes the Content spec (`docs/AGENT-HANDOFF.md`). The spec is `docs/modules/content.md` (draft); this file is
not a spec. It holds the questions the owner was asked and the answers, in the owner's words where quoted. **The owner's latest
word wins**, so where a later entry changes an earlier one, the later one stands. Anything marked OPEN is still the owner's to answer.

Starting points in the repo: `docs/HANDOFF.md` §13.2 (Content), and what Ops is already built against: `Content\Public\Events\CampaignExpiring`,
`CampaignWentLive` and `ContentApi::campaignForMessage` (ops.md §1.6 S8, §1.7, §9.5), answered today by the placeholder `NoCampaignsYet`.

## The order the owner set

1. **Vision (2026-11-01):** build the whole storefront to fit the future. A campaign version of a store changes a lot: page backgrounds become a visual thing rather than a colour, flags, banners and more.
2. **Design first (2026-11-02):** the design was finished in Claude Design and handed over on 2026-11-03. The Content spec comes from that design, then each component is built.
3. **Themes before building (2026-11-03):** decide what changes per theme BEFORE building the site, so it's built right the first time. The owner and the agent build the themes together.
4. **Phone is not optional (2026-11-02):** 78% of clicks are on phones.

## Round 1 (asked 2026-10-31, answered 2026-11-01)

| # | Question (rec = what was recommended) | Owner's answer, verbatim |
|---|---|---|
| 1 | Scope at launch: (a) all: homepage, pages, banners, campaigns, pop-ups, alert bar, smart bar, blog, projects, SEO and redirects; (b) all but blog and projects. | "A; tell me how would we get the old site indexing and blogs and also explain to me whats and how would we build and is the SEO thing works i wanna understand it all" |
| 2 | Which platform is the old site on, and does it have blog posts or project pages? | "i guess wordpress, you can check if you want" (checked: not WordPress, see below) |
| 3 | Old-site redirects: (a) a table managed by Super Admins, with a CSV import from Search Console; (b) written by a developer in server config. Rec (a). | "you mean to let the previous pages still work since they are indexed and let them redirect to the new site? can't we just make indexing on the new site and let everything work that way or what" (explained in round 2; table confirmed below) |
| 4 | Homepage: (a) fixed section types that admins add, order and show or hide per store; (b) a free page builder. Rec (a). | "yes something like this, we can go with the reference i made with claude design or just build new ordering of sections, but A at the end" |
| 5 | Pages: (a) rich text per language; (b) plain text. Rec (a). | A |
| 6 | Every content item in both languages: (a) Arabic required, English required before publishing; (b) either alone. Rec (a). | A |
| 7 | Publishing: (a) draft to published, with optional start and end; (b) published at once only. Rec (a). | "i guess to go with B" (replaced by round 2 #1) |
| 8 | One theme live at a time: (a) per store; (b) whole site. Rec (a). | "A; since campaigns can be around the whole store of for some stores (like the Saudi national day can't be at the egy store)" |
| 9 | What a campaign can price: (a) products, sizes, categories, brands, with % off or a fixed price per size; (b) chosen sizes, fixed price only. Rec (a); lowest price still wins. | Not settled by the owner's answer: asked how themes and discounts relate (explained in round 2: separate things). See round 2 #7. |
| 10 | The 12 theme presets: (a) data that staff upload and edit; (b) designed in code. Rec (a). | "we will set and decide which are the things that will change for themes from the basice design of store" |
| 11 | Pop-ups: (a) one at a time per store, on entry or after N seconds, once per visitor, with start and end; (b) none at launch. Rec (a). | a |
| 12 | Alert bar and smart bar: what should the smart bar show? | "i may say yes to this" (round 2 #12: yes) |
| 13 | Blog: (a) posts, categories, cover image, SEO fields, linked products, no comments; (b) posts only. Rec (a). | "its in, btu explain this too" |
| 14 | Projects: (a) a showcase of finished client projects: title, story, photos, city, products; (b) photos only. Rec (a). | a |
| 15 | SEO: (a) title, description, share image per language with defaults, plus sitemap and robots per store; (b) defaults only. Rec (a). | "which the besst for us ?" (recommended (a) in round 2) |
| 16 | Who edits: (a) a Content permission group per store, campaign prices also needing Pricing, redirects and themes Super Admin only; (b) admins only. Rec (a). | A |
| 17 | Your content: (a) entered through the admin screens, plus the redirects CSV; (b) sent as files. Rec (a). | "according to what we will upload and how its the shape of this data and which are the data that we're gonna upload" |

## What was checked on the old site (2026-11-02, read only)

- touchwoodksa.com is not WordPress. It runs PHP on LiteSpeed; inferred from its addresses (`/flash-deals`, `/todays-deal`): a ready-made Laravel store script.
- Its sitemap was made once, on 2026-01-10: 205 addresses. It's Arabic only, with no canonical or language tags.
- `/blog` has 6 posts, none in the sitemap. `/projects` is 404.
- **Search Console, 2025-11-01 to 2026-10-31:** home is 30% of clicks and the top 100 pages 92%. 78% of clicks are on phones; Saudi 80%, Egypt 12%. 213 of 368 clicked pages aren't in the old sitemap. Of the top 120 pages, 18 already answer 404 on the old site.

## Round 2 (asked and answered 2026-11-02)

| # | Question (rec) | Owner's answer |
|---|---|---|
| 1 | Publishing: (a) pages and blog at once; campaigns, pop-ups and banners keep start and end, and only the Content permission changes them; (b) no times anywhere. Rec (a). | A |
| 2 | The new site takes touchwoodksa.com on launch day, old site off. Rec yes. | Yes: this codebase takes the domain from day one, same hosting provider, old code down. |
| 3 | Matching old products to new: (a) an optional old-address field in the import; (b) a separate sheet by hand. Rec (a). | Not (a): don't add an old-address field to the import, because new products aren't necessarily the old ones. **The sheet was chosen after the Search Console review** (below). |
| 4 | An old address with no new page: (a) forward to its category; (b) show gone. Rec (a). | Decided with #3, through the sheet (below). |
| 5 | Page editor: (a) extend Catalog's text format; (b) a separate editor. Rec (a). | **OPEN.** To be decided after the Search Console review and an explanation; the owner would replace everything from scratch for clean SEO. |
| 6 | One campaign in several stores: (a) a campaign picks stores and shares its look, with prices per store; (b) copied per store. Rec (a). | A |
| 7 | The campaign's admin page: (a) one page with theme and prices sections; (b) prices on a Pricing page. Rec (a). | Campaign and theme are **separate**. A campaign may link to a theme; its page then shows the theme's settings or a link, and they start and end together when the dates match. No linked theme, nothing shown. |
| 8 | What a theme can change. | **OPEN, now Round 3 #1–4.** The owner: keep the site's colours, but the campaign must feel present. |
| 9 | Order of work. | Settled by the order above: the design came from Claude Design. |
| 10 | Homepage sections. | The prototype's sections and order stay as they are (below). |
| 11 | Banners: (a) desktop and phone images, per language where the artwork has text; (b) one image. Rec (a). | A, with each size defined. |
| 12 | Smart bar: a short message and link above product lists, per category or campaign. Rec yes. | Yes |
| 13 | Pop-up frequency: (a) once per visitor per pop-up; (b) once a day. Rec (a). | A: once per visitor per pop-up, delayed, small on phones. |
| 14 | English on the 6 old blog posts: (a) translate; (b) allow Arabic only for blog posts. Rec (b). | **OPEN:** it depended on #3. |
| 15 | Projects: (a) build it, hidden until the first project is published; (b) leave out. Rec (a). | The owner asked whether this means new finished projects or old-site things, and later said projects get added by the owner. **The client must be asked** what they can give (reminder 20). |

## Decisions after round 2

- **Redirects (2026-11-02):** a hand-checked sheet, not automatic. The redirects table needs a visit count and last visit date per line. The sheet has 140 pages, 95.8% of clicks, and **stays parked until the owner's final product list exists**; the owner fills it, and it must be done before launch (reminder 18).
- **Old site:** before the old code goes down, copy the 6 blog posts' text and photos, and anything else worth keeping.
- **Desktop home (2026-11-02):** keep the prototype's sections and order: bundle, projects, individuals and companies, points, client logos. Its spacing is fine. Change only the footer and the newsletter box's shape. (Then see "the design" below: bundles were dropped on 2026-11-03.)

## The design (handoff zip received 2026-11-03)

The owner's words, in substance:
- The design is the reference for sections and look.
- **Storefront:** as the design, with edits such as the back-to-admin button in admin view.
- **Admin panel:** keep what's built. List any conflict with the design for the owner, who decides by shadcn, Geist and common practice. Anything already agreed stays.
- **Admin Home dashboard:** NOT as the design says; references may need sending (check with the owner).
- **National Day design:** dropped, a failed try.
- **Anything in the design the system lacks** (bundles, "مختارات لك") is simply dropped.

The comparison of design against built code is in `docs/handoff/design-compare.md`.

## Round 3 (asked 2026-11-03, answered 2026-11-09)

Answered: see "Round 3 answers" below. "rec" is what was recommended.

**Themes, decided first (a sample comes after the answers)**
1. **Parts a theme may change.** Rec: announcement bar, hero artwork, section bands (promises row, points, logos), flags and ribbons on products, product badges, listing banners, cart message, footer artwork, and page background at the edges only.
2. **Parts kept fixed.** Rec: colours of text and buttons, prices, cards, photo backgrounds, phone tab bar, stars and hearts.
3. **Logo:** can a theme swap it? Rec: no, to keep the brand steady.
4. **Sample:** which theme to draw as default against campaign? Rec: Ramadan.

**Storefront**
5. **Card contents:** the design shows an add button, stock counts and product codes; earlier decisions forbid them. Rec: keep them off.
6. **Technical list view for contractors:** build or drop? Rec: build.
7. **Colours:** the design's differ slightly (navy `#0B3B63` against `#02365e`). Rec: keep the current set, already settled for the admin panel.
8. **Numbers on product pages:** the design uses a monospaced font. Rec: the design's look for prices only, if Arabic reads well; show the owner first.
9. **Product photos:** the design's gallery is 4:3 on desktop, the built one square. Rec: square, because most supplier photos are square and it never crops.

**Admin panel (keep what's built unless the owner chooses otherwise)**
10. **Menu:** the design's 15 short names against the built 16. Rec: the design's shorter names.
11. **Tabs:** Orders uses a pill with no counts, 7 other pages are underlined. Rec: underlined everywhere, with counts.
12. **Opening an order:** only the number today, the whole row on other lists. Rec: whole row.
13. **Language switch:** the docs say one press, it's built inside the person menu. Rec: follow the doc.
14. **Phone top bar.** Rec: the design's icon-only View Store, 44-pixel menu button and 20-pixel title, keeping shadcn's breakpoint.
15. **Wide tables on phones:** they scroll today. Rec: cards on phones.
16. **Kept as built, unless the owner objects:** white page ground, the list layout, and no "All operational" line, since nothing real sits behind it.

**Brevo**
17. **Lost unsubscribes:** if Brevo's unsubscribe call is lost in an outage, consent stays on with us and Brevo offers no replay. Add a nightly read of recently changed contacts? Rec: yes.
18. **Starting the site:** already built as recommended (`OpsServiceProvider::ONLY_LOGGED`): a missing key or sender refuses to start in production; a missing folder or webhook secret is a loud log line instead, at most once an hour. **Confirm with the owner** (ops.md §9.6).
19. **Queued list jobs** stop after about 36 minutes today. Make them last a day? Rec: yes.
20. **Bad key or no credit:** add a bell alert for queued emails too? Rec: yes.

## Round 3 answers (owner, 2026-11-09)

"The rest as rec", so #2, #3, #4, #6, #7, #9 to #20 are as recommended above.

| # | Answer |
|---|---|
| 1 | Shown each part, default against a campaign. "Yes, all 11": announcement bar, hero artwork, promises row, points band, logos band, product flag and ribbon, product badge, listing banner, cart message, footer artwork, page background at the edges only. |
| 2 | As rec: fixed in every theme are text and button colours, prices, cards, photo backgrounds, the phone tab bar, stars and hearts. |
| 3 | As rec: a theme can't swap the logo. |
| 5 | "That was just a design and these are dummy data": cards keep the add button, stock counts and codes OFF. |
| 6 | As rec: build the technical list view for contractors. |
| 7 | As rec: keep the current colours (`#02365e` etc.). |
| 8 | Prices keep the current font. Digits stay 0-9 everywhere. |
| 9 | As rec: product photos square. |
| 10 to 16 | As rec: the design's shorter menu names (15); underlined tabs with counts everywhere; whole row opens an order; one-press language switch per the docs; the design's phone top bar (icon-only View Store, 44 px menu button, 20 px title, keeping shadcn's breakpoint); wide tables become cards on phones; keep white page ground, list layout, no "All operational" line. |
| 17 to 20 | As rec: add a nightly read of recently changed Brevo contacts; keep the start-up rule as built; queued list jobs last a day; bell alert for queued emails on a bad key or no credit. |

## Round 4 (owner, 2026-11-09)

| # | Question | Answer |
|---|---|---|
| 1 | Page editor | Pages and blog use Catalog's text format extended with links, images and tables; no raw HTML stored. (Settles Round 2 #5.) |
| 2 | What a campaign discounts | Products, sizes, categories and brands, by % off or a fixed price per size; lowest price still wins; Pricing's CAMPAIGN kind. |
| 3 | Who creates a theme | A Super Admin, by filling the 11 parts and uploading artwork; no developer. The owner: build the themes "after we finish the whole content stage, as an extra step inside the stage itself, not to let it for other time". So the 11 slots go into the site from the start; the actual themes (National Day, Ramadan...) are created with the owner as the stage's last step. |
| 4 | Overlapping themes | A second theme overlapping in one store is refused on save, naming the clash. |
| 5 | Announcement and alert bar | One bar (they are the same thing), which a theme can dress. |
| 6 | Translation (and the 6 old posts) | A Translate button for staff on every Content item that has Arabic and English: staff type Arabic, then type English or press Translate; English fills the box, staff read and fix it, then publish; nothing goes live unseen. Service: DeepL (`deeplcom/deepl-php`), token in the server's settings (the owner holds it), behind an adapter Feedback's F2 and F5 can reuse. English stays required before publishing. Whether DeepL keeps formatting for Arabic is not verified. (Settles Round 2 #14.) |
| 7 | Projects page | Built, hidden until the first project is published. |
| 8 | Pop-up targeting | One pop-up for everyone in the store at launch; once per visitor per pop-up, delayed, small on phones. |
| 9 | A campaign's notice | Chosen on the campaign's page, sent once on go-live (`CampaignWentLive` with `noticeId`), back to draft if the campaign is cancelled. |
| 10 | Ending alert | `CampaignExpiring` fires twice: 3 days and 24 hours before the end. A campaign shorter than 3 days gets only the 24-hour one, shorter than 24 hours gets none. |
| 11 | Who gets it | S8's audience: holders of the new Content permission. |
| 12 | How content is entered | Typed into the admin screens; only the redirect sheet is uploaded. |

## Open owner calls for the spec

All earlier ones are settled by rounds 3 and 4. What is still the owner's is the numbered list in `docs/modules/content.md` §9.2.
