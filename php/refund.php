<?php

declare(strict_types=1);

/**
 * Refund Endpoint for Basic Refund Tool
 *
 * Processes refunds using GP API with proper business logic validation
 * POST /refund - Process a refund for a given transaction
 *
 * PHP version 8.0 or higher
 *
 * @category  Refund_Processing
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

    // Validate required fields
    if (empty($data['transactionId'])) {
        PaymentUtils::sendErrorResponse(400, 'Transaction ID is required', 'VALIDATION_ERROR');
    }

    if (empty($data['amount']) || $data['amount'] <= 0) {
        PaymentUtils::sendErrorResponse(400, 'Valid refund amount is required', 'VALIDATION_ERROR');
    }

    $transactionId = $data['transactionId'];
    $refundAmount = (float)$data['amount'];
    $currency = $data['currency'] ?? 'USD';
    $reason = $data['reason'] ?? 'Refund requested';

    try {
        // Process refund with GP API
        $refundResult = PaymentUtils::processRefundWithGpApi(
            $transactionId,
            $refundAmount,
            $currency
        );

        // Prepare successful response
        $response = [
            'refundId' => $refundResult['refund_id'],
            'transactionId' => $transactionId,
            'amount' => $refundAmount,
            'currency' => $currency,
            'status' => $refundResult['status'],
            'timestamp' => $refundResult['timestamp'],
            'responseCode' => $refundResult['response_code'],
            'responseMessage' => $refundResult['response_message'],
            'authorizationCode' => $refundResult['authorization_code'],
            'referenceNumber' => $refundResult['reference_number'],
            'reason' => $reason
        ];

        PaymentUtils::sendSuccessResponse($response, 'Refund processed successfully');

    } catch (\Exception $e) {
        error_log('Refund processing error: ' . $e->getMessage());

        // Determine if this is a specific refund error or generic failure
        $errorMessage = 'Refund processing failed';
        $errorCode = 'REFUND_ERROR';

        if (strpos($e->getMessage(), 'Refund failed:') === 0) {
            $errorMessage = $e->getMessage();
            $errorCode = 'REFUND_DECLINED';
        }

        PaymentUtils::sendErrorResponse(422, $errorMessage, $errorCode);
    }

} catch (\Exception $e) {
    error_log('General refund processing error: ' . $e->getMessage());
    PaymentUtils::sendErrorResponse(500, 'Internal server error', 'SERVER_ERROR');
}