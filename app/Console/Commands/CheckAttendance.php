<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckAttendance extends Command
{
    protected $signature = 'gt:attendance:check';

    protected $description = 'Read-only attendance and capacity consistency counts';

    public function handle(): int
    {
        $queries = [
            'duplicates' => 'SELECT count(*) AS n FROM (SELECT 1 FROM attendance_records GROUP BY training_session_id, studio_member_id HAVING count(*) > 1) x',
            'present_cancelled_reservation' => "SELECT count(*) AS n FROM attendance_records a JOIN reservations r ON r.id=a.reservation_id WHERE a.status='present' AND r.status<>'active'",
            'cancelled_session' => "SELECT count(*) AS n FROM attendance_records a JOIN training_sessions s ON s.id=a.training_session_id WHERE s.status<>'scheduled'",
            'ownership' => 'SELECT count(*) AS n FROM attendance_records a JOIN reservations r ON r.id=a.reservation_id WHERE (a.organization_id,a.training_session_id,a.studio_member_id) IS DISTINCT FROM (r.organization_id,r.training_session_id,r.studio_member_id)',
            'capacity' => "SELECT count(*) AS n FROM training_sessions s WHERE (SELECT count(*) FROM reservations r WHERE r.training_session_id=s.id AND r.status='active')>s.capacity",
        ];
        $counts = [];
        foreach ($queries as $name => $sql) {
            $counts[$name] = (int) DB::selectOne($sql)->n;
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return array_sum($counts) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
