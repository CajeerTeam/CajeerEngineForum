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
    public function __construct(private DatabaseManager $db, private ForumSource $source, private ?string $files=null) {}
    private array $mappingCache=[];
    private array $statements=[];
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
    public function plan(): array {return ['ok'=>true,'job'=>$this->source->identity(),'platform'=>$this->source->platform(),'version'=>$this->source->version(),'counts'=>$this->source->plan(),'policy'=>'hidden_readonly_until_manual_acl_review','unsupported'=>$this->source->unsupported()];}
    public function exists(): bool {return $this->row('SELECT id FROM ce_forum_import_jobs WHERE id=?',[$this->source->identity()])!==null;}
    public function start(string $backup): array
    {
        $this->resetBatch();$plan=$this->source->plan();$id=$this->source->identity();
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
        foreach(['groups'=>['ce_forum_groups','role_id'],'users'=>['ce_users','id'],'forums'=>['ce_forum_boards','id'],'topics'=>['ce_forum_threads','id'],'posts'=>['ce_forum_posts','id'],'attachments'=>['ce_forum_attachments','id'],'polls'=>['ce_forum_polls','thread_id']] as $kind=>[$table,$column]){
            // PostgreSQL uses UUID PKs; cast the small ledger key, not the indexed target column.
            $key=$this->db->driver()==='pgsql'?'CAST(m.target_id AS UUID)':'m.target_id';
            $n=(int)$this->query("SELECT COUNT(*) FROM ce_forum_import_map m LEFT JOIN $table t ON t.$column=$key WHERE m.job_id=? AND m.kind=? AND m.outcome='imported' AND t.$column IS NULL",[$job,$kind])->fetchColumn();
            if($n!==0)throw new \RuntimeException('Imported target missing: '.$kind);
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
            $phase=(int)$job['phase'];$kind=ForumSource::PHASES[$phase];$cursor=(int)$job['cursor_id'];$sub=(int)$job['subcursor_id'];
            $category=$this->row('SELECT visibility FROM ce_forum_categories WHERE id=?',[$job['category_id']]);
            if(!$category||$category['visibility']!=='hidden'||$this->row('SELECT id FROM ce_forum_boards WHERE category_id=? AND (visibility<>? OR state<>?) LIMIT 1',[$job['category_id'],'hidden','readonly']))throw new \RuntimeException('Imported tree must remain hidden and readonly until completion');
            $batch=$this->source->batch($kind,$cursor,$plan[$kind]['high'],$kind==='attachments'?1:($kind==='posts'?min($limit,20):$limit));
            if($batch===[]){
                if($phase+1===count(ForumSource::PHASES)){
                    if($this->source->plan()!==$plan)throw new \RuntimeException('Source snapshot changed; restore backup and restart with an immutable snapshot');
                    foreach(['groups','users','forums','topics','posts','attachments','polls','votes','watches','board_watches'] as $covered){
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
                $key=match($kind){'groups'=>'group_id','users'=>'user_id','forums'=>'left_id','topics','polls','votes','watches','finalize'=>'topic_id','board_watches'=>'forum_id','posts'=>'post_id','attachments'=>'attach_id'};
                $next=(int)$r[$key];
                if(in_array($kind,['watches','board_watches','votes'],true)){
                    $target=$this->mapped(match($kind){'watches'=>'topics','board_watches'=>'forums','votes'=>'polls'},$next);
                    if($target!==null){
                        $watchers=$this->source->watchers($kind,$next,$sub,$remaining);
                        $optionMap=[];$maxChoices=0;
                        if($kind==='votes'){
                            foreach($this->source->options($next) as $i=>$o)$optionMap[(int)$o['poll_option_id']]=$i+1;
                            $maxChoices=(int)$this->row('SELECT max_choices FROM ce_forum_polls WHERE thread_id=?',[$target])['max_choices'];
                        }
                        foreach($watchers as $uid){
                            $sub=(int)$uid;$user=$this->mapped('users',$sub);if($user===null)continue;
                            if($kind==='votes'){
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
                }else $this->import($kind,$r,$job['category_id']);
                $cursor=$next;
                if(in_array($kind,['watches','board_watches','votes'],true)&&$remaining<=0)break;
              }
            }
            $this->query('UPDATE ce_forum_import_jobs SET phase=?,cursor_id=?,subcursor_id=?,updated_at=? WHERE id=?',[$phase,$cursor,$sub,time(),$id]);
        });}finally{$this->resetBatch();}
        $result=['ok'=>true,'job'=>$this->row('SELECT id,state,phase,cursor_id,subcursor_id FROM ce_forum_import_jobs WHERE id=?',[$id])];$this->resetBatch();return $result;
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
                foreach(array_unique($groups) as $group){$role=$this->mapped('groups',(int)$group);if($role!==null)$this->query('INSERT INTO ce_user_roles(user_id,role_id) VALUES(?,?)',[$id,$role]);}break;
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
