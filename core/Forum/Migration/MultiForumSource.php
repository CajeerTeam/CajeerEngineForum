<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use PDO;

/** Normalizes native XenForo 2.2/2.3, MyBB 1.8 and SMF 2.1 snapshots.
 * The source connection is read-only; no legacy credentials or private payloads are selected.
 * Forum tree is the only buffered collection (bounded); all content uses keyset batches.
 */
final class MultiForumSource implements EnrichedForumSource
{
    private array $queries=[];
    private ?array $additionalCounts=null;
    private array $tree=[];
    private array $relationQueries=[];
    private array $extraCounts=[];
    private string $keyColumn;
    private string $pollOptions;
    private string $pollBallots;
    private string $pollVoters;
    private const KEYS=['groups'=>'group_id','users'=>'user_id','topics'=>'topic_id','posts'=>'post_id','attachments'=>'attach_id','finalize'=>'topic_id','polls'=>'topic_id','votes'=>'topic_id','watches'=>'topic_id','board_watches'=>'forum_id'];
    public function __construct(private PDO $pdo,private string $prefix,private string $type,private string $sourceVersion,private string $snapshot)
    {
        if(!preg_match('/\A[A-Za-z0-9_]{1,40}\z/D',$prefix))throw new \InvalidArgumentException('Invalid source prefix');
        $pattern=match($type){'xenforo'=>'2\.[23]\.\d+','mybb'=>'1\.8\.\d+','smf'=>'2\.1\.\d+',default=>throw new \InvalidArgumentException('Unknown forum source')};
        if(!preg_match('/\A'.$pattern.'\z/D',$sourceVersion))throw new \InvalidArgumentException('Unsupported source version; provide the installed stable version');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){$pdo->exec('SET NAMES utf8mb4');$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET SESSION TRANSACTION READ ONLY');}
        elseif($driver==='sqlite')$pdo->exec('PRAGMA query_only=ON');
        else throw new \InvalidArgumentException('Source must be MySQL/MariaDB or SQLite');
        $pdo->beginTransaction();
        $this->configure();
        $this->loadTree();
    }
    public function platform(): string{return $this->type;}
    public function version(): string{return $this->sourceVersion;}
    public function identity(): string{return hash('sha256',$this->type."\0".$this->sourceVersion."\0".$this->prefix."\0".$this->snapshot);}
    public function unsupported(): array
    {
        $items=['source ACL translation','password hashes','private messages','bans and sanctions','anonymous poll votes','avatars and custom profile fields','remote media','source-specific BBCode rendering','source-specific poll visibility (imported closed)'];
        if($this->type==='xenforo')$items[]='nonstandard/negative XenForo reactions (explicitly skipped with reason)';
        else $items[]='reactions and profile metadata (not yet supported for this platform)';
        return $items;
    }
    /** XenForo 2.x: preflight exact required columns, then scan immutable source snapshots. */
    private function additionalQuery(string $kind): string
    {
        return match($kind){
            'profiles'=>'SELECT user_id AS profile_id, user_id, about AS bio, location, website, signature FROM '.$this->t('user_profile'),
            'reactions'=>"SELECT reaction_content_id AS reaction_source_id, content_id AS post_id, reaction_user_id AS user_id, reaction_id AS source_type FROM ".$this->t('reaction_content')." WHERE content_type='post'",
            default=>throw new \InvalidArgumentException('Unknown additional import phase'),
        };
    }
    public function additionalPlan(): array
    {
        if($this->type!=='xenforo')return [];
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Source snapshot transaction ended');
        if($this->additionalCounts!==null)return $this->additionalCounts;
        $counts=[];
        foreach(['profiles'=>'profile_id','reactions'=>'reaction_source_id'] as $kind=>$key){
            $sql=$this->additionalQuery($kind);
            $row=$this->q('SELECT COUNT(*) AS n,COALESCE(MAX('.$key.'),0) AS high FROM ('.$sql.') src')->fetch(PDO::FETCH_ASSOC);
            if((int)$this->q('SELECT COUNT(*) FROM ('.$sql.') src WHERE '.$key.' IS NULL OR '.$key.'<=0')->fetchColumn()>0)
                throw new \RuntimeException('Invalid extended source identity: '.$kind);
            $counts[$kind]=['count'=>(int)$row['n'],'high'=>(int)$row['high']];
            $this->additionalBatch($kind,0,$counts[$kind]['high'],1);
        }
        return $this->additionalCounts=$counts;
    }
    public function additionalBatch(string $phase,int $after,int $high,int $limit): array
    {
        if($this->type!=='xenforo')throw new \InvalidArgumentException('Additional phase not supported for source');
        if($after<0||$high<0||$limit<1||$limit>500)throw new \InvalidArgumentException('Invalid additional batch bounds');
        $key=match($phase){'profiles'=>'profile_id','reactions'=>'reaction_source_id',default=>throw new \InvalidArgumentException('Unknown additional phase')};
        $sql=$this->additionalQuery($phase);
        return $this->all('SELECT * FROM ('.$sql.") src WHERE $key>? AND $key<=? ORDER BY $key LIMIT $limit",[$after,$high]);
    }
    private function t(string $table):string{return $this->prefix.$table;}
    private function q(string $sql,array $params=[]):\PDOStatement
    {
        $s=$this->pdo->prepare($sql);
        foreach(array_values($params) as $index=>$value){
            $type=is_int($value)?PDO::PARAM_INT:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR);
            $s->bindValue($index+1,$value,$type);
        }
        $s->execute();return $s;
    }
    private function all(string $sql,array $params=[]):array{return $this->q($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    private function configure():void
    {
        $t=fn(string $n)=>$this->t($n);
        switch($this->type){
        case 'xenforo':
            // XF 2.3 uses file_key; older snapshots may use file_hash. Detect actual column.
            $this->keyColumn='file_hash';
            try{$this->q('SELECT file_key FROM '.$t('attachment_data').' WHERE 1=0');$this->keyColumn='file_key';}catch(\PDOException){if(str_starts_with($this->sourceVersion,'2.3.'))throw new \RuntimeException('XenForo 2.3 attachment_data.file_key missing');}
            $this->queries=[
                'groups'=>'SELECT user_group_id AS group_id,title AS group_name,\'\' AS group_desc FROM '.$t('user_group'),
                'users'=>'SELECT user_id,0 AS user_type,username,email AS user_email,register_date AS user_regdate FROM '.$t('user'),
                'forums'=>"SELECT node_id AS forum_id,parent_node_id AS parent_id,display_order AS position,CASE node_type_id WHEN 'Category' THEN 0 WHEN 'Forum' THEN 1 ELSE 2 END AS forum_type,title AS forum_name,description AS forum_desc,0 AS forum_status FROM ".$t('node'),
                'topics'=>"SELECT thread_id AS topic_id,node_id AS forum_id,user_id AS topic_poster,title AS topic_title,post_date AS topic_time,last_post_date AS topic_last_post_time,CASE WHEN discussion_type='redirect' THEN 2 WHEN discussion_open=0 THEN 1 ELSE 0 END AS topic_status,sticky AS topic_type,CASE WHEN discussion_state='visible' THEN 1 ELSE 0 END AS topic_visibility FROM ".$t('thread'),
                'polls'=>"SELECT content_id AS topic_id,question AS poll_title,0 AS poll_start,close_date AS poll_length,CASE WHEN max_votes=0 THEN 20 ELSE max_votes END AS poll_max_options,0 AS poll_vote_change FROM ".$t('poll')." WHERE content_type='thread'",
                'posts'=>"SELECT p.post_id,p.thread_id AS topic_id,t.node_id AS forum_id,p.user_id AS poster_id,CASE WHEN LENGTH(p.message)<=1048576 THEN p.message ELSE NULL END AS post_text,'' AS bbcode_uid,p.post_date AS post_time,p.last_edit_date AS post_edit_time,CASE WHEN p.message_state='visible' THEN 1 ELSE 0 END AS post_visibility FROM ".$t('post').' p LEFT JOIN '.$t('thread').' t ON t.thread_id=p.thread_id',
                'attachments'=>"SELECT a.attachment_id AS attach_id,a.content_id AS post_msg_id,p.thread_id AS topic_id,CASE WHEN a.content_type='post' THEN 0 ELSE 1 END AS in_message,d.user_id AS poster_id,a.unassociated AS is_orphan,d.data_id AS data_id,d.".$this->keyColumn." AS storage_key,d.filename AS real_filename,d.file_size AS filesize,a.attach_date AS filetime FROM ".$t('attachment').' a LEFT JOIN '.$t('attachment_data').' d ON d.data_id=a.data_id LEFT JOIN '.$t('post')." p ON p.post_id=a.content_id AND a.content_type='post'",
            ];
            $this->relationQueries=['watches'=>'SELECT thread_id AS object_id,user_id FROM '.$t('thread_watch'),'board_watches'=>'SELECT node_id AS object_id,user_id FROM '.$t('forum_watch')];
            $this->pollOptions='SELECT r.poll_response_id AS poll_option_id,r.response AS poll_option_text FROM '.$t('poll_response').' r JOIN '.$t('poll')." p ON p.poll_id=r.poll_id WHERE p.content_type='thread' AND p.content_id=? ORDER BY r.poll_response_id LIMIT 21";
            $this->pollBallots='SELECT DISTINCT v.poll_response_id AS option_id FROM '.$t('poll_vote').' v JOIN '.$t('poll_response').' r ON r.poll_response_id=v.poll_response_id JOIN '.$t('poll')." p ON p.poll_id=r.poll_id WHERE p.content_type='thread' AND p.content_id=? AND v.user_id=? ORDER BY option_id LIMIT 21";
            $this->pollVoters='SELECT p.content_id AS object_id,v.user_id FROM '.$t('poll_vote').' v JOIN '.$t('poll_response').' r ON r.poll_response_id=v.poll_response_id JOIN '.$t('poll')." p ON p.poll_id=r.poll_id WHERE p.content_type='thread'";
            $this->extraCounts=['private_messages'=>'conversation_message','polls'=>'poll','reactions'=>'reaction_content'];
            break;
        case 'mybb':
            $this->queries=[
                'groups'=>'SELECT gid AS group_id,title AS group_name,description AS group_desc FROM '.$t('usergroups'),
                'users'=>'SELECT uid AS user_id,0 AS user_type,username,email AS user_email,regdate AS user_regdate FROM '.$t('users'),
                'forums'=>"SELECT fid AS forum_id,pid AS parent_id,disporder AS position,CASE WHEN linkto<>'' THEN 2 WHEN type='c' THEN 0 WHEN type='f' THEN 1 ELSE 2 END AS forum_type,name AS forum_name,description AS forum_desc,0 AS forum_status FROM ".$t('forums'),
                'topics'=>"SELECT tid AS topic_id,fid AS forum_id,uid AS topic_poster,subject AS topic_title,dateline AS topic_time,lastpost AS topic_last_post_time,CASE WHEN closed LIKE 'moved|%' THEN 2 WHEN closed='1' THEN 1 ELSE 0 END AS topic_status,sticky AS topic_type,CASE WHEN visible=1 THEN 1 ELSE 0 END AS topic_visibility FROM ".$t('threads'),
                'polls'=>'SELECT tid AS topic_id,question AS poll_title,dateline AS poll_start,timeout*86400 AS poll_length,CASE WHEN multiple=0 THEN 1 WHEN maxoptions=0 THEN 20 ELSE maxoptions END AS poll_max_options,0 AS poll_vote_change FROM '.$t('polls'),
                'posts'=>"SELECT pid AS post_id,tid AS topic_id,fid AS forum_id,uid AS poster_id,CASE WHEN LENGTH(message)<=1048576 THEN message ELSE NULL END AS post_text,'' AS bbcode_uid,dateline AS post_time,edittime AS post_edit_time,CASE WHEN visible=1 THEN 1 ELSE 0 END AS post_visibility FROM ".$t('posts'),
                'attachments'=>"SELECT a.aid AS attach_id,a.pid AS post_msg_id,p.tid AS topic_id,0 AS in_message,a.uid AS poster_id,CASE WHEN a.visible=1 THEN 0 ELSE 1 END AS is_orphan,a.attachname AS physical_filename,a.filename AS real_filename,a.filesize,a.dateuploaded AS filetime FROM ".$t('attachments').' a LEFT JOIN '.$t('posts').' p ON p.pid=a.pid',
            ];
            $this->relationQueries=['watches'=>'SELECT tid AS object_id,uid AS user_id FROM '.$t('threadsubscriptions'),'board_watches'=>'SELECT fid AS object_id,uid AS user_id FROM '.$t('forumsubscriptions')];
            $this->pollOptions='SELECT options,numoptions FROM '.$t('polls').' WHERE tid=?';
            $this->pollBallots='SELECT DISTINCT v.voteoption AS option_id FROM '.$t('pollvotes').' v JOIN '.$t('polls').' p ON p.pid=v.pid WHERE p.tid=? AND v.uid=? ORDER BY option_id LIMIT 21';
            $this->pollVoters='SELECT p.tid AS object_id,v.uid AS user_id FROM '.$t('pollvotes').' v JOIN '.$t('polls').' p ON p.pid=v.pid';
            $this->extraCounts=['private_messages'=>'privatemessages','polls'=>'polls','reputation'=>'reputation'];
            break;
        case 'smf':
            $settings=array_column($this->all('SELECT variable,value FROM '.$t('settings')." WHERE variable IN ('smfVersion','recycle_enable','recycle_board')"),'value','variable');
            if(isset($settings['smfVersion'])&&trim(preg_replace('/^SMF /i','',(string)$settings['smfVersion']))!==$this->sourceVersion)throw new \RuntimeException('SMF snapshot version differs from --source-version');
            $recycle=(int)($settings['recycle_enable']??0)!==0?(int)($settings['recycle_board']??0):0;
            $this->queries=[
                'groups'=>'SELECT id_group AS group_id,group_name,\'\' AS group_desc FROM '.$t('membergroups').' WHERE id_group>0',
                'users'=>'SELECT id_member AS user_id,0 AS user_type,real_name AS username,email_address AS user_email,date_registered AS user_regdate FROM '.$t('members'),
                // Source board IDs are unsigned 32-bit. Reserve a separate ID range for category nodes.
                'forums'=>"SELECT id_board AS forum_id,CASE WHEN id_parent=0 THEN 4294967296+id_cat ELSE id_parent END AS parent_id,board_order AS position,1 AS forum_type,name AS forum_name,description AS forum_desc,0 AS forum_status FROM ".$t('boards')." UNION ALL SELECT 4294967296+id_cat AS forum_id,0 AS parent_id,cat_order AS position,0 AS forum_type,name AS forum_name,'' AS forum_desc,0 AS forum_status FROM ".$t('categories'),
                'topics'=>'SELECT t.id_topic AS topic_id,t.id_board AS forum_id,t.id_member_started AS topic_poster,m.subject AS topic_title,m.poster_time AS topic_time,l.poster_time AS topic_last_post_time,CASE WHEN t.id_redirect_topic<>0 THEN 2 WHEN t.locked<>0 THEN 1 ELSE 0 END AS topic_status,t.is_sticky AS topic_type,CASE WHEN t.approved=1 AND t.id_board<>'.$recycle.' THEN 1 ELSE 0 END AS topic_visibility FROM '.$t('topics').' t LEFT JOIN '.$t('messages').' m ON m.id_msg=t.id_first_msg LEFT JOIN '.$t('messages').' l ON l.id_msg=t.id_last_msg',
                'polls'=>'SELECT t.id_topic AS topic_id,p.question AS poll_title,0 AS poll_start,p.expire_time AS poll_length,p.max_votes AS poll_max_options,0 AS poll_vote_change FROM '.$t('topics').' t JOIN '.$t('polls').' p ON p.id_poll=t.id_poll WHERE t.id_poll>0',
                'posts'=>"SELECT id_msg AS post_id,id_topic AS topic_id,id_board AS forum_id,id_member AS poster_id,CASE WHEN LENGTH(body)<=1048576 THEN body ELSE NULL END AS post_text,'' AS bbcode_uid,poster_time AS post_time,modified_time AS post_edit_time,CASE WHEN approved=1 THEN 1 ELSE 0 END AS post_visibility FROM ".$t('messages'),
                'attachments'=>'SELECT a.id_attach AS attach_id,a.id_msg AS post_msg_id,m.id_topic AS topic_id,CASE WHEN a.attachment_type=0 AND a.id_msg>0 THEN 0 ELSE 1 END AS in_message,m.id_member AS poster_id,CASE WHEN a.approved=1 THEN 0 ELSE 1 END AS is_orphan,a.id_attach AS data_id,a.file_hash AS storage_key,a.id_folder AS folder_id,a.filename AS real_filename,a.size AS filesize,m.poster_time AS filetime FROM '.$t('attachments').' a LEFT JOIN '.$t('messages').' m ON m.id_msg=a.id_msg',
            ];
            $this->relationQueries=['watches'=>'SELECT id_topic AS object_id,id_member AS user_id FROM '.$t('log_notify').' WHERE id_topic>0','board_watches'=>'SELECT id_board AS object_id,id_member AS user_id FROM '.$t('log_notify').' WHERE id_board>0 AND id_topic=0'];
            $this->pollOptions='SELECT c.id_choice AS poll_option_id,c.label AS poll_option_text FROM '.$t('poll_choices').' c JOIN '.$t('topics').' t ON t.id_poll=c.id_poll WHERE t.id_topic=? ORDER BY c.id_choice LIMIT 21';
            $this->pollBallots='SELECT DISTINCT v.id_choice AS option_id FROM '.$t('log_polls').' v JOIN '.$t('topics').' t ON t.id_poll=v.id_poll WHERE t.id_topic=? AND v.id_member=? ORDER BY option_id LIMIT 21';
            $this->pollVoters='SELECT t.id_topic AS object_id,v.id_member AS user_id FROM '.$t('log_polls').' v JOIN '.$t('topics').' t ON t.id_poll=v.id_poll';
            $this->extraCounts=['private_messages'=>'personal_messages','polls'=>'polls','likes'=>'user_likes','categories'=>'categories'];
            break;
        }
        $this->queries['votes']=$this->queries['polls'];$this->relationQueries['votes']=$this->pollVoters;
        $this->queries['finalize']=$this->queries['topics'];$this->queries['watches']=$this->queries['topics'];$this->queries['board_watches']=$this->queries['forums'];
    }
    private function loadTree():void
    {
        $rows=$this->all('SELECT * FROM ('.$this->queries['forums'].') src ORDER BY position,forum_id LIMIT 100001');
        if(count($rows)>100000)throw new \RuntimeException('Source tree exceeds 100000 nodes');
        $children=[];$ids=[];foreach($rows as $r){$id=(int)$r['forum_id'];if($id<=0||isset($ids[$id]))throw new \RuntimeException('Invalid source node identity');$ids[$id]=true;$children[(int)$r['parent_id']][]=$r;}
        $stack=array_map(fn($r)=>[$r,1],array_reverse($children[0]??[]));$seen=[];while($stack){[$r,$depth]=array_pop($stack);if($depth>16)throw new \RuntimeException('Source tree exceeds target depth of 16');$id=(int)$r['forum_id'];if(isset($seen[$id]))throw new \RuntimeException('Cyclic source node tree');$seen[$id]=true;$r['left_id']=count($this->tree)+1;$this->tree[]=$r;foreach(array_reverse($children[$id]??[]) as $child)$stack[]=[$child,$depth+1];}
        if(count($seen)!==count($rows))throw new \RuntimeException('Source tree contains orphan or cyclic nodes; repair the snapshot before importing');
    }
    private ?array $planned=null;
    public function plan():array
    {
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Source snapshot transaction ended');
        if($this->planned!==null)return $this->planned;
        $counts=['source_platform'=>$this->type,'source_tree_sha256'=>hash('sha256',json_encode($this->tree,JSON_THROW_ON_ERROR))];foreach(self::PHASES as $phase){
            if($phase==='forums'){$n=count($this->tree);$counts[$phase]=['count'=>$n,'high'=>$n];continue;}
            $key=self::KEYS[$phase];$row=$this->q('SELECT COUNT(*) AS n,COALESCE(MAX('.$key.'),0) AS high FROM ('.$this->queries[$phase].') src')->fetch(PDO::FETCH_ASSOC);
            $counts[$phase]=['count'=>(int)$row['n'],'high'=>(int)$row['high']];
            if((int)$this->q('SELECT COUNT(*) FROM ('.$this->queries[$phase].') src WHERE '.$key.'<=0 OR '.$key.' IS NULL')->fetchColumn()>0)throw new \RuntimeException('Invalid primary key in source phase '.$phase);
            $this->batch($phase,0,$counts[$phase]['high'],1);
        }
        foreach($this->extraCounts as $kind=>$table)$counts['source_'.$kind]=(int)$this->q('SELECT COUNT(*) FROM '.$this->t($table))->fetchColumn();
        foreach($this->relationQueries as $kind=>$sql)$counts['source_'.$kind]=(int)$this->q('SELECT COUNT(*) FROM ('.$sql.') src')->fetchColumn();
        $this->memberships(0);$this->options(0);$this->ballot(0,0);
        foreach(['watches','board_watches','votes'] as $kind)$this->watchers($kind,0,0,1);
        // A poll is keyed by topic, not by the source's unrelated poll ID.
        if((int)$this->q('SELECT COUNT(*) FROM (SELECT topic_id FROM ('.$this->queries['polls'].') p GROUP BY topic_id HAVING COUNT(*)>1) duplicate_polls')->fetchColumn()>0)throw new \RuntimeException('Multiple source polls for one topic');
        return $this->planned=$counts;
    }
    public function batch(string $phase,int $cursor,int $high,int $limit):array
    {
        $limit=max(1,min(500,$limit));
        if($phase==='forums')return array_slice($this->tree,$cursor,max(0,min($limit,$high-$cursor)));
        $key=self::KEYS[$phase]??throw new \InvalidArgumentException('Unknown phase');
        $rows=$this->all('SELECT * FROM ('.$this->queries[$phase].") src WHERE $key>? AND $key<=? ORDER BY $key LIMIT $limit",[$cursor,$high]);
        if($phase==='attachments')foreach($rows as &$r){
            if($this->type==='xenforo'){$id=(int)$r['data_id'];$r['physical_filename']=$id>0&&preg_match('/\A[a-f0-9]{32}\z/iD',(string)$r['storage_key'])?intdiv($id,1000).'/'.$id.'-'.$r['storage_key'].'.data':'';}
            elseif($this->type==='smf')$r['physical_filename']=preg_match('/\A[a-f0-9]{32,64}\z/iD',(string)$r['storage_key'])?(int)$r['folder_id'].'/'.(int)$r['data_id'].'_'.$r['storage_key']:'';
        }unset($r);
        return $rows;
    }
    public function memberships(int $user):array
    {
        $sql=match($this->type){'xenforo'=>'SELECT user_group_id AS primary_id,secondary_group_ids AS extra FROM '.$this->t('user').' WHERE user_id=?','mybb'=>'SELECT usergroup AS primary_id,additionalgroups AS extra FROM '.$this->t('users').' WHERE uid=?','smf'=>'SELECT id_group AS primary_id,additional_groups AS extra FROM '.$this->t('members').' WHERE id_member=?'};
        $row=$this->q($sql,[$user])->fetch(PDO::FETCH_ASSOC);if(!$row)return [];
        $groups=array_merge([(string)$row['primary_id']],explode(',',(string)$row['extra']));
        return array_values(array_unique(array_map('intval',array_filter($groups,fn($g)=>preg_match('/\A[0-9]+\z/D',trim($g))&&(int)$g>0))));
    }
    public function options(int $topic):array
    {
        if($this->type!=='mybb')return $this->all($this->pollOptions,[$topic]);
        $r=$this->q($this->pollOptions,[$topic])->fetch(PDO::FETCH_ASSOC);if(!$r)return [];
        $labels=explode('||~|~||',(string)$r['options']);
        if(count($labels)!==(int)$r['numoptions']||count($labels)>20)return [];
        $options=[];foreach($labels as $i=>$label)$options[]=['poll_option_id'=>$i+1,'poll_option_text'=>$label];return $options;
    }
    public function ballot(int $topic,int $user):array{return $this->q($this->pollBallots,[$topic,$user])->fetchAll(PDO::FETCH_COLUMN);}
    public function watchers(string $kind,int $id,int $after,int $limit):array
    {
        $sql=$this->relationQueries[$kind]??throw new \InvalidArgumentException('Unknown relation');$limit=max(1,min(500,$limit));
        return $this->q('SELECT DISTINCT user_id FROM ('.$sql.") src WHERE object_id=? AND user_id>? ORDER BY user_id LIMIT $limit",[$id,$after])->fetchAll(PDO::FETCH_COLUMN);
    }
}
