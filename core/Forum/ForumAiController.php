<?php

declare(strict_types=1);

namespace CajeerEngine\Forum;

use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Forum\Service\ForumAiChat;
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response,StreamedResponse};

/** Cookie-session protected member AI; never exposes CMS chat or credentials. */
final readonly class ForumAiController
{
    public function __construct(private ForumAiChat $chat, private AuditLogger $audit) {}

    private function user(Request $request): string
    {
        $auth=$request->attributes->get('auth');
        if (!is_array($auth) || ($auth['cookie']??false)!==true || ($auth['session']??false)!==true)
            throw new ForumException('Для AI-чата требуется вход через браузер.',401);
        return \CajeerEngine\Forum\Security\ForumAccessPolicy::fromAuth($auth)->userId;
    }

    private function input(Request $r,array $allowed):array
    {
        $data=$r->attributes->get('json',[]);
        if (!is_array($data)||array_diff(array_keys($data),$allowed)!==[])throw new ForumException('Недопустимые параметры AI.',422);
        return $data;
    }

    private function ok(mixed $data):JsonResponse
    {
        return new JsonResponse(['data'=>$data],200,['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization']);
    }

    private function wrap(callable $action):JsonResponse
    {
        try{return $this->ok($action());}
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_ai_error','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'no-store']);}
        catch(\DomainException|\InvalidArgumentException $e){return new JsonResponse(['error'=>['code'=>'forum_ai_limit','message'=>$e->getMessage()]],422,['Cache-Control'=>'no-store']);}
        catch(AiProviderException $e){return new JsonResponse(['error'=>['code'=>'ai_provider_unavailable','message'=>'AI-провайдер недоступен. Попробуйте позднее.']],$e->httpStatus,['Cache-Control'=>'no-store']);}
    }

    private function message(Request $r):array
    {
        $body=$this->input($r,['conversation_id','message']);
        if (!is_string($body['message']??null) || (isset($body['conversation_id'])&&!is_string($body['conversation_id'])))throw new ForumException('Некорректное сообщение.',422);
        return [$body['conversation_id']??null,$body['message']];
    }

    public function status(Request $r):JsonResponse {return $this->wrap(fn()=> $this->chat->status($this->user($r)));}
    public function list(Request $r):JsonResponse {return $this->wrap(fn()=> $this->chat->conversations($this->user($r)));}
    public function show(Request $r,array $p):JsonResponse {return $this->wrap(fn()=> $this->chat->messages($this->user($r),(string)$p['id']));}
    public function rename(Request $r,array $p):JsonResponse {return $this->wrap(function()use($r,$p){$v=$this->input($r,['title']);if(!is_string($v['title']??null))throw new ForumException('Укажите название.',422);return $this->chat->rename($this->user($r),(string)$p['id'],$v['title']);});}
    public function delete(Request $r,array $p):JsonResponse {return $this->wrap(function()use($r,$p){if($this->input($r,[])!==[])throw new ForumException('Неверный запрос.',422);$this->chat->delete($this->user($r),(string)$p['id']);return ['deleted'=>true];});}
    public function adminStatus(Request $r):JsonResponse {return $this->wrap(function()use($r){$this->user($r);\CajeerEngine\Forum\Security\ForumAccessPolicy::fromAuth($r->attributes->get('auth'))->require('forum:acp:settings');return $this->chat->administrator();});}
    public function adminConfigure(Request $r):JsonResponse {return $this->wrap(function()use($r){$uid=$this->user($r);\CajeerEngine\Forum\Security\ForumAccessPolicy::fromAuth($r->attributes->get('auth'))->require('forum:acp:settings');$settings=$this->chat->configure($this->input($r,['enabled','model','max_output_tokens','daily_request_limit','monthly_token_limit','revision']));$this->audit->record('forum.ai.settings.updated',$uid,['enabled'=>$settings['enabled'],'model'=>$settings['model'],'revision'=>$settings['revision']]);return $settings;});}

    public function send(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r){$uid=$this->user($r);[$id,$message]=$this->message($r);return $this->chat->send($uid,$id,$message);});
    }

    public function stream(Request $r):Response
    {
        // Validate ownership and input before any streamed bytes leave the server.
        try{$uid=$this->user($r);[$id,$message]=$this->message($r);}
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_ai_error','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'no-store']);}
        return new StreamedResponse(function()use($uid,$id,$message):void {
            try{$result=$this->chat->send($uid,$id,$message,fn(string $delta)=>self::event('delta',['text'=>$delta]));self::event('done',$result);}
            catch(ForumException $e){self::event('error',['message'=>$e->getMessage()]);}
            catch(AiProviderException $e){self::event('error',['message'=>'AI-провайдер недоступен. Попробуйте позднее.']);}
            catch(\DomainException|\InvalidArgumentException $e){self::event('error',['message'=>$e->getMessage()]);}
            catch(\Throwable){self::event('error',['message'=>'Не удалось завершить генерацию ответа.']);}
        },200,['Content-Type'=>'text/event-stream; charset=utf-8','Cache-Control'=>'private, no-store, no-transform','X-Accel-Buffering'=>'no','X-Content-Type-Options'=>'nosniff','Vary'=>'Cookie, Authorization']);
    }

    private static function event(string $type,array $payload):void
    {
        echo 'event: '.$type."\n".'data: '.json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n\n";
        if(function_exists('ob_flush')&&ob_get_level()>0)@ob_flush();flush();
    }
}
