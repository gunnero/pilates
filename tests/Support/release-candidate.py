"""Private production-mode candidate and synthetic recovery; never uses existing env/DBs.

Requires native PostgreSQL 18, locked dependencies and built web assets. TLS is
loopback only; the temporary processes/clusters are stopped on every exit. Kept
artifacts/configuration are private under ignored .local/gt017-closeout/release.
"""
import base64
import hashlib
import http.cookiejar
import http.client
import http.server
import ssl
import threading
import io
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
WORK = ROOT / ('.local/gt017-closeout/release-' + time.strftime('%Y%m%dT%H%M%S', time.gmtime()))
BIN = Path('/usr/lib/postgresql/18/bin')
os.umask(0o077)
if WORK.exists():
    raise SystemExit('Refusing to overwrite existing release evidence; select a fresh evidence directory.')
WORK.mkdir(parents=True, mode=0o700)
for port in [55437, 55438, 8017, 8018]:
    with socket.socket() as listener:
        listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        listener.bind(('127.0.0.1', port))
candidate = WORK / 'candidate'
candidate.mkdir()
source = {}
files = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).decode().split('\0')
for name in filter(None, files):
    path = ROOT / name
    if not path.is_file() or name.startswith('.env'):
        continue
    source[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    target = candidate / name
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(path, target)
shutil.copytree(ROOT / 'public/build', candidate / 'public/build')
for name in ['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app/private', 'bootstrap/cache', '.local']:
    (candidate / name).mkdir(parents=True, exist_ok=True)
log = (WORK / 'execution.log').open('w')


def run(args, cwd=candidate, env=None, output=False, data=None):
    result = subprocess.run([str(x) for x in args], cwd=cwd, env=env, input=data, text=True,
                            stdout=subprocess.PIPE if output else log, stderr=log, check=True)
    return result.stdout


def sql(port, statement):
    return run([BIN / 'psql', '-X', '-h', '127.0.0.1', '-p', port, '-d', 'gt_test', '-At', '-v', 'ON_ERROR_STOP=1'], output=True, data=statement)


def digest(port):
    tables = sql(port, "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename;").splitlines()
    rows = {}
    for table in tables:
        assert table.replace('_', '').isalnum()
        raw = sql(port, f'SELECT row_to_json(t)::text FROM "{table}" t ORDER BY row_to_json(t)::text;')
        rows[table] = hashlib.sha256(raw.encode()).hexdigest()
    constraints = json.loads(sql(port, "SELECT json_agg(t ORDER BY relname,conname) FROM (SELECT c.conname,r.relname,c.contype,c.convalidated,c.condeferrable,c.condeferred,pg_get_expr(c.conbin,c.conrelid) AS expression,pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class r ON r.oid=c.conrelid WHERE c.connamespace='public'::regnamespace) t;"))
    for constraint in constraints:
        if constraint['contype'] == 'c':
            table = constraint['relname']
            assert table.replace('_', '').isalnum()
            # PostgreSQL dump/restore normalizes casts/parentheses. Compare the
            # planner's normalized boolean expression, retaining type/validation flags.
            plan = json.loads(sql(port, f'EXPLAIN (VERBOSE, FORMAT JSON, COSTS OFF) SELECT ({constraint["expression"]}) FROM "{table}" LIMIT 0;'))
            constraint['definition'] = plan[0]['Plan']['Output']
        constraint.pop('expression')
    rows['constraints'] = hashlib.sha256(json.dumps(constraints, sort_keys=True).encode()).hexdigest()
    rows['triggers'] = hashlib.sha256(sql(port, "SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE NOT tgisinternal ORDER BY tgname,pg_get_triggerdef(oid);").encode()).hexdigest()
    rows['permissions'] = hashlib.sha256(sql(port, "SELECT relname,pg_get_userbyid(relowner),relacl FROM pg_class WHERE relnamespace='public'::regnamespace ORDER BY relname;").encode()).hexdigest()
    rows['database_privileges'] = sql(port, "SELECT pg_get_userbyid(datdba),has_database_privilege('gt_test','gt_test','CONNECT'),has_database_privilege('gt_test','gt_test','CREATE'),has_database_privilege('gt_test','gt_test','TEMP') FROM pg_database WHERE datname='gt_test';")
    return rows


clusters = []
server = None
proxy = None
try:
    for port in [55437, 55438]:
        cluster = WORK / f'postgres-{port}'
        socket_dir = WORK / f'socket-{port}'
        socket_dir.mkdir()
        run([BIN / 'initdb', '-D', cluster, '--auth-local=trust', '--auth-host=trust', '--encoding=UTF8', '--locale=C.UTF-8'])
        run([BIN / 'pg_ctl', '-D', cluster, '-o', f'-p {port} -h 127.0.0.1 -k {socket_dir}', '-l', WORK / f'postgres-{port}.log', '-w', 'start'])
        clusters.append(cluster)
        run([BIN / 'psql', '-X', '-h', '127.0.0.1', '-p', port, '-d', 'postgres', '-v', 'ON_ERROR_STOP=1'], data='CREATE ROLE gt_test LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE; CREATE ROLE gt_migrate NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE; CREATE DATABASE gt_test OWNER gt_test; REVOKE ALL ON DATABASE gt_test FROM PUBLIC; GRANT CONNECT ON DATABASE gt_test TO gt_test;')
    values = {
        'APP_ENV': 'production', 'APP_DEBUG': 'false',
        'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'APP_URL': 'https://127.0.0.1:8017', 'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1',
        'DB_PORT': '55437', 'DB_DATABASE': 'gt_test', 'DB_USERNAME': 'gt_test', 'DB_PASSWORD': '',
        'CACHE_STORE': 'database', 'SESSION_DRIVER': 'database', 'SESSION_COOKIE': 'gt_private_candidate',
        'SESSION_SECURE_COOKIE': 'true', 'SANCTUM_STATEFUL_DOMAINS': '127.0.0.1:8017',
        'QUEUE_CONNECTION': 'database', 'MAIL_MAILER': 'array', 'LOG_CHANNEL': 'single',
        'NOTIFICATION_PUSH_ENABLED': 'false', 'NOTIFICATION_EMAIL_ENABLED': 'false',
    }
    clean_env = {key: value for key, value in os.environ.items() if not key.startswith(('APP_', 'DB_', 'DATABASE_', 'MAIL_', 'REDIS_', 'SESSION_', 'CACHE_', 'QUEUE_', 'NOTIFICATION_', 'EXPO_'))}
    env = clean_env | values
    run(['composer', 'install', '--no-interaction', '--prefer-dist'], env=env)
    seeded = run(['php', 'tests/browser/seed.php'], env=env | {'APP_ENV': 'e2e'}, output=True)
    assert 'GT_BROWSER_FIXTURE_COMPLETE' in seeded.splitlines(), 'Incomplete candidate fixture'
    for table in ['training_sessions', 'cash_payments', 'payment_reversals']:
        assert int(sql(55437, f'SELECT count(*) FROM {table};')) > 0, f'Empty required fixture: {table}'
    sql(55437, "UPDATE organization_memberships SET role='administrator' WHERE user_id=(SELECT id FROM users WHERE email='operations-admin-desktop@browser.example.test');")
    sql(55437, 'REASSIGN OWNED BY gt_test TO gt_migrate; GRANT CONNECT ON DATABASE gt_test TO gt_test; GRANT USAGE ON SCHEMA public TO gt_test; GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO gt_test; GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO gt_test; REVOKE INSERT,UPDATE,DELETE ON migrations FROM gt_test;')
    before = digest(55437)
    dump = WORK / 'synthetic-database.dump'
    run([BIN / 'pg_dump', '-h', '127.0.0.1', '-p', '55437', '-d', 'gt_test', '-Fc', '-f', dump])
    run([BIN / 'pg_restore', '--list', dump])
    sql(55438, 'ALTER DATABASE gt_test OWNER TO gt_migrate; GRANT CONNECT ON DATABASE gt_test TO gt_test;')
    run([BIN / 'pg_restore', '-h', '127.0.0.1', '-p', '55438', '-d', 'gt_test', '--role=gt_migrate', '--exit-on-error', dump])
    after = digest(55438)
    assert before == after, 'Restored hashes differ: ' + ','.join(key for key in before if before[key] != after.get(key))
    invalid_writes = ['UPDATE training_sessions SET capacity=0', "UPDATE training_sessions SET status='invalid'", 'UPDATE cash_payments SET amount_minor=0', "UPDATE training_sessions SET starts_at=(local_date + TIME '23:30') AT TIME ZONE timezone, ends_at=(local_date + 1 + TIME '00:30') AT TIME ZONE timezone"]
    for statement in invalid_writes:
        failures = []
        for port in [55437, 55438]:
            result = subprocess.run([str(BIN / 'psql'), '-X', '-h', '127.0.0.1', '-p', str(port), '-d', 'gt_test', '-v', 'ON_ERROR_STOP=1', '-v', 'VERBOSITY=sqlstate', '-c', 'BEGIN; ' + statement + '; ROLLBACK;'], capture_output=True, text=True)
            assert result.returncode != 0, 'Restored invariant accepted invalid write: ' + statement
            failures.append(result.stderr.strip())
        assert failures[0] == failures[1], 'Restored rejection changed'
    values['DB_PORT'] = '55438'
    env = clean_env | values
    (candidate / '.env').write_text('\n'.join(key + '=' + value for key, value in values.items()) + '\n')
    private = candidate / 'storage/app/private/recovery-control.bin'
    private.write_bytes(secrets.token_bytes(64))
    private_hash = hashlib.sha256(private.read_bytes()).hexdigest()
    config_hash = hashlib.sha256((candidate / '.env').read_bytes()).hexdigest()
    with tarfile.open(WORK / 'synthetic-private-backup.tar.gz', 'w:gz') as archive:
        archive.add(candidate / '.env', arcname='private.env')
        archive.add(private, arcname='private/recovery-control.bin')
    recovered = WORK / 'recovered-private'
    recovered.mkdir()
    with tarfile.open(WORK / 'synthetic-private-backup.tar.gz') as archive:
        archive.extractall(recovered, filter='data')
    assert hashlib.sha256((recovered / 'private.env').read_bytes()).hexdigest() == config_hash
    assert hashlib.sha256((recovered / 'private/recovery-control.bin').read_bytes()).hexdigest() == private_hash
    run(['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'], env=env)
    run(['php', 'artisan', 'config:cache'], env=env)
    run(['php', 'artisan', 'route:cache'], env=env)
    run(['php', 'artisan', 'view:cache'], env=env)
    run(['php', 'artisan', 'migrate:status', '--no-interaction'], env=env)
    run(['php', 'artisan', 'gt:finance:check'], env=env)
    run(['php', 'artisan', 'gt:attendance:check'], env=env)
    run(['php', 'artisan', 'gt:reservations:check'], env=env)
    run(['php', 'artisan', 'schedule:list'], env=env)
    run(['php', 'artisan', 'gt:notifications:queue'], env=env)
    run(['php', 'artisan', 'queue:work', 'database', '--queue=notifications', '--stop-when-empty', '--tries=1', '--timeout=60'], env=env)
    run(['php', 'artisan', 'gt:reports:cleanup'], env=env)
    # Ephemeral loopback TLS, not shared Caddy/DNS or a trusted machine-wide CA.
    certificate, key = WORK / 'localhost.crt', WORK / 'localhost.key'
    run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=IP:127.0.0.1', '-keyout', key, '-out', certificate])
    router = WORK / 'tls-router.php'
    router.write_text("<?php\n$path = realpath(__DIR__.'/candidate/public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));\nif ($path !== false && str_starts_with($path, __DIR__.'/candidate/public/') && is_file($path) && pathinfo($path, PATHINFO_EXTENSION) !== 'php') { return false; }\n// This private backend accepts only the local TLS gateway; no middleware is disabled.\n$_SERVER['HTTPS'] = 'on';\n$_SERVER['SERVER_PORT'] = '8017';\nrequire __DIR__.'/candidate/public/index.php';\n")
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8018', '-t', 'public', str(router)], cwd=candidate, env=env, stdout=log, stderr=log)
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', 8018), timeout=1):
                break
        except OSError:
            if server.poll() is not None:
                raise RuntimeError('Private PHP backend failed to start')
            time.sleep(.1)
    else:
        raise RuntimeError('Private PHP backend startup timed out')
    class Gateway(http.server.BaseHTTPRequestHandler):
        def log_message(self, *_args):
            pass
        def forward(self):
            connection = http.client.HTTPConnection('127.0.0.1', 8018, timeout=35)
            body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
            headers = {k: v for k, v in self.headers.items() if k.lower() not in ['connection', 'transfer-encoding']}
            connection.request(self.command, self.path, body=body or None, headers=headers)
            response = connection.getresponse()
            payload = response.read()
            self.send_response(response.status)
            for name, value in response.getheaders():
                if name.lower() not in ['connection', 'transfer-encoding', 'content-length']:
                    self.send_header(name, value)
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            try:
                self.wfile.write(payload)
            except (BrokenPipeError, ConnectionResetError):
                # A client may close after receiving headers; application assertions still fail normally.
                print('Private client closed response connection.', file=log)
            finally:
                connection.close()
        do_GET = forward
        do_POST = forward
    proxy = http.server.ThreadingHTTPServer(('127.0.0.1', 8017), Gateway)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(certificate, key)
    proxy.socket = tls.wrap_socket(proxy.socket, server_side=True)
    threading.Thread(target=proxy.serve_forever, daemon=True).start()
    trust = ssl.create_default_context(cafile=certificate)
    url = 'https://127.0.0.1:8017'
    for _ in range(100):
        try:
            with urllib.request.urlopen(url + '/up', timeout=1, context=trust) as health:
                assert health.status == 200
                health.read()
                break
        except (OSError, urllib.error.URLError):
            time.sleep(.1)
    else:
        raise RuntimeError('Private candidate health failed')
    fixture = json.loads((candidate / '.local/browser-report-fixture.json').read_text())['desktop']
    checks = []
    admin_fixture = json.loads((candidate / '.local/browser-operations-fixture.json').read_text())['desktop']
    member_fixture = json.loads((candidate / '.local/browser-member-fixture.json').read_text())['desktop']
    for identity, organization, expected_status, expected_net in [('reports-desktop', fixture['organization'], 200, '4000'), ('report-trainer-desktop', fixture['organization'], 403, None), ('operations-admin-desktop', admin_fixture['organization'], 200, '0'), ('member-desktop', fixture['organization'], 404, None)]:
        jar = http.cookiejar.CookieJar()
        client = urllib.request.build_opener(urllib.request.HTTPSHandler(context=trust), urllib.request.HTTPCookieProcessor(jar))

        def request(path, body=None, csrf=True):
            headers = {'Accept': 'application/json', 'Referer': url + '/', 'Origin': url, 'Accept-Language': 'en'}
            if body is not None:
                headers['Content-Type'] = 'application/json'
                if csrf:
                    headers['X-XSRF-TOKEN'] = urllib.parse.unquote(next(cookie.value for cookie in jar if cookie.name == 'XSRF-TOKEN'))
            req = urllib.request.Request(url + path, data=None if body is None else json.dumps(body).encode(), headers=headers)
            try:
                response = client.open(req, timeout=35)
            except urllib.error.HTTPError as error:
                response = error
            return response.status, response.headers, response.read()

        assert request('/sanctum/csrf-cookie')[0] == 204
        credentials = {'email': identity + '@browser.example.test', 'password': 'Browser-fixture-123!'}
        assert request('/auth/login', credentials, csrf=False)[0] == 419
        assert request('/auth/login', credentials)[0] in [200, 204]
        endpoint = '/api/v1/organizations/' + organization + '/reports?report=revenue&from=' + fixture['from'] + '&until=' + fixture['until']
        status, headers, body = request(endpoint)
        assert status == expected_status, (identity, status, expected_status)
        assert 'no-store' in headers.get('Cache-Control', '') and headers['X-Content-Type-Options'] == 'nosniff'
        if expected_status == 200:
            assert json.loads(body)['data']['totals']['net_minor'] == expected_net
        if identity == 'member-desktop':
            assert request('/api/v1/member/organizations')[0] == 200
            assert request('/api/v1/member/organizations/' + member_fixture['second'] + '/profile')[0] == 200
        if identity == 'reports-desktop':
            for kind in ['csv', 'xlsx']:
                status, headers, body = request(endpoint + '&view=details&format=' + kind)
                assert status == 200
                output = WORK / ('recovered-report.' + kind)
                output.write_bytes(body)
                parsed = json.loads(run(['python3', ROOT / 'tests/Support/parse-report-export.py', output, kind], output=True))
                assert parsed['gross_minor'] == '6000' and parsed['reversed_minor'] == '2000' and parsed['net_minor'] == '4000'
        assert request('/auth/logout', {})[0] in [200, 204]
        assert request(endpoint)[0] == 401
        checks.append(identity + ': CSRF, login, current role/tenant scope, logout/revocation')
    probe = subprocess.run([str(BIN / 'psql'), '-X', '-h', '127.0.0.1', '-p', '55438', '-U', 'gt_test', '-d', 'gt_test', '-v', 'ON_ERROR_STOP=1', '-c', 'CREATE TABLE public.runtime_ddl_probe (id integer)'], stdout=log, stderr=log)
    assert probe.returncode != 0, 'Runtime role unexpectedly has schema creation authority'
    checks.append('runtime schema creation denied')
    page = urllib.request.urlopen(url + '/login', context=trust).read().decode()
    import re
    assets = re.findall(r'(?:src|href)="([^"]*/build/[^"]+)"', page)
    assert assets
    for asset in assets:
        with urllib.request.urlopen(urllib.parse.urljoin(url, asset), context=trust) as response:
            assert response.status == 200
            response.read()
    checks.append('built production CSS/JavaScript served from public only')
    # Package only PHP/runtime resources and built assets; Node/native/test trees stay out.
    artifact = WORK / 'candidate-runtime.tar.gz'
    include = ['app', 'bootstrap/app.php', 'bootstrap/providers.php', 'config', 'database/migrations', 'lang', 'public', 'resources/views', 'routes', 'artisan', 'composer.json', 'composer.lock', 'vendor']
    with tarfile.open(artifact, 'w:gz') as archive:
        for name in include:
            archive.add(candidate / name, arcname=name)
    restored_artifact = WORK / 'restored-artifact'
    restored_artifact.mkdir()
    with tarfile.open(artifact) as archive:
        assert not any('node_modules' in name or name.endswith('.map') or name.startswith('.env') for name in archive.getnames())
        archive.extractall(restored_artifact, filter='data')
    for name in ['artisan', 'composer.lock', 'public/build/manifest.json']:
        assert (candidate / name).read_bytes() == (restored_artifact / name).read_bytes()
    result = {'status': 'passed', 'source_head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(), 'source_manifest_sha256': hashlib.sha256(json.dumps(source, sort_keys=True).encode()).hexdigest(), 'scope': 'private loopback only; no public or native distribution', 'http_boundary': url, 'environment': 'production; debug false; cached config/routes/views; real CSRF; secure cookies; ephemeral loopback TLS', 'database': 'disposable gt_test/gt_test; ports 55437/55438; runtime DML and separate gt_migrate ownership', 'restored_tables': len(before) - 4, 'row_constraint_grant_hashes_equal': True, 'constraint_comparison': 'catalog identities/flags and planner-normalized CHECK expressions; trigger definitions; matching invalid-write SQLSTATE controls', 'private_configuration_and_file_restored': True, 'runtime_artifact_sha256': hashlib.sha256(artifact.read_bytes()).hexdigest(), 'database_backup_sha256': hashlib.sha256(dump.read_bytes()).hexdigest(), 'node_dependencies_in_runtime': False, 'external_transports': 'disabled; array mail', 'smoke_checks': checks, 'export_gross_minor': '6000', 'export_reversed_minor': '2000', 'export_net_minor': '4000'}
    (WORK / 'source-manifest.json').write_text(json.dumps(source, indent=2) + '\n')
    (WORK / 'result.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result, indent=2))
finally:
    if proxy is not None:
        proxy.shutdown()
        proxy.server_close()
    if server is not None:
        server.terminate()
        server.wait(timeout=10)
    for cluster in reversed(clusters):
        run([BIN / 'pg_ctl', '-D', cluster, '-m', 'fast', '-w', 'stop'])
    log.close()
