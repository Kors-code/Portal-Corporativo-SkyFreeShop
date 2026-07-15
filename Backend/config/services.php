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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'mailersend' => [
        'api_key' => env('MAILERSEND_API_KEY'),
    ],

    'whatsapp_cloud' => [
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v23.0'),
        'access_token' => env('WHATSAPP_CLOUD_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'webhook_app_secret' => env('WHATSAPP_WEBHOOK_APP_SECRET'),
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '57'),
        'recipient_numbers' => env('WHATSAPP_RECIPIENT_NUMBERS', ''),
    ],

];
