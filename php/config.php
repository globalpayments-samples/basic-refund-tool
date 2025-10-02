<?php

declare(strict_types=1);

/**
 * Configuration Endpoint for Basic Refund Tool
 *
 * This script provides configuration information for the Basic Refund Tool,
 * including GP API settings and generates a client-side access token.
 *
 * PHP version 8.0 or higher
 *
 * @category  Configuration
 * @package   GlobalPayments_BasicRefundTool
 * @author    Global Payments
 * @license   MIT License
 * @link      https://github.com/globalpayments
 */

require_once 'vendor/autoload.php';

use Dotenv\Dotenv;
use GlobalPayments\Api\ServiceConfigs\Gateways\GpApiConfig;
use GlobalPayments\Api\ServicesContainer;
use GlobalPayments\Api\Entities\Enums\Environment;
use GlobalPayments\Api\Entities\Enums\Channel;
use GlobalPayments\Api\Services\GpApiService;

try {
    // Load environment variables from .env file
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    // Set response content type to JSON
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Handle preflight requests
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // Determine environment from configuration
    $environment = $_ENV['GP_API_ENVIRONMENT'] ?? 'sandbox';
    $isProduction = strtolower($environment) === 'production';

    // Configure GP API to generate access token for client-side use
    $config = new GpApiConfig();
    $config->appId = $_ENV['GP_API_APP_ID'] ?? '';
    $config->appKey = $_ENV['GP_API_APP_KEY'] ?? '';
    $config->environment = $isProduction ? Environment::PRODUCTION : Environment::TEST;
    $config->channel = Channel::CardNotPresent;
    $config->country = 'US';

    // Set permissions specifically for client-side tokenization
    $config->permissions = ['PMT_POST_Create_Single'];

    // Generate session token for client-side tokenization
    try {
        // Configure service first to establish connection
        ServicesContainer::configureService($config);

        // Generate session token specifically for client-side tokenization
        $sessionToken = GpApiService::generateTransactionKey($config);

        if (is_object($sessionToken) && isset($sessionToken->accessToken)) {
            $accessToken = $sessionToken->accessToken;
            error_log('Session token generated successfully: ' . substr($accessToken, 0, 8) . '...');
        } else {
            throw new Exception('Invalid session token response format');
        }

        if (empty($accessToken)) {
            throw new Exception('Failed to generate session token');
        }

    } catch (Exception $e) {
        error_log('Session token generation failed: ' . $e->getMessage());
    }

    // Return configuration for Basic Refund Tool
    echo json_encode([
        'success' => true,
        'data' => [
            'accessToken' => $accessToken,
            'environment' => $environment,
            'supportedCurrencies' => ['USD', 'EUR', 'GBP', 'CAD'],
            'supportedPaymentMethods' => ['CARD'],
            'defaultCurrency' => 'USD',
            'maxAmount' => 999999, // Maximum amount in cents
            'minAmount' => 1, // Minimum amount in cents
            'api' => [
                'version' => '2021-03-22',
                'baseUrl' => $isProduction
                    ? 'https://apis.globalpay.com'
                    : 'https://apis.sandbox.globalpay.com'
            ],
            'refund' => [
                'maxPercentage' => 115, // Maximum refund percentage of original transaction
                'timeWindowDays' => 180 // Time window for refunds in days
            ]
        ],
    ]);
} catch (Exception $e) {
    // Handle configuration errors
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error loading configuration: ' . $e->getMessage()
    ]);
}
