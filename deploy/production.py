#!/usr/bin/env python3
"""Single-node Linux deployment. Run the trusted checkout as root, not the downloaded ZIP."""
import argparse
import fcntl
import grp
import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import zipfile
from pathlib import Path

TOOL = Path(__file__).resolve().parent
ETC = Path('/etc/cajeerengine-deploy')
STATE = Path('/var/lib/cajeerengine-deploy')
BASE = Path('/srv/cajeerengine')
APP = BASE / 'app'
GATE = STATE / 'maintenance'
WORKERS_ALLOWED = STATE / 'workers-enabled'
FPM_ALLOWED = STATE / 'fpm-enabled'
JOURNAL = STATE / 'deployment.json'
HEALTH_CACHE = Path('/var/cache/nginx/cajeerengine-health')
USER = 'cajeerengine'
GROUP = 'www-data'
FPM = 'cajeerengine-php.service'
WORKER = 'cajeerengine-worker.service'
SCHEDULER = 'cajeerengine-scheduler.service'
TIMER = 'cajeerengine-scheduler.timer'
SERVICES = (TIMER, SCHEDULER, WORKER, FPM)
PHP = '/usr/bin/php8.4'
FPM_BIN = '/usr/sbin/php-fpm8.4'
PHP_PREFLIGHT = """
$errors = [];
if (PHP_VERSION_ID < 80401 || PHP_VERSION_ID >= 90000) {
    $errors[] = "Unsupported PHP version: " . PHP_VERSION . " (required >=8.4.1 <9.0)";
}
$missing = array_filter(
    ["pdo_pgsql", "sodium", "mbstring", "fileinfo", "openssl", "phar", "gd"],
    static fn(string $name): bool => !extension_loaded($name)
);
if ($missing) {
    $errors[] = "Missing PHP extensions: " . implode(", ", $missing);
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
"""
MUTABLE = ('storage', 'bootstrap/cache', 'public/uploads', 'public/extensions')


def atomic(path, data, mode=0o644):
    path = Path(path)
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as f:
        tmp = Path(f.name)
        try:
            os.fchmod(f.fileno(), mode)
            f.write(data.encode() if isinstance(data, str) else data)
            f.flush(); os.fsync(f.fileno())
            os.replace(tmp, path)
            fd = os.open(path.parent, os.O_DIRECTORY)
            try: os.fsync(fd)
            finally: os.close(fd)
        finally:
            tmp.unlink(missing_ok=True)


def run(*args, input=None, capture=False, timeout=1800, env_extra=None):
    safe_env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C.UTF-8','TZ':'UTC'}
    if env_extra is not None:
        if set(env_extra) != {'PHP_INI_SCAN_DIR'} or not isinstance(env_extra['PHP_INI_SCAN_DIR'],str):
            raise ValueError('Unexpected operator subprocess environment')
        safe_env.update(env_extra)
    return subprocess.run([str(x) for x in args], input=input, text=True, check=True,
                          stdout=subprocess.PIPE if capture else None, timeout=timeout,
                          env=safe_env).stdout


def legacy_php_environment():
    # The signed 0.8.x updater launches its own PHP postcheck subprocess. A
    # parent -d auto_prepend_file does not reach that child; instead supply a
    # root-owned PHP scan dir for this one signed update/recovery operation.
    source=TOOL.parent/'core/Forum/Service/ForumSearchMaintenance.php'
    root_file(source)
    compat=STATE/'legacy-php-ini'
    secure_dir(compat)
    atomic(compat/'zz-ce-forum-fts.ini', 'auto_prepend_file='+str(source)+'\n')
    return {'PHP_INI_SCAN_DIR': ':'+str(compat)}


def app_run(*args, input=None, capture=False, legacy_compat=False):
    return run('runuser', '-u', USER, '-g', GROUP, '--', '/bin/sh', '-c',
               'umask 027; cd /srv/cajeerengine/app && exec "$@"', 'deploy', PHP, *args,
               input=input, capture=capture, timeout=None,
               env_extra=legacy_php_environment() if legacy_compat else None)


def secure_dir(path, mode=0o755):
    path.mkdir(parents=True, exist_ok=True, mode=mode)
    s=path.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid != 0 or s.st_mode & 0o022 or (mode==0o755 and s.st_mode & 0o005 != 0o005):
        raise ValueError(f'Not a root-controlled directory: {path}')


def root_file(path, private=False):
    s=path.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or s.st_mode & (0o077 if private else 0o022):
        raise ValueError(f'Unsafe operator file: {path}')
    return path


def config():
    c=json.loads(root_file(ETC/'site.json').read_text())
    if set(c) != {'domain','db_host','db_port','db_name','db_user','db_sslmode','admin_email'}:
        raise ValueError('Unexpected or missing site.json fields')
    if not isinstance(c['domain'],str) or not re.fullmatch(r'(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',c['domain']):
        raise ValueError('A lowercase DNS hostname is required')
    for k in ('db_host','db_name','db_user'):
        if not isinstance(c[k],str) or not re.fullmatch(r'[A-Za-z0-9_.-]+',c[k]): raise ValueError(k)
    if type(c['db_port']) is not int or not 1<=c['db_port']<=65535: raise ValueError('db_port')
    if c['db_name'] in ('postgres','template0','template1'): raise ValueError('Dedicated database required')
    if c['db_sslmode'] not in ('require','verify-ca','verify-full','disable'): raise ValueError('db_sslmode')
    if c['db_sslmode']=='disable' and c['db_host']!='127.0.0.1': raise ValueError('Unencrypted remote DB prohibited')
    if not isinstance(c['admin_email'],str) or not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+',c['admin_email']): raise ValueError('admin_email')
    return c


def gate_on():
    atomic(GATE,'maintenance\n')
    WORKERS_ALLOWED.unlink(missing_ok=True)
    FPM_ALLOWED.unlink(missing_ok=True)
    fd=os.open(GATE.parent,os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)


def record(state, **fields):
    atomic(JOURNAL,json.dumps({'edition':'forum','state':state,'at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),**fields},indent=2)+'\n',0o600)


def current_version():
    # Read through the runtime identity, never open potentially app-controlled files as root.
    identity=json.loads(app_run(TOOL/'verify.php','--identity',capture=True))
    version_tuple(identity['edition_version'])
    if identity.get('edition')!='forum': raise ValueError('Not a Forum Edition installation')
    return identity['edition_version']


def version_tuple(v):
    if v == '1.0.0-rc.1':
        return (1, 0, 0, -1)  # Approved RC sorts before stable 1.0.0.
    if not re.fullmatch(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)',v): raise ValueError('Expected stable or explicitly approved RC version')
    return (*map(int,v.split('.')), 0)


def service_config(c):
    # Dedicated FPM master, not a shared distro pool. Mount code read-only inside every runtime service.
    common=f'''User={USER}
Group={GROUP}
WorkingDirectory={APP}
UMask=0027
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths={' '.join(str(APP/p) for p in MUTABLE)}
'''
    fpm=f'''[global]
error_log = syslog
syslog.ident = cajeerengine-php
syslog.facility = daemon
daemonize = no
[app]
user = {USER}
group = {GROUP}
listen = /run/cajeerengine/php.sock
listen.mode = 0660
pm = dynamic
pm.max_children = 12
; Keep enough warmed workers for the eight-way TLS qualification burst.
; Dynamic scaling still caps the total worker count at twelve.
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 10
clear_env = yes
catch_workers_output = yes
security.limit_extensions = .php
request_terminate_timeout = 120s
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = syslog
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 65M
php_admin_value[memory_limit] = 256M
'''
    units={FPM:f'''[Unit]
Description=CajeerEngine Forum dedicated PHP-FPM
After=network.target
[Service]
{common}RuntimeDirectory=cajeerengine
RuntimeDirectoryMode=0750
StandardOutput=journal
StandardError=journal
ExecCondition=/usr/bin/test -f {FPM_ALLOWED}
ExecStart={FPM_BIN} --nodaemonize --fpm-config {ETC}/fpm.conf
Restart=on-failure
TimeoutStopSec=130
KillSignal=SIGQUIT
[Install]
WantedBy=multi-user.target
''',WORKER:f'''[Unit]
Description=CajeerEngine Forum queue worker
After=network-online.target
[Service]
{common}Environment=CE_UPDATE_WAIT_FOR_DRAIN=1
ExecCondition=/usr/bin/test -f {WORKERS_ALLOWED}
ExecStart=/bin/sh -c 'while true; do {PHP} {APP}/bin/cajeer queue:work --limit=10 >/dev/null || exit 1; sleep 5; done'
Restart=on-failure
RestartSec=5
TimeoutStopSec=150
KillSignal=SIGTERM
[Install]
WantedBy=multi-user.target
''',SCHEDULER:f'''[Unit]
Description=CajeerEngine Forum scheduler
After=network-online.target
[Service]
{common}Type=oneshot
ExecCondition=/usr/bin/test -f {WORKERS_ALLOWED}
ExecStart={PHP} {APP}/bin/cajeer scheduler:run
TimeoutStartSec=180
''',TIMER:f'''[Unit]
Description=CajeerEngine Forum scheduler timer
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
Unit={SCHEDULER}
[Install]
WantedBy=timers.target
'''}
    # Public vhost uses only index.php; install/upgrade are deliberately inaccessible.
    # Local acceptance listener is separate, loopback-only, with an explicit read-only allowlist.
    fastcgi=f'''fastcgi_param QUERY_STRING $query_string;
        fastcgi_param REQUEST_METHOD $request_method;
        fastcgi_param CONTENT_TYPE $content_type;
        fastcgi_param CONTENT_LENGTH $content_length;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param DOCUMENT_URI $document_uri;
        fastcgi_param SERVER_PROTOCOL $server_protocol;
        fastcgi_param REQUEST_SCHEME https;
        fastcgi_param SERVER_NAME {c['domain']};
        fastcgi_param SERVER_PORT 443;
        fastcgi_param REMOTE_ADDR $remote_addr;
        fastcgi_param REDIRECT_STATUS 200;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization if_not_empty;
        fastcgi_param SCRIPT_FILENAME {APP}/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT {APP}/public;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/cajeerengine/php.sock;
        fastcgi_read_timeout 120s;'''
    static_security = """add_header X-Content-Type-Options nosniff always;
        add_header X-Frame-Options SAMEORIGIN always;
        add_header Referrer-Policy strict-origin-when-cross-origin always;
        add_header Permissions-Policy 'camera=(), microphone=(), geolocation=()' always;
        add_header Strict-Transport-Security 'max-age=31536000' always;"""
    admin_security = static_security + """
        add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'" always;
        add_header Cache-Control 'no-cache' always;"""
    # Historic signed releases predate the application's optimized HTTP health
    # path. A narrowly scoped, one-second FastCGI microcache prevents a burst
    # of identical anonymous health probes from saturating PostgreSQL. The
    # separate /health/ready operator gate remains uncached and live. We never
    # cache authenticated/cookie requests or non-200 upstream responses.
    nginx=f'''fastcgi_cache_path {HEALTH_CACHE} levels=1:2 keys_zone=ce_forum_health:1m max_size=8m inactive=10s use_temp_path=off;
server {{
    listen 80;
    server_name {c['domain']};
    return 308 https://{c['domain']}$request_uri;
}}
server {{
    listen 443 ssl;
    server_name {c['domain']};
    ssl_certificate {ETC}/tls/fullchain.pem;
    ssl_certificate_key {ETC}/tls/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    root {APP}/public;
    disable_symlinks on from=$document_root;
    client_max_body_size 64m;
    open_file_cache off;
    if (-f {GATE}) {{ return 503; }}
    if (-f {APP}/storage/app/maintenance.json) {{ return 503; }}
    location ~ /\\. {{ return 404; }}
    location ~* ^/(install|upgrade)(/|$) {{ return 404; }}
    location = /index.php {{ {fastcgi} }}
    location = /api/v1/health {{
        limit_except GET {{ deny all; }}
        fastcgi_cache ce_forum_health;
        fastcgi_cache_key "$scheme|$host|$request_uri";
        fastcgi_cache_valid 200 1s;
        fastcgi_cache_lock on;
        fastcgi_cache_background_update on;
        fastcgi_cache_use_stale updating;
        fastcgi_cache_bypass $http_authorization $http_cookie;
        fastcgi_no_cache $http_authorization $http_cookie;
        # The public health JSON is anonymous infrastructure information. Its
        # generic API no-store header protects browser/proxy storage, while
        # this explicitly scoped internal cache retains it for at most 1s.
        fastcgi_ignore_headers Cache-Control Expires;
        {fastcgi}
    }}
    location ~* \\.(php[0-9]*|phtml|phar)(/|$) {{ return 404; }}
    location = /admin {{ return 308 /admin/; }}
    location /admin/assets/ {{ try_files $uri =404; {admin_security} }}
    location /admin/ {{ try_files $uri $uri/ /admin/index.html; index index.html; {admin_security} }}
    location /uploads/ {{ try_files $uri =404; {static_security} }}
    location /extensions/ {{ try_files $uri =404; {static_security} }}
    location /forum-assets/ {{ try_files $uri =404; {static_security} }}
    location / {{ try_files $uri /index.php?$query_string; }}
}}
server {{
    listen 127.0.0.1:18085;
    server_name localhost;
    root {APP}/public;
    disable_symlinks on from=$document_root;
    location = /forum {{ limit_except GET {{ deny all; }} {fastcgi} }}
    location = /api/v1/forum {{ limit_except GET {{ deny all; }} {fastcgi} }}
    location = /admin/ {{ limit_except GET {{ deny all; }} try_files /admin/index.html =404; {admin_security} }}
    location = /health/live {{ limit_except GET {{ deny all; }} {fastcgi} }}
    location = /health/ready {{ limit_except GET {{ deny all; }} {fastcgi} }}
    location = /api/v1/health {{ limit_except GET {{ deny all; }} {fastcgi} }}
    location / {{ return 404; }}
}}
'''
    return fpm,units,nginx


def validate_host_material(c):
    # Only dedicated host config files; reject unsafe trust material before modifying services.
    secure_dir(ETC/'tls',0o700)
    root_file(ETC/'release.pub'); root_file(ETC/'tls/fullchain.pem'); root_file(ETC/'tls/privkey.pem',True)
    run('openssl','x509','-in',ETC/'tls/fullchain.pem','-noout','-checkend','86400',capture=True)
    if 'does match certificate' not in run('openssl','x509','-in',ETC/'tls/fullchain.pem','-noout','-checkhost',c['domain'],capture=True):
        raise ValueError('TLS certificate hostname mismatch')
    key=(ETC/'backup.key').lstat()
    if not stat.S_ISREG(key.st_mode) or key.st_uid!=pwd.getpwnam(USER).pw_uid or stat.S_IMODE(key.st_mode)!=0o600:
        raise ValueError('backup.key must be cajeerengine-owned 0600')
    run('runuser','-u',USER,'-g',GROUP,'--',PHP,'-r','if (strlen((string)base64_decode(trim(file_get_contents($argv[1])),true))!==32) exit(1);',ETC/'backup.key')
    # Validate the local certificate's date and purpose. Public CA trust is
    # provisioned externally; the local leaf is an explicit trust anchor here.
    run('openssl','verify','-partial_chain','-CAfile',ETC/'tls/fullchain.pem','-verify_hostname',c['domain'],'-purpose','sslserver',ETC/'tls/fullchain.pem',capture=True)
    # Reject a mismatched private key before closing public traffic.
    certificate=run('openssl','x509','-in',ETC/'tls/fullchain.pem','-pubkey','-noout',capture=True)
    private=run('openssl','pkey','-in',ETC/'tls/privkey.pem','-pubout',capture=True,input='')
    if certificate.strip()!=private.strip(): raise ValueError('TLS certificate/private key mismatch')


def configure(c):
    validate_host_material(c)
    # The cache holds public health JSON only and is written by the nginx
    # worker, never by the unprivileged PHP application or deploy user.
    HEALTH_CACHE.parent.mkdir(parents=True, exist_ok=True)
    root_controlled_parents(HEALTH_CACHE)
    if HEALTH_CACHE.is_symlink():
        raise ValueError('Unsafe health cache path')
    HEALTH_CACHE.mkdir(exist_ok=True)
    if not HEALTH_CACHE.is_dir():
        raise ValueError('Unsafe health cache path')
    os.chown(HEALTH_CACHE,pwd.getpwnam('www-data').pw_uid,grp.getgrnam('www-data').gr_gid)
    HEALTH_CACHE.chmod(0o750)
    fpm,units,nginx=service_config(c)
    files={ETC/'fpm.conf':fpm,Path('/etc/nginx/conf.d/cajeerengine.conf'):nginx}
    files.update({Path('/etc/systemd/system')/k:v for k,v in units.items()})
    before={p:p.read_bytes() if p.exists() else None for p in files}
    try:
        for p,v in files.items(): atomic(p,v)
        run(FPM_BIN,'--test','--fpm-config',ETC/'fpm.conf')
        run('nginx','-t')
        run('systemctl','daemon-reload')
        run('systemctl','enable','--now','nginx')
        run('systemctl','reload','nginx')
        for _ in range(20):
            try:
                code=run('curl','--noproxy','*','--silent','--show-error','--insecure','--max-time','3','--resolve',c['domain']+':443:127.0.0.1','--output','/dev/null','--write-out','%{http_code}','https://'+c['domain']+'/health/ready',capture=True)
                if code=='503': break
            except subprocess.SubprocessError: pass
            time.sleep(.5)
        else: raise RuntimeError('Public maintenance gate not effective')
    except Exception:
        for p,v in before.items():
            if v is None: p.unlink(missing_ok=True)
            else: atomic(p,v)
        run('systemctl','daemon-reload')
        raise


def root_controlled_parents(path):
    # Check from the root down before accessing an untrusted descendant.
    for parent in reversed(Path(path).absolute().parents):
        st=parent.lstat()
        if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o022:
            raise ValueError(f'Artifact must be in a root-controlled directory: {parent} '
                             f'(uid={st.st_uid}, mode={stat.S_IMODE(st.st_mode):04o})')


def artifact(zip_path, sha, version):
    root_controlled_parents(zip_path)
    root_file(ETC/'release.pub')
    version_tuple(version)
    root_file(zip_path); root_file(Path(str(zip_path)+'.sig'))
    if zip_path.stat().st_size>2*1024**3 or Path(str(zip_path)+'.sig').stat().st_size>8192: raise ValueError('Oversized artifact or signature')
    if not re.fullmatch(r'[a-f0-9]{64}',sha): raise ValueError('Expected lowercase SHA256')
    inbox=STATE/'artifacts'; secure_dir(inbox)
    target=inbox/(sha+'.zip')
    # Root-owned copy of the exact bytes checked and later used by the updater.
    with tempfile.TemporaryDirectory(dir=inbox) as tmp:
        z=Path(tmp)/'candidate.zip'
        shutil.copyfile(zip_path,z); shutil.copyfile(str(zip_path)+'.sig',str(z)+'.sig')
        run(PHP,TOOL/'verify.php',z,ETC/'release.pub',sha,version,capture=True)
        os.chmod(z,0o644); os.chmod(str(z)+'.sig',0o644)
        for file in (z,Path(str(z)+'.sig')):
            with file.open('rb') as f: os.fsync(f.fileno())
        os.replace(z,target); os.replace(str(z)+'.sig',str(target)+'.sig')
        fd=os.open(inbox,os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
    return target


def extract(package, stage):
    # No extractall, no traversal, no symlinks, no directory entries, no duplicate names.
    seen=set(); total=0
    with zipfile.ZipFile(package) as z:
        for info in z.infolist():
            name=info.filename; parts=name.split('/')
            if (parts[0]!='cajeerengine' or len(parts)<2 or any(p in ('','.','..') for p in parts)
                or '\\' in name or ':' in name or any(ord(c)<32 or ord(c)==127 for c in name)
                or name in seen or stat.S_IFMT(info.external_attr>>16) not in (0,stat.S_IFREG)):
                raise ValueError('Unsafe ZIP entry')
            seen.add(name); total+=info.file_size
            if info.file_size>256*1024**2 or total>2*1024**3: raise ValueError('ZIP too large')
            dest=stage.joinpath(*parts[1:]); dest.parent.mkdir(parents=True,exist_ok=True)
            with z.open(info) as src, dest.open('xb') as out: shutil.copyfileobj(src,out)
    uid=pwd.getpwnam(USER).pw_uid; gid=grp.getgrnam(GROUP).gr_gid
    for p in [stage,*stage.rglob('*')]:
        os.chown(p,uid,gid); os.chmod(p,0o750 if p.is_dir() else 0o640)
    os.chmod(stage/'bin/cajeer',0o750)


def stop(): run('systemctl','stop',*SERVICES)


def validate_health_payload(payload, version, upstream):
    return (isinstance(payload,dict) and payload.get('status')=='ok' and payload.get('edition_version')==version
            and payload.get('version')==upstream and isinstance(payload.get('checks'),dict)
            and bool(payload['checks']) and all(value is True for value in payload['checks'].values()))


def local_probe(path):
    return run('curl','--noproxy','*','--fail','--silent','--show-error','--max-time','5',
               'http://127.0.0.1:18085'+path,capture=True,timeout=8)


def forum_probe():
    payload=json.loads(local_probe('/api/v1/forum'))
    data=payload.get('data') if isinstance(payload,dict) else None
    if not isinstance(data,dict) or not all(isinstance(data.get(k),list) for k in ('categories','boards')):
        raise RuntimeError('Forum API response invalid')
    html=local_probe('/forum')
    if '/forum-assets/forum.js' not in html or 'data-rendered="true"' not in html or 'id="forum-theme"' not in html:
        raise RuntimeError('Forum SSR response invalid')
    if 'admin-app.js' not in local_probe('/admin/'):
        raise RuntimeError('Forum ACP shell response invalid')


def health(version):
    identity=json.loads(app_run(TOOL/'verify.php','--identity',capture=True))
    if identity.get('edition_version')!=version: raise RuntimeError('Installed Forum Edition differs')
    for _ in range(40):
        try:
            r=json.loads(run('curl','--noproxy','*','--fail','--silent','--show-error','--max-time','3','http://127.0.0.1:18085/health/ready',capture=True,timeout=5))
            if r.get('status')=='ready':
                h=json.loads(run('curl','--noproxy','*','--fail','--silent','--show-error','--max-time','3','http://127.0.0.1:18085/api/v1/health',capture=True,timeout=5))
                if validate_health_payload(h,version,identity['upstream_version']):
                    forum_probe(); return
        except (ValueError,RuntimeError,subprocess.SubprocessError): pass
        time.sleep(1)
    # Collect safe service/socket metadata before the failure handler stops FPM.
    # Never dump request bodies, environment values or application logs.
    try:
        run('systemctl','show',FPM,'--property=ActiveState,SubState,Result,ExecMainCode,ExecMainStatus,MainPID,NRestarts,ConditionResult')
    except subprocess.SubprocessError:
        pass
    for path in (Path('/run/cajeerengine'),Path('/run/cajeerengine/php.sock')):
        try:
            st=path.lstat()
            print(f'FPM path {path}: uid={st.st_uid} gid={st.st_gid} mode={stat.S_IMODE(st.st_mode):04o} socket={stat.S_ISSOCK(st.st_mode)}',file=sys.stderr)
        except OSError as e:
            print(f'FPM path {path}: errno={e.errno}',file=sys.stderr)
    raise RuntimeError('Nginx/PHP-FPM readiness failed; inspect journalctl -u '+FPM+' and Nginx error log')


def verify_installed(version,sha256):
    if not isinstance(sha256,str) or not re.fullmatch(r'[a-f0-9]{64}',sha256): raise ValueError('Missing artifact identity')
    app_run(TOOL/'verify.php',STATE/'artifacts'/(sha256+'.zip'),ETC/'release.pub',sha256,version,'--installed')


def activate(version, sha256, site):
    verify_installed(version,sha256)
    if current_version()!=version: raise RuntimeError('Unexpected installed version')
    app_run('bin/cajeer','maintenance:off')
    # The signed pre-0.9.8 distribution contains a PostgreSQL catalog typo
    # (pg_am.am). Preload the audited operator class only for that known legacy
    # version; do not modify the signed dist, ledger, or installed files.
    if version_tuple(version) <= version_tuple('1.0.0'):
        legacy_compat=TOOL.parent/'core/Forum/Service/ForumSearchMaintenance.php'
        if not legacy_compat.is_file() or legacy_compat.is_symlink():
            raise RuntimeError('Trusted historical FTS verifier unavailable')
        app_run('-d','auto_prepend_file='+str(legacy_compat),'tools/update-postcheck.php','verify')
    else:
        app_run('tools/update-postcheck.php','verify')
    atomic(FPM_ALLOWED,'enabled\n')
    run('systemctl','start',FPM)
    health(version)
    app_run('bin/cajeer','queue:diagnostics')
    app_run('bin/cajeer','scheduler:due')
    record('starting_workers',version=version,sha256=sha256,site=site)
    atomic(WORKERS_ALLOWED,'enabled\n')
    started=int(time.time())
    app_run('-r','if (is_file("storage/logs/observability/worker.json")) unlink("storage/logs/observability/worker.json");')
    run('systemctl','start',WORKER)
    for _ in range(150):
        marker=app_run('-r','echo is_file("storage/logs/observability/worker.json") ? file_get_contents("storage/logs/observability/worker.json") : "{}";',capture=True)
        try:
            if (state:=json.loads(marker)).get('timestamp',0)>=started and state.get('status')=='success': break
        except ValueError: pass
        time.sleep(1)
    else: raise RuntimeError('Queue worker did not complete a successful pass')
    # Verify a real scheduler execution while traffic is still gated. At this
    # stage external side effects may have occurred: failure requires recovery.
    run('systemctl','start',SCHEDULER)
    run('systemctl','start',TIMER)
    health(version)
    for service in (FPM,WORKER,TIMER): run('systemctl','is-active','--quiet',service)
    run('systemctl','enable',FPM,WORKER,TIMER)
    record('ready',version=version,sha256=sha256,site=site)
    GATE.unlink(missing_ok=True)
    fd=os.open(STATE,os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)


def install_options(c):
    secret=json.loads(root_file(ETC/'install-secrets.json',True).read_text())
    if not isinstance(secret,dict) or set(secret)!={'db_password','admin_password'} or not isinstance(secret['db_password'],str) or not isinstance(secret['admin_password'],str) or not re.fullmatch(r'[A-Za-z0-9_-]{24,128}',secret['db_password']): raise ValueError('Invalid install secrets')
    options={k:v for k,v in c.items() if k!='domain'}
    options.update(secret); options.update(db='pgsql',domain=c['domain'],app_url='https://'+c['domain'])
    return options


def resume_install(j,site):
    """Resume only an authenticated, published but never initialized first install."""
    if j.get("previous") is not None or j.get("state") not in ("deploying","installing","recovery_required"):
        return
    if not APP.is_dir() or APP.is_symlink():
        raise RuntimeError("Release not published; rerun deploy with the same signed artifact")
    verify_installed(j["version"],j["sha256"])
    flags=json.loads(app_run("-r",'echo json_encode(["env"=>file_exists(".env")||is_link(".env"),"installed"=>file_exists("storage/app/installed.lock")||is_link("storage/app/installed.lock")]);',capture=True))
    if flags=={"env":False,"installed":False}:
        # install.php repeats the empty-database preflight. Never reseed a partial DB.
        record("installing",version=j["version"],previous=None,sha256=j["sha256"],site=site)
        app_run(TOOL/"install.php",input=json.dumps(install_options(site)))
    elif flags=={"env":True,"installed":True}:
        # The installer can have died after creating the install lock but before
        # final runtime configuration. Verify that step rather than guessing.
        app_run(TOOL/"install.php","--check-finalized")
    else:
        raise RuntimeError("Partial first installation; inspect installer state and recover explicitly")


def deploy(args,c):
    for binary in ('psql','pg_dump','pg_restore','nginx','openssl','curl','systemctl','runuser','unzip'):
        if not shutil.which(binary): raise ValueError('Missing host tool: '+binary)
    if not Path(FPM_BIN).is_file(): raise ValueError('PHP-FPM 8.4 missing')
    run(PHP,'-r',PHP_PREFLIGHT)
    package=artifact(args.artifact,args.sha256,args.version)
    validate_host_material(c)
    journal=json.loads(JOURNAL.read_text()) if JOURNAL.exists() else {}
    retry_empty=not APP.exists() and not APP.is_symlink() and (not journal or (journal.get('previous') is None and journal.get('version')==args.version and journal.get('sha256')==args.sha256 and journal.get('site')==c))
    if (GATE.exists() or journal and journal.get('state')!='ready') and not retry_empty:
        raise RuntimeError('Previous operation unresolved; use status/resume after recovery')
    if APP.is_symlink(): raise ValueError('Unexpected application symlink')
    existing=APP.exists(); previous=current_version() if existing else None
    old=json.loads(JOURNAL.read_text()) if JOURNAL.exists() else {}
    if existing and (old.get('version')!=previous or old.get('site')!=c):
        raise ValueError('Unmanaged installation or site configuration changed; recover/migrate explicitly')
    if existing and version_tuple(args.version)<version_tuple(previous): raise ValueError('Downgrade forbidden')
    if existing and previous==args.version:
        # Verify identity, not merely version: reject changed bytes under an existing tag.
        if json.loads(JOURNAL.read_text()).get('sha256')!=args.sha256: raise ValueError('Same-version artifact identity differs')
        verify_installed(args.version,args.sha256)
        health(args.version)
        for service in (FPM,WORKER,TIMER): run('systemctl','is-active','--quiet',service)
        if getattr(args,'dry_run',False):
            print(json.dumps({'ok':True,'mode':'already_deployed','edition_version':args.version,'sha256':args.sha256}))
        else: print('Already deployed')
        return
    if existing:
        verify_installed(previous,old.get('sha256'))
        # Check target compatibility in the currently trusted application before
        # closing traffic. Never execute candidate PHP to perform this check.
        app_run('bin/cajeer','forum:ecosystem:check','--target='+args.version,'--json',capture=True)
    options=None
    if not existing:
        options=install_options(c)
        # Same real read-only PG preflight as the installer, but before any
        # application publication, gate or service change. Rechecked at install.
        run('runuser','-u',USER,'-g',GROUP,'--',PHP,TOOL/'install.php','--preflight',
            input=json.dumps(options),timeout=60,capture=True)
    if getattr(args,'dry_run',False):
        print(json.dumps({'ok':True,'mode':'upgrade' if existing else 'install',
                          'edition_version':args.version,'previous':previous,'sha256':args.sha256}))
        return
    gate_on(); record('deploying',version=args.version,previous=previous,sha256=args.sha256,previous_sha256=args.previous_sha256,site=c)
    committed=False
    try:
        if existing: stop()
        configure(c)
        if not existing: stop()
        if existing:
            app_run('bin/cajeer','update:apply','--package='+str(package),'--sha256='+args.sha256,'--dry-run',legacy_compat=True)
            app_run('bin/cajeer','update:apply','--package='+str(package),'--sha256='+args.sha256,legacy_compat=True)
            committed=True
        else:
            with tempfile.TemporaryDirectory(dir=BASE,prefix='.stage-') as tmp:
                stage=Path(tmp)/'app'; stage.mkdir()
                extract(package,stage)
                os.rename(stage,APP)
            record('installing',version=args.version,previous=None,sha256=args.sha256,site=c)
            app_run(TOOL/'install.php',input=json.dumps(options))
        activate(args.version,args.sha256,c)
        print('Deployment ready:',args.version)
    except BaseException:
        # Gate stays closed across crashes, failed migrations or an unhealthy new FPM runtime.
        gate_on()
        try:
            stop()
            state=json.loads(JOURNAL.read_text()).get('state')
            if existing and state not in ('starting_workers','ready'):
                if committed: app_run('bin/cajeer','update:rollback','--yes',legacy_compat=True)
                activate(previous,args.previous_sha256,c)
                print('Previous release recovered',file=sys.stderr)
            else: record('recovery_required',version=args.version,previous=previous,sha256=args.sha256,previous_sha256=args.previous_sha256,site=c)
        except BaseException:
            gate_on(); record('recovery_required',version=args.version,previous=previous,sha256=args.sha256,previous_sha256=args.previous_sha256,site=c)
        raise



def verify_host(site, ca=None, require_backup=False):
    """Real read-only host admission; do not mutate units, gate or journal."""
    if GATE.exists() or not FPM_ALLOWED.is_file() or not WORKERS_ALLOWED.is_file():
        raise RuntimeError('Deployment is gated or workers are not admitted')
    if not JOURNAL.is_file() or APP.is_symlink() or not APP.is_dir():
        raise RuntimeError('No managed running installation')
    journal=json.loads(JOURNAL.read_text())
    if journal.get('edition')!='forum' or journal.get('state')!='ready' or journal.get('site')!=site:
        raise RuntimeError('Deployment not committed or site identity changed')
    version=journal.get('version'); version_tuple(version)
    digest=journal.get('sha256')
    verify_installed(version,digest)
    if current_version()!=version:
        raise RuntimeError('Installed release version differs from host journal')
    for service in (FPM,WORKER,TIMER,'nginx'):
        run('systemctl','is-active','--quiet',service,timeout=10)
    health(version)
    domain=site['domain']
    curl=['curl','--noproxy','*','--silent','--show-error','--max-time','10',
          '--resolve',domain+':443:127.0.0.1']
    if ca is not None:
        if not ca.is_absolute() or ca.is_symlink() or not ca.is_file():
            raise ValueError('Provide an existing absolute CA file')
        curl+=['--cacert',str(ca)]
    origin='https://'+domain
    def response(path):
        # Do not use --insecure, implicit proxies or redirects; reject a
        # mismatched real certificate or a gateway serving another forum.
        code=run(*curl,'--output','/dev/null','--write-out','%{http_code}',origin+path,
                 capture=True,timeout=15)
        if not re.fullmatch(r'[0-9]{3}',code): raise RuntimeError('Invalid HTTPS status')
        return int(code)
    for path in ('/health/ready','/api/v1/health','/forum','/admin/'):
        if response(path)!=200: raise RuntimeError('Public HTTPS gate failed: '+path)
    for path in ('/.env','/install/','/upgrade/'):
        if response(path)!=404: raise RuntimeError('Private path unexpectedly accessible: '+path)
    health_data=json.loads(run(*curl,'--fail',origin+'/api/v1/health',capture=True,timeout=15))
    upstream=json.loads(app_run(TOOL/'verify.php','--identity',capture=True)).get('upstream_version')
    if not validate_health_payload(health_data,version,upstream):
        raise RuntimeError('Public HTTPS application identity/readiness failed')
    if require_backup:
        backup=json.loads(app_run('bin/cajeer','backup:automation:status','--json',capture=True))
        if backup.get('ok') is not True or int(backup.get('last_offsite',0))<=0 or int(backup.get('pending',1))!=0:
            raise RuntimeError('Verified off-site backup is missing, stale or pending')
    return {'ok':True,'edition':'forum','edition_version':version,'sha256':digest,
            'checks':{'signed_artifact':True,'systemd':True,'application':True,
                      'public_tls':True,'private_routes':True,
                      'offsite_backup':True if require_backup else 'not_requested'}}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='command',required=True)
    d=sub.add_parser('deploy'); d.add_argument('artifact',type=Path); d.add_argument('--sha256',required=True); d.add_argument('--version',required=True); d.add_argument('--dry-run',action='store_true',help='Verify candidate and host without closing traffic or installing')
    sub.add_parser('status'); v=sub.add_parser('verify'); v.add_argument('--ca',type=Path); v.add_argument('--require-backup',action='store_true'); r=sub.add_parser('resume'); r.add_argument('--version',required=True); r.add_argument('--sha256',required=True)
    args=parser.parse_args()
    if os.geteuid()!=0: raise ValueError('Run as root')
    root_controlled_parents(TOOL/'production.py')
    for name in ('production.py','verify.php','install.php'): root_file(TOOL/name)
    os.umask(0o022)
    for p in (ETC,STATE,BASE): secure_dir(p)
    with (STATE/'deploy.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if args.command=='status':
            service_state={}
            for service in (FPM,WORKER,TIMER):
                try: service_state[service]=run('systemctl','is-active',service,capture=True).strip()
                except subprocess.SubprocessError: service_state[service]='inactive_or_unavailable'
            print(json.dumps({'maintenance':GATE.exists(),'edition':'forum','deployment':json.loads(JOURNAL.read_text()) if JOURNAL.exists() else None,'services':service_state},indent=2)); return
        c=config()
        if args.command=='verify':
            print(json.dumps(verify_host(c,args.ca,args.require_backup),indent=2)); return
        pwd.getpwnam(USER); grp.getgrnam(GROUP)
        if args.command=='resume':
            version_tuple(args.version)
            if not GATE.exists(): raise ValueError('No gated recovery')
            # Resume only the exact recorded deployment or its previous verified artifact.
            j=json.loads(JOURNAL.read_text())
            if (args.version,args.sha256) not in ((j.get('version'),j.get('sha256')),(j.get('previous'),j.get('previous_sha256'))): raise ValueError('Unknown recovery identity')
            if j.get('site')!=c: raise ValueError('Site configuration changed')
            gate_on(); stop(); configure(c)
            try:
                if (args.version,args.sha256)==(j.get('version'),j.get('sha256')): resume_install(j,c)
                activate(args.version,args.sha256,c)
            except BaseException:
                gate_on(); stop(); raise
            return
        args.previous_sha256=json.loads(JOURNAL.read_text()).get('sha256') if JOURNAL.exists() else None
        deploy(args,c)


if __name__=='__main__':
    try: main()
    except (OSError,ValueError,RuntimeError,subprocess.SubprocessError) as e:
        print('Deployment failed:',e,file=sys.stderr); sys.exit(1)
