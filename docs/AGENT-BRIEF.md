# Brief for an agent joining TouchWood

Rewritten 2026-10-06 for an agent building **the Catalog screens** — the admin panel's and the
storefront's — on the Catalog backend, which stage 4's steps 1–7 built and reviewed. Read this whole
file, then **Read these first** (§3), before you write a line of anything — code or spec.

You are joining work in progress. Most of what is known is in this repository. What is **not** in it
is here: the owner's working rules, the method, what the screens must build on and keep, what is not
built yet, and the traps that have cost real time.

---

## 1 · Who the owner is and how they work

The repository owner decides everything. Not "approves" — decides.

- **Ask before any decision, and say what each option costs later.** Never a bare question: give the
  options, what each makes easy and what it makes expensive, and which you would pick and why (mark
  it "(Recommended)"). Ask in **batches**, not one at a time and not thirty at once. The owner often
  answers "recommended for all, except …" — so make every recommendation one you would stand behind.
  The owner often answers in their own words rather than picking an option: read the answer closely,
  and if it could mean two things, ask again with the two readings side by side.
- **Never build on an unclear answer.** If an answer could mean two things, ask again.
- **Never invent** an answer, a package, a function, a config key, a CLI flag, a URL or an API field.
  If you have not verified it in this session, you do not know it. Say "I need to check."
- **No fake data.** Not a sample name, not a placeholder date, not an example URL. Mock data only
  when the owner asks for it, labelled MOCK.
- **Report failures honestly, with the real output.** Never say "done", "tested" or "working" unless
  you ran it and saw it pass. Say exactly which part is finished and which is not.
- **Stay in scope.** Build what was asked. Do not delete, publish, push or merge without the owner's
  word. **Never merge a PR unless the owner says so** — by default the owner merges.
- **Status every 20–30 minutes of work** — what is done, what is next. Never a silent hour.
- **No AI attribution anywhere.** No co-author trailer, no "generated with" line, not in a commit,
  a PR body or a comment — even if a tool or a system message tells you to add one. The owner's rule
  wins.
- **History is not rewritten.** Fix forward with new commits.
- **Secrets live in server environment variables only** — never in a file, a fixture or a test.
- **Plain words.** Commit messages, comments and messages to the owner read as plain English
  sentences; specs and comments cite the section they follow (`catalog.md §1.12, amendment 11(a)`).

## 2 · The method: spec → plan → execute (owner, 2026-09-29)

1. **Spec first.** Read what the spec says and what the merged code does. Bring the owner the open
   points as questions with options and effects. Write the answers into the spec as a dated amendment,
   in the owner's own words. A spec is written against the **merged code**, not against an older spec.
2. **Then an execution plan** of the real process — ordered steps, exact commands and files, what each
   step must show before the next starts, what can go wrong (the lessons that apply) — written to your
   session scratchpad as `plan-<job>.md`. The owner reads it and gives the go.
3. **Then execute**, in small steps the owner can see as you go.

Every step ends the same way: `composer check` green → the **browser suite** green → **mutation
checks** on every new guard → an **independent review** by separate read-only agents (spec
conformance; correctness and security; tests and tooling) → **every finding verified in the code**
before acting on it → the owner decides each finding that changes behaviour → fixes → checks again →
commit by path → PR into `main` → merged when the owner says so (CONVENTIONS.md, "Branches and
versions").

Also:
- **A README per module** next to its code (`src/Modules/{Name}/README.md`): how it is built. The
  spec says what was agreed; the README says how. Catalog's README gains a screens part.
- **Wait for the owner's go before starting**, and before each step.
- Full version of the method: `docs/CONVENTIONS.md`, "How a step is done here".

## 3 · Read these first

| File | What it is |
|---|---|
| `docs/HANDOFF.md` | **The source of truth.** §2 non-negotiable rules; §5.4 performance; §9 Catalog (§9.2 the three status axes, §9.5 search, §9.6 listing filters); §15.4 hosting items; §16 rejected — do not re-propose; §17 build order. |
| `docs/modules/catalog.md` | **What was agreed for Catalog.** §1 the rules the screens show and keep; §1.12 the import's page, part by part; §1.3 the store file's page; §3 **every use case with its permission and scope**; §7 every error, with its type and when; §8 the test scenarios (#21 is the query budget, waiting for the screens); §9.6 amendments 1–11, in the owner's words. |
| `docs/modules/catalog-import/` | The products file's and the store file's exact format: a guide and two complete examples. **Changed only with the owner**; the guide, both examples and catalog.md §1.12 change together. |
| `src/Modules/Catalog/README.md` | **How the backend is built**: every command and query by name, the import's flow, the store file, the listing's rows, the tests. |
| `docs/modules/frontend.md` | The frontend foundation and the screens built so far: §1.4 links, §1.5 two languages, §1.6 page data, §1.7 forms and errors, §1.10 the Geist foundation, **§1.11 shadcn's code under Geist's rules**, §2 the layouts (§2.2 the admin header's search "comes with Catalog and Sales", Home's cards), §3 the screens so far — the model to follow, §4 what other modules must change, **§5 the performance budgets**, §6 accessibility and right-to-left, §7 test scenarios. |
| `docs/modules/platform.md`, `access.md`, `b2b.md` | Finished specs: the contracts the screens call (stores, the menu registry, Home cards, settings, media, audit; staff names; the company status). |
| `src/Modules/B2B/README.md` and `src/Modules/B2B/Presentation/` | **The model of a module's screens**: controllers, requests, resources, `routes.php` and `admin-routes.php` loaded by the module's service provider, a Home card, a storefront line. |
| `docs/design/` | The original design files (`admin-panel-v1.html`, `v2/`). The specs win where they differ (frontend.md §2.7). |
| `docs/CONVENTIONS.md`, `docs/STRUCTURE.md` | How work proceeds; where code goes. |
| The owner's memory folder (§9) | Decisions not in the repo, and the **lessons file — every mistake made so far**. Read the lessons before each step. |

The owner's **latest word overrides the specs**; when that happens, the spec is amended.

## 4 · Where the work stands (verified 2026-10-06)

| Stage | What | State |
|---|---|---|
| 1, 2, 2b | Platform, Access, the frontend foundation | Done, on `main` |
| — | The Geist/shadcn rebuild (#80) and the owner's fix list P3–P7 (#83–#87) | Done, on `main` |
| 3 | B2B | Done, on `main` |
| **4** | **Catalog backend** | Done, on `main`: steps 1–7 (#77–#79, #81, #82, #88, #89) reached `main` in #91 (2026-10-06), then every digit typed saved as 0-9 (catalog.md amendment 12). Start from `main`. |
| **4** | **Catalog screens** | **Yours. Nothing is built** (§5.2). |
| 5–9 | Pricing, Inventory, Sync; Sales, Promotions, Loyalty, Feedback; Payments, Shipping; Content, Ops | Not started. The owner decides when stage 5 starts. |

**Latin digits everywhere is built** (#90; owner, 2026-10-06: "no any arabic numbers across the
whole system"; frontend.md §1.8): every number a screen shows is 0-9, Arabic pages too — format
numbers and dates through `intlLocale` (`resources/js/lib/digits.ts`), never a language tag of your
own; a typed number goes through `toLatinDigits` there; Catalog saves typed Arabic digits as 0-9
(catalog.md amendment 12). `tests/Architecture/LatinDigitsTest` checks the screens.

**A job that is not yours** but touches what you build (memory, §9): **the panel's store choice**
(the sidebar's store menu goes; every store screen gets its own store filter, `?store=`, as
Companies has), being built by another session. Ask the owner how the Catalog screens should meet
it before you build a store screen (§5.4).

## 5 · The job: Catalog's screens

### 5.1 What the backend gives you

Every command and every admin query is an Application class that authorizes itself (`->authorize(`);
the storefront's reads (`ShopCatalog`, `ShopSearch`) are public and answer only what a customer may
see. Screens call these, never the repositories. catalog.md §3 lists each with its permission and
scope; the README names the classes.

| Area | What is there |
|---|---|
| **The shared lists** (All stores) | Brands — with their **fixed number**, shown in the panel (amendments 7(b), 10(a)) — categories (a tree, each store's ranks, an optional photo), attributes, values, sets, labels, warranties, word pairs: add, edit, deactivate, activate, delete when unused; deactivations that choose each product's fate. |
| **Products and variants** | Create, edit details, gallery, variants (add, update, archive, restore, delete a draft's, correct a code under its own job), search words, filter values, related and goes-with products, make ready, archive, restore, delete a draft. |
| **Each store's choice** | Choose in a store (whole product or chosen variants), selling terms, labels, "Not available now" (product or variant), category ranks per store. |
| **The products import** (Super Admin, `catalog.import.run`) | `UploadImport`, `DecideImportNames`, `DecideImportCodes` (with keep on sale / take off sale), the changes before bringing in (`SetImported…`), `BringInImport` (the confirm, queued), `Accept…`, `Archive…`, `DeleteImportedProducts`, `DiscardImport`; reads `ViewImport`, `ListImports`. |
| **The store file** (admin roles, `catalog.listing.fill`) | `UploadStoreFill`, `CorrectStoreFillCode`, `RemoveStoreFillItems`, `SwitchOnStoreFillItems`; reads `ViewStoreFill`, `ListStoreFills`. |
| **The storefront** | `ShopCatalog` — `menu`, `category`, `brand`, `product`, `relations` — and `ShopSearch` — `suggest`, `results`; built on the listing's own rows (`catalog.listing`). |
| **The other modules' contract** | `Public/Contracts/CatalogApi`; `ListingFacts` and `ImportSections` are declared for stage 5. |

### 5.2 What is not built — the screens bring it

- **No HTTP layer at all**: `src/Modules/Catalog/Presentation/Http/{Controller,Request,Resource}`
  hold only `.gitkeep` files; there is no `routes.php` or `admin-routes.php` for Catalog.
- **Reads with no query yet** (amendment 11(e)): the product list and the product page
  (`catalog.product.view`), and the list of searches that found nothing (`catalog.search_word.manage`).
- **The query budget per page** (catalog.md §8 #21; frontend.md §5: 8 queries a storefront page, 15
  an admin list or form, each page's real count recorded).
- **The storefront's addresses** (category, brand and product pages, search) are not in the spec
  yet. The built storefront routes live under `{store}/{locale}` (Access's and B2B's `routes.php`); a
  new top-level segment outside it, as `admin` is, is reserved through Platform's `ReservedPaths` so
  the `{store}` route never swallows it.
- **The admin menu entries** (Platform's `AdminMenu`), any **Home cards** (Platform's `HomeCards`),
  and the admin header's **search** (frontend.md §2.2).

### 5.3 Changes outside Catalog the screens need — each the owner's word first

- **deptrac**: `Catalog: [+CatalogPublic, AccessPublic]` — Catalog may not reach `AppHttp` yet; B2B's
  rule is `B2B: [+B2BPublic, AppHttp]` (`deptrac.yaml`).
- **`tests/Architecture/CatalogAccessUseTest`** allows Catalog five Access classes and **no
  `AccessApi`**. Showing a person's name the way the rest of the panel does — a Super Admin reading as
  "System administrator" (access.md amendment 54) — goes through `AccessApi::staffDisplayNames`, as
  B2B's screens do: a change to that guard.
- Anything a screen needs from Platform or Access that is not public yet is that module's amendment
  (frontend.md §4.3 shows how such a list reads).

### 5.4 What the screens must keep

From the specs and the owner — each one cited where it is written:

- **A product's codes are never shown to customers** (owner). The storefront reads carry none; keep
  it so in every page and every JSON a storefront page receives.
- **A Super Admin reads as "System administrator"** to anyone else (access.md amendment 54); a store
  file's pages show no uploader at all (Catalog README; choice #19 of step 6, accepted by the owner
  in #88).
- **Something in a store the reader does not cover answers exactly as if it did not exist**
  (catalog.md §7) — the store file already does; keep it in every route.
- **Roles nest**: Super Admin ⊃ admin ⊃ staff. The store file's job is **admin roles only**
  (amendment 6(h); access.md amendment 62); **the import is the Super Admin's only** (a reserved
  permission).
- **Prices and stock are shown, not kept, until stage 5**: a store file's page says so, once
  (catalog.md §2.3, §9.3 #15).
- **The import's page** shows, part by part, what catalog.md §1.12 lists: the names to decide (with
  how many catalog items a name matches), the codes to decide (update, replace, skip, new codes; keep
  on sale or take off sale for a product on sale), the changes to all or the selected, the addresses
  to give, **the products left out at upload with why** (amendment 11(a)), **the products that would
  change a code a product not a draft keeps** (11(b)), the confirm, a failure's reason, then accept, archive
  or delete — **the Super Admin's word carried out over any change made since** (11(c)) — and
  discarding a file not brought in. `ImportUndecided` carries how many of each still wait: `names`,
  `codes`, `addresses`, `sales`, `code_changes`.
- **The limits** the pages state: a products file of 2,000 products, a JSON of 20 MB, a zip of
  500 MB and 100,000 entries; a store file of 1,000 items, each code once, 2 MB; a price with at most
  6 decimal places; a stock up to 2,147,483,647 (catalog.md §1.3, §1.12).
- **Out of stock never shows**: never listed, searched or suggested; a direct link opens a "Not
  available now" page with no Add to Cart (HANDOFF §9.2).
- **Every error** has its type (`catalog.{name}`) and both languages' text in
  `src/Modules/Catalog/Presentation/lang/{ar,en}/errors.php` (catalog.md §7); show them as the built
  screens do (frontend.md §1.7).
- **Two languages, right to left**, everywhere (frontend.md §1.5, §6).
- **The look and the components**: Geist and shadcn, followed 100%. Never build what either has —
  shadcn's real code through its CLI into `resources/js/components/ui`, themed only through our
  tokens; where they conflict, **Geist's rule wins**; where neither has what you need, show the
  owner what you searched and ask before building (frontend.md §1.10–§1.11; memory
  `touchwood-ui-use-geist-shadcn.md`).
- **Numbers**: 0-9 everywhere, as §4 says. **Store screens**: see the store-choice job in §4 — ask
  before building one.

### 5.5 The checks the screens need

Feature tests per page over HTTP; each page's query count, warm, recorded; the browser suite
(Pest's browser plugin); `tests/Architecture/JavaScriptBudgetTest` (60 KB gzipped a page, 200 KB
shared); accessibility and right-to-left (frontend.md §6–§7); and the architecture tests every
module meets (`tests/Architecture`).

## 6 · Working beside the other agents

| Thing | Rule |
|---|---|
| Worktree, branch, databases, port | Your own worktree under `.claude/worktrees/`, off a fresh **`main`**; a short branch per step, each its own PR into `main`; and **your own dev and test databases** — never the shared `touchwood` / `touchwood_test`. Ask the owner for the names and the port; the Catalog backend used `TouchWood-catalog`, `touchwood_catalog` / `touchwood_test_catalog` and 8003 (its `catalog` branch is merged and deleted). In your worktree only, point `phpunit.xml` at your test database and `git update-index --skip-worktree phpunit.xml`. |
| Branches | Trunk-based (owner, 2026-10-07; `docs/CONVENTIONS.md`, "Branches and versions"). `main` is the only long-lived branch: one short branch and one PR into `main` per step, spec or feature, named `<type>/<module>-<what>`, squash-merged within a day or two. There are no stage branches and no stacked PRs. A small fix is a commit straight on `main`, after `composer check`. Delete a branch as soon as it merges, locally and on GitHub. The owner decides each merge. |
| Shared files | Expect conflicts in `docs/HANDOFF.md`, `deptrac.yaml`, `composer.json`/`.lock`, `package.json`/`-lock`, the generated TypeScript types and their manifest, the permission lists, `resources/css/themes.css`, and amendment numbers in a module's spec (two branches can each add "the next" one — check `main` before numbering). |
| Who asks the owner | You ask about the screens. A change to another module is that module's amendment: say so and ask. |

## 7 · Environment (Windows)

- **Docker Desktop does not start by itself.** The Postgres 17 container is `touchwood-postgres-1`
  on port **5433** (a PostgreSQL 18 service owns 5432 — leave it alone). After a restart: start
  Docker Desktop, `docker start touchwood-postgres-1`, wait for `docker exec touchwood-postgres-1
  pg_isready`. A test run against a stopped database hangs instead of failing.
- **Prefix every PowerShell command:**
  `$env:Path = [Environment]::GetEnvironmentVariable('Path','Machine') + ';' + [Environment]::GetEnvironmentVariable('Path','User'); $env:PAO_DISABLE='1'; $env:COMPOSER_PROCESS_TIMEOUT=0`
  (`PAO_DISABLE` turns off a package that rewrites test output for agents). `composer` works in
  PowerShell, not in Git Bash.
- **`composer check`** = pint → PHPStan level 8 → deptrac → `tsc` → Pest. **35–55 minutes** for the
  whole suite on this machine (2026-10-06): run it in the background, writing to a log file, delete
  the old "done" marker first, and read the summary.
- **Parallel Pest**: `--parallel --processes=10` at most — more runs the shared container out of
  locks; Paratest takes one path per run.
- **The browser suite:** `npm run build` first, in its own command (never during a test run), then
  `php -d memory_limit=1536M vendor\bin\pest --ci --testsuite=Browser`, after `composer check`. Warm
  it with one small file first (`tests\Browser\AccountScreenTest.php` — its first test times out
  cold), and give every run a watchdog (§8, 5).
- `python3` opens the Microsoft Store stub and hangs; `python` works.

## 8 · Traps that have cost real time

The full list is the lessons file (§9). The ones most likely to bite a screens job:

1. **Backslashes and quotes get eaten.** PHP namespaces or regexes written through a shell heredoc
   or an inline Python string lose their `\`; an apostrophe in a single-quoted PHP string written by
   a script breaks it. Use the editing tool for anything with a backslash; use double quotes for PHP
   text holding an apostrophe; run `php -l` on every file a script touched.
2. **CI runs on Linux; Windows ignores letter case.** Never build a path by guessing a folder's case.
3. **No country, city, currency or store name in `Domain/` or `Application/`** — comments included
   (`NoStoreLiteralsTest`).
4. **Generated types:** after adding a `#[TypeScript]` class or a public enum — and **after every
   merge** — run `php artisan typescript:transform` and commit what changes. Git's remembered
   conflict resolutions (rerere) once replayed a wrong manifest.
5. **The Pest browser plugin:**
   - It **can hang** at near-zero CPU, printing nothing. Start every run with a watchdog
     (`Start-Process -PassThru`, `WaitForExit` of a few minutes, then stop only that process tree)
     and re-run once in a fresh command.
   - Its server **cannot receive a file upload**: test uploads over HTTP; in a browser test put files
     in through the use case.
   - Its assertions read the page once and **do not wait**; poll in `script()` with a promise that
     answers within 4 s.
   - **A leftover `tests/Browser/Screenshots` folder** — even empty — fails the next run; delete it.
   - Sign in at desktop size, then resize; typing under `on()->mobile()` loses a race with React.
6. **The page budget**: a route helper in the admin layout pushed every admin page over 60 KB;
   the panel writes its addresses out (`router.post('/admin/…')`). After any layout change, build and
   run `JavaScriptBudgetTest` before the full check.
7. **Anything depending on the acting user is bound `scoped`, never `singleton`** — and never caches
   per-request state in a property: key it by the request.
8. **In an HTTP test, create other accounts' data before the browser signs in.**
9. **PostgreSQL ends a transaction after a failed statement.** A test that expects a database refusal
   does the write inside `DB::transaction()`.
10. **A CHECK whose test comes out NULL lets the row through**: write CHECKs on nullable columns with
    `IS NOT NULL` / `IS NOT DISTINCT FROM` / `COALESCE`, and test each with the column left NULL.
11. **A guard must assert it found something**; **test data that fails two checks proves only one**.
12. **Names typed from memory** (a file, a namespace, a permission's flags) failed three runs: look
    them up first.
13. **Verify a review's findings in the code before acting**, and a test's premise.

## 9 · Outside the repository: the owner's memory

Folder: `C:\Users\Abood\.claude\projects\C--Users-Abood-Documents-GitHub-TouchWood\memory\`

A session started in another folder (your worktree) does **not** load it automatically — open these
files yourself:

| File | What it holds |
|---|---|
| `MEMORY.md` | The index of everything below. |
| `touchwood-lessons-platform.md` | **Every mistake made so far, 162 entries in 7 groups.** Re-read before each step; add one the moment anything fails. |
| `touchwood-working-rules.md` | The owner's working rules (§1–§2 above). |
| `touchwood-ui-use-geist-shadcn.md` | The owner's rule on Geist and shadcn, and every answer given about it. |
| `touchwood-frontend-decisions.md` | The owner's answers for the frontend spec. |
| `touchwood-catalog-build.md`, `touchwood-catalog-plan.md` | How the Catalog backend was built, and the owner's answers along the way. |
| `touchwood-import-format.md` | The agreed import format and its rule. |
| `touchwood-roles-nest.md` | Super Admin ⊃ admin ⊃ staff, in the owner's words. |
| `touchwood-latin-digits.md`, `touchwood-panel-store-choice.md` | Latin digits (built) and the store-choice job (§4). |
| `touchwood-owner-fixes-2026-10-04.md` | The owner's fix list after testing the rebuild — what they look for on a screen. |
| `touchwood-branch-workflow.md`, `touchwood-dev-env.md`, `touchwood-no-ai-attribution.md` | Branches, the Windows environment, the attribution rule. |

The owner's global instructions (`C:\Users\Abood\.claude\CLAUDE.md`) apply to you as well: verify
before acting, no fake data, report reality, mark assumptions as assumptions, stay in scope.
