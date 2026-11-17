<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\ConnectionFactory;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Scheduler\TaskRunStore;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Support\JsonFile;

/** Cheap readiness and explicit, sanitized operational diagnostics. */
final readonly class OperationsMonitor
{
    private ConfigRepository $config;
    public function __construct(private string $root,?ConfigRepository $config=null){$this->config=$config??new ConfigRepository($root);}
    public function readiness(): array
    {
        $checks=[];
        $checks['dependencies']=is_file($this->root.'/vendor/autoload.php')&&class_exists(\Symfony\Component\HttpFoundation\Request::class)&&class_exists(\Symfony\Component\Routing\RouteCollection::class)&&extension_loaded('mbstring')&&extension_loaded('sodium')&&extension_loaded('fileinfo')&&extension_loaded('pdo');
        $checks['installed']=is_file($this->root.'/storage/app/installed.lock')||is_file($this->root.'/storage/installed.lock');
        $checks['maintenance']=!OperationLock::maintenance($this->root);
        $checks['storage']=is_writable($this->root.'/storage/app')&&is_writable($this->root.'/storage/logs')&&is_writable($this->root.'/storage/cache');
        $lock=null;try{$lock=OperationLock::acquire($this->root,false);$checks['activity_lock']=true;}catch(\Throwable){$checks['activity_lock']=false;}
        // During recovery never touch the database or partially replaced code.
        $checks['database']=false;
        if ($checks['installed'] && $checks['maintenance'] && $checks['activity_lock']) {
            try {
                $this->assertDatabasePresent();
                $db = new DatabaseManager($this->config);
                $pdo = $db->connection();
                foreach (['ce_content_entries','ce_users','ce_public_routes','ce_cms_search'] as $table) {
                    $pdo->query('SELECT 1 FROM '.$table.' WHERE 1=0');
                }
                $ledger = (new \CajeerEngine\Database\MigrationRunner($pdo, $this->root.'/migrations'))->verifyApplied($db->migrationDriver());
                $checks['database'] = $ledger['ok'] && (new \CajeerEngine\Forum\ForumDatabaseCheck($db))->check()['ok'];
            } catch (\Throwable) { /* Readiness exposes no credentials or SQL. */ }
        }
        // Redis is mandatory for distributed admission control and Redis-backed jobs.
        // A cache-only Redis outage may degrade performance, but must not block readiness.
        $requiredRedis=getenv('RATE_LIMIT_DRIVER')==='redis'||$this->config->string('queue.default')==='redis';
        $checks['redis_required']=!$requiredRedis;
        // A Redis ping is safe during a maintenance lease. Keep checking an
        // indispensable rate-limit/queue backend even while public traffic is
        // intentionally denied. Otherwise post-update readiness misreports it.
        if($requiredRedis&&$checks['installed']){
            $redis=null;
            try{$redis=\CajeerEngine\Cache\RedisConnection::open();$checks['redis_required']=(bool)$redis->ping();}
            catch(\Throwable){$checks['redis_required']=false;}
            finally{if($redis!==null)try{$redis->close();}catch(\Throwable){}}
        }
        OperationLock::release($lock);
        $free=@disk_free_space($this->root.'/storage');$checks['disk']=$free!==false&&$free>=$this->minFree();
        return ['ok'=>!in_array(false,$checks,true),'status'=>in_array(false,$checks,true)?'not_ready':'ready','checks'=>$checks];
    }
    /**
     * Public HTTP health is a high-volume load-balancer endpoint. Rechecking
     * every migration and forum table on each probe exhausts the application
     * database under concurrent traffic. Reuse only a recent *healthy* schema
     * report; test the live database and mandatory Redis on every response.
     * Full operator diagnostics and readiness() remain uncached.
     */
    public function cachedReadiness(): array
    {
        if (OperationLock::maintenance($this->root)) return $this->readiness();

        try {
            $dir = $this->root.'/storage/cache';
            \CajeerEngine\Update\OperationFiles::dir($dir, 0770);
            $file = new JsonFile($dir.'/http-readiness.json');
            $saved = $file->readObject();
            $now = time();
            if ($this->healthySample($saved, $now, 10) && $this->liveReadinessCheck($saved['migration_fingerprint'] ?? null)) {
                return $saved['readiness'];
            }
            $h = @fopen($dir.'/http-readiness.lock', 'c+');
            if ($h === false) return $this->readiness();
            try {
                if (!flock($h, LOCK_EX | LOCK_NB)) {
                    // Another request is refreshing the expensive schema checks.
                    // A bounded fallback is allowed only after fresh DB/Redis
                    // probes, never during maintenance or on a failed sample.
                    if ($this->healthySample($saved, $now, 30) && $this->liveReadinessCheck($saved['migration_fingerprint'] ?? null)) {
                        return $saved['readiness'];
                    }
                    return $this->readiness();
                }
                $saved = $file->readObject();
                if ($this->healthySample($saved, time(), 10) && $this->liveReadinessCheck($saved['migration_fingerprint'] ?? null)) {
                    return $saved['readiness'];
                }
                $ready = $this->readiness();
                if ($ready['ok']) {
                    // A healthy schema sample is reusable only while the live
                    // migration ledger still matches. The check is one bounded
                    // read, not an unverified positive cache entry.
                    $fingerprint = $this->migrationFingerprint($this->probeConnection());
                    $file->writeObject(['sampled_at' => time(), 'migration_fingerprint' => $fingerprint, 'readiness' => $ready]);
                }
                return $ready;
            } finally {
                flock($h, LOCK_UN);
                fclose($h);
            }
        } catch (\Throwable) {
            // A broken cache must never make an unhealthy system look ready.
            return $this->readiness();
        }
    }

    private function healthySample(array $saved, int $now, int $maxAge): bool
    {
        return (int)($saved['sampled_at'] ?? 0) >= $now - $maxAge
            && is_array($saved['readiness'] ?? null)
            && ($saved['readiness']['ok'] ?? false) === true;
    }

    /**
     * Read-only operational connection. A persistent PDO cannot accept
     * PDO::ATTR_STATEMENT_CLASS, which MeasuredPDO always installs; therefore
     * it must not be constructed through the measured transactional manager.
     * This probe has no application transaction or session-level write state.
     */
    private function probeConnection(): \PDO
    {
        $database = $this->config->get('database', []);
        if ($this->config->string('database.default') === 'pgsql') {
            $database['connections']['pgsql']['persistent'] = true;
        } elseif ($this->config->string('database.default') === 'sqlite') {
            $path = $database['connections']['sqlite']['database'] ?? '';
            if (is_string($path) && $path !== '' && $path !== ':memory:' && !str_starts_with($path, '/')) {
                $database['connections']['sqlite']['database'] = $this->root.'/'.ltrim($path, '/');
            }
        }
        return (new ConnectionFactory($database))->create();
    }

    private function migrationFingerprint(\PDO $pdo): string
    {
        // Include names and checksums, not just a migration count. A changed or
        // deleted checksum must invalidate the cached positive health result.
        $rows = $pdo->query('SELECT migration, checksum FROM ce_migrations ORDER BY migration')->fetchAll(\PDO::FETCH_NUM);
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function liveReadinessCheck(?string $expectedFingerprint): bool
    {
        // Old cache records (without a ledger fingerprint) are not trusted.
        if (!is_string($expectedFingerprint) || !preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint)) return false;
        $lease = null;
        try {
            $lease = OperationLock::acquire($this->root, false);
            if (OperationLock::maintenance($this->root)) return false;
            $this->assertDatabasePresent();
            $pdo = $this->probeConnection();
            if ($pdo->query('SELECT 1')->fetchColumn() === false) return false;
            if (!hash_equals($expectedFingerprint, $this->migrationFingerprint($pdo))) return false;
            $requiredRedis = getenv('RATE_LIMIT_DRIVER') === 'redis'
                || $this->config->string('queue.default') === 'redis';
            if ($requiredRedis) {
                $redis = \CajeerEngine\Cache\RedisConnection::open();
                try { if (!$redis->ping()) return false; }
                finally { $redis->close(); }
            }
            $free = @disk_free_space($this->root.'/storage');
            return $free !== false && $free >= $this->minFree();
        } catch (\Throwable) {
            return false;
        } finally {
            OperationLock::release($lease);
        }
    }

    public function report(): array
    {
        $ready=$this->readiness();$checks=[];$alerts=[];
        $probe=function(string $name,callable $fn)use(&$checks,&$alerts){$start=hrtime(true);try{$data=$fn();$ok=(bool)($data['ok']??true);$checks[$name]=['ok'=>$ok,'latency_ms'=>round((hrtime(true)-$start)/1e6,3)]+$data;if(!$ok)$alerts[]=$name.'_unavailable';}catch(\Throwable){$checks[$name]=['ok'=>false,'latency_ms'=>round((hrtime(true)-$start)/1e6,3),'error'=>'probe_failed'];$alerts[]=$name.'_unavailable';}};
        $lock=null;try{$lock=OperationLock::acquire($this->root,false);if(OperationLock::maintenance($this->root))throw new \RuntimeException('Maintenance');}catch(\Throwable){OperationLock::release($lock);return ['ok'=>false,'status'=>'not_ready','time'=>gmdate(DATE_ATOM),'readiness'=>$ready,'checks'=>[],'disk'=>['free_bytes'=>null,'total_bytes'=>null],'alerts'=>['maintenance_or_activity_lock']];}
        try {
        $db=new DatabaseManager($this->config);
        $probe('database',function()use($db){$this->assertDatabasePresent();$h=$db->health();return ['ok'=>(bool)($h['ok']??false)&&(bool)($h['schema_ready']??false),'driver'=>$db->driver(),'schema_ready'=>(bool)($h['schema_ready']??false)];});
        $probe('forum_database',function()use($db){$this->assertDatabasePresent();return ForumMetrics::database($db);});
        $probe('forum_cache',fn()=>ForumMetrics::cache());
        $probe('storage',function(){ $s=new StorageManager($this->root,$this->config);$d=$s->diagnostics();return ['ok'=>(bool)($d['local_probe_write']??false)&&(bool)($d['local_probe_delete']??false)&&(bool)($d['driver_probe']['ok']??false),'driver'=>$s->driver()];});
        $probe('queue',function()use($db){if($this->config->string('queue.default')==='pgsql')$this->assertDatabasePresent();$d=(new QueueFactory($this->root,$this->config,$db))->make()->diagnostics();$counts=array_fill_keys(['pending','reserved','processed','failed'],0);foreach($d['queues']??[] as $q)foreach($counts as $k=>$v)$counts[$k]+=(int)($q[$k]??0);return ['ok'=>($d['writable']??true)&&($d['connected']??true),'driver'=>$d['driver']??'unknown','counts'=>$counts,'oldest_ready_age_seconds'=>(int)($d['oldest_ready_age_seconds']??0),'expired_reservations'=>(int)($d['expired_reservations']??0)];});
        $probe('scheduler',function()use($db){if($this->config->string('scheduler.driver')==='pgsql')$this->assertDatabasePresent();$store=TaskRunStore::fromConfig($this->root,$this->config,$db);$tasks=[];foreach(['tmp.cleanup'=>3600,'queue.cleanup_processed'=>86400,'search.index_published'=>60,'webhooks.dispatch_pending'=>60] as $name=>$interval){$s=$store->status($name);$last=strtotime((string)($s['last_success_at']??''))?:0;$tasks[$name]=['status'=>$s['status']??'never','last_success'=>$last,'stale'=>$last===0||time()-$last>max(180,$interval*2)];}return ['ok'=>!in_array(true,array_column($tasks,'stale'),true),'tasks'=>$tasks];});
        $probe('cache',fn()=> $this->cache());
        $probe('search',function()use($db){$this->assertDatabasePresent();$d=(new \CajeerEngine\Search\SearchFactory($this->root,$this->config,$db))->make()->diagnostics();return ['ok'=>(bool)($d['ok']??$d['writable']??true),'documents'=>(int)($d['documents']??0)];});
        $probe('backup',fn()=> $this->backup());
        if (is_file($this->root.'/storage/app/recovery-receipt.json')) $probe('cold_recovery',function(){
            $r=\CajeerEngine\Update\OperationFiles::read($this->root.'/storage/app/recovery-receipt.json');
            return ['ok'=>($r['application_boot_tested']??false)===true&&($r['rto_met']??false)===true&&($r['rpo_met']??false)===true,'application_boot_tested'=>($r['application_boot_tested']??false)===true,'edition_version'=>(string)($r['edition_version']??''),'verified_at'=>(string)($r['verified_at']??''),'restore_seconds'=>(int)($r['restore_seconds']??0),'snapshot_age_seconds'=>(int)($r['snapshot_age_seconds']??0),'rto_met'=>($r['rto_met']??false)===true,'rpo_met'=>($r['rpo_met']??false)===true];
        });
        if (is_file($this->root.'/storage/logs/observability/backup-automation.json')) $probe('backup_automation',fn()=> \CajeerEngine\Backup\AutomatedBackup::status($this->root));
        if(TraceExporter::enabled())$probe('traces',function()use($db){$store=TaskRunStore::fromConfig($this->root,$this->config,$db);$state=$store->status('observability.export_traces');$pending=count(glob($this->root.'/storage/logs/observability/traces/span-*.json')?:[]);return ['ok'=>$pending<1000&&($state['status']??'never')!=='failed','pending'=>$pending,'status'=>$state['status']??'never'];});
        $probe('update',function(){ $d=(new JsonFile($this->root.'/storage/logs/observability/update.json'))->readObject();return ['ok'=>($d['status']??'never')!=='failure','last_success'=>(int)($d['last_success']??0),'status'=>$d['status']??'never'];});
        $probe('worker',function(){ $p=$this->root.'/storage/logs/observability/worker.json';$d=(new JsonFile($p))->readObject();$last=(int)($d['timestamp']??0);$success=(int)($d['last_success']??0);return ['ok'=>$success>0&&time()-$success<=max(60,$this->config->int('observability.worker_max_age',300))&&($d['status']??'never')!=='failure','last_seen'=>$last,'last_success'=>$success,'status'=>$d['status']??'never'];});
        $free=@disk_free_space($this->root.'/storage');$total=@disk_total_space($this->root.'/storage');
        if($free===false||$free<$this->minFree())$alerts[]='disk_low';
        if(($checks['queue']['counts']['failed']??0)>0)$alerts[]='queue_failed_jobs';
        if(($checks['queue']['oldest_ready_age_seconds']??0)>$this->config->int('observability.queue_max_age',300))$alerts[]='queue_backlog_stale';
        if(($checks['queue']['expired_reservations']??0)>0)$alerts[]='queue_lease_expired';
        $fq=$checks['forum_database']['queues']??[];
        foreach(['notifications','answer_notifications','mail','extensions','media','replicas'] as $name)if(($fq[$name]['oldest_age_seconds']??0)>$this->config->int('observability.queue_max_age',300))$alerts[]='forum_'.$name.'_stale';
        foreach(['extension_dead','mail_dead','media_failed'] as $name)if(($fq[$name]['count']??0)>0)$alerts[]='forum_'.$name;
        if(($fq['imports']['oldest_age_seconds']??0)>3600)$alerts[]='forum_import_stalled';
        try{$http=(new Metrics($this->root))->httpSummary();}catch(\Throwable){$http=['requests'=>null,'errors_5xx'=>null,'average_ms'=>null,'p95_bucket_ms'=>null];$alerts[]='metrics_unavailable';}
        try{$forumHttp=(new Metrics($this->root))->forumSummary();}catch(\Throwable){$forumHttp=[];}
        return ['ok'=>$ready['ok'],'status'=>!$ready['ok']?'not_ready':($alerts?'degraded':'ready'),'time'=>gmdate(DATE_ATOM),'readiness'=>$ready,'checks'=>$checks,'http'=>$http,'forum_http'=>$forumHttp,'disk'=>['free_bytes'=>$free===false?null:(int)$free,'total_bytes'=>$total===false?null:(int)$total],'alerts'=>$alerts];
        }finally{OperationLock::release($lock);}
    }
    private function assertDatabasePresent(): void
    {
        if($this->config->string('database.default')!=='sqlite')return;
        $path=$this->config->string('database.connections.sqlite.database');
        if(!str_starts_with($path,'/'))$path=$this->root.'/'.$path;
        if(!is_file($path))throw new \RuntimeException('SQLite database missing');
    }
    private function minFree(): int{return max(0,$this->config->int('observability.min_free_bytes',104857600));}
    private function cache(): array
    {
        $driver=$this->config->string('cache.default','file');if($driver!=='redis')return ['ok'=>in_array($driver,['file','array','none'],true),'driver'=>in_array($driver,['file','array','none'],true)?$driver:'unknown','probe'=>'not_required'];
        if(!class_exists(\Redis::class))throw new \RuntimeException('Redis unavailable');
        $r=new \Redis();try{if(!$r->connect($this->config->string('cache.stores.redis.host','127.0.0.1'),$this->config->int('cache.stores.redis.port',6379),1.0))throw new \RuntimeException('Redis unavailable');$r->setOption(\Redis::OPT_READ_TIMEOUT,1.0);$password=$this->config->string('cache.stores.redis.password');if($password!==''&&!$r->auth($password))throw new \RuntimeException('Redis auth failed');if(!$r->select($this->config->int('cache.stores.redis.database',0)))throw new \RuntimeException('Redis select failed');return ['ok'=>(bool)$r->ping(),'driver'=>'redis'];}finally{try{$r->close();}catch(\Throwable){}}
    }
    private function backup(): array
    {
        // Marker is written only after authenticated full backup verification; no key needed by web process.
        $d=(new JsonFile($this->root.'/storage/logs/observability/backup.json'))->readObject();$last=(int)($d['timestamp']??0);
        return ['ok'=>$last>0&&time()-$last<=max(60,$this->config->int('observability.backup_max_age',86400)),'last_success'=>$last,'age_seconds'=>$last>0?max(0,time()-$last):null];
    }
    public static function marker(string $root,string $name,string $status='success'): void
    {
        if(!in_array($name,['backup','worker','update'],true))return;
        try{$dir=$root.'/storage/logs/observability';\CajeerEngine\Update\OperationFiles::dir($dir,0770);$h=fopen($dir.'/'.$name.'.lock','c+');if(!$h)throw new \RuntimeException('Marker lock unavailable');try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Marker lock failed');$file=new JsonFile($dir.'/'.$name.'.json');$old=$file->readObject();$file->writeObject(['timestamp'=>time(),'last_success'=>$status==='success'?time():($old['last_success']??0),'status'=>$status]);}finally{fclose($h);}}catch(\Throwable){error_log('CajeerEngine: operation marker write failed');}
    }
    public function prometheus(): string
    {
        $r=$this->cachedReport();$out=(new Metrics($this->root))->prometheus();
        $out.="# TYPE cajeer_ready gauge\ncajeer_ready ".(int)$r['ok']."\n# TYPE cajeer_dependency_up gauge\n";
        foreach($r['checks'] as $name=>$d)$out.='cajeer_dependency_up{component="'.$name.'"} '.(int)$d['ok']."\n";
        $out.="# TYPE cajeer_dependency_probe_duration_seconds gauge\n";
        foreach($r['checks'] as $name=>$d)if(isset($d['latency_ms']))$out.='cajeer_dependency_probe_duration_seconds{component="'.$name.'"} '.sprintf('%.6F',$d['latency_ms']/1000)."\n";
        if(isset($r['checks']['worker']['last_success']))$out.="# TYPE cajeer_worker_last_success_timestamp_seconds gauge\ncajeer_worker_last_success_timestamp_seconds ".(int)($r['checks']['worker']['last_success']??0)."\n";
        foreach(['free_bytes','total_bytes'] as $key)if($r['disk'][$key]!==null)$out.="# TYPE cajeer_disk_$key gauge\ncajeer_disk_$key ".$r['disk'][$key]."\n";
        $out.="# TYPE cajeer_queue_jobs gauge\n";foreach($r['checks']['queue']['counts']??[] as $k=>$v)$out.='cajeer_queue_jobs{state="'.$k.'"} '.$v."\n";
        $out.="# TYPE cajeer_queue_oldest_ready_age_seconds gauge\ncajeer_queue_oldest_ready_age_seconds ".(int)($r['checks']['queue']['oldest_ready_age_seconds']??0)."\n";
        if(($r['checks']['queue']['ok']??false)===true)$out.="# TYPE cajeer_queue_expired_reservations gauge\ncajeer_queue_expired_reservations ".(int)($r['checks']['queue']['expired_reservations']??0)."\n";
        $out.="# TYPE cajeer_scheduler_task_stale gauge\n";foreach($r['checks']['scheduler']['tasks']??[] as $n=>$t)$out.='cajeer_scheduler_task_stale{task="'.$n.'"} '.(int)($t['stale']??false)."\n";
        $out.="# TYPE cajeer_scheduler_last_success_timestamp_seconds gauge\n";foreach($r['checks']['scheduler']['tasks']??[] as $n=>$t)$out.='cajeer_scheduler_last_success_timestamp_seconds{task="'.$n.'"} '.$t['last_success']."\n";
        foreach(['backup'=>'last_success','worker'=>'last_seen','update'=>'last_success'] as $n=>$k)$out.="# TYPE cajeer_{$n}_{$k}_timestamp_seconds gauge\ncajeer_{$n}_{$k}_timestamp_seconds ".(int)($r['checks'][$n][$k]??0)."\n";
        if(isset($r['checks']['backup_automation'])) {
            $b=$r['checks']['backup_automation'];
            foreach (['last_local'=>'local_last_success_timestamp_seconds','last_offsite'=>'offsite_last_success_timestamp_seconds','pending'=>'offsite_pending','checked_at'=>'automation_last_check_timestamp_seconds'] as $field=>$metric) $out.="# TYPE cajeer_backup_$metric gauge\ncajeer_backup_$metric ".(int)($b[$field]??0)."\n";
        }
        if(isset($r['checks']['traces']['pending']))$out.="# TYPE cajeer_trace_spool_pending gauge\ncajeer_trace_spool_pending ".$r['checks']['traces']['pending']."\n";
        return $out.ForumMetrics::prometheus($r['checks']);
    }
    /** Scrapes reuse a short-lived sample; a concurrent scrape never queues expensive probes. */
    public function cachedReport(): array
    {
        $dir=$this->root.'/storage/logs/observability';\CajeerEngine\Update\OperationFiles::dir($dir,0770);
        $file=new JsonFile($dir.'/report.json');$now=time();
        if(OperationLock::maintenance($this->root))return $this->report();
        try{$lease=OperationLock::acquire($this->root,false);OperationLock::release($lease);}catch(\Throwable){return $this->report();}
        $saved=$file->readObject();if(($saved['sampled_at']??0)>=$now-15)return $saved['report'];
        $h=fopen($dir.'/report.lock','c+');if(!$h)throw new \RuntimeException('Cannot lock diagnostics');
        try{
            if(!flock($h,LOCK_EX|LOCK_NB)){if(($saved['sampled_at']??0)>=$now-60&&isset($saved['report']))return $saved['report'];throw new \RuntimeException('Diagnostic sample in progress');}
            $saved=$file->readObject();if(($saved['sampled_at']??0)>=$now-15)return $saved['report'];
            $report=$this->report();$file->writeObject(['sampled_at'=>time(),'report'=>$report]);return $report;
        }finally{fclose($h);}
    }
}
