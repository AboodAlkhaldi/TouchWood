# The admin dashboard

The admin panel's Home (`/admin`) turned from three cards into a dashboard: a quick read on the store for the
period chosen, then the work waiting. It changes no module's rules of business: every figure is one a module
already counts for the daily report (ops.md §1.11), read the same way, plus the few reads listed in §6.

**State:** written 2026-11-18 from the owner's answers of 2026-11-17 and 2026-11-18. **Accepted by the owner,
2026-11-18, with all 11 picks** ("Accept all picks"). Points marked **[PROPOSED Dn]** are the builder's picks,
listed in §9, now accepted; every other point is the owner's answer. Not built.

**Sources:**
- **The owner's references** (2026-10-13): shadcndashboard.dev's e-commerce dashboard, an "EzMart" light
  dashboard and a "Dukaan" dark dashboard (screenshots). Studied again on 2026-11-18 in the browser: the
  **Analytics** and **eCommerce** demos of demos.shadcndashboard.dev.
- **The section list of the hand-off** (HANDOFF.md, "Dashboard"): sales for the month, three months and year;
  total orders; orders not yet shipped; completed; cancelled; low stock; pending company approvals; recent
  orders; a monthly target (dropped, §1 #6).
- **What is built:** the home-card contract (platform.md §2.6, §9.8, §9.10) and its three cards; Sales' report
  reads (`SalesReports`, ops.md §5's Sales row); the daily report's reader (ops.md §1.11).
- **Two sketches** drawn for the owner on 2026-11-18; the second, improved from the references, is the one
  answered.

---

## 1 · The owner's answers

| # | Point | Answer |
|---|---|---|
| 1 | Sections | The nine of sketch 2 (§2), "good for a first attempt", improved from the two references |
| 2 | The word | **"Sales" (المبيعات), never "Revenue"**: it is what was sold at our selling price, not what was earned; there is no cost price in the system, so no profit |
| 3 | Individuals and companies | **A ring chart**, not a line |
| 4 | All Stores | **Each store in its own currency**, side by side. No exchange rates, no grand total: no new feature |
| 5 | Periods | Today, 7 days, This month, 3 months, Year, **each against the period before it of the same length** |
| 6 | Monthly target | **Dropped.** "If we want to see something like this we can just look on how many things made this month and compare it with previous" |
| 7 | Charts | **shadcn's Chart** (Recharts v3), **loaded just after the page shows** (§7) |
| 8 | Figures | Sales without VAT; test orders never counted (as the daily report) |
| 9 | Who sees what | Each section by the permission behind it (§5) |
| 10 | Visitors | **Left out** (ops.md R4-3 stands: Google Analytics, outside the system). So no traffic sources, funnel or users by country |

---

## 2 · The page, top to bottom

Desktop: the sections below in the panel's content width. **Phone: one column in the same order**, each chart
the full width.

| # | Section | What it shows | From the references |
|---|---|---|---|
| A | **Greeting row** | "Good morning, <name>" (the reader's first name; morning, afternoon or evening by the store's clock) **[PROPOSED D1]**; Home's store switcher as built (platform.md §9.10); the **period** menu (§3.3); **Today's Report (PDF)** (§2.1) | Analytics' greeting with "View Full Report" |
| B | **Four figure cards** | Sales · Orders · Average Order · New Customers. Each: the figure, its change against the period before (§3.4), and a **small trend line** of the period by day (by month for Year) | eCommerce's top cards with their small charts |
| C | **Sales and orders by day** | One line chart, days along the bottom (months for Year): Sales, Orders, and the period before's Sales dotted. A tooltip names the day and its figures | eCommerce's Order Overview |
| D | **Individuals and companies** | A ring: each one's share of Sales, the total in its middle; under it each one's Sales and its change | eCommerce's Customer Segmentation |
| E | **Orders now** | Four small cards: **Waiting** (placed, not yet approved or rejected) · **To Ship** (approved, not shipped) · **On the Way** (shipped, not delivered) · **Cancelled and Came Back** (in the period). The first three are now, whatever the period **[PROPOSED D2]**; each of those three links to Orders filtered the same way (Cancelled and Came Back links nowhere: §10 #2) | eCommerce's Total Orders / Orders Shipped; the hand-off's "not yet shipped" |
| F | **Average rating** | The store's approved reviews' average, out of 5, and their count; its change against the period before is not shown (a rating is an average of all time) **[PROPOSED D3]** | eCommerce's Customer Satisfaction 4.9 / 5 |
| G | **Recent orders** | The last 8 placed **[PROPOSED D4]**: the first line's photo and name ("+2 more" when there are more), the customer, pieces, the way to pay, the status badge, the total; **View All Orders** | eCommerce's Recent Sales Orders |
| H | **Best sellers** | The 10 sizes sold most in the period (ops.md §1.11's product read): photo, name and size, rating, pieces sold, their Sales | Analytics' Top Selling Products |
| I | **Two split bars** | **Sales by category** (top-level categories, the smallest past the fifth joined as "Other" **[PROPOSED D5]**) and **money received by way** (online, transfer, other; the period's payments, as the daily report's Money section) | Analytics' Sales Distribution, eCommerce's Distribution |
| J | **Needs attention** | The three cards built today (Company Approvals, Stores and System, Low Stock) and four more: **Transfers Overdue** (Sales), **Questions Waiting** (Feedback), **Sync Problems** (Sync), **Returns Waiting** (Sales) **[PROPOSED D6]**. A card with nothing to say is not shown, as today | — |

Left out (§8): monthly target, visitors and everything built on them, campaign performance by channel, latest
products, profit.

### 2.1 Today's Report

The daily report of ops.md §1.11, **for the store chosen** (All Stores: the all-stores report, a Super Admin
only, as on Daily Reports). The button makes it as the Daily Reports page's button does, and opens the PDF; it
is then listed on Daily Reports like any other. Shown only with `ops.report.daily` for that scope.

---

## 3 · The figures

Every figure is read **in each store's own day** (its time zone, ops.md §1.11) and **never counts a test order**.

### 3.1 Definitions

| Figure | Is | Read from |
|---|---|---|
| **Sales** | What customers pay for the goods after the coupon and the points, **without VAT and without shipping**, of the orders placed in the period that count (never cancelled, rejected or came back, judged by what each is now) | `SalesReports::revenueByDay` (`revenue`), summed |
| Refunds | Shown under Sales in small text ("Refunds 120.00 SAR"), **not taken off it**, as the daily report shows them apart **[PROPOSED D7]** | the same read (`refunds`) |
| **Orders** | Orders placed in the period that count | `counts` (`counted`) |
| **Average Order** | Sales ÷ Orders; "—" with no order | worked out |
| **New Customers** | Accounts registered in the store in the period, individuals and companies | `AccessApi::customersRegisteredByDay` for the period and its trend line, `customersRegistered` for the period before (§10 #1) |
| Individuals / companies | Sales split by who ordered | new: `revenueByDay` split by audience (§6) |
| Orders now | §2 E | new: `ordersNow` (§6) |
| Best sellers | §2 H | `productSales` |
| By category | Sales of each top-level category | `productNetSales` |
| By way | Payments received in the period, by way | `moneyOn` |

### 3.2 Money

Each figure in its store's currency, written as the panel writes money (Latin digits, the currency's sign).
**All Stores** (§1 #4): each figure card lists **one line per store**, its own currency; the chart, the ring and
the split bars show **one store at a time** with small store tabs in the section, the first the base store
**[PROPOSED D8]**, because one axis cannot hold three currencies. Counts (orders, customers, orders now) add
up across stores and also show a total.

### 3.3 Periods

| Period | From | Against |
|---|---|---|
| Today | Midnight to now | Yesterday, midnight to the same time |
| 7 days | Today and the 6 days before | The 7 days before those |
| This month (opens on it **[PROPOSED D9]**) | The 1st to now | The month before, from its 1st to the same day and time (the last day of a shorter month when it has fewer days) |
| 3 months | This month and the two before it, to now | The three months before those, to the same point |
| Year | 1 January to now | The year before, to the same day and time |

The period chosen stays in the address (`/admin?store=sa&period=month`), as the store does.

### 3.4 The change

`(now − before) ÷ before`, rounded to one decimal, with ▲ or ▼ and the good/bad colour (good up for every
figure here). **Before = 0 and now > 0: "New"**; both 0: no change shown.

---

## 4 · Look

- **shadcn's components only**: `Card` for each section, `Chart` (with its `ChartTooltip`, `ChartLegend`) for C,
  D, B's small lines and I, `Table` for G and H, `Badge` for statuses, `Tabs` for the All Stores store tabs,
  `DropdownMenu` or `Select` for the period (frontend.md: never rebuilt).
- **Our colours**: the chart's lines and parts from our tokens (brand navy, copper, the soft greys), in light
  and dark, read from CSS variables as shadcn's Chart does; the previous period dotted in a grey.
- Numbers in Latin digits everywhere (memory: Latin digits rule), Arabic pages too.
- Every chart has its figures in words for screen readers (the chart's `aria-label` and a hidden table).

---

## 5 · Who sees what

The home-card rule stays (platform.md §2.6): a section shows when the reader holds its permission in the scope
(the chosen store, or every store for All Stores; a Super Admin always).

| Sections | Permission |
|---|---|
| B, C, D, E, G, H, I (Sales' sections) | `sales.order.view` |
| F Average rating | `feedback.review.moderate` |
| Today's Report | `ops.report.daily` |
| J's cards | each its own, as built; Transfers Overdue `sales.payment.record`, Questions Waiting `feedback.question.answer`, Sync Problems `sync.report.view`, Returns Waiting `sales.return.manage` |

New Customers rides with Sales' cards (`sales.order.view`) **[PROPOSED D10]**. Someone holding none sees what
today's Home says to someone with no card.

---

## 6 · What each module adds

Each module registers its own sections, as menu entries and cards are registered (platform.md §2.6). What is
new:

1. **Platform: the home-card contract grows** (platform.md §2.6 amended):
   - `HomeScope` carries the **period** (§3.3) beside the stores.
   - `HomeCardData` gains new kinds beside `figures` and `rows`: a **money figure** (amount, currency, change, a
     small series), a **series** (points by day or month, for C), a **split** (parts with their share, for D and
     I), a **table** (rows with a photo, for G and H), and a **size**: full, two-thirds, one-third or quarter of
     the row, so the page lays out §2's order.
   - Home's page lays sections out by `position` and `size`.
2. **Sales:** its sections B (Sales, Orders, Average Order), C, D, E, G, H, I, and the J cards Transfers Overdue
   and Returns Waiting. New reads in `SalesReports`: `ordersNow(store)` (§2 E's three counts), `recentOrders(store,
   limit)`, `returnsWaiting(store)` (returns asked and not yet finished, for J), the audience split of
   `revenueByDay` (§3.1).
3. **Access:** ~~nothing new~~ **one new read** (§10 #1, access.md amendment 100): `customersRegisteredByDay`, for
   New Customers' trend line; the period before still reads `customersRegistered`.
4. **Feedback:** section F and the J card Questions Waiting. New read: `averageRating(store)`: the approved
   reviews' average and count.
5. **Catalog:** nothing new; photos and names of G and H come from `productCards` / `variants`, as the daily
   report reads them.
6. **Ops:** the Today's Report button (§2.1) on its existing action.
7. **Sync:** the J card Sync Problems, from `readsAndProblemsPerDay`.

---

## 7 · Speed

- **The charts load just after the page shows**, by their own dynamic import: frontend.md §5 already asks this
  of "anything heavy (charts…)", and the page budget does not count it (`JavaScriptBudgetTest`). **Measured
  2026-11-18**: Recharts 3.11.0 with the line chart, the ring and the bars, built with our Vite, React left out:
  **127.9 KB gzipped** (a line chart alone 114.8 KB). Until it arrives each chart's place keeps its size, so
  nothing jumps. On the server (SSR) only that place is drawn (frontend.md: nothing touches `window` while
  rendering).
- **Queries:** an admin page's own budget is 15 (frontend.md §5). A dashboard reading every section for three
  stores and two periods will pass it, so **each section's figures are kept 5 minutes per store, period and
  language** **[PROPOSED D11]** (a "Updated 3 minutes ago" line and a Refresh button in the greeting row), and
  the count is measured warm and recorded in the test, as the owner's rule on budgets asks (exceeded only when
  there is no choice, the number recorded).

---

## 8 · Left out, and why

| Left out | Why |
|---|---|
| Monthly target | Owner, §1 #6 |
| Visitors, traffic sources, the funnel, users by country, "viewed vs checkout" | Owner, §1 #10; ops.md R4-3 |
| Campaign performance by channel (Instagram, Google…) | Needs traffic sources (above) |
| Profit, margins | No cost price in the system (§1 #2) |
| Latest products | Not a reading of the business; Catalog's list does it |
| Exchange rates, one total for all stores | Owner, §1 #4 |

---

## 9 · The builder's picks (accepted 2026-11-18)

| # | Pick |
|---|---|
| D1 | The greeting by the store's clock, with the reader's first name |
| D2 | Waiting, To Ship and On the Way are **now**, whatever the period; Cancelled and Came Back are **in the period** |
| D3 | Average rating with its count, no change against before |
| D4 | Recent orders: the last 8 |
| D5 | Sales by category: top-level categories, past the fifth joined as "Other" |
| D6 | Two more attention cards: Returns Waiting and Sync Problems, beside Transfers Overdue and Questions Waiting |
| D7 | Refunds shown under Sales, not taken off it |
| D8 | All Stores: figure cards list each store; charts show one store at a time with store tabs, the base store first |
| D9 | The dashboard opens on This Month |
| D10 | New Customers shows with Sales' permission |
| D11 | Figures kept 5 minutes, with "Updated … ago" and Refresh |

---

## 10 · Amendments while building (2026-11-21)

**The owner's decisions:**
1. **New Customers gets its trend line** (§2 B as written; §6.3 amended): Access adds `customersRegisteredByDay`
   (access.md amendment 100). Two reads a store, inside the 5-minute cache.
2. **Cancelled and Came Back links nowhere** (§2 E amended from "each links"): the Orders list has no tab for
   them, and a link to a list that disagrees with the figure is worse than none. A Cancelled tab can be its own job.
3. **shadcn's Chart writes its tooltip's default value in Latin digits** (`components/ui/chart.tsx`, our one change
   to that file, using `lib/digits`' `figure()`), so `LatinDigitsTest` keeps no exemption for it.

**The builder's picks, checked against this spec, the policies spec and the architecture rules (2026-11-21):**

| # | Pick | Fits because |
|---|---|---|
| 2 | Sales' home figures are read inside Sales (`SalesHomeReads`, Application), not added to `SalesReports` (§6.2's wording) | Only Sales' own cards read them; a public contract carries what other modules need (Ops reads `SalesReports`) |
| 3 | Best sellers' ratings from `CatalogApi::productRatings` (§6.5 said "nothing new") | The shop's own copy of each product's rating, which Feedback pushes to Catalog (`ListingFacts::ratings`): the same number the shop shows, one query |
| 4 | The No Terms warning (F17) is Sales' card, with `sales.order.view`, no link | The orders are what stop (`TermsMissing`); asked of Platform's `ShopPolicies`, never Content (build 4's rule); a Content link would refuse readers without `content.policy.manage` |
| 5 | Recent Orders and Best Sellers also get the store tabs | D8's reason (one store's money at a time) holds for the tables' totals |
| 7 | "Before" ends at the same time of day | §1 #5: the period before is of the same length |
| 8 | A **half** card size beside §6.1's four | Layout only |
| 9 | Each card's figures kept per **reader**, store, period and language (D11 named store, period and language) | Who may see what differs by reader; the reader in the key keeps one person's figures from another's. Each reader pays one cold read |
| 10 | Today's Report on a day still running | Ops' own "Today So Far" (ops.md, Daily Reports) |
| 1, 6 | No trend line on New Customers; no link on Cancelled and Came Back | Decided by the owner above |
