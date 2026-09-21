<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckReservations extends Command
{
    protected $signature = 'gt:reservations:check';

    protected $description = 'Read-only checks of allocation, FIFO provenance and permanent capacity; no PII output';

    public function handle(): int
    {
        $queries = [
            'capacity' => "SELECT count(*) AS n FROM training_sessions s WHERE s.status='scheduled' AND (SELECT count(*) FROM reservations r WHERE r.training_session_id=s.id AND r.status='active')>s.capacity",
            'duplicate_reservations' => "SELECT count(*) AS n FROM (SELECT training_session_id,studio_member_id FROM reservations WHERE status='active' GROUP BY 1,2 HAVING count(*)>1) x",
            'duplicate_waiting' => "SELECT count(*) AS n FROM (SELECT training_session_id,studio_member_id FROM waiting_list_entries WHERE status='waiting' GROUP BY 1,2 HAVING count(*)>1) x",
            'reserved_waiting' => "SELECT count(*) AS n FROM reservations r JOIN waiting_list_entries w ON r.training_session_id=w.training_session_id AND r.studio_member_id=w.studio_member_id WHERE r.status='active' AND w.status='waiting'",
            'promotion_provenance' => "SELECT count(*) AS n FROM waiting_list_entries w LEFT JOIN reservations r ON r.id=w.reservation_id AND r.training_session_id=w.training_session_id AND r.studio_member_id=w.studio_member_id AND r.organization_id=w.organization_id WHERE w.status='promoted' AND r.id IS NULL",
            'permanent_capacity' => "SELECT count(*) AS n FROM training_groups g JOIN organizations o ON o.id=g.organization_id WHERE EXISTS (SELECT 1 FROM permanent_spots boundary WHERE boundary.training_group_id=g.id AND boundary.status='active' AND (boundary.effective_until IS NULL OR boundary.effective_until >= (now() AT TIME ZONE o.timezone)::date) AND (SELECT count(*) FROM permanent_spots p WHERE p.training_group_id=g.id AND p.status='active' AND daterange(p.effective_from,p.effective_until,'[]') @> greatest(boundary.effective_from,(now() AT TIME ZONE o.timezone)::date))>g.capacity)",
            'released_recreated' => "SELECT count(*) AS n FROM reservations r JOIN permanent_spot_releases e ON e.training_session_id=r.training_session_id AND e.studio_member_id=r.studio_member_id WHERE r.source='permanent_spot' AND r.status='active'",
            'ended_permanent_future' => "SELECT count(*) AS n FROM reservations r JOIN permanent_spots p ON p.id=r.permanent_spot_id JOIN training_sessions s ON s.id=r.training_session_id WHERE r.status='active' AND s.status='scheduled' AND s.starts_at>now() AND (p.status<>'active' OR NOT (daterange(p.effective_from,p.effective_until,'[]') @> s.occurrence_date))",
            'tenant_ownership' => 'SELECT count(*) AS n FROM reservations r JOIN training_sessions s ON s.id=r.training_session_id JOIN studio_members m ON m.id=r.studio_member_id WHERE r.organization_id<>s.organization_id OR r.organization_id<>m.organization_id',
            'permanent_source_group' => 'SELECT count(*) AS n FROM reservations r JOIN permanent_spots p ON p.id=r.permanent_spot_id JOIN training_sessions s ON s.id=r.training_session_id WHERE p.training_group_id<>s.training_group_id',
        ];
        $counts = [];
        foreach ($queries as $key => $query) {
            $counts[$key] = (int) DB::selectOne($query)->n;
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return array_sum($counts) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
