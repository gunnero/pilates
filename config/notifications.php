<?php

return [
    'reminder_catchup_hours' => 2,
    'expiry_catchup_days' => 7,
    // Explicit operator-owned tenant UUIDs; no cron/worker is installed by GT-010.
    'scheduled_organizations' => [],
    'batch_size' => 100,
];
