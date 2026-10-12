<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

final class ReportingConcurrencyTest extends TestCase
{
    use DatabaseMigrations, ReportingFixtures;

    public static function cases(): array
    {
        $cases = [];
        for ($i = 0; $i < 5; $i++) {
            foreach (['payment', 'reversal', 'attendance', 'renewal', 'export_insert', 'revocation', 'cross_tenant'] as $case) {
                $cases[] = [$case];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_real_snapshot_overlaps_canonical_writes(string $case): void
    {
        File::ensureDirectoryExists(base_path('.local/gt017'), 0700);
        [$o,$u,$a,$sid,$m,$payment] = $this->reportFixture();
        $base = $this->base($o);
        $clock = '2026-09-23T12:00:00Z';
        $this->travelTo(CarbonImmutable::parse($clock));
        $report = $case === 'attendance' ? 'attendance' : ($case === 'renewal' ? 'activity' : 'revenue');
        $export = in_array($case, ['reversal', 'export_insert', 'revocation', 'cross_tenant'], true);
        $url = "$base/reports?report=$report&from=2026-09-01&until=2026-09-30&comparison=none".($export ? '&view=details&format=csv' : '');
        $barrier = base_path('.local/gt017/race-'.Str::uuid7());
        $worker = new Process([PHP_BINARY, base_path('tests/Support/report-worker.php'), $u->public_id, $url, $barrier, $clock], base_path(), null, null, 30);
        $worker->start();
        try {
            $deadline = microtime(true) + 10;
            while (! is_file($barrier.'.ready')) {
                if (! $worker->isRunning() || microtime(true) > $deadline) {
                    $this->fail('Worker did not establish snapshot: '.$worker->getErrorOutput());
                }usleep(10000);
            }
            $ready = json_decode(file_get_contents($barrier.'.ready'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('repeatable read', $ready['isolation']);
            $this->assertNotEmpty($ready['snapshot']);
            if (in_array($case, ['payment', 'export_insert'], true)) {
                $membership = DB::table('member_memberships')->where('organization_id', $o->id)->value('public_id');
                $this->postJson($base.'/cash-payments', ['membership_id' => $membership, 'amount_minor' => 2000, 'paid_at' => '2026-09-10T10:00:00Z', 'request_id' => (string) Str::uuid7()])->assertCreated();
            } elseif ($case === 'reversal') {
                $this->postJson("$base/cash-payments/$payment/reverse", ['reason' => 'wrong_amount'])->assertOk();
            } elseif ($case === 'attendance') {
                $this->postJson("$base/sessions/$sid/attendance/correct", ['member_id' => $m->public_id, 'status' => 'present', 'reason' => 'late_confirmation'])->assertOk();
            } elseif ($case === 'renewal') {
                $plan = DB::table('membership_plans')->where('organization_id', $o->id)->value('public_id');
                $this->postJson($base.'/memberships', ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2026-10-01'])->assertCreated();
            } elseif ($case === 'revocation') {
                // The production lifecycle writer re-resolves authority under the organization lock.
                $this->patchJson("$base/staff/$a->public_id", ['permissions' => array_values(array_diff($a->permissions, ['reports.view']))])->assertOk();
            } elseif ($case === 'cross_tenant') {
                [$other,$otherUser] = $this->sessionFixture();
                $this->travelTo(CarbonImmutable::parse($clock));
                $this->getJson($this->base($other).'/reports?report=revenue')->assertOk()->assertJsonPath('data.totals.net_minor', '0');
                $this->actingAs($u);
            }
            file_put_contents($barrier.'.release', '1');
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if ($case === 'revocation') {
                $this->assertContains($result['status'], [403, 409]);
                $this->getJson($url)->assertForbidden();
            } else {
                $this->assertSame(200, $result['status']);
                if ($export) {
                    $this->assertCount(1, $result['rows']);
                    $headers = $result['header'];
                    $index = array_search('net_minor', $headers, true);
                    $this->assertNotFalse($index);
                    $this->assertSame('4000', $result['rows'][0][$index]);
                } elseif ($case === 'attendance') {
                    $this->assertSame(0, $result['body']['data']['totals']['present']);
                } elseif ($case === 'renewal') {
                    $this->assertSame(1, $result['body']['data']['totals']['eligible']);
                } else {
                    $this->assertSame('4000', $result['body']['data']['totals']['net_minor']);
                }
            }
            $this->artisan('gt:finance:check')->assertSuccessful();
            $this->artisan('gt:attendance:check')->assertSuccessful();
            $this->artisan('gt:reservations:check')->assertSuccessful();
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }foreach (['.ready', '.release'] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }
}
