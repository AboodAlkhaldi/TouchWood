# Sales

The cart, checkout, orders and their money, shipping and delivery, staff edits and returns: the
transaction. The specification is [`docs/modules/sales.md`](../../../docs/modules/sales.md) (accepted
2026-10-10, the owner's answers of 2026-10-19 in §9.1b, 31–44).

Backend only in this stage's build; the screens are another session's. Shipping and Payments are stage
7's: since stage 7 step 6 the quote asks `ShippingApi::quote` (the carrier picked and snapshotted, or the
fee to be set by staff; no VAT on shipping), and Payments is still to come — no online payment yet.

## Build steps

| Step | What | Where |
|---|---|---|
| F0 — the contract Feedback reads | `SalesApi` (`deliveredPurchase`, `returnedProducts`), `ReturnCompleted`, answered by the placeholder `Infrastructure/External/NothingSoldYet` until S10b replaced it with `DatabaseSalesApi` | `Public`, built with Feedback |
| S1 — foundation | The `sales` schema; the fourteen permissions of §3 (every customer's cart and orders and every guest's cart, per store; the staff jobs under Orders; `sales.settings.update` admin-only; the system's reserved `sales.system.run`); the three store settings of §1.10 (flat fee 0, free from 0 — never free —, return window 14 days); the errors of §7 in both languages; `OrderPlaced`, `OrderCancelled`, `OrderDelivered`; stage 7's `ShippingApi` and `PaymentsApi` (with `PaymentAudience`, `PaymentSucceeded`, `PaymentFailed`), provisional and unbound; the owner's answers 41–44 in the spec | below |
| S2 — the outbox | `Shared/Infrastructure/Outbox` (platform.md §5.6; a recorded exception to Shared's ceiling, answer 43): `Outbox::record` in the change's transaction; `OutboxRelay` every minute from one server (queued as Platform's `RelayOutboxJob`) — claimed under `FOR UPDATE SKIP LOCKED` and leased before dispatch, retried 1, 2, 4 … minutes up to an hour, never dropped, logged from the tenth attempt, dispatched rows kept 30 days; `ProcessedEvents::once` for idempotent consumers; tables `public.outbox_messages`, `public.processed_events` | `src/Shared/Infrastructure/Outbox`, `database/migrations` |
| S3 — what Sales reads of Catalog and Access | catalog.md amendment 29: `storeVariants` (many sizes in a store in one read) and `productsOpenTo` (which products the category switches leave open to a shopper); access.md amendment 74: `GuestIdentity` (`current`, `ensure` — the guest cart's id, its cookie Access's) | `src/Modules/Catalog/Public`, `src/Modules/Access/Public` |
| S4 — the cart | Tables `sales.carts`, `sales.cart_lines`; `AddCartLine` (permissive: any size, either mode, 1 to 1,000,000, the same size and mode once; a signed-in individual never wholesale — answer 32; a guest's first line creates the guest), `ChangeCartLine`, `RemoveCartLine`, `SetCartCoupon` (a customer's only; a guest is told to sign in); a guest's carts move or merge at sign-in, the lines the account may not buy leaving with why (`GuestCartMover`, `MoveGuestCart`); an anonymized account's carts deleted (`ForgetCustomer`, which from S6b also clears its orders' email); carts untouched for 90 days emptied each night (`EmptyOldCarts`) | below |
| S5–S11 — the quote, placing, decisions, money, fulfilment, edits, returns, the Best-Selling ranks, the reads | One step each, every one in the list below (S11a–S11c the ranks and the reads: §3.1, §3.2) | below |
| Stage 7 step 6 — shipping wired in (sales.md §9.5 #1–#3, #11, #12; §9.6) | `Quote/ShippingQuotes` and `Quote/QuoteShipping` (the quote's step 9: served, not covered, outside; another store's country refused — `AddressInOtherStore`); `Domain/Model/OrderShipping` (the fee, its rule, the ordered carrier, the fee to be set) and `Shipment`; `Order/ShipmentInputs` (one of the store's carriers or "Other carrier", a typed link wins over the pattern); `ShipOrder` refused while the fee is to be set (`ShippingFeeNotSet`); `CorrectShipment`; `SetShippingFee`; staff address changes re-quoted (`OrderEditing::shipping`, gap 6.1); returns refund the shipping with no VAT share; the flat-fee settings removed; migration `2026_10_30_600000_wire_shipping_into_orders` | `Application/Quote`, `Application/Order`, `Application/Command/{ShipOrder,CorrectShipment,SetShippingFee}`, `Domain/Model` |
| S12 — the module pass | A whole-module review: every refused value worded in both languages (tested against the code), `OrderSnapshot` reading sizes through `SizeRecords`, an unused per-piece helper removed, the spec's and this file's stale lines, the §8 scenarios still untested (placing, fulfilment, decisions, edits, returns, the tables). Left untested: a store's VAT rate changed under an edit and a VAT of 0 (the rate is Platform's cached store row, changed only through its own command), a retail and a wholesale line of one size held as one (Inventory's hold-line key) | below |

## What is here

- **`Infrastructure/SalesServiceProvider`** — loads the migrations and the words, declares the
  permissions through Access and the settings through Platform; binds `SalesApi` to `DatabaseSalesApi` (S10b).
- **`Application/SalesPermissions`**, **`Application/SalesSettings`**.
- **`Infrastructure/Persistence/SalesSchema`** and its migration — the `sales` schema, on the
  connection's search path so a fresh migration wipes it.
- **`Domain/Exception`** — the errors of spec §7, each with its type (`sales.{snake}`), its status, and a
  title and a message in Arabic and English at `sales::errors.{snake}`.
- **`Public/Events`** — the critical events (handoff §4.5), ids only, published through the outbox (S2) after
  commit: `OrderPlaced`, `OrderCancelled`, `OrderDelivered`, `ReturnCompleted`.
- **`Domain/Model`** — the machines' states (`OrderState`, `ReturnState`) and the rows a change reads
  (`OrderLine`, `OrderForEdit`, `EditedTotals`, `MoneyRecord`, …); `Infrastructure/Eloquent/Moment` turns
  the database's times into moments.
- **The cart** (S4): `Domain/Model/{Cart,CartLine,RemovedLine}`, `Domain/Repository/Carts` with SQL in
  `Infrastructure/Eloquent/DatabaseCarts` (a change locks the cart's row first; the first line of a buyer
  made under the buyer's own key, so two at once make one cart); `Application/Cart/CartBuyer` (who the
  cart is — the customer, or the guest this browser is, made only once a first line passed every check),
  `CartInputs`; the commands `AddCartLine`, `ChangeCartLine`, `RemoveCartLine`, `SetCartCoupon`,
  `EmptyOldCarts` (the system's, nightly through `Infrastructure/Queue/EmptyOldCartsJob`);
  `Application/Cart/GuestCartMover` behind `Infrastructure/Listener/MoveGuestCart` (synchronous, never
  failing a sign-in) and `ForgetCustomer` (queued: the carts deleted, and the orders' email cleared — §1.12).
- **The quote** (S5): `Application/Quote/QuoteBuilder` (§1.2's twelve steps, in order; a refused
  coupon or points carried, never failing it) and `QuoteView` (the itemised answer, kept whole as the
  row's `result`); `Domain/Model/Quote`, `Domain/Repository/Quotes` with SQL in
  `Infrastructure/Eloquent/DatabaseQuotes`; the commands `StartCheckout` (a signed-in customer's cart
  in the store) and `DeleteExpiredQuotes` (the system's, nightly through
  `Infrastructure/Queue/DeleteExpiredQuotesJob`); `Public/Enums/{WayToPay,OrderAudience}`.
- **The order's tables** (S6a): `sales.orders`, `order_lines`, `order_edits`, `order_ticks`,
  `order_history` (edits and history append-only, by trigger), each machine's rules a CHECK;
  `Domain/Service/OrderShares` (the coupon's, points' and VAT's shares a line — §1.8; a piece's part by
  running totals is `ReturnRefunds`', S10); `Domain/Repository/OrderNumbers` as one sequence a store from 1 (shown `SA-0001`)
  (`Infrastructure/Eloquent/OrderNumberSequences`, made for a store opened later by
  `Infrastructure/Listener/CreateOrderSequence`).
- **Placing** (S6b): `Application/Quote/QuoteRules` (the rules that can change in 15 minutes, checked at
  checkout on the cart and at placement on the quote), `Application/Order/OrderSnapshot` (the order as its
  quote placed it), `Domain/Model/{NewOrder,NewOrderLine,PlacedOrder}`, `Domain/Repository/Orders` with
  SQL in `Infrastructure/Eloquent/DatabaseOrders`; the command `PlaceOrder` (one transaction, locks in one
  order, `OrderPlaced` through the outbox).
- **Decisions** (S7a): `Application/Order/{OrderAccess,OwnOrderAccess}` (which orders staff and a customer
  may reach), `OrderUndoing` (points back, then the stock freed; the history row and the audit entry),
  `OrderInputs`; the commands `ApproveOrder`, `RejectOrder`, `CancelOrder`, `CancelOwnOrder` (answers
  whether a refund is due), `ClearShortFlag`; `Infrastructure/Listener/FlagShortOrders` (queued, on
  Inventory's `HoldsShort`); `Public/Enums/CancelReason`.
- **Money** (S7b): `sales.payments` / `sales.refunds` (a trigger keeps each record, voided once at most);
  `Domain/Repository/MoneyRecords` with SQL in `Infrastructure/Eloquent/DatabaseMoneyRecords`;
  `Application/Order/MoneyRecording` (one recorder for payments and refunds — pressing twice records once);
  the commands `RecordPayment`, `RecordRefund`, `VoidMoneyRecord` (answer 42), `AllowShipUnpaid`.
- **Transfer documents** (S7c): `sales.transfer_documents`; `UploadTransferDocument` (up to 3, PDF, JPEG or
  PNG, private, through Platform's `uploadMediaFor`); `Infrastructure/Media/SalesFilesUsage` (Platform
  never deletes one an order holds).
- **Fulfilment** (S8): `ShipOrder` (once per order; Inventory's `ship` last), `TickReducedInProvider` (a
  wired store's gift and stock-dependent sizes), `MarkDelivered` (the return window fixed, `OrderDelivered`,
  the points earned), `MarkCameBack` (undelivered, nothing due, the points used back, only the pieces whose
  stock was taken put back).
- **Edits** (S9): `Application/Order/OrderEditing` (an edit worked out, §1.7 steps 1–6: parts kept at their
  prices, added parts at today's, the order's own ceiling, coupon only shrinking, points giving way first,
  its own shipping, the gift by staff's choice) into an `EditPlan`; `SizeRecords`; the query
  `PreviewOrderEdit` and the command `EditOrder` (one transaction; every share again; Promotions, Loyalty,
  then Inventory's `adjustHold` last).
- **Returns asked and decided** (S10a): `sales.returns`, `sales.return_lines`, `sales.return_photos`;
  `Domain/Repository/Returns` with SQL in `Infrastructure/Eloquent/DatabaseReturns`; `Application/Return`
  (`ReturnAccess`, `OwnReturnAccess`, `ReturnInputs`, `ReturnChanges` — the order locked, then the return);
  the commands `RequestReturn`, `WithdrawReturn`, `UploadReturnPhoto` (the customer's), `AcceptReturn`,
  `RefuseReturn`, `CloseReturn` (staff's); `Public/Enums/ReturnReason`. `SalesFilesUsage` keeps a photo a
  return holds too.
- **Returns received and completed** (S10b): `ReceiveReturn` (Inventory's `returned` last, for the pieces
  going back), `CompleteReturn` (`Domain/Service/ReturnRefunds` — running totals, the shipping with the
  last bought piece; `due` lowered; Loyalty's `orderReturned`; `ReturnCompleted`);
  `Infrastructure/Eloquent/DatabaseSalesApi` answers `SalesApi` (the placeholder `NothingSoldYet` removed).
- **The customer's reads** (S11b-1, sales.md §3.1): `Domain/Service/OrderStatuses` (`PaymentStatus`,
  `CustomerOrderStatus`); `Query/ListOwnOrders`, `Query/ViewOwnOrder`, `Query/ViewOwnReturn` through
  `Query/Own/OwnReader` (`sales.order.view_own`), SQL in `Infrastructure/Eloquent/DatabaseOwnReads`;
  `Query/ViewCart` (S11b-2: each line's card, today's prices, `CartLineProblem`, the subtotal; §4.2 B12: `CartBlock`,
  the goods total after the coupon typed, the shipping, the next gift, the points); `Query/CountCartPieces` (B13,
  the header's count, through `CartBuyer::who`, which reads no customer); `Presentation/Storefront/CartSection`
  (B15, the shop product page's `sales.cart`: Add to Cart per size); `OwnReturnRow.staffReason` (B16, the refusal's
  words on the order's returns); `Command/DismissCartNotice` (B17, Close on the cart's notice, for good).
- **Staff's reads** (S11c-1, sales.md §3.2): `Query/ListOrders`, `Query/ViewOrder`, `Query/PrintOrder` through
  `Query/Staff/StaffReader` (customers by name, staff names, exponents), SQL in `Infrastructure/Eloquent/DatabaseStaffReads`;
  `Query/OrderLines` shared by the customer's and staff's order views; `Query/Staff/OrderStores` (an off store
  listed only while unfinished, §1.12) behind `Query/ListOrderStores`. S11c-2: `Query/ListReturns`,
  `Query/ViewReturn`, `Query/OpenOrderFile` (a transfer document or a return photo, an expiring link), SQL in
  `Infrastructure/Eloquent/DatabaseStaffReturnReads`. For the Sales screens (2026-10-27): `ListOrders`'s
  filters, search and customer, `Query/ListCarriers`, `Query/ListCustomerOrders` behind the customer page's
  `Presentation/Http/Resource/OrdersCustomerSection` (`sales.orders`); payments only of the whole balance
  (`Order/MoneyRecording`). PR B: `Command/BuyAgain`, `Query/SearchEditSizes`, `Query/PreviewReturnRefund`,
  `ListReturns`' `CLOSED_ALL`.
- **The Best-Selling ranks** (S11a): `PushSalesRanks` (nightly through `Infrastructure/Queue/PushSalesRanksJob`;
  `Orders::piecesSold`) pushes each store's whole ranking to Catalog's `ListingFacts::salesRanks` (catalog.md
  amendment 30).

Tests: `tests/Modules/Sales/Integration` — `SalesPermissionsTest`, `SalesSettingsTest`,
`SalesErrorMessagesTest`, `SalesSchemaTest`, `CartTest`, `CartTablesTest`, `CartRaceTest`, `QuoteTest`, `OrderTablesTest`, `PlaceOrderTest`, `PlaceOrderRaceTest`, `OrderDecisionsTest`, `OrderDecisionsRaceTest`, `OrderMoneyTest`, `TransferDocumentsTest`, `OrderFulfilmentTest`, `OrderEditTest`, `ReturnRequestsTest`, `ReturnRequestsRaceTest`, `ReturnCompletionTest`, `SalesContractTest`, `SalesRanksTest`, `OwnOrdersTest`, `CartViewTest`, `StaffOrdersTest`, `StaffReturnsTest`, and
`tests/Modules/Sales/Unit/OrderSharesTest`, `ReturnRefundsTest`, `OrderStatusesTest`; the outbox's in
`tests/Shared/Integration/Outbox*Test`.

## For Ops (sales.md §2.2, §6; 2026-10-28)

- `Public/Events`: `OrderApproved`, `OrderRejected`, `OrderEdited`, `OrderShipped`, `OrderCameBack` (their order
  handlers), `ReturnRequested`, `ReturnAccepted`, `ReturnRefused`, `ReturnReceived` (their return handlers),
  `PaymentRecorded`, `RefundRecorded` (`Application/Order/MoneyRecording`) — each through the outbox in its change's
  transaction. Tests: `tests/Modules/Sales/Integration/SalesOpsEventsTest.php`.

## Ops' reads (sales.md §2.5; 2026-10-29)

- `Public/Contracts/SalesReports` (bound to `Infrastructure/Eloquent/DatabaseSalesReports`): `orderForMessage`,
  `returnForMessage`, `moneyRecordForMessage`, `counts`, `moneyOn`, `transfersWaiting`, `revenueByDay` (refunds by the return's asked day,
  the waiting ones estimated with `Domain/Service/ReturnRefunds`), `productSales`. Tests: `tests/Modules/Sales/Integration/SalesReportsTest.php`.
