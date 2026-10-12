<?php

use Carbon\CarbonImmutable;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../browser/fixture-time.php';

$checks = 0;
foreach (['2026-10-12', '2026-03-29', '2026-10-25'] as $date) {
    for ($minute = 0; $minute < 1440; $minute += 15) {
        $start = CarbonImmutable::parse($date.'T00:00:00Z')->addMinutes($minute);
        $end = fixtureSessionEnd($start, 'Europe/Skopje');
        $localStart = $start->setTimezone('Europe/Skopje');
        $localEnd = $end->setTimezone('Europe/Skopje');
        if ($end->lte($start) || $start->diffInSeconds($end) > 18000
            || $localStart->toDateString() !== $localEnd->toDateString()
            || $localStart->format('H:i:s') >= $localEnd->format('H:i:s')) {
            throw new RuntimeException('Invalid studio-date fixture interval at '.$start->toIso8601String());
        }
        $checks++;
    }
}
$late = CarbonImmutable::parse('2026-10-12T23:30:00', 'Europe/Skopje');
if (fixtureSessionEnd($late, 'Europe/Skopje')->setTimezone('Europe/Skopje')->format('Y-m-d H:i:s') !== '2026-10-12 23:59:59') {
    throw new RuntimeException('Late fixture crossed studio midnight.');
}
echo json_encode(['result' => 'passed', 'fixture_intervals' => $checks, 'midnight_control' => 'passed'], JSON_THROW_ON_ERROR).PHP_EOL;
