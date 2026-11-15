<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Forum\Repository\{BoardRepository, ThreadRepository, PostRepository, MemberRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Support\Uuid;

final readonly class DiscussionService
{
    public function __construct(private DatabaseManager $db, private ForumTransaction $tx, private BoardRepository $boards, private ThreadRepository $threads, private PostRepository $posts, private MemberRepository $members) {}

    public function seoSettings(string $root): \CajeerEngine\Forum\ForumSeoSettings { return new \CajeerEngine\Forum\ForumSeoSettings($root,$this->db,true); }

    private function access(?ForumAccessPolicy $actor): array { return $this->tx->permissions()->access($actor??new ForumAccessPolicy('',[])); }
    private function decorated(array $board,array $access): array { $board['permissions']=$access['boards'][$board['id']]; return $board; }
    public function admin(): ForumAdminService { return new ForumAdminService($this->db,$this->tx); }
    public function antiSpam(): AntiSpamService {return $this->tx->antiSpam();}
    public function sanctions(): SanctionService {return $this->tx->sanctions();}
    public function moderationQueue(): ModerationQueueService { return $this->tx->moderationQueue(); }
    public function drafts(): DraftService { return $this->tx->drafts(); }
    public function revisionHistory(ForumAccessPolicy $actor,string $id,int $offset=0,?int $number=null): array
    {
        return $this->tx->read(function()use($actor,$id,$offset,$number){
            $this->tx->permissions()->requireCurrent($actor,'forum:write');
            $post=(new \CajeerEngine\Forum\Repository\AttachmentRepository($this->db))->post($id)??throw new ForumException('Сообщение не найдено.',404);
            $access=$this->access($actor);NodePermissionService::assert($access,$post['board_id'],'thread:read');
            if($post['author_id']!==$actor->userId)NodePermissionService::assert($access,$post['board_id'],'post:edit_any');
            return $number===null?$this->tx->revisions()->history('post',$id,$offset):$this->tx->revisions()->version('post',$id,$number);
        });
    }
    public function restoreRevision(ForumAccessPolicy $actor,string $id,int $number,int $expected): array
    {
        $old=$this->revisionHistory($actor,$id,0,$number);
        $this->edit($actor,$id,$old['body'],$old['body_format'],[],$expected,'Restore revision '.$number,true);
        return ['id'=>$id,'revision_number'=>$expected+1];
    }
    public function polls(): PollQuestionService { return $this->tx->polls(); }
    public function attachments(): AttachmentService { return $this->tx->attachments(); }
    public function conversations(): ConversationService { return $this->tx->conversations(); }
    public function watchDelivery(): WatchService { return $this->tx->watches(); }
    public function notifications(): NotificationService { return $this->tx->notifications(); }
    public function readTracking(): ReadTrackingService { return new ReadTrackingService($this->tx,$this->tx->readState(),$this->boards,$this->threads); }
    private function readBoards(array $boards,array $allowed,string $viewer): array
    {
        if ($viewer==='') return $boards;
        $counts=$this->tx->readState()->boardCounts($viewer,$allowed);
        $topology=array_column($this->boards->topology(),null,'id');
        $readable=array_fill_keys($allowed,true);$totals=[];
        // Propagate each nonzero count up at most 16 accessible ancestors.
        foreach($counts as $id=>$count) {
            $seen=[];
            while(isset($readable[$id],$topology[$id]) && !isset($seen[$id]) && count($seen)<16) {
                $seen[$id]=true;$totals[$id]=($totals[$id]??0)+(int)$count;
                $id=$topology[$id]['parent_id']??'';
            }
        }
        foreach($boards as &$board) $board['unread_threads']=$totals[$board['id']]??0;
        unset($board);
        return $boards;
    }
    /** Build the full path from the same ACL-protected snapshot as the content. */
    private function breadcrumbs(array $board,array $access): array
    {
        $path=[];$seen=[];$categoryId=$board['category_id'];
        while (true) {
            NodePermissionService::assert($access,$board['id'],'thread:read');
            if (isset($seen[$board['id']]) || count($seen)>=16 || $board['category_id']!==$categoryId) throw new ForumException('Раздел не найден.',404);
            $seen[$board['id']]=true;
            $path[]=['kind'=>'board','id'=>$board['id'],'name'=>$board['name'],'seo_index'=>(int)($board['seo_index']??1)];
            if ($board['parent_id']===null) break;
            $board=$this->boards->find($board['parent_id'])??throw new ForumException('Раздел не найден.',404);
        }
        $categories=$this->boards->categories([$categoryId]);
        if (!in_array($categoryId,NodePermissionService::allowed($access,'thread:read','categories'),true) || !$categories) throw new ForumException('Раздел не найден.',404);
        $path[]=['kind'=>'category','id'=>$categoryId,'name'=>$categories[0]['name']];
        return array_reverse($path);
    }
    public function index(?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($actor) {
            $access=$this->access($actor);$allowed=NodePermissionService::allowed($access);$categories=NodePermissionService::allowed($access,'thread:read','categories');
            return ['categories'=>$this->boards->categories($categories), 'boards'=>$this->readBoards(array_map(fn($b)=>$this->decorated($b,$access),$this->boards->boards($allowed)),$allowed,$actor?->userId??'')];
        });
    }
    /** Only guest-readable boards with an indexable ancestor chain. Called inside a read snapshot. */
    private function seoBoards(array $excluded): array
    {
        $access=$this->access(null);$allowed=array_fill_keys(NodePermissionService::allowed($access),true);
        $topology=array_column($this->boards->topology(),null,'id');$blocked=array_fill_keys($excluded,true);$ids=[];
        foreach($topology as $id=>$board){
            $cursor=$board;$seen=[];
            while(true){
                $key=$cursor['id'];
                if(!isset($allowed[$key])||isset($blocked[$key])||(int)($cursor['seo_index']??1)!==1||isset($seen[$key])||count($seen)>=16)continue 2;
                $seen[$key]=true;
                if($cursor['parent_id']===null)break;
                if(!isset($topology[$cursor['parent_id']]))continue 2;
                $cursor=$topology[$cursor['parent_id']];
            }
            $ids[]=$id;
        }
        return $ids;
    }
    /** Resolve guest visibility and SEO exclusions in the same snapshot as sitemap contents. */
    public function sitemapPage(?int $page,array $excluded): array
    {
        return $this->db->readSnapshot(function () use ($page,$excluded) {
            $ids=$this->seoBoards($excluded);$repo=new \CajeerEngine\Forum\Repository\ForumSitemapRepository($this->db);
            $result=\CajeerEngine\Forum\ForumSitemapPage::plan($ids,$repo->countThreads($ids),$page);
            if($page!==null && $result['thread_limit']>0)foreach($repo->threads($ids,$result['thread_offset'],$result['thread_limit']) as $thread)$result['paths'][]='/forum/threads/'.$thread['id'];
            return $result;
        });
    }
    public function seoLatest(array $excluded): array
    {
        return $this->db->readSnapshot(fn()=> (new \CajeerEngine\Forum\Repository\ForumSitemapRepository($this->db))->latest($this->seoBoards($excluded)));
    }
    public function board(string $id, int $offset, ?ForumAccessPolicy $actor=null,bool $seo=false): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor,$seo) {
            $access=$this->access($actor);NodePermissionService::assert($access,$id,'thread:read');
            $board=$this->boards->view($id)??throw new ForumException('Раздел не найден.',404);
            $allowed=NodePermissionService::allowed($access);$viewer=$actor?->userId??'';
            $children=$this->boards->children($id,$allowed);
            foreach ($children as &$child) $child['effective_state']=($this->boards->view($child['id'])??$child)['effective_state']??'archived';
            unset($child);
            $nodes=$this->readBoards([$this->decorated($board,$access),...array_map(fn($b)=>$this->decorated($b,$access),$children)],$allowed,$viewer);
            $current=array_shift($nodes);if($seo)$current['threads']=$this->threads->countInBoard($id);$current['watch_mode']=$this->tx->watches()->mode('board',$id,$viewer);
            return ['board'=>$current,'breadcrumbs'=>$this->breadcrumbs($board,$access),'threads'=>$this->tx->readState()->decorateThreads($this->threads->inBoard($id,$offset,$viewer),$viewer),'children'=>$nodes];
        });
    }
    public function thread(string $id, int $offset, ?ForumAccessPolicy $actor=null, bool $firstUnread=false,bool $seo=false): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor,$firstUnread,$seo) {
            $repo=new \CajeerEngine\Forum\Repository\DiscussionManagementRepository($this->db);
            $access=$this->access($actor);$thread=$this->threads->find($id);
            if ($thread===null) {
                $redirect=$repo->findRedirect($id)??throw new ForumException('Тема не найдена.',404);
                NodePermissionService::assert($access,$redirect['source_board_id'],'thread:read');
                $id=$redirect['target_id'];$thread=$this->threads->find($id)??throw new ForumException('Тема не найдена.',404);
            }
            NodePermissionService::assert($access,$thread['board_id'],'thread:read');$thread['tags']=$repo->tags($id);
            $board=$this->boards->view($thread['board_id'])??throw new ForumException('Раздел не найден.',404);
            $viewer=$actor?->userId??'';
            $thread['watch_mode']=$this->tx->watches()->mode('thread',$id,$viewer);
            $state=$viewer===''?null:$this->tx->readState()->summary($viewer,$id);
            // Resolve the destination and fetch that page in one ACL-protected snapshot.
            if($firstUnread) $offset=25*(($state['first_unread']['page']??1)-1);
            $result=['thread'=>$thread,'breadcrumbs'=>$this->breadcrumbs($board,$access),'board'=>$this->decorated($board,$access),'posts'=>$this->posts->inThread($id,$offset,$viewer),'first_post_id'=>$this->posts->firstId($id),'page'=>1+intdiv($offset,25)];
            $result['posts']=$this->tx->attachments()->decorate('post',$result['posts']);
            if($seo)$result['total_posts']=$this->posts->countInThread($id);
            $result['interaction']=$this->polls()->view($id,$viewer);
            if($result['interaction']['kind']==='question') {
                $qa=new \CajeerEngine\Forum\Repository\PollQuestionRepository($this->db);
                $result['answers']=$qa->answers($id,$viewer,0,true);
                $stats=$qa->answerStats(array_column($result['posts'],'id'),$viewer);
                foreach($result['posts'] as &$p) if($p['id']!==$result['first_post_id']) $p['answer_vote']=$stats[$p['id']]??['score'=>0,'voted'=>false];
                unset($p);
                $accepted=$result['interaction']['question']['accepted_post_id'];
                if($accepted!==null) $result['interaction']['question']['accepted_page']=$qa->answerPage($id,$accepted);
            }
            if ($state!==null) {
                $result['read_state']=$state;
                foreach($result['posts'] as &$post) $post['is_unread']=(int)$post['read_sequence']>$result['read_state']['read_sequence'];
                unset($post);
            }
            return $result;
        });
    }
    public function search(string $term,int $offset,?ForumAccessPolicy $actor=null,?string $tag=null,string $mode='threads',array $options=[]): array { return ForumSearchBudget::run($this->db,fn()=> $this->tx->readState()->decorateThreads($this->threads->search($term,$offset,NodePermissionService::allowed($this->access($actor)),$tag,$mode,['viewer'=>$actor?->userId??'']+$options),$actor?->userId??'')); }
    public function latestCursor(?array $cursor,?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function()use($cursor,$actor){
            $viewer=$actor?->userId??'';
            $rows=$this->threads->latestAfter(NodePermissionService::allowed($this->access($actor)),$viewer,$cursor);
            $more=count($rows)>25;$rows=array_slice($rows,0,25);
            $next=$more?\CajeerEngine\Forum\Validation\ForumFeedCursor::encode($rows[count($rows)-1]):null;
            return ['items'=>$this->tx->readState()->decorateThreads($rows,$viewer),'next_cursor'=>$next];
        });
    }
    public function latest(int $offset,?ForumAccessPolicy $actor=null): array { return $this->db->readSnapshot(fn()=> $this->tx->readState()->decorateThreads($this->threads->latest($offset,NodePermissionService::allowed($this->access($actor)),$actor?->userId??''),$actor?->userId??'')); }
    public function activity(): ActivityService { return new ActivityService($this->tx,new \CajeerEngine\Forum\Repository\ActivityRepository($this->db),$this->profiles()); }
    public function profiles(): MemberProfileService { return new MemberProfileService($this->tx,new \CajeerEngine\Forum\Repository\ProfileRepository($this->db)); }
    public function profile(string $id,int $offset,?ForumAccessPolicy $actor=null): array
    {
        return $this->db->readSnapshot(function () use ($id,$offset,$actor) {
            $member=$this->profiles()->publicProfile($id,$actor??new ForumAccessPolicy('',[]));
            $member['reputation']=$this->activity()->summary($member,$actor??new ForumAccessPolicy('',[]));
            return ['member'=>$member,'threads'=>$member['activity_visible']?$this->tx->readState()->decorateThreads($this->threads->byAuthor($id,$offset,NodePermissionService::allowed($this->access($actor))),$actor?->userId??''):[]];
        });
    }
    public function management(): DiscussionManagementService { return new DiscussionManagementService($this->tx,$this->boards,$this->threads,$this->posts,new \CajeerEngine\Forum\Repository\DiscussionManagementRepository($this->db)); }
    public function groups(): GroupService { return $this->tx->groups(); }
    public function permissions(): NodePermissionService { return $this->tx->permissions(); }
    public function createThread(ForumAccessPolicy $actor, string $board, string $title, mixed $body, string $format='plain', mixed $attachments=[], mixed $kind='discussion', mixed $poll=null,bool $reviewed=false): string
    {
        $board = V::id($board); $title = V::text($title,'title',180,3); $body = \CajeerEngine\Forum\Content\ForumContentRenderer::source($body,$format);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$board,$title,$body,$format,$attachments,$kind,$poll,$reviewed) {
            $this->permissions()->assertWrite($actor,$board,'thread:create');
            $this->boards->writable($board);
            $this->tx->settings()->assertPublishing();
            if(!$reviewed&&$this->moderationQueue()->requires($actor,$board))throw new ForumException('Публикация требует предварительной проверки.',409);
            if(!$reviewed&&$this->tx->antiSpam()->publication($actor->userId,'thread',$body,$format,$this->access($actor)['boards'][$board]['thread:moderate']??false,$title)!==null)throw new ForumException('Публикация требует предварительной проверки.',409);
            $id = Uuid::v4(); $post = Uuid::v4();
            $this->threads->insert($id,$board,$actor->userId,$title);
            $this->polls()->initialize($id,$kind,$poll);
            $this->posts->insert($post,$id,$actor->userId,$body,1,$format);
            $this->tx->revisions()->initial('post',$post,$body,$format,$actor->userId);
            $this->tx->attachments()->attach($actor->userId,'post',$post,$attachments);
            $this->tx->notifications()->published($actor->userId,$id,$post,$body,false,$format);
            $this->tx->audit('forum.thread.create',$actor->userId,['thread_id'=>$id,'board_id'=>$board,'post_id'=>$post]);
            return $id;
        });
    }
    public function reply(ForumAccessPolicy $actor, string $id, mixed $body, ?string $format=null, mixed $attachments=[],bool $reviewed=false): string
    {
        $id = V::id($id); $body = \CajeerEngine\Forum\Content\ForumContentRenderer::source($body,$format??'plain');
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$id,$body,$format,$attachments,$reviewed) {
            $thread = $this->threads->lockThread($id) ?? throw new ForumException('Тема не найдена.',404);
            $this->permissions()->assertWrite($actor,$thread['board_id'],'post:reply');
            $this->boards->writable($thread['board_id']);
            $this->tx->settings()->assertPublishing();
            if(!$reviewed&&$this->moderationQueue()->requires($actor,$thread['board_id']))throw new ForumException('Публикация требует предварительной проверки.',409);
            if ((int) $thread['locked'] !== 0) throw new ForumException('Тема закрыта.',409);
            if(!$reviewed&&$this->tx->antiSpam()->publication($actor->userId,'reply',$body,$format??'plain',$this->access($actor)['boards'][$thread['board_id']]['thread:moderate']??false)!==null)throw new ForumException('Публикация требует предварительной проверки.',409);
            $post = Uuid::v4();
            $this->posts->insert($post,$id,$actor->userId,$body,$this->posts->nextOrdinal($id),$format??'plain');
            $this->tx->revisions()->initial('post',$post,$body,$format??'plain',$actor->userId);
            $this->threads->touch($id);
            $this->tx->attachments()->attach($actor->userId,'post',$post,$attachments);
            $this->tx->notifications()->published($actor->userId,$id,$post,$body,true,$format??'plain');
            $this->tx->audit('forum.post.reply',$actor->userId,['thread_id'=>$id,'post_id'=>$post]);
            return $post;
        });
    }
    public function edit(ForumAccessPolicy $actor, string $id, mixed $body, ?string $format=null, mixed $attachments=[], ?int $expectedRevision=null, string $reason='', bool $forceRevision=false): string
    {
        $id = V::id($id);
        $actor->require('forum:write');
        return $this->tx->write(function () use ($actor,$id,$body,$format,$attachments,$expectedRevision,$reason,$forceRevision) {
            $thread = $this->threads->lockForPost($id) ?? throw new ForumException('Сообщение не найдено.',404);
            $post = $this->posts->findForWrite($id) ?? throw new ForumException('Сообщение не найдено.',404);
            $this->permissions()->assertWrite($actor,$thread['board_id'],$post['author_id']===$actor->userId && (int)$thread['locked']===0?'post:edit_own':'post:edit_any');
            $this->boards->writable($thread['board_id']);
            $actor->assertEdit($post,$thread);
            $format=$format??$post['body_format'];$body=\CajeerEngine\Forum\Content\ForumContentRenderer::source($body,$format);
            $this->tx->antiSpam()->publication($actor->userId,'edit',$body,$format,$this->access($actor)['boards'][$thread['board_id']]['thread:moderate']??false);
            $this->tx->revisions()->advance('post',$post,$body,$format,$actor->userId,$expectedRevision,$reason,$forceRevision||$attachments!==[]);
            $this->posts->edit($id,$body,$format);
            $this->tx->attachments()->attach($actor->userId,'post',$id,$attachments);
            $this->tx->audit('forum.post.edit',$actor->userId,['thread_id'=>$thread['id'],'post_id'=>$id]);
            return $id;
        });
    }
    public function nodes(): NodeService { return new NodeService($this->tx,$this->boards); }
    public function category(ForumAccessPolicy $actor, string $name, string $description): string
    {
        return $this->nodes()->createCategory($actor,$name,$description);
    }
    public function createBoard(ForumAccessPolicy $actor, string $category, string $name, string $description): string
    {
        return $this->nodes()->createBoard($actor,$category,$name,$description);
    }
}
