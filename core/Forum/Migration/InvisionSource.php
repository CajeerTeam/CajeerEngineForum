<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use PDO;

/**
 * Consistent, read-only Invision Community 4/5 snapshot adapter.
 * Version 6 retains its original six core phases for in-progress imports.
 * Version 7 adds post media and content; v8 appends privacy-preserving community data.
 */
final class InvisionSource implements ForumSource
{
    public const CORE_PHASES=['groups','users','forums','topics','posts','finalize'];
    public const CONTENT_PHASES=['attachments','polls','invision_content'];
    public const COMMUNITY_PHASES=['inv_profiles','inv_reactions','inv_follow','inv_conversations','inv_messages','inv_pm_content'];
    private ?string $sourceOrigin=null;
    private array $columns=[];
    private ?string $pollTable=null;
    private array $contentBounds=[];
    private ?string $contentError=null;
    private ?string $communityError=null;
    private array $communityBounds=[];
    private array $communityLayouts=[];
    private array $tree=[];
    private array $ledger=[];
    private string $prefix;
    private string $version;
    private string $fingerprint;
    private string $driver;

    public function __construct(private readonly PDO $pdo,?string $prefix,?string $assertVersion,private readonly string $snapshot,?string $files=null,?string $origin=null)
    {
        if($origin!==null && !XenForoContent::validOrigin($origin))
            throw new \InvalidArgumentException('Invalid Invision canonical source origin');
        $this->sourceOrigin=$origin;
        $discovery=new InvisionSourceDiscovery($pdo,$prefix,$assertVersion);
        $report=$discovery->plan($files);
        foreach($report['blockers'] as $blocker){
            // Files are not read by the v6 core pipeline; attachments are a later phase.
            if($blocker['code']!=='attachment_snapshot_required')
                throw new \RuntimeException('Invision core source blocked: '.$blocker['code']);
        }
        $this->prefix=$report['prefix'];
        $this->version=$report['version'];
        $this->fingerprint=$report['schema_sha256'];
        $this->driver=$report['database_driver'];
        foreach(['core_groups','core_members','forums_forums','forums_topics','forums_posts','core_attachments','core_attachments_map','core_polls','forums_polls','core_reputation_index','core_follow','core_message_topics','core_message_posts','core_message_topic_user_map','core_pfields_content'] as $table)
            $this->columns[$table]=$this->columnsOf($table);
        // These are mandatory for a reliable privacy-preserving import. A missing
        // publication flag must not be silently interpreted as public content.
        if(!in_array('approved',$this->columns['forums_topics'],true))
            throw new \RuntimeException('Invision topics require approved state');
        if(!in_array('queued',$this->columns['forums_posts'],true))
            throw new \RuntimeException('Invision posts require queued state');
        $this->tree=$this->orderedTree();
        $counts=$report['counts'];
        $this->ledger=['source_platform'=>'invision','pipeline_version'=>6,
            'source_schema_sha256'=>$this->fingerprint,
            'source_tree_sha256'=>hash('sha256',json_encode($this->tree,JSON_THROW_ON_ERROR)),
            'source_deferred'=>array_keys($report['optional_counts'])];
        foreach(['groups'=>'core_groups','users'=>'core_members','topics'=>'forums_topics','posts'=>'forums_posts','finalize'=>'forums_topics'] as $phase=>$table)
            $this->ledger[$phase]=$counts[$table];
        $this->ledger['forums']=['count'=>count($this->tree),'high'=>count($this->tree)];
        try{
            $this->contentBounds['attachments']=$this->optionalBounds('core_attachments','attach_id');
            // Both names occur in known IPS exports; accept only a verifiable layout.
            foreach(['core_polls','forums_polls','core_reputation_index','core_follow','core_message_topics','core_message_posts','core_message_topic_user_map','core_pfields_content'] as $table){
                $id=$table==='core_polls'?'pid':'poll_id';
                if($this->optionalBounds($table,$id)['count']>0){
                    if($this->pollTable!==null)throw new \RuntimeException('Ambiguous Invision poll tables');
                    $this->pollTable=$table;
                }
            }
            $this->contentBounds['polls']=$this->pollTable===null?['count'=>0,'high'=>0]:
                $this->optionalBounds($this->pollTable,$this->pollTable==='core_polls'?'pid':'poll_id');
            $this->contentBounds['invision_content']=$this->ledger['posts'];
            if($this->contentBounds['attachments']['count']>0)$this->validateAttachmentSchema();
            if($this->contentBounds['polls']['count']>0)$this->validatePollSchema();
        }catch(\RuntimeException $e){$this->contentError=$e->getMessage();}
        // Community data uses only source tables with verified, unambiguous keys.
        // v6/v7 jobs must not be invalidated by a newer community schema.
        try{$this->prepareCommunity();}
        catch(\RuntimeException $e){$this->communityError=$e->getMessage();}
        // Keyset scans must have exact, stable keys. All names here are code-owned.
        foreach(['groups','users','topics','posts'] as $phase)
            $this->batch($phase,0,$this->ledger[$phase]['high'],1);
    }

    public function platform(): string{return 'invision';}
    public function version(): string{return $this->version;}
    public function contentOrigin(): ?string{return $this->sourceOrigin;}
    public function identity(): string{return hash('sha256',"invision\0".$this->version."\0".$this->prefix."\0".$this->snapshot.($this->sourceOrigin===null?'':"\0origin:".$this->sourceOrigin));}
    public function unsupported(): array
    {
        return ['source credentials and ACL','existing account auto-merge','archived posts',
            'remote or unavailable file storage, unsupported attachment layouts',
            'unsupported source reputation types or unknown follow targets, private attachments without a verifiable mapping',
            'member suspensions and moderation privileges'];
    }
    public function plan(int $pipelineVersion=8): array
    {
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Invision snapshot transaction ended');
        if($pipelineVersion===6)return $this->ledger;
        if(!in_array($pipelineVersion,[7,8],true))throw new \InvalidArgumentException('Unsupported Invision pipeline');
        if($this->contentError!==null)throw new \RuntimeException($this->contentError);
        $plan=array_merge($this->ledger,['pipeline_version'=>$pipelineVersion,
            'source_content_schema_sha256'=>hash('sha256',json_encode([
                'attachments'=>$this->columns['core_attachments'],
                'attachment_map'=>$this->columns['core_attachments_map'],
                'core_polls'=>$this->columns['core_polls'],
                'forums_polls'=>$this->columns['forums_polls'],
                'phase_bounds'=>$this->contentBounds],JSON_THROW_ON_ERROR))],$this->contentBounds);
        if($pipelineVersion===7)return $plan;
        if($this->communityError!==null)throw new \RuntimeException($this->communityError);
        return array_merge($plan,['source_community_schema_sha256'=>hash('sha256',json_encode([
            'layouts'=>$this->communityLayouts,
            'columns'=>array_intersect_key($this->columns,array_fill_keys([
                'core_members','core_pfields_content','core_reputation_index','core_follow',
                'core_message_topics','core_message_posts','core_message_topic_user_map'],true)),
            'bounds'=>$this->communityBounds],JSON_THROW_ON_ERROR))],$this->communityBounds);
    }

    /** The v8 source is read-only; no target IDs or secrets are ever exposed here. */
    private function prepareCommunity():void
    {
        $this->communityBounds['inv_profiles']=$this->ledger['users'];
        $specs=[
            'inv_reactions'=>['core_reputation_index','id', ['id','member_id','type_id','rep_rating']],
            'inv_follow'=>['core_follow','follow_id',['follow_id','follow_app','follow_area','follow_rel_id','follow_member_id']],
            'inv_conversations'=>['core_message_topics','mt_id',['mt_id','mt_title','mt_starter_id']],
            'inv_messages'=>['core_message_posts','msg_id',['msg_id','msg_topic_id','msg_author_id','msg_post']],
        ];
        foreach($specs as $phase=>[$table,$pk,$required]){
            $cols=$this->columns[$table]??[];
            if($cols!==[] && array_diff($required,$cols)!==[]){
                $count=(int)$this->pdo->query('SELECT COUNT(*) FROM '.$this->prefix.$table)->fetchColumn();
                if($count>0)throw new \RuntimeException('Unsupported Invision community columns: '.$table);
            }
            $this->communityBounds[$phase]=$this->optionalBounds($table,$pk);
        }
        $this->communityBounds['inv_pm_content']=$this->communityBounds['inv_messages'];
        $messages=$this->communityBounds['inv_messages']['count'];
        $topics=$this->communityBounds['inv_conversations']['count'];
        if($messages>0 && $topics===0)throw new \RuntimeException('Private messages exist without conversation topics');
        $members=$this->columns['core_message_topic_user_map']??[];
        if($topics>0){
            foreach(['map_topic_id','map_user_id','map_user_active','map_left_time'] as $col)
                if(!in_array($col,$members,true))throw new \RuntimeException('Cannot prove Invision private conversation participant privacy');
            $q='SELECT COUNT(*) FROM '.$this->prefix.'core_message_topics t LEFT JOIN '.$this->prefix.'core_message_topic_user_map m ON m.map_topic_id=t.mt_id WHERE m.map_topic_id IS NULL';
            if((int)$this->pdo->query($q)->fetchColumn()>0)
                throw new \RuntimeException('Invision private conversation without participant mappings');
        }
        $this->communityLayouts['pm_date']=$this->choose('core_message_topics',['mt_date','mt_start_time','mt_time'],$topics,'private conversation date');
        $this->communityLayouts['msg_date']=$this->choose('core_message_posts',['msg_date','msg_timestamp','msg_time'],$messages,'private message date');
        // A source with ambiguous content-type metadata must not have its reputation
        // rows interpreted as post likes across arbitrary IPS applications.
        $rep=$this->columns['core_reputation_index']??[];
        if($this->communityBounds['inv_reactions']['count']>0 && !in_array('type',$rep,true))
            throw new \RuntimeException('Invision reputation entries need their content type');
        $this->communityLayouts['rep_app']=in_array('app',$rep,true)?'app':null;
        $this->communityLayouts['rep_type']=in_array('type',$rep,true)?'type':null;
        $this->communityLayouts['rep_reaction']=in_array('reaction',$rep,true)?'reaction':null;
        $this->communityLayouts['pm_body']='msg_post';
        $this->communityLayouts['profile_bio']=$this->choose('core_members',['pp_about_me','about_me'],0,'profile biography');
        $this->communityLayouts['profile_location']=$this->choose('core_members',['location'],0,'profile location');
        $this->communityLayouts['profile_website']=$this->choose('core_members',['website','homepage'],0,'profile website');
        $this->communityLayouts['profile_signature']=$this->choose('core_members',['signature'],0,'profile signature');
    }

    private function choose(string $table,array $alternatives,int $count,string $description):?string
    {
        foreach($alternatives as $name)if(in_array($name,$this->columns[$table]??[],true))return $name;
        if($count>0)throw new \RuntimeException('Invision '.$description.' column missing');
        return null;
    }

    /** Bounded keyset scans for the append-only v8 community pipeline. */
    public function communityBatch(string $phase,int $after,int $high,int $limit):array
    {
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Invision snapshot transaction ended');
        if(!in_array($phase,self::COMMUNITY_PHASES,true)||$after<0||$high<0||$limit<1||$limit>500)
            throw new \InvalidArgumentException('Invalid Invision community batch');
        if($phase==='inv_pm_content')$phase='inv_messages';
        $table=match($phase){
            'inv_profiles'=>'core_members','inv_reactions'=>'core_reputation_index',
            'inv_follow'=>'core_follow','inv_conversations'=>'core_message_topics','inv_messages'=>'core_message_posts'};
        $key=match($phase){
            'inv_profiles'=>'member_id','inv_reactions'=>'id','inv_follow'=>'follow_id',
            'inv_conversations'=>'mt_id','inv_messages'=>'msg_id'};
        if($phase!=='inv_profiles' && $this->communityBounds[$phase]['count']===0)return [];
        $q=$this->pdo->prepare('SELECT * FROM '.$this->prefix.$table.' WHERE '.$key.'>? AND '.$key.'<=? ORDER BY '.$key.' LIMIT '.$limit);
        $q->execute([$after,$high]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function communityHasMessages(int $topic):bool
    {
        if(!$this->pdo->inTransaction()||$topic<=0)throw new \RuntimeException('Invalid Invision conversation scan');
        $q=$this->pdo->prepare('SELECT msg_id FROM '.$this->prefix.'core_message_posts WHERE msg_topic_id=? LIMIT 1');
        $q->execute([$topic]);return $q->fetchColumn()!==false;
    }

    /** Exact snapshot recipients, never infer a conversation's members from its posts. */
    public function communityRecipients(int $topic):array
    {
        if(!$this->pdo->inTransaction()||$topic<=0)throw new \RuntimeException('Invalid Invision recipient scan');
        $sql='SELECT map_user_id,map_user_active,map_left_time'.(in_array('map_user_banned',$this->columns['core_message_topic_user_map']??[],true)?',map_user_banned':'').
            ' FROM '.$this->prefix.'core_message_topic_user_map WHERE map_topic_id=? ORDER BY map_user_id LIMIT 22';
        $q=$this->pdo->prepare($sql);$q->execute([$topic]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function batch(string $phase,int $cursor,int $high,int $limit,bool $raw=false): array
    {
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Invision snapshot transaction ended');
        if(!in_array($phase,self::CORE_PHASES,true)||$cursor<0||$high<0||$limit<1||$limit>500)
            throw new \InvalidArgumentException('Invalid Invision import batch');
        if($phase==='forums')return array_slice($this->tree,$cursor,min($limit,max(0,$high-$cursor)));
        $t=fn(string $name):string=>$this->prefix.$name;
        $sql=match($phase){
            'groups'=>'SELECT g_id AS group_id,g_title AS group_name,\'\' AS group_desc FROM '.$t('core_groups'),
            'users'=>'SELECT member_id AS user_id,0 AS user_type,name AS username,email AS user_email,joined AS user_regdate FROM '.$t('core_members'),
            'topics'=>'SELECT tid AS topic_id,forum_id,starter_id AS topic_poster,title AS topic_title,start_date AS topic_time,'.
                $this->col('forums_topics','last_post','start_date').' AS topic_last_post_time,'.
                $this->topicState().' AS topic_status,'.
                $this->col('forums_topics','pinned','0').' AS topic_type,approved AS topic_visibility FROM '.$t('forums_topics'),
            'posts'=>'SELECT p.pid AS post_id,p.topic_id,t.forum_id,p.author_id AS poster_id,'.
                $this->col('forums_posts','post','p.post_content','p.').' AS post_text,\'\' AS bbcode_uid,'.
                'p.post_date AS post_time,'.$this->col('forums_posts','edit_time','0','p.').' AS post_edit_time,'.
                'CASE WHEN p.queued=0 THEN 1 ELSE 0 END AS post_visibility FROM '.$t('forums_posts').' p JOIN '.$t('forums_topics').' t ON t.tid=p.topic_id',
            'finalize'=>'SELECT tid AS topic_id FROM '.$t('forums_topics'),
        };
        $key=match($phase){'groups'=>'group_id','users'=>'user_id','topics','finalize'=>'topic_id','posts'=>'post_id'};
        $stmt=$this->pdo->prepare('SELECT * FROM ('.$sql.") src WHERE $key>? AND $key<=? ORDER BY $key LIMIT ".$limit);
        $stmt->execute([$cursor,$high]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if($phase==='posts'&&!$raw){
            foreach($rows as &$row){
                $raw=$row['post_text'];
                $row['post_text']=$raw===null?null:InvisionContent::plain((string)$raw);
            }
            unset($row);
        }
        return $rows;
    }

    /** Row bounded source phases for v7. Never acquire a target DB handle here. */
    public function contentBatch(string $phase,int $cursor,int $high,int $limit):array
    {
        if(!$this->pdo->inTransaction())throw new \RuntimeException('Invision snapshot transaction ended');
        if($cursor<0||$high<0||$limit<1||$limit>500)throw new \InvalidArgumentException('Invalid Invision media batch');
        if($phase==='invision_content')return $this->batch('posts',$cursor,$high,min(20,$limit),true);
        if(!in_array($phase,['attachments','polls'],true))throw new \InvalidArgumentException('Unknown Invision content phase');
        if($phase==='attachments'){
            if($this->contentBounds['attachments']['count']===0)return [];
            $t=$this->prefix.'core_attachments';$m=$this->prefix.'core_attachments_map';
            $cols=$this->columns['core_attachments'];
            $mapped=in_array('attachment_id',$this->columns['core_attachments_map'],true)?'attachment_id':'attach_id';
            $where="x.$mapped=a.attach_id AND x.location IN ('forums_Post','forums_Forums_Post','forums_Posts')";
            $sql="SELECT a.attach_id, a.attach_member_id AS poster_id,a.attach_file AS real_filename,a.attach_location AS physical_filename,a.attach_filesize AS filesize,".
                (in_array('attach_date',$cols,true)?'a.attach_date':'0')." AS filetime, ".
                "(SELECT MIN(x.id1) FROM $m x WHERE $where) AS post_msg_id, ".
                "(SELECT COUNT(*) FROM $m x WHERE $where) AS map_count FROM $t a WHERE a.attach_id>? AND a.attach_id<=? ORDER BY a.attach_id LIMIT $limit";
            $q=$this->pdo->prepare($sql);
        }else{
            if($this->pollTable===null)return [];
            if($this->pollTable==='core_polls'){
                $sql='SELECT p.pid AS poll_id,t.tid AS topic_id,p.poll_question AS poll_question,p.choices AS choices FROM '.$this->prefix.'core_polls p LEFT JOIN '.$this->prefix.'forums_topics t ON t.poll_state=p.pid WHERE p.pid>? AND p.pid<=? ORDER BY p.pid LIMIT '.$limit;
            }else{
                $sql='SELECT poll_id,topic_id,question AS poll_question,choices FROM '.$this->prefix.'forums_polls WHERE poll_id>? AND poll_id<=? ORDER BY poll_id LIMIT '.$limit;
            }
            $q=$this->pdo->prepare($sql);
        }
        $q->execute([$cursor,$high]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function optionalBounds(string $table,string $key):array
    {
        if(($this->columns[$table]??[])===[])return ['count'=>0,'high'=>0];
        if(!in_array($key,$this->columns[$table],true)){
            $existing=(int)$this->pdo->query('SELECT COUNT(*) FROM '.$this->prefix.$table)->fetchColumn();
            if($existing>0)throw new \RuntimeException('Invision '.$table.' has unsupported primary key');
            return ['count'=>0,'high'=>0];
        }
        $row=$this->pdo->query('SELECT COUNT(*) AS n,COALESCE(MAX('.$key.'),0) AS high FROM '.$this->prefix.$table)->fetch(PDO::FETCH_ASSOC);
        return ['count'=>(int)$row['n'],'high'=>(int)$row['high']];
    }
    private function validateAttachmentSchema():void
    {
        $required=['attach_id','attach_member_id','attach_file','attach_location','attach_filesize'];
        if(array_diff($required,$this->columns['core_attachments'])!==[])
            throw new \RuntimeException('Invision attachment columns unsupported; require '.implode(',',$required));
        $map=$this->columns['core_attachments_map'];
        if(!in_array('id1',$map,true)||!in_array('location',$map,true)
            ||!in_array('attachment_id',$map,true)&&!in_array('attach_id',$map,true))
            throw new \RuntimeException('Invision post attachment map unavailable or incomplete');
    }
    private function validatePollSchema():void
    {
        $table=$this->pollTable;
        $required=$table==='core_polls'?['pid','poll_question','choices']:['poll_id','topic_id','question','choices'];
        if(array_diff($required,$this->columns[$table])!==[] || ($table==='core_polls' && !in_array('poll_state',$this->columns['forums_topics'],true)))
            throw new \RuntimeException('Unsupported Invision poll schema');
    }

    public function memberships(int $user): array
    {
        if($user<=0)return [];
        $fields='member_group_id';
        $also=in_array('mgroup_others',$this->columns['core_members'],true);
        if($also)$fields.=',mgroup_others';
        $q=$this->pdo->prepare('SELECT '.$fields.' FROM '.$this->prefix.'core_members WHERE member_id=?');
        $q->execute([$user]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)return [];
        $ids=[(int)$r['member_group_id']];
        if($also){
            if(strlen((string)$r['mgroup_others'])>8192)throw new \RuntimeException('Invision group membership exceeds safe size');
            foreach(explode(',',(string)$r['mgroup_others']) as $id){
                $id=trim($id);
                if($id!==''&&ctype_digit($id))$ids[]=(int)$id;
            }
        }
        $ids=array_values(array_unique(array_filter($ids,static fn(int $id):bool=>$id>0)));
        if(count($ids)>1000)throw new \RuntimeException('Invision member has too many groups');
        return $ids;
    }
    public function options(int $topic):array{return [];}
    public function watchers(string $kind,int $id,int $after,int $limit):array{return [];}
    public function ballot(int $topic,int $user):array{return [];}

    private function col(string $table,string $preferred,string $fallback,string $alias=''):string
    {return in_array($preferred,$this->columns[$table],true)?$alias.$preferred:$fallback;}
    private function topicState():string
    {
        return in_array('state',$this->columns['forums_topics'],true)
            ?"CASE WHEN state IN ('closed','archived') THEN 1 ELSE 0 END":'0';
    }
    private function columnsOf(string $table):array
    {
        $name=$this->prefix.$table;
        if($this->driver==='sqlite')$rows=$this->pdo->query('PRAGMA table_info("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
        else{
            $q=$this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$name]);return array_map('strtolower',$q->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_map('strtolower',array_column($rows,'name'));
    }
    /** Parent-first ordering produces the `left_id` checkpoint expected by the engine. */
    private function orderedTree():array
    {
        $rows=$this->pdo->query('SELECT * FROM '.$this->prefix.'forums_forums')->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>50000)throw new \RuntimeException('Invision tree exceeds 50000 forums');
        $byId=[];$children=[];
        foreach($rows as $row){
            $id=(int)$row['id'];$parent=(int)$row['parent_id'];
            if($id<=0||isset($byId[$id]))throw new \RuntimeException('Invalid Invision forum ID');
            $byId[$id]=$row;$children[$parent>0?$parent:0][]=$id;
        }
        foreach($children as &$ids){
            usort($ids,static fn(int $a,int $b):int=>
                [(int)($byId[$a]['position']??0),$a] <=> [(int)($byId[$b]['position']??0),$b]);
        }unset($ids);
        $result=[];$seen=[];$stack=[];
        foreach(array_reverse($children[0]??[]) as $id)$stack[]=$id;
        while($stack!==[]){
            $id=array_pop($stack);
            if(isset($seen[$id]))throw new \RuntimeException('Invision forum cycle');
            $seen[$id]=true;$r=$byId[$id];
            $canPost=in_array('sub_can_post',$this->columns['forums_forums'],true)?(int)$r['sub_can_post']:
                (in_array('is_cat',$this->columns['forums_forums'],true)&&((int)$r['is_cat'])===1?0:1);
            $result[]=['forum_id'=>$id,'parent_id'=>max(0,(int)$r['parent_id']),
                'left_id'=>count($result)+1,'forum_type'=>$canPost===0?0:1,
                'forum_name'=>(string)$r['name'],'forum_desc'=>(string)($r['description']??''),'forum_status'=>0];
            foreach(array_reverse($children[$id]??[]) as $child)$stack[]=$child;
        }
        if(count($result)!==count($rows))throw new \RuntimeException('Invision forum tree contains orphan/cyclic nodes');
        return $result;
    }
}
