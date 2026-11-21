<?php

return [
    'app_brand' => 'NexaTrade Enterprise Engine',

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', 'SB-Mid-server-NexaTradeEnterpriseKey2026'),
        'client_key' => env('MIDTRANS_CLIENT_KEY', 'SB-Mid-client-NexaTradeEnterpriseKey2026'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
    ],

    'digiflazz' => [
        'username' => env('DIGIFLAZZ_USERNAME', 'nexatrade_h2h_prod'),
        'api_key' => env('DIGIFLAZZ_API_KEY', 'digiflazz_secret_key_nexatrade_2026'),
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
    'invoice_expiry_minutes' => 30,
    'auto_complete_hours' => 24,
    'sla_badge_threshold_minutes' => 10.00,
];
