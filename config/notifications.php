<?php

return [
    'push_enabled' => env('NOTIFICATION_PUSH_ENABLED', false),
    'email_enabled' => env('NOTIFICATION_EMAIL_ENABLED', false),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
    'reminder_catchup_hours' => 2,
    'expiry_catchup_days' => 7,
    // Explicit operator-owned tenant UUIDs; no cron/worker is installed by GT-010.
    'scheduled_organizations' => [],
    'batch_size' => 100,
];
