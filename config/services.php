<?php

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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fonnte' => [
        'device_token' => env('FONNTE_DEVICE_TOKEN'),
        'account_token' => env('FONNTE_ACCOUNT_TOKEN'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'notification_url' => env('MIDTRANS_NOTIFICATION_URL'),
        'iris_api_key' => env('MIDTRANS_IRIS_API_KEY'),
        'iris_merchant_key' => env('MIDTRANS_IRIS_MERCHANT_KEY'),
    ],

    'pajak_express' => [
        'base_url' => env('PAJAK_EXPRESS_BASE_URL', 'https://nodemin.pajakexpress.id:1830'),
        'email'    => env('PAJAK_EXPRESS_EMAIL', 'dummy@ortax.org'),
        'password' => env('PAJAK_EXPRESS_PASSWORD', 'Ortax123#'),
        'npwp'     => env('PAJAK_EXPRESS_NPWP'),
    ],

];
