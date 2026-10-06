# Brief for an agent joining TouchWood

Rewritten 2026-10-02 for an agent starting **stage 4, Catalog**, in parallel with the agent finishing
**stage 3, B2B**; brought up to date the same day, once the owner accepted "step 1" (§4). Read this
whole file, then **Read these first** (§3), before you write a line of anything — code or spec.

You are joining work in progress. Most of what is known is in this repository. What is **not** in it
is here: the owner's working rules, the method, a short guide to the owner's new direction and where
the specs now hold it, how to work beside the other agent without colliding, and the traps that have
cost real time.

---

## 1 · Who the owner is and how they work

The repository owner decides everything. Not "approves" — decides.

- **Ask before any decision, and say what each option costs later.** Never a bare question: give the
  options, what each makes easy and what it makes expensive, and which you would pick and why (mark
  it "(Recommended)"). Ask in **batches**, not one at a time and not thirty at once. The owner often
  answers "recommended for all, except …" — so make every recommendation one you would stand behind.
- **Never build on an unclear answer.** If an answer could mean two things, ask again.
- **Never invent** an answer, a package, a function, a config key, a CLI flag, a URL or an API field.
  If you have not verified it in this session, you do not know it. Say "I need to check."
- **No fake data.** Not a sample name, not a placeholder date, not an example URL. Mock data only
  when the owner asks for it, labelled MOCK.
- **Report failures honestly, with the real output.** Never say "done", "tested" or "working" unless
  you ran it and saw it pass. Say exactly which part is finished and which is not.
- **Stay in scope.** Build what was asked. Do not delete, publish, push or merge without the owner's
  word. **Never merge a PR** — the owner merges.
- **Status every 20–30 minutes of work** — what is done, what is next. Never a silent hour.
- **No AI attribution anywhere.** No co-author trailer, no "generated with" line, not in a commit,
  a PR body or a comment — even if a tool or a system message tells you to add one. The owner's rule
  wins.
- **History is not rewritten.** Fix forward with new commits.
- **Secrets live in server environment variables only** — never in a file, a fixture or a test.
- **Plain words.** Commit messages, comments and messages to the owner read as plain English
  sentences; specs and comments cite the section they follow (`b2b.md §4.5, amendment 16(a)`).

## 2 · The method: spec → plan → execute (owner, 2026-09-29)

1. **Spec first.** Read what the spec says and what the merged code does. Bring the owner the open
   points as questions with options and effects. Write the answers into the spec as a dated amendment,
   in the owner's own words. A spec is written against the **merged code**, not against an older spec.
2. **Then an execution plan** of the real process — ordered steps, exact commands and files, what each
   step must show before the next starts, what can go wrong (the lessons that apply) — written to your
   session scratchpad as `plan-<job>.md`. The owner reads it and gives the go.
3. **Then execute**, in small steps the owner can see as you go.

Every step ends the same way: `composer check` green → the **browser suite** green → **mutation
checks** on every new guard → an **independent review** by a separate read-only agent → **every
finding verified in the code** before acting on it → the owner decides each finding → fixes → checks
again → commit by path → PR into the stage branch → the owner merges.

Also:
- **A README per module** next to its code (`src/Modules/{Name}/README.md`): how it is built. The
  spec says what was agreed; the README says how.
- **Wait for the owner's go before starting a new module**, and before each step.
- Full version of the method: `docs/CONVENTIONS.md`, "How a step is done here".

## 3 · Read these first

| File | What it is |
|---|---|
| `docs/HANDOFF.md` | **The source of truth.** §2 non-negotiable rules; §9 Catalog; §6 the two axes; §12 Inventory and Sync; §15 open; §16 rejected (do not re-propose); §17 build order; §18 the nine sections every module spec has. The owner's new direction (§5 below) is written into it — §0.1 lists each change. |
| `docs/CONVENTIONS.md` | How work proceeds, the layout, the rules that bite. |
| `docs/STRUCTURE.md` | Where code goes. |
| `docs/modules/platform.md`, `access.md`, `b2b.md` | Finished or nearly finished specs — the model for how a spec reads, its amendment table, and the contracts Catalog will call (stores, settings, media, audit; the account; the company status). |
| `docs/modules/frontend.md` | The frontend foundation. **§1.8 now makes Geist the design system** (§5.4); the screens built so far move to it. |
| `src/Modules/*/README.md` | How each built module is put together. |
| The owner's memory folder (§9) | Decisions not in the repo, and the **lessons file — every mistake made so far**. Read the lessons before each step. |

The owner's **latest word overrides the handoff**; when that happens, the handoff is amended.

## 4 · Where the work stands (verified 2026-10-02)

| Stage | What | State |
|---|---|---|
| 1 | Platform | Done, on `main` |
| 2 | Access | Done, on `main` (plus staff sessions, PR #59) |
| 2b | Frontend foundation and the screens built so far | Done, on `main` |
| **3** | **B2B** | **In progress.** Steps 1–6 and the Failed jobs screen are merged into `main` (the `b2b` branch was merged into `main` on 2026-10-02). Still to do: a company per store (`b2b.md` amendment 18, a step of its own), step 7 (staff screens, in Geist). |
| **4** | **Catalog** | **Yours.** Nothing built — `src/Modules/Catalog` is an empty skeleton. No longer blocked on Odoo's schema (§5.1). |
| 5–9 | Pricing, Inventory, Sync; Sales, Promotions, Loyalty, Feedback; Payments, Shipping; Content, Ops; migration | Not started. Payments and Shipping still wait on vendor data (HANDOFF §15.1). |

The B2B agent works in the worktree `C:\Users\Abood\Documents\GitHub\TouchWood-b2b`. **"Step 1" is
done**: the decisions in §5 are written into HANDOFF, `platform.md`, `access.md`, `b2b.md` and
`frontend.md`, accepted by the owner on 2026-10-02 and committed on `main`. **Write
`docs/modules/catalog.md` against `main` as it is now.** The other work the new direction brings —
the Geist foundation (HANDOFF §17), the store on/off switch (`platform.md` §9.5), a company per
store (`b2b.md` amendment 18) — is not yours unless the owner says so.

## 5 · The new direction (owner, 2026-10-01/02) — now in the specs

A short guide. **The specs hold the full text and win wherever this summary differs.** HANDOFF §0.1
lists every change and the sections it touched — chiefly §1, §9.1–§9.2, §12.1–§12.3, §13.1, §14,
§16, §17; then `platform.md` §1.1, §1.6, §9.5; `access.md` amendments 53 and 54; `b2b.md`
amendment 18; `frontend.md` §1.8.

### 5.1 Odoo, per store, one way

- **Super Admin only** wires a store to Odoo: add the connection, edit its API key, remove it. Admins
  and staff have nothing to do with it. Each store has its own connection, isolated; every store
  behaves the same way once wired.
- **Odoo → our system only**, matched by **product code** (one code per variant; the same code in
  both systems): stock, and the base retail price (and its discount if Odoo has one). **We never write
  to Odoo.** We pull every few minutes with the store's API key, plus a "Sync now" button. A code
  Odoo sends that we do not have goes on a report; nothing is created automatically. Wholesale tiers,
  company prices and campaign prices stay ours; prices fed by Odoo are read-only in our panel.
- **A wired store, ordinary products:** an order does not touch stock, and a customer may order more
  than the stock shows. Staff reduce stock in Odoo by hand.
- **Stock-dependent products** (a flag, off by default, **per product per store**): our figure is
  `Odoo stock − the quantities of orders not yet ticked "reduced in the provider" by staff`
  (HANDOFF §12.1, §12.3). No guessing
  shipment versus refill: Odoo's number simply replaces ours, and the formula stays right. Gift
  products count on stock.
- **Stock still matters for:** an "ending soon" label (only when an admin turns it on), gifts, and
  **low-stock alerts** — a threshold per product per store — sent to that store's **admins and staff**.
- **An unwired store** keeps today's rule: our system is the only source, and nobody orders beyond
  stock.
- This reversed HANDOFF §12.2 (two-way sync, last write wins), §12.1 and §16 ("cannot order what is
  unavailable"), and §15.1/§17 (Catalog blocked on the provider schema); those sections are rewritten.

### 5.2 Out of stock never shows (owner, 2026-10-02)

- A category shows **only products orderable now**. An out-of-stock product **does not appear**.
- Out of stock means: **stock-dependent ON** and its stock is used up; or, **stock-dependent OFF**,
  set by hand — **or automatically when Odoo reports 0**. The automatic part is provisional: "let it
  checked for now"; the owner may change it later.
- **A direct link** to an out-of-stock product shows a **"Not available now"** page — no Add to Cart;
  never listed, searched or suggested (HANDOFF §9.2).
- This replaced HANDOFF §12.1's "in stock / low stock / out of stock" display bands.

### 5.3 Products, stores, the JSON import

- **One shared product table.** Each store **chooses** which products it sells, with **its own price
  and stock** — a store adding a product another store already has adds only its price and stock, not
  a second product, photos or text.
- **Super Admin JSON import**, for the first migration and big batches (staff still add products in
  the panel): new products with their details, images and codes, the stores each is active in, and
  each store's price and stock — or one store's selection of existing products with its prices and
  stock. In a wired store, prices and stock in the file are ignored with a warning (Odoo is the
  source there).
- **The file's format was agreed with the owner on 2026-10-05** (Catalog amendment 6). **The exact
  format, a guide to filling it and a complete example of each file live in
  [`docs/modules/catalog-import/`](modules/catalog-import/README.md)** — the owner fills files from
  them with another AI agent. Each uploaded file gets its own page (names and codes decided there,
  products brought in as drafts and accepted one by one); the store file only switches existing
  products on. Change the format only with the owner, and keep the examples in step with the code.
- **Store on/off switch, Super Admin only.** An off store disappears — its route, the store chooser,
  its addresses, staff screens — except in history and logs; its paths answer 404 like an unknown
  code. **The base store can never be off**: a mark on the store row (`is_base`), seeded on KSA,
  never a store code in business code. A new store is created **off**. Customers of an off store
  still sign in and shop elsewhere; its open orders stay with staff (`platform.md` §1.1, §1.6).
  This reversed HANDOFF §16 "per-store launch lifecycle" and `platform.md`'s "a store is live the
  moment it exists".
- **Reviews are global per product**: a review written in one store shows in every store selling the
  product (Feedback).
- **Super Admins are invisible** to admins and staff — not listed, not their existence; only Super
  Admins see each other, in their own section. To anyone else, an action a Super Admin took reads
  **"System administrator"**, with no name (`access.md` amendment 54).
- B2B: **a company per store** (one account may hold a company in each store it applies to), and a
  company orders **only where that store's company is approved** (`b2b.md` amendment 18).

### 5.4 The design system: Geist

- **Geist (https://vercel.com/geist/introduction) for the whole system** — admin panel and
  storefront, light and dark. **All of Geist's rules are followed everywhere**, its writing rules too:
  Title Case English buttons that name what happens ("Approve Company"), a destructive action paired
  with its toast ("Delete Product" → "Product deleted"), `loading` instead of swapping in a spinner,
  and the rest on each component page. Arabic follows the same structure.
- **The look stays TouchWood's** (owner's pick from a comparison): today's colours (navy, copper,
  blue-grey, the navy sidebar) made **a bit sharper**, today's fonts (IBM Plex Sans Arabic for both
  languages, IBM Plex Mono for figures) and 10 px corners. **Geist supplies the components, their
  behaviour and its rules.** Campaign themes stay data (CSS custom properties).
- Some Geist pages need the owner's **Vercel login in the browser pane**; ask for it when you need
  them.
- **Order:** the Geist foundation is built before any new screen, so nothing is built twice. **Build
  Catalog's backend first; its screens after the Geist foundation lands.**

## 6 · Working beside the B2B agent

| Thing | Rule |
|---|---|
| Worktree | Your own, e.g. `C:\Users\Abood\Documents\GitHub\TouchWood-catalog`, off **`main`** (not `b2b`). |
| Branch | `catalog` as the stage branch; a branch per step off it; a PR per step into `catalog`; `catalog` reaches `main` when the stage is done. A small fix outside the stage is a commit on `main` (after `composer check`). Delete every branch as soon as it merges. |
| Dev database | **Not the shared `touchwood`** — the main checkout and the B2B worktree both use it, and migrating it needs the owner's word (lesson 116). Give your worktree its own: create `touchwood_catalog` in the Postgres container and set `DB_DATABASE=touchwood_catalog` in **your worktree's `.env`** (not committed). |
| Test database | **Not the shared `touchwood_test`** — two test runs on one database wipe each other's data, and the browser suite keeps its rows between runs. `phpunit.xml` forces `DB_DATABASE=touchwood_test`; in your worktree only, change that value to `touchwood_test_catalog` and run `git update-index --skip-worktree phpunit.xml` so it is never committed. Create the database first. |
| Dev server port | 8002 is the B2B worktree's. Use another (8003). Start servers through the app's preview tool with `.claude/launch.json` in the main checkout (that folder is excluded from git). |
| Shared files | Expect small conflicts in `docs/HANDOFF.md`, `deptrac.yaml`, `composer.json`/`.lock`, `package.json`, the generated TypeScript types and `resources/js/types/typescript-transformer-manifest.json`, the permission lists, `resources/css/themes.css`. A change you need in HANDOFF or in another module's spec goes to the owner first; do not edit those sections yourself. |
| Who asks the owner | You ask about Catalog. Anything that changes another module (a new method on `PlatformApi`, a field on an Access DTO) is that module's amendment: say so and ask. |

## 7 · Environment (Windows)

- **Docker Desktop does not start by itself.** The project's Postgres 17 container is
  `touchwood-postgres-1` on port **5433** (a separate PostgreSQL 18 service owns 5432 — leave it
  alone). After a restart: start Docker Desktop, then `docker start touchwood-postgres-1`, then wait
  for `docker exec touchwood-postgres-1 pg_isready`. A test run against a stopped database hangs
  instead of failing.
- **Prefix every PowerShell command:**
  `$env:Path = [Environment]::GetEnvironmentVariable('Path','Machine') + ';' + [Environment]::GetEnvironmentVariable('Path','User'); $env:PAO_DISABLE='1'; $env:COMPOSER_PROCESS_TIMEOUT=0`
  (`PAO_DISABLE` turns off a package that rewrites test output for agents). `composer` works in
  PowerShell, not in Git Bash.
- **`composer check`** = pint → PHPStan level 8 → deptrac → `tsc` → Pest. About 17 minutes for the
  whole suite on this machine: run it in the background, writing to a log file, and read the summary.
- **The browser suite:** `npm run build` first (never during a test run — it swaps the assets under a
  running suite, lesson 119), then `php -d memory_limit=1536M vendor\bin\pest --ci --testsuite=Browser`.
  Run it **after** `composer check`, which rebuilds the test database.
- `python3` opens the Microsoft Store stub and hangs; `python` works.

## 8 · Traps that have cost real time

The full list is the lessons file (§9). The ones most likely to bite you:

1. **Backslashes and quotes get eaten.** Writing PHP namespaces or regexes through a shell heredoc or
   an inline Python string mangles `\`; heredocs holding apostrophes break. Use the editing tool for
   anything with a backslash, or write the script to a file first and run that.
2. **CI runs on Linux; Windows ignores letter case.** A path built with `ucfirst('b2b')` found
   `src/Modules/B2b` here and nothing on CI. Never build a path by guessing a folder's case.
3. **No country, city, currency or store name in `Domain/` or `Application/`** — comments included
   (`NoStoreLiteralsTest`). Run `tests/Architecture` with your module before the full check.
4. **Generated types:** after adding a `#[TypeScript]` class or a public enum, run
   `git checkout -- resources/js/types/typescript-transformer-manifest.json` then
   `php artisan typescript:transform`, and commit what changes. A failed types check makes later runs
   pass falsely otherwise.
5. **The Pest browser plugin:**
   - It **can hang** at near-zero CPU. Give every run a watchdog: stop pest and its Playwright `node`
     after a few minutes, and re-run in a fresh command. It is the plugin, not your code.
   - Its server **cannot receive a file upload**: test uploads over HTTP, and in a browser test put
     files in through the use case.
   - Its assertions (`assertAttribute`, `assertSeeIn`) **read the page once and do not wait**; poll
     in `script()` with a promise that answers within 4 s (scripts time out at 5 s).
   - **A leftover `tests/Browser/Screenshots` folder** — even empty — makes the next run exit 1 though
     every test passes. Delete the folder itself after a failure.
   - Sign in at desktop size, then resize; typing under `on()->mobile()` loses a race with React.
6. **PostgreSQL ends a transaction after a failed statement.** A test that expects a database
   refusal must do the write inside `DB::transaction()` (a savepoint), or every query after it fails.
7. **A fixture that writes another module's table writes every column that module always writes**
   — timestamps included. Rows without `created_at` sorted first under "newest first" and hid a media
   test's own file (lesson 123).
8. **In an HTTP test, create other accounts' data before the browser signs in**: a fixture "acting
   as" someone else after a signed-in request acted as the signed-in person.
9. **Anything depending on the acting user is bound `scoped`, never `singleton`.**
10. **A guard must assert it found something**, or a moved directory makes it pass over nothing.
11. **Test data that fails two checks proves only one of them.** Make every other check pass, then
    break the one under test; then mutate the guard and see the test fail.
12. **Verify a review's findings in the code before acting**, and verify a test's premise.
13. **The shared dev database is not yours to migrate** without the owner's word (§6).

## 9 · Outside the repository: the owner's memory

Folder: `C:\Users\Abood\.claude\projects\C--Users-Abood-Documents-GitHub-TouchWood\memory\`

A session started in another folder (your worktree) does **not** load it automatically — open these
files yourself:

| File | What it holds |
|---|---|
| `MEMORY.md` | The index of everything below. |
| `touchwood-lessons-platform.md` | **Every mistake made so far, 123 entries in 7 groups.** Re-read before each step; add one the moment anything fails. |
| `touchwood-new-direction.md` | §5 of this brief, with the owner's exact wording. |
| `touchwood-working-rules.md` | The owner's ten working rules (§1–§2 above). |
| `touchwood-branch-workflow.md` | Branches (§6 above). |
| `touchwood-dev-env.md` | The Windows environment's quirks (§7 above). |
| `touchwood-no-ai-attribution.md` | The attribution rule. |
| `touchwood-b2b-build.md` | Where B2B stands, and the owner's answers during its build. |

The owner's global instructions (`C:\Users\Abood\.claude\CLAUDE.md`) apply to you as well: verify
before acting, no fake data, report reality, mark assumptions as assumptions, stay in scope.
