<?php

declare(strict_types=1);

namespace CajeerEngine\Forum;

use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\ForumSupportAssistant;
use CajeerEngine\Audit\AuditLogger;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};

/** Browser-session-only support API. Operators require explicit Forum ACP member privileges. */
final readonly class ForumSupportController
{
    public function __construct(private ForumSupportAssistant $service,private AuditLogger $audit){}

    private function actor(Request $request):ForumAccessPolicy
    {
        $auth=$request->attributes->get('auth');
        if(!is_array($auth)||($auth['cookie']??false)!==true||($auth['session']??false)!==true)
            throw new ForumException('Войдите через браузер.',401);
        return ForumAccessPolicy::fromAuth($auth);
    }

    private static function body(Request $r):array
    {
        $body=$r->attributes->get('json',[]);
        if(!is_array($body)||array_is_list($body)&&$body!==[])throw new ForumException('Ожидался JSON-объект.',422);
        return $body;
    }

    private function wrap(callable $fn,int $status=200):JsonResponse
    {
        try{return new JsonResponse(['data'=>$fn()],$status,['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization']);}
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_support','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'no-store']);}
        catch(\InvalidArgumentException|\DomainException $e){return new JsonResponse(['error'=>['code'=>'invalid_support_request','message'=>'Невозможно обработать запрос.']],422,['Cache-Control'=>'no-store']);}
    }

    private function auditAction(string $type,ForumAccessPolicy $actor,array $context):void
    {
        $this->audit->record($type,$actor->userId,$context);
    }

    public function faqs(Request $r):JsonResponse {return $this->wrap(fn()=> $this->service->faq());}
    public function mine(Request $r):JsonResponse {return $this->wrap(fn()=> $this->service->mine($this->actor($r)));}
    public function show(Request $r,array $p):JsonResponse {return $this->wrap(fn()=> $this->service->show($this->actor($r),(string)$p['id']));}
    public function create(Request $r):JsonResponse
    {
        return $this->wrap(fn()=> $this->service->create($this->actor($r),self::body($r)),201);
    }
    public function reply(Request $r,array $p):JsonResponse
    {
        return $this->wrap(fn()=> $this->service->reply($this->actor($r),(string)$p['id'],self::body($r)));
    }
    public function handoff(Request $r,array $p):JsonResponse
    {
        return $this->wrap(fn()=> $this->service->handoff($this->actor($r),(string)$p['id'],self::body($r)));
    }
    public function queue(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r){$actor=$this->actor($r);$params=$r->query->all();if(array_diff(array_keys($params),['status'])!==[])throw new ForumException('Некорректный фильтр.',422);return $this->service->queue($actor,(string)($params['status']??'open'));});
    }
    public function action(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p){$actor=$this->actor($r);$input=self::body($r);$value=$this->service->action($actor,(string)$p['id'],$input);$this->auditAction('forum.support.operator.action',$actor,['ticket'=>$p['id'],'action'=>$input['action']??'']);return $value;});
    }
    public function operatorReply(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p){$actor=$this->actor($r);$actor->require('forum:acp:members');$v=$this->service->reply($actor,(string)$p['id'],self::body($r),true);$this->auditAction('forum.support.operator.reply',$actor,['ticket'=>$p['id']]);return $v;});
    }
    public function manageFaq(Request $r):JsonResponse{return $this->wrap(fn()=> $this->service->manageFaq($this->actor($r)));}
    public function createFaq(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r){$actor=$this->actor($r);$v=$this->service->saveFaq($actor,null,self::body($r));$this->auditAction('forum.support.faq.created',$actor,['id'=>$v['id']]);return $v;},201);
    }
    public function updateFaq(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p){$actor=$this->actor($r);$v=$this->service->saveFaq($actor,(string)$p['id'],self::body($r));$this->auditAction('forum.support.faq.updated',$actor,['id'=>$p['id']]);return $v;});
    }
    public function deleteFaq(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p){$actor=$this->actor($r);$body=self::body($r);if(array_diff(array_keys($body),['expected_revision'])!==[]||!is_int($body['expected_revision']??null))throw new ForumException('Укажите версию FAQ.',422);$this->service->deleteFaq($actor,(string)$p['id'],$body['expected_revision']);$this->auditAction('forum.support.faq.deleted',$actor,['id'=>$p['id']]);return ['deleted'=>true];});
    }
}
