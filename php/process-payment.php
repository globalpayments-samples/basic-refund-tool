<?php

declare(strict_types=1);

/**
 * Legacy Payment Processing Script for Basic Refund Tool
 *
 * This script maintains backward compatibility while redirecting
 * processing to the new charge.php endpoint using GP API.
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
require_once 'TransactionStorage.php';

// Handle CORS
PaymentUtils::handleCORS();

// Initialize SDK
PaymentUtils::configureSdk();

try {
    // Check if this is a POST request from the legacy form
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate required fields
        if (!isset($_POST['payment_token'], $_POST['billing_zip'], $_POST['amount'])) {
            PaymentUtils::sendErrorResponse(400, 'Missing required fields', 'VALIDATION_ERROR');
        }

        $amount = floatval($_POST['amount']);
        if ($amount <= 0) {
            PaymentUtils::sendErrorResponse(400, 'Invalid amount', 'VALIDATION_ERROR');
        }

        $paymentData = [
            'payment_token' => $_POST['payment_token'],
            'amount' => $amount,
            'currency' => 'USD',
            'billing_zip' => $_POST['billing_zip'],
            'cardDetails' => [
                'cardType' => 'unknown',
                'cardLast4' => '0000'
            ]
        ];

        // Process with GP API
        try {
            $billingAddress = [
                'postalCode' => $paymentData['billing_zip']
            ];

            $paymentResult = PaymentUtils::processPaymentWithGpApi(
                $paymentData['payment_token'],
                $paymentData['amount'],
                $paymentData['currency'],
                $billingAddress
            );

            // Store transaction
            $transactionData = [
                'transactionId' => $paymentResult['transaction_id'],
                'amount' => $paymentData['amount'],
                'currency' => $paymentData['currency'],
                'status' => $paymentResult['status'],
                'paymentMethod' => [
                    'type' => 'card',
                    'brand' => 'Unknown',
                    'last4' => '0000',
                    'token' => $paymentData['payment_token']
                ],
                'timestamp' => $paymentResult['timestamp'],
                'responseCode' => $paymentResult['response_code'],
                'responseMessage' => $paymentResult['response_message'],
                'authorizationCode' => $paymentResult['authorization_code'],
                'referenceNumber' => $paymentResult['reference_number']
            ];

            // Store in transaction storage
            TransactionStorage::addTransaction($transactionData);

            // Return legacy format response
            echo json_encode([
                'success' => true,
                'message' => 'Payment successful! Transaction ID: ' . $paymentResult['transaction_id'],
                'data' => [
                    'transactionId' => $paymentResult['transaction_id'],
                    'amount' => $paymentData['amount'],
                    'currency' => $paymentData['currency'],
                    'status' => $paymentResult['status'],
                    'responseCode' => $paymentResult['response_code'],
                    'responseMessage' => $paymentResult['response_message']
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Legacy payment processing error: ' . $e->getMessage());

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => [
                    'code' => 'PAYMENT_ERROR',
                    'details' => $e->getMessage()
                ]
            ]);
        }

    } else {
        // Redirect GET requests to charge.php for consistency
        PaymentUtils::sendErrorResponse(405, 'Method not allowed. Use POST to process payments.');
    }

} catch (\Exception $e) {
    error_log('Process payment error: ' . $e->getMessage());
    PaymentUtils::sendErrorResponse(500, 'Internal server error', 'SERVER_ERROR');
}
