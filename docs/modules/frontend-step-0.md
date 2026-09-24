# Stage 2b, step 0 — the six changes other modules must make

**Status:** APPROVED to build — the owner agreed to all six and answered the four decisions on
2026-09-22.
**Source:** `docs/modules/frontend.md` §0.2 and §4.3 (P1–P6); `docs/modules/platform.md`;
`docs/modules/access.md`; the code as merged on 2026-09-22.
**Scope:** no screen, no React, no page. Six changes to Platform and Access, each of which the
screens of steps 1–4 depend on. Each becomes an amendment to its own module's specification when
it is agreed.

Every one was checked against the merged code before it was written here; where the check found
something the earlier draft did not know, it is marked **(found 2026-09-22)**.

---

## P1 · A module uploads a file for its own use — Platform

### Today

`PlatformApi` reads media (`media()`, `mediaUrls()`) but has no way to upload. Uploading goes
through `UploadMediaHandler`, which asserts `platform.media.upload` globally.

Consequence, checked: `access.own_account.update` is automatic for **every** staff member, so every
staff member may edit their own account — but none of them may set a picture unless someone also
gives them `platform.media.upload`, a store-free media permission. The avatar column exists and the
screen (§3.2 B1) is specified; nothing can fill it.

B2B needs the same in stage 3, for company documents uploaded by a customer who will never hold a
media permission.

### The change

One method on `PlatformApi`:

```php
/**
 * A module uploads a file for its own use. Platform checks the permission that module names for
 * the change — not platform.media.upload — and stores the file as any other media.
 */
public function uploadMediaFor(ModuleUploadDto $upload): string;
```

`ModuleUploadDto` (Platform `Public/Dto`), carrying: the **module** doing the upload, the
**permission** that module checks for this change, the **scope** to check it in (Shared
`PermissionScope`: global, one store, or all stores), the **visibility** (public or private), the
**path** of the file on local disk and its **original filename** — the last three exactly as
`UploadMedia` takes them today.

### Rules

- The permission must be **declared** and must **start with the calling module's name**. An
  undeclared name is refused by the authorizer (`InvalidPermissionCheck`, "no module declares it" —
  a programming error, not a refusal); a name belonging to another module is refused by Platform
  (`InvalidMediaAttribute`, "does not belong").
- **What this does and does not prove.** The module names itself, so the check catches a mistake,
  not a lie: nothing stops a caller passing another module's name together with that module's
  permission. What keeps modules apart is deptrac and the review, as everywhere else in this
  codebase — this rule's job is to stop `platform.media.upload` from being the only key to the
  media table, not to be a security boundary between modules.
- Everything else is what `UploadMedia` already does: the checksum dedupe for public images,
  variants queued, the same size and type limits, the same audit entry — with the module and the
  permission named in it.
- **No new column.** `platform.media.uploaded_by` already records the actor (found 2026-09-22).
- The file appears in the media library like any other, and `MediaUsages` still governs whether it
  can be deleted. *My assumption, stated for the owner to reject:* that is right — a staff picture
  should be visible to whoever manages media.

### Tests

A staff member holding no media permission uploads an avatar and it works. A module naming a
permission that is not its own is refused; so is an undeclared name; so is a staff member who does
not hold the named permission. The audit entry names the module and the permission it went under.
Nothing about `platform.media.upload` changes for the media library screen.

---

## P2 · Every permission carries a group — Platform and Access

### Today

`PermissionDefinitionDto(name, audience, reserved, kind)` — no group. The role editor (§3.4 D3) and
the staff member's permission screen (§3.3 C6) must show actions grouped by business area, which is
also how handoff §14 describes the menu.

### The change

`PermissionDefinitionDto` gains `group`, a key from a **fixed list**. The list belongs to Access,
which owns permissions, and each group is named in Arabic and English in Access's translations at
`access::permission_groups.{group}`. A module picks one when it declares a permission; an unknown
group is refused at boot, where the catalog already refuses bad declarations.

Why a fixed list rather than each module naming its own: two modules would otherwise name the same
area differently, and the role editor would show "Staff" and "Staff and permissions" as separate
groups.

**Reserved and automatic permissions need no group** — they are never offered in the role editor
(`access.super_admin.manage`, `access.account.anonymize`, `platform.store.create`, the currency
pair, and everything with a customer, guest or staff audience). The DTO allows null for them, and a
test asserts every *role* permission has a group.

### The groups, and where today's permissions fall

Six of these are the owner's, approved 2026-09-19 (handoff §14 and the design). Three were added
on 2026-09-22 (D2), because Platform's own permissions had nowhere to go.

Only the actions a role can be given appear here. A **reserved** action — `platform.store.create`,
`platform.currency.create`, `platform.currency.update`, `platform.media.variants.generate` — belongs
to Super Admins alone, is never offered on the role screen, and therefore has no group: the catalog
refuses one that carries a group.

| Group | Approved | Permissions today |
|---|---|---|
| `staff_and_permissions` — Staff and permissions | yes | `access.staff.invite`, `access.staff.update`, `access.staff.assign_role`, `access.staff.disable`, `access.staff.view`, `access.role.manage`, `access.staff_settings.update` |
| `store_settings` — Store settings and tax | yes | `platform.store.view`, `platform.store.update`, `platform.settings.view`, `platform.settings.update`, `access.settings.update`, `access.address_format.update` |
| `customers` — Customers | 2026-09-22 | `access.customer.view`, `access.customer.block`, `access.customer.delete` |
| `media` — Media library | 2026-09-22 | `platform.media.upload`, `platform.media.update`, `platform.media.delete` |
| `audit` — Audit log | 2026-09-22 | `platform.audit.view` |
| `catalog` — Catalog and variants | yes | none yet |
| `pricing` — Pricing and campaigns | yes | none yet |
| `orders` — Orders and fulfilment | yes | none yet |
| `companies` — Company approvals | yes | none yet |

### Tests

Every role permission has a group; every group is named in both languages; an undeclared group is
refused at boot. The existing catalog tests already assert names in both languages — this joins them.

---

## P3 · The staff account remembers the store they are working in — Access

### Today

Checked: `access.staff_users` has no such column. The admin panel carries no store in its URLs
(§2.2), so the store has to be remembered somewhere, and the owner chose the account rather than
the browser (2026-09-19) so it follows the person between machines.

### The change

- A nullable `current_store_id` on `access.staff_users` — `char(26)`, foreign key to
  `platform.stores`, **`ON DELETE SET NULL`** so closing a store cannot block anything.
- A use case `ChooseCurrentStore(storeId)` under `access.own_account.update`, which every staff
  member has. It refuses a store the person's role does not cover (`Unauthorized`), so the column
  can never hold a store they may not see.
- The read the panel uses: the remembered store **if it is still one of theirs**, otherwise their
  first store by the store's position, and the screen says so — §2.2's "You no longer have access
  to Egypt — showing KSA."
- A Super Admin covers every store, so any store is valid for them, and a Super Admin with none
  remembered opens in the first store by position.

### Rules

- **Not audited** (owner, 2026-09-22): it changes many times a day, reveals nothing, and would bury
  the audit log.
- Store-free screens (media, roles) ignore it entirely.
- It is a preference, not permission: it never widens what the person may see. Every read still
  filters by the person's own stores.

### Tests

Choosing a store outside the person's own is refused. A remembered store that leaves their scope
falls back to the first remaining one. Closing a store leaves the column null, and the panel still
opens. A Super Admin may choose any store.

---

## P4 · The sign-in code page receives the masked phone — Access

### Today

Checked (found 2026-09-22): nothing in Access masks or exposes a phone number. `PendingSignIn`
carries `staffId`, `sessionVersion` and `needsPhone`. The code screen (§3.1 A3) has to tell the
person *which* number the code went to — they may have two — without showing it.

### The change

`PendingSignIn` gains `maskedPhone`: the **last three digits** with everything before them masked
(`•••••••180`), built inside Access from the account's number. It is null when `needsPhone` is true,
because there is no number yet.

### Rules

- The masking happens in Access. The page data never contains the full number, not even to be
  masked in React — a page's data is visible to whoever holds the browser.
- Three digits is the owner's decision of 2026-09-19 and is not re-opened here.

### Tests

The page data carries the masked form and never the full number. A short number still masks
correctly and never reveals more than three digits. The masked value appears on the code screen and
on the invitation code screen (A7).

---

## P5 · Where the shared form-error helper lives — and a problem with the earlier answer

### Today

`Modules\Access\Presentation\Http\FormErrors` turns a `DomainError` into a message on the form, in
the person's language. Platform's admin screens (§3.5) need exactly the same and **cannot import
Access**: Platform sits below it.

The draft's answer was to move it to `app/Http`, beside `ProblemDetails`.

### The problem, found 2026-09-22

Deptrac's rule is that a module may import **only another module's `Public/**` and `Shared/**`**.
No module imports `App\` today — I checked, there is not one. So moving the helper to `app/Http`
and having Access's controllers import it would either fail the dependency check or require the
rule to be widened. The earlier answer skipped that consequence.

### The three ways out — **the owner chose 1 (D1, 2026-09-22)**

1. **`app/Http`, with a narrow rule change.** The helper joins `ProblemDetails` as framework glue,
   and deptrac gains one rule: a module's **`Presentation/`** layer — and only that layer — may use
   `App\Http`. Defensible, because Presentation is the framework-facing layer and `ProblemDetails`
   already lives there. It does widen a rule the project has kept absolute.
2. **`Modules\Platform\Public\Http\FormErrors`.** No rule changes at all: every module may already
   import Platform's `Public`. The cost is that a presentation helper sits in a module's public
   contract, beside DTOs and events, which is not what `Public/` has meant so far.
3. **The Shared kernel.** Rejected on precedent: the error renderer and the correlation-id
   middleware were deliberately moved *out* of Shared into `app/Http` on 2026-09-18 as framework
   glue, and the kernel holds a ~20-class ceiling.

**Decided: 1.** The rule is written as narrowly as deptrac can express it, and a test asserts that
no layer but `Presentation` uses `App\`.

### Tests

Whichever way: Access's existing feature tests pass untouched, Platform's screens produce the same
message for the same error, and the dependency check stays green with the rule as it then stands.

---

## P6 · The admin menu registry — Platform

### Today

Nothing. The menu has to be built from what each person may do (handoff §14), and every module must
be able to add its entries as it ships.

**Decided 2026-09-22:** Platform keeps the registry — the same shape as the permission catalog and
the settings registry. Platform never reaches into Access: "may this person do X" is asked of the
Shared `Authorizer`, which Access implements and Platform already uses.

### The change

A contract in Platform's `Public/Contracts`, registered from each module's service provider exactly
as `SettingsRegistry::define()` and `MediaUsages::register()` are today:

```php
$menu->register(new MenuEntryDto(
    module:     'access',
    key:        'staff',
    group:      'staff_and_permissions',
    routeName:  'access.admin.staff.index',
    permission: AccessPermissions::STAFF_VIEW,
    position:   10,
));
```

- **Label:** `{module}::menu.{key}`, in Arabic and English. A test fails if either is missing.
- **Group:** the same list as P2, so the menu and the role editor group things identically (owner,
  2026-09-22).
- **Permission:** the one that entry needs. An entry with **no** permission is a "coming soon"
  entry, shown to Super Admins only (§2.2), for a module whose permissions do not exist yet.
- **Reading it:** `for(Actor)` returns the entries that person may use — each permission asked of
  the Shared `Authorizer` (held in any store), plus the "coming soon" ones for a Super Admin. A
  group with nothing in it for that person is not returned at all.

### Rules

- The registry decides **what is offered**, never what is allowed: every screen behind an entry
  still checks its own permission in its handler. Hiding a link is not protection.
- Entry keys are unique per module; two modules may not claim the same route.
- **What `register()` can check, and what it cannot.** It runs while providers are booting, from
  Platform, which sits below every module. So it checks what it can see for itself: the group is one
  the role editor uses, the module/key pair is not taken, the route name is not taken. It cannot
  check that the permission is declared (the catalog is Access's, above it, and not every module has
  declared yet), nor that the route or the label exists (neither is registered yet).
  - An **undeclared permission** is caught instead the first time anyone asks for a menu — the
    authorizer refuses a name no module declares — so it fails on the first request, for everyone
    including a Super Admin, rather than silently hiding a screen. There is a test for it.
  - A **missing route or label** is not caught by the registry at all. It is caught by a test over
    the real entries, which arrives in step 1 with the first real entries — there are none in step
    0, and a test over an empty list proves nothing.

### Tests

A staff member with one permission sees exactly one entry. A Super Admin sees the "coming soon"
ones; nobody else does. Two entries sharing a position are ordered by key, so a menu never shuffles
between requests. An entry whose permission no module declares makes the menu throw the first time
it is built. Labels and route names are tested in step 1, against the real entries.

---

## Decisions — answered by the owner, 2026-09-22

All four as recommended.

| # | Question | Decided |
|---|---|---|
| **D1** | Where the shared form-error helper lives (P5), now that moving it to `app/Http` needs the dependency rule widened | **`app/Http`**, beside `ProblemDetails`, and deptrac gains one rule: a module's **`Presentation/`** layer, and only that layer, may use `App\Http`. A test asserts no other layer uses `App\` |
| **D2** | The three new permission groups — Customers, Media library, Audit log — added to the six approved on 2026-09-19 (P2) | **Added.** Nine groups in all, and Platform's own permissions have homes |
| **D3** | Whether choosing the current store is written to the audit log (P3) | **Not audited.** It is a preference that changes many times a day and reveals nothing |
| **D4** | Whether the menu uses the same group list as the role editor (P6) | **One list.** The same nine groups, the same words, in the menu and in the role editor |

Nothing in this document is open. It is ready to build.

---

## Found while building, and answered — 2026-09-22

**A queued job's two answers about who it is.** Access's authorizer answered "is this actor
unlimited?" and "which stores does this actor hold X in?" differently inside a job a *person*
queued: the first said no, correctly, while the second still answered as the system and granted
every store. **The owner's decision, 2026-09-22: fix it now.**

Looking at it properly showed the disagreement was wider than the review said, and that the obvious
fix would have broken uploads:

- `authorize()` — "may this proceed?" — also answers for the system inside such a job. That one is
  **deliberate and stays**: the person's permission is checked when they *start* the action, and the
  job then does work they often cannot do directly. Generating image variants is reserved to Super
  Admins, yet every upload queues it. Restricting this would have stopped images being resized for
  everyone but a Super Admin.
- `storesWith()` — "which stores does this actor hold X in?" — is the scoping question, and it is
  what decides which **rows** a screen or a report shows. It now answers with the **requester's**
  stores. This is where rows would have leaked: `ListStaff`, `ListCustomers`, `ViewStaff`,
  `ViewCustomer` all scope themselves with it.
- `isUnlimited()` now shares that definition, so the two agree. A job queued by a Super Admin is
  unlimited, which it was not before.

Three tests hold it, including one pinning the deliberate exception so that it is not "fixed" by
somebody later and uploads break quietly.

---

## What step 0 does not include

No React, no page, no layout, no screen. The admin menu is *registered* in this step but nothing
renders it until step 1. Access's and Platform's existing behaviour is unchanged except where a
row above says otherwise: no permission is renamed or removed, no existing endpoint changes its
answer, and every test that passes today passes afterwards.
