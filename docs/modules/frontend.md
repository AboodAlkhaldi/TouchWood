# Frontend foundation — Stage Specification

> **Read this first.** The body of this file was written with the owner on 2026-09-19, while Access
> was still being built. On **2026-09-22**, with Access finished and merged, it was checked against
> the merged code and the owner settled everything that had changed underneath it (§0). Sections the
> owner approved on 2026-09-19 and that nothing has invalidated stand as they are; they are not
> re-opened here.
>
> Facts recorded on 2026-09-19 that still carry a date — package versions, font coverage — were true
> then and are **verified again when the build starts**. Where this file and a module's own approved
> spec disagree, the module's spec wins.

**Status:** APPROVED for building, in the steps of §0.2. Written section by section with the owner
on 2026-09-19; revised with the owner on 2026-09-22 against the merged code.
**Stage:** 2b (handoff §17), after Access, before B2B. **Depends on:** Platform, Access.
**Source:** `docs/HANDOFF.md` §3, §4.1, §5.2, §14, §17; `docs/STRUCTURE.md`; `docs/modules/platform.md`
§3, §5.1, §9.3; `docs/modules/access.md` (header, §3); the owner's answers of 2026-09-19 (§8).

This stage is not one of the 15 modules, so it does not use the nine-section module template
(handoff §18). Its outline was agreed with the owner on 2026-09-19:

1. Foundation · 2. Layouts · 3. Screens · 4. New backend endpoints · 5. Performance budgets ·
6. Accessibility and RTL · 7. Test scenarios · 8. Questions

**What it delivers** (handoff §17, access.md header, platform.md §3 and §9.3): Inertia + React +
shadcn with SSR; the storefront's sign-in, registration and verification pages; the admin sign-in
with its SMS code; staff and role management, with their endpoints; Platform's admin screens
(stores, currencies, settings, media library, audit log); error pages for 409, 413, 415 and 422;
and the check that the Riyal and Dirham signs render in the chosen font.

Items marked **[DECIDED date]** are the owner's answers in the planning conversation of that date —
not final acceptance (see the notice above); each is confirmed again before it is built.

**Changes to a section after its approval** — each shown to the owner:

| Section | Change | Why |
|---|---|---|
| §1.7, §1.8 | Fonts, colours, digits and the first currency-sign check filled in from the design | The design arrived after §1 was approved; the digits rule is the owner's answer |
| §2.2 | The EN/AR toggle changes only the display; remembered per browser | Access amendment 16; the question asked before §2's approval had it wrong. Owner's answer, 2026-09-19 |
| §0 | Everything Access changed between 2026-09-19 and its merge, and the step list | Owner, 2026-09-22 |
| §1.8, §2.3, §8.3 | The design of record is the handoff of 2026-09-22, which includes a storefront | Owner, 2026-09-22 |
| §1.8 | A theme is data: campaign themes must be possible without touching a component | Owner, 2026-09-22 |
| §1.8, §3.6 F1 | **Geist is the design system**: its components, behaviour and all its rules (writing rules included), in TouchWood's look — today's colours a bit sharper, IBM Plex fonts, 10 px corners. Built before any new screen; every built screen moves to it. The store chooser and switchers list **on** stores only (platform.md §1.6) | Owner, 2026-10-01/02 (the new direction) |
| §1.10 (new) | **The Geist foundation**: colours set B (owner's pick); Geist's type scale, materials, sizes, components and writing rules; Light and Dark only — the provisional picks marked there | Owner's overnight run, 2026-10-02 |
| §1.10 | **Store time**: every moment in the zone of the store being worked in (owner's answer, 2026-10-02); Geist's `Time` (provisional) | Owner, 2026-10-02 (the "store time on panel screens" job) |
| §1.10, §1.11 (new) | **shadcn's real code, Geist's look and rules on top.** Components come from shadcn's CLI and stay unchanged, apart from the edits §1.11 lists; the hand-built `components/geist/` goes; where Geist's rule and shadcn's code disagree, Geist's rule wins; nothing is built that either system has, and anything neither has is put to the owner first | Owner, 2026-10-02, after seeing the Geist screens: "dont ever create something if they had it" |
| §1.10, §2.3, §3.4, §3.5 | The owner's answer for each piece neither system has (§1.11 table); the theme switch with System, once per area; SMS codes in shadcn's InputOTP | Owner, 2026-10-02, one by one with pictures |
| §1.10, §1.11 | §1.10's provisional picks settled (type, surfaces, store time stand); the overnight review's Geist rules applied in the rebuild; every module's error messages rewritten in Geist's form | Owner, 2026-10-03 |

---

## 0 · Revised with the owner, 2026-09-22

Access was finished after this file was written: steps 5 (addresses), 6 (deletion, blocking, the
staff views) and 7 (the module's own pass) landed, with amendments 41–46. This section records what
that changed here, and the step list the build follows. Nothing else in the file was re-opened.

### 0.1 What Access changed underneath this spec

| # | Section | It said | It now says | Source |
|---|---|---|---|---|
| R1 | §3.3 C1 | The staff list shows picture, name, email, role, status and the joined date for everyone | An **admin** seen by anyone but a Super Admin shows a **name and a role only**. They appear in a **separate short section** of the list, above the ordinary colleagues, with those two columns and nothing else — no empty cells pretending there is data (owner, 2026-09-22). The list is ordered **by name**, not by joining date, for a reader who is not a Super Admin | access.md amendments 43(a), 44(e), 46(d); the list's own ordering rule |
| R2 | §3.2 B2 | Changing your own phone asks for the new number and a code | It asks for the **current password first**, then the code. A wrong password is counted like a wrong one at sign-in, so the screen must show the lockout message too | access.md amendment 46(b) |
| R3 | §3.3 C7 | Enabling someone with no role asks for the role in the same step | Still true, but the case it was written for is gone: revoking a Super Admin now **closes the account** (`CANCELLED`, email and phone freed). Nobody is left role-less by a revoke, and the screen never shows one | access.md amendment 45(b) |
| R4 | §3.5 E4 | Access's numbers are settings on the settings screen | They are, in **one Access section** (owner, 2026-09-22), but under **two permissions**: a store's own settings are ordinary, the staff sign-in and security numbers are **admin-only**. Each row checks its own permission, so a staff member simply does not see the rows they may not change | access.md amendment 46(a) |
| R5 | §3.6 F10 | Closing the account: locked at once, anonymized after 14 days, signing in cancels it | Confirming it also **signs them out of every device immediately**, and the "cancel from the account page" is gone — signing in again is the only way back. They land on the **store home, signed out**, with a message giving the deletion date and saying that signing in before then cancels it (owner, 2026-09-22) | access.md amendment 45(a) |
| R6 | §3.7 G2 | Staff may block, unblock, and start the 14-day deletion | All three are **admin-only** actions, and staff may also **cancel** a pending deletion, with a reason. As everywhere, a button appears only for someone who may use it | access.md amendment 43(b), 43(c) |
| R7 | §4.3 P7 | Read models for the customer screens are missing | **Done.** `ListCustomers`, `ViewCustomer`, `ListStaff` and `ViewStaff` shipped with Access step 6 | access.md §3.3 |
| R8 | §8.1 | Open: which module keeps the admin menu registry | **Platform keeps it** (owner, 2026-09-22), like the permission catalog and the settings registry: every module, Platform included, declares its own entries with the permission each needs. Filtering asks the Shared `Authorizer`, so Platform never reaches into Access | §4.3 P6 |

Checked and **not** stale: the address fields of §3.6 F9 (five required of thirteen, exactly as
`StartingAddressFormat` writes them), the route names of §3.1, and the customer rules of §3.6 F5.

### 0.2 The steps this stage is built in

Agreed with the owner, 2026-09-22. Each step ends the way every step of Access ended: `composer
check` green, an independent review, a mutation run over what it added, and the owner's word before
the next one starts.

| Step | What | Why it is one step |
|---|---|---|
| **0** | The six changes other modules must make (§4.3 P1–P6), each an amendment to its own module's spec | No screen can be built well without them, and three of them change a module's public contract that later stages depend on |
| **1** | The foundation: Inertia, SSR, the four layouts, fonts, colours, RTL, the `t()` helper, Ziggy groups, generated types, the added checks — **proved end to end by the admin sign-in pages** (A1–A9) | Every decision in §1 and §2 is exercised by real screens before anything is built on top of them |
| **2** | The admin: my account, staff, roles (§3.2, §3.3, §3.4) | The screens with the most rules behind them, all of them Access's, all already built and tested underneath |
| **3** | Platform's screens: stores, currencies, settings, the media library, the audit log (§3.5) | One module's screens, one permission model, no dependency on step 2 |
| **4** | The storefront and the customer's account (§2.3, §3.6), and the customer screens staff see (§3.7) | The public side, which needs the layouts of step 1 and the read models Access already has |

**Step 0 in detail** — written out in [frontend-step-0.md](frontend-step-0.md), which the owner
approves before any of it is built. Each becomes an amendment to the named module's spec:

| # | Module | What is built |
|---|---|---|
| P1 | Platform | A contract method by which a module uploads a file **for its own use**, Platform checking that module's permission for the change. Without it, a staff member with no media permission cannot set their own picture, and B2B cannot take company documents in stage 3 |
| P2 | Platform + Access | Every declared permission carries a **group** (business area), named in Arabic and English, so the role editor and the comparison table group actions the way staff think |
| P3 | Access | The staff account remembers **which store the person is working in**, with the fallback of §2.2 when that store leaves their scope |
| P4 | Access | The sign-in code page receives the **masked phone** — the last 3 digits, masked by Access, never the whole number. Verified 2026-09-22: nothing exposes it today |
| P5 | Access → `app/Http` | `FormErrors` moves beside `ProblemDetails`, so Platform's screens answer forms the same way without importing Access |
| P6 | Platform | The **menu registry** (R8): entries with their permissions, declared by each module |

---

## 1 · Foundation

### 1.1 Packages

Latest versions checked on 2026-09-19. The build pins exact versions when it starts and records
them here.

| Package | Checked | Role |
|---|---|---|
| `inertiajs/inertia-laravel` | v3.3.4 (supports Laravel 13) | Server side of Inertia |
| `@inertiajs/react` | 3.7.1 | Client side of Inertia |
| `react` | 19.3.0 | UI |
| `typescript` | 7.0.2 | **[DECIDED 2026-09-19]** TypeScript, not JavaScript |
| `tailwindcss`, `@tailwindcss/vite` | ^4 (already in `package.json`) | Styling |
| `shadcn` (CLI) | 4.21.0; **4.21.1** for the rebuild (2026-10-03, §1.11) | Components, copied into the repository |
| `radix-ui`, `cn` | 1.6.7, 0.4.0 (installed 2026-10-03) | Under shadcn's components: Radix's primitives, and shadcn's class-merging helper |
| `cmdk` | 1.1.1 (2026-10-03) | Under shadcn's Command (the combobox's list) |
| `input-otp` | 1.5.0 (2026-10-03) | Under shadcn's InputOTP, the SMS code boxes (owner, 2026-10-02) |
| `@dnd-kit/core`, `/sortable`, `/modifiers`, `/utilities` | 6.3.1, 10.0.0, 9.0.0, 3.2.2 (2026-10-03) | Under shadcn's `dashboard-01` drag handles, for the address form's field order - by mouse, touch or keyboard (owner, 2026-10-03) |
| `sonner` | 2.0.8 (2026-10-03) | Under shadcn's toasts; `next-themes`, which shadcn adds with it, is removed (§1.11 edit 2) |
| `tightenco/ziggy` | v2.6.4 | **[DECIDED 2026-09-19]** Links to named routes, with TypeScript types |
| `spatie/laravel-typescript-transformer` | 3.3.0 (supports Laravel 13) | **[DECIDED 2026-09-19]** TypeScript types generated from page data |
| `spatie/laravel-data` | 4.23 (already installed) | Page data classes (presentation layer only, handoff §3) |
| `pestphp/pest-plugin-browser` | v5.0.1 (needs Pest ^5.0.4, PHP ^8.4, `ext-sockets`) | **[DECIDED 2026-09-19]** Browser tests |

Local machine, checked 2026-09-19: Node 20.19.6, npm 11.6.4, PHP 8.4.25 with `sockets` loaded.
There is no `package-lock.json` yet. The build commits one.

**To check when the build starts** (not verified yet): that TypeScript 7 works with the Vite
and Inertia tooling of the day, and which Node version Vite 8 needs.

### 1.2 Where the code lives

`docs/STRUCTURE.md` already places the React code in `resources/js/` and each module's page data
in `Presentation/Http/Resource/`. **[DECIDED 2026-09-19] Pages are grouped by module**, mirroring
`src/Modules`. Inside `resources/js/`:

```
resources/js/
├── app.tsx              Client entry
├── ssr.tsx              SSR entry
├── pages/{Module}/      One folder per module: pages/Access/Admin/SignIn.tsx
├── layouts/             Storefront, account and admin shells (§2)
├── components/ui/       shadcn components, generated by its CLI
├── components/          Our own shared components
├── lib/                 t(), route helpers, formatting
└── types/generated/     Generated types, never edited by hand
```

A module's controllers stay in its own `Presentation/Http/Controller/`, and its page data classes
in `Presentation/Http/Resource/`. Only the React files live outside the module.

### 1.3 Server-side rendering

**[DECIDED 2026-09-19] Every page is rendered on the server, admin included.** Inertia v3 runs
SSR as a Node process started by `php artisan inertia:start-ssr` (Inertia v3 docs). In production
it must run all the time, like the queue worker and the scheduler. Hosting is not chosen yet
(handoff §15.4).

Consequences:
- Every component, including admin-only ones (charts, editors, the media uploader), must render
  without a browser: no `window` or `document` while rendering.
- **[DECIDED 2026-09-19]** If the SSR process is down, or a page fails to render on the server,
  the page is rendered in the browser instead and the failure is logged. Nobody sees an error
  because of SSR. The test list covers this (§7).

### 1.4 Links

**[DECIDED 2026-09-19] Ziggy, with TypeScript types** (`php artisan ziggy:generate --types`, Ziggy
README).

**[DECIDED 2026-09-19] Routes are sent per area.** Ziggy's `groups` split the routes: storefront
pages receive only the `storefront` group, admin pages only the `admin` group. A shopper's page
never contains the admin URLs. Ziggy's README says hiding a route is not protection, and
nothing here relies on it: every admin route still checks the person's permission in its handler.

- Every route has a name, and every name belongs to exactly one group. A test fails otherwise,
  and it also asserts that it found routes to check.
- Storefront links always carry the store and the language (`/sa/ar/...`), as generated links do
  today (project rules, "Stores").
- *Assumption, to verify when the build starts:* Blade's `@routes` directive does not reach the
  SSR renderer, so the route list goes to the page with the rest of the shared page data.

### 1.5 Text in two languages

**[DECIDED 2026-09-19, owner's delegation]** The screens' text lives in the **Laravel lang files**,
where the backend's text already is: `lang/ar`, `lang/en`, and each module's
`Presentation/lang/{ar,en}` (for example `access::auth`). There are no separate frontend
translation files.

- Each page names the translation files it needs, and receives only those, in the page's language.
  React reads them through one `t('access::auth.sign_in')` helper.
- A test fails if a key used by a page is missing in either language.
- Why this and not frontend files: there is one source for every text, and one missing-translation
  check. With SSR, the text arrives with the page, so nothing loads separately. The cost is the
  `t()` helper and each page's list of files.
- The page's language: the storefront's comes from the URL (`/sa/ar`); the admin panel's from the
  staff member's own `locale` (handoff §4.1). Arabic pages set `<html lang="ar" dir="rtl">`.

### 1.6 Page data

**[DECIDED 2026-09-19]** Each page's data is a `spatie/laravel-data` class in the module's
`Presentation/Http/Resource/`. `spatie/laravel-typescript-transformer` generates its TypeScript
type into `resources/js/types/generated/`. A renamed field then breaks the TypeScript check, not
the live page. `composer check` fails if the generated types are older than the PHP classes.

Page data classes are presentation only: they are built from a module's DTOs and never cross a
module boundary (handoff §4.3).

### 1.7 Forms and errors

Access's sign-in endpoints, being built now (step 3b, not yet merged), already answer forms with
**redirects** (Access amendment 12). The frontend builds on that pattern:

- A field that fails validation (a Form Request) comes back in Inertia's usual `errors`, next to
  its field.
- A business error (a `DomainError`, for example a wrong code) comes back as one form-level message,
  `errors.form`, translated into the person's language. Access's `FormErrors` does this today.
- A success message comes back as the flash `status`.
- A request asking for JSON still gets the RFC 7807 problem document (`app/Http/ProblemDetails.php`).

How these messages look is in §2.1. *Open (§4):* `FormErrors` is inside Access, and Platform's
admin screens need the same thing; where the shared version lives is decided with the owner.

### 1.8 Look: fonts, colours, digits

**[DECIDED 2026-10-01/02, owner] Geist is the design system** (https://vercel.com/geist), for the
whole system — admin panel and storefront, light and dark:

- **Geist supplies the components, their behaviour and all its rules**, everywhere, its writing rules
  included: Title Case English buttons that name what happens ("Approve Company", not "Submit"), a
  destructive action paired with its toast ("Delete Product" → "Product deleted"), `loading` on a
  button rather than a spinner in its place, a disabled control explained by a tooltip, and the rest
  on each component's page. Arabic follows the same structure. Where a screen needs something Geist
  has no component for, it is built from Geist's own parts and rules.
- **The look stays TouchWood's** (owner's pick from a side-by-side comparison, 2026-10-02): today's
  colours — navy, copper, blue-grey, the navy admin sidebar — **made a bit sharper** (more contrast),
  today's fonts (IBM Plex Sans Arabic for both languages, IBM Plex Mono for figures) and today's
  10 px corners. The colour tokens keep today's names and roles; only their values are sharpened.
- **A theme is still data** (below): Geist's components read our tokens, so light, dark and campaign
  themes keep working without touching a component.
- **Order:** the Geist foundation is built before any new screen (B2B step 7 included), and every
  screen built so far moves to it, in batches. Some of Geist's pages need the owner's Vercel login.
- The paragraphs below stand where they do not contradict these: the v2 design's **colours, fonts and
  layouts** remain the source of the look; its **components and their behaviour** give way to
  Geist's.

**[DECIDED 2026-09-19, design of record replaced 2026-09-22]** The owner's design decides fonts,
colours, spacing and layouts. The design of record is now the handoff of 2026-09-22 in
`docs/design/v2/`: **`TouchWood Admin.dc.html`** for the panel, `TouchWood Foundations.dc.html` for
the shared parts, and `TouchWood Home.dc.html` with `SysStore`/`SysHome` for the storefront.
`docs/design/admin-panel-v1.html` stays as history. It is the same line as v1 — navy, cream, IBM
Plex Sans Arabic with IBM Plex Mono for figures — so nothing already decided about the look
changes. The four alternative design systems in the handoff's `_ds/` folder are explorations and
are **not** followed (owner, 2026-09-22). It shows look and behaviour only. Where it shows something the specs do not have, or contradicts a decided rule, the rule
wins (§2.7). Screens the design does not show are derived from its look (§2.1).

- **Fonts:** IBM Plex Sans Arabic for text in both languages, IBM Plex Mono for figures (amounts,
  counts, codes), as in the design.
- **Colours:** the design's palette, light and dark (§2.1), becomes the shadcn colour tokens.
- **[DECIDED 2026-09-19] Digits:** Arabic pages show Arabic-Indic digits (٠–٩), except in codes
  (SKU, order number) and phone numbers, which keep 0–9. Every number input accepts both kinds of
  digits. This replaces the design's mix (figures in 0–9, numbers inside sentences in ٠–٩).
- **Checked in the design's own font files, 2026-09-19:** IBM Plex Mono covers Latin only, with no
  Arabic-Indic digits. On Arabic pages, figures therefore use IBM Plex Sans Arabic, which has them.

- **[DECIDED 2026-09-22] A theme is data, not code.** Every colour, radius, font, spacing value and
  shadow is a CSS custom property on `:root`, overridden by `[data-theme="..."]`. Light and dark are
  simply the first two themes. The owner intends **campaign themes** — the whole system dressed for
  National Day, Ramadan, a sale — each a named set of values, and the admin design already treats
  campaigns as a first-class thing. So:
  - No component ever carries a colour, a font or a radius of its own. A guard test fails the build
    when a hex colour, an `rgb()` or a hardcoded font size appears anywhere under
    `resources/js/pages/` or `resources/js/components/` (outside the token file).
  - The theme is chosen **on the server** and rendered into the page, as light and dark already are
    (§2.1), so a campaign theme arrives with no flash and SSR is unaffected.
  - Where a campaign's values will come from — a record in Promotions (stage 6), with dates — is not
    this stage's business. What this stage guarantees is that adding one later is **data and a
    stylesheet, never a change to a component**. Nothing here assumes only two themes exist.
- shadcn is set up for right-to-left (`rtl: true` in `components.json`, with its `DirectionProvider`).
  Its docs say the automatic conversion only works for projects created with `shadcn create` using
  its new styles. The project starts that way.
- **The currency signs** (platform.md §5.1): before this stage merges, the Riyal sign (`U+20C1`) and
  the Dirham sign (`U+20C3`) are checked on a real price in the chosen font. A sign the font cannot
  draw is cleared, and that currency shows its letters until the font supports it. **First check,
  2026-09-19:** neither IBM Plex Sans Arabic nor IBM Plex Mono, as embedded in the design, covers
  either sign (their declared Unicode ranges stop short of them, and a test render fell back to
  another font). Unless the released fonts differ, both signs are cleared and SAR and AED show their
  letters (ر.س / SAR, د.إ / AED). The check is repeated on the fonts the build installs.

### 1.9 Checks

`composer check` grows beyond config:clear → pint → phpstan → deptrac → pest. The steps added:
generating the TypeScript types (page data and Ziggy) and failing if they changed, the TypeScript
check, and the browser tests, which Pest runs with the rest.

CI needs Node, the `sockets` PHP extension (not in `ci.yml`'s extension list today) and
Playwright's browsers.

### 1.10 The Geist foundation (built 2026-10-02)

What §1.8's decision becomes in code. Written and built in the owner's overnight run: the owner
chose the colours (set B) before leaving; every other pick below was the builder's, provisional,
as the owner instructed for that night — **settled by the owner on 2026-10-02/03**: the type,
surfaces, sizes and store time stand; the components, the pieces Geist lacks and the theme switch
are replaced by §1.11. Geist's rules were read
from its own pages (vercel.com/geist, every component's "Best Practices") and its sizes from its own
stylesheet, the same day.

- **Colours (owner's pick, 2026-10-02): set B, "a bit sharper".** Every token keeps its name and
  hue; text and lines are a step stronger and brand and status colours a step more saturated,
  measured in OKLCH. Light-mode muted text on a card goes from 4.90:1 to 6.31:1. The values are in
  `resources/css/themes.css`, light and dark.
- **Type**: Geist's type scale under Geist's own class names — `text-heading-{14…48}`,
  `text-label-{12…20}` (and `-mono`), `text-copy-{13…24}` (and `-mono`), `text-button-{12,14,16}` —
  with the sizes, line heights and weights of Geist's stylesheet, set in our fonts. Geist's negative
  letter-spacing on headings applies to English only: Arabic letters are joined and are never spaced.
- **Surfaces**: Geist's materials under Geist's names — `material-base`, `-small`,
  `-medium`, `-large` on the page; `material-tooltip`, `-menu`, `-modal`, `-fullscreen` above it —
  with Geist's shadow recipes, each a theme token so a campaign can change it. Corners are ours, not
  Geist's 6 and 12 px: **10 px** everywhere, 6 px on a tooltip, 16 px fullscreen.
- **Sizes** (Geist's stylesheet): controls are 32, 36 and 40 px high (small, medium, large; medium
  is the default); the focus ring is Geist's — a 2 px gap in the page colour, then 2 px of brand.
- **Components** — *replaced by §1.11 (owner, 2026-10-02)*. The overnight build hand-made them in
  `resources/js/components/geist/`; they give way to shadcn's own code, from its CLI, with Geist's
  look and rules on top, and that folder is deleted once nothing imports it. The components' few
  words of their own (Cancel, Close, Previous, Next, the typed confirmation's prompt, a sidebar's
  screen-reader words) stay in `lang/*/ui.php`, which every page carries (`App\Http\Page`).
- **Where Geist has no component** — *replaced by §1.11*: shadcn has all three — the sidebar block
  that collapses to icons (`sidebar-07`), its team switcher for the store picker, and
  `field-choice-card` for the address picker.
- **The theme switch** — *replaced by §1.11*: System, Light and Dark.
- **Store time** (owner, 2026-10-02: "each store will have its own — a viewer from Egypt sees
  Egypt's time, from KSA KSA's"; for someone in several stores, **the store they are working in**).
  The server and the database keep UTC; every moment on a screen is written in the zone of the
  panel's current store, or of the shop's store, with the zone's short name beside it — the store
  screens' own times included (failed jobs, audit log, media, sessions). Geist's `Time`: in a list
  a recent moment reads short and relative ("2h ago"), past seven days as a date, the full moment
  on hover and focus — the store's zone and UTC; on a detail page the full moment is the text.
  B2B's company times on the customer's page were already written by the server in the company's
  store clock (step 6) and are left as they are; **B2B's staff screens use `Time`, in the store
  being worked in** (b2b.md amendment 23(b), owner 2026-10-03).
- **Writing rules** (Geist's, for every English word on a screen; Arabic follows the same
  structure — a verb and its object on a button, the toast that answers it, no "please"):

| Where | Rule | Example |
|---|---|---|
| Buttons, menu items | Title Case, Verb + Noun, naming what happens; never "Submit", "OK", "Confirm" | Approve Company · Invite Member |
| A destructive button and its toast | Same verb, 1:1 | Delete Address → Address deleted |
| A mode switch | Ends with "Instead" | Use a Recovery Code Instead |
| An item that opens a dialog | Ends with "…" | Rename… |
| Labels, headings, column headers, tabs, badges, modal titles | Title Case nouns or statements, never a question | Email Address · Delete Address |
| Descriptions, helper text, notes, tooltips | Sentence case, one sentence, a period | Changing this region restarts all functions. |
| Toasts | Sentence case, "{Noun} {past participle}", no period, never "successfully" | Settings saved |
| Errors | "Couldn't …" for the person's own state, "Failed to …" for the system's; then the fix | Couldn't verify the code. Try again. |
| Validation | Names the field and the rule, a period, no "please" | Email address is required. |
| Placeholders | An example value, never an instruction | name@example.com |
| Unknown values | An em dash | — |
| Dismissal | "Cancel"; "Done" after a one-time display | |
| Never | "please", "successfully", "Unable to", "Something went wrong", "Oops" | |

### 1.11 shadcn's code under Geist's rules (owner, 2026-10-02 and 2026-10-03)

**The rule** (owner, 2026-10-02): never design or build a UI piece that Geist or shadcn already has
— use theirs, 100 %. Search both first: Geist's component pages, and shadcn's components, blocks
and examples. Only when neither has it, show the owner what was searched and ask before building.

- **Code**: every component comes from shadcn's CLI into `resources/js/components/ui/`; every block
  or example from shadcn's registry, as written (`sidebar-07`, `login-02`, `field`, `input-group`,
  `item`, `empty`, `field-choice-card`, `combobox-demo` and the like). Nothing in them is changed
  except the edits below. `resources/js/components/geist/` is deleted once no page imports it.
- **Look**: our tokens (§1.8, §1.10) feed shadcn's token names, as `resources/css/app.css` already
  does — colours, Geist's type scale and materials, 32/36/40 px controls, Geist's focus ring, 10 px
  corners. A theme is still data.
- **Rules**: Geist's rules sit on top of shadcn's code — each component's "Best Practices", and the
  writing rules of §1.10. **Where the two disagree, Geist's rule wins**: a loading button stays
  focusable; a field stays editable while it saves; an action that cannot be done is shown
  disabled, with the reason.
- **The only edits to shadcn's code** (five), each written down where it is made:
  1. **`accent`** — TouchWood's copper keeps the name `accent`; shadcn uses `accent` as its neutral
     background - hover, focus, open, selected, pressed, and Skeleton's fill - which would paint
     copper behind dark text (2.3:1). After every install, every background shadcn paints with
     `accent` is changed to the neutral `muted`; `accent-foreground`, which is our ink, stays.
  2. **Toasts** — shadcn's Sonner reads the theme from our server's page data, not from
     `next-themes` (a one-line change).
  3. **Right to left** — shadcn's `DirectionProvider` wraps the app (`rtl: true` in
     `components.json`).
  4. **The sidebar's rail in Arabic** (owner, 2026-10-03) — shadcn's `SidebarRail` places itself with
     `-right-4` / `left-0`, which in Arabic puts it on the far side of the screen; ours writes
     `-end-4` / `start-0`, so it stays on the sidebar's edge. Put back after every reinstall; a test
     checks it.
  5. **shadcn's own screen-reader words** (owner, 2026-10-03, after the review of the foundation) —
     a few words shadcn writes in English inside its code (the phone sidebar's title and
     description; later a dialog's "Close", the pager's "Previous" and "Next", "Loading") read our
     words from the design system's file (`lang/{ar,en}/ui.php`), each where a prop cannot reach it.
     Words a prop can give - the sidebar trigger's, the rail's, the breadcrumb's - are given from
     outside, with no edit.
- **The CLI's CSS**: installing a component may add shadcn's default colours to `app.css` (the
  sidebar's, for one). They are not kept: our tokens already feed those names (Look, above).
- **Geist's pieces that shadcn lacks**, built exactly from Geist's own page, with no invention:
  Loading Dots, Middle Truncate, Copy Button, Description, Theme Switcher, and Progress "with
  stops" (shadcn's Progress has no stops; the bar itself stays shadcn's).
- **Earlier choices that give way to Geist** (owner: "Geist, keep store time"): a setting's switch
  saves the moment it flips (Settings are no longer read-only until "Edit"); a Delete that cannot
  be done is shown disabled, with the reason as its tooltip (media); B2B's staff screens show their
  moments through `Time`; store time (§1.10) stays — the hover shows the store's zone and UTC.
- **SMS codes**: shadcn's InputOTP, one box per digit (the package `input-otp`), on staff sign-in,
  accepting an invitation, the staff Change Phone Number dialog and the customer's Change Phone
  Number. The server sends each page the code's length; Latin and Arabic-Indic digits are both
  accepted.
- **The theme switch**: Geist's Theme Switcher as it is — **System, Light, Dark** — once per area
  (Geist: "once per app, in the footer or settings"), instead of today's three places:
  - **panel**: the sidebar's footer, small, inside the person menu — one click from any page, the
    sidebar collapsed included;
  - **shop**: the footer, small;
  - the **panel's** sign-in pages lose it. The shop's pages, its sign-in pages included, keep it in the shop's footer, which every shop page has.
  Light and Dark are still rendered by the server. For System, a few lines of script in the page's
  head choose the theme from the device before the first paint (as Geist's own setup does), so
  nothing flashes; campaign themes are unaffected. Access's preferences endpoint accepts `system`
  besides `light` and `dark`. **A first visit, before any choice, is System** (owner, 2026-10-02),
  replacing "Light until the person chooses" of 2026-09-19. The app sends no Content-Security-Policy
  today (checked 2026-10-02); if hosting adds one, that script needs its nonce or hash.
- **Toasts**: shadcn's Sonner (edit 2).
- **The pieces neither system has — the owner's answers** (2026-10-02, one by one, each with a
  picture):

| # | Piece | Answer |
|---|---|---|
| 1 | Panel page title: big title, one-line subtitle, the page's main action | **Keep it**, built from shadcn's parts with Geist's type |
| 2 | A dot on a collapsed sidebar icon while something waits (failed jobs) | **Keep it**; a screen reader hears the count |
| 3 | The language switch | **Keep the button** showing the other language's name; one press switches |
| 4 | The signed-in shopper in the shop header | **shadcn's user menu**: the name opens a menu with My Account and Sign Out (`sidebar-07`'s nav-user pattern) |
| 5 | The permissions table on the roles screen | **A plain table**: the area is its own column, nothing sticks — replacing the sticky area rows |
| 6 | The yes/no mark in each cell | **Keep as now**: a tick in a green square, a minus for no |
| 7 | A row in the roles list | **The whole row opens the role**; Edit Role moves into the row's ⋯ menu (shadcn Item + DropdownMenu) |
| 8 | The staff list, grouped | **A heading per group** (Admins, each store, Centralized), each group a shadcn Item group |
| 9 | Editing a file's description in the media library | **shadcn's Dialog** with the form, Cancel and Save Description, in place of the extra table row |
| 10 | The steps beside the company page | **Geist's Progress with stops** (b2b.md amendment 22(b)) |
| 11 | How a company field shows its state | **The mix** (b2b.md amendment 22(a)): the save state inside the field's end, the reason under it, red the only edge colour |
| 12 | Picking a saved address on the company form | **shadcn's field-choice-card**, each tile naming its store, no headings (b2b.md amendment 22(c)) |
| — | The phone-number fields | No question: plain shadcn Inputs with a helper line; nothing is built |
| — | The two-step phone change (number, then code) | No question: two forms of Input and Button, InputOTP for the code |
| — | The TouchWood mark | No question: our own logo, an SVG |

- **Applied in the rebuild, each a Geist rule** (owner, 2026-10-03, from the overnight review):
  - a page header keeps **one main button** and puts the rest in a ⋯ menu, destructive last (Geist
    Button: "Switch to a Menu or Split Button when more than one related action shares a row") —
    the staff member page first;
  - **a confirm dialog** before ending another browser's session, Forget Browser, Forget All
    Browsers, and removing a file from a company draft (Geist Modal: "Confirm destructive actions in
    a Modal");
  - **words on every badge**, never colour alone (Geist Badge) — the customer list's Email and
    Phone badges first;
  - the media library's Table/Grid switch is a toggle;
  - the account tabs live in the address, so a refresh keeps the tab; the "Close Account" tab is
    named with a noun;
  - a dialog's main button repeats its title's verb;
  - the sidebar's screen-reader words are in Arabic too.
- **Error messages in Geist's form** (owner, 2026-10-03: inside the rebuild): every module's error
  messages — Access's, Platform's and B2B's `Presentation/lang/{ar,en}/errors.php` — are rewritten:
  "Couldn't …" for the person's own state, "Failed to …" for the system's, then the fix; titles
  are Title Case statements. The tests that read them change with them, never weakened.

---

## 2 · Layouts

Four layouts: admin, storefront, account (inside the storefront) and sign-in. Error pages use the
layout of the area they occur in.

### 2.1 What every layout shares

- **Look:** the design's fonts, colours, radius and spacing (§1.8). Screens the design does not show
  are built from its parts: cards, filled inputs with the label above, pill filters, tables with
  small upper-case headings, toasts **[DECIDED 2026-09-19]**.
- **Direction:** Arabic pages mirror the whole layout, sidebar included, as the design does.
- **[DECIDED 2026-09-19] Light and dark themes, admin and storefront**, chosen with a toggle and
  remembered **per browser** in a cookie, so the server renders the right theme with no flash.
  **Light until the person chooses.** The design's dark palette (`html[data-theme="dark"]`) is used
  as it is. Every screen is checked in both themes.
- **[DECIDED 2026-09-19] Fonts are served from our own domain**, not a font service.
- **[DECIDED 2026-09-19] Phones are supported, admin and storefront.** The design has no phone
  version. Below tablet width the admin sidebar becomes a slide-in menu, and tables scroll
  sideways inside their card (as the design already does on narrow screens).
- **Success** shows as a toast at the bottom centre (the design: "Invitation sent.",
  "Settings saved."). It is the flash `status` of §1.7.
- **[DECIDED 2026-09-19] A business error shows twice:** as a red toast, and as a red message next
  to what it concerns — under the field it names, otherwise at the top of the form. The message
  stays until the person changes the form; the toast fades. Validation errors (§1.7) show under
  their fields.

### 2.2 Admin

From the design, with the decided rules applied:

- **Sidebar:** the logo with "Admin panel"; groups that open one at a time; count badges; at the
  bottom, the person's avatar, name and role, and the language toggle (ع / EN). The language is not
  in the URL **[DECIDED 2026-09-19]**. The toggle changes **only what is displayed**, at once; the
  staff member's saved `locale` is their communication language (emails, SMS codes) and changes
  only in their own settings (Access amendment 16). **[DECIDED 2026-09-19]** The display choice is
  remembered **per browser**, in a cookie, like the theme. With no cookie, the panel shows the
  person's saved language.
- **The menu shows only what the person may do** (handoff §14), read from Access's `MyPermissions`.
  A group with nothing the person may use is not shown.
- **[DECIDED 2026-09-19] Modules not built yet appear as "coming soon"** entries with a placeholder
  page (the design's "This screen is next in the build queue"), **to Super Admins only**. Their
  permissions do not exist yet, so they cannot be checked; everyone else sees only screens they
  can use. A module's entries become real, permission-checked items when its screens ship.
- **Header:** the sidebar toggle, breadcrumbs, the store picker, and "View store". **[DECIDED
  2026-09-19] The search box (⌘K) and the notifications bell are hidden** until a module gives them
  content: search with Catalog and Sales, the bell with Ops.
- **[DECIDED 2026-09-19] The store picker** is **remembered on the staff account**. URLs carry no
  store (`/admin/...`). Store-free screens (media, roles) ignore it.
  - It never shows a store outside the person's stores.
  - One store: the header shows that store's name, with no menu.
  - Two or more: a picker of exactly those stores.
  - If the remembered store is no longer one of theirs (an admin removed it), the panel opens in
    their first remaining store (by store position) and a toast says so: "You no longer have
    access to Egypt — showing KSA." If the store is given back later, it returns to the picker.
  - This needs a new field on the staff account: an Access amendment, listed in §4.
- **Page frame:** a title and a one-line subtitle, with the main action at the top right.

### 2.3 Storefront

**[Superseded 2026-09-22]** The storefront now has a design of its own — `TouchWood Home.dc.html`,
`SysStore.dc.html` and `SysHome.dc.html` in `docs/design/v2/` — and step 4 follows it, reviewed
screen by screen as the admin's were (owner, 2026-09-22). What follows was written when there was
no storefront design and still holds for anything those files do not show:

- A header with the logo, the country (store) switch and the language switch (`/sa/ar` ↔ `/sa/en`,
  staying on the same page), and "Sign in", or the customer's name opening a menu with My Account
  and Sign Out (§1.11 #4). Search and the cart come with Catalog and Sales.
- A small footer, holding the theme switch (§1.11, 2026-10-02).
- **[DECIDED 2026-09-19] Platform's two Blade pages are rebuilt in React:** the country page at
  `brand.com/` and the placeholder store home (platform.md §3). Their tests move with them.

### 2.4 Customer account

Inside the storefront layout: tabs like the design's "Account & settings" (Account · Security ·
…). Which tabs, and what each holds, is section 3.

### 2.5 Sign-in pages

The design has the admin sign-in's text but no screen for it. Built from its look: a card with the
form, next to a brand panel with the design's line "One panel for catalog, orders, companies and
campaigns." and "Saudi Arabia · Egypt · United Arab Emirates". The brand panel is hidden on phones.
The same layout serves the SMS code, accepting an invitation, and password reset, for staff and
customers (the storefront's with its own text).

### 2.6 Error pages

Each area's error pages use its own layout (admin or storefront), in the page's language, with a
way back (the dashboard or the store home). They show the translated title that
`ProblemDetails` already produces, never a developer message. Stage 2b adds the missing pages
for 409, 413, 415 and 422 (platform.md §9.3).

### 2.7 What the design shows that the system does differently

The owner's direction, 2026-09-19: the design is look and behaviour only.

| In the design | In the system | Rule |
|---|---|---|
| "Keep me signed in" on the staff sign-in | Not offered: staff sessions end after 30 minutes idle and 12 hours at most | access.md §1.8 |
| A two-factor on/off switch | No switch: every staff member signs in with an SMS code | access.md §1.8 |
| Amounts in Arabic written "SAR" | The sign where one is set, else the letters in the page's language (ر.س) | platform.md §5.1 |
| Every menu item and all three stores shown | Only what the person may do, and only their stores | handoff §14 |
| Digits mixed on Arabic pages | Arabic-Indic, except codes and phones | §1.8 |
| Out-of-scope entries (cashback, wallet top-ups, profit report, tickets, warehouse pickup…) | Not built, and not listed as "coming soon" either | handoff §14, §16 |

---

## 3 · Screens

Written group by group with the owner. For each screen: the page's URL (a `GET` route added in this
stage), the endpoint its form posts to, what it shows, and its states. Screens the design does not
show are derived from its look (§2.1) and reviewed here by the owner.

Refusals are the owning module's errors, shown as §2.1 says; a screen never invents its own
wording for them.

### 3.1 Admin sign-in and emailed links

Access §1.4, §1.6, §1.8, §4.4. The endpoints are Access's (amendment 12), built in its step 3b; the
route names below are those of that work in progress and are rechecked when it merges. Every page
here uses the sign-in layout (§2.5). A staff member already signed in who opens one of them goes
to the dashboard.

- **[DECIDED 2026-09-19] After signing in, always the dashboard**, as Access's endpoints do today,
  including after a session timeout.
- **[DECIDED 2026-09-19] Language before anyone is signed in:** the display cookie if the browser
  has one (§2.2), otherwise **Arabic**, with the ع / EN toggle on the page. The invitation pages (A6,
  A7) use the communication language the admin chose for that person.

| # | Screen | Page | Posts to | Shows |
|---|---|---|---|---|
| A1 | Sign in | `/admin/sign-in` | `access.staff.sign-in` | Work email, password, "Forgot password?". No "keep me signed in" (§2.7). |
| A2 | New phone | `/admin/sign-in/phone` | `access.staff.sign-in.phone` | Only after a correct password, for an account with no phone (a Super Admin whose phone was reset by console, access.md §1.6). A phone number with its country code. |
| A3 | SMS code | `/admin/sign-in/code` | `access.staff.sign-in.code`; resend: `access.staff.sign-in.resend` | "Code sent to •••••••180": **[DECIDED 2026-09-19]** the last 3 digits of the number, passed masked by Access (§4). One box per digit (the length is a setting, 4–8, Access amendment 22); "Trust this browser for 30 days" (the number of days from the setting); "Resend code", disabled with a countdown until a resend is allowed. |
| A4 | Forgot password | `/admin/password/forgot` | `access.staff.password.forgot` | Email. The answer is always the same, whether or not the account exists. |
| A5 | New password | `/admin/password/reset/{token}` | `access.staff.password.reset` | New password and its confirmation, with the rule in words (at least 12 characters, from the setting). Afterwards: the sign-in page, where the SMS code is still asked. |
| A6 | Accept invitation | `/admin/invitation/{token}` | `access.staff.invitation.accept` | The person's name and email (read only), a password and its confirmation, and the phone the admin entered, which they may correct (Access amendment 15). |
| A7 | Invitation code | `/admin/invitation/{token}/code` | `access.staff.invitation.confirm` | The code, as on A3. Afterwards: signed in, on the dashboard. |
| A8 | Confirm new email | `/admin/email-change/{token}` | `access.staff.email-change.confirm` | The new address and a "Confirm" button. Afterwards: the sign-in page. |
| A9 | Sign out | In the sidebar's person block | `access.staff.sign-out` | Afterwards: the sign-in page, "You signed out." |

- **A2, A3 and A7 open only in their place in the flow** (after a correct password; after A6). Opened
  any other way, they send the person back to A1 or A6.
- **Opening a link never changes anything.** A6, A7 and A8 act only when the person presses the
  button: mail scanners open links on their own.
- **A dead link** (expired, already used, or replaced by a newer one) shows one page: the link no
  longer works, and what to do — ask an admin for a new invitation, or request a new reset link.
- **A3's refusals:** a wrong code (the code dies after the allowed wrong tries), an expired code,
  and the hourly limit (3 an hour, Access amendment 28), each with Access's own message.
- **The person block** (the design's name, role and avatar at the bottom of the sidebar) opens a
  small menu: "Account & settings" and "Sign out". The design has no such menu.
- **Session ended** (30 minutes idle or 12 hours): the next click shows A1 with a message that the
  session ended.

### 3.2 My account (staff)

`/admin/account`, from the person block's menu (§3.1). The design's "Account & settings", with its
three tabs. Every staff member has it (`access.own_account.update`, automatic). What each tab
edits is what Access already allows (`UpdateOwnStaffProfile`, `ChangeOwnStaffPhone`,
`ChangeOwnStaffPassword`, notification preferences; access.md §1.4, §3.2).

| # | Tab | Shows and edits |
|---|---|---|
| B1 | Account | Picture; first and last name, job title, date of birth, country, address; the **communication language** (emails and SMS codes, Access amendment 16), labelled so it is not confused with the display toggle. The email is read only, with "Ask an admin to change it"; a Super Admin instead has "Change email", which sends a link to the new address (Access amendment 17) and shows the change as pending until it is used. |
| B2 | Account → phone | The phone, with "Change": a dialog asks for the **current password** (R2) and the new number, sends a code to it, and takes the code. The old number stays in use until the new one is confirmed (access.md §1.4). A wrong password is counted like a wrong one at sign-in, so the dialog shows the lockout message too. |
| B3 | Security | Change password: current, new, confirmation, with the rule in words. Afterwards: "Every other session was signed out." (Access's message). No two-factor switch (§2.7). |
| B4 | Notifications | For each topic — new orders, company applications, low stock, campaign expiry — an email switch and an in-panel switch (access.md §1.4). **[DECIDED 2026-09-19]** Each switch saves as it is flipped, with a small "Saved" toast. |

- What a change ends is Access's rule, not the screen's (a new password or phone ends every trusted
  browser, access.md §1.8); the screens only show Access's result.
- A Super Admin's phone changes here too, confirmed by a code (access.md §1.6).
- **[DECIDED 2026-09-19] The picture is uploaded through a new Platform contract method**: a module
  uploads a file for its own use, and Platform checks that module's permission for the change —
  here `access.own_account.update`. `PlatformApi` has no upload method today, so every staff member
  without `platform.media.upload` could not set a picture. B2B needs the same method for company
  documents in stage 3. A Platform amendment, built in this stage (§4).
- **[DECIDED 2026-09-19] No list of active sessions** (the design shows one). A new password already
  ends every other session. If it is wanted later, it is an Access change.

### 3.3 Staff

access.md §1.4, §1.5, §3.2. Everything here is shown only to someone allowed to do it: the list to
`access.staff.view`, each action to its own permission, and only for people the viewer's stores
cover (access.md §1.5, amendments 9 and 11). A Super Admin sees and manages everyone, and no admin
manages another admin or themselves.

| # | Screen | Page | Shows |
|---|---|---|---|
| C1 | Staff | `/admin/staff` | The design's list — picture, name, email, role — **grouped by store, with a "Centralized" section** for people working in two or more stores (access.md §1.5). **[DECIDED 2026-09-19]** In place of the design's "last seen": the status (Active, Invited, Disabled) and the date they were invited or joined; nothing new is written on ordinary page loads. Filters by status, and a search by name or email. "Invite member" appears with `access.staff.invite` (which also needs `access.staff.assign_role` in the new person's stores, Access amendment 23). **Admins are a separate short section** above the rest, showing a **name and a role only** — no picture, email, status or date — for anyone but a Super Admin (R1). The list is ordered **by name** for such a reader, because ordering by the joining date would give an admin's away. |
| C2 | One staff member | `/admin/staff/{id}` | Profile, status, communication language; their role, what it allows, their stores and each action's exceptions; the actions they may be given, each as a button. |
| C3 | Invite | `/admin/staff/invite` | **[DECIDED 2026-09-19] Three steps** with a progress line — profile, then role, then stores — sent at the end. The profile is what Access requires (email, first and last name, job title, date of birth, country, phone, communication language; address and picture optional — amendment 15). Leaving before the last step sends nothing. |
| C4 | Edit profile | On C2 | The same fields, except the email and the role. Changing the phone also needs every action of the person's role (Access amendment 26). |
| C5 | Change email | On C2 | The new address; the change happens when the link sent there is used (Access amendment 17). Someone who never accepted their invitation gets a new invitation at the new address instead (amendment 25). |
| C6 | Role and stores | On C2 | Access's screen (§1.5): every action ticked or not, one row of store boxes that fills every action, and store boxes per action for exceptions. Store-free actions show their boxes ticked and disabled (amendment 4). Actions the admin does not hold cannot be ticked. Saving an edited saved role here makes it that person's **personal role**. |
| C7 | Disable / Enable | On C2 | Disabling ends their sessions and trusted browsers at once (Access). Enabling someone with no role asks for the role in the same step (amendment 27) — a case a revoke no longer creates, since revoking a Super Admin closes the account outright (R3). |
| C8 | Invitation | On C2, while `INVITED` | Resend (a new link; the old one dies) or cancel. Resending also needs every action of their role (amendment 26). |
| C9 | Refresh permissions | On C2 | Rebuilds this person's cached permissions (`RefreshStaffPermissions`, amendment 10), for an admin who wants the change to take effect at once. |

- **Refusals are Access's**: `PermissionEscalation`, `AdminOnlyPermission`, `SuperAdminOnly`,
  `StaffEmailInUse`, `InvalidStaffStatus` (amendment 21), each shown as §2.1 says.
- **A Super Admin** appears in the list as one, and shows no management buttons at all: they are
  created and removed only by console command (access.md §1.6).

### 3.4 Roles

access.md §1.5, §3.2 (`ListRoles`, `ViewRole`, `RoleEditorPermissions`, `CreateRole`, `CloneRole`,
`UpdateRole`, `DeleteRole`, `RefreshRolePermissions`). The design's "Employee permissions". Seen by
someone with `access.role.manage` or `access.staff.assign_role`; `access.role.manage` is store-free
(amendment 4). **Only a Super Admin** creates, clones, edits or deletes an **admin** role; admins
see admin roles in the list but cannot open them for editing (amendment 9 and the step 2 round).

| # | Screen | Page | Shows |
|---|---|---|---|
| D1 | Roles | `/admin/roles` | Saved roles with their name in the display language, their level (admin or staff), and how many hold each. "New role" and, on a row, "Clone". Personal roles never appear here (access.md §1.5). |
| D2 | One role | `/admin/roles/{id}` | Its actions, and its holders — only those the viewer manages, plus the total count (amendment 8). Buttons: edit, clone, delete, refresh. |
| D3 | New / edit role | `/admin/roles/new`, `/admin/roles/{id}/edit` | The name in Arabic and English (each unique among saved roles, ignoring case — amendment 7), the level, and the actions: everything the author holds, with the rest not offered. At least one action (amendment 7). Store-free actions are marked as such; stores are not part of a role, they are chosen per staff member (§3.3 C6). |
| D4 | Delete a role | On D2 | If anyone holds it, a saved role of the same level must be picked as the replacement, and every holder moves to it (amendment 7). Without one the delete is refused, listing the holders. |
| D5 | Refresh | On D2 | Rebuilds the cached permissions of the role's holders (`RefreshRolePermissions`, amendment 10). |

- **[DECIDED 2026-09-19] Actions are grouped by business area** on every screen that lists them
  (D3 and §3.3 C6): "Catalog and variants", "Pricing and campaigns", "Orders and fulfilment",
  "Company approvals", "Staff and permissions", "Store settings and tax" — the design's groups,
  which handoff §14 also names. Each permission therefore carries a group, named in Arabic and
  English, when its module declares it: a change to the permission catalog and to Platform's own
  list, listed in §4. Modules built later pick a group for each permission they declare.
  **[DECIDED 2026-09-29]** A **System** area joins them, for the running of the system — first the
  failed jobs (E7) — with its own section in the menu.
- **[DECIDED 2026-09-19] The design's "Permissions by role" table is kept**, on D1: groups down the
  side, saved roles across the top, scrolling sideways as roles are added — **[2026-10-02, owner]
  as a plain table: the area its own column, nothing sticky** (§1.11 #5).
- A role's Arabic and English names are both asked for on one screen, whichever language the panel
  is being read in.
- Editing a saved role changes it for everyone holding it; the screen says so before saving, with
  the number of holders.

### 3.5 Platform's admin screens

platform.md §1 and §3. These are the "view" use cases Platform left until Access and the frontend
existed (platform.md §3, §9.2 #19). Each screen shows only the stores in the person's scope.

| # | Screen | Page | Shows |
|---|---|---|---|
| E1 | Stores | `/admin/stores` | The design's card per store: name, currency, tax rate, timezone. `platform.store.view`. |
| E2 | Edit a store | On E1 | Name in both languages, tax rate as a percentage (kept as basis points, platform.md §1.1), timezone, position. The code, country and currency are shown but cannot be changed — they are immutable. `platform.store.update`, that store. |
| E3 | Currencies | `/admin/currencies` | **[DECIDED 2026-09-19]** Currencies are created and edited **in the panel**, by a Super Admin only (reserved permissions, platform.md §3): name and abbreviation in both languages, the sign, and the exponent — which is locked once any store uses the currency (platform.md §1.2). The sign field shows the sign as the site's font draws it, so a sign the font cannot draw is seen before it is saved (platform.md §5.1). |
| E4 | Settings | `/admin/settings` | Every declared setting the person may change, grouped by the module that declares it, each with the input its type asks for and its default shown. Store settings apply to the store in the header; global keys need All stores (Access amendment 5). A setting marked sensitive never shows its value (platform.md §1.3). Each setting's permission comes from its own definition, and a row a person may not change is not shown to them. Access's settings sit in **one Access section** (owner, 2026-09-22) although they carry two permissions: a store's own settings are ordinary, the staff sign-in and security numbers are admin-only (R4). A module may put **one line at the top of its section** (platform.md §1.3): the Companies section says whether bank transfer is on or temporarily off (b2b.md amendment 13(c)). A setting whose default is empty — "not set yet" — shows no default under its box: there is no value in force to name (owner, 2026-09-29). |
| E5 | Media library | `/admin/media` | **[DECIDED 2026-09-19]** The design's table, with a switch to a grid of thumbnails. The table: file, type, size, used in, uploaded. Plus the state of an image's variants (pending, ready, failed), upload (`platform.media.upload`), alt text (`platform.media.update`) — **in a dialog** with Cancel and Save Description (§1.11 #9, 2026-10-02), retry (a failed image, or one pending for 15 minutes), and delete (`platform.media.delete`), which first shows where the file is used and refuses when a use blocks it (platform.md §1.4). Paged by keyset, newest first. |
| E6 | Audit log | `/admin/audit` | **[DECIDED 2026-09-19]** Built in this stage. Who changed what and when: time, actor, action, subject, source, and the staff member's IP where there is one. Entries for the stores in scope; entries belonging to no store need All stores. Personal fields show only as "changed", never their values (platform.md §1.5). Filters: date range, actor, action, source. `platform.audit.view`. |
| E7 | Failed jobs | `/admin/failed-jobs` | **[DECIDED 2026-09-29]** The queue's failed work, oldest first: what the job was in its module's words (its technical name when none), when it failed, the tries it was allowed (Laravel keeps those, not the tries made — owner, 2026-09-29), the error's first line. Opening one shows the whole error and its queue. Fifty at a time, with "Show more". **Retry** puts it back on its queue and off the list — offered only for a job that failed on the database queue; **Delete** removes it unrun, after a confirmation. One at a time, no bulk. Kept until handled. In the **System** section of the menu, with the number waiting beside it, and a dot on its icon while the sidebar is collapsed; the admin home says so while any waits. `platform.jobs.manage`, admin-only (platform.md §3). |

- **[DECIDED 2026-09-19] No store is created here:** opening a country stays a console command, so a
  store is created complete, in one command, and can never exist half-configured (platform.md §1.1).

### 3.6 Storefront and the customer's account

access.md §1.1, §1.2, §1.3, §1.8, §1.9, §1.10, §3.1; platform.md §3. Every page sits under the
store and language (`/sa/ar/...`).

| # | Screen | Page | Shows |
|---|---|---|---|
| F1 | Choose a country | `/` | The three stores. The choice is remembered for a year; a visitor with the cookie is sent straight to their store (platform.md §3). Rebuilt in React (§2.3). |
| F2 | Store home | `/{store}/{lang}` | The placeholder with the store's name, until Content builds the real one (platform.md §3). |
| F3 | Register | `/{store}/{lang}/register` | First **individual or company** — it can never change later (access.md §1.1). Then email, password, first and last name, and accepting the terms, whose version is recorded. A company account is registered the same way and told that its company details and documents come next; until B2B exists it can sign in but cannot order (access.md §1.1). ~~When B2B exists, the two parts are one wizard.~~ **Two pages** (b2b.md amendment 14(b), 2026-09-29): a company account goes to F4 as anyone does, and applies on F11. |
| F4 | Verify your email | `/{store}/{lang}/verify-email` and the link `…/verify-email/{token}` | What to do next, and "send it again" (limited). The link is good for 24 hours. Until it is used the customer can browse and build a cart, but not order. |
| F5 | Sign in | `/{store}/{lang}/sign-in` | Email, password, "keep me signed in" (30 days — customers do have this, access.md §1.8) and "forgot password". Afterwards: the store they last used (access.md §1.1). A blocked account is told so after the right password; a pending deletion is cancelled by signing in, and the page says so (access.md §1.10). |
| F6 | Forgot / new password | `…/password/forgot`, `…/password/reset/{token}` | As the admin's (§3.1 A4, A5), with the customer's own rule (at least 8 characters). |
| F7 | Phone | In the account | Add the first number, or change it: a code goes to the number, and the old one stays in use until the new one is confirmed (access.md §1.3). While there is no verified phone, the account pages show what is still missing before ordering. |
| F8 | My account | `/{store}/{lang}/account` | Tabs like the admin's: profile (names, language), security (password), phone, addresses, and closing the account. |
| F9 | Addresses | In the account | Grouped by store, one default in each. Adding one asks the **country first** (only our stores' countries), then that store's fields: administrative area, city, district, street and building are required, the rest optional, with the map pin either both coordinates or neither (access.md §1.9). **[DECIDED 2026-09-19] No map in this stage:** the pin is simply left empty, because a map needs a paid maps provider that is not chosen. The written fields are what the courier receives. A store with no format yet refuses addresses with a clear message. Opened with `return` naming a page registered for the account (access.md amendment 51), saving an address lands the customer back on that page; making one the default or deleting one keeps `return` on the tab (amendment 52). |
| F10 | Close my account | In the account | Confirmed with the password. It says plainly: the account is locked at once and anonymized after 14 days, and signing in during those days cancels it (access.md §1.10). Confirming **signs them out of every device at once** (R5), so the screen they land on is the **store home, as a visitor**, with a message giving the deletion date and saying that signing in before then cancels it. There is no cancel button on the account pages: they cannot reach them. |
| F11 | Company | `/{store}/{lang}/account/company`, a **Company** entry in the account's pages | B2B's, for company accounts only (b2b.md §4.5, amendment 14): the design's company page — a status box, the form that saves itself (each field yellow, saving, green or red, amendment 16(a)) or what was sent, every application sent with its reference, the address picked from the saved addresses, and a side column with the application's lifecycle alone (amendment 16). |
| F12 | The company line | Under the header, on every shop page | B2B's (b2b.md §4.4, amendment 14(c)): for a company account that cannot order, one line saying why, linking to F11; nothing once approved. |

- The email address is never editable (access.md §1.1); the screen says so.
- An account's home store is the store it registered in and never changes; the customer may shop
  in any store (access.md §1.1).

### 3.7 Customers, seen by staff

access.md §3.3. Staff see the customers of their own stores — those whose home store is one of
theirs — and a Super Admin sees everyone.

| # | Screen | Page | Shows |
|---|---|---|---|
| G1 | Customers | `/admin/customers` | The design's table, with the columns this stage can fill: name, email, individual or company, whether the email and phone are verified, home store, registered on, and status (active, blocked, deletion pending). The design's order count and lifetime spend come with Sales, in stage 6. Filters by type and status, and a search by name, email or phone. `access.customer.view`. |
| G2 | One customer | `/admin/customers/{id}` | Their profile, verification, addresses per store, and status. Actions, all **admin-only** (R6) and each shown only to someone who holds it: block or unblock with a reason (`access.customer.block`), starting the same 14-day deletion at the customer's request, and **cancelling a pending one** with a reason — for a customer who cannot sign in to cancel it themselves (`access.customer.delete`). |

- **[DECIDED 2026-09-19] The store address format has its own editor** (`access.address_format.update`,
  access.md §1.9): a store's fields with their labels in both languages, which are required, their
  order and the display template. It is built in this stage rather than seeded once.
- **Access's own numbers** — lockout, code and session settings (access.md §3.3
  `UpdateAccessSettings`) — are settings, so they appear on the settings screen (§3.5 E4) under
  Access, with the ranges Access allows (amendment 22). They are not a screen of their own.
- A customer is never edited by staff beyond blocking and deletion: their profile is their own.

---

## 4 · New backend endpoints and what other modules must change

### 4.1 Where this stage's code lives

Screens are React files under `resources/js/pages/{Module}/` (§1.2). Everything else belongs to the
module that owns the data: its `Presentation/Http/Controller` (the page and the form endpoints), its
`Presentation/Http/Request` (shape and type validation) and its `Presentation/Http/Resource` (the
page's data as a `spatie/laravel-data` class, §1.6). This stage adds no module and no domain code.

- **Every page is one `GET` route** named `{module}.{area}.{screen}`, in the module's own
  `routes.php`, inside the admin group (`UseAdminSession`, `web`, `IdentifyStaff`, and
  `RequireStaff` for anything behind the sign-in) or the storefront group (`{store}/{locale}`).
- **Every form posts to a `POST` route** that calls one Application handler and answers with a
  redirect (Access amendment 12), as Access's sign-in endpoints already do.
- **A controller checks nothing itself**: the handler asserts the permission (handoff §19). A page
  that must not appear at all for someone without the permission asks the module's read model,
  which answers for the person's own stores, and shows the error page when there is nothing.
- **Listings use read models** (`Application/Query`), never Eloquent (`docs/STRUCTURE.md`), and the
  keyset paging Platform already indexes for (platform.md §5.4).

### 4.2 Endpoints by group

| Group | Pages | Form endpoints |
|---|---|---|
| §3.1 admin sign-in | 8 pages for A1–A8 | Already built in Access step 3b |
| §3.2 my account | `/admin/account` | Own profile, phone (request code, confirm), password, each notification switch, own email for a Super Admin |
| §3.3 staff | list, one person, invite | Invite, update profile, change email, change role and stores, disable, enable, resend and cancel invitation, refresh permissions |
| §3.4 roles | list, one role, new, edit | Create, clone, update, delete with a replacement, refresh |
| §3.5 Platform | stores, currencies, settings, media, audit | Update store, create and update currency, update setting, upload media, update alt text, delete media, retry variants |
| §3.6 storefront | country page, home, register, verify, sign in, password, account, addresses | Register, resend verification, sign in, sign out, password reset, request and confirm a phone code, update profile, save, delete and default an address, ask for deletion |

Each one calls the use case of the same name in access.md §3 or platform.md §3. Where a use case is
missing, it is listed below.

### 4.3 What other modules must change — each needs the owner's agreement

| # | Module | Change | Why |
|---|---|---|---|
| P1 | Platform | A contract method by which a module uploads a file **for its own use**, with Platform checking that module's permission for the change | A staff member without `platform.media.upload` cannot set their own picture (§3.2); B2B needs the same for company documents in stage 3. **[DECIDED 2026-09-19]** |
| P2 | Platform + Access | Every declared permission carries a **group** (business area), named in Arabic and English | The role editor and the comparison table group actions the way staff think, as the design and handoff §14 do (§3.4). **[DECIDED 2026-09-19]** |
| P3 | Access | The staff account remembers **which store the person is working in**, with the fallback rule of §2.2 | The admin has no store in its URLs (§2.2). **[DECIDED 2026-09-19]** |
| P4 | Access | The sign-in code page receives the **masked phone** (last 3 digits) | §3.1 A3. **[DECIDED 2026-09-19]** |
| P5 | Access → `app/Http` | Turning a business error into a message on the form (Access's `FormErrors`) moves to `app/Http`, beside `ProblemDetails` | Platform's admin screens answer forms the same way (§1.7), and this is framework glue, so the Shared kernel keeps its class limit. **[DECIDED 2026-09-19]** |
| P6 | Platform | An **admin menu registry**: each module, Platform included, declares its menu entries with the permission each needs | The menu is built from what the person may do, and grows module by module; "coming soon" entries are shown to Super Admins (§2.2). **[DECIDED 2026-09-22]** Platform keeps it, like the permission catalog and the settings registry; filtering asks the Shared `Authorizer`, so Platform never reaches into Access (R8). |
| P7 | Access | ~~Read models for the customer screens~~ | **Done** in Access step 6: `ListCustomers`, `ViewCustomer`, `ListStaff`, `ViewStaff` (R7) |

---

## 5 · Performance budgets

Handoff §5.4: "the five-second page is the enemy", and a CI test that fails the build on query count
is "the single highest-value guard in the project". The owner left these numbers to me on
2026-09-19; they are a starting point to confirm before the build.

| What | Budget | How it is checked |
|---|---|---|
| Queries, storefront page | **8** | A feature test per page, counted **warm** (the store already resolved from the cache table, 2 small reads — `docs/STRUCTURE.md`) |
| Queries, admin list or form | **15** | The same, per page |
| Every page's real count | **Recorded** | The test asserts the recorded number, so a page that grows from 5 to 9 fails even under its ceiling; raising it is a deliberate edit |
| JavaScript, shared | **200 KB gzipped** | Measured in the build: React, Inertia and everything every page uses, cached once |
| JavaScript, one page | **60 KB gzipped** | The same. Anything heavy (charts, a rich editor) loads only on the page that needs it |
| Fonts | **The two families, subset** | Latin and Arabic ranges only, served from our own domain (§2.1), preloaded so the first paint has them |

- **A breach fails the build**, as handoff §5.4 asks. A warning that stays green is ignored, which is
  how the slow system happened.
- **[2026-10-03, owner: fixed in the rebuild's foundation]** The JavaScript budgets had gone
  unenforced, and every page rode in one shared file: 236.5 KB gzipped by 2026-10-02. Now each page
  is its own file, fetched with the app because the shell names the current page to Vite. The
  shared part was 138.4 KB when this was written. `tests/Architecture/JavaScriptBudgetTest` measures
  the build: the shared part, and what each page adds, gzipped. A file that every page loads counts
  as shared, as the row above defines it, even when the bundler splits it from the app's own file
  (batch A of the rebuild, 2026-10-03: shadcn's common Radix code, about 15 KB, which all 57 page
  files load). Confirmed by the owner, 2026-10-03.
- Listings use keyset paging and read models, never Eloquent hydration (handoff §5.4,
  `docs/STRUCTURE.md`).
- SSR renders every page (§1.3); when the SSR process is down the page still works, rendered in the
  browser, and the failure is logged.

---

## 6 · Accessibility and right-to-left

**[DECIDED 2026-09-19, the owner left it to me] WCAG 2.2 AA** for every screen in this stage.
shadcn's components already carry much of it; what this adds is that it is checked, not assumed.

- Everything works with a keyboard alone: menus, the store picker, dialogs, the code boxes, the
  role editor's ticks. Focus is always visible, and a dialog returns focus where it came from.
- Every field has a real label, not a placeholder standing in for one. An error is tied to its
  field, so a screen reader announces it, and the toast (§2.1) is announced politely.
- Colour never carries meaning on its own: a status is a word as well as a colour.
- Contrast is checked in **both themes**, light and dark.
- Touch targets are at least 24 by 24 CSS pixels, with spacing.
- **Right-to-left:** layouts use logical properties (start and end, never left and right), so Arabic
  mirrors correctly, as shadcn's RTL support expects (§1.8). Directional icons flip; a clock or a
  logo does not. Arabic pages set `lang="ar"` and `dir="rtl"`, English pages `lang="en"` and `ltr`.
- Numbers, dates and currencies are formatted for the page's language (§1.8), including the
  Arabic-Indic digits and the currency's sign or letters.
- An automated pass runs over every page in the browser tests (§7), and the main flows — signing in,
  inviting a staff member, registering, adding an address — are also walked with the keyboard alone.

---

## 7 · Test scenarios

Pest, as the rest of the project (§1.1). Browser tests run with the suite (`composer check`).

**In a browser**

- Admin sign-in: password → code → dashboard; the trusted browser skips the code for 30 days; a new
  browser asks again; a wrong code, an expired code and the hourly limit each show their message.
- Accepting an invitation: set a password, correct a mistyped phone, confirm the code, land signed
  in. An expired link shows the "link no longer works" page.
- A staff member is invited in three steps, appears in the list under their store, and a person with
  two stores appears under "Centralized".
- The role editor: ticking actions, filling every action's stores in one row, giving one action its
  own stores, and saving an edit from a person's page as a personal role.
- The store picker: two stores switch; one store shows a name and no menu; a removed store falls
  back with its message.
- A customer registers, verifies the email, adds a phone with its code, saves an address, and asks
  to close the account.
- The theme and display-language toggles survive a reload, and the pages come back in that theme
  with no flash.
- Arabic mirrors the layout, the sidebar sits on the right, and figures show Arabic-Indic digits.
- Phone width: the sidebar becomes a slide-in menu; tables scroll inside their card.
- With SSR turned off, every page still renders in the browser.
- The accessibility pass and the keyboard walk-throughs of §6.

**On the server**

- Every page route: who may open it, what its data contains, and 403 or 404 where it must not open.
- Every form endpoint: validation errors land on their fields; a business error comes back as the
  form's message; success flashes its status and redirects.
- The query budgets of §5, warm, per page.
- The menu contains only what the person may do, and "coming soon" entries appear for a Super Admin
  only.
- Ziggy: every named route belongs to exactly one group, and a storefront page's data carries no
  admin route (the test also asserts it found routes to check).
- The generated TypeScript types match the PHP page-data classes; a stale file fails.
- Every translation key a page uses exists in Arabic and English.
- Uploading a picture works for a staff member who holds no media permission (§3.2, P1).

**Architecture**

- No controller asserts a permission itself; the handlers do (handoff §19).
- Every page component lives under `resources/js/pages/{Module}/`, and the test asserts it found
  pages, so a moved folder cannot make it pass over nothing.
- No listing hydrates Eloquent (handoff §5.4).

---

## 8 · Questions

### 8.1 Open — the owner has not answered

None. The one question here — which module keeps the admin menu registry — was answered on
2026-09-22: Platform keeps it (R8).

### 8.2 Left to me by the owner, to confirm before building

| # | Item | What I chose |
|---|---|---|
| 2 | Where the screens' text lives (§1.5) | The Laravel lang files, one source with the backend's text |
| 3 | The budgets of §5 | Storefront 8 queries, admin 15, each page's count recorded; 200 KB + 60 KB of JavaScript; a breach fails the build |
| 4 | The accessibility target (§6) | WCAG 2.2 AA, checked automatically and by keyboard |

### 8.3 Waiting on something outside this stage

| # | Item | Waiting for |
|---|---|---|
| ~~5~~ | ~~The storefront's look is derived from the admin design~~ | **Closed 2026-09-22:** the storefront design arrived (§2.3) |
| 6 | The map pin on an address (§3.6 F9) stays empty | A maps provider being chosen (handoff §15.1 keeps such items) |
| 7 | The changes other modules must make (§4.3 P1–P7) | Each module's owner agreeing, as an amendment to that module's spec |
| 8 | Where the SSR process runs, and on which Node version (§1.3) | Hosting (handoff §15.4) |
| 9 | The currency signs (§1.8) | Rechecking the fonts the build installs; on today's evidence both signs stay cleared |
| 10 | Registration continuing into the company wizard (§3.6 F3) | B2B, stage 3 |
- Uploading gives the same answer for an image already stored: Platform returns the existing one
  (platform.md §1.4), and the library simply shows it.

