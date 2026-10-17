<?php

/*
 * Financial data is encrypted per owner. Each owner has a random data key, stored in
 * `users.data_key` wrapped (encrypted) by this master key. The master key never lives
 * in the database or in backups: keep it in a root-only file outside the project and
 * keep two offline copies. Losing it makes every owner's data unreadable.
 *
 * Generate one with `php artisan vault:key`.
 */
return [
    // Path to a file containing the master key ("base64:..."). Preferred in production.
    'master_key_file' => env('DATA_MASTER_KEY_FILE'),

    // Or the key itself. Used when no file is set (local development, tests).
    'master_key' => env('DATA_MASTER_KEY'),

    // During rotation: the old key(s), comma separated, until `vault:rotate` has run.
    'previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('DATA_MASTER_KEY_PREVIOUS', ''))))),
];
