<?php

declare(strict_types=1);
namespace CajeerEngine\Api\Controller;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Service\PlatformWorkflows;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};

final readonly class PlatformWorkflowController
{
    public function __construct(private PlatformWorkflows $workflows) {}
    private function handle(Request $request,callable $fn): JsonResponse
    {
        $auth=$request->attributes->get('auth');$headers=['Cache-Control'=>'no-store'];
        // Match the disposable integration server's loopback-only browser login.
        // Production and every non-loopback request still require HTTPS.
        $localTest=PHP_SAPI==='cli-server' && getenv('APP_ENV')==='testing'
            && in_array($request->getHost(),['127.0.0.1','localhost','::1'],true)
            && in_array($request->server->get('REMOTE_ADDR'),['127.0.0.1','::1'],true);
        if((!$request->isSecure()&&!$localTest)||!is_array($auth)||($auth['cookie']??false)!==true||($auth['session']??false)!==true||!is_string($auth['user_id']??null))
            return new JsonResponse(['error'=>['code'=>'browser_session_required','message'=>'Требуется защищённая браузерная сессия.']],403,$headers);
        try{return new JsonResponse(['data'=>$fn($auth['user_id'])],200,$headers);}
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'workflow_rejected','message'=>$e->getMessage()]],$e->status,$headers);}
    }
    public function index(Request $request): JsonResponse {return $this->handle($request,fn($actor)=>$this->workflows->overview($actor));}
    public function save(Request $request): JsonResponse {return $this->handle($request,fn($actor)=>$this->workflows->save($actor,$request->attributes->get('json',[])));}
    public function preview(Request $request): JsonResponse {return $this->handle($request,fn($actor)=>$this->workflows->preview($actor,$request->attributes->get('json',[])));}
    public function remove(Request $request): JsonResponse
    {
        return $this->handle($request,function($actor)use($request){$body=$request->attributes->get('json',[]);
            if(!is_string($body['id']??null)||!is_int($body['revision']??null)||array_diff(array_keys($body),['id','revision']))throw new ForumException('Некорректный запрос.',422);
            return $this->workflows->remove($actor,$body['id'],$body['revision']);});
    }
}
