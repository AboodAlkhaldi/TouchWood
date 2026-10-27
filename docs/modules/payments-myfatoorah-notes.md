# MyFatoorah — research notes for the Payments module (KSA, v3)

Research date: 2026-10-27. Source: only docs.myfatoorah.com. Every page was read as its `.md` twin (the index at https://docs.myfatoorah.com/llms.txt lists them that way), and every fact below cites the exact URL it came from. "NOT IN THE DOCS" means I looked and the documentation does not say it. Nothing here was tested against the API; these notes are what the documentation claims.

**Re-checked by a second reader on 2026-10-27** against the pages themselves:
- The webhook signature's five fields and their order, with no amount (webhook-v2-payment-status-data-model).
- HMAC-SHA256 → base64, the `MyFatoorah-Signature` header, the secret from the portal's webhook page (webhook-signature).
- `Idempotency-Key` on POST/PUT /v3/payments in Kuwait and Saudi Arabia, cached 250 minutes, the cached response returned even when the amount changed (idempotency).
- Legacy keys stay valid until they expire, new keys have permissions and an expiry, and each country has its own key (api-key).
- `PaymentExpiry` in UTC, with nothing about after it (create-payment).
- Create Payment's `PaymentMethod` enum is CARD, APPLE_PAY, GOOGLE_PAY, KNET, with no MADA value (create-payment).

No token or secret is written in this file. Where the docs publish a public test token, the notes say where it is.

Words used below:
- **Invoice**: MyFatoorah's record of "this amount is owed". One invoice can have several payment attempts.
- **Transaction**: one payment attempt on an invoice (a card try, an Apple Pay try...). Each has its own **PaymentId**.
- **v3**: the new API (`/v3/...`). **v2**: the older API (`/v2/...`). The docs say v3 is the one to use for new work, and v2 pages remain as "Old Integration".

---

## 0. The full read of 2026-10-28 (the owner asked for every page)

All **212 pages** were downloaded as their `.md` twins: the 167 in https://docs.myfatoorah.com/llms.txt and
the 45 more that those pages link to (the old v2 pages among them). The pages about the payment flow —
hosted page, embedded payment, sessions, payments, invoices, auth & capture, status, webhooks,
idempotency, keys, test cards, Apple Pay — were read in full. The plugin, mobile SDK, multi-vendor,
recurring and MyFatoorah-shipping pages were searched for expiry, cancel, rate limit, session and
retry wording; nothing in them changes what follows.

**What this read added or settled** (each with its page):

1. **Two ways to take a payment on the web, and MyFatoorah recommends the embedded one.** "Embedded
   Payment (Recommended)" — "complete the payment directly on your checkout page"; the Hosted Payment
   Page — "redirect the client to the payment page" on MyFatoorah's site.
   https://docs.myfatoorah.com/docs/get-started.md, https://docs.myfatoorah.com/docs/choose-your-payment-integration.md
2. **The hosted page is MyFatoorah's own site**: `POST /v3/payments` returns a `PaymentURL` on a
   MyFatoorah address, the customer is redirected there and comes back to our address with
   `?paymentId=`. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
3. **Embedded**: a JavaScript library draws the payment form inside a box on our page. "Embedded
   Payment Methods (processed on your page without redirection): Cards (Visa / Mastercard / AMEX,
   etc.), Apple Pay, Google Pay, STC Pay"; other methods redirect to the hosted page. "MyFatoorah
   handles all PCI DSS requirements; no certification needed on your end". Library for Saudi Arabia:
   `https://sa.myfatoorah.com/sessions/v1/session.js` (test: `https://demo.myfatoorah.com/...`).
   https://docs.myfatoorah.com/docs/embedded-payment-v3.md
4. **"SessionId is valid for only one payment."** A new session is created for each payment
   (`POST /v3/sessions`). https://docs.myfatoorah.com/docs/embedded-payment-v3.md (twice), and the
   same sentence on https://docs.myfatoorah.com/docs/v3-token-payments.md
5. **A session has an expiry we set** — `SessionExpiry`, "Must be in UTC timezone" — and the answer
   gives it back. No default, minimum or maximum is written anywhere.
   https://docs.myfatoorah.com/reference/create-session.md
6. **Our server can ask about a session**: `GET /v3/sessions/{sessionId}` returns `IsUsed` ("whether
   the session has been used for a transaction") and `TransactionResult` (the invoice and the
   transaction). https://docs.myfatoorah.com/reference/get-session-details.md
7. **Collect Details mode puts our server between "Pay" and the charge.** The form collects the
   details, the callback tells our page, and **our server** then calls `POST /v3/payments` with
   `SourceOfFund.SessionId` — "here you can update the amount of payment". "Ideal for complex
   pricing scenarios or when the final amount may change." `OperationType` and the 3-D Secure model
   cannot change between the two calls. https://docs.myfatoorah.com/docs/embedded-payment-v3.md
8. **The bank's code page (OTP / 3-D Secure) can open inside a frame on our page**: "keep your
   customers on your website during the whole payment process"; we handle the frame and "the
   cancelation if the customer wants to cancel"; the result comes as a browser message whose sender
   is exactly `MF-3DSecure`. https://docs.myfatoorah.com/docs/otp-page-in-an-iframe.md
9. **Our own Pay button**: with `useCustomButton: true` we run our own steps and then call
   `myfatoorah.submitCardPayment()`; `validateCardInputs()` checks the form first. Events include
   `PAYMENT_STARTED`, `3DS_CHALLENGE_INITIATED`, `SESSION_CANCELED` (Apple Pay sheet closed).
   https://docs.myfatoorah.com/docs/customizing-embedded-payment.md
10. **Mada works in the embedded card form**: `SupportedNetworks` takes "visa", "masterCard", "mada",
    "amex". A Mada test card is listed (4464040000000007). STC Pay test: mobile 0557877988, OTP 1234.
    https://docs.myfatoorah.com/reference/create-session.md, https://docs.myfatoorah.com/docs/test-cards.md
11. **Apple Pay on our page needs every domain verified** — staging and live: a file from
    tech@myfatoorah.com at `https://{domain}/.well-known/apple-developer-merchantid-domain-association`,
    kept there ("Apple might need to revalidate the domain on a yearly basis"), HTTPS only, then
    `POST /v2/RegisterApplePayDomain` (KSA: `https://api-sa.myfatoorah.com/...`).
    https://docs.myfatoorah.com/docs/apple-pay-domain-verification.md
12. **Authorize & Capture**: with `OperationType: "AUTHORIZE"` the money is only held on the card;
    later `PUT /v3/payments/{paymentId}` with `CAPTURE` (takes it, fully or partly) or `RELEASE`
    (gives it back). "You can perform only one Capture or Release operation per invoice." "Kindly
    contact your account manager to check the availability" — it must be switched on by MyFatoorah,
    and works "when a payment is made using a gateway that supports" it.
    https://docs.myfatoorah.com/docs/v3-auth-capture.md, https://docs.myfatoorah.com/reference/update-payment.md
13. **Still no way to cancel an unpaid invoice or session.** No page in the 212 offers one; the only
    ends are `PaymentExpiry` / `SessionExpiry`, and what happens after them is not written.
14. **A phone number is not required**: in `POST /v3/sessions` only `PaymentMode`, `Order` and
    `Order.Amount` are required; in `POST /v3/payments` only `Order` and `Order.Amount`. The phone
    must match `^(?:(\+)|(00)|(\*)|())\d{6,14}(#?)$` when sent; the email is required only for
    `NotificationOption` EMAIL or ALL. https://docs.myfatoorah.com/reference/create-session.md,
    https://docs.myfatoorah.com/reference/create-payment.md
15. **Webhook v2 retries**: set in the portal, "the maximum number of retries is 5, and the maximum
    delay is 180 seconds". https://docs.myfatoorah.com/docs/webhook-v2.md
16. **The return to our page is not reliable** — "redirection may fail if the customer closes the page
    before it completes"; use the webhook and Get Payment Details together; if Get Payment Details is
    unreachable, retry 3 times 5 seconds apart, 30-second timeout, then schedule a reattempt.
    https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
17. **Idempotency** covers `POST /v3/payments`, `PUT /v3/payments` and the refunds — **not**
    `POST /v3/sessions`. https://docs.myfatoorah.com/docs/idempotency.md
18. **Keys**: up to 5; "avoid disabling the user who created the token, as it will disable the token
    key". https://docs.myfatoorah.com/docs/api-key.md

---

## 1. Environments

### 1.1 Base URLs

| Environment | API base URL | Portal | Source |
|---|---|---|---|
| Test (sandbox), all countries | `https://apitest.myfatoorah.com/` | `https://demo.myfatoorah.com/` | https://docs.myfatoorah.com/docs/api-key.md |
| Live, Saudi Arabia | `https://api-sa.myfatoorah.com/` | `https://sa.myfatoorah.com/` | https://docs.myfatoorah.com/docs/api-key.md |
| Live, Kuwait/Bahrain/Jordan/Oman | `https://api.myfatoorah.com/` | `https://portal.myfatoorah.com/` | https://docs.myfatoorah.com/docs/api-key.md |

- There is one test URL for every country; live has one URL per country group. https://docs.myfatoorah.com/docs/api-key.md and https://docs.myfatoorah.com/docs/live-token.md
- The hosted page must use the `/v3/` endpoints, e.g. `https://apitest.myfatoorah.com/v3/payments`. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
- "Older versions /v2 should not be used for new integrations" (said on the embedded page). https://docs.myfatoorah.com/docs/embedded-payment-v3.md
- Every API reference page's OpenAPI block lists `https://apitest.myfatoorah.com` as the server and points to the API-key table for the right base URL. https://docs.myfatoorah.com/reference/create-payment.md

### 1.2 Authentication header
- Header: `Authorization: Bearer <token>`. https://docs.myfatoorah.com/docs/api-key.md

### 1.3 Test vs live tokens — is a token tied to one environment?
- The docs pair the test URL with a test token and the live URL with a live token: "All you need is the test API URL and a test API Key" and "All you need is the live API URL and a live API Key". https://docs.myfatoorah.com/docs/test-token.md and https://docs.myfatoorah.com/docs/live-token.md
- Test tokens come from a demo account (registered at `https://registertest.myfatoorah.com/en/`) or the public test token; live tokens come from the live portal. https://docs.myfatoorah.com/docs/api-key.md
- Whether a live token is rejected by the test URL (or the reverse), and what error comes back: **NOT IN THE DOCS**.
- Each country enabled on a portal account has its own API token; you must use that country's token to let customers pay in local currency. https://docs.myfatoorah.com/docs/api-key.md and https://docs.myfatoorah.com/docs/live-token.md
- The official Laravel package switches environment with a `test_mode` boolean plus `country_iso` (e.g. `SAU`) and an `api_key`. https://docs.myfatoorah.com/docs/laravel.md

### 1.4 Legacy vs new tokens
- "If you are using a legacy API Key, it will remain valid until it expires. After that, you will be forced to use the new version of the API Keys." https://docs.myfatoorah.com/docs/api-key.md
- Whether a legacy key works with the v3 endpoints: **NOT IN THE DOCS**.
- How to tell a legacy key from a new one (other than the portal screenshot on that page): **NOT IN THE DOCS** in text form.

### 1.5 Token permissions, limits and expiry (new keys)
- Created in the portal under **Integration Settings → API Key**, by the Super Master account. Each key has: Name, **Expiry Date** (you choose), Active/Inactive status, and **Permissions** (which endpoints it may call, e.g. "Super Rules, Create Payments, Update Payments"). https://docs.myfatoorah.com/docs/api-key.md
- At most **5 API keys**. https://docs.myfatoorah.com/docs/api-key.md
- Calling an endpoint without the permission returns `Status Code: 401, Message: "The token does not have the required permissions!"`. https://docs.myfatoorah.com/docs/api-key.md
- GET Payment Methods specifically: "ensure that the API key you are using has permission to call this endpoint." https://docs.myfatoorah.com/reference/get-payment-methods.md
- Disabling the portal user who created a token disables the token. https://docs.myfatoorah.com/docs/api-key.md
- The older Live Token page says: the key "is expired every five years", and "If you regenerate a new API token key, the old one will be expired." https://docs.myfatoorah.com/docs/live-token.md (Inferred from the two pages: the five-year rule and the "regenerate expires the old one" rule describe the older key type; the newer API Key page lets you set the expiry yourself and hold up to 5 keys. The docs do not reconcile the two.)
- The exact permission names our key needs for Create Payment, Get Payment Details, Get Payment Methods, Get Invoice: **NOT IN THE DOCS** (only examples like "Create Payments, Update Payments"). The plugin page lists permissions for plugins only (Refund, Multi-suppliers, Shipping) with a screenshot. https://docs.myfatoorah.com/docs/plugin-api-permissions.md

### 1.6 Demo account
- Register at `https://registertest.myfatoorah.com/en/`, **select Kuwait as the country**, skip the bank step, then email `tech@myfatoorah.com` to activate it and enable features. https://docs.myfatoorah.com/docs/api-key.md and https://docs.myfatoorah.com/docs/get-started.md
- Payment methods on a demo account are enabled by emailing `tech@myfatoorah.com`; on live, by the account manager. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
- The older Test Token page says register at `https://demo.myfatoorah.com/` instead. https://docs.myfatoorah.com/docs/test-token.md
- Whether a KSA (SAU) demo account exists, or the owner's "KSA test token" is from a Kuwait demo account: **NOT IN THE DOCS** (the docs only describe Kuwait demo accounts).

### 1.7 The public test token
- MyFatoorah publishes a public test API token in the "Test (Demo) Token" section of https://docs.myfatoorah.com/docs/api-key.md (and on https://docs.myfatoorah.com/docs/test-token.md). It is also pre-filled in the "Try it" box of every API reference page. Not copied here.

### 1.8 Test cards and wallets
All on https://docs.myfatoorah.com/docs/test-cards.md:
- **Mada**: two test cards listed (one marked "Capture").
- **Visa/Master**: six test cards listed.
- **Apple Pay**: no MyFatoorah test card; the page links to Apple's sandbox testing page (`https://developer.apple.com/apple-pay/sandbox-testing/`).
- **STC Pay**: two test mobile numbers with a fixed OTP.
- AMEX, Knet, Benefit, Jaywan cards also listed.
- Card holder name: "Use any two-part name like 'test test'".
- "Not all gateways provide test cards."
- Google Pay test data: **NOT IN THE DOCS**.

### 1.9 The test currency
- "As long as you are in test mode, the currency will be in KWD. Once you switch to live mode, the currency will be the same as your portal account currency. Also, the invoice value will be converted to KWD using the MyFatoorah exchange rate." https://docs.myfatoorah.com/docs/test-token.md
- The Idempotency page's test example sends `"Currency": "SAR"`, `"Amount": 800` to `apitest` and gets back `BaseCurrency: KWD`, `ValueInBaseCurrency: 64.772`, `PayCurrency: SAR`, `ValueInPayCurrency: 800`. https://docs.myfatoorah.com/docs/idempotency.md
- Consequence (inferred from the two pages above): in test, `Amount.ValueInBaseCurrency` is in KWD, not SAR. Amount checks in test must compare the SAR figure (`ValueInPayCurrency` / `ValueInDisplayCurrency`), not the base figure.

### 1.10 Moving to live
- Register at `https://register.myfatoorah.com/`, choose the country, then call the account manager to **activate the account** and **activate the API role**. https://docs.myfatoorah.com/docs/live-account.md
- Generate a live key in the live portal (Integration Settings → API Key). https://docs.myfatoorah.com/docs/api-key.md
- Use the KSA live URL `https://api-sa.myfatoorah.com/`. https://docs.myfatoorah.com/docs/api-key.md
- Payment methods on live are enabled by the account manager. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
- Webhooks are configured per portal (Integration Settings → Webhook Settings), so the live portal needs its own webhook set-up and secret. https://docs.myfatoorah.com/docs/webhook-information.md
- A formal go-live checklist: **NOT IN THE DOCS**.

---

## 2. The hosted-page flow, end to end (v3)

Source for the overall flow: https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md

1. **List methods** with `GET /v3/payment-methods`; show them on our checkout; keep the chosen method's `ApiName`. The docs say you may call it once and store the result rather than calling it for every payment.
2. **Create the payment** with `POST /v3/payments`, sending the chosen method and order details. The response holds `PaymentURL`; redirect the customer there.
3. **Customer returns** to our `IntegrationUrls.Redirection` URL with `?paymentId=...` appended. Call `GET /v3/payments/{paymentId}` to learn the real status.
4. MyFatoorah recommends also enabling the **webhook**.

### 2.1 Request: `POST /v3/payments` (Create Payment)
Source: https://docs.myfatoorah.com/reference/create-payment.md unless stated.

| Field | What the docs say | Our use |
|---|---|---|
| `PaymentMethod` | string; enum shown: `CARD`, `APPLE_PAY`, `GOOGLE_PAY`, `KNET`; default `CARD`. "Specify the payment method you want to use, or omit this parameter to create a payment page that displays all enabled methods. This is required for redirection cases." | The method the customer picked. See §3 for the naming conflict. |
| `Order.Amount` | number, **required**, "must be greater than 0". | Order total in SAR. |
| `Order.Currency` | "The currency ISO code you want to display to the customer, by default is the same as the base currency of the country API." Enum includes `SAR`. | `SAR` (this is the display currency; there is no separate display-currency field in v3). |
| `Order.ExternalIdentifier` | "You may use as additional information to be stored with the transaction." | Our order reference (it is returned in the webhook and is **signed** — see §5). |
| `Customer.Name`, `Customer.Email` | Email required when `NotificationOption` is `ALL` or `EMAIL`. | Optional pre-fill. |
| `Customer.Mobile.CountryCode`, `Customer.Mobile.Number` | Country code max 4 chars, regex `^(?:(\+)|(00)|(\*)|())\d{1,4}$`; number max 11, regex `^(?:(\+)|(00)|(\*)|())\d{6,14}(#?)$`. | `+966` + number. |
| `Customer.Reference` | "Refers to the order or transaction ID in your own system that you can link with the invoice generated for reporting purposes." | Our order number (portal staff can filter by it — see below). |
| `Customer.CivilId` | optional extra id. | Not needed. |
| `IntegrationUrls.Redirection` | "URL where the user is redirected after making the payment. Use this URL to check the payment status." | Our return page. |
| `IntegrationUrls.Webhook` | Per-invoice webhook URL; "The secret key for this URL will be the same as your webhook URL used in the dashboard. If you don't add this parameter, MyFatoorah sends the webhook event to the one configured in the dashboard." | Optional: lets one portal serve test and live hosts. |
| `Language` | `EN` or `AR` — "Invoice language." | Customer's language. |
| `PaymentExpiry` | date-time, "The payment expiration date. Must be in UTC timezone." | Order time + 60 min (see §7). |
| `MetaData.UDF1`…`UDF5` | "Custom fields stored with the transaction and returned in the webhook." | Optional (store id, payment-attempt id). Not signed. |
| `DisplayPaymentMethods` | array, e.g. `["card", "knet", "googlepay", "applepay"]`; "If this parameter is not sent, and the PaymentMethod field is also not provided, all payment methods enabled on your account will be displayed automatically." | Alternative way to restrict the hosted page. |
| `NotificationOption` | `EMAIL`, `SMS`, `ALL`, `LINK`. | For the "further payment" link (see §2.5). |
| `OperationType` | `AUTHORIZE` or `PAY`. | `PAY` (or omit). |
| `IpAddress` | "The customer's IP address". | Optional. |
| `SourceOfFund`, `SaveCardOptions`, `ThreeDS`, `Suppliers`, `NetworkTransactionId` | Embedded/direct/token/multi-vendor features. | Not used. |

- `IntegrationUrls.Redirection` is optional; "If omitted, customers will remain on the MyFatoorah result page after completing payment." https://docs.myfatoorah.com/docs/v3-invoicing.md
- v3 has **one** redirection URL. v2 had two: `CallBackUrl` (success) and `ErrorUrl` (failure), each max 254 characters, and "The localhost is not allowed to be set as a domain." https://docs.myfatoorah.com/reference/execute-payment.md and https://docs.myfatoorah.com/docs/execute-payment.md
- Whether v3's `Redirection` also forbids localhost or has a length limit: **NOT IN THE DOCS**.
- Whether v3 redirects on failure as well as success: the docs only say "After the payment is completed". **NOT IN THE DOCS** explicitly for failure.
- Portal staff can filter Orders List by "your website order ID as the Customer Reference" and by Invoice ID. https://docs.myfatoorah.com/docs/orders-information.md

### 2.2 Response
Source: https://docs.myfatoorah.com/reference/create-payment.md and https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
- HTTP **201** "Payment created successfully".
- Body: `IsSuccess`, `Message`, `ValidationErrors`, `Data`.
- `Data.InvoiceId` (string, "A unique invoice number"), `Data.PaymentId` (**null** for the hosted page — the example shows `"PaymentId": null`), `Data.PaymentURL` (note the capital `URL`), `Data.PaymentCompleted` (`false`), `Data.TransactionDetails` (`null`; "null if payment not completed").
- The reference also shows a second example where `PaymentCompleted: true` and full `TransactionDetails` come back at once — labelled "Non-3DS payments" (token/direct cases, not the hosted page).

### 2.3 The redirect back
- MyFatoorah redirects to the Redirection URL and "append[s] a `paymentId` as a query parameter", example `https://your-website.com/payment-callback?paymentId=100201923790872553`. The parameter name is lower-case `paymentId`. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
- No other query parameters are documented. **NOT IN THE DOCS**: any signature or status on the redirect.
- The `paymentId` in the URL is not proof of payment; always call Get Payment Details: "to ensure the payment response is returned from the MyFatoorah end, we encourage you to call `GET /v3/payments/{paymentId}`". https://docs.myfatoorah.com/docs/get-payment-details.md
- "Relying on redirection may not always be reliable … redirection may fail if the customer closes the page before it completes or if a slow network prevents the redirection." https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md

### 2.4 Status call after redirect
- `GET /v3/payments/{paymentId}` returns `Data.Invoice`, `Data.Transaction`, `Data.Customer`, `Data.Amount`, `Data.Suppliers` (details in §4). https://docs.myfatoorah.com/reference/get-payment-details.md

### 2.5 "Further payment" request by staff
- The same `POST /v3/payments` creates a payment link without `PaymentMethod`; set `NotificationOption`: `EMAIL` (needs `Customer.Email`), `SMS` (needs `Customer.Mobile.CountryCode` + `Number`), `LINK` (link only in the response), `ALL` (email + SMS + link in response). The customer can then pay with any enabled method. https://docs.myfatoorah.com/docs/v3-invoicing.md
- Its result is checked the same way (redirect `paymentId` → Get Payment Details; or webhook). https://docs.myfatoorah.com/docs/v3-invoicing.md

---

## 3. Payment methods

### 3.1 The endpoint that lists enabled methods
- **v3**: `GET /v3/payment-methods`, no parameters (no amount). Returns `Data.PaymentMethods[]` with `Name`, `ApiName` ("API name to use when creating a payment"), `CommissionPercentage`, `CommissionValue`, `IsDirectPayment`, `IsEmbeddedSupported`, `CurrencyIso`, `LogoUrl`. https://docs.myfatoorah.com/reference/get-payment-methods.md
- **v2**: `POST /v2/InitiatePayment` with `InvoiceAmount` and `CurrencyIso` — this one **does take the amount**, and returns per method `PaymentMethodId`, `PaymentMethodAr`, `PaymentMethodEn`, `PaymentMethodCode`, `IsDirectPayment`, `ServiceCharge`, `TotalAmount`, `CurrencyIso`, `ImageUrl`, `IsEmbeddedSupported`, `PaymentCurrencyIso`. It "does not execute or store any payment information". https://docs.myfatoorah.com/reference/initiate-payment.md and https://docs.myfatoorah.com/docs/initiate-payment.md
- A v3 call that lists methods **for a given amount**: **NOT IN THE DOCS**.

### 3.2 Method names / codes
v3 `ApiName` values shown in the v3 example (a Kuwait demo account): `APPLEPAY`, `CARD` (VISA/MASTER), `GOOGLEPAY`, `KNET`. https://docs.myfatoorah.com/reference/get-payment-methods.md

v2 values shown in the InitiatePayment example (demo account): https://docs.myfatoorah.com/docs/initiate-payment.md

| Method | v2 PaymentMethodId | v2 PaymentMethodCode |
|---|---|---|
| MADA | 6 | `md` |
| VISA/MASTER | 2 | `vm` |
| Apple Pay | 11 | `ap` |
| Apple Pay (Mada) | 25 | `ap` |
| GooglePay | 32 | `gp` |
| STC Pay | 14 | `stc` |
| AMEX | 3 | `ae` |

- **The v3 `ApiName` for Mada and STC Pay: NOT IN THE DOCS.** The v3 hosted page says to use "the ApiName of the selected payment method" from GET Payment Methods. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md — so the real values must be read from our own KSA account's response.
- **Naming conflict in the docs**: the Create Payment enum spells `APPLE_PAY` / `GOOGLE_PAY` (https://docs.myfatoorah.com/reference/create-payment.md), but GET Payment Methods returns `APPLEPAY` / `GOOGLEPAY` (https://docs.myfatoorah.com/reference/get-payment-methods.md), and `DisplayPaymentMethods` uses lower case `applepay` / `googlepay` / `card` / `knet` (https://docs.myfatoorah.com/reference/create-payment.md). Which spelling Create Payment accepts: not settled by the docs.
- Whether the v2 PaymentMethodIds are fixed across accounts: **NOT IN THE DOCS** (they come from a demo example).
- Not every method is available to every account; "Based on your account and agreement with MyFatoorah". https://docs.myfatoorah.com/docs/choose-your-payment-integration.md and https://docs.myfatoorah.com/docs/payment-methods.md

### 3.3 Can the chosen method be forced on the hosted page?
- Yes, per the docs: send `PaymentMethod`; omitting it shows all enabled methods. https://docs.myfatoorah.com/reference/create-payment.md
- Or restrict the page with `DisplayPaymentMethods`. https://docs.myfatoorah.com/reference/create-payment.md
- Whether the customer can still switch to another method on the hosted page when `PaymentMethod` is set: **NOT IN THE DOCS**.

### 3.4 Apple Pay on the hosted page
- Domain verification is required for **embedded** Apple Pay (the Apple Pay button on your own page): host a file from `tech@myfatoorah.com` at `https://{domain}/.well-known/apple-developer-merchantid-domain-association`, then call `POST /v2/RegisterApplePayDomain` with `{"DomainName": "example.com"}` (KSA live: `https://api-sa.myfatoorah.com/v2/RegisterApplePayDomain`); HTTPS required; keep the file (Apple may revalidate yearly); do it for demo and live domains. https://docs.myfatoorah.com/docs/apple-pay-domain-verification.md and https://docs.myfatoorah.com/reference/register-apple-pay-domain.md
- That endpoint "Registers your domain to use Apple Pay in **embedded** integration". https://docs.myfatoorah.com/reference/register-apple-pay-domain.md
- Whether the **hosted** page needs our domain verified: **NOT IN THE DOCS** (the hosted page lists Apple Pay as supported with no prerequisite: https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md).

### 3.5 Per-method min/max amounts and fees
- Fees: `CommissionPercentage` and `CommissionValue` per method (v3) https://docs.myfatoorah.com/reference/get-payment-methods.md; `ServiceCharge` and `TotalAmount` for a given amount (v2) https://docs.myfatoorah.com/docs/initiate-payment.md
- Who pays the fee is set in the portal: Profile → Commission Charges, per method: vendor, customer, or split. If on the customer, `TotalAmount` = amount + service charge and is "the total amount that your customers will be charged with". https://docs.myfatoorah.com/docs/gateway-integration.md
- Per-method minimum or maximum amounts: **NOT IN THE DOCS**.

---

## 4. Status

### 4.1 v3: `GET /v3/payments/{paymentId}` (Get Payment Details)
Source: https://docs.myfatoorah.com/reference/get-payment-details.md
- Lookup key: **PaymentId only** (path parameter).
- `Data.Invoice`: `Id`, `Status` ("PAID, PENDING, CANCELED"), `Reference` (MyFatoorah's), `CreationDate`, `ExpirationDate` (UTC), `ExternalIdentifier`, `UserDefinedField`, `MetaData`.
- `Data.Transaction` (singular — the transaction for that PaymentId): `Id`, `Status` ("SUCCESS, FAILED, INPROGRESS, CANCELED, AUTHORIZE"), `PaymentMethod`, `PaymentId`, `ReferenceId`, `TrackId`, `AuthorizationId`, `TransactionDate`, `ECI`, `IP{Address,Country}`, `Error{Code,Message}`, `Card{...}`.
- `Data.Customer`: `Name`, `Mobile`, `Email` (examples also show `Reference`, e.g. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md, but the schema does not list it).
- `Data.Amount` (all **strings**): `BaseCurrency`, `ValueInBaseCurrency`, `ServiceCharge`, `ServiceChargeVAT`, `ReceivableAmount`, `DisplayCurrency`, `ValueInDisplayCurrency`, `PayCurrency`, `ValueInPayCurrency`.

### 4.2 v3: lookup by InvoiceId or by our reference
- `GET /v3/invoices/{InvoiceId}` returns the invoice with a **`Transactions` array** (all attempts). Note: "If the invoice doesn't exist OR the invoice exists but has no transactions, the API will return the 'Message': 'No invoices match this InvoiceId'." https://docs.myfatoorah.com/reference/get-invoice-by-invoiceid.md
- `GET /v3/invoices/externalIdentifier/{externalIdentifier}`: "If multiple invoices exist with the same ExternalIdentifier, the API will return data for the recent invoice only." and "If the latest invoice doesn't contain any transactions, the API response will include 'Message': 'No invoices match this External Identifier'." https://docs.myfatoorah.com/reference/get-invoice-by-externalidentifier.md
- A v3 lookup by `Customer.Reference`: **NOT IN THE DOCS**.

### 4.3 v2: `POST /v2/GetPaymentStatus`
Source: https://docs.myfatoorah.com/reference/get-payment-status.md and https://docs.myfatoorah.com/docs/get-payment-status.md
- Body `{ "Key": "...", "KeyType": "InvoiceId" | "PaymentId" | "CustomerReference" }`. PaymentId is "recommended".
- "As long as there is not payment or transactions done for a certain invoice, you can inquire using InvoiceId" — i.e. v2 works for an invoice with **no** attempts, unlike the v3 invoice lookup. https://docs.myfatoorah.com/docs/get-payment-status.md
- Response: `InvoiceId`, `InvoiceStatus` (`Pending`, `Paid`, `Canceled`), `InvoiceReference`, `CustomerReference`, `CreatedDate`, `ExpiryDate`, `InvoiceValue`, `InvoiceDisplayValue`, `DueDeposit`, `DepositStatus`, `InvoiceItems[]`, `InvoiceTransactions[]` (each with `TransactionStatus`, `PaymentId`, `TransationValue` [sic], `CustomerServiceCharge`, `DueValue`, `PaidCurrency`, `PaidCurrencyValue`, `Error`, `ErrorCode`...).
- v2 transaction statuses: `InProgress`, **`Succss`** (spelled this way in the docs and examples), `Failed`, `Canceled`, `Authorize`. https://docs.myfatoorah.com/docs/get-payment-status.md
- The `ExpiryDate` in v2 examples is a text date like `"January 13, 2026"` with a separate `ExpiryTime`. https://docs.myfatoorah.com/reference/get-payment-status.md
- **CustomerReference is not unique**: "When using the same CustomerReference multiple times, the GetPaymentStatus endpoint returns the last invoice created for this customer reference." https://docs.myfatoorah.com/docs/payment-inquiry.md
- Querying by any PaymentId of an invoice returns the same whole-invoice response as querying by InvoiceId. https://docs.myfatoorah.com/docs/payment-inquiry.md

### 4.4 Status meanings
- Invoice is **PAID** "if there is a successful transaction (with 'SUCCESS' status)"; **PENDING** "if all transactions have 'FAILED', 'INPROGRESS', 'AUTHORIZE', or 'CANCELED' status." https://docs.myfatoorah.com/docs/get-payment-details.md
- v2 wording: Paid if a transaction is `Succss`; Pending if all are `InProgress` or `Failed`. An `InProgress` transaction means "the customer has not tried to pay the invoice till this moment". https://docs.myfatoorah.com/docs/payment-inquiry.md
- Invoice status **CANCELED**: listed but **its meaning is NOT IN THE DOCS** (e.g. whether expiry turns an invoice CANCELED).
- `AUTHORIZE` belongs to the Authorize & Capture flow (`OperationType: AUTHORIZE`). https://docs.myfatoorah.com/docs/v3-auth-capture.md
- Transaction error codes (`Error.Code`): MF001 3DS failed; MF002 issuer declined; MF003 blocked by gateway; MF004 insufficient funds; MF005 session timeout; MF006 transaction canceled; MF007 card expired; MF008 issuer no response; MF009 denied by risk; MF010 wrong security code; MF020 unspecified. https://docs.myfatoorah.com/docs/get-payment-details.md

### 4.5 Success is final
- "In MyFatoorah system, the Success Payment Status is final and cannot be override. If you get the payment status as success, you can safely mark the order as paid on your system." https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md

### 4.6 One invoice, several transactions
- "MyFatoorah invoice may have more than one transaction." https://docs.myfatoorah.com/docs/get-payment-details.md
- The v2 example shows one invoice with four attempts: KNET Failed, MADA InProgress, Visa/Master Failed, KNET `Succss`. https://docs.myfatoorah.com/docs/payment-inquiry.md
- Whether one invoice can end up with **two** successful transactions (customer pays twice on the same link): **NOT IN THE DOCS**.

### 4.7 Rate limits
- "GET Payments endpoint has a rate limit"; contact the account manager to raise it. https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md (same for v2 GetPaymentStatus: https://docs.myfatoorah.com/docs/get-payment-status.md)
- The actual limit numbers and the response when exceeded (e.g. HTTP 429): **NOT IN THE DOCS**.

### 4.8 Retry guidance
From https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md:
- If GET Payments is unreachable: retry **3 times**, **5 seconds** apart.
- Client timeout "at least **30 seconds**".
- Still unreachable: set the order to `Pending_Status_Update`, then schedule a later attempt or handle it manually.
- Redirect after webhook: if the webhook already updated the order, show the result; else run the redirect steps. Webhook after redirect: if already updated, return 200; else run the webhook steps.
- "Both the Webhook and Redirection are triggered from MyFatoorah at the same time … it is most likely that you will get the webhook event first."

---

## 5. Webhooks (v2)

### 5.1 Setup in the portal
- Portal: **Integration Settings → Webhook Settings**: enable, add endpoint URL, select event types, choose version (V2: also configure retries), Save. https://docs.myfatoorah.com/docs/webhook-information.md
- SSL required: "Ensure that your server is configured to support HTTPS, with a valid server certificate." https://docs.myfatoorah.com/docs/webhook-v2.md
- Per-invoice override: `IntegrationUrls.Webhook` on Create Payment (same secret as the dashboard one). https://docs.myfatoorah.com/reference/create-payment.md

### 5.2 Event types
`Event.Code` / `Event.Name`: 1 `PAYMENT_STATUS_CHANGED`, 2 `REFUND_STATUS_CHANGED`, 3 `BALANCE_TRANSFERRED`, 4 `SUPPLIER_STATUS_CHANGED`, 5 `RECURRING_UPDATES`, 6 `DISPUTE_STATUS_CHANGED`, 7 `SUPPLIER_UPDATE_REQUEST_CHANGED`. https://docs.myfatoorah.com/docs/webhook-v2.md

### 5.3 Payload (PAYMENT_STATUS_CHANGED)
Source: https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model.md
- `Event`: `Code` (1), `Name`, `CountryIsoCode`, `CreationDate` (UTC ISO 8601), **`Reference`** ("Unique reference ID for the webhook event"; https://docs.myfatoorah.com/docs/webhook-v2.md says "Each webhook has its unique reference").
- `Data.Invoice`: `Id`, `Status` ("PAID - PENDING"), `Reference`, `CreationDate`, `ExpirationDate`, `UserDefinedField`, **`ExternalIdentifier`**, `MetaData` (UDF1–5).
- `Data.Transaction`: `Id`, `Status` ("SUCCESS - FAILED - AUTHORIZE - CANCELED"), `PaymentMethod`, `PaymentId`, `ReferenceId`, `TrackId`, `AuthorizationId`, `TransactionDate`, `ECI`, `IP`, `Error{Code,Message}`, `Card{...}`.
- `Data.Customer`: `Name`, `Mobile`, `Email` ("masked"). **No `Customer.Reference` in the v2 webhook model** — to find our order, use `Invoice.ExternalIdentifier` or `Invoice.MetaData`.
- `Data.Amount`: same nine string fields as Get Payment Details.
- Doc wording oddity: `Invoice.ExternalIdentifier` is described as "The value sent in the CustomerIdentifier field in the request" — but Create Payment's field is `Order.ExternalIdentifier`. (Inferred: they mean `Order.ExternalIdentifier`; the sample shows it filled.)

### 5.4 Signature — exact algorithm
Sources: https://docs.myfatoorah.com/docs/webhook-signature.md and https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model.md
- Header: **`MyFatoorah-Signature`** (also written `myfatoorah-signature`).
- Secret: the webhook "secure key" from the portal's webhook page. In v2 "the use of the webhook secret key is now mandatory". https://docs.myfatoorah.com/docs/webhook-v2.md
- Steps:
  1. Take the listed properties **in the documented order** for that event.
  2. Build one string `key=value,key2=value2,...`. A `null` value becomes an empty string (`CustomerEmail=,`).
  3. Encode the secret and the string as **UTF-8**.
  4. **HMAC-SHA256** with the secret.
  5. **Base64**-encode the result.
  6. Compare with the header.
- Signed fields and order for `PAYMENT_STATUS_CHANGED`:
  `Invoice.Id=…,Invoice.Status=…,Transaction.Status=…,Transaction.PaymentId=…,Invoice.ExternalIdentifier=…`
  ("If a parameter has no value, set its value to an empty string.")
- **The amount is NOT signed** in v2 (only those five fields). Contrast: Webhook V1 signed all data fields sorted alphabetically, including amounts and `CustomerReference`. https://docs.myfatoorah.com/docs/transaction-data-model.md
- For `REFUND_STATUS_CHANGED` the signed string is `Refund.Id=…,Refund.Status=…,Amount.ValueInBaseCurrency=…,ReferencedInvoice.Id=…`. https://docs.myfatoorah.com/docs/webhook-v2-refund-data-model.md
- Whether the comparison should be constant-time, or whether there is a timestamp/replay guard: **NOT IN THE DOCS**.

### 5.5 Retries
- V2: configurable in the dashboard; "The maximum number of retries is **5**, and the maximum delay is **180 seconds**." https://docs.myfatoorah.com/docs/webhook-v2.md
- Expected answer: "Return HTTP 200 OK". https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
- V1 (for contrast): four attempts, "each time for nearly 100 seconds", 10 s apart; a timeout counts as failure; no more tries after the fourth. https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
- V2 per-attempt timeout, and which non-200 codes count as success: **NOT IN THE DOCS**.
- Missed events can be fetched later with `POST /v2/GetWebhooks` (filter by `Start`/`End` UTC, `EventType`, `Status` = Waiting/Running/Succeeded/Failed, `KeyType` = InvoiceId/CustomerReference/WebhookReference, 500 per page). https://docs.myfatoorah.com/docs/getwebhooks.md and https://docs.myfatoorah.com/reference/get-webhooks.md

### 5.6 Duplicates and dedupe
- "In some rare scenarios, you may receive two webhook events for the same transaction. If the TransactionStatus one of these webhook events is SUCCESS, ignore the other webhook event and mark the order as paid." https://docs.myfatoorah.com/docs/webhook-v2.md
- "a webhook event indicating a successful payment status overrides any other status (even if received first)." https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
- Dedupe keys available: `Event.Reference` (unique per webhook event) and `Transaction.PaymentId` (unique per attempt). https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model.md
- Whether a retried delivery keeps the same `Event.Reference`: **NOT IN THE DOCS** (inferred likely, since GetWebhooks shows one `WebhookReference` with several `Attempts`: https://docs.myfatoorah.com/docs/getwebhooks.md).
- Recommended handling: validate signature → update order → return 200; MyFatoorah recommends relying on **both** webhook and the status call. https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md

---

## 6. Idempotency

Source: https://docs.myfatoorah.com/docs/idempotency.md
- Header: `Idempotency-Key: <unique-value>`.
- Endpoints: **`POST /v3/payments`**, `PUT /v3/payments` (capture/release), `MakeRefund`, `MakeSupplierRefund`.
- Availability: "currently available only in … Kuwait, **Saudi Arabia**".
- Cache: "the system will cache the original request for **250 minutes**. If the same request is sent again using the same Idempotency-Key within this period, the system will return the exact same response instead of processing the request again."
- **Different body, same key**: the page's example sends `Amount: 800` then `Amount: 1000` with the same key; the second call returns the **first** response unchanged (800). No error is raised. So the key alone decides; the body is not compared.
- Behaviour after 250 minutes (a new invoice would be created): inferred from "cache … for 250 minutes"; the docs do not say it explicitly.
- Whether the test environment (`apitest`, a Kuwait demo) honours the key: the page's own example is on `apitest`, so yes for that example.
- Other ways to avoid two invoices for one order:
  - `Order.ExternalIdentifier` is **not unique** (several invoices may share it; lookup returns the latest). https://docs.myfatoorah.com/reference/get-invoice-by-externalidentifier.md
  - `CustomerReference` is **not unique**. https://docs.myfatoorah.com/docs/payment-inquiry.md
  - No "reuse the open invoice" or "find unpaid invoice by our reference" feature: **NOT IN THE DOCS**. So we must store the InvoiceId/PaymentURL ourselves and reuse it.
- Refunds: "Please make sure to send one refund request each time, to avoid duplicate partial refund requests (You need to handle this from your system front-end side)." https://docs.myfatoorah.com/reference/create-refund.md

---

## 7. Expiry and cancellation

- v3: `PaymentExpiry` (date-time, "Must be in UTC timezone"). https://docs.myfatoorah.com/reference/create-payment.md
- v2: `ExpiryDate` — "The date you want the payment to expire. If not passed, the default is considered from the account profile in the portal." https://docs.myfatoorah.com/reference/execute-payment.md
- The v2 PHP sample says: "The Invoice expires after **3 days** by default. Use 'Y-m-d\TH:i:s' format in the '**Asia/Kuwait**' time zone." https://docs.myfatoorah.com/docs/gateway-integration-sample-code.md — this conflicts with v3's "UTC". (Inferred: v2 and v3 differ; for v3 send UTC.)
- The response's `Invoice.ExpirationDate` is UTC. https://docs.myfatoorah.com/reference/get-payment-details.md
- The minimum allowed expiry (can it be 60 minutes?): **NOT IN THE DOCS**.
- What happens when the customer tries to pay after expiry (blocked page? error?): **NOT IN THE DOCS**.
- What happens to a payment already in progress (e.g. in 3-D Secure) when the expiry passes — can it still succeed? **NOT IN THE DOCS**.
- Whether an expired invoice becomes `CANCELED`: **NOT IN THE DOCS**.
- An API to cancel or void an **unpaid** invoice: **NOT IN THE DOCS**. (The only "release" is for authorized payments in Authorize & Capture: `PUT /v3/payments/{paymentId}` with `OperationType: RELEASE`; "Only one Capture or Release operation is allowed for each invoice." https://docs.myfatoorah.com/reference/update-payment.md and https://docs.myfatoorah.com/docs/v3-auth-capture.md)

---

## 8. Amounts

- `Order.Amount` is a number > 0. https://docs.myfatoorah.com/reference/create-payment.md
- v2 `InvoiceValue` "accepts decimal values (e.g., 2.500)". https://docs.myfatoorah.com/reference/execute-payment.md
- Number of SAR decimals accepted, and the rounding rule: **NOT IN THE DOCS**.
- Response amounts are **strings** (e.g. `"ValueInBaseCurrency": "20"`). https://docs.myfatoorah.com/reference/get-payment-details.md
- Currency conversion: `Order.Currency` is the currency shown to the customer; MyFatoorah converts to the account's base currency with its exchange rate. In test the base is KWD (§1.9). https://docs.myfatoorah.com/reference/create-payment.md and https://docs.myfatoorah.com/docs/test-token.md
- Rates: `POST /v2/GetCurrenciesExchangeList` "Retrieves a list of currencies with their exchange rates against your base currency". https://docs.myfatoorah.com/reference/get-currencies-exchange-list.md
- Can the paid amount differ from the invoice amount? Yes, when the commission is set "on the customer" or split: the customer pays `TotalAmount` = amount + service charge. https://docs.myfatoorah.com/docs/gateway-integration.md — and the v2 example shows a MADA attempt with `TransationValue` 12,345.000, `CustomerServiceCharge` 185.175, `DueValue` 12,530.180. https://docs.myfatoorah.com/docs/payment-inquiry.md
- `ServiceCharge`, `ServiceChargeVAT` and `ReceivableAmount` (what the merchant receives) are returned separately. https://docs.myfatoorah.com/reference/get-payment-details.md
- Partial payment of an invoice: **NOT IN THE DOCS**.

---

## 9. Errors

- Every response: `IsSuccess`, `Message`, `ValidationErrors` (also called `FieldsErrors`; items `{Name, Error}`), `Data`. https://docs.myfatoorah.com/docs/response-model.md
- Note: Create Payment documents `IsSuccess` as a **string** "true"/"false" (https://docs.myfatoorah.com/reference/create-payment.md) while other v3 pages document it as boolean and examples show `true` (https://docs.myfatoorah.com/reference/get-payment-details.md). Parse both.
- Validation error example (HTTP **400**): `{"IsSuccess": false, "Message": "Invalid data", "ValidationErrors": [{"Name": "InvoiceValue", "Error": "Invoice value must be more than 0"}], "Data": null}`. https://docs.myfatoorah.com/reference/send-payment.md
- Not-found example (HTTP **400**): `ValidationErrors: [{"Name": "refundId", "Error": "No refund exist matching this RefundId!"}]`. https://docs.myfatoorah.com/reference/get-refund-details.md
- Not found on v3 invoice lookup is signalled by `Message` "No invoices match this InvoiceId" (HTTP code not stated). https://docs.myfatoorah.com/reference/get-invoice-by-invoiceid.md
- Missing permission: **401** "The token does not have the required permissions!". https://docs.myfatoorah.com/docs/api-key.md
- Success codes: Create Payment **201**; Get Payment Details / Get Payment Methods / GetPaymentStatus **200**. https://docs.myfatoorah.com/reference/create-payment.md, https://docs.myfatoorah.com/reference/get-payment-details.md
- Timeouts: use a client timeout of at least 30 s; retry 3× at 5 s. https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
- A full list of HTTP codes (401 for a bad/expired token, 403, 404, 429, 5xx): **NOT IN THE DOCS**.

---

## 10. Refunds and voids (description only — not automated)

- Refunds return funds "to the original credit or debit card used for the charge". https://docs.myfatoorah.com/docs/refunds.md
- v3 `POST /v3/refunds` (needs the successful **PaymentId** and Amount); v2 `POST /v2/MakeRefund` (KeyType InvoiceId or PaymentId). Full or partial; several partial refunds allowed up to the paid amount. https://docs.myfatoorah.com/docs/refunds.md, https://docs.myfatoorah.com/reference/create-refund.md, https://docs.myfatoorah.com/docs/make-refund.md
- A refund is a **request** to MyFatoorah's finance team, executed by them, unless "Auto Refund" is enabled. Initial status "Pending"; later Refunded or Canceled. https://docs.myfatoorah.com/docs/refunds.md and https://docs.myfatoorah.com/reference/create-refund.md
- Refund currency is the account's base currency. https://docs.myfatoorah.com/docs/refunds.md
- Needs enough "available refund balance" (Awaiting Balance minus Awaiting-to-Transfer, or minus Amount Received Today). https://docs.myfatoorah.com/docs/refunds.md
- Staff can follow refunds in the portal: **Refunds → Refunds List**. https://docs.myfatoorah.com/docs/refunds.md
- A refund made in the portal still triggers `REFUND_STATUS_CHANGED` if that event is enabled: **NOT IN THE DOCS** explicitly (the webhook page says it notifies "the refund status"). https://docs.myfatoorah.com/docs/webhook-v2.md
- Void: only "Release" of an **authorized** (not captured) payment in Authorize & Capture, which needs the feature enabled by the account manager. https://docs.myfatoorah.com/docs/v3-auth-capture.md

---

## 11. Security

- Hosted page: card data is entered on MyFatoorah's page; "PCI Compliance: Managed by MyFatoorah" for the hosted page. https://docs.myfatoorah.com/docs/choose-your-payment-integration.md
- Webhook endpoint must be HTTPS with a valid certificate. https://docs.myfatoorah.com/docs/webhook-v2.md
- Validate the webhook signature; in v2 the secret is mandatory. https://docs.myfatoorah.com/docs/webhook-v2.md and https://docs.myfatoorah.com/docs/webhook-signature.md
- Confirm payment status from MyFatoorah's API, not from the redirect. https://docs.myfatoorah.com/docs/get-payment-details.md and https://docs.myfatoorah.com/docs/update-payment-status-guidelines.md
- The Laravel package is "examples"; "Any validations or security criteria must be taken from your side". https://docs.myfatoorah.com/docs/laravel.md
- Do not disable the user who created the token (it disables the token). https://docs.myfatoorah.com/docs/api-key.md
- Token permissions can be limited per key. https://docs.myfatoorah.com/docs/api-key.md
- IP allow-listing (MyFatoorah's webhook source IPs, or our server IPs registered with MyFatoorah): **NOT IN THE DOCS**.
- Minimum TLS version, mutual TLS: **NOT IN THE DOCS**.

---

## 12. KSA specifics

- Live API `https://api-sa.myfatoorah.com/`, portal `https://sa.myfatoorah.com/`. https://docs.myfatoorah.com/docs/api-key.md
- Country `SAU`, currency `SAR`, mobile code `+966`. https://docs.myfatoorah.com/docs/iso-lookups.md
- A KSA token is separate from other countries' tokens. https://docs.myfatoorah.com/docs/api-key.md
- Idempotency is available in Saudi Arabia. https://docs.myfatoorah.com/docs/idempotency.md
- Mada is a listed method (v2 code `md`), and "Apple Pay (Mada)" appears as its own method in the v2 example. https://docs.myfatoorah.com/docs/initiate-payment.md
- Mada and STC Pay have test data. https://docs.myfatoorah.com/docs/test-cards.md
- KSA RegisterApplePayDomain URL: `https://api-sa.myfatoorah.com/v2/RegisterApplePayDomain` (embedded only). https://docs.myfatoorah.com/docs/apple-pay-domain-verification.md
- Mada rules (e.g. mada cards must be routed as mada, co-branded cards), SAMA or other Saudi regulations, ZATCA/VAT-on-fees rules for merchants: **NOT IN THE DOCS** (only `ServiceChargeVAT` exists as a field: https://docs.myfatoorah.com/reference/get-payment-details.md).

---

## (a) Facts that decide our design

1. The redirect only carries `?paymentId=`; the order must be confirmed by calling `GET /v3/payments/{paymentId}`, never by the redirect itself. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md and https://docs.myfatoorah.com/docs/get-payment-details.md
2. Redirects can be lost (closed tab, slow network); MyFatoorah recommends using both the webhook and the status call. https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
3. "Success is final and cannot be override" — once a transaction is SUCCESS we may mark the order paid, and no later event may un-pay it. https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
4. A SUCCESS webhook overrides any other status for the same transaction, even if it arrives first or second. https://docs.myfatoorah.com/docs/webhook-v2.md
5. One invoice can hold several attempts (each its own PaymentId); invoice is PAID if any attempt is SUCCESS. https://docs.myfatoorah.com/docs/get-payment-details.md and https://docs.myfatoorah.com/docs/payment-inquiry.md
6. `Idempotency-Key` on `POST /v3/payments` works in KSA, caches 250 minutes, and returns the first response even if the body changed — so one key per payment attempt, stored before the call, makes a timed-out create safe to retry. https://docs.myfatoorah.com/docs/idempotency.md
7. `ExternalIdentifier` and `CustomerReference` are not unique; lookups return only the latest invoice — we must store our own InvoiceId per attempt. https://docs.myfatoorah.com/reference/get-invoice-by-externalidentifier.md and https://docs.myfatoorah.com/docs/payment-inquiry.md
8. The v3 invoice lookups return "No invoices match" for an invoice with **no** transactions; v2 GetPaymentStatus by InvoiceId works for such invoices. https://docs.myfatoorah.com/reference/get-invoice-by-invoiceid.md and https://docs.myfatoorah.com/docs/get-payment-status.md
9. The v2 webhook signature covers only `Invoice.Id, Invoice.Status, Transaction.Status, Transaction.PaymentId, Invoice.ExternalIdentifier` (in that order) — **not the amount**; amount checks must use the API response. https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model.md
10. The v2 webhook has no `Customer.Reference`; put our order reference in `Order.ExternalIdentifier` (signed) to match webhooks to orders. https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model.md
11. Webhook signature: HMAC-SHA256 over UTF-8 `key=value,...`, null → empty, Base64, header `MyFatoorah-Signature`, secret from Integration Settings → Webhook Settings. https://docs.myfatoorah.com/docs/webhook-signature.md
12. Webhook V2 retries: up to 5, max 180 s apart; missed events can be pulled with `POST /v2/GetWebhooks`. https://docs.myfatoorah.com/docs/webhook-v2.md and https://docs.myfatoorah.com/docs/getwebhooks.md
13. Status-call resilience: 30 s timeout, 3 retries 5 s apart, then park the order as "pending status update" and recheck later. https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
14. `PaymentExpiry` (UTC) exists on Create Payment, so the invoice can be given our 60-minute deadline — but what happens after it is undocumented (see (b)). https://docs.myfatoorah.com/reference/create-payment.md
15. No API to cancel an unpaid invoice is documented — the 60-minute auto-cancel must be our own state change, and a late SUCCESS can still arrive and must be handled (our 5-minute revive window, or a refund by staff). https://docs.myfatoorah.com/reference/update-payment.md (Release is only for authorized payments)
16. Test mode settles in KWD: `ValueInBaseCurrency` is KWD in test; compare SAR via `ValueInPayCurrency`/`ValueInDisplayCurrency`. https://docs.myfatoorah.com/docs/test-token.md and https://docs.myfatoorah.com/docs/idempotency.md
17. The customer may pay more than the order total if the commission is set on the customer; the store should set commission "on vendor" or the amount check must allow the service charge. https://docs.myfatoorah.com/docs/gateway-integration.md
18. Use `ApiName` values read from our own KSA account's `GET /v3/payment-methods`; Mada's v3 ApiName is not documented. https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
19. `GET /v3/payment-methods` takes no amount and may be cached; per-amount fees need v2 InitiatePayment. https://docs.myfatoorah.com/reference/get-payment-methods.md and https://docs.myfatoorah.com/reference/initiate-payment.md
20. Test and live use different base URLs and different tokens; each country has its own token; the store switch must change URL **and** token (and webhook secret, which is per portal). https://docs.myfatoorah.com/docs/api-key.md and https://docs.myfatoorah.com/docs/webhook-information.md
21. A key without the needed permission gets 401 "The token does not have the required permissions!" — the key must be granted at least create-payment and read permissions. https://docs.myfatoorah.com/docs/api-key.md
22. Legacy keys stop working at their expiry and must be replaced by new-style keys. https://docs.myfatoorah.com/docs/api-key.md
23. "Further payment" can be a v3 payment link with `NotificationOption` (`LINK`/`EMAIL`/`SMS`/`ALL`). https://docs.myfatoorah.com/docs/v3-invoicing.md
24. Per-invoice `IntegrationUrls.Webhook` can point an invoice's webhooks to a chosen URL, signed with the portal's secret. https://docs.myfatoorah.com/reference/create-payment.md

## (b) Not in the docs — to ask MyFatoorah support

1. After `PaymentExpiry` passes: is the hosted page blocked, and can an attempt already in 3-D Secure still succeed after the expiry?
2. What is the minimum `PaymentExpiry` allowed (is 60 minutes accepted)? What is the KSA account's default expiry?
3. What does invoice status `CANCELED` mean, and does an expired invoice become `CANCELED`?
4. Is there any API to cancel or void an unpaid invoice/payment link?
5. Can one invoice ever end with two SUCCESS transactions (customer pays the same link twice, e.g. two tabs)? If yes, how to detect and handle it?
6. The exact v3 `ApiName` values for Mada, STC Pay and "Apple Pay (Mada)" on a KSA account; and which spelling Create Payment accepts: `APPLE_PAY` or `APPLEPAY`?
7. When `PaymentMethod` is set on the hosted page, can the customer switch to another method there?
8. Does Apple Pay on the **hosted** page need our domain verified, or only embedded Apple Pay?
9. Does our KSA test token belong to a SAU demo account (SAR base) or a Kuwait demo account (KWD base)? Can a SAU demo account be created?
10. Is a token bound to one environment (live token rejected by `apitest` and vice versa)? What error is returned?
11. Do legacy tokens work with the v3 endpoints? When does our legacy token expire?
12. The exact permission names a key needs for: Create Payment, Get Payment Details, Get Invoice, Get Payment Methods, GetWebhooks, GetPaymentStatus.
13. The rate limit numbers for GET Payments / GetPaymentStatus in KSA, and the response (HTTP code, body) when exceeded.
14. Webhook V2: per-attempt timeout; which HTTP codes count as delivered; does a retry keep the same `Event.Reference`?
15. Is there a replay/timestamp protection for webhooks, and are there fixed source IPs we can allow-list?
16. Does v3 redirect to `IntegrationUrls.Redirection` on failure and cancel too, not only success? Any length limit or localhost restriction on it?
17. How many decimals does SAR accept in `Order.Amount`, and how is rounding done?
18. In live KSA, is the base currency SAR (so `ValueInBaseCurrency` equals the SAR amount when no conversion)?
19. Does a refund done by staff in the portal send `REFUND_STATUS_CHANGED` to our webhook?
20. Is mixing v3 (create, details) with v2 GetPaymentStatus (lookup by InvoiceId for an invoice with no attempts) supported long-term, given v2 "should not be used for new integrations"?
21. Any KSA rules we must follow as a merchant (mada routing/branding, SAMA, VAT on service charges)?
22. Is the Idempotency-Key honoured on the KSA live host `api-sa` exactly as on `apitest` (250 minutes)?

## (c) Every URL read

- https://docs.myfatoorah.com/llms.txt
- https://docs.myfatoorah.com/docs/get-started.md
- https://docs.myfatoorah.com/docs/live-account.md
- https://docs.myfatoorah.com/docs/account-information.md
- https://docs.myfatoorah.com/docs/orders-information.md
- https://docs.myfatoorah.com/docs/api-key.md
- https://docs.myfatoorah.com/docs/live-token.md
- https://docs.myfatoorah.com/docs/test-token.md
- https://docs.myfatoorah.com/docs/test-cards.md
- https://docs.myfatoorah.com/docs/iso-lookups.md
- https://docs.myfatoorah.com/docs/choose-your-payment-integration.md
- https://docs.myfatoorah.com/docs/payment-methods.md
- https://docs.myfatoorah.com/docs/v3-hosted-payment-page.md
- https://docs.myfatoorah.com/docs/v3-invoicing.md
- https://docs.myfatoorah.com/docs/features.md
- https://docs.myfatoorah.com/docs/get-payment-details.md
- https://docs.myfatoorah.com/docs/v3-updating-payment-status-guidelines.md
- https://docs.myfatoorah.com/docs/update-payment-status-guidelines.md
- https://docs.myfatoorah.com/docs/gateway-integration.md
- https://docs.myfatoorah.com/docs/gateway-integration-sample-code.md (PHP part)
- https://docs.myfatoorah.com/docs/initiate-payment.md
- https://docs.myfatoorah.com/docs/execute-payment.md
- https://docs.myfatoorah.com/docs/invoice-link.md
- https://docs.myfatoorah.com/docs/payment-inquiry.md
- https://docs.myfatoorah.com/docs/get-payment-status.md
- https://docs.myfatoorah.com/docs/refunds.md
- https://docs.myfatoorah.com/docs/refund.md
- https://docs.myfatoorah.com/docs/make-refund.md
- https://docs.myfatoorah.com/docs/v3-auth-capture.md (introduction and step 1)
- https://docs.myfatoorah.com/docs/webhook.md
- https://docs.myfatoorah.com/docs/webhook-information.md
- https://docs.myfatoorah.com/docs/webhook-signature.md
- https://docs.myfatoorah.com/docs/webhook-v1.md
- https://docs.myfatoorah.com/docs/transaction-data-model.md (signature section)
- https://docs.myfatoorah.com/docs/webhook-v2.md
- https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model.md
- https://docs.myfatoorah.com/docs/webhook-v2-refund-data-model.md (refund object and signature)
- https://docs.myfatoorah.com/docs/getwebhooks.md
- https://docs.myfatoorah.com/docs/response-model.md
- https://docs.myfatoorah.com/docs/idempotency.md
- https://docs.myfatoorah.com/docs/embedded-payment-v3.md (introduction, prerequisites, environment URLs)
- https://docs.myfatoorah.com/docs/apple-pay.md (introduction)
- https://docs.myfatoorah.com/docs/apple-pay-domain-verification.md
- https://docs.myfatoorah.com/docs/php-library.md (payment operations part)
- https://docs.myfatoorah.com/docs/laravel.md
- https://docs.myfatoorah.com/docs/plugin-api-permissions.md
- https://docs.myfatoorah.com/reference/create-payment.md
- https://docs.myfatoorah.com/reference/get-payment-details.md
- https://docs.myfatoorah.com/reference/update-payment.md (summary and operation types)
- https://docs.myfatoorah.com/reference/get-invoice-by-invoiceid.md
- https://docs.myfatoorah.com/reference/get-invoice-by-externalidentifier.md
- https://docs.myfatoorah.com/reference/get-payment-methods.md
- https://docs.myfatoorah.com/reference/create-refund.md (field descriptions)
- https://docs.myfatoorah.com/reference/get-refund-details.md (400 example)
- https://docs.myfatoorah.com/reference/initiate-payment.md
- https://docs.myfatoorah.com/reference/execute-payment.md
- https://docs.myfatoorah.com/reference/send-payment.md (URL fields, expiry, 400 example)
- https://docs.myfatoorah.com/reference/get-payment-status.md
- https://docs.myfatoorah.com/reference/get-webhooks.md
- https://docs.myfatoorah.com/reference/register-apple-pay-domain.md
- https://docs.myfatoorah.com/reference/get-currencies-exchange-list.md (summary)
