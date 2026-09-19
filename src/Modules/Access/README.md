# Access module

**Build stage 2. Depends on Platform.** Every module above it depends on it.

Access answers **who someone is and what they may do**: customer and staff accounts, roles and
permissions, sign-in and sessions, addresses, and account deletion. The rules are in the approved
specification, [docs/modules/access.md](../../../docs/modules/access.md). This file explains how the
code is organised and why, and grows with each build step.

**Built so far: steps 1–2 of 7 — the permission catalog, roles, and the real permission check.**
Nobody signs in yet: until step 3 Platform's interim `ActorContext` still reports the system, so in
the running application only console commands and jobs act.

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

---

## What is inside

| Folder | Contents |
|---|---|
| `Public/` | `PermissionCatalog`, `PermissionDefinitionDto`; enums `PermissionAudience`, `PermissionKind`, `AccessLevel`, `StaffStatus`. |
| `Domain/Model` | `Role` (saved or personal, admin or staff level, at least one action), `RoleAssignment` (a staff member's one role, their store row, and each action's own stores), `StaffUser` (read-only in step 2). |
| `Domain/ValueObject` | `RoleName`, `StoreChoice` (all stores, or at least one chosen store), `RoleKind`, `RoleLevel`. |
| `Domain/Exception` | `AccessError` and its subclasses, with messages in `Presentation/lang/{ar,en}/errors.php`. |
| `Application/Permission` | `InMemoryPermissionCatalog` (declarations, renames, removals, checked at boot), `AccessPermissions` (Access's list, and which actions are admin-only). |
| `Application/Authorization` | `RoleAuthorizer` (the real `Authorizer`), `GrantRules` (who may grant what to whom), `StaffGrants` + `GrantsReader` (a staff member's permissions, cached), `Author`. |
| `Application/Command` | `CreateRole`, `CloneRole`, `UpdateRole`, `DeleteRole`, `ChangeStaffRole`, `RefreshStaffPermissions`, `RefreshRolePermissions`. |
| `Application/Query` | `ListRoles`, `ViewRole`, `RoleEditorPermissions`, `MyPermissions`, and the `RoleReader` they use. |
| `Application/Audit` | `RoleAudit`: every audit entry for roles and assignments. |
| `Infrastructure/Eloquent` | Query-builder repositories, `CachedGrantsReader`, `DatabaseRoleReader`. |
| `Infrastructure/Permission` | `PermissionSync`: carries renames and removals into the roles on every migrate. |
| `Infrastructure/Persistence` | Migrations: the schema, `staff_users` (identity and status only; the rest comes with sign-in), the role tables. |

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
manages a staff member only when covering **all** of their stores — a staff member's stores being
their store row plus any store an exception adds.

### One role per staff member, stores per staff member

A role holds only actions. The stores sit on the assignment: one store row for every action, and
any action may have its own stores for that person (an exception). A **saved** role is shared —
editing it changes it for everyone who holds it, so only an author covering every holder may edit
it. Editing a staff member's role from their page makes their **personal** role, edited in place
from then on, and deleted when they move back to a saved role.

### Nobody grants more than they hold

`GrantRules` checks every change: a role holds only actions its author holds; each action reaches
only stores where its author holds it; a store-free action only needs holding. The system and Super
Admins are unlimited. "Every store" means the **All stores** choice — ticking every store one by one
is not enough, because a global change also reaches stores opened later.

### The check, and its cache

`RoleAuthorizer` denies by default. A staff member's permissions are built from four tables, so
they are cached per staff member with Shared's `VersionedCache` (1 hour at most, as a safety net).
Every change replaces the cached copy **inside its transaction**, and admins can also rebuild it by
hand (`RefreshStaffPermissions`, `RefreshRolePermissions`). A warm check reads only the cache table.
The cache holds plain arrays, because `config/cache.php` refuses to rebuild objects.

**Until step 3** the interim `ActorContext` reports the system for a web request too, so
`RoleAuthorizer` lets the system act only outside web requests — exactly what Platform's interim
authorizer did. Customers act from step 4; integrations hold nothing yet.

### Renamed and removed permissions

`PermissionSync` runs at the end of every `php artisan migrate` — on `MigrationsEnded`, and on
`NoPendingMigrations` because Laravel fires that one instead when there is nothing to migrate. It
moves renamed names (and their exceptions' stores) and takes removed names out, auditing each
change. A name that is neither declared nor removed is left alone and logged: it grants nothing,
and a module switched off by mistake cannot wipe anyone's roles.

### The database is the last line of defence

Every CHECK, unique index and foreign key is enforced first in code, with a test that the code
refuses first (`AccessSchemaTest` checks the database refuses too). A CHECK that evaluates to NULL
passes, so the role-name rule is wrapped in `COALESCE(…, false)` — the schema test caught that.

---

## How it was built

| Step | What |
|---|---|
| 1 | Foundation: the `access` schema, the service provider, the permission catalog with Access's and Platform's permissions |
| 2 | Roles, assignments and exceptions; the real authorizer and its cache; the three levels; renamed and removed permissions; the role reads. `VersionedCache` moved to Shared; Platform's interim authorizer removed |
