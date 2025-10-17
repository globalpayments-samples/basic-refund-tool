using GlobalPayments.Api;
using GlobalPayments.Api.Entities;
using GlobalPayments.Api.PaymentMethods;
using GlobalPayments.Api.Services;
using dotenv.net;
using System.Text.Json;
using System.Text.Json.Serialization;
using GpEnvironment = GlobalPayments.Api.Entities.Environment;

namespace BasicRefundTool;

/// <summary>
/// Payment & Refund Processing Application
///
/// This application demonstrates payment and refund processing using the Global Payments GP API.
/// It provides endpoints for configuration, payment processing, and refund handling.
/// </summary>
public class Program
{
    public static void Main(string[] args)
    {
        // Load environment variables from .env file
        DotEnv.Load();

        var builder = WebApplication.CreateBuilder(args);

        var app = builder.Build();

        // Configure CORS
        app.Use(async (context, next) =>
        {
            context.Response.Headers.Append("Access-Control-Allow-Origin", "*");
            context.Response.Headers.Append("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
            context.Response.Headers.Append("Access-Control-Allow-Headers", "Content-Type, Authorization");

            if (context.Request.Method == "OPTIONS")
            {
                context.Response.StatusCode = 200;
                return;
            }

            await next();
        });

        // Configure static file serving for the payment form
        app.UseDefaultFiles();
        app.UseStaticFiles();

        // Configure the SDK on startup
        ConfigureGlobalPaymentsSDK();

        ConfigureEndpoints(app);

        var port = System.Environment.GetEnvironmentVariable("PORT") ?? "8000";
        app.Urls.Add($"http://0.0.0.0:{port}");

        app.Run();
    }

    /// <summary>
    /// Configures the Global Payments GP API SDK with necessary credentials and settings.
    /// </summary>
    private static void ConfigureGlobalPaymentsSDK()
    {
        var config = new GpApiConfig
        {
            AppId = System.Environment.GetEnvironmentVariable("GP_API_APP_ID"),
            AppKey = System.Environment.GetEnvironmentVariable("GP_API_APP_KEY"),
            Channel = Channel.CardNotPresent,
            Country = "US",
            Environment = System.Environment.GetEnvironmentVariable("GP_API_ENVIRONMENT")?.ToLower() == "production"
                ? GpEnvironment.PRODUCTION
                : GpEnvironment.TEST
        };

        ServicesContainer.ConfigureService(config);
    }

    /// <summary>
    /// Configures the application's HTTP endpoints.
    /// </summary>
    private static void ConfigureEndpoints(WebApplication app)
    {
        // Config endpoint - generates session token for client-side tokenization
        app.MapGet("/config", () =>
        {
            try
            {
                var config = new GpApiConfig
                {
                    AppId = System.Environment.GetEnvironmentVariable("GP_API_APP_ID"),
                    AppKey = System.Environment.GetEnvironmentVariable("GP_API_APP_KEY"),
                    Channel = Channel.CardNotPresent,
                    Country = "US",
                    Environment = System.Environment.GetEnvironmentVariable("GP_API_ENVIRONMENT")?.ToLower() == "production"
                        ? GpEnvironment.PRODUCTION
                        : GpEnvironment.TEST,
                    Permissions = new[] { "PMT_POST_Create_Single" }
                };

                var accessTokenInfo = GpApiService.GenerateTransactionKey(config);

                return Results.Ok(new
                {
                    success = true,
                    data = new
                    {
                        accessToken = accessTokenInfo.Token
                    }
                });
            }
            catch (Exception ex)
            {
                return Results.Json(new
                {
                    success = false,
                    message = $"Error loading configuration: {ex.Message}"
                }, statusCode: 500);
            }
        });

        ConfigureChargeEndpoint(app);
        ConfigureRefundEndpoint(app);
    }

    /// <summary>
    /// Sanitizes postal code input by removing invalid characters.
    /// </summary>
    private static string SanitizePostalCode(string? postalCode)
    {
        if (string.IsNullOrEmpty(postalCode)) return string.Empty;

        var sanitized = new string(postalCode.Where(c => char.IsLetterOrDigit(c) || c == '-').ToArray());
        return sanitized.Length > 10 ? sanitized[..10] : sanitized;
    }

    /// <summary>
    /// Configures the charge endpoint for payment processing.
    /// </summary>
    private static void ConfigureChargeEndpoint(WebApplication app)
    {
        app.MapPost("/charge", async (HttpContext context) =>
        {
            try
            {
                using var reader = new StreamReader(context.Request.Body);
                var body = await reader.ReadToEndAsync();
                var data = JsonSerializer.Deserialize<PaymentRequest>(body, new JsonSerializerOptions
                {
                    PropertyNameCaseInsensitive = true
                });

                if (data == null || string.IsNullOrEmpty(data.PaymentToken))
                {
                    return Results.Json(new
                    {
                        success = false,
                        message = "Payment token is required",
                        error_code = "VALIDATION_ERROR"
                    }, statusCode: 400);
                }

                if (data.Amount <= 0)
                {
                    return Results.Json(new
                    {
                        success = false,
                        message = "Valid amount is required",
                        error_code = "VALIDATION_ERROR"
                    }, statusCode: 400);
                }

                var card = new CreditCardData
                {
                    Token = data.PaymentToken
                };

                var chargeBuilder = card.Charge(data.Amount)
                    .WithCurrency(data.Currency ?? "USD")
                    .WithAllowDuplicates(true);

                if (!string.IsNullOrEmpty(data.BillingZip))
                {
                    var address = new Address
                    {
                        PostalCode = SanitizePostalCode(data.BillingZip)
                    };
                    chargeBuilder.WithAddress(address);
                }

                var response = chargeBuilder.Execute();

                if (response.ResponseCode == "SUCCESS" || response.ResponseCode == "00")
                {
                    return Results.Ok(new
                    {
                        success = true,
                        message = "Payment processed successfully",
                        data = new
                        {
                            transactionId = response.TransactionId,
                            amount = data.Amount,
                            currency = data.Currency ?? "USD",
                            status = "captured",
                            responseCode = response.ResponseCode,
                            responseMessage = response.ResponseMessage ?? "CAPTURED",
                            timestamp = DateTime.UtcNow.ToString("o"),
                            authorizationCode = response.AuthorizationCode ?? "",
                            referenceNumber = response.ReferenceNumber ?? "",
                            paymentMethod = new
                            {
                                type = "card",
                                brand = data.CardDetails?.CardType ?? "Unknown",
                                last4 = data.CardDetails?.CardLast4 ?? "0000"
                            }
                        }
                    });
                }
                else
                {
                    return Results.Json(new
                    {
                        success = false,
                        message = $"Payment failed: {response.ResponseMessage}",
                        error_code = "PAYMENT_DECLINED"
                    }, statusCode: 422);
                }
            }
            catch (Exception)
            {
                return Results.Json(new
                {
                    success = false,
                    message = "Internal server error",
                    error_code = "SERVER_ERROR"
                }, statusCode: 500);
            }
        });
    }

    /// <summary>
    /// Configures the refund endpoint for refund processing.
    /// </summary>
    private static void ConfigureRefundEndpoint(WebApplication app)
    {
        app.MapPost("/refund", async (HttpContext context) =>
        {
            try
            {
                using var reader = new StreamReader(context.Request.Body);
                var body = await reader.ReadToEndAsync();
                var data = JsonSerializer.Deserialize<RefundRequest>(body, new JsonSerializerOptions
                {
                    PropertyNameCaseInsensitive = true
                });

                if (data == null || string.IsNullOrEmpty(data.TransactionId))
                {
                    return Results.Json(new
                    {
                        success = false,
                        message = "Transaction ID is required",
                        error_code = "VALIDATION_ERROR"
                    }, statusCode: 400);
                }

                if (data.Amount <= 0)
                {
                    return Results.Json(new
                    {
                        success = false,
                        message = "Valid refund amount is required",
                        error_code = "VALIDATION_ERROR"
                    }, statusCode: 400);
                }

                var transaction = Transaction.FromId(data.TransactionId);

                var response = transaction.Refund(data.Amount)
                    .WithCurrency(data.Currency ?? "USD")
                    .WithAllowDuplicates(true)
                    .Execute();

                if (response.ResponseCode == "SUCCESS" || response.ResponseCode == "00")
                {
                    return Results.Ok(new
                    {
                        success = true,
                        message = "Refund processed successfully",
                        data = new
                        {
                            refundId = response.TransactionId,
                            transactionId = data.TransactionId,
                            amount = data.Amount,
                            currency = data.Currency ?? "USD",
                            status = "captured",
                            responseCode = response.ResponseCode,
                            responseMessage = response.ResponseMessage ?? "CAPTURED",
                            timestamp = DateTime.UtcNow.ToString("o"),
                            authorizationCode = response.AuthorizationCode ?? "",
                            referenceNumber = response.ReferenceNumber ?? "",
                            reason = data.Reason ?? "Refund requested"
                        }
                    });
                }
                else
                {
                    return Results.Json(new
                    {
                        success = false,
                        message = $"Refund failed: {response.ResponseMessage}",
                        error_code = "REFUND_DECLINED"
                    }, statusCode: 422);
                }
            }
            catch (Exception)
            {
                return Results.Json(new
                {
                    success = false,
                    message = "Internal server error",
                    error_code = "SERVER_ERROR"
                }, statusCode: 500);
            }
        });
    }
}

/// <summary>
/// Payment request model
/// </summary>
public class PaymentRequest
{
    [JsonPropertyName("payment_token")]
    public string? PaymentToken { get; set; }

    [JsonPropertyName("amount")]
    public decimal Amount { get; set; }

    [JsonPropertyName("currency")]
    public string? Currency { get; set; }

    [JsonPropertyName("billing_zip")]
    public string? BillingZip { get; set; }

    [JsonPropertyName("cardDetails")]
    public CardDetails? CardDetails { get; set; }
}

/// <summary>
/// Card details model
/// </summary>
public class CardDetails
{
    [JsonPropertyName("cardType")]
    public string? CardType { get; set; }

    [JsonPropertyName("cardLast4")]
    public string? CardLast4 { get; set; }
}

/// <summary>
/// Refund request model
/// </summary>
public class RefundRequest
{
    [JsonPropertyName("transactionId")]
    public string? TransactionId { get; set; }

    [JsonPropertyName("amount")]
    public decimal Amount { get; set; }

    [JsonPropertyName("currency")]
    public string? Currency { get; set; }

    [JsonPropertyName("reason")]
    public string? Reason { get; set; }
}
