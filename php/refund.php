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
require_once 'TransactionStorage.php';

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
    $reason = $data['reason'] ?? 'Refund requested';

    // Get original transaction
    $originalTransaction = TransactionStorage::getTransactionById($transactionId);
    if (!$originalTransaction) {
        PaymentUtils::sendErrorResponse(404, 'Original transaction not found', 'NOT_FOUND');
    }

    // Validate transaction is eligible for refund
    if (!in_array($originalTransaction['status'], ['captured', 'partially_refunded'])) {
        PaymentUtils::sendErrorResponse(400,
            'Transaction is not eligible for refund. Status: ' . $originalTransaction['status'],
            'INELIGIBLE_TRANSACTION'
        );
    }

    $originalAmount = $originalTransaction['amount'];
    $totalRefunded = $originalTransaction['totalRefunded'] ?? 0.0;
    $currency = $originalTransaction['currency'];

    // Validate refund amount using business rules
    $validationErrors = PaymentUtils::validateRefundAmount($refundAmount, $originalAmount, $totalRefunded);
    if (!empty($validationErrors)) {
        PaymentUtils::sendErrorResponse(400, implode('; ', $validationErrors), 'VALIDATION_ERROR');
    }

    // Check if this is a full refund request
    $isFullRefund = ($refundAmount == ($originalAmount - $totalRefunded));

    try {
        // Process refund with GP API
        $refundResult = PaymentUtils::processRefundWithGpApi(
            $originalTransaction['transactionId'],
            $refundAmount,
            $currency
        );

        // Prepare refund data for storage
        $refundData = [
            'refundId' => $refundResult['refund_id'],
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

        // Add refund to transaction storage
        $refundStored = TransactionStorage::addRefund($transactionId, $refundData);
        if (!$refundStored) {
            error_log('Failed to store refund data for transaction: ' . $transactionId);
            // Continue since the refund was processed successfully
        }

        // Get updated transaction data
        $updatedTransaction = TransactionStorage::getTransactionById($transactionId);

        // Prepare successful response
        $response = [
            'refund' => [
                'refundId' => $refundResult['refund_id'],
                'amount' => $refundAmount,
                'currency' => $currency,
                'status' => $refundResult['status'],
                'timestamp' => $refundResult['timestamp'],
                'responseCode' => $refundResult['response_code'],
                'responseMessage' => $refundResult['response_message'],
                'authorizationCode' => $refundResult['authorization_code'],
                'referenceNumber' => $refundResult['reference_number'],
                'reason' => $reason,
                'refundType' => $isFullRefund ? 'full' : 'partial'
            ],
            'transaction' => [
                'id' => $updatedTransaction['id'] ?? $originalTransaction['id'],
                'transactionId' => $originalTransaction['transactionId'],
                'originalAmount' => $originalAmount,
                'totalRefunded' => $updatedTransaction['totalRefunded'] ?? ($totalRefunded + $refundAmount),
                'remainingBalance' => $updatedTransaction['remainingBalance'] ?? ($originalAmount - $totalRefunded - $refundAmount),
                'status' => $updatedTransaction['status'] ?? $originalTransaction['status'],
                'refundCount' => count($updatedTransaction['refunds'] ?? [])
            ],
            'stored' => $refundStored
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