<?php

namespace App\Support\Reporting;

use App\Models\User;
use App\Support\SessionDirectory;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Report
{
    public const VERSION = 'GT017.1';

    public function __construct(private TenantContext $context, private User $actor, public ReportFilters $filters) {}

    /** @return array<string,mixed> */
    public function result(bool $export = false): array
    {
        $name = $this->filters->input['report'];
        ReportScope::authorize($this->context, $this->actor, $name, ($this->filters->input['view'] ?? '') === 'details');
        $query = new ReportQuery($this->context, $this->actor, $this->filters);
        $query->validateReferences();
        $data = $this->summary($query);
        $previous = $this->filters->previous();
        $comparison = null;
        if ($previous !== null && ! in_array($name, ['settlement', 'expiry'], true)) {
            $prior = (new self($this->context, $this->actor, $previous))->summary(new ReportQuery($this->context, $this->actor, $previous));
            $changes = [];
            foreach ($data['totals'] as $key => $value) {
                if ($name === 'activity' && $key !== 'participated') {
                    continue;
                }
                if (! is_numeric($value) || ! is_numeric($prior['totals'][$key] ?? null)) {
                    continue;
                }
                $before = $prior['totals'][$key];
                $changes[$key] = ['current' => $value, 'previous' => $before, 'absolute' => str_ends_with($key, '_minor') ? (string) ((int) $value - (int) $before) : $value - $before, 'relative_percent' => $before > 0 ? round(($value - $before) / $before * 100, 2) : null, 'percentage_points' => str_ends_with($key, '_rate') ? $value - $before : null];
            }
            $comparison = ['from' => $previous->from->toDateString(), 'until' => $previous->until->toDateString(), 'totals' => $prior['totals'], 'rows' => $prior['rows'], 'changes' => $changes];
        }
        if (($this->filters->input['view'] ?? 'summary') === 'details') {
            $detail = $this->detail($query);
            $total = (clone $detail)->count();
            abort_if($export && $total > 50000, 422, __('reporting.limit'));
            $size = $export ? max(1, $total) : (int) ($this->filters->input['per_page'] ?? 25);
            $page = $export ? 1 : (int) ($this->filters->input['page'] ?? 1);
            $data['rows'] = $this->rows($detail->orderBy('id')->offset(($page - 1) * $size)->limit($size)->get()->all());
            $data['pagination'] = ['total' => $total, 'page' => $page, 'last_page' => max(1, (int) ceil($total / $size))];
        }
        $data['columns'] = array_keys($data['rows'][0] ?? $this->emptyRow($name));
        $data['comparison'] = $comparison;
        $data['options'] = $this->options($query);
        $data['meta'] = ['version' => self::VERSION, 'report' => $name, 'view' => $this->filters->input['view'] ?? 'summary', 'organization' => $this->context->organization()->public_id, 'filters' => $this->filters->input, 'from' => $this->filters->from->toDateString(), 'until' => $this->filters->until->toDateString(), 'as_of' => $this->filters->clock->toIso8601String(), 'today' => $this->filters->today(), 'timezone' => $this->filters->zone, 'currency' => 'MKD', 'money_unit' => 'minor_100', 'basis' => $name === 'revenue' ? 'restated_original_payment_period' : (in_array($name, ['settlement', 'expiry'], true) ? 'current_snapshot' : ($name === 'activity' ? 'current_population_period_participation' : 'occurrence_start_current_outcome')), 'comparison_period' => $comparison === null ? null : ['from' => $comparison['from'], 'until' => $comparison['until']], 'interval' => '[local midnight from, local midnight after until)', 'week_starts' => 'Monday', 'freshness' => 'request_snapshot', 'historical_snapshot_available' => false, 'bounds' => ['days' => 366, 'export_rows' => 50000, 'export_bytes' => 26214400], 'limitations' => ['MKD_only', 'no_pending_cash_or_partial_refund', 'no_historical_member_snapshot', 'retained_session_assignment', 'current_final_outcome']];

        return $data;
    }

    /** @return array<string,mixed> */
    private function summary(ReportQuery $query): array
    {
        $name = $this->filters->input['report'];
        if ($name === 'revenue') {
            $base = $query->cash();
            $totals = ['payments' => 0, 'gross_minor' => 0, 'reversed_minor' => 0, 'net_minor' => 0];
            $bucket = $this->filters->bucket('paid_at');
            $rows = DB::query()->fromSub($base, 'cash')->selectRaw("$bucket as period, count(*) as payments, sum(gross_minor) as gross_minor, sum(reversed_minor) as reversed_minor, sum(net_minor) as net_minor", [$this->filters->zone])->groupBy('period')->orderBy('period')->get()->keyBy('period');
            $series = [];
            foreach ($this->filters->buckets() as $b) {
                $series[] = $b + (array) ($rows->get($b['period']) ?? ['payments' => 0, 'gross_minor' => '0', 'reversed_minor' => '0', 'net_minor' => '0']);
            }

            foreach ($series as $row) {
                foreach (array_keys($totals) as $key) {
                    $value = (string) $row[$key];
                    $maximum = (string) (PHP_INT_MAX - $totals[$key]);
                    abort_if(strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0), 422, __('reporting.limit'));
                    $sum = $totals[$key] + (int) $row[$key];
                    $totals[$key] = $sum;
                }
            }

            return ['totals' => $this->normalize($totals), 'rows' => $this->rows($series)];
        }
        if (in_array($name, ['settlement', 'expiry'], true)) {
            $base = $query->periods();
            $key = $name === 'settlement' ? 'payment_state' : 'expiry';
            $money = $name === 'settlement' ? ', coalesce(sum(agreed_price_minor),0) as agreed_minor, coalesce(sum(paid_minor),0) as paid_minor, coalesce(sum(balance_minor),0) as balance_minor, coalesce(sum(greatest(balance_minor,0)),0) as outstanding_minor' : '';
            $totals = (array) DB::query()->fromSub(clone $base, 'm')->selectRaw('count(*) as memberships, count(distinct studio_member_id) as members'.$money)->first();
            $rows = DB::query()->fromSub($base, 'm')->select($key)->selectRaw('count(*) as memberships, count(distinct studio_member_id) as members'.$money)->groupBy($key)->orderBy($key)->get()->all();

            return ['totals' => $this->normalize($totals), 'rows' => $this->rows($rows)];
        }
        if ($name === 'activity') {
            $base = $query->members();
            $totals = (array) DB::query()->fromSub(clone $base, 'm')->selectRaw('count(*) as members, count(*) FILTER (WHERE eligible) as eligible, count(*) FILTER (WHERE NOT eligible) as ineligible, count(*) FILTER (WHERE participated) as participated, count(*) FILTER (WHERE NOT participated) as not_participated')->first();
            $rows = DB::query()->fromSub($base, 'm')->select('status')->selectRaw('count(*) as members, count(*) FILTER (WHERE eligible) as eligible, count(*) FILTER (WHERE participated) as participated')->groupBy('status')->orderBy('status')->get()->all();

            return ['totals' => $this->normalize($totals), 'rows' => $this->rows($rows)];
        }
        $base = $query->sessions();
        $aggregate = "count(*) as sessions, count(*) FILTER (WHERE s.status='cancelled') as cancelled, count(*) FILTER (WHERE held) as held, coalesce(sum(s.capacity) FILTER (WHERE s.status='scheduled'),0) as capacity, coalesce(sum(reserved) FILTER (WHERE s.status='scheduled'),0) as reserved, coalesce(sum(waiting) FILTER (WHERE s.status='scheduled'),0) as waiting, coalesce(sum(s.capacity) FILTER (WHERE held),0) as held_capacity, coalesce(sum(reserved) FILTER (WHERE held),0) as expected, coalesce(sum(present) FILTER (WHERE held),0) as present, coalesce(sum(absent) FILTER (WHERE held),0) as absent, coalesce(sum(no_show) FILTER (WHERE held),0) as no_show, coalesce(sum(pending) FILTER (WHERE held),0) as pending, coalesce(sum(reservation_cancelled),0) as reservation_cancelled";
        $totals = $this->rates($this->normalize((array) DB::query()->fromSub(clone $base, 's')->selectRaw($aggregate)->first()));
        $participation = DB::query()->fromSub(clone $base, 's')->join('attendance_records as ar', function ($j) {
            $j->on('ar.training_session_id', '=', 's.id')->on('ar.organization_id', '=', 's.organization_id');
        })->where('held', true)->where('ar.status', 'present');
        $totals['unique_participants'] = (clone $participation)->distinct()->count('ar.studio_member_id');
        $eventSeries = [];
        if ($name === 'cancellations') {
            $eventScope = SessionDirectory::query($this->context, $this->actor)->withoutEagerLoads()->select('training_sessions.id');
            foreach (['group_id' => 'group', 'trainer_id' => 'trainer'] as $key => $relation) {
                if (isset($this->filters->input[$key])) {
                    $eventScope->whereHas($relation, fn ($q) => $q->where('public_id', $this->filters->input[$key]));
                }
            }
            $eventBucket = $this->filters->bucket('cancelled_at');
            $eventSeries['session_cancellation_events'] = (clone $eventScope)->select([])->selectRaw("$eventBucket as period, count(*) as events", [$this->filters->zone])->where('cancelled_at', '>=', $this->filters->start())->where('cancelled_at', '<', $this->filters->end())->groupBy('period')->pluck('events', 'period');
            $eventSeries['reservation_cancellation_events'] = DB::table('reservations')->where('organization_id', $this->context->organization()->id)->whereIn('training_session_id', $eventScope)->where('cancelled_at', '>=', $this->filters->start())->where('cancelled_at', '<', $this->filters->end())->selectRaw("$eventBucket as period, count(*) as events", [$this->filters->zone])->groupBy('period')->pluck('events', 'period');
            foreach ($eventSeries as $key => $values) {
                $totals[$key] = (int) $values->sum();
            }
        }
        $bucket = $this->filters->bucket('starts_at');
        $series = DB::query()->fromSub(clone $base, 's')->selectRaw("$bucket as period, $aggregate", [$this->filters->zone])->groupBy('period')->orderBy('period')->get()->keyBy('period');
        $rows = [];
        foreach ($this->filters->buckets() as $b) {
            $rows[] = $b + $this->rates($this->normalize((array) ($series->get($b['period']) ?? array_fill_keys(['sessions', 'cancelled', 'held', 'capacity', 'reserved', 'waiting', 'held_capacity', 'expected', 'present', 'absent', 'no_show', 'pending', 'reservation_cancelled'], 0))));
        }
        foreach ($rows as &$row) {
            foreach ($eventSeries as $key => $values) {
                $row[$key] = (int) $values->get($row['period'], 0);
            }
        }
        unset($row);
        $dimensions = [];
        foreach (['group' => 'training_group_id', 'trainer' => 'trainer_profile_id', 'slot' => null] as $dimension => $column) {
            $table = $dimension === 'group' ? 'training_groups' : 'trainer_profiles';
            $ranked = DB::query()->fromSub(clone $base, 's');
            if ($column !== null) {
                $ranked->join("$table as d", function ($j) use ($column) {
                    $j->on('d.id', '=', "s.$column")->on('d.organization_id', '=', 's.organization_id');
                })->selectRaw('d.public_id as dimension_id, max(s.'.($dimension === 'group' ? 'group_name' : 'trainer_name').') as label')->groupBy('d.public_id');
            } else {
                $ranked->selectRaw("to_char(s.starts_at AT TIME ZONE ?, 'ID HH24') as dimension_id, to_char(s.starts_at AT TIME ZONE ?, 'ID HH24') as label", [$this->filters->zone, $this->filters->zone])->groupBy('dimension_id', 'label');
            }
            $unique = clone $participation;
            if ($column !== null) {
                $unique->join("$table as d", function ($j) use ($column) {
                    $j->on('d.id', '=', "s.$column")->on('d.organization_id', '=', 's.organization_id');
                })->select('d.public_id as dimension_id')->groupBy('d.public_id');
            } else {
                $unique->selectRaw("to_char(s.starts_at AT TIME ZONE ?, 'ID HH24') as dimension_id", [$this->filters->zone])->groupBy('dimension_id');
            }
            $uniqueCounts = $unique->selectRaw('count(distinct ar.studio_member_id) as unique_participants')->pluck('unique_participants', 'dimension_id');
            // Qualify status after joining a group/trainer with its own status.
            $ranked->selectRaw($aggregate);
            $rank = $this->filters->input['rank'] ?? 'present';
            $top = (int) ($this->filters->input['top'] ?? 10);
            $found = $ranked->orderByDesc($rank)->orderBy('dimension_id')->limit($top + 1)->get();
            $dimensions[$dimension] = ['rows' => array_map(fn ($r) => $this->rates($this->normalize((array) $r)) + ['unique_participants' => (int) $uniqueCounts->get($r->dimension_id, 0)], $found->take($top)->all()), 'truncated' => $found->count() > $top];
        }

        if ($name === 'popularity') {
            $rows = [];
            foreach ($dimensions as $dimension => $entries) {
                foreach ($entries['rows'] as $row) {
                    $rows[] = ['dimension' => $dimension] + $row;
                }
            }
        }

        return ['totals' => $totals, 'rows' => $rows, 'dimensions' => $dimensions];
    }

    /** @return array<string,mixed> */
    private function options(ReportQuery $q): array
    {
        $result = [];
        if (in_array($this->filters->input['report'], ['revenue', 'settlement', 'expiry'], true)) {
            $values = DB::table('membership_plans')->where('organization_id', $this->context->organization()->id)->orderBy('id')->limit(101)->get(['public_id as id', 'name as label']);
            $result['plan_id'] = ['rows' => $values->take(100)->all(), 'truncated' => $values->count() > 100];
        } elseif ($this->filters->input['report'] !== 'activity') {
            foreach (['group_id' => ['training_groups', 'training_group_id', 'group_name'], 'trainer_id' => ['trainer_profiles', 'trainer_profile_id', 'trainer_name']] as $key => [$table,$column,$label]) {
                $values = DB::query()->fromSub($q->sessions(), 's')->join("$table as d", function ($j) use ($column) {
                    $j->on('d.id', '=', "s.$column")->on('d.organization_id', '=', 's.organization_id');
                })->select('d.public_id as id')->selectRaw("max(s.$label) as label")->groupBy('d.public_id')->orderBy('d.public_id')->limit(101)->get();
                $result[$key] = ['rows' => $values->take(100)->all(), 'truncated' => $values->count() > 100];
            }
        }

        return $result;
    }

    private function detail(ReportQuery $q): Builder
    {
        return match ($this->filters->input['report']) {
            'revenue' => $q->cash(),
            'settlement' => DB::query()->fromSub($q->periods(), 'p')->select(['id', 'public_id', 'member_id', 'member_name', 'plan_name', 'starts_on', 'ends_on', 'state', 'payment_state', 'currency', 'agreed_price_minor', 'paid_minor', 'balance_minor']),
            'expiry' => DB::query()->fromSub($q->periods(), 'p')->select(['id', 'public_id', 'member_id', 'member_name', 'plan_name', 'starts_on', 'ends_on', 'state', 'expiry']),
            'activity' => $q->members(),
            default => DB::query()->fromSub($q->sessions(), 's')->select(['id', 'public_id', 'group_name', 'trainer_name', 'starts_at', 'ends_at', 'status', 'capacity', 'reserved', 'present', 'absent', 'no_show', 'pending', 'waiting', 'reservation_cancelled', 'held']),
        };
    }

    /** @param array<string,mixed> $r
     * @return array<string,mixed> */
    private function normalize(array $r): array
    {
        unset($r['id']);
        foreach ($r as $k => $v) {
            if ($v !== null && is_numeric($v) && ! in_array($k, ['public_id', 'member_id', 'dimension_id', 'label', 'member_name', 'plan_name', 'group_name', 'trainer_name', 'expiry'], true)) {
                $r[$k] = str_ends_with($k, '_minor') ? (string) $v : (int) $v;
            }
        }

        return $r;
    }

    /** @param array<int,mixed> $rows
     * @return list<array<string,mixed>> */
    private function rows(array $rows): array
    {
        return array_values(array_map(fn ($r) => $this->normalize((array) $r), $rows));
    }

    /** @param array<string,mixed> $r
     * @return array<string,mixed> */
    private function rates(array $r): array
    {
        $resolved = ($r['present'] ?? 0) + ($r['absent'] ?? 0) + ($r['no_show'] ?? 0);
        $r['resolved'] = $resolved;
        foreach (['attendance_rate' => ['present', $resolved], 'occupancy_rate' => ['reserved', $r['capacity'] ?? 0], 'utilization_rate' => ['present', $r['held_capacity'] ?? 0], 'no_show_rate' => ['no_show', $resolved], 'session_cancellation_rate' => ['cancelled', $r['sessions'] ?? 0]] as $key => [$num, $den]) {
            $r[$key] = $den > 0 ? round(($r[$num] ?? 0) * 100 / $den, 2) : null;
        }

        return $r;
    }

    /** @return array<string,mixed> */
    private function emptyRow(string $report): array
    {
        if (($this->filters->input['view'] ?? 'summary') === 'details') {
            $columns = match ($report) {
                'revenue' => ['public_id', 'paid_at', 'currency', 'plan_name', 'gross_minor', 'reversed_minor', 'net_minor'],
                'settlement' => ['public_id', 'member_id', 'member_name', 'plan_name', 'starts_on', 'ends_on', 'state', 'payment_state', 'currency', 'agreed_price_minor', 'paid_minor', 'balance_minor'],
                'expiry' => ['public_id', 'member_id', 'member_name', 'plan_name', 'starts_on', 'ends_on', 'state', 'expiry'],
                'activity' => ['public_id', 'status', 'member_name', 'eligible', 'participated'],
                default => ['public_id', 'group_name', 'trainer_name', 'starts_at', 'ends_at', 'status', 'capacity', 'reserved', 'present', 'absent', 'no_show', 'pending', 'waiting', 'reservation_cancelled', 'held'],
            };

            return array_fill_keys($columns, null);
        }

        return match ($report) {
            'settlement' => ['payment_state' => '', 'memberships' => 0], 'expiry' => ['expiry' => '', 'memberships' => 0], 'activity' => ['status' => '', 'members' => 0], default => ['period' => '']
        };
    }
}
