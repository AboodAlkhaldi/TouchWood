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
tests, like Platform's Stage 1. The HTTP endpoints are those of the sign-in flows; staff and role
management endpoints come with their screens (amendment 12). The screens (registration, sign-in, verification, the admin
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
  **[DECIDED 2026-09-19]** — in the same words whether it belongs to a customer or to a staff
  account, so staff addresses cannot be found by trying them (amendment 39).
- **Registering signs the customer in at once** (amendment 39): they arrive on the store's page
  signed in, with the verification email on its way, and order once both verifications are done.

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
  6 digits, valid **5 minutes**, resend after **60 seconds**, at most **3 per hour** per number
  (amendment 28),
  **5 wrong tries** and the code is dead — a new one must be requested. Codes are stored only as a
  hash.

### 1.4 Staff user

Handoff §7.6.

| Attribute | Invariant |
|---|---|
| `id` | ULID. |
| `email` | Required, unique regardless of case. The invitation goes there. An email belongs to a staff account **or** a customer account, never both (amendment 13). Changed only through a link sent to the new address (amendment 17); someone invited who has not accepted gets a new invitation there instead (amendment 25). |
| `password` | Set by the staff member when accepting the invitation; the admin never knows it. Rules in §1.8. |
| `first_name`, `last_name`, `job_title`, `date_of_birth`, `country`, `address` | The profile (handoff §7.6). Personal data. **Required at invitation** except the address (amendment 15): names and job title up to 100 characters, the date of birth in the past and after 1900, `country` any ISO 3166-1 alpha-2 code, the address free text up to 500. |
| `phone` | E.164, **verified by SMS before first sign-in**: it receives the 2FA codes **[DECIDED 2026-09-18]**. Entered by the admin at invitation, verified — or first corrected — by the invitee when accepting (amendment 15). **Unique among staff** (amendment 13). |
| `avatar_media_id` | Optional **public** Platform media **[DECIDED 2026-09-19]** — a column with a `RESTRICT` foreign key, registered as a detachable `MediaUsage` (Platform rule, 2026-09-18). |
| `locale` | The **communication language**: every email and SMS code to this person uses it. Chosen at invitation (default: the inviting admin's), changed in the person's own settings. The panel's EN/AR switch changes only what is displayed, at once, and does not change it (amendment 16). |
| `status` | `INVITED`, `ACTIVE`, `DISABLED` or `CANCELLED` (§4.3, amendment 29). **Never deleted** **[DECIDED 2026-09-18]**: the audit log names them forever. |
| `is_super_admin` | Only set by the console command (§1.6). |

**Phone.** It receives the 2FA codes, so it is verified by an SMS code when the invitation is
accepted. A staff member changing their own phone confirms the new number with a code before it
takes effect. An admin with `access.staff.update` may change it (a lost phone); the staff member
then verifies the new number at their next sign-in. A Super Admin's phone is changed only by the
Super Admin themselves (confirmed by a code) or by console command (§1.6).

**Password reset** works as for customers, by an email link, valid **30 minutes** for staff
(amendment 31). It never skips the SMS code at sign-in.

**Notification preferences** (handoff §7.6): for each topic — new orders, company applications,
low stock, campaign expiry — an email toggle and an in-panel toggle. Access stores them; Ops reads
them later through `AccessApi`. A new staff member starts with every in-panel toggle on and every
email toggle off (amendment 19).

### 1.5 Roles, permissions and assignments

Handoff §7.5, with the owner's answers **[DECIDED 2026-09-18, 2026-09-19]**:

- **Our own tables**, not `spatie/laravel-permission`: the store scope lives on each role
  assignment, which that package cannot express (handoff §3 amended).
- **One role per staff member.** The role has a name in Arabic and English and a set of permissions
  — **at least one** **[DECIDED 2026-09-19, amendment 7]**.
- **Assigning a role [DECIDED 2026-09-19]:** the admin picks a saved role, then either keeps it as
  it is — the staff member holds that saved role — or edits it: every action is shown, ticked or
  not, the admin changes them within their own permissions, and the result is saved as that staff
  member's personal role.
- **Saved roles** are shared: many staff can hold the same one, and **editing a saved role changes
  it for everyone who holds it**. Admins can create new saved roles and clone one into a new
  saved role (a copy at creation time, never live inheritance). **Saved role names are unique in
  each language, ignoring case** **[DECIDED 2026-09-19, amendment 7]**.
- **Editing a staff member's role from that staff member's page** gives them a **personal role**:
  a copy of the saved role with the change, belonging to that one person. Nobody else changes. A
  personal role never appears in the list of saved roles; editing it again changes only that person.
  It starts with the saved role's names, which the admin may change, or is built from scratch; it
  is **deleted when its holder is moved to a saved role** **[DECIDED 2026-09-19, amendment 7]**.
- **Stores are chosen per staff member, with exceptions [DECIDED 2026-09-19].** The role holds only
  the actions, so a saved role such as "Order fulfilment" works in any store. For each staff
  member the admin ticks the actions' stores once — KSA, UAE, Egypt, or all stores, now and
  added later — and that choice applies to **every action** in the role. Any single action can
  then be given its own stores for that person: *view orders in KSA and UAE, refund only in KSA*.
  An action added to a saved role later reaches each holder in their chosen stores. The screen:
  tick the actions, a row of store boxes that fills every action, and store boxes per action for
  the exceptions.
- A staff member's **stores**, for who may see and manage them (§3.3), are their store row plus
  every store an exception adds (amendment 9).
- **Nobody grants more than they hold** **[DECIDED 2026-09-18]**: a role can contain only
  permissions its author holds, and an assignment can cover only stores its author covers, for
  those permissions. Among people, only a Super Admin is unlimited; so is the system when a
  console command acts, while a queued job acts under the permissions of whoever queued it.
  Checked in code on every change.
- **Editing a saved role** reaches every store where it is held, so only a **Super Admin** or an
  admin who **covers every store of every holder** (and holds every permission in it) may edit it
  **[DECIDED 2026-09-19]** — covering meaning holding `access.staff.assign_role` there (amendment 11). A KSA + Egypt admin can edit a role held only in KSA and Egypt, never
  one held in UAE; a single-store admin has no authority over another store.
- **Reserved permissions** (e.g. `platform.store.create`) exist so handlers can assert them but
  are never offered in the role editor and can never be put in a role: only a Super Admin holds them.
- **Deleting a saved role** that anyone holds needs a **replacement** **[DECIDED 2026-09-19]**: the
  admin picks another saved role and every holder moves to it (the no-escalation rule applies to
  the move). Without a replacement the delete is refused, listing the holders.

**Three levels: Super Admin → admins → staff [DECIDED 2026-09-19, amendment 9].**

- Every role has a **level**, `ADMIN` or `STAFF`. Whoever holds an admin role is an **admin**;
  whoever holds a staff role is **staff**.
- **Management actions** — `access.staff.invite`, `access.staff.update`,
  `access.staff.assign_role`, `access.staff.disable` and `access.role.manage` — can be put only
  into admin roles. Staff manage no roles and no people.
- **Only a Super Admin** creates, clones, edits or deletes admin roles, gives anyone an admin role,
  and manages admins. No admin manages another admin, or themselves.
- **Admins** create and edit saved staff roles, and manage staff.
- **An admin manages a staff member only if the admin covers all of that person's stores** — holds
  the management action being used in every one of them (amendment 11). This holds for changing
  their role and for every action on the whole account: disabling them, their profile and phone,
  their invitation. A KSA-only admin manages KSA-only staff. A KSA+UAE
  staff member is managed by a KSA+UAE admin, an admin with more stores, or a Super Admin.
- **Redirecting an account** — a new email or phone, a resent invitation — also needs every action
  of the person's role, in the stores it reaches for them (amendment 26): otherwise an admin could
  take over an account holding more than they do.
- **Nobody works without a role** (amendment 27). An account left with no role (a revoked Super
  Admin) is disabled; any admin holding the action somewhere, or a Super Admin, enables it only
  together with a role.
- **A staff member's stores** are the store row chosen for them, plus any store an exception adds.
  Store-free actions add nothing. A staff member with one store is listed under that store; one
  with two or more is listed as **centralized**, and only an admin with the same stores or more
  manages them.
- `access.staff.view` is an ordinary action that a staff role may hold. Its holder sees the staff
  whose stores all lie within their own stores for that action: a KSA-only holder never sees
  KSA+UAE staff, and a KSA+UAE holder sees KSA, UAE and KSA+UAE staff.

**Store-free permissions [DECIDED 2026-09-19, amendment 4].** Each permission is declared either
**per store** or **store-free**. A store-free permission concerns nothing that belongs to one
store, such as uploading or deleting media, or managing roles. Holding it is enough, whatever the
person's stores. The role editor shows its store boxes ticked and disabled: it has no store
choice and no exception. A handler checks a store-free permission only with
`PermissionScope::global()`, and a per-store one only with `store()` or `allStores()`. Any other
check is a programming error and fails at once.

**"Every store" [DECIDED 2026-09-19, amendment 5].** A change that reaches every store
(`PermissionScope::allStores()`, e.g. a global setting) needs the permission with **All stores**.
Ticking every current store one by one is not enough, because the change also reaches stores
opened later.

**The permission catalog.** Every permission is declared in code by the module that checks it,
with its labels in Arabic and English, whether it is reserved, and whether it is per store or
store-free. Platform publishes its list in its public contract (a small Platform addition, §2.5);
modules above Access declare theirs through `PermissionCatalog` (§2.2). A role can only hold
declared permissions. Permission names follow `{module}.{resource}.{action}`.

**Renamed and removed permissions [DECIDED 2026-09-19, amendment 3].** A module that renames or
removes a permission declares it (`renamed()`, `removed()`, §2.2). At the end of every
`php artisan migrate` — also when there is nothing to migrate — Access moves every role and every
exception from the old name to the new one, and takes removed permissions out of every role,
auditing each change. A name in a role that is neither declared nor declared removed is left
alone and reported: it grants nothing, and a module switched off by mistake cannot wipe anyone's
roles.

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
  {email} {first_name} {last_name}` with the full profile as required options — `--job-title`,
  `--date-of-birth`, `--country`, `--phone`, `--locale` (amendment 18) — creates the account and
  emails an invitation that works **24 hours** (amendment 30); unaccepted by then, the system
  cancels the account and frees its email and phone. `access:super-admin:resend-invitation {email}`
  sends a new 24-hour link and `access:super-admin:cancel {email}` cancels the invitation account
  at once. They open the link, set a password and verify their phone by
  SMS code, and are signed in at once (amendment 36: the console named them; staff instead sign
  in afterwards like any sign-in). From then on they sign in at `/admin` like any staff member:
  password, then an SMS code, with a trusted browser for 30 days. Run with the email of an existing staff member, the command
  **promotes** them: their role is removed (a Super Admin has none); an active person keeps their
  password and phone, and someone who never accepted gets a fresh invitation (amendment 18).
- **Removed only by a console command:** `php artisan access:super-admin:revoke {email}` takes the
  power away; with no role, the account is disabled (with every link and code it had) until an
  admin enables it together with a role (amendment 27). An invited Super Admin who never accepted
  is cancelled instead, and freed (amendment 30). **The last
  active Super Admin cannot be revoked** — one who has accepted their invitation must remain — so
  the business is never locked out (amendment 18).
- **A lost phone:** `php artisan access:super-admin:reset-phone {email}` removes the phone and
  ends every trusted browser; at the next sign-in, after the password, they enter a new number and
  verify it by SMS code (amendment 14).
- Never from the admin panel: nobody — not even another Super Admin — can create, edit, disable or
  remove a Super Admin there, so a hijacked admin session cannot mint or remove one. A Super Admin
  edits only their own profile, password and phone. More than one may exist.
- A Super Admin holds every permission in every store, including reserved ones. They have no role.

### 1.7 Guest

A visitor with no account (Platform actor type `GUEST`) **[DECIDED 2026-09-18]**: browses and keeps
a cart, no favourites.

- A guest's id is a ULID, created the first time something needs it (the first cart line), not on
  every page view, so bots create nothing.
- It lives in an **encrypted, HTTP-only cookie**, which lasts **a year** (amendment 39): a cart left
  for a while is still theirs when they come back. Access only reads that cookie; whoever first
  needs a guest writes it. **An id is never a secret**
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
  server). When the leak service cannot be reached, the password is accepted and the outage logged
  (amendment 20).
- **Lockout [DECIDED 2026-09-18]:** 5 wrong passwords for one account → that account is locked for
  **15 minutes**; a separate limit per IP address stops one machine trying many accounts — for
  staff, **10 wrong passwords in 15 minutes** make that address wait 15 minutes (amendment 31). A
  wrong email or password is answered "wrong email or password", without saying which.
- **Staff password reset:** by an email link valid **30 minutes** (amendment 31); it ends every
  session and trusted browser, so the next sign-in asks the SMS code.
- **Signing out** ends the session only: the browser stays trusted (amendment 31). An email link
  (invitation, email change) opened in a browser signed in to the admin panel signs that session
  out first, then continues.
- **Customer sessions:** "remember me" keeps a customer signed in **30 days**; without it, **2 hours**
  idle ends the session. Both are per-store settings; the storefront session's cookie and row are
  given the longest "remember me" any store may set (a year, amendment 39), so the framework never
  ends a session before Access does.
- **The lockout counts on its own keys per side** (amendment 39): a shop's busy address never makes
  the admin panel wait, or the other way round.
- **Staff sessions:** **30 minutes** idle ends the session; **12 hours** at most after sign-in.
- **Staff two-factor [DECIDED 2026-09-18]: an SMS code**, asked after the password. The browser can
  be marked **trusted for 30 days**, after which no code is asked on it until the trust expires.
  Trust is a random token in a cookie, stored only as a hash, tied to one staff member; disabling
  the staff member or changing their password or phone ends every trust.
- **The admin area uses its own session cookie**, separate from the storefront's, so its idle
  limit, 2FA and sign-out never touch a customer session in the same browser — and a staff member
  can also be a customer. It keeps its rows in its own table as well (`access.admin_sessions`,
  amendment 40), so neither side's housekeeping can end the other's sessions.
- **Registrations and reset requests are limited per address**: 10 an hour, a per-store setting
  (amendment 40).
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

    /** The roles holding $from get $to instead, at the next migrate (amendment 3). */
    public function renamed(string $module, string $from, string $to): void;

    /** Taken out of every role at the next migrate (amendment 3). */
    public function removed(string $module, string ...$names): void;
}
```

A permission outside the module's prefix, declared twice, or malformed is refused at boot. So is a
rename whose old name is still declared or whose new name is not, a name renamed twice, and a
removed name that is still declared.

### 2.3 `SecurityMessages` — sent by Access now, by Ops later **[DECIDED 2026-09-18]**

```php
interface SecurityMessages
{
    public function emailVerification(CustomerDto $customer, string $link): void;
    public function passwordReset(string $email, string $locale, string $link): void;
    public function phoneCode(string $phone, string $locale, string $code): void;
    public function staffInvitation(StaffDto $staff, string $link): void;
    public function staffEmailChange(StaffDto $staff, string $newEmail, string $link): void; // amendment 17
    public function staffSignInCode(string $phone, string $locale, string $code): void;       // amendment 34
    public function deletionScheduled(CustomerDto $customer, DateTimeImmutable $on): void;
}
```

A staff sign-in code has its own message, which warns that the password was just used: "Your admin
panel sign-in code is …. If you did not try to sign in, change your password now." It takes the
number, not the staff member: a Super Admin whose phone was reset gets theirs on a number not yet on
the account (amendment 34). The customer messages (`emailVerification`, `deletionScheduled`) arrive
with customer accounts (step 4).

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
| `PermissionDefinitionDto` | `name`, `audience` (`ROLE`, `EVERY_STAFF`, `EVERY_CUSTOMER`, `EVERY_GUEST`), `reserved`, `kind` (`PER_STORE`, `GLOBAL` — store-free, amendment 4); its names in Arabic and English are the module's translations at `labelKey()` — `{module}::permissions.{resource}.{action}` (amendment 1, §9.4) |
| `StaffNotificationPreferenceDto` | `topic`, `email`, `panel` |

Enums: `AccountType`, `CustomerStatus`, `StaffStatus`, `AccessLevel`, `PermissionAudience`,
`PermissionKind`, `StaffNotificationTopic` — stored as strings.

### 2.5 What Access implements for others, and what it needs

- **Implements `Shared\Application\ActorContext`** — the signed-in customer or staff member, the
  guest, or the system — and **`Shared\Application\Authorizer`** with `PermissionScope` and
  `storesWith()`. Both bound with `scoped()`, replacing Platform's interim bindings; Platform's
  wrapper keeps making queued jobs act as the system for their requester.
- **Needs from Platform:** `PlatformApi` (stores, settings, `recordAudit`, media), `StoreContext`,
  `SettingsRegistry` (its settings, §1.8), `MediaUsages` (staff avatars), `ReservedPaths` (Platform
  already reserves `admin` and `api`).
- **Platform additions in this stage:** Platform publishes its permission list in its public
  contract (`PlatformPermissions`: names, reserved flags and kinds, with the names in Arabic and
  English in `platform::permissions` — amendments 1 and 4), and its interim `SystemActorContext`
  and `SystemOnlyAuthorizer` are removed. Platform's `VersionedCache` moves to the Shared kernel,
  so Access caches permissions by the same never-stale rule (amendment 6).

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
| `SignInStaff` → `VerifyStaffSignInCode` (+ trust this browser); `ResendStaffSignInCode`; `SendStaffSignInCodeToNewPhone` — a Super Admin whose phone was reset (amendment 14) | every guest | `access.session.sign_in` | Global |
| `RequestStaffPasswordReset` / `ResetStaffPassword` | every guest | `access.session.reset_password` | Global |
| `AcceptStaffInvitation` — set password, verify phone | every guest (with the link) | `access.staff.accept_invitation` | Global |
| `InviteStaff` — profile, role, store scope | role | `access.staff.invite` | The stores in the scope; no escalation; an admin invites staff only (amendment 9) |
| `ResendStaffInvitation` / `CancelStaffInvitation` — the link dies, still invited | role | `access.staff.invite` | Every store of the staff member; resending also needs every action of their role (amendment 26) |
| `CancelStaffAccount` — someone invited: `CANCELLED`, email and phone freed (amendment 29) | role | `access.staff.invite` | Every store of the staff member, and only the admin who invited them or a Super Admin |
| `UpdateStaffProfile` — including their phone | role | `access.staff.update` | Every store of the staff member; a new phone also needs every action of their role (amendment 26) |
| `ChangeStaffRole` — pick a saved role, or edit it into a personal role; set the scope | role | `access.staff.assign_role` | Every old and new store; no escalation; admins change staff only, never themselves (amendment 9) |
| `DisableStaff` / `EnableStaff` — someone with no role is enabled only together with one (amendment 27) | role | `access.staff.disable` (+ `access.staff.assign_role` for the role) | Every store of the staff member; anywhere, when they have no role |
| `ListStaff` / `ViewStaff` | role | `access.staff.view` | Staff whose stores all lie within the viewer's |
| `CreateRole` / `CloneRole` (saved roles) | role | `access.role.manage` (store-free) | Only permissions the author holds; admin roles by a Super Admin only |
| `UpdateRole` / `DeleteRole` (saved roles; deleting needs a replacement for its holders) | role | `access.role.manage` (store-free) | Every store of every holder; admin roles by a Super Admin only |
| `ListRoles` / `ViewRole` — saved roles; one role with its permissions and holders (amendment 8) | role | `access.role.manage` or `access.staff.assign_role` | An admin sees admin roles read-only; of the holders, only those they manage, plus the total count |
| `RefreshStaffPermissions` / `RefreshRolePermissions` — rebuild the cached permissions of one staff member, or of a role's holders (amendment 10) | role | `access.staff.assign_role` / `access.role.manage` | As for changing that staff member's role / editing that role |
| `RoleEditorPermissions` — what the author may put in a role, with names, kinds and their own stores (amendment 8) | role | `access.role.manage` or `access.staff.assign_role` | The author's own permissions |
| `MyPermissions` — what I may do, and where; the admin menu is built from it (amendment 8) | every staff | none: it shows only the reader's own permissions | Own data |
| `UpdateOwnStaffProfile` / `ChangeOwnStaffPassword` / `ChangeOwnStaffPhone` / notification preferences | every staff | `access.own_account.update` | Global |
| `SignOutStaff` | every staff | `access.own_account.update` | Global |
| `CreateSuperAdmin` / `RevokeSuperAdmin` — never the last active one; create promotes an existing staff member / `ResetSuperAdminPhone` (amendments 14, 18) / `ResendSuperAdminInvitation` / `CancelSuperAdminInvitation` (amendment 30) | system (console) | `access.super_admin.manage` (reserved) | Global |
| `CancelExpiredSuperAdminInvitations` — a scheduled job (amendment 30) | system | `access.super_admin.manage` (reserved) | Global |
| `ChangeStaffEmail` → `ConfirmStaffEmailChange` (the link sent to the new address, 72 hours; amendment 17). Someone invited gets a new invitation instead (amendment 25) | role (`access.staff.update`) / every guest with the link (`access.staff.accept_invitation`) | as named | Every store of the staff member and every action of their role (amendment 26) / Global — the requester must still be allowed when the link is used |

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
customer list. A staff member is visible to a viewer who holds `access.staff.view` in all of the
staff member's stores (their store row plus exception stores, amendment 9), and managed by an admin
who holds the management action in all of them (amendment 11).

Every change is audited. Personal fields — names, email, phone, addresses, date of birth, the
staff profile's job title, country and address — are recorded only as "changed".

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
invite ──▶ INVITED (not registered yet)
             │ ├─ resend → a new link
             │ ├─ cancel invitation → the link dies, still INVITED
             │ └─ accepts (password + phone code) ──▶ ACTIVE
             │                                          │  ▲
             │                                  disable │  │ enable
             │                                          ▼  │
             │                                        DISABLED
             ▼
      cancel the account (the inviter or a Super Admin; a Super Admin invitation
             │           24 hours unaccepted, by the system)
             ▼
         CANCELLED — final; email and phone free again
```

An invitation can be resent while `INVITED`; each resend replaces the link. `DISABLED` ends every
session and trusted browser at once. Only someone who accepted can be disabled and enabled
(amendment 29).

### 4.4 Staff sign-in

`password ok → (trusted browser? → signed in) : SMS code → (right code → signed in, optionally trust
this browser for 30 days)`. Wrong codes follow the same limits as customer codes. A Super Admin whose
phone was reset gives a new number after the password, and the code sent there verifies it
(amendment 14). The code step must be finished within 15 minutes of the password (amendment 34,
proposed); after that, the password is asked again.

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
| `session_version` | `integer` NOT NULL | Starts 0; every password change or reset raises it, which ends every session holding the old number (step 4b) |
| `remember_token` | `varchar(100)` NULL | Laravel's own column; unused — "remember me" is a flag in the session, not a token (step 4b) |
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
| `access.staff_users` | `id`, `email` (unique on lower among accounts not `CANCELLED`), `password` NULL until accepted, `first_name`, `last_name`, `job_title`, `date_of_birth`, `phone` (unique among accounts not `CANCELLED`), `phone_verified_at`, `country`, `address`, `avatar_media_id` FK → `platform.media` RESTRICT, `locale`, `status` (`INVITED`, `ACTIVE`, `DISABLED`, `CANCELLED`), `is_super_admin`, `invited_by` FK → `staff_users` (null: the console), `session_version` (raised when the password changes, ending other sessions), timestamps |
| `access.staff_invitations` | `staff_user_id` PK/FK, `token_hash` unique, `pending_password` (the chosen password, hashed, waiting for the phone code), `expires_at`, `invited_by`, `created_at` |
| `access.staff_email_changes` | `staff_user_id` PK/FK, `new_email`, `token_hash` unique, `expires_at`, `requested_by`, `created_at` (amendment 17) |
| `access.staff_password_resets` | `staff_user_id` PK/FK, `token_hash` unique, `expires_at`, `created_at` — one live link per staff member |
| `access.staff_phone_codes` | `staff_user_id` PK/FK, `purpose` (`ACCEPT`/`CHANGE`), `phone`, `code_hash`, `attempts`, `expires_at`, `sent_at` — verifying a phone when accepting or changing it |
| `access.staff_sign_in_codes` | `staff_user_id` PK/FK, `phone` (where it went), `code_hash`, `attempts`, `expires_at`, `sent_at` |
| `access.staff_trusted_browsers` | `id`, `staff_user_id` FK, `token_hash` unique, `expires_at`, `created_at`, `last_used_at` |
| `access.staff_notification_preferences` | (`staff_user_id`, `topic`) PK, `email` bool, `panel` bool |

### 5.4 Roles

| Table | Columns |
|---|---|
| `access.roles` | `id`, `name` jsonb (ar, en), `kind` (`SAVED`, `PERSONAL`), `level` (`ADMIN`, `STAFF`, amendment 9), `personal_to` FK → staff NULL (set exactly when `PERSONAL`; at most one personal role per staff member), timestamps — saved role names unique in each language, ignoring case (amendment 7) |
| `access.role_permissions` | (`role_id`, `permission`) PK — `permission` must be a declared, non-reserved permission of audience `ROLE` (code rule) |
| `access.role_assignments` | `staff_user_id` PK/FK (one role each), `role_id` FK RESTRICT, `access_level` (`ALL_STORES`, `SELECTED_STORES`) — the stores for every action — `assigned_by`, `assigned_at` |
| `access.role_assignment_stores` | (`staff_user_id`, `store_id`) PK, FK → `platform.stores` — rows exist exactly when `SELECTED_STORES` |
| `access.role_assignment_exceptions` | (`staff_user_id`, `permission`) PK, `access_level` — one action with its own stores for that person; the permission must be in their role |
| `access.role_assignment_exception_stores` | (`staff_user_id`, `permission`, `store_id`) PK, FK → `platform.stores` — rows exist exactly when the exception is `SELECTED_STORES` |

An action's stores for a staff member: its exception if there is one, otherwise the assignment's
stores. Removing an action from a role removes its exceptions. The handoff's `store_ids[]` is a
link table instead of an array, so each store id has a real foreign key. A staff member's
permissions are cached (versioned, written inside the change's transaction — Platform's rule); a
warm authorization check reads the cache table only. A cached copy lives at most 1 hour, and
admins can rebuild it by hand (amendment 10).

### 5.5 Addresses

| Table | Columns |
|---|---|
| `access.addresses` | `id`, `customer_id` FK, `store_id` FK (its country), `label`, `recipient_name`, `phone`, `fields` jsonb (the scheme's values), `latitude`, `longitude` `numeric(9,6)` NULL (both or neither), `is_default`, timestamps — one default per (`customer_id`, `store_id`) (partial unique index) |
| `access.store_address_formats` | `store_id` PK/FK, `fields` jsonb (definitions), `display_template`, `updated_at` |

### 5.6 Shared tables

Storefront sessions stay in `public.sessions` (its `user_id` is a ULID, Platform §5.6); the admin
panel keeps its own rows in `access.admin_sessions`, of the same shape (amendment 40). The two areas
also use different session cookies (§1.8). Rate limits and lockouts use Laravel's rate limiter on
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
├── StaffEmailInUse               CONFLICT     another staff account has this email (amendment 21)
├── PhoneAlreadyInUse             CONFLICT
├── InvalidStaffStatus            CONFLICT     a change the account's status does not allow (amendment 21)
├── InvalidCredentials            FORBIDDEN    never says which part was wrong
├── AccountLocked                 FORBIDDEN    too many wrong passwords; says when to retry
├── SignInRefused                 FORBIDDEN    a staff account disabled, cancelled or anonymized
├── CustomerBlocked               FORBIDDEN    a blocked customer, told only after the right password
├── TooManyRequests               FORBIDDEN    too many registrations or reset requests from one address (amendment 40)
├── InvalidOrExpiredLink          INVALID
├── InvalidCode                   INVALID      wrong or expired SMS code
├── CodeRequestTooSoon            CONFLICT     resend limits
├── PasswordTooWeak               INVALID      too short, or found in a leak
├── CustomerNotFound / StaffNotFound / RoleNotFound / AddressNotFound   NOT_FOUND
├── PermissionEscalation          FORBIDDEN    granting more than you hold
├── UnknownPermission             INVALID
├── ReservedPermission            INVALID
├── AdminOnlyPermission           INVALID      a management action in a staff role (amendment 9)
├── RoleNameTaken                 CONFLICT     another saved role has that name (amendment 7)
├── RoleInUse                     CONFLICT     deleting a saved role someone holds, with no replacement
├── LastSuperAdmin                CONFLICT     revoking the only Super Admin
├── StaffNotEditable              CONFLICT     a Super Admin from the panel; an admin, except by a Super Admin; yourself
├── SuperAdminOnly                FORBIDDEN    an admin role created, changed or given by anyone but a Super Admin (amendment 9)
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
- Levels: management actions refused in a staff role; admin roles and admins only by a Super Admin;
  nobody changes their own role (amendment 9). Store-free actions take no store choice or
  exception (amendment 4).
- The catalog: kinds, renames and removals validated at boot (amendments 3, 4).
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
  warm check reads only the cache table; a store-free permission checked against a store, or a
  per-store one checked with no store, fails; "every store" needs All stores (amendments 4, 5).
- Renamed and removed permissions reach every role and exception at the end of `migrate`, also
  with nothing to migrate; unknown names are left and reported (amendment 3).
- An admin manages only staff whose stores all lie within theirs; a KSA-only admin cannot touch a
  KSA+UAE staff member (amendment 9).
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
- Every Access command handler asserts a permission; the role reads check theirs (`MyPermissions`
  needs none: it shows only the reader's own); no controller checks permissions itself.
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

| # | Question | Found | Ask by |
|---|---|---|---|
| 36 | May staff with `access.staff.view` see admins? | Step 2 | Step 6 |

Answered: #35, who manages a staff member with no role — any admin holding the action somewhere, or
a Super Admin; and such an account is disabled until enabled together with a role (owner,
2026-09-19; amendment 27).

### 9.4 Amendments during the build — each needs the owner's agreement

| # | Where | Change | Why | Status |
|---|---|---|---|---|
| 1 | §2.4 `PermissionDefinitionDto` | The DTO carries no `labelAr`/`labelEn`; a permission's names are the declaring module's translations at `{module}::permissions.{resource}.{action}`, read only when a screen shows them. A test fails if any permission lacks either language. | Passing both labels at declaration would load every module's permission names on every request. | Agreed (PR #25 merged) |
| 2 | §3.1 `SignOut` | A customer signs out under its own permission, `access.session.sign_out` (every customer), not `access.session.sign_in`. | Each permission has exactly one audience; signing in belongs to guests, signing out to customers. | Agreed (PR #25 merged) |
| 3 | §1.5, §2.2 | Renamed permissions carry over and removed ones are dropped, both declared by their module and applied at the end of every `migrate` (also with nothing to migrate); names neither declared nor removed are left and reported. | The spec did not say what happens to roles when a later version renames or removes a permission. | Owner, 2026-09-19 |
| 4 | §1.5, §2.4 | Each permission is per store or store-free. Store-free: any store is enough; the editor shows its boxes ticked and disabled; no exceptions. Store-free today: `platform.media.upload/update/delete`, `access.role.manage`, and the reserved `platform.store.create`, `platform.currency.create/update`, `platform.media.variants.generate`. Everything else a role can hold is per store. The automatic permissions and Access's reserved ones follow their §3 scope: "Global" ones are store-free (signing in, one's own account, `access.super_admin.manage`, `access.account.anonymize`), and `access.address.manage` ("That store") is per store. A check must match the kind. | Media and roles belong to no store. | Owner, 2026-09-19 |
| 5 | §1.5 | "Every store" (`allStores()`) needs the permission with All stores; every current store ticked is not enough. | A change reaching every store also reaches stores opened later. | Owner, 2026-09-19 |
| 6 | §2.5 (Platform, Shared) | `VersionedCache` moves from Platform's interior to the Shared kernel (17 → 18 classes). | Access, and later Catalog and Pricing, cache by the same never-stale rule; modules cannot reach Platform's interior. | Owner, 2026-09-19 |
| 7 | §1.5, §5.4, §7 | Saved role names unique in each language, ignoring case (`RoleNameTaken`); a personal role starts from the saved role's names or from scratch and is deleted when its holder moves to a saved role; a role has at least one action; a clone is made only from a saved role and is refused if it holds an action the author does not; a deleted role's replacement is a saved role of the same level. | Two roles with one name confuse admins; nobody else uses a personal role; staff never become admins through a delete. | Owner, 2026-09-19 |
| 8 | §3.2 | Read use cases added: `ListRoles`, `ViewRole`, `RoleEditorPermissions`, `MyPermissions`. Admins see admin roles read-only; of a role's holders, only those they manage, plus the count. | The role screens and the admin menu need them; the spec listed none. | Owner, 2026-09-19 |
| 9 | §1.5, §3.2, §5.4, §7 | Three levels: Super Admin → admins → staff. Roles have a level; management actions only in admin roles (`AdminOnlyPermission`); only a Super Admin manages admins and admin roles; nobody changes their own role; an admin manages a staff member only when covering all of their stores, for their role and their whole account; a staff member's stores are the store row plus exception stores; one store = listed under it, two or more = centralized; `access.staff.view` is an ordinary action. New errors `AdminOnlyPermission` and `SuperAdminOnly`. | The owner's model of who manages whom. | Owner, 2026-09-19 |
| 10 | §3.2, §5.4 | Cached permissions live at most **1 hour** (a safety net: every change replaces them at once), and an admin editing a staff member or a role can rebuild their cached permissions by hand (`RefreshStaffPermissions`, `RefreshRolePermissions`). | A second guard behind the automatic one. | Owner, 2026-09-19 |
| 11 | §1.5, §3.2 | An admin's reach over a staff member is the stores of the management action: anything that changes a staff member's access — changing their role, editing, deleting or refreshing a saved role they hold, moving them when a role is deleted — needs `access.staff.assign_role` in **all** of their stores (and `access.role.manage` to edit roles). A role page lists only the holders the admin could reassign. | Two parts of the code read "covers their stores" differently when an admin's own actions have exceptions. | Owner, 2026-09-19 |
| 12 | Header, §3 | HTTP endpoints in this stage cover the flows that need cookies and sessions — accepting an invitation, confirming an email change, signing in with the SMS code and a trusted browser, password reset, signing out — answering with redirects. Staff and role management endpoints come with their screens in the frontend foundation stage. Step 3 is split into 3a (staff accounts) and 3b (signing in): eight build steps. | The screens are built in stage 2b; endpoints with no screen to call them would be built twice. | Owner, 2026-09-19 |
| 13 | §1.4 | A staff phone is unique among staff. An email belongs to a staff account or a customer account, never both; the same person may use one phone for both, verifying it once for each (the cross-check with customers arrives with customer accounts). | A code must reach exactly one staff member; one email, one account. | Owner, 2026-09-19 |
| 14 | §1.6, §3.2 | `access:super-admin:reset-phone {email}` removes a Super Admin's phone and ends every trusted browser; a new number is verified at the next sign-in, after the password. | Nobody can change a Super Admin in the panel, so a lost phone needs a server-side way back. | Owner, 2026-09-19 |
| 15 | §1.4 | The full profile is required at invitation (email, names, job title, date of birth, country, phone, communication language; the address and avatar optional); limits as in §1.4. The invitee verifies the phone by SMS when accepting, and may first correct a mistyped number. | The owner wants every staff account complete from the start. | Owner, 2026-09-19 |
| 16 | §1.4 | `locale` is the communication language (emails, codes), set at invitation and changed in the person's settings; the panel's EN/AR switch changes only the display, at once. | A person reads the panel in either language but gets messages in one. | Owner, 2026-09-19 |
| 17 | §1.4, §2.3, §3.2 | A staff email can change: an admin who manages the person (or a Super Admin for themselves) enters the new address, which takes effect when its link (72 hours) is used; `SecurityMessages::staffEmailChange()`. | Company email addresses change; the history stays on one account. | Owner, 2026-09-19 |
| 18 | §1.6, §3.2 | `access:super-admin:create` takes the full profile as options, promotes an existing staff member (role removed; a never-accepted one gets a fresh invitation); revoking must leave at least one active Super Admin. | Consistent with amendment 15; an invited Super Admin may never accept. | Owner, 2026-09-19 |
| 19 | §1.4 | New staff start with in-panel notifications on and email notifications off for every topic. | Nobody's inbox fills with every order by default. | Owner, 2026-09-19 |
| 20 | §1.8 | When the leaked-password service cannot be reached, the new password is accepted and the outage logged (Laravel's `uncompromised()` default). | Nobody is stuck because an outside service is down; the length rule still applies. | Owner, 2026-09-19 |
| 21 | §7 | New errors: `StaffEmailInUse` (CONFLICT) when another staff account has the email an admin enters, and `InvalidStaffStatus` (CONFLICT) for a change the account's status does not allow — disabling twice, resending to someone active. | The spec named none; `EmailAlreadyRegistered` speaks to a customer signing up. | Owner, 2026-09-19 |
| 22 | §1.8 | The staff security settings take only these ranges: password 8–128 characters; invitation and email-change links 1–720 hours; codes 4–8 digits, valid 1–60 minutes, resent after 0–3,600 seconds, 1–100 an hour, 1–20 wrong tries. | A mistyped setting (a code valid 0 minutes, a 2-character password) would lock everyone out or weaken sign-in. | Owner, 2026-09-19 |
| 23 | §3.2 | `InviteStaff` needs `access.staff.invite` **and** `access.staff.assign_role` in every store the new member gets. | Inviting gives a role, which amendment 11 puts under `assign_role`. | Owner, 2026-09-19 |
| 24 | §1.6 | Promoting a `DISABLED` staff member to Super Admin enables them, with their old password and phone. | A disabled Super Admin could never act, and nothing in the panel can enable one. | Owner, 2026-09-19 |
| 25 | §1.4, §3.2 | Changing the email of someone invited who has not accepted changes it at once and sends a new invitation there; the link sent to the old address dies. A disabled account's email is not changed. | A mistyped address must not keep a live link; accepting the invitation proves the new address. | Owner, 2026-09-19 |
| 26 | §1.5, §3.2 | Redirecting an account — a staff member's new email or new phone, or a resent invitation — needs every action of their role in the stores it reaches for them (`PermissionEscalation` otherwise), the same as giving them that role. An email change takes effect only if whoever asked may still make it when the link is used. | An admin managing someone by stores could otherwise take over an account holding more than they do. | Owner, 2026-09-19 |
| 27 | §1.5, §1.6, §3.2 | Nobody works without a role. A revoked Super Admin is disabled, with every link and code. Someone with no role is enabled only together with one (`EnableStaff` takes the role); any admin holding the action somewhere, or a Super Admin, may do it. Deleting a role its holders still hold needs a replacement, as before. | A staff account must always have a role; keep the no-role case as small as possible. | Owner, 2026-09-19 |
| 28 | §1.3, §1.8 | SMS codes: at most **3 an hour** per number (was 5). | Fewer paid messages per number. | Owner, 2026-09-19 |
| 29 | §1.4, §3.2, §4.3, §5.3 | **Staff lifecycle:** `INVITED` (not registered yet) becomes `ACTIVE` only by accepting. Two actions: *cancel the invitation* (the link dies; still `INVITED`; a new link can be resent) and *cancel the account* (`CANCELLED`: final; the email and phone are free again; the row stays for the audit log; inviting the person again makes a new account). Cancelling the account needs `access.staff.invite` in the person's stores **and** being the admin who invited them, or a Super Admin. An invited person cannot be disabled; `DISABLED` is only for people who accepted, and enabling brings them back to `ACTIVE`. `staff_users.invited_by` keeps who invited them. | An invited person gave nothing yet; freeing them avoids stuck emails and phones. | Owner, 2026-09-19 |
| 30 | §1.6, §1.8, §3.2 | **Super Admin invitations** work **24 hours** (setting `access.staff.super_admin_invitation_hours`); when one passes unaccepted, a scheduled job cancels the account and frees it. Console: `access:super-admin:resend-invitation {email}` (a new 24-hour link) and `access:super-admin:cancel {email}`; `revoke` on an invited Super Admin cancels it. Staff invitations keep 72 hours and are never freed automatically. | A Super Admin invitation left open is the most dangerous link there is. | Owner, 2026-09-19 |
| 31 | §1.8 | **Staff sign-in numbers:** 10 wrong passwords from one IP address in 15 minutes make that address wait 15 minutes; a staff password reset link works **30 minutes**; signing out keeps the browser trusted; opening an email link (invitation, email change) in a browser signed in to the admin panel signs that session out first, then continues. | The spec named no number for the IP limit or the staff reset link. | Owner, 2026-09-19 |
| 32 | §3.2 | Audited: each staff sign-in, sign-out, lockout, and browser marked trusted. Wrong passwords are counted for the lockout, not logged one by one. | A record of who got in, and of attacks, without flooding the log. | Owner, 2026-09-19 |
| 33 | (handoff §5.3) | A failed database query is logged without its values (`mask_bindings_in_exception_messages`), so no password hash or personal data reaches the log file. | Personal data stays out of the logs, as it stays out of the audit log. | Owner, 2026-09-19 |
| 34 | §1.8, §2.3, §4.4 | **Choices made while building step 3b:** (a) the new sign-in settings take only these ranges: account lockout 3–20 wrong passwords over 1–1,440 minutes; address limit 3–100 over 1–1,440 minutes; idle 5–720 minutes; longest session 1–72 hours; trusted browser 1–90 days; staff reset link 5–1,440 minutes; (b) a reset email goes to one account at most **3 times an hour** (a setting, 1–20), and the page answers the same every time; (c) after the right password, the code step must be finished within **15 minutes** (fixed); (d) a sign-in code has its own message, `SecurityMessages::staffSignInCode(phone, locale, code)`, warning that the password was just used; it takes the number, because a Super Admin's code after a phone reset goes to a number not yet on the account; (e) until customer accounts (step 4), a guest gets a new id on every request. | The spec named no numbers for these; (d) lets a staff member learn that someone else has their password; (e) follows from how the code works. | Owner, 2026-09-19 |
| 35 | §1.4, §1.8 | **From the independent review of step 3b:** (a) a wrong current password, when changing one's own password, counts towards the account lockout like a wrong password at sign-in; (b) a password reset link dies when the account is disabled or revoked, or its email changes; (c) a staff member with no phone who is not a Super Admin cannot sign in until an admin gives them one — only a Super Admin whose phone was reset chooses a number at sign-in (amendment 14); (d) a sign-in code counts only for the number the account has now, and changing the number drops a code already sent; a half-finished sign-in ends if the password changes. Also fixed, with no visible change: attempts sent at the same moment are counted before the password is checked; disabling ends every session for good, even once enabled again; staff links are built on `APP_URL`, never on the request's host; the reset email goes out after the answer, so its timing tells nothing; failed queries lose the values PostgreSQL puts in its error line and CONTEXT line too. | Security findings: a stolen session could guess the current password without limit; an old mailbox could keep a live reset link; the password alone could pick a phone; a code on its way could bring back a replaced number. | Owner, 2026-09-19 |
| 36 | §1.6, §1.8, §3.2, handoff §5.3 | (a) Accepting an invitation signs in **only a Super Admin** at once; a staff member is sent to the sign-in page and signs in as always, password and SMS code. (b) An address made to wait after 10 wrong passwords is audited (`access.staff_sign_in.address_locked`), once per lock; Platform keeps IP addresses only for staff actions (Platform spec §1.5), so the entry names the address by a keyed fingerprint — repeats show, the address cannot be read back. (c) "Change my own password" keeps its endpoint in this stage. (d) In production the application refuses to start while `MAIL_MAILER` is `log`, `array` or unset, which would write invitation and reset links to the log. (e) Trusted proxies and HTTPS-only cookies wait for the hosting choice (handoff §15). | The console names a Super Admin, so their acceptance is enough; staff prove themselves at the sign-in page. An attack on many accounts should leave a trace. Links must never sit in a log file. | Owner, 2026-09-19 |
| 37 | §1.2, §1.8, §3.1, §3.3, handoff §17 | **Step 4, planned 2026-09-20:** it splits into **4a** (customer accounts: registration, email verification, phone, profile) and **4b** (customer sign-in, sessions, password reset, guests) — nine build steps in all. (a) A customer's sign-in limit per IP address is **10 wrong passwords in 15 minutes**, as for staff; it is a per-store setting, so a store whose customers share one mobile address can raise it without a deploy. (b) The **terms and privacy version is per store** (each store is its own market and law); the accepted version recorded at registration is that store's. (c) A customer's own **account events are audited** — registration, email and phone verification, phone and password changes, blocking, deletion — with personal fields only as "changed" and no IP address (Platform keeps those for staff only); sign-ins and browsing are not audited. | Step 3b grew too large to review in one piece. The spec named no customer IP number. Three countries cannot share one legal text. Support must be able to answer "when did this change?" without the log growing with shopping traffic. | Owner, 2026-09-20 |
| 38 | §1.2, §3.1 | **The email verification link proves itself** (owner, 2026-09-20): whoever opens it verifies that address, signed in or not, under a new guest permission `access.account.verify_email` — §3.1's row named "every customer", which would stop a customer opening the link on a phone where they are not signed in. Verifying a **phone** stays with the customer (`access.account.verify`). Step 4a therefore also builds the one storefront route the link points at (`{store}/{locale}/account/verify-email/{customer}`, signed, 24 hours), so registering and verifying work end to end; every other customer endpoint waits for 4b. | A link sent to that address is proof of it, as the staff invitation link already is; otherwise a customer who clicks it on a device where they are not signed in is stopped, and often never comes back. | Owner, 2026-09-20 |
| 39 | §1.2, §1.7, §1.8, §3.1 | **Choices made while building step 4b** (owner, 2026-09-20): (a) **registering signs the customer in at once** — no second form — and the session then follows the ordinary rules; (b) the **guest cookie lasts a year** (`ACCESS_GUEST_COOKIE_DAYS`, 365), so a cart left for a while is still theirs when they come back; Access only reads that cookie, Sales writes it with the first cart line; (c) an email that belongs to a **staff** account is refused at registration with exactly the same words as a customer's ("You already have an account — please sign in"), so nobody can find staff addresses by trying them; (d) the storefront session lives in the site's own cookie, and its cookie and row last as long as the longest "remember me" any store may set (`ACCESS_STOREFRONT_SESSION_DAYS`, **365 days**, applied by `UseStorefrontSession` before the `web` group) — `SESSION_LIFETIME` stays only as the framework's fallback, and Access ends the session sooner by its own limits: the store's idle minutes (2 hours) or its remembered days (30); (e) a customer's lockout counts on its **own keys**, apart from staff's, so a shop's busy address never makes the admin panel wait, or the other way round. | The spec left these open. A customer who has just proved an email and chosen a password should not type them again. A session framework cannot decide when Access ends a session; it must only be wide enough not to end it first. | Owner, 2026-09-20 |
| 40 | §1.2, §1.8, §5.6, §7 | **From the independent reviews of step 4b** (owner, 2026-09-20): (a) the admin panel keeps its sessions in **its own table**, `access.admin_sessions` — Laravel deletes old session rows using the lifetime of whichever request happens to sweep, so one table let an admin request (twelve hours) end a customer's remembered session (thirty days); (b) a customer who is signed in and opens their **reset link, or the forgot-password form, is signed out of that browser first**, then the reset goes on — the rule staff links already follow (amendment 31); someone who remembers their password changes it in their account settings instead, which keeps the session it was changed from, and registering or signing in while already signed in simply lands them in the store; (c) registrations and reset requests are limited to **10 an hour from one address** (`access.customer.address_requests_per_hour`, 1–100, per store), a new error `TooManyRequests` — the sign-in limits count wrong passwords, which these forms never have; (d) asking for a **verification link again keeps sharing** the reset link's hourly setting (`access.customer.password_reset_hourly_limit`): one "emails to one account an hour" number covers both. Also fixed, with no visible change: signing in read the account before checking the password and wrote the whole row back afterwards, which could undo a reset, a block or a verification that landed in between; the session is started only once the change is committed; the verification link's own page now keeps the storefront session, so opening it no longer ended a remembered one. | An attacker who knows a password could have reverted a password reset; "remember me" would have lasted hours, not thirty days; the public forms could be sent thousands of times from one machine. | Owner, 2026-09-20 |
