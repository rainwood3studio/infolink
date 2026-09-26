<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'redmine' => [
        'url' => env('REDMINE_URL'),
        'key' => env('REDMINE_API_KEY'),
        'timeout' => (int) env('REDMINE_TIMEOUT', 15),
        /*
         * The single person who does final acceptance (驗收) on every issue. Matched against the start of the
         * assignee's display name (Redmine shows "名 姓"), so "驗證中" can be split into their queue vs. everyone else.
         */
        'acceptor_name' => env('REDMINE_ACCEPTOR_NAME', '文豪'),
    ],

    'line' => [
        /*
         * LINE Official Account + Messaging API (LINE Notify was discontinued in 2025-03). Push goes to one user.
         */
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
        'user_id' => env('LINE_USER_ID'),
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

];
