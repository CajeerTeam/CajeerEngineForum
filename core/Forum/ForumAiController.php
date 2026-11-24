<?php

declare(strict_types=1);

namespace CajeerEngine\Forum;

use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Forum\Service\{ForumAiChat,ForumThreadAssistant,ForumAiSearch,ForumWritingAssistant,ForumModerationAdvisor,ForumAiInsights,ForumAdminAiActions};
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response,StreamedResponse};

/** Cookie-session protected member AI; never exposes CMS chat or credentials. */
final readonly class ForumAiController
{
    public function __construct(private ForumAiChat $chat, private AuditLogger $audit, private ForumThreadAssistant $threadAssistant, private ForumAiSearch $search, private ForumWritingAssistant $writing, private ForumModerationAdvisor $moderationAdvisor, private ForumAiInsights $insights, private ForumAdminAiActions $adminActions) {}

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

    /** All admin AI mutations are separate from the generation request. */
    public function adminActionList(Request $r):JsonResponse
    {return $this->wrap(fn()=> $this->adminActions->list($this->actor($r)));}

    public function adminActionGet(Request $r,array $p):JsonResponse
    {return $this->wrap(fn()=> $this->adminActions->get($this->actor($r),(string)$p['id']));}

    public function adminActionPropose(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r):array {
            $b=$this->input($r,['action','instructions','faq_id','acknowledge_external_processing']);
            if(!is_string($b['action']??null)||!is_string($b['instructions']??null)
                ||(isset($b['faq_id'])&&!is_string($b['faq_id']))||!is_bool($b['acknowledge_external_processing']??null))
                throw new ForumException('Неверные параметры AI-предложения.',422);
            return $this->adminActions->propose($this->actor($r),$b['action'],$b['instructions'],$b['faq_id']??null,$b['acknowledge_external_processing']);
        });
    }

    public function adminActionDecide(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p):array {
            $b=$this->input($r,['digest','decision']);
            if(!is_string($b['digest']??null)||!is_string($b['decision']??null))throw new ForumException('Неверное подтверждение.',422);
            return $this->adminActions->decide($this->actor($r),(string)$p['id'],$b['digest'],$b['decision']);
        });
    }

    /** Operator-only, anonymous analytics preview. No provider call. */
    public function insightsPreview(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r):array {
            $actor=$this->actor($r);$query=$r->query->all();
            if(array_diff(array_keys($query),['from','until','focus'])!==[])throw new ForumException('Неизвестные параметры отчёта.',422);
            foreach($query as $value)if(!is_string($value))throw new ForumException('Некорректные параметры отчёта.',422);
            return $this->insights->preview($actor,$query['from']??null,$query['until']??null,$query['focus']??'total');
        });
    }

    /** The provider receives only an explicitly approved anonymous aggregate snapshot. */
    public function insightsExplain(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r):array {
            $actor=$this->actor($r);
            $body=$this->input($r,['from','until','focus','acknowledge_external_processing']);
            foreach(['from','until','focus'] as $field)if(isset($body[$field])&&!is_string($body[$field]))throw new ForumException('Некорректные параметры отчёта.',422);
            if(($body['acknowledge_external_processing']??null)!==true)throw new ForumException('Требуется подтверждение передачи агрегатов.',422);
            $result=$this->insights->explain($actor,$body['from']??null,$body['until']??null,$body['focus']??'total',true);
            $this->audit->record('forum.ai.insights.generated',$actor->userId,['from'=>$result['report']['from'],'until'=>$result['report']['until'],'focus'=>$result['report']['focus']]);
            return $result;
        });
    }

    /** Advisory read is never sent to OpenAI. Permission and revision are rechecked. */
    public function moderationAdvice(Request $r,array $p):JsonResponse
    {
        return $this->wrap(fn()=> $this->moderationAdvisor->get($this->actor($r),(string)$p['kind'],(string)$p['id']));
    }

    /** Explicit disclosure and server-side ACL checks before billed provider generation. */
    public function moderationAssess(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p):array {
            $body=$this->input($r,['acknowledge_external_processing','include_non_public']);
            if(!is_bool($body['acknowledge_external_processing']??null) || !is_bool($body['include_non_public']??null))
                throw new ForumException('Неверное подтверждение AI-модерации.',422);
            return $this->moderationAdvisor->assess($this->actor($r),(string)$p['kind'],(string)$p['id'],
                $body['acknowledge_external_processing'],$body['include_non_public']);
        });
    }

    /** Server-side session, scope and strict payload checks precede any OpenAI call. */
    public function writingSuggest(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r):array {
            $actor=$this->actor($r);
            $actor->require('forum:write');
            return $this->writing->suggest($actor->userId,$this->input($r,['mode','body','title','body_format']));
        });
    }

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

    private function actor(Request $r):\CajeerEngine\Forum\Security\ForumAccessPolicy
    {
        $this->user($r);
        return \CajeerEngine\Forum\Security\ForumAccessPolicy::fromAuth($r->attributes->get('auth'));
    }

    private function threadInput(Request $r):array
    {
        $body=$this->input($r,['mode','message','conversation_id']);
        if(!is_string($body['mode']??null) || (isset($body['message'])&&!is_string($body['message']))
            || (isset($body['conversation_id'])&&!is_string($body['conversation_id'])))throw new ForumException('Некорректный запрос к AI.',422);
        return [$body['mode'],$body['message']??null,$body['conversation_id']??null];
    }

    public function threadStatus(Request $r,array $p):JsonResponse
    {
        return $this->wrap(fn()=> $this->threadAssistant->overview($this->actor($r),(string)$p['id']));
    }
    public function threadMessages(Request $r,array $p):JsonResponse
    {
        return $this->wrap(fn()=> $this->threadAssistant->messages($this->actor($r),(string)$p['id'],(string)$p['conversation_id']));
    }
    public function threadDelete(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p){
            $this->input($r,[]);
            $this->threadAssistant->delete($this->actor($r),(string)$p['id'],(string)$p['conversation_id']);
            return ['deleted'=>true];
        });
    }
    public function threadSend(Request $r,array $p):JsonResponse
    {
        return $this->wrap(function()use($r,$p){
            [$mode,$message,$conversation]=$this->threadInput($r);
            return $this->threadAssistant->send($this->actor($r),(string)$p['id'],$mode,$message,$conversation);
        });
    }
    public function threadStream(Request $r,array $p):Response
    {
        try{$actor=$this->actor($r);[$mode,$message,$conversation]=$this->threadInput($r);}
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_ai_error','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'no-store']);}
        return new StreamedResponse(function()use($actor,$p,$mode,$message,$conversation):void{
            try{
                $result=$this->threadAssistant->send($actor,(string)$p['id'],$mode,$message,$conversation,fn(string $delta)=>self::event('delta',['text'=>$delta]));
                self::event('done',$result);
            }catch(ForumException $e){self::event('error',['message'=>$e->getMessage()]);}
            catch(AiProviderException){self::event('error',['message'=>'AI-провайдер недоступен. Попробуйте позднее.']);}
            catch(\DomainException|\InvalidArgumentException $e){self::event('error',['message'=>$e->getMessage()]);}
            catch(\Throwable){self::event('error',['message'=>'Не удалось завершить генерацию ответа.']);}
        },200,['Content-Type'=>'text/event-stream; charset=utf-8','Cache-Control'=>'private, no-store, no-transform',
            'X-Accel-Buffering'=>'no','X-Content-Type-Options'=>'nosniff','Vary'=>'Cookie, Authorization']);
    }

    private function searchQuestion(Request $r):string
    {
        $body=$this->input($r,['question']);
        if(count($body)!==1)throw new ForumException('Укажите вопрос для поиска.',422);
        return ForumAiSearch::question($body['question']);
    }

    public function searchSources(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r):array {
            $actor=$this->actor($r);
            $query=$r->query->all();
            if(array_diff(array_keys($query),['q'])!==[])throw new ForumException('Неизвестные параметры поиска.',422);
            return $this->search->retrieve($actor,ForumAiSearch::question($query['q']??null));
        });
    }

    public function searchAnswer(Request $r):JsonResponse
    {
        return $this->wrap(function()use($r):array {
            $actor=$this->actor($r);
            return $this->search->answer($actor,$this->searchQuestion($r));
        });
    }

    public function searchStream(Request $r):Response
    {
        try{$actor=$this->actor($r);$question=$this->searchQuestion($r);}
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_ai_search_error','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'no-store']);}
        return new StreamedResponse(function()use($actor,$question):void {
            try {
                // The source event precedes model output and is generated by the server.
                $found=$this->search->retrieve($actor,$question);
                self::event('sources',$found);
                $answer=$this->search->answer($actor,$question,fn(string $delta)=>self::event('delta',['text'=>$delta]),$found);
                self::event('done',$answer);
            } catch(ForumException $e){self::event('error',['message'=>$e->getMessage()]);}
            catch(AiProviderException){self::event('error',['message'=>'AI-провайдер недоступен. Попробуйте позднее.']);}
            catch(\DomainException|\InvalidArgumentException $e){self::event('error',['message'=>$e->getMessage()]);}
            catch(\Throwable){self::event('error',['message'=>'Не удалось выполнить AI-поиск.']);}
        },200,['Content-Type'=>'text/event-stream; charset=utf-8','Cache-Control'=>'private, no-store, no-transform','X-Accel-Buffering'=>'no','X-Content-Type-Options'=>'nosniff','Vary'=>'Cookie, Authorization']);
    }

    private static function event(string $type,array $payload):void
    {
        echo 'event: '.$type."\n".'data: '.json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n\n";
        if(function_exists('ob_flush')&&ob_get_level()>0)@ob_flush();flush();
    }
}
