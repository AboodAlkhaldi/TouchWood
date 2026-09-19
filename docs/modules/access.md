# Access — Module Specification

**Status:** **APPROVED** by the owner, 2026-09-19 (PR #24). Changes from here on are amendments and
need the owner's agreement (§9.4).
**Tier:** 2 (identity). **Depends on:** Platform. **Needs from shared plumbing:** nothing — it
publishes events but consumes none, so `processed_events` and `outbox_messages` (handoff §4.5)
are still not needed. **Build stage:** 2.
**Source:** `docs/HANDOFF.md` §4.1, §5.2, §5.3, §7, §14, §15, §16, §17; `docs/modules/platform.md`
(the shape this spec follows); the owner's answers to the Access questions, 2026-09-18 and
2026-09-19 (§9.1).

Access owns **who someone is and what they may do**: customer accounts and their verification,
staff accounts, roles and permissions, sign-in and sessions, addresses, and account deletion. It
replaces Platform's interim `ActorContext` and `Authorizer` with the real ones.

Items marked **[DECIDED date]** are the owner's answers (§9). Everything else follows directly
from the handoff or from Platform's approved rules.

**Delivery [DECIDED 2026-09-18]:** backend only — domain, tables, use cases, HTTP endpoints and
tests, like Platform's Stage 1. The screens (registration, sign-in, verification, the admin
sign-in and 2FA, staff and role management) are built in the **frontend foundation stage** that
follows Access, together with Platform's admin screens.

---

## What this module does not own

| Concern | Owner |
|---|---|
| Company data, documents, company status (`PENDING`…`SUSPENDED`), the company application | B2B (stage 3) |
| Carts, including merging a guest's cart into an account | Sales (stage 6) |
| Wishlists | Feedback (handoff §13.1) |
| Order, marketing and operational notifications; newsletters | Ops (stage 8) |
| The email and SMS **providers** long term | Ops — Access sends its own security messages until then (§2.3) |
| Stores, settings storage, media, the audit log, store context | Platform |
| The final "may place an order" decision (needs the company status) | Sales, combining Access and B2B (§2.1) |

---

## 1 · Aggregates and invariants

### 1.1 Customer

A person or company that buys. **One account for every store** (handoff §4.1: customer identity
is global).

| Attribute | Invariant |
|---|---|
| `id` | ULID. Also the actor id in the audit log. |
| `email` | Required, a valid address, unique regardless of letter case. **Never changes** **[DECIDED 2026-09-18]**: a customer who needs another email registers again. |
| `password` | Stored only as a hash. Rules in §1.8. |
| `first_name`, `last_name` | Required at registration **[DECIDED 2026-09-18]**, editable. Personal data: audited only as "changed". |
| `account_type` | `INDIVIDUAL` or `COMPANY`, chosen at registration, **immutable** (handoff §7.2, §16). |
| `status` | `ACTIVE` or `BLOCKED` (handoff §7.4). Controls **sign-in** only. |
| `email_verified_at` | Set when the verification link is used. |
| `phone` | E.164 (`+9665…`), **any country, unique across customers** **[DECIDED 2026-09-18]**. Null until the first phone is verified; **never null again** once set (handoff §7.3). |
| `phone_verified_at` | Set with `phone`. |
| `locale` | `ar` or `en`: the page's language at registration, editable. Emails and SMS use it (handoff §5.2). |
| `home_store_id` | **The store the account was registered in, fixed [DECIDED 2026-09-19].** It decides which store's staff see the customer (§3.3). The customer can shop in every store. |
| `last_store_id` | The last store the customer used. After signing in — on any device — they land there **[DECIDED 2026-09-19]**; emails sent later (not during a request) link to it. |
| `terms_version`, `terms_accepted_at` | The terms and privacy policy accepted at registration, and when **[DECIDED 2026-09-19]**. |
| `deletion_scheduled_for` | Set while a deletion is pending (§1.10). |
| `anonymized_at` | Set once the account is anonymized. |

**Ordering eligibility.** Handoff §7.4 defines `canPlaceOrder`. Its company part needs the
company's status, which B2B owns, and B2B depends on Access — so Access cannot evaluate the whole
rule. Access exposes the person's part (`ACTIVE`, email verified, phone verified, no deletion
pending) and the account type; **Sales**, which depends on both, combines it with B2B's
`company.status == APPROVED`, with no branching beyond the account type (handoff §7.4).

**A company account [DECIDED 2026-09-19].** At registration the customer chooses individual or
company, and the flow differs: a company enters its company data and documents (handoff §8.1).
Access registers the account (the responsible person's name, email, password); **B2B** owns the
company application that follows. The frontend presents both as one wizard. Until B2B exists, a
company account can register and sign in but has no company, so it cannot order.

### 1.2 Registration and email verification

Handoff §7.2: register (email + password) → email verification link → add phone → SMS code →
ordering unlocked.

- Registering needs: email, password, first and last name, account type, and acceptance of the
  terms and privacy policy **[DECIDED 2026-09-19]**; the accepted version is recorded (the current
  version is a setting staff change when the terms change). No address is asked (§1.9). The account
  starts `ACTIVE`, unverified.
- The verification link is signed, single-purpose, and expires after **24 hours**
  **[DECIDED 2026-09-18]**. It can be resent; resending is rate-limited.
- An unverified customer can sign in, browse and build a cart; only ordering needs both
  verifications.
- **An email already registered** is told plainly: "You already have an account — please sign in"
  **[DECIDED 2026-09-19]**.

### 1.3 Phone

Handoff §7.3. One phone per account; adding and changing both go through an SMS code.

- **First phone:** the customer enters a number → an SMS code → on the right code, `phone` and
  `phone_verified_at` are set.
- **Change:** a pending change (handoff §7.3's `pending_phone_changes`, here a `phone_codes` row
  with purpose `CHANGE`, §5.2) holds the new number and the code's hash. The old number stays live
  until the new one verifies; then they swap and the pending row is deleted. There is no way to
  remove a phone.
- A number already used by another customer is refused when it is **entered**, not after the code.
- **SMS codes [DECIDED 2026-09-18]** (provisional until the SMS provider is chosen, handoff §15.3):
  6 digits, valid **5 minutes**, resend after **60 seconds**, at most **5 per hour** per number,
  **5 wrong tries** and the code is dead — a new one must be requested. Codes are stored only as a
  hash.

### 1.4 Staff user

Handoff §7.6.

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `email` | Required, unique regardless of case. The invitation goes there. |
| `password` | Set by the staff member when accepting the invitation; the admin never knows it. Rules in §1.8. |
| `first_name`, `last_name`, `job_title`, `date_of_birth`, `country`, `address` | The profile (handoff §7.6). Personal data. `country` is ISO 3166-1 alpha-2. |
| `phone` | E.164, **verified by SMS before first sign-in**: it receives the 2FA codes **[DECIDED 2026-09-18]**. |
| `avatar_media_id` | Optional **public** Platform media **[DECIDED 2026-09-19]** — a column with a `RESTRICT` foreign key, registered as a detachable `MediaUsage` (Platform rule, 2026-09-18). |
| `locale` | The admin panel's language for this person. |
| `status` | `INVITED`, `ACTIVE` or `DISABLED` (§4.3). **Never deleted** **[DECIDED 2026-09-18]**: the audit log names them forever. |
| `is_super_admin` | Only set by the console command (§1.6). |

**Phone.** It receives the 2FA codes, so it is verified by an SMS code when the invitation is
accepted. A staff member changing their own phone confirms the new number with a code before it
takes effect. An admin with `access.staff.update` may change it (a lost phone); the staff member
then verifies the new number at their next sign-in. A Super Admin's phone is changed only by the
Super Admin themselves (confirmed by a code) or by console command (§1.6).

**Password reset** works as for customers: an email link valid 60 minutes. It never skips the SMS
code at sign-in.

**Notification preferences** (handoff §7.6): for each topic — new orders, company applications,
low stock, campaign expiry — an email toggle and an in-panel toggle. Access stores them; Ops reads
them later through `AccessApi`.

### 1.5 Roles, permissions and assignments

Handoff §7.5, with the owner's answers **[DECIDED 2026-09-18, 2026-09-19]**:

- **Our own tables**, not `spatie/laravel-permission`: the store scope lives on each role
  assignment, which that package cannot express (handoff §3 amended).
- **One role per staff member.** The role has a name in Arabic and English and a set of permissions.
- **Saved roles** are shared: many staff can hold the same one, and **editing a saved role changes
  it for everyone who holds it**. Admins can create new saved roles and clone one into a new
  saved role (a copy at creation time, never live inheritance).
- **Editing a staff member's role from that staff member's page** gives them a **personal role**:
  a copy of the saved role with the change, belonging to that one person. Nobody else changes. A
  personal role never appears in the list of saved roles; editing it again changes only that person.
- **Stores are chosen per staff member, with exceptions [DECIDED 2026-09-19].** The role holds only
  the actions, so a saved role such as "Order fulfilment" works in any store. For each staff
  member the admin ticks the actions' stores once — KSA, UAE, Egypt, or all stores, now and
  added later — and that choice applies to **every action** in the role. Any single action can
  then be given its own stores for that person: *view orders in KSA and UAE, refund only in KSA*.
  An action added to a saved role later reaches each holder in their chosen stores. The screen:
  tick the actions, a row of store boxes that fills every action, and store boxes per action for
  the exceptions.
- A staff member's **stores**, for who may see and manage them (§3.3), are every store any of
  their actions covers.
- **Nobody grants more than they hold** **[DECIDED 2026-09-18]**: a role can contain only
  permissions its author holds, and an assignment can cover only stores its author covers, for
  those permissions. Only a Super Admin is unlimited. Checked in code on every change.
- **Editing a saved role** reaches every store where it is held, so only a **Super Admin** or an
  admin who **covers every store of every holder** (and holds every permission in it) may edit it
  **[DECIDED 2026-09-19]**. A KSA + Egypt admin can edit a role held only in KSA and Egypt, never
  one held in UAE; a single-store admin has no authority over another store.
- **Reserved permissions** (e.g. `platform.store.create`) exist so handlers can assert them but
  are never offered in the role editor and can never be put in a role: only a Super Admin holds them.
- **Deleting a saved role** that anyone holds needs a **replacement** **[DECIDED 2026-09-19]**: the
  admin picks another saved role and every holder moves to it (the no-escalation rule applies to
  the move). Without a replacement the delete is refused, listing the holders.

**The permission catalog.** Every permission is declared in code by the module that checks it,
with its labels in Arabic and English and whether it is reserved. Platform publishes its list in
its public contract (a small Platform addition, §2.5); modules above Access declare theirs through
`PermissionCatalog` (§2.2). A role can only hold declared permissions. Permission names follow
`{module}.{resource}.{action}`.

**Automatic permissions.** Every command handler asserts a permission (handoff §19), including
actions no role grants: signing in, registering, a customer editing their own profile, a staff
member changing their own password. Each permission has an **audience**:

| Audience | Held by | Examples |
|---|---|---|
| `ROLE` | Staff, only through their role | `access.staff.invite`, `platform.store.update` |
| `EVERY_STAFF` | Every active staff member, automatically | `access.own_account.update` |
| `EVERY_CUSTOMER` | Every active customer, automatically | `access.account.update`, `access.address.manage` |
| `EVERY_GUEST` | Every visitor not signed in, automatically | `access.account.register`, `access.session.sign_in` |

Only `ROLE` permissions appear in the role editor. An automatic permission lets a person act on
**their own** data only: the handler takes their id from the session, never from the request
**[DECIDED 2026-09-19]**.

### 1.6 Super Admin

Handoff §7.5: bypasses every check, non-deletable, non-editable, sees every store, the only one who
creates admins and sets their store scope.

**[DECIDED 2026-09-18, flow 2026-09-19]:**

- **Created only by a console command on the server:** `php artisan access:super-admin:create
  {email} {first_name} {last_name}` creates the account and emails an invitation (72 hours). They
  open the link, set a password and verify their phone by SMS code, then sign in at `/admin` like
  any staff member: password, then an SMS code, with a trusted browser for 30 days.
- **Removed only by a console command:** `php artisan access:super-admin:revoke {email}` takes the
  power away; the person keeps an account with no role until an admin gives them one. **The last
  Super Admin cannot be revoked**, so the business is never locked out.
- Never from the admin panel: nobody — not even another Super Admin — can create, edit, disable or
  remove a Super Admin there, so a hijacked admin session cannot mint or remove one. A Super Admin
  edits only their own profile, password and phone. More than one may exist.
- A Super Admin holds every permission in every store, including reserved ones. They have no role.

### 1.7 Guest

A visitor with no account (Platform actor type `GUEST`) **[DECIDED 2026-09-18]**: browses and keeps
a cart, no favourites.

- A guest's id is a ULID, created the first time something needs it (the first cart line), not on
  every page view, so bots create nothing.
- It lives in an **encrypted, HTTP-only cookie**. **An id is never a secret**
  **[DECIDED 2026-09-18]**: the audit log may keep it forever; proof that a cart belongs to someone
  is the encrypted cookie, which cannot be forged or read without the server's key.
- No guest table: the id exists only in the cookie and in the records that use it (carts).
- When a guest **registers**, their cart moves to the new account; when a guest **signs in**, their
  cart **merges** into the account's cart **[DECIDED 2026-09-19]**. Access publishes
  `GuestBecameCustomer` (§6); Sales does the moving and merging in stage 6.

### 1.8 Sign-in, sessions and security

Handoff §7.7: session authentication (no JWT, handoff §16). All numbers below are **settings**, so
they change without a deploy; customer settings are per store (handoff §7.7), staff settings global.

- **Customers sign in with email and password only** **[DECIDED 2026-09-18]**. Password reset is
  by an email link valid **60 minutes**.
- **Passwords [DECIDED 2026-09-18]:** customers at least **8** characters, staff at least **12**; no
  composition rules; every new password is checked against known leaked passwords (Laravel's
  `uncompromised()` rule: only the first 5 characters of the password's SHA-1 hash leave the
  server).
- **Lockout [DECIDED 2026-09-18]:** 5 wrong passwords for one account → that account is locked for
  **15 minutes**; a separate limit per IP address stops one machine trying many accounts. A wrong
  email or password is answered "wrong email or password", without saying which.
- **Customer sessions:** "remember me" keeps a customer signed in **30 days**; without it, **2 hours**
  idle ends the session.
- **Staff sessions:** **30 minutes** idle ends the session; **12 hours** at most after sign-in.
- **Staff two-factor [DECIDED 2026-09-18]: an SMS code**, asked after the password. The browser can
  be marked **trusted for 30 days**, after which no code is asked on it until the trust expires.
  Trust is a random token in a cookie, stored only as a hash, tied to one staff member; disabling
  the staff member or changing their password or phone ends every trust.
- **The admin area uses its own session cookie**, separate from the storefront's, so its idle
  limit, 2FA and sign-out never touch a customer session in the same browser — and a staff member
  can also be a customer.
- Signing in regenerates the session id; changing or resetting a password ends every other session
  of that account.
- A `BLOCKED` customer or a `DISABLED` staff member cannot sign in, and their open sessions end at
  once. A blocked customer is told so, after the right password: "Your account is blocked — please
  contact us" **[DECIDED 2026-09-19]**.

### 1.9 Addresses

Handoff §7.8: each store owns its address shape, so a change to one country's format cannot affect
another. **[DECIDED 2026-09-19]:**

- **The customer first picks the country**, from our stores' countries only (KSA, UAE, Egypt). The
  address belongs to that country's store, and **that store's scheme** appears.
- **One scheme for all three today**, but each store keeps its own copy, so one country's scheme
  can change later — as data, with no deploy — without touching the others.
- The scheme's fields: `country_code` (from the chosen store), `administrative_area` (region,
  governorate or emirate), `city`, `district`, `street`, `building`, `unit`, `floor`,
  `postal_code`, `additional_number`, `po_box`, `short_address`, `landmark`,
  `additional_information`, and a map pin (`latitude`, `longitude`).
- **Required:** country, administrative area, city, district, street, building. The rest are
  optional; the map pin is both coordinates or neither, within valid ranges. For now only lengths
  are checked; country-specific rules come later, per country.
- Every address also has a `label` ("Home"), the `recipient_name`, the recipient's `phone` (E.164,
  any country, not verified) and `is_default`. A customer keeps **as many addresses as they need
  in every store**, with **one default** per store.
- **Not asked at registration**, but **required before an order, in the store being ordered from**
  **[DECIDED 2026-09-19]**: an order is shipped within that store's country, so checkout asks for
  an address there when there is none (Sales, stage 6, through `AccessApi::addresses()`). A customer
  whose home store is KSA and who has only a KSA address adds a UAE address before their first UAE
  order; it stays on their account for next time. The home store never limits where they shop or
  keep addresses.
- **Store address formats** are data: the fields (key, labels in both languages, required,
  validation, order) and a display template for orders and shipping labels. A store with no format
  (a new country, before its scheme is entered) refuses addresses with a clear error.
- Every address field is personal data: audited only as "changed". The owner's rule: `city` and
  `postal_code` are not refused by name in the audit log, so Access marks them personal itself.

### 1.10 Account deletion

Handoff §7.9: anonymize, never hard-delete. **[DECIDED 2026-09-19]:**

- The customer asks from their account page, confirming with their **password**. Staff with the
  permission can do it on the customer's request.
- The account is **locked at once** (it cannot order) and **anonymized 14 days later**.
- **Signing in during those 14 days cancels the deletion** (after a password reset if needed), so a
  hijacked or regretted request can be undone.
- Anonymizing (a scheduled job, queued like all scheduled work): name → "Deleted customer", email →
  an irreversible placeholder that keeps uniqueness, phone → null, addresses purged, preferences
  cleared; the account can never sign in again. The email becomes free: the same person may
  register again as a new account.
- Orders keep their snapshot; reviews and questions survive as "Deleted customer" (handoff §7.9) —
  Access publishes `CustomerAnonymized` and those modules keep their own copies.

---

## 2 · Public contract

### 2.1 `Modules\Access\Public\Contracts\AccessApi`

```php
interface AccessApi
{
    public function customer(string $customerId): ?CustomerDto;

    /** The person's part of handoff §7.4: active, email and phone verified, no deletion pending. */
    public function customerMayOrder(string $customerId): bool;

    public function staff(string $staffId): ?StaffDto;

    /** @return list<StaffNotificationPreferenceDto> for Ops, when it sends staff notifications */
    public function staffNotificationPreferences(string $staffId): array;

    public function address(string $addressId): ?AddressDto;

    /** @return list<AddressDto> the customer's addresses in one store, default first — checkout needs one */
    public function addresses(string $customerId, string $storeId): array;
}
```

### 2.2 `PermissionCatalog`

Used once, at boot, by each module above Access (Platform's list reaches Access through Platform's
own contract, §2.5).

```php
interface PermissionCatalog
{
    public function declare(string $module, PermissionDefinitionDto ...$permissions): void;
}
```

A permission outside the module's prefix, declared twice, or malformed is refused at boot.

### 2.3 `SecurityMessages` — sent by Access now, by Ops later **[DECIDED 2026-09-18]**

```php
interface SecurityMessages
{
    public function emailVerification(CustomerDto $customer, string $link): void;
    public function passwordReset(string $email, string $locale, string $link): void;
    public function phoneCode(string $phone, string $locale, string $code): void;
    public function staffInvitation(StaffDto $staff, string $link): void;
    public function staffSignInCode(StaffDto $staff, string $code): void;
    public function deletionScheduled(CustomerDto $customer, DateTimeImmutable $on): void;
}
```

Access binds a **temporary implementation**: Laravel mail with simple bilingual templates, and SMS
through an `SmsGateway` interface. The email provider is not chosen either (handoff §15.1): until
it is, mail goes to Laravel's `log` mailer. The **SMS provider is not chosen yet** **[DECIDED 2026-09-18]**:
a `log` driver writes codes to the application log for development and tests; the provider's
adapter is added when the owner names it — configuration, not a code change. When **Ops** is built
it binds its own `SecurityMessages` and the temporary one is deleted: one binding changes, nothing
else in Access.

Codes and links travel only through this interface — never inside events or queued job payloads,
which are stored in the database.

### 2.4 DTOs (`Public/Dto`) — plain `final readonly` classes

| DTO | Fields |
|---|---|
| `CustomerDto` | `id`, `accountType`, `status`, `firstName`, `lastName`, `email`, `phone`, `emailVerified`, `phoneVerified`, `locale`, `deletionScheduledFor` |
| `StaffDto` | `id`, `firstName`, `lastName`, `email`, `phone`, `locale`, `status`, `isSuperAdmin` |
| `AddressDto` | `id`, `customerId`, `storeId`, `label`, `recipientName`, `phone`, `fields`, `isDefault`, `formatted` (the store's display template applied) |
| `PermissionDefinitionDto` | `name`, `audience` (`ROLE`, `EVERY_STAFF`, `EVERY_CUSTOMER`, `EVERY_GUEST`), `reserved`; its names in Arabic and English are the module's translations at `labelKey()` — `{module}::permissions.{resource}.{action}` (amendment 1, §9.4) |
| `StaffNotificationPreferenceDto` | `topic`, `email`, `panel` |

Enums: `AccountType`, `CustomerStatus`, `StaffStatus`, `AccessLevel`, `PermissionAudience`,
`StaffNotificationTopic` — stored as strings.

### 2.5 What Access implements for others, and what it needs

- **Implements `Shared\Application\ActorContext`** — the signed-in customer or staff member, the
  guest, or the system — and **`Shared\Application\Authorizer`** with `PermissionScope` and
  `storesWith()`. Both bound with `scoped()`, replacing Platform's interim bindings; Platform's
  wrapper keeps making queued jobs act as the system for their requester.
- **Needs from Platform:** `PlatformApi` (stores, settings, `recordAudit`, media), `StoreContext`,
  `SettingsRegistry` (its settings, §1.8), `MediaUsages` (staff avatars), `ReservedPaths` (Platform
  already reserves `admin` and `api`).
- **Platform additions in this stage:** Platform publishes its permission list in its public
  contract (`PlatformPermissions`: names and reserved flags, with the names in Arabic and English in
  `platform::permissions` — amendment 1), and its interim `SystemActorContext` and
  `SystemOnlyAuthorizer` are removed.

---

## 3 · Use cases

Permissions follow `{module}.{resource}.{action}`. **Audience** (§1.5): `role` through a staff
role; `every staff`, `every customer` and `every guest` automatically, for their own data only;
`system` for console commands and jobs. **Reserved** means Super Admin only.

### 3.1 Customers and guests

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `RegisterCustomer` — email, password, names, account type, locale | every guest | `access.account.register` | Global |
| `SendEmailVerification` / `VerifyEmail` | every customer | `access.account.verify` | Global |
| `RequestPhoneCode` — first phone or a change | every customer | `access.account.verify` | Global |
| `VerifyPhone` — completes adding or changing | every customer | `access.account.verify` | Global |
| `SignIn` — cancels a pending deletion | every guest | `access.session.sign_in` | Global |
| `SignOut` | every customer | `access.session.sign_out` (amendment 2, §9.4) | Global |
| `RequestPasswordReset` / `ResetPassword` | every guest | `access.session.reset_password` | Global |
| `ChangePassword` | every customer | `access.account.update` | Global |
| `UpdateProfile` — names, language | every customer | `access.account.update` | Global |
| `SaveAddress` / `DeleteAddress` / `SetDefaultAddress` | every customer | `access.address.manage` | That store |
| `RequestAccountDeletion` | every customer | `access.account.delete` | Global |

### 3.2 Staff

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `SignInStaff` → `VerifyStaffSignInCode` (+ trust this browser) | every guest | `access.session.sign_in` | Global |
| `RequestStaffPasswordReset` / `ResetStaffPassword` | every guest | `access.session.reset_password` | Global |
| `AcceptStaffInvitation` — set password, verify phone | every guest (with the link) | `access.staff.accept_invitation` | Global |
| `InviteStaff` — profile, role, store scope | role | `access.staff.invite` | The stores in the scope; no escalation |
| `ResendStaffInvitation` / `CancelStaffInvitation` | role | `access.staff.invite` | The staff member's stores |
| `UpdateStaffProfile` — including their phone | role | `access.staff.update` | The staff member's stores |
| `ChangeStaffRole` — pick a saved role, or edit it into a personal role; set the scope | role | `access.staff.assign_role` | Old and new stores; no escalation |
| `DisableStaff` / `EnableStaff` | role | `access.staff.disable` | The staff member's stores |
| `ListStaff` / `ViewStaff` | role | `access.staff.view` | Staff whose stores are within the viewer's |
| `CreateRole` / `CloneRole` (saved roles) | role | `access.role.manage` | Only permissions the author holds |
| `UpdateRole` / `DeleteRole` (saved roles; deleting needs a replacement for its holders) | role | `access.role.manage` | Every store of every holder |
| `UpdateOwnStaffProfile` / `ChangeOwnStaffPassword` / `ChangeOwnStaffPhone` / notification preferences | every staff | `access.own_account.update` | Global |
| `SignOutStaff` | every staff | `access.own_account.update` | Global |
| `CreateSuperAdmin` / `RevokeSuperAdmin` — never the last one | system (console) | `access.super_admin.manage` (reserved) | Global |

### 3.3 Customers, seen by staff

| Use case | Audience | Permission | Scope |
|---|---|---|---|
| `ListCustomers` / `ViewCustomer` | role | `access.customer.view` | The customer's home store |
| `BlockCustomer` / `UnblockCustomer` — with a reason | role | `access.customer.block` | The customer's home store |
| `DeleteCustomerOnRequest` — the same 14-day deletion | role | `access.customer.delete` | The customer's home store |
| `UpdateStoreAddressFormat` | role | `access.address_format.update` | That store |
| `UpdateAccessSettings` — lockout, OTP and session numbers | role | `access.settings.update` | That store (customer settings); all stores (staff settings) |
| `AnonymizeDueAccounts` — daily | system (scheduled job) | `access.account.anonymize` (reserved) | Global |

**Who sees whom [DECIDED 2026-09-19].** A KSA-only admin sees only KSA customers — those whose home
store is KSA — and only KSA staff; an Egypt-only admin only Egypt's; a multi-store admin the
customers and staff of their stores; a Super Admin everyone. A customer who also orders in another
store appears there through the order, with the order's customer details, not in that store's
customer list. A staff member is visible to an admin whose stores include all of theirs (every
store any of the staff member's actions covers).

Every change is audited. Personal fields — names, email, phone, addresses, date of birth, the
staff address — are recorded only as "changed".

---

## 4 · State machines

### 4.1 Customer

```
                register
                    │
                    ▼
    ┌──────────────────────────┐   block (staff)    ┌─────────┐
    │ ACTIVE                   │ ─────────────────▶ │ BLOCKED │
    │  email: unverified→verified ◀──────────────── │         │
    │  phone: none→verified     │   unblock (staff)  └─────────┘
    └──────────────────────────┘
          │ request deletion (password)        ▲ sign in within 14 days
          ▼                                     │ (cancels)
    ┌──────────────────────┐ ───────────────────┘
    │ DELETION PENDING     │
    │ (cannot order)       │
    └──────────────────────┘
          │ 14 days pass (daily job)
          ▼
    ┌──────────────────────┐
    │ ANONYMIZED (final)   │  cannot sign in; its email is free again
    └──────────────────────┘
```

`status` (`ACTIVE`/`BLOCKED`) and the deletion are separate columns: a blocked customer's deletion
still runs. Verifications only move forward (a verified email never becomes unverified); changing
the phone keeps the old verified number until the new one verifies.

### 4.2 Phone code

`requested → (right code) verified` · `(5 wrong tries or 5 minutes) → dead` · a new request
replaces the previous code.

### 4.3 Staff member

```
   invite ──▶ INVITED ── accept (password + phone) ──▶ ACTIVE
                │                                       │   ▲
                │ cancel                        disable │   │ enable
                ▼                                       ▼   │
             DISABLED ◀───────────────────────────── DISABLED
```

An invitation can be resent while `INVITED`; each resend replaces the link. `DISABLED` ends every
session and trusted browser at once. Enabling someone who never accepted their invitation sends a
new invitation (back to `INVITED`), because they have no password yet.

### 4.4 Staff sign-in

`password ok → (trusted browser? → signed in) : SMS code → (right code → signed in, optionally trust
this browser for 30 days)`. Wrong codes follow the same limits as customer codes.

---

## 5 · Tables

All in the `access` PostgreSQL schema (added to `search_path`). ULIDs are `char(26)`; timestamps
`timestamptz`; enums strings. Every CHECK has a code rule that refuses the value first (Platform
§5.8).

### 5.1 `access.customers`

| Column | Type | Rules |
|---|---|---|
| `id` | `char(26)` PK | |
| `email` | `varchar(254)` NOT NULL | Unique on `lower(email)` |
| `password` | `varchar(255)` NOT NULL | A hash |
| `first_name`, `last_name` | `varchar(100)` NOT NULL | |
| `account_type` | `varchar(16)` NOT NULL | `INDIVIDUAL`, `COMPANY`; immutable (trigger) |
| `status` | `varchar(16)` NOT NULL | `ACTIVE`, `BLOCKED` |
| `email_verified_at`, `phone_verified_at` | `timestamptz` NULL | |
| `phone` | `varchar(16)` NULL | E.164; unique where not null; `phone_verified_at` set exactly when `phone` is |
| `locale` | `char(2)` NOT NULL | `ar`, `en` |
| `home_store_id` | `char(26)` NOT NULL | FK → `platform.stores(id)`; immutable |
| `last_store_id` | `char(26)` NOT NULL | FK → `platform.stores(id)` |
| `terms_version` | `varchar(32)` NOT NULL | |
| `terms_accepted_at` | `timestamptz` NOT NULL | |
| `deletion_scheduled_for`, `anonymized_at` | `timestamptz` NULL | |
| `remember_token` | `varchar(100)` NULL | Laravel's "remember me" |
| `created_at`, `updated_at` | `timestamptz` | |

### 5.2 Phone and email flows

| Table | Columns |
|---|---|
| `access.phone_codes` | `id`, `customer_id` FK, `phone`, `code_hash`, `purpose` (`ADD`, `CHANGE`), `attempts`, `expires_at`, `created_at` — one live code per customer |
| `access.customer_password_resets` | `customer_id` PK/FK, `token_hash`, `expires_at` |

The verification link is a signed URL, so it needs no table. `pending_phone_changes` from handoff
§7.3 is `phone_codes` with purpose `CHANGE`: the new number and its code are one row.

### 5.3 Staff

| Table | Columns |
|---|---|
| `access.staff_users` | `id`, `email` (unique on lower), `password` NULL until accepted, `first_name`, `last_name`, `job_title`, `date_of_birth`, `phone`, `phone_verified_at`, `country`, `address`, `avatar_media_id` FK → `platform.media` RESTRICT, `locale`, `status`, `is_super_admin`, timestamps |
| `access.staff_invitations` | `staff_user_id` PK/FK, `token_hash`, `expires_at`, `invited_by` |
| `access.staff_password_resets` | `staff_user_id` PK/FK, `token_hash`, `expires_at` |
| `access.staff_phone_codes` | `staff_user_id` PK/FK, `phone`, `code_hash`, `attempts`, `expires_at` — verifying a new phone |
| `access.staff_sign_in_codes` | `staff_user_id` PK/FK, `code_hash`, `attempts`, `expires_at` |
| `access.staff_trusted_browsers` | `id`, `staff_user_id` FK, `token_hash` unique, `expires_at`, `created_at`, `last_used_at` |
| `access.staff_notification_preferences` | (`staff_user_id`, `topic`) PK, `email` bool, `panel` bool |

### 5.4 Roles

| Table | Columns |
|---|---|
| `access.roles` | `id`, `name` jsonb (ar, en), `kind` (`SAVED`, `PERSONAL`), `personal_to` FK → staff NULL (set exactly when `PERSONAL`), timestamps |
| `access.role_permissions` | (`role_id`, `permission`) PK — `permission` must be a declared, non-reserved permission of audience `ROLE` (code rule) |
| `access.role_assignments` | `staff_user_id` PK/FK (one role each), `role_id` FK RESTRICT, `access_level` (`ALL_STORES`, `SELECTED_STORES`) — the stores for every action — `assigned_by`, `assigned_at` |
| `access.role_assignment_stores` | (`staff_user_id`, `store_id`) PK, FK → `platform.stores` — rows exist exactly when `SELECTED_STORES` |
| `access.role_assignment_exceptions` | (`staff_user_id`, `permission`) PK, `access_level` — one action with its own stores for that person; the permission must be in their role |
| `access.role_assignment_exception_stores` | (`staff_user_id`, `permission`, `store_id`) PK, FK → `platform.stores` — rows exist exactly when the exception is `SELECTED_STORES` |

An action's stores for a staff member: its exception if there is one, otherwise the assignment's
stores. Removing an action from a role removes its exceptions. The handoff's `store_ids[]` is a
link table instead of an array, so each store id has a real foreign key. A staff member's permissions are cached (versioned, written inside the change's
transaction — Platform's rule); a warm authorization check reads the cache table only.

### 5.5 Addresses

| Table | Columns |
|---|---|
| `access.addresses` | `id`, `customer_id` FK, `store_id` FK (its country), `label`, `recipient_name`, `phone`, `fields` jsonb (the scheme's values), `latitude`, `longitude` `numeric(9,6)` NULL (both or neither), `is_default`, timestamps — one default per (`customer_id`, `store_id`) (partial unique index) |
| `access.store_address_formats` | `store_id` PK/FK, `fields` jsonb (definitions), `display_template`, `updated_at` |

### 5.6 Shared tables

Sessions stay in `public.sessions` (its `user_id` is a ULID, Platform §5.6). The customer and admin
areas use different session cookies (§1.8). Rate limits and lockouts use Laravel's rate limiter on
the database cache.

---

## 6 · Events

### 6.1 Published (`Public/Events`, ids only, after commit)

| Event | Fields | Expected consumers |
|---|---|---|
| `CustomerRegistered` | `eventId`, `customerId`, `accountType`, `storeId`, `occurredAt` | B2B (a company starts its application), Ops |
| `GuestBecameCustomer` | `eventId`, `guestId`, `customerId`, `how` (`REGISTERED`, `SIGNED_IN`), `occurredAt` | Sales (move or merge the cart) |
| `CustomerEmailVerified`, `CustomerPhoneVerified` | `eventId`, `customerId`, `occurredAt` | Ops |
| `CustomerBlocked`, `CustomerUnblocked` | `eventId`, `customerId`, `occurredAt` | Ops |
| `CustomerDeletionScheduled`, `CustomerDeletionCancelled` | `eventId`, `customerId`, `occurredAt` | Ops |
| `CustomerAnonymized` | `eventId`, `customerId`, `occurredAt` | Sales, Feedback, B2B, Loyalty, Promotions |
| `StaffActivated`, `StaffDisabled` | `eventId`, `staffId`, `occurredAt` | Ops |

### 6.2 Consumed

None.

### 6.3 Messages

The security messages of §2.3, sent by Access until Ops exists. No other notifications.

---

## 7 · Errors

`AccessError extends DomainError`, following Platform §7. Each has a stable `type`
(`access.{name}`) and translations in both languages.

```
AccessError
├── EmailAlreadyRegistered        CONFLICT     "You already have an account — please sign in"
├── PhoneAlreadyInUse             CONFLICT
├── InvalidCredentials            FORBIDDEN    never says which part was wrong
├── AccountLocked                 FORBIDDEN    too many wrong passwords; says when to retry
├── SignInRefused                 FORBIDDEN    blocked ("please contact us"), disabled or anonymized
├── InvalidOrExpiredLink          INVALID
├── InvalidCode                   INVALID      wrong or expired SMS code
├── CodeRequestTooSoon            CONFLICT     resend limits
├── PasswordTooWeak               INVALID      too short, or found in a leak
├── CustomerNotFound / StaffNotFound / RoleNotFound / AddressNotFound   NOT_FOUND
├── PermissionEscalation          FORBIDDEN    granting more than you hold
├── UnknownPermission             INVALID
├── ReservedPermission            INVALID
├── RoleInUse                     CONFLICT     deleting a saved role someone holds, with no replacement
├── LastSuperAdmin                CONFLICT     revoking the only Super Admin
├── StaffNotEditable              CONFLICT     a Super Admin, from the panel
├── AddressFormatMissing          CONFLICT     the store has no address format yet
├── InvalidAddress                INVALID      a field fails the store's format
├── DeletionPending               CONFLICT
└── InvalidAccessAttribute        INVALID      malformed email, name, phone, locale…
```

---

## 8 · Test scenarios

### Unit
- Customer: email and account type never change; phone is never removed; verifications move
  forward only; the person's part of the ordering rule for every combination.
- Password rules: 8 / 12 characters; a leaked password refused (the leak check faked in unit
  tests).
- Phone numbers: E.164 normalisation; any country accepted.
- Roles: no escalation (permissions and stores, exceptions included); reserved permissions
  refused; a personal role belongs to one staff member; editing a saved role vs editing a staff
  member's role; an action's stores are its exception's, otherwise the assignment's; an action
  added to a saved role reaches each holder in their stores.
- Address validation against a scheme: required fields, the map pin both-or-neither and in range;
  the country's store decides the scheme.
- Every Access error has a unique `type` and a category.

### Integration (PostgreSQL)
- Migrations create the `access` schema, tables, CHECKs, indexes; every enum column matches its
  PHP enum; every CHECK has a code rule that refuses first.
- Email unique regardless of case; phone unique; several addresses per store, one default per
  customer per store; a KSA customer's UAE address is kept for their next UAE order.
- Authorizer: Super Admin everywhere; staff only through their role and each action's stores
  (exceptions included); `storesWith()`;
  customers and guests only their audience's permissions; a disabled staff member nothing; the
  warm check reads only the cache table.
- Platform's handlers authorize through Access (no interim binding left).
- SMS codes: expiry, 5 wrong tries, resend limits; codes and links never stored in plain text and
  never in events or job payloads.
- Deletion: scheduled, cancelled by signing in, anonymized after 14 days by the scheduled job,
  events published; anonymized fields.
- Visibility: a single-store admin sees and manages only that store's customers and staff; a
  multi-store admin theirs; a Super Admin everyone; editing a saved role held in a store the admin
  does not cover is refused; deleting a held role needs a replacement.
- Super Admin commands: create sends an invitation; revoke refuses the last one.
- Audit: every change audited; personal fields only "changed".

### Feature (HTTP)
- Register (terms version recorded, home store set) → verify email → add phone → verify → the person
  may order; each step's errors, including the plain "already registered" and "blocked" messages.
- After signing in, the customer lands in their last store, on any device.
- Sign in, lockout after 5 wrong passwords, per-IP limit, remember me, session idle limits,
  session id regenerated, other sessions ended on password change.
- Staff: invitation → accept → phone → sign in with an SMS code → trusted browser for 30 days;
  password reset still asks the SMS code; a new phone verified before use; disabled staff signed
  out at once; admin session cookie separate from the storefront's.
- Guest id created lazily, in an encrypted cookie; `GuestBecameCustomer` on registration and
  sign-in.
- The `log` SMS driver writes the code; `SecurityMessages` swappable by binding.

### Architecture
- Every Access handler asserts a permission; no controller checks permissions itself.
- Access imports only `Platform\Public` and `Shared`.
- No `spatie/laravel-permission`.

---

## 9 · Questions

### 9.1 Answered by the owner — 2026-09-18 and 2026-09-19

| # | Question | Decision |
|---|---|---|
| 1 | What the Access stage delivers | **Backend first**; a frontend foundation stage follows (header). |
| 2 | Who sends security messages | **Access, temporarily; Ops later by one binding** (§2.3). |
| 3 | SMS provider | **Not chosen yet**: an interface and a `log` driver; the owner names the provider during the build (§2.3). |
| 4 | Roles and permissions storage | **Our own tables**, not `spatie/laravel-permission` (§1.5). |
| 5 | How customers sign in | **Email and password only** (§1.8). |
| 6 | Registration data | **First and last name** too (§1.1). |
| 7 | Customer phones | **Any country, unique** (§1.1). |
| 8 | Changing email | **Not possible** (§1.1). |
| 9 | Password rules | **8 / 12 characters, leak check** (§1.8). |
| 10 | Lockout and sessions | **5 tries → 15 minutes; customers 30 days / 2 hours idle; staff 30 minutes idle, 12 hours max** (§1.8). |
| 11 | Code and link lifetimes | **SMS code 6 digits, 5 min, 60 s, 5/hour, 5 tries; verification 24 h; reset 60 min; invitation 72 h** (§1.2, §1.3). |
| 12 | Staff 2FA | **SMS code; trusted browser 30 days** (§1.8). |
| 13 | Granting permissions | **Never more than you hold** (§1.5). |
| 14 | Roles per staff member | **One** (§1.5). |
| 15 | Staff who leave | **Disabled, never deleted** (§1.4). |
| 16 | Super Admins | **Console command only; more than one allowed** (§1.6). |
| 17 | Saved roles | **Shared; editing a saved role changes all holders** (§1.5). |
| 18 | Editing a staff member's role from their page | **A personal role for that member only** (§1.5). |
| 19 | Individual or company | **Chosen at registration; the flow differs; B2B owns the company step** (§1.1). |
| 20 | EG / AE address formats | **KSA now; Egypt and UAE later, as data** — superseded by #31 (§1.9). |
| 21 | Guest signs in with a cart | **Merge the carts** (§1.7). |
| 22 | Account deletion | **Self-service with password; 14-day grace; staff can do it on request** (§1.10). |

### 9.2 Answered on the draft — 2026-09-19

| # | Question | Decision |
|---|---|---|
| 23 | The store an account belongs to | **Its registration store, fixed** (home store); the customer shops anywhere and lands in their last store after signing in (§1.1). |
| 24 | Terms at registration | **Accepted and recorded, with the version** (§1.2). |
| 25 | What errors tell a person | **Plainly**: "You already have an account — please sign in"; "Your account is blocked — please contact us" (§1.2, §1.8). |
| 26 | Staff avatars | **Public media** (§1.4). |
| 27 | Editing a saved role held in other stores | **Only a Super Admin, or an admin covering every holder's stores** (§1.5). |
| 28 | Deleting a saved role someone holds | **Refused unless a replacement is picked; holders move to it** (§1.5). |
| 29 | Actions no role grants | **Automatic permissions, own data only** (§1.5). |
| 30 | Super Admin flow | **Created and revoked only by console; invitation, password, phone, SMS code; never the last one** (§1.6). |
| 31 | Addresses | **Country first (our stores' countries); one scheme of 16 fields for all three now, per store; six required; not at registration but before an order** (§1.9). |
| 32 | Who sees which customers and staff | **By home store and store scope; Super Admin everyone** (§3.3). |
| 33 | Stores in a role | **Per staff member: one store choice for every action, and any action may have its own stores** (§1.5). |
| 34 | Ordering from a store the customer does not belong to | **Allowed; an address in that store is required first; several addresses per store** (§1.9). |

### 9.3 Still open

None.

### 9.4 Amendments during the build — each needs the owner's agreement

| # | Where | Change | Why | Status |
|---|---|---|---|---|
| 1 | §2.4 `PermissionDefinitionDto` | The DTO carries no `labelAr`/`labelEn`; a permission's names are the declaring module's translations at `{module}::permissions.{resource}.{action}`, read only when a screen shows them. A test fails if any permission lacks either language. | Passing both labels at declaration would load every module's permission names on every request. | Proposed in the step 1 PR |
| 2 | §3.1 `SignOut` | A customer signs out under its own permission, `access.session.sign_out` (every customer), not `access.session.sign_in`. | Each permission has exactly one audience; signing in belongs to guests, signing out to customers. | Proposed in the step 1 PR |
