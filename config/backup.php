<?php

return [
    'enabled' => (bool) env('BACKUP_ENABLED', true),
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),
    'retention_days' => max(1, (int) env('BACKUP_RETENTION_DAYS', 14)),
];
