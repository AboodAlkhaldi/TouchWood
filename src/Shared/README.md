# Shared kernel

The small set of types every module needs. A module may import `Shared\**` and other modules'
`Public\**` — nothing else (enforced by Deptrac).

**Rule of thumb:** a type belongs here only if three or more modules need it and it will
essentially never change. When in doubt, keep it in the module. The ceiling is ~20 classes.

## What is here

| Type | Layer | What it is for |
|---|---|---|
| `Money`, `MoneyException` | Domain | An amount in minor units (halalas, piastres, fils) plus a currency code. Exact arithmetic through `brick/math`: `multiply()` takes an integer or decimal string and an explicit rounding mode, `allocate()` splits without losing a unit, parsing rejects extra decimals instead of rounding, and overflow throws instead of becoming a float. It never knows how many decimals a currency has: that exponent is passed in from the `currencies` row where parsing or formatting needs it. |
| `StoreId` | Domain | A store's ULID, kept lowercase. |
| `DomainError`, `ErrorCategory` | Domain | The base of every expected business error. Each error declares a stable `type` (`platform.store_code_taken`) and a category (`NOT_FOUND`, `CONFLICT`…). Domain code never knows HTTP. |
| `StoreContext`, `MissingStoreContext` | Application | Which store the current request, job or command runs in. Implemented by Platform. |
| `Authorizer`, `PermissionScope`, `Unauthorized` | Application | `authorize($permission, $scope)`, where the scope is `global()`, `store($id)` or `allStores()` — an empty store used to mean both "no store" and "every store". `storesWith($permission)` answers "which stores may I do this in" for admin lists. Every command handler authorizes first. Implemented by Access (an interim system-only version lives in Platform until then). |
| `ActorContext`, `Actor`, `ActorType` | Application | Who is acting: staff, customer, guest, integration or system. Every id is a ULID. Inside a queued job the actor is the system with `requestedBy` set to whoever queued it. |
| `CrossStoreWrite` | Application | Thrown when code tries to write another store's row. |
| `ProblemDetails` | Infrastructure | The only place a `DomainError` becomes an HTTP response. |
| `AssignCorrelationId` | Infrastructure | Gives every request an id that follows it into logs and queued jobs. |
| `BelongsToStore`, `StoreScope` | Infrastructure | The Eloquent trait for store-scoped models. |

## How the pieces work

### Errors

```
Domain:          throw new StoreCodeTaken('sa');        // category CONFLICT, type platform.store_code_taken
                          ↓
ProblemDetails:  CONFLICT → 409, title/detail translated from platform::errors.store_code_taken
                          ↓
JSON response:   { type, title, status, detail, correlation_id }   (RFC 7807)
```

- A module owns its errors (`Modules\{Name}\Domain\Exception\*`, all extending the module's own
  base, which extends `DomainError`) and their messages (`Presentation/lang/{ar,en}/errors.php`).
- Anything that is not a `DomainError` is a bug: it is reported with the correlation id and the
  caller gets a generic 500 that reveals nothing.
- Page requests get the error page for the matching status instead of JSON.

### Store scoping

A model that uses `BelongsToStore`:

- reads, updates and deletes only the current store's rows;
- stamps new rows with the current store and refuses a row for another store;
- refuses to change a row's `store_id`, or to save or delete another store's row;
- throws `MissingStoreContext` when no store is set — it never falls back to every store.

`Model::query()->acrossStores()` is the only opt-out, allowed in `Application/Query` read
models and the Ops module (an architecture test checks where it appears). Raw `insert()` and
`upsert()` bypass model events, so create store-scoped rows through the model.

### Correlation id

Set by `AssignCorrelationId` (an incoming well-formed `X-Correlation-Id` is kept), stored in
Laravel's `Context`, returned as a response header, included in error bodies and audit entries,
and carried into every queued job.

## Tests

`tests/Shared/{Unit,Integration,Feature}`. The store scope is tested against a real PostgreSQL
table; the error envelope in both Arabic and English.
