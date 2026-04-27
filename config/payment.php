<?php

return [
    'crypto_buyer_fee_rate' => (float) env('AKSA_CRYPTO_BUYER_FEE_RATE', 0.02),
    'crypto_buyer_fee_minimum' => (float) env('AKSA_CRYPTO_BUYER_FEE_MINIMUM', 0.10),
    'midtrans_customer_fee_percentage' => (int) env('AKSA_MIDTRANS_CUSTOMER_FEE_PERCENTAGE', 50),
];
