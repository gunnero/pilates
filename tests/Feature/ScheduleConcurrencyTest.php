<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class ScheduleConcurrencyTest extends TestCase
{
    use DatabaseMigrations, OrganizationFixtures;

    public static function races(): array
    {
        return [['duplicate'], ['group_overlap'], ['trainer_overlap']];
    }

    #[DataProvider('races')]
    public function test_competing_schedule_writes_cannot_both_commit(string $race): void
    {
        $org = Organization::create(['name' => 'Race fixture']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $org);
        $location = $this->location($org);
        $trainer = new TrainerProfile;
        $trainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $membership->id, 'status' => 'active'])->save();
        $groups = [];
        foreach ([1, 2] as $i) {
            $g = new TrainingGroup;
            $g->forceFill(['organization_id' => $org->id, 'location_id' => $location->id, 'primary_trainer_id' => $trainer->id, 'name' => 'Race '.$i, 'capacity' => 12, 'status' => 'active'])->save();
            $groups[] = $g;
        }
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach ([0, 1] as $i) {
                $group = $race === 'trainer_overlap' ? $groups[$i] : $groups[0];
                $worker = new Process([PHP_BINARY, base_path('tests/Support/schedule-worker.php'), $org->public_id, $user->public_id, $group->public_id, $i === 1 && $race !== 'duplicate' ? '18:30' : '18:00', (string) $i], base_path(), null, null, 15);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE application_name LIKE 'gt-schedule-race-%' AND wait_event_type = 'Lock'")->count;
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked);
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $statuses[] = trim($worker->getOutput());
            }
            sort($statuses);
            $this->assertSame(['201', '409'], $statuses);
            $this->assertDatabaseCount('schedule_rules', 1);
            $this->assertDatabaseCount('audit_events', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
