# Brief for an agent joining TouchWood

Written 2026-09-22, at the end of stage 2b step 0. Read this whole file, then the files in
**Read these first**, before you write a line of anything.

You are joining work already in progress. Most of what the person who wrote this knows is in this
repository. What is **not** in the repository is in this file: the owner's working rules, the method
we settled on, the traps that have cost us real time, and where the work stands today.

---

## 1 · Who the owner is and how they work

The repository owner decides everything. Not "approves" — decides.

- **Ask before any decision, and say what each option costs later.** Never a bare question. Give the
  options, say what each one makes easy and what it makes expensive in six months, and say which you
  would pick and why. Then wait.
- **Never build on an unclear answer.** If an answer could mean two things, ask again. Building the
  wrong thing costs more than one more question.
- **Never invent an answer**, a package name, a config key, a CLI flag, a URL or an API field. If you
  have not verified it in this session, you do not know it. Say "I need to check."
- **No fake data.** Ever. Not a sample name, not a placeholder date, not an example URL. If mock data
  is genuinely needed, the owner asks for it and it is labelled MOCK.
- **Report failures honestly, with the real output.** Never say "done", "tested" or "working" unless
  you ran it and saw it pass. If part of something is finished and part is not, say exactly which.
- **Stay in scope.** Build what was asked. Do not add files, features or integrations nobody asked
  for. Do not delete, publish, push or merge without the owner's word.
- **Status while you work.** The owner wants to know where things stand every 20–30 minutes of work,
  not a silent hour followed by a wall of text.
- **No AI attribution anywhere.** Not in a commit message, not in a PR body, not in a code comment,
  not a co-author trailer, not a "generated with" line. The owner has said this explicitly and more
  than once. Commits and PRs read as the owner's work, because they are.
- **History is not rewritten.** Everything published stays as it is. Fix forward.

Secrets live in server environment variables only — never in a file, a fixture or a test.

## 2 · Read these first

| File | What it is |
|---|---|
| `docs/HANDOFF.md` | **The source of truth.** §2 rules and the §16 rejection table are decided — do not re-propose what they reject. §15 items are open; ask the owner. §17 is the build order. §18 is the nine sections every module spec must have. |
| `docs/CONVENTIONS.md` | How work proceeds, the layout, the rules that bite. **"How a step is done here"** is the method, agreed 2026-09-22. |
| `docs/STRUCTURE.md` | Where code goes. |
| `docs/modules/platform.md`, `docs/modules/access.md` | The two finished modules' specs — and the model for how a spec reads. |
| `docs/modules/frontend.md` | The stage 2b specification. §0.1 lists what Access changed that the older spec did not know. |
| `docs/modules/frontend-step-0.md` | The step-0 specification, built and reviewed. Its **Left open** section holds one question waiting on the owner. |
| `docs/design/v2/` | The design handoff of 2026-09-22 — prototypes, foundations, brief. The design of record. |

The owner's **latest word overrides the handoff**; when that happens, the handoff is updated.

## 3 · Where the work stands (verified 2026-09-22)

- **Stage 1 Platform** — merged to `main`. Stores, currencies, tax, settings, media, audit.
- **Stage 2 Access** — merged to `main`, PR #36, 2026-09-21. Identity, auth, verification, RBAC,
  staff, addresses, 2FA. Nine steps, each reviewed and mutation-tested.
- **Stage 2b frontend foundation** — the spec is merged (PR #37). **Step 0** (six backend changes the
  frontend needs) is built on branch `stage-2b-step-0`, PR #38, reviewed and mutation-tested; it
  needs the owner's go to merge. Steps 1–4 are specified but not built.
- **Stage 3 B2B** — not started. The owner's pre-spec answers exist but the spec is not written.
  There is a `b2b-spec` branch for it.
- Everything from stage 4 on is blocked on the external provider schema (handoff §17).

The stack: Laravel 13, PHP 8.4, PostgreSQL 17, Inertia + React + shadcn with SSR. There is **no
separate JSON API phase** — a screen and the endpoint behind it are built together, by the module
that owns the data.

## 4 · The method (summary — the full version is in `docs/CONVENTIONS.md`)

Settled with the owner on 2026-09-22, after Access, because deciding as we went cost too much:

> **Settle the whole specification first. Agree the step list cut from it. Then build step by step
> from that definition.** Questions during a build are allowed, but rare, and batched.

A spec is written against the **merged code**, not against an older spec — the frontend spec had to
be rewritten against what Access actually turned out to be. Start by reading the code and listing
what changed.

Every step ends the same way: `composer check` green, an **independent review** of the diff against
the spec, **every review finding verified in the code before it is acted on**, a **mutation run**
over what changed, then the owner's go to merge.

## 5 · If you are writing a specification

This is the job the owner is most likely handing you, because it parallelises safely — specs are
documents, and documents do not collide with code being written elsewhere.

1. Read the merged code of everything your phase touches. List what it actually does now.
2. Write `docs/modules/{phase}.md` in the nine sections of handoff §18.
3. Collect every open question. Put them to the owner **in batches**, each with options and what
   each costs later. Do not ask one at a time; do not ask thirty at once.
4. Write the answers into the spec, dated, in the owner's own words.
5. Cut the step list from the finished spec and get that approved too.
6. Hand over: the approved spec plus the approved step list is what a builder starts from. Anything
   you decided that is not written in the spec does not exist.

Mark your own assumptions in the document as `My assumption, stated for the owner to reject:`.

## 6 · Traps that have cost real time

- **Backslashes get eaten.** Writing PHP namespaces or regexes through a shell heredoc or a Python
  script mangles `\` — even inside a quoted heredoc in this environment. A `use` line with a
  namespace comes out as garbage, and a two-backslash string literal comes out as one. Use the
  editing tool for anything containing a backslash, or build them with `chr(92)`. This has gone
  wrong roughly ten times.
- **`composer` is on PATH in PowerShell, not in Git Bash.** A `composer check` piped to `tail` in
  bash exits 0 having run nothing at all. Run it in PowerShell and read the whole output.
- **Read why something failed before re-running it.** The owner has said this directly. A re-run is
  not a diagnosis.
- **`composer check` takes minutes.** Set `COMPOSER_PROCESS_TIMEOUT=0`.
- **One test process at a time** against `touchwood_test`.
- **Anything depending on the acting user is bound `scoped`, never `singleton`.** A singleton holding
  the authorizer answers a later request with an earlier person's permissions. This has already
  happened once, in the admin menu, and was caught by a test.
- **A guard must assert it found something**, or a moved directory makes it pass over nothing. PHP's
  `glob()` has no globstar: a `**` pattern silently matches one level only.
- **Verify a review's findings in the code before acting.** Reviews are confidently wrong often
  enough that fixing an imaginary problem is a real risk.
- **Check a test's premise.** Two tests written here asserted things that were not true of the
  system — one assumed a store could be closed, when stores cannot be deleted at all because the
  audit log is append-only.

## 7 · What is not in the repository

The owner's memory files live outside it, at
`.claude/projects/C--Users-Abood-Documents-GitHub-TouchWood/memory/`. They hold the owner's answers
from sessions past, the branch workflow, the dev environment's quirks, and a lessons file with every
mistake made so far. If you can read them, read them; if you cannot, ask the owner for what you need
rather than guessing.

## 8 · Branches

A phase branch off `main` (e.g. `stage-2b`), a sub-branch per step off the phase branch, one PR per
step into the phase branch. The phase branch merges to `main` only when the whole module is done.
Specs are written on their own branch — `b2b-spec` exists for stage 3.
