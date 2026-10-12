<?php

namespace Tests\Feature;

use App\Models\TrainingSession;
use App\Support\Reporting\ReportExport;
use App\Support\Reporting\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ReportingTest extends TestCase
{
    use DatabaseMigrations, \Tests\Support\ReportingFixtures;

    public function test_all_reports_and_current_snapshots_reconcile_without_domain_writes(): void
    {
        [$o,$u,$a,$id,$m] = $this->reportFixture();
        $url = $this->base($o);
        $s = TrainingSession::where('public_id', $id)->firstOrFail();
        $this->travelTo($s->starts_at);
        $this->postJson("$url/sessions/$id/attendance", ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $this->travelTo($s->ends_at->addMinute());
        $before = DB::table('cash_payments')->get()->toJson();
        $base = "$url/reports?from=2026-09-01&until=2026-09-30&report=";
        $r = $this->getJson($base.'revenue')->assertOk()->assertJsonPath('data.totals.net_minor', '4000')->assertJsonCount(30, 'data.rows')->assertJsonPath('data.comparison.totals.net_minor', '0');
        $this->assertSame(4000, array_sum(array_column($r->json('data.rows'), 'net_minor')));
        $this->getJson($base.'settlement')->assertOk()->assertJsonPath('data.totals.balance_minor', '6000')->assertJsonPath('data.comparison', null);
        $this->getJson($base.'activity')->assertOk()->assertJsonPath('data.totals.eligible', 1)->assertJsonPath('data.totals.participated', 1);
        $this->getJson($base.'expiry')->assertOk()->assertJsonPath('data.totals.memberships', 1);
        foreach (['attendance', 'occupancy', 'cancellations', 'popularity'] as $report) {
            $this->getJson($base.$report)->assertOk()->assertJsonPath('data.totals.present', 1)->assertJsonPath('data.totals.expected', 1)->assertJsonPath('data.totals.held_capacity', 12);
        }
        $this->assertSame($before, DB::table('cash_payments')->get()->toJson());
        $this->assertSame(8, DB::table('audit_events')->where('action', 'report.viewed')->count());
    }

    public function test_later_reversal_restates_original_period_and_exports_real_bytes(): void
    {
        [$o,$u,$a,$id,$m,$payment] = $this->reportFixture();
        $url = $this->base($o);
        $this->travelTo(CarbonImmutable::parse('2026-10-10T12:00:00Z'));
        $this->postJson("$url/cash-payments/$payment/reverse", ['reason' => 'mistake'])->assertStatus(422);
        $this->postJson("$url/cash-payments/$payment/reverse", ['reason' => 'wrong_amount'])->assertOk();
        $base = "$url/reports?report=revenue&from=2026-09-01&until=2026-09-30";
        $this->getJson($base)->assertOk()->assertJsonPath('data.totals.gross_minor', '4000')->assertJsonPath('data.totals.reversed_minor', '4000')->assertJsonPath('data.totals.net_minor', '0');
        foreach (['csv', 'xlsx'] as $format) {
            $response = $this->get($base.'&view=details&format='.$format)->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            if ($format === 'csv') {
                $stream = fopen($path, 'rb');
                $header = fgetcsv($stream, escape: '');
                $meta = fgetcsv($stream, escape: '');
                $row = fgetcsv($stream, escape: '');
                fclose($stream);
                $this->assertContains("'+План", $row);
                $this->assertSame('comparison_metadata', end($header));
                $this->assertSame('metadata', $meta[0]);
            } else {
                $zip = new \ZipArchive;
                $this->assertTrue($zip->open($path));
                $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
                $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
                $this->assertStringContainsString('+План', $xml);
                $this->assertStringNotContainsString('<f>', $xml);
                $this->assertStringContainsString('<pane', $xml);
                $zip->close();
            }
            $parser = new Process(['python3', base_path('tests/Support/parse-report-export.py'), $path, $format]);
            $parser->mustRun();
            $parsed = json_decode($parser->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $parsed['rows']);
            $this->assertSame('0', $parsed['net_minor']);
            unlink($path);
        }
    }

    public function test_permissions_foreign_filters_and_invalid_bounds_fail_closed(): void
    {
        [$o,$u,$a] = $this->reportFixture();
        $base = $this->base($o).'/reports?report=';
        foreach (['revenue&from=2026-01-01&until=2027-01-02', 'revenue&until=2026-02-01', 'revenue&timezone=UTC', 'attendance&per_page=101', 'attendance&top=51', 'settlement&group_id='.(string) Str::uuid7(), 'revenue&from=2026-02-30&until=2026-03-02'] as $query) {
            $this->getJson($base.$query)->assertUnprocessable();
        }
        $this->getJson($base.'revenue&plan_id='.(string) Str::uuid7())->assertNotFound();
        $a->forceFill(['role' => 'trainer'])->save();
        $this->getJson($base.'revenue')->assertForbidden();
        $this->getJson($base.'activity')->assertForbidden();
        $this->getJson($base.'attendance')->assertOk();
        $a->forceFill(['permissions' => ['studio.access', 'attendance.view', 'sessions.view']])->save();
        $this->getJson($base.'attendance')->assertForbidden();
    }

    public function test_dst_and_formula_controls_are_explicit(): void
    {
        $f = new ReportFilters(['from' => '2026-03-29', 'until' => '2026-03-29'], 'Europe/Skopje');
        $this->assertSame(23.0, CarbonImmutable::parse($f->start())->diffInHours(CarbonImmutable::parse($f->end())));
        $f = new ReportFilters(['from' => '2026-10-25', 'until' => '2026-10-25'], 'Europe/Skopje');
        $this->assertSame(25.0, CarbonImmutable::parse($f->start())->diffInHours(CarbonImmutable::parse($f->end())));
        foreach (['=1', '+cmd', '-cmd', '@SUM', "\t=1", "\r+1", " \n@SUM", '"quoted"'] as $text) {
            $safe = ReportExport::csv($text);
            $this->assertSame($text === '"quoted"' ? $text : "'".$text, $safe);
        }
        $this->assertSame('-100', ReportExport::csv(-100));
    }
}
