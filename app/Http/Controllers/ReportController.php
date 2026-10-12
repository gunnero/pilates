<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Support\Audit;
use App\Support\Lifecycle;
use App\Support\OperationsDirectory;
use App\Support\Permission;
use App\Support\Reporting\Report;
use App\Support\Reporting\ReportExport;
use App\Support\Reporting\ReportFilters;
use App\Support\Reporting\ReportScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class ReportController
{
    public function show(ReportRequest $request, TenantContext $context): JsonResponse|BinaryFileResponse
    {
        $actor = MemberAccountController::actor($request);
        $input = $request->validated();
        $export = isset($input['format']);
        $filters = new ReportFilters($input, $context->organization()->timezone);
        ReportScope::authorize($context, $actor, $input['report']);
        $fingerprint = ReportScope::fingerprint($context, $actor);
        $path = null;
        $started = microtime(true);
        $memory = memory_get_usage(true);
        memory_reset_peak_usage();
        try {
            $data = OperationsDirectory::snapshot(function () use ($context, $actor, $filters, $export, &$path, $input, $memory) {
                // Bound even pathological SQL plans. SET LOCAL ends with this read transaction.
                DB::statement("SET LOCAL statement_timeout = '15000ms'");
                $data = (new Report($context, $actor, $filters))->result($export);
                abort_if(memory_get_peak_usage(true) - $memory > ReportExport::MAX_MEMORY, 422, __('reporting.limit'));
                $data['meta']['correlation_id'] = (string) Str::uuid7();
                if ($export) {
                    $path = ReportExport::write($data, $input['format']);
                }

                return $data;
            });
            abort_if(microtime(true) - $started > ReportExport::MAX_SECONDS || memory_get_peak_usage(true) - $memory > ReportExport::MAX_MEMORY, 422, __('reporting.limit'));
            Lifecycle::run($context, $actor, Permission::ReportsView, function () use ($context, $actor, $input, $fingerprint, $export, $data) {
                ReportScope::authorize($context, $actor, $input['report']);
                abort_unless(hash_equals($fingerprint, ReportScope::fingerprint($context, $actor)), 409, __('reporting.changed'));
                Audit::record($context->organization(), $actor, $export ? 'report.export_prepared' : 'report.viewed', 'organization', $context->organization()->public_id, ['version' => Report::VERSION, 'report' => $input['report'], 'filters' => $input, 'timezone' => $data['meta']['timezone'], 'currency' => 'MKD', 'as_of' => $data['meta']['as_of'], 'rows' => count($data['rows']), 'correlation_id' => $data['meta']['correlation_id']]);
                if ($export) {
                    Audit::record($context->organization(), $actor, 'report.download_started', 'organization', $context->organization()->public_id, ['correlation_id' => $data['meta']['correlation_id'], 'format' => $input['format'], 'rows' => count($data['rows'])]);
                }
            });
            if ($path !== null) {
                $mime = $input['format'] === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

                return response()->download($path, 'gt-'.$input['report'].'.'.$input['format'], ['Content-Type' => $mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Report-As-Of' => $data['meta']['as_of']])->deleteFileAfterSend(true);
            }

            return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
        } catch (Throwable $e) {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
    }
}
