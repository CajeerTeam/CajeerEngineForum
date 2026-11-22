<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\Service\AttachmentService;
use CajeerEngine\Forum\ForumException;
use PDO;

/** Operator-only, bounded transactional import. Mapping and checkpoint commit with data. */
final class ForumMigrationEngine
{
    public function __construct(private DatabaseManager $db, private ForumSource $source, private ?string $files=null, ?string $avatarFiles=null,int $newPipelineVersion=5)
    {
        if($avatarFiles!==null && (!is_dir($avatarFiles)||($root=realpath($avatarFiles))===false))throw new \InvalidArgumentException('XenForo avatar snapshot directory not found');
        $this->avatarFiles=$avatarFiles===null?null:$root;
        if(!in_array($newPipelineVersion,$source instanceof InvisionSource?[2,3,4,5,6,7,8]:[2,3,4,5],true))throw new \InvalidArgumentException('Invalid new job pipeline version');
        $this->newPipelineVersion=$source instanceof InvisionSource?($newPipelineVersion===6?6:($newPipelineVersion===7?7:8)):($source instanceof MultiForumSource && $source->platform()==='xenforo'?$newPipelineVersion:min(3,$newPipelineVersion));
    }
    private array $mappingCache=[];
    private array $statements=[];
    private bool $contentPipeline=false;
    private bool $communityPipeline=false;
    private ?string $avatarFiles=null;
    private int $newPipelineVersion;
    private function query(string $sql,array $params=[]): \PDOStatement
    {
        $s=$this->statements[$sql]??=$this->db->connection()->prepare($sql);
        $s->closeCursor();$s->execute($params);return $s;
    }
    /** Only cache statements and mappings within a single batch/connection. */
    private function resetBatch(): void {$this->statements=[];$this->mappingCache=[];}
    public static function assertNoRunning(DatabaseManager $db): void
    {
        if($db->tableExists('ce_forum_import_jobs') && $db->connection()->query("SELECT id FROM ce_forum_import_jobs WHERE state<>'complete' LIMIT 1")->fetchColumn()!==false)
            throw new \RuntimeException('An incomplete forum import exists; resume it or restore its verified backup');
    }
    private function row(string $sql,array $params=[]): ?array {$s=$this->query($sql,$params);$r=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();return $r===false?null:$r;}
    private static function uuid(): string {$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));}
    private const EXTRA_PHASES=['profiles','reactions'];
    private const COMMUNITY_PHASES=['avatars','follows'];
    private const CONTENT_PHASE='content_links';
    private const MEDIA_PHASES=['conversations','conversation_messages','conversation_attachments','conversation_content'];
    private const INVISION_PHASES=['attachments','polls','invision_content'];
    private const INVISION_COMMUNITY_PHASES=['inv_profiles','inv_reactions','inv_follow','inv_conversations','inv_messages','inv_pm_content'];
    /** Existing jobs have no pipeline_version and always finish with the original eleven phases. */
    private function phaseNames(array $plan): array
    {
        if(!isset($plan['pipeline_version']))return ForumSource::PHASES;
        if(in_array($plan['pipeline_version'],[6,7,8],true)){
            if(!($this->source instanceof InvisionSource))throw new \RuntimeException('Invision pipeline requires Invision source');
            foreach(InvisionSource::CORE_PHASES as $kind)if(!isset($plan[$kind]['count'],$plan[$kind]['high']))throw new \RuntimeException('Incomplete Invision phase ledger');
            if($plan['pipeline_version']>=7){
                foreach(self::INVISION_PHASES as $kind)if(!isset($plan[$kind]['count'],$plan[$kind]['high']))
                    throw new \RuntimeException('Incomplete Invision v7 content ledger');
                if($plan['invision_content']!==$plan['posts'])throw new \RuntimeException('Invision content bounds mismatch');
                if($plan['pipeline_version']===8){
                    foreach(self::INVISION_COMMUNITY_PHASES as $kind)
                        if(!isset($plan[$kind]['count'],$plan[$kind]['high']))throw new \RuntimeException('Incomplete Invision v8 community ledger');
                    if($plan['inv_profiles']!==$plan['users']||$plan['inv_pm_content']!==$plan['inv_messages']
                        ||!isset($plan['source_community_schema_sha256']))throw new \RuntimeException('Invalid Invision v8 community ledger');
                    return array_merge(InvisionSource::CORE_PHASES,self::INVISION_PHASES,self::INVISION_COMMUNITY_PHASES);
                }
                return array_merge(InvisionSource::CORE_PHASES,self::INVISION_PHASES);
            }
            return InvisionSource::CORE_PHASES;
        }
        if(!in_array($plan['pipeline_version'],[2,3,4,5],true) || !($this->source instanceof EnrichedForumSource))
            throw new \RuntimeException('Unsupported import pipeline; do not rewrite an in-progress job');
        $phases=array_merge(ForumSource::PHASES,self::EXTRA_PHASES);
        if($plan['pipeline_version']>=4){
            if(!($this->source instanceof MultiForumSource)||$this->source->platform()!=='xenforo')
                throw new \RuntimeException('Community phases require a XenForo source');
            foreach(self::COMMUNITY_PHASES as $community){
                if(!isset($plan[$community]['count'],$plan[$community]['high']))throw new \RuntimeException('Incomplete community phase ledger');
                $phases[]=$community;
            }
        }
        if($plan['pipeline_version']>=3){
            if($this->source->platform()!=='xenforo'||!isset($plan[self::CONTENT_PHASE])||$plan[self::CONTENT_PHASE]!==$plan['posts'])
                throw new \RuntimeException('Invalid XenForo content phase ledger');
            $phases[]=self::CONTENT_PHASE;
        }
        if($plan['pipeline_version']===5){
            if(!($this->source instanceof MultiForumSource)||$this->source->platform()!=='xenforo')throw new \RuntimeException('Private media phases require XenForo');
            foreach(self::MEDIA_PHASES as $kind){
                if(!isset($plan[$kind]['count'],$plan[$kind]['high']))throw new \RuntimeException('Incomplete XenForo media ledger');
                $phases[]=$kind;
            }
            if(!isset($plan['source_media_schema_sha256']))throw new \RuntimeException('Missing XenForo media fingerprint');
        }
        return $phases;
    }
    /** Exact snapshot ledger including added phase high-water marks for new jobs. */
    public function snapshotPlan(bool $extended=true,?int $pipelineVersion=null): array
    {
        $pipelineVersion??=$this->newPipelineVersion;
        if($this->source instanceof InvisionSource){
            if(!in_array($pipelineVersion,[6,7,8],true))throw new \RuntimeException('Invalid Invision pipeline version');
            return $this->source->plan($pipelineVersion);
        }
        $plan=$this->source->plan();
        if($extended && $this->source instanceof EnrichedForumSource){
            $extra=$this->source->additionalPlan();
            if($extra!==[]){
                if(array_keys($extra)!==self::EXTRA_PHASES)throw new \RuntimeException('Unsupported additional source phases');
                if(!in_array($pipelineVersion,[2,3,4,5],true))throw new \InvalidArgumentException('Invalid pipeline version');
                $plan['pipeline_version']=$pipelineVersion;
                foreach($extra as $kind=>$bounds)$plan[$kind]=$bounds;
                if($pipelineVersion>=4){
                    if(!($this->source instanceof MultiForumSource)||$this->source->platform()!=='xenforo')
                        throw new \RuntimeException('Community phases require XenForo');
                    $plan['source_community_schema_sha256']=$this->source->communityFingerprint();
                    foreach($this->source->communityPlan() as $kind=>$bounds)$plan[$kind]=$bounds;
                }
                if($pipelineVersion>=3 && $this->source->platform()==='xenforo')
                    $plan[self::CONTENT_PHASE]=$plan['posts'];
                if($pipelineVersion===5){
                    if(!($this->source instanceof MultiForumSource)||$this->source->platform()!=='xenforo')throw new \RuntimeException('Media pipeline requires XenForo');
                    $plan['source_media_schema_sha256']=$this->source->mediaFingerprint();
                    foreach($this->source->mediaPlan() as $kind=>$bounds)$plan[$kind]=$bounds;
                }
            }
        }
        return $plan;
    }
    public function matchesRecordedPlan(array $recorded): bool
    {
        $current=$this->snapshotPlan(isset($recorded['pipeline_version']),(int)($recorded['pipeline_version']??3));
        // 1.1.1 jobs were created before XenForo schema fingerprints existed.
        // Preserve their exact eleven/thirteen-phase ledger on resume; new jobs
        // verify the full recorded schema, including the 2.2/2.3 key choice.
        foreach(['source_schema_sha256','source_attachment_key'] as $key){
            if(!array_key_exists($key,$recorded))unset($current[$key]);
        }
        return $current===$recorded;
    }
    public function plan(): array
    {
        $plan=$this->snapshotPlan();
        return ['ok'=>true,'job'=>$this->source->identity(),'platform'=>$this->source->platform(),'version'=>$this->source->version(),
            'pipeline_version'=>$plan['pipeline_version']??1,'counts'=>array_diff_key($plan,['pipeline_version'=>true]),
            'policy'=>'hidden_readonly_until_manual_acl_review','unsupported'=>$this->source->unsupported()];
    }
    public function exists(): bool {return $this->row('SELECT id FROM ce_forum_import_jobs WHERE id=?',[$this->source->identity()])!==null;}
    public function start(string $backup): array
    {
        $this->resetBatch();$plan=$this->snapshotPlan();$id=$this->source->identity();
        if(($plan['pipeline_version']??1)>=4 && ($plan['avatars']['count']??0)>0 && $this->avatarFiles===null)
            throw new \RuntimeException('XenForo source has avatars: supply --avatar-files as an immutable data/avatars snapshot');
        if(($plan['pipeline_version']??1)===5 && (($plan['attachments']['count']??0)>0 || ($plan['conversation_attachments']['count']??0)>0) && $this->files===null)
            throw new \RuntimeException('XenForo attachments require --files immutable internal_data/attachments snapshot');
        if(($plan['pipeline_version']??0)===7 && ($plan['attachments']['count']??0)>0 && $this->files===null)
            throw new \RuntimeException('Invision attachments require --files immutable uploads snapshot');
        try{$this->db->transaction(function()use($plan,$id,$backup){
            // The filesystem lease only covers one node. Serialize start decisions in SQL too.
            if($this->db->driver()==='sqlite')$this->query('UPDATE ce_forum_tree_lock SET id=id WHERE id=1');
            $lock=$this->row('SELECT id FROM ce_forum_tree_lock WHERE id=1'.($this->db->driver()==='sqlite'?'':' FOR UPDATE'));
            if($lock===null)throw new \RuntimeException('Forum tree lock missing');
            if($this->exists())throw new \RuntimeException('Import already exists; resume it');
            self::assertNoRunning($this->db);
            $category=self::uuid();
            $this->query("INSERT INTO ce_forum_categories(id,name,description,position,visibility) VALUES(?,?,?,0,'hidden')",[$category,$this->source->platform().' import '.substr($id,0,8),'Imported archive: review permissions before opening.']);
            $this->query('INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$id,$this->source->version(),$category,$backup,json_encode($plan,JSON_THROW_ON_ERROR),time(),time()]);
            (new AuditLogRepository($this->db))->record('forum.import.start',null,['job'=>$id,'backup'=>$backup,'category'=>$category]);
        });}finally{$this->resetBatch();}return $this->status();
    }
    public function status(): array {return self::report($this->db,$this->source->identity());}
    public function progress(): array
    {
        $job=$this->row('SELECT * FROM ce_forum_import_jobs WHERE id=?',[$this->source->identity()])??throw new \RuntimeException('Import job not found');
        $this->resetBatch();$job['plan']=json_decode($job['plan_json'],true,512,JSON_THROW_ON_ERROR);unset($job['plan_json']);
        return ['ok'=>true,'job'=>$job];
    }
    /** Validate the ledger while the imported tree is still quarantined. */
    private function verifyMappings(string $job): void
    {
        foreach(['groups'=>['ce_forum_groups','role_id'],'users'=>['ce_users','id'],'forums'=>['ce_forum_boards','id'],'topics'=>['ce_forum_threads','id'],'posts'=>['ce_forum_posts','id'],'attachments'=>['ce_forum_attachments','id'],'polls'=>['ce_forum_polls','thread_id'],'profiles'=>['ce_forum_member_profiles','user_id'],'reactions'=>['ce_forum_posts','id'],'avatars'=>['ce_forum_profile_images','user_id'],'follows'=>['ce_users','id'],'content_links'=>['ce_forum_posts','id'],'invision_content'=>['ce_forum_posts','id'],'inv_profiles'=>['ce_forum_member_profiles','user_id'],
            'inv_reactions'=>['ce_forum_posts','id'],'inv_follow'=>['ce_users','id'],
            'inv_conversations'=>['ce_forum_conversations','id'],
            'inv_messages'=>['ce_forum_conversation_messages','id'],'inv_pm_content'=>['ce_forum_conversation_messages','id'],'conversations'=>['ce_forum_conversations','id'],'conversation_messages'=>['ce_forum_conversation_messages','id'],'conversation_attachments'=>['ce_forum_attachments','id'],'conversation_content'=>['ce_forum_conversation_messages','id']] as $kind=>[$table,$column]){
            // PostgreSQL uses UUID PKs; cast the small ledger key, not the indexed target column.
            $key=$this->db->driver()==='pgsql'?'CAST(m.target_id AS UUID)':'m.target_id';
            $n=(int)$this->query("SELECT COUNT(*) FROM ce_forum_import_map m LEFT JOIN $table t ON t.$column=$key WHERE m.job_id=? AND m.kind=? AND m.outcome='imported' AND t.$column IS NULL",[$job,$kind])->fetchColumn();
            if($n!==0)throw new \RuntimeException('Imported target missing: '.$kind);
            if($kind==='avatars'){
                $missing=(int)$this->query("SELECT COUNT(*) FROM ce_forum_import_map m LEFT JOIN ce_forum_profile_images i ON i.user_id=".$key." AND i.kind='avatar' WHERE m.job_id=? AND m.kind='avatars' AND m.outcome='imported' AND i.user_id IS NULL",[$job])->fetchColumn();
                if($missing>0)throw new \RuntimeException('Imported avatar missing');
            }
        }
    }
    public static function report(DatabaseManager $db,string $id): array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$id))throw new \InvalidArgumentException('Invalid import job ID');
        $s=$db->connection()->prepare('SELECT * FROM ce_forum_import_jobs WHERE id=?');$s->execute([$id]);$job=$s->fetch(PDO::FETCH_ASSOC);if(!$job)throw new \RuntimeException('Import job not found');
        $s=$db->connection()->prepare('SELECT kind,outcome,reason,COUNT(*) AS count FROM ce_forum_import_map WHERE job_id=? GROUP BY kind,outcome,reason ORDER BY kind,outcome,reason');$s->execute([$id]);
        $job['plan']=json_decode($job['plan_json'],true,512,JSON_THROW_ON_ERROR);unset($job['plan_json']);$job['platform']=$job['plan']['source_platform']??'phpbb';
        $results=$s->fetchAll(PDO::FETCH_ASSOC);$relations=[];
        foreach(['watches'=>['ce_forum_watches','thread_id','topics'],'board_watches'=>['ce_forum_board_watches','board_id','forums'],'votes'=>['ce_forum_poll_votes','thread_id','polls']] as $name=>[$table,$column,$kind]){
            $q=$db->connection()->prepare("SELECT COUNT(*) FROM $table t JOIN ce_forum_import_map m ON t.$column=".($db->driver()==='pgsql'?"CAST(m.target_id AS UUID)":"m.target_id")." WHERE m.job_id=? AND m.kind=? AND m.outcome='imported'");$q->execute([$id,$kind]);$relations[$name]=(int)$q->fetchColumn();
        }
        return ['ok'=>true,'job'=>$job,'results'=>$results,'relation_rows'=>$relations];
    }
    public static function mapping(DatabaseManager $db,string $job,string $kind,int $id): array
    {
        $s=$db->connection()->prepare('SELECT kind,source_id,target_id,outcome,reason FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=?');$s->execute([$job,$kind,$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return ['ok'=>$r!==false,'mapping'=>$r===false?null:$r];
    }
    private function mapped(string $kind,int $id): ?string
    {
        if(count($this->mappingCache)>=4096)$this->mappingCache=[];
        $key=$kind.':'.$id;
        if(!array_key_exists($key,$this->mappingCache))$this->mappingCache[$key]=$this->row("SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=? AND outcome='imported'",[$this->source->identity(),$kind,$id])['target_id']??null;
        return $this->mappingCache[$key];
    }
    private function mark(string $kind,int $id,?string $target,string $reason=''): void
    {$this->query('INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,?,?,?)',[$this->source->identity(),$kind,$id,$target,$target===null?'skipped':'imported',$reason]);$this->mappingCache[$kind.':'.$id]=$target;}
    /** Run a single bounded batch. Stop safely between invocations; no offset scanning. */
    public function step(int $limit=100): array
    {
        if($limit<1||$limit>500)throw new \InvalidArgumentException('Batch must be 1..500');
        $id=$this->source->identity();
        $this->resetBatch();
        try{$this->db->transaction(function()use($id,$limit){
            if($this->db->driver()==='sqlite')$this->query('UPDATE ce_forum_import_jobs SET updated_at=updated_at WHERE id=?',[$id]);
            $job=$this->row('SELECT * FROM ce_forum_import_jobs WHERE id=?'.($this->db->driver()==='sqlite'?'':' FOR UPDATE'),[$id]);
            if(!$job)throw new \RuntimeException('Start the import first');if($job['state']==='complete')return;
            $plan=json_decode($job['plan_json'],true,512,JSON_THROW_ON_ERROR);
            $phases=$this->phaseNames($plan);
            $this->contentPipeline=($plan['pipeline_version']??0)>=3 && $this->source->platform()==='xenforo';
            $this->communityPipeline=($plan['pipeline_version']??0)>=4 && $this->source->platform()==='xenforo';
            if($this->communityPipeline && ($plan['avatars']['count']??0)>0 && $this->avatarFiles===null)
                throw new \RuntimeException('XenForo avatar snapshot directory required to resume this import');
            $phase=(int)$job['phase'];$kind=$phases[$phase]??throw new \RuntimeException('Invalid import phase checkpoint');$cursor=(int)$job['cursor_id'];$sub=(int)$job['subcursor_id'];
            $category=$this->row('SELECT visibility FROM ce_forum_categories WHERE id=?',[$job['category_id']]);
            if(!$category||$category['visibility']!=='hidden'||$this->row('SELECT id FROM ce_forum_boards WHERE category_id=? AND (visibility<>? OR state<>?) LIMIT 1',[$job['category_id'],'hidden','readonly']))throw new \RuntimeException('Imported tree must remain hidden and readonly until completion');
            $isMedia=in_array($kind,self::MEDIA_PHASES,true);
            $isInvisionContent=($plan['pipeline_version']??0)>=7 && in_array($kind,self::INVISION_PHASES,true);
            $isInvisionCommunity=($plan['pipeline_version']??0)===8 && in_array($kind,self::INVISION_COMMUNITY_PHASES,true);
            $isAdditional=in_array($kind,self::EXTRA_PHASES,true);
            $isCommunity=in_array($kind,self::COMMUNITY_PHASES,true);
            if($isInvisionContent){
                if(!($this->source instanceof InvisionSource))throw new \RuntimeException('Invision media requires Invision source');
                $batch=$this->source->contentBatch($kind,$cursor,$plan[$kind]['high'],$kind==='attachments'?1:min(20,$limit));
            }elseif($isInvisionCommunity){
                if(!($this->source instanceof InvisionSource))throw new \RuntimeException('Invision community phase requires source');
                $batch=$this->source->communityBatch($kind,$cursor,$plan[$kind]['high'],min($limit,in_array($kind,['inv_conversations','inv_pm_content'],true)?20:100));
            }elseif($isMedia){
                if(!($this->source instanceof MultiForumSource)||$this->source->platform()!=='xenforo')throw new \RuntimeException('XenForo media phase requires source');
                $batch=$this->source->mediaBatch($kind,$cursor,$plan[$kind]['high'],in_array($kind,['conversation_attachments','conversation_content'],true)?min($limit,$kind==='conversation_attachments'?1:20):$limit);
            }elseif($kind===self::CONTENT_PHASE){
                $batch=$this->source->batch('posts',$cursor,$plan[self::CONTENT_PHASE]['high'],min($limit,20));
            }elseif($isCommunity){
                if(!($this->source instanceof MultiForumSource))throw new \RuntimeException('Community phase requires XenForo source');
                $batch=$this->source->communityBatch($kind,$cursor,$plan[$kind]['high'],$kind==='avatars'?min(5,$limit):$limit);
            }elseif($isAdditional){
                if(!($this->source instanceof EnrichedForumSource))throw new \RuntimeException('Source does not support additional import phases');
                $batch=$this->source->additionalBatch($kind,$cursor,$plan[$kind]['high'],$limit);
            }else $batch=$this->source->batch($kind,$cursor,$plan[$kind]['high'],$kind==='attachments'?1:($kind==='posts'?min($limit,20):$limit));
            if($batch===[]){
                if($phase+1===count($phases)){
                    if(!$this->matchesRecordedPlan($plan))throw new \RuntimeException('Source snapshot changed; restore backup and restart with an immutable snapshot');
                    foreach(array_filter($phases,static fn(string $k):bool=>$k!=='finalize') as $covered){
                        $n=(int)$this->query('SELECT COUNT(*) FROM ce_forum_import_map WHERE job_id=? AND kind=?',[$id,$covered])->fetchColumn();
                        if($n!==$plan[$covered]['count'])throw new \RuntimeException('Source row count mismatch: '.$covered);
                    }
                    $this->verifyMappings($id);
                    (new \CajeerEngine\Forum\Service\ForumThemeService($this->db))->snapshot();
                    if(!(new \CajeerEngine\Forum\Service\ForumSearchMaintenance($this->db))->check()['ok'])throw new \RuntimeException('Forum search index check failed; repair before completing import');
                    if(!(new \CajeerEngine\Forum\ForumIntegrityCheck($this->db))->check()['ok'])throw new \RuntimeException('Forum integrity check failed; inspect target before completing import');
                    $this->query("UPDATE ce_forum_import_jobs SET phase=?,state='complete',updated_at=? WHERE id=?",[$phase+1,time(),$id]);
                    (new AuditLogRepository($this->db))->record('forum.import.complete',null,['job'=>$id]);return;
                }
                $phase++;$cursor=0;$sub=0;
            }else {
              // Bound total relation work, not just the number of topics in a batch.
              $remaining=$limit;
              foreach($batch as $r){
                $key=match($kind){'groups'=>'group_id','users'=>'user_id','forums'=>'left_id','topics','polls','votes','watches','finalize'=>'topic_id','board_watches'=>'forum_id','posts'=>'post_id','attachments'=>'attach_id','profiles'=>'profile_id','reactions'=>'reaction_source_id','avatars'=>'avatar_id','follows'=>'follow_id','content_links','invision_content'=>'post_id','conversations'=>'conversation_source_id','conversation_messages','conversation_content'=>'message_source_id','conversation_attachments'=>'attach_id','inv_profiles'=>'member_id','inv_reactions'=>'id','inv_follow'=>'follow_id','inv_conversations'=>'mt_id','inv_messages','inv_pm_content'=>'msg_id'};
                $next=(int)$r[$isInvisionContent && $kind==='polls'?'poll_id':$key];
                if(in_array($kind,['watches','board_watches','votes','follows'],true)){
                    $target=$this->mapped(match($kind){'watches'=>'topics','board_watches'=>'forums','votes'=>'polls','follows'=>'users'},$next);
                    if($target!==null){
                        $watchers=$kind==='follows'
                            ?($this->source instanceof MultiForumSource?$this->source->communityFollowing($next,$sub,$remaining):[])
                            :$this->source->watchers($kind,$next,$sub,$remaining);
                        $optionMap=[];$maxChoices=0;
                        if($kind==='votes'){
                            foreach($this->source->options($next) as $i=>$o)$optionMap[(int)$o['poll_option_id']]=$i+1;
                            $maxChoices=(int)$this->row('SELECT max_choices FROM ce_forum_polls WHERE thread_id=?',[$target])['max_choices'];
                        }
                        foreach($watchers as $uid){
                            $sub=(int)$uid;$user=$this->mapped('users',$sub);if($user===null)continue;
                            if($kind==='follows'){
                                if($user!==$target && !$this->row("SELECT user_id FROM ce_forum_member_relations WHERE user_id=? AND target_id=? AND kind='follow'",[$target,$user]))
                                    $this->query("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",[$target,$user]);
                            }elseif($kind==='votes'){
                                $ballot=$this->source->ballot($next,$sub);
                                if(count($ballot)>$maxChoices||array_diff(array_map('intval',$ballot),array_keys($optionMap)))continue;
                                foreach($ballot as $o)$this->query('INSERT INTO ce_forum_poll_votes(thread_id,user_id,option_no) VALUES(?,?,?)',[$target,$user,$optionMap[(int)$o]]);
                            }elseif($kind==='board_watches')$this->query("INSERT INTO ce_forum_board_watches(board_id,user_id,mode) VALUES(?,?,'internal')",[$target,$user]);
                            else $this->query("INSERT INTO ce_forum_watches(thread_id,user_id,mode) VALUES(?,?,'internal')",[$target,$user]);
                        }
                        $remaining-=count($watchers);
                        if($remaining===0)break; // retain previous object cursor and last processed user
                        $remaining--; // charge even an empty object

                    }
                    $this->mark($kind,$next,$target,$target===null?'parent_not_imported':'');$sub=0;
                }elseif($kind==='finalize'){
                    $thread=$this->mapped('topics',$next);
                    if($thread!==null&&!$this->row('SELECT id FROM ce_forum_posts WHERE thread_id=? LIMIT 1',[$thread])){
                        $this->query('DELETE FROM ce_forum_threads WHERE id=?',[$thread]);
                        $this->query("UPDATE ce_forum_import_map SET target_id=NULL,outcome='skipped',reason='no_importable_posts' WHERE job_id=? AND kind='topics' AND source_id=?",[$id,$next]);
                    }
                }elseif($isInvisionContent){
                    match($kind){
                        'attachments'=>$this->importInvisionAttachment($r),
                        'polls'=>$this->importInvisionPoll($r),
                        'invision_content'=>$this->rewriteInvisionPost($r),
                    };
                }elseif($isInvisionCommunity){
                    match($kind){
                        'inv_profiles'=>$this->importInvisionProfile($r),
                        'inv_reactions'=>$this->importInvisionReaction($r),
                        'inv_follow'=>$this->importInvisionFollow($r),
                        'inv_conversations'=>$this->importInvisionConversation($r),
                        'inv_messages'=>$this->importInvisionMessage($r),
                        'inv_pm_content'=>$this->rewriteInvisionConversationMessage($r),
                    };
                }elseif($isMedia){
                    match($kind){
                        'conversations'=>$this->importConversation($r),
                        'conversation_messages'=>$this->importConversationMessage($r),
                        'conversation_attachments'=>$this->importConversationAttachment($r),
                        'conversation_content'=>$this->rewriteConversationMessage($r),
                    };
                }elseif($kind===self::CONTENT_PHASE){
                    $this->rewriteXenForoPost($r);
                }elseif($kind==='avatars'){
                    $this->importAvatar($r);
                }elseif($isAdditional){
                    $this->importAdditional($kind,$r);
                }else $this->import($kind,$r,$job['category_id']);
                $cursor=$next;
                if(in_array($kind,['watches','board_watches','votes','follows'],true)&&$remaining<=0)break;
              }
            }
            $this->query('UPDATE ce_forum_import_jobs SET phase=?,cursor_id=?,subcursor_id=?,updated_at=? WHERE id=?',[$phase,$cursor,$sub,time(),$id]);
        });}finally{$this->resetBatch();}
        $result=['ok'=>true,'job'=>$this->row('SELECT id,state,phase,cursor_id,subcursor_id FROM ce_forum_import_jobs WHERE id=?',[$id])];$this->resetBatch();return $result;
    }
    /** Final pass resolves forward references after every imported post and attachment has an ID.
     * Each rewritten post, its baseline revision, ledger row and checkpoint commit together.
     */
    /** Append-only v7 pass after all topic, post and attachment mappings exist. */
    /** All v8 target writes share the engine's existing SQL transaction and ledger. */
    private function importInvisionProfile(array $row):void
    {
        $source=(int)$row['member_id'];$user=$this->mapped('users',$source);
        if($user===null){$this->mark('inv_profiles',$source,null,'member_not_imported');return;}
        $fields=['bio'=>['pp_about_me','about_me'], 'location'=>['location'],
            'website'=>['website','homepage'],'signature'=>['signature']];
        $values=[];
        foreach($fields as $field=>$names){
            $raw='';foreach($names as $name)if(array_key_exists($name,$row)){$raw=(string)($row[$name]??'');break;}
            $value=in_array($field,['bio','signature'],true)?InvisionContent::plain($raw):trim(strip_tags(str_replace("\0",'',html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'))));
            $limit=match($field){'bio'=>2000,'location'=>120,'website'=>500,'signature'=>500};
            if($value===null||!preg_match('//u',$value)||strlen($value)>$limit*4||mb_strlen($value,'UTF-8')>$limit){
                $this->mark('inv_profiles',$source,null,'invalid_or_oversized_profile');return;
            }
            $values[$field]=$value;
        }
        $url=$values['website'];
        if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true)
            ||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)){
            $this->mark('inv_profiles',$source,null,'unsafe_profile_website');return;
        }
        if(implode('',$values)===''){$this->mark('inv_profiles',$source,null,'no_supported_profile_fields');return;}
        // Existing user preferences are private; never publish an imported profile.
        $this->query('INSERT INTO ce_forum_member_profiles(user_id,bio,location,website,signature) VALUES(?,?,?,?,?)',
            [$user,$values['bio'],$values['location'],$values['website'],$values['signature']]);
        $this->mark('inv_profiles',$source,$user);
    }
    private function importInvisionReaction(array $row):void
    {
        $source=(int)$row['id'];$app=strtolower((string)($row['app']??''));$kind=strtolower((string)($row['type']??''));
        // IPS reputation may refer to any app's content, never guess its target.
        if(($app!==''&&$app!=='forums')||!in_array($kind,['pid','post','forums_post'],true)
            ||(int)$row['rep_rating']<=0){$this->mark('inv_reactions',$source,null,'unsupported_reputation_content');return;}
        // A custom reaction ID is not necessarily a Like; never change its meaning.
        if(isset($row['reaction']) && !in_array((int)$row['reaction'],[0,1],true)){
            $this->mark('inv_reactions',$source,null,'unmapped_custom_reaction');return;
        }
        $post=$this->mapped('posts',(int)$row['type_id']);$user=$this->mapped('users',(int)$row['member_id']);
        if($post===null||$user===null){$this->mark('inv_reactions',$source,null,'post_or_member_not_imported');return;}
        if((string)($this->row('SELECT author_id FROM ce_forum_posts WHERE id=?',[$post])['author_id']??'')===$user){
            $this->mark('inv_reactions',$source,null,'self_reaction');return;
        }
        if($this->row('SELECT user_id FROM ce_forum_reactions WHERE post_id=? AND user_id=?',[$post,$user])){
            $this->mark('inv_reactions',$source,null,'duplicate_reaction');return;
        }
        $this->query("INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,'like')",[$post,$user]);
        $this->mark('inv_reactions',$source,$post);
    }
    private function importInvisionFollow(array $row):void
    {
        $source=(int)$row['follow_id'];$app=strtolower((string)$row['follow_app']);$area=strtolower((string)$row['follow_area']);
        if((int)($row['follow_is_anon']??0)!==0){$this->mark('inv_follow',$source,null,'anonymous_follow_not_exposed');return;}
        $user=$this->mapped('users',(int)$row['follow_member_id']);$old=(int)$row['follow_rel_id'];
        $relation=match($app.':'.$area){
            'forums:topic'=>['topics','thread'],'forums:forum'=>['forums','board'],
            'core:member'=>['users','member'],default=>null};
        if($user===null||$relation===null){$this->mark('inv_follow',$source,null,'unsupported_or_missing_follower');return;}
        $target=$this->mapped($relation[0],$old);
        if($target===null){$this->mark('inv_follow',$source,null,'follow_target_not_imported');return;}
        if($relation[1]==='member'){
            if($target===$user){$this->mark('inv_follow',$source,null,'self_follow');return;}
            $exists=$this->row("SELECT user_id FROM ce_forum_member_relations WHERE user_id=? AND target_id=? AND kind='follow'",[$user,$target]);
            if(!$exists)$this->query("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",[$user,$target]);
        }else{
            $table=$relation[1]==='thread'?'ce_forum_watches':'ce_forum_board_watches';
            $column=$relation[1]==='thread'?'thread_id':'board_id';
            if(!$this->row("SELECT user_id FROM $table WHERE user_id=? AND $column=?",[$user,$target]))
                $this->query("INSERT INTO $table($column,user_id,mode) VALUES(?,?,'internal')",[$target,$user]);
        }
        $this->mark('inv_follow',$source,$user);
    }
    private function importInvisionConversation(array $row):void
    {
        $source=(int)$row['mt_id'];
        if((int)($row['mt_is_draft']??0)!==0||(int)($row['mt_is_deleted']??0)!==0){
            $this->mark('inv_conversations',$source,null,'draft_or_deleted_conversation');return;
        }
        if(!($this->source instanceof InvisionSource))throw new \LogicException('Expected Invision snapshot');
        $recipients=$this->source->communityRecipients($source);
        if(count($recipients)<1||count($recipients)>20){$this->mark('inv_conversations',$source,null,'recipient_count_invalid');return;}
        $members=[];
        foreach($recipients as $entry){
            // A former/deleted/banned recipient must never be reinstated.
            if((int)$entry['map_user_active']!==1||(int)$entry['map_left_time']>0
                ||(int)($entry['map_user_banned']??0)!==0){
                // Do not expose a partial historical audience as a different chat.
                $this->mark('inv_conversations',$source,null,'inactive_or_departed_recipient');return;
            }
            $member=$this->mapped('users',(int)$entry['map_user_id']);
            if($member===null||isset($members[$member])){
                $this->mark('inv_conversations',$source,null,'unmapped_or_duplicate_active_recipient');return;
            }
            $members[$member]=true;
        }
        if($members===[]){$this->mark('inv_conversations',$source,null,'no_active_recipients');return;}
        if(!$this->source->communityHasMessages($source)){$this->mark('inv_conversations',$source,null,'no_messages');return;}
        $starter=$this->mapped('users',(int)$row['mt_starter_id']);
        $owner=$starter!==null&&isset($members[$starter])?$starter:array_key_first($members);
        $raw=html_entity_decode((string)$row['mt_title'],ENT_QUOTES|ENT_HTML5,'UTF-8');
        $title=trim(strip_tags(str_replace("\0",'',$raw)));
        if($title===''||!preg_match('//u',$title)||mb_strlen($title,'UTF-8')>180){
            $this->mark('inv_conversations',$source,null,'invalid_conversation_title');return;
        }
        $date=max(0,(int)($row['mt_date']??$row['mt_start_time']??$row['mt_time']??0));
        $id=self::uuid();$kind=count($members)>2?'group':'direct';
        $this->query('INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at) VALUES(?,?,?,?,?,?)',[$id,$owner,$title,$kind,$date,$date]);
        foreach(array_keys($members) as $member)
            $this->query('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)',[$id,$member]);
        $this->mark('inv_conversations',$source,$id);
    }
    private function importInvisionMessage(array $row):void
    {
        $source=(int)$row['msg_id'];
        if((int)($row['msg_is_deleted']??$row['msg_deleted']??0)!==0){
            $this->mark('inv_messages',$source,null,'deleted_private_message');return;
        }
        $conversation=$this->mapped('inv_conversations',(int)$row['msg_topic_id']);
        if($conversation===null){$this->mark('inv_messages',$source,null,'conversation_not_imported');return;}
        $author=$this->mapped('users',(int)$row['msg_author_id']);
        // Never create an accessible private message by a former/non-member author.
        if($author===null||!$this->row('SELECT user_id FROM ce_forum_conversation_participants WHERE conversation_id=? AND user_id=? AND active=1',[$conversation,$author])){
            $this->mark('inv_messages',$source,null,'author_not_active_participant');return;
        }
        $body=InvisionContent::plain((string)$row['msg_post']);
        if($body===null||!preg_match('//u',$body)||strlen($body)>80000||preg_match_all('/./us',$body)>20000){
            $this->mark('inv_messages',$source,null,'invalid_or_oversized_private_message');return;
        }
        if(trim($body)==='')$body='[Empty imported message]';
        $previous=$this->row('SELECT last_sequence,updated_at FROM ce_forum_conversations WHERE id=?',[$conversation]);
        if($previous===null)throw new \RuntimeException('Imported private conversation not found');
        $sequence=(int)$previous['last_sequence']+1;
        $when=max(0,(int)($row['msg_date']??$row['msg_timestamp']??$row['msg_time']??0));
        $id=self::uuid();
        $this->query("INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,body_format,created_at) VALUES(?,?,?,?,?,'plain',?)",[$id,$conversation,$author,$sequence,$body,$when]);
        $this->query("INSERT INTO ce_forum_conversation_revisions(message_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'Invision import',?)",[$id,$body,$author,$when]);
        $this->query('UPDATE ce_forum_conversations SET last_sequence=?,updated_at=? WHERE id=?',[$sequence,max($when,(int)$previous['updated_at']),$conversation]);
        $this->mark('inv_messages',$source,$id);
    }
    private function rewriteInvisionConversationMessage(array $row):void
    {
        $source=(int)$row['msg_id'];$id=$this->mapped('inv_messages',$source);
        if($id===null){$this->mark('inv_pm_content',$source,null,'message_not_imported');return;}
        // Private messages do not resolve source attachments into public download URLs.
        // Only mapped user mentions may become links; unsupported links are sanitized.
        $resolver=fn(string $kind,int $old):?string=>$kind==='users'&&($user=$this->mapped('users',$old))!==null
            ?'/forum/members/'.$user:null;
        $body=InvisionContent::rich((string)$row['msg_post'],$resolver,$this->source instanceof InvisionSource?$this->source->contentOrigin():null);
        if($body===null){$this->mark('inv_pm_content',$source,null,'invalid_private_rich_content');return;}
        if($body==='')$body='[Empty imported message]';
        $this->query("UPDATE ce_forum_conversation_messages SET body=?,body_format='bbcode' WHERE id=?",[$body,$id]);
        $this->query("UPDATE ce_forum_conversation_revisions SET body=?,body_format='bbcode' WHERE message_id=? AND revision_number=1",[$body,$id]);
        $this->mark('inv_pm_content',$source,$id);
    }

    private function rewriteInvisionPost(array $row):void
    {
        $source=(int)$row['post_id'];$post=$this->mapped('posts',$source);
        if($post===null){$this->mark('invision_content',$source,null,'post_not_imported');return;}
        $resolve=function(string $kind,int $old):?string{
            $id=$this->mapped($kind,$old);if($id===null)return null;
            return match($kind){
                'users'=>'/forum/members/'.$id,
                'topics'=>'/forum/threads/'.$id,
                'attachments'=>'/api/v1/forum/attachments/'.$id.'/download',
                'posts'=>($p=$this->row('SELECT thread_id,ordinal FROM ce_forum_posts WHERE id=?',[$id]))!==null
                    ?'/forum/threads/'.$p['thread_id'].'?page='.(1+intdiv(max(1,(int)$p['ordinal'])-1,25)).'#post-'.$id:null,
                default=>null,
            };
        };
        $body=is_string($row['post_text'])?InvisionContent::rich($row['post_text'],$resolve,$this->source instanceof InvisionSource?$this->source->contentOrigin():null):null;
        if($body===null){$this->mark('invision_content',$source,null,'invalid_or_over_limit_html');return;}
        if($body==='')$body='[Empty imported message]';
        $this->query("UPDATE ce_forum_posts SET body=?,body_format='bbcode' WHERE id=?",[$body,$post]);
        $this->query("UPDATE ce_forum_post_revisions SET body=?,body_format='bbcode' WHERE post_id=? AND revision_number=1",[$body,$post]);
        $this->mark('invision_content',$source,$post);
    }

    /** Existing attachment ACL enforces post membership; source storage never becomes a public URL. */
    private function importInvisionAttachment(array $r):void
    {
        $source=(int)$r['attach_id'];$oldPost=(int)($r['post_msg_id']??0);
        if((int)($r['map_count']??0)!==1||$oldPost<=0){$this->mark('attachments',$source,null,'not_a_unique_forum_post_attachment');return;}
        $post=$this->mapped('posts',$oldPost);$owner=$this->mapped('users',(int)$r['poster_id']);
        if($post===null||$owner===null){$this->mark('attachments',$source,null,'post_or_owner_not_imported');return;}
        $actual=$this->row('SELECT author_id FROM ce_forum_posts WHERE id=?',[$post]);
        if($actual===null||$actual['author_id']!==$owner){$this->mark('attachments',$source,null,'post_author_mismatch');return;}
        if($this->files===null)throw new \RuntimeException('Invision attachment snapshot is required');
        $root=realpath($this->files);
        $relative=(string)$r['physical_filename'];
        $relative=preg_replace('~^<fileStore\\.core_Attachment>/~','',$relative)??'';
        if(!$root || strlen($relative)>512 || !preg_match('~\\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\\z~D',$relative)
            || array_intersect(explode('/',$relative),['.','..'])){
            $this->mark('attachments',$source,null,'unsupported_storage_path');return;
        }
        $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        $real=realpath($path);
        if($real===false||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)||!is_file($real)||is_link($path)){
            $this->mark('attachments',$source,null,'missing_or_unsafe_local_file');return;
        }
        $size=filesize($real);
        if($size===false||$size<1||$size>26214400||$size!==(int)$r['filesize']){
            $this->mark('attachments',$source,null,'file_size_mismatch_or_limit');return;
        }
        $usage=$this->row('SELECT COUNT(*) AS n,COALESCE(SUM(size_bytes),0) AS bytes FROM ce_forum_attachments WHERE post_id=?',[$post]);
        if((int)$usage['n']>=10||(int)$usage['bytes']+$size>52428800){
            $this->mark('attachments',$source,null,'post_attachment_limit');return;
        }
        $data=file_get_contents($real,false,null,0,26214401);
        if($data===false||strlen($data)!==$size)throw new \RuntimeException('Frozen Invision file changed during read');
        try{$m=AttachmentService::validate((string)$r['real_filename'],$data);}
        catch(ForumException){$this->mark('attachments',$source,null,'unsupported_or_invalid_attachment');return;}
        $id=self::uuid();
        $this->query('INSERT INTO ce_forum_attachments(id,owner_id,post_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',
            [$id,$owner,$post,$m['filename'],$m['mime'],$m['size_bytes'],$m['sha256'],base64_encode($data),max(0,(int)$r['filetime'])]);
        if(in_array($m['mime'],['image/jpeg','image/png','image/webp'],true))
            $this->query("INSERT INTO ce_forum_media_state(attachment_id,state,due_at) VALUES(?,'pending',0)",[$id]);
        $this->mark('attachments',$source,$id);
    }

    /** Import verifiable choices, never execute serialized IPS classes or guess voter data. */
    private function importInvisionPoll(array $r):void
    {
        $source=(int)$r['poll_id'];$topic=(int)($r['topic_id']??0);$thread=$this->mapped('topics',$topic);
        if($thread===null){$this->mark('polls',$source,null,'topic_not_imported');return;}
        $choices=InvisionPoll::options((string)$r['choices']);
        if($choices===null){$this->mark('polls',$source,null,'unsupported_poll_choices');return;}
        $question=trim(html_entity_decode(strip_tags((string)$r['poll_question']),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if($question===''||strlen($question)>180||!preg_match('//u',$question)){
            $this->mark('polls',$source,null,'invalid_poll_question');return;
        }
        if($this->row('SELECT thread_id FROM ce_forum_polls WHERE thread_id=?',[$thread])){
            $this->mark('polls',$source,null,'poll_already_exists');return;
        }
        $this->query("INSERT INTO ce_forum_polls(thread_id,question,max_choices,allow_change,visibility,closes_at,closed) VALUES(?,?,1,0,'always',NULL,1)",[$thread,$question]);
        foreach($choices as $i=>$option)$this->query('INSERT INTO ce_forum_poll_options(thread_id,option_no,label) VALUES(?,?,?)',[$thread,$i+1,$option]);
        $this->mark('polls',$source,$thread,'imported_closed_no_voter_history');
    }

    private function rewriteXenForoPost(array $sourcePost): void
    {
        $sourceId=(int)$sourcePost['post_id'];
        $post=$this->mapped('posts',$sourceId);
        if($post===null){$this->mark(self::CONTENT_PHASE,$sourceId,null,'post_not_imported');return;}
        if(!is_string($sourcePost['post_text'])||!preg_match('//u',$sourcePost['post_text'])){
            $this->mark(self::CONTENT_PHASE,$sourceId,null,'invalid_source_content');return;
        }
        $resolver=function(string $kind,int $sourceId): ?string {
            $target=$this->mapped($kind,$sourceId);
            if($target===null)return null;
            return match($kind){
                'users'=>'/forum/members/'.$target,
                'topics'=>'/forum/threads/'.$target,
                'forums'=>'/forum/boards/'.$target,
                'attachments'=>'/api/v1/forum/attachments/'.$target.'/download',
                'posts'=>($post=$this->row('SELECT thread_id,ordinal FROM ce_forum_posts WHERE id=?',[$target]))!==null
                    ?'/forum/threads/'.$post['thread_id'].'?page='.(1+intdiv(max(1,(int)$post['ordinal'])-1,25)).'#post-'.$target:null,
                default=>null,
            };
        };
        $origin=$this->source instanceof MultiForumSource?$this->source->contentOrigin():null;
        $body=XenForoContent::convert($sourcePost['post_text'],$resolver,$origin);
        if(strlen($body)>80000 || preg_match_all('/./us',$body)>20000){
            // Original source text remains available as plain text for operator review;
            // never truncate a converted message or store markup the renderer cannot read.
            $this->mark(self::CONTENT_PHASE,$sourceId,null,'conversion_over_renderer_limit');return;
        }
        $this->query("UPDATE ce_forum_posts SET body=?,body_format='bbcode' WHERE id=?",[$body,$post]);
        $this->query("UPDATE ce_forum_post_revisions SET body=?,body_format='bbcode' WHERE post_id=? AND revision_number=1",[$body,$post]);
        $this->mark(self::CONTENT_PHASE,$sourceId,$post);
    }

    /** Copy bytes only from an operator-supplied immutable avatar snapshot; output always
     * goes through the production image sanitizer (re-encoded JPEG, no source metadata).
     * All writes and map/checkpoint updates occur in the caller's transaction.
     */
    private function importAvatar(array $row): void
    {
        $source=(int)$row['avatar_id'];
        $user=$this->mapped('users',$source);
        if($user===null){$this->mark('avatars',$source,null,'user_not_imported');return;}
        if($this->avatarFiles===null){$this->mark('avatars',$source,null,'avatar_directory_not_supplied');return;}
        $reason='avatar_file_missing_or_unsafe';
        foreach(XenForoAvatar::paths($this->avatarFiles,$source) as $candidate){
            if(is_link($candidate)||!is_file($candidate))continue;
            $real=realpath($candidate);
            if($real===false||$real!==$candidate||!str_starts_with($real,$this->avatarFiles.DIRECTORY_SEPARATOR))continue;
            $size=filesize($real);
            if($size===false||$size<1||$size>2097152){$reason='avatar_file_too_large_or_empty';continue;}
            $bytes=file_get_contents($real,false,null,0,2097153);
            if($bytes===false||strlen($bytes)!==$size)throw new \RuntimeException('XenForo avatar snapshot changed during read');
            try{$image=\CajeerEngine\Forum\Security\ProfileImage::encode('avatar',base64_encode($bytes));}
            catch(\CajeerEngine\Forum\ForumException $e){
                if($e->status===503)throw $e; // missing GD fails instead of silently losing images
                $reason='invalid_avatar_image';continue;
            }
            $this->query("INSERT INTO ce_forum_profile_images(user_id,kind,image_data) VALUES(?,'avatar',?)",[$user,$image]);
            $this->mark('avatars',$source,$user);return;
        }
        $this->mark('avatars',$source,null,$reason);
    }

    /** Imported private conversations remain unavailable during migration: their participants
     * are freshly imported, disabled local accounts, and no imported credentials are used.
     * Deleted source recipients are never restored. All participants and mapping commit together.
     */
    private function importConversation(array $row): void
    {
        $source=(int)$row['conversation_source_id'];
        if(!($this->source instanceof MultiForumSource))throw new \LogicException('Expected XenForo snapshot');
        $recipients=$this->source->mediaRecipients($source);
        if(count($recipients)>20){$this->mark('conversations',$source,null,'recipient_limit_exceeded');return;}
        if(!$this->source->mediaHasMessages($source)){$this->mark('conversations',$source,null,'empty_conversation');return;}
        $members=[];
        foreach($recipients as $recipient){
            if((string)$recipient['recipient_state']!=='active')continue;
            $user=$this->mapped('users',(int)$recipient['user_id']);
            if($user===null){$this->mark('conversations',$source,null,'active_recipient_not_imported');return;}
            $members[$user]=true;
        }
        if($members===[]){$this->mark('conversations',$source,null,'no_active_recipients');return;}
        $owner=$this->mapped('users',(int)$row['owner_source_id']);
        if($owner===null||!isset($members[$owner]))$owner=array_key_first($members);
        $title=trim(str_replace("\0",'',(string)$row['title']));
        if(!preg_match('//u',$title)||strlen($title)>720){$this->mark('conversations',$source,null,'invalid_title');return;}
        $target=self::uuid();$when=max(0,(int)$row['start_date']);
        $kind=count($members)>2?'group':'direct';
        $this->query('INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at) VALUES(?,?,?,?,?,?)',
            [$target,$owner,PhpbbText::short($title,180,'Imported conversation '.$source),$kind,$when,$when]);
        foreach(array_keys($members) as $user)$this->query('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,active) VALUES(?,?,1,0,1)',[$target,$user]);
        $this->mark('conversations',$source,$target);
    }
    private function importConversationMessage(array $row): void
    {
        $source=(int)$row['message_source_id'];$conversation=$this->mapped('conversations',(int)$row['conversation_id']);
        if($conversation===null){$this->mark('conversation_messages',$source,null,'conversation_not_imported');return;}
        $body=$row['body'];
        if(!is_string($body)||!preg_match('//u',$body)||strlen($body)>80000 || preg_match_all('/./us',$body)>20000){
            $this->mark('conversation_messages',$source,null,'invalid_or_oversized_message');return;
        }
        $body=str_replace("\0",'',$body);
        if(trim($body)==='')$body='[Empty imported message]';
        $author=$this->mapped('users',(int)$row['author_source_id']);
        $status=$this->row('SELECT last_sequence,updated_at FROM ce_forum_conversations WHERE id=?',[$conversation]);
        if($status===null)throw new \RuntimeException('Imported conversation missing during message import');
        $sequence=(int)$status['last_sequence']+1;
        $date=max(0,(int)$row['message_date']);$id=self::uuid();
        $this->query("INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,body_format,created_at) VALUES(?,?,?,?,?,'plain',?)",[$id,$conversation,$author,$sequence,$body,$date]);
        $this->query("INSERT INTO ce_forum_conversation_revisions(message_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'XenForo import',?)",[$id,$body,$author,$date]);
        $this->query('UPDATE ce_forum_conversations SET last_sequence=?,updated_at=? WHERE id=?',[$sequence,max((int)$status['updated_at'],$date),$conversation]);
        $this->mark('conversation_messages',$source,$id);
    }
    /** For private attachments the target message_id is set immediately, so the normal
     * download route checks the conversation membership. Never create public post links.
     */
    private function importConversationAttachment(array $row): void
    {
        $source=(int)$row['attach_id'];
        $message=$this->mapped('conversation_messages',(int)$row['message_source_id']);
        $owner=$this->mapped('users',(int)$row['poster_id']);
        if($message===null||$owner===null||(int)$row['is_orphan']!==0){
            $this->mark('conversation_attachments',$source,null,'orphan_or_unimported_private_message');return;
        }
        $m=$this->row('SELECT author_id FROM ce_forum_conversation_messages WHERE id=?',[$message]);
        if($m===null||$m['author_id']!==$owner){$this->mark('conversation_attachments',$source,null,'private_attachment_author_mismatch');return;}
        $usage=$this->row('SELECT COUNT(*) AS n,COALESCE(SUM(size_bytes),0) AS bytes FROM ce_forum_attachments WHERE message_id=?',[$message]);
        if((int)$usage['n']>=10){$this->mark('conversation_attachments',$source,null,'private_message_attachment_limit');return;}
        [$bytes,$reason]=$this->loadFrozenAttachment($row);
        if($bytes===null){$this->mark('conversation_attachments',$source,null,$reason);return;}
        if((int)$usage['bytes']+strlen($bytes)>52428800){$this->mark('conversation_attachments',$source,null,'private_message_attachment_size_limit');return;}
        try{$info=AttachmentService::validate((string)$row['real_filename'],$bytes);}
        catch(ForumException){$this->mark('conversation_attachments',$source,null,'unsupported_private_attachment');return;}
        $id=self::uuid();
        $this->query('INSERT INTO ce_forum_attachments(id,owner_id,message_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',
            [$id,$owner,$message,$info['filename'],$info['mime'],$info['size_bytes'],$info['sha256'],base64_encode($bytes),max(0,(int)$row['filetime'])]);
        if(in_array($info['mime'],['image/jpeg','image/png','image/webp'],true))
            $this->query("INSERT INTO ce_forum_media_state(attachment_id,state,due_at) VALUES(?,'pending',0)",[$id]);
        $this->mark('conversation_attachments',$source,$id);
    }
    /** Returns [bytes, skip reason]. Unsafe paths are never opened, and a source file
     * changing during a read aborts the transaction rather than storing corrupt bytes.
     */
    private function loadFrozenAttachment(array $row): array
    {
        if($this->files===null)return [null,'attachment_directory_not_supplied'];
        $root=realpath($this->files);$name=(string)($row['physical_filename']??'');
        if(!$root||!preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D',$name)||in_array('..',explode('/',$name),true)||in_array('.',explode('/',$name),true))
            return [null,'unsafe_private_file_path'];
        $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$name);
        if(is_link($path)||!is_file($path)||realpath($path)!==$path)return [null,'private_file_missing_or_unsafe'];
        $size=filesize($path);
        if($size===false||$size<1||$size>26214400||$size!==(int)$row['filesize'])return [null,'private_file_size_mismatch'];
        $bytes=file_get_contents($path,false,null,0,26214401);
        if($bytes===false||strlen($bytes)!==$size)throw new \RuntimeException('Frozen XenForo private file changed during read');
        return [$bytes,''];
    }
    /** Resolve private IDs only after all conversation, message and file mappings exist. */
    private function rewriteConversationMessage(array $row): void
    {
        $source=(int)$row['message_source_id'];$id=$this->mapped('conversation_messages',$source);
        if($id===null){$this->mark('conversation_content',$source,null,'message_not_imported');return;}
        if(!is_string($row['body'])||!preg_match('//u',$row['body'])){
            $this->mark('conversation_content',$source,null,'invalid_source_content');return;
        }
        $resolve=function(string $kind,int $old): ?string {
            if($kind==='attachments'){
                $target=$this->mapped('conversation_attachments',$old);
                return $target!==null?'/api/v1/forum/attachments/'.$target.'/download':null;
            }
            if($kind==='conversations'){
                $target=$this->mapped('conversations',$old);
                return $target!==null?'/forum/conversations/'.$target:null;
            }
            if($kind==='users'){
                $target=$this->mapped('users',$old);
                return $target!==null?'/forum/members/'.$target:null;
            }
            // Do not turn old public attachments or private messages into unguarded URLs.
            return null;
        };
        $origin=$this->source instanceof MultiForumSource?$this->source->contentOrigin():null;
        $body=XenForoContent::convert($row['body'],$resolve,$origin);
        if(strlen($body)>80000||preg_match_all('/./us',$body)>20000){
            $this->mark('conversation_content',$source,null,'conversion_over_renderer_limit');return;
        }
        $this->query("UPDATE ce_forum_conversation_messages SET body=?,body_format='bbcode' WHERE id=?",[$body,$id]);
        $this->query("UPDATE ce_forum_conversation_revisions SET body=?,body_format='bbcode' WHERE message_id=? AND revision_number=1",[$body,$id]);
        $this->mark('conversation_content',$source,$id);
    }

    /** Target data, ledger and checkpoint are written in the caller's one SQL transaction. */
    private function importAdditional(string $kind,array $r): void
    {
        if($kind==='profiles'){
            $source=(int)$r['profile_id'];$user=$this->mapped('users',(int)$r['user_id']);
            if($user===null){$this->mark($kind,$source,null,'user_not_imported');return;}
            $values=[];
            foreach(['bio'=>2000,'location'=>120,'website'=>500,'signature'=>500] as $column=>$max){
                $text=trim(str_replace("\0",'',(string)($r[$column]??'')));
                if(!preg_match('//u',$text)||mb_strlen($text,'UTF-8')>$max){$this->mark($kind,$source,null,'invalid_or_oversized_profile');return;}
                $values[$column]=$text;
            }
            if($values['website']!=='' && (!filter_var($values['website'],FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($values['website'],PHP_URL_SCHEME)),['http','https'],true))){
                $this->mark($kind,$source,null,'unsafe_profile_url');return;
            }
            if(implode('',$values)===''){$this->mark($kind,$source,null,'empty_profile');return;}
            // Imported users retain private profile/activity preferences; no automatic publication.
            $this->query('INSERT INTO ce_forum_member_profiles(user_id,bio,location,website,signature) VALUES(?,?,?,?,?)',[$user,$values['bio'],$values['location'],$values['website'],$values['signature']]);
            $this->mark($kind,$source,$user);return;
        }
        if($kind==='reactions'){
            $source=(int)$r['reaction_source_id'];
            $post=$this->mapped('posts',(int)$r['post_id']);$user=$this->mapped('users',(int)$r['user_id']);
            if($post===null||$user===null){$this->mark($kind,$source,null,'post_or_user_not_imported');return;}
            // XF standard 1=Like, 2=Love. Unsupported emotive/negative/custom types are audited, not misrepresented.
            $type=match((int)$r['source_type']){1=>'like',2=>'celebrate',default=>null};
            if($type===null){$this->mark($kind,$source,null,'unsupported_reaction_type');return;}
            if(($this->row('SELECT author_id FROM ce_forum_posts WHERE id=?',[$post])['author_id']??null)===$user){$this->mark($kind,$source,null,'self_reaction');return;}
            if($this->row('SELECT post_id FROM ce_forum_reactions WHERE post_id=? AND user_id=?',[$post,$user])){
                $this->mark($kind,$source,null,'duplicate_reaction');return;
            }
            $this->query('INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,?)',[$post,$user,$type]);
            $this->mark($kind,$source,$post);return;
        }
        throw new \RuntimeException('Unknown additional import phase');
    }
    private function import(string $kind,array $r,string $category): void
    {
        $id=self::uuid();$reason='';$target=$id;
        switch($kind){
            case 'groups':
                $source=(int)$r['group_id'];
                $handle='forum-group-import-'.substr($this->source->identity(),0,12).'-'.$source;
                $this->query('INSERT INTO ce_roles(id,handle,name,permissions) VALUES(?,?,?,?)',[$id,$handle,PhpbbText::short((string)$r['group_name'],80,'Imported group '.$source),'[]']);
                $this->query('INSERT INTO ce_forum_groups(role_id,description) VALUES(?,?)',[$id,PhpbbText::short((string)$r['group_desc'],500,'')]);break;
            case 'users':
                $source=(int)$r['user_id'];$email=strtolower(trim((string)$r['user_email']));
                if(($this->source->platform()==='phpbb'&&$source===1)||(int)$r['user_type']===2){$target=null;$reason='anonymous_or_bot';break;}
                if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)){$target=null;$reason='invalid_email';break;}
                if($this->row('SELECT id FROM ce_users WHERE LOWER(email)=?',[$email])){$target=null;$reason='email_conflict';break;}
                $name=PhpbbText::short((string)$r['username'],60,'Imported member '.$source);
                // Never import credentials or privileges. Operator reviews and activates accounts before reset.
                $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);if($hash===false)throw new \RuntimeException('Cannot generate replacement credential');
                $date=PhpbbText::date(max(1,min(2147483647,(int)$r['user_regdate'])));
                $this->query("INSERT INTO ce_users(id,email,name,password_hash,status,two_factor_recovery_codes,created_at,updated_at,disabled_at) VALUES(?,?,?,?,'disabled','[]',?,?,CURRENT_TIMESTAMP)",[$id,$email,$name,$hash,$date,$date]);
                $member=$this->row("SELECT id FROM ce_roles WHERE handle='forum-member'")['id']??throw new \RuntimeException('Missing forum-member role');
                $this->query('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)',[$id,$member]);
                $this->query('INSERT INTO ce_forum_primary_groups(user_id,role_id) VALUES(?,?)',[$id,$member]);
                $this->query("INSERT INTO ce_forum_member_preferences(user_id,profile_visibility,activity_visibility,show_groups) VALUES(?,'private','private',0)",[$id]);
                $groups=$this->source->memberships($source);if(count($groups)>1000)throw new \RuntimeException('Source user has over 1000 memberships');
                foreach(array_unique($groups) as $group){$role=$this->mapped('groups',(int)$group);if($role!==null)$this->query('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)',[$id,$role]);}
                if($this->communityPipeline&&$groups!==[]){
                    $primary=$this->mapped('groups',(int)$groups[0]);
                    if($primary!==null)$this->query('UPDATE ce_forum_primary_groups SET role_id=? WHERE user_id=?',[$primary,$id]);
                }break;
            case 'forums':
                $source=(int)$r['forum_id'];$type=(int)$r['forum_type'];
                if(!in_array($type,[0,1],true)){$target=null;$reason='link_or_unknown_forum';break;}
                $parent=(int)$r['parent_id'];$parentId=$parent===0?null:$this->mapped('forums',$parent);
                if($parent!==0&&$parentId===null){$target=null;$reason='parent_not_imported';break;}
                $this->query("INSERT INTO ce_forum_boards(id,category_id,parent_id,name,description,position,node_type,state,visibility,seo_index) VALUES(?,?,?,?,?,?,?,'readonly','hidden',0)",[$id,$category,$parentId,PhpbbText::short((string)$r['forum_name'],120,'Imported forum '.$source),PhpbbText::short((string)$r['forum_desc'],4000,''),(int)$r['left_id'],$type===0?'container':'forum']);break;
            case 'topics':
                $source=(int)$r['topic_id'];$board=$this->mapped('forums',(int)$r['forum_id']);
                if((int)$r['topic_visibility']!==1||$board===null||(int)$r['topic_status']===2||!$this->row("SELECT id FROM ce_forum_boards WHERE id=? AND node_type='forum'",[$board])){$target=null;$reason='unapproved_moved_or_missing_forum';break;}
                $this->query('INSERT INTO ce_forum_threads(id,board_id,author_id,title,locked,pinned,created_at,updated_at,read_sequence) VALUES(?,?,?,?,?,?,?,?,0)',[$id,$board,$this->mapped('users',(int)$r['topic_poster']),PhpbbText::short((string)$r['topic_title'],180,'Imported topic '.$source),(int)$r['topic_status']===1?1:0,(int)$r['topic_type']>0?1:0,PhpbbText::date($r['topic_time']),PhpbbText::date(max((int)$r['topic_time'],(int)$r['topic_last_post_time']))]);break;
            case 'posts':
                $source=(int)$r['post_id'];$thread=$this->mapped('topics',(int)$r['topic_id']);
                if((int)$r['post_visibility']!==1||$thread===null||$this->mapped('forums',(int)$r['forum_id'])!==($this->row('SELECT board_id FROM ce_forum_threads WHERE id=?',[$thread])['board_id']??null)){$target=null;$reason='unapproved_or_missing_topic';break;}
                if($r['post_text']===null){$target=null;$reason='body_over_1mib';break;}
                // v3 content is rendered by ForumContentRenderer (80 KB / 20k codepoints).
                // Reject over-limit source rather than importing a post that will break SSR.
                if($this->contentPipeline && (strlen((string)$r['post_text'])>80000 || preg_match_all('/./us',(string)$r['post_text'])>20000)){
                    $target=null;$reason='body_over_renderer_limit';break;
                }
                $body=$this->source->platform()==='phpbb'?PhpbbText::plain((string)$r['post_text'],(string)$r['bbcode_uid']):str_replace("\0",'',(string)$r['post_text']);
                if(!preg_match('//u',$body)){$target=null;$reason='invalid_utf8';break;}
                if(trim($body)==='')$body='[Empty imported message]';
                // Preserve content as literal text; reject oversize source rows rather than silently truncate.
                if(strlen($body)>1048576){$target=null;$reason='body_over_1mib';break;}
                $seq=(int)$this->row('SELECT read_sequence FROM ce_forum_threads WHERE id=?',[$thread])['read_sequence']+1;
                $this->query("INSERT INTO ce_forum_posts(id,thread_id,author_id,body,body_format,ordinal,read_sequence,created_at,edited_at) VALUES(?,?,?,?,'plain',?,?,?,?)",[$id,$thread,$this->mapped('users',(int)$r['poster_id']),$body,$seq,$seq,PhpbbText::date($r['post_time']),(int)$r['post_edit_time']>0?PhpbbText::date($r['post_edit_time']):null]);
                $this->query("INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'Forum import',?)",[$id,$body,$this->mapped('users',(int)$r['poster_id']),max(0,(int)$r['post_time'])]);
                $this->query('UPDATE ce_forum_threads SET read_sequence=? WHERE id=?',[$seq,$thread]);break;
            case 'attachments':
                $source=(int)$r['attach_id'];$post=$this->mapped('posts',(int)$r['post_msg_id']);$owner=$this->mapped('users',(int)$r['poster_id']);
                if((int)$r['in_message']!==0||(int)$r['is_orphan']!==0||$post===null||$owner===null||!$this->row('SELECT id FROM ce_forum_posts WHERE id=? AND thread_id=? AND author_id=?',[$post,$this->mapped('topics',(int)$r['topic_id']),$owner])){$target=null;$reason='private_or_orphan_or_missing_owner';break;}
                if($this->files===null){$target=null;$reason='attachment_directory_not_supplied';break;}
                $filename=(string)$r['physical_filename'];$root=realpath($this->files);
                if(!$root||!preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D',$filename)||array_intersect(explode('/',$filename),['.','..'])){$target=null;$reason='unsafe_file_path';break;}
                $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$filename);
                if(is_link($path)||!is_file($path)||realpath($path)!==$path){$target=null;$reason='missing_or_unsafe_file';break;}
                $size=filesize($path);if($size===false||$size!==(int)$r['filesize']||$size<1||$size>26214400){$target=null;$reason='invalid_file_size';break;}
                $usage=$this->row('SELECT COUNT(*) AS n,COALESCE(SUM(size_bytes),0) AS bytes FROM ce_forum_attachments WHERE post_id=?',[$post]);
                if((int)$usage['n']>=10||(int)$usage['bytes']+$size>52428800){$target=null;$reason='post_attachment_limit';break;}
                $data=file_get_contents($path,false,null,0,26214401);if($data===false||strlen($data)!==$size)throw new \RuntimeException('Source attachment read failed; retry with the unchanged snapshot');
                try{$m=AttachmentService::validate((string)$r['real_filename'],$data);}catch(ForumException){$target=null;$reason='unsupported_or_invalid_file';break;}
                $this->query('INSERT INTO ce_forum_attachments(id,owner_id,post_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',[$id,$owner,$post,$m['filename'],$m['mime'],$m['size_bytes'],$m['sha256'],base64_encode($data),max(0,(int)$r['filetime'])]);
                if(in_array($m['mime'],['image/jpeg','image/png','image/webp'],true))$this->query("INSERT INTO ce_forum_media_state(attachment_id,state,due_at) VALUES(?,'pending',0)",[$id]);break;
            case 'polls':
                $source=(int)$r['topic_id'];$thread=$this->mapped('topics',$source);$options=$this->source->options($source);
                if($thread===null||count($options)<2||count($options)>20){$target=null;$reason='missing_topic_or_unsupported_options';break;}
                $target=$thread;$max=max(1,min(count($options),(int)$r['poll_max_options']));
                $close=(int)$r['poll_length']>0?max(1,(int)$r['poll_start']+(int)$r['poll_length']):null;
                $this->query("INSERT INTO ce_forum_polls(thread_id,question,max_choices,allow_change,visibility,closes_at,closed) VALUES(?,?,?,?,'always',?,1)",[$thread,PhpbbText::short((string)$r['poll_title'],180,'Imported poll'),$max,(int)$r['poll_vote_change']===1?1:0,$close]);
                foreach($options as $i=>$o)$this->query('INSERT INTO ce_forum_poll_options(thread_id,option_no,label) VALUES(?,?,?)',[$thread,$i+1,PhpbbText::short((string)$o['poll_option_text'],180,'Option '.($i+1))]);$reason='imported_closed';break;
            default:throw new \LogicException('Unexpected import phase');
        }
        $this->mark($kind,$source,$target,$reason);
    }
}
