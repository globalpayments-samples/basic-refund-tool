<?php

declare(strict_types=1);

/**
 * Charge Endpoint for Basic Refund Tool
 *
 * Processes immediate payments using GP API and stores transaction data
 * for later refund processing.
 *
 * PHP version 8.0 or higher
 *
 * @category  Payment_Processing
 * @package   GlobalPayments_BasicRefundTool
 * @author    Global Payments
 * @license   MIT License
 * @link      https://github.com/globalpayments
 */

require_once 'PaymentUtils.php';

// Handle CORS
PaymentUtils::handleCORS();

// Initialize SDK
PaymentUtils::configureSdk();

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    PaymentUtils::sendErrorResponse(405, 'Method not allowed');
}

try {
    $data = PaymentUtils::parseJsonInput();

    // Generate request ID for correlation
    $requestId = 'charge_' . uniqid();

    // Log incoming request data
    error_log("[$requestId] CHARGE REQUEST - Raw input: " . json_encode($data));

    // Validate required fields
    if (empty($data['payment_token'])) {
        error_log("[$requestId] VALIDATION ERROR - Missing payment token");
        PaymentUtils::sendErrorResponse(400, 'Payment token is required', 'VALIDATION_ERROR');
    }

    if (empty($data['amount']) || $data['amount'] <= 0) {
        error_log("[$requestId] VALIDATION ERROR - Invalid amount: " . ($data['amount'] ?? 'null'));
        PaymentUtils::sendErrorResponse(400, 'Valid amount is required', 'VALIDATION_ERROR');
    }

    $paymentToken = $data['payment_token'];
    $amount = (float)$data['amount'];
    $currency = $data['currency'] ?? 'USD';

    // Log parsed payment data (mask sensitive token)
    $maskedToken = substr($paymentToken, 0, 8) . '...' . substr($paymentToken, -4);
    error_log("[$requestId] PARSED DATA - Token: $maskedToken, Amount: $amount, Currency: $currency");

    // Extract billing address if provided
    $billingAddress = [];
    if (!empty($data['billing_zip'])) {
        $billingAddress['postalCode'] = $data['billing_zip'];
    }

    // Log billing address
    error_log("[$requestId] BILLING ADDRESS: " . json_encode($billingAddress));

    // Extract card details from the request (provided by Global Payments PaymentForm)
    $cardDetails = $data['cardDetails'] ?? [];

    // Log card details (without sensitive data)
    error_log("[$requestId] CARD DETAILS: " . json_encode($cardDetails));

    // Determine card brand from token or card details
    $cardBrand = 'Unknown';
    $last4 = '0000';

    if (!empty($cardDetails['cardType'])) {
        $cardBrand = ucfirst(strtolower($cardDetails['cardType']));
    }

    if (!empty($cardDetails['cardLast4'])) {
        $last4 = $cardDetails['cardLast4'];
    }

    error_log("[$requestId] CARD INFO - Brand: $cardBrand, Last4: $last4");

    // Process payment with GP API
    try {
        error_log("[$requestId] CALLING GP API - Starting payment processing");
        $paymentResult = PaymentUtils::processPaymentWithGpApi($paymentToken, $amount, $currency, $billingAddress, $requestId);

        error_log("[$requestId] GP API COMPLETED - Result: " . json_encode($paymentResult));

        // Prepare successful response
        $response = [
            'transactionId' => $paymentResult['transaction_id'],
            'amount' => $amount,
            'currency' => $currency,
            'status' => $paymentResult['status'],
            'responseCode' => $paymentResult['response_code'],
            'responseMessage' => $paymentResult['response_message'],
            'timestamp' => $paymentResult['timestamp'],
            'authorizationCode' => $paymentResult['authorization_code'],
            'referenceNumber' => $paymentResult['reference_number'],
            'paymentMethod' => [
                'type' => 'card',
                'brand' => $cardBrand,
                'last4' => $last4
            ]
        ];

        error_log("[$requestId] RESPONSE - Sending success response: " . json_encode($response));
        PaymentUtils::sendSuccessResponse($response, 'Payment processed successfully');

    } catch (\Exception $e) {
        error_log("[$requestId] PAYMENT ERROR: " . $e->getMessage());
        error_log("[$requestId] PAYMENT ERROR TRACE: " . $e->getTraceAsString());

        // Determine if this is a specific payment error or generic failure
        $errorMessage = 'Payment processing failed';
        $errorCode = 'PAYMENT_ERROR';

        if (strpos($e->getMessage(), 'Payment failed:') === 0) {
            $errorMessage = $e->getMessage();
            $errorCode = 'PAYMENT_DECLINED';
        }

        PaymentUtils::sendErrorResponse(422, $errorMessage, $errorCode);
    }

} catch (\Exception $e) {
    error_log('General charge processing error: ' . $e->getMessage());
    PaymentUtils::sendErrorResponse(500, 'Internal server error', 'SERVER_ERROR');
}