# Access module

**Build stage 2. Depends on Platform.** Every module above it depends on it.

Access answers **who someone is and what they may do**: customer and staff accounts, roles and
permissions, sign-in and sessions, addresses, and account deletion. The rules are in the approved
specification, [docs/modules/access.md](../../../docs/modules/access.md). This file explains how the
code is organised and why, and grows with each build step.

**Built so far: steps 1–6 of 9 — the permission catalog, roles, the real permission check, staff
accounts, staff sign-in, customer accounts, customer sign-in, addresses, and deletion, blocking and
the staff views.** Both sides sign in through form
endpoints that answer with redirects; the pages that show those forms come with the screens, in the
frontend foundation stage (amendment 12). A web request acts as the staff member or customer signed
in, else as a guest — never as the system. Customers register (signed in at once), verify their
email by link and their phone by SMS code, sign in and out on the storefront, reset and change their
password, edit their own profile, keep an address book in each country, and ask for their account to
be deleted. Staff see the customers and the colleagues of their own stores, block and unblock, and
delete on a customer's request. **Step 7 is the module's own README and its final review.**

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
| `Public/` | Contracts `PermissionCatalog`, `AccessApi`, `SecurityMessages`; DTOs `PermissionDefinitionDto`, `StaffDto`, `CustomerDto`, `StaffNotificationPreferenceDto`; enums `PermissionAudience`, `PermissionKind`, `AccessLevel`, `StaffStatus`, `CustomerStatus`, `AccountType`, `StaffNotificationTopic`; events `StaffActivated`, `StaffDisabled`, `CustomerRegistered`, `CustomerEmailVerified`, `CustomerPhoneVerified`, `GuestBecameCustomer`, `CustomerBlocked`, `CustomerUnblocked`, `CustomerDeletionScheduled`, `CustomerDeletionCancelled`, `CustomerAnonymized`; `AddressDto` for the addresses Sales and Shipping read. |
| `Domain/Model` | `Role` (saved or personal, admin or staff level, at least one action), `RoleAssignment` (a staff member's one role, their store row, and each action's own stores), `StaffUser` (invited → active ⇄ disabled, or invited → cancelled; profile, phone, email, language, Super Admin, who invited them, session version), `Customer` (one account for every store: email, account type and home store fixed; verifications only move forward), `StaffInvitation`, `StaffEmailChange`, `PhoneCode`, `CustomerPhoneCode`, `StaffPasswordReset`, `CustomerPasswordReset`, `TrustedBrowser`, `Address` (one customer, one store, never moved), `StoreAddressFormat` (a country's fields and how an address is printed). |
| `Domain/ValueObject` | `RoleName`, `StoreChoice` (all stores, or at least one chosen store), `RoleKind`, `RoleLevel`, `EmailAddress`, `PhoneNumber` (E.164, any country), `CountryCode` (the 249 ISO countries), `Language` (ar/en), `StaffProfile`, `PhoneCodePurpose`, `CustomerPhoneCodePurpose`, `AddressField`, `MapPin` (both coordinates or neither). |
| `Domain/Exception` | `AccessError` and its subclasses, with messages in `Presentation/lang/{ar,en}/errors.php`. |
| `Application/Permission` | `InMemoryPermissionCatalog` (declarations, renames, removals, checked at boot), `AccessPermissions` (Access's list, and which actions are admin-only). |
| `Application/Authorization` | `RoleAuthorizer` (the real `Authorizer`), `GrantRules` (who may grant what to whom, who may manage whom, console-only), `StaffGrants` + `GrantsReader` (a staff member's permissions, cached), `Author`. |
| `Application/Command` | Roles: `CreateRole`, `CloneRole`, `UpdateRole`, `DeleteRole`, `ChangeStaffRole`, `RefreshStaffPermissions`, `RefreshRolePermissions`. Staff (admin): `InviteStaff`, `ResendStaffInvitation`, `CancelStaffInvitation`, `CancelStaffAccount`, `DisableStaff`, `EnableStaff`, `UpdateStaffProfile`, `ChangeStaffEmail`. The invitee: `AcceptStaffInvitation`, `ConfirmStaffInvitation`, `ConfirmStaffEmailChange`. Signing in: `SignInStaff`, `VerifyStaffSignInCode`, `ResendStaffSignInCode`, `SendStaffSignInCodeToNewPhone`, `SignOutStaff`, `RequestStaffPasswordReset`, `ResetStaffPassword`. Own account: `UpdateOwnStaffProfile`, `ChangeOwnStaffPassword`, `RequestOwnPhoneChange`, `VerifyOwnPhoneChange`, `UpdateOwnNotificationPreferences`. Console: `CreateSuperAdmin`, `RevokeSuperAdmin`, `ResetSuperAdminPhone`, `ResendSuperAdminInvitation`, `CancelSuperAdminInvitation`; the scheduled `CancelExpiredSuperAdminInvitations`. Customers: `RegisterCustomer`, `VerifyCustomerEmail`, `RequestCustomerPhoneCode`, `VerifyCustomerPhone`, `UpdateCustomerProfile`, `SignInCustomer`, `SignOutCustomer`, `RequestCustomerPasswordReset`, `ResetCustomerPassword`, `ChangeOwnCustomerPassword`, `ResendCustomerEmailVerification`, `SaveAddress`, `DeleteAddress`, `SetDefaultAddress`, `RequestAccountDeletion`. Staff: `UpdateStoreAddressFormat`, `BlockCustomer`, `UnblockCustomer`, `DeleteCustomerOnRequest`, `CancelCustomerDeletion`; the scheduled `AnonymizeDueAccounts`. |
| `Application/Query` | `ListRoles`, `ViewRole`, `RoleEditorPermissions`, `MyPermissions`, `ListCustomers`, `ViewCustomer`, `ListStaff`, `ViewStaff`, and the `RoleReader`, `CustomerReader` and `StaffReader` they use. |
| `Application/Security` | `SecretTokens` (links), `Codes` (SMS codes), `PasswordPolicy`, `PhoneVerification` and `CustomerPhoneVerification` (sending and checking a code, with its limits), `SignInLimits` (wrong passwords per account and per address, counted on its own keys for staff and for customers, each side's numbers read through `LockoutLimits`), `AddressLimits` (registrations and reset requests per address). |
| `Application/Customer` | `CurrentCustomer` (whose account this request may change), `CustomerMapper`, the `CustomerLinks` port (the verification and password-reset links), the `GuestVisitors` port (the guest id this browser carries). |
| `Application/Address` | `AddressMapper` (a store's order, its layout, and whether the address still fits), `StartingAddressFormat` (the scheme every store starts with), `GiveEveryStoreAnAddressFormat` (run when the schema is migrated). |
| `Application/Session` | The `StaffSessions` port (the admin session: pending sign-in, signed in, kept, ended), the `CustomerSessions` port (the storefront session: started, kept, ended), `PendingSignIn`, `TrustedBrowsers`. |
| `Application/Settings` | `StaffSecuritySettings` (global) and `CustomerSecuritySettings` (**per store**: each store's terms version, password length, verification hours and SMS numbers). |
| `Application/Staff`, `Messages`, `Audit` | `StaffMapper`, `StaffLinks`, `Avatars`, `Invitations` (every invitation link), `StaffCancellation`; the `SmsGateway` port; `RoleAudit`, `StaffAudit` and `CustomerAudit` (every audit entry). `AccessApiImpl` sits beside them. |
| `Infrastructure/Eloquent` | Query-builder repositories, `CachedGrantsReader`, `DatabaseRoleReader`, `CachedStoreAddressFormatRepository` (each store's form, under its own version). |
| `Infrastructure/Http` | `RequestActor` (who this request acts as), `RequestActorContext` (the real `ActorContext`), `LaravelStaffSessions`, `LaravelCustomerSessions`, `CookieGuestVisitors`. |
| `Infrastructure/Listener` | `WriteStartingAddressFormat`: a store opened later gets the starting address form. |
| `Infrastructure/Queue` | `CancelExpiredSuperAdminInvitationsJob`, every ten minutes; `AnonymizeDueAccountsJob`, daily at 03:00 in Riyadh. |
| `Infrastructure/Messages` | `TemporarySecurityMessages`, `SecurityMail`, `LogSmsGateway`, `UrlStaffLinks`, `UrlCustomerLinks` (the signed verification link). |
| `Infrastructure/Security`, `Media` | `HmacCodes`, `LaravelPasswordPolicy`; `StaffAvatarUsage` (the avatar as Platform media). |
| `Infrastructure/Permission` | `PermissionSync`: carries renames and removals into the roles on every migrate. |
| `Infrastructure/Persistence` | Migrations: the schema and `staff_users`, the role tables, the staff account tables (invitations, phone codes, email changes, notification preferences), the sign-in tables (sign-in codes, password resets, trusted browsers), the customer tables (`customers`, `phone_codes`), the customer sign-in tables (`customers.session_version`, `customer_password_resets`), the admin panel's own `admin_sessions`, and the address tables (`addresses`, `store_address_formats`). |
| `Presentation/` | `routes.php` (the `/admin` form endpoints and the storefront ones under `{store}/{locale}/account/…`), controllers, form requests, the middleware (`IdentifyRequestActor`, `UseAdminSession`, `IdentifyStaff`, `RequireStaff`, `UseStorefrontSession`, `IdentifyCustomer`, `RequireCustomer`), `FormErrors`; the five Super Admin console commands, the security email view, translations. |

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

Who acts is Access's `RequestActorContext`: in a web request, the staff member signed in, else a
guest; outside one (the console, a queue worker), the system. `IdentifyRequestActor` runs on every
web request, so no route can act as the system, and `RoleAuthorizer` lets the system do anything.
Platform's wrapper still makes a queued job the system acting for whoever queued it. Customers act
from step 4; integrations hold nothing yet.

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
right, so an `INVITED` account never has a password (a CHECK guards that). A Super Admin, whom the
console named, is signed in at once; a staff member goes to the sign-in page and signs in as always,
password and SMS code (amendment 36).

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

### Customer accounts: register, verify the email, then the phone

A visitor registers in the store they are browsing: email, password, name, individual or company,
the page's language and acceptance of that store's terms. The account is **active at once** with the
email unverified and no phone, so they can browse and fill a cart; only ordering waits for both
verifications (`AccessApi::customerMayOrder`, which Sales combines with B2B's company status). The
store they registered in becomes their **home store**, fixed — it decides which staff see them — and
the **terms version** recorded is that store's setting (amendment 37).

An email belongs to a customer account **or** a staff account, never both (amendment 13), so
registration refuses both, each with its own message. The email itself never changes.

**The verification link proves itself** (amendment 38): a signed storefront URL, good for 24 hours,
that verifies the address for whoever opens it — signed in or not — and needs no table. It is built
on `APP_URL`, never on the request's host, and opening it twice changes nothing.

**The phone** goes through an SMS code, with that store's numbers: a number another customer uses is
refused when it is entered, not after the code; the account keeps its current number until the new
one is verified; the number is checked again when the code comes back, in case someone took it
meanwhile. A verified phone is never removed.

A customer's own account events are audited — registered, email verified, phone verified, profile
edited — with names, email and phone recorded only as "changed", no IP address, and never their
sign-ins or browsing (amendment 37). Signing in, sessions, guests and password reset come with 4b.

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

`CreateSuperAdmin`, `RevokeSuperAdmin`, `ResetSuperAdminPhone`, `ResendSuperAdminInvitation`,
`CancelSuperAdminInvitation` and the sweep of expired invitations refuse every actor except the
console's own system actor — a Super Admin in the panel, or a job queued on their behalf, is
refused — so a hijacked admin session can never make or remove one. Revoking never removes the
last **active** Super Admin (an invited one who never accepted does not count), and disables the
account until an admin enables it together with a role; an invited one is cancelled instead.

A Super Admin invitation works 24 hours. A queued job, scheduled every ten minutes on one server,
cancels and frees each invited Super Admin whose last link is older than that (or who has no link
left). It checks each one again under its lock, so a link resent in the meantime is kept. The
scheduler and a queue worker must run (`schedule:work`, `queue:work`).

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
link included, to `storage/logs/laravel.log`. In production the application refuses to start while
`MAIL_MAILER` is `log`, `array` or unset (amendment 36), as the `log` SMS driver refuses to send
there: links must never sit in a log file. The endpoints the invitation form posts to exist
(`POST /admin/invitation/{token}`, then `/code`), but the link itself opens no page yet: the form
comes with the screens, in the frontend foundation stage (amendment 12).

### Signing in and sessions

```
password ─┬─ trusted browser ──────────────────────────────▶ signed in
          ├─ SMS code (within 15 minutes) ── right code ───▶ signed in (+ trust this browser, 30 days)
          └─ a Super Admin with no phone: new number ── its code verifies it ──▶ signed in
```

- **The admin panel has its own session**, `touchwood_admin_session`, sent only to `/admin`
  (`UseAdminSession`, before the `web` group starts the session): its limits and sign-out never
  touch a storefront session in the same browser. It lives in its own table, `access.admin_sessions`
  (amendment 40) — Laravel deletes old session rows with the lifetime of whichever request happens
  to do it, so one table would let an admin request end a customer's remembered session. Signing in
  gives a new session id.
- **The session ends** after 30 minutes idle, 12 hours after signing in however busy, for good when
  the account is disabled (enabling it again brings no session back), and when the password changes
  (`staff_users.session_version` is raised; the
  cached permissions carry it, so a warm request reads only `access.admin_sessions` and the `cache` table — a
  test checks it). Changing one's
  own password keeps the session it was changed from.
- **Wrong passwords** (`SignInLimits`): 5 for one account lock it for 15 minutes; 10 from one
  address, across accounts, make it wait 15 minutes. Each attempt is counted *before* its password
  is checked, so attempts sent at the same moment cannot all slip through; the right password
  clears the account's count and gives the address that one attempt back. An unknown email is
  counted too and answered the same, so the answer tells a stranger nothing. A wrong current
  password when changing one's own counts the same way. Keys hold a hash of the email or address.
- **The code step** lasts 15 minutes after the password and ends if the password changes meanwhile.
  The sign-in SMS has its own text, which warns that the password was just used, so someone who did
  not try to sign in learns that another person has it (`SecurityMessages::staffSignInCode`,
  amendment 34). A code counts only for the number the account has now; changing the number drops a
  code already sent. Only a Super Admin whose phone was reset chooses a number here; anyone else
  with no phone is refused until an admin gives them one.
- **A trusted browser** holds a random token in `touchwood_admin_trust` (only its hash is stored),
  for one staff member, 30 days. Signing out keeps it. It is forgotten when the account is disabled
  or revoked, the password is changed or reset, or the phone changes (by the person, an admin, or a
  Super Admin phone reset).
- **Password reset** by an email link valid 30 minutes, at most 3 emails an hour per account; the
  page says the same whether or not the email has an account, and the email goes out after the
  answer, so its timing tells nothing either. Using the link ends every session and trusted
  browser; disabling or revoking the account, or changing its email, kills the link. Every staff
  link is built on `APP_URL`, never on the host a request names.
- **An email link opened while signed in** (an invitation, an email change) signs that admin session
  out first, then continues (amendment 31).
- **Audited:** each sign-in, sign-out, lockout and browser trusted, and each address made to wait.
  Wrong passwords are counted, not logged one by one. An address made to wait belongs to no staff
  member, and Platform keeps IP addresses only for staff actions, so its entry names it by a keyed
  fingerprint (`Codes::hash`): repeats from one address show, but the address cannot be read back.
- **A failed database query is logged without its values** (amendment 33): the connection masks
  its bindings, and `App\Exceptions\QueryErrorLog` removes what PostgreSQL repeats in its own words:
  the `DETAIL` and `CONTEXT` lines, and a value it could not read at the end of the error line.

Every number here is a setting (`StaffSecuritySettings`), except the 15 minutes to enter the code.

### The storefront: a customer signing in

```
register ──▶ signed in at once (verification email on its way)
sign in ───▶ email + password ──▶ signed in, in the store they signed in from
```

- **Its own session**, in the site's own cookie (`UseStorefrontSession`, before the `web` group):
  the admin panel's session is never touched, and a staff member can be a customer in the same
  browser. Signing in gives a new session id. The cookie and the `sessions` row are given the
  longest "remember me" any store may set (a year, `ACCESS_STOREFRONT_SESSION_DAYS`) — only an
  outer bound, so the framework never ends a session before Access does.
- **The session ends** after the store's idle minutes (2 hours by default), or, with "remember me",
  after the store's remembered days (30) however quiet the customer is; at once when the account is
  blocked; and when the password changes or is reset (`customers.session_version`). Of the customer
  tables, a signed-in request reads one row, the customer's, by its primary key; it also reads the
  session row and the store's settings from the `cache` table, like every storefront request.
- **Registering signs the customer in at once** (amendment 39), in the store they registered in.
  Signing in moves `last_store_id` to the store they signed in from. Access records it; sending
  someone to that store when they arrive without one belongs to the storefront's own pages, in the
  frontend foundation stage — nothing in Access redirects across stores today.
- **Wrong passwords** use the same `SignInLimits` as staff, on their own keys (`access:customer-…`):
  5 for one account lock it for 15 minutes, 10 from one address make it wait 15 — every number a
  per-store setting. A shop's busy address never makes the admin panel wait, or the other way
  round. A wrong email and a wrong password are answered the same; only the right password learns
  that an account is blocked. A wrong current password, when changing one's own, counts the same.
- **Password reset** by an email link valid 60 minutes (a per-store setting), at most 3 an hour per
  account; the page answers the same whether or not the email has an account, and the mail goes out
  after the answer. The link works once and ends every session of that account. A customer who is
  signed in and uses the forgot-password form or the link is signed out of that browser first
  (amendment 40, the rule staff links follow); someone who remembers their password changes it in
  their account settings, which keeps this session and ends the others.
- **The verification link** can be resent by the customer signed in, at most 3 an hour — the same
  per-store number as reset emails, by the owner's decision — and does nothing once verified.
- **Registering and asking for a reset** are limited to 10 an hour from one address
  (`TooManyRequests`, a per-store setting): one machine cannot make thousands of accounts or send
  thousands of emails. Signing in is not counted there; it has its own limits.
- **A guest who signs in or registers** is announced to Sales as `GuestBecameCustomer` (`REGISTERED`
  or `SIGNED_IN`), with the guest id from the storefront's encrypted cookie; Sales moves or merges
  the cart. Access only reads that cookie.

### Addresses: the country first, then that country's form

- **An address belongs to one store** — the country it is in — and never moves: an address in
  another country is a new address there. A customer keeps one address book per store, at most ten
  (a per-store setting), each with a label, a recipient, a phone and an optional map pin.
- **Each store owns its form** (`access.store_address_formats`): the fields it asks for, their
  names in both languages, how long each may be, their order, and a display template. Changing one
  country's form leaves the others exactly as they were.
- Every store **starts with the standard scheme**, written when the schema is migrated
  (`GiveEveryStoreAnAddressFormat`) and when a store is opened later (`WriteStartingAddressFormat`,
  on Platform's `StoreCreated`), so addresses work before the staff screen exists.
- **The form decides what a value may be**: a required field must be there, each has its own
  maximum length, and a key the form does not define is refused — a mistyped key would otherwise
  store what no screen and no shipping label can show. Every value is **text on one line**: a
  newline inside one would add a line to a shipping label (amendment 42). A form holds at most 60
  fields, an address 4,000 characters in all.
- **The template is text**: `{field}` is replaced by its value, a placeholder with nothing in it
  disappears, and a line left with nothing on it is dropped. Nothing in it is executed.
- **A form changed later leaves saved addresses alone**, but one that no longer satisfies it — a
  field asked for, shortened or dropped — comes back with `isComplete` false, and Sales may not ship
  to it until the customer completes it.
- **One customer's addresses change one at a time**: each of the three handlers holds that
  customer's own row for its transaction, so two tabs cannot both take a store's default or both
  count the last address allowed.
- **One default per store**, enforced by a partial unique index and by the handlers, which clear
  the others first. The first address in a store becomes its default; deleting the default moves the
  flag to the newest address left.
- **Every field is personal data**: the audit log records that an address was added, changed or
  deleted, and never a street or a recipient's name.

### Deleting an account: locked now, anonymized in fourteen days

- **The customer asks with their password** (guessing it counts towards the same lockout as signing
  in). The account cannot order from that moment, **every session of theirs ends at once** — they are
  signed out of every device (amendment 45) — and **one email** tells them the date and that signing
  in cancels it — so a deletion nobody asked for is undone by the owner simply coming back.
- **Signing in cancels it** — which is also the only way back in. Support can cancel it too, for a
  customer who cannot sign in at all (amendment 43).
- **Staff may delete on a customer's request** and **block or unblock** a customer. Both are
  **admin-only** actions in the customer's home store, and both record a **reason**, which is kept
  only in the audit entry — never on the account.
- **The sweep runs daily at 03:00 in Riyadh** (`AnonymizeDueAccountsJob`, queued like all scheduled
  work) and reads each account again under its lock, so one that was stopped in the meantime is left
  alone. Each account is anonymized in its own transaction.
- **What goes:** the names become "Deleted customer", the email becomes
  `deleted-{id}@deleted.invalid` — which keeps nothing of the old address and frees it for a new
  account — the phone, every address, and any live code or reset link. The password becomes a value
  no password can match, and the session version moves on, so nothing signed in survives.
- **What stays:** the id, the account type, the home store and the dates, so counts by store stay
  honest and the keys orders and reviews hold still resolve. The audit entry says which fields
  changed and nothing of what they held.

### Who sees whom

- **Customers:** a staff member sees those whose **home store** is one of theirs, with their
  contacts and their address book; a Super Admin sees everyone. A customer of another store is
  answered as no customer at all.
- **Staff:** a colleague is visible only when the reader holds "see staff" in **all** of that
  person's stores (amendment 9). An **admin** shows a name, a role and a status — no job title,
  email or phone. A **Super Admin** is invisible to everyone but another Super Admin: not in a list,
  not in a count, and asked for by id the answer is the same as for an id that never existed
  (amendment 43).

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
| 3b | The staff lifecycle the owner decided (amendments 29, 30): `CANCELLED` frees an invited person's email and phone; only the inviter or a Super Admin cancels; Super Admin invitations work 24 hours and are swept by a scheduled job; two new console commands. Then signing in: the real `ActorContext`, the admin session cookie, password → SMS code or trusted browser, lockouts per account and per address, idle and 12-hour limits, session versions, password reset and change, sign-out, email links that sign the session out first, sign-in audits, failed queries logged without values. Platform's interim `SystemActorContext` removed. A mutation run (58 deliberate mistakes; 56 caught, the other 2 refused by the domain with the same error) proved the tests |
| 4a | Customer accounts: registration in a store (individual or company, terms version per store), the email verification link as a signed storefront URL, the phone added and changed by SMS code with that store's numbers, the customer's own profile, `CustomerRegistered` / `CustomerEmailVerified` / `CustomerPhoneVerified`, the customer reads of `AccessApi`, and the authorizer's customer path, which step 3 had left closed. Customer sign-in, sessions, guests and password reset come in 4b |
| 4b | Customer sign-in and the storefront session: the session in the site's own cookie (`UseStorefrontSession`, `IdentifyCustomer`, `RequireCustomer`), registering that signs the customer in at once, signing in and out, the store they last used, "remember me" and the idle limit, `customers.session_version`, the lockout shared with staff's code but counted on its own keys, the password reset link and the customer's own password change, resending the verification link, and `GuestBecameCustomer` for the cart Sales will move or merge. A mutation run (20 deliberate mistakes; 16 caught at once, 4 more after four tests were added) proved the tests |
| 5 | Addresses: a customer's address book in each store, with that store's own form as data — the fields, their lengths and the layout — written for every store when the schema is migrated and when a store is opened; the first address in a store is its default and deleting the default moves the flag; at most ten per store; an address the store's form has outgrown comes back as not complete, so Sales cannot ship to it; `AccessApi::address()` and `addresses()` for checkout. No HTTP endpoints: the address book and checkout call the handlers from the frontend stage and Sales. A mutation run (20 deliberate mistakes, all caught — one of them only after the migration's own work moved into a service a test can call) proved the tests |
| 6 | Deletion, blocking and the staff views: "delete my account" with the password, the account locked at once and anonymized fourteen days later by a daily sweep, one email saying the date and that signing in cancels it; signing in, the account page and support all cancel it; blocking, unblocking and deleting on a customer's request are admin-only actions in the customer's home store, each with a reason kept only in the audit log; the five events Ops and Sales listen for; `ListCustomers`, `ViewCustomer`, `ListStaff` and `ViewStaff`, where an admin shows a name and a role only and a Super Admin is invisible to everyone but another Super Admin (spec §9.3 #36, answered). No HTTP endpoints: the account page and the staff screens call the handlers from the frontend stage. A mutation run (25 deliberate mistakes; 21 caught at once, 4 more after four tests were strengthened, 1 equivalent — the sweep's own re-check makes a wider query harmless) proved the tests |
