<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class FinanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations,FinanceFixtures;

    public static function cases(): array
    {
        return [['reverse'], ['cancel'], ['overlap'], ['totals']];
    }

    #[DataProvider('cases')]
    public function test_competing_postgresql_financial_writers(string $case): void
    {
        [$org,$u,$a,$m,$plan,$base] = $this->financeFixture();
        $id = $this->assignFinance($base, $m, $plan);
        $pid = $this->postJson("$base/cash-payments", $this->payData($id, 100000))->assertCreated()->json('data.id');
        $reverse = ['POST', "/cash-payments/$pid/reverse", ['reason' => 'wrong_amount']];
        $assign = ['POST', '/memberships', ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2026-10-01']];
        $pay = ['POST', '/cash-payments', $this->payData($id, 50000)];
        $tasks = match ($case) {
            'reverse' => [$reverse, $reverse],'cancel' => [$pay, ['POST', "/memberships/$id/cancel", ['reason' => 'mistake']]],'overlap' => [$assign, $assign],'totals' => [$pay, $reverse]
        };
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach ($tasks as $i => $task) {
                $w = new Process([PHP_BINARY, base_path('tests/Support/finance-worker.php'), $u->public_id, $org->public_id, json_encode($task, JSON_THROW_ON_ERROR), (string) $i, '2026-09-24T12:00:00Z'], base_path(), null, null, 30);
                $w->start();
                $workers[] = $w;
            }
            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE application_name LIKE 'gt-finance-race-%' AND wait_event_type='Lock'")->n;
                if ($blocked === 2) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked);
            DB::commit();
            $statuses = [];
            foreach ($workers as $w) {
                $w->wait();
                $this->assertSame(0, $w->getExitCode(), $w->getErrorOutput());
                $status = trim($w->getOutput());
                $this->assertContains($status, ['200', '201', '409']);
                $statuses[] = $status;
            }
            $this->artisan('gt:finance:check')->assertSuccessful();
            if ($case === 'reverse') {
                $this->assertDatabaseCount('payment_reversals', 1);
                sort($statuses);
                $this->assertSame(['200', '409'], $statuses);
                $this->getJson("$base/memberships/$id")->assertJsonPath('data.net_paid_minor', 0);
            }
            if ($case === 'overlap') {
                $this->assertDatabaseCount('member_memberships', 2);
                sort($statuses);
                $this->assertSame(['201', '409'], $statuses);
            }
            if ($case === 'cancel') {
                $this->assertSame('200', $statuses[1]);
                $this->getJson("$base/memberships/$id")->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.net_paid_minor', $statuses[0] === '201' ? 150000 : 100000);
                $this->postJson("$base/cash-payments", $this->payData($id))->assertStatus(409);
            }
            if ($case === 'totals') {
                $this->assertSame(['201', '200'], $statuses);
                $this->getJson("$base/memberships/$id")->assertJsonPath('data.net_paid_minor', 50000)->assertJsonPath('data.balance_minor', 100000);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
        }
    }
}
