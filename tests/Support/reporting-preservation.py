"""Two disposable private PostgreSQL clusters, each gt_test/gt_test. No shared DB changes."""
import base64, io, json, os, pathlib, secrets, subprocess, tarfile, tempfile
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
    (base/'vendor').symlink_to(ROOT/'vendor', target_is_directory=True)
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
                run(['php','tests/browser/seed.php'], cwd=base, env=env, stdout=subprocess.DEVNULL)
            run(['php',ROOT/'tests/Support/reporting-preservation.php',kind], cwd=ROOT, env=env)
        finally:
            run([BIN/'pg_ctl','-D',cluster,'-m','fast','-w','stop'], stdout=subprocess.DEVNULL)
