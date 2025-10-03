/**
 * Basic Refund Tool - Node.js Server
 *
 * This Express application provides endpoints for processing payments and refunds
 * using the Global Payments GP API SDK.
 *
 * Endpoints:
 * - GET /config - Returns GP API session token and configuration
 * - POST /charge - Processes payment transactions
 * - POST /refund - Processes refund transactions
 */

import express from 'express';
import * as dotenv from 'dotenv';
import {
    ServicesContainer,
    GpApiConfig,
    CreditCardData,
    Transaction,
    Address,
    Environment,
    Channel
} from 'globalpayments-api';

// Load environment variables from .env file
dotenv.config();

/**
 * Initialize Express application with necessary middleware
 */
const app = express();
const port = process.env.PORT || 8000;

app.use(express.static('.')); // Serve static files from current directory
app.use(express.urlencoded({ extended: true })); // Parse form data
app.use(express.json()); // Parse JSON requests

/**
 * CORS middleware to handle cross-origin requests
 */
app.use((req, res, next) => {
    res.header('Access-Control-Allow-Origin', '*');
    res.header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    next();
});

/**
 * Configure Global Payments GP API SDK
 * Returns configured GpApiConfig instance
 */
const createGpApiConfig = () => {
    const environment = process.env.GP_API_ENVIRONMENT || 'sandbox';
    const isProduction = environment.toLowerCase() === 'production';

    const config = new GpApiConfig();
    config.appId = process.env.GP_API_APP_ID;
    config.appKey = process.env.GP_API_APP_KEY;
    config.environment = isProduction ? Environment.PRODUCTION : Environment.TEST;
    config.channel = Channel.CardNotPresent;
    config.country = 'US';

    return config;
};

/**
 * Initialize GP API service container
 */
const initializeGpApi = () => {
    const config = createGpApiConfig();
    ServicesContainer.configureService(config);
};

// Initialize GP API on startup
initializeGpApi();

/**
 * Utility function to sanitize postal code
 * Removes invalid characters and limits to 10 characters
 */
const sanitizePostalCode = (postalCode) => {
    if (!postalCode) return '';
    return postalCode.replace(/[^a-zA-Z0-9-]/g, '').slice(0, 10);
};

/**
 * GET /config
 * Generates GP API session token for client-side tokenization
 * Returns configuration data including access token and API settings
 */
app.get('/config', async (req, res) => {
    try {
        const environment = process.env.GP_API_ENVIRONMENT || 'sandbox';
        const isProduction = environment.toLowerCase() === 'production';

        // Configure GP API for session token generation
        const config = new GpApiConfig();
        config.appId = process.env.GP_API_APP_ID;
        config.appKey = process.env.GP_API_APP_KEY;
        config.environment = isProduction ? Environment.PRODUCTION : Environment.TEST;
        config.channel = Channel.CardNotPresent;
        config.country = 'US';
        config.permissions = ['PMT_POST_Create_Single'];

        // Configure service container
        ServicesContainer.configureService(config);

        // Generate session token for client-side use
        const accessTokenInfo = await ServicesContainer.instance().getClient().getAccessToken();
        const accessToken = accessTokenInfo.token;

        if (!accessToken) {
            throw new Error('Failed to generate session token');
        }

        console.log('Session token generated successfully:', accessToken.substring(0, 8) + '...');

        // Return configuration
        res.json({
            success: true,
            data: {
                accessToken: accessToken,
                environment: environment,
                supportedCurrencies: ['USD', 'EUR', 'GBP', 'CAD'],
                supportedPaymentMethods: ['CARD'],
                defaultCurrency: 'USD',
                maxAmount: 999999, // Maximum amount in cents
                minAmount: 1, // Minimum amount in cents
                api: {
                    version: '2021-03-22',
                    baseUrl: isProduction
                        ? 'https://apis.globalpay.com'
                        : 'https://apis.sandbox.globalpay.com'
                },
                refund: {
                    maxPercentage: 115, // Maximum refund percentage of original transaction
                    timeWindowDays: 180 // Time window for refunds in days
                }
            }
        });
    } catch (error) {
        console.error('Configuration error:', error.message);
        res.status(500).json({
            success: false,
            message: 'Error loading configuration: ' + error.message
        });
    }
});

/**
 * POST /charge
 * Processes payment transactions using tokenized card data
 * Accepts JSON body with payment_token, amount, currency, and optional billing_zip
 */
app.post('/charge', async (req, res) => {
    const requestId = 'charge_' + Date.now() + '_' + Math.random().toString(36).substring(7);

    try {
        console.log(`[${requestId}] CHARGE REQUEST - Raw input:`, JSON.stringify(req.body));

        // Validate required fields
        if (!req.body.payment_token) {
            console.log(`[${requestId}] VALIDATION ERROR - Missing payment token`);
            return res.status(400).json({
                success: false,
                message: 'Payment token is required',
                error_code: 'VALIDATION_ERROR',
                timestamp: new Date().toISOString()
            });
        }

        if (!req.body.amount || req.body.amount <= 0) {
            console.log(`[${requestId}] VALIDATION ERROR - Invalid amount:`, req.body.amount);
            return res.status(400).json({
                success: false,
                message: 'Valid amount is required',
                error_code: 'VALIDATION_ERROR',
                timestamp: new Date().toISOString()
            });
        }

        const paymentToken = req.body.payment_token;
        const amount = parseFloat(req.body.amount);
        const currency = req.body.currency || 'USD';

        // Log parsed payment data (mask sensitive token)
        const maskedToken = paymentToken.substring(0, 8) + '...' + paymentToken.substring(paymentToken.length - 4);
        console.log(`[${requestId}] PARSED DATA - Token: ${maskedToken}, Amount: ${amount}, Currency: ${currency}`);

        // Extract card details from the request (provided by Global Payments PaymentForm)
        const cardDetails = req.body.cardDetails || {};
        console.log(`[${requestId}] CARD DETAILS:`, JSON.stringify(cardDetails));

        // Determine card brand and last4 from card details
        let cardBrand = 'Unknown';
        let last4 = '0000';

        if (cardDetails.cardType) {
            cardBrand = cardDetails.cardType.charAt(0).toUpperCase() + cardDetails.cardType.slice(1).toLowerCase();
        }

        if (cardDetails.cardLast4) {
            last4 = cardDetails.cardLast4;
        }

        console.log(`[${requestId}] CARD INFO - Brand: ${cardBrand}, Last4: ${last4}`);

        // Re-initialize GP API with full permissions for server-side transaction processing
        initializeGpApi();

        // Process payment with GP API
        console.log(`[${requestId}] CALLING GP API - Starting payment processing`);

        const card = new CreditCardData();
        card.token = paymentToken;

        console.log(`[${requestId}] CARD OBJECT - Created with token: ${maskedToken}`);

        let chargeBuilder = card.charge(amount)
            .withCurrency(currency)
            .withAllowDuplicates(true);

        console.log(`[${requestId}] CHARGE BUILDER - Amount: ${amount}, Currency: ${currency}, AllowDuplicates: true`);

        // Add billing address if provided
        if (req.body.billing_zip) {
            console.log(`[${requestId}] BILLING ADDRESS - Adding postal code: ${req.body.billing_zip}`);

            const address = new Address();
            address.postalCode = sanitizePostalCode(req.body.billing_zip);

            chargeBuilder = chargeBuilder.withAddress(address);
            console.log(`[${requestId}] ADDRESS OBJECT - Postal: ${address.postalCode}`);
        } else {
            console.log(`[${requestId}] BILLING ADDRESS - None provided`);
        }

        console.log(`[${requestId}] EXECUTING CHARGE - About to call GP API`);
        const response = await chargeBuilder.execute();
        console.log(`[${requestId}] CHARGE EXECUTED - GP API call completed`);

        // Log the complete response details
        console.log(`[${requestId}] GP API RESPONSE - Response Code:`, response.responseCode);
        console.log(`[${requestId}] GP API RESPONSE - Response Message:`, response.responseMessage);
        console.log(`[${requestId}] GP API RESPONSE - Transaction ID:`, response.transactionId);
        console.log(`[${requestId}] GP API RESPONSE - Authorization Code:`, response.authorizationCode);
        console.log(`[${requestId}] GP API RESPONSE - Reference Number:`, response.referenceNumber);

        if (response.responseCode === 'SUCCESS' || response.responseCode === '00') {
            console.log(`[${requestId}] GP API SUCCESS - Payment approved`);

            const result = {
                transactionId: response.transactionId || 'txn_' + Date.now(),
                amount: amount,
                currency: currency,
                status: 'captured',
                responseCode: response.responseCode,
                responseMessage: response.responseMessage || 'Approved',
                timestamp: new Date().toISOString(),
                authorizationCode: response.authorizationCode || '',
                referenceNumber: response.referenceNumber || '',
                paymentMethod: {
                    type: 'card',
                    brand: cardBrand,
                    last4: last4
                }
            };

            console.log(`[${requestId}] RESPONSE - Sending success response:`, JSON.stringify(result));

            res.json({
                success: true,
                message: 'Payment processed successfully',
                data: result,
                timestamp: new Date().toISOString()
            });
        } else {
            console.log(`[${requestId}] GP API FAILED - Code: ${response.responseCode}, Message:`, response.responseMessage);
            throw new Error('Payment failed: ' + (response.responseMessage || 'Unknown error'));
        }

    } catch (error) {
        console.error(`[${requestId}] PAYMENT ERROR:`, error.message);
        console.error(`[${requestId}] PAYMENT ERROR STACK:`, error.stack);

        // Determine if this is a specific payment error or generic failure
        let errorMessage = 'Payment processing failed';
        let errorCode = 'PAYMENT_ERROR';

        if (error.message && error.message.startsWith('Payment failed:')) {
            errorMessage = error.message;
            errorCode = 'PAYMENT_DECLINED';
        }

        res.status(422).json({
            success: false,
            message: errorMessage,
            error_code: errorCode,
            timestamp: new Date().toISOString()
        });
    }
});

/**
 * POST /refund
 * Processes refund transactions using transaction IDs
 * Accepts JSON body with transactionId, amount, currency, and optional reason
 */
app.post('/refund', async (req, res) => {
    const requestId = 'refund_' + Date.now() + '_' + Math.random().toString(36).substring(7);

    try {
        console.log(`[${requestId}] REFUND REQUEST - Raw input:`, JSON.stringify(req.body));

        // Validate required fields
        if (!req.body.transactionId) {
            console.log(`[${requestId}] VALIDATION ERROR - Missing transaction ID`);
            return res.status(400).json({
                success: false,
                message: 'Transaction ID is required',
                error_code: 'VALIDATION_ERROR',
                timestamp: new Date().toISOString()
            });
        }

        if (!req.body.amount || req.body.amount <= 0) {
            console.log(`[${requestId}] VALIDATION ERROR - Invalid amount:`, req.body.amount);
            return res.status(400).json({
                success: false,
                message: 'Valid refund amount is required',
                error_code: 'VALIDATION_ERROR',
                timestamp: new Date().toISOString()
            });
        }

        const transactionId = req.body.transactionId;
        const refundAmount = parseFloat(req.body.amount);
        const currency = req.body.currency || 'USD';
        const reason = req.body.reason || 'Refund requested';

        console.log(`[${requestId}] PARSED DATA - Transaction ID: ${transactionId}, Amount: ${refundAmount}, Currency: ${currency}`);

        // Re-initialize GP API with full permissions for server-side transaction processing
        initializeGpApi();

        // Process refund with GP API
        console.log(`[${requestId}] CALLING GP API - Starting refund processing`);

        const transaction = Transaction.fromId(transactionId);

        console.log(`[${requestId}] TRANSACTION OBJECT - Created with ID: ${transactionId}`);

        const response = await transaction.refund(refundAmount)
            .withCurrency(currency)
            .withAllowDuplicates(true)
            .execute();

        console.log(`[${requestId}] REFUND EXECUTED - GP API call completed`);

        // Log the complete response details
        console.log(`[${requestId}] GP API RESPONSE - Response Code:`, response.responseCode);
        console.log(`[${requestId}] GP API RESPONSE - Response Message:`, response.responseMessage);
        console.log(`[${requestId}] GP API RESPONSE - Transaction ID:`, response.transactionId);
        console.log(`[${requestId}] GP API RESPONSE - Authorization Code:`, response.authorizationCode);
        console.log(`[${requestId}] GP API RESPONSE - Reference Number:`, response.referenceNumber);

        if (response.responseCode === 'SUCCESS' || response.responseCode === '00') {
            console.log(`[${requestId}] GP API SUCCESS - Refund approved`);

            const result = {
                refundId: response.transactionId || 'ref_' + Date.now(),
                transactionId: transactionId,
                amount: refundAmount,
                currency: currency,
                status: 'captured',
                timestamp: new Date().toISOString(),
                responseCode: response.responseCode,
                responseMessage: response.responseMessage || 'Refunded',
                authorizationCode: response.authorizationCode || '',
                referenceNumber: response.referenceNumber || '',
                reason: reason
            };

            console.log(`[${requestId}] RESPONSE - Sending success response:`, JSON.stringify(result));

            res.json({
                success: true,
                message: 'Refund processed successfully',
                data: result,
                timestamp: new Date().toISOString()
            });
        } else {
            console.log(`[${requestId}] GP API FAILED - Code: ${response.responseCode}, Message:`, response.responseMessage);
            throw new Error('Refund failed: ' + (response.responseMessage || 'Unknown error'));
        }

    } catch (error) {
        console.error(`[${requestId}] REFUND ERROR:`, error.message);
        console.error(`[${requestId}] REFUND ERROR STACK:`, error.stack);

        // Determine if this is a specific refund error or generic failure
        let errorMessage = 'Refund processing failed';
        let errorCode = 'REFUND_ERROR';

        if (error.message && error.message.startsWith('Refund failed:')) {
            errorMessage = error.message;
            errorCode = 'REFUND_DECLINED';
        }

        res.status(422).json({
            success: false,
            message: errorMessage,
            error_code: errorCode,
            timestamp: new Date().toISOString()
        });
    }
});

// Start the server
app.listen(port, '0.0.0.0', () => {
    console.log(`Basic Refund Tool - Node.js Server`);
    console.log(`Server running at http://localhost:${port}`);
    console.log(`Environment: ${process.env.GP_API_ENVIRONMENT || 'sandbox'}`);
    console.log(`Ready to process payments and refunds using GP API`);
});
