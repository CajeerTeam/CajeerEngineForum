<?php
/** Real Redis + SQLite/PostgreSQL, Symfony HTTP and independent worker connections. */
declare(strict_types=1);
use CajeerEngine\Cache\RedisConnection;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{RoleRepository,UserRepository};
use CajeerEngine\Kernel\Application;
use CajeerEngine\Queue\{QueueFactory,QueueWorker};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\{PasswordHasher,RateLimiter};
use Symfony\Component\HttpFoundation\Request;
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')==='pgsql'?'pgsql':'sqlite';
if($driver==='pgsql'&&(getenv('DB_DATABASE')!=='forum_test_redis'||getenv('DB_HOST')!=='127.0.0.1'))throw new RuntimeException('Expected disposable loopback forum_test_redis');
$tmp=sys_get_temp_dir().'/forum-redis-'.bin2hex(random_bytes(6));mkdir($tmp,0700);$ns='test-'.bin2hex(random_bytes(8));$queuePrefix='ce:test:'.$ns.':';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
foreach(['APP_ENV=testing','APP_URL=https://forum.test','APP_NAME=Forum Redis Test','FORUM_SEO_INDEX=true','FORUM_SEO_EXCLUDED_BOARDS=','DB_DRIVER='.$driver,'SQLITE_DATABASE='.$tmp.'/forum.sqlite','DB_SQLITE_WAL=true','CACHE_DRIVER=file','STORAGE_DRIVER=local','CONTENT_STORAGE=database','CMS_STORAGE=database','QUEUE_DRIVER=redis','QUEUE_REDIS_PREFIX='.$queuePrefix,'FORUM_REDIS_NAMESPACE='.$ns,'FORUM_CACHE_ENABLED=true','FORUM_CACHE_TTL_SECONDS=60','RATE_LIMIT_DRIVER=file','RATE_LIMIT_GLOBAL=10000','RATE_LIMIT_AUTH=10000','FORUM_REGISTRATION=true','FORUM_REQUIRE_EMAIL_VERIFICATION=false','PASSWORD_ARGON2_MEMORY=8192','PASSWORD_ARGON2_TIME=2','FORUM_MEDIA_S3_REPLICA=false'] as $v)putenv($v);
$r=RedisConnection::open();$prefix=RedisConnection::namespace();
try{
    $cfg=new ConfigRepository($root);$db=new DatabaseManager($cfg);
    if($driver==='pgsql'&&$db->tableExists('ce_users'))throw new RuntimeException('Expected empty forum_test_redis');
    (new MigrationRunner($db->connection(),$root.'/migrations'))->run($driver);
    $roles=new RoleRepository($db);$roles->seedDefaults();(new UserRepository($db,$roles,new PasswordHasher($cfg)))->createAdmin('admin@example.test','Admin','AdminPass12345');
    $app=Application::boot($root);$second=Application::boot($root);
    $http=function(string $method,string $path,?array $data=null,?string $token=null,?Application $server=null)use($app){$headers=['CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'];if($token)$headers['HTTP_AUTHORIZATION']='Bearer '.$token;return ($server??$app)->handle(Request::create('https://forum.test'.$path,$method,[],[],[],$headers,$data===null?null:json_encode($data,JSON_THROW_ON_ERROR)));};
    $api=function(string $method,string $path,?array $data=null,?string $token=null,int $status=200,?Application $server=null)use($http){$res=$http($method,'/api/v1'.$path,$data,$token,$server);check($res->getStatusCode()===$status,"$method $path status $status: ".substr((string)$res->getContent(),0,200));return json_decode((string)$res->getContent(),true,512,JSON_THROW_ON_ERROR)['data']??[];};
    $board=$api('GET','/forum')['boards'][0]['id'];
    $member=$api('POST','/forum/register',['name'=>'Member','email'=>'member@example.test','password'=>'MemberPass12345'],null,201)['id'];
    $other=$api('POST','/forum/register',['name'=>'Other','email'=>'other@example.test','password'=>'OtherPass12345'],null,201)['id'];
    $login=fn($email,$password)=>$api('POST','/auth/login',['email'=>$email,'password'=>$password],null,201)['access_token'];
    $m=$login('member@example.test','MemberPass12345');$o=$login('other@example.test','OtherPass12345');$a=$login('admin@example.test','AdminPass12345');
    $t=$api('POST',"/forum/boards/$board/threads",['title'=>'Redis test','body'=>'Original marker'],$m,201)['id'];
    $path='/forum/threads/'.$t;
    $publicSearch=fn()=>array_column($api('GET','/forum/search?q=Redis'),'id');
    $sitemap=fn()=>(string)$http('GET','/forum/sitemap.xml?page=1')->getContent();
    $rss=fn()=>(string)$http('GET','/forum/rss.xml')->getContent();
    check(in_array($t,$publicSearch(),true)&&str_contains($sitemap(),$t)&&str_contains($rss(),$t),'public search, sitemap and RSS agree');
    $first=$http('GET',$path);$hit=$http('GET',$path,null,null,$second);
    check($first->headers->get('X-Forum-Cache')==='MISS'&&$hit->headers->get('X-Forum-Cache')==='HIT'&&$first->getContent()===$hit->getContent(),'SSR Redis hit shared by independent application instances');
    // Draft saves do not invalidate public HTML; activation does across instances.
    $theme=$api('GET','/forum/acp?view=themes',null,$a);$design=$theme['presets']['ocean'];
    $theme=$api('PUT','/forum/acp/theme',['revision'=>$theme['revision'],'action'=>'draft','design'=>$design],$a);
    $draftHit=$http('GET',$path,null,null,$second);check($draftHit->headers->get('X-Forum-Cache')==='HIT'&&$draftHit->getContent()===$hit->getContent(),'draft theme leaves public cache intact');
    $preview=$http('GET',$path.'?theme_preview=1&scheme=dark&revision='.$theme['revision'],null,$a);
    check($preview->headers->get('X-Forum-Cache')===null&&str_contains((string)$preview->getContent(),'--bg:#081b23'),'preview bypasses Redis');
    $theme=$api('PUT','/forum/acp/theme',['revision'=>$theme['revision'],'action'=>'activate'],$a);
    $themed=$http('GET',$path,null,null,$second);check($themed->headers->get('X-Forum-Cache')==='MISS'&&str_contains((string)$themed->getContent(),'--bg:#081b23'),'activation changes shared HTML cache identity');
    check($http('GET',$path)->headers->get('X-Forum-Cache')==='HIT','new theme cache shared');
    $theme=$api('PUT','/forum/acp/theme',['revision'=>$theme['revision'],'action'=>'restore','index'=>0],$a);
    check($http('GET',$path,null,null,$second)->getContent()===$hit->getContent(),'restore returns original HTML representation');
    $first=$http('GET','/api/v1'.$path);$hit=$http('GET','/api/v1'.$path,null,null,$second);check($first->headers->get('X-Forum-Cache')==='MISS'&&$hit->headers->get('X-Forum-Cache')==='HIT'&&$first->getContent()===$hit->getContent(),'API shared cache preserves JSON bytes');
    $cursor=$http('GET','/forum/latest');$numbered=$http('GET','/forum/latest?page=1');
    check($cursor->headers->get('X-Forum-Cache')==='MISS'&&$numbered->headers->get('X-Forum-Cache')==='MISS'&&str_contains((string)$cursor->getContent(),'aria-label="Навигация"')&&str_contains((string)$numbered->getContent(),'aria-label="Страницы"'),'numbered and cursor HTML have distinct cache keys');
    check($http('GET','/forum/latest',null,null,$second)->getContent()===$cursor->getContent()&&$http('GET','/forum/latest?page=1',null,null,$second)->getContent()===$numbered->getContent(),'cache hits retain each pagination representation');
    putenv('FORUM_SEO_INDEX=false');$noindex=$http('GET',$path,null,null,$second);
    check($noindex->headers->get('X-Forum-Cache')==='MISS'&&str_contains((string)$noindex->headers->get('X-Robots-Tag'),'noindex')&&$http('GET','/forum/sitemap.xml')->getStatusCode()===404,'global SEO change invalidates HTML representation without changing content');
    putenv('FORUM_SEO_INDEX=true');
    check($http('GET',$path,null,$m)->headers->get('X-Forum-Cache')===null,'authenticated HTML bypasses cache');
    $post=$api('GET',$path)['posts'][0]['id'];$api('PATCH','/forum/posts/'.$post,['body'=>'Edited marker'],$m);
    $edited=$http('GET',$path,null,null,$second);check($edited->headers->get('X-Forum-Cache')==='MISS'&&str_contains((string)$edited->getContent(),'Edited marker')&&!str_contains((string)$edited->getContent(),'Original marker'),'edit invalidates cross-instance cache');
    check($api('GET','/forum/search?q=Original&mode=posts')===[]&&$api('GET','/forum/search?q=Edited&mode=posts')[0]['post_id']===$post,'edit synchronizes search with warm SSR');
    $api('POST',$path.'/posts',['body'=>'Reply marker'],$o,201);
    $before=$api('GET','/forum/notifications?count_only=1',null,$m);$api('GET','/forum/notifications?count_only=1',null,$m,200,$second);
    check((int)$r->hGet($prefix.'stats','counter_hit')>=1,'private counter shared cache hit');
    $stats=$app->container()->get(QueueWorker::class)->work('default',10);check($stats['forum_notifications_processed']>=1&&$stats['processed']>=1,'Redis worker executes real SQL notification outbox');
    $after=$api('GET','/forum/notifications?count_only=1',null,$m,200,$second);$otherCount=$api('GET','/forum/notifications?count_only=1',null,$o);
    check($after['unread']>$before['unread']&&$otherCount['unread']!==$after['unread'],'notification dispatch invalidates per-user counts');
    $api('POST','/forum/notifications/read',['all'=>true],$m);check($api('GET','/forum/notifications?count_only=1',null,$m)['unread']===0,'mark read invalidates count');
    $http('GET',$path);$http('GET',$path);$acl=$api('GET',"/forum/boards/$board/permissions",null,$a);
    $api('PUT',"/forum/boards/$board/permissions",['visibility'=>'private','rules'=>[],'revision'=>$acl['revision']],$a);
    $hidden=$http('GET',$path,null,null,$second);check($hidden->getStatusCode()===404&&!str_contains((string)$hidden->getContent(),'Edited marker'),'ACL revocation cannot leak through warm cache');
    check($http('GET',$path,null,$a)->getStatusCode()===200,'authorized administrator still reads private thread');
    check(!in_array($t,$publicSearch(),true)&&!str_contains($sitemap(),$t)&&!str_contains($rss(),$t)&&$api('GET','/forum/search?q=Redis&discover=suggest')===[],'revoked ACL excludes search, discovery, sitemap and RSS');
    $acl=$api('GET',"/forum/boards/$board/permissions",null,$a);$api('PUT',"/forum/boards/$board/permissions",['visibility'=>'public','rules'=>[],'revision'=>$acl['revision']],$a);
    check(in_array($t,$publicSearch(),true)&&str_contains($sitemap(),$t)&&$http('GET',$path)->getStatusCode()===200,'restored ACL reopens all public surfaces');
    $http('GET',$path);$deletion=$api('POST','/forum/recycle-bin/thread/'.$t,['action'=>'delete','reason'=>'Stable lifecycle test'],$a)['deletion_id'];
    check($http('GET',$path,null,null,$second)->getStatusCode()===404&&!in_array($t,$publicSearch(),true)&&!str_contains($sitemap(),$t)&&!str_contains($rss(),$t),'soft delete excludes warm HTML, search and feeds');
    $api('POST','/forum/recycle-bin/thread/'.$t,['action'=>'restore','deletion_id'=>$deletion],$a);
    check($http('GET',$path,null,null,$second)->getStatusCode()===200&&in_array($t,$publicSearch(),true)&&str_contains($sitemap(),$t),'restored discussion returns across public surfaces');
    putenv('RATE_LIMIT_DRIVER=redis');$l1=new RateLimiter($tmp);$l2=new RateLimiter($tmp);check($l1->hit('test','peer',2,60)['allowed']&&$l2->hit('test','peer',2,60)['allowed']&&!$l1->hit('test','peer',2,60)['allowed'],'Redis rate budget shared across connections');putenv('RATE_LIMIT_DRIVER=file');
    // Simulate loss of Redis connectivity without touching the CI service.
    $http('GET',$path);$http('GET',$path);
    $port=getenv('REDIS_PORT')?:'6379';putenv('REDIS_PORT=1');$offline=Application::boot($root);
    check($http('GET',$path,null,null,$offline)->getStatusCode()===200,'Redis outage does not prevent SQL-backed reads or application boot');
    $api('PATCH','/forum/posts/'.$post,['body'=>'Offline edit marker'],$m,200,$offline);
    check(str_contains((string)$http('GET',$path,null,null,$offline)->getContent(),'Offline edit marker'),'write during Redis outage remains readable');
    putenv('RATE_LIMIT_DRIVER=redis');check(!(new RateLimiter($tmp))->hit('test','offline',2,60)['allowed'],'distributed limiter fails closed');putenv('RATE_LIMIT_DRIVER=file');
    putenv('REDIS_PORT='.$port);
    check(str_contains((string)$http('GET',$path)->getContent(),'Offline edit marker'),'old Redis entries are unreachable after offline write');
    $diag=\CajeerEngine\Forum\Service\ForumRuntimeCache::diagnostics();check($diag['connected']&&$diag['stats']['hit']>=2,'runtime diagnostics reports shared hits');
    $reconnecting=new \CajeerEngine\Queue\RedisQueueDriver(static fn()=>RedisConnection::open(),$queuePrefix.'recovery:',5);
    check($reconnecting->pop('recovery')===null,'empty queue is distinct from an unconfirmed Redis response');
    // Simulate an unavailable transport on the next connection; no retry is performed
    // inside a transition. Once the endpoint is restored the next call reconnects.
    putenv('REDIS_PORT=1');$offlineQueue=new \CajeerEngine\Queue\RedisQueueDriver(static fn()=>RedisConnection::open(),$queuePrefix.'recovery:',5);
    try{$offlineQueue->pop('recovery');throw new \LogicException('expected Redis outage');}catch(\RedisException|\RuntimeException $e){check(true,'offline Redis pop reports failure rather than empty');}
    putenv('REDIS_PORT='.$port);$id=$offlineQueue->push('log.message',['message'=>'recovered'],'recovery');$job=$offlineQueue->pop('recovery');check($job?->id===$id,'next independent Redis operation reconnects');$offlineQueue->complete($job);
    echo "Redis forum regression complete\n";
}finally{
    foreach([$prefix,$queuePrefix] as $p){$cursor=null;do{foreach($r->scan($cursor,$p.'*',200)?:[] as $k)$r->del($k);}while($cursor!==0);}
    foreach(glob($tmp.'/*')?:[] as $file)if(is_file($file))unlink($file);@rmdir($tmp);
}
