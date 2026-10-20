# Sales

The cart, checkout, orders and their money, shipping and delivery, staff edits and returns: the
transaction. The specification is [`docs/modules/sales.md`](../../../docs/modules/sales.md) (accepted
2026-10-10, the owner's answers of 2026-10-19 in §9.1b, 31–44).

Backend only in this stage's build; the screens are another session's. Shipping and Payments are stage
7's: Sales declares what it needs of them (`Modules\Shipping\Public`, `Modules\Payments\Public`,
provisional) and, until they exist, uses its flat shipping fee and offers no online payment.

## Build steps

| Step | What | Where |
|---|---|---|
| F0 — the contract Feedback reads | `SalesApi` (`deliveredPurchase`, `returnedProducts`), `ReturnCompleted`, answered by the placeholder `Infrastructure/External/NothingSoldYet` until S10b replaced it with `DatabaseSalesApi` | `Public`, built with Feedback |
| S1 — foundation | The `sales` schema; the fourteen permissions of §3 (every customer's cart and orders and every guest's cart, per store; the staff jobs under Orders; `sales.settings.update` admin-only; the system's reserved `sales.system.run`); the three store settings of §1.10 (flat fee 0, free from 0 — never free —, return window 14 days); the errors of §7 in both languages; `OrderPlaced`, `OrderCancelled`, `OrderDelivered`; stage 7's `ShippingApi` and `PaymentsApi` (with `PaymentAudience`, `PaymentSucceeded`, `PaymentFailed`), provisional and unbound; the owner's answers 41–44 in the spec | below |
| S2 — the outbox | `Shared/Infrastructure/Outbox` (platform.md §5.6; a recorded exception to Shared's ceiling, answer 43): `Outbox::record` in the change's transaction; `OutboxRelay` every minute from one server (queued as Platform's `RelayOutboxJob`) — claimed under `FOR UPDATE SKIP LOCKED` and leased before dispatch, retried 1, 2, 4 … minutes up to an hour, never dropped, logged from the tenth attempt, dispatched rows kept 30 days; `ProcessedEvents::once` for idempotent consumers; tables `public.outbox_messages`, `public.processed_events` | `src/Shared/Infrastructure/Outbox`, `database/migrations` |
| S3 — what Sales reads of Catalog and Access | catalog.md amendment 29: `storeVariants` (many sizes in a store in one read) and `productsOpenTo` (which products the category switches leave open to a shopper); access.md amendment 74: `GuestIdentity` (`current`, `ensure` — the guest cart's id, its cookie Access's) | `src/Modules/Catalog/Public`, `src/Modules/Access/Public` |
| S4 — the cart | Tables `sales.carts`, `sales.cart_lines`; `AddCartLine` (permissive: any size, either mode, 1 to 1,000,000, the same size and mode once; a signed-in individual never wholesale — answer 32; a guest's first line creates the guest), `ChangeCartLine`, `RemoveCartLine`, `SetCartCoupon` (a customer's only; a guest is told to sign in); a guest's carts move or merge at sign-in, the lines the account may not buy leaving with why (`GuestCartMover`, `MoveGuestCart`); an anonymized account's carts deleted (`ForgetCustomer`, which from S6b also clears its orders' email); carts untouched for 90 days emptied each night (`EmptyOldCarts`) | below |

## What is here

- **`Infrastructure/SalesServiceProvider`** — loads the migrations and the words, declares the
  permissions through Access and the settings through Platform; binds `SalesApi` to `DatabaseSalesApi` (S10b).
- **`Application/SalesPermissions`**, **`Application/SalesSettings`**.
- **`Infrastructure/Persistence/SalesSchema`** and its migration — the `sales` schema, on the
  connection's search path so a fresh migration wipes it.
- **`Domain/Exception`** — the errors of spec §7, each with its type (`sales.{snake}`), its status, and a
  title and a message in Arabic and English at `sales::errors.{snake}`.
- **`Public/Events`** — the critical events (handoff §4.5), ids only, after commit; published through the
  outbox once it exists (step S2).
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
  `Domain/Service/OrderShares` (the coupon's, points' and VAT's shares a line, and a piece's part by
  running totals — §1.8); `Domain/Repository/OrderNumbers` as one sequence a store from 1001
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
- **The Best-Selling ranks** (S11a): `PushSalesRanks` (nightly through `Infrastructure/Queue/PushSalesRanksJob`;
  `Orders::piecesSold`) pushes each store's whole ranking to Catalog's `ListingFacts::salesRanks` (catalog.md
  amendment 30).

Tests: `tests/Modules/Sales/Integration` — `SalesPermissionsTest`, `SalesSettingsTest`,
`SalesErrorMessagesTest`, `SalesSchemaTest`, `CartTest`, `CartTablesTest`, `CartRaceTest`, `QuoteTest`, `OrderTablesTest`, `PlaceOrderTest`, `PlaceOrderRaceTest`, `OrderDecisionsTest`, `OrderDecisionsRaceTest`, `OrderMoneyTest`, `TransferDocumentsTest`, `OrderFulfilmentTest`, `OrderEditTest`, `ReturnRequestsTest`, `ReturnRequestsRaceTest`, `ReturnCompletionTest`, `SalesContractTest`, `SalesRanksTest`, and
`tests/Modules/Sales/Unit/OrderSharesTest`, `ReturnRefundsTest`; the outbox's in
`tests/Shared/Integration/Outbox*Test`.
