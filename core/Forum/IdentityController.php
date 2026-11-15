<?php
declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Forum\Service\IdentityService;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use Symfony\Component\HttpFoundation\{Request, JsonResponse, Cookie};

final readonly class IdentityController
{
    public function __construct(private IdentityService $service) {}
    private function respond(callable $fn,int $status=200): JsonResponse
    {
        try { return new JsonResponse(['data'=>$fn()],$status,['Cache-Control'=>'private, no-store']); }
        catch (ForumException $e) { return new JsonResponse(['error'=>['code'=>'identity_error','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'private, no-store']); }
    }
    private function field(Request $r,string $key): mixed { return $r->attributes->get('json',[])[$key]??null; }
    private function password(Request $r): string { return V::password($this->field($r,'password')); }
    private function code(Request $r): ?string { $v=$this->field($r,'two_factor_code');return $v===null||$v===''?null:V::text($v,'two_factor_code',32); }
    private function token(Request $r,string $key='token'): string { return V::text($this->field($r,$key),$key,64,64); }
    private function actor(Request $r): array { $a=$r->attributes->get('auth');if(!is_array($a))throw new ForumException('Требуется авторизация.',401);return $a; }
    private function email(Request $r): string { $e=V::text($this->field($r,'email'),'email',254);if(!filter_var($e,FILTER_VALIDATE_EMAIL))throw new ForumException('Некорректный email.');return $e; }
    public function personalData(Request $r): JsonResponse { return $this->respond(function()use($r){$q=$r->query->all();$section=V::text($q['section']??'index','section',40);$offset=$q['offset']??'0';if(!is_string($offset)||!preg_match('/^(0|[1-9][0-9]{0,8})$/D',$offset))throw new ForumException('Некорректное смещение.',422);return $this->service->personalData($this->actor($r),$section,(int)$offset);}); }
    public function clearPersonalProfile(Request $r): JsonResponse { return $this->respond(fn()=>$this->service->clearPersonalProfile($this->actor($r),$this->password($r),$this->code($r))); }
    public function erasePersonalAccount(Request $r): JsonResponse { $response=$this->respond(function()use($r){if($this->field($r,'confirmation')!=='DELETE')throw new ForumException('Введите DELETE для подтверждения.',422);return $this->service->erasePersonalAccount($this->actor($r),$this->password($r),$this->code($r));});if($response->getStatusCode()===200)$response->headers->clearCookie('ce_session','/api/v1',null,$r->isSecure(),true,Cookie::SAMESITE_STRICT);return $response; }
    public function resend(Request $r): JsonResponse { return $this->respond(function()use($r){$this->service->requestMail($this->email($r),'verify');return ['accepted'=>true];},202); }
    public function forgot(Request $r): JsonResponse { return $this->respond(function()use($r){$this->service->requestMail($this->email($r),'reset');return ['accepted'=>true];},202); }
    public function verify(Request $r): JsonResponse { return $this->respond(function()use($r){$this->service->verifyEmail($this->token($r),$this->password($r));return ['verified'=>true];}); }
    public function reset(Request $r): JsonResponse { return $this->respond(function()use($r){$this->service->resetPassword($this->token($r),$this->password($r));return ['reset'=>true];}); }
    public function sessions(Request $r): JsonResponse { return $this->respond(fn()=>$this->service->sessions($this->actor($r))); }
    public function revoke(Request $r,array $p): JsonResponse { return $this->respond(fn()=>['revoked'=>$this->service->revokeSession($this->actor($r),V::id($p['id']??null))]); }
    public function revokeAll(Request $r): JsonResponse { return $this->respond(function()use($r){$this->service->revokeSessions($this->actor($r),$this->password($r),$this->code($r));return ['revoked'=>true];}); }
    public function begin(Request $r): JsonResponse { return $this->respond(fn()=>$this->service->beginTwoFactor($this->actor($r),$this->password($r)),201); }
    public function confirm(Request $r): JsonResponse { return $this->respond(fn()=>$this->service->confirmTwoFactor($this->actor($r),$this->password($r),$this->token($r,'setup_token'),V::text($this->field($r,'two_factor_code'),'two_factor_code',6,6))); }
    public function disable(Request $r): JsonResponse { return $this->respond(function()use($r){$this->service->disableTwoFactor($this->actor($r),$this->password($r),$this->code($r));return ['two_factor_enabled'=>false];}); }
}
