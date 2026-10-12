<?php

namespace Tests\Feature;

use App\Support\Reporting\ReportExport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

final class ReportingSecurityTest extends TestCase
{
    use DatabaseMigrations,ReportingFixtures;

    public function test_cross_tenant_profiles_options_exports_and_tokens_fail_closed(): void
    {
        [$o,$u,$a] = $this->reportFixture();
        [$foreign,$other] = $this->reportFixture();
        $this->actingAs($u);
        $this->getJson($this->base($foreign).'/reports?report=revenue&format=csv')->assertNotFound();
        $plan = DB::table('membership_plans')->where('organization_id', $foreign->id)->value('public_id');
        $this->getJson($this->base($o).'/reports?report=revenue&plan_id='.$plan)->assertNotFound();
        $this->getJson($this->base($o).'/reports?report=revenue')->assertOk()->assertJsonPath('data.totals.net_minor', '4000')->assertJsonCount(1, 'data.options.plan_id.rows');
        $memberToken = $u->createToken('Member', ['member.read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($memberToken)->getJson($this->base($o).'/reports?report=revenue')->assertForbidden();
    }

    public function test_audit_failure_prevents_file_release_and_cleans_private_output(): void
    {
        [$o] = $this->reportFixture();
        $before = glob(ReportExport::directory().'/report-*') ?: [];
        $hash = hash('sha256', DB::table('cash_payments')->orderBy('id')->get()->toJson());
        DB::unprepared("CREATE OR REPLACE FUNCTION gt_report_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic audit failure'; END $$; CREATE TRIGGER gt_report_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt_report_audit_failure();");
        foreach (['csv', 'xlsx'] as $format) {
            $this->getJson($this->base($o).'/reports?report=revenue&format='.$format)->assertStatus(500);
        }
        $this->assertSame($before, glob(ReportExport::directory().'/report-*') ?: []);
        $this->assertSame($hash, hash('sha256', DB::table('cash_payments')->orderBy('id')->get()->toJson()));
    }

    public function test_export_row_limit_rejects_without_truncation_or_artifacts(): void
    {
        [$o] = $this->reportFixture();
        DB::insert("INSERT INTO studio_members (public_id,organization_id,first_name,last_name,status,preferred_locale,digital_access_mode) SELECT uuidv7(),?,'Synthetic',n::text,'active','mk','offline' FROM generate_series(1,50000)n", [$o->id]);
        $this->getJson($this->base($o).'/reports?report=activity&view=details&format=csv')->assertUnprocessable();
        $this->assertSame(0, DB::table('audit_events')->where('action', 'report.export_prepared')->count());
    }

    public function test_cleanup_only_removes_expired_private_artifacts(): void
    {
        if (! is_dir(ReportExport::directory())) {
            mkdir(ReportExport::directory(), 0700, true);
        }
        $old = ReportExport::directory().'/report-old-fixture';
        $new = ReportExport::directory().'/report-new-fixture';
        file_put_contents($old, 'synthetic');
        file_put_contents($new, 'synthetic');
        touch($old, time() - 86401);
        try {
            $this->assertGreaterThanOrEqual(1, ReportExport::cleanup());
            $this->assertFileDoesNotExist($old);
            $this->assertFileExists($new);
        } finally {
            if (is_file($old)) {
                unlink($old);
            }if (is_file($new)) {
                unlink($new);
            }
        }
    }

    public function test_empty_rates_and_custom_calendar_comparison_are_not_fake_zero_percent(): void
    {
        [$o] = $this->reportFixture();
        $url = $this->base($o).'/reports?report=occupancy&from=2024-02-29&until=2024-02-29&comparison=custom&compare_from=2023-02-28&compare_until=2023-02-28';
        $this->getJson($url)->assertOk()->assertJsonPath('data.totals.occupancy_rate', null)->assertJsonPath('data.totals.attendance_rate', null)->assertJsonPath('data.comparison.from', '2023-02-28')->assertJsonPath('data.comparison.changes.reserved.relative_percent', null);
    }

    public function test_export_preserves_large_exact_money_and_literal_identity_bytes(): void
    {
        $exact = '900719925474099301';
        $report = ['columns' => ['public_id', 'member_name', 'net_minor'], 'rows' => [['public_id' => '000001', 'member_name' => "\t=SUM(1,2) Кирилица", 'net_minor' => $exact]], 'totals' => ['net_minor' => $exact], 'meta' => ['report' => 'revenue', 'currency' => 'MKD'], 'comparison' => null];
        foreach (['csv', 'xlsx'] as $format) {
            memory_reset_peak_usage();
            $path = ReportExport::write($report, $format);
            try {
                $parser = new Process(['python3', base_path('tests/Support/parse-report-export.py'), $path, $format]);
                $parser->mustRun();
                $parsed = json_decode($parser->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($exact, $parsed['net_minor']);
                $this->assertSame(1, $parsed['rows']);
                if ($format === 'csv') {
                    $stream = fopen($path, 'rb');
                    fgetcsv($stream, escape: '');
                    fgetcsv($stream, escape: '');
                    $row = fgetcsv($stream, escape: '');
                    fclose($stream);
                    $this->assertSame('000001', $row[1]);
                    $this->assertSame("'\t=SUM(1,2) Кирилица", $row[2]);
                } else {
                    $zip = new \ZipArchive;
                    $this->assertTrue($zip->open($path));
                    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                    $this->assertStringContainsString('000001', $xml);
                    $this->assertStringContainsString($exact, $xml);
                    $this->assertStringNotContainsString('<f>', $xml);
                    $zip->close();
                }
            } finally {
                unlink($path);
            }
        }
    }
}
