<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Migration;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Exhaustive, read-only Invision v8 acceptance against the exact frozen source snapshot.
 * This is not a migration/cutover command: it never activates accounts or publishes forums.
 */
final class InvisionAcceptance
{
    private const KEYS = [
        'groups'=>'group_id', 'users'=>'user_id', 'forums'=>'forum_id',
        'topics'=>'topic_id', 'posts'=>'post_id', 'attachments'=>'attach_id',
        'polls'=>'poll_id', 'invision_content'=>'post_id', 'inv_profiles'=>'member_id',
        'inv_reactions'=>'id', 'inv_follow'=>'follow_id', 'inv_conversations'=>'mt_id',
        'inv_messages'=>'msg_id', 'inv_pm_content'=>'msg_id',
    ];
    private const TARGETS = [
        'groups'=>['ce_roles','id'], 'users'=>['ce_users','id'],
        'forums'=>['ce_forum_boards','id'], 'topics'=>['ce_forum_threads','id'],
        'posts'=>['ce_forum_posts','id'], 'attachments'=>['ce_forum_attachments','id'],
        'polls'=>['ce_forum_polls','thread_id'], 'invision_content'=>['ce_forum_posts','id'],
        'inv_profiles'=>['ce_forum_member_profiles','user_id'],
        'inv_reactions'=>['ce_forum_posts','id'], 'inv_follow'=>['ce_users','id'],
        'inv_conversations'=>['ce_forum_conversations','id'],
        'inv_messages'=>['ce_forum_conversation_messages','id'],
        'inv_pm_content'=>['ce_forum_conversation_messages','id'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InvisionSource $source,
        private readonly ForumMigrationEngine $engine,
        private readonly ?string $files = null,
    ) {}

    public static function skipFingerprint(array $rows): string
    {
        return XenForoAcceptance::skipFingerprint($rows);
    }

    private static function one(PDO $pdo,string $sql,array $params=[]): ?array
    {
        $s=$pdo->prepare($sql);$s->execute($params);
        $row=$s->fetch(PDO::FETCH_ASSOC);$s->closeCursor();
        return $row===false?null:$row;
    }

    private function mapped(PDO $pdo,string $job,string $kind,int $old):?string
    {
        return self::one($pdo,"SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=? AND outcome='imported'",[$job,$kind,$old])['target_id']??null;
    }

    /** Check an actual, imported message against the immutable source, not just a local revision. */
    private function compareBody(PDO $pdo,string $job,string $kind,array $sourceRow,string $id,callable $fail):void
    {
        $private=$kind==='inv_messages';
        $original=(string)($sourceRow[$private?'msg_post':'post_text']??'');
        $row=self::one($pdo,'SELECT body,body_format,author_id,'.($private?'conversation_id':'thread_id').' AS parent_id FROM '.
            ($private?'ce_forum_conversation_messages':'ce_forum_posts').' WHERE id=?',[$id]);
        if($row===null){$fail('missing_message:'.$kind);return;}
        $sourceId=(int)$sourceRow[$private?'msg_id':'post_id'];
        $rewrite=$this->mapped($pdo,$job,$private?'inv_pm_content':'invision_content',$sourceId);
        $expectedFormat=$rewrite!==null?'bbcode':'plain';
        if($rewrite!==null){
            $resolver=function(string $type,int $old)use($pdo,$job,$private):?string{
                if($private && $type!=='users')return null;
                $target=$this->mapped($pdo,$job,$type,$old);
                if($target===null)return null;
                return match($type){
                    'users'=>'/forum/members/'.$target,
                    'topics'=>'/forum/threads/'.$target,
                    'attachments'=>'/api/v1/forum/attachments/'.$target.'/download',
                    'posts'=>($post=self::one($pdo,'SELECT thread_id,ordinal FROM ce_forum_posts WHERE id=?',[$target]))!==null
                        ?'/forum/threads/'.$post['thread_id'].'?page='.(1+intdiv(max(1,(int)$post['ordinal'])-1,25)).'#post-'.$target:null,
                    default=>null,
                };
            };
            $expected=InvisionContent::rich($original,$resolver,$this->source->contentOrigin());
        }else{
            $expected=InvisionContent::plain($original);
        }
        if($expected==='')$expected='[Empty imported message]';
        if($expected===null||$row['body']!==$expected||$row['body_format']!==$expectedFormat)
            $fail('source_body_mismatch:'.$kind.':'.$sourceId);
        $actor=$this->mapped($pdo,$job,'users',(int)$sourceRow[$private?'msg_author_id':'poster_id']);
        $parent=$this->mapped($pdo,$job,$private?'inv_conversations':'topics',(int)$sourceRow[$private?'msg_topic_id':'topic_id']);
        if($actor!==$row['author_id'] || $parent!==$row['parent_id'])
            $fail('source_message_relation_mismatch:'.$kind.':'.$sourceId);
        $rev=self::one($pdo,'SELECT body,body_format FROM '.($private?'ce_forum_conversation_revisions':'ce_forum_post_revisions').
            ' WHERE '.($private?'message_id':'post_id').'=? AND revision_number=1',[$id]);
        if($rev===null||$rev['body']!==$row['body']||$rev['body_format']!==$row['body_format'])
            $fail('source_message_revision_mismatch:'.$kind.':'.$sourceId);
    }

    public function check(string $jobId,?string $approvedSkips=null):array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$jobId)||$jobId!==$this->source->identity())
            throw new \InvalidArgumentException('Job does not match the frozen Invision source');
        if($approvedSkips!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$approvedSkips))
            throw new \InvalidArgumentException('Invalid approved skip SHA-256');
        $pdo=$this->db->connection();
        $job=self::one($pdo,'SELECT state,phase,plan_json,source_version FROM ce_forum_import_jobs WHERE id=?',[$jobId]);
        if($job===null)throw new \RuntimeException('Import job not found');
        $plan=json_decode((string)$job['plan_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($plan)||($plan['source_platform']??'')!=='invision'||($plan['pipeline_version']??null)!==8)
            throw new \RuntimeException('Invision v8 job required for full-source acceptance');
        $issues=[];$totals=[];$sourceSeen=[];$audited=0;$skipCount=0;
        $fail=static function(string $issue)use(&$issues):void {if(count($issues)<100)$issues[]=$issue;};
        if($job['state']!=='complete'||(int)$job['phase']!==15)$fail('job_not_complete');
        if($job['source_version']!==$this->source->version())$fail('source_version_changed');
        if(!$this->engine->matchesRecordedPlan($plan))$fail('source_snapshot_changed');
        if((int)($plan['attachments']['count']??0)>0&&$this->files===null)$fail('source_files_not_supplied');
        $integrity=ForumImportIntegrity::inspect($this->db,$jobId,true);
        foreach($integrity['violations'] as $issue)$fail('integrity:'.$issue);

        $hash=hash_init('sha256');$first=true;
        $skipped=$pdo->prepare("SELECT kind,source_id,reason FROM ce_forum_import_map WHERE job_id=? AND outcome='skipped' ORDER BY kind,source_id");
        $skipped->execute([$jobId]);
        while(($s=$skipped->fetch(PDO::FETCH_ASSOC))!==false){
            if((int)$s['source_id']<=0 || $s['reason']==='')$fail('unexplained_skip');
            if(!$first)hash_update($hash,"\n");
            hash_update($hash,$s['kind']."\0".(int)$s['source_id']."\0".$s['reason']);
            $skipCount++;$first=false;
        }
        $skipped->closeCursor();
        $skipDigest=hash_final($hash);
        if($skipCount>0 && ($approvedSkips===null || !hash_equals($skipDigest,$approvedSkips)))
            $fail('unapproved_skipped_records');
        if($skipCount===0 && $approvedSkips!==null && !hash_equals($skipDigest,$approvedSkips))
            $fail('skip_digest_mismatch');

        // Reject ledger kinds introduced by tampering or an unsupported pipeline.
        $statement=$pdo->prepare('SELECT DISTINCT kind FROM ce_forum_import_map WHERE job_id=?');
        $statement->execute([$jobId]);
        foreach($statement->fetchAll(PDO::FETCH_COLUMN) as $kind){
            if(!array_key_exists((string)$kind,self::KEYS))$fail('unknown_import_mapping_kind:'.$kind);
        }
        $statement->closeCursor();
        // One-to-one phases must not point multiple source IDs to one target object.
        foreach(['groups','users','forums','topics','posts','attachments','inv_conversations','inv_messages'] as $kind){
            $row=self::one($pdo,"SELECT target_id,COUNT(*) AS n FROM ce_forum_import_map WHERE job_id=? AND kind=? AND outcome='imported' GROUP BY target_id HAVING COUNT(*)>1 LIMIT 1",[$jobId,$kind]);
            if($row!==null)$fail('duplicate_import_target:'.$kind);
        }

        foreach(self::KEYS as $kind=>$key){
            $expected=(int)($plan[$kind]['count']??-1);
            if($expected<0){$fail('phase_missing:'.$kind);continue;}
            [$table,$column]=self::TARGETS[$kind];
            $counts=self::one($pdo,'SELECT COUNT(*) AS n FROM ce_forum_import_map WHERE job_id=? AND kind=?',[$jobId,$kind]);
            $total=(int)($counts['n']??0);
            $totals[$kind]=['expected'=>$expected,'mapped'=>$total];
            if($total!==$expected)$fail('mapping_count_mismatch:'.$kind);
            $cursor=0;$count=0;
            // Check each source ID, not only COUNT/MAX; missing and substituted rows must fail.
            do {
                $batch=match($kind){
                    'attachments','polls','invision_content'=>$this->source->contentBatch($kind,$cursor,(int)$plan[$kind]['high'],100),
                    'inv_profiles','inv_reactions','inv_follow','inv_conversations','inv_messages','inv_pm_content'=>$this->source->communityBatch($kind,$cursor,(int)$plan[$kind]['high'],100),
                    default=>$this->source->batch($kind,$cursor,(int)$plan[$kind]['high'],100,true),
                };
                foreach($batch as $source){
                    $id=(int)$source[$key];$count++;
                    $cursor=$kind==='forums'?(int)$source['left_id']:$id;
                    $map=self::one($pdo,'SELECT target_id,outcome,reason FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=?',[$jobId,$kind,$id]);
                    if($map===null){$fail('source_id_unmapped:'.$kind.':'.$id);continue;}
                    if($map['outcome']==='skipped'){
                        if($map['target_id']!==null || $map['reason']==='')$fail('invalid_skip:'.$kind.':'.$id);
                        continue;
                    }
                    if($map['outcome']!=='imported'||!is_string($map['target_id'])||$map['target_id']===''){
                        $fail('invalid_mapping:'.$kind.':'.$id);continue;
                    }
                    $target=self::one($pdo,"SELECT * FROM $table WHERE $column=?",[$map['target_id']]);
                    if($target===null){$fail('missing_imported_target:'.$kind.':'.$id);continue;}
                    if($kind==='groups' && $target['permissions']!=='[]')$fail('group_privilege_escalation:'.$id);
                    if($kind==='users'){
                        $email=strtolower(trim((string)$source['user_email']));
                        if($target['email']!==$email)$fail('source_user_email_mismatch:'.$id);
                    }
                    if($kind==='forums'){
                        $originalParent=(int)$source['parent_id'];
                        $expectedParent=$originalParent===0?null:$this->mapped($pdo,$jobId,'forums',$originalParent);
                        if($target['parent_id']!==$expectedParent || $target['visibility']!=='hidden'
                            || $target['state']!=='readonly')$fail('source_forum_tree_or_quarantine_mismatch:'.$id);
                    }
                    if($kind==='topics'){
                        $board=$this->mapped($pdo,$jobId,'forums',(int)$source['forum_id']);
                        $author=$this->mapped($pdo,$jobId,'users',(int)$source['topic_poster']);
                        if($target['board_id']!==$board || $target['author_id']!==$author)
                            $fail('source_topic_relation_mismatch:'.$id);
                    }
                    if($kind==='polls' && (int)$target['closed']!==1)
                        $fail('imported_poll_reopened:'.$id);
                    if($kind==='inv_reactions'){
                        $actor=$this->mapped($pdo,$jobId,'users',(int)$source['member_id']);
                        $reaction=$actor===null?null:self::one($pdo,
                            'SELECT reaction_type FROM ce_forum_reactions WHERE post_id=? AND user_id=?',[$map['target_id'],$actor]);
                        if(($reaction['reaction_type']??null)!=='like')$fail('source_reaction_missing_or_changed:'.$id);
                    }
                    if($kind==='inv_follow'){
                        $user=$this->mapped($pdo,$jobId,'users',(int)$source['follow_member_id']);
                        $app=strtolower((string)$source['follow_app']);
                        $area=strtolower((string)$source['follow_area']);
                        $followed=$this->mapped($pdo,$jobId,$app==='core'?'users':($area==='topic'?'topics':'forums'),(int)$source['follow_rel_id']);
                        $follow=match($app.':'.$area){
                            'core:member'=>$user!==null&&$followed!==null?self::one($pdo,
                                "SELECT user_id FROM ce_forum_member_relations WHERE user_id=? AND target_id=? AND kind='follow'",[$user,$followed]):null,
                            'forums:topic'=>$user!==null&&$followed!==null?self::one($pdo,
                                'SELECT user_id FROM ce_forum_watches WHERE user_id=? AND thread_id=?',[$user,$followed]):null,
                            'forums:forum'=>$user!==null&&$followed!==null?self::one($pdo,
                                'SELECT user_id FROM ce_forum_board_watches WHERE user_id=? AND board_id=?',[$user,$followed]):null,
                            default=>null,
                        };
                        if($follow===null)$fail('source_follow_missing:'.$id);
                    }
                    if($kind==='users' && ($target['status']!=='disabled'||$target['disabled_at']===null))$fail('user_prematurely_enabled:'.$id);
                    if(in_array($kind,['posts','inv_messages'],true))$this->compareBody($pdo,$jobId,$kind,$source,$map['target_id'],$fail);
                    if($kind==='inv_conversations'){
                        $recipients=$this->source->communityRecipients($id);
                        $expectedMembers=[];
                        foreach($recipients as $member){
                            if((int)$member['map_user_active']!==1||(int)$member['map_left_time']!==0||(int)($member['map_user_banned']??0)!==0){
                                $fail('inactive_source_recipient_imported:'.$id);continue;
                            }
                            $user=$this->mapped($pdo,$jobId,'users',(int)$member['map_user_id']);
                            if($user===null)$fail('unmapped_source_recipient:'.$id);
                            else $expectedMembers[]=$user;
                        }
                        $s=$pdo->prepare('SELECT user_id FROM ce_forum_conversation_participants WHERE conversation_id=? AND active=1 ORDER BY user_id');
                        $s->execute([$map['target_id']]);$actual=$s->fetchAll(PDO::FETCH_COLUMN);$s->closeCursor();
                        sort($expectedMembers,SORT_STRING);sort($actual,SORT_STRING);
                        if($actual!==$expectedMembers || $actual===[])$fail('private_recipient_mismatch:'.$id);
                    }
                    if($kind==='attachments'){
                        $filename=(string)($source['physical_filename']??'');
                        $filename=preg_replace('~^<fileStore\.core_Attachment>/~','',$filename)??'';
                        if($this->files===null || !preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D',$filename)
                            || in_array('..',explode('/',$filename),true) || in_array('.',explode('/',$filename),true)){
                            $fail('attachment_source_path_invalid:'.$id);
                        }else{
                            $root=realpath($this->files);$path=$root===false?'':$root.DIRECTORY_SEPARATOR.$filename;
                            $resolved=$path===''?false:realpath($path);
                            if($resolved===false||!str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)||!is_file($resolved)
                                ||is_link($path)||!hash_equals(strtolower((string)$target['sha256']),hash_file('sha256',$resolved)))
                                $fail('source_attachment_checksum_mismatch:'.$id);
                        }
                    }
                    $audited++;
                }
            }while(count($batch)===100);
            $sourceSeen[$kind]=$count;
            if($count!==$expected)$fail('source_row_count_mismatch:'.$kind);
        }
        return ['ok'=>$issues===[],'job'=>$jobId,'platform'=>'invision','pipeline_version'=>8,
            'complete'=>$job['state']==='complete','source_rows'=>$sourceSeen,'mapped_rows'=>$totals,
            'targets_audited'=>$audited,'files_verified'=>$integrity['files_verified'],
            'skipped'=>$skipCount,'skip_sha256'=>$skipDigest,'approved_skips'=>$skipCount===0||$approvedSkips===$skipDigest,
            'violations'=>$issues];
    }
}
