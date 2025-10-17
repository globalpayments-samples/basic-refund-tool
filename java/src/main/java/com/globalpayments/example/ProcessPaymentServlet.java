package com.globalpayments.example;

import com.global.api.ServicesContainer;
import com.global.api.entities.Address;
import com.global.api.entities.Transaction;
import com.global.api.entities.enums.Channel;
import com.global.api.entities.enums.Environment;
import com.global.api.entities.exceptions.ApiException;
import com.global.api.entities.exceptions.ConfigurationException;
import com.global.api.paymentMethods.CreditCardData;
import com.global.api.serviceConfigs.GpApiConfig;
import com.global.api.services.GpApiService;
import io.github.cdimascio.dotenv.Dotenv;
import jakarta.servlet.ServletException;
import jakarta.servlet.annotation.WebServlet;
import jakarta.servlet.http.HttpServlet;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;

import java.io.BufferedReader;
import java.io.IOException;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.HashMap;
import java.util.Map;

/**
 * Basic Refund Tool Servlet - GP API Implementation
 *
 * This servlet provides card payment and refund processing using the Global Payments GP API.
 * It provides endpoints for configuration, payment processing, and refund processing.
 *
 * Endpoints:
 * - GET /config: Generates GP API session token for client-side tokenization
 * - POST /charge: Processes card payments using tokenized card data
 * - POST /refund: Processes refunds using transaction IDs
 *
 * @author Global Payments
 * @version 2.0
 */
@WebServlet(urlPatterns = {"/config", "/charge", "/refund"})
public class ProcessPaymentServlet extends HttpServlet {

    private static final long serialVersionUID = 1L;
    private Dotenv dotenv;

    /**
     * Initializes the servlet and loads environment variables.
     *
     * @throws ServletException if there's an error initializing the servlet
     */
    @Override
    public void init() throws ServletException {
        try {
            dotenv = Dotenv.load();
            log("GP API servlet initialized successfully");
        } catch (Exception e) {
            throw new ServletException("Failed to load environment variables", e);
        }
    }

    /**
     * Handles OPTIONS requests for CORS preflight.
     */
    @Override
    protected void doOptions(HttpServletRequest request, HttpServletResponse response)
            throws ServletException, IOException {
        setCorsHeaders(response);
        response.setStatus(HttpServletResponse.SC_OK);
    }

    /**
     * Handles GET requests to /config endpoint.
     * Generates and returns a GP API session token for client-side tokenization.
     *
     * @param request The HTTP request
     * @param response The HTTP response
     * @throws ServletException If there's an error in servlet processing
     * @throws IOException If there's an I/O error
     */
    @Override
    protected void doGet(HttpServletRequest request, HttpServletResponse response)
            throws ServletException, IOException {

        setCorsHeaders(response);
        response.setContentType("application/json");

        if (!request.getServletPath().equals("/config")) {
            sendErrorResponse(response, 404, "Endpoint not found", "NOT_FOUND");
            return;
        }

        try {
            // Get environment variables
            String appId = dotenv.get("GP_API_APP_ID");
            String appKey = dotenv.get("GP_API_APP_KEY");
            String envString = dotenv.get("GP_API_ENVIRONMENT", "sandbox");

            if (appId == null || appKey == null) {
                throw new ServletException("Missing GP_API_APP_ID or GP_API_APP_KEY");
            }

            // Determine environment
            boolean isProduction = "production".equalsIgnoreCase(envString);
            Environment environment = isProduction ? Environment.PRODUCTION : Environment.TEST;

            // Configure GP API for session token generation
            GpApiConfig config = new GpApiConfig();
            config.setAppId(appId);
            config.setAppKey(appKey);
            config.setEnvironment(environment);
            config.setChannel(Channel.CardNotPresent);
            config.setCountry("US");

            // Set permissions specifically for client-side tokenization
            config.setPermissions(new String[]{"PMT_POST_Create_Single"});

            // Configure service
            ServicesContainer.configureService(config);

            // Generate session token for client-side tokenization
            com.global.api.entities.gpApi.entities.AccessTokenInfo tokenInfo = GpApiService.generateTransactionKey(config);

            if (tokenInfo == null || tokenInfo.getAccessToken() == null || tokenInfo.getAccessToken().isEmpty()) {
                throw new ServletException("Failed to generate session token");
            }

            String accessToken = tokenInfo.getAccessToken();
            log("Session token generated successfully: " + accessToken.substring(0, Math.min(8, accessToken.length())) + "...");

            // Build response
            Map<String, Object> data = new HashMap<>();
            data.put("accessToken", accessToken);
            data.put("environment", envString);
            data.put("supportedCurrencies", new String[]{"USD", "EUR", "GBP", "CAD"});
            data.put("supportedPaymentMethods", new String[]{"CARD"});
            data.put("defaultCurrency", "USD");
            data.put("maxAmount", 999999);
            data.put("minAmount", 1);

            Map<String, Object> apiInfo = new HashMap<>();
            apiInfo.put("version", "2021-03-22");
            apiInfo.put("baseUrl", isProduction
                ? "https://apis.globalpay.com"
                : "https://apis.sandbox.globalpay.com");
            data.put("api", apiInfo);

            Map<String, Object> refundInfo = new HashMap<>();
            refundInfo.put("maxPercentage", 115);
            refundInfo.put("timeWindowDays", 180);
            data.put("refund", refundInfo);

            sendSuccessResponse(response, data, "Configuration loaded successfully");

        } catch (ConfigurationException e) {
            log("Configuration error: " + e.getMessage());
            sendErrorResponse(response, 500, "Error loading configuration: " + e.getMessage(), "CONFIG_ERROR");
        } catch (Exception e) {
            log("Unexpected error: " + e.getMessage());
            sendErrorResponse(response, 500, "Internal server error", "SERVER_ERROR");
        }
    }

    /**
     * Handles POST requests to /charge and /refund endpoints.
     * Routes to appropriate handler based on servlet path.
     *
     * @param request The HTTP request containing payment details
     * @param response The HTTP response
     * @throws ServletException If there's an error in servlet processing
     * @throws IOException If there's an I/O error
     */
    @Override
    protected void doPost(HttpServletRequest request, HttpServletResponse response)
            throws ServletException, IOException {

        setCorsHeaders(response);
        response.setContentType("application/json");

        String path = request.getServletPath();

        if ("/charge".equals(path)) {
            handleChargeRequest(request, response);
        } else if ("/refund".equals(path)) {
            handleRefundRequest(request, response);
        } else {
            sendErrorResponse(response, 404, "Endpoint not found", "NOT_FOUND");
        }
    }

    /**
     * Handles charge/payment requests.
     */
    private void handleChargeRequest(HttpServletRequest request, HttpServletResponse response)
            throws IOException {

        String requestId = "charge_" + System.currentTimeMillis();

        try {
            // Parse JSON input
            Map<String, Object> data = parseJsonInput(request);

            log("[" + requestId + "] CHARGE REQUEST - Raw input: " + data);

            // Validate required fields
            if (!data.containsKey("payment_token") || data.get("payment_token") == null) {
                log("[" + requestId + "] VALIDATION ERROR - Missing payment token");
                sendErrorResponse(response, 400, "Payment token is required", "VALIDATION_ERROR");
                return;
            }

            if (!data.containsKey("amount") || data.get("amount") == null) {
                log("[" + requestId + "] VALIDATION ERROR - Missing amount");
                sendErrorResponse(response, 400, "Valid amount is required", "VALIDATION_ERROR");
                return;
            }

            String paymentToken = data.get("payment_token").toString();
            BigDecimal amount = new BigDecimal(data.get("amount").toString());
            String currency = data.getOrDefault("currency", "USD").toString();

            if (amount.compareTo(BigDecimal.ZERO) <= 0) {
                log("[" + requestId + "] VALIDATION ERROR - Invalid amount: " + amount);
                sendErrorResponse(response, 400, "Valid amount is required", "VALIDATION_ERROR");
                return;
            }

            // Log parsed data (mask token)
            String maskedToken = paymentToken.substring(0, Math.min(8, paymentToken.length()))
                + "..." + (paymentToken.length() > 4 ? paymentToken.substring(paymentToken.length() - 4) : "");
            log("[" + requestId + "] PARSED DATA - Token: " + maskedToken + ", Amount: " + amount + ", Currency: " + currency);

            // Configure GP API
            configureGpApiService();

            // Extract card details if provided
            @SuppressWarnings("unchecked")
            Map<String, Object> cardDetails = (Map<String, Object>) data.get("cardDetails");
            String cardBrand = "Unknown";
            String last4 = "0000";

            if (cardDetails != null) {
                if (cardDetails.containsKey("cardType")) {
                    cardBrand = capitalizeFirst(cardDetails.get("cardType").toString().toLowerCase());
                }
                if (cardDetails.containsKey("cardLast4")) {
                    last4 = cardDetails.get("cardLast4").toString();
                }
            }

            log("[" + requestId + "] CARD INFO - Brand: " + cardBrand + ", Last4: " + last4);

            // Create card data with token
            CreditCardData card = new CreditCardData();
            card.setToken(paymentToken);

            // Build charge transaction
            com.global.api.builders.AuthorizationBuilder chargeBuilder = card.charge(amount)
                .withCurrency(currency)
                .withAllowDuplicates(true);

            // Add billing address if provided
            if (data.containsKey("billing_zip") && data.get("billing_zip") != null) {
                Address address = new Address();
                address.setPostalCode(sanitizePostalCode(data.get("billing_zip").toString()));
                chargeBuilder.withAddress(address);
                log("[" + requestId + "] BILLING ADDRESS - Postal: " + address.getPostalCode());
            }

            log("[" + requestId + "] EXECUTING CHARGE - Starting GP API call");
            Transaction transaction = chargeBuilder.execute();
            log("[" + requestId + "] CHARGE EXECUTED - GP API call completed");

            // Log response details
            log("[" + requestId + "] GP API RESPONSE - Response Code: " + transaction.getResponseCode());
            log("[" + requestId + "] GP API RESPONSE - Response Message: " + transaction.getResponseMessage());
            log("[" + requestId + "] GP API RESPONSE - Transaction ID: " + transaction.getTransactionId());

            // Check if transaction was successful
            if ("SUCCESS".equals(transaction.getResponseCode()) || "00".equals(transaction.getResponseCode())) {
                log("[" + requestId + "] GP API SUCCESS - Payment approved");

                Map<String, Object> responseData = new HashMap<>();
                responseData.put("transactionId", transaction.getTransactionId());
                responseData.put("amount", amount);
                responseData.put("currency", currency);
                responseData.put("status", "captured");
                responseData.put("responseCode", transaction.getResponseCode());
                responseData.put("responseMessage", transaction.getResponseMessage() != null ? transaction.getResponseMessage() : "Approved");
                responseData.put("timestamp", Instant.now().toString());
                responseData.put("authorizationCode", transaction.getAuthorizationCode() != null ? transaction.getAuthorizationCode() : "");
                responseData.put("referenceNumber", transaction.getReferenceNumber() != null ? transaction.getReferenceNumber() : "");

                Map<String, Object> paymentMethod = new HashMap<>();
                paymentMethod.put("type", "card");
                paymentMethod.put("brand", cardBrand);
                paymentMethod.put("last4", last4);
                responseData.put("paymentMethod", paymentMethod);

                sendSuccessResponse(response, responseData, "Payment processed successfully");
            } else {
                log("[" + requestId + "] GP API FAILED - Code: " + transaction.getResponseCode() + ", Message: " + transaction.getResponseMessage());
                sendErrorResponse(response, 422, "Payment failed: " + transaction.getResponseMessage(), "PAYMENT_DECLINED");
            }

        } catch (ApiException e) {
            log("[" + requestId + "] PAYMENT ERROR: " + e.getMessage());
            sendErrorResponse(response, 422, "Payment processing failed: " + e.getMessage(), "PAYMENT_ERROR");
        } catch (NumberFormatException e) {
            log("[" + requestId + "] VALIDATION ERROR: Invalid amount format");
            sendErrorResponse(response, 400, "Invalid amount format", "VALIDATION_ERROR");
        } catch (Exception e) {
            log("[" + requestId + "] UNEXPECTED ERROR: " + e.getMessage());
            e.printStackTrace();
            sendErrorResponse(response, 500, "Internal server error", "SERVER_ERROR");
        }
    }

    /**
     * Handles refund requests.
     */
    private void handleRefundRequest(HttpServletRequest request, HttpServletResponse response)
            throws IOException {

        String requestId = "refund_" + System.currentTimeMillis();

        try {
            // Parse JSON input
            Map<String, Object> data = parseJsonInput(request);

            log("[" + requestId + "] REFUND REQUEST - Raw input: " + data);

            // Validate required fields
            if (!data.containsKey("transactionId") || data.get("transactionId") == null) {
                log("[" + requestId + "] VALIDATION ERROR - Missing transaction ID");
                sendErrorResponse(response, 400, "Transaction ID is required", "VALIDATION_ERROR");
                return;
            }

            if (!data.containsKey("amount") || data.get("amount") == null) {
                log("[" + requestId + "] VALIDATION ERROR - Missing amount");
                sendErrorResponse(response, 400, "Valid refund amount is required", "VALIDATION_ERROR");
                return;
            }

            String transactionId = data.get("transactionId").toString();
            BigDecimal refundAmount = new BigDecimal(data.get("amount").toString());
            String currency = data.getOrDefault("currency", "USD").toString();
            String reason = data.getOrDefault("reason", "Refund requested").toString();

            if (refundAmount.compareTo(BigDecimal.ZERO) <= 0) {
                log("[" + requestId + "] VALIDATION ERROR - Invalid refund amount: " + refundAmount);
                sendErrorResponse(response, 400, "Valid refund amount is required", "VALIDATION_ERROR");
                return;
            }

            log("[" + requestId + "] PARSED DATA - Transaction ID: " + transactionId + ", Amount: " + refundAmount + ", Currency: " + currency);

            // Configure GP API
            configureGpApiService();

            // Create transaction reference
            Transaction transaction = Transaction.fromId(transactionId);

            log("[" + requestId + "] EXECUTING REFUND - Starting GP API call");
            Transaction refundResponse = transaction.refund(refundAmount)
                .withCurrency(currency)
                .withAllowDuplicates(true)
                .execute();
            log("[" + requestId + "] REFUND EXECUTED - GP API call completed");

            // Log response details
            log("[" + requestId + "] GP API RESPONSE - Response Code: " + refundResponse.getResponseCode());
            log("[" + requestId + "] GP API RESPONSE - Response Message: " + refundResponse.getResponseMessage());
            log("[" + requestId + "] GP API RESPONSE - Refund ID: " + refundResponse.getTransactionId());

            // Check if refund was successful
            if ("SUCCESS".equals(refundResponse.getResponseCode()) || "00".equals(refundResponse.getResponseCode())) {
                log("[" + requestId + "] GP API SUCCESS - Refund approved");

                Map<String, Object> responseData = new HashMap<>();
                responseData.put("refundId", refundResponse.getTransactionId());
                responseData.put("transactionId", transactionId);
                responseData.put("amount", refundAmount);
                responseData.put("currency", currency);
                responseData.put("status", "captured");
                responseData.put("timestamp", Instant.now().toString());
                responseData.put("responseCode", refundResponse.getResponseCode());
                responseData.put("responseMessage", refundResponse.getResponseMessage() != null ? refundResponse.getResponseMessage() : "Refunded");
                responseData.put("authorizationCode", refundResponse.getAuthorizationCode() != null ? refundResponse.getAuthorizationCode() : "");
                responseData.put("referenceNumber", refundResponse.getReferenceNumber() != null ? refundResponse.getReferenceNumber() : "");
                responseData.put("reason", reason);

                sendSuccessResponse(response, responseData, "Refund processed successfully");
            } else {
                log("[" + requestId + "] GP API FAILED - Code: " + refundResponse.getResponseCode() + ", Message: " + refundResponse.getResponseMessage());
                sendErrorResponse(response, 422, "Refund failed: " + refundResponse.getResponseMessage(), "REFUND_DECLINED");
            }

        } catch (ApiException e) {
            log("[" + requestId + "] REFUND ERROR: " + e.getMessage());
            sendErrorResponse(response, 422, "Refund processing failed: " + e.getMessage(), "REFUND_ERROR");
        } catch (NumberFormatException e) {
            log("[" + requestId + "] VALIDATION ERROR: Invalid amount format");
            sendErrorResponse(response, 400, "Invalid amount format", "VALIDATION_ERROR");
        } catch (Exception e) {
            log("[" + requestId + "] UNEXPECTED ERROR: " + e.getMessage());
            e.printStackTrace();
            sendErrorResponse(response, 500, "Internal server error", "SERVER_ERROR");
        }
    }

    /**
     * Configures the GP API service with credentials from environment variables.
     */
    private void configureGpApiService() throws ConfigurationException {
        String appId = dotenv.get("GP_API_APP_ID");
        String appKey = dotenv.get("GP_API_APP_KEY");
        String envString = dotenv.get("GP_API_ENVIRONMENT", "sandbox");

        if (appId == null || appKey == null) {
            throw new ConfigurationException("Missing GP_API_APP_ID or GP_API_APP_KEY");
        }

        boolean isProduction = "production".equalsIgnoreCase(envString);
        Environment environment = isProduction ? Environment.PRODUCTION : Environment.TEST;

        GpApiConfig config = new GpApiConfig();
        config.setAppId(appId);
        config.setAppKey(appKey);
        config.setEnvironment(environment);
        config.setChannel(Channel.CardNotPresent);
        config.setCountry("US");

        ServicesContainer.configureService(config);
    }

    /**
     * Parses JSON input from request body.
     */
    private Map<String, Object> parseJsonInput(HttpServletRequest request) throws IOException {
        StringBuilder sb = new StringBuilder();
        try (BufferedReader reader = request.getReader()) {
            String line;
            while ((line = reader.readLine()) != null) {
                sb.append(line);
            }
        }

        String jsonString = sb.toString();
        if (jsonString.isEmpty()) {
            return new HashMap<>();
        }

        // Simple JSON parsing (for production, use a proper JSON library like Jackson or Gson)
        return parseJsonString(jsonString);
    }

    /**
     * Simple JSON parser (for production, use a proper JSON library).
     */
    @SuppressWarnings("unchecked")
    private Map<String, Object> parseJsonString(String json) {
        Map<String, Object> result = new HashMap<>();
        json = json.trim();

        if (!json.startsWith("{") || !json.endsWith("}")) {
            return result;
        }

        json = json.substring(1, json.length() - 1);

        boolean inString = false;
        boolean inNestedObject = false;
        int nestedLevel = 0;
        StringBuilder key = new StringBuilder();
        StringBuilder value = new StringBuilder();
        boolean readingKey = true;

        for (int i = 0; i < json.length(); i++) {
            char c = json.charAt(i);

            if (c == '"' && (i == 0 || json.charAt(i - 1) != '\\')) {
                inString = !inString;
                continue;
            }

            if (!inString) {
                if (c == '{') {
                    nestedLevel++;
                    inNestedObject = true;
                } else if (c == '}') {
                    nestedLevel--;
                    if (nestedLevel == 0) {
                        inNestedObject = false;
                    }
                }

                if (c == ':' && !inNestedObject) {
                    readingKey = false;
                    continue;
                }

                if (c == ',' && !inNestedObject) {
                    String k = key.toString().trim();
                    String v = value.toString().trim();
                    if (!k.isEmpty()) {
                        result.put(k, parseValue(v));
                    }
                    key = new StringBuilder();
                    value = new StringBuilder();
                    readingKey = true;
                    continue;
                }
            }

            if (readingKey) {
                if (c != '"' && c != ' ') {
                    key.append(c);
                }
            } else {
                value.append(c);
            }
        }

        // Handle last key-value pair
        String k = key.toString().trim();
        String v = value.toString().trim();
        if (!k.isEmpty()) {
            result.put(k, parseValue(v));
        }

        return result;
    }

    /**
     * Parses a JSON value (handles strings, numbers, booleans, and nested objects).
     */
    private Object parseValue(String value) {
        value = value.trim();

        if (value.startsWith("\"") && value.endsWith("\"")) {
            return value.substring(1, value.length() - 1);
        }

        if (value.startsWith("{") && value.endsWith("}")) {
            return parseJsonString(value);
        }

        if ("true".equals(value)) {
            return true;
        }
        if ("false".equals(value)) {
            return false;
        }
        if ("null".equals(value)) {
            return null;
        }

        return value;
    }

    /**
     * Sanitizes postal code input by removing invalid characters.
     */
    private String sanitizePostalCode(String postalCode) {
        if (postalCode == null) {
            return "";
        }
        String sanitized = postalCode.replaceAll("[^a-zA-Z0-9-]", "");
        return sanitized.length() > 10 ? sanitized.substring(0, 10) : sanitized;
    }

    /**
     * Capitalizes the first letter of a string.
     */
    private String capitalizeFirst(String str) {
        if (str == null || str.isEmpty()) {
            return str;
        }
        return str.substring(0, 1).toUpperCase() + str.substring(1);
    }

    /**
     * Sets CORS headers on the response.
     */
    private void setCorsHeaders(HttpServletResponse response) {
        response.setHeader("Access-Control-Allow-Origin", "*");
        response.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
        response.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");
    }

    /**
     * Sends a success JSON response.
     */
    private void sendSuccessResponse(HttpServletResponse response, Map<String, Object> data, String message)
            throws IOException {
        response.setStatus(HttpServletResponse.SC_OK);

        Map<String, Object> responseBody = new HashMap<>();
        responseBody.put("success", true);
        responseBody.put("data", data);
        responseBody.put("message", message);
        responseBody.put("timestamp", Instant.now().toString());

        response.getWriter().write(toJson(responseBody));
    }

    /**
     * Sends an error JSON response.
     */
    private void sendErrorResponse(HttpServletResponse response, int statusCode, String message, String errorCode)
            throws IOException {
        response.setStatus(statusCode);

        Map<String, Object> responseBody = new HashMap<>();
        responseBody.put("success", false);
        responseBody.put("message", message);
        responseBody.put("timestamp", Instant.now().toString());
        if (errorCode != null) {
            responseBody.put("error_code", errorCode);
        }

        response.getWriter().write(toJson(responseBody));
    }

    /**
     * Simple JSON serializer (for production, use a proper JSON library).
     */
    private String toJson(Map<String, Object> map) {
        StringBuilder json = new StringBuilder("{");
        boolean first = true;

        for (Map.Entry<String, Object> entry : map.entrySet()) {
            if (!first) {
                json.append(",");
            }
            first = false;

            json.append("\"").append(entry.getKey()).append("\":");
            json.append(toJsonValue(entry.getValue()));
        }

        json.append("}");
        return json.toString();
    }

    /**
     * Converts a Java object to JSON value string.
     */
    @SuppressWarnings("unchecked")
    private String toJsonValue(Object value) {
        if (value == null) {
            return "null";
        }

        if (value instanceof String) {
            return "\"" + escapeJson((String) value) + "\"";
        }

        if (value instanceof Number || value instanceof Boolean) {
            return value.toString();
        }

        if (value instanceof Map) {
            return toJson((Map<String, Object>) value);
        }

        if (value instanceof Object[]) {
            StringBuilder array = new StringBuilder("[");
            Object[] arr = (Object[]) value;
            for (int i = 0; i < arr.length; i++) {
                if (i > 0) {
                    array.append(",");
                }
                array.append(toJsonValue(arr[i]));
            }
            array.append("]");
            return array.toString();
        }

        return "\"" + escapeJson(value.toString()) + "\"";
    }

    /**
     * Escapes special characters in JSON strings.
     */
    private String escapeJson(String str) {
        return str.replace("\\", "\\\\")
                  .replace("\"", "\\\"")
                  .replace("\n", "\\n")
                  .replace("\r", "\\r")
                  .replace("\t", "\\t");
    }
}
