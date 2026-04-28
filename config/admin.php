<?php

$emails = array_filter(array_map(
    fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('ADMIN_EMAILS', ''))
));

return [
    'emails' => array_values($emails),
    'low_stock_threshold' => max(0, (int) env('AKSA_LOW_STOCK_THRESHOLD', 3)),
];
