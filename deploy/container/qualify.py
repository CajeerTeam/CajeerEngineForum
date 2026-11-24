#!/usr/bin/env python3
"""Destructive qualification of a DISPOSABLE local Compose forum. Never use a live project."""
import argparse
import concurrent.futures
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import subprocess
import time
import uuid
from collections import Counter
from certify_load import validate_thresholds, current_policy
from qualification_telemetry import ResourceMonitor, QueueLatencyMonitor
from chaos import run_chaos

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('acceptance', HERE.parents[1] / 'tools/production-acceptance.py')
acceptance = importlib.util.module_from_spec(spec); spec.loader.exec_module(acceptance)
Client, require, data, expect, write_report = (acceptance.Client, acceptance.require, acceptance.data, acceptance.expect, acceptance.write_report)

# Bounded histograms: constant memory even for a 24-hour, 200-client run.
BOUNDS = (1, 2, 5, 10, 20, 30, 50, 75, 100, 150, 200, 300, 400, 500, 750, 1000, 1500, 2000, 3000, 5000, 10000, 20000, 30000, 60000)

class Histogram:
    def __init__(self):
        self.counts = [0] * (len(BOUNDS) + 1); self.errors = Counter(); self.total = 0
    def add(self, ms, error=None):
        self.counts[next((i for i, b in enumerate(BOUNDS) if ms <= b), len(BOUNDS))] += 1
        self.total += 1
        if error: self.errors[error] += 1
    def percentile(self, p):
        if not self.total: return None
        rank = math.ceil(self.total * p); cumulative = 0
        for i, n in enumerate(self.counts):
            cumulative += n
            if cumulative >= rank: return BOUNDS[i] if i < len(BOUNDS) else 60001
        raise AssertionError('histogram corrupted')
    def report(self):
        return {'requests': self.total, 'errors': sum(self.errors.values()), 'error_types': dict(self.errors),
                **{f'p{int(q*100)}_upper_ms': self.percentile(q) for q in (.5,.95,.99)}}

class Compose:
    def __init__(self, project, env):
        self.env = {k:v for k,v in os.environ.items() if not k.startswith(('CE_', 'COMPOSE_'))}
        self.prefix = ['docker','compose','--project-name',project,'--env-file',str(env),'-f',str(HERE/'compose.yaml')]
    def run(self, *args, stdin=None, timeout=180):
        result = subprocess.run(self.prefix+list(args), input=stdin, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, env=self.env)
        require(result.returncode == 0, 'Compose command failed: '+args[0])
        return result.stdout
    def memory(self):
        # cgroup v2 is required for qualification; do not silently report zero.
        values = {}
        for service in ('app','worker','scheduler','postgres','redis'):
            raw = self.run('exec','-T',service,'cat','/sys/fs/cgroup/memory.current',timeout=15).strip()
            require(raw.isdigit(), 'cgroup v2 memory unavailable')
            values[service] = int(raw)
        return values
    def sql(self, sql, timeout=180):
        return self.run('exec','-T','postgres','sh','-c','PGPASSWORD="$(cat /tmp/ce-db-password)" exec psql -X -qAt -v ON_ERROR_STOP=1 -h 127.0.0.1 -U cajeerengine -d cajeerengine',stdin=sql,timeout=timeout).strip()


def seed(dc, thread, posts, namespace):
    """Populate the real PostgreSQL schema, including revisions and FTS/cache triggers.
    Test-only synthetic data. Each bounded transaction has complete threads and posts.
    """
    if not posts: return []
    require(posts % 100 == 0, 'seed count must be divisible by 100')
    ns = str(uuid.UUID(namespace)); thread = str(uuid.UUID(thread))
    total = posts // 100
    for start in range(1,total+1,50):
        stop = min(total,start+49)
        # One data-modifying CTE per dependency: later statements see committed rows.
        dc.sql(f"""BEGIN;
INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence)
SELECT md5('{ns}:t:'||i)::uuid,board_id,author_id,'QualificationCorpus'||replace('{ns}','-','')||'x'||i,100
FROM ce_forum_threads CROSS JOIN generate_series({start},{stop}) i WHERE id='{thread}'::uuid;
INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence)
SELECT md5('{ns}:p:'||i||':'||j)::uuid,md5('{ns}:t:'||i)::uuid,author_id,'Qualification corpus body '||i||' entry '||j,j,j
FROM ce_forum_threads CROSS JOIN generate_series({start},{stop}) i CROSS JOIN generate_series(1,100) j WHERE id='{thread}'::uuid;
INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at)
SELECT p.id,1,p.body,p.body_format,p.author_id,'Qualification seed',extract(epoch from current_timestamp)::bigint
FROM generate_series({start},{stop}) i JOIN ce_forum_posts p ON p.thread_id=md5('{ns}:t:'||i)::uuid;
COMMIT;""",timeout=300)
    # Check the actual rows and revision coverage, not a declared seed count.
    got=dc.sql(f"SELECT count(*) FROM ce_forum_posts p JOIN generate_series(1,{total}) i ON p.thread_id=md5('{ns}:t:'||i)::uuid JOIN ce_forum_post_revisions r ON r.post_id=p.id AND r.revision_number=1;")
    require(int(got)==posts,'corpus count mismatch')
    dc.sql('ANALYZE ce_forum_threads; ANALYZE ce_forum_posts; ANALYZE ce_forum_post_revisions;')
    return [str(uuid.UUID(hex=__import__('hashlib').md5(f'{ns}:t:{i}'.encode()).hexdigest())) for i in range(1,min(total,16)+1)]


def load(client, routes, clients, seconds, rate, progress=None):
    """Closed-loop batches, bounded in-flight work and paced starts; no unbounded future list."""
    groups = {}
    for route in routes:
        groups.setdefault(route[0], []).append(route)
    names = tuple(groups)
    stats = {name: Histogram() for name in names}; started = time.monotonic(); deadline=started+seconds
    i=0; next_start=started; next_report=started+30
    def one(index):
        name = names[index % len(names)]
        variants = groups[name]
        name,path,headers,validate=variants[(index // len(names)) % len(variants)]; begin=time.monotonic(); error=None
        try:
            code,_,body=client.request(path,headers=headers)
            if code!=200: error='http_'+str(code)
            elif not validate(body): error='semantic_mismatch'
        except Exception as e: error=type(e).__name__
        return name,(time.monotonic()-begin)*1000,error
    with concurrent.futures.ThreadPoolExecutor(max_workers=clients) as pool:
        active=set()
        while time.monotonic()<deadline or active:
            now=time.monotonic()
            while now<deadline and len(active)<clients and now>=next_start:
                active.add(pool.submit(one,i)); i+=1; next_start=max(next_start+1/rate,now+1/rate); now=time.monotonic()
            if active:
                done,active=concurrent.futures.wait(active,timeout=min(.1,max(.001,next_start-now)) if len(active)<clients else .1,return_when=concurrent.futures.FIRST_COMPLETED)
                for f in done:
                    name,ms,error=f.result();stats[name].add(ms,error)
            elif now<deadline: time.sleep(min(.1,max(.001,next_start-now)))
            if progress and now>=next_report:
                progress({k:v.report() for k,v in stats.items()});next_report=now+30
    elapsed=time.monotonic()-started
    return {'elapsed_seconds':round(elapsed,3),'requests':sum(s.total for s in stats.values()),'requests_per_second':round(sum(s.total for s in stats.values())/elapsed,3),'operations':{k:v.report() for k,v in stats.items()}}


def assert_load(result, max_p95, max_error_rate, min_rps):
    require(result['requests_per_second']>=min_rps,'throughput below target')
    for name,row in result['operations'].items():
        require(row['requests']>0,'no observations: '+name)
        require(row['p95_upper_ms']<=max_p95,'p95 threshold: '+name)
        require(row['errors']/row['requests']<=max_error_rate,'error threshold: '+name)
        require(not row['error_types'].get('semantic_mismatch'),'incorrect response: '+name)


def wait_until(fn, seconds, label):
    end=time.monotonic()+seconds
    while time.monotonic()<end:
        try:
            if fn(): return
        except (OSError, RuntimeError, ValueError, KeyError): pass
        time.sleep(1)
    raise RuntimeError('Timed out: '+label)


def qualify(a):
    require(a.disposable=='destroy-test-services','explicit disposable confirmation required')
    require(re.fullmatch(r'[a-z0-9][a-z0-9_-]*',a.project) is not None,'invalid project name')
    if a.profile != 'smoke' or a.faults:
        require(re.search(r'(?:^|[-_])(?:ci|load|qualification|benchmark|chaos)(?:[-_]|$)', a.project) is not None, 'destructive test requires a dedicated named project')
    dc=Compose(a.project,a.env); c=Client(a.domain,a.ca,a.port)
    from update import read_site
    site = read_site(a.env)
    require(site['CE_RELEASE_VERSION'] == a.version and site['CE_DOMAIN'] == a.domain and int(site['CE_HTTPS_PORT']) == a.port, 'site identity mismatch')
    if a.profile != 'smoke':
        require(site['CE_QUEUE_DRIVER'] == 'pgsql', 'physical qualification requires PostgreSQL queue')
        require(site['CE_BIND_ADDRESS'] == '127.0.0.1', 'physical fixture must bind loopback only')
    if a.faults or a.profile != 'smoke':
        host = dc.env.get('DOCKER_HOST')
        if not host or dc.env.get('DOCKER_CONTEXT'):
            host = subprocess.check_output(['docker','context','inspect','--format','{{.Endpoints.docker.Host}}'], env=dc.env, text=True, timeout=30).strip()
        require(host.startswith('unix://'), 'destructive qualification requires local Docker')
    env_text = a.env.read_text()
    found = re.search(r'^CE_RELEASE_SHA256=([a-f0-9]{64})$', env_text, flags=re.M)
    require(found is not None, 'site.env must identify approved release SHA-256')
    commit = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=HERE.parents[1], capture_output=True, text=True, check=True).stdout.strip()
    require(re.fullmatch(r'[a-f0-9]{40}', commit) is not None, 'Git commit identity unavailable')
    port=dc.run('port','gateway','8443').strip();require(port in ('127.0.0.1:'+str(a.port),'0.0.0.0:'+str(a.port),'[::]:'+str(a.port)), 'gateway mapping differs from target')
    import hashlib, os, platform, socket
    host_bytes=socket.gethostname().encode('utf-8')
    report={'hardware':{'host_sha256':hashlib.sha256(host_bytes).hexdigest(),
                        'cpu_count':os.cpu_count(),'architecture':platform.machine(),
                        'kernel':platform.release()},
            'schema':2,'profile':a.profile,'queue_driver':site['CE_QUEUE_DRIVER'],'source_commit':commit,'artifact_sha256':found.group(1),'edition_version':a.version,'run_id':str(uuid.uuid4()),'started_at':int(time.time()),'ok':False,'checks':{},'samples':[], 'thresholds':{'clients':a.clients,'seconds':a.seconds,'target_rps':a.rate,'minimum_rps':a.min_rps,'max_p95_ms':a.max_p95_ms,'max_error_rate':a.max_error_rate,'max_memory_growth_mib':a.max_growth_mib,'max_queue_p95_ms':a.max_queue_p95_ms}}
    a.report.parent.mkdir(parents=True,exist_ok=True)
    def save(): write_report(a.report,report)
    def check(name): report['checks'][name]=True;save();print('PASS',name,flush=True)
    def call(path,status=200,**kw): return expect(c,path,status,**kw)
    def login(email): return {'Authorization':'Bearer '+data(call('/api/v1/auth/login',201,method='POST',data={'email':email,'password':a.password_file.read_text().strip()})[1])['access_token']}
    def health(): return c.request('/health/ready')[0]==200
    def assert_ready(): wait_until(health,120,'ready')
    def recover(service): dc.run('start',service);assert_ready()
    def verify_posts(thread, expected):
        # Every acknowledged ID/body must appear exactly once across the real paginated API.
        seen={}; page=1
        while True:
            rows=data(call('/api/v1/forum/threads/'+thread+'?page='+str(page))[1])['posts']
            for row in rows:
                require(row['id'] not in seen,'duplicate post ID');seen[row['id']]=row['body']
            if len(rows)<25: break
            page+=1;require(page<=400,'unexpected thread size')
        require(all(seen.get(k)==v for k,v in expected.items()),'confirmed publication missing or changed')
        require(sum(v.startswith('Qualification reply '+report['run_id']) for v in seen.values())==len(expected),'duplicate or unknown qualification publication')
        require(not any(v.startswith('Rejected fault '+report['run_id']) for v in seen.values()),'fault-window write unexpectedly committed')
    save()
    try:
        image=dc.run('images','-q','app').strip();require(bool(re.fullmatch(r'(?:sha256:)?[a-f0-9]{12,64}',image)),'app image identity missing');report['app_image']=image
        if a.profile != 'smoke':
            cid = dc.run('ps','-q','app').strip()
            require(re.fullmatch(r'[a-f0-9]{64}', cid) is not None, 'unique running app required')
            actual = json.loads(subprocess.check_output(['docker','inspect',cid], text=True, timeout=30, env=dc.env))[0]
            require(actual['Config']['Image'] == f"cajeerengine-forum:{a.version}-{site['CE_RELEASE_SHA256']}" and actual['Image'].removeprefix('sha256:').startswith(image.removeprefix('sha256:')), 'running image differs from approved artifact')
        if a.profile != 'smoke' and a.version == '1.5.0':
            from release_identity import verify_running
            report['deployment_identity'] = verify_running(dc,site,a.signed_dist,a.trusted_key)
            require(report['deployment_identity']['image'].removeprefix('sha256:').startswith(image.removeprefix('sha256:')), 'qualified image differs from signed deployment')
            check('signed_deployment_identity')
        assert_ready();require(json.loads(call('/api/v1/health')[1])['edition_version']==a.version,'edition mismatch')
        state=json.loads(a.state.read_text()); thread=str(uuid.UUID(state['thread'])); path='/api/v1/forum/threads/'+thread
        require(b'ContainerSmokeBody' in call('/forum/threads/'+thread)[1],'disposable fixture missing')
        admin=login(a.email); member=login('container-smoke-member@example.invalid')
        thread_data=data(call(path)[1]);board=str(uuid.UUID(thread_data['thread']['board_id']))
        category=str(uuid.UUID(thread_data['board']['category_id']))
        expected={};publication=Histogram(); queue=QueueLatencyMonitor(c,admin,Histogram()).start();check('identity_and_fixture')
        if a.seed_posts:
            samples=seed(dc,thread,a.seed_posts,report['run_id']);report['seed_posts']=a.seed_posts;check('corpus_seeded')
        else: samples=[]
        # One burst of concurrent writes; do not disable normal moderation or quotas.
        def publish(i, observe=True):
            body=f"Qualification reply {report['run_id']} {i}"
            begin=time.monotonic();pid=data(call(path+'/posts',201,method='POST',headers=member,data={'body':body})[1])['id'];
            if observe: queue.track(pid)
            return pid,body,(time.monotonic()-begin)*1000
        with concurrent.futures.ThreadPoolExecutor(max_workers=min(a.clients,8)) as pool:
            for pid,body,ms in pool.map(publish,range(8)): expected[pid]=body;publication.add(ms)
        verify_posts(thread,expected);report['confirmed_writes']=len(expected);check('concurrent_publications')
        routes=[('ssr','/forum/threads/'+thread,{},lambda b:b'ContainerSmokeBody' in b),('api',path,{},lambda b:data(b)['thread']['id']==thread),('search','/api/v1/forum/search?q=ContainerSmokeMarker',{},lambda b:any(r['id']==thread for r in data(b))),('acp','/api/v1/forum/acp?view=overview',admin,lambda b:isinstance(data(b),dict))]
        for t in samples:
            routes.append(('corpus_ssr','/forum/threads/'+t,{},lambda b:b'Qualification corpus body' in b))
        if samples:
            routes.append(('corpus_search','/api/v1/forum/search?q=QualificationCorpus'+report['run_id'].replace('-','')+'x1',{},
                           lambda b:any(row['id']==samples[0] for row in data(b))))

        # Verify the actual public SSR cache, not just API response semantics.
        cache_headers=[]
        for _ in range(3): cache_headers.append(call('/forum/threads/'+thread)[0].get('x-forum-cache'))
        require('HIT' in cache_headers,'real SSR cache did not warm')
        check('cache_warmed')
        # Warm memory after real concurrent traffic; do not count initial allocator/cache startup as a leak.
        warmup=load(c,routes,a.clients,5,a.rate);require(not any(r['errors'] for r in warmup['operations'].values()),'warmup failed')
        resources=ResourceMonitor(dc, interval_seconds=a.sample_interval).start()
        report['resource_samples_interval_seconds']=a.sample_interval
        # Probe low-rate privileged subsystems under the same traffic and worker
        # contention, without invoking paid AI providers or external integrations.
        probe_hist = {name:Histogram() for name in ('ai_status','support','analytics','integrations','workflows','operations')}
        def platform_probes():
            endpoints = (
                ('ai_status','/api/v1/forum/ai/status',member,lambda d:isinstance(d.get('enabled'),bool) and isinstance(d.get('configured'),bool)),
                ('support','/api/v1/forum/support/tickets',member,lambda d:isinstance(d,list)),
                ('analytics','/api/v1/forum/acp?view=analytics_dashboard',admin,lambda d:isinstance(d.get('totals'),dict)),
                ('integrations','/api/v1/integrations',admin,lambda d:isinstance(d.get('items'),list)),
                ('workflows','/api/v1/platform/workflows',admin,lambda d:isinstance(d.get('rules'),list)),
                ('operations','/api/v1/system/operations',admin,lambda d:all(d['checks'].get(name,{}).get('ok') is True for name in ('database','redis','queue','worker','analytics','ai_admission','integrations'))),
            )
            for name, endpoint, headers, validate in endpoints:
                begin=time.monotonic(); body=call(endpoint,headers=headers)[1]
                payload=json.loads(body) if name=='operations' else data(body)
                require(validate(payload),'platform probe failed: '+name)
                probe_hist[name].add((time.monotonic()-begin)*1000)
        import threading
        stop_writes = threading.Event()
        def sustained_writes():
            sequence=100; next_write=time.monotonic()+60
            while not stop_writes.wait(max(0,next_write-time.monotonic())):
                pid,body,ms=publish(sequence);expected[pid]=body;publication.add(ms)
                if a.profile != 'smoke' and current_policy(a.version): platform_probes()
                sequence+=1;next_write+=60
        def progress(rows):
            if writes.done():
                writes.result()
                raise RuntimeError('sustained writer stopped unexpectedly')
            report['samples'].append({'elapsed_seconds':int(time.time())-report['started_at'],'operations':rows});save()
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as writer:
            writes=writer.submit(sustained_writes)
            try:
                result=load(c,routes,a.clients,a.seconds,a.rate,progress)
            finally:
                stop_writes.set();writes.result(timeout=20)
        report['load']=result
        if a.profile != 'smoke' and current_policy(a.version):
            report['platform_probes']={name:h.report() for name,h in probe_hist.items()}
            require(all(h.total >= max(1,a.seconds//60-1) for h in probe_hist.values()),'missing sustained platform probes')
            check('mixed_platform_probes')
        save()
        assert_load(result,a.max_p95_ms,a.max_error_rate,a.min_rps);check('load_thresholds')
        report['resources']=resources.finish()
        report['resource_observations']=resources.samples
        for service, row in report['resources']['services'].items():
            require(row['memory_growth_bytes']<=a.max_growth_mib*1048576,'memory growth threshold: '+service)
        check('cpu_ram_io_postgres_telemetry')
        check('memory_budget')
        # Stop the queue delivery poller BEFORE deliberate database/Redis outages;
        # a 503 inside a controlled fault window is not steady-state queue latency.
        report['queue_delivery']=queue.finish()
        require(report['queue_delivery']['errors']==0,'queue delivery errors')
        require(report['queue_delivery']['p95_upper_ms']<=a.max_queue_p95_ms,'queue delivery p95 threshold')
        check('queue_delivery_latency')
        # Explicit fault windows are excluded from normal latency/error thresholds.
        if a.faults:
            worker_begin=time.monotonic()
            dc.run('kill','-s','SIGKILL','worker');dc.run('stop','worker')
            queue_job = None
            try:
                pid,body,ms=publish(8,False);expected[pid]=body;publication.add(ms);verify_posts(thread,expected)
                if a.chaos:
                    # Enqueue through the *actual* CLI while the only worker is
                    # stopped. The DB record must survive SIGKILL and then
                    # transition from pending to processed after worker restart.
                    payload=json.dumps({'message':'Chaos durable queue '+report['run_id'],
                                        '_idempotency_key':'chaos-queue-'+report['run_id']})
                    raw=dc.run('exec','-T','app','php','bin/cajeer','queue:push','log.message','--payload='+payload)
                    queue_job=json.loads(raw)['id']
                    require(re.fullmatch(r'[a-f0-9-]{36}',queue_job) is not None,'Invalid queue job identity')
                    require(dc.sql("SELECT status FROM ce_jobs WHERE id='"+queue_job+"'::uuid")=='pending',
                            'Queued task was not durable during worker shutdown')
            finally: dc.run('start','worker')
            wait_until(lambda:any(x.get('post_id')==pid for x in data(call('/api/v1/forum/notifications',headers=admin)[1])['items']),420,'worker delivery after crash/lease expiry')
            if queue_job:
                wait_until(lambda:dc.sql("SELECT status FROM ce_jobs WHERE id='"+queue_job+"'::uuid")=='processed',
                           420,'durable queue replay after SIGKILL')
                report['checks']['durable_queue_replay'] = True
                check('durable_queue_replay')
            report.setdefault('fault_recovery_seconds',{})['worker']=round(time.monotonic()-worker_begin,3)
            check('worker_crash_recovered')
            for service in ('postgres','redis'):
                begin=time.monotonic();dc.run('stop',service)
                try:
                    wait_until(lambda:c.request('/health/ready')[0]==503,60,service+' readiness failure')
                    # Do not retry writes whose commit outcome is unknown. A deliberate
                    # rejected write must never return a successful publication.
                    code,_,_=c.request(path+'/posts',method='POST',headers=member,data={'body':'Rejected fault '+report['run_id']+' '+service})
                    require(code>=500,'write admitted while mandatory dependency unavailable')
                finally: recover(service)
                verify_posts(thread,expected)
                report.setdefault('fault_recovery_seconds',{})[service]=round(time.monotonic()-begin,3);check(service+'_recovered')
            # New publication after both dependency outages proves writes resume.
            pid,body,ms=publish(9,False);expected[pid]=body;publication.add(ms)
        if a.chaos:
            run_chaos(dc,c,project=a.project,path=path,member=member,
                      marker_prefix='Rejected fault '+report['run_id']+' chaos ',
                      wait_ready=assert_ready,
                      verify_posts=lambda:verify_posts(thread,expected),
                      report=report,save=save)
            post=load(c,routes,a.clients,60,a.rate)
            assert_load(post,a.max_p95_ms,0,a.min_rps)
            report['post_fault_load']=post;check('post_fault_workload')
        verify_posts(thread,expected);report['confirmed_writes']=len(expected);report['publication']=publication.report()
        require(publication.percentile(.95)<=a.max_p95_ms,'publication p95 threshold')
        check('confirmed_writes_preserved')
        policy='/api/v1/forum/categories/'+category+'/permissions'; original=data(call(policy,headers=admin)[1])
        try:
            call(policy,method='PUT',headers=admin,data={'visibility':'hidden','rules':[],'revision':original['revision']})
            call('/forum/threads/'+thread,404);call(path,404)
            require(not any(x['id']==thread for x in data(call('/api/v1/forum/search?q=ContainerSmokeMarker')[1])),'ACL leaked after cache warmup')
            check('acl_revocation')
        finally:
            current=data(call(policy,headers=admin)[1]);call(policy,method='PUT',headers=admin,data={'visibility':original['visibility'],'rules':original['rules'],'revision':current['revision']})
        require(b'ContainerSmokeBody' in call('/forum/threads/'+thread)[1],'SSR cache not revalidated after ACL restore')
        require(any(x['id']==thread for x in data(call('/api/v1/forum/search?q=ContainerSmokeMarker')[1])),'FTS cache not revalidated after ACL restore')
        check('cache_invalidation')
        dc.run('exec','-T','app','php','bin/cajeer','forum:ecosystem:check','--deep','--json',timeout=600);check('ecosystem_integrity')
        report['ok']=True;report['finished_at']=int(time.time());save()
    except BaseException as exc:
        if 'resources' in locals() and resources._thread is not None and resources._thread.is_alive():
            resources._stop.set();resources._thread.join(timeout=20)
        if 'queue' in locals():
            queue._stop.set()
            if queue._thread is not None: queue._thread.join(timeout=5)
        report['failure']=type(exc).__name__;report['finished_at']=int(time.time());save();raise


def main():
    p=argparse.ArgumentParser(description=__doc__)
    for name in ('project','domain','email','version','disposable'):p.add_argument('--'+name,required=True)
    for name in ('env','ca','password-file','state','report'):p.add_argument('--'+name,type=Path,required=True)
    p.add_argument('--profile', choices=('smoke','10k','100k','1m-soak'),default='smoke')
    p.add_argument('--signed-dist',type=Path);p.add_argument('--trusted-key',type=Path)
    p.add_argument('--sample-interval',type=int,default=30)
    p.add_argument('--max-queue-p95-ms',type=int,default=30000)
    p.add_argument('--port',type=int,default=18443);p.add_argument('--clients',type=int,default=8);p.add_argument('--seconds',type=int,default=60);p.add_argument('--rate',type=float,default=10);p.add_argument('--min-rps',type=float,default=5);p.add_argument('--max-p95-ms',type=int,default=1000);p.add_argument('--max-error-rate',type=float,default=.001);p.add_argument('--max-growth-mib',type=int,default=256);p.add_argument('--seed-posts',type=int,default=0);p.add_argument('--faults',action='store_true');p.add_argument('--chaos',action='store_true',help='destructive FPM/network/tmpfs/migration faults on named disposable projects only')
    a=p.parse_args();require(1<=a.clients<=200 and 5<=a.seconds<=86400 and 0.1<=a.rate<=1000 and 0<a.min_rps<=a.rate and 0<=a.seed_posts<=1000000 and a.seed_posts%100==0 and 0<=a.max_error_rate<=.01 and a.max_p95_ms in BOUNDS and 1<=a.max_growth_mib<=8192,'invalid qualification limits')
    require(5<=a.sample_interval<=60 and a.max_queue_p95_ms in BOUNDS,'invalid telemetry limits')
    minimum={'smoke':(0,5),'10k':(10000,300),'100k':(100000,1800),'1m-soak':(1000000,86400)}[a.profile]
    if a.profile!='smoke':
        require(a.seed_posts==minimum[0] and a.seconds>=minimum[1], 'certification profile corpus/duration mismatch')
    if a.profile != 'smoke' and a.version == '1.5.0':
        require(a.signed_dist is not None and a.trusted_key is not None, 'signed deployment verification required')
    if a.profile != 'smoke' and current_policy(a.version):
        validate_thresholds(a.profile, {'clients':a.clients,'target_rps':a.rate,'minimum_rps':a.min_rps,'max_p95_ms':a.max_p95_ms,'max_error_rate':a.max_error_rate,'max_memory_growth_mib':a.max_growth_mib,'max_queue_p95_ms':a.max_queue_p95_ms})
    if a.chaos:
        require(a.faults and any(word in a.project for word in ('load','qualification','benchmark','chaos')), 'chaos requires --faults and disposable project naming')
    if a.profile=='1m-soak':
        require(a.clients>=8 and a.faults and a.chaos, 'full soak requires concurrent writes and complete chaos recovery')
    qualify(a)
if __name__=='__main__':main()
