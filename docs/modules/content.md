# Content: module specification

**Status: ACCEPTED 2026-11-10 (owner, in chat; merged with #364).** Nothing here is built. It is written from the owner's answers in
`docs/handoff/content-rounds.md` (rounds 1 to 4) and the code on `main` at `4434e8ac`. The owner answered every question of §9.2 on 2026-11-10 ("Yes, all rec"); each answer is written
where it applies. A pick marked `[ACCEPTED 2026-11-10]` was the spec's own and was accepted with the rest, none rejected (§9.3).

**Stage:** 8 (handoff §17), the second of the stage's two modules. **Depends on:** Platform, Catalog and Pricing (handoff §4.4), and on
Access only to declare its permissions (a new arrow, §9.4 A1). **Source:** `docs/HANDOFF.md` §4, §5, §13.2, §14; `docs/handoff/content-rounds.md`;
`docs/handoff/design-compare.md`; ops.md §1.6 S8, §1.7, §2.3, §9.5; pricing.md; catalog.md; frontend.md §1.8, §1.10, §1.11; the placeholder in
`src/Modules/Content`; the owner's design (handoff zip of 2026-11-03: `TouchWood Home`, `TouchWood Screens`, `TouchWood Admin`), read only, kept outside the repo.

Content owns **what the shop says and how it is dressed**: the home page, the frame's words, the pages, banners, campaigns, themes, the pop-up,
the announcement bar, the smart bar, the blog, the projects, SEO and the redirects from the old site.

Five of the owner's rules shape everything below:

- **Phone is not optional** (2026-11-02): 78% of the old site's clicks are on phones. Every screen and shop page is specified at 375 x 812.
- **Build the site once for its themes** (2026-11-03): the 11 theme parts are built into the site from the start; **the actual themes (National Day, Ramadan...) are created with the owner as the last step of this same stage** (2026-11-09).
- **Nothing goes live unseen** (2026-11-09): English comes from typing or from Translate, staff read it, then publish.
- **Arabic always, English before publishing** (2026-11-01).
- **A clean start** (2026-11-02): content is typed into the admin screens; only the redirect sheet is uploaded (round 4 #12).

## What this module does not own

| Concern | Owner |
|---|---|
| Campaign prices: the numbers, resolving the lowest, the ceiling, the moment a price starts and ends | Pricing, through a new contract (§2.3 row P1). Content holds which campaign and the staff's choices; Pricing holds and applies the prices |
| Products, categories, brands, their slugs, their shop pages | Catalog. Content adds to its pages through registries Catalog declares (§2.3) |
| The newsletter box, the bell, offer notices, every message | Ops. Content fires events; **Content never makes Ops send anything by calling it** (ops.md §1.7) |
| Who a customer is, segments | Access, Promotions. **The pop-up targets everyone at launch** (round 4 #8), so Content has no arrow to Promotions (closes promotions.md §9.2 #4) |
| Files, sizes, the audit log, stores, settings, the menu | Platform |
| The rebuild of the existing shop pages to the design (category, product, cart, account...) | A separate job, after Content and before the themes (Q19 (a), owner 2026-11-10; §1.16, §1.17) |
| Panel-wide changes the owner chose in round 3 (shorter menu names, underlined tabs with counts, one-press language, phone top bar, whole row opens an order) and Brevo's three | Separate jobs, §1.17. Content's own screens already follow them |

---

## 1 · Rules

### 1.1 What every content item follows

1. **Per store.** Everything but themes' definitions, redirects and the DeepL token belongs to one store (handoff §4.1). A campaign or a theme picks its stores (§1.7, §1.8). **Pages, blog posts and projects belong to one store too** (Q11 (a), owner 2026-11-10).
2. **Two languages.** Every item a shopper reads has Arabic and English. **Arabic is required to save at all; English is required to publish or schedule** (owner, 2026-11-01). A draft may have Arabic alone. There is no fallback on a shopper's page: a half-Arabic page is worse than a blocked publish (handoff §5.2). **Where there is no Publish step**, the same rule applies at the moment the words would reach a shopper `[ACCEPTED 2026-11-10]`: the footer, smart bars, SEO defaults and blog categories are refused on save without both languages; the announcement bar can't be switched on without both; a home section can't be shown without both; a theme part with words on one side refuses Schedule, naming the part; English blanked on anything published, scheduled or live is refused (`MissingEnglish`).
3. **Digits.** Every digit shown and every digit saved is 0-9, Arabic pages included (owner, 2026-10-06). Arabic-Indic digits typed are accepted and saved as 0-9, text included (as catalog.md amendment 12).
4. **Direction.** Arabic pages are right to left, English left to right, from logical properties only. In the panel each language's box writes in its own direction; a figure sits in `<bdi dir="ltr">`.
5. **Publishing** (owner, 2026-11-02, round 2 #1). **Pages, blog posts and projects publish at once**: no dates. **Campaigns, pop-ups and banners keep a start and an end.** Only the Content permissions change them.
6. **Time.** The database keeps UTC. Every moment typed or shown is in **the store's own time zone** (`StoreDto::$timezone`), its zone named beside it (`DateTimeField`, `Time`). All-store pages (themes, campaigns list) show the first store's zone per row. **A typed time is read on each store's own clock**, for a campaign and for a theme alike: 00:00 is each store's own midnight (Q1 (a), owner 2026-11-10).
7. **A store that is off** (handoff §1): its shop answers 404 as today, Content's pages and sitemap included, and it leaves `/sitemap.xml` and the robots file. In the panel only someone who may switch stores is offered it, marked Off, to prepare it (`StoreChoices`, `StoreOffBadge`); anyone else is refused (`content.store_off`). **A theme, banner, pop-up or smart bar whose store goes off keeps its dates**: nobody can see it, nothing fires, and it shows again if the store comes back inside its dates. *A campaign's run is §1.7 #9 and #11.*
8. **Colours.** No component carries a colour. Everything is a token of `themes.css`. A theme changes no colour, radius or shadow token; it sets only image properties written by the server, `--tw-backdrop` for the page's edges among them (§1.8).
9. **Components.** Only shadcn and Geist, as frontend.md §1.11. Named per screen in §4. The only thing neither has is a text editor: one is put together from their parts (§1.2).
10. **Images.** Every image is a public file in Platform's library, JPEG, PNG or WebP, 10 MB at most (handoff §5.5), uploaded under the item's own permission (`uploadMediaFor`). Each media id sits in a column or a link table with a `RESTRICT` foreign key, never in JSON. Content registers a `MediaUsage`: **a file in use by something scheduled, live or published blocks the delete; one used by a draft or something ended is detached** `[ACCEPTED 2026-11-10]`, and a body image's block goes with it, so no document is left naming an image that is gone. **An image isn't ready until Platform has made its sizes** (`MediaVariantsStatus`: `PENDING`, `READY`, `FAILED`; `MediaUrlsDto::$variants` is empty till then). Publish and Schedule are refused while any image they would show is not `READY`, naming it: "Banner image is still being prepared. Try again in a minute." or, for `FAILED`, "Upload this image again." (`ImageNotReady`) `[ACCEPTED 2026-11-10]`. Sizes are §1.6.
11. **Deleting.** A draft may be deleted. Published pages, posts and projects must be unpublished first. A banner, pop-up, campaign or theme is deleted only as a draft; an ended or cancelled one is history and stays. Deleting is permanent, asks first (`DestructiveActionDialog`), and is audited. A deleted item's addresses are free again.
12. **Two people editing.** Long texts lose work under "last save wins", so pages, posts, projects, campaigns, themes, the home sections and the frame carry a version: a save over a newer version is refused with who and when, and what was typed stays on the screen (`content.stale_edit`) `[ACCEPTED 2026-11-10]`. Overlap checks (themes, pop-ups) run under a per-store lock. Short lists saved row by row stay last save wins, as elsewhere (pricing.md Q41).
13. **Audit.** Every change writes a Platform audit entry in its own transaction, named `content.*` in both languages. Short fields by value, from and to. **Long text (a body) as "changed" with its length** `[ACCEPTED 2026-11-10]`: the log is kept forever.
14. **Words.** Written with the build in both languages, formal Arabic, Geist's writing rules (frontend.md §1.10), approved for now: the owner checks the whole site's wording later (reminder 16).
15. **Addresses.** Slugs are per language, Arabic letters for `ar`, Latin for `en`; unique per store, language and kind; made from the title, editable. **Every slug an item ever had is kept. An old slug, or the other language's slug, answers 301 to the current page in one hop, exactly when that page answers 200 for this viewer; otherwise 404**, as a slug that never existed, so a draft stays hidden (handoff §5.2; catalog.md amendment 38's rule).
16. **Links typed by staff** are one of: a page of the shop without its store and language (ops.md §4.4's rule, cross-language safe), or a full `https://` address, in the footer, a body or a button only. Anything else (`javascript:`, `//`, a backslash) is refused. A picker offers category, brand, product, page, post and project, and writes the path. **A link is a path, so it can go stale**: a changed slug still forwards (rule 15); something deleted answers the shop's 404. Unpublishing or deleting one of Content's own pages, posts or projects lists first where Content's links name it (footer, home, banners, bars, pop-up) and asks `[ACCEPTED 2026-11-10]`.
17. **What other modules delete.** Catalog deletes a category or brand only when unused and asks every module first (`CatalogUsage`, catalog.md amendment 19). **Content registers one**: a banner, smart bar or campaign target naming it refuses the delete, naming the use, with a `RESTRICT` key behind it. An archived product or size: in a campaign's choices it is skipped and listed (§1.7 #4); a post's or project's linked product vanishes from the shop and stays listed in the panel, marked Archived. A notice deleted in Ops reads as gone (§1.7 #10).

### 1.2 The text format (round 4 #1)

Pages and blog posts use **Catalog's text format extended with links, images and tables. No raw HTML is stored**, so nothing typed can run as a script. A project's story uses the same format.

Stored: a structured document, `{"blocks": [...]}`, per language. Today's blocks are kept in the same shape (`Catalog\Domain\ValueObject\StructuredText`): `paragraph`, `heading`, `list`, with runs `{text, bold?}`. Content keeps **its own copy** of the value object: Catalog's is not public, and a class in Shared needs three users (handoff §4.5).

| Added | Typed as | Stored as | Rules |
|---|---|---|---|
| A second heading level | `## ` | `heading` with `level: 3` (`# ` is level 2; a page's title is its only level 1) | One line |
| A numbered list | `1. ` | `list` with `ordered: true` | |
| A link inside a line | `[words](address)` | a run with `link` | §1.1 rule 16. External links open in a new tab with `rel="noopener noreferrer"` |
| An image | `![description](image:3)` on its own line | `image` with `ref: 3` | `ref` names a row of the item's own images table (`content.page_images`, `post_images`, `project_images`), which holds the media id with its foreign key and goes when the item goes (§1.1 rule 10). The description is required, per language. Picked or uploaded from the editor, which writes the mark. Drawn at 100% of the text's width, with its real width and height, lazy below the fold, in Platform's Detail size |
| A table | lines of `\| a \| b \|`, the first line the header | `table` with `head` and `rows` of runs | 2 to 8 columns, 1 to 100 rows `[ACCEPTED 2026-11-10]`, a cell one line. On a phone it scrolls sideways inside its own frame |

Limits `[ACCEPTED 2026-11-10]`: 100,000 characters a language, 50 images a document. Refusals name the line ("Line 12: the table has 3 columns here and 4 above.").

**Shown** by one React component from the blocks: never `dangerouslySetInnerHTML`. **A mark written wrong is the text it is**, as Catalog's `**` left open, except that an image or link reference that resolves to nothing is refused at save. The typed marks and the stored blocks convert both ways, so a text saved unchanged stays as it was.

**The editor** is a shadcn `Textarea` with the formatted text beside it as it is typed (under it on a phone), Catalog's own pattern (catalog.md §9.7 P1). **A small row of shadcn `Button`s (Bold, Link, Image, Table, List) writes the marks for the person** around what is selected (Q10 (a), owner 2026-11-10). The formatted preview is `Tabs` (Write, Preview) on a phone.

### 1.3 The Translate button (round 4 #6)

On **every box or text that has an Arabic and an English side**, for whoever may edit that item:

1. Staff type the Arabic. Then they type the English, or press **Translate** (ترجمة) beside the English box.
2. The English box fills. The box is marked **Translated, read it before publishing** (مترجم آليًا، راجعه قبل النشر), in the save-state place fields already use. Staff read it and fix it.
3. Nothing is saved or published by the button itself. **Publish or Schedule asks once, with a required tick:** "I read the translated English." (قرأت النص الإنجليزي المترجم.) when any box still carries the mark `[ACCEPTED 2026-11-10]`: the owner's "nothing goes live unseen" made into one tick. Typing in a box removes its mark.

**Rules.**

- Translate is disabled, with the reason, while the Arabic is empty ("Type the Arabic first."), and when no token is set on the server ("Translation isn't set up on this server."). A non-empty English box asks before it is replaced: "Replace the English text?" with **Replace Text** and **Keep Text**.
- **Translate All** at the top of a form with several pairs fills every English box that is empty, leaving the rest. `[ACCEPTED 2026-11-10]`
- **Rich text is sent block by block**: each heading, paragraph, list item, table cell and image description is its own text, so the document's structure comes back whatever the service does. **Bold and links go with the words: (a), built** (owner, 2026-11-10, after a live test). Each block is sent as XML with `tag_handling` `xml`: bold as `<b>words</b>`, a link as `<a i="0">words</a>`, where `i` is the link's place in the block and its address stays on our side (never sent, so the service cannot change it); `&`, `<` and `>` in the words are written as XML's entities. The answer is read strictly: only the tags sent, nested as sent, each link's index once. **An answer that is not that falls back for its box alone** to (b): the box's blocks are put back as plain words, their marks dropped, and the editor says under the box: "2 paragraphs lost their bold or links in translation. Add them again." (`lostMarks`, now counted only then); the press never fails for it, and no tag the service writes becomes a mark. A press holding no bold or link is sent as plain text. Either way images keep their place and addresses never go to the service.
  **The live test** (2026-11-10, the owner's DeepL key, run through `DeepLTextTranslator`): Arabic is taken as a source; the target `en-US` is taken; with tag handling, `خزانة <b>خشب الزان</b> مع <b>ضمان سنتين</b>.` came back as `<b>Beech wood</b> cabinet with <b>a two-year warranty</b>.`, the tags on the right words though the words moved. **Links were not tested live**: an answer that loses or changes a link's tag takes the fallback above.
- Digits in the answer are turned to 0-9. Text the service returns is treated as typed text: the same limits, the same marks check.
- **Limits** `[ACCEPTED 2026-11-10]`. It is a call in the person's request, like Sync's one test call, since a person is waiting. **One press sends at most 20,000 Arabic characters** (inside the service's 128 KiB, so one call: checked on the request's bytes as the library encodes them, tags included), **in 15 seconds at most, with no retry**. Translate All is one press and its boxes count together. Over the cap, nothing is sent: "This text is too long to translate at once. Translate it in parts, or type the English." A text kept at the 100,000 limit of §1.2 therefore can't be translated whole. At most 30 presses a minute and 300,000 characters a day per person. **The check is by kind, not by item**: the request names the kind and the store, and the handler checks that kind's permission there, so an item not yet saved can be translated. Only staff text is ever sent; no customer's data.
- **Failures keep what is in the box** and say so under it: quota used up (DeepL's 456) "Failed to translate: this month's allowance is used up. Type the English instead."; too many requests (429, 529) "Failed to translate: too many requests. Try again in a minute."; a bad token (403), a timeout or anything else "Failed to translate. Type the English instead." The token and the raw answer never reach a log or a screen.
- Translated text is never stored apart, and no history of it is kept.

**The adapter** is Platform's so Feedback F2 and F5 can reuse it (Feedback cannot depend on Content): §2.2.

### 1.4 The home page (rounds 1, 2 and the design)

**Fixed section types that admins add, order and show or hide per store. No free page builder** (round 1 #4). The starting order is the prototype's, with bundles and "مختارات لك" dropped (owner, 2026-11-03):

| # | Type | What staff fill | Reads from elsewhere |
|---|---|---|---|
| 1 | `HERO` | Desktop: eyebrow, title, one line, main button, second button (each text and link, per language), up to 3 short facts `[ACCEPTED 2026-11-10]`, artwork per §1.6. **Phone: the swiped banners instead** (§1.6 `HOME_PHONE`, up to 3, the design's dots) | Live banners; the live theme's hero artwork |
| 2 | `PROMISES` | The row of promises: 2 to 4 `[ACCEPTED 2026-11-10]`, each an icon (a fixed list of Lucide icons), a title and a line per language | Theme's promises row |
| 3 | `CATEGORIES` | The heading; how many tiles (4 to 12, default 8) `[ACCEPTED 2026-11-10]` | Catalog's top categories in the store's order, for this viewer, with each one's photo (row C4). Nothing to choose by hand: an empty category hides itself (catalog.md §1.5) |
| 4 | `BEST_SELLERS` | The heading; how many (4 to 20, default 8 `[ACCEPTED 2026-11-10]`) | Catalog's `ShopBestSellers`, as built: only what this shopper can order, best-selling first |
| 5 | `PROJECTS` | The heading | **The newest published project, and the 2 newest published posts as "guides"** `[ACCEPTED 2026-11-10]`, the design's row. Hidden while no project is published (round 4 #7) |
| 6 | `AUDIENCE` | Two blocks, individuals and companies: title, line, button text and link per language | Nothing |
| 7 | `POINTS` | Small heading, heading and line, typed per language. **The figures are read from the Points settings, never typed** (Q16 (b), owner 2026-11-10): points per unit spent and points per unit off, with fixed words around them | `loyalty.points.earn_per_unit` and `loyalty.points.points_per_unit_off` (`PlatformApi::setting`); hidden while `loyalty.points.enabled` is off for the store |
| 8 | `CLIENT_LOGOS` | The heading; logos (image, name as its description, optional link), ordered by dragging, 6 to 40 `[ACCEPTED 2026-11-10]` | Theme's logos band |

- **Each type at most once.** A new store starts with all eight, hidden, in this order; **Add Section** brings back one removed. `[ACCEPTED 2026-11-10]`
- A section with nothing to show draws nothing: never an empty frame.
- The category row and quick chips on the phone home (the design's) are Catalog's frame, §1.5.
- **Cached** per store and language in the never-stale cache (handoff §5.4, `VersionedCache`), everything that isn't the shopper's. Each item carries its start and end, and the page filters by the clock, so a banner starts and ends on the second with no cache trouble. Best sellers are read per request as they depend on the shopper.

### 1.5 The frame

What every shop page carries from Content, through `ShopFrameParts` (`frame.content`), one cached read per store and language. **The cache holds every bar, theme part and pop-up that is scheduled or live with its start and end, and the part filters by the clock on each request**, so nothing waits for the cache at a start or an end. Every Content change that alters it, and a file's sizes becoming ready, invalidates it inside the change's transaction (`VersionedCache`). A person previewing a theme reads past the cache (§4.4 W5b). Every shop page gains this one read, recorded in its query test (owner, 2026-10-19).

- **The announcement bar** (one bar: "alert bar" and "announcement bar" are the same thing, round 4 #5). One per store: text (one line, 120 characters) per language, optional link. **On or off**, with optional start and end `[ACCEPTED 2026-11-10]`. Full width, above the header, at every width; one line, cut with an ellipsis on a phone, its whole text on the link's page. While a theme is live its dress (§1.8 part 1) applies. With the bar off and no theme words, nothing draws.
- **The header, top bar and phone frame** follow the design: a sticky desktop header; on a phone (640 or less) a 52 px sticky header (menu, logo, theme, favourites), a search row, and a fixed bottom **tab bar** of the design's five: Home, Explore, Cart, Favourites, My Account (الرئيسية، استكشف، السلة، المفضلة، حسابي). Those parts hold the shop's own controls and no Content data; they are built in this stage because the home needs them and every other page inherits them (§1.17). **Must stay though the design omits them** (design-compare): staff view line and menu, back to panel, shopper lines, verify-email badge, country and language switches, bell.
- **The footer**, per store: a line about the store, phone, email, address line, and up to 3 columns of up to 8 links `[ACCEPTED 2026-11-10]` (each a text per language and a link), the copyright line, per language. Keeps the theme switch and Ops' newsletter box. On a phone the design's own compact footer. The owner asked for the footer and newsletter box's shape to change (2026-11-02); the design's is that shape.
- **The live theme's parts** (§1.8) and **the pop-up** (§1.10).

### 1.6 Banners and image sizes (round 2 #11)

A banner: placement, **desktop and phone images, per language where the artwork has text** (a tick "This artwork has words on it"; ticked, an Arabic and an English image are each required), a link, a description for screen readers per language, start (default Now), end (optional, No End), order.

| Placement | Where | Live at once | Desktop image | Phone image |
|---|---|---|---|---|
| `HOME_HERO` | Behind the home hero, desktop and tablet | 1, the first in order | **Upload 1600 x 1040**; shown cover at 593x420 (641), 772x420 (820), 471x420 (1024), 647x420 (1400+), so **keep what matters in the middle** | None: phones use `HOME_PHONE` |
| `HOME_PHONE` | The swiped row on the phone home | 3, the first in order; **a live theme's hero artwork takes the first of the 3 places** | None | **Upload 1200 x 600, 2:1**; shown 347x174 at 375 |
| `LISTING` | Above a product list: one category (and those below it) or all lists | 1 per list, nearest category first, then the first in order | **Upload 1600 x 300** `[ACCEPTED 2026-11-10]` | **Upload 1200 x 300** `[ACCEPTED 2026-11-10]` |

Other slots, for the sizes shown beside each upload (design-compare; shown sizes at 375/641/820/1024/1400+): category tile 4:3, 187x140 to 315x236 (Catalog's photo); project card **upload 1600 x 800**, shown 593x400 to 801x400; guide card **upload 1200 x 400**, shown 593x190 to 501x191; client logo **upload 360 x 156 PNG**, shown 180x78; a blog cover and a project's photos **upload 1600 wide at 3:2** `[ACCEPTED 2026-11-10]`; share image **1200 x 630**.

Each upload names its size under the box. **An image under the shown size at double density is warned, not refused** `[ACCEPTED 2026-11-10]`; Platform's sizes (200/600/1200/2400 on the longest side) keep the whole shape, never crop, and never enlarge. `<picture>` elements choose by width and format; the browser never gets the desktop file on a phone. Originals are never served (handoff §5.5). `MediaSize` stops at 2400 on the longest side, so nothing here asks for more.

### 1.7 Campaigns (round 1 #8-9, round 2 #6-7, round 4 #2, #9, #10)

A campaign is **a named period with prices, separate from a theme**. It may link to a theme; it need not.

1. **Fields.** Name per language (shown to staff and in S8's alert), stores (one or more), start, end (**required** `[ACCEPTED 2026-11-10]`: the alerts need one), an optional link to a theme, and per store: its prices and an optional notice.
2. **It picks stores, shares its look, and has prices per store** (round 2 #6). Each chosen store is a **run** (`content.campaign_runs`), with its own id, dates, prices, notice and state.
3. **Many pricing campaigns may run together. Lowest price still wins** (handoff §13.2; round 4 #2). Pricing's `CAMPAIGN` kind. **The one exception: a sale or category discount marked "always wins" beats a cheaper campaign** while both run on a size (Q4 (a), owner 2026-11-10; the category discount inferred from Q4's reason, "the mark keeps its one meaning", as pricing.md §1.3 and §1.5 put the mark on both).
4. **What it discounts** (round 4 #2). Products, sizes, categories, brands, each **a percentage off**, and for products and sizes optionally **a fixed price per size**. Categories and brands take a percentage only, since a fixed price is per size. Products can be left out of a category or a brand. Rounded half up to the coin (pricing.md §1.1). **An archived product or size is skipped and listed in the preview**, as a sale never holds one (pricing.md §1.3). **The store's discount ceiling limits a campaign** as it does a sale: one deeper is refused when saved, naming the products (Q2 (a)). **Each campaign chooses Retail, Wholesale or Both**; a wholesale line pays the lower of the campaign and its quantity price (Q3 (a)). **A category or brand target follows the products in it**: a product moved in gains the discount at once, one moved out loses it; products picked by name stay as picked (Q18 (a)). All owner 2026-11-10.
5. **Who.** `content.campaign.manage` for the campaign; **its prices also need `pricing.price.edit` in that store** (round 1 #16). Without it the Prices part reads "You may not edit prices in this store." and is read-only. Fields shared by every run (name, dates, theme) need the permission in **every store it runs in** (catalog.md §1.1's rule).
6. **Preview before saving.** The Prices part shows what it reaches and skips and every other offer on those sizes in the same dates, from Pricing (pricing.md §1.3's note and §1.5's preview).
7. **A linked theme** (round 2 #7). The campaign's page shows the theme's parts and a link to it. **They start and end together when the dates match**, compared store by store, each run's moments against the theme's moments for that store (`content.theme_stores`), so a two-zone campaign matches a two-zone theme. **When they don't, the theme keeps its own dates**: the page says, store by store, "Dates match" or "Dates differ", and a Super Admin has **Use Campaign's Dates** (Q6 (a), owner 2026-11-10). No linked theme, nothing shown. Linking needs only `content.campaign.manage` `[ACCEPTED 2026-11-10]`: it changes nothing on the theme; changing the theme stays a Super Admin's.
8. **Changing it.** A draft or scheduled campaign in full. **A live one: its name and end only, or End Now**, as a running sale (pricing.md §1.3) `[ACCEPTED 2026-11-10]`. **Half live** `[ACCEPTED 2026-11-10]` (two zones are mixed for an hour; an off store can leave one mixed for days): **once any run is live**, the start, the stores and the theme link are locked for every run. The name and the end still change for all, and a run not yet live still has its own prices and notice changed. A new end applies to runs scheduled or live; **an ended or cancelled run is never revived**.
9. **Going live** is one job every minute (`$schedule->job(AdvanceCampaignsJob::class)->everyMinute()->onOneServer()`, Platform's pattern for the outbox relay). A scheduled run whose start has come is made live by one conditional update, `SET state = LIVE WHERE id = ? AND state = SCHEDULED`; only the update that changed the row writes `CampaignWentLive`, in that transaction, so a retry or a second job fires nothing. Each run has its own transaction, so one bad run doesn't stop the rest; a failed job waits on the failed jobs screen and its retry carries on (named at `content::jobs.advance_campaigns`). Cancel and End lock the run's row first: **whichever gets there first wins**, and the loser is told: "This campaign has just gone live. End it instead." (`CampaignNotChangeable`). A run of **an off store does not go live**: it waits, with no notice and no alerts, and goes live if the store is switched on before the end (Q17 (a), owner 2026-11-10). A run whose end came without its going live ends with no event. **A live run whose store goes off stays live**: its prices are invisible with the shop, no alerts go (table below). Pricing starts and stops the prices itself, on its own timed tasks (pricing.md §1.10), so a late tick here never delays a price.
10. **Its notice** (round 4 #9). Chosen on the campaign's page, per store, from that store's **draft** notices in Ops (§2.3 row O1). **Sent once, on go-live**: `CampaignWentLive` carries `noticeId`. One notice serves one run at a time `[ACCEPTED 2026-11-10]`: otherwise one notice would be claimed by two go-lives and only the first would send. **If the campaign is cancelled before it goes live, the tie is cleared and the notice stays a draft in Ops**, ready for another use. After go-live it can't be recalled (ops.md §1.7). A notice cancelled, sent or deleted in Ops meanwhile shows that on the campaign's page and sends nothing.
11. **The ending alerts** (round 4 #10). `CampaignExpiring` per run, by this table. **Length** is the run's end less its start as scheduled, not the time left. Each alert is its own event with its own `eventId`, written by the same job's conditional update on `warned_72_at` or `warned_24_at`, in the transaction that stamps it, so it goes once.

    | Case | 72-hour alert | 24-hour alert |
    |---|---|---|
    | Length 3 days or more (exactly 3 included) | 72 hours before the end; exactly 3 days: as it goes live | 24 hours before the end |
    | Length from 24 hours up to under 3 days | None | 24 hours before the end; exactly 24 hours: as it goes live |
    | Length under 24 hours | None | None |
    | Goes live late (the job was down, the store was off) | Only if the length qualifies and more than 24 hours are left: at once | Only if the length qualifies: at once if 24 hours or less are left |
    | Its store is off at the moment | Held; decided as "goes live late" when the store comes back | The same |
    | The end moved, and this alert's moment is ahead again | Stamp cleared: sent again when it comes `[ACCEPTED 2026-11-10]` | The same |
    | The end moved, the moment has passed, it was sent | Not sent again | Not sent again |
    | The end moved, the moment has passed, it wasn't sent | Only if more than 24 hours are left: at once | At once |
    | The end moved so the length no longer qualifies | None from then | None from then |
    | Ended or cancelled | Never | Never |

    **Checked against Ops** (`AlertStaff`, `MessageWriter::campaignExpiring`, read 2026-11-09): "once per event" is by `eventId`, so two events alert twice, as wanted. The words read "ends on :date", the end in the store's zone; true for both alerts and needing no change. An end at exactly 00:00 names the new day; the form says so under the box: "Ends at the start of this day." (ينتهي مع بداية هذا اليوم.)
12. **Cancel and End.** **Cancel Campaign** while scheduled (all runs or one store): its prices are removed, its runs are `CANCELLED`, no event. **End Campaign** while live (all runs or one store): the run ends now, its prices stop now, the theme is not touched. Both ask first, naming the stores. Ended and cancelled ones stay listed.
13. **`campaignId` in Ops' events and `ContentApi::campaignForMessage` is the run's id** (the row per store). `MessageWriter` drops an alert whose read names another store; with one id per store the placeholder's contract works unchanged, the name being the campaign's. **The read answers null unless the run is live**, so an alert queued just before End or Cancel sends nothing. **No change to Ops' contract.**
14. **A draft's prices stay in Content** (Pricing has no draft: its candidates apply on their dates). While a run is a draft, its choices are kept as typed in `content.campaign_runs.price_choices`, and `CampaignPrices::preview` works from them without writing. **Only Schedule calls `CampaignPrices::save`**, which from then is the record; Content's copy is cleared and the form reads `CampaignPrices::read`. A choice no longer valid at Schedule (archived, past the ceiling) is refused or skipped as the preview says.
15. **Scheduling several stores.** Each run schedules in **its own transaction, one store's pricing lock at a time, in store-id order** (Pricing locks per store; lesson 37: one lock order). All runs are checked first with no writes. If a store then refuses, the runs done stay scheduled, the rest stay drafts, and the page says which, for another press. The campaign reads Mixed until they agree.
16. **A campaign with no prices may be scheduled** `[ACCEPTED 2026-11-10]`: handoff §13.2 allows "theme only", and it can carry a notice or a smart bar. **Someone lacking `pricing.price.edit` in a store whose run holds price choices can't schedule that run**, and the refusal names the store.
17. **A provider's price past the ceiling** (owner, 2026-11-10: "skip it from campaign so the new price active but no campaign and notice the staff about it so nothing is missed"). In a store wired to Odoo, when the provider's new price would take a scheduled or live campaign past the store's ceiling (§1.7 Q2 (a)) on a size, **the new price applies and the campaign skips that size**, as a fixed-amount category discount does (sync.md §1.4 #6): the size sells at the new price with no campaign. It rejoins the campaign by itself once the price allows again, whatever brought it back. **Staff are told**: the read opens a Sync report entry `CAMPAIGN_SKIPPED` naming the size and the campaign, which sends S4 through `SyncNeedsAttention` (bell and email, by their switches) to holders of `sync.report.view`; and the campaign's page (W4b) lists that store's skipped sizes, so its own staff see it too, **read from Pricing** (`CampaignPrices::skippedSizes`, §2.3), which decides the skip, so Content gains no arrow to Sync (the build coordinator's pick, 2026-11-10). The entry is meant to close when the size rejoins or the campaign ends; how Sync learns of either is open for the Pricing and Sync builders (sync.md §9.5 amendment 2 (b)).

### 1.8 Themes (rounds 3 and 4)

A theme is **those 11 parts only**. It is not colours, radius or shadow: this **replaces** the older note in `resources/css/themes.css` and frontend.md §1.8 that a campaign changes colour, radius and shadow (§9.4 A9). A theme is **created by a Super Admin in the panel by filling the 11 parts and uploading artwork; no developer** (round 4 #3). **The 11 slots are built into the site from the start, and the real themes are made with the owner as the last step of this stage.**

| # | Part | What the theme fills | Where it shows | Left empty |
|---|---|---|---|---|
| 1 | Announcement bar | Artwork strip (desktop 2400 x 80, phone 1200 x 80 `[ACCEPTED 2026-11-10]`), optional words per language with a link | §1.5, over the store's own bar | The store's bar as it is |
| 2 | Hero artwork | Desktop image per language if it has words; phone image likewise (§1.6 sizes) | Replaces `HOME_HERO` and leads `HOME_PHONE` | The banners |
| 3 | Promises row | Band artwork; optional words for each promise | The row's ground | The row as it is |
| 4 | Points band | Band artwork; optional heading | The band's ground | As it is |
| 5 | Logos band | Band artwork; optional heading | The band's ground | As it is |
| 6 | Product flag and ribbon | Flag image (small, transparent PNG or WebP, 96 x 96) and ribbon image (strip, 400 x 48 `[ACCEPTED 2026-11-10]`) | Corner of a card, top of a product's gallery. **Every product showing a reduced price now** (Q9 (a), owner 2026-11-10) | Nothing |
| 7 | Product badge | Short words per language (24 characters) | With the card's labels, in the system's badge style | Nothing |
| 8 | Listing banner | §1.6 `LISTING` sizes | Above every product list, ahead of ordinary banners | The banners |
| 9 | Cart message | One line per language, optional link | Top of the cart | Nothing |
| 10 | Footer artwork | Band artwork at the footer's top edge | Footer | As it is |
| 11 | Page background | Images for the **edges only**, start side and end side (each keeps its side's meaning: on Arabic pages the start side is on the right), each 480 x 1600 transparent PNG or WebP, tiled down `[ACCEPTED 2026-11-10]`. Never behind the content column, hidden at 1024 and under `[ACCEPTED 2026-11-10]` | Page edges, `--tw-backdrop` | The plain page ground |

**Fixed in every theme** (round 3 #2): text and button colours, prices, cards, photo backgrounds, the phone tab bar, stars and hearts. **A theme can't swap the logo** (round 3 #3). Prices keep the current font. **A part takes artwork only, never a colour**: left empty, it looks as the normal site (Q7 (a)). **One set of artwork serves light and dark**, the bands' ground softened in dark by a fixed tint, a token of `themes.css` per mode that no theme changes (Q8 (a)). Both owner 2026-11-10.

- **Legibility.** Words sit on artwork with text colours fixed, which no check can prove readable. The theme's page shows each part as it will look, at phone and desktop, light and dark, and states that, and **Preview Theme** opens the shop with it, for the person pressing alone (§4.4).
- **One theme live per store at a time** (round 1 #8). **A second theme overlapping in one store is refused on save, naming the clash** (round 4 #4): "Couldn't save the theme: Ramadan runs in Saudi Arabia from 2027-02-18 to 2027-03-19. Change the dates or the stores." Checked under each store's lock; a draft never clashes.
- **Fields.** Name per language; stores; start and end as typed, kept as moments **per store** (`content.theme_stores`), the same way a campaign's are (Q1 (a)); the parts. States as §4.1, per store's moments.
- **How artwork reaches the page** `[ACCEPTED 2026-11-10]`. Parts drawn as images (2, 6, 8) use `<picture>` from `mediaUrlsOf`. Parts that are a background (1, 3, 4, 5, 10, 11) can't use `<picture>`, so `theme.style` sets one custom property per part and width (phone, wider), each an `image-set()` of the file's AVIF and WebP sizes: **Zoom (2400) for a desktop band, Detail (1200) for a phone band and the edges**. Never the original, which is never served (handoff §5.5). **Transparent parts (6, 11) use WebP only**: Platform's JPEG sizes turn transparency white (`InterventionImageVariantGenerator`), and AVIF's handling on GD isn't verified. `background-position` has no start or end, so the server writes part 11's properties for the page's direction. An image whose sizes aren't ready can't be scheduled (§1.1 #10), and one that fails later draws as if left empty.
- **Safe to print.** `app.blade.php` prints `theme.style` unescaped. Content fills it only with custom properties built by the server from media ids, never with anything typed.
- **How the live theme reaches the shell.** `HandleInertiaRequests` runs in `web`, before the store is resolved, and knows no module (its own docblock), so it stays as it is, answering `base`. **A new shop-area middleware of Content's**, `content.storefront-theme`, sits in `StorefrontArea::MIDDLEWARE` after `STORE`, as `access.identify-customer` does, and shares `theme.campaign` and `theme.style` from the same cached read as §1.5, the shop's pages only; the panel stays base. An error page drawn outside it stays base.
- **In dark mode and right to left.** Text colours are fixed per mode, so artwork is judged in both on the theme's page. One set serves both modes (Q8 (a)). Nothing in a part is mirrored but where it sits: artwork with words on it is uploaded per language.
- **Nothing a theme does touches price.** "A visual theme does not create discounts" (the design's own line; round 2 #7).

### 1.9 The smart bar (round 2 #12)

A short message and a link above product lists, **per category or per campaign**. Message 100 characters per language, link optional.

- **Per category:** above that category's list and those below it, with optional start and end `[ACCEPTED 2026-11-10]`.
- **Per campaign:** above every product list in that run's store while the run is live.
- **One at a time above a list:** the nearest category's first, then a campaign's, newest first `[ACCEPTED 2026-11-10]`. Sits above the listing banner, full width, one line and a scroll-free ellipsis on a phone.

### 1.10 The pop-up (rounds 1 #11, 2 #13, round 4 #8)

**One pop-up for everyone in the store at launch.** One live at a time per store (round 1 #11); a second overlapping one is refused on save, naming the clash, as for themes `[ACCEPTED 2026-11-10]`.

- **Fields.** Name (staff only), title and text per language (80 and 240 characters), optional image, button words and link per language, delay in seconds (3 to 60, default 8 `[ACCEPTED 2026-11-10]`), start, end (optional).
- **Once per visitor per pop-up**, kept in the browser (`localStorage` under one key holding pop-up ids; if storage is refused, it isn't shown at all, so nobody is nagged) `[ACCEPTED 2026-11-10]`. Delayed; drawn only in the browser after the page, no effect on first paint or SSR.
- **Small on phones**: shadcn `Sheet` from the bottom, at most 40% of the height (325 px at 812). **Its image is left out on a phone** `[ACCEPTED 2026-11-10]`: 240 characters, a title and a button already fill that; text that still runs over scrolls inside the sheet. Desktop: shadcn `Dialog`, 420 px wide, with the image. Closes on the X, Escape, outside press and the button; focus returns.
- Not shown in the staff view, nor on cart, checkout, sign-in and account pages `[ACCEPTED 2026-11-10]`: no pop-up between a customer and paying.

### 1.11 Pages (round 1 #5)

Title, slug, body (§1.2), SEO (§1.14), per language; Draft or Published. Address `/{store}/{locale}/pages/{slug}`. A page may be picked for the footer or any link.

### 1.12 Blog (round 1 #13)

**Posts and categories, cover image, SEO fields, linked products, no comments.**

- A post: title, slug, one-line summary, body, cover image and its description, one category (optional), up to 8 linked products `[ACCEPTED 2026-11-10]`, SEO, per language where it has words. Draft or Published, published at once. Published date shown.
- Categories: name and slug per language; dragged into order; deleted only when empty.
- Addresses: `/{store}/{locale}/blog`, `/{store}/{locale}/blog/{slug}`, `/{store}/{locale}/blog/categories/{slug}`.
- **Linked products** show as the shop's cards under the post, only those the shopper can see (`productCards`, the `ShopCardFacts` flow), and vanish if no longer sold.
- **Old posts.** The 6 old posts are typed in, English by Translate (round 4 #6, settling round 2 #14). Their text and photos must be copied before the old code goes down (reminder 18). Their old addresses go in the redirect sheet.

### 1.13 Projects (round 1 #14, round 4 #7)

A showcase of finished client projects: title, slug, story (§1.2), photos (ordered, 1 to 20 `[ACCEPTED 2026-11-10]`), city per language, products used (up to 20 `[ACCEPTED 2026-11-10]`), SEO. Draft or Published.

**Built, and hidden until the first project is published**: no footer or nav link, no home section, and `/projects` answers 404 until then. Unpublishing the last one hides it again. What the client can supply is still the owner's to ask (reminder 20).

Addresses: `/{store}/{locale}/projects`, `/{store}/{locale}/projects/{slug}`.

### 1.14 SEO (round 1 #15, round 2)

- **On every page type: title, description and share image per language, with defaults.** Defaults per store: site name, a title pattern (`:title | :site`), a description, a share image. Blank fields use the item's own title, summary and picture. **Products, categories and brands take theirs from their own name, description and first photo, with no boxes, at launch** (Q14 (b), owner 2026-11-10).
- **Canonical and hreflang** on every page (the old site had none). Home and Content's pages give `StorefrontLayout` the fields of Catalog's `ShopAddressData` (canonical, one alternate, noindex) through a data class of Content's own, since Catalog's is in its Presentation. Both languages of a page name each other. **Only the home pages name each other across stores** (Q21 (a), owner 2026-11-10): each on store's home lists the others' homes as alternates, by language and the store's country (`ar-SA`, `ar-AE`, `en-EG` and so on). **`/` with no remembered store sends the visitor, and Google, on to the base store's Arabic home** with a temporary redirect (302), whose canonical is itself; a remembered store goes to it, as today; a shopper changes store with the header's country switch (Q20 (a), owner 2026-11-10; replaces handoff §4.1's country page at `/`).
- **Structured data** is not in the sources: not built.
- **The sitemap, per store**: `/sitemap.xml` is an index of each on store's `/{store}/sitemap.xml`, each listing home, categories, brands, products that are listed, pages, posts and projects, in both languages with alternates and last-changed date; split at 50,000 addresses. Rebuilt nightly and on demand from the SEO screen, stored as a file, never made in a shopper's request. Not-available and noindex pages are left out.
- **Robots.** Crawlers read one robots file per domain, so "robots per store" is **one generated `/robots.txt` with each store's lines**: allowed paths, the sitemap index, and Disallow for `/admin`, cart, checkout, account, search, and any extra paths typed per store on the SEO screen. **`public/robots.txt` must go** (a static file wins over the route). **Anywhere but production it says Disallow: /** so staging is never indexed `[ACCEPTED 2026-11-10]`.
- **www and non-www.** Search Console shows both for the old site (170 of its top 1,000 rows are www; memory `touchwood-content-round1.md`). Exactly one host is canonical; the other answers 301 to the same path. **Without www**: `touchwoodksa.com` is canonical, and `www.` answers 301 (Q13 (a), owner 2026-11-10).

### 1.15 Redirects (rounds 1 #3, 2 #3-4, decisions after round 2)

A table managed by Super Admins, each line: old address, new address, **visit count, last visit date**. **Hand-checked sheet upload. No automatic matching, no old-address field in the product import.**

- **The old address** is a path on the domain, with no host: decoded, trailing slash trimmed; compared exactly; query string ignored and dropped `[ACCEPTED 2026-11-10]`. A path beginning with a store code that exists, or with a reserved path, is refused: the new site answers there. A single word is allowed (`/blog`, `/contact`).
- **The new address** is a full shop path, `/{store}/{locale}/...`, on a store that exists. It can't be a chain, as an old address never begins with a store code and a new one must.
- **It answers 301, and only when nothing else would answer**, so no shop page pays a query. There are **two places** a path can fail (§2.3 row L2), both of which ask the same registry: no route at all (`/flash-deals`, `/product/x`, whose second word isn't a language), and **a single word of 2 to 8 small letters** (`/blog`, `/contact`), which matches `{store}` and reaches `StoreWithoutLanguageController`'s unknown-store 404. The second path has already opened the shop's session; the redirect still writes nothing to it. An address on no line is an ordinary 404: no guessing, no forward to a category `[ACCEPTED 2026-11-10]`.
- **A trailing slash costs one hop first**: `public/.htaccess` forwards `/x/` to `/x` before Laravel sees it, so an old address indexed with a slash takes two 301s. Accepted; Google follows both.
- **What can go wrong after a line is written** `[ACCEPTED 2026-11-10]`: the new address's page is deleted, its slug changes (a second hop), its store goes off, or a store is later created whose code equals the old first word (the line can then no longer be reached). W11's **Check Addresses**, a queued job, asks each new address and marks the line: Fine, Forwards to (with **Use Final Address**), Not Found, Store Off. The unreachable case is marked by comparing with the store list on reading the page.
- **Counting** adds 1 and sets the date with one small write after the answer. The count can be reset line by line. Bots count too.
- **The sheet.** xlsx or csv (`openspout`, installed), two columns, **Old Address** and **New Address**, first row headings, 5,000 lines and 2 MB at most `[ACCEPTED 2026-11-10]`. Upload shows a page of what would happen, line by line (New, Same as Now, Change, Refused and why) and only **Apply Sheet** writes it. It never deletes a line. The owner's sheet stays parked until the final product list exists, and must be done before launch (reminder 18).
- **Launch.** This codebase takes the domain from day one, old code down (round 2 #2).

### 1.16 What is decided about the whole stage's order

In this order (Q19 (a), owner 2026-11-10): 1. Content, with the 11 slots, the home page and the frame. 2. The existing shop pages rebuilt to the design, a separate job with its own spec, building theme parts 6 to 9 into them. 3. The redirect sheet, filled once products are final, before launch. 4. **Last step of this stage: the owner and the builder create the actual themes in the panel.** One style for every flag, tag and badge (sales.md §9.2 #10, reminder 5) is settled in that step.

### 1.17 The shop pages this spec covers, and the rest

**Covered here:** the frame (§1.5), Home (§1.4), and Content's own pages: Pages, Blog (list, post, category), Projects (list, project), the campaign page (H7), sitemap, robots, redirects.

**A separate job, specified on its own: rebuilding the existing shop pages to the design**, after Content and before the themes (Q19 (a), owner 2026-11-10; §1.16). Theme parts 6 to 9 (flag and ribbon, badge, listing banner, cart message) sit on those pages, so that job builds their places in. Open points to carry to its spec:

| # | Point | State |
|---|---|---|
| 1 | Category and brand pages: the design's filter layout, phone filter sheets, grid and **technical list** view for contractors (round 3: build) | The design's list shows code and availability columns; **codes and stock counts stay off** (round 3 #5), so its columns need the owner's look: product, size, how sold, price |
| 2 | Product page: swipe gallery, sticky buy bar on phones, **photos square** (round 3) | Design is 4:3 on desktop; built is square, kept |
| 3 | Cart, order, B2B screens to the design | Design exists: `cat`, `pdp`, `cart`, `order`, `b2b` |
| 4 | Pages with **no design**: checkout, account, search, brand, wishlist | Derived from its look (frontend.md §2.1) |
| 5 | Must keep though the design omits them: canonical and noindex, Not available now, greyed sizes, Last pieces, how sold, sale percent, cart notices and coupon, next gift, returns, buy again, phone verification, company per store | design-compare |
| 6 | Dropped by the owner's rule: bundles, مختارات لك, save for later, share, price slider, availability filters, branches link, New and Offers pages, review translate, delivery-days line | design-compare |
| 7 | Cards: **add button, stock counts and codes OFF** (round 3 #5); prices keep the current font (round 3 #8) | Decided |
| 8 | Colours: keep `#02365e` and the current set (round 3 #7); cards' edge and page ground as built | Decided |
| 9 | Panel-wide (round 3 #10-16): the design's 15 shorter menu names, underlined tabs with counts everywhere, whole row opens an order, one-press language, the phone top bar (icon-only View Store, 44 px menu button, 20 px title, shadcn's breakpoint), wide tables as cards on phones, white ground and no "All operational" | Decided, not built; its own job. **ops.md §4.15's "tables scroll" is replaced for new screens** |
| 10 | Brevo (round 3 #17-20): a nightly read of recently changed contacts; queued list jobs last a day; bell alert for queued emails on a bad key or no credit; start-up rule kept as built | Decided, Ops' job (ops.md §9.6) |

---

## 2 · Contracts

Module to module: ids and values in, DTOs out; no permission is checked inside a contract, the calling use case checks its own.

### 2.1 What Content gives

| Contract or event | Says | State |
|---|---|---|
| `ContentApi::campaignForMessage(string $id): ?CampaignMessageDto` | **Kept exactly as Ops is built against it.** `$id` is a **run's** id (§1.7 #13); `{campaignId: the run, storeId: the run's store, name: the campaign's in both languages}`; null for an unknown id **and for a run not live** | Declared; `NoCampaignsYet` is deleted and the provider binds `ContentApiImpl` |
| `CampaignWentLive(eventId, campaignId, storeId, noticeId, occurredAt)` | Unchanged. One per run, once. `campaignId` the run's id, `noticeId` the run's notice or null | Declared, §6 |
| `CampaignExpiring(eventId, campaignId, storeId, endsAt, occurredAt)` | Unchanged. Up to two per run, §1.7 #11 | Declared, §6 |
| **New** port `Content\Public\Contracts\CampaignNotices` | What the campaign's page lists: `drafts(StoreId, ?string $after, int $limit)` and `named(StoreId, list<string> $ids)`, each `NoticeChoiceDto{id, titleAr, titleEn, status}`. **Declared by Content, bound by Ops**, as Access's `SecurityMessages` is, so nothing depends on Ops (handoff §4.4). Content's default answers "none" | Row O1 |

### 2.2 The translator (round 4 #6)

`Modules\Platform\Public\Contracts\TextTranslator`, **in Platform so Feedback F2 and F5 can bind to it later** without depending on Content:

- `isReady(): bool`: a token is set.
- `translate(list<string> $texts, string $from, string $to, bool $keepMarks = false): list<string>`: the same count back, in the same order. Throws `TranslationFailed` with `reason`: `QUOTA`, `BUSY`, `REFUSED`, `UNREACHABLE`; never the service's text or the token.

Adapters, chosen by configuration: `DeepLTextTranslator` over **`deeplcom/deepl-php`** (v1.21.0, Packagist, read 2026-11-09; **not yet in `composer.json`**), `FakeTextTranslator` for development and tests. The token is the server's: **`DEEPL_API_KEY`**, in `.env` only (CONVENTIONS), held in the owner's hands. A `SensitiveParameter`. Texts go in calls under the service's 128 KiB limit (read 2026-11-09); §1.3's cap keeps a press to one call.

**Verified on the real account** (2026-11-10, the owner's key, §1.3): Arabic is accepted as a source, `en-US` as the target, and `<b>` is kept in Arabic with `tag_handling` `xml`. `$keepMarks` means the texts are XML whose tags must be kept. Not tried live: a link's tag, and the account's full language list (`GET /v3/languages`). **The English is American** (Q15 (a), owner 2026-11-10): the account's American code, whichever its language list gives.

### 2.3 What Content needs from other modules

Each row is an amendment to that module's spec; the module's builder is asked first (owner, 2026-10-28). Numbers in §9.4.

| Row | Module | What | State |
|---|---|---|---|
| L1 | Platform | `TextTranslator` and its adapters (§2.2) | New; **built by the Content session** (reminder 8) |
| L2 | Platform | **An address nobody answers.** A registry, `UnmatchedAddresses::register(class)`: for a GET, Content's redirect lookup answers `?RedirectResponse`. **Asked from two places**: the 404 for a path that matched no route (`/flash-deals`; `/product/x`, as `{locale}` is only a language), and **`StoreWithoutLanguageController`'s unknown-store branch**, since a single word of 2 to 8 small letters matches `{store}` (`InMemoryReservedPaths::routePattern`; `/blog`, `/contact`). No store context. The second place has the shop's session open already; the lookup uses none of it | New |
| L3 | Platform | A `content` group in `InMemoryAdminMenu::GROUPS`, and icons in `MenuIcon` | New |
| L4 | Platform | `StoreHomeController` and `Platform/Storefront/Home.tsx` hand `storefront.home` to Content. Its placeholder comment says so | New |
| L5 | Platform | One canonical host: a request on the other host answers 301 to the same path (§1.14) | New |
| L6 | Platform (framework glue) | `StorefrontArea::MIDDLEWARE` gains Content's `content.storefront-theme` after `STORE` (§1.8); `public/robots.txt` removed. **`ReservedPaths` needs nothing**: `/sitemap.xml` and `/robots.txt` hold a dot, so they never match `{store}` | New |
| L7 | Platform | Exists, used as they are: stores, `setting`, `mediaUrlsOf`, `mediaOf`, `uploadMediaFor`, `MediaUsages`, `recordAudit`, `AdminMenu`, `MenuCount`, `ShopFrameParts`, `StaffNames`, `StoreChoices`, the scheduler | On `main` |
| C1 | Catalog | **`ShopListingSlots`**, a registry as `ShopProductSections` is: `ShopListingSlot::read(ShopListingVisitDto{store, locale, kind, ?categoryId, categoryPath}): ?array`, shown above a category, brand or search list, for the smart bar and the listing banner | New; Catalog declares, Content registers. Asked outside the page's cache, a fixed few queries |
| C2 | Catalog | **Not owed**: product, category and brand heads stay Catalog's own defaults (Q14 (b)) | — |
| C3 | Catalog | **`sitemapEntries(StoreId, ?string $after, int $limit)`**: listed products, categories and brands, with both slugs and the last change | New |
| C4 | Catalog | **Top categories as tiles**: `shopCategoryTiles(StoreId, string $locale, int $limit)`, for the viewer as the menu is, with photo addresses. The shop's data doesn't carry the photo today (design-compare) | New |
| C5 | Catalog | Exists, used as they are: `ShopBestSellers`, `productCards`, `ShopCardFacts`, `ShopProductSections`, `searchProducts`, `activeCategories`, `activeBrands`, `categoriesWithPaths`, `brands`, `products`, `variants`, the card's price before and now | On `main` |
| C6 | Catalog | Content registers a **`CatalogUsage`** (§1.1 #17), nothing more: Q21 (a) asks no other store's alternate of Catalog's pages. `CatalogUsage` exists; a brand's or category's delete already asks it | Exists |
| P1 | Pricing | **`CampaignPrices`** (below). Replaces pricing.md §2.5's reserved line | New; reserved kind on `main` |
| O1 | Ops | A **draft** state for a notice: saved with no time, never sent by the scheduler, offered to a campaign; `StartCampaignNotice` starts a `DRAFT` as it starts a waiting one; Ops binds `CampaignNotices` | New; closes ops.md §9.5 #1, #4 |
| O2 | Ops | S8 goes to holders of **`content.campaign.manage`** (round 4 #11), replacing `ops.message.send_many` in `AlertStaff` | One line; closes §9.5 #2 |
| O3 | Ops | S8 links to the campaign: `/admin/content/campaigns/runs/{run}`, offered only to who may open it. **Optional** | Closes the "no link" note, §1.6 |
| X1 | Access | Declaring Content's permissions (`PermissionCatalog`), a **Content** group in `PermissionGroup` and `access::permission_groups` | New; arrow in A1 |

**`Pricing\Public\Contracts\CampaignPrices`**, written so Pricing's own spec can take it:

| Method | Says |
|---|---|
| `preview(StoreId, CampaignPriceSetDto, DateTimeImmutable $start, DateTimeImmutable $end, ?string $after): CampaignPreviewDto` | What it would do: each size's new price, the sizes skipped and why, the other offers on them in those dates, deepest reduction. Paged 20 products at a time, totals for all |
| `save(StoreId, string $runId, CampaignPriceSetDto, $start, $end): void` | Called only at Schedule (§1.7 #14). Writes or replaces the run's prices and their candidates of kind `CAMPAIGN`, inside the caller's transaction, under that one store's pricing lock (§1.7 #15), timed tasks at start and end |
| `changeEnd(StoreId, string $runId, DateTimeImmutable $end): void` · `endNow(StoreId, string $runId): void` · `remove(StoreId, string $runId): void` | The run's life, §1.7 #8, #12 |
| `read(StoreId, string $runId): ?CampaignPriceSetDto` · `productIds(StoreId, string $runId, ?string $after, int $limit)` | The page's form; the shop's campaign page (H7) |
| `skippedSizes(StoreId, string $runId): list<string>` | The sizes the run skips now because a provider's price would take it past the ceiling (§1.7 #17), for W4b; one query (the build coordinator's pick, 2026-11-10) |

`CampaignPriceSetDto`: `lines` (`RETAIL`, `WHOLESALE`, `BOTH`; §1.7 #4) · `targets`: each `{kind: PRODUCT, SIZE, CATEGORY or BRAND, id, percentBp}` or `{kind: SIZE, id, amount: Money}` · `leftOut` product ids. **Pricing owes, in all:**

- resolution's new candidate (pricing.md §1.6 step 2), and the place among equal prices, already in code as `SALE`, `CATEGORY`, `CAMPAIGN`, `QUANTITY`, `BASE` (`PriceResolution::RANK`, "decided then"): kept `[ACCEPTED 2026-11-10]`;
- **`CandidateWriter::refresh` rebuilding campaign candidates**: it writes only sale and category candidates today, so a retail price change would leave a percentage campaign's price stale;
- **the reads that name offers**: `DatabasePriceCandidates` filters to `SALE` and `CATEGORY` (`reducedAt`, used for On Sale Now), and `OverlapsFor` and the previews must see campaigns;
- **`CeilingCheck` and its two guards extended to campaigns** (Q2 (a); pricing.md §1.12): it covers sales and discounts only today; a retail price or a lower ceiling that takes a campaign past it is held back;
- **what a product joining or leaving a category or brand does** (Q18 (a): the campaign follows), through `ProductChanged` and `CategoryMoved` as for category discounts; a brand's products from Catalog (a new read);
- an archived size kept out of the candidates, as today's;
- the provider's price against a campaign in a wired store: the campaign skips the size and staff are told (§1.7 #17; §9.4 A6, A12).

---

## 3 · Use cases and permissions

A new **Content** group in the role editor (round 1 #16). Per store, any role. Roles nest (super admin includes admin includes staff). Every use case in a store that is off is refused to anyone who may not switch stores (§7).

| Use case | Permission | Scope |
|---|---|---|
| Home sections: add, remove, order, show or hide, fill · the footer | **`content.homepage.manage`** (الصفحة الرئيسية) | That store |
| Announcement bar · smart bars | **`content.bar.manage`** | That store |
| Pages: create, change, publish, unpublish, delete | **`content.page.manage`** | That store |
| Banners | **`content.banner.manage`** | That store |
| Campaigns: create, change, schedule, cancel, end; choose a notice; link a theme | **`content.campaign.manage`**; **shared fields: in every store of the campaign** | The run's store |
| A campaign's prices | `content.campaign.manage` **and** Pricing's `pricing.price.edit` | That store |
| Pop-ups | **`content.popup.manage`** | That store |
| Blog posts and categories | **`content.blog.manage`** | That store |
| Projects | **`content.project.manage`** | That store |
| SEO defaults, robots lines, rebuild sitemap | **`content.seo.manage`** | That store |
| **Translate** | Whichever permission edits the item being typed | That store |
| Themes: create, change, schedule, end, delete, preview | **`content.theme.manage`**, reserved: Super Admins | Every store |
| Redirects: list, add, change, delete, upload | **`content.redirect.manage`**, reserved, store-free: Super Admins | Global |
| `AdvanceCampaignsJob`, `BuildSitemapsJob`, `CheckRedirectsJob` | System, reserved: `content.campaigns.advance`, `content.sitemap.build`, `content.redirects.check`. Each named at `content::jobs` (the failed jobs screen requires it) | |
| Reading the shop's pages | None | Guests included |

S8's audience is `content.campaign.manage` (round 4 #11). A staff member's menu shows an entry only for a job held somewhere (`storesWith`); every handler checks again. Presentation never asks the authorizer: a `ContentActionsForReader` query answers what each screen may offer (`AccessDecisionsTest`).

---

## 4 · State machines and the screens

### 4.1 State machines

**A page, post or project:** `DRAFT` ⇄ `PUBLISHED` (Publish, Unpublish). Publish needs both languages. Deletable only as a draft.

**A banner, pop-up, theme:** `DRAFT` → (Schedule, both languages) → `SCHEDULED` → (its start) → `LIVE` → (its end, or End Now) → `ENDED`. A scheduled one may go back to draft (Back to Draft). `SCHEDULED`, `LIVE` and `ENDED` are read from the dates and the clock, never stored, as a sale's are (pricing.md §4.1).

**A campaign's run:** `DRAFT` → `SCHEDULED` → `LIVE` (by the job's conditional update, §1.7 #9) → `ENDED`; `SCHEDULED` → `CANCELLED`. The campaign's own badge is its runs': one state if all agree, else **Mixed** with each store's beside it. What may change while mixed is §1.7 #8.

**A redirect:** exists or not. **A sheet upload:** `CHECKED` → `APPLIED`, or dropped after 24 hours `[ACCEPTED 2026-11-10]`.

Badges, words as well as colour: **Draft** gray مسودة · **Published** green منشور · **Scheduled** blue مجدول · **Live** green يعمل الآن · **Ended** gray منتهٍ · **Cancelled** gray ملغى.

### 4.2 What every Content screen keeps

- **The frame**: `AdminLayout`, title, one-line subtitle, one main action, the rest in a row's `RowMenu` with destructive items last; `StoreFilter` (drawn with two or more stores; opens on the first store that is on; off stores marked for whoever may switch them).
- **Tabs**: underlined, each with its count (round 3 #11). shadcn `Tabs`.
- **Lists**: shadcn `Table` in `material-base`, the **whole row opens the item**; on a phone (under 640) **each row is a card** (round 3 #15): shadcn `Item`, title, badge and dates, its menu at the end. Show More (`LoadMoreButton`, 50, keyset), never a page number.
- **Forms**: their own pages, not dialogs, for anything long; `useLeaveGuard` asks before leaving with changes; `SaveBar` for groups; every box checked as typed (`use-checks`) and again on the server; refusals under their box, others at the top (`FormError`).
- **Two languages**: Arabic and English side by side, stacked on a phone (ops.md Q11), each in its own direction, with Translate (§1.3).
- **Dates**: `DateTimeField`, store's zone named, Now and No End as in pricing.md §4.3.1.
- **Images**: an upload box (a shadcn `Button` and the file picker), thumbnail, name, size note (§1.6), Remove. shadcn `Progress` while uploading.
- **Ordering**: `SortableList` / `SortableGroups` drag handles, with Move to Top and Move to Bottom in the menu.
- **Confirm**: `DestructiveActionDialog` or `PanelDialog`, a button repeating the title's verb.
- **Toasts** (`Toasts`): "{Noun} saved", published, scheduled, deleted... no period.
- **Phones**: every page fits 375 x 812 with no sideways page scroll, and every browser test checks it after the page settles (`browserSoon`). Touch targets 24 px at least; primary controls 44 px on the shop.
- **Query targets** (owner, 2026-10-19: reduce toward, record when over): admin page 15 of its own; JavaScript 60 KB a page, the editor's preview loaded on demand.

### 4.3 The menu

A new section after Marketing. **One section, Content, holds all eleven** (Q12 (a), owner 2026-11-10). Icon `content` (Lucide's `LayoutTemplate`).

| # | Entry | Address | Offered for |
|---|---|---|---|
| 10 | Home Page · الصفحة الرئيسية | `/admin/content/home` | `content.homepage.manage` |
| 20 | Bars · الأشرطة | `/admin/content/bars` | `content.bar.manage` |
| 30 | Banners · البنرات | `/admin/content/banners` | `content.banner.manage` |
| 40 | Campaigns · الحملات | `/admin/content/campaigns` | `content.campaign.manage` |
| 50 | Themes · المظاهر | `/admin/content/themes` | `content.theme.manage` |
| 60 | Pop-Ups · النوافذ المنبثقة | `/admin/content/popups` | `content.popup.manage` |
| 70 | Pages · الصفحات | `/admin/content/pages` | `content.page.manage` |
| 80 | Blog · المدونة | `/admin/content/blog` | `content.blog.manage` |
| 90 | Projects · المشاريع | `/admin/content/projects` | `content.project.manage` |
| 100 | SEO · تحسين الظهور | `/admin/content/seo` | `content.seo.manage` |
| 110 | Redirects · التحويلات | `/admin/content/redirects` | `content.redirect.manage` |

Routes `content.admin.*` in `Presentation/admin-routes.php` (Ziggy, deptrac `AppHttp`), pages under `resources/js/pages/Content/Admin/`, words in `src/Modules/Content/Presentation/lang/{ar,en}/`.

### 4.4 The admin screens

#### W1 · Home Page, `/admin/content/home?store=`

"What this store's home page shows, from top to bottom." Tabs **Sections** and **Footer** (`?tab=`), both under `content.homepage.manage`; the Footer tab is §1.5's fields, columns and links in `SortableList`. **Add Section** (main; a menu of the types not on the page; disabled "Every section is on the page." when none is left).

- **The list**: `SortableList` of sections, each an `Item`: drag handle, the type's name, a one-line summary ("3 banners live", "8 products"), a shadcn `Switch` Show (saves as flipped), Edit (opens its form), ⋯ Remove Section… Empty: "No sections yet." with Add Section (shadcn `Empty`).
- **Edit** is a page per type, `/admin/content/home/{section}`, with §1.4's fields and rules. `HERO` links to Banners for its artwork; `CLIENT_LOGOS` holds its list by dragging.
- **Preview** opens the shop's home (View Store's staff view). A section's unpublished English: a section with missing English can be saved but **Show is refused**: "Add the English first."
- Phone: the list one column, drag handle kept (touch sensors).

#### W2 · Bars, `/admin/content/bars?store=&tab=announcement|smart` (both tabs `content.bar.manage`)

- **Announcement Bar** (`content.bar.manage`): the switch, text per language, link, start, end, **Save Bar**, and a line while a theme dresses it: "The Ramadan theme dresses this bar until 2027-03-19." `[ACCEPTED 2026-11-10]`.
- **Smart Bars**: a list (Message · Shown On · State · Start · End), **Add Smart Bar**; a dialog: message per language, **Shown On** radios (A Category with `SearchCombobox`, A Campaign with a select of that store's scheduled and live runs), link, dates. Empty: "No smart bars yet."

#### W3 · Banners, `/admin/content/banners?store=&placement=`

Tabs by placement with counts. Columns: thumbnail · Name · Shown On · State · Start · End · Order. **Add Banner**, its page: placement, name (staff only, 100), target (`LISTING`: All Lists or a category), the words tick, the images with size notes, link, description, dates, **Schedule Banner** or **Save Draft**. Row menu: Edit, End Now…, Back to Draft, Delete Banner… "Live at once" limits (§1.6) are met by the Order column: a fourth live phone banner is allowed and the note says "Only the first 3 show." Scheduling waits for images to be ready (§1.1 #10). Empty: "No banners yet." · "Banners show on the home page and above product lists."

#### W4 · Campaigns, `/admin/content/campaigns?store=&tab=all|scheduled|live|ended`

Columns: Name · Stores (codes with each state) · Start · End · Theme ("—") · Offers (count of sizes reached) · Notice · State. **New Campaign**.

**W4b, a campaign's page** (`/admin/content/campaigns/{id}`, `/runs/{run}` lands here): four cards on one page, one column on a phone.

1. **Details**: name per language with Translate; stores (shadcn `Checkbox` list, only stores where the reader holds the job); Start, End, zone named (Q1 (a)); the 00:00 note (§1.7).
2. **Theme** (round 2 #7): a shadcn `Combobox`-pattern `SearchCombobox` of themes not ended, or None. Linked: the theme's name, dates, the 11 parts as a list with a filled mark, **Open Theme** for a Super Admin, and "Dates match" or "Dates differ" (Q6 (a)). No link: nothing else.
3. **Prices, one tab per store**: lines (Retail, Wholesale, Both); targets (`SearchCombobox` for products, sizes, categories, brands; each a row with its percent or, for a size, its price box in the store's currency, as pricing.md §4.3.1); left out; the live preview (Pricing's, `Spinner` while refreshing, its last result dimmed). The ceiling line as Pricing's screens have it. Reader lacking `pricing.price.edit`: read-only and the line in §1.7 #5. That store's run live: read-only with "Prices are fixed while a campaign runs. End it, or change its end." A draft's choices are saved in Content and reach Pricing only at Schedule (§1.7 #14). In a wired store, the sizes the run skips now are listed under the preview (`CampaignPrices::skippedSizes`; §1.7 #17).
4. **Notice, one row per store**: `NativeSelect` of that store's draft notices (`CampaignNotices::drafts`), or None; words "Sent once when this campaign goes live in this store. It can't be recalled." and a link to Offer Notices for who holds it. No draft: "No draft notices in this store." A notice since sent or cancelled shows that, in red `Note`.

**Main action**: Save Draft, then **Schedule Campaign** (asks for English, and the tick of §1.3). Live: **Save Changes**. ⋯ **End Campaign…** live; **Cancel Campaign…** scheduled; each asks All Stores or one store, naming them: "Offers stop at once in Saudi Arabia." Delete only a draft.

Empty: "No campaigns yet." · "A campaign sets offers for a time, in one or more stores."

#### W5 · Themes, `/admin/content/themes`, Super Admins alone

Columns: Name · Stores · Start · End · Parts ("9 of 11") · State. **New Theme**. Empty: "No themes yet." · "A theme dresses a store's shop for a time."

**W5b, a theme's page**: Name per language, stores, dates; then the **11 parts as `Collapsible` rows in §1.8's order**, each with its fields and size notes and a filled mark; beside the form, on desktop, the part drawn over a small default-and-theme comparison at phone and desktop width, under it on a phone. **Preview Theme** (a signed cookie for this browser, opening the store's home in the staff view with the theme on, reading past the frame's cache, `noindex` and `no-store`, shown to nobody else, a line "Previewing Ramadan." and **Stop Preview**) `[ACCEPTED 2026-11-10]`. **Schedule Theme** asks for the clash check; refusal as §1.8 under the dates. A linked campaign shows here read-only. ⋯ End Now… live; Back to Draft scheduled; Delete Theme… draft.

**Editing a live theme** is allowed in full: it shows at the next page `[ACCEPTED 2026-11-10]`.

#### W6 · Pop-Ups, `/admin/content/popups?store=`

Columns: Name · State · Start · End. **New Pop-Up** (own page, §1.10's fields, a Preview that draws it at phone and desktop size). A clash refused under the dates. Empty: "No pop-ups yet."

#### W7 · Pages, `/admin/content/pages?store=&tab=`

Tabs Published, Draft. Columns: Title · Address · State · Changed · By. **New Page**, its page: title, slug (made from the title until touched; shows the whole address), body (§1.2), SEO (a `Collapsible`, title 70, description 160, share image; each with its count of characters and the default shown as the placeholder). **Publish Page** / **Save Draft**. Published: Save Changes; ⋯ Unpublish Page…; Delete Page… draft only. Empty: "No pages yet."

A changed slug says: "The old address will forward here." Refusals: slug taken in this store ("That address is used by another page.").

#### W8 · Blog, `/admin/content/blog?store=&tab=posts|categories`

Posts: Title · Category · State · Published · Changed; filter by category. Its page as W7 plus summary, cover and description, category, linked products (`SearchCombobox` of ready products, `SortableList`, at most 8). **Publish Post**. Categories: `SortableGroups`-style list with **Add Category**, dialog with name and slug per language. Delete refused while it holds posts: "Move its posts first." Empty: "No posts yet."

#### W9 · Projects, `/admin/content/projects?store=`

As W7, plus city per language, photos (`SortableList` grid, at most 20), products. A `Note` while none is published: "The Projects page stays hidden until the first project is published."

#### W10 · SEO, `/admin/content/seo?store=`

Defaults (§1.14): site name, title pattern with the sample rendered, description, share image, per language. Robots lines (a `Textarea`, one path a line, checked as a path). **Sitemap**: last built (`Time`), address count, a `CopyButton` of its address, **Rebuild Sitemap** ("Queued"; disabled while one waits). Google Search Console isn't touched by the system.

#### W11 · Redirects, `/admin/content/redirects?q=`

A search, then columns: Old Address (mono, `MiddleTruncate`) · New Address · Visits · Last Visit (`Time`, "—") · Check (Fine, Forwards, Not Found, Store Off, Can't Be Reached; "—" until checked) · ⋯ Change… Reset Count… Use Final Address… Delete Line…. **Check Addresses** in ⋯ of the page ("Queued"; disabled while one waits). Sorted by most visited `[ACCEPTED 2026-11-10]`. **Upload Sheet** (main) to W11b; ⋯ Add Line… a dialog of two boxes. Empty: "No redirects yet." · "Forward old addresses to new ones."

**W11b, the checked sheet**: counts per result as tabs (New 120 · Same 3 · Change 2 · Refused 5), line rows with the reason, **Apply Sheet** asks "Write 122 lines?". A sheet over the line or size limit (5,000 lines, 2 MB), without the headings, or not xlsx or csv is refused whole with the words.

### 4.5 The shop's pages

All under `StorefrontLayout`, with its frame, canonical and alternate (§1.14), Arabic right to left, Latin digits, tokens only, phone at 375 x 812, the design's section spacing.

| # | Page | Address | Notes |
|---|---|---|---|
| H1 | Home | `/{store}/{locale}` (`storefront.home`) | §1.4. Target 8 queries; the frame, menu, payload from cache and best sellers are about 10 by count from the code, recorded per the owner's rule (2026-10-19). First image `fetchpriority`-high, not lazy |
| H2 | A page | `.../pages/{slug}` | Title, body. 404 for a draft; an old or other-language slug forwards only when the page answers 200 (§1.1 #15) |
| H3 | Blog | `.../blog` | Cards, cover, category chips, 12 then Show More. "No posts yet." hides the link to it `[ACCEPTED 2026-11-10]` |
| H4 | A post | `.../blog/{slug}` | Cover, title, date (Latin digits), body, linked products as cards |
| H5 | Projects | `.../projects` | Hidden per §1.13. Cards of photo, title, city |
| H6 | A project | `.../projects/{slug}` | Photo gallery (swipe on a phone), story, products |
| H7 | A campaign | `.../campaigns/{slug}` | Products of the run as cards (Q5 (a), the one exception to the Offers page the owner dropped), Show More; 404 before go-live and 301 to home after the end `[ACCEPTED 2026-11-10]` |

Not found: the shop's own 404 page in its frame (frontend.md §2.6). Scroll positions and Show More as frontend.md's "Going Back" rule.

---

## 5 · Tables

Schema `content` (added to `search_path` in `config/database.php`, or `migrate:fresh` won't clear it: CONVENTIONS). Store-scoped models use `BelongsToStore`. ULID keys. Each translated pair in the two-column form unless named. Every CHECK has a code rule first.

| Table | Columns |
|---|---|
| `content.home_sections` | `store_id` FK RESTRICT · `type` CHECK (§1.4) · `position` · `is_visible` · `settings` jsonb (words only, no media) · `version` · unique (`store_id`, `type`) |
| `content.home_section_media` | `section_id` CASCADE · `slot` · `position` · `media_id` FK `platform.media` RESTRICT |
| `content.bars` | `store_id` PK · `text_ar`, `text_en` · `link` · `is_on` · `starts_at`, `ends_at` NULL · `version` |
| `content.footers` | `store_id` PK · about, phone, email, address, copyright per language · `columns` jsonb (words and links) · `version` |
| `content.banners` | `store_id` · `placement` CHECK · `category_id` NULL FK `catalog.categories` RESTRICT (Content's `CatalogUsage` refuses the delete first) · `name` · `link` · `has_words` · `alt_ar`, `alt_en` · `is_scheduled` · `starts_at`, `ends_at` NULL · `position` · `version` |
| `content.banner_images` | (`banner_id` CASCADE, `kind` CHECK (`DESKTOP`, `PHONE`), `locale` CHECK (`any`, `ar`, `en`)) PK · `media_id` RESTRICT |
| `content.campaigns` | `name_ar`, `name_en` · `starts_at_local`, `ends_at_local` as typed (Q1 (a)) · `theme_id` NULL FK RESTRICT (a theme is deleted only as a draft, and only unlinked) · `version` · `created_by` |
| `content.campaign_runs` | `id` (the id events carry) · `campaign_id` CASCADE · `store_id` RESTRICT · `state` CHECK (`DRAFT`, `SCHEDULED`, `LIVE`, `ENDED`, `CANCELLED`) · `starts_at`, `ends_at` CHECK end after start · `price_choices` jsonb NULL (a draft's choices, ids and amounts as typed, no media; cleared at Schedule, §1.7 #14) · `slug_ar`, `slug_en` NULL (H7), unique per store · `notice_id` NULL (no key: Ops') · `went_live_at`, `ended_at`, `cancelled_at` NULL · `warned_72_at`, `warned_24_at` NULL (§1.7 #11) · unique (`campaign_id`, `store_id`) · a partial unique index on `notice_id` where state in (`DRAFT`, `SCHEDULED`) · index (`state`, `starts_at`), (`state`, `ends_at`) |
| `content.themes` | `slug` · `name_ar`, `name_en` · `is_scheduled` · `starts_at_local`, `ends_at_local` as typed (Q1 (a)) · `version` · `created_by` |
| `content.theme_stores` | (`theme_id` CASCADE, `store_id` RESTRICT) PK · `starts_at`, `ends_at` (that store's moments). Overlap checked in code per store, under the store's lock |
| `content.theme_parts` | (`theme_id` CASCADE, `part` CHECK (the 11)) PK · `text_ar`, `text_en`, `link` NULL · `extras` jsonb (words) |
| `content.theme_part_images` | (`theme_id`, `part`, `slot`, `locale`) PK · `media_id` RESTRICT |
| `content.smart_bars` | `store_id` · `scope` CHECK (`CATEGORY`, `CAMPAIGN`) · `category_id` NULL FK `catalog.categories` RESTRICT · `run_id` NULL FK `content.campaign_runs` CASCADE · message per language · `link` · `starts_at`, `ends_at` NULL. Exactly one of the two |
| `content.popups` | `store_id` · `name` · title, text, button per language · `link` · `delay_seconds` CHECK 3-60 · `media_id` NULL RESTRICT · `is_scheduled` · `starts_at`, `ends_at` NULL · `version` |
| `content.pages` | `store_id` · `status` CHECK · `published_at` · `share_media_id` per language NULL RESTRICT · `version` · `created_by`, `updated_by` |
| `content.page_translations` | (`page_id` CASCADE, `locale`) PK · `title` · `slug` · `body` jsonb · `seo_title`, `seo_description` NULL · unique (`store_id`, `locale`, `slug`) through a denormalized `store_id` |
| `content.blog_categories` | `store_id` · name and slug per language · `position` |
| `content.blog_posts` | as `pages`, plus `category_id` NULL SET NULL · `cover_media_id` RESTRICT |
| `content.blog_post_translations` | as `page_translations`, plus `summary` · `cover_alt` |
| `content.blog_post_products` | (`post_id` CASCADE, `product_id`) PK · `position` |
| `content.projects`, `content.project_translations`, `content.project_photos`, `content.project_products` | as the blog's; `city` in the translation; photos with `media_id` RESTRICT and `position` |
| `content.page_images`, `content.post_images`, `content.project_images` | (owner id CASCADE, `locale`, `ref`) PK · `media_id` RESTRICT · `alt`. A row no document names is removed on save |
| `content.slug_history` | `store_id` · `kind` CHECK · `item_id` · `locale` · `slug` · unique (`store_id`, `kind`, `locale`, `slug`) |
| `content.seo_defaults` | `store_id` PK · per-language site name, title pattern, description · share image RESTRICT · `robots_extra` text |
| `content.sitemaps` | (`store_id`, `part`) PK · `file_key` · `urls` · `built_at`. File in private storage, served by the routes |
| `content.redirects` | `old_path` PK, trimmed and decoded (`COLLATE "C"`) · `new_path` · `visits` bigint default 0 · `last_visit_at` NULL · `source` CHECK (`HAND`, `SHEET`) · `check_result` NULL · `check_final_path` NULL · `checked_at` NULL · `created_by` · timestamps |
| `content.redirect_uploads` | `id` · `lines` jsonb (result per line) · `state` · `created_by` · `created_at`. Dropped after 24 hours |

Campaign prices are Pricing's own tables (§2.3).

---

## 6 · Events

| Published | When | For |
|---|---|---|
| `CampaignWentLive` | A run goes live (§1.7 #9), once, after commit, through the outbox | Ops: starts the notice |
| `CampaignExpiring` | §1.7 #11, up to twice a run, each a fresh `eventId` | Ops: S8 |

Both are unchanged from what Ops listens to. "Ids only, through the outbox, after commit."

| Consumed | From | Content |
|---|---|---|
| `StoreActivated`, `StoreDeactivated` | Platform | A sitemap rebuild; the frame's cache. `AdvanceCampaignsJob` reads whether a store is on at each tick, so §1.7 #9 and #11 need no listener |
| `ProductArchived`, `ProductMadeReady`, `StoreListingChanged` | Catalog | Queue a sitemap rebuild, at most one a day beyond the nightly `[ACCEPTED 2026-11-10]` |
| `MediaVariantsReady` | Platform | Clears a Content cache holding addresses of that file |

The placeholder comment in each event class ("nothing fires it yet") is removed.

---

## 7 · Errors

Every error extends `ContentError` → `DomainError`, type `content.*`, both languages at `content::errors.{snake}`.

| Error | Category | When |
|---|---|---|
| `InvalidContentInput` | INVALID | A box against its rule, naming the field and rule: lengths, a link that leaves the rules, a bad image type, a table line (with its line), a date, a start after an end |
| `MissingEnglish` | INVALID | Publish or Schedule with an English side empty, naming which |
| `SlugTaken` | CONFLICT | The slug is another item's, current or held by history |
| `StaleEdit` | CONFLICT | A save over a newer version, naming who and when |
| `ThemeClash` | CONFLICT | §1.8, naming the theme, the store and its dates |
| `PopupClash` | CONFLICT | §1.10, the same |
| `NoticeTaken` | CONFLICT | A notice another run holds |
| `NoticeNotDraft` | CONFLICT | A notice no longer a draft of this store (read through the port) |
| `CampaignNotChangeable` | CONFLICT | A change a run's state forbids (§1.7 #8, #12) |
| `CategoryNotEmpty` | CONFLICT | Deleting a blog category holding posts |
| `NotDeletable` | CONFLICT | Deleting something published, scheduled or live |
| `ImageNotReady` | CONFLICT | Publish or Schedule with an image whose sizes are pending or failed, naming it |
| `CampaignPricesNotAllowed` | FORBIDDEN | Scheduling a run with price choices, without `pricing.price.edit` in its store, naming the store |
| `RedirectInvalid` | INVALID | §1.15: a store's or reserved old address, a new address of no store |
| `SheetRefused` | INVALID | Wrong type, no headings, over 5,000 lines or 2 MB |
| `TranslationUnavailable` | CONFLICT | The token isn't set, or `TranslationFailed` with its reason's words (§1.3) |
| `StoreOff` | FORBIDDEN | Any use case in an off store, by someone who may not switch stores |
| `ContentNotFound` | NOT_FOUND | An unknown id, or one of a store the reader holds nothing in: the same answer |

Pricing's and Platform's refusals reach the person in their own words (`pricing.over_ceiling` named per size). A permission missing is `shared.unauthorized`.

---

## 8 · Test scenarios

1. **Languages**: Arabic required to save; English required to publish or schedule, for every kind; where there is no Publish step (footer, smart bar, SEO defaults, blog category, the bar switched on, a section shown, a theme part with one side filled); English blanked on something live refused; digits saved 0-9.
2. **Text format**: each block both ways, typed to stored and back unchanged; no HTML survives, `<script>` shows as text; `javascript:` refused; a missing image ref refused; a table's columns; the shop draws only from blocks.
3. **Translate**: fills only the box asked; never saves; a non-empty box asks; rich text block by block, structure intact, marks by the build's outcome; each failure kept the text and said its words; the token in no log; no token, disabled with its reason; the tick required while a mark stays, removed by typing; over 20,000 characters nothing sent, Translate All counted as one press; a not-yet-saved item checked by kind and store.
4. **Home**: sections per store, ordered, hidden, each once; empty sections draw nothing; projects hidden until one is published; a banner starts and ends by the clock through a warm cache; query counts recorded.
5. **Bar and frame**: one bar, the theme dressing it, off with no words draws nothing; footer per store; must-keep frame items still drawn; a bar and pop-up start and end by the clock through a warm cache; a change invalidates it.
6. **Banners**: each image rule per placement and language; the live limit by order; a live theme takes one of the 3 phone places; picture element per width.
7. **Campaigns**: multi-store runs with one id each; prices need both permissions; shared fields need every store; live is read-only but for end and name; half live: start, stores and theme locked, a new end reaches scheduled and live runs, an ended run stays ended; go live once per run however often the job runs, two jobs or a retry included; **Cancel or End racing go-live: one wins, the other is told**; a run of an off store waits and goes live if switched on before the end (Q17 (a)); a live run whose store goes off stays live, no alerts; cancel removes prices and fires nothing; a failed job's retry carries on.
8. **Prices through Pricing**: a draft writes nothing to Pricing and costs no shopper a price; Schedule writes, one store's lock at a time, in store order, a refused store leaving the rest; product, size, category, brand, percent and fixed; lowest wins; many campaigns at once; a retail price change re-prices a percentage campaign; the ceiling refusing a deeper campaign, Retail, Wholesale or Both, "always wins" beating a campaign, a moved product following its category or brand; candidates at the second; an archived size skipped; a campaign with no prices schedules; a scheduler lacking Pricing's permission refused for that store.
   A wired store: a provider's price past the ceiling applies, the campaign skips the size, `CAMPAIGN_SKIPPED` opens and S4 goes to `sync.report.view` holders; the size rejoins when the price allows; W4b lists the skipped sizes from `CampaignPrices::skippedSizes`; the entry's closing as sync.md amendment 2 (b) settles it.
9. **Alerts, one test per row of §1.7 #11's table**: 3 days or more, exactly 3 days; 24 hours to under 3 days, exactly 24 hours; under 24 hours; went live late; store off at the moment; end moved with the moment ahead again, passed and sent, passed and unsent, no longer qualifying; ended or cancelled. Two events, two alerts in Ops; **`campaignForMessage` answers null once the run isn't live**, and with the run's store, so a two-store campaign alerts each store.
10. **Notices**: only that store's drafts are offered; one notice one run; `noticeId` on the event; cancelled before go-live clears the tie and leaves the draft; scheduler never sends a draft (Ops).
11. **Themes**: Super Admin only; 11 parts each fill and empty; a clash refused per store, naming; under a race, one wins; preview only for its presser, past the cache, `noindex`; `theme.style` holds only server-built values (a typed `</style>` never appears) and no original file; transparent parts WebP only; **backdrops in light and dark, left and right to left**; an image not ready can't be scheduled, one that fails later draws as empty; a store off and back inside the dates shows it again; logo and fixed parts unchanged by any theme; the panel and error pages stay base; `HandleInertiaRequests` knows no module.
12. **Smart bar**: nearest first, campaign's while live.
13. **Pop-up**: once per visitor per pop-up; delay; storage refused means none; sheet on a phone, no image, fits at 375 x 812; nothing on checkout or for staff view; a clash refused; live when its store goes off: gone, back inside its dates.
14. **Pages, blog, projects**: publish at once; an old and an other-language slug 301 in one hop only when the page answers 200, a draft's 404; delete rules; the warning listing Content's links; a stale edit refused with the text kept; linked products vanish when no longer sold, marked in the panel; detaching a draft's body image removes its block; deleting an item removes its image rows.
15. **SEO**: defaults fill in; canonical and the other language on every page; `/` with no remembered store answering 302 to the base store's Arabic home, one remembered going to it; home pages naming the other stores' homes; sitemap index per store, leaves out off stores, drafts, noindex pages; robots generated, the static file gone; non-production says Disallow; the other host 301s.
16. **Redirects**: only an unmatched GET asks, **from both places: no route (`/flash-deals`, `/product/x`) and an unknown single word matching `{store}` (`/blog`, `/contact`)**; a real store's `/{code}` still redirects to its home; exact match; decoded Arabic; count and date; refusals; sheet previewed, applied only on Apply, never deletes; 5,000 line and 2 MB limits; Check Addresses marks a deleted target, a second hop, an off store and a store whose code took the line's word; no shop page's query count changes.
17. **Off store, isolation, permissions, audit**: each permission in its store only; an off store refused, its shop 404; a Super Admin prepares it; audit by value, long text as changed; both languages on every word.
18. **Media and references**: a file in live use blocks its delete; a draft's is detached; no media id in JSON (a guard); a category or brand in use refuses Catalog's delete, naming the use; an image not ready refuses Publish and Schedule.
19. **Phones and direction**: every screen and shop page at 375 x 812, no sideways scroll, both languages and themes.
20. **Boundaries**: deptrac green with only the arrows of §9.4 A1; `ContentAccessUseTest` holds Access to the permission classes, as Catalog's does.

**Not testable before a real account**: Search Console's reading of the new sitemap. DeepL's handling of Arabic and of bold was tested on the real account on 2026-11-10 (§1.3); a link's tag was not.

---

## 9 · Questions and picks

### 9.1 Answered already

Every answer is in `docs/handoff/content-rounds.md`, rounds 1 to 4 and the spec's questions of 2026-11-10, and in its place above. Ops' open points of §9.5 are settled here: #1 and #4 by row O1 and §1.7 #10, #2 by row O2 (round 4 #11). **#3 is not settled here**: S8's added words wait for approval with the full review of the words (reminder 16). §1.7 #11 only checks that those words stay true for two alerts.

### 9.2 The owner's questions, answered 2026-11-10

Asked as round 1 of this spec. The owner's answer, 2026-11-10: "Yes, all rec". Each question takes its recommended option, given in the Answer column and written into the body.

**Order of work**

| # | Question | Options, and what each does | Rec | Answer |
|---|---|---|---|---|
| Q19 | Four of the 11 theme parts (product flag and ribbon, product badge, listing banner, cart message) sit on the category, product and cart pages, which don't yet follow the new design. You wanted themes decided before building. In what order do we build? | **(a)** First Content with the home page and the shop's frame. Then the existing shop pages rebuilt to the design, with those four places built in. Then the real themes, last, with you. **(b)** The shop pages first, then Content, then themes: the home page, your biggest page, waits longest. **(c)** Content and themes now, the shop pages after launch: those four parts must be built twice or stay missing until then | (a) | (a), owner 2026-11-10 |

**Campaigns**

| # | Question | Options, and what each does | Rec | Answer |
|---|---|---|---|---|
| Q1 | A campaign or theme runs in Saudi Arabia and the UAE, whose clocks differ by an hour. When you type "starts 22 Sept at 00:00", what does it mean? | **(a)** 00:00 on each store's own clock: each store starts at its own midnight, one hour apart, and a campaign and theme with the same typed dates start together in each store. **(b)** One single moment for every store, read on the Saudi clock (Saudi is the system's main store): the UAE starts at 01:00 its time | (a): a shopper's midnight is what the date means | (a), owner 2026-11-10 |
| Q2 | Each store has a limit on how deep a discount can go (30% unless changed), which already refuses a sale or category discount deeper than it. Does it also limit a campaign? | **(a)** Yes, same rule: a campaign deeper than the limit is refused when saved, naming the products. To run 40% you raise the limit first. **(b)** No: a campaign may go deeper, and coupons and points simply find no room on top | (a): one rule for every reduction | (a), owner 2026-11-10 |
| Q3 | Does a campaign's price apply to wholesale orders too? Wholesale buyers pay a price that drops as the quantity rises. | **(a)** A choice on each campaign: Retail, Wholesale or Both, as category discounts have. A wholesale buyer pays whichever is lower, the campaign or their quantity price. **(b)** Retail only: companies buying in bulk keep their quantity prices | (a): staff already know this from category discounts | (a), owner 2026-11-10 |
| Q4 | A sale marked "Always wins while on" beats cheaper sales and discounts. You said lowest price wins for campaigns. If one of those sales runs on a size during a campaign? | **(a)** The "always wins" sale wins, even when the campaign is cheaper: the one exception to lowest price wins. **(b)** The campaign is treated like any price and the lowest wins, so "always wins" stops meaning always | (a): the mark then keeps its one meaning | (a), owner 2026-11-10 |
| Q5 | A campaign can discount 40 hand-picked products in different categories. Where does its banner send a shopper? | **(a)** A simple shop page per campaign listing its products, only while it runs. **This brings back something close to the Offers page you removed from the design**, though each one lasts only as long as its campaign. **(b)** No such page: banners link to a category, brand or page, and a hand-picked campaign has no single place to point to | (a), if you are happy to allow that exception | (a), owner 2026-11-10 |
| Q6 | A campaign linked to a theme. You said they start and end together "when the dates match". If they differ? | **(a)** The theme keeps its own dates. The campaign's page says, store by store, "Dates match" or "Dates differ", and a Super Admin has a button to use the campaign's dates. **(b)** Linking locks the theme to the campaign's dates: an admin moving a campaign then moves a theme only Super Admins may touch, and that move can clash with another theme | (a) | (a), owner 2026-11-10 |
| Q17 | A store is switched off at the moment its campaign should start. Should the campaign go live there? | **(a)** No, it waits: no notice, no ending alerts. If the store is switched on before the end, the campaign goes live then. **(b)** Yes, regardless: the system sends messages for switched-off stores too, so that store's customers get an offer notice for a shop they can't open | (a) | (a), owner 2026-11-10 |
| Q18 | A campaign discounts a whole category or brand. A product is moved into it, or out of it, while the campaign runs. | **(a)** The campaign follows: the product gains or loses the discount at once, as category discounts do today. Products picked by name stay as picked. **(b)** Fixed at the moment the campaign is scheduled, as sales are: a product added later gets nothing | (a) | (a), owner 2026-11-10 |

**Themes**

| # | Question | Options, and what each does | Rec | Answer |
|---|---|---|---|---|
| Q7 | Each of the 11 parts takes artwork and sometimes words. May a part also take a plain colour, say for a band with no picture? | **(a)** Artwork only: an empty part looks as the normal site. Simple, and the site's colours stay fixed. **(b)** Also a colour from the site's own colour list (Navy, Copper...): more ways to make a theme without an artist. **(c)** Any colour typed: risks unreadable text, since text colours are fixed | (a) | (a), owner 2026-11-10 |
| Q8 | The shop has light and dark modes. May a theme have different artwork for dark mode? | **(a)** One set of artwork for both, with the bands' ground softened in dark by a fixed tint. Half the uploads. **(b)** An optional dark version of each image. A better-looking dark mode, for double the work per theme | (a) for launch | (a), owner 2026-11-10 |
| Q9 | Which products wear the theme's flag, ribbon and badge? | **(a)** Every product showing a reduced price right now: the card already knows, no new work. An ordinary sale wears the flag too. **(b)** Only products priced by the campaign linked to the theme: exact, but needs new work, and a theme with no campaign shows nothing. **(c)** Every product in the store while the theme lives: loudest, and flags lose their meaning | (a) | (a), owner 2026-11-10 |

**Editing and structure**

| # | Question | Options, and what each does | Rec | Answer |
|---|---|---|---|---|
| Q10 | Pages and blog use marks you type, as product descriptions do, with the formatted result beside them. Neither shadcn nor Geist has an editor with buttons. | **(a)** The same box and preview, plus a small row of buttons (Bold, Link, Image, Table, List) that write the marks for you. Uses only their parts. **(b)** Marks only, exactly as products. **(c)** A true "what you see" editor: a large outside component, against your rule on Geist and shadcn, and it adds weight to those pages | (a) | (a), owner 2026-11-10 |
| Q11 | You chose a Content permission per store. Should pages, blog posts and projects also belong to one store? | **(a)** Yes, each store its own: each is typed for its store, and can differ. **(b)** A post picks its stores, as a campaign does: typed once. **Either way, the same text in two stores counts as a duplicate to Google**, unless it's rewritten, or one is marked as the main one | (a): it matches the permission, and Saudi has 80% of the clicks | (a), owner 2026-11-10 |
| Q12 | The panel's menu. Your agreed tree scatters these: Campaigns and Pop-Ups under Marketing, Blog on its own, Smart Bar under Products. | **(a)** One section, Content, holds all eleven. Staff who run the site's words find everything together, and the sidebar gains one row. **(b)** Keep the agreed tree: Campaigns, Pop-Ups and the Announcement Bar under Marketing, Blog its own, Smart Bar under Products, the rest under a small Content section | (a) | (a), owner 2026-11-10 |
| Q16 | The home page's Points band. The design's words state rates ("100 points = 5 SAR"). | **(a)** Staff type everything: free wording, but it goes stale if the rate changes in the Points settings. **(b)** Staff type the heading, and the figures are read from the Points settings: always true. Either way it hides when points are off | (b) | (b), owner 2026-11-10 |

**SEO and translation**

| # | Question | Options, and what each does | Rec | Answer |
|---|---|---|---|---|
| Q20 | Your old home page brings 30% of Google clicks. Today the new site's `touchwoodksa.com/` shows anyone with no remembered store, Google included, a page to choose a country. What should a first-time visitor or Google get there? | **(a)** They are sent on to the Saudi Arabic home, and that is the page Google lists; someone returning goes to the store they used. The header's country switch stays. An Egyptian on a first visit starts in the Saudi shop until they switch. This changes the handoff's rule. **(b)** Keep the country page, listed by Google: the old home's visitors land on a page of three links and press one more time. **(c)** Country page for people, hidden from Google: the site's main address leaves Google's results | (a) | (a), owner 2026-11-10 |
| Q21 | The same product exists at `/sa/ar/...` and `/ae/ar/...`, in the same language. Should we tell Google these are versions for different countries? | **(a)** Only for the home page: cheap, and it covers the biggest page. **(b)** Also every product, category and brand: complete, but each page must find which stores sell it, a little slower and more work. **(c)** Nowhere: Google decides for itself which to show | (a) at launch | (a), owner 2026-11-10 |
| Q13 | Should the site's address be with or without "www"? Google has both for the old site. | **(a)** Without (`touchwoodksa.com`): 830 of the old site's top 1,000 rows. **(b)** With www | (a) | (a), owner 2026-11-10 |
| Q14 | You chose SEO fields on every page type. Pages, posts and projects have boxes. For products, categories and brands? | **(a)** Also boxes, on an SEO screen where staff pick the item: more control over every product, more work, and a small addition to the product pages' code. **(b)** Automatic from name, description and first photo at launch, with the boxes later if wanted | (b) at launch: the top 100 pages are 92% of clicks, and boxes can be added later with no redo | (b), owner 2026-11-10 |
| Q15 | When Translate writes English, which English? | **(a)** American. **(b)** British. The site's words so far follow neither strictly | (a) | (a), owner 2026-11-10 |

### 9.3 The spec's own picks: accepted 2026-11-10

The owner was told "tell me any you'd reject; the rest stand" and rejected none (2026-11-10). So every pick below stands, marked `[ACCEPTED 2026-11-10]` where it sits in the text.

| # | Pick | Where | Why |
|---|---|---|---|
| 1 | A file in live use can't be deleted; a draft's can, and its body block goes with it | §1.1 #10 | A live banner shouldn't go blank |
| 2 | Version check on long edits | §1.1 #12 | Two people on one long text mustn't lose work |
| 3 | Long text audited as "changed" with its length | §1.1 #13 | The audit log is kept forever |
| 4 | Where there is no Publish step, both languages at the moment words reach a shopper | §1.1 #2 | The owner's rule applied to items without a button |
| 5 | Publish and Schedule wait for an image's sizes | §1.1 #10 | Nothing goes live blank |
| 6 | Unpublishing or deleting lists Content's links to it first | §1.1 #16 | A link is a path; a warning is cheap |
| 7 | Limits: 100,000 characters and 50 images a document; tables 2-8 columns, 1-100 rows; promises 2-4; categories 4-12; best sellers 4-20; logos 6-40; footer 3 columns of 8; linked products 8; project photos and products 20; redirect sheet 5,000 lines and 2 MB | §1.2, §1.4, §1.5, §1.12, §1.13, §1.15 | Numbers set by the design or well above any real use |
| 8 | The required "I read the translated English" tick | §1.3 | Your "nothing goes live unseen", as one tick |
| 9 | Translate All; caps: 20,000 characters a press, 15 seconds, no retry, 30 presses a minute, 300,000 characters a day per person | §1.3 | One call a press; protects the allowance |
| 10 | Hero: up to 3 short facts | §1.4 | The design's side card, without its dummy numbers |
| 11 | Projects section: newest project and 2 newest posts as guides | §1.4 | Nothing to choose; stays fresh |
| 12 | Each section once; a new store starts hidden | §1.4 | Fixed types; nothing unfinished goes out |
| 13 | Announcement bar: on or off, optional dates | §1.5 | The bar's message is often seasonal |
| 14 | Sizes the design doesn't give: listing banner, theme strips, flag, ribbon, edges, cover, photos | §1.6, §1.8 | Twice the shown size, within Platform's 2400; settled at the themes step with real art |
| 15 | Small images warned, not refused | §1.6 | Staff can still publish on a deadline |
| 16 | A campaign must have an end | §1.7 #1 | The alerts need one |
| 17 | Linking a theme needs only the campaign permission | §1.7 #7 | It changes nothing on the theme |
| 18 | A live campaign: only its name and end change; half-live rule: start, stores and theme lock once any run is live, an ended run isn't revived | §1.7 #8 | As a running sale; a multi-zone campaign is mixed for an hour |
| 19 | One notice serves one run | §1.7 #10 | A second go-live would send nothing |
| 20 | An end moved so an alert's moment is ahead again sends it again | §1.7 #11 | Staff told the true date |
| 21 | A campaign with no prices may be scheduled; scheduling a run with price choices needs the Pricing permission in that store | §1.7 #16 | Handoff's "theme only"; the owner's two permissions |
| 22 | Theme artwork through CSS: AVIF and WebP, the named sizes; transparent parts WebP only | §1.8 | Originals are never served; JPEG turns transparency white |
| 23 | Smart bars: optional dates on a category's; nearest wins | §1.9 | One bar above a list |
| 24 | A second overlapping pop-up refused, naming the clash (one at a time is your Round 1 #11) | §1.10 | As for themes |
| 25 | Pop-up delay 3 to 60 seconds, default 8 | §1.10 | "Delayed", not on entry |
| 26 | Seen pop-ups kept in the browser; storage refused means none | §1.10 | No server cost, nobody nagged |
| 27 | No pop-up on cart, checkout, account, sign-in, staff view; no image on a phone | §1.10 | Nothing between a customer and paying; it can't fit in 325 px |
| 28 | Staging says Disallow | §1.14 | Staging must never be indexed |
| 29 | Old address: query string dropped, no guess for a missing one | §1.15 | The sheet was chosen over automatic matching |
| 30 | Check Addresses marks lines gone stale | §1.15 | A deleted target or a new store silently breaks a line otherwise |
| 31 | Redirects sorted by most visited; upload page dropped after 24 hours | §4.1, W11 | What matters first; nothing stale waits |
| 32 | Pricing's tie order stays as coded (Sale, Category, Campaign) | §2.3 | Code already has it; costs nothing |
| 33 | Live theme may be edited; preview for the presser alone | W5b | Fix a typo mid-Ramadan; nobody else sees a draft |
| 34 | Blog link hidden while there is no post; campaign page 301s home after its end | §4.5 | No empty pages; no 404 from an old ad |
| 35 | Catalog changes rebuild the sitemap at most once a day beyond the nightly | §6 | Products change often |

### 9.4 Amendments other documents would need

Accepted with this spec (owner, 2026-11-10); each module's builder is still asked before its rows are built (owner, 2026-10-28). **Written into the documents on 2026-11-10**, each in its module's own style: handoff §0.1, §4.1, §4.4, §13.2, §14; platform.md §9.4; access.md amendment 97; catalog.md amendment 41; pricing.md §9.6; sync.md amendment 2; ops.md §9.6's answers and §9.7; sales.md §9.10; frontend.md §1.8, §2.3; `themes.css`'s header; promotions.md §9.2 #4. **A1's `deptrac.yaml` and A2's `config/database.php` change with the backend's first PR.**

| # | Where | Change |
|---|---|---|
| A1 | handoff §4.4, `deptrac.yaml` | Content's interior gains `AccessPublic` (to declare permissions only) and `AppHttp`, as Sync's has: `Content: [+ContentPublic, AccessPublic, AppHttp]`. The `Vendor` layer's regex gains `DeepL`. Handoff §13.2's `campaigns` shape updated: a theme is separate; runs per store |
| A2 | `config/database.php` | `content` added to `search_path` |
| A3 | platform.md; `InMemoryAdminMenu`, `StoreWithoutLanguageController`, the 404 path, `StoreHomeController`, `StorefrontArea`, `public/robots.txt` | Rows L1 to L6; `HandleInertiaRequests` and `ReservedPaths` are unchanged. **`ChooseStoreController` and handoff §4.1** (Q20 (a)): `/` with no remembered store answers 302 to the base store's Arabic home instead of showing the country page; with one, as today. **Open for PR 10**: whether the country page stays reachable at an address of its own (content.md says only that a shopper changes store with the header's country switch) |
| A4 | access.md | Row X1; the Content group in `PermissionGroup`, and the permission names in both languages |
| A5 | catalog.md | Rows C1, C3, C4 and C6 (C2 dropped with Q14 (b)); its cards and product page draw a theme's flag, ribbon and badge on every product showing a reduced price (Q9 (a)), its lists a listing slot |
| A6 | pricing.md | Row P1 and everything §2.3 lists as owed: `CampaignPrices`, its tables, §1.6 step 2, §2.5, §9.1 #4 closed, `CandidateWriter::refresh`, the offers reads, `CeilingCheck` extended to campaigns (Q2 (a)), a brand's products from Catalog, a category or brand target following its products (Q18 (a)). A provider's price past the ceiling makes the campaign skip that size (§1.7 #17), as a fixed-amount discount, and `skippedSizes` lists them for W4b. Pricing's campaign tables are written with its build. |
| A7 | ops.md, Ops' code | Rows O1 to O3. §1.7 and §9.5 rewritten: a `DRAFT` notice with no time; O5 and O6 offer "With a Campaign" as a fourth **When** and a Draft badge; S8's permission; §1.6's S8 row. Round 3's Brevo items are ops.md §9.6 #1 to #3 |
| A8 | sales.md, cart page | The cart draws `frame.content`'s cart message. No new arrow: it comes in the frame |
| A9 | frontend.md §1.8, `resources/css/themes.css`'s header | "Only colour, radius and shadow are a campaign's" replaced by "A theme is the 11 parts of content.md §1.8. It changes no colour, radius or shadow token; it sets only image properties, `--tw-backdrop` among them." `ThemeTokensTest` unchanged |
| A10 | promotions.md §9.2 #4, reminder 34 | Closed: no pop-up targeting at launch, no new arrow |
| A11 | handoff §14's tree, frontend.md §2.3 | One Content section in the menu (Q12 (a)); the storefront frame of §1.5 |
| A12 | sync.md | A new report kind **`CAMPAIGN_SKIPPED`** (§1.4, §1.6's table, the list that sends `SyncNeedsAttention`, §5's CHECK, §6): the provider's price made a campaign skip a size; it is meant to close when the size rejoins or the campaign ends, by a signal from Pricing still to be settled by the Pricing and Sync builders. **The campaign's page reads its skipped sizes from Pricing**, not Sync (`CampaignPrices::skippedSizes`, §2.3), so there is no Content → Sync arrow (the build coordinator's pick, 2026-11-10) |
