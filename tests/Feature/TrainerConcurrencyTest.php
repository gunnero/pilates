<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class TrainerConcurrencyTest extends TestCase
{
    use DatabaseMigrations, OrganizationFixtures;

    public function test_two_postgresql_enablements_create_one_profile_and_one_audit_event(): void
    {
        $org = Organization::create(['name' => 'Race fixture']);
        $user = User::factory()->create();
        $access = $this->membership($user, $org);
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach ([1, 2] as $i) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/trainer-worker.php'), $org->public_id, $user->public_id, $access->public_id, (string) $i], base_path(), null, null, 15);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE application_name LIKE 'gt-trainer-race-%' AND wait_event_type = 'Lock'")->count;
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both real connections must contend for the organization lock.');
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $statuses[] = trim($worker->getOutput());
            }
            sort($statuses);
            $this->assertSame(['201', '409'], $statuses);
            $this->assertDatabaseCount('trainer_profiles', 1);
            $this->assertDatabaseCount('audit_events', 1);
            $this->assertDatabaseHas('audit_events', ['action' => 'trainer.enabled']);
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
