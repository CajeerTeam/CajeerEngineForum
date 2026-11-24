<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Security\{RateLimiter,SecretEnvironment,AbuseIdentity,SecurityMaintenance};
use CajeerEngine\Audit\{AuditLogger,AuditContext};
use CajeerEngine\Observability\Metrics;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function reject(callable $fn,string $label):void{try{$fn();}catch(RuntimeException){check(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
$root=sys_get_temp_dir().'/ce-security147-'.bin2hex(random_bytes(8));mkdir($root.'/storage',0700,true);$src=dirname(__DIR__);mkdir($root.'/config');foreach(glob($src.'/config/*.php') as $f)copy($f,$root.'/config/'.basename($f));copy($src.'/VERSION',$root.'/VERSION');copy($src.'/release.json',$root.'/release.json');
try{
    putenv('RATE_LIMIT_DRIVER=file');putenv('OPENAI_API_KEY');putenv('OPENAI_API_KEY_FILE');
    $secret='sk-proj-'.str_repeat('Z',40);$file=$root.'/secret';file_put_contents($file,$secret."\n");chmod($file,0600);putenv('OPENAI_API_KEY_FILE='.$file);
    check(SecretEnvironment::get('OPENAI_API_KEY')===$secret&&getenv('OPENAI_API_KEY')===false,'mounted secret read without environment export');
    check(!str_contains(json_encode(SecretEnvironment::status()),$secret)&&!str_contains(json_encode(SecretEnvironment::status()),$file),'secret diagnostics omit values and paths');
    putenv('OPENAI_API_KEY=conflict');reject(fn()=>SecretEnvironment::get('OPENAI_API_KEY'),'conflicting secret sources rejected');putenv('OPENAI_API_KEY');
    chmod($file,0644);reject(fn()=>SecretEnvironment::get('OPENAI_API_KEY'),'world-readable credential refused');chmod($file,0600);symlink($file,$root.'/link');putenv('OPENAI_API_KEY_FILE='.$root.'/link');reject(fn()=>SecretEnvironment::get('OPENAI_API_KEY'),'symlink refused');putenv('OPENAI_API_KEY_FILE');
    check(AbuseIdentity::network('::ffff:192.0.2.1')===AbuseIdentity::network('192.0.2.1'),'mapped IP shares counter');check(AbuseIdentity::network('2001:db8:1:2::a')===AbuseIdentity::network('2001:db8:1:2::b'),'IPv6 rotating addresses share network');
    check(AbuseIdentity::account(' A@EXAMPLE.TEST ')===AbuseIdentity::account('a@example.test'),'canonical account identity');
    $context=['token_id'=>'id-123','before'=>['enabled'=>false],'after'=>['enabled'=>true],'nested'=>['clientSecret'=>'hidden','email'=>'a@example.test','error'=>'Bearer '.$secret,'payload'=>['text'=>'hidden']]];
    $audit=new AuditLogger($root);$audit->record('forum.test','id-1',$context);$raw=file_get_contents($root.'/storage/logs/audit.jsonl');
    check(!str_contains($raw,$secret)&&!str_contains($raw,'hidden')&&!str_contains($raw,'@example.test')&&str_contains($raw,'id-123'),'file audit sanitizes nested credentials and preserves identifiers');
    check($audit->count()===1&&$audit->recent()[0]['context']['after']['enabled']===true,'actual audit reads preserve policy diffs');
    check((fileperms($root.'/storage/logs/audit.jsonl')&0077)===0,'private audit permissions');
    unlink($root.'/storage/logs/audit.jsonl');symlink($file,$root.'/storage/logs/audit.jsonl');reject(fn()=>$audit->record('test'),'audit symlink rejected');check(file_get_contents($file)===$secret."\n",'audit cannot overwrite credential');unlink($root.'/storage/logs/audit.jsonl');
    check(AuditContext::network('192.0.2.189')==='192.0.2.0'&&AuditContext::network('2001:db8:1234:5678::1')==='2001:db8:1234::','network minimization');
    $lim=new RateLimiter($root);check($lim->hit('auth','test',1,60)['allowed']&&!$lim->hit('auth','test',1,60)['allowed'],'actual file limiter rejects excess');
    $state=$root.'/storage/rate-limits/auth-'.hash('sha256','test').'.json';file_put_contents($state,json_encode(['hits'=>1,'reset_at'=>time()-120]));check($lim->cleanup()===1&&!file_exists($state)&&$lim->hit('auth','test',1,60)['allowed'],'expired file counter cleaned and recreated');
    putenv('RATE_LIMIT_DRIVER=invalid');check(!$lim->hit('auth','test',10,60)['allowed'],'invalid driver fails closed');putenv('RATE_LIMIT_DRIVER=file');
    if(in_array('--database',$argv,true)){
        require_once $src.'/vendor/autoload.php';putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$root.'/test.sqlite');$cfg=new ConfigRepository($root);$db=new DatabaseManager($cfg);$pdo=$db->connection();$pdo->exec('CREATE TABLE ce_audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,actor_id TEXT,context TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        (new AuditLogRepository($db))->record('test',null,$context,'192.0.2.189','private-UA');$row=$pdo->query('SELECT * FROM ce_audit_log')->fetch(PDO::FETCH_ASSOC);check($row['ip_address']==='192.0.2.0'&&$row['user_agent']===null&&!str_contains($row['context'],$secret),'real SQL writer minimizes personal metadata');
        $pdo->exec("INSERT INTO ce_audit_log(event,context,ip_address,user_agent,created_at) VALUES('old','{}','192.0.2.5','old-UA','2000-01-01 00:00:00')");$res=(new SecurityMaintenance($root,$db))->run();check($res['audit_network_rows_minimized']===1&&(int)$pdo->query('SELECT COUNT(*) FROM ce_audit_log WHERE ip_address IS NULL')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM ce_audit_log')->fetchColumn()===2,'maintenance clears old metadata without deleting audit records');
        $cfg->set('security.rate_limits.login_pair',2);$cfg->set('security.rate_limits.login_account',3);$mw=new CajeerEngine\Http\Middleware\LoginAbuseMiddleware($cfg,$lim);$next=fn($r)=>new Symfony\Component\HttpFoundation\Response('',201);$call=function($ip,$email)use($mw,$next){$r=Symfony\Component\HttpFoundation\Request::create('/api/v1/auth/login','POST',[],[],[],['REMOTE_ADDR'=>$ip]);$r->attributes->set('matched_route','api.v1.auth.login');$r->attributes->set('json',['email'=>$email]);return $mw->process($r,$next);};
        check($call('192.0.2.1','one@example.test')->getStatusCode()===201&&$call('192.0.2.1','ONE@example.test')->getStatusCode()===201&&$call('192.0.2.1','one@example.test')->getStatusCode()===429,'real middleware pair admission');check($call('192.0.2.2','one@example.test')->getStatusCode()===201&&$call('192.0.2.3','one@example.test')->getStatusCode()===429,'real middleware account limit across IPs');
    }
    if(in_array('--redis',$argv,true)){
        putenv('RATE_LIMIT_DRIVER=redis');putenv('FORUM_REDIS_NAMESPACE=security147-'.bin2hex(random_bytes(6)));$r=new RateLimiter($root);$second=new RateLimiter($root);check($r->hit('auth','shared',2,2)['allowed']&&$second->hit('auth','shared',2,2)['allowed']&&!$r->hit('auth','shared',2,2)['allowed'],'real Redis shares atomic admission across connections');sleep(3);check($second->hit('auth','shared',2,2)['allowed'],'real Redis TTL resets counter');
    }
    putenv('RATE_LIMIT_DRIVER=redis');putenv('REDIS_PORT=1');check(!$lim->hit('auth','unavailable',1,1)['allowed'],'Redis failure denies without file fallback');Metrics::flushAll();
}finally{Metrics::flushAll();foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);}
