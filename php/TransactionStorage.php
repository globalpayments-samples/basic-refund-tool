<?php

declare(strict_types=1);

/**
 * Transaction Storage Class
 *
 * Handles persistent storage of transaction data in JSON format
 * for the Basic Refund Tool.
 */
class TransactionStorage
{
    private const STORAGE_FILE = __DIR__ . '/data/transactions.json';

    /**
     * Initialize storage directory
     */
    public static function initialize(): void
    {
        $dataDir = dirname(self::STORAGE_FILE);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        if (!file_exists(self::STORAGE_FILE)) {
            self::writeData(['transactions' => []]);
        }
    }

    /**
     * Read all transaction data
     */
    public static function readData(): array
    {
        self::initialize();

        if (!file_exists(self::STORAGE_FILE)) {
            return ['transactions' => []];
        }

        $content = file_get_contents(self::STORAGE_FILE);
        if ($content === false) {
            return ['transactions' => []];
        }

        $data = json_decode($content, true);
        return $data ?: ['transactions' => []];
    }

    /**
     * Write transaction data
     */
    public static function writeData(array $data): bool
    {
        self::initialize();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents(self::STORAGE_FILE, $json, LOCK_EX) !== false;
    }

    /**
     * Generate unique transaction ID
     */
    public static function generateTransactionId(): string
    {
        return 'txn_' . uniqid() . '_' . substr(md5(mt_rand()), 0, 8);
    }

    /**
     * Generate unique refund ID
     */
    public static function generateRefundId(): string
    {
        return 'ref_' . uniqid() . '_' . substr(md5(mt_rand()), 0, 8);
    }

    /**
     * Add new transaction
     */
    public static function addTransaction(array $transaction): bool
    {
        $data = self::readData();

        $newTransaction = [
            'id' => $transaction['id'] ?? self::generateTransactionId(),
            'transactionId' => $transaction['transactionId'] ?? '',
            'amount' => (float)($transaction['amount'] ?? 0),
            'currency' => $transaction['currency'] ?? 'USD',
            'status' => $transaction['status'] ?? 'pending',
            'paymentMethod' => [
                'type' => 'card',
                'brand' => $transaction['paymentMethod']['brand'] ?? 'Unknown',
                'last4' => $transaction['paymentMethod']['last4'] ?? '0000',
                'token' => $transaction['paymentMethod']['token'] ?? ''
            ],
            'timestamp' => $transaction['timestamp'] ?? date('c'),
            'responseCode' => $transaction['responseCode'] ?? '',
            'responseMessage' => $transaction['responseMessage'] ?? '',
            'authorizationCode' => $transaction['authorizationCode'] ?? '',
            'referenceNumber' => $transaction['referenceNumber'] ?? '',
            'refunds' => [],
            'totalRefunded' => 0.0,
            'remainingBalance' => (float)($transaction['amount'] ?? 0),
            'createdAt' => date('c'),
            'updatedAt' => date('c')
        ];

        $data['transactions'][] = $newTransaction;

        return self::writeData($data);
    }

    /**
     * Get all transactions
     */
    public static function getAllTransactions(array $filters = []): array
    {
        $data = self::readData();
        $transactions = $data['transactions'] ?? [];

        // Apply filters if provided
        if (!empty($filters)) {
            $transactions = array_filter($transactions, function ($transaction) use ($filters) {
                foreach ($filters as $key => $value) {
                    if (isset($transaction[$key]) && $transaction[$key] !== $value) {
                        return false;
                    }
                }
                return true;
            });
        }

        // Sort by timestamp (newest first)
        usort($transactions, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_values($transactions);
    }

    /**
     * Get transaction by ID
     */
    public static function getTransactionById(string $id): ?array
    {
        $data = self::readData();
        $transactions = $data['transactions'] ?? [];

        foreach ($transactions as $transaction) {
            if ($transaction['id'] === $id || $transaction['transactionId'] === $id) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * Update transaction
     */
    public static function updateTransaction(string $id, array $updates): bool
    {
        $data = self::readData();
        $transactions = &$data['transactions'];

        for ($i = 0; $i < count($transactions); $i++) {
            if ($transactions[$i]['id'] === $id || $transactions[$i]['transactionId'] === $id) {
                $transactions[$i] = array_merge($transactions[$i], $updates);
                $transactions[$i]['updatedAt'] = date('c');
                return self::writeData($data);
            }
        }

        return false;
    }

    /**
     * Add refund to transaction
     */
    public static function addRefund(string $transactionId, array $refund): bool
    {
        $data = self::readData();
        $transactions = &$data['transactions'];

        for ($i = 0; $i < count($transactions); $i++) {
            if ($transactions[$i]['id'] === $transactionId || $transactions[$i]['transactionId'] === $transactionId) {
                $newRefund = [
                    'refundId' => $refund['refundId'] ?? self::generateRefundId(),
                    'amount' => (float)($refund['amount'] ?? 0),
                    'currency' => $refund['currency'] ?? $transactions[$i]['currency'],
                    'status' => $refund['status'] ?? 'pending',
                    'timestamp' => $refund['timestamp'] ?? date('c'),
                    'responseCode' => $refund['responseCode'] ?? '',
                    'responseMessage' => $refund['responseMessage'] ?? '',
                    'authorizationCode' => $refund['authorizationCode'] ?? '',
                    'referenceNumber' => $refund['referenceNumber'] ?? ''
                ];

                // Add refund to transaction
                $transactions[$i]['refunds'][] = $newRefund;

                // Update totals
                $transactions[$i]['totalRefunded'] += $newRefund['amount'];
                $transactions[$i]['remainingBalance'] = $transactions[$i]['amount'] - $transactions[$i]['totalRefunded'];

                // Update transaction status based on refund amount
                if ($transactions[$i]['totalRefunded'] >= $transactions[$i]['amount']) {
                    $transactions[$i]['status'] = 'refunded';
                } elseif ($transactions[$i]['totalRefunded'] > 0) {
                    $transactions[$i]['status'] = 'partially_refunded';
                }

                $transactions[$i]['updatedAt'] = date('c');

                return self::writeData($data);
            }
        }

        return false;
    }

    /**
     * Get transactions summary statistics
     */
    public static function getTransactionsSummary(): array
    {
        $transactions = self::getAllTransactions();

        $summary = [
            'total_transactions' => count($transactions),
            'total_amount' => 0.0,
            'total_refunded' => 0.0,
            'status_breakdown' => [
                'captured' => 0,
                'refunded' => 0,
                'partially_refunded' => 0,
                'pending' => 0,
                'failed' => 0
            ],
            'currency_breakdown' => []
        ];

        foreach ($transactions as $transaction) {
            $summary['total_amount'] += $transaction['amount'];
            $summary['total_refunded'] += $transaction['totalRefunded'];

            // Status breakdown
            $status = $transaction['status'];
            if (isset($summary['status_breakdown'][$status])) {
                $summary['status_breakdown'][$status]++;
            }

            // Currency breakdown
            $currency = $transaction['currency'];
            if (!isset($summary['currency_breakdown'][$currency])) {
                $summary['currency_breakdown'][$currency] = [
                    'count' => 0,
                    'total_amount' => 0.0,
                    'total_refunded' => 0.0
                ];
            }
            $summary['currency_breakdown'][$currency]['count']++;
            $summary['currency_breakdown'][$currency]['total_amount'] += $transaction['amount'];
            $summary['currency_breakdown'][$currency]['total_refunded'] += $transaction['totalRefunded'];
        }

        return $summary;
    }

    /**
     * Validate transaction data
     */
    public static function validateTransactionData(array $data): array
    {
        $errors = [];

        if (empty($data['amount']) || $data['amount'] <= 0) {
            $errors[] = 'Amount must be greater than 0';
        }

        if (empty($data['currency'])) {
            $errors[] = 'Currency is required';
        }

        if (empty($data['transactionId'])) {
            $errors[] = 'Transaction ID is required';
        }

        if (empty($data['paymentMethod']['brand'])) {
            $errors[] = 'Payment method brand is required';
        }

        if (empty($data['paymentMethod']['last4'])) {
            $errors[] = 'Payment method last4 is required';
        }

        return $errors;
    }

    /**
     * Delete transaction (for testing purposes)
     */
    public static function deleteTransaction(string $id): bool
    {
        $data = self::readData();
        $transactions = $data['transactions'] ?? [];

        $filteredTransactions = array_filter($transactions, function ($transaction) use ($id) {
            return $transaction['id'] !== $id && $transaction['transactionId'] !== $id;
        });

        if (count($filteredTransactions) !== count($transactions)) {
            $data['transactions'] = array_values($filteredTransactions);
            return self::writeData($data);
        }

        return false;
    }

    /**
     * Clear all transactions (for testing purposes)
     */
    public static function clearAllTransactions(): bool
    {
        return self::writeData(['transactions' => []]);
    }
}