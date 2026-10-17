<?php

/*
 * Keeping the server healthy: the nightly off-server backup and the health checks.
 * See README → "Backups" and "Monitoring".
 */
return [
    'backup' => [
        // Encrypts every backup file (separate from the data master key). Off when empty.
        // Generate with `php artisan finance:backup-key`.
        'key' => env('BACKUP_KEY'),

        // Where the files go: the "backups" disk in config/filesystems.php (BACKUP_DRIVER=s3 for off-server).
        'disk' => 'backups',

        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

        // Use `mariadb-dump` on MariaDB, or a full path.
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    ],

    // Admins get these alerts; this address too, if set.
    'alert_email' => env('OPS_ALERT_EMAIL'),

    // Minutes without a heartbeat before a part counts as stopped.
    'stale_minutes' => [
        'scheduler' => 10,
        'queue' => 20,
        'backup' => 26 * 60,
    ],

    // While a problem lasts, repeat its alert at most this often.
    'realert_hours' => 12,
];
