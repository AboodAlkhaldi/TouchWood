# Access module

**Build stage 2. Depends on Platform.** Every module above it depends on it.

Access answers **who someone is and what they may do**: customer and staff accounts, roles and
permissions, sign-in and sessions, addresses, and account deletion. The rules are in the approved
specification, [docs/modules/access.md](../../../docs/modules/access.md). This file explains how the
code is organised and why, and grows with each build step.

**Built so far: step 1 of 7 — the foundation.** Nothing signs in yet: until step 3, Platform's
interim bindings still let only the system act.

---

## Using Access from another module

Import only `Modules\Access\Public\**` (Deptrac refuses anything else).

```php
// Declare every permission your module checks, once, in your service provider's boot().
$this->app->make(PermissionCatalog::class)->declare('catalog',
    new PermissionDefinitionDto('catalog.product.update'),                                   // through a staff role
    new PermissionDefinitionDto('catalog.brand.delete', reserved: true),                     // Super Admins only
    new PermissionDefinitionDto('catalog.review.write', PermissionAudience::EveryCustomer),  // every customer, own data
);
```

Name each permission in Arabic and English in your module's translations, at
`{module}::permissions.{resource}.{action}` — e.g. `catalog::permissions.product.update`. A test
fails if a handler checks a permission nobody declared, or if a permission has no name in either
language.

---

## What is inside (so far)

| Folder | Contents |
|---|---|
| `Public/` | `PermissionCatalog`, `PermissionDefinitionDto`, `PermissionAudience`. |
| `Application/Permission` | `InMemoryPermissionCatalog` (the declared permissions, what the role editor offers, what each kind of person holds automatically), `AccessPermissions` (Access's own list), `InvalidPermissionDefinition`. |
| `Infrastructure/` | `AccessServiceProvider`; the migration that creates the `access` schema. |
| `Presentation/lang` | The names of Access's permissions, in Arabic and English. |

---

## Approaches and why

### One catalog of permissions, declared by the module that checks them

Every command handler asserts a permission (handoff §19). A role may only hold a declared permission,
so the catalog is the list the role editor offers. Each permission has an **audience**:

| Audience | Held by |
|---|---|
| `ROLE` | Staff, only through their role — the only kind in the role editor |
| `EVERY_STAFF` | Every active staff member, automatically, for their own account |
| `EVERY_CUSTOMER` | Every active customer, automatically, for their own data |
| `EVERY_GUEST` | Every visitor not signed in, automatically |

A **reserved** permission (creating a store, managing Super Admins) exists so its handler can assert
it, but only Super Admins and the system hold it; it is never offered in a role.

**Platform sits below Access**, so it cannot call the catalog. It publishes its list in
`Modules\Platform\Public\PlatformPermissions` (with each permission's reserved flag), its handlers use
those constants, and `AccessServiceProvider` declares the list.

**Names come from translations**, read only when a screen shows them, so declaring permissions costs
nothing on an ordinary request (spec amendment 1, awaiting the owner).

---

## How it was built

| Step | What |
|---|---|
| 1 | Foundation: the `access` schema, the service provider, the permission catalog with Access's and Platform's permissions |
