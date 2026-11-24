<?php
declare(strict_types=1);
namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\{AbuseIdentity,RateLimiter};
use CajeerEngine\Observability\Operation;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};

/** Account limits apply before password hashing and equally to existing and absent accounts. */
final readonly class LoginAbuseMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigRepository $config, private RateLimiter $limiter) {}
    public function process(Request $request, callable $next): Response
    {
        $route=(string)$request->attributes->get('matched_route','');
        if ($request->getMethod()!=='POST' || $route!=='api.v1.auth.login') return $next($request);
        $input=$request->attributes->get('json',[]);
        $email=is_array($input)?($input['email']??null):null;
        if (!is_string($email) || strlen($email)>254 || trim($email)==='') return $next($request);
        $started=hrtime(true);
        $account=AbuseIdentity::account($email); $network=AbuseIdentity::network($request->getClientIp());
        $pair=$this->limiter->hit('login_pair',$account.':'.$network, max(1,min(100,$this->config->int('security.rate_limits.login_pair',8))),600);
        $hit=$pair['allowed']?$this->limiter->hit('login_account',$account,max(1,min(500,$this->config->int('security.rate_limits.login_account',30))),600):$pair;
        Operation::record('security','login_admission',$hit['allowed']?'success':'rate_limited',$started,$this->config->rootPath());
        if (!$hit['allowed']) return new JsonResponse(['error'=>['code'=>'rate_limit_exceeded','message'=>'Превышен лимит попыток входа.','retry_after'=>$hit['retry_after'],'request_id'=>(string)$request->attributes->get('request_id','')]],429,['Retry-After'=>(string)$hit['retry_after'],'Cache-Control'=>'no-store']);
        return $next($request);
    }
}
