<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Dotenv\Dotenv;
use GlobalPayments\Api\Entities\Address;
use GlobalPayments\Api\PaymentMethods\CreditCardData;
use GlobalPayments\Api\ServiceConfigs\Gateways\GpApiConfig;
use GlobalPayments\Api\ServicesContainer;
use GlobalPayments\Api\Entities\Enums\Environment;
use GlobalPayments\Api\Entities\Enums\Channel;
use GlobalPayments\Api\Entities\Transaction;
use GlobalPayments\Api\Utils\Logging\Logger;
use GlobalPayments\Api\Utils\Logging\SampleRequestLogger;

/**
 * Payment utility functions for Basic Refund Tool using GP API
 */
class PaymentUtils
{
    /**
     * Configure the Global Payments GP API SDK
     */
    public static function configureSdk(): void
    {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();

        $config = new GpApiConfig();
        $config->appId = $_ENV['GP_API_APP_ID'] ?? '';
        $config->appKey = $_ENV['GP_API_APP_KEY'] ?? '';
        $config->environment = Environment::TEST; // Use Environment::PRODUCTION for live transactions
        $config->channel = Channel::CardNotPresent;
        $config->country = 'US';

        $config->requestLogger = new SampleRequestLogger(new Logger("logs"));


        ServicesContainer::configureService($config);
    }

    /**
     * Sanitize postal code by removing invalid characters
     */
    public static function sanitizePostalCode(?string $postalCode): string
    {
        if ($postalCode === null) {
            return '';
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9-]/', '', $postalCode);
        return substr($sanitized, 0, 10);
    }

    /**
     * Determine card brand from card number
     */
    public static function determineCardBrand(string $cardNumber): string
    {
        $cardNumber = preg_replace('/\s+/', '', $cardNumber);

        if (preg_match('/^4/', $cardNumber)) {
            return 'Visa';
        } elseif (preg_match('/^5[1-5]/', $cardNumber) || preg_match('/^2[2-7]/', $cardNumber)) {
            return 'Mastercard';
        } elseif (preg_match('/^3[47]/', $cardNumber)) {
            return 'American Express';
        } elseif (preg_match('/^6(?:011|5)/', $cardNumber)) {
            return 'Discover';
        } elseif (preg_match('/^35/', $cardNumber)) {
            return 'JCB';
        } elseif (preg_match('/^30[0-5]|36|38/', $cardNumber)) {
            return 'Diners Club';
        } else {
            return 'Unknown';
        }
    }

    /**
     * Process payment using Global Payments GP API
     */
    public static function processPaymentWithGpApi(string $paymentToken, float $amount, string $currency, array $billingAddress = [], string $requestId = null): array
    {
        $logPrefix = $requestId ? "[$requestId]" : "[GP_API]";

        try {
            // Log the start of GP API processing
            $maskedToken = substr($paymentToken, 0, 8) . '...' . substr($paymentToken, -4);
            error_log("$logPrefix GP API PROCESSING - Token: $maskedToken, Amount: $amount, Currency: $currency");

            $card = new CreditCardData();
            $card->token = $paymentToken;

            error_log("$logPrefix CARD OBJECT - Created with token: $maskedToken");

            $chargeBuilder = $card->charge($amount)
                ->withCurrency($currency)
                ->withAllowDuplicates(true);

            error_log("$logPrefix CHARGE BUILDER - Amount: $amount, Currency: $currency, AllowDuplicates: true");

            // Add billing address if provided
            if (!empty($billingAddress)) {
                error_log("$logPrefix BILLING ADDRESS - Adding: " . json_encode($billingAddress));

                $address = new Address();
                $address->postalCode = self::sanitizePostalCode($billingAddress['postalCode'] ?? '');
                $address->streetAddress1 = $billingAddress['streetAddress1'] ?? '';
                $address->city = $billingAddress['city'] ?? '';
                $address->state = $billingAddress['state'] ?? '';
                $address->country = $billingAddress['country'] ?? '';

                $chargeBuilder->withAddress($address);
                error_log("$logPrefix ADDRESS OBJECT - Postal: {$address->postalCode}, Street: {$address->streetAddress1}, City: {$address->city}");
            } else {
                error_log("$logPrefix BILLING ADDRESS - None provided");
            }

            error_log("$logPrefix EXECUTING CHARGE - About to call GP API");
            $response = $chargeBuilder->execute();
            error_log("$logPrefix CHARGE EXECUTED - GP API call completed");

            // Log the complete response details
            error_log("$logPrefix GP API RESPONSE - Response Code: " . ($response->responseCode ?? 'null'));
            error_log("$logPrefix GP API RESPONSE - Response Message: " . ($response->responseMessage ?? 'null'));
            error_log("$logPrefix GP API RESPONSE - Transaction ID: " . ($response->transactionId ?? 'null'));
            error_log("$logPrefix GP API RESPONSE - Authorization Code: " . ($response->authorizationCode ?? 'null'));
            error_log("$logPrefix GP API RESPONSE - Reference Number: " . ($response->referenceNumber ?? 'null'));

            // Log all available response properties for debugging
            $responseProps = [];
            foreach (get_object_vars($response) as $key => $value) {
                $responseProps[$key] = $value;
            }
            error_log("$logPrefix GP API RESPONSE - All Properties: " . json_encode($responseProps, JSON_PARTIAL_OUTPUT_ON_ERROR));

            if ($response->responseCode === 'SUCCESS' || $response->responseCode === '00') {
                error_log("$logPrefix GP API SUCCESS - Payment approved");

                $result = [
                    'transaction_id' => $response->transactionId ?? 'txn_' . uniqid(),
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'captured',
                    'response_code' => $response->responseCode,
                    'response_message' => $response->responseMessage ?? 'Approved',
                    'timestamp' => date('c'),
                    'authorization_code' => $response->authorizationCode ?? '',
                    'reference_number' => $response->referenceNumber ?? ''
                ];

                error_log("$logPrefix PAYMENT RESULT: " . json_encode($result));
                return $result;
            } else {
                error_log("$logPrefix GP API FAILED - Code: {$response->responseCode}, Message: " . ($response->responseMessage ?? 'Unknown error'));
                throw new \Exception('Payment failed: ' . ($response->responseMessage ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            error_log("$logPrefix GP API EXCEPTION - Error: " . $e->getMessage());
            error_log("$logPrefix GP API EXCEPTION - Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Process refund using Global Payments GP API
     */
    public static function processRefundWithGpApi(string $transactionId, float $amount, string $currency): array
    {
        try {
            $transaction = new Transaction();
            $transaction->transactionId = $transactionId;

            $response = $transaction->refund($amount)
                ->withCurrency($currency)
                ->withAllowDuplicates(true)
                ->execute();

            if ($response->responseCode === 'SUCCESS' || $response->responseCode === '00') {
                return [
                    'refund_id' => $response->transactionId ?? 'ref_' . uniqid(),
                    'original_transaction_id' => $transactionId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'captured',
                    'response_code' => $response->responseCode,
                    'response_message' => $response->responseMessage ?? 'Refunded',
                    'timestamp' => date('c'),
                    'authorization_code' => $response->authorizationCode ?? '',
                    'reference_number' => $response->referenceNumber ?? ''
                ];
            } else {
                throw new \Exception('Refund failed: ' . ($response->responseMessage ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            error_log('GP API refund processing error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate refund amount against business rules
     */
    public static function validateRefundAmount(float $refundAmount, float $originalAmount, float $totalRefunded = 0.0): array
    {
        $errors = [];

        // Check if refund amount is positive
        if ($refundAmount <= 0) {
            $errors[] = 'Refund amount must be greater than 0';
        }

        // Check if refund amount exceeds 115% of original amount
        $maxRefundAmount = $originalAmount * 1.15;
        if (($totalRefunded + $refundAmount) > $maxRefundAmount) {
            $errors[] = sprintf(
                'Total refunds cannot exceed 115%% of original amount. Maximum refundable: $%.2f',
                $maxRefundAmount - $totalRefunded
            );
        }

        // Check if there's sufficient balance to refund
        $remainingBalance = $originalAmount - $totalRefunded;
        if ($refundAmount > $remainingBalance && $totalRefunded > 0) {
            $errors[] = sprintf(
                'Refund amount exceeds remaining balance. Available: $%.2f',
                $remainingBalance
            );
        }

        return $errors;
    }

    /**
     * Get Global Payments test cards
     */
    public static function getTestCards(): array
    {
        return [
            'success' => [
                'visa' => [
                    'number' => '4263970000005262',
                    'cvv' => '123',
                    'expiry' => '12/2025',
                    'brand' => 'Visa',
                    'description' => 'Visa Success'
                ],
                'mastercard' => [
                    'number' => '5425230000004415',
                    'cvv' => '123',
                    'expiry' => '12/2025',
                    'brand' => 'Mastercard',
                    'description' => 'Mastercard Success'
                ],
                'amex' => [
                    'number' => '374101000000608',
                    'cvv' => '1234',
                    'expiry' => '12/2025',
                    'brand' => 'American Express',
                    'description' => 'American Express Success'
                ],
                'discover' => [
                    'number' => '6011000000000087',
                    'cvv' => '123',
                    'expiry' => '12/2025',
                    'brand' => 'Discover',
                    'description' => 'Discover Success'
                ],
                'jcb' => [
                    'number' => '3566000000000000',
                    'cvv' => '123',
                    'expiry' => '12/2025',
                    'brand' => 'JCB',
                    'description' => 'JCB Success'
                ],
                'diners' => [
                    'number' => '36256000000725',
                    'cvv' => '123',
                    'expiry' => '12/2025',
                    'brand' => 'Diners Club',
                    'description' => 'Diners Club Success'
                ]
            ]
        ];
    }

    /**
     * Send success response
     */
    public static function sendSuccessResponse($data, string $message = 'Operation completed successfully'): void
    {
        http_response_code(200);

        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => date('c')
        ];

        echo json_encode($response);
        exit();
    }

    /**
     * Send error response
     */
    public static function sendErrorResponse(int $statusCode, string $message, string $errorCode = null): void
    {
        http_response_code($statusCode);

        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('c')
        ];

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        echo json_encode($response);
        exit();
    }

    /**
     * Handle CORS headers
     */
    public static function handleCORS(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    /**
     * Parse JSON input for POST requests
     */
    public static function parseJsonInput(): array
    {
        $inputData = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $rawInput = file_get_contents('php://input');
            if ($rawInput) {
                $inputData = json_decode($rawInput, true) ?? [];
            }
            $inputData = array_merge($_POST, $inputData);
        }
        return $inputData;
    }
}