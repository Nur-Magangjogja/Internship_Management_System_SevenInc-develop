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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'absen_djuragan' => [
        'url'              => env('ABSEN_DJURAGAN_API_URL', 'http://absen-djuragan.test/api/v1/external'),
        'web_url'          => env('ABSEN_DJURAGAN_WEB_URL', 'http://absen-djuragan.test'),
        'api_key'          => env('ABSEN_DJURAGAN_INBOUND_API_KEY', env('ABSEN_DJURAGAN_API_KEY')),
        'inbound_api_key'  => env('ABSEN_DJURAGAN_INBOUND_API_KEY', env('ABSEN_DJURAGAN_API_KEY')),
        'outbound_api_key' => env('ABSEN_DJURAGAN_OUTBOUND_API_KEY', env('ABSEN_DJURAGAN_API_KEY')),
        'inbound_enabled'       => (bool) env('ABSEN_DJURAGAN_INBOUND_ENABLED', false),
        'inbound_write_enabled' => (bool) env('ABSEN_DJURAGAN_INBOUND_WRITE_ENABLED', false),
        'outbox_enabled'        => (bool) env('ABSEN_DJURAGAN_OUTBOX_ENABLED', false),
        'outbound_enabled'      => (bool) env('ABSEN_DJURAGAN_OUTBOUND_ENABLED', false),
        'sync_timeout'     => (int) env('ABSEN_DJURAGAN_SYNC_TIMEOUT', 15),
        'max_retries'      => (int) env('ABSEN_DJURAGAN_MAX_RETRIES', 5),
        'mappings'         => [
            'institutions' => [],
            'divisions'    => [],
            'brands'       => [],
        ],
    ],

    'google_drive' => [
        'enabled'       => env('GOOGLE_DRIVE_ENABLED', false),
        'client_id'     => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'folder_id'     => env('GOOGLE_DRIVE_FOLDER_ID'),
    ],

    'google_sheets' => [
        'enabled' => env('GOOGLE_SHEETS_ENABLED', false),
    ],

    'google_apps_script' => [
        'enabled'          => env('GOOGLE_APPS_SCRIPT_ENABLED', false),
        'endpoint_url'     => env('GOOGLE_APPS_SCRIPT_ENDPOINT_URL'),
        'shared_secret'    => env('GOOGLE_APPS_SCRIPT_SHARED_SECRET', env('GOOGLE_APPS_SCRIPT_WEBHOOK_SECRET')),
        'timeout_seconds'  => (int) env('GOOGLE_APPS_SCRIPT_TIMEOUT_SECONDS', 30),
        'root_folder_id'   => env('GOOGLE_DRIVE_ROOT_FOLDER_ID'),
    ],

];

