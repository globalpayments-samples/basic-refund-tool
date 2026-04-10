# C# Payment & Refund Tool

A simplified payment processing and refund management tool using C# ASP.NET Core and the Global Payments SDK.

## Features

- **Single-Page Interface** - Streamlined UI with payment form and inline refund functionality
- **Payment Processing** - Process credit card payments using Global Payments tokenization
- **Instant Refunds** - Refund transactions immediately after processing (full or partial)
- **Test Card Helper** - Built-in test card selector with auto-fill functionality
- **No Database Required** - Stateless design, no transaction storage needed
- **Duplicate Refund Prevention** - Automatically disables refund form after successful refund

## Requirements

- .NET 9.0 or later
- Global Payments account and API credentials (GP API)

## Project Structure

```
dotnet/
├── Program.cs           # Main application with payment and refund endpoints
├── wwwroot/
│   └── index.html      # Single-page frontend application
├── appsettings.json    # Application configuration
├── dotnet.csproj       # Project dependencies
├── .env.sample         # Template for environment variables
└── run.sh              # Convenience script to run the application
```

## Setup

1. **Install dependencies:**
   ```bash
   dotnet restore
   ```

2. **Configure environment variables:**
   ```bash
   cp .env.sample .env
   ```

   Update `.env` with your Global Payments GP API credentials:
   ```env
   GP_API_APP_ID=your_app_id
   GP_API_APP_KEY=your_app_key
   GP_API_ENVIRONMENT=test  # or 'production'
   ```

3. **Run the application:**
   ```bash
   ./run.sh
   ```

   Or manually:
   ```bash
   dotnet run
   ```

4. **Access the application:**
   Open your browser to `http://localhost:5000`

## Usage

### Processing a Payment

1. Enter payment amount and billing zip code
2. Select a test card from the dropdown (optional, for testing)
3. Enter credit card information
4. Click "Process Payment"
5. Transaction details appear below the form upon success

### Processing a Refund

1. After a successful payment, the refund section appears
2. Choose "Full Refund" or "Partial Refund"
3. For partial refunds, enter the refund amount
4. Optionally enter a reason for the refund
5. Click "Process Refund"
6. Refund success/error message displays inline
7. Refund form is automatically disabled to prevent duplicates

### Test Cards

The application includes official Global Payments test cards:

- **Visa**: 4263982640269299
- **Mastercard**: 5425233424241200
- **American Express**: 374101000000608
- **Discover**: 6011000000000087
- **JCB**: 3566000000000000
- **Diners Club**: 36256000000725

All test cards use:
- **CVV**: 123 (1234 for Amex)
- **Expiry**: 12/2025

## API Endpoints

### GET /config
Generates a session token for client-side tokenization.

**Response:**
```json
{
  "success": true,
  "data": {
    "accessToken": "session_token_here"
  }
}
```

### POST /charge
Processes a payment using the provided token.

**Request Body:**
```json
{
  "payment_token": "PMT_xxx",
  "amount": 25.00,
  "currency": "USD",
  "billing_zip": "12345",
  "cardDetails": {
    "cardType": "visa",
    "cardLast4": "5262"
  }
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Payment processed successfully",
  "data": {
    "transactionId": "TRN_xxx",
    "amount": 25.00,
    "currency": "USD",
    "status": "captured",
    "responseCode": "SUCCESS",
    "responseMessage": "CAPTURED",
    "timestamp": "2025-10-02T14:18:39+00:00",
    "authorizationCode": "",
    "referenceNumber": "08c055bd-9721-4844-b094-1238d8e76f33",
    "paymentMethod": {
      "type": "card",
      "brand": "Visa",
      "last4": "5262"
    }
  }
}
```

### POST /refund
Processes a refund for a transaction.

**Request Body:**
```json
{
  "transactionId": "TRN_xxx",
  "amount": 25.00,
  "currency": "USD",
  "reason": "Customer request"
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Refund processed successfully",
  "data": {
    "refundId": "REF_xxx",
    "transactionId": "TRN_xxx",
    "amount": 25.00,
    "currency": "USD",
    "status": "captured",
    "responseCode": "SUCCESS",
    "responseMessage": "CAPTURED",
    "timestamp": "2025-10-02T14:20:00+00:00",
    "authorizationCode": "",
    "referenceNumber": "abc123",
    "reason": "Customer request"
  }
}
```

## Implementation Details

### Stateless Architecture
- No database or storage layer required
- Transaction IDs are passed directly from frontend to backend
- Each operation is independent and self-contained

### Payment Processing Flow
1. Frontend loads `/config` endpoint to get session token
2. Global Payments SDK tokenizes card data client-side
3. Token is sent to `/charge` endpoint for processing
4. GP API processes payment and returns transaction ID
5. Frontend stores transaction data for refund capability

### Refund Processing Flow
1. User initiates refund from success screen
2. Transaction ID is sent to `/refund` endpoint with refund amount
3. GP API processes refund against original transaction
4. Success/error message displayed inline
5. Refund form is disabled to prevent duplicate refunds

### Security Features
- Client-side tokenization (no raw card data touches server)
- CORS handling for cross-origin requests
- Input validation and sanitization
- Error message sanitization
- Secure session token generation with limited permissions

## Error Handling

The application implements comprehensive error handling:

- **Validation Errors**: Missing or invalid input parameters
- **Payment Errors**: Card declined, insufficient funds, etc.
- **Refund Errors**: Transaction not found, invalid amount, etc.
- **API Errors**: Network issues, authentication failures, etc.

All errors are logged server-side and user-friendly messages are displayed in the UI.

## Development

### File Descriptions

**Backend:**
- `Program.cs` - Main application with ASP.NET Core minimal API endpoints for config, charge, and refund operations

**Frontend:**
- `wwwroot/index.html` - Single-page application with payment form, success display, and inline refund section

### Customization

**Modify Default Amount:**
```html
<!-- In wwwroot/index.html -->
<input type="number" id="amount" name="amount" value="25.00" required>
```

**Change Currency:**
```javascript
// In wwwroot/index.html, submitPayment function
currency: 'USD',  // Change to EUR, GBP, etc.
```

**Adjust Session Token Permissions:**
```csharp
// In Program.cs, /config endpoint
Permissions = new[] { "PMT_POST_Create_Single" }
```

**Add Custom Validation:**
```csharp
// In Program.cs, /charge or /refund endpoint
if (string.IsNullOrEmpty(yourField))
{
    return Results.BadRequest(new { success = false, message = "Your field is required" });
}
```

## Security Considerations

**For Production Use:**

- ✅ Use HTTPS only
- ✅ Implement rate limiting
- ✅ Add CSRF protection
- ✅ Implement proper logging and monitoring
- ✅ Set restrictive CORS policies
- ✅ Validate all input data
- ✅ Sanitize error messages
- ✅ Use environment variables for all credentials
- ✅ Set appropriate security headers
- ✅ Implement request signing/verification
- ✅ Add fraud detection measures

**Not Included (Add for Production):**
- Request authentication/authorization
- User session management
- Advanced fraud detection
- Transaction history/reporting
- Email notifications
- Webhook handling
- Multi-currency support
- Payment method management

## Troubleshooting

**Issue: "GlobalPayments failed to initialize"**
- Check that the Global Payments JS SDK is loading correctly
- Verify network connectivity to `js.globalpay.com`

**Issue: "Configuration failed"**
- Verify `.env` file exists with correct credentials
- Check GP API credentials are valid and active
- Ensure `GP_API_ENVIRONMENT` is set to `test` or `production`

**Issue: "Payment token is required"**
- Verify card form tokenization is completing successfully
- Check browser console for JavaScript errors
- Ensure all card fields are filled correctly

**Issue: "Refund processing failed"**
- Verify transaction ID is valid and in GP API system
- Check that transaction hasn't already been fully refunded
- Ensure refund amount doesn't exceed original transaction amount

**Issue: "Memory exhaustion" (if you encounter this)**
- This was an issue in a previous version with transaction storage
- Current version has no storage layer and should not have memory issues
- If encountered, check for infinite loops in custom code

## License

MIT License - See LICENSE file for details

## Support

For Global Payments API support, visit:
- [Developer Documentation](https://developer.globalpayments.com/)
- [API Reference](https://developer.globalpayments.com/api/references-overview)
- [SDKs](https://github.com/globalpayments/dotnet-sdk)
