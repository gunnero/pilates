"""Two disposable private PostgreSQL clusters, each gt_test/gt_test. No shared DB changes."""
import base64, io, json, os, pathlib, secrets, shutil, subprocess, tarfile, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[2]
BASE = 'a7a3f66ae9b7304bd2a3d1dc352de76745bb2f68'
BIN = pathlib.Path('/usr/lib/postgresql/18/bin')
def run(args, **kwargs):
    return subprocess.run([str(x) for x in args], check=True, **kwargs)
with tempfile.TemporaryDirectory(prefix='gt017-preserve-', dir=ROOT/'.local') as work:
    work = pathlib.Path(work)
    base = work/'base'; base.mkdir()
    archive = subprocess.check_output(['git','archive', BASE], cwd=ROOT)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        tar.extractall(base, filter='data')
    for name in ['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','.local']:
        (base/name).mkdir(parents=True, exist_ok=True)
    for kind,port in [('fresh',55435),('upgrade',55436)]:
        cluster=work/kind; socket=work/(kind+'-socket');socket.mkdir()
        run([BIN/'initdb','-D',cluster,'--auth-local=trust','--auth-host=trust','--encoding=UTF8','--locale=C.UTF-8'], stdout=subprocess.DEVNULL)
        run([BIN/'pg_ctl','-D',cluster,'-o',f'-p {port} -h 127.0.0.1 -k {socket}','-l',work/(kind+'.log'),'-w','start'], stdout=subprocess.DEVNULL)
        try:
            run([BIN/'psql','-h','127.0.0.1','-p',port,'-d','postgres','-v','ON_ERROR_STOP=1'], input='CREATE ROLE gt_test LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE; CREATE DATABASE gt_test OWNER gt_test;', text=True, stdout=subprocess.DEVNULL)
            env=os.environ | {'APP_ENV':'e2e','APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'DB_CONNECTION':'pgsql','DB_HOST':'127.0.0.1','DB_PORT':str(port),'DB_DATABASE':'gt_test','DB_USERNAME':'gt_test','DB_PASSWORD':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','LOG_CHANNEL':'stderr'}
            if kind == 'upgrade':
                # A symlinked optimized vendor autoloader can resolve App classes
                # back into current source. Build the strict base's own autoloader.
                run(['composer','install','--no-interaction','--prefer-dist'], cwd=base, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                actual = subprocess.check_output(['php','-r', "require 'vendor/autoload.php'; echo (new ReflectionClass(App\\Models\\Organization::class))->getFileName();"], cwd=base, env=env, text=True)
                assert pathlib.Path(actual).resolve() == base/'app/Models/Organization.php', 'Base autoloader escaped strict source'
                # Instrument only a disposable copy of the historical fixture.
                # The base application, migrations and original seed stay unchanged.
                original = (base/'tests/browser/seed.php').read_text()
                seeded = original.replace("$app->make(Kernel::class)->bootstrap();", """$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Strict-base fixture setup failed: '.$error::class.' ('.$error->getCode().')'.PHP_EOL);
    exit(1);
});
require __DIR__.'/fixture-time.php';""")
                seeded = seeded.replace("'ends_at' => $start->addHour()", "'ends_at' => fixtureSessionEnd($start, $org->timezone)")
                seeded = seeded.replace("'ends_at' => $attendanceStart->addHour()", "'ends_at' => fixtureSessionEnd($attendanceStart, $org->timezone)")
                seeded = seeded.replace("'ends_at' => $s->ends_at->addHour()", "'ends_at' => fixtureSessionEnd(CarbonImmutable::instance($s->starts_at->addHour()), $org->timezone)")
                seeded = seeded.replace("$ended->ends_at = $start->subHours(2);", "$ended->ends_at = fixtureSessionEnd($start->subHours(3), $org->timezone);")
                seeded = seeded.replace("$cancelSession = $s->replicate(['public_id']);", "$cancelSession = $s->replicate(['public_id']);\n$cancelStart = CarbonImmutable::instance($s->starts_at->addHour())->max(CarbonImmutable::instance($s->ends_at));")
                seeded = seeded.replace("'starts_at' => $s->starts_at->addHour(), 'ends_at' => fixtureSessionEnd(CarbonImmutable::instance($s->starts_at->addHour()), $org->timezone), 'local_date' => $s->starts_at->addHour()->setTimezone($org->timezone)->toDateString()", "'starts_at' => $cancelStart, 'ends_at' => fixtureSessionEnd($cancelStart, $org->timezone), 'local_date' => $cancelStart->setTimezone($org->timezone)->toDateString()")
                seeded += '\necho "GT_BROWSER_FIXTURE_COMPLETE\\n";\n'
                (base/'tests/browser/seed-verified.php').write_text(seeded)
                shutil.copyfile(ROOT/'tests/browser/fixture-time.php', base/'tests/browser/fixture-time.php')
                result = subprocess.run(['php','tests/browser/seed-verified.php'], cwd=base, env=env, check=True, capture_output=True, text=True)
                assert 'GT_BROWSER_FIXTURE_COMPLETE' in result.stdout.splitlines(), 'Incomplete strict-base fixture'
                assert (base/'tests/browser/seed.php').read_text() == original

            run(['php',ROOT/'tests/Support/reporting-preservation.php',kind], cwd=ROOT, env=env)
        finally:
            run([BIN/'pg_ctl','-D',cluster,'-m','fast','-w','stop'], stdout=subprocess.DEVNULL)
