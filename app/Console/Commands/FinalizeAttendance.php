<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\TrainingSession;
use App\Support\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FinalizeAttendance extends Command
{
    protected $signature = 'gt:attendance:finalize {organization} {--from=} {--until=}';

    protected $description = 'Finalize unmarked active reservations for at most 200 ended sessions in a bounded tenant date range';

    public function handle(): int
    {
        $v = Validator::make(['organization' => $this->argument('organization'), 'from' => $this->option('from'), 'until' => $this->option('until')], ['organization' => 'required|uuid', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        if (CarbonImmutable::parse($v['from'])->diffInDays(CarbonImmutable::parse($v['until'])) >= 31) {
            $this->error('Range must contain at most 31 dates.');

            return self::FAILURE;
        }
        $result = DB::transaction(function () use ($v) {
            $o = Organization::query()->where('public_id', $v['organization'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            $sessions = TrainingSession::query()->where('organization_id', $o->id)->where('status', 'scheduled')->where('ends_at', '<=', now())->whereBetween('local_date', [$v['from'], $v['until']])->orderBy('id')->limit(201)->get();
            if ($sessions->count() > 200) {
                return null;
            }
            $count = 0;
            foreach ($sessions as $s) {
                $count += Attendance::finalize($s);
            }

            return ['sessions' => $sessions->count(), 'created' => $count];
        }, 3);
        if ($result === null) {
            $this->error('More than 200 sessions; use a smaller date range. No changes made.');

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
