<?php

use App\Models\OrganizationMembership;
use App\Support\OperationsDirectory;
use App\Support\Reporting\Report;
use App\Support\Reporting\ReportExport;
use App\Support\Reporting\ReportFilters;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Preservation check failed: '.$error::class.' ('.$error->getCode().')'.PHP_EOL);
    exit(1);
});
if ($app->configurationIsCached() || config('database.connections.pgsql.database') !== 'gt_test' || config('database.connections.pgsql.username') !== 'gt_test' || config('database.connections.pgsql.host') !== '127.0.0.1' || ! in_array((string) config('database.connections.pgsql.port'), ['55435', '55436'], true) || config('database.connections.pgsql.url')) {
    throw new RuntimeException('Only disposable GT017 gt_test clusters are accepted.');
}
$mode = $argv[1];
$snapshot = function (): array {
    $tables = DB::select("select tablename from pg_tables where schemaname='public' order by tablename");
    $data = [];
    foreach ($tables as $table) {
        $name = $table->tablename;
        // Names come only from the private schema catalog, never user input.
        $rows = DB::select('select row_to_json(t)::text as row from "'.str_replace('"', '""', $name).'" t order by row_to_json(t)::text');
        $data[$name] = ['count' => count($rows), 'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    $data['constraints'] = hash('sha256', json_encode(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where connamespace='public'::regnamespace order by conname, definition"), JSON_THROW_ON_ERROR));
    $data['ledger'] = DB::select('select organization_id, sum(amount_minor)::text as gross from cash_payments group by organization_id order by organization_id');
    $data['reversals'] = DB::select('select organization_id, sum(amount_minor)::text as reversed from payment_reversals group by organization_id order by organization_id');

    return $data;
};
$before = $mode === 'upgrade' ? $snapshot() : null;
if (Artisan::call('migrate', ['--force' => true]) !== 0 || DB::table('migrations')->count() !== 17) {
    throw new RuntimeException('Migration verification failed.');
}
if ($before !== null && json_encode($before) !== json_encode($snapshot())) {
    throw new RuntimeException('Upgrade changed canonical data, grants, constraints or ledger.');
}
$reports = 0;
if ($mode === 'upgrade') {
    if (DB::table('cash_payments')->count() === 0 || DB::table('payment_reversals')->count() === 0) {
        throw new RuntimeException('Preservation requires a complete nonempty financial fixture.');
    }
    $context = app(TenantContext::class);
    foreach (OrganizationMembership::where('role', 'owner')->where('status', 'active')->with(['user', 'organization'])->get() as $access) {
        $context->resolve($access->user, $access->organization->public_id);
        foreach (['revenue', 'settlement', 'activity', 'expiry', 'attendance', 'occupancy', 'cancellations', 'popularity'] as $name) {
            OperationsDirectory::snapshot(function () use ($context, $access, $name, &$reports) {
                $data = (new Report($context, $access->user, new ReportFilters(['report' => $name], $context->organization()->timezone)))->result(true);
                foreach (['csv', 'xlsx'] as $format) {
                    $path = ReportExport::write($data, $format);
                    unlink($path);
                }
                $reports++;
            });
        }
    }
    if ($reports !== 192) {
        throw new RuntimeException('Preservation did not cover every expected baseline owner/report.');
    }
    if (json_encode($before) !== json_encode($snapshot())) {
        throw new RuntimeException('Reporting changed canonical data or ledger.');
    }
}
echo json_encode(['result' => 'passed', 'mode' => $mode, 'migrations' => 17, 'strict_base' => 'a7a3f66ae9b7304bd2a3d1dc352de76745bb2f68', 'tables_hashed' => $before === null ? 0 : count($before) - 3, 'report_snapshots' => $reports, 'exports' => $reports * 2], JSON_THROW_ON_ERROR).PHP_EOL;
