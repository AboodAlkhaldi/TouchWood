# Access module

**Build stage 2. Depends on Platform.** Every module above it depends on it.

Access answers **who someone is and what they may do**: customer and staff accounts, roles and
permissions, sign-in and sessions, addresses, and account deletion. The rules are in the approved
specification, [docs/modules/access.md](../../../docs/modules/access.md). This file explains how the
code is organised and why, and grows with each build step.

**Built so far: steps 1–3a of 8 — the permission catalog, roles, the real permission check, and
staff accounts.** Nobody signs in yet: until step 3b Platform's interim `ActorContext` still reports
the system, so in the running application only console commands and jobs act, and the invitation
and email-change links have no page to open until 3b adds their endpoints.

---

## Using Access from another module

Import only `Modules\Access\Public\**` (Deptrac refuses anything else).

```php
// Declare every permission your module checks, once, in your service provider's boot().
$catalog = $this->app->make(PermissionCatalog::class);
$catalog->declare('catalog',
    new PermissionDefinitionDto('catalog.product.update'),                                    // a staff role, per store
    new PermissionDefinitionDto('catalog.image.upload', kind: PermissionKind::Global),        // a staff role, store-free
    new PermissionDefinitionDto('catalog.brand.delete', reserved: true),                      // Super Admins only
    new PermissionDefinitionDto('catalog.review.write', PermissionAudience::EveryCustomer),   // every customer, own data
);

// Renamed or removed a permission in a later version? Say so; the next migrate updates the roles.
$catalog->renamed('catalog', 'catalog.product.edit', 'catalog.product.update');
$catalog->removed('catalog', 'catalog.product.archive');
```

- Name each permission in Arabic and English at `{module}::permissions.{resource}.{action}`.
- Check a **per-store** permission with `PermissionScope::store($id)` or `allStores()`, and a
  **store-free** one with `PermissionScope::global()`. Anything else throws
  `InvalidPermissionCheck` — a programming error, found by the tests.
- A setting's `permission` must be a declared per-store permission (a global setting is checked
  against every store). A test fails otherwise.

```php
// A staff member's name and communication language, e.g. for a notification (null if unknown).
$staff = $this->access->staff($staffId);                                 // AccessApi → ?StaffDto

// Which notifications they want, and where (Ops reads this before sending).
$preferences = $this->access->staffNotificationPreferences($staffId);   // list<StaffNotificationPreferenceDto>
```

- Listen for `StaffActivated` and `StaffDisabled` (dispatched after commit).
- Ops will send the security messages by binding its own `SecurityMessages`; until then Access's
  temporary sender uses Laravel mail and its `SmsGateway`.

---

## What is inside

| Folder | Contents |
|---|---|
| `Public/` | Contracts `PermissionCatalog`, `AccessApi`, `SecurityMessages`; DTOs `PermissionDefinitionDto`, `StaffDto`, `StaffNotificationPreferenceDto`; enums `PermissionAudience`, `PermissionKind`, `AccessLevel`, `StaffStatus`, `StaffNotificationTopic`; events `StaffActivated`, `StaffDisabled`. |
| `Domain/Model` | `Role` (saved or personal, admin or staff level, at least one action), `RoleAssignment` (a staff member's one role, their store row, and each action's own stores), `StaffUser` (invited → active ⇄ disabled; profile, phone, email, language, Super Admin), `StaffInvitation`, `StaffEmailChange`, `PhoneCode`. |
| `Domain/ValueObject` | `RoleName`, `StoreChoice` (all stores, or at least one chosen store), `RoleKind`, `RoleLevel`, `EmailAddress`, `PhoneNumber` (E.164, any country), `CountryCode` (the 249 ISO countries), `Language` (ar/en), `StaffProfile`, `PhoneCodePurpose`. |
| `Domain/Exception` | `AccessError` and its subclasses, with messages in `Presentation/lang/{ar,en}/errors.php`. |
| `Application/Permission` | `InMemoryPermissionCatalog` (declarations, renames, removals, checked at boot), `AccessPermissions` (Access's list, and which actions are admin-only). |
| `Application/Authorization` | `RoleAuthorizer` (the real `Authorizer`), `GrantRules` (who may grant what to whom, who may manage whom, console-only), `StaffGrants` + `GrantsReader` (a staff member's permissions, cached), `Author`. |
| `Application/Command` | Roles: `CreateRole`, `CloneRole`, `UpdateRole`, `DeleteRole`, `ChangeStaffRole`, `RefreshStaffPermissions`, `RefreshRolePermissions`. Staff (admin): `InviteStaff`, `ResendStaffInvitation`, `CancelStaffInvitation`, `DisableStaff`, `EnableStaff`, `UpdateStaffProfile`, `ChangeStaffEmail`. The invitee: `AcceptStaffInvitation`, `ConfirmStaffInvitation`, `ConfirmStaffEmailChange`. Own account: `UpdateOwnStaffProfile`, `RequestOwnPhoneChange`, `VerifyOwnPhoneChange`, `UpdateOwnNotificationPreferences`. Console: `CreateSuperAdmin`, `RevokeSuperAdmin`, `ResetSuperAdminPhone`. |
| `Application/Query` | `ListRoles`, `ViewRole`, `RoleEditorPermissions`, `MyPermissions`, and the `RoleReader` they use. |
| `Application/Security` | `SecretTokens` (links), `Codes` (SMS codes), `PasswordPolicy`, `PhoneVerification` (sending and checking a code, with its limits). |
| `Application/Settings` | `StaffSecuritySettings`: the staff security numbers, as Platform settings. |
| `Application/Staff`, `Messages`, `Audit` | `StaffMapper`, `StaffLinks`, `Avatars`; the `SmsGateway` port; `RoleAudit` and `StaffAudit` (every audit entry). `AccessApiImpl` sits beside them. |
| `Infrastructure/Eloquent` | Query-builder repositories, `CachedGrantsReader`, `DatabaseRoleReader`. |
| `Infrastructure/Messages` | `TemporarySecurityMessages`, `SecurityMail`, `LogSmsGateway`, `UrlStaffLinks`. |
| `Infrastructure/Security`, `Media` | `HmacCodes`, `LaravelPasswordPolicy`; `StaffAvatarUsage` (the avatar as Platform media). |
| `Infrastructure/Permission` | `PermissionSync`: carries renames and removals into the roles on every migrate. |
| `Infrastructure/Persistence` | Migrations: the schema and `staff_users`, the role tables, the staff account tables (invitations, phone codes, email changes, notification preferences). |
| `Presentation/` | The three Super Admin console commands, the security email view, translations. |

---

## Approaches and why

### Permissions: declared by the module that checks them

Every command handler asserts a permission (handoff §19). Each permission has an **audience**
(`ROLE` through a staff role; `EVERY_STAFF`, `EVERY_CUSTOMER`, `EVERY_GUEST` automatically, own data
only), a **kind** (per store, or store-free such as media and role management — the role editor
shows a store-free action's store boxes ticked and disabled), and may be **reserved** for Super
Admins. Platform sits below Access, so it publishes its list in `PlatformPermissions` and Access
declares it. Names come from translations, read only when a screen shows them.

### Three levels: Super Admin → admins → staff

A role is an **admin** or a **staff** role. The management actions (`staff.invite`, `staff.update`,
`staff.assign_role`, `staff.disable`, `role.manage`) go only into admin roles. Only a Super Admin
creates, edits or gives admin roles and manages admins; nobody changes their own role. An admin
manages a staff member only when holding **assign roles** in **all** of their stores — a staff
member's stores being their store row plus any store an exception adds. The same holds for
anything else that changes a staff member's access: editing, deleting or refreshing a saved role
they hold, or moving them when a role is deleted (amendment 11). A role page lists only the
holders the reader could reassign.

### One role per staff member, stores per staff member

A role holds only actions. The stores sit on the assignment: one store row for every action, and
any action may have its own stores for that person (an exception). A **saved** role is shared —
editing it changes it for everyone who holds it, so only an author covering every holder may edit
it. Editing a staff member's role from their page makes their **personal** role, edited in place
from then on, and deleted when they move back to a saved role.

### Nobody grants more than they hold

`GrantRules` checks every change: a role holds only actions its author holds; each action reaches
only stores where its author holds it; a store-free action only needs holding. Super Admins and
console commands are unlimited; a queued job grants only what the person who queued it holds.
"Every store" means the **All stores** choice — ticking every store one by one is not enough,
because a global change also reaches stores opened later.

A name left in a role by a module that is switched off grants nothing and stays where it is — an
edit of that role still works — but it is never copied into a clone, and a limited author cannot
give a role holding it to anyone: it would come back to life with its module.

### Locks and concurrent changes

Every handler locks **roles first, then staff and assignments**, so two admins changing related
things at once queue up instead of deadlocking; the transactions still retry a deadlock up to three
times. Commands that name a staff member check that the author may assign roles at all before
looking the id up, so someone without the right learns nothing about which ids exist.

### The check, and its cache

`RoleAuthorizer` denies by default. A staff member's permissions are built from four tables, so
they are cached per staff member with Shared's `VersionedCache` (1 hour at most, as a safety net).
Every change replaces the cached copy **inside its transaction**, and admins can also rebuild it by
hand (`RefreshStaffPermissions`, `RefreshRolePermissions`). A warm check reads only the cache table.
A cold load is **one SQL statement**, so a snapshot is one consistent moment even while a change
commits. The cache holds plain arrays, because `config/cache.php` refuses to rebuild objects. Every
migration or rollback replaces every staff member's cached copy, as Platform does for its caches.

As a second guard, the check itself never lets a staff-level role use a management action, even if
one reached such a role another way.

**Until step 3b** the interim `ActorContext` reports the system for a web request too, so
`RoleAuthorizer` lets the system act only outside web requests — exactly what Platform's interim
authorizer did. Customers act from step 4; integrations hold nothing yet.

### Renamed and removed permissions

`PermissionSync` runs at the end of every `php artisan migrate` — on `MigrationsEnded`, and on
`NoPendingMigrations` because Laravel fires that one instead when there is nothing to migrate. It
moves renamed names (and their exceptions' stores) and takes removed names out, auditing each
change. Two permissions renamed into one are refused at boot (their store choices cannot be merged
safely: rename one, remove the other), and a rename never puts a management action into a staff
role. A name that is neither declared nor removed is left alone and logged: it grants nothing,
and a module switched off by mistake cannot wipe anyone's roles.

### Staff accounts: invited, then active or disabled — or cancelled

```
invite ──▶ INVITED (not registered yet) ── accepts ──▶ ACTIVE ⇄ DISABLED (disable / enable)
             │  resend: a new link · cancel invitation: the link dies, still INVITED
             ▼  cancel the account (the inviter, or a Super Admin)
         CANCELLED — final; the email and phone are free for a new account
```

An admin invites with the whole profile, the communication language and a role (so inviting needs
both **invite staff** and **assign roles** in every store the person gets). The account starts
`INVITED`, with no password. The invitee opens the link, chooses a password and confirms the phone
— correcting it first if the admin mistyped it — then enters the SMS code; only then does the
account become `ACTIVE`. The chosen password waits, hashed, on the invitation row until the code is
right, so an `INVITED` account never has a password (a CHECK guards that).

An invited person gave nothing yet, so they are never disabled: the admin cancels the invitation
(the link dies, a new one can be resent) or cancels the account (`StaffCancellation`): final, the
role goes, and the unique email and phone indexes ignore cancelled rows, so both are free for a new
invitation. Only the admin who invited them (`staff_users.invited_by`, kept through resends) while
they may still invite staff, or a Super Admin, cancels an account. Every link, whoever sends it, goes
through `Invitations`: 72 hours for staff, 24 for a Super Admin (amendments 29, 30).

Disabling ends everything the person holds at once (their cached permissions are replaced in the
same transaction); only someone who accepted is disabled and enabled. Disabling, enabling, editing a profile and changing an email each
need their action (**disable staff**, **edit staff**) in **all** of the person's stores, and never
reach a Super Admin, another admin (except for a Super Admin) or oneself. A phone the admin changes
is unverified until the person verifies it. Each person edits their own profile, communication language, avatar,
phone (the new number counts only after its code) and notification toggles.

**Redirecting an account** — a new email, a new phone, a resent invitation — would let the admin
use the person's role, so it also needs every action of that role in the stores it reaches for
them (`GrantRules::requireCoversActionsOf`), the same as giving them the role.

**Nobody works without a role.** A revoked Super Admin is disabled, with every link and code. An
account with no role is enabled only together with one: `EnableStaff` takes the role and gives it
first, under every rule of `ChangeStaffRole`, in the same transaction — a refusal undoes both. Any
admin holding the action somewhere, or a Super Admin, may do it, because the person has no stores.

A staff **email changes only through a link sent to the new address** (72 hours); until it is used
the old one stays, and it takes effect only if whoever asked may still make the change then. A
Super Admin changes their own this way; nobody else can change it. Someone invited who has not
accepted has no proven address yet: their email changes at once and a new invitation goes there,
so the link sent to a mistyped address dies.

### Links and codes are never stored in plain text

A link carries 32 random bytes; only their SHA-256 hash is stored, so a copy of the database opens
nothing. An SMS code is stored as an HMAC keyed with the application key. A new link or code
replaces the previous one. Codes live 5 minutes, die after 5 wrong tries — each wrong try is
committed even though the request fails — and are resent no sooner than a minute later and at
most 3 times an hour per number. Every number is a setting (`StaffSecuritySettings`). A dead link
is refused before the password is checked, so a made-up link never reaches the leaked-password
service; that service's outages — an error reply as much as no reply — are logged
(`LoggedBreachList`) and the password accepted.

Both the email check and the phone check run in code before the database's unique indexes, and
again when the link or code is used, since someone may have taken the value in between. The
tests drop those indexes inside their transaction, so they prove the code refuses first.

### Messages

Access sends its own security messages until Ops exists, through `SecurityMessages` — one binding
to replace later. Emails go out **after the commit and never through the queue**, because a
queued job would store the link in the `jobs` table. SMS goes through `SmsGateway`, chosen by
`ACCESS_SMS_DRIVER`; only `log` exists until the SMS provider is chosen, and an unknown driver
fails loudly rather than sending nothing. `log` writes codes to the log, so it refuses to run in
production. Every message is in the person's communication language.

### Super Admins: the console only

`CreateSuperAdmin`, `RevokeSuperAdmin` and `ResetSuperAdminPhone` refuse every actor except the
console's own system actor — a Super Admin in the panel, or a job queued on their behalf, is
refused — so a hijacked admin session can never make or remove one. Revoking never removes the
last **active** Super Admin (an invited one who never accepted does not count), and disables the
account until an admin enables it together with a role.

```bash
# A new Super Admin: the whole profile, --locale (ar or en) included; only --address is optional.
php artisan access:super-admin:create owner@example.com "First" "Last" \
    --job-title="Founder" --date-of-birth=1990-01-31 --country=SA \
    --phone=+966500000000 --locale=ar --address="Riyadh"

# An existing staff member: the email alone promotes them (their role ends; their profile stays).
php artisan access:super-admin:create staff.member@example.com

php artisan access:super-admin:resend-invitation owner@example.com   # a new 24-hour link
php artisan access:super-admin:cancel owner@example.com              # cancelled and freed
php artisan access:super-admin:revoke owner@example.com       # never the last active one; disables (or cancels an invited one)
php artisan access:super-admin:reset-phone owner@example.com  # a lost phone
```

The invitation is emailed through `MAIL_MAILER` — `log` in `.env.example`, which writes the email,
link included, to `storage/logs/laravel.log`. Step 3b adds the endpoints the invitation form posts
to; the page the link opens comes with the screens, in the frontend foundation stage (amendment 12).

### The database is the last line of defence

Every CHECK, unique index and foreign key is enforced first in code, with a test that the code
refuses first (`AccessSchemaTest` checks the database refuses too). A CHECK that evaluates to NULL
passes, so the role-name rule is wrapped in `COALESCE(…, false)` — the schema test caught that.

---

## How it was built

| Step | What |
|---|---|
| 1 | Foundation: the `access` schema, the service provider, the permission catalog with Access's and Platform's permissions |
| 2 | Roles, assignments and exceptions; the real authorizer and its cache; the three levels; renamed and removed permissions; the role reads. `VersionedCache` moved to Shared; Platform's interim authorizer removed. Then an independent review (spec, security, tests): lock order and retries, one-statement cache loads, the admin-reach rule (amendment 11), stricter handling of undeclared names, and the missing tests, with a mutation run proving them |
| 3a | Staff accounts: the full profile, invitations accepted with a password and an SMS code, disable/enable, profile edits by an admin and by the person, email change by link, notification toggles, avatars as Platform media, the three Super Admin console commands, `AccessApi`, and the temporary security messages (Laravel mail, `log` SMS driver). No HTTP endpoints yet: they need the real `ActorContext` of step 3b. A mutation run (30 deliberate mistakes, each caught) proved the tests. Then an independent review (spec, security, tests) and the owner's answers: redirecting an account needs the person's actions; nobody works without a role; an invited person's new email gets a new invitation; an email change re-checks its requester; 3 SMS an hour; outages of the leaked-password service logged; the `log` SMS driver refused in production; many missing tests |
