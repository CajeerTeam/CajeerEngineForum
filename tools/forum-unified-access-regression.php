<?php
/** Executable regression of actual Forum ACL and engine assurance logic, without Composer. */
declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    final class Attributes { public function __construct(private array $values = []) {} public function get(string $key, mixed $default = null): mixed {return $this->values[$key] ?? $default;} }
    final class Request { public Attributes $attributes; public function __construct(private string $method = 'GET', string $route = '') {$this->attributes = new Attributes(['matched_route'=>$route]);} public function getMethod():string{return $this->method;} }
}
namespace CajeerEngine\Database {
    final class TestConnection {
        public array $session = ['provider'=>'local','assured_at'=>null,'assurance_method'=>null,'assurance_mfa'=>0,'revoked_at'=>null];
        public string $lastSql='';
        public function prepare(string $sql): TestStatement {$this->lastSql=$sql;return new TestStatement($this,$sql);}
    }
    final class TestStatement {
        private int $changed=0;
        public function __construct(private TestConnection $db,private string $sql){}
        public function execute(array $args):bool {
            if (str_starts_with($this->sql,'UPDATE ce_user_sessions')) {
                if ($this->db->session['revoked_at']!==null || ((str_contains($this->sql,"provider IN ('local','legacy')")) && $this->db->session['provider']==='skif4er')
                    || (array_key_exists('provider',$args) && $args['provider']!==$this->db->session['provider'])) return true;
                $this->db->session['assured_at']=$args['at']; $this->db->session['assurance_method']=$args['method'];
                $this->db->session['assurance_mfa']=$args['mfa'];$this->changed=1;
            }
            return true;
        }
        public function fetch(int $mode):array|false {return $this->db->session;}
        public function rowCount():int {return $this->changed;}
    }
    final class DatabaseManager {
        public function __construct(public TestConnection $db){}
        public function connection(): TestConnection {return $this->db;}
        public function transaction(callable $call):mixed{return $call();}
    }
}
namespace CajeerEngine\Database\Repository {
    final class UserRepository {
        public function findById(string $id):?array {return $id==='owner'?['id'=>'owner','email'=>'owner@forum.test','status'=>'active']:null;}
        public function findByEmailForAuth(string $email):?array {return $email==='owner@forum.test' ? ['id'=>'owner','password_hash'=>'verified','two_factor_enabled'=>true,'two_factor_secret'=>'totp-secret']:null;}
    }
    final class AuditLogRepository { public array $records=[];public function record(string $action,?string $user,array $details=[]):void {$this->records[]=[$action,$user,$details];} }
}
namespace CajeerEngine\Security {
    final class AuthService {public static function intersectScopes(array $token,array $current):array {if(in_array('*',$token,true))return $current;if(in_array('*',$current,true))return $token;return array_values(array_intersect($token,$current));}}
    final class PasswordHasher { public function verify(string $password,string $hash):bool{return $hash==='verified' && $password==='correct-password';} }
    final class TwoFactorTotp { public function verify(string $secret,string $code):bool{return $secret==='totp-secret' && $code==='123456';} }
}
namespace {
    require dirname(__DIR__).'/core/Http/RouteRegistry.php';
    require dirname(__DIR__).'/core/Forum/ForumException.php';
    require dirname(__DIR__).'/core/Forum/Security/ForumAccessPolicy.php';
    require dirname(__DIR__).'/core/Forum/Security/PermissionResolver.php';
    require dirname(__DIR__).'/core/Security/AuthenticationAssurance.php';
    use Symfony\Component\HttpFoundation\Request;
    use CajeerEngine\Security\AuthenticationAssurance as Assurance;
    use CajeerEngine\Forum\Security\{PermissionResolver as ACL, ForumAccessPolicy as Principal};
    $n=0;
    $assert=static function(bool $value,string $what)use(&$n):void {if(!$value)throw new \RuntimeException('FAIL '.$what);++$n;};
    foreach (['api.v1.forum.acp.delegation','api.v1.forum.acp.member','api.v1.forum.acp.policy','api.v1.forum.acp.theme',
        'api.v1.forum.setBoardPermissions','api.v1.forum.setCategoryPermissions','api.v1.forum.setGroupMembership',
        'api.v1.forum.createGroup','api.v1.forum.deleteGroup','api.v1.forum.issueSanction',
        'api.v1.forum.revokeSanction','api.v1.forum.decideSanctionAppeal','api.v1.forum.moderateProfile'] as $route) {
        $assert(Assurance::required(new Request('POST',$route)),'step-up required for '.$route);
        $assert(!Assurance::required(new Request('GET',$route)),'GET does not mutate '.$route);
    }
    foreach (['api.v1.forum.createThread','api.v1.forum.reply','api.v1.forum.appealSanction','api.v1.forum.watch','api.v1.auth.logout'] as $route) {
        $assert(!Assurance::required(new Request('POST',$route)),'routine request unaffected '.$route);
    }
    $routes=\CajeerEngine\Http\RouteRegistry::all();$found=[];
    foreach($routes as [$method,$path,$name,$controller,$handler,$scopes]) {
        if($method!=='GET' && Assurance::forumPrivilegeChange($name)) {
            $assert(is_array($scopes) && $scopes!==[],'sensitive action requires RBAC '.$name);
            $found[$name]=true;
        }
    }
    $assert(count($found)>=30,'real RouteRegistry must cover critical privilege changes');
    // A future admin route must not accidentally ship without MFA protection.
    foreach ($routes as [$method,$path,$name,$controller,$handler,$scopes]) {
        if (str_starts_with($name,'api.v1.forum.') && !in_array($method,['GET','HEAD','OPTIONS'],true)
            && is_array($scopes) && in_array('*',$scopes,true)) {
            $assert(Assurance::required(new Request($method,$name)),'every administrator-only mutation requires step-up: '.$name);
        }
    }
    $rule=static fn(string $type,string $id,string $permission,string $effect):array => ['subject_type'=>$type,'subject_id'=>$id,'permission'=>$permission,'effect'=>$effect];
    $cat=['key'=>'category:c','visibility'=>'public'];$board=['key'=>'board:b','visibility'=>'private'];
    $writer=new Principal('owner',['forum:write']);
    $roles=['member'];
    $result=ACL::evaluate($writer,$roles,[$cat,$board],['board:b'=>[$rule('role','member','thread:read','allow')]]);
    $assert($result['thread:read']&&$result['post:reply'],'role ACL opens a private board within core scope ceiling');
    $blocked=ACL::evaluate($writer,$roles,[$cat,$board],['category:c'=>[$rule('everyone','','thread:read','never')],'board:b'=>[$rule('role','member','thread:read','allow')]]);
    $assert(!$blocked['thread:read']&&!$blocked['post:reply'],'ancestor never cannot be bypassed');
    $noWrite=ACL::evaluate(new Principal('owner',[]),$roles,[$cat,['key'=>'board:b','visibility'=>'public']],['board:b'=>[$rule('user','owner','post:reply','allow')]]);
    $assert(!$noWrite['post:reply'],'ACL cannot grant permission outside current RBAC scopes');
    $moderator=ACL::evaluate(new Principal('owner',['forum:write','forum:moderate']),$roles,[$cat,['key'=>'board:b','visibility'=>'public']],[],true);
    $assert(!$moderator['thread:moderate'],'delegated moderation is not global');
    $moderator=ACL::evaluate(new Principal('owner',['forum:write','forum:moderate']),$roles,[$cat,['key'=>'board:b','visibility'=>'public']],['board:b'=>[$rule('role','member','thread:moderate','allow')]],true);
    $assert($moderator['thread:moderate'],'delegated moderation works only where ACL explicitly allows');

    $db=new \CajeerEngine\Database\TestConnection();$audit=new \CajeerEngine\Database\Repository\AuditLogRepository();
    $assurance=new Assurance(new \CajeerEngine\Database\DatabaseManager($db),new \CajeerEngine\Database\Repository\UserRepository(),new \CajeerEngine\Security\PasswordHasher(),new \CajeerEngine\Security\TwoFactorTotp(),$audit);
    $identity=['cookie'=>true,'session'=>true,'token_id'=>'session1','user_id'=>'owner'];
    $assert(!$assurance->status($identity)['fresh'],'new login has no assurance');
    foreach([['bad-password','123456'],['correct-password','000000']] as [$pass,$code]) {
        try{$assurance->confirmLocal($identity,$pass,$code);$ok=false;}catch(\RuntimeException){$ok=true;}
        $assert($ok,'invalid password or TOTP rejected');
    }
    $assert($assurance->confirmLocal($identity,'correct-password','123456')['fresh'],'local password+MFA confirms same session');
    $assert(count($audit->records)===1,'reauthentication is audited');
    $db->session=['provider'=>'skif4er','assured_at'=>time(),'assurance_method'=>'local','assurance_mfa'=>1,'revoked_at'=>null];
    $assert(!$assurance->status($identity)['fresh'],'old local stamp cannot authorize SSO session');
    try{$assurance->confirmLocal($identity,'correct-password','123456');$ok=false;}catch(\RuntimeException){$ok=true;}
    $assert($ok && $db->session['assurance_method']==='local','SSO cannot fallback to local password');
    try{$assurance->confirmOidc('session1','owner',['auth_time'=>time(),'amr'=>['pwd']]);$ok=false;}catch(\RuntimeException){$ok=true;}
    $assert($ok,'OIDC step-up without MFA evidence rejected');
    $assurance->confirmOidc('session1','owner',['auth_time'=>time(),'amr'=>['pwd','mfa']]);
    $assert($assurance->status($identity)['fresh'],'validated IdP MFA enables SSO assurance');
    $db->session['revoked_at']='now';
    $assert(!$assurance->status($identity)['fresh'],'revocation immediately invalidates assurance');
    echo "Forum 1.0.8 unified access: $n PASS\n";
}
