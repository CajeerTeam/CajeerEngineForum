<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Forum\Service\{DiscussionService, EngagementService, ModerationService, RegistrationService};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};

final readonly class ForumController
{
    public function __construct(private DiscussionService $discussions, private EngagementService $engagement, private ModerationService $moderation, private RegistrationService $registration, private string $root, private \CajeerEngine\Forum\Service\IdentityService $identity) {}

    public function page(): Response
    {
        $name = htmlspecialchars(getenv('APP_NAME') ?: 'CajeerEngine Forum Edition', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $registration = filter_var(getenv('FORUM_REGISTRATION') === false ? 'true' : getenv('FORUM_REGISTRATION'), FILTER_VALIDATE_BOOLEAN);
        $html = strtr((string)file_get_contents($this->root.'/public/forum-assets/index.html'), ['<!--FORUM_NAME-->'=>$name,'<!--FORUM_REGISTRATION-->'=>$registration?'true':'false']);
        return new Response($html, 200, ['Content-Type'=>'text/html; charset=UTF-8']);
    }
    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try { return new JsonResponse(['data'=>$operation()],$status,['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token']); }
        catch (ForumException $e) { return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$e->getMessage()]],$e->status,['Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token']); }
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
    public function index(Request $r): JsonResponse { return $this->respond(fn () => $this->discussions->index($this->reader($r))); }
    public function board(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->discussions->board($this->id($p),$this->offset($r),$this->reader($r))); }
    public function thread(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->discussions->thread($this->id($p),$this->offset($r),$this->reader($r),($r->query->all()['first_unread']??null)==='1')); }
    public function search(Request $r): JsonResponse { return $this->respond(function () use ($r) {
        $q=$r->query->all();$tag=null;
        if (array_key_exists('tag',$q)) { [, $tags]=\CajeerEngine\Forum\Service\DiscussionManagementService::normalizeMetadata('',[$q['tag']]);$tag=$tags[0]; }
        $term=V::text($q['q']??'','q',100,$tag===null?2:0);
        return $this->discussions->search($term,$this->offset($r),$this->reader($r),$tag);
    }); }
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
    public function conversationReports(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->reports($this->actor($r),$this->offset($r))); }
    public function conversationReport(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->reportDetail($this->actor($r),$this->id($p))); }
    public function resolveConversationReport(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->conversations()->resolve($this->actor($r),$this->id($p),$this->body($r))); }
    public function notifications(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->inbox($this->actor($r),$this->offset($r),($r->query->all()['count_only']??null)==='1')); }
    public function notificationTarget(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->target($this->actor($r),$this->id($p))); }
    public function markNotifications(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->mark($this->actor($r),$this->body($r))); }
    public function notificationPreferences(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->notifications()->preferences($this->actor($r),$this->body($r))); }
    public function unread(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->readTracking()->unread($this->actor($r),$this->offset($r))); }
    public function markRead(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->readTracking()->mark($this->actor($r),$this->body($r))); }
    public function latest(Request $r): JsonResponse { return $this->respond(fn () => $this->discussions->latest($this->offset($r),$this->reader($r))); }
    public function profile(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->discussions->profile($this->id($p),$this->offset($r),$this->reader($r))); }
    private function body(Request $r): array { $data=$r->attributes->get('json',[]);return is_array($data)?$data:[]; }
    public function members(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->profiles()->directory($this->reader($r),$r->query->all()['q']??'',$this->offset($r),$r->query->all()['sort']??'name')); }
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
        $email=$this->text($r,'email',254);
        $id=$this->registration->register($email,$this->text($r,'name',60,2),V::password($this->field($r,'password')));
        $required=RegistrationService::verificationRequired();
        if ($required) $this->identity->requestMail($email,'verify');
        return ['id'=>$id,'verification_required'=>$required];
    },201); }
    public function createThread(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->createThread($this->actor($r),$this->id($p),$this->text($r,'title',180,3),$this->text($r,'body',20000))],201); }
    public function reply(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->reply($this->actor($r),$this->id($p),$this->text($r,'body',20000))],201); }
    public function edit(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->edit($this->actor($r),$this->id($p),$this->text($r,'body',20000))]); }
    public function reaction(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->engagement->reaction($this->actor($r),$this->id($p),V::enabled($this->field($r,'enabled')))); }
    public function watch(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->engagement->watch($this->actor($r),$this->id($p),V::enabled($this->field($r,'enabled')),$this->field($r,'mode'))); }
    public function boardWatch(Request $r,array $p): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->set($this->actor($r),'board',$this->id($p),V::enabled($this->field($r,'enabled')),$this->field($r,'mode'))); }
    public function watchSettings(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->settings($this->actor($r),$this->offset($r))); }
    public function watchPreferences(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->preferences($this->actor($r),$this->field($r,'auto_mode'))); }
    public function unsubscribeEmail(Request $r): JsonResponse { return $this->respond(fn()=> $this->discussions->watchDelivery()->unsubscribe($this->field($r,'token'))); }
    public function watches(Request $r): JsonResponse { return $this->respond(fn () => $this->engagement->watches($this->actor($r),$this->offset($r))); }
    public function report(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->report($this->actor($r),$this->id($p),$this->text($r,'reason',500,3))],201); }
    public function reports(Request $r): JsonResponse { return $this->respond(fn () => $this->moderation->reports($this->actor($r),$this->offset($r))); }
    public function resolve(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->resolve($this->actor($r),$this->id($p))]); }
    public function moderate(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->moderate($this->actor($r),$this->id($p),$this->text($r,'action',20))]); }
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
    public function createBoard(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->createBoard($this->actor($r),$this->id($p),$this->text($r,'name',120),$this->text($r,'description',1000,0),$this->parent($r),V::text($this->field($r,'node_type')??'forum','node_type',16),V::text($this->field($r,'state')??'open','state',16),$this->position($r),V::text($this->field($r,'rules')??'','rules',4000,0))],201); }
    public function updateCategory(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->updateCategory($this->actor($r),$this->id($p),$this->text($r,'name',120),$this->text($r,'description',1000,0),$this->position($r))]); }
    public function updateBoard(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->nodes()->updateBoard($this->actor($r),$this->id($p),$this->text($r,'name',120),$this->text($r,'description',1000,0),$this->text($r,'node_type',16),$this->text($r,'state',16),$this->field($r,'rules')===null?null:$this->text($r,'rules',4000,0))]); }
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
