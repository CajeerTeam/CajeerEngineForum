<?php
/** HTTP identity lifecycle against real DB and disposable Mailpit SMTP. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Kernel\Application;
use CajeerEngine\Security\{PasswordHasher,TwoFactorTotp};
use Symfony\Component\HttpFoundation\Request;
$root=dirname(__DIR__); require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')==='pgsql'?'pgsql':'sqlite';
$tmp=sys_get_temp_dir().'/identity-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function mailToken(string $email,string $path): string {
    $base=getenv('FORUM_TEST_MAILPIT_URL')?:'http://127.0.0.1:8025';
    for($i=0;$i<40;$i++) {
        $data=json_decode((string)file_get_contents($base.'/api/v1/messages'),true,512,JSON_THROW_ON_ERROR);
        foreach($data['messages']??[] as $m) {
            if(!in_array($email,array_column($m['To']??[],'Address'),true))continue;
            $msg=json_decode((string)file_get_contents($base.'/api/v1/message/'.$m['ID']),true,512,JSON_THROW_ON_ERROR);
            if(preg_match('~'.preg_quote($path,'~').'#token=([a-f0-9]{64})~',$msg['Text']??'',$matches))return $matches[1];
        }
        usleep(250000);
    }
    throw new RuntimeException('Email not delivered to Mailpit: '.$path);
}
try {
    foreach(['APP_ENV=testing','APP_DEBUG=false','APP_URL=https://forum.test','DB_DRIVER='.$driver,'SQLITE_DATABASE='.$tmp.'/forum.sqlite','CACHE_DRIVER=file','QUEUE_DRIVER=file','STORAGE_DRIVER=local','CONTENT_STORAGE=database','CMS_STORAGE=database','FORUM_REGISTRATION=true','FORUM_REQUIRE_EMAIL_VERIFICATION=true','FORUM_SMTP_HOST=127.0.0.1','FORUM_SMTP_PORT=1025','FORUM_SMTP_SECURITY=plain','FORUM_SMTP_FROM=forum@example.test','FORUM_SMTP_USERNAME=','FORUM_SMTP_PASSWORD=','RATE_LIMIT_AUTH=1000','RATE_LIMIT_GLOBAL=1000','PASSWORD_ARGON2_MEMORY=8192','PASSWORD_ARGON2_TIME=2'] as $env) putenv($env);
    if($driver==='pgsql')putenv('DB_DATABASE='.(getenv('FORUM_TEST_IDENTITY_DB')?:throw new RuntimeException('Dedicated DB required')));
    $cfg=new ConfigRepository($root);$db=new DatabaseManager($cfg);$pdo=$db->connection();
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
    check((int)$pdo->query('SELECT COUNT(*) FROM ce_users')->fetchColumn()===0,'disposable empty DB');
    $roles=new RoleRepository($db);$roles->seedDefaults();$users=new UserRepository($db,$roles,new PasswordHasher($cfg));
    $admin=$users->createAdmin('admin@example.test','Admin','AdminPass12345');
    $app=Application::boot($root);
    $call=function(string $method,string $path,?array $body=null,?string $token=null,int $status=200,array $cookies=[],array $extra=[])use($app):array {
        $h=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'127.0.0.1']+$extra;
        if($token)$h['HTTP_AUTHORIZATION']='Bearer '.$token;
        $res=$app->handle(Request::create('https://forum.test/api/v1'.$path,$method,[],$cookies,[],$h,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR)));
        check($res->getStatusCode()===$status,"$method $path expected $status got ".$res->getStatusCode());
        return json_decode((string)$res->getContent(),true,512,JSON_THROW_ON_ERROR)['data']??[];
    };
    $email='identity-'.bin2hex(random_bytes(5)).'@example.test';$password='MemberPass12345';$new='ChangedPass12345';
    $reg=$call('POST','/forum/register',['name'=>'Identity','email'=>$email,'password'=>$password],null,201);$id=$reg['id'];
    check($reg['verification_required']&&$users->findById($id)['status']==='pending','new account pending');
    $login=fn($pw,$code=null,$status=201)=>$call('POST','/auth/login',['email'=>$email,'password'=>$pw]+($code===null?[]:['two_factor_code'=>$code]),null,$status);
    $login($password,null,401);
    $verify=mailToken($email,'/forum/verify-email');
    $expire=$pdo->prepare('UPDATE ce_identity_challenges SET expires_at=:expires WHERE user_id=:id AND purpose=:purpose');
    $expire->execute(['expires'=>time()-1,'id'=>$id,'purpose'=>'verify']);
    $call('POST','/auth/email/verify',['token'=>$verify,'password'=>$password],null,422);
    $expire->execute(['expires'=>time()+600,'id'=>$id,'purpose'=>'verify']);
    $call('POST','/auth/email/verify',['token'=>$verify,'password'=>'WrongPass12345'],null,422);
    $call('POST','/auth/email/verify',['token'=>$verify,'password'=>$password]);
    check($users->findById($id)['status']==='active'&&$users->findById($id)['email_verified_at']!==null,'verified account active');
    $call('POST','/auth/email/verify',['token'=>$verify,'password'=>$password],null,422);
    $t1=$login($password)['access_token'];$t2=$login($password)['access_token'];
    $adminToken=$call('POST','/auth/login',['email'=>'admin@example.test','password'=>'AdminPass12345'],null,201)['access_token'];
    $sessions=$call('GET','/auth/sessions',null,$t1);check(count($sessions)===2,'two active sessions');
    $other=$call('GET','/auth/sessions',null,$adminToken)[0]['id'];
    check(!$call('DELETE','/auth/sessions/'.$other,null,$t1)['revoked'],'cannot revoke foreign session');
    foreach($sessions as $session)if(!$session['current'])$call('DELETE','/auth/sessions/'.$session['id'],null,$t1);
    $call('GET','/auth/me',null,$t2,401);
    $call('POST','/auth/password/forgot',['email'=>'missing@example.test'],null,202);
    $call('POST','/auth/password/forgot',['email'=>$email],null,202);$reset=mailToken($email,'/forum/reset-password');
    $expire->execute(['expires'=>time()-1,'id'=>$id,'purpose'=>'reset']);
    $call('POST','/auth/password/reset',['token'=>$reset,'password'=>$new],null,422);
    $expire->execute(['expires'=>time()+600,'id'=>$id,'purpose'=>'reset']);
    $call('POST','/auth/password/reset',['token'=>$reset,'password'=>$new]);
    $call('POST','/auth/password/reset',['token'=>$reset,'password'=>$password],null,422);
    $call('GET','/auth/me',null,$t1,401);$login($password,null,401);
    $t=$login($new)['access_token'];
    $setup=$call('POST','/auth/2fa/enable',['password'=>$new],$t,201);
    check(!$users->findById($id)['two_factor_enabled'],'enrollment does not activate before confirmation');
    $totp=new TwoFactorTotp();$code=$totp->code($setup['secret']);$wrong=$code==='000000'?'111111':'000000';
    // Generate a wrong code outside the allowed ±1 window, not just different from current.
    while($totp->verify($setup['secret'],$wrong))$wrong=str_pad((string)((int)$wrong+1),6,'0',STR_PAD_LEFT);
    $call('POST','/auth/2fa/confirm',['password'=>$new,'setup_token'=>$setup['setup_token'],'two_factor_code'=>$wrong],$t,422);
    $result=$call('POST','/auth/2fa/confirm',['password'=>$new,'setup_token'=>$setup['setup_token'],'two_factor_code'=>$code],$t);
    check(count($result['recovery_codes'])===8&&$users->findById($id)['two_factor_enabled'],'TOTP confirmed and recovery codes issued');
    $call('GET','/auth/me',null,$t,401);$login($new,null,401);$login($new,$code,401);
    $r1=$result['recovery_codes'][0];$r2=$result['recovery_codes'][1];
    $t=$login($new,$r1)['access_token'];$login($new,$r1,401);
    $call('POST','/auth/2fa/disable',['password'=>$new],$t,403);
    $call('POST','/auth/2fa/disable',['password'=>$new,'two_factor_code'=>$r2],$t);
    $call('GET','/auth/me',null,$t,401);$t=$login($new)['access_token'];
    $call('POST','/auth/sessions/revoke-all',['password'=>$new],$t);$call('GET','/auth/me',null,$t,401);
    // Real cookie auth and CSRF check on new sensitive routes.
    $res=$app->handle(Request::create('https://forum.test/api/v1/auth/login','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CAJEER_BROWSER'=>'1','REMOTE_ADDR'=>'127.0.0.1'],json_encode(['email'=>$email,'password'=>$new])));
    check($res->getStatusCode()===201,'browser login');$cookie=$res->headers->getCookies()[0]->getValue();$csrf=json_decode($res->getContent(),true)['data']['csrf_token'];
    $call('POST','/auth/sessions/revoke-all',['password'=>$new],null,403,['ce_session'=>$cookie]);
    $call('POST','/auth/sessions/revoke-all',['password'=>$new],null,200,['ce_session'=>$cookie],['HTTP_X_CSRF_TOKEN'=>$csrf]);
    $call('GET','/auth/me',null,null,401,['ce_session'=>$cookie]);
    check((int)$pdo->query("SELECT COUNT(*) FROM ce_identity_challenges WHERE purpose='verify' OR purpose='reset' OR purpose='totp'")->fetchColumn()===0,'consumed challenges removed');
    echo "Identity HTTP + SMTP integration passed ($driver)\n";
} finally {unset($app,$pdo,$db);foreach(glob($tmp.'/*')?:[] as $file)unlink($file);rmdir($tmp);}
