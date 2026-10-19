# Sync — module specification

**Status (2026-10-13): accepted by the owner** (§9.2, with points 10–16). Sync reads an external provider — Odoo is
the one wanted — **one way, per store**: each size's **base (retail) price** and **stock**, by product
code. It is built from handoff §12.2 (rewritten 2026-10-01, narrowed 2026-10-13), the changelog row of
2026-10-07 (handoff §0.1), inventory.md and pricing.md, and the owner's answers of 2026-10-13 (§9.2).
Everything on our side is built against a **pretend provider** in the tests; only the adapter that
calls Odoo waits for the provider's team (§9.1).

## What Sync does not own

| Thing | Owner |
|---|---|
| Retail prices, sales, the ceiling, and refusing staff edits of a provider's price | Pricing — Sync writes through its public contract (§2.3) |
| Stock, holds, the ledger, orderable and "last pieces" | Inventory — Sync writes through its public contract (§2.3) |
| Product codes, sizes, whether a size is switched on, archived or a draft | Catalog — Sync reads it |
| Stores, settings, the audit log, menu counts, the scheduler, encryption with the app's key | Platform |
| Email and SMS alerts | Ops (stage 8) |
| What the customer's order page and receipt show (base price and price after discount) | Sales (stage 6; owner, 2026-10-13) |

---

## 1 · Rules

### 1.1 The rules

1. **One way: provider → us.** We read **the base price and the stock** of each size, nothing else —
   not the provider's discounts (owner, 2026-10-13). **We never write to the provider**: not stock,
   not prices, not orders (handoff §12.2).
2. **Per store, isolated.** A store is **wired** when it has a connection (§1.2); wiring one store
   changes nothing in another, and every wired store behaves the same way. A store with no connection
   has Sync dormant.
3. **Only a Super Admin** wires a store, changes its key or removes the wiring (handoff §12.2).
4. **No call to the provider ever happens inside a web request**: every read is a queued job (handoff
   §12.2). A notification from the provider (§1.3) only queues a read. The one exception: the Super
   Admin's test when saving a connection, 10 seconds at most (§1.2; §9.3 #21).
5. **Matched by product code** — one per size, the same in both systems (catalog.md §1.2: digits only,
   1–10 of them). A code is compared **exactly as written, spaces around it trimmed**.
6. **The provider's values are read-only in our panel** while the store is wired: Pricing refuses a
   hand price (`pricing.provider_owns_price`), Inventory a hand stock change
   (`inventory.provider_owns_stock`). The store's file's prices and stock are ignored there, with a
   warning (pricing.md §1.8, inventory.md §1.8).
7. **Nothing is rolled back.** A bad value skips that one size and is reported; the rest is applied
   (handoff §12.2).
8. **A wired store that is switched off is still read**, so it is right the moment it is switched on
   (follows from the owner's answer on off sizes, 2026-10-13 #4).

### 1.2 Wiring a store

A Super Admin opens a store's **Provider** page and enters: the **provider** (Odoo, the one adapter),
its **address**, the **key**, the **notification secret** (§1.3), and the fields the adapter asks for
(which wait for the provider's team, §9.1). The key and the secret are **kept encrypted in our
database with the app's key** (owner, 2026-10-07 and 2026-10-13) and **never shown again** — the page
shows only that they are set and when; a new value replaces the old one.

- **Saving tests the connection first**: one small read. If it fails, nothing is saved and the reason
  is shown (`sync.connection_failed`). *(§9.2 #10)*
- Once saved, the store is wired **at that second**, and **a full read is queued at once** (§1.3). The
  provider's prices replace the store's own, and its stock numbers replace ours through the ledger.
- **Changing the key or the secret** is the same form, tested the same way.

### 1.3 When we read

(Owner, 2026-10-13.)

| Read | What | When |
|---|---|---|
| **Notified** | The codes the provider names | **The provider calls us when a price or a stock number changes.** The call only names codes; we then read those codes' values from the provider's API, so a forged call can only make us read, never set a value |
| **Hourly** | What changed since the last read — or everything, if the provider cannot say *(§9.1 #7)* | Every hour, per wired store: catches a lost notification within the hour |
| **Nightly** | Everything | Once a night per wired store: the full check, and the only read that finds **our sizes the provider does not know** (§1.6) |
| **Sync now** | Everything | A button on the Sync report (§1.6), queued |
| **One code** | One size's code | When staff correct a size's code (`VariantCodeCorrected`, catalog.md §6.1) — the size is matched again at once |

**The notification** is an HTTPS `POST` to our address for that store, its body a list of up to 1,000
codes, **signed with the store's notification secret** (an HMAC-SHA256 of the timestamp and the body,
in headers) and refused if the signature is wrong or the timestamp more than 5 minutes off. A good
call is answered `202` at once and queues a Notified read. Too many calls from one store are limited
(60 a minute), and from one address (600 a minute, whatever store it names); the hourly read covers
what is refused. A notice's codes join the one Notified read waiting, up to 5,000 codes; past them, one
full read of the store is queued instead (owner, 2026-10-18, §9.3 #27).

**One read per store at a time.** A read asked for while one runs waits for it; asking twice queues
one more read, never two.

### 1.4 Prices in

For each code the provider sends with a price (owner, 2026-10-13):

1. **A code we do not have** → reported (`UNKNOWN_CODE`), **never created** (handoff §12.2).
2. **An archived size, or a size of an archived product** → **skipped, reported** (`ARCHIVED_SKIPPED`):
   it stays read-only until restored, as in Pricing (owner, 2026-10-13 #2).
3. **A price that is not one** — 0 or below, more decimals than the store's currency has — →
   skipped, reported (`BAD_VALUE`); the size keeps its price. The provider sends no currency: every
   price is taken in the store's own (owner, 2026-10-18, §9.3 #26). *(VAT: §9.1 #5.)*
4. Otherwise **the size's retail price becomes the provider's** (source `PROVIDER`), audited in the
   price history. A size **switched off in the store, or of a draft product**, takes it too and **stays
   as it is** — off, or a draft — and is **listed** (`OFF_OR_DRAFT_PRICED`) so staff switch it on with
   the new price or leave it (owner, 2026-10-13 #4).
5. **A price that takes a running or scheduled sale past the store's ceiling is taken**, and **the
   sale stops on that size**; it is reported (`SALE_STOPPED`) (owner, 2026-10-13 #2: "go with 2 and
   report too"). The size leaves the sale; a sale left with no size ends if running, or is removed if
   not started — audited as Sync's. *(§9.2 #16)*
6. **A price that takes a running or scheduled fixed-amount category discount past the ceiling is
   taken**, and **the discount skips that size** while it would be too deep, reaching it again once
   the price allows; reported (`DISCOUNT_SKIPPED`). *(§9.3 #17, owner, 2026-10-17)*

An unchanged price changes nothing and is not audited.

### 1.5 Stock in

For each code the provider sends with a stock number:

1. Unknown, archived → reported, as for prices (§1.4 #1–2).
2. **A number that is not a whole number** → skipped, reported (`BAD_VALUE`). **Below 0** → kept as
   **0**, reported (`BAD_VALUE`). *(§9.2 #11)*
3. Otherwise Inventory records it (inventory.md §1.1 rule 3, §1.9): **the same number as last time
   changes nothing**; a different one is a `PROVIDER` movement to the new value, with a reference
   unique to that read and size, so a retried read is counted once — and a stock-dependent size's
   returned extra goes back to 0. Orderable and "last pieces" are pushed to Catalog as with any change.
   An off or draft size takes it too, as for prices.

### 1.6 The Sync report

**One page per wired store**, for staff given **View the Sync Report** in that store; **a count beside
its menu item** — the open entries — like Low Stock (owner, 2026-10-13 #3). Each entry names the size
and product (or the provider's code), what happened, and when:

| Kind | Opened when | Closed when |
|---|---|---|
| `UNKNOWN_CODE` | The provider sent a code we do not have | A read matches it (the size was made), or staff dismiss it |
| `NOT_IN_PROVIDER` | The nightly read did not find one of our sizes switched on in the store | A read finds it, or staff dismiss it |
| `ARCHIVED_SKIPPED` | A value for an archived size was skipped | The size is restored and read, or staff dismiss it |
| `OFF_OR_DRAFT_PRICED` | An off or draft size took a new price | Staff switch it on (Catalog) or press **Leave it** |
| `SALE_STOPPED` | The provider's price stopped a sale on a size (§1.4 #5) | Staff dismiss it (seen) |
| `BAD_VALUE` | A value was skipped or kept as 0 | A good value arrives, or staff dismiss it |
| `READ_FAILED` | A read of the store failed after its retries (§1.7) | A later read of the store succeeds |
| `DISCOUNT_SKIPPED` | The provider's price made a category discount skip a size (§1.4 #6; §9.3 #17) | The discount reaches the size again - at once, whatever brought it back: the ceiling raised, the discount changed or ended, the provider's price lowered (Pricing's `DiscountReachChecked`, closed by `PRICING`; §9.3 #28) - or staff dismiss it |

`SALE_STOPPED`, `DISCOUNT_SKIPPED` and `READ_FAILED` also send a panel notification to staff with
View the Sync Report in that store (§9.3 #18). **The page of the store's reads**, each opening on what
it changed and reported: §9.3 #19.

One entry per kind and size stays open; the same thing again updates its time. **Retry** on an entry
reads that code again; **Sync now** reads the store. Closed entries and the log of reads are **kept 90
days**, then deleted by a nightly job (owner, 2026-10-13); the price and stock histories are kept for
good, as always. **One audit entry per read**, the detail being in those histories (handoff changelog,
2026-10-07).

### 1.7 When a read fails

- **The connection fails** (refused, timed out, the key rejected, the provider's error): the read is
  **retried 3 times** — after 1, 5 and 15 minutes — then the store is marked **failing**, a
  `READ_FAILED` entry opens, and the next scheduled read tries again. Nothing read so far is undone.
- **One code fails** (§1.4, §1.5): that code is skipped and reported; the read goes on.
- A read is never left half-recorded: each batch of sizes is written in its own transaction, so a
  crash keeps what was written and the next read finishes the rest.
- **A read's values are gathered from the provider first, then written in batches of 500**, each in its
  own transaction (so a code that comes twice is known before anything is written, §2.4): a failure while
  fetching writes nothing from that attempt, and the retry reads again (screens step 6's review, 2026-10-19).
- Bulk work is batched — never one job per size (handoff §12.2).

### 1.8 Unwiring a store

A Super Admin removes the wiring (owner, 2026-10-13 #3): the key and the secret are deleted, and **from
that second the store is not wired** — staff own its prices and stock. **Nothing is cleared**: the last
prices and stock stay as they are, now editable by hand.

- Inventory works out every size's orderability again for a store with no provider, where **every
  product counts on its stock** (inventory.md §1.1 rule 2) — so an ordinary product the provider left
  at 0 stops being orderable. **The Super Admin is shown how many sizes will stop before confirming.**
- A stock-dependent size's returned extra is added into its stock. *(§9.2 #12)*
- The report's entries are closed; reads queued for the store are dropped.

---

## 2 · Contracts

### 2.1 The provider's adapter (Sync's own)

`Modules\Sync\Domain\Provider\ProviderAdapter` — one per provider, Odoo the first:

- `test(Connection): void` — one small read; throws with the reason.
- `read(Connection, ?list<string> $codes, ?DateTimeImmutable $changedSince): iterable<ProviderValue>`
  — page by page; `ProviderValue(code, ?price as a decimal string in the store's currency, ?stock as
  a number)`. A `ConnectionFailed`'s reason is shown to staff: a short sentence (what failed, a status
  code), never the provider's response or an address with a key in it.

`OdooAdapter` waits for the custom API's spec (§2.4, the owner's answers of 2026-10-19: §9.1); `FakeProvider` serves the tests.

### 2.2 What Sync gives other modules

Nothing new for Sales or Catalog. Sync **binds** the wiring question Pricing and Inventory already ask
(§2.3) and registers its menu count with Platform.

### 2.3 What Sync needs from other modules

| From | What | State |
|---|---|---|
| Pricing | **`StoreWiring::isWired(StoreId)`** moved to Pricing's **Public** contracts, so Sync can answer it (bound to "never wired" until Sync binds its own) | Moved with Sync step 2a (2026-10-17) |
| Pricing | **`ProviderPrices::apply(StoreId, array<variantId, mixed>, string $readRef): ProviderPricesResult`** — the provider's base price as it came (text in the store's currency; anything else named invalid), set with source `PROVIDER`; archived sizes skipped and named; a sale the new price takes past the ceiling (§1.4 #5) stopped on that size and named; a fixed-amount category discount skips the size (§1.4 #6); under the store's pricing lock | Built with Sync step 2 (2026-10-17): pricing.md §2.6 - the price passed as the provider wrote it, a decimal checked by the store's rules; a fixed-amount category discount skips the size (§9.3 #17) |
| Inventory | **`StoreWiring::isWired(StoreId)`** moved to Inventory's **Public** contracts, as for Pricing | Moved with Sync step 2a (2026-10-17) |
| Inventory | **`ProviderStock::apply(StoreId, array<variantId, int>, string $readRef): ProviderStockResult`** — §1.5 #3 · **`ProviderStock::unwired(StoreId): int`** — §1.8, returns how many sizes stopped being orderable (with a dry-run read for the confirm page) | Built with Sync step 2a (2026-10-17): inventory.md §2.5, the dry-run read `stopsWhenUnwired`, and **`ProviderStock::wired(StoreId)`**, which Sync calls once a store is wired so Catalog is told every size as a wired store's |
| Catalog | `variantByCode(string)` (archived ones too), **`variantsByCodes(list)`** (many at once - catalog.md amendment 21, for the reads' batches), `variants()`, `products()`, `switchedOnVariantIds(StoreId)`; the `VariantCodeCorrected` event | On `main` |
| Platform | Stores; encryption with the app's key; the audit log; `MenuCount`; the scheduler | On `main` |
| Access | Declaring Sync's permissions (handoff §4.4; `deptrac.yaml`) | Allowed |

### 2.4 The Odoo API built for us (accepted by the owner, 2026-10-19)

The provider's team builds this inside each store's Odoo account, to this text (owner, 2026-10-19:
"a custom API built for us … they follow it"; §9.1). **It only reads**: every call is a `GET`, so it can
change nothing in Odoo. Each store is its own account, with its own address and token (§9.1 #2).

**Calls - ours to theirs**

| | |
|---|---|
| Address | The store's base address, typed by a Super Admin on the Provider page (§1.2), ending in `/touchwood/v1` - `https://<the account's address>/touchwood/v1`. HTTPS only |
| Sign-in | Header `Authorization: Bearer <token>` - the store's token, typed with the address. One token per account, never shared (§9.1 #2) |
| Pace | **One call at a time per store, at least 1 second apart** - within Odoo's policy of about 1 call a second, none in parallel (§9.1 #8). Each call waits **30 seconds** at most |

1. **Every item** - `GET /items?limit=1000&after=<next>`: the account's items, **ordered by code**,
   `limit` at most 1,000 (about 700 today: one call). The nightly read, Sync now, a store just wired.
2. **What changed** - `GET /items?changed_since=<time>&limit=1000&after=<next>`: the items whose price or
   available stock changed at or after `<time>` (UTC, `2026-10-19T12:00:00Z`). The hourly read. We pass
   the start of our last read **less 5 minutes** - the most their clock may be off (the notice's rule) -
   so a change at the edge is read twice, never missed (a value read twice changes nothing, §1.4, §1.5).
3. **Named codes** - `GET /items?codes=123,1234,…`: up to **500 codes** a call (a longer list is asked
   in several calls). The notified read, a corrected code (§1.3). Codes not in the account are listed in
   `missing`.
4. **The test** - `GET /items?limit=1`: when a Super Admin saves the connection (§1.2, §9.2 #10).

**The answer** - `200`, JSON:

```json
{
  "items": [
    { "code": "123",  "price": "120.00", "available": 7 },
    { "code": "1234", "price": "85.50",  "available": 0 }
  ],
  "missing": [],
  "next": null
}
```

| Field | Rule |
|---|---|
| `code` | **Text**, exactly as kept in Odoo (`"123"`, never the number `123`); **one code per variant, never two variants with one code** in an account (§9.1 #4) |
| `price` | **Text**, the variant's **base selling price** in the store's currency, with the currency's decimals (`"120.00"`), **without VAT and without any discount** (§9.1 #5) |
| `available` | A **whole number**: the variant's stock **available to order** (§9.1 #6) |
| `missing` | Call 3 only: the asked codes the account does not have; `[]` otherwise |
| `next` | Text to pass as `after` for the next page; `null` on the last page |

Other fields are ignored. **A code twice in one read**: both skipped and reported (`BAD_VALUE`) (owner, 2026-10-19). Archived variants are left out of calls 1 and 2, and listed in `missing` by
call 3 - so our nightly read reports them as not known to the provider (§1.6).

**Errors** - `401` a wrong or missing token · `400` a bad parameter (`{"error": "<short reason>"}`) ·
`429` too many calls, with `Retry-After` in seconds · `5xx` their fault. Our side: a `429` waits `Retry-After`
(at most 60 seconds) and asks once more; anything else - or a second `429`, or a timeout - fails the
read, which is **retried after 1, 5 and 15 minutes**, then reported as failed until a later read
succeeds (§1.7); the reason shown is a short sentence with the status code (§2.1).

**Their notice - theirs to ours** (§1.3, accepted 2026-10-19, §9.1 #9): when a variant's price or
available stock changes, `POST https://<our address>/sync/notify/<store id>`, body `{"codes": ["123"]}`
(1–1,000 codes, as text), headers `X-TouchWood-Timestamp` (Unix seconds - the same number in every time
zone) and `X-TouchWood-Signature` (hex HMAC-SHA256 of `<timestamp>.<body>` with the store's notice
secret). Their server's **clock must be right** (synced): a notice more than 5 minutes off is refused.
Answers: `202` taken · `401` a bad signature or time · `422` not a list of codes · `429` too many (at
most 60 a minute per store), wait `Retry-After` and send again, or drop it - the hourly read covers it.

**What we ask of their team**: build the four calls and the notice to this text, in each account;
per store, the address and the token (to the Super Admin), and the notice secret agreed with us; a
clock kept right; codes unique per account; a contact for the go-live and for a read that fails.

---

## 3 · Use cases

| Use case | Permission | Scope |
|---|---|---|
| `WireStore` / `ChangeConnection` — the provider, address, key, secret and adapter fields; tested first | **`sync.connection.manage`** — reserved, held by Super Admins only (as `platform.store.switch` is) | That store |
| `UnwireStore` — §1.8, with the count first | `sync.connection.manage` | That store |
| The Sync report and its count (`ViewSyncReport`, `CountSyncReport`); the page of reads and each read's page (`ListSyncReads`, `ViewSyncRead`, §9.3 #19) | **`sync.report.view`** | That store |
| `RetryEntry` (one entry's code; a failed read's, the store) · `SyncNow` (the store) · `DismissEntry` / **Leave it** (never a failed read's, §9.3 #23) | **`sync.read.run`** | That store |
| `ReceiveNotification` — the provider's call (§1.3) | The signature, no staff | That store |
| `ReadStore` (Notified, Hourly, Nightly, Now, One code) · `PruneSyncLog` | System (reserved): `sync.read.scheduled`, `sync.log.prune` | — |

The permissions sit in a new **Sync** group in the role editor (§9.2 #13). Every change of a connection is audited (never
the key or the secret themselves), in both languages.

---

## 4 · State machines

**A store's connection:** `NOT WIRED` → (WireStore, test passes) → `OK` ⇄ `FAILING` (a read fails
after its retries / a read succeeds) → (UnwireStore) → `NOT WIRED`.

**A read:** `QUEUED` → `RUNNING` → `DONE` | `FAILED` (after its retries).

**A report entry:** `OPEN` → `CLOSED` (by a read, by staff, by unwiring).

### 4.1 The admin screens (stage 5's screens)

**Written 2026-10-18, detailed 2026-10-19**, with Pricing's and Inventory's, on the owner's answers (a Sync
section in the menu; the Sync Report and the Provider page; every detail settled before building; S1-S8
accepted). Every **(Qn)** is answered (owner, 2026-10-19; pricing.md §9.4). **Everything pricing.md §4.3.1 says holds here.**

#### 4.1.1 What every Sync screen keeps

- **Kinds' badges** (§1.6): **Unknown Code** gray · **Not at Provider** gray · **Archived** gray · **Off or
  Draft** gray · **Offer Stopped** amber ("Offer": pricing.md §4.3.1) · **Bad Value** gray · **Read Failed** red · **Discount Skipped**
  amber.
- **Reads**: kinds **Notice**, **Hourly**, **Nightly**, **Now**, **One Code**; states **Waiting** gray,
  **Running** blue, **Done** green, **Failed** red. A store: **Working** green, **Failing** red, **Not Wired**
  gray.
- **A failure's text (Q31)**: Sync's own reasons are kept as **a code** and written in the page's language -
  "Stopped: the store was unwired or wired again." · "Stopped: its worker went away." · "The store was
  unwired." · "Superseded: a read of the same kind is waiting." · "Failed: see the failed jobs screen." - and
  **the provider's own reason** is shown as it came, under "The provider said:", in `dir="auto"`.
- **Toasts**: "Read queued" · "Entry dismissed" · "Store wired" · "Connection changed" · "Store unwired".
- **Words**: `src/Modules/Sync/Presentation/lang/{ar,en}/admin_report.php`, `admin_provider.php`,
  `menu.php` (Q42). §1.6's and §3's "Leave it" becomes **Dismiss** (Q28).

#### 4.1.2 Where they are

`Presentation/admin-routes.php`, `sync.admin.*` (Ziggy, deptrac `AppHttp`); pages under
`resources/js/pages/Sync/Admin/`. **The menu** (S6): Platform's `AdminMenu`, a **Sync** group after
Pricing, icon `sync` (Lucide's `RefreshCw`):

| # | Entry | Address | Offered for |
|---|---|---|---|
| 10 | Sync Report · تقرير المزامنة | `/admin/sync` | `sync.report.view`, with **`SyncReportCount`** (new `MenuCount`: the open entries over the stores the reader views, one query) |
| 20 | Provider · المزوّد | `/admin/sync/provider` | `sync.connection.manage` (Super Admins) |

#### 4.1.3 The reads

| Query | Answers |
|---|---|
| `SyncStatus` (new, S8; `sync.report.view`) | the provider's name, the state with its reason, **Last Read** - the newest read that finished successfully, of any kind (owner, 2026-10-19: option A - a failed read shows as Failing with its reason, never as the last read) - and **Last Full Read** (Q29), a read waiting or running (its kind and start) |
| `ViewSyncReport` (extended) | each entry's size values; for a stopped sale or a skipped discount its description and whether it still exists (Pricing's `salesNamed`, `discountsNamed`) (Q37) |
| `ListSyncReads` (extended) | who asked, by name (Platform's `StaffNames`) (Q36); a failed read's changes counted from its lines (Q30) |
| `ViewSyncRead` (extended) | each section paged on its own (`?section=&page=`), the codes asked for as a section, 100 a page (Q32); the read's store code |
| `ViewConnection` (extended, S7) | the last full read; the notice address and what the provider must send |
| `SyncActionsForReader` (new) | per store: may view, may run, may wire; whether a Now read waits; `mayViewProducts`; the reasons |

**Built (stage 5's screens step 6, 2026-10-19)** - the reads as Application queries answering page-facing
views, every moment ISO 8601, a price written as Pricing writes money; the routes (`sync.admin.*`), their
controllers and page data, the menu's two entries and the words; the pages are placeholders until step 7:

- `SyncStatus`: `WORKING`, `FAILING` (its reason) or `NOT_WIRED`; Last Read is the newest read **done**, by
  its end; Last Full Read the start of the last full read that worked (`connections.last_full_read_at`); the
  read running, else the first waiting.
- **Show More** pages by a cursor (the last row's time and id): Problems by when last seen, Reads by when
  asked. A cursor that is not one is refused (`after`). A read's sections page by number (`?page=`).
- **Q31**: Sync's own reasons are stored as codes - `STOPPED_REWIRED`, `WORKER_GONE`, `UNWIRED`, `SUPERSEDED`,
  `JOB_FAILED` - in the same columns as before (a migration turned the sentences into codes); anything
  else is the provider's words. **Q34**: a setting is refused as `settings` naming it (`rules.setting`), and
  the Provider form puts it under its own box (`errors["settings.<name>"]`).
- `SyncActionsForReader` also answers `mayViewOffers` (any of Pricing's three jobs), so a link to an offer
  or a discount is drawn only for whoever may open it (pricing.md §4.3.1).
- The provider's adapter answers `ready()` (Q38): Odoo's is false until §2.4's adapter is written.
- **A code twice in one read** (§2.4): the read's values are gathered by code before anything is written, so
  both are skipped however far apart they come; the size's `BAD_VALUE` keeps both (`detail.twice`). A code we
  do not have stays `UNKNOWN_CODE`, once (a `BAD_VALUE` names a size of ours, §5). The entry keeps the first five
  values sent and how many came (`count`).
- **The review's points (2026-10-19)**: an off store is refused (`StoreOff`, §7) in every read and action to
  whoever may not switch stores; Retry is refused on Offer Stopped and Off or Draft (`EntryNotRetryable`,
  §7; Q27); an entry about no size of ours (Unknown Code, Read Failed) says nothing of its size
  (`sizeExists` null); a failed read's audit entry keeps its code and, beside it, the English sentence
  (`reason`) - the audit log shows a module's values as recorded, never phrased. **The budget, in its
  worst case** (an offer stopped, a discount skipped, a bad value, an unknown code, a failed read, several
  askers): the page's sizes are read once and handed to Pricing's `salesNamed` (pricing.md §2.1), and every
  store's wiring and waiting Sync Now reads are one query - each page's own queries, for a reader of one store
  and for a Super Admin alike: Problems 12, Reads 5, a read 8, its Stock 8, its Reported 13, Provider 5. (The
  frame's Low Stock count, counted live until a store's count is kept, asks Sync's `StoreWiring` binding; it is
  the frame's, not the page's, and is left out as Inventory's own test leaves it out.)

#### Y1 · Sync Report - `/admin/sync?store=&tab=problems|reads&kind=`

- **Subtitle**: "What the provider's reads found, and every read of this store."
- **Main action**: **Sync Now** (`sync.read.run`, a wired store). Pressed while a read runs, it queues one
  more to follow; disabled only while a Now read already waits: "A read is already waiting." (Q26) →
  "Read queued".
- **The status line** (`SyncStatus`), above the tabs: the provider ("Odoo"), the state badge, "Last read 12
  minutes ago" - the newest read that finished successfully, of any kind (owner, 2026-10-19: option A - a
  failed read shows as Failing with its reason, never as the last read) - and "Last full read last night at
  02:00" (`Time`), and when one waits or runs: "A read is running, started 2 minutes ago." **Failing**: a red `Note` - "The provider said: …" - and "Press Sync Now
  once the provider is back."
- **A store not wired** (Q35): the status line **Not Wired** - "This store is not wired to a provider." - with
  **Wire Store** for a Super Admin (Y3); **no Problems tab**: the page shows Reads (kept 90 days),
  read-only; `?tab=problems` shows Reads.
- **Tabs** (in the address, nouns): **Problems** (its count in the trigger) · **Reads**.
- **Problems** (`ViewSyncReport`, 50 at a time, latest seen first): a kind filter (`NativeSelect`: **All
  Kinds**, then the eight, each with its count).
  - **Columns**: **Kind** · **What** - the product and size with its code, the product a link to its page's
    Stores tab (when the reader may view products); or the provider's code (mono) for one we don't have ·
    **Detail** · **First Seen** · **Last Seen** · ⋯.
  - **Detail per kind**: Unknown Code - the price and stock sent; Not at Provider - "The provider has no such
    code."; Archived - "Archived here; the provider still sends it."; Off or Draft - the price sent, "Make
    the product ready and switch the size on to sell it at this price."; Offer Stopped - the sale (a link to
    P2 `?sale=`; "Offer removed" when it is gone, Q37) and what the provider's price did, taking the size past
    the ceiling: **Left** "The size left the offer." / **Ended** "The offer ended." / **Removed** "The scheduled
    offer was removed."; Bad Value - what came ("0", "12.5", "−3") and what was kept; Read Failed - the read
    (a link to Y2) and its reason; Discount Skipped - the discount (linked) and "Past the ceiling at the
    provider's price."
  - **The ⋯ menu** (`sync.read.run`): **Retry Read** - reads that size's code again (a One Code read) →
    "Read queued" - for every kind but **Offer Stopped** and **Off or Draft**, which a read never closes; those
    offer **Open Offer** / **Open Product** instead (Q27); a **Read Failed** entry's Retry Read is a whole read
    of the store, as Sync Now. A size deleted since: Retry Read disabled, "This size no longer exists.
    Dismiss the entry instead." Separator; **Dismiss Entry…** (Q28) - every kind but Read Failed, where it is
    disabled: "Closes by itself when a read works." It asks "Dismiss Entry" · "It leaves the report, and
    comes back if a read finds it again." · **Dismiss Entry** → "Entry dismissed".
- **Reads** (`ListSyncReads`, 50 at a time, newest first): **Kind** · **State** · **Asked By** (a name; "The
  System"; a merged Notice or One Code read shows who asked first) · **Started** ("—" while waiting) ·
  **Took** ("2 min 14 s"; "—" until done) · **Prices** · **Stock** · **Skipped** (Q30) · **Codes** ("3"; "—"
  for a whole read). The row opens Y2.
- **Empty**: Problems - "No problems" · "The provider's last reads found nothing to look at." Reads - "No
  reads yet" · "Reads appear here once the store is wired."

#### Y2 · A read - `/admin/sync/reads/{id}?section=&page=`

- **Breadcrumb**: Sync Report › the store's code › "Read of 18 Oct 2026, 02:00" (store time).
- **Header** (`Description`): Kind · State · Asked By · Queued · Started · Finished · Took · the counts; a
  failed read's reason as Y1 writes it.
- **Sections as tabs** (Q32), each 100 a page with Show More: **Prices Changed** (Product · Size · Code ·
  From → To, money as Pricing's) · **Stock Changed** (the same, whole numbers) · **Reported** (as it was that
  day, §9.3 #22: Kind · What · Detail, as Y1's) · **Codes Asked** (a Notice or One Code read; mono).
- **Not there** - pruned after 90 days, another store's, or never one (§9.3 #29): the not-found page, "This
  read isn't available."

#### Y3 · Provider - `/admin/sync/provider?store=` (Super Admins)

- **Subtitle**: "Where this store's prices and stock come from."
- **Until the Odoo connection is built** (Q38): a `Note` - "The Odoo connection is being built. Wiring a
  store works once it is ready." The form is drawn; its test is refused with the adapter's reason.
- **A store not wired** - a `Card` **Wire Store**: **Provider** (`NativeSelect`: Odoo) · **Address** ("https://
  odoo.example.com"; as typed: "Address must start with https://." · "Address needs a host." · "Address
  can't hold spaces, a name or a password." · 500 characters) · **the provider's settings** - its own fields
  (none for Odoo yet), each refused by its own name (Q34) · **Key** and **Secret** (`PasswordInput`, 1,000
  characters). **Test and Wire Store** (main): `loading` "Testing…" while the test read runs (up to 10
  seconds); a failure under the form - "Failed to read the provider: <its reason>" - saves nothing; success
  → "Store wired", the page shows the wired store, a Now read is queued.
- **A wired store** - a `Description`: Provider · Address · its settings · **Key and Secret**: "Set" ·
  **Last Changed** (any change of the connection, Q33) · State (badge; the reason when failing) · Last Read
  · Last Full Read · **Notice Address** (S7): `https://…/sync/notify/<store id>` with a `CopyButton`, and
  under it what the provider sends - the headers `X-TouchWood-Timestamp` (unix seconds) and
  `X-TouchWood-Signature` (an HMAC-SHA256 of "timestamp.body" with the secret, hexadecimal) and the body
  `{"codes": [...]}`, 1 to 1,000 codes.
- **Change Connection** (main): the card turns into the form, filled, **Key** and **Secret** empty ("Leave
  empty to keep the current key."); **Test and Save Connection** tests again → "Connection changed" and a
  Now read is queued; **Cancel** returns.
- ⋯ **Unwire Store…** (destructive, last): `DestructiveActionDialog` - "Unwire Store" · "12 sizes stop being
  orderable: with no provider every size counts on its stock, and these have none free." (or "No size stops
  being orderable.") · "Prices and stock the provider wrote stay. Staff set them here again from now on." ·
  typing the store's code to confirm · **Unwire Store** → "Store unwired".

---

## 5 · Tables

Schema `sync`. Store-scoped rows name their store in every query; CHECKs on nullable columns are
written so a NULL cannot slip through.

| Table | Columns |
|---|---|
| `sync.connections` | `store_id` PK FK `platform.stores` RESTRICT · `provider` CHECK (`ODOO`) · `address` · `settings` jsonb (the adapter's fields) · `key_encrypted` · `secret_encrypted` · `state` CHECK (`OK`, `FAILING`) · `last_read_at` NULL · `last_full_read_at` NULL · `last_error` NULL · `created_by` · `created_at` · `updated_at` |
| `sync.reads` | `id` ULID PK · `store_id` · `kind` CHECK (`NOTIFIED`, `HOURLY`, `NIGHTLY`, `NOW`, `CODE`) · `requested_by_type`, `requested_by_id` NULL (staff only, and never for a notice's or the hour's read) · `state` CHECK (`QUEUED`, `RUNNING`, `DONE`, `FAILED`) · `prices_changed`, `stock_changed`, `skipped` int ≥ 0 · `error` NULL · `queued_at` · `started_at` NULL · `finished_at` NULL · `codes` jsonb NULL (a notified or one-code read's codes, a list; no other read has them) · **one running per store, one full read of each kind waiting** (unique partial indexes, so two workers never both win) — deleted 90 days after it finished |
| `sync.read_lines` | `id` bigint PK · `read_id` FK `sync.reads` CASCADE · `kind` CHECK (`PRICE`, `STOCK`) · `variant_id` (no key) · `code` NULL · `before` NULL · `after` — each price and stock number a read changed (§9.3 #19), deleted with its read |
| `sync.read_reports` | `id` bigint PK · `read_id` FK `sync.reads` CASCADE · `kind` · `code` NULL · `variant_id` NULL · `sale_id` NULL · `detail` jsonb — what each read reported, as it was that day (§9.3 #22), deleted with its read |
| `sync.report_entries` | `id` bigint PK · `store_id` · `kind` CHECK (the eight of §1.6, with `DISCOUNT_SKIPPED` (§9.3 #17)) · `code` NULL · `variant_id` NULL (no key: a deleted size keeps its entry) · `sale_id` NULL · `detail` jsonb · `opened_at` · `seen_at` · `announced_at` NULL (when staff were told of it, §9.3 #18 - each entry once) · `closed_at` NULL · `closed_by` NULL (`READ`, `UNWIRED`, `CATALOG`, `PRICING` or `STAFF:{id}`) · one open entry per (store, kind, code, size) — a unique partial index · deleted 90 days after closing |

---

## 6 · Events

| Consumed | From | Sync |
|---|---|---|
| `VariantCodeCorrected` | Catalog | In a wired store, a One-code read of the size's new code |
| `StoreListingChanged`, `ProductMadeReady` | Catalog | An off or draft size's entry closes once the size is switched on in a wired store and its product is ready (`CATALOG`) |
| `DiscountReachChecked` | Pricing | A "discount skipped" entry of a size now reached closes at once (`PRICING`; §9.3 #28) |

| Published | When | For |
|---|---|---|
| `SyncNeedsAttention` (`eventId`, `storeId`, `readId`, `opened` - a count by kind, `occurredAt`) | A read ends - done, or failed after its retries - and the store has open entries of `SALE_STOPPED`, `DISCOUNT_SKIPPED` or `READ_FAILED` not yet told of, whichever read opened them; each entry told once (`announced_at`) - another sale stopped, or discount skipped, on the same size told again (§9.3 #25) - one event a read, after the change commits | Ops: the store's holders of View the Sync Report who chose Sync problems (access.md amendment 71; §9.3 #18) |

Pricing and Inventory tell Catalog of every change themselves.

---

## 7 · Errors

Every error extends `SyncError` → `DomainError` ("sync.*"), with both languages.

| Error | Category | When |
|---|---|---|
| `ConnectionFailed` | INVALID | Saving a connection whose test read fails — its reason named |
| `AlreadyWired` | CONFLICT | Wiring a store that is wired |
| `NotWired` | CONFLICT | Sync now, Retry or unwiring in a store with no connection |
| `ReadRunning` | — | never thrown: a second read waits (§1.3) |
| `EntryNotFound` | NOT_FOUND | Retrying or dismissing an entry that is not there |
| `ReadNotFound` | NOT_FOUND | A read's page for a read that is not there (pruned after 90 days, or never one) |
| `SizeNotFound` | NOT_FOUND | Retry on an entry whose size was deleted since (§9.3 #24) |
| `EntryNotDismissable` | CONFLICT | Dismissing a failed read's entry: it closes when a read works again (§9.3 #23) |
| `EntryNotRetryable` | CONFLICT | Retry on an Offer Stopped or an Off or Draft entry, which a read never closes (Q27): staff open the offer or the product instead (screens step 6's review, 2026-10-19) |
| `StoreOff` | FORBIDDEN | A Sync read or action - the report, a read, Sync now, Retry, Dismiss, the Provider page and its changes - in a store that is off, by someone who may not switch stores (pricing.md §4.3.1 Q1, shared by every stage 5 screen; screens step 6's review, 2026-10-19). The system's own reads of an off store go on (§1.1 rule 8) |
| `InvalidSyncInput` | INVALID | A store that does not exist, an address that is not HTTPS, an empty key or secret, a provider we do not have — named by its field |

A notification with a bad signature or an old timestamp is answered `401` with no body: it is not a
staff error.

---

## 8 · Test scenarios

1. **Wiring**: only a Super Admin; the test read must pass; the key and secret encrypted, never
   returned; a full read queued; the store wired at once (Pricing and Inventory refuse hand changes).
2. **Notifications**: a good signature queues one read of those codes; a wrong signature, an old
   timestamp or too many calls refused; the call itself never reaches the provider.
3. **Prices**: a new price applied (source `PROVIDER`), unchanged changes nothing; unknown code,
   archived, 0, too many decimals — each reported and skipped; off and draft sizes
   priced, left as they are, listed; a price past the ceiling stops the sale on that size (one-size
   sale ended or removed), reported.
4. **Stock**: a new number a `PROVIDER` movement; the same number nothing; a retried read counted once;
   a fraction skipped; below 0 kept as 0; the returned extra back to 0; orderable and "last pieces"
   pushed.
5. **Reads**: hourly, nightly, Sync now, one code; one read per store at a time; the nightly read
   reports our sizes the provider does not know.
6. **Failures**: retried 3 times then `FAILING` and reported; one bad code skips only itself; a crash
   mid-read keeps the batches written.
7. **The report**: one open entry per kind and size; closing by a read, by staff, by unwiring; the
   count; only `sync.report.view` holders; 90 days then gone.
8. **Unwiring**: the count shown first; nothing cleared; hand changes allowed at once; orderability
   worked out again; the returned extra added.
9. **Isolation**: two wired stores — a read, a failure or unwiring in one changes nothing in the other.
10. **Permissions and audit**: every use case's permission in that store only; one audit entry per
    read; a connection's change audited without the key.

---

## 9 · Questions

### 9.1 The provider's team - answered (owner, 2026-10-19)

The owner answered the questions sent to the provider's team (2026-10-13); several had been settled
already (§9.2 #9: the base price only, nothing written back).

| # | Question | Answer |
|---|---|---|
| 1 | Standard API or one built for us; a test copy | **A custom API built for us**: our code runs in their Odoo, shaped as we write it (§2.4, to be written and accepted). **No test copy** (owner, 2026-10-19): we only read, and a read changes nothing in Odoo - the adapter is tested against the live account before the store opens |
| 2 | How we sign in, per store | **Each store is its own Odoo account** - its own address, database, token and prices; **no two stores share a token**. A **token per store**, typed on the Provider page by a Super Admin (§1.2); how it reaches the Super Admin is outside the system |
| 3 | The calls, fields, examples | **Ours to write** (§2.4); they follow it |
| 4 | The code; one per size? | **One code per size (variant)**; Odoo keeps only the name, code, price and stock - **we read price and stock by code, nothing else**. Their codes are **3 or 4 digits** (`123`, `1234`); **sent as text** exactly as kept, compared as written (§9.2 #15) |
| 5 | Currency, VAT | **No currency** (one account = one store and region, its price in the store's currency); **no VAT, no discount: the base price only** (§9.2 #9). VAT, offers, discounts and shipping are ours |
| 6 | Which stock | **Available to order, per size**; warehouses are not ours to see (inventory.md §9.1 #1) |
| 7 | Changed since a time; how many | **Yes**, besides the hourly read; **about 700 products**, growing |
| 8 | Limits, hours to avoid | **No hours to avoid**; calls within Odoo's own policy: *"throttled calls are typically acceptable for unsustained usage at a rate of 1 call/second, with no parallel calls"* (Odoo Cloud Acceptable Use Policy, odoo.com/acceptable-use) - the adapter makes **one call at a time per store, at least 1 second apart**. The limit counts calls, not products: **one call answers many products** (a page of up to 1,000 - about 700 today, so a full read is one call) |
| 9 | Their notice | **Our format accepted** (§1.3, as written in the 2026-10-13 request): `POST /sync/notify/{store id}`, `{"codes": [...]}`, signed with the store's secret. **Their server's clock must be right**: the timestamp is universal seconds (the same number in every time zone), refused when more than 5 minutes off; the times staff see stay in each store's own zone (KSA: Riyadh time) |
| 10 | Writing to Odoo | **Never** - nothing is written to the provider (§9.2 #9; inventory.md §1.9) |

### 9.2 Answered (owner, 2026-10-13)

| # | Question | Answer |
|---|---|---|
| 1 | How we learn of changes | **The provider notifies us of price and stock changes**, plus **Sync now**, a **nightly** full read and a light **hourly** read (§1.3) |
| 2 | The provider's price takes a sale past the ceiling | **Take the price, stop the sale on that size, report it** (§1.4 #5) |
| 3 | Unwiring | **Keep the last prices and stock; the store is unwired at that second and staff own everything** (§1.8) |
| 4 | Codes of off, draft and archived sizes | **Off and draft: take the values, stay as they are, listed for staff; archived: skipped, listed** (§1.4, §1.5) |
| 5 | Who is told, where | **A Sync report page per store with a menu count** (§1.6) |
| 6 | Where the key is kept | **Encrypted in our database** with the app's key, typed by a Super Admin, never shown again — the notification secret too (§1.2); CONVENTIONS.md's rule on secrets gets this written exception |
| 7 | How long the report and the log of reads are kept | **90 days** (§1.6) |
| 8 | "Our invoice shows the base price and the price after discount" | **Our order page, confirmation email and receipt show both**; tax invoices stay outside — the Sales spec (stage 6) |
| 9 | What is read from the provider | **The base price only**, and stock; every sale and discount is ours, never written to the provider (§1.1) |
| 10 (A) | Saving a connection whose test read fails | **Refused**, the reason shown (§1.2) |
| 11 (B) | A provider stock number below 0, or not whole | **Below 0 kept as 0; not whole skipped**; both reported (§1.5) |
| 12 (C) | Unwiring: a stock-dependent size's returned extra | **Added into its stock** (a `CORRECTION` movement, note "unwired") (§1.8) |
| 13 (D) | The permissions' place in the role editor | **A new "Sync" group** holding View the Sync Report and Run Sync Reads; wiring stays Super Admin only (§3) |
| 14 (E) | Notifications per store | **At most 60 a minute, up to 1,000 codes each**; one refused is answered "too many" and the hourly read covers it (§1.3) |
| 15 (F) | How codes are compared | **Exactly as written, spaces trimmed** (`0123` ≠ `123`) (§1.1) |
| 16 (G) | A sale stopped on a size | **The size leaves the sale; a sale left with none ends (running) or is removed (scheduled)**, audited as Sync's and reported (§1.4) |

### 9.3 Answered (owner, 2026-10-17) — while Sync was being built

Category discounts (pricing.md §1.12) came after this spec; these answers fill the gap and add two
things the owner asked for. They change §1.4 #5, §1.6, §3 and §5 as below.

| # | Question | Answer |
|---|---|---|
| 17 | The provider's price takes a running or scheduled **fixed-amount category discount** past the store's ceiling on a size (a percentage one goes as deep on every price, so a price never does that) | **The price is taken; the discount skips that size** while it would be too deep — as it already skips a size it would take to 0 — and reaches it again by itself once the price allows. The rest of the category keeps the discount. Reported: a new kind **`DISCOUNT_SKIPPED`** (the size, the discount), closed when the discount reaches the size again, or by staff. Only in a wired store: elsewhere staff setting such a price are still refused (pricing.md §1.12). |
| 18 | Who is told when the provider undoes staff's work | **A panel notification** to staff holding **View the Sync Report** in that store — a new staff notification topic, switched off like the others — when **a sale stops on a size (`SALE_STOPPED`), a discount skips a size (`DISCOUNT_SKIPPED`), or a read fails after its retries (`READ_FAILED`)**. Every other entry waits on the report and its count. |
| 19 | Seeing each read | **A page of the store's reads** — when, which kind, who asked, how many prices and stock numbers changed, how many skipped — each opening on its sections: **prices changed** (before → after), **stock changed** (before → after), and **reported**; each line links to the product, the sale or the discount, to be changed there, as the bulk import's product page does. The provider's prices stay read-only. For staff with View the Sync Report. A read's lines are kept with the read: **90 days**, then deleted with it (a new table, `sync.read_lines`). |
| 20 | In a wired store, a size a fixed-amount discount already skips (#17): does it still block lowering the ceiling, or saving or adding a fixed-amount discount? | **No: it is skipped and named, not a reason to refuse** (owner, 2026-10-17). In a wired store the ceiling may be lowered and such a discount saved or added; the sizes it cannot reach are named on its page and in the Sync report. A percentage is still held to the ceiling as before. Elsewhere nothing changes: staff are refused (pricing.md §1.12). |
| 21 | Saving a connection tests it first (#10), but no call to the provider happens in a web request (§1.1 rule 4) | **One quick test read in the Super Admin's save, 10 seconds at most** — the one exception to rule 4; every real read stays a queued job. Saving (or changing the key) queues a full read at once (§1.2), and **Sync now** (§1.3) reads the store whenever staff know something changed, without waiting for the provider's notice. |
| 22 | A read's "reported" section: what that read saw, or the entries a later read took over | **Exact per read** (owner, 2026-10-18): each read keeps its own list of what it reported, as it was that day (`sync.read_reports`), deleted with the read after 90 days |
| 23 | Dismissing a failed read's entry | **Refused** (owner, 2026-10-18): it closes only when a later read of the store succeeds (§1.6); staff press Retry or Sync now |
| 24 | Retry on a failed read's entry, or on a size deleted since | **A failed read's: the whole store is read** (as Sync now); **a deleted size: refused**, "this size no longer exists", so staff dismiss it (owner, 2026-10-18) |
| 25 | A size already told of, then another sale stopped or another discount skipped on it | **Told again** (owner, 2026-10-18, accepting the recommendation): a different sale or discount is a new thing undone; the same one seen again by a later read is not told twice |
| 26 | A price in another currency than the store's | **The provider sends no currency** (owner, 2026-10-18): every price is the store's currency; §1.4 #3 and §2.1 say so |
| 27 | A burst of notices piling codes into the one waiting read | **Past 5,000 codes, one full read of the store instead** (owner, 2026-10-18), and 600 calls a minute at most from one address |
| 28 | A "discount skipped" entry once the ceiling is raised, or the discount changed or ended | **Closed at once** (owner, 2026-10-18): Pricing tells Sync when a discount reaches the size again |
| 29 | An entry or a read of a store the person holds nothing of Sync's in | **Not found**, as one that does not exist (owner, 2026-10-18): nothing tells them it is there; their own store's viewer pressing an action is still refused |

### 9.4 Proposals for the screens (2026-10-18) - built as written unless the owner says otherwise

| # | Proposal | Why |
|---|---|---|
| S6 | **A Sync section in the admin menu** (platform.md §9.4: `sync` joins the menu's business areas, after Pricing; its icon `sync`, Lucide's `RefreshCw`) | The owner chose the screens by module (2026-10-18); the permissions already sit in their own Sync group (access.md amendment 68) |
| S7 | **`ViewConnection` gains the last full read and the notice address** (to copy into the provider's settings) | The Super Admin wiring a store needs the address the provider calls; the last full read says when the night's read last worked |
| S8 | **`SyncStatus`**, a read for the report's viewers: the provider and its state, the last reads, the failure's reason - never the address, settings or key | The report's header needs the state; `ViewConnection` is the Super Admin's alone |
