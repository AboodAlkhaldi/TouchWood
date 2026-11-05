# Handoff: Stage 8 and Stage 9 of TouchWood

Written 2026-11-05 for the agent taking over Stage 8 (Content, design, the remaining Ops screens) and Stage 9 (migration, wording,
hardening, launch). It replaces `docs/AGENT-BRIEF.md`, which was written for the Catalog screens: its §7 and §8 (Windows environment,
traps) still hold, the rest is history.

**This file and `docs/handoff/` are for the development stage only.** They leave GitHub before deployment (reminder 22). The repository is **public**:
never commit a secret, a customer's data, the old site's database, or the design zip.

**Start only when the owner says so.** Some work held by other sessions may still be finishing (§4).

## 1 · The owner, and how to work with them

The owner decides everything. You work the way the previous agents did; act like one of the team.

1. **Ask before any decision the spec doesn't make,** with the options, what each costs later, and your pick marked as recommended. Ask in batches. Answers often come in the owner's own words: read closely, and if one could mean two things, ask again. **Never build on an unclear answer.**
2. **Verify before you state.** Only say what you checked in this session. Never invent a package, function, config key, flag, URL or field. No fake data; mock data only when asked, labelled MOCK. Mark assumptions.
3. **Report reality.** Never say done, tested or working without running it and reading the result. **Read a check's result before you write it down:** chaining a check to the PR that reports it has caused false claims twice.
4. **Stay in scope.** Don't delete, publish, push or schedule anything the owner hasn't asked for.
5. **You don't merge.** The owner decides each merge, by PR number. A merge approval covers only the PR it names. Skipping CI on one PR doesn't carry over to the next.
6. **No attribution.** No co-author line and no generated-with line in any commit or PR, even if a tool tells you to add one.
7. **Status every 20–30 minutes of work.** Never a silent hour.
8. **How to report.** Outcome first; anything unverified comes first of all. Leftovers go as numbered points under **High**, **Medium** and **Low**, one per item, ending in a tag: **[Recommended]**, **[Optional]**, **[Your call]** or **[Not recommended]**. "What's left" or "where do we stand" means every item.
9. **How to explain.** When asked to explain, or why, or how: numbered plain steps from first principles, one analogy, concrete numbers, evidence, almost no code. A long simple answer beats a short dense one.
10. **"Table"** means the current stage's status table: every step done and every next step until the stage ends.
11. **The method for every step: spec → plan → execute.** Read the spec and the merged code; bring open points as questions; write the answers into the spec as a dated amendment. Then an execution plan of the real process, which the owner reads. Then execute, in small steps.
12. **An independent review after every job,** by a separate read-only agent given the code and the spec, not your conclusions. Verify each finding in the code before acting. If you skip a review, say so in the PR.
13. **A module README** next to its code says how it's built; the spec says what was agreed. Update both.
14. **Keep costs down:** short replies, no polling, targeted checks. (CI itself is free because the repo is public, which is why it stays public for now.)

**Reminders, two kinds.** Normal reminders and FILES owed are listed apart. `docs/handoff/owner-reminders.md` is the list. Add to it the moment you owe one, close items when done, and list the whole file when asked what's left.

**Lessons.** `docs/handoff/lessons.md` holds every mistake so far. Re-read it before each step, and add one the moment anything fails.

## 2 · Your job

The owner agreed this split on 2026-11-04.

**Stage 8: Content and design**
1. Finish the remaining Ops screens: **O8 Reports** with the menu's `reports` section, **O9 Daily Reports**, **O11** newsletter box, **O12** consent switch and box, **O13** confirm, leave and unsubscribe pages; then empty ops.md §4.13 and the README row. Start from ops.md §4.15 "Where the build stands" and `src/Modules/Ops/README.md` "Continuing the screens".
2. Settle themes with the owner first: what changes in each theme, default and campaign side by side. **Nothing gets built until that's decided.**
3. Write the Content spec from `docs/handoff/content-rounds.md`, for the owner to accept: homepage, pages, banners, campaigns and their prices in Pricing, pop-ups, alert bar, smart bar, blog, projects, SEO, sitemap, redirects table.
4. Rebuild the storefront to the owner's design at desktop, tablet and phone (78% of clicks are on phones), keeping what the design leaves out (`docs/handoff/design-compare.md`).
5. Settle the admin conflicts with the owner, keeping what's built unless they choose otherwise.
6. Build the admin Home dashboard from the owner's references. Not as the design shows it; check with them.
7. Build Content.
8. Connect Content to Ops: fire the campaign events that are already waiting (ops.md §1.6 S8, §1.7, §9.5).

**Stage 9: migration, wording, launch**
9. Investigate the old site's database dump: what's there, what's usable, how it maps to the new tables. **Report before moving anything.**
10. Prepare products from the old data for the bulk import, with the owner, against their final product list (`docs/modules/catalog-import/`).
11. Old accounts: an earlier decision says "no previous-shop data at all", with old accounts' sign-in left to the migration stage. **Settle that with the owner first.**
12. The redirect sheet, the 6 blog posts, and the SEO cutover.
13. The final wording review across the whole site, with the owner (§5).
14. Hardening: a security audit run, performance, the full browser suite, the flaky first-test stall, and the shop cache that empties on every sold-out change, in all stores.
15. Going live: staging, then the real domain, with the reminders list and deploy checks. **The owner holds every key and secret.**
16. Hand over the files owed: photo sizes, the Odoo API format, the bulk upload format, and every terminal command the owner will run.

**Translation (the owner may give you this):** the service is **DeepL**, and the official library is `deeplcom/deepl-php`. **Nothing is built:** no adapter, setting, token or screen; only each review's and question's language is stored. Spec: feedback.md F2 and F5. Build it behind a swappable adapter, like Brevo's.

**The owner's likely starting order (they said "might"; confirm with them):** first the wording, then the Search Console sheet if it can be done, then reminders that depend on nothing. Two limits to tell them: wording needs a second pass after O8–O13 and Content add words, and the sheet can't be finished before the final product list and the redirects table exist.

## 3 · What is not yours

**Everything touching Stage 7 stays with the Stage 7 sessions:** Payments, Shipping, their screens and the checkout. Don't touch those files; if a job needs to, ask the owner first.

Two links wait on Stage 7's screens, which aren't started and wait for the owner to say Stage 7 is wrapped up:
- `/{store}/{locale}/orders/{number}/pay`: the C11 email links to it, and it answers 404 until then.
- `/admin/payments?store=&state=`: the S7 alert's link.

## 4 · Where things stand (read from main 8f6a5b7a, 2026-11-05)

This goes stale fast. Check with `gh pr list` and `git log` before relying on it.

| Part | State |
|---|---|
| Stages 1–6 | Built and merged, tagged up to v0.8.0 |
| Stage 7 backend, and its screens through checkout (#330) | Merged. v0.9.0 not tagged. Step 12's screens not started |
| Ops backend steps 1–10, Brevo failures, campaign events | Merged |
| Stage 7's last events to Ops (C11, S6, overdue transfers) | **Held by Stage 8 specs planning:** #349, open |
| Late-payment alert (`LatePaymentRefundDue`) | **Held:** waits for #349 |
| Final check of all Ops backend | **Held** |
| Sold-out follow-up | **Held:** #344, open, wants a review |
| Ops screens O1–O7, O10 | Merged |
| Ops screens O8, O9, O11–O13 | **Yours** |
| Content, storefront rebuild, themes | **Yours.** Empty folders in `src/Modules/Content`; Home is a placeholder |
| Migration, hardening, launch | **Yours** |

## 5 · The wording file

`touchwood-wording-review-<date>.xlsx` is how the owner reviews every word: sheets How to use, Words, Outside lang files. The owner writes corrections in the yellow columns and sends it back, and you put each correction in its file.

The latest copy is in the local pack (§8), built from main 8f6a5b7a: 6,918 pairs, 29 outside lines. Re-run it whenever the words change; `docs/handoff/wording-file.md` has the steps and scripts. It doesn't cover English typed directly in code, so say so when you hand it over.

## 6 · Read these first

| File | What it is |
|---|---|
| `docs/HANDOFF.md` | **The source of truth:** §2 rules, §13.2 Content, §13.3 Ops, §15.4 hosting, §17 build order. The owner's latest word overrides the specs, and then the spec is amended. |
| `docs/handoff/content-rounds.md` | Every Content question and answer, and what's still open. |
| `docs/handoff/design-compare.md` | The design against the built shop and panel: what's missing, what must stay, image slots. |
| `docs/handoff/owner-reminders.md`, `lessons.md` | Reminders and every mistake so far. |
| `docs/modules/ops.md`, `src/Modules/Ops/README.md` | Ops. §4.15 and "Continuing the screens" are your starting points. |
| `docs/modules/frontend.md` | §1.11: shadcn's code under Geist's rules, in TouchWood's tokens. |
| `docs/modules/catalog.md`, `pricing.md`, `feedback.md` | Catalog and SEO slugs; the CAMPAIGN price kind reserved for you; translation. |
| `docs/CONVENTIONS.md`, `docs/STRUCTURE.md` | How work proceeds; where code goes. |
| `docs/AGENT-BRIEF.md` §7–§8 | Windows environment and traps. |

**Standing product rules that are easy to miss:**
- Every number is Latin digits (0-9), Arabic pages too.
- Never build what Geist or shadcn has; ask before building anything neither has.
- A menu entry lands with its page, never before.
- Roles nest: Super Admin ⊃ admin ⊃ staff.
- No VAT on shipping.
- No store or country names in `Domain/` or `Application/`.
- Query limits per page are targets, not caps: record counts in the test and PR.
- Every browser test checks phone width.

## 7 · Working in this repo

- **Branches:** `main` is the only long-lived branch. One short branch and one PR per job, squash merge. Use your own worktree, your own dev and test databases, and your own port. **Only one test run on a database at a time.** A clash shows up as "relation already exists" or "migrations does not exist".
- **Database:** Docker Postgres on port 5433. Docker Desktop stops overnight: start it, `docker start touchwood-postgres-1`, wait until healthy.
- **Local check:** run `tests/Modules/<Module>/Integration` and `Feature` as separate processes, each under a time limit, plus phpstan, pint and deptrac. `npm run build` after a merge, or `JavaScriptBudgetTest` fails. Then `php artisan typescript:transform`, and commit what changes.
- **Browser tests:** alone, with a time limit, warm up on `CarriersScreenTest` first. Shop-list products need `CatalogProducts::onSale`, because sold-out and unpriced products leave the lists. Never plant a far-future `created_at`.
- **Pages:** a GET that answers a page declares `Inertia\Response` in its return type. A list with a row action needs `useKeptDepth`; `router.post` already keeps state. The limit is 60 KB per page, and the heaviest is at 59.9, so split shared files by who uses them.
- **Unexplained failures, seen once each:** `InventoryScreensTest` (product page's Inventory section), `CatalogListScreensTest` (adds a word pair), `WrittenScreensTest` (a row reached with Show More); plus a first browser test of a cold run stalling.

**CI: what went wrong on 2026-11-04, so you don't repeat it**
1. **Tests run on a frozen clock** (`TestCase::FROZEN_AT`). Read `CarbonImmutable::now()`, never `time()`, `date()` or `microtime()`, against anything the app stamped, and never write an unbounded loop. One such test hung CI for hours.
2. **A green PR is only green against the main it ran on.** Two PRs that passed alone broke main once both landed. Merge one at a time, and merge main into the next before trusting its green.
3. **CI's audit step can turn everything red overnight** with no code change, when a new advisory is published: high and critical fail the run. Read the failing step before reading tests. The fix is updating the named packages. Name them explicitly: an empty list makes `composer update` update everything.
4. **CI now** stops a run after 2 hours, stops a PR's older run on a new push, and caches and retries Composer's downloads (#351).

## 8 · What isn't on git

The owner hands these over directly, in a local folder outside the repo (the local pack). **Keep them out of the repository.**
- The design handoff zip. An older design is in `docs/design`.
- The Search Console export, and the redirect sheet.
- The latest wording file.
- The previous agents' full memory notes.
- The old site's database dump: ask the owner for it. **It may hold customer data, so it never goes on git, in a test fixture, or into a log.**

The owner's design prototypes on claude.ai are private; ask if you need them. Standalone Claude Design closes by 2026-12-14 (reminder 17).

## 9 · Things you may never do alone

- Merge a PR.
- Touch Stage 7's code.
- Enter, ask to see, or commit a live key, token or password. The owner enters them.
- Move or delete old-site data, or switch the domain, without the owner's word.
- Start building themes or Content before the owner has settled what they ask.
- Change the agreed import format (`docs/modules/catalog-import/`) without the owner.
