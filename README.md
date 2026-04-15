# Basic Refund Tool

A complete charge-and-refund implementation using the Global Payments GP API. Developers can charge a card using hosted tokenization, then issue a full or partial refund against any transaction ID — all without handling raw card data. All implementations use the official Global Payments SDK (`GpApiConfig`).

Available in four languages: PHP, Node.js, .NET, and Java.

---

## Available Implementations

| Language | Framework | SDK Version |
|----------|-----------|-------------|
| [**PHP**](./php/) | Built-in Server | globalpayments/php-sdk ^13.1 |
| [**Node.js**](./nodejs/) | Express.js | globalpayments-api ^3.10.6 |
| [**.NET**](./dotnet/) | ASP.NET Core | GlobalPayments.Api 9.0.16 |
| [**Java**](./java/) | Jakarta Servlet | globalpayments-sdk 14.2.20 |

Preview links (runs in browser via CodeSandbox):
- [PHP Preview](https://githubbox.com/globalpayments-samples/basic-refund-tool/tree/main/php)
- [Node.js Preview](https://githubbox.com/globalpayments-samples/basic-refund-tool/tree/main/nodejs)
- [.NET Preview](https://githubbox.com/globalpayments-samples/basic-refund-tool/tree/main/dotnet)
- [Java Preview](https://githubbox.com/globalpayments-samples/basic-refund-tool/tree/main/java)

---

## How It Works

This tool demonstrates a two-step payment lifecycle:

1. **Charge** — The frontend loads a hosted payment form from GP API. The customer enters card details, which are tokenized client-side. The token is sent to `POST /charge`, which calls the GP API SDK to capture the payment and returns a `transactionId`.
2. **Refund** — The `transactionId` from the charge is passed to `POST /refund` along with the refund amount. The backend calls `Transaction.fromId()` on the SDK to issue the refund without needing the original card data.

```
Browser
  │
  ├─ GET /config ──────────────────► Server
  │                                    └─ GP API: generate access token
  │  ◄── { accessToken, environment } ──┘
  │
  ├─ Hosted fields tokenize card (client-side, PCI-compliant)
  │
  ├─ POST /charge ─────────────────► Server
  │   { payment_token, amount }        └─ SDK: CreditCardData.charge().execute()
  │  ◄── { transactionId, status } ───┘
  │
  └─ POST /refund ─────────────────► Server
      { transactionId, amount }        └─ SDK: Transaction.fromId().refund().execute()
     ◄── { refundId, status } ────────┘
```

---

## Prerequisites

- Global Payments developer account — [Sign up at developer.globalpayments.com](https://developer.globalpayments.com)
- GP API credentials: `APP_ID` and `APP_KEY` (sandbox credentials available after sign-up)
- Docker (for multi-service setup), or a local runtime for your chosen language:
  - PHP 8.0+ with Composer
  - Node.js 18+ with npm
  - .NET 8.0 SDK
  - Java 17+ with Maven

---

## Quick Start

### 1. Clone the repository

```bash
git clone https://github.com/globalpayments-samples/basic-refund-tool.git
cd basic-refund-tool
```

### 2. Choose a language and configure credentials

```bash
cd php       # or nodejs, dotnet, java
cp .env.sample .env
```

Edit `.env`:

```env
GP_API_APP_ID=your_app_id_here
GP_API_APP_KEY=your_app_key_here
GP_API_ENVIRONMENT=test
```

### 3. Install and run

**PHP:**
```bash
composer install
php -S localhost:8003
```
Open: http://localhost:8003

**Node.js:**
```bash
npm install
npm start
```
Open: http://localhost:8001

**.NET:**
```bash
dotnet restore
dotnet run
```
Open: http://localhost:8006

**Java:**
```bash
mvn clean package
mvn cargo:run
```
Open: http://localhost:8004

### 4. Run a charge and refund

1. Open the app in your browser
2. Enter an amount (e.g. `19.99`)
3. Use a test card (see [Test Cards](#test-cards) below)
4. Click **Charge** — note the `transactionId` in the response
5. Enter that `transactionId` and an amount to refund
6. Click **Refund** — confirm the refund response

---

## Docker Setup

Run all four language implementations simultaneously:

```bash
# Copy root-level env (used by all services)
cp .env.sample .env
# Edit .env with your credentials, then:
docker-compose up
```

Individual services:

```bash
docker-compose up nodejs    # http://localhost:8001
docker-compose up php       # http://localhost:8003
docker-compose up dotnet    # http://localhost:8006
docker-compose up java      # http://localhost:8004
```

Run integration tests (requires all services healthy):

```bash
docker-compose --profile testing up
```

Test results written to `./test-results/` and `./playwright-report/`.

---

## API Endpoints

### `GET /config`

Returns a GP API access token for client-side hosted field initialization. The token has restricted permissions (`PMT_POST_Create_Single`) — it can only tokenize cards, not process transactions.

**Response:**
```json
{
  "success": true,
  "data": {
    "accessToken": "uua7....",
    "environment": "test",
    "supportedCurrencies": ["USD", "EUR", "GBP", "CAD"],
    "defaultCurrency": "USD",
    "refund": {
      "maxPercentage": 115,
      "timeWindowDays": 180
    }
  }
}
```

---

### `POST /charge`

Processes a card payment using a tokenized payment reference.

**Request body:**
```json
{
  "payment_token": "PMT_abc123...",
  "amount": 19.99,
  "currency": "USD",
  "billing_zip": "10001",
  "cardDetails": {
    "cardType": "visa",
    "cardLast4": "9299"
  }
}
```

**Success response (`200`):**
```json
{
  "success": true,
  "message": "Payment processed successfully",
  "data": {
    "transactionId": "TRN_abc123xyz",
    "amount": 19.99,
    "currency": "USD",
    "status": "captured",
    "responseCode": "SUCCESS",
    "responseMessage": "Approved",
    "authorizationCode": "12345",
    "referenceNumber": "REF_001",
    "timestamp": "2025-01-15T10:30:00.000Z",
    "paymentMethod": {
      "type": "card",
      "brand": "Visa",
      "last4": "9299"
    }
  }
}
```

**Error response (`422`):**
```json
{
  "success": false,
  "message": "Payment failed: Declined",
  "error_code": "PAYMENT_DECLINED",
  "timestamp": "2025-01-15T10:30:00.000Z"
}
```

---

### `POST /refund`

Issues a refund against an existing transaction ID. Supports partial refunds (amount less than original) and full refunds.

**Request body:**
```json
{
  "transactionId": "TRN_abc123xyz",
  "amount": 19.99,
  "currency": "USD",
  "reason": "Customer requested refund"
}
```

**Success response (`200`):**
```json
{
  "success": true,
  "message": "Refund processed successfully",
  "data": {
    "refundId": "TRN_ref456xyz",
    "transactionId": "TRN_abc123xyz",
    "amount": 19.99,
    "currency": "USD",
    "status": "captured",
    "responseCode": "SUCCESS",
    "responseMessage": "Refunded",
    "authorizationCode": "67890",
    "referenceNumber": "REF_002",
    "reason": "Customer requested refund",
    "timestamp": "2025-01-15T10:35:00.000Z"
  }
}
```

**Error response (`422`):**
```json
{
  "success": false,
  "message": "Refund failed: Transaction not found",
  "error_code": "REFUND_DECLINED",
  "timestamp": "2025-01-15T10:35:00.000Z"
}
```

---

## Test Cards

Use these in sandbox (`GP_API_ENVIRONMENT=test`). CVV: `123`. Expiry: any future date.

| Brand | Card Number | Expected Result |
|-------|-------------|-----------------|
| Visa | 4263 9826 4026 9299 | Approved |
| Visa | 4263 9700 0000 5262 | Approved |
| Mastercard | 5425 2334 2424 1200 | Approved |
| Discover | 6011 0000 0000 0012 | Approved |
| Amex | 3714 496353 98431 | Approved |

> Sandbox transactions do not move real money. Use test credentials only.

---

## Project Structure

```
basic-refund-tool/
├── index.html                  # Shared frontend (served by all backends)
├── docker-compose.yml          # Multi-service Docker config
├── Dockerfile.tests            # Playwright test runner
├── LICENSE
├── README.md
│
├── php/                        # Port 8003
│   ├── .env.sample
│   ├── composer.json
│   ├── Dockerfile
│   ├── PaymentUtils.php        # SDK config + shared helpers
│   ├── config.php              # GET /config endpoint
│   ├── charge.php              # POST /charge endpoint
│   ├── refund.php              # POST /refund endpoint
│   └── index.html              # Serves frontend
│
├── nodejs/                     # Port 8001
│   ├── .env.sample
│   ├── package.json
│   ├── Dockerfile
│   └── server.js               # Express app: /config, /charge, /refund
│
├── dotnet/                     # Port 8006
│   ├── .env.sample
│   ├── dotnet.csproj
│   ├── Program.cs              # ASP.NET Core app: all endpoints
│   ├── Dockerfile
│   └── wwwroot/                # Static frontend files
│
└── java/                       # Port 8004
    ├── .env.sample
    ├── pom.xml
    ├── Dockerfile
    └── src/
        └── main/java/com/globalpayments/example/
            ├── ConfigServlet.java
            ├── ProcessPaymentServlet.java
            └── RefundServlet.java
```

---

## Environment Variables

All language implementations use the same three variables:

| Variable | Description | Example |
|----------|-------------|---------|
| `GP_API_APP_ID` | Your GP API application ID | `UJqPrAhrDkGzzNoFInpzKqoI8vfZtGRV` |
| `GP_API_APP_KEY` | Your GP API application key | `zCFrbrn0NKly9sB4` |
| `GP_API_ENVIRONMENT` | `test` for sandbox, `production` for live | `test` |

Credentials are available in the [GP Developer Portal](https://developer.globalpayments.com) after creating an account.

---

## Troubleshooting

**`401 Unauthorized` on `/config`**
Credentials are invalid or for the wrong environment. Verify `GP_API_APP_ID` and `GP_API_APP_KEY` in `.env` match the environment setting (`test` vs `production`).

**`422` on `/charge` — "Payment failed"**
The test card may have been declined. Try a different card from the [Test Cards](#test-cards) table. Confirm `GP_API_ENVIRONMENT=test` when using test cards.

**`422` on `/refund` — "Transaction not found"**
The `transactionId` does not exist in sandbox or has expired. Use the `transactionId` returned by a `/charge` call made in the same session with the same credentials.

**Port already in use**
Another process is using the port. Either stop it (`lsof -i :8003`) or change the port mapping in `docker-compose.yml`.

**Java build fails — `mvn cargo:run`**
Requires Java 17+ and Maven 3.8+. Verify with `java -version` and `mvn -version`.

**.NET — `dotenv.net` missing**
Run `dotnet restore` before `dotnet run` to install NuGet dependencies.

## Community

- 🌐 **Developer Portal** — [developer.globalpayments.com](https://developer.globalpayments.com)
- 💬 **Discord** — [Join the community](https://discord.gg/myER9G9qkc)
- 📋 **GitHub Discussions** — [github.com/orgs/globalpayments/discussions](https://github.com/orgs/globalpayments/discussions)
- 📧 **Newsletter** — [Subscribe](https://www.globalpayments.com/en-gb/modals/newsletter)
- 💼 **LinkedIn** — [Global Payments for Developers](https://www.linkedin.com/showcase/global-payments-for-developers/posts/?feedView=all)

Have a question or found a bug? [Open an issue](https://github.com/globalpayments-samples/basic-refund-tool/issues) or reach out at [communityexperience@globalpay.com](mailto:communityexperience@globalpay.com).

---

## License

MIT — see [LICENSE](./LICENSE).
