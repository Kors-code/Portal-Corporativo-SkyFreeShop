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

    'automation' => [
        'token' => env('AUTOMATION_TOKEN', env('IMPORT_AUTOMATION_TOKEN')),
    ],

    'whatsapp_cloud' => [
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v23.0'),
        'access_token' => env('WHATSAPP_CLOUD_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'webhook_app_secret' => env('WHATSAPP_WEBHOOK_APP_SECRET'),
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '57'),
        'recipient_numbers' => env('WHATSAPP_RECIPIENT_NUMBERS', ''),
        'allow_any_report_sender' => env('WHATSAPP_REPORTS_ALLOW_ANY_SENDER', false),
        'daily_report_template' => env('WHATSAPP_DAILY_REPORT_TEMPLATE', 'reporte_diario_sky'),
        'menu_template' => env('WHATSAPP_MENU_TEMPLATE', 'menu_reportes_sky'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es'),
        'menu_template_language' => env('WHATSAPP_MENU_TEMPLATE_LANGUAGE'),
        'menu_template_language_fallbacks' => env('WHATSAPP_MENU_TEMPLATE_LANGUAGE_FALLBACKS', 'es_ES,es,es_CO'),
        'daily_report_template_body_params' => env('WHATSAPP_DAILY_REPORT_TEMPLATE_BODY_PARAMS', 2),
        'daily_report_template_body_param_names' => env('WHATSAPP_DAILY_REPORT_TEMPLATE_BODY_PARAM_NAMES', 'nombre,fecha'),
        'menu_template_body_param_names' => env('WHATSAPP_MENU_TEMPLATE_BODY_PARAM_NAMES', 'nombre'),
    ],

    'onedrive_advisor_info' => [
        'tenant_id' => env('ONEDRIVE_TENANT_ID', env('TENANT_ID')),
        'client_id' => env('ONEDRIVE_CLIENT_ID', env('CLIENT_ID')),
        'client_secret' => env('ONEDRIVE_CLIENT_SECRET', env('CLIENT_SECRET')),
        'user_id' => env('ONEDRIVE_INFO_USER_ID', env('ONEDRIVE_USER_ID')),
        'root_folder' => env('ONEDRIVE_INFO_FOLDER', 'Info Asesores'),
        'ca_bundle' => env('ONEDRIVE_CA_BUNDLE'),
    ],

    'datos_gov' => [
        'verify_ssl' => env('DATOS_GOV_VERIFY_SSL', false),
    ],

];
