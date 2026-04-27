<?php

$emails = array_filter(array_map(
    fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('ADMIN_EMAILS', ''))
));

return [
    'emails' => array_values($emails),
];
