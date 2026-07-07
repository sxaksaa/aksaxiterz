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
        'expires_minutes' => (int) env('PAKASIR_EXPIRES_MINUTES', 5),
    ],

    'payments' => [
        'reservation_grace_minutes' => (int) env('PAYMENT_RESERVATION_GRACE_MINUTES', 0),
        'stale_pending_hours' => (int) env('PAYMENT_STALE_PENDING_HOURS', 24),
        'traffic_cleanup_seconds' => (int) env('PAYMENT_TRAFFIC_CLEANUP_SECONDS', 30),
        'traffic_cleanup_limit' => (int) env('PAYMENT_TRAFFIC_CLEANUP_LIMIT', 20),
        'traffic_cleanup_cache_store' => env('PAYMENT_TRAFFIC_CLEANUP_CACHE_STORE', 'file'),
        'checkout_lock_ttl_seconds' => 120,
        'checkout_lock_wait_seconds' => 5,
    ],

    'crypto_direct' => [
        'expires_minutes' => (int) env('CRYPTO_DIRECT_EXPIRES_MINUTES', 10),
        'grace_minutes' => (int) env('CRYPTO_DIRECT_GRACE_MINUTES', 2),
        'recovery_hours' => (int) env('CRYPTO_DIRECT_RECOVERY_HOURS', 24),
        'self_service_verify_minutes' => (int) env('CRYPTO_DIRECT_SELF_SERVICE_VERIFY_MINUTES', 60),
        'unique_max' => (int) env('CRYPTO_DIRECT_UNIQUE_MAX', 9999),
        'networks' => [
            'usdttrc20' => [
                'token' => 'USDT',
                'label' => 'USDT Tron (TRC20)',
                'short_label' => 'USDT TRC20',
                'address' => env('CRYPTO_TRC20_ADDRESS'),
                'contract' => env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
                'api_url' => env('TRONGRID_URL', 'https://api.trongrid.io'),
                'api_key' => env('TRONGRID_API_KEY'),
                'decimals' => 6,
                'binance_network' => env('BINANCE_TRC20_NETWORK', 'TRX'),
            ],
            'usdtbsc' => [
                'token' => 'USDT',
                'label' => 'USDT BNB Smart Chain (BEP20)',
                'short_label' => 'USDT BEP20',
                'address' => env('CRYPTO_BEP20_ADDRESS'),
                'contract' => env('BSC_USDT_CONTRACT', '0x55d398326f99059fF775485246999027B3197955'),
                'rpc_url' => env('BSC_RPC_URL', 'https://bsc-rpc.publicnode.com'),
                'rpc_scan_blocks' => (int) env('BSC_RPC_SCAN_BLOCKS', 40000),
                'rpc_chunk_blocks' => (int) env('BSC_RPC_CHUNK_BLOCKS', 3000),
                'rpc_block_seconds' => (float) env('BSC_RPC_BLOCK_SECONDS', 0.4),
                'rpc_confirmations' => (int) env('BSC_RPC_CONFIRMATIONS', 5),
                'rpc_overlap_blocks' => (int) env('BSC_RPC_OVERLAP_BLOCKS', 1000),
                'decimals' => 18,
                'binance_network' => env('BINANCE_BEP20_NETWORK', 'BSC'),
            ],
            'usdcbsc' => [
                'token' => 'USDC',
                'label' => 'USDC BNB Smart Chain (BEP20)',
                'short_label' => 'USDC BEP20',
                'address' => env('CRYPTO_USDC_BEP20_ADDRESS') ?: env('CRYPTO_BEP20_ADDRESS'),
                'contract' => env('BSC_USDC_CONTRACT', '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d'),
                'rpc_url' => env('BSC_RPC_URL', 'https://bsc-rpc.publicnode.com'),
                'rpc_scan_blocks' => (int) env('BSC_RPC_SCAN_BLOCKS', 40000),
                'rpc_chunk_blocks' => (int) env('BSC_RPC_CHUNK_BLOCKS', 3000),
                'rpc_block_seconds' => (float) env('BSC_RPC_BLOCK_SECONDS', 0.4),
                'rpc_confirmations' => (int) env('BSC_RPC_CONFIRMATIONS', 5),
                'rpc_overlap_blocks' => (int) env('BSC_RPC_OVERLAP_BLOCKS', 1000),
                'decimals' => 18,
                'binance_network' => env('BINANCE_USDC_BEP20_NETWORK') ?: env('BINANCE_BEP20_NETWORK', 'BSC'),
            ],
        ],
    ],

    'binance' => [
        'deposit_fallback' => [
            'enabled' => (bool) env('BINANCE_DEPOSIT_FALLBACK', false),
            'primary' => (bool) env('BINANCE_DEPOSIT_PRIMARY', false),
            'api_key' => env('BINANCE_API_KEY'),
            'api_secret' => env('BINANCE_API_SECRET'),
            'base_url' => env('BINANCE_API_URL', 'https://api.binance.com'),
            'recv_window' => (int) env('BINANCE_RECV_WINDOW', 5000),
        ],
        'pay' => [
            'enabled' => (bool) env('BINANCE_PAY_ENABLED', false),
            'pay_id' => env('BINANCE_PAY_ID'),
            'qr_content' => env('BINANCE_PAY_QR_CONTENT'),
            'qr_contents' => [
                'USDT' => env('BINANCE_PAY_USDT_QR_CONTENT'),
                'USDC' => env('BINANCE_PAY_USDC_QR_CONTENT'),
            ],
            'token' => strtoupper((string) env('BINANCE_PAY_TOKEN', 'USDT')),
            'expires_minutes' => (int) env('BINANCE_PAY_EXPIRES_MINUTES', 10),
            'grace_minutes' => (int) env('BINANCE_PAY_GRACE_MINUTES', 2),
            'recovery_hours' => (int) env('BINANCE_PAY_RECOVERY_HOURS', 24),
            'self_service_verify_minutes' => (int) env('BINANCE_PAY_SELF_SERVICE_VERIFY_MINUTES', 60),
            'scan_cooldown_seconds' => (int) env('BINANCE_PAY_SCAN_COOLDOWN_SECONDS', 20),
            'unique_max' => (int) env('BINANCE_PAY_UNIQUE_MAX', 9999),
            'api_key' => env('BINANCE_PAY_API_KEY') ?: env('BINANCE_API_KEY'),
            'api_secret' => env('BINANCE_PAY_API_SECRET') ?: env('BINANCE_API_SECRET'),
            'base_url' => env('BINANCE_API_URL', 'https://api.binance.com'),
            'recv_window' => (int) env('BINANCE_RECV_WINDOW', 5000),
        ],
    ],

    'brmods' => [
        'reset_url' => env('BRMODS_RESET_URL', 'https://brmods.net/api/reset.php'),
        'api_key' => env('BRMODS_API_KEY'),
        'product_slug' => env('BRMODS_PRODUCT_SLUG', 'br-mods-pc'),
        'cooldown_hours' => (int) env('BRMODS_RESET_COOLDOWN_HOURS', 24),
        'connect_timeout_seconds' => (int) env('BRMODS_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (int) env('BRMODS_TIMEOUT_SECONDS', 15),
        'pending_timeout_minutes' => (int) env('BRMODS_PENDING_TIMEOUT_MINUTES', 2),
    ],

    'xgteam' => [
        'reset_url' => env('XGTEAM_RESET_URL', 'https://xgteam.pythonanywhere.com/resethwid'),
        'secret' => env('XGTEAM_RESET_SECRET'),
        'product_slug' => env('XGTEAM_PRODUCT_SLUG', 'xg-team'),
        'cooldown_hours' => (int) env('XGTEAM_RESET_COOLDOWN_HOURS', 48),
        'connect_timeout_seconds' => (int) env('XGTEAM_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (int) env('XGTEAM_TIMEOUT_SECONDS', 15),
        'pending_timeout_minutes' => (int) env('XGTEAM_PENDING_TIMEOUT_MINUTES', 2),
    ],

];
