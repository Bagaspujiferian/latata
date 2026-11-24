<?php

$midtransIsProduction = (bool) env('MIDTRANS_IS_PRODUCTION', false);

return [
    'app_brand' => 'NexaTrade Enterprise Engine',
    'demo_switcher_enabled' => (bool) env('NEXATRADE_DEMO_SWITCHER', env('APP_ENV', 'local') !== 'production'),

    'midtrans' => [
        'payment_driver' => env('NEXATRADE_PAYMENT_DRIVER', 'simulation'),
        'is_production' => $midtransIsProduction,
        'merchant_id' => env('MIDTRANS_MERCHANT_ID', 'G000000000'),
        'server_key' => env('MIDTRANS_SERVER_KEY', 'SB-Mid-server-NexaTradeEnterpriseKey2026'),
        'client_key' => env('MIDTRANS_CLIENT_KEY', 'SB-Mid-client-NexaTradeEnterpriseKey2026'),
        'snap_api_url' => $midtransIsProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        'core_api_url' => $midtransIsProduction
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com',
        'snap_js_url' => $midtransIsProduction
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js',
    ],

    'digiflazz' => [
        'username' => env('DIGIFLAZZ_USERNAME', 'nexatrade_h2h_prod'),
        'api_key' => env('DIGIFLAZZ_API_KEY', 'digiflazz_secret_key_nexatrade_2026'),
        'webhook_secret' => env('DIGIFLAZZ_WEBHOOK_SECRET', 'digiflazz_webhook_secret_nexatrade_2026'),
        'endpoint' => env('DIGIFLAZZ_ENDPOINT', 'https://api.digiflazz.com/v1/transaction'),
        'simulate_live_dispatch' => (bool) env('DIGIFLAZZ_SIMULATE', true),
    ],

    'gateway_fees' => [
        'qris' => 1500.00,
        'bca_va' => 4000.00,
        'bri_va' => 4000.00,
        'gopay' => 2000.00,
        'wallet_full' => 0.00,
        'hybrid_wallet_qris' => 1500.00,
        'hybrid_wallet_bca_va' => 4000.00,
        'hybrid_wallet_bri_va' => 4000.00,
        'hybrid_wallet_gopay' => 2000.00,
    ],

    'withdrawal_admin_fee' => 2500.00,
    'withdrawal_min_amount' => 10000.00,
    'withdrawal_maker_checker_threshold' => (float) env('NEXATRADE_WITHDRAWAL_MAKER_CHECKER_THRESHOLD', 5000000),
    'invoice_expiry_minutes' => 30,
    'flash_sale_invoice_ttl_minutes' => (int) env('NEXATRADE_FLASH_SALE_TTL_MINUTES', 15),
    'max_unpaid_invoices_per_buyer' => (int) env('NEXATRADE_MAX_UNPAID_INVOICES', 3),
    'auto_complete_hours' => 24,
    'sla_badge_threshold_minutes' => 10.00,
];
