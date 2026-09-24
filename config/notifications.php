<?php

return [
    'reminder_lead_hours' => 24,
    'reminder_catchup_hours' => 2,
    'expiry_lead_days' => 7,
    'expiry_catchup_days' => 7,
    // Explicit operator-owned tenant UUIDs; no cron/worker is installed by GT-010.
    'scheduled_organizations' => [],
    'batch_size' => 100,
];
