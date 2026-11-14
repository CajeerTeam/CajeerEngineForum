<?php
/** Integration test: real Symfony routing, authentication, migrations and SQLite persistence. */
declare(strict_types=1);
use CajeerEngine\Kernel\Application;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Database\Repository\{RoleRepository,UserRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use Symfony\Component\HttpFoundation\Request;
$root=dirname(__DIR__);$driver=getenv('FORUM_TEST_DRIVER')==='pgsql'?'pgsql':'sqlite';require $root.'/vendor/autoload.php';
$tmp=sys_get_temp_dir().'/forum-ci-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
try {
    foreach(['APP_NAME=Test <Forum>','APP_ENV=testing','APP_DEBUG=false','APP_URL=https://forum.test','DB_DRIVER='.$driver,'SQLITE_DATABASE='.$tmp.'/forum.sqlite','CACHE_DRIVER=file','QUEUE_DRIVER=file','STORAGE_DRIVER=local','CONTENT_STORAGE=database','CMS_STORAGE=database','FORUM_REGISTRATION=true','FORUM_REQUIRE_EMAIL_VERIFICATION=false','RATE_LIMIT_GLOBAL=1000','RATE_LIMIT_AUTH=1000','PASSWORD_ARGON2_MEMORY=8192','PASSWORD_ARGON2_TIME=2'] as $v)putenv($v);
    $cfg=new ConfigRepository($root);$db=new DatabaseManager($cfg);(new MigrationRunner($db->connection(),$root.'/migrations'))->run($driver);
    if ((int)$db->connection()->query('SELECT COUNT(*) FROM ce_users')->fetchColumn()!==0) throw new RuntimeException('Disposable forum database must be empty');
    $roles=new RoleRepository($db);$roles->seedDefaults();$users=new UserRepository($db,$roles,new PasswordHasher($cfg));$users->createAdmin('admin@example.test','Admin','AdminPass12345');
    $app=Application::boot($root);
    $call=function(string $method,string $path,?array $data=null,?string $token=null,int $expect=200,array $cookies=[],array $extra=[])use($app):array{
        $headers=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'127.0.0.1']+$extra;
        if($token)$headers['HTTP_AUTHORIZATION']='Bearer '.$token;
        $r=Request::create('https://forum.test/api/v1'.$path,$method,[],$cookies,[],$headers,$data===null?null:json_encode($data,JSON_THROW_ON_ERROR));$res=$app->handle($r);
        check($res->getStatusCode()===$expect,"$method $path expected $expect, got ".$res->getStatusCode().' '.substr((string)$res->getContent(),0,300));return json_decode((string)$res->getContent(),true,512,JSON_THROW_ON_ERROR)['data']??[];
    };
    $board=$call('GET','/forum')['boards'][0]['id'];
    $call('GET','/forum/latest?page%5B%5D=1',null,null,400);
    $call('GET','/forum/search?q=x',null,null,422);
    $call('GET','/forum/threads/not-a-uuid',null,null,422);
    $call('POST',"/forum/boards/$board/threads",['title'=>'Test topic','body'=>'First'],null,401);
    $member=$call('POST','/forum/register',['name'=>'Member','email'=>'member@example.test','password'=>'MemberPass12345'],null,201)['id'];
    $other=$call('POST','/forum/register',['name'=>'Other','email'=>'other@example.test','password'=>'OtherPass12345'],null,201)['id'];
    $login=fn($email,$password)=>$call('POST','/auth/login',['email'=>$email,'password'=>$password],null,201)['access_token'];
    $call('POST','/forum/register',['name'=>'Member','email'=>'member@example.test','password'=>'MemberPass12345'],null,409);
    $m=$login('member@example.test','MemberPass12345');$a=$login('admin@example.test','AdminPass12345');$o=$login('other@example.test','OtherPass12345');
    $t=$call('POST',"/forum/boards/$board/threads",['title'=>'Test topic','body'=>'First <script>not HTML</script>'],$m,201)['id'];
    check($call('GET',"/forum/boards/$board")['threads'][0]['id']===$t,'board listing with joined author');
    $post=$call('GET',"/forum/threads/$t")['posts'][0]['id'];
    $call('POST',"/forum/threads/$t/posts",['body'=>''], $o,422);
    $call('POST',"/forum/threads/$t/posts",['body'=>'Second'],$o,201);
    $posts=$call('GET',"/forum/threads/$t")['posts'];check($posts[0]['id']===$post&&count($posts)===2,'post ordering');
    $call('PATCH',"/forum/posts/$post",['body'=>'Unauthorized'],$o,403);
    $call('PATCH',"/forum/posts/$post",['body'=>'Edited'],$m);
    $call('PUT',"/forum/posts/$post/reaction",['enabled'=>'true'],$o,422);
    $call('PUT',"/forum/posts/$post/reaction",['enabled'=>true],$o);$call('PUT',"/forum/posts/$post/reaction",['enabled'=>true],$o);
    check((int)$call('GET',"/forum/threads/$t")['posts'][0]['likes']===1,'reaction idempotency');
    $call('PUT',"/forum/threads/$t/watch",['enabled'=>true],$m);check(count($call('GET','/forum/watches',null,$m))===1,'subscription stored');
    $report=$call('POST',"/forum/posts/$post/report",['reason'=>'Test report'],$o,201)['id'];$call('GET','/forum/reports',null,$m,403);$call('GET','/forum/reports',null,$a);$call('POST',"/forum/reports/$report/resolve",null,$a);
    $call('POST',"/forum/threads/$t/moderate",['action'=>'lock'],$m,403);$call('POST',"/forum/threads/$t/moderate",['action'=>'lock'],$a);$call('POST',"/forum/threads/$t/posts",['body'=>'Blocked'],$m,409);
    $call('POST',"/forum/threads/$t/moderate",['action'=>'unlock'],$a);$call('POST',"/forum/threads/$t/moderate",['action'=>'pin'],$a);
    check(count($call('GET','/forum/search?q=Test'))===1,'search');check(!isset($call('GET',"/forum/members/$member")['member']['email']),'public profile hides email');
    $cat=$call('POST','/forum/categories',['name'=>'New','description'=>''],$a,201)['id'];$call('POST',"/forum/categories/$cat/boards",['name'=>'Board','description'=>''],$a,201);
    $browser=$app->handle(Request::create('https://forum.test/api/v1/auth/login','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CAJEER_BROWSER'=>'1','REMOTE_ADDR'=>'127.0.0.1'],json_encode(['email'=>'member@example.test','password'=>'MemberPass12345'])));check($browser->getStatusCode()===201,'browser login');$cookie=$browser->headers->getCookies()[0]->getValue();$csrf=json_decode($browser->getContent(),true)['data']['csrf_token'];
    $call('POST',"/forum/threads/$t/posts",['body'=>'CSRF blocked'],null,403,['ce_session'=>$cookie]);$call('POST',"/forum/threads/$t/posts",['body'=>'CSRF valid'],null,201,['ce_session'=>$cookie],['HTTP_X_CSRF_TOKEN'=>$csrf]);
    $call('POST',"/forum/threads/$t/moderate",['action'=>'delete'],$a);$call('GET',"/forum/threads/$t",null,null,404);
    check((int)$db->connection()->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()===0,'cascade removal');
    check($app->handle(Request::create('https://forum.test/'))->getStatusCode()===200,'forum home');
    check(str_contains((string)$app->handle(Request::create('https://forum.test/'))->getContent(),'Test &lt;Forum&gt;'),'configured forum title safely escaped');
    putenv('FORUM_REGISTRATION=false');
    $call('POST','/forum/register',['name'=>'Closed','email'=>'closed@example.test','password'=>'MemberPass12345'],null,403);
    check(str_contains((string)$app->handle(Request::create('https://forum.test/'))->getContent(),'data-registration="false"'),'closed registration UI');
    check($app->handle(Request::create('https://forum.test/'))->headers->get('X-CajeerEngine-Forum-Version')==='0.2.1','forum version header');
    echo "Forum integration passed\n";
} finally {foreach(glob($tmp.'/*')?:[] as $f)unlink($f);rmdir($tmp);}
