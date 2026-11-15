# The shop to the design

The job content.md §1.17 names: rebuilding the shop's existing pages to the design, "specified on its own".
It changes no module's rules of business: what each module sells, prices, counts and checks stays as its
spec says. It changes **how the shop looks**, and adds the few pieces of data the look needs (§10).

**State:** written 2026-11-16 from the owner's answers of 2026-11-15 and 2026-11-16 (two rounds). Not built.
Points marked **[PROPOSED Pn]** are the builder's picks, listed in §13 for the owner's review; every other
point is the owner's answer.

**Sources:**
- **The design:** the owner's Claude Design project "TouchWood E-Commerce UI Design" (Home, Screens: the
  category, product and cart pages, Foundations, Mobile Options), compared screen by screen on 2026-11-15, and
  the "Home, phone first" redraw in our colours. The repo's copy is `docs/design/v2/` (`TouchWood Home.dc.html`,
  `TouchWood Screens.dc.html`).
- **The shop as built:** main at 2e5887f4, read in the code and on screen at 1440 and 375 px.

**The rule over all of it** (owner, 2026-11-15): "we wont make our site a 100% like the design, but that
doesn't means to not even make it close to it". What is left out is listed in §11, and only that.

**"The design" here** means the design's *role* for a colour, drawn in our colours: `#02365e` and the current
set stay (content.md round 3 #7). Sizes are the design's, read from its source.

---

## 1 · The owner's answers

| # | Point | Answer | Replaces |
|---|---|---|---|
| 1 | Width | 1400 px with 40 px padding on every shop page | The frame's width as built (`max-w-6xl`, 1152 px); no spec stated one |
| 2 | Footer | Dark, as the design, **plus our newsletter box**, which the design lacks, fitted to the footer in light and dark. The mock data fills the footer | content.md §1.5's footer shape |
| 3 | Heart | **No spinner anywhere**: cards, the product page, everywhere a heart is. It fills at once and undoes itself if the save fails. "such an error must not happen" | feedback.md F5, F7 (the heart's look) |
| 4 | Card | Brand; price per unit; the wholesale line for individuals; **no stock count**; stock and labels as **flags over the photo**; **Add to cart** for one size, **Choose options** for several | sales.md S2's "never on a card" and content.md round 3 #5 (§1.17 row 7) |
| 5 | Category row | The first 6 top categories in the store's order, one line, the rest under All Categories | catalog.md §4.5's menu row |
| 6 | Admin sidebar | One area open at a time; opening another doesn't change the page; the area holding the page keeps its mark | frontend.md §2.2 "several open" (owner, 2026-10-04) |
| 7 | Product page | Everything in the design except the code line; no stock line; no bundles; the delivery line comes back | content.md §1.17 rows 2 (square photos) and 6 (delivery line dropped) |
| 8 | Slow photos | Local machine only (no queue worker, one-threaded `artisan serve`): no action | — |
| 9 | Round 2 | Every recommendation taken, except the answers in §1.1 | — |

### 1.1 Round 2: the answers that differ from or add to the recommendations

| # | Point | Answer |
|---|---|---|
| C3 | Complete the set | The hand-picked **Goes With** products, in the design's compact rows; no bundle price |
| C4 | Unit of sale | A field on each product from a fixed list (§10.1) |
| C11 | Delivery line | **One fixed number of working days in Settings, default 4**: content only, nothing to do with the carriers |
| C14 | Several photos on hover | **Wanted now on desktop**; phones keep one photo |
| D1 | Delivery wording | **Working days**: «التوصيل خلال 4 أيام عمل» |
| D2 | Units | حبة، طقم، زوج، متر، كرتون، علبة; piece by default |
| D3 | A card's Add to cart | Adds what the product page would start with: for an individual 1 piece, retail; for a company the page's starting way of buying and its minimum (the owner's "ok" to that wording, 2026-11-15) |
| D4 | Flags | All six, more than two at once (§4.3) |
| H3 | Hero product card | Dropped. **The first section becomes rotating banners** of news, events and big sales (§3.1) |
| H5 | Points band | Two cards read from the Points settings; no "2×" card |
| — | Sale price | Red, with the old price readable and «وفّر» (§4.2) |
| — | Theme artwork on cards | The flag dropped from cards and the gallery; the theme's artwork stays in the banners, the edges, the points band and the ribbon over the gallery (the owner's "ok", 2026-11-15) (§4.3) |
| — | Hero words | Option (b): only the title kept, as a slim line under the banners; the facts in the service strip; the buttons go (§3.1) |
| — | Flag colours | The recommended mix, **for now**: shown again once built (the owner's reminder) |

---

## 2 · The frame (every shop page)

### 2.1 Width and bands
- **Content box: 1400 px wide at most; side padding 40 px, 24 px at 1024 and under, 16 px at 640 and under.**
  Every `max-w-6xl` of `StorefrontLayout.tsx` (nine) and `CategoryMenu.tsx` (one) moves to it.
- **Bands run edge to edge:** `main` is full width and each section brings its own 1400 box, so the home hero's
  ground, the service strip, the logos band and the footer reach the screen's edges.
- **The theme's edge artwork** (content.md §1.8 part 11) starts at **700 px** from the centre instead of 576
  (`ThemeStyle.php:41`, `HALF_COLUMN`: 36rem today), so it shows only on screens wider than 1400 px (content.md
  §9.3d D4's "72rem column" becomes the 1400 px one).
- **The page ground stays ours, `#f7f8f9`** (round 3); the design's is white.
- **Phone section spacing 22 to 28 px** (today 40).

### 2.2 Top bar (the announcement bar's row)
- **38 px.** The announcement at the start; **Track Order** and the language at the end. Track Order opens the
  customer's orders (a guest is asked to sign in) **[PROPOSED P13]**. The row shows even with
  no announcement (then only its end links).
- **Dark mode keeps a deep navy bar** with light text: today it turns `bg-brand` (#6aaae3) with dark text
  (bug 2, §12).
- A live theme's dress (content.md §1.8 part 1) applies as built.

### 2.3 Header
- **78 px tall, gap 36 px.**
- **Logo:** a 46 px tile with the wordmark **"TOUCHWOOD" and «تاتش وود» under it** (H8).
- **Search:** fills the row; 46 px tall; a grey well with a border; **16 px text on phones**, so an iPhone doesn't
  zoom in on tap.
- **Cart:** a filled navy button with «السلة» and the count of pieces.
- **Icon buttons:** 42 px, 40 on phones.
- **Kept, though the design omits them** (content.md §1.5): country, language, bell, staff view line and menu,
  back to panel, shopper lines, verify-email badge.

### 2.4 Category row
- **One line, 50 px, 13.5 px items: the first 6 top categories in the store's order** (fewer as the screen
  narrows); every other one under **All Categories**.
- **At the row's end:** a copper «اطلب بالجملة» (Order wholesale) link to B2B's company page (`storefront.company`),
  shown to guests and individuals; a company account doesn't see it **[PROPOSED P21]**.
- **All Categories opens full width, in 4 columns.** No promo card (H13).

### 2.5 Footer
- **Dark in both themes**, in the admin sidebar's colours (`--tw-sidebar`: #01233c light, #0a151e dark; the
  design's #07273E is the same role).
- **The logo, the line about the store and the phone**, then up to 3 link columns, then the bottom line.
- **The newsletter box: a sand-coloured card at the footer's top** (H6), its colours fitted to the dark footer in
  light and dark mode.
- The theme switch stays (frontend.md §1.11).
- **The mock data fills the footer** (about line, phone, email, address, three columns of links).
- The theme's footer artwork (content.md §1.8 part 10) as built.

### 2.6 Phone frame
- **The tab bar's current tab in navy, bold.** Today only its icon fills.
- **The "phone first" redraw is the phone reference** (H1): stacked sections, 2-column grids, the dark footer,
  labelled tabs. What is already decided stays (the banners, Explore, the drawer). The original phone design's
  round category row is not added **[PROPOSED P14]**.
- **Compact top bars** on the category page and the cart, and buttons over the product photo (C12; §5.4, §6.6).

### 2.7 New colour tokens
**The footer reuses the sidebar's tokens** (`--tw-sidebar`, `-ink`, `-ink-muted`, `-line`); **the copper is
`--tw-accent`** (#864720 light, #dca071 dark), as built. New in `resources/css/themes.css`, light and dark, each a
role (frontend.md §1.10): **a warm border** and **the newsletter card's sand ground and ink**, their values taken
from the design when built and recorded there **[PROPOSED P1]**.

---

## 3 · Home

### 3.1 The first section: rotating banners (owner, 2026-11-16)
The most important part of the store: **news, events and big sales**, one image each with a short text.

- **The banners change by themselves every 5 seconds and loop back to the first** (the owner asked for about 3;
  5 lets the short text be read; owner's yes to the recommendation).
- **Arrows and dots** choose a banner. **It pauses** while the pointer is over it and while a finger is on it,
  and **doesn't move by itself** for a person whose device asks for reduced motion (the owner's yes). It also
  pauses while it has the keyboard's focus and while the browser tab is hidden **[PROPOSED P15]**.
- **Each banner's title, one short line and an optional button are real text over the image**, not written into
  it, on a dark band that keeps them readable in both themes (as drawn, 2026-11-15). They can then be translated, and search engines
  read them.
- **One list for both widths:** each banner has a wide image and a phone image (§10.3). This replaces today's
  split between `HOME_HERO` (one desktop banner beside the words) and `HOME_PHONE` (up to 3 swiped banners).
- **A live theme's hero artwork comes first, at both widths** (content.md §1.8 part 2, amended: today it replaces
  the desktop banner and leads the phone row).
- **At most 5 live banners**, the theme's artwork taking one of the places **[PROPOSED P2]**.
- **With no banner,** the section shows only the slim title line **[PROPOSED P16]**.
- **The slim title line under the banners:** the hero's title stays the page's H1, for search engines and screen
  readers. The eyebrow, the line and the two buttons go (option (b): only the title kept); each banner has its
  own button.
- **The facts** (the design's example: «TALLSEN وكيل معتمد») move into the service strip (§3.2), as a figure
  with a short caption (H2). The data is §10.9.
- **Built with shadcn's Carousel** (Embla) and Embla's autoplay plugin, added by shadcn's CLI. Neither is
  installed today. Their size is checked against the page's 60 KB before they are kept (frontend.md §5).
- **Dropped:** the design's floating product card over the hero, which showed a name, a price and "in stock"
  (H3).

### 3.2 The other sections
| Section | To the design |
|---|---|
| Service strip | Edge to edge with a bottom border; the promises in 4 columns, 2 up to 820 px; **the hero's facts as one slim row under them** **[PROPOSED P3]** |
| Category tiles | 28 px heading with an "All categories" link; about 6 columns; the photo edge to edge, 4:3 |
| Best sellers | 28 px heading with a link; 5 columns at 1440; **a grid/list switch, as the category page's** (H10, since C7 is built) |
| Individuals and companies | Small headings; the companies block warm with a copper button; one button each (H4) |
| Points band | Warm ground; 30 px heading; **two stat cards from the Points settings** (points per unit spent; points per unit off), as content.md §1.4 POINTS already reads them; no "2×" card, since Loyalty has no wholesale rule (H5) |
| Projects | `1.6fr 1fr`; the caption on a dark gradient with white text (bug 1, §12) |
| Client logos | Edge to edge; tiles, grey until hovered, moving slowly; still for reduced motion (H7) |
| Newsletter | In the footer (§2.5) |

---

## 4 · The product card (every list: category, brand, search, home, Goes With's cards, You May Also Like)

### 4.1 Its parts, top to bottom
- **The photo reaches the card's edges**, radius 12 px; **square, the whole product shown** (`object-contain`)
  on a grey well. Today it is cropped: `ShopPhoto.tsx:41` forces `object-cover`; it becomes the caller's choice.
- **On desktop, more photos while the pointer is over it** (C14): the product's next photos, up to 4 in all,
  with small bars at the photo's foot marking which one shows **[PROPOSED P4]**. Phones keep one photo. *Not
  verified: the reference shop's exact behaviour; the owner's example is DeFacto.*
- **The brand**: a small grey line above the name, the house brand (TouchWood) included (C5).
- **The name**: kept at 2 lines, so cards line up.
- **The price**: 19 px navy with its unit, «ر.س / للحبة» (§10.1); "From" as built. A sale's price: §4.2.
- **The wholesale line**, always given its row so cards line up:
  - an individual: «متاح بالجملة لحسابات الشركات» where the product sells wholesale;
  - a guest and a company: "Wholesale from", as built.
- **Stars and count** as built (feedback.md F7).
- **The button, 40 px** (reverses sales.md S2's "never on a card"):
  - **one size this viewer can order now: «أضف إلى السلة», filled navy.** It adds what the product page would
    start with: the same way of buying, its minimum quantity (D3).
  - **several: «اختر الخيارات», outlined**, opening the product page.
  - **in the staff view** (which can't order), the product page, as «اختر الخيارات» does **[PROPOSED P20]**.

  Added, the shop's toast says so (§6.4) and the cart count goes up.
- **The phone card:** no border; a 12 px name; a 14.5 px price; a 32 px «+» button doing the same as the button.

### 4.2 A sale's price (owner, 2026-11-16)
- **The price in red at 21 px** (`--tw-bad`: #982b24 light, #ed8c81 dark), with its unit (as drawn and chosen,
  layout 2).
- **Under it, on its own line:** the old price struck through, **at 15 px in the darker grey** (`ink-muted`),
  with a thicker line, then **«وفّر 6 ر.س»** in green (`--tw-good`): the amount saved per unit. *Example
  figures, as drawn.*
- **The −% goes onto the photo** (§4.3).
- **To check at 375 px:** where «وفّر» doesn't fit beside the old price it wraps under it, and on the phone card it
  is left out **[PROPOSED P5]**.
- A company's "Wholesale from" on a sale keeps amendment 45's rule (as built), in this look **[PROPOSED P17]**.

### 4.3 Flags over the photo (owner, 2026-11-16)

| Flag | When | Look (for now: shown again once built) | Data |
|---|---|---|---|
| −% | The card's price is reduced now | **Strong:** red, white text, **at the photo's bottom-start corner**, outside the cap below | As built (`percentOff`) |
| Campaign word | A theme is live and the price is reduced now | **Strong:** navy | As built (content.md §1.8 part 7) |
| «آخر القطع» (Last pieces) | A size this viewer can order is ending soon | **Strong:** copper, with a clock icon | As built (inventory.md §1.2; catalog.md amendment 16(h)) |
| «الأكثر مبيعاً» (Best seller) | Among the store's top sellers | **Soft:** pale amber, with a flame icon | **New, automatic** (§10.4) |
| «وصل حديثاً» (New arrival) | Listed in this store within the last N days | **Soft:** pale green | **New, automatic** (§10.4) |
| Store labels | Attached by staff | **In the tone staff chose** (catalog.md §1.8: five meanings, each strong or subtle); a new label's form starts on a subtle tone **[PROPOSED P18]** | As built |

- **Where:** the text flags stack at the photo's **top-start corner**, in the table's order (campaign word, Last
  pieces, Best seller, New arrival, then the labels in the store's order) **[PROPOSED P19: the order]**. **The
  heart at the top-end corner.**
  The −% at the bottom-start corner.
- **How many:** **at most 4 text flags on desktop and 3 on phones** (the −% not counted); the rest are left off
  the card, from the end of that order (catalog.md §1.8's "every label on the card" is amended). The product page
  shows them all.
- **Each soft flag has a thin white edge**, so it stands off a light photo.
- **The theme's artwork flag is dropped** from cards and the gallery (content.md §1.8 part 6, its flag image).
  Its ribbon over the gallery stays (the owner's "ok", 2026-11-15).
- Each flag's words are fixed per language, read with the card by screen readers.

### 4.4 The heart (feedback.md F7)
- **32 px, at the photo's top-end corner, on a white disc.**
- **No spinner.** Pressed, it fills or empties at once; the save goes in the background; a failed save puts it
  back and says so in a toast. Pressed twice quickly, the last press wins **[PROPOSED P6]**.
- A guest's press still goes to sign in and comes back with it saved (feedback.md Q18 (a), as built).
- The same on the product page, and wherever a heart is.

---

## 5 · Category and brand pages

### 5.1 Heading
- **34 px name**, then **the category's description** (a new field, §10.5), then **«عرض 1–24 من 918»**
  ("Showing 1–24 of 918"), then a line under it. *The figures are an example.* The total is this viewer's count
  under the chosen filters; *inferred:* the filters' one read already counts each choice, so it fits there.
- A brand's page as built (logo, name, description), in the same style.

### 5.2 Columns and filters
- **A 248 px filter column beside the grid, gap 34 px.**
- **The filters are sticky**, with their title and a copper **Clear all** at the top, and the chosen chips inside
  the filters, not above the grid.
- **Order:** how it is sold (Retail / Wholesale, for a company or a guest), brand, then the attribute values.
  Sizes are chips.

### 5.3 Sort, view, grid, paging
- **Sort** in a 38 px box labelled «الترتيب:». Best-Selling (default), Newest and Best Rated (where the store's
  ratings switch is on, feedback.md F7) as built, **plus price low to high and high to low** (C8). The price is this viewer's card price.
- **A grid / technical list switch** (C7) for contractors. The list's columns are photo · product and brand ·
  size · how sold · price · button. No code, no stock. The page remembers the choice per browser
  **[PROPOSED P7]**.
- **The grid's columns fill the width** (4 at 1400), gap 18; 2 columns up to 1024.
- **Show More stays** (C9), restyled to the design's button.

### 5.4 Phone
- A compact top bar (C12).
- **A sticky Sort and Filter bar**; **Filter and Sort open as bottom sheets** (shadcn's Sheet, installed).
- Brand chips.

---

## 6 · The product page

### 6.1 Columns
- **The gallery fills what's left; the details column is 460 px; gap 44 px.**

### 6.2 Gallery
- **A 4:3 main photo, the whole product shown**, with **a row of 4 thumbnails** under it (C15). Today it is
  square and cropped.
- The theme's ribbon over it as built; the theme's flag dropped (§4.3).

### 6.3 Details, top to bottom
1. **The brand and the flags** (all of them: §4.3).
2. **The name at 30 px.**
3. **The rating line** (feedback.md F5).
4. **The price box:** a bordered box with the price at 36 px and its unit, the tax note, the wholesale note, and
   **the Retail / Wholesale switch inside it**. On a sale, as §4.2, at its size.
5. **The choices as chips:** the chosen one filled navy, padding 10 by 16. Greyed sold-out sizes and Last
   pieces on its option stay (amendments 16(h), 39).
6. **One 48 px buy row:** the **− and + stepper, whose number can be typed** (C13; a wholesale minimum of 50
   makes + slow), Add to cart, and an icon-only heart (§4.4).
7. **The total line:** «المجموع X ر.س قبل الضريبة».
8. **The delivery and returns lines:**
   - «التوصيل خلال 4 أيام عمل», from the setting (§10.2);
   - «إرجاع خلال 14 يومًا من الاستلام», from `sales.returns.window_days` (as built, default 14).

### 6.4 Added to cart
- **The shop's toast** (shadcn's Sonner, installed): small and dark, at the bottom, saying what was added, with
  **View Cart**. It replaces today's text under the button for 6 seconds (sales.md S2's message, its words kept).

### 6.5 Below
- **Tabs** (shadcn's Tabs, installed): **«الوصف»** (Description) first (C1), **«المواصفات»** (Specs: the size's
  details, weight and size in a table, as built), **«الإرجاع والضمان»** (Returns and warranty: the warranty's
  name, period and terms, and the returns line). **No Installation tab** until a product field exists (C2).
- **Goes With** (catalog.md §1.10) **as the design's compact rows**: photo, name, price, and «أضف» (one size) or
  «اختر» (several), as the card's button. No bundle price (C3).
- **You May Also Like** as cards (§4).
- **Reviews:** the summary above, the reviews as **two-column cards**, the star filters kept (feedback.md F5).
- Questions as built.

### 6.6 Phone
- **A swipe gallery with «1/4»**; buttons over the photo (C12): back and the heart **[PROPOSED P22]** (sharing
  stays off, content.md §1.17 row 6).
- **The buy bar fixed at the bottom**, with the tab bar hidden on this page.
- **The sections fold open** (shadcn's Accordion, added by its CLI).

### 6.7 Kept as built
Not available now; the greyed and Last-pieces choices; how it is sold; the canonical and noindex rules
(catalog.md §4.5).

---

## 7 · The cart (sales.md S4)

- **Heading 34 px plus «N قطعة»** (N pieces).
- **Columns:** the lines, and a **392 px summary**.
- **The lines as one bordered list:** a 96 px photo; the brand; **a chip for each chosen value and for the way of
  buying**; one stepper box; «إزالة» (Remove) as text.
- **The summary as one sticky box**, the free-shipping bar inside it, a 25 px total and a 50 px Checkout button.
  **Tax and points stay at checkout as built** (C10).
- **Under the lines: «متابعة التسوق»** (Continue shopping). No "Empty cart" (C10: not asked for).
- **Phone:** a grey page, a compact top bar, and the Checkout bar fixed at the bottom with the subtotal «قبل
  الضريبة».
- Everything sales.md S4 lists stays: a line's problem and its fix, the notices, the coupon box, the gift,
  points, the theme's cart message.

---

## 8 · The admin sidebar (frontend.md §2.2)

- **One area open at a time.** Opening an area closes the one open. It doesn't change the page you're on.
- **The area holding the page you're on keeps its lit mark while it's folded**, as built (`AppSidebar.tsx:263`:
  `isActive={holdsHere && !open}`).
- **On each page, only that page's area opens**, unless you open another one there (S1). Today's cookie that
  remembers several open areas (`sidebar_sections`, read back by `HandleInertiaRequests`) goes, on both sides.

---

## 9 · The heart, everywhere (feedback.md F5, F7)
§4.4's rule. No `loading` spinner on any Save heart.

---

## 10 · Data and settings to add

Each piece is added by its own module's code, in its own PR (§14), with its tests. The card's new facts come
**from the same listing read** as today: no query is added.

### 10.1 Unit of sale (Catalog)
- **A field on each product: one of حبة (piece), طقم (set), زوج (pair), متر (metre), كرتون (carton), علبة
  (box)**, piece by default (C4, D2).
- Set in the product's panel screen and **in the import file** (`src/Modules/Catalog/Presentation/import-format/`;
  changed here with the owner's word).
- Shown after the price on the card and the product page: «ر.س / للحبة», «ر.س / للطقم», and the same for the
  others. **Where else, and what the header's count means for metres and cartons, is question Q2** (§13b).
- *Not checked:* whether Odoo carries a unit. The sync doesn't read one (sync.md); none is added.

### 10.2 Delivery days (Content)
- **A store setting, «مدة التوصيل (أيام عمل)», an integer, default 4** (C11, D1). **Who owns it is question Q3**
  (§13b). Proposed: Content, key `content.delivery.working_days`, scope Store, 1 to 60, edited under
  `content.homepage.manage`, in a new `ContentSettings` (as `SalesSettings`; Content registers none today)
  **[PROPOSED P8]**.
- Text on the product page only. Nothing to do with the carriers or the checkout's shipping (owner).

### 10.3 Banners (Content)
- `HOME_HERO` takes **both a wide and a phone image**; `HOME_PHONE` goes, its rows moved into `HOME_HERO` (the
  data is mock only before launch).
- **New per-language fields:** a title (60 characters), a line (120 characters), a button's text (24 characters)
  **[PROPOSED P9: the lengths]**; the link as built.
- **Sizes [PROPOSED P10]:** wide **upload 2400 × 800** (3:1), shown up to 1320 × 440; phone **upload 1200 ×
  600** (2:1), as `HOME_PHONE` today.

### 10.4 The automatic flags (Catalog)
- **Best seller:** the store's top **N** products by its sales rank (`sales_rank`, already on the listing row),
  among what is listed; a store setting, **default 10** **[PROPOSED P11]**: `catalog.flags.best_seller_top`,
  scope Store, 0 (off) to 100, under `catalog.listing.labels`, in a new `CatalogSettings`.
- **New arrival:** listed in this store within the last **N days**; a store setting, **default 30**
  **[PROPOSED P12]**: `catalog.flags.new_arrival_days`, scope Store, 0 (off) to 365, under
  `catalog.listing.labels`. *Not checked:* which date the listing row can carry for "listed in this store"; checked in
  the card PR before it is built.
- **Decided when the card is read, not stored as a yes or no**, so neither goes stale: the row already reads its
  rank as it is read (`DatabaseListingRows.php:143`), and gains the date it was listed here, compared with the
  setting's days. No query is added; the two settings come with the page's settings read, recorded in its query
  test.

### 10.5 Category description (Catalog)
- **A text per language on each category, optional** (C16), set in the category's panel screen; shown under the
  category page's heading. The import names categories only by their path and may create new ones
  (`import-format/guide.md`), so the description is set in the panel only **[PROPOSED P23]**.

### 10.6 The card's facts (Catalog)
Read in the listing row as built (`DatabaseListingRows`), each side's (individuals; companies and guests):
- **the brand's name** (`brand_id` is on the row);
- **the count of sizes this side can order now**, and for one, its variant, way of buying and minimum (*inferred:*
  the row's build already holds each side's orderable, priced sizes, `DatabaseListingRows.php:217-247`);
- **sells wholesale**, for the individual's line (`sells_wholesale` is on the row);
- **the unit** (§10.1);
- **the next photos** for desktop hover (§4.1);
- the two automatic flags (§10.4).

### 10.7 The cart's lines (Sales)
`CartLineData` gains the brand, and the chosen values as a list (today one string).

### 10.8 The product page's reads (Catalog)
The returns window (`sales.returns.window_days`) and the delivery days (§10.2), read through `PlatformApi::setting`
as Content reads Loyalty's (content.md §1.4 POINTS).

### 10.9 The hero's facts (Content)
- **They stay `HERO`'s** (content.md §1.4: up to 3), each now **a figure and a caption per language** (H2)
  **[PROPOSED P24]**. Drawn in the service strip's slim row (§3.2); with `PROMISES` hidden they are drawn alone
  in that row; with `HERO` hidden, not at all.

---

## 11 · Left out, on purpose

- **Stock numbers** anywhere: the flags only (owner).
- **The product code** line (owner; amendment 5(d)).
- **Bundles and a bundle price** (owner).
- **The design's floating hero product card** (H3).
- **The theme's artwork flag on cards and the gallery** (owner, 2026-11-16).
- **An Installation tab** until a field exists (C2).
- **"Empty cart"** (C10).
- **The order pages and B2B's screens** to the design (content.md §1.17 row 3): **question Q1** (§13b), not in
  these PRs until answered.
- **The round category row** of the original phone design (H1; P14, proposed).
- **The All Categories promo card** (H13).
- **A "2×" points card** (H5).
- Still dropped by the owner's earlier rule (content.md §1.17 row 6): مختارات لك, save for later, share, the price
  slider, availability filters, the branches link, the New and Offers pages, review translate.

## 12 · Bugs fixed on the way (found in the review, 2026-11-15)

1. **The project card's caption on Home is unreadable in both themes** (`Home.tsx:260`: `text-ink-on-brand` on
   `--tw-overlay`, white on 94% white in light, #0c1a26 on a dark overlay in dark). → White text on a dark
   gradient, as the design.
2. **The announcement bar in dark mode** is light blue with dark text. → The deep navy bar (§2.2).
3. **On a phone with no banner, the hero shows only its title.** → The banners serve both widths (§3.1).

## 13 · The builder's picks [PROPOSED], for the owner's review

| # | Pick |
|---|---|
| P1 | The footer on the sidebar's tokens and the copper on `--tw-accent`; new tokens only for the warm border and the newsletter card's sand ground and ink, values from the design, recorded in frontend.md §1.10 |
| P2 | At most 5 live home banners, a live theme's artwork taking one of the places |
| P3 | The hero's facts as one slim row under the promises |
| P4 | Desktop hover: up to 4 photos, small bars at the photo's foot |
| P5 | «وفّر» wraps under the old price where it doesn't fit, and is left off the phone card |
| P6 | Two quick presses of a heart: the last one wins |
| P7 | The grid/list choice remembered per browser |
| P8 | Delivery days: `content.delivery.working_days`, Store, 1 to 60, under `content.homepage.manage` (if Q3 says Content) |
| P9 | A banner's title 60, line 120, button 24 characters per language |
| P10 | Banner uploads: wide 2400 × 800, phone 1200 × 600 |
| P11 | Best seller: `catalog.flags.best_seller_top`, default 10, 0 to 100, under `catalog.listing.labels` |
| P12 | New arrival: `catalog.flags.new_arrival_days`, default 30, 0 to 365, under `catalog.listing.labels` |
| P13 | The top bar's Track Order opens the customer's orders; a guest signs in first |
| P14 | The original phone design's round category row not added |
| P15 | The banners also pause on keyboard focus and while the tab is hidden |
| P16 | With no banner, only the slim title line |
| P17 | A company's "Wholesale from" on a sale in the same red look |
| P18 | Store labels in their chosen tone; a new label starts on a subtle one |
| P19 | The flags' order: campaign word, Last pieces, Best seller, New arrival, labels |
| P20 | In the staff view, the card's button opens the product page |
| P21 | «اطلب بالجملة» to `storefront.company`, for guests and individuals only |
| P22 | Over the phone photo: back and the heart |
| P23 | The category description in the panel only, not the import |
| P24 | A fact: a figure (16 characters) and a caption (40) per language |

## 13b · Questions for the owner

| # | Question | Recommended |
|---|---|---|
| Q1 | The order pages and B2B's screens to the design (content.md §1.17 row 3): in this job or a later one? | **A later small job**, after these eight PRs, reusing their pieces (card, chips, lists) |
| Q2 | The unit of sale beyond the card and product page: cart lines, checkout, order lines and emails? And the header's count (pieces, R1-10) when a cart holds metres or cartons? | **The unit wherever a line's price shows**; the header counts **products** (lines), since metres and cartons don't add up to pieces |
| Q3 | Who owns the delivery-days setting: Content (it is text) or Shipping (it is about delivery)? | **Content**, since it has nothing to do with the carriers (the owner) |

## 14 · The build, one PR each (spec → plan → build → review)

1. **Quick fixes:** bug 1 and bug 2 (§12).
2. **The frame:** width and bands, top bar, header, category row, the dark footer with the newsletter, the mock
   footer data, the phone frame, the new tokens, the edge artwork at 700 px.
3. **Home:** the banners (and their data, §10.3), the slim title, the strip with the facts, tiles, best sellers,
   audience, points, projects, logos.
4. **The card (backend + screen):** §10.1, §10.4, §10.6; the card, the sale price, the flags, the heart without
   a spinner everywhere, the phone card, desktop hover photos.
5. **Category and brand pages:** heading, count and description (§10.5), filters, sort with prices, the list
   view, the phone sheets.
6. **The product page (backend + screen):** §10.2, §10.8; gallery, price box, buy row, total, toast, delivery
   and returns, tabs, Goes With rows, review cards, the phone gallery and buy bar.
7. **The cart:** §10.7; lines, summary, the phone checkout bar.
8. **The admin sidebar:** §8.

**Checked in every PR:**
- the browser tests at 375 × 812 and desktop, Arabic and English, light and dark;
- each page's query count (frontend.md §5: 8 a shop page is the target, every count recorded);
- each page's JavaScript under 60 KB gzipped.
