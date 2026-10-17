<?php

return [
    'parts' => [
        'backup' => 'Nightly backup',
        'queue' => 'Queue worker (emails and push)',
        'scheduler' => 'Scheduler (alerts, heir check)',
        'encryption' => 'Data encryption key',
        'failed_jobs' => 'Background jobs',
    ],
    'backup' => [
        'title' => ':app: the nightly backup failed',
        'body' => "Tonight's backup was not saved: :error. Until it works again, a server failure could lose recent data.",
    ],
    'queue' => [
        'title' => ':app: the queue worker has stopped',
        'body' => 'No background job has run for :minutes minutes, so alert emails, push notifications and heir emails are not being sent. Check it with `sudo supervisorctl status` and restart it with `sudo supervisorctl restart finance-queue:*`.',
    ],
    'encryption' => [
        'title' => ':app: the data master key cannot be read',
        'body' => 'The server cannot read the data master key, so financial data cannot be opened. Check DATA_MASTER_KEY_FILE and the file permissions.',
    ],
    'failed_jobs' => [
        'title' => ':app: :count background job(s) failed',
        'body' => ':count job(s) failed in the last 24 hours (for example an email that could not be sent). See them with `php artisan queue:failed` and retry with `php artisan queue:retry all`.',
    ],
    'recovered' => [
        'title' => ':app: :part is working again',
        'body' => ':part is back to normal. No action needed.',
    ],
    'health' => 'Live status: :url',
];
