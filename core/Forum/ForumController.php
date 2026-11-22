<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Forum\Service\{DiscussionService, EngagementService, ModerationService, RegistrationService};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};

final readonly class ForumController
{
    public function __construct(private DiscussionService $discussions, private EngagementService $engagement, private ModerationService $moderation, private RegistrationService $registration, private string $root, private \CajeerEngine\Forum\Service\IdentityService $identity, private ?\CajeerEngine\Forum\Service\ForumRuntimeCache $cache=null,private ?\CajeerEngine\Extension\Runtime\PlatformServices $platform=null) {}

    public function page(Request $request, array $parameters=[]): Response
    {
        $settings=$this->discussions->seoSettings($this->root);
        if($request->query->has('theme_preview')){
            try{
                $q=$request->query->all();$scheme=$q['scheme']??'system';
                if(($q['theme_preview']??null)!=='1'||!in_array($scheme,['system','light','dark'],true))throw new ForumException('Некорректный предпросмотр.',422);
                $state=$this->discussions->admin()->listing($this->actor($request),['view'=>'themes']);
                if(isset($q['revision'])&&(!is_string($q['revision'])||$q['revision']!==(string)$state['revision']))throw new ForumException('Черновик изменён. Обновите ACP.',409);
                $draft=$state['draft'];
                // Preview is a public-reader simulation, never a privileged data view.
                return (new ForumPageRenderer($this->discussions,$this->root,$settings))->render($request,ForumAccessPolicy::optional(null),$draft,$scheme);
            }catch(ForumException $e){return new Response('Предпросмотр недоступен.', $e->status,['Content-Type'=>'text/plain; charset=UTF-8','Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow']);}
        }
        $load=fn()=> (new ForumPageRenderer($this->discussions,$this->root,$settings,$this->platform))->render($request,$this->reader($request));
        // Dynamic extension panels can depend on time or external state; never reuse stale HTML.
        if($this->platform?->hasForumPanels())return $load();
        return $this->cached($request,'page',$load,$settings);
    }
    public function legacy(Request $request,array $parameters=[]): Response
    {
        $headers=['Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow','Vary'=>'Cookie, Authorization, X-API-Token'];
        try{
            $job=(string)($parameters['job']??'');
            $path=isset($parameters['path'])?$this->discussions->legacyUrl($job,(string)$parameters['path'],$request->query->all()):$this->discussions->legacyLocation($job,(string)($parameters['kind']??''),(string)($parameters['id']??''));
            return new Response('',301,$headers+['Location'=>$path]);
        }catch(ForumException){return new Response('Страница не найдена.',404,$headers+['Content-Type'=>'text/plain; charset=UTF-8']);}
    }
    public function sitemap(Request $request, array $parameters=[]): Response
    {
        return (new ForumPageRenderer($this->discussions,$this->root))->sitemap($request);
    }
    public function rss(Request $request, array $parameters=[]): Response
    {
        return (new ForumPageRenderer($this->discussions,$this->root))->rss();
    }
    private function cached(Request $request,string $view,callable $load,?ForumSeoSettings $settings=null): Response
    { return $this->cache===null?$load():$this->cache->response($request,$view,$load,$settings); }
    private function cachedJson(Request $request,string $view,callable $load): JsonResponse
    { $response=$this->cached($request,$view,$load);if(!$response instanceof JsonResponse)throw new \LogicException('Expected JSON response');return $response; }
    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try { return new JsonResponse(['data'=>$operation()],$status,['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token']); }
        catch (ForumException $e) { return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token']+($e->status===503?['Retry-After'=>'3']:[])); }
    }
    private function field(Request $r, string $key): mixed
    {
        $json = $r->attributes->get('json',[]);
        return is_array($json) ? ($json[$key] ?? null) : null;
    }
    private function text(Request $r, string $key, int $max, int $min = 1): string { return V::text($this->field($r,$key),$key,$max,$min); }
    private function actor(Request $r): ForumAccessPolicy { return ForumAccessPolicy::fromAuth($r->attributes->get('auth')); }
    private function reader(Request $r): ForumAccessPolicy { return ForumAccessPolicy::optional($r->attributes->get('auth')); }
    private function id(array $p): string { return V::id($p['id'] ?? null); }
    private function offset(Request $r): int { return V::offset($r->query->all()['page'] ?? 1); }
    public function adminListing(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->admin()->listing($this->actor($r),$r->query->all())); }
    public function adminDelegation(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->admin()->delegation($this->actor($r),$this->id($p),$this->body($r))); }
    public function adminTheme(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->admin()->theme($this->actor($r),$this->body($r))); }
    public function adminPolicy(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->admin()->policy($this->actor($r),$this->body($r))); }
    public function adminMaintenance(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->admin()->maintenance($this->actor($r),$this->body($r))); }
    public function adminUpdateMember(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->admin()->updateMember($this->actor($r),$this->id($p),$this->body($r))); }
    public function index(Request $r): JsonResponse { return $this->cachedJson($r,'index',fn()=> $this->respond(fn () => $this->discussions->index($this->reader($r)))); }
    public function board(Request $r,array $p): JsonResponse { return $this->cachedJson($r,'board',fn()=> $this->respond(fn () => $this->discussions->board($this->id($p),$this->offset($r),$this->reader($r)))); }
    public function thread(Request $r,array $p): JsonResponse { return $this->cachedJson($r,'thread',fn()=> $this->respond(fn () => $this->discussions->thread($this->id($p),$this->offset($r),$this->reader($r),($r->query->all()['first_unread']??null)==='1'))); }
    public function search(Request $r): JsonResponse { return $this->respond(function () use ($r) {
        $q=$r->query->all();$tag=null;
        if (array_key_exists('tag',$q)) { [, $tags]=\CajeerEngine\Forum\Service\DiscussionManagementService::normalizeMetadata('',[$q['tag']]);$tag=$tags[0]; }
        $options=\CajeerEngine\Forum\Validation\ForumSearchOptions::parse($q);
        $term=V::text($q['q']??'','q',100,0);
        $mode=$q['mode']??'threads';
        if (!is_string($mode) || ($term!=='' && mb_strlen($term)<2)) throw new ForumException('Введите минимум два символа.');
        \CajeerEngine\Forum\Validation\ForumSearchOptions::validateTerm($term,$mode,$options,$tag);
        $offset=$this->offset($r);if($offset>=2500)throw new ForumException('Уточните запрос: доступны первые 100 страниц.');
        $results=$this->discussions->search($term,$offset,$this->reader($r),$tag,$mode,$options);
        if($offset===0 && $term!=='')$this->discussions->analyticsRead('forum_search_performed');
        return $results;
    }); }
    public function activity(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->activity()->feed($this->reader($r),$r->query->all()['scope']??'all',$this->offset($r))); }
    public function leaderboard(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->activity()->leaderboard($this->reader($r),$this->offset($r))); }
    public function relations(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->activity()->relations($this->actor($r),$r->query->all()['kind']??'follow',$this->offset($r))); }
    public function relationship(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->activity()->relationship($this->actor($r),$this->id($p))); }
    public function setRelationship(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->activity()->setRelation($this->actor($r),$this->id($p),$this->body($r))); }
    public function achievementRules(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->activity()->rules($this->actor($r))); }
    public function saveAchievementRule(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->activity()->saveRule($this->actor($r),$p['code']??null,$this->body($r))); }
    public function conversations(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->inbox($this->actor($r),$this->offset($r),($r->query->all()['archived']??null)==='1',($r->query->all()['notifications']??null)==='1',$r->query->all()['q']??'')); }
    public function createConversation(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->create($this->actor($r),$this->body($r)),201); }
    public function conversation(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->view($this->actor($r),$this->id($p),$this->offset($r))); }
    public function sendConversation(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->send($this->actor($r),$this->id($p),$this->body($r)),201); }
    public function editConversationMessage(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->edit($this->actor($r),$this->id($p),V::id($p['message_id']??null),$this->body($r))); }
    public function inviteConversation(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->invite($this->actor($r),$this->id($p),$this->body($r))); }
    public function removeConversationParticipant(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->removeParticipant($this->actor($r),$this->id($p),V::id($p['user_id']??null))); }
    public function readConversation(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->mark($this->actor($r),$this->id($p),$this->body($r))); }
    public function conversationFlags(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->flags($this->actor($r),$this->id($p),$this->body($r))); }
    public function leaveConversation(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->leave($this->actor($r),$this->id($p))); }
    public function conversationSettings(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->settings($this->actor($r))); }
    public function saveConversationSettings(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->setSettings($this->actor($r),$this->body($r))); }
    public function blockConversationUser(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->block($this->actor($r),$this->id($p),$this->body($r))); }
    public function reportConversationMessage(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->report($this->actor($r),$this->id($p),V::id($p['message_id']??null),$this->body($r)),201); }
    public function conversationReports(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->reports($this->actor($r),$this->offset($r),V::text($r->query->all()['status']??'all','status',12))); }
    public function conversationReport(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->reportDetail($this->actor($r),$this->id($p))); }
    public function resolveConversationReport(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->resolve($this->actor($r),$this->id($p),$this->body($r))); }
    public function notifications(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->inbox($this->actor($r),$this->offset($r),($r->query->all()['count_only']??null)==='1')); }
    public function notificationTarget(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->target($this->actor($r),$this->id($p))); }
    public function markNotifications(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->mark($this->actor($r),$this->body($r))); }
    public function notificationPreferences(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->preferences($this->actor($r),$this->body($r))); }
    public function unread(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->readTracking()->unread($this->actor($r),$this->offset($r))); }
    public function markRead(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->readTracking()->mark($this->actor($r),$this->body($r))); }
    public function latest(Request $r): JsonResponse { if(array_key_exists('cursor',$r->query->all()))return $this->cachedJson($r,'latest-cursor',fn()=> $this->respond(fn()=> $this->discussions->latestCursor(\CajeerEngine\Forum\Validation\ForumFeedCursor::decode($r->query->all()['cursor']),$this->reader($r))));return $this->cachedJson($r,'latest',fn()=> $this->respond(fn () => $this->discussions->latest($this->offset($r),$this->reader($r)))); }
    public function profile(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->discussions->profile($this->id($p),$this->offset($r),$this->reader($r))); }
    private function body(Request $r): array { $data=$r->attributes->get('json',[]);return is_array($data)?$data:[]; }
    private function revisionInput(Request $r,string $key,bool $zero=false): int {return \CajeerEngine\Forum\Service\DraftService::revision($this->field($r,$key),$zero);}
    public function drafts(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->drafts()->list($this->actor($r),$this->offset($r),$r->query->all()['scope']??null,$r->query->all()['target_id']??null));}
    public function draft(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->drafts()->get($this->actor($r),$this->id($p)));}
    public function saveDraft(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->drafts()->save($this->actor($r),$this->id($p),$this->body($r)));}
    public function deleteDraft(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->drafts()->delete($this->actor($r),$this->id($p),$this->revisionInput($r,'revision_number')));}
    public function publishDraft(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->drafts()->publish($this->actor($r),$this->id($p),$this->revisionInput($r,'revision_number'),$this->discussions));}
    public function postRevisions(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->revisionHistory($this->actor($r),$this->id($p),$this->offset($r)));}
    public function restorePostRevision(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->restoreRevision($this->actor($r),$this->id($p),$this->revisionInput($r,'revision_number'),$this->revisionInput($r,'expected_revision')));}
    public function conversationRevisions(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->conversations()->revisionHistory($this->actor($r),$this->id($p),V::id($p['message_id']??null),$this->offset($r)));}
    public function restoreConversationRevision(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->conversations()->restoreRevision($this->actor($r),$this->id($p),V::id($p['message_id']??null),$this->revisionInput($r,'revision_number'),$this->revisionInput($r,'expected_revision')));}
    public function members(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->directory($this->reader($r),$r->query->all()['q']??'',$this->offset($r),$r->query->all()['sort']??'name',($r->query->all()['discovery']??null)==='1')); }
    public function ownProfile(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->own($this->actor($r))); }
    public function updateProfile(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->update($this->actor($r),$this->body($r))); }
    public function updatePreferences(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->preferences($this->actor($r),$this->body($r))); }
    public function setProfileImage(Request $r,array $p): JsonResponse { return $this->respond(function()use($r,$p){$data=$this->field($r,'data');if(!is_string($data)||$data==='')throw new ForumException('Требуется изображение.',422);return $this->discussions->profiles()->setImage($this->actor($r),$p['kind'],$data);}); }
    public function deleteProfileImage(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->setImage($this->actor($r),$p['kind'],null)); }
    public function profileImage(Request $r,array $p): Response {
        $headers=['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token','X-Content-Type-Options'=>'nosniff'];
        try { return new Response($this->discussions->profiles()->image($this->reader($r),$this->id($p),$p['kind']),200,$headers+['Content-Type'=>'image/jpeg']); }
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$e->getMessage()]],$e->status,$headers);}
    }
    public function profileFields(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->fields($this->actor($r))); }
    public function createProfileField(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->saveField($this->actor($r),null,$this->body($r)),201); }
    public function updateProfileField(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->saveField($this->actor($r),$this->id($p),$this->body($r))); }
    public function deleteProfileField(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->deleteField($this->actor($r),$this->id($p))); }
    public function moderateProfile(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->moderate($this->actor($r),$this->id($p),$this->field($r,'field'))); }
    public function register(Request $r): JsonResponse { return $this->respond(function () use ($r) {
        $this->registration->checkAttempt($r->getClientIp()??'unknown',$this->field($r,'website'));
        $email=$this->text($r,'email',254);
        $id=$this->registration->register($email,$this->text($r,'name',60,2),V::password($this->field($r,'password')));
        $required=RegistrationService::verificationRequired();
        if ($required) $this->identity->requestMail($email,'verify');
        return ['id'=>$id,'verification_required'=>$required];
    },201); }
    private function contentFormat(Request $r): ?string
    { $body=$this->body($r);return array_key_exists('body_format',$body)?\CajeerEngine\Forum\Content\ForumContentRenderer::format($body['body_format']):null; }
    public function attachments(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->attachments()->pending($this->actor($r)));}
    public function uploadAttachment(Request $r): JsonResponse
    {
        return $this->respond(function()use($r){
            $actor=$this->actor($r);$actor->require('forum:write');$file=$r->files->get('file');
            if(!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile||!$file->isValid())throw new ForumException('Требуется файл multipart.',422);
            if($file->getSize()>26214400)throw new ForumException('Файл слишком большой.',413);
            $data=file_get_contents($file->getPathname());if($data===false)throw new ForumException('Не удалось прочитать файл.',422);
            return $this->discussions->attachments()->upload($actor,$file->getClientOriginalName(),$data);
        },201);
    }
    public function attachment(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->attachments()->get($this->reader($r),$this->id($p)));}
    public function deleteAttachment(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->attachments()->remove($this->actor($r),$this->id($p)));}
    public function downloadAttachment(Request $r,array $p): Response
    {
        $headers=['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token','X-Content-Type-Options'=>'nosniff'];
        try{$a=$this->discussions->attachments()->get($this->reader($r),$this->id($p),true);
            // The file was authorized and checked for corruption; never log its ID or name.
            $this->discussions->analyticsRead('forum_attachment_downloaded');
            return new Response($a['data'],200,$headers+['Content-Type'=>'application/octet-stream','Content-Length'=>(string)$a['size_bytes'],'Content-Disposition'=>"attachment; filename=\"download\"; filename*=UTF-8''".rawurlencode($a['filename']),'Content-Security-Policy'=>"default-src 'none'; sandbox"]);
        }catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$e->getMessage()]],$e->status,$headers);}
    }
    public function attachmentImage(Request $r,array $p): Response
    {
        $headers=['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'none'; sandbox"];
        try{$v=$this->discussions->attachments()->image($this->reader($r),$this->id($p),(string)($p['variant']??''));return new Response($v['data'],200,$headers+['Content-Type'=>$v['mime'],'Content-Length'=>(string)$v['size_bytes'],'Content-Disposition'=>'inline']);}
        catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$e->getMessage()]],$e->status,$headers);}
    }
    public function mediaStatus(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->attachments()->mediaStats($this->actor($r)));}
    public function restoreAttachmentOriginal(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->attachments()->restoreOriginal($this->actor($r),$this->id($p)));}
    public function retryAttachmentImage(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->attachments()->retryImage($this->actor($r),$this->id($p)));}
    public function previewContent(Request $r): JsonResponse
    { return $this->respond(function()use($r){$this->actor($r)->require('forum:write');$format=$this->contentFormat($r)??'plain';$body=\CajeerEngine\Forum\Content\ForumContentRenderer::source($this->field($r,'body'),$format,true);return \CajeerEngine\Forum\Content\ForumContentRenderer::render($body,$format);}); }
    public function createThread(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->submit($this->actor($r),'thread',$this->id($p),$this->body($r),$this->discussions),201);}
    public function reply(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->submit($this->actor($r),'reply',$this->id($p),$this->body($r),$this->discussions),201);}
    public function edit(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->edit($this->actor($r),$this->id($p),$this->field($r,'body'),$this->contentFormat($r),$this->field($r,'attachment_ids')??[],$this->field($r,'expected_revision')===null?null:$this->revisionInput($r,'expected_revision'),\CajeerEngine\Forum\Service\DraftService::reason($this->field($r,'edit_reason')))]); }
    public function reaction(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->engagement->reaction($this->actor($r),$this->id($p),V::enabled($this->field($r,'enabled')),$this->field($r,'type'))); }
    public function watch(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->engagement->watch($this->actor($r),$this->id($p),V::enabled($this->field($r,'enabled')),$this->field($r,'mode'))); }
    public function boardWatch(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->set($this->actor($r),'board',$this->id($p),V::enabled($this->field($r,'enabled')),$this->field($r,'mode'))); }
    public function watchSettings(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->settings($this->actor($r),$this->offset($r))); }
    public function watchPreferences(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->preferences($this->actor($r),$this->field($r,'auto_mode'))); }
    public function unsubscribeEmail(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->unsubscribe($this->field($r,'token'))); }
    public function watches(Request $r): JsonResponse { return $this->respond(fn () => $this->engagement->watches($this->actor($r),$this->offset($r))); }
    public function report(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->report($this->actor($r),$this->id($p),$this->text($r,'reason',500,3),V::text($this->field($r,'category')??'other','category',20))],201); }
    public function myReports(Request $r): JsonResponse {return $this->respond(fn()=>$this->moderation->mine($this->actor($r),V::text($r->query->all()['kind']??'public','kind',10),$this->offset($r)));}
    public function reports(Request $r): JsonResponse { return $this->respond(fn () => $this->moderation->reports($this->actor($r),$this->offset($r))); }
    public function resolve(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->resolve($this->actor($r),$this->id($p))]); }
    public function spamStats(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->antiSpam()->stats($this->actor($r)));}
    public function mySanctions(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->sanctions()->listing($this->actor($r),$this->actor($r)->userId,$this->offset($r)));}
    public function memberSanctions(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->sanctions()->listing($this->actor($r),$this->id($p),$this->offset($r)));}
    public function issueSanction(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->sanctions()->issue($this->actor($r),$this->id($p),$this->body($r)),201);}
    public function revokeSanction(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->sanctions()->revoke($this->actor($r),$this->id($p),$this->body($r)));}
    public function appealSanction(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->sanctions()->appeal($this->actor($r),$this->id($p),$this->body($r)),201);}
    public function sanctionAppeals(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->sanctions()->appeals($this->actor($r),$this->offset($r)));}
    public function decideSanctionAppeal(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->sanctions()->decide($this->actor($r),$this->id($p),$this->body($r)));}
    public function moderationQueue(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->listing($this->actor($r),V::text($r->query->all()['kind']??'report','kind',16),V::text($r->query->all()['status']??'all','status',16),$this->offset($r),isset($r->query->all()['assigned'])?V::text($r->query->all()['assigned'],'assigned',36):null));}
    public function moderationDetail(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->detail($this->actor($r),V::text($p['kind']??null,'kind',16),$this->id($p),$this->offset($r)));}
    public function moderationAction(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->action($this->actor($r),V::text($p['kind']??null,'kind',16),$this->id($p),$this->body($r),$this->discussions));}
    public function reviewPolicy(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->policy($this->actor($r),$this->id($p),V::enabled($this->field($r,'review_required'))));}
    public function mySubmissions(Request $r): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->mine($this->actor($r),$this->offset($r)));}
    public function cancelSubmission(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->discussions->moderationQueue()->action($this->actor($r),'submission',$this->id($p),['action'=>'cancel','revision_number'=>$this->field($r,'revision_number')],$this->discussions,true));}
    public function reviewDownload(Request $r,array $p): Response
    {
        $headers=['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'none'; sandbox"];
        try{$a=$this->discussions->moderationQueue()->download($this->actor($r),$this->id($p),V::id($p['file_id']??null));return new Response($a['data'],200,$headers+['Content-Type'=>'application/octet-stream','Content-Length'=>(string)$a['size_bytes'],'Content-Disposition'=>"attachment; filename=\"download\"; filename*=UTF-8''".rawurlencode($a['filename'])]);}catch(ForumException $e){return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$e->getMessage()]],$e->status,$headers);}
    }
    public function recycleBin(Request $r): JsonResponse {return $this->respond(fn()=>$this->moderation->recycle()->listing($this->actor($r),$this->offset($r),V::text($r->query->all()['kind']??'all','kind',10)));}
    public function recycleDetail(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->moderation->recycle()->detail($this->actor($r),V::text($p['kind']??null,'kind',10),$this->id($p),$this->offset($r)));}
    public function recycleChange(Request $r,array $p): JsonResponse {return $this->respond(fn()=>$this->moderation->recycle()->change($this->actor($r),V::text($p['kind']??null,'kind',10),$this->id($p),$this->text($r,'action',10),V::text($this->body($r)['reason']??'','reason',500,0),isset($this->body($r)['deletion_id'])?V::id($this->body($r)['deletion_id']):null));}
    public function moderate(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->moderate($this->actor($r),$this->id($p),$this->text($r,'action',20),V::text($this->body($r)['reason']??'','reason',500,0))]); }
    public function pollVote(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->polls()->vote($this->actor($r),$this->id($p),$this->field($r,'choices'))); }
    public function pollControl(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->polls()->control($this->actor($r),$this->id($p),$this->field($r,'closed'),$this->field($r,'closes_at'))); }
    public function acceptAnswer(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->polls()->accept($this->actor($r),$this->id($p),$this->field($r,'post_id'))); }
    public function answerVote(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->polls()->answerVote($this->actor($r),$this->id($p),$this->field($r,'enabled'))); }
    public function answers(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->polls()->answers($this->reader($r),$this->id($p),$this->offset($r),$r->query->all()['sort']??'score')); }
    public function questions(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->polls()->questions($this->reader($r),$this->offset($r),$r->query->all()['status']??'all')); }
    public function threadMetadata(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->management()->metadata($this->actor($r),$this->id($p),$this->field($r,'prefix'),$this->field($r,'tags'))); }
    public function bulkThreads(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->management()->bulk($this->actor($r),$this->field($r,'thread_ids'),$this->field($r,'action'),$this->field($r,'board_id'))); }
    public function mergeThreads(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->management()->merge($this->actor($r),$this->id($p),$this->field($r,'source_ids'))); }
    public function transferPosts(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->management()->transfer($this->actor($r),$this->id($p),$this->field($r,'post_ids'),$this->field($r,'target_id'),$this->field($r,'board_id'),$this->field($r,'title'))); }
    public function category(Request $r): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->category($this->actor($r),$this->text($r,'name',120),$this->text($r,'description',1000,0))],201); }
    private function parent(Request $r): ?string { $value=$this->field($r,'parent_id');return $value===null?null:V::id($value); }
    private function position(Request $r): ?int {
        $value=$this->field($r,'position');
        if ($value===null) return null;
        if (!is_int($value) || $value<0) throw new ForumException('position должен быть целым неотрицательным числом.',422);
        return $value;
    }
    public function createBoard(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->createBoard($this->actor($r),$this->id($p),$this->text($r,'name',120),$this->text($r,'description',1000,0),$this->parent($r),V::text($this->field($r,'node_type')??'forum','node_type',16),V::text($this->field($r,'state')??'open','state',16),$this->position($r),V::text($this->field($r,'rules')??'','rules',4000,0),V::enabled($this->field($r,'seo_index')??true))],201); }
    public function updateCategory(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->updateCategory($this->actor($r),$this->id($p),$this->text($r,'name',120),$this->text($r,'description',1000,0),$this->position($r))]); }
    public function updateBoard(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->updateBoard($this->actor($r),$this->id($p),$this->text($r,'name',120),$this->text($r,'description',1000,0),$this->text($r,'node_type',16),$this->text($r,'state',16),$this->field($r,'rules')===null?null:$this->text($r,'rules',4000,0),$this->field($r,'seo_index')===null?null:V::enabled($this->field($r,'seo_index')))]); }
    public function moveBoard(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->move($this->actor($r),$this->id($p),V::id($this->field($r,'category_id')),$this->parent($r),$this->position($r))]); }
    public function reorderNodes(Request $r): JsonResponse { return $this->respond(fn()=> ['id'=>$this->discussions->nodes()->reorder($this->actor($r),$this->text($r,'kind',16),V::id($this->field($r,'id')),$this->text($r,'target_kind',16),V::id($this->field($r,'target_id')),$this->text($r,'placement',16))]); }
    public function permissionInspector(Request $r): JsonResponse { return $this->respond(function () use ($r) {
        $actor=$this->actor($r);$actor->require('*');$q=$r->query->all();
        $kind=V::text($q['kind']??null,'kind',16);$id=V::id($q['id']??null);
        $user=array_key_exists('user_id',$q)?V::id($q['user_id']):null;
        return $this->discussions->permissions()->inspect($actor,$kind,$id,$user);
    }); }
    public function boardPermissions(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->permissions()->get($this->actor($r),'board',$this->id($p))); }
    public function categoryPermissions(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->permissions()->get($this->actor($r),'category',$this->id($p))); }
    public function setBoardPermissions(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->permissions()->replace($this->actor($r),'board',$this->id($p),$this->field($r,'visibility'),$this->field($r,'rules'),$this->field($r,'revision'))); }
    public function setCategoryPermissions(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->permissions()->replace($this->actor($r),'category',$this->id($p),$this->field($r,'visibility'),$this->field($r,'rules'),$this->field($r,'revision'))); }
    public function groups(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->groups()->all($this->actor($r))); }
    public function createGroup(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->groups()->create($this->actor($r),$this->field($r,'handle'),$this->field($r,'name'),$this->field($r,'description')??'', $this->field($r,'permissions')),201); }
    public function updateGroup(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->groups()->update($this->actor($r),$this->id($p),$this->field($r,'name'),$this->field($r,'description')??'', $this->field($r,'permissions'))); }
    public function deleteGroup(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->groups()->delete($this->actor($r),$this->id($p))); }
    public function groupMembership(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->groups()->membership($this->actor($r),$this->id($p))); }
    public function setGroupMembership(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->groups()->assign($this->actor($r),$this->id($p),$this->field($r,'primary_group_id'),$this->field($r,'secondary_group_ids'))); }
    public function deleteBoard(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->delete($this->actor($r),$this->id($p),false)]); }
    public function deleteCategory(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->delete($this->actor($r),$this->id($p),true)]); }
}
