<?php

declare(strict_types=1);

/**
 * Transactions Endpoint for Basic Refund Tool
 *
 * Handles CRUD operations for transaction data
 * GET /transactions - Retrieve all transactions with optional filtering
 * GET /transactions/{id} - Retrieve specific transaction
 * POST /transactions/summary - Get transaction summary statistics
 *
 * PHP version 8.0 or higher
 *
 * @category  Transaction_Management
 * @package   GlobalPayments_BasicRefundTool
 * @author    Global Payments
 * @license   MIT License
 * @link      https://github.com/globalpayments
 */

require_once 'PaymentUtils.php';
require_once 'TransactionStorage.php';

// Handle CORS
PaymentUtils::handleCORS();

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$parsedUri = parse_url($requestUri);
$path = $parsedUri['path'] ?? '';
$pathParts = array_filter(explode('/', $path));

try {
    if ($method === 'GET') {
        // Check if requesting specific transaction by ID
        if (count($pathParts) >= 2 && $pathParts[count($pathParts) - 1] !== 'transactions') {
            // GET /transactions/{id}
            $transactionId = end($pathParts);
            $transaction = TransactionStorage::getTransactionById($transactionId);

            if (!$transaction) {
                PaymentUtils::sendErrorResponse(404, 'Transaction not found', 'NOT_FOUND');
            }

            PaymentUtils::sendSuccessResponse($transaction, 'Transaction retrieved successfully');

        } else {
            // GET /transactions - Retrieve all transactions with optional filtering
            $filters = [];
            $queryParams = $parsedUri['query'] ?? '';
            if ($queryParams) {
                parse_str($queryParams, $params);

                // Support filtering by status, currency, date range
                if (!empty($params['status'])) {
                    $filters['status'] = $params['status'];
                }
                if (!empty($params['currency'])) {
                    $filters['currency'] = $params['currency'];
                }

                // Date range filtering (if needed in the future)
                // if (!empty($params['from_date'])) {
                //     $filters['from_date'] = $params['from_date'];
                // }
                // if (!empty($params['to_date'])) {
                //     $filters['to_date'] = $params['to_date'];
                // }
            }

            $transactions = TransactionStorage::getAllTransactions($filters);
            $formattedTransactions = array_map(function($transaction) {
                return [
                    'id' => $transaction['id'],
                    'transactionId' => $transaction['transactionId'],
                    'amount' => $transaction['amount'],
                    'currency' => $transaction['currency'],
                    'status' => $transaction['status'],
                    'paymentMethod' => $transaction['paymentMethod'],
                    'timestamp' => $transaction['timestamp'],
                    'refunds' => $transaction['refunds'] ?? [],
                    'totalRefunded' => $transaction['totalRefunded'] ?? 0.0,
                    'remainingBalance' => $transaction['remainingBalance'] ?? $transaction['amount'],
                    'responseCode' => $transaction['responseCode'] ?? '',
                    'responseMessage' => $transaction['responseMessage'] ?? '',
                    'authorizationCode' => $transaction['authorizationCode'] ?? '',
                    'referenceNumber' => $transaction['referenceNumber'] ?? '',
                    'canRefund' => ($transaction['remainingBalance'] ?? $transaction['amount']) > 0 &&
                                    in_array($transaction['status'], ['captured', 'partially_refunded']),
                    'createdAt' => $transaction['createdAt'] ?? $transaction['timestamp'],
                    'updatedAt' => $transaction['updatedAt'] ?? $transaction['timestamp']
                ];
            }, $transactions);

            PaymentUtils::sendSuccessResponse($formattedTransactions, 'Transactions retrieved successfully');
        }

    } elseif ($method === 'POST') {
        $data = PaymentUtils::parseJsonInput();

        // Check if requesting summary statistics
        if (strpos($requestUri, '/summary') !== false) {
            // POST /transactions/summary
            $summary = TransactionStorage::getTransactionsSummary();

            // Add additional calculated fields
            $summary['net_amount'] = $summary['total_amount'] - $summary['total_refunded'];
            $summary['refund_rate'] = $summary['total_amount'] > 0
                ? round(($summary['total_refunded'] / $summary['total_amount']) * 100, 2)
                : 0;

            PaymentUtils::sendSuccessResponse($summary, 'Transaction summary retrieved successfully');

        } else {
            PaymentUtils::sendErrorResponse(400, 'Invalid request', 'INVALID_REQUEST');
        }

    } elseif ($method === 'DELETE') {
        // DELETE /transactions/{id} - For testing purposes only
        if (count($pathParts) >= 2 && $pathParts[count($pathParts) - 1] !== 'transactions') {
            $transactionId = end($pathParts);

            $deleted = TransactionStorage::deleteTransaction($transactionId);
            if (!$deleted) {
                PaymentUtils::sendErrorResponse(404, 'Transaction not found or could not be deleted', 'NOT_FOUND');
            }

            PaymentUtils::sendSuccessResponse(['id' => $transactionId], 'Transaction deleted successfully');

        } else {
            PaymentUtils::sendErrorResponse(400, 'Transaction ID required for deletion', 'VALIDATION_ERROR');
        }

    } else {
        PaymentUtils::sendErrorResponse(405, 'Method not allowed');
    }

} catch (\Exception $e) {
    error_log('Transaction endpoint error: ' . $e->getMessage());
    PaymentUtils::sendErrorResponse(500, 'Internal server error', 'SERVER_ERROR');
}