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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'pakasir' => [
        'slug' => env('PAKASIR_SLUG'),
        'api_key' => env('PAKASIR_API_KEY'),
        'url' => env('PAKASIR_URL', 'https://app.pakasir.com'),
        'return_url' => env('PAKASIR_RETURN_URL'),
        'qris_only' => (bool) env('PAKASIR_QRIS_ONLY', true),
    ],

    'crypto_direct' => [
        'expires_minutes' => (int) env('CRYPTO_DIRECT_EXPIRES_MINUTES', 1440),
        'unique_max' => (int) env('CRYPTO_DIRECT_UNIQUE_MAX', 9999),
        'networks' => [
            'usdttrc20' => [
                'label' => 'TRX Tron (TRC20)',
                'short_label' => 'TRC20',
                'address' => env('CRYPTO_TRC20_ADDRESS'),
                'contract' => env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
                'api_url' => env('TRONGRID_URL', 'https://api.trongrid.io'),
                'api_key' => env('TRONGRID_API_KEY'),
                'decimals' => 6,
            ],
            'usdtbsc' => [
                'label' => 'BSC BNB Smart Chain (BEP20)',
                'short_label' => 'BEP20',
                'address' => env('CRYPTO_BEP20_ADDRESS'),
                'contract' => env('BSC_USDT_CONTRACT', '0x55d398326f99059fF775485246999027B3197955'),
                'rpc_url' => env('BSC_RPC_URL', 'https://bsc-rpc.publicnode.com'),
                'rpc_scan_blocks' => (int) env('BSC_RPC_SCAN_BLOCKS', 40000),
                'rpc_chunk_blocks' => (int) env('BSC_RPC_CHUNK_BLOCKS', 3000),
                'decimals' => 18,
            ],
        ],
    ],

];
