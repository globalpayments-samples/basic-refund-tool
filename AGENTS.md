# Basic Refund Tool

> Charge a tokenized card, then issue a full or partial refund against the resulting transaction ID — implemented with the Global Payments GP API SDK in PHP, Node.js, .NET, and Java.

## Critical Patterns

1. **The `/config` access token is intentionally restricted to tokenization only.** Every implementation sets `Permissions = ["PMT_POST_Create_Single"]` on the `GpApiConfig` that generates the client-side token. That token can only tokenize cards via hosted fields — it cannot charge or refund. The server then reconfigures the SDK with full permissions (no `Permissions` array) before calling `charge()` or `refund()`. If you serve a fully-permissioned token to the browser, you leak the ability to issue arbitrary transactions from any client.

2. **Refunds work from the transaction ID alone — original card data is never re-handled.** All four implementations call `Transaction.fromId(transactionId).refund(amount).withCurrency(...).execute()`. PHP is the one exception: it constructs `new Transaction()` and assigns `$transaction->transactionId` directly (see `PaymentUtils::processRefundWithGpApi`). Functionally equivalent, but if you're adapting PHP code to another language, use the `fromId(...)` static factory instead.

3. **`Channel.CardNotPresent` + `country = "US"` are hardcoded in every config.** Both values appear in `createGpApiConfig` (Node.js), `PaymentUtils::configureSdk` (PHP), `ConfigureGlobalPaymentsSDK` (.NET), and `configureGpApiService` (Java). Changing the channel or country requires editing every language — there is no env var override.

4. **`docker-compose.yml` references `PUBLIC_API_KEY` and `SECRET_API_KEY`, but no code reads them.** Every implementation reads `GP_API_APP_ID` / `GP_API_APP_KEY` / `GP_API_ENVIRONMENT` instead. Setting only the docker-compose vars in your shell leaves the container with no credentials. Set the GP_API_* vars (or place a `.env` in each language dir) before `docker-compose up`.

## Repository Structure

### PHP (built-in PHP server + Global Payments SDK)
- [`php/PaymentUtils.php`](php/PaymentUtils.php) — shared helpers; `configureSdk()`, `processPaymentWithGpApi()`, `processRefundWithGpApi()`, `getTestCards()`
- [`php/config.php`](php/config.php) — `GET /config`; calls `GpApiService::generateTransactionKey()` with `PMT_POST_Create_Single` permission
- [`php/charge.php`](php/charge.php) — `POST /charge`; entry point that calls `PaymentUtils::processPaymentWithGpApi()`
- [`php/refund.php`](php/refund.php) — `POST /refund`; entry point that calls `PaymentUtils::processRefundWithGpApi()`
- [`php/index.html`](php/index.html) — per-language copy of the frontend form
- [`php/composer.json`](php/composer.json) — `globalpayments/php-sdk` ^13.1

### Node.js (Express + Global Payments SDK)
- [`nodejs/server.js`](nodejs/server.js) — single-file app; `createGpApiConfig()`, `initializeGpApi()`, `sanitizePostalCode()`, `/config`, `/charge`, `/refund` route handlers
- [`nodejs/index.html`](nodejs/index.html) — per-language copy of the frontend form
- [`nodejs/package.json`](nodejs/package.json) — `globalpayments-api` ^3.10.6, Express 4

### .NET (ASP.NET Core minimal API + Global Payments SDK)
- [`dotnet/Program.cs`](dotnet/Program.cs) — `Main()`, `ConfigureGlobalPaymentsSDK()`, `ConfigureEndpoints()`, `ConfigureChargeEndpoint()`, `ConfigureRefundEndpoint()`, `SanitizePostalCode()`; request DTOs `PaymentRequest` / `RefundRequest` / `CardDetails`
- [`dotnet/wwwroot/`](dotnet/wwwroot) — static frontend assets served by `UseDefaultFiles` / `UseStaticFiles`
- [`dotnet/dotnet.csproj`](dotnet/dotnet.csproj) — `GlobalPayments.Api` 9.0.16, `DotEnv.Net` 3.2.1, net9.0

### Java (Jakarta Servlet on embedded Tomcat 10 + Global Payments SDK)
- [`java/src/main/java/com/globalpayments/example/ProcessPaymentServlet.java`](java/src/main/java/com/globalpayments/example/ProcessPaymentServlet.java) — single servlet mapped to `/config`, `/charge`, `/refund`; `doGet()` handles config, `doPost()` dispatches to `handleChargeRequest()` / `handleRefundRequest()`; `configureGpApiService()` builds the `GpApiConfig`; ships a hand-rolled `parseJsonString()` / `toJson()` instead of pulling in Jackson
- [`java/src/main/webapp/index.html`](java/src/main/webapp/index.html) — per-language copy of the frontend form
- [`java/src/main/webapp/WEB-INF/web.xml`](java/src/main/webapp/WEB-INF/web.xml) — servlet wiring
- [`java/pom.xml`](java/pom.xml) — `globalpayments-sdk` 14.2.20, Jakarta Servlet 5.0, cargo-maven3-plugin (Tomcat 10x embedded on port 8000)

### Shared
- [`index.html`](index.html) — root copy of the frontend form (each language directory also keeps its own copy; all four are expected to stay in sync)
- [`docker-compose.yml`](docker-compose.yml) — four services on host ports 8001 (Node.js), 8003 (PHP), 8004 (Java), 8006 (.NET), all mapping to container port 8000; `tests` service runs Playwright via `Dockerfile.tests`
- [`README.md`](README.md) — developer-facing quick start and per-endpoint API reference

## API Surface

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/config` | Returns a tokenization-only GP API access token plus supported currencies, refund window, and environment metadata |
| POST | `/charge` | Charges a card using a `payment_token` from hosted fields; returns the `transactionId` to use for later refunds |
| POST | `/refund` | Refunds (full or partial) against an existing `transactionId`; same shape across all four languages |

All four implementations expose identical endpoints and JSON response shapes.

## Environment Variables

```bash
GP_API_APP_ID=your_app_id            # GP API application ID
GP_API_APP_KEY=your_app_key          # GP API application key
GP_API_ENVIRONMENT=test              # "test" for sandbox, "production" for live
PORT=8000                            # Optional; defaults to 8000 in every implementation
```

Each language reads from a `.env` in its own directory — there is no root `.env.sample`. The `docker-compose.yml` declares `PUBLIC_API_KEY` and `SECRET_API_KEY` for every service, but no source file references them; treat them as obsolete and supply the `GP_API_*` vars instead.

## Test Cards

Sandbox cards for GP-API. CVV `123`, expiry any future date.

| Brand | Number | Expected Result |
|-------|--------|-----------------|
| Visa | 4263970000005262 | Approved |
| Mastercard | 5425230000004415 | Approved |

Get sandbox credentials at [developer.globalpayments.com](https://developer.globalpayments.com).

## Architecture Summary

**Charge:** browser loads hosted fields via `accessToken` from `GET /config` → card tokenized client-side → `POST /charge {payment_token, amount}` → SDK `CreditCardData.charge(amount).withCurrency(...).execute()` → returns `transactionId`
**Refund:** UI submits `transactionId` from a prior charge → `POST /refund {transactionId, amount}` → SDK `Transaction.fromId(transactionId).refund(amount).execute()` (PHP: `new Transaction(); $t->transactionId = ...; $t->refund(...)`) → returns `refundId`

## Security Notes

This is demo code. No authentication on `/charge` or `/refund` — anyone with the URL can charge or refund. Servers log full GP API response objects and partially-masked tokens to stdout. PHP writes request/response logs to `php/logs/`. For production: add auth middleware, drop the verbose logging, scope refund permission to admin users, and serve over HTTPS with proper secrets management.

## How to Run

```bash
cd php && ./run.sh       # PHP — :8000 (host :8003 via docker-compose)
cd nodejs && ./run.sh    # Node.js — :8000 (host :8001 via docker-compose)
cd dotnet && ./run.sh    # .NET — :8000 (host :8006 via docker-compose)
cd java && ./run.sh      # Java — :8000 (host :8004 via docker-compose)
# All at once:
docker-compose up
```

`POST /charge` cannot be tested with curl alone — it requires a `payment_token` produced by hosted fields, which only render in a browser. Open the frontend (e.g. `http://localhost:8000` after `./run.sh`) to exercise the charge flow. `GET /config` and `POST /refund` (with a previously-captured `transactionId`) are curl-testable.

## How to Verify

```bash
# Config — fetch a tokenization access token
curl http://localhost:8000/config
# Expected: {"success":true,"data":{"accessToken":"...","environment":"test","supportedCurrencies":["USD","EUR","GBP","CAD"],...}}

# Refund against a transactionId from a prior browser charge
curl -X POST http://localhost:8000/refund \
  -H "Content-Type: application/json" \
  -d '{"transactionId":"TRN_xxx","amount":19.99,"currency":"USD","reason":"test"}'
# Expected: {"success":true,"data":{"refundId":"TRN_yyy","transactionId":"TRN_xxx","amount":19.99,"status":"captured",...}}

# Charge — browser-only (hosted fields tokenize card client-side; no raw-card path on the server)
```

## Making Changes

All four language implementations expose identical behavior. A change to one must be applied to all — each language in a separate commit. The shared files are the root [`index.html`](index.html), the per-language `index.html` copies (`php/index.html`, `nodejs/index.html`, `dotnet/wwwroot/index.html`, `java/src/main/webapp/index.html`), and [`docker-compose.yml`](docker-compose.yml). Edits to any of these must keep every implementation working — in particular, do not change container port 8000 in one service without updating all of them.

## SDK Versions

- **PHP**: `globalpayments/php-sdk` ^13.1
- **Node.js**: `globalpayments-api` ^3.10.6
- **.NET**: `GlobalPayments.Api` 9.0.16
- **Java**: `globalpayments-sdk` (com.heartlandpaymentsystems) 14.2.20
