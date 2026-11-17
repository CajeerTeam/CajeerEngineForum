#!/usr/bin/env python3
"""Operator-only Compose update. Retain both signed images and the host journal.
No Docker socket is mounted into the application. Recovery never guesses a version.
"""
import argparse
from contextlib import contextmanager
import ipaddress
import stat
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
SERVICES = ('gateway', 'worker', 'scheduler', 'app')
KEYS = {'CE_RELEASE_VERSION','CE_RELEASE_SHA256','CE_DOMAIN','CE_ADMIN_EMAIL','CE_NAMESPACE',
        'CE_SECRETS_DIR','CE_HTTPS_PORT','CE_BIND_ADDRESS','CE_QUEUE_DRIVER',
        'CE_LOOP_TIMEOUT_SECONDS','CE_RATE_LIMIT_GLOBAL','CE_RATE_LIMIT_FORUM_SEARCH'}


def read_site(path):
    values = {}
    for line in path.read_text().splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        key, sep, value = line.partition('=')
        # This is the documented literal site.env format, not a shell program.
        if not sep or key not in KEYS or key in values or not re.fullmatch(r'[A-Za-z0-9_./:@+\-]+', value):
            raise ValueError('Invalid literal site.env entry: '+key)
        values[key] = value
    if not {'CE_RELEASE_VERSION','CE_RELEASE_SHA256','CE_DOMAIN','CE_ADMIN_EMAIL','CE_NAMESPACE','CE_SECRETS_DIR'} <= values.keys():
        raise ValueError('Incomplete site.env')
    values.setdefault('CE_QUEUE_DRIVER','pgsql'); values.setdefault('CE_HTTPS_PORT','443'); values.setdefault('CE_BIND_ADDRESS','0.0.0.0'); values.setdefault('CE_LOOP_TIMEOUT_SECONDS','600')
    if not (re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', values['CE_RELEASE_VERSION']) or values['CE_RELEASE_VERSION'] == '1.0.0-rc.1'):
        raise ValueError('Stable release version required')
    if not re.fullmatch(r'[a-f0-9]{64}',values['CE_RELEASE_SHA256']):
        raise ValueError('Approved SHA256 required')
    if values['CE_QUEUE_DRIVER'] not in ('pgsql', 'redis'):
        raise ValueError('Unsupported queue driver')
    for name, minimum, maximum in [('CE_LOOP_TIMEOUT_SECONDS',30,3600),
                                    ('CE_RATE_LIMIT_GLOBAL',1,1000000),
                                    ('CE_RATE_LIMIT_FORUM_SEARCH',1,1000000)]:
        if name in values and (not re.fullmatch(r'[1-9][0-9]{0,6}', values[name])
                               or not minimum <= int(values[name]) <= maximum):
            raise ValueError('Invalid operator configuration: '+name)
    if not Path(values['CE_SECRETS_DIR']).is_absolute():
        raise ValueError('Absolute secret directory required')
    if not re.fullmatch(r'[1-9][0-9]{0,4}',values['CE_HTTPS_PORT']) or not 1 <= int(values['CE_HTTPS_PORT']) <= 65535:
        raise ValueError('Invalid HTTPS port')
    if not re.fullmatch(r'(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',values['CE_DOMAIN']):
        raise ValueError('Invalid TLS domain')
    if not re.fullmatch(r'[A-Za-z0-9_.-]{1,80}',values['CE_NAMESPACE']):
        raise ValueError('Invalid Redis namespace')
    if ipaddress.ip_address(values['CE_BIND_ADDRESS']).version!=4:
        raise ValueError('IPv4 bind address required')
    return values


def version(v):
    return (1,0,0,-1) if v == '1.0.0-rc.1' else (*map(int,v.split('.')),0)


def durable_json(path, data, mode=0o600):
    fd, tmp = tempfile.mkstemp(dir=path.parent, prefix='.state-')
    os.fchmod(fd, mode)
    try:
        with os.fdopen(fd,'w') as out:
            json.dump(data,out,indent=2,sort_keys=True); out.write('\n'); out.flush(); os.fsync(out.fileno())
        os.replace(tmp,path)
        fd=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)


@contextmanager
def project_lock(project, directory=Path('/tmp')):
    # A second journal directory must not bypass the host-wide project mutex.
    # Never unlink this lock: another process could otherwise acquire a new inode.
    path=directory/('cajeer-forum-'+project+'.update.lock')
    fd=os.open(path,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        st=os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=os.getuid() or st.st_mode&0o077:
            raise RuntimeError('Unsafe project lock; use the same dedicated operator')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        yield
    finally: os.close(fd)


class Deployment:
    def __init__(self,args):
        self.args=args; self.old=read_site(args.old_env); self.new=read_site(args.new_env)
        if {k:v for k,v in self.old.items() if not k.startswith('CE_RELEASE_')} != {k:v for k,v in self.new.items() if not k.startswith('CE_RELEASE_')}:
            raise ValueError('Only release identity may change during an update')
        if version(self.new['CE_RELEASE_VERSION'])<=version(self.old['CE_RELEASE_VERSION']):
            raise ValueError('Target release must be newer')
        if self.old['CE_QUEUE_DRIVER'] != 'pgsql':
            raise ValueError('Automatic update/rollback requires PostgreSQL queue; Redis queue recovery is not supported')
        self.target=self.new['CE_RELEASE_VERSION']; self.sha=self.new['CE_RELEASE_SHA256']; self.previous=self.old['CE_RELEASE_VERSION']
        self.identity={'project':args.project,'old':self.old,'new':self.new}
        self.path=args.state_dir/'update.json'
        self.env={k:v for k,v in os.environ.items() if not k.startswith(('CE_','COMPOSE_'))}
        if self.env.get('DOCKER_HOST') and not self.env['DOCKER_HOST'].startswith('unix://'): raise ValueError('Local Docker host required')
        self.artifact=HERE/'artifacts/release.zip'

    def local_docker(self):
        host=self.env.get('DOCKER_HOST')
        if not host or self.env.get('DOCKER_CONTEXT'):
            host=subprocess.check_output(['docker','context','inspect','--format','{{.Endpoints.docker.Host}}'],text=True,env=self.env).strip()
        if not host.startswith('unix://'): raise ValueError('Local Unix Docker endpoint required')

    def dc(self,site,*argv,capture=False):
        # Explicit env file + no ambient Compose overrides; no shell interpolation.
        cmd=['docker','compose','--project-name',self.args.project,'--env-file',str(site),'-f',str(HERE/'compose.yaml'),*argv]
        return subprocess.run(cmd,cwd=ROOT,env=self.env,check=True,text=True,stdout=subprocess.PIPE if capture else None).stdout

    def state(self):
        if not self.path.exists(): return None
        j=json.loads(self.path.read_text())
        if j.get('identity')!=self.identity: raise ValueError('Journal belongs to different release/site; use its original environment files')
        return j['state']

    def record(self,state):
        durable_json(self.path,{'identity':self.identity,'state':state,'updated_at':int(time.time())})
        # Dedicated *disposable* chaos certification: pause after a fsynced
        # checkpoint so an external runner can SIGKILL this host operator.
        # Never honor fault injection on normally named projects or without
        # the literal test-only confirmation. This cannot bypass preflight.
        pause=os.environ.get('CE_CHAOS_PAUSE_AFTER_STATE')
        if pause == state:
            if (state not in ('drained','activating') or
                os.environ.get('CE_CHAOS_DISPOSABLE') != 'destroy-test-services' or
                re.search(r'(^|[-_])chaos([-_]|$)',self.args.project) is None):
                raise RuntimeError('Chaos pause is forbidden outside disposable test project')
            time.sleep(120)

    def operator(self,mode):
        # Old image and embedded old trust key are retained across rollback.
        args=['run','--rm','--no-deps','-T','--entrypoint','/bin/sh']
        mounts=[(HERE/'update-operator.sh','/opt/ce-update/operator.sh'),(HERE/'update-operator.php','/opt/ce-update/operator.php'),(ROOT/'core/Update/UpdatePreflight.php','/opt/ce-update/UpdatePreflight.php')]
        if mode in ('verify','apply'):
            mounts.extend([(self.artifact,'/opt/ce-update/release.zip'),(Path(str(self.artifact)+'.sig'),'/opt/ce-update/release.zip.sig'),(HERE/'artifacts/release.pub','/opt/ce-update/release.pub'),
                           # The installed 0.8.x image may not recognize files introduced by 1.0.
                           # Supply the local trusted operator's updated verifier; it is
                           # invoked only AFTER the old pinned publisher key validates the ZIP.
                           (ROOT/'core/Release/ReleaseFilePolicy.php','/opt/ce-update/ReleaseFilePolicy.php'),
                           (ROOT/'core/Release/ReleaseDependencyInventory.php','/opt/ce-update/ReleaseDependencyInventory.php'),
                           (ROOT/'core/Release/ReleaseArtifactVerifier.php','/opt/ce-update/ReleaseArtifactVerifier.php'),
                           (ROOT/'core/Forum/Service/ForumSearchMaintenance.php','/opt/ce-update/ForumSearchMaintenance.php')])
        for source,target in mounts:
            if not source.is_file() or source.is_symlink() or ':' in str(source): raise ValueError('Missing/unsafe operator input: '+source.name)
            args+=['--volume',f'{source}:{target}:ro']
        try:
            self.dc(self.args.old_env,*args,'init','/opt/ce-update/operator.sh',mode,self.target,self.sha)
        except subprocess.CalledProcessError as exc:
            raise RuntimeError('Signed update operator '+mode+' failed (exit '+str(exc.returncode)+'); inspect the operator container log') from exc

    def stop(self,site):
        # Stop ingress first, then drain all writers. PostgreSQL/Redis stay running.
        self.dc(site,'stop','gateway')
        self.dc(site,'stop','worker','scheduler','app')

    def verify(self):
        if not self.artifact.is_file(): raise ValueError('Run prepare.sh with the approved signed target first')
        with self.artifact.open('rb') as f:
            if hashlib.file_digest(f,'sha256').hexdigest()!=self.sha: raise ValueError('Prepared artifact digest differs')
        self.operator('verify')

    def activate(self,site,expected,final_state):
        # No traffic or background side effects until the code/DB transaction is
        # committed and the host journal has selected this release durably.
        self.operator('open')
        self.dc(site,'up','-d','--no-deps','--force-recreate','init')
        init_id=self.dc(site,'ps','-aq','init',capture=True).strip()
        if not init_id: raise RuntimeError('Missing init container')
        subprocess.run(['docker','wait',init_id],check=True,stdout=subprocess.PIPE,text=True,env=self.env)
        state=subprocess.check_output(['docker','inspect','--format','{{.State.ExitCode}}',init_id],text=True,env=self.env).strip()
        if state!='0': raise RuntimeError('Selected image failed init identity/schema check')
        self.dc(site,'up','-d','--no-deps','--force-recreate','app')
        for _ in range(90):
            try:
                self.dc(site,'exec','-T','app','php','/opt/ce-container/runtime.php','ready'); break
            except subprocess.CalledProcessError: time.sleep(2)
        else: raise RuntimeError('Selected PHP-FPM image not ready')
        self.dc(site,'up','-d','--no-deps','--force-recreate','worker','scheduler')
        for _ in range(90):
            try:
                for service in ('worker','scheduler'):
                    self.dc(site,'exec','-T',service,'php','/opt/ce-container/runtime.php','loop-health')
                break
            except subprocess.CalledProcessError: time.sleep(2)
        else: raise RuntimeError('Background services not ready')
        self.dc(site,'up','-d','--no-deps','--force-recreate','gateway')
        self.http_check(site,expected)
        # A successful HTTPS response alone cannot prove that workers, image
        # digests, and the persisted release volume agree. Validate the running
        # installation before committing the durable operator journal.
        self.operational_gate(site)
        self.record(final_state)

    def operational_gate(self, site):
        from operations import Operations
        check = Operations(self.args.project, site, self.args.ca)
        for _ in range(45):
            result = check.run()
            if result['ok']:
                return
            time.sleep(2)
        failed = ','.join(name for name, item in result['checks'].items() if not item['ok'])
        raise RuntimeError('Post-activation operational gates failed: '+failed)

    def http_check(self,site,expected):
        cfg=self.old if site==self.args.old_env else self.new
        domain=cfg['CE_DOMAIN']; port=cfg['CE_HTTPS_PORT']; base=f'https://{domain}:{port}'
        # Verify actual TLS and edition; custom CA only when explicitly supplied.
        bind=cfg['CE_BIND_ADDRESS']; address='127.0.0.1' if bind in ('0.0.0.0','127.0.0.1') else bind
        cmd=['curl','--noproxy','*','--fail','--silent','--show-error','--max-time','10','--resolve',f'{domain}:{port}:{address}']
        if self.args.ca: cmd+=['--cacert',str(self.args.ca)]
        for _ in range(60):
            try:
                def get(path): return subprocess.check_output(cmd+[base+path],text=True,env=self.env)
                get('/health/ready'); h=json.loads(get('/api/v1/health'))
                if h.get('edition_version')!=expected: raise RuntimeError('HTTP edition mismatch')
                if 'data-rendered="true"' not in get('/forum'): raise RuntimeError('SSR not ready')
                if 'admin-app.js' not in get('/admin/'): raise RuntimeError('ACP shell not ready')
                return
            except (subprocess.CalledProcessError, ValueError, RuntimeError): time.sleep(2)
        raise RuntimeError('TLS/Forum HTTP checks failed')

    def apply(self):
        if self.state() is not None: raise RuntimeError('Use recover or rollback with existing journal; archive finished journal for a new update')
        self.verify()
        if self.args.dry_run: print('Preflight passed; no live changes'); return
        self.dc(self.args.new_env,'build','app','gateway')
        self.record('prepared')
        self.stop(self.args.old_env)
        self.record('drained')
        self.operator('apply')
        self.record('applied')
        # Once activation can start workers/ingress, rollback can discard writes
        # or external effects. Resume instead of automatically reverting.
        self.record('activating')
        self.activate(self.args.new_env,self.target,'committed')

    def recover(self,rollback=False):
        state=self.state()
        if state is None: raise RuntimeError('No deployment journal')
        if state in ('committed','rolled_back') and not rollback: print('Deployment already '+state); return
        if rollback and not self.args.yes: raise RuntimeError('Explicit --yes required: rollback discards writes after the snapshot')
        if state=='rolled_back': return
        if state=='prepared' and not rollback:
            # The snapshot has not yet been taken, but stopping old services
            # may have started before the checkpoint was interrupted. Restore
            # the unchanged old release; never invoke destructive DB rollback.
            self.stop(self.args.old_env)
            self.activate(self.args.old_env,self.previous,'rolled_back'); return
        if state=='activating' and not rollback:
            self.stop(self.args.new_env)
            self.activate(self.args.new_env,self.target,'committed'); return
        # For interrupted operations before activation, no new release writers
        # were started. After activation, destructive rollback requires --yes.
        self.record('rolling_back')
        self.stop(self.args.new_env)
        self.operator('recover')
        self.activate(self.args.old_env,self.previous,'rolled_back')


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('action',choices=['apply','recover','rollback','status'])
    p.add_argument('--project',required=True); p.add_argument('--old-env',required=True,type=Path); p.add_argument('--new-env',required=True,type=Path)
    p.add_argument('--state-dir',required=True,type=Path); p.add_argument('--ca',type=Path); p.add_argument('--dry-run',action='store_true'); p.add_argument('--yes',action='store_true')
    a=p.parse_args()
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}',a.project): p.error('Invalid project name')
    a.old_env=a.old_env.resolve(); a.new_env=a.new_env.resolve(); a.state_dir=a.state_dir.absolute()
    if a.state_dir.is_symlink(): p.error('State directory cannot be a symlink')
    a.state_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
    if a.state_dir.stat().st_uid!=os.getuid() or a.state_dir.stat().st_mode&0o077: p.error('State directory must be private and owned by operator')
    with project_lock(a.project), open(a.state_dir/'lock','a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        d=Deployment(a)
        if a.action=='status': print(json.dumps({'state':d.state(),'identity':d.identity},indent=2)); return
        d.local_docker()
        try:
            if a.action=='apply': d.apply()
            else: d.recover(a.action=='rollback')
        except BaseException:
            # Never leave new ingress open on a failed activation. A prepared
            # preflight/build failure has not touched the running project.
            if d.state() not in (None,'committed','rolled_back'):
                try: d.dc(a.new_env,'stop','gateway')
                except Exception: pass
            raise


if __name__=='__main__': main()
