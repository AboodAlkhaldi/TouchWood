# Loyalty — Module Specification

**Status:** **ACCEPTED by the owner, 2026-10-10** (merged as #96) — sections 1–9 written 2026-10-07
from the owner's answers of the same day (§9.1), corrected after two independent reviews of the draft
(§9.3), with the changes Sales's spec needed (2026-10-10). The picks marked "my assumption" were
accepted with it.
**Tier:** 2. **Stage:** 6 (handoff §17), the first of the stage's four modules to be built (owner,
2026-10-07: Loyalty → Promotions → Feedback → Sales).
**Depends on:** Platform, Access (handoff §4.4; `deptrac.yaml` already says so).
**Source:** `docs/HANDOFF.md` §4.1–§4.5, §5.1, §5.3, §5.4, §6, §10.2, §11.3–§11.5, §12.3, §12.5, §14,
§16; the owner's answers of 2026-10-07 (§9.1); the merged code of Platform and Access as of `afe2f5d`.

Loyalty owns **points**: what a customer has in a store, how they are earned on a delivered order,
spent as a discount at checkout, given back when an order is cancelled or returned, changed by hand
by admins, and how they expire. **Points are the only loyalty mechanism** — no wallet, no store credit,
no cashback balance, no tiers, no per-product earn rate (handoff §11.5, §16).

Three rules of the owner's (2026-10-07) shape everything below:

- **A balance never goes below 0** ("there is no minus points ever") — replacing the handoff's
  negative balances and their checkout warning (§11.5, amended with this spec).
- **Earned points are usable right after delivery.**
- **Points given back keep their old expiry dates.**

## What this module does not own

| Concern | Owner |
|---|---|
| The order: when it is placed, delivered, cancelled or returned, its public number, its amounts, the return window | Sales — it **calls** Loyalty (§2); Loyalty never depends on Sales (handoff §4.4) |
| Every order total — `net_subtotal`, `goods_total`, VAT (handoff §10.2) | Pricing computes them (stage 5, owner 2026-10-07); Sales passes Loyalty the ones it needs, and passes Loyalty's points discount to Pricing |
| The discount ceiling `max_discount_percent` (handoff §11.3) | Promotions owns the number (promotions.md §1.6); Sales applies the check with the room Promotions gives it; Loyalty only answers what a redemption would be |
| Whether a buyer is `PUBLIC` or `COMPANY` (an approved company) | Sales passes it in (it reads B2B); Loyalty never reads B2B |
| Telling a customer about their points — earned, expiring | Ops (stage 8) — owner, 2026-10-07: "not now" |
| Customers, staff, the permission catalog | Access |
| Settings, the audit log, stores and their currency, staff names, the admin menu | Platform |

---

## 1 · Aggregates and invariants

### 1.1 The points account

**One account per customer per store** — KSA points never spend in Egypt (handoff §11.5). A customer is
one identity in Access; their points are per store. An account is created the first time Loyalty
records anything for the customer in that store — a redemption, a delivery (even one that earns
nothing), or points added by hand; a customer with none has a balance of 0.

- **The balance** is the sum of the points left in the account's **lots** (§1.3) that are still live:
  whole points, **never below 0**.
- The stored balance always equals the sum of the account's ledger (§1.2). An account is never deleted.
- **One change at a time:** every write — the nightly sweep included — locks the account first, then
  its lots. An account is inserted only if absent, then locked, so two first deliveries cannot race.
- **No reading ever counts a dead point.** Every read and quote adds up only the lots whose date has
  not passed, without writing anything; every write first expires the account's lots whose date has
  passed (§1.10), under the account's lock.

### 1.2 The ledger

Every change to an account's points is **one entry, written once and never changed or deleted**
(append-only, as the audit log: a trigger refuses UPDATE, DELETE and TRUNCATE).

| Kind | Points | Made by |
|---|---|---|
| `EARNED` | + | An order delivered (§1.6) |
| `REDEEMED` | − | Checkout, inside Sales's order placement (§1.7) |
| `REDEMPTION_RETURNED` | + | The order that used them cancelled or returned — the points that land on a lot (§1.8) |
| `EARNING_REVERSED` | − | The order that earned them cancelled or returned — the points taken from lots (§1.8) |
| `ADDED` | + | An admin, by hand, with a reason (§1.9) |
| `DEDUCTED` | − | An admin, by hand, with a reason (§1.9) |
| `EXPIRED` | − | A lot's date passing (§1.10), or points that come back to a lot whose date has passed (§1.8) |

An entry never holds 0 points: a fact that moves no point is recorded on the order (§5,
`loyalty.orders`), not in the ledger. Every point an entry moves is also recorded **lot by lot**
(`lot_movements`), so each lot's history reads back exactly.

### 1.3 Lots, and points that remember where they are

Every point that comes in (earned, added by hand) forms a **lot** with its own **expiry date**: the
moment it came in plus the store's expiry period (§1.5), fixed then — changing the period later moves
no existing date. A lot is **live** while it has points left and its date has not passed.

**Spending takes from the lot that expires first** (equal dates: the older lot first). **A point taken
by an order is out with that order** — each such **take** remembers its lot, its points, and how many
have come back. Points an order took come back, when it is cancelled or returned, **to the lot they
were taken from, with its old date** (owner, 2026-10-07): on a lot whose date has passed they come back
expired, recorded as returned and expired at once.

**A reversed lot settles its covers first.** When the order a lot was earned on is reversed while some
of that lot's points are out with other orders, what the lot cannot give back itself is **covered** —
by other points, or dropped (§1.8) — and each cover is recorded **on the lot**. Points that later come
back to that lot, from any of its takes, **first undo its covers**: the points that covered from
another lot go back to that lot, with its date; covers by points that had already expired, and dropped
points, take their share and go nowhere. Only what is left comes back to the lot itself. So a cancelled
order cannot revive points whose earning was already taken back, and points that covered come back
when the points they covered do. Which points were live when a reversal ran can still change the end —
see the next paragraph. A cover taken from a lot is
itself a take from that lot, and comes back the same way.

**The order of events can change a balance** (owner, 2026-10-07, answer 15): because a reversal takes
from the customer's other points, what it finds depends on what is live at that moment, which other
orders' returns and cancellations change. Each settlement is exact on its own; two orders settled in
different sequences may end with different balances — even two alone, when one is returned in parts:
A earned 200 into lot E, all spent on B, and the customer has 150 in L2. A returned, then half of B
returned: L2 covers 150 and 50 are dropped, then B's 100 restore 100 of L2 — **100**. Half of B
returned first: E gets 100 back, A's return takes those and covers the other 100 from L2 — **50**. The owner chose this over never touching other
points, which would always end alike but would never recover anything from them.

### 1.4 Never below 0

**Points are only ever taken from points that are there.** A redemption, a deduction by hand and a
reversal take only from live lots; when a reversal (§1.8) finds fewer points than it should take back,
**the rest is dropped** — the customer keeps the discount they already had (owner, 2026-10-07). What
was dropped is recorded on the order, not as points.

### 1.5 The store's programme — settings

Each store sets its own programme, as **Platform settings declared by Loyalty**, store-scoped, changed
under `loyalty.settings.update` (§3, admin-only). Values are read when they are used: earning reads the
rates and the period at delivery, a redemption at checkout.

| Key | Type | Range | Default | Means |
|---|---|---|---|---|
| `loyalty.points.enabled` | Boolean | — | **false** | **The store's points programme on or off — off until an admin turns it on** (owner, 2026-10-07). Off: nothing is earned or redeemed; balances stay, expiry goes on, returns and cancellations still settle what was done while it was on |
| `loyalty.points.earn_per_unit` | Integer | 1–1,000 | 1 | **Points earned per 1 unit of the store's currency spent** (owner, 2026-10-07): 1 point per SAR |
| `loyalty.points.points_per_unit_off` | Integer | 1–100,000 | 100 | **Points for 1 unit of currency off** (owner, 2026-10-07): 100 points = 1 SAR. With the default 1 point per SAR, 1% back |
| `loyalty.points.expiry_months` | Integer | 1–120 | 12 | How long a lot lives, from the day it came in (handoff §11.5, "an admin-defined period") |
| `loyalty.points.minimum_redemption` | Integer | 0–1,000,000 | 0 | The fewest points one redemption may charge (handoff §11.5) |
| `loyalty.points.max_redemption_percent` | Integer | 0–100 | 50 | The most of an order's `net_subtotal` points may pay (handoff §11.4) |
| `loyalty.redemption.public_partial` | Boolean | — | true | Individuals (every `PUBLIC` buyer) choose how many points to use (`PARTIAL`); off: their whole balance or none (`ALL_OR_NOTHING`) |
| `loyalty.redemption.company_partial` | Boolean | — | true | The same for approved companies (`COMPANY`) — set separately per audience (handoff §11.5) |

My assumption, stated for the owner to reject: **the defaults** above (except `enabled`, which is the
owner's) and the ranges. The mode is a switch per audience rather than a word to type, because the
settings page draws a Boolean as a switch.

**The arithmetic, in the currency's smallest unit** (halalas, piastres, fils), whole numbers only —
never a float (handoff §5.1). `e` is the store currency's exponent (2 for SAR), `ppu` is
`points_per_unit_off`; `floor` and `ceil` round down and up:

| | Formula |
|---|---|
| Points earned on an order | `floor(goods_total × earn_per_unit ÷ 10^e)` — 1,234.56 SAR earns 1,234 points at 1 per SAR |
| What `P` points are worth | `floor(P × 10^e ÷ ppu)` |
| The cap | `floor(net_subtotal × max_redemption_percent ÷ 100)` |
| The most points the cap lets count | `floor(((cap + 1) × ppu − 1) ÷ 10^e)` — the largest `P` whose worth does not pass the cap |
| The points charged for a discount `D` | `ceil(D × ppu ÷ 10^e)` — the fewest points worth `D`; any other point stays with the customer |

**A customer is never charged a point that bought nothing**, whatever `ppu` is: at 150 points per SAR,
asking for 4 points (worth 2 halalas) charges 3, the fewest worth 2.

### 1.6 Earning — on delivery

- **Sales tells Loyalty when an order is delivered**, with its `goods_total` (handoff §10.2: the
  earning base is `goods_total` — after coupon and points, before shipping and VAT). Loyalty records
  `EARNED` with the points of §1.5, in a lot that expires `expiry_months` later. **The points are usable
  at once** (owner, 2026-10-07).
- **Both individuals and company accounts earn** (owner, 2026-10-07); guests have no account and earn
  nothing.
- **The delivery is recorded even when it earns nothing** — the programme off, or a sum that rounds
  down to 0 — so asking again never earns later.
- A delivery of a cancelled order is refused (§7).
- My assumption, stated for the owner to reject: whether a store's programme is on is read **at
  delivery**, not when the order was placed.

### 1.7 Redemption — a discount at checkout

**Points redeem as a direct discount at checkout, never a generated code** (handoff §11.5). Sales asks
for a quote while building its quote, and applies the redemption inside placing the order.

In order:

1. The store's programme is on and the balance is above 0.
2. **The points asked for:**
   - `PARTIAL`: the number the customer chooses, from 1 to the balance.
   - `ALL_OR_NOTHING`: the whole balance; the customer chooses nothing.
3. **The cap:** the points that count are at most the most the cap lets count (§1.5) — for
   `max_redemption_percent` of `net_subtotal` (handoff §11.4). Points asked for beyond it **are not
   used, and stay** — in both modes (owner, 2026-10-07, for `ALL_OR_NOTHING`; handoff §11.3, "capped
   by max_redemption_percent", for both).
4. **The discount** is what the points that count are worth, and **the points charged** are the fewest
   worth that discount (§1.5). A discount of 0 is refused, never charged.
5. **The minimum:** the points charged reach `minimum_redemption`, or the redemption is refused. My
   assumption, stated for the owner to reject: the minimum is measured on the points charged, not the
   points asked for.
6. The answer: the mode, the most points usable now, the points charged, and the discount.

**The discount ceiling is checked by Sales**, with the room Promotions gives it (promotions.md §1.6): if the order's whole reduction from list price would pass
`max_discount_percent`, Sales does not apply the points at all — **the whole redemption is refused and
the points stay unused**; a part is never applied (handoff §11.3: "points are evaluated last and
refused first").

**At placement** Loyalty works the redemption out again — with the balance and settings of that moment
— and refuses if the points or the discount differ from the quote, so checkout quotes again; it never
applies anything the customer did not see. Then it takes the points from the lots that expire first
(§1.3) and records `REDEEMED` with the order's id, its public number and the discount — inside Sales's
transaction, so an order and its points stand or fall together.

### 1.8 Cancellations and returns

Sales cancels an order before it ships and takes returns after it is delivered (handoff §12.3), so an
order is either cancelled or returned, never both. Each settlement does two things together: **give
back** the points the order used, and **take back** the points it earned.

**How much.** A cancellation settles everything. A return settles **by running totals**: Sales passes,
for each return, the returned goods' allocated share of `goods_total` and the returned allocated share
of the order's points discount (each the returned lines' parts by `Money::allocate`); the order's
target so far is `floor(the order's points × returned so far ÷ the order's total)`, and the settlement
moves the target minus what earlier settlements moved. **Sales's obligation:** once an order is
returned in full, the shares it has passed add up exactly to `goods_total` and to the points discount
— then the last return lands exactly on the order's points. An order with `goods_total` 0 earned
nothing to take back; one with no points discount used nothing to give back.

**1. Give back.** The points to give back are taken from the order's own takes **in the order they were
spent**. My assumption, stated for the owner to reject: that order (not "the latest date first") keeps
returning an order in parts close to returning it at once. Each point goes back to its take's lot —
**which first undoes that lot's covers, if its earning was reversed** (§1.3): the lot covers oldest
first, back to the lots that covered, with their dates; then the covers by expired points and the
dropped points, which go nowhere. The rest lands on the lot with its old date (expired at once if
that has passed).

**2. Take back.** The points to take back come from the order's own earned lot, in this order — **the
order's own unspent points always first** (owner, 2026-10-07, answer 16):

- **a. What is still in that lot**, if it is live.
- **b. What left that lot by expiry or by hand counts as already taken back** — it is never taken
  twice (owner, 2026-10-07, for expiry). My assumption, stated for the owner to reject: points an
  admin deducted from that lot count the same way.
- **c. What is still out with other orders** — the rest — **is covered**, each point by the first of:
  1. **this order's given-back points that came back already expired**, from this settlement or an
     earlier one of the same order, not yet used to cover (owner, 2026-10-07: they "first cover what is
     taken back"). Points that went nowhere through a cover never count as given back expired, and a
     cover by expired points that is later undone does not return them to be used again;
  2. **this settlement's other given-back points**, after they land — the lot that expires first
     first;
  3. **the customer's other live lots**, the one expiring first first (owner, 2026-10-07, answer 15:
     "take them from other points first");
  4. **nothing — the rest is dropped** (§1.4).

  Each cover is recorded on the order's earned lot (§1.3), with what covered it.

**The order's own points come first** (answer 16): an order returned after it used 300 points that have
since expired, and earned 200 still unspent, ends with **0** — the 200 are taken from its own lot (a),
and the expired 300 cover nothing, since nothing was spent elsewhere. Without that order the customer
would have had nothing.

**3.** These still run while the store's programme is off.

**The owner's example.** 300 points expiring 1 March paid for order A; A was delivered and earned 200,
which paid for order B. A is returned in April. Give back: the 300 come back to their lot, expired. Take
back: A's lot is empty and nothing of it expired, so the 200 out with B are covered — by A's 300
expired given-back points. The other 100 stay expired. The balance does not change, and A's lot now
carries a cover of 200 by expired points: **if B is cancelled later, its 200 come back to A's lot,
undo that cover, and go nowhere** — the same end as if B had been cancelled first (A's lot would have
had its 200 back, and A's return would have taken them).

**With other points.** A earned 200, spent on B; the customer also has 150 in another lot L2. A is
returned: the 200 are covered by L2's 150, and 50 are dropped — balance 0. If B is cancelled later,
its 200 come back to A's lot: 150 go back to L2 with its date, 50 go nowhere — balance 150. Had B been
cancelled first, A's lot would have had its 200 back and A's return would have taken them — balance 150
too. With a third order settled between them, the two sequences can end differently (§1.3).

**A staff edit before shipping** (sales.md §1.7; owner, 2026-10-10: coupon and points "checked again;
trimmed or removed if they no longer fit"). An edit can only lower what points pay — never charge more
points. Sales passes the order's new points discount; Loyalty **gives back the points above it, to
their own lots with their old dates**, exactly as a return gives back its share of the points discount
(step 1, by running totals: the edit lowers the points discount by the difference). It is recorded like
a return, keyed by the edit's id, so it counts once. Nothing is taken back: an undelivered order has
earned nothing.

**How much the points may still pay** is Loyalty's to say, since the cap is Loyalty's: Sales asks
`pointsAfterEdit` with the edited order's `net_subtotal` and **the room the coupon left under the
ceiling** (sales.md §1.7 — the points give way before the coupon, handoff §11.3). The new points
discount is **the least of the discount now, the cap on the edited `net_subtotal` — by the
`max_redemption_percent` the order was placed under, recorded when it redeemed (owner, 2026-10-10: an
edit keeps the order's own rules) — and that room**; the points still charged are the order's points less those the running totals give back.
`orderEdited` then settles it and **answers the same two numbers**, which Sales records on the order.
An edit is the one settlement before delivery: its `returned_discount_minor` grows on an undelivered
order, its `returned_goods_minor` never does (§5).

### 1.9 Changes by hand

Admins **add or remove points by hand** — a goodwill gift, a correction — **under their own
permission, admin-only, with a reason, and audited** (owner, 2026-10-07). The customer's history shows
each one.

- Adding forms a lot that expires `expiry_months` later.
- Removing takes from the lots that expire first, never more than the balance (§1.4), and is final:
  removed points never come back.
- A reason of 1 to 500 characters is required.
- My assumption, stated for the owner to reject: changes by hand work while the store's programme is
  off.

### 1.10 Expiry

A lot whose date has passed loses its remaining points as **one `EXPIRED` entry**. Every write does
this first for its account (§1.1), and a **nightly job** does it for the rest: it runs as the system at
00:30 UTC (03:30 Riyadh), after Access's 00:00 UTC sweep, one account at a time under its lock. A lot
already empty when its date passes needs no entry. Reads never count a lot whose date has passed,
whether or not the sweep has reached it.

### 1.11 A deleted customer

Loyalty holds **no personal data** — only the customer's id, which Access keeps for an anonymized
account (access.md §1.10). Nothing changes when an account is anonymized: its points stay recorded and
can never be used again, since nobody can sign in to it. My assumption, stated for the owner to reject.

### 1.12 Each store's rows

Loyalty's configuration and balances are store-scoped (handoff §4.1). Accounts, entries and orders
carry their store; lots, takes, covers, movements and returns reach it through their account, which
composite keys hold them to (§5). Everything is written and read by one repository that names the
store in every call, as Catalog's store rows are (catalog.md §5.2); no Eloquent model is used, so
handoff §4.1's `BelongsToStore` guard has nothing to guard. The nightly sweep is the one pass across
stores.

---

## 2 · Public contract

### 2.1 `Modules\Loyalty\Public\Contracts\LoyaltyApi`

Ids in, DTOs out (handoff §4.3). The five writes are Sales's, made **inside Sales's own transaction**,
and each names **the Sales permission that allowed the change** — the precedent of Platform's
`deleteMediaFor` (`ModuleDeleteDto`), with one difference: **Loyalty builds the scope itself**, from the
store the change touches — for a settlement or a delivery, **the store recorded on the order** — so a
caller cannot change one store's points with a permission held in another. A store passed in that is
not the order's recorded store is refused. The permission must belong to the calling module.

| Method | For |
|---|---|
| `earningPreview(string $storeId, Money $goodsTotal): int` | The cart and checkout: "this order earns about 120 points on delivery" (owner, 2026-10-07: cart and checkout, not product pages). 0 while the programme is off |
| `quoteRedemption(RedemptionRequest $request): RedemptionQuote` | Sales's quote (§1.7). Reads only; changes nothing |
| `redeem(RedeemPoints $change): void` | Placing the order (§1.7) |
| `earn(EarnPoints $change): void` | The order delivered (§1.6) |
| `orderCancelled(SettleOrder $change): void` | §1.8, everything |
| `orderReturned(SettleReturn $change): void` | §1.8, a return completed |
| `pointsAfterEdit(EditedPointsRequest $request): EditedPoints` | Sales, working out a staff edit before shipping (§1.8): the new points discount and the points still charged. Reads only |
| `orderEdited(SettleEdit $change): EditedPoints` | §1.8, a staff edit before shipping that lowered the points discount; answers the points discount and the points still charged |
| `balance(string $customerId, string $storeId): PointsBalanceDto` | **Sales only**, inside flows it has already authorized. The screens read through §3's queries, which check the reader |

### 2.2 DTOs and enums (`Public/Dto`, `Public/Enums`)

- `RedemptionRequest`: customer id, store id, `Audience`, the points asked for (null: as many as
  allowed — the only choice in `ALL_OR_NOTHING`), `net_subtotal` as `Money`.
- `RedemptionQuote`: the mode, the most points usable now, the points charged, the discount as `Money`,
  and — when refused — the reason (`RedemptionRefusal`). Checkout draws its points control from it.
- `RedeemPoints`: the order's id and public number, customer, store, the quote's points and discount,
  `Audience`, `net_subtotal`, the caller's permission.
- `EarnPoints`: the order's id and public number, customer, store, `goods_total`, the caller's
  permission.
- `SettleOrder`: the order's id, store, the caller's permission.
- `SettleReturn`: the order's id, store, the return's id, the returned goods' allocated share of
  `goods_total` and the returned allocated share of the points discount (§1.8), the caller's permission.
- `EditedPointsRequest`: the order's id, store, the edited order's `net_subtotal` and the room the coupon
  left under the ceiling, both as `Money`.
- `EditedPoints`: the order's points discount after the edit, as `Money`, and the points still charged.
- `SettleEdit`: the order's id, store, the edit's id, the order's new points discount (never above the
  old), the caller's permission.
- `CallerPermission`: the calling module and its permission — no scope (§2.1).
- `PointsBalanceDto`: the balance, what it is worth in the store's currency, and the next expiry (date
  and points).
- `Audience`: `PUBLIC`, `COMPANY` — handoff §6; Loyalty's own (§9.2).
- `RedemptionMode`: `PARTIAL`, `ALL_OR_NOTHING`.
- `RedemptionRefusal`: `PROGRAMME_OFF`, `NOTHING_TO_REDEEM`, `OUT_OF_RANGE` (a `PARTIAL` ask outside 1
  to the balance), `NOTHING_UNDER_CAP` (the cap allows no discount), `WORTH_NOTHING`, `BELOW_MINIMUM`.
- `EntryKind`: the seven kinds of §1.2.

### 2.3 What Loyalty needs from other modules

| From | What | State |
|---|---|---|
| Platform | Store-scoped settings, declared and read (§1.5) | Exists (`SettingsRegistry::define`, `PlatformApi::setting`) |
| Platform | The store's currency code and exponent | Exists (`PlatformApi::store` → `StoreDto`) |
| Platform | The audit log (§3) | Exists (`PlatformApi::recordAudit`) |
| Platform | Staff names as the reader may see them — a Super Admin reads as "System administrator" (handoff §14) | Exists (`StaffNames::forReader`) |
| Platform | **A "Points" section in the admin menu** (owner, 2026-10-07; handoff §14: Points settings · Redemptions) | **A Platform addition, built with Loyalty**: `points` joins the menu's groups (`InMemoryAdminMenu::GROUPS`) — built with step 1, platform.md §9.4 |
| Access | The permission catalog | Exists (`PermissionCatalog::declare`) |
| Access | **A `Points` permission group**, so the role editor and the menu show the points permissions together (owner, 2026-10-07) | **An Access addition, built with Loyalty**: `PermissionGroup::Points` — built with step 1, access.md amendment 66 |
| Access | A customer exists, for a change by hand | Exists (`AccessApi::customer`) |
| Access | **For the screens** (§4.1): customers' names in bulk; a search by name, email or phone; a section on the staff customer page; a condition on the account's Points tab | `AccessApi::customers` exists (amendment 70); **the rest is access.md amendment 72**, built with the screens |
| Platform | **For the screens**: a settings card opened by address (`#settings-loyalty`); the Points menu's icons | **A Platform addition, built with the screens** (platform.md §9.4, 2026-10-19) |

### 2.4 What Loyalty gives others

`LoyaltyApi` above, to Sales, and the reads of §3 to the screens. No event is published: nothing
listens yet. Ops adds what it needs when it tells customers about their points (§6).

---

## 3 · Use cases

Every command and query handler authorizes first (`CommandHandlersAuthorizeTest`).

| Use case | Permission | Notes |
|---|---|---|
| `Redeem` | **The caller's** — Sales's placing an order — in the order's store | §1.7. Inside Sales's transaction |
| `Earn` | The caller's — Sales's delivery action — in the order's recorded store | §1.6 |
| `SettleCancelledOrder` | The caller's — Sales's cancellation (the customer's or staff's) — in the order's recorded store | §1.8 |
| `SettleEditedOrder` | The caller's — Sales's editing an order — in the order's recorded store | §1.8, a staff edit |
| `SettleReturnedOrder` | The caller's — Sales's completing a return — in the order's recorded store | §1.8 |
| `AddPoints`, `DeductPoints` | `loyalty.points.adjust` (per store, **admin-only**) | §1.9. Audited: `loyalty.points.added` / `loyalty.points.deducted`, with the points and the reason |
| `ExpirePoints` | `loyalty.points.expire` (reserved, global: the system only) | §1.10. Maintenance: not audited, the ledger is its record |
| Update a programme setting | `loyalty.settings.update` (per store, **admin-only**) | Platform's `UpdateSetting`, audited there |
| `earningPreview`, `quoteRedemption`, `balance` | **None of their own** — reads for Sales inside a flow Sales has authorized; never reached from a screen | §2.1 |

| Read (query) | Permission |
|---|---|
| **My points** in a store: the balance, what it is worth, each live lot and its date, the history newest first with each order's public number | `loyalty.points.view_own` — every customer, about their own account only |
| **A customer's points** in a store (the menu's "Points › Customers" and the customer page's Points section, §4.1; handoff §14 amended 2026-10-19): the same, and every change by hand with its admin's name (`StaffNames::forReader`) and reason | `loyalty.points.view` in **the store the points belong to**, not the customer's home store (a KSA-registered customer's UAE points are UAE's). My assumption, stated for the owner to reject |
| **The store's redemptions** (the menu's "Points › Redemptions", handoff §14): the order's public number, the customer, the points, the discount, the date | `loyalty.points.view` in that store |

**The permissions Loyalty declares:**

| Permission | Audience | Kind | Group | Admin-only |
|---|---|---|---|---|
| `loyalty.points.view_own` | every customer | global | — | — |
| `loyalty.points.view` | Role | per store | `Points` | no |
| `loyalty.points.adjust` | Role | per store | `Points` | **yes** |
| `loyalty.settings.update` | Role | per store | `Points` | **yes** |
| `loyalty.points.expire` | Role, reserved | global | — | — |

**Both changes are admin-only** (owner, 2026-10-07): only an admin role can hold `loyalty.points.adjust`
or `loyalty.settings.update` — points cost the business money — unlike a store's own Access settings,
which stay an ordinary action (access.md §1.5). **The menu's Points section** holds the store's points
settings, its customers holding points and its redemptions; a customer's points are reached from that
list and from the customer's own page (§4.1, owner 2026-10-19).

---

## 4 · State machines

**A lot:**

```
LIVE ──(spent to 0)──────────▶ EMPTY ──(points back, date not passed)──▶ LIVE
LIVE or EMPTY ──(date passes)──▶ EXPIRED   (final: points that come back to it expire at once)
```

**A take** (points an order or a cover took from a lot):

```
OUT ──▶ partly back … ──▶ ALL BACK (each point back through its lot: its covers first, then the lot)
```

**A cover** (on a lot whose earning was reversed): `OPEN` ──(points coming back to the lot)──▶ partly
undone … ──▶ `UNDONE`. A lot's covers are undone lot covers first, oldest first; then covers by expired
points and drops.

**An order's points, in one store** (`loyalty.orders`):

```
redeemed:  none ─▶ REDEEMED ─▶ partly given back … ─▶ all given back
earned:    none ─▶ DELIVERED (points earned, maybe 0) ─▶ partly taken back … ─▶ all taken back
```

A cancellation jumps straight to "all given back". A delivered order is never cancelled; a cancelled
order is never delivered or returned.

**An account:** no state beyond its balance, which is never below 0.

### 4.1 The screens (stage 6's screens)

**Written 2026-10-19** from the owner's answers of 2026-10-19 (§9.4, answers 1–15), all as
recommended. What is marked **[PROPOSED Pn]** is mine, listed in §9.5, built as written unless the
owner says otherwise. The use cases behind every button are §3's. A screen offers only what the reader
may do next — asked of an Application query (`LoyaltyActionsForReader`), never of the authorizer from
the screen (`tests/Architecture/AccessDecisionsTest`) — and every handler asks again.

**What every screen keeps** — as Catalog's and Pricing's (catalog.md §4.4, pricing.md §4.3): Geist's
rules on shadcn's code (frontend.md §1.11), every box checking itself as it is typed, every number in
0-9, every moment through `Time` in the store's zone, names in the page's language first. **Points** are
whole numbers with the thousands grouped ("12,500"), in Latin digits; **money** with the store's
currency and its own decimals, through **`resources/js/lib/money.ts`** — the one formatter every
screen showing money uses (Pricing's screens too; whichever is built first adds it): the server sends
an amount as its decimal text and the currency's code ("12.34", "SAR"), never a float. An empty cell
(no order, no staff member, nothing expiring) is Geist's em dash. The words in
`src/Modules/Loyalty/Presentation/lang/{ar,en}/admin_points.php`, `shop_points.php`, `menu.php` and
`account_pages.php` (the Points tab's name, as `CustomerAccountPageDto::labelKey()` reads it); refusals
are §7's.

**The store.** Every Loyalty staff screen has **its own store filter** (`?store=<code>`, frontend.md
§2.2, Platform's `StoreChoices`): the stores where the reader holds the screen's job, a Super Admin's
off stores marked Off, none drawn for one store; no `?store=` opens the first store that is on (answer
3). **There is no all-stores view**: points belong to one store (§1.12), and every read takes one. A
reader with the job in no store they may open — none at all, or only in stores that are off and they
cannot switch stores — gets the panel's 403 page. A store that is **off** shows its screens to whoever
may still open it (a Super Admin), with the StoreOff badge; reading works, and so do changes by hand
(§1.9). **A programme that is off** (`loyalty.points.enabled` false) shows a Geist Note above the
screen — "Points are off in this store: nobody earns or uses points. Balances stay, and still
expire." — and changes nothing else (answer 9).

**Where they are.** Admin routes in `Presentation/admin-routes.php`, named `loyalty.admin.*`, loaded
by the module's provider through `App\Http\AdminArea`, `Page` and `FormErrors` (as Catalog's,
catalog.md amendment 13(d); `config/ziggy.php`'s admin group gains `loyalty.admin.*`); the shop's in
`Presentation/shop-routes.php`, named **`storefront.loyalty.*`**, which the storefront group already
holds. **The menu** — Platform's `AdminMenu`, the **Points** group (it exists, access.md amendment
66), its icon `points` (Lucide's `Coins`, joining `MenuIcon`'s list):

| # | Entry | Address | Icon | Offered for |
|---|---|---|---|---|
| 10 | Customers · العملاء | `/admin/points/customers` | `customers` (exists) | `loyalty.points.view` |
| 20 | Redemptions · الاستبدالات | `/admin/points/redemptions` | `redemptions` (Lucide's `TicketPercent`, new) | `loyalty.points.view` |

**The reads the screens add** — Application queries, each **one query or a fixed few, never one per
row** (frontend.md §5: 15 queries an admin page, the page's own; 8 a shop page):

| Query | Job | What it answers |
|---|---|---|
| `ListPointHolders` (new) | `loyalty.points.view`, that store | **every points account in the store, most live points first** (then the account's id), 50 at a time, keyset by (live points, id): each customer's name (Access, below), the **live** points (the live lots' sum, as every read counts them, §1.1 — never the stored balance, which still holds points past their date until the next write or the nightly sweep), their worth, the points expiring on the soonest date (below) and that date, and the last entry's moment. Accounts with no live points come last. One query over the store's accounts and live lots; with a search, the same query kept to the customers Access found |
| `ViewCustomerPoints` | §3's, **tightened** (below) | as built: the balance, its worth, every live lot (all of them, unpaged — they are few), the history newest first with staff names and reasons |
| `CustomerPointsInStores` (new) | `loyalty.points.view`, each store asked | one customer's live points, worth and soonest expiry in each store the reader holds the job in where the customer has an account — **one query** for all of them (the customer page's section, below) |
| `ListRedemptions` | §3's | as built, with each row's customer name (below) |
| `ViewMyPoints` | §3's | as built, plus whether the programme is on and its two rates, for the off note and the "how it works" line **[PROPOSED P3]** |
| `LoyaltyActionsForReader` (new) | — (asks the authorizer for the reader) | for a store: whether the reader may change points by hand there (`loyalty.points.adjust`) **and** open L2 there (`loyalty.points.view`); for a customer, whether the reader may open the customer's own page (Access's `access.customer.view` in the customer's home store) |

**"Expiring next"**, wherever it shows: the points of every live lot whose date falls on the **soonest
calendar day** in the store's time zone, summed, and that day — lots are timestamps, and several can end
on one day.

**Names come from Access** — Loyalty keeps none. Every list reads the page's customers in **one call**,
`AccessApi::customers(ids)` (access.md amendment 70); L2 reads its one customer with
`AccessApi::customer`. **A store's staff see the name of a customer who has an account in that store
though registered in another** — the owner's answer 7, the same widening Promotions has (access.md
amendment 72(c)); **only the name**: the email shows only for a customer whose page the reader may
open **[PROPOSED P1]**. An anonymized customer reads "Deleted customer" · "عميل محذوف", with no link
(answer 7).

**The search** (answer 2) asks Access for the customers matching the text (access.md amendment 72(b)):
**by name, any store; by email or phone, only among the customers the reader may already view** — so
typing someone's email never names a customer the reader could not see **[PROPOSED P6]**. Access
answers at most 1,000 ids; Loyalty keeps those with an account in the store and lists them as above,
with **Show More**. When Access answers 1,000, the list says "Too many customers match — type more of
the name, email or phone." and shows none **[PROPOSED P2]**.

**`ViewCustomerPoints`, tightened** (review of 2026-10-19): today it answers any existing customer, an
empty account where they have none — so any holder of `loyalty.points.view` could learn that an id is a
customer and read their name on L2. From the screens on, **a customer with no account in the store is
`PointsAccountNotFound`** unless the reader may view that customer's own page (`access.customer.view`
in their home store), as access.md amendment 44(c) answers a customer who is not the reader's
**[PROPOSED P7]**. A Loyalty change, told to the stage 6 backend session.

#### L1 · Points › Customers — `/admin/points/customers?store=&search=`

- **Header**: title "Customers" · "العملاء", subtitle "Who holds points in this store" · "من لديه نقاط
  في هذا المتجر"; the store filter; the programme-off Note when off.
- **Search**: one box (shadcn Input, Geist's search look), "Search by name, email or phone", sent 300 ms
  after typing stops and kept in the address (`?search=`); fewer than 3 characters searches nothing and
  says "Type at least 3 characters." under the box; more than 100 is refused as typed; Escape empties
  it.
- **Columns** (shadcn Table, Geist's look; it scrolls inside its card at phone width, frontend.md):
  **Customer** (the name; under it, the email for a customer whose page the reader may open — P1) ·
  **Points** (live, right-aligned, grouped) · **Worth** (money) · **Expiring next** ("1,200 on 3 Jan
  2027", or an em dash) · **Last change** (`Time`, relative, the full moment in its tooltip). A row opens
  **L2** in the same store — the whole row is the link (keyboard reachable).
- **Paging**: 50 rows, then **Show More** under the table while there are more (answer 13; Geist's Load
  More, `useLoadMore`): the button shows it is busy while the page comes; the rows already there stay.
  The same with a search.
- **Empty**: no accounts — Geist's Empty, "No points yet" / "When customers earn points in this store,
  they appear here."; a search that finds nobody — "No customers match "{text}"." with **Clear Search**.

#### L2 · A customer's points — `/admin/points/customers/{customer}?store=`

- **Header**: the customer's name as the title (or "Deleted customer"), subtitle "Points"; breadcrumbs
  Points › Customers › the name; the store filter; **Open Customer** (a link to `/admin/customers/{id}`)
  only when `LoyaltyActionsForReader` says the reader may open it.
- **Balance card** (shadcn Card, Geist's Description): **Points** (large, grouped) · **Worth** (money)
  · **Expiring next**: "1,200 points expire on 3 Jan 2027" (answer 11), or "No points are waiting to
  expire." The card's action: **Change Points…** for a reader who may (`loyalty.points.adjust` in that
  store); nobody else sees it.
- **Live points** (answer 11): a short table — **Points** · **Expires** (date) — every live lot, soonest
  first, unpaged; hidden when there is none.
- **History**: a table, newest first — **Date** (`Time`) · **What** (the kind, in staff words: Earned,
  Used, Given back, Taken back, Added by hand, Removed by hand, Expired) · **Order** (its public number,
  plain text until Sales's screens can open it — answer 12; an em dash when none) · **Points** (+1,200
  in green, −300 in the default ink; never red for an ordinary spend) · **By** (the admin's name for a
  change by hand, "System administrator" for a Super Admin, as `StaffNames`; an em dash otherwise) ·
  **Reason** (by hand only; long reasons wrap). 50 rows, then **Show More**.
- **No account in this store** (reached only by a reader who may view the customer — above): Empty, "No
  points in this store" / "This customer has not earned or used points here."; **Change Points…** is
  still offered, on Add (Remove needs points).
- **Refusals**: an unknown customer, a customer with no account here whom the reader may not view, or a
  store the reader may not open → the panel's 404 page (the store, as every screen: the filter's
  refusal).

#### L3 · The change-by-hand dialog (answer 8)

- **One shadcn Dialog**, "Change Points" · "تعديل النقاط", opened from L2's card. At the top a shadcn
  ToggleGroup of two items: **Add** · **Remove**, Add chosen first. **Remove is out of reach, saying
  why** ("There are no points to remove."), while the live balance is 0.
- **Points**: a number box, whole numbers 1 to 1,000,000,000 (`Inputs::MOST_BY_HAND`), checked as typed;
  for Remove also "at most the balance, {balance}".
- **Reason**: a Textarea, required, 1 to 500 characters **after trimming** (as `Inputs::reason`), the
  count shown on the trimmed text ("120 / 500"); the hint "Kept in the customer's history and the audit
  log. The customer does not see it." (answer 10).
- **Before and after**: a line under the boxes, "Balance: 4,500 → 5,700" (Remove: "→ 3,300"), worked out
  as typed from the page's balance; the server's answer is the truth.
- **Buttons**: Cancel · **Add Points** / **Remove Points** (the main button names the action; out of
  reach, saying why, until both boxes pass). Enter in the points box sends. No second "Are you sure"
  (answer 8). **Remove is not red**: a correction, not a deletion **[PROPOSED P4]**.
- **After**: the dialog closes, a toast "1,200 points added." / "300 points removed.", the card and the
  history show the new entry at the top; focus goes back to Change Points….
- **Refused** — the dialog stays open, the boxes as typed, the message in it (`DialogError`), and the
  page's balance refreshed: `DeductionTooLarge` (the balance changed meanwhile), `InvalidPointsAttribute`,
  `PointsAccountNotFound` (a Remove where the account is gone or never was); **`Unauthorized`** (the
  permission taken away meanwhile) closes the dialog with the panel's refusal message, and Change
  Points… leaves the page on its next answer.

#### L4 · Points › Redemptions — `/admin/points/redemptions?store=`

- **Header**: title "Redemptions" · "الاستبدالات", subtitle "Points used on orders in this store"; the
  store filter; the programme-off Note.
- **Columns**: **Date** (`Time`) · **Order** (public number, plain text — answer 12) · **Customer** (the
  name, a link to L2 in this store; "Deleted customer", no link) · **Points** (grouped) · **Discount**
  (money). Newest first, 50 rows, then **Show More**. No filters, no totals (answer 6).
- **Empty**: "No redemptions yet" / "When customers use points on an order here, it appears here."

#### L5 · The customer page's Points section (answers 1 and 15)

- On Access's customer page (G2), under Addresses, through **`CustomerPageSections`** (access.md
  amendment 72(a)): **Points**, one block per store where the reader holds `loyalty.points.view` **and**
  the customer has an account, in the stores' order — the store's name (Off badge when off) · the live
  points · their worth · expiring next — and **View Points** (a link to L2 in that store) **only where
  L2's filter offers that store** (an off store's block, for staff who cannot switch stores, has no
  link: G2 shows them the data, as their addresses, but L2 cannot open there).
- **Not drawn at all** for a reader without `loyalty.points.view` in any store; for one who holds it
  but the customer has points in none of those stores: "No points in your stores."
- **Giving a first point**: for a reader who may change points by hand **and** open L2 in a store where
  the customer has no account yet, the section ends with **Give Points…**, a link to L2 in that store
  (the first such store; the filter there reaches the others), where Change Points… starts on Add
  **[PROPOSED P5]**. That reader may view the customer (they are on G2), so L2 answers them.
- One query for every block (`CustomerPointsInStores`), plus the reader's stores already read.

#### L6 · Settings — on Store Settings only

Not a screen of its own (answer 5), and **not in the Points menu** (answer 16): the programme's settings
are the **Points** card of Platform's settings screen (Store Settings › Settings), reached from there
only. Platform's settings cards keep their `id` of `settings-{module}` (platform.md §9.4, 2026-10-19),
and the page still scrolls to a card named in the address. The settings themselves are as built
(§1.5); each row stays the admin-only `loyalty.settings.update`'s.

#### L7 · The shop: the account's Points tab — `/{store}/{locale}/account/points`

- **Offered** in the account's side list (Access's `CustomerAccountPages`, `loyalty.points`, every
  account type, its name from `loyalty::account_pages.points`) **when the store's programme is on, or the
  customer has an account there** (answer 4) — through amendment 72(d)'s condition: the switch is read
  from the settings every shop page already holds (no query); only when it is off does the condition
  read whether the customer has an account in the store (one query, on shop pages of signed-in customers
  in such a store — the recorded counts of those pages move by one, deliberately). A customer who opens
  the address when it is not offered gets the shop's 404.
- **Drawn in `AccountLayout`**, as B2B's company page. Title "My Points" · "نقاطي".
- **Balance card**: the live points, large · "Worth {money}" · expiring next, "1,200 points expire on 3
  Jan 2027" (answer 11). **How it works** — one line from the store's two rates **[PROPOSED P3]**: "You
  earn 1 point for every 1.00 SAR. 100 points take 1.00 SAR off an order." — shown only while the
  programme is on.
- **Programme off** but points left: a Note, "Points are paused in this store. You cannot earn or use
  points for now; your points stay until they expire."
- **Live points**: as L2's, soonest first.
- **History**: newest first — **Date** · **What**, in the customer's words (answer 10): "Earned on order
  SA-10428", "Used on order SA-10428", "Given back — order SA-10428", "Taken back — order SA-10428
  returned", "Added by the store", "Removed by the store", "Expired" · **Points** (+/−). **No reason, no
  staff name** — the read already strips them (§3). 50 rows, then Show More.
- **Empty** (on, no account yet): "No points yet" / "You earn points on every delivered order."
- **Queries**: within the shop page's 8.

**Every screen above** is checked at phone width (375 px, no sideways scroll), in Arabic and English,
light and dark; its query count recorded by a feature test; its page under 60 KB gzipped.

#### As built — the build and its three reviews, 2026-10-19

Where the built screens say or do something other than the lines above, and why. Nothing here changes
an owner's answer.

- **The words have no plurals** (the screens' words never had them), so the lines quoted above read,
  in English: the expiring line "Expiring on {day}: {points}"; the toasts "Points added: 1,200." and
  "Points removed: 300."; the "how it works" line (P3) "Every 1.00 SAR you spend adds 1 to your
  points. Every 100 of your points takes 1.00 SAR off an order." The Arabic reads the same way.
- **The reads are a fixed few, not one**: `CustomerPointsInStores` reads the accounts, then their
  live lots (two queries for every block); the shop's rates and on/off are read from the programme's
  settings beside `ViewMyPoints`, not inside it. Recorded own counts: Points › Customers 5, a
  customer's points 8, Redemptions 3, the customer page's section 3 — each the same for one row as for
  six (`Feature/LoyaltyScreensTest`).
- **A store the reader may not open is the panel's 403** on every Points page, as on every store screen
  (the filter's refusal); an unknown customer, or one with no points here whom the reader may not view,
  is its 404.
- **A search typed into the address that the box would never send** (under 3 or over 100 characters)
  searches nothing; a Show More cursor given only in part is refused (422).
- **Remove is out of reach at a balance of 0** on its own choice, and the main button says why.
- **A change by hand always names its store**: one that names none is refused, never made in the first
  store that is on.
- **Points › Settings opens the settings screen with a full page load**, since an Inertia visit's
  redirect drops the "#" the screen scrolls to.
- **An amount on Redemptions** is written with its own order's currency's decimals.
- **Dates, amounts and the typed search inside a sentence are isolated** (frontend.md §1.8), so an
  Arabic sentence keeps "1.00 SAR" and an order number in their order; the points box takes digits
  typed in Arabic-Indic too.
- **Accounts by store**: an index `accounts_by_store` (`store_id`, `id`), §5.
- **Signed-in shop pages read one query more** while a store's programme is off (every store starts
  off): the account menu asks whether to offer the Points tab (access.md amendment 72(d)) - Catalog's
  recorded counts 13 → 14 (search 14 → 15). **Accepted by the owner "for now", 2026-10-19**; the
  alternative, remembering the answer in the customer's session, stays open.

---

## 5 · Tables

Schema `loyalty`. **`accounts` has a ULID id; the ledger and its working rows, high-volume and never
read by id, have `bigint` identity ids** (handoff §5.3: "`bigint` for high-volume ledgers (…
`point_entries`)"), which also order rows made in the same moment. **Every column is NOT NULL unless
marked NULL**, every points column is `bigint`, every rule is also checked in code first (handoff §5.3),
and every CHECK touching a nullable column is written so that NULL fails it where it must (lessons 35,
162).

| Table | Columns | Rules |
|---|---|---|
| `loyalty.accounts` | `id` ULID, `customer_id` → `access.customers` RESTRICT, `store_id` → `platform.stores` RESTRICT, `balance`, `created_at`, `updated_at` | UNIQUE (`customer_id`, `store_id`); UNIQUE (`id`, `store_id`); CHECK `balance >= 0` |
| `loyalty.orders` | `order_id` PK (Sales's id, text — no foreign key: Loyalty never depends on Sales), `order_number` (its public number, e.g. `SA-10428` — never the id, handoff §5.3), `account_id`, `store_id`, `currency_code`, `goods_total_minor` NULL, `discount_minor`, `points_redeemed`, `points_earned`, `delivered_at` NULL, `cancelled_at` NULL, `returned_goods_minor`, `returned_discount_minor`, `redemption_cap_percent` NULL (the `max_redemption_percent` it redeemed under, §1.8), `points_given_back`, `points_back_through_covers`, `points_reversed`, `points_counted_gone`, `points_covered`, `points_dropped`, `expired_back_unused` | (`account_id`, `store_id`) → accounts (`id`, `store_id`) RESTRICT; UNIQUE (`order_id`, `account_id`); every amount and points column `>= 0`; CHECK `delivered_at IS NULL OR goods_total_minor IS NOT NULL`; CHECK `delivered_at IS NOT NULL OR (points_earned = 0 AND goods_total_minor IS NULL AND returned_goods_minor = 0)` (a staff edit before delivery may lower the points discount, §1.8); CHECK `NOT (delivered_at IS NOT NULL AND cancelled_at IS NOT NULL)`; CHECK `(points_redeemed = 0) = (discount_minor = 0)`; CHECK `(points_redeemed = 0) = (redemption_cap_percent IS NULL)`, `redemption_cap_percent BETWEEN 0 AND 100`; CHECK `returned_goods_minor <= COALESCE(goods_total_minor, 0)`, `returned_discount_minor <= discount_minor`; CHECK `points_given_back <= points_redeemed`, `points_back_through_covers <= points_given_back`; CHECK `points_reversed + points_counted_gone + points_covered + points_dropped <= points_earned`. **The record of every order fact, written even when no point moves** |
| `loyalty.order_returns` | `order_id`, `account_id`, `return_id`, `returned_goods_minor`, `returned_discount_minor`, `settled_at` | PK (`order_id`, `return_id`); (`order_id`, `account_id`) → orders RESTRICT; amounts `>= 0`. A return settled once, whatever it moved |
| `loyalty.entries` | `id` bigint identity, `account_id`, `store_id`, `kind`, `points`, `order_id` NULL, `return_id` NULL, `reason` NULL, `staff_id` NULL → `access.staff_users` RESTRICT, `occurred_at` | (`account_id`, `store_id`) → accounts (`id`, `store_id`) RESTRICT; (`order_id`, `account_id`) → orders (`order_id`, `account_id`) RESTRICT (unchecked while `order_id` is NULL); UNIQUE (`id`, `account_id`); CHECK `kind` is one of §1.2's and `points` has its sign, never 0; CHECK `order_id IS NOT NULL` for `EARNED`, `REDEEMED`, `REDEMPTION_RETURNED`, `EARNING_REVERSED`, and `order_id IS NULL` for `ADDED`, `DEDUCTED` (an `EXPIRED` carries one only when it expires points coming back for that order); CHECK `return_id IS NULL` unless the kind is `REDEMPTION_RETURNED`, `EARNING_REVERSED` or `EXPIRED`; CHECK `ADDED`/`DEDUCTED` have `staff_id IS NOT NULL AND char_length(reason) BETWEEN 1 AND 500`, the others `reason IS NULL AND staff_id IS NULL`; UNIQUE (`order_id`) WHERE `kind = 'EARNED'`, and WHERE `kind = 'REDEEMED'`; UNIQUE (`kind`, `order_id`, `return_id`) **NULLS NOT DISTINCT** WHERE `kind IN ('REDEMPTION_RETURNED', 'EARNING_REVERSED')` (a cancellation's NULL return id counts once; precedent `platform.settings`); **append-only** (trigger, as `platform.audit_entries`) |
| `loyalty.lots` | `id` bigint identity, `account_id`, `entry_id` (the `EARNED` or `ADDED` that formed it), `order_id` NULL (the order it was earned on), `points`, `points_left`, `expires_at` | (`entry_id`, `account_id`) → entries (`id`, `account_id`) RESTRICT; (`order_id`, `account_id`) → orders RESTRICT; UNIQUE (`entry_id`); UNIQUE (`order_id`) WHERE `order_id IS NOT NULL`; UNIQUE (`id`, `account_id`); CHECK `points > 0 AND points_left >= 0 AND points_left <= points` |
| `loyalty.takes` | `id` bigint identity, `account_id`, `lot_id`, `entry_id` (the `REDEEMED` or `EARNING_REVERSED` that took them), `kind` (`REDEMPTION`, `COVER`), `points`, `back` | (`lot_id`, `account_id`) → lots; (`entry_id`, `account_id`) → entries; UNIQUE (`id`, `account_id`); CHECK `points > 0`, `back >= 0`, `back <= points` |
| `loyalty.covers` | `id` bigint identity, `account_id`, `lot_id` (the earned lot whose reversal it settles), `order_id`, `return_id` (the return that made it — NOT NULL since 2026-10-11, answer 19: only a return takes earned points back — a cover may move no point, so it is keyed to the settlement, not to an entry), `source` (`EXPIRED_POINTS`, `LOT`, `DROPPED`), `source_take_id` NULL (the `COVER` take from the covering lot), `points`, `undone` | (`lot_id`, `account_id`) → lots; (`order_id`, `account_id`) → orders; (`source_take_id`, `account_id`) → takes; CHECK `points > 0`, `undone >= 0`, `undone <= points`; CHECK `(source = 'LOT') = (source_take_id IS NOT NULL)` |
| `loyalty.lot_movements` | `id` bigint identity, `account_id`, `lot_id`, `entry_id`, `points` (+ onto the lot, − off it) | (`lot_id`, `account_id`) → lots; (`entry_id`, `account_id`) → entries; CHECK `points <> 0`; **append-only** — every lot's history |

**Indexes:** accounts by (`store_id`, `id`) — a store's accounts, for Points › Customers (§4.1,
2026-10-19); lots by (`account_id`, `expires_at`, `id`) WHERE `points_left > 0` — spending and the next
expiry; lots by `expires_at` WHERE `points_left > 0` — the sweep; takes by (`lot_id`, `id`) — a lot's
takes, oldest first; takes by `entry_id`; covers by (`lot_id`, `id`) — a lot's covers, oldest first; entries by (`account_id`,
`occurred_at` DESC, `id` DESC) — the history; entries by (`store_id`, `kind`, `occurred_at` DESC, `id`
DESC) — the redemptions list (keyset paging, handoff §5.4); movements by `lot_id` and by `entry_id`.

---

## 6 · Events

**Published:** none in this stage. Nothing listens to points yet; Ops (stage 8) adds the events it
needs to tell customers — earned, about to expire.

**Consumed:** none. `CustomerAnonymized` needs nothing from Loyalty (§1.11).

The `processed_events` and outbox tables are not needed: Loyalty is called synchronously inside Sales's
transaction, and every call is idempotent by its order or return id (`loyalty.orders`,
`loyalty.order_returns`).

---

## 7 · Errors

Each extends the module's `LoyaltyError`, which extends `Shared\Domain\Error\DomainError`, with its own
type string and HTTP status.

| Error | Status | When |
|---|---|---|
| `PointsProgrammeOff` | CONFLICT | Redeeming in a store whose programme is off |
| `RedemptionChanged` | CONFLICT | At placement, the redemption no longer works out as quoted — the balance spent elsewhere, the settings changed — so checkout quotes again (§1.7) |
| `RedemptionRefused` | INVALID | Placing a redemption the rules refuse — the same reasons as `RedemptionRefusal` |
| `DeductionTooLarge` | CONFLICT | A deduction by hand larger than the balance (§1.4) |
| `PointsAccountNotFound` | NOT_FOUND | A staff read or change in a store the staff member does not cover, or for a customer with no account there where one is needed — the same answer for both |
| `OrderNotSettleable` | CONFLICT | A delivery of a cancelled order; a cancellation of a delivered order; a return of an order whose delivery Loyalty never recorded (§1.8). A cancellation of an order Loyalty has no record of — it used no points and was never delivered — settles nothing and is not an error |
| `InvalidPointsAttribute` | INVALID | A value refused: points not a positive whole number, a reason empty or too long, returned amounts past the order's, a currency that is not the store's, a store that is not the order's recorded store, a caller's permission that is not its own module's |

**At placement** (owner, 2026-10-11): a quoted redemption that the rules refuse when worked out again
— the balance spent elsewhere, a setting changed — is `RedemptionChanged`, as one that works out
differently is, so checkout quotes again and shows the customer what now applies.
`RedemptionRefused` answers a placement no quote could give — no points to redeem at all, or a
discount of 0 — with its reason.

`quoteRedemption` refuses nothing: it answers with a `RedemptionRefusal` the checkout shows. A Global
permission named by a caller is the caller's programming error, which Access's authorizer already
refuses.

---

## 8 · Test scenarios

**Earning**
- 1,234.56 SAR earns 1,234 points at 1 per SAR; 0.99 SAR earns nothing, but the delivery is recorded.
- A store with a 3-decimal currency earns on the right exponent.
- Delivered twice: earned once. Delivered with the programme off, then retried after it is on: still
  nothing. A delivery of a cancelled order is refused.
- A company account earns as an individual does. Earned points can be redeemed at once.

**Redeeming**
- `PARTIAL` uses the chosen points; `ALL_OR_NOTHING` the whole balance; each audience reads its own
  switch; the quote says which mode applies.
- Over the cap: up to the cap, the rest kept — in both modes; the discount never passes the cap.
- At 150 points per unit off (worth less than a halala each): 4 points asked charges 3; the cap
  formula finds the largest count that fits. At 3 points per unit off: never charged a point that
  bought nothing.
- A discount of 0, a `PARTIAL` ask of 0 or above the balance, below the minimum: refused with the right
  refusal, nothing charged.
- Two placements at once on one balance: one succeeds, the other is refused; never below 0.
- The settings changed between quote and placement: `RedemptionChanged`.
- Points come from the lot that expires first; a lot whose date has just passed is never counted or
  spent, even before the nightly sweep.
- Redeemed twice for one order: once.

**Cancellations and returns**
- Cancelled: points come back to their own lots with their old dates; a lot expired meanwhile — its
  points come back expired, and the history shows both.
- The owner's example (§1.8), then B cancelled: B's 200 come back to nothing; the same final balance as
  cancelling B first.
- §1.8's "with other points" example, both sequences: A returned then B cancelled, and B cancelled then
  A returned — 150 both ways, L2's 150 back with its own date.
- A returned then B cancelled never revives A's reversed points: B's points first undo A's lot's
  covers, and a dropped cover's share goes nowhere.
- A lot's covers are undone lot covers first, oldest first, then expired-point covers and drops.
- A documented sequence where a third order settled in between changes the end (§1.3, answer 15) —
  pinned, so a change to the rule is noticed.
- The order's own unspent points are taken back first: used 300 since expired, earned 200 unspent,
  returned — ends with 0 (answer 16).
- Earned points that expired unused, or that an admin removed, count as taken back, never taken twice.
- Owed points beyond everything the customer has: dropped, recorded on the order; never below 0.
- An order returned in three parts lands its running totals exactly on the order's points — given back,
  taken back, covered, dropped and counted-expired — and every CHECK holds after each part.
- A return settled twice, a cancellation settled twice: once each — even when it moved no point.
- A staff edit that lowers the points discount gives back the points above it, with their old dates,
  once per edit; a new discount above the old is refused; nothing is taken back.
- `pointsAfterEdit` answers the least of the discount now, the cap on the edited `net_subtotal` (by the
  order's recorded percent, though the store's changed since) and the room passed; `orderEdited` answers the same discount and points; the `loyalty.orders` CHECKs hold for
  an edit settled before delivery, and a later full return still lands exactly on the order's points.
- An order with `goods_total` 0 or no points discount settles without dividing by zero.

**By hand**
- Adding forms a lot with the period's date and is audited with the reason; a Super Admin's name reads
  as "System administrator" to anyone else.
- Deducting more than the balance is refused; a reason is required; deducted points never come back.
- Only an admin role may hold `loyalty.points.adjust` or `loyalty.settings.update`; a staff role cannot
  be given either.

**Expiry**
- The sweep expires exactly the live lots whose date passed, one entry each; empty lots need none.
- Changing the period moves no existing date.

**Permissions and scope**
- Loyalty checks the caller's permission in the store recorded on the order, whatever store was
  passed; a mismatch is refused; so is a permission of another module.
- A customer reads only their own points; staff read a store's points only where they hold
  `loyalty.points.view`.
- `earningPreview` and `quoteRedemption` change nothing.

**The database**
- Every CHECK refused, with each nullable column left NULL (lesson 162); the composite keys refuse a
  lot, take, cover, movement or entry of another account or store, and an order entry of another
  account; the ledger and the movements refuse UPDATE, DELETE and TRUNCATE; the unique indexes refuse
  a second `EARNED` or `REDEEMED` for one order and a second settlement of one return or cancellation.

---

## 9 · Open questions

### 9.1 The owner's answers, 2026-10-07

1. ALL_OR_NOTHING over the cap: **use points up to the cap, keep the rest.**
2. Points an order used, when it is cancelled or returned: **back, with their old expiry dates.**
3. Changes by hand: **yes, with their own permission, a reason, audited** — "admins hold it by
   default", then (answer 12) **admin-only**.
4. A store's programme: **switched per store, off until an admin turns it on.**
5. Who earns and spends: **individuals and company accounts.**
6. Where the earning is previewed: **the cart and checkout.**
7. Reminders before expiry: **not now — the account shows the dates; reminders come with Ops.**
8. The rates: **points per 1 unit spent, and points for 1 unit off.**
9. **"There is no minus points ever"** — confirmed: **a balance never goes below 0; what cannot be
   taken back is dropped**, and the customer keeps the discount. Replaces handoff §11.5's negative
   balances and their checkout warning.
10. Points given back that had expired: **they first cover what is taken back** ("the returned points
    stick to their old date").
11. Earned points that expired unused, when the order is returned: **counted as already taken back.**
12. Who changes points by hand and the programme's settings: **both admin-only.**
13. The admin menu: **a new Points section** (a `Points` permission group).
14. When earned points can be used: **right after delivery** (not after the order's return window,
    which was offered as the simpler model).
15. Earned points spent on another order when their order is returned: **"take them from other points
    first"**, then drop the rest — accepting that the order in which different orders are settled can
    change a balance (offered against "never touch other points", which always ends alike).
16. An order returned with its own earned points unspent: **"0: the 200 are taken back"** — expired
    given-back points cover only points spent on other orders.
17. **A staff edit keeps the rules the order was placed under** — the points cap among them
    (2026-10-10, asked with Sales).
18. **At placement, a quoted redemption the rules now refuse is `RedemptionChanged`** — "your
    discount changed", checkout quotes again (2026-10-11, asked while building step 3; §7).
19. **The four further database rules the step 2 reviews found** — an expiry entry naming a return
    names its order; a cover's return id; a bound on `expired_back_unused`; an entry's return id
    keyed to `order_returns` — **are added with step 4**, where those rows are first written
    (2026-10-11).

### 9.2 Still open

1. **Where `Audience` lives.** **Loyalty keeps its own enum** — agreed with the stage 5 session on
   2026-10-07. **There are no company prices** (owner, 2026-10-07: "we dont have two kind of pricings";
   one price set for everyone), so Pricing has no audience. Individual vs company still decides what a
   buyer may see and buy (handoff §6, amended 2026-10-07) and Loyalty's per-audience redemption mode
   (§6); whether one Shared enum serves Loyalty, Promotions, Catalog's visibility and Sales is the
   owner's call (handoff §4.5's three-module rule).
2. ~~**Loyalty's screens**~~ — **settled** (owner, 2026-10-18/19): the stage 6 screens session builds
   them, as §4.1 says; the answers are §9.4.
3. **Every assumption marked above** — accepted with the spec (owner, 2026-10-10) — the defaults and ranges, the programme read at delivery, the
   minimum measured on points charged, giving back in the order spent, deducted points counting as
   taken back, a lot's covers undone lot covers first and oldest first, an undone expired-point cover
   not used again, changes by hand while off, nothing on anonymizing, staff seeing points by the
   points' store.

### 9.3 The independent reviews of the draft (2026-10-07)

Two read-only reviews; each finding was checked in the code or worked by hand before acting.

**The first** found three blockers and fourteen should-fix points. Fixed: order facts and running
totals with nowhere to live, and zero-point facts with no record (`loyalty.orders`,
`loyalty.order_returns`); debt and expiry depending on the order of operations, and expired earned
points taken twice (the owner's answers 9–11); a cancellation recordable twice behind a NULL return id
(NULLS NOT DISTINCT); CHECKs a NULL or a negative row could pass, and rows that could cross accounts
(NOT NULL, composite keys); an index naming another table's column (`store_id` on entries); ULID ids on
the ledger (handoff §5.3); a charge formula that could overcharge; the return share's basis; refills
not recorded; points counted after their date; the caller's scope; the customer's own read; the menu
section; Super Admins' names; order numbers; store binding; `bigint` points; account creation racing;
the redemption mode in the quote.

**The second** found one blocker — **points could be created or lost depending on which of two orders
was settled first**, when one spent the points the other earned — and five should-fix points. Fixed: a
point remembers where it is (takes and covers, §1.3, §1.8; the owner chose immediate use, answer 14);
how a partial return counts what was already settled, and expired given-back points kept for later
parts of the same order (`expired_back_unused`); giving back in the order spent, so parts end as a
whole; the arithmetic when `points_per_unit_off` does not divide `10^e` (§1.5); reads counting dead
points (§1.1, §1.10); the scope taken from the order's recorded store and order entries bound to their
order's account; NULL-safe CHECKs, the lot's flag dropped for its date, keyset tiebreakers, the
handoff's other §11.5 lines (amended) and §14's "Customers › Loyalty" (§3), Sales's obligation on the
shares, and the API reads' permission.

**The third**, aimed at that answer, worked six scenarios by hand and checked thousands of random
histories with a throwaway model: covers recorded per take still let the result depend on the order of
settlement, and so does any taking from the customer's other points — what is live depends on
unrelated orders. Only "never touch other points" (debt on the lot) passed every history. The owner
chose to **take from other points first** and accept the order-dependence (answer 15), and that an
order's own unspent points are taken back first (answer 16). Also fixed: covers moved from takes to
the lot, so points coming back from any of a lot's takes undo its covers in one fixed order; covers
keyed to their settlement (a cover may move no point, so it can have no entry); undone expired-point
covers and points that went nowhere never count again; which landed lot covers first; the handoff's
"points given back keep their old expiry dates" qualified.

### 9.4 The owner's answers for the screens, 2026-10-19

Asked by the stage 6 screens session; every one as recommended. Written into §4.1.

| # | Question | Answer |
|---|---|---|
| 1 | Where staff see a customer's points, when the customer page opens by the **home** store and points belong to the store they were earned in | **Both**: a Points section on the customer page (an Access registry of sections, amendment 72(a)) **and** a Points › Customers list of the store's holders, each opening the customer's points |
| 2 | Searching that list | **By name, email or phone** — a new Access search (amendment 72(b)) |
| 3 | The store on Loyalty's staff screens | **The screen's own `?store=` filter**, as every store screen; none given opens the first store that is on. Only Home has All Stores (its figures); Loyalty has no all-stores view, every read being one store's |
| 4 | When the shop account shows its Points tab | **When the store's programme is on, or the customer has points or history there** |
| 5 | Where admins change the programme's settings | **On Platform's settings screen**, as built; the Points menu's Settings entry opens it at the Points card |
| 6 | Filters and totals on Redemptions | **None**: date, order, customer, points, discount |
| 7 | A customer registered in another store, on Redemptions | **Their name is shown**, as Promotions does; an anonymized customer reads "Deleted customer", with no link |
| 8 | Changing points by hand | **One dialog, Add or Remove**: points, a reason of 1 to 500 characters, the balance before and after, Remove capped at the balance; a toast after; no second confirmation |
| 9 | Changing points while the programme is off | **Allowed, with a note** that the programme is off |
| 10 | What the customer's history says | **Plain words**; the reason of a change by hand is **never** shown to the customer |
| 11 | Expiry | **The soonest expiry as a headline**, then the list of live points with their dates |
| 12 | Order numbers | **Plain text until Sales's screens exist**, then links |
| 13 | Paging | **Show More** under each list, no page numbers — as Geist and shadcn do |
| 14 | Points elsewhere in the shop (the header, product pages) | **Not now**; the cart's and checkout's preview come with Sales's screens |
| 15 | The customer page's Points section | **One block per store** the reader covers where the customer has points, as its addresses are grouped |
| 16 | Points › Settings in the menu (owner, 2026-10-19, after seeing the screens) | **Removed**: the settings are reached from Store Settings only; `/admin/points/settings` is gone. Reverses answer 5's menu entry |

**For Ops (owner, 2026-10-19)**: staff sending customers a message — all, some, or one by name; in the
account's notifications, by email or by SMS, as the staff member chooses — for example to explain a
change of points. Not Loyalty's and not now: written into handoff §13.3 so Ops specifies it.

### 9.5 Proposed with the screens — built as recommended

Mine, written 2026-10-19 while the owner was away (their go: "start until all done"); built as
written, for the owner to review with the built screens. Each says what changing it later costs.

| # | Proposal | Changing it later |
|---|---|---|
| P1 | **Only the name** of a customer registered in a store whose customers the reader may not view — no email, no phone — on Points › Customers and Redemptions; on Points › Customers the email shows under the name for a customer the reader may open (Redemptions shows names only, as L4 says) | One field in the list's row |
| P2 | **A search that matches 1,000 customers or more** asks for more of the text and shows none, rather than a partial list that would look complete | The search's cap and the message |
| P3 | **A "how it works" line** on the customer's Points tab from the store's two rates ("You earn 1 point for every 1.00 SAR. 100 points take 1.00 SAR off an order."), only while the programme is on | One line and two values in the read |
| P4 | **Remove Points is not red**: a correction, not a deletion; the dialog's main button names the action | A button variant |
| P5 | **Give Points…** on the customer page's section, for a reader who may change points and open L2 in a store where the customer has none yet — otherwise only customers who already hold points could be given any | A link |
| P6 | **The search matches email and phone only among customers the reader may already view** (names in every store), so an email or phone typed in never reveals who owns it — the review of 2026-10-19 found that matching them everywhere would show more than answer 7's "the name only" | The search's query, in Access |
| P7 | **`ViewCustomerPoints` answers "not found" for a customer with no account in the store** unless the reader may view that customer (access.md amendment 44(c)) — the review found that today any points reader can confirm a customer id and read the name. **Not applied to adding a first point by hand** (`AddPoints`): that needs the admin-only job and a guessed id, and the backend's tests give such admins no customer view; **the owner, 2026-10-19: left as is** | One check in the handler |
| P8 | **Handoff §14's "Customers › Loyalty" entry goes**: Points › Customers and the customer page's section (answers 1, 15) cover it; a third way in would be the same list twice in the menu | One menu entry |
