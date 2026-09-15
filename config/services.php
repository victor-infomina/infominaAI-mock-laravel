<?php

use App\Support\SenangpayKeys;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // Senangpay-protocol payment mock (see PaymentMockController). One secret
    // per frontend host (localhost / dev / staging); each must equal the
    // SENANGPAY_SECRET_KEY of the infominaAI-BE serving that frontend, since
    // that BE verifies the callback hash with its own key. Hosts not listed
    // here may not use the payment mock at all.
    'senangpay' => [
        'keys' => SenangpayKeys::parse(env('SENANGPAY_SECRET_KEYS')),
        'redirect_url' => env('MOCK_REDIRECT_URL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
