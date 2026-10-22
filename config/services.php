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
        'notification_driver' => env('WHATSAPP_NOTIFICATION_DRIVER', 'fonnte'),
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
        'local_simulation' => env('PAJAK_EXPRESS_LOCAL_SIMULATION', true),
    ],

    'crm' => [
        'whatsapp' => [
            'service_url' => env('CRM_WHATSAPP_SERVICE_URL', 'http://whatsapp:3100'),
            'webhook_secret' => env('CRM_WHATSAPP_WEBHOOK_SECRET'),
        ],
        'gmail' => [
            'client_id' => env('CRM_GMAIL_CLIENT_ID'),
            'client_secret' => env('CRM_GMAIL_CLIENT_SECRET'),
            'redirect_uri' => env('CRM_GMAIL_REDIRECT_URI'),
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'openid email profile https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send',
        ],
        'microsoft' => [
            'client_id' => env('CRM_MICROSOFT_CLIENT_ID'),
            'client_secret' => env('CRM_MICROSOFT_CLIENT_SECRET'),
            'redirect_uri' => env('CRM_MICROSOFT_REDIRECT_URI'),
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => 'openid profile email offline_access User.Read Mail.Read Mail.Send',
        ],
    ],

];
