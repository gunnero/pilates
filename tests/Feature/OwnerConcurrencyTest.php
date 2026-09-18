<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\OrganizationFixtures;
use Tests\TestCase;

class OwnerConcurrencyTest extends TestCase
{
    use DatabaseMigrations, OrganizationFixtures;

    public static function transitions(): array
    {
        return [['revoked'], ['suspended'], ['demote']];
    }

    #[DataProvider('transitions')]
    public function test_competing_owner_removals_leave_one_owner(string $transition): void
    {
        $org = Organization::create(['name' => 'Race Studio']);
        $workers = [];
        DB::beginTransaction();
        try {
            // Seed committed fixtures before holding the coordination lock.
            DB::commit();
            foreach ([1, 2] as $index) {
                $user = User::factory()->create();
                $membership = $this->membership($user, $org);
                $workers[] = new Process([PHP_BINARY, base_path('tests/Support/membership-worker.php'), $org->public_id, $user->public_id, $membership->public_id, $transition], base_path(), null, null, 15);
            }
            DB::beginTransaction();
            Organization::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach ($workers as $worker) {
                $worker->start();
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE application_name LIKE 'gt-race-%' AND wait_event_type = 'Lock'")->count;
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both real PostgreSQL connections must contend for the organization lock.');
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $statuses[] = trim($worker->getOutput());
            }
            sort($statuses);
            $this->assertSame(['200', '409'], $statuses);
            $this->assertSame(1, OrganizationMembership::query()->where('organization_id', $org->id)->where('role', 'owner')->where('status', 'active')->count());
            $this->assertSame(1, DB::table('audit_events')->where('organization_id', $org->id)->where('action', $transition === 'demote' ? 'staff.role_changed' : 'membership.'.$transition)->count());
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
