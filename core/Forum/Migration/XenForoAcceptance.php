<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Migration;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Read-only, exhaustive post-import reconciliation. A passing result is evidence from the
 * actual source snapshot and target DB, not an assertion made by a release manifest.
 * Imported content remains quarantined until a separate operator ACL/cutover review.
 */
final class XenForoAcceptance
{
    private const TARGETS = [
        'groups'=>['ce_roles','id'], 'users'=>['ce_users','id'],
        'forums'=>['ce_forum_boards','id'], 'topics'=>['ce_forum_threads','id'],
        'posts'=>['ce_forum_posts','id'], 'attachments'=>['ce_forum_attachments','id'],
        'polls'=>['ce_forum_polls','thread_id'], 'votes'=>['ce_forum_polls','thread_id'],
        'watches'=>['ce_forum_threads','id'], 'board_watches'=>['ce_forum_boards','id'],
        'profiles'=>['ce_forum_member_profiles','user_id'], 'reactions'=>['ce_forum_posts','id'],
        'avatars'=>['ce_forum_profile_images','user_id'], 'follows'=>['ce_users','id'],
        'content_links'=>['ce_forum_posts','id'],
        'conversations'=>['ce_forum_conversations','id'],
        'conversation_messages'=>['ce_forum_conversation_messages','id'],
        'conversation_attachments'=>['ce_forum_attachments','id'],
        'conversation_content'=>['ce_forum_conversation_messages','id'],
    ];
    private const SOURCE_KEYS = [
        'groups'=>'group_id','users'=>'user_id','forums'=>'forum_id','topics'=>'topic_id',
        'posts'=>'post_id','attachments'=>'attach_id','polls'=>'topic_id','votes'=>'topic_id',
        'watches'=>'topic_id','board_watches'=>'forum_id','profiles'=>'profile_id',
        'reactions'=>'reaction_source_id','avatars'=>'avatar_id','follows'=>'follow_id',
        'content_links'=>'post_id','conversations'=>'conversation_source_id',
        'conversation_messages'=>'message_source_id','conversation_attachments'=>'attach_id',
        'conversation_content'=>'message_source_id',
    ];

    public function __construct(private readonly DatabaseManager $db, private readonly MultiForumSource $source, private readonly ForumMigrationEngine $engine, private readonly ?string $files=null, private readonly ?string $avatars=null) {}

    /** The skip hash must be reviewed/approved out-of-band. Do not waive rows individually
     * by reason, since new unexpected omissions would otherwise pass silently.
     */
    public static function skipFingerprint(array $rows): string
    {
        $items=[];
        foreach($rows as $r) {
            $kind=(string)($r['kind']??'');$source=(int)($r['source_id']??0);$reason=(string)($r['reason']??'');
            if($kind===''||$source<=0||$reason==='')throw new \InvalidArgumentException('Invalid skip ledger row');
            $items[]=['kind'=>$kind,'id'=>$source,'reason'=>$reason];
        }
        usort($items,static fn(array $a,array $b):int=>strcmp($a['kind'],$b['kind'])?:($a['id']<=>$b['id']));
        return hash('sha256',implode("\n",array_map(static fn(array $r):string=>$r['kind']."\0".$r['id']."\0".$r['reason'],$items)));
    }

    public function check(string $jobId,?string $approvedSkipDigest=null): array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$jobId))throw new \InvalidArgumentException('Invalid job ID');
        if($approvedSkipDigest!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$approvedSkipDigest))
            throw new \InvalidArgumentException('Expected skip SHA-256 must be lowercase hex');
        if($this->source->platform()!=='xenforo')throw new \InvalidArgumentException('XenForo source required');
        if($jobId!==$this->source->identity())throw new \RuntimeException('Import job does not match the selected source snapshot');
        $db=$this->db->connection();
        $row=$this->one($db,'SELECT state,phase,category_id,plan_json,source_version FROM ce_forum_import_jobs WHERE id=?',[$jobId]);
        if($row===null)throw new \RuntimeException('Import job not found');
        $plan=json_decode($row['plan_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($plan)||($plan['source_platform']??'')!=='xenforo'||($plan['pipeline_version']??null)!==5)
            throw new \RuntimeException('A completed XenForo pipeline v5 import is required');
        $errors=[];$counts=[];$audited=0;$skipCount=0;$skipHash=hash_init('sha256');$firstSkip=true;
        $block=static function(string $reason)use(&$errors):void {if(count($errors)<40)$errors[]=$reason;};
        if($row['state']!=='complete'||(int)$row['phase']!==20)$block('job_not_complete');
        if($row['source_version']!==$this->source->version())$block('source_version_mismatch');
        if(!$this->engine->matchesRecordedPlan($plan))$block('source_snapshot_drift');
        if((($plan['attachments']['count']??0)>0||($plan['conversation_attachments']['count']??0)>0) && $this->files===null)$block('attachment_snapshot_not_supplied');
        if(($plan['avatars']['count']??0)>0 && $this->avatars===null)$block('avatar_snapshot_not_supplied');
        $category=$this->one($db,'SELECT visibility FROM ce_forum_categories WHERE id=?',[$row['category_id']]);
        if($category===null||$category['visibility']!=='hidden')$block('import_category_not_hidden');
        $boards=$this->one($db,'SELECT COUNT(*) AS n FROM ce_forum_boards WHERE category_id=? AND (visibility<>? OR state<>?)',[$row['category_id'],'hidden','readonly']);
        if((int)($boards['n']??0)!==0)$block('import_board_not_hidden_readonly');
        $kinds=self::TARGETS;ksort($kinds,SORT_STRING);
        foreach($kinds as $kind=>[$table,$field]) {
            $total=$this->one($db,"SELECT COUNT(*) AS n, SUM(CASE WHEN outcome='imported' THEN 1 ELSE 0 END) AS imported, SUM(CASE WHEN outcome='skipped' THEN 1 ELSE 0 END) AS skipped FROM ce_forum_import_map WHERE job_id=? AND kind=?",[$jobId,$kind]);
            $expected=(int)($plan[$kind]['count']??-1);
            $counts[$kind]=['source'=>$expected,'mapped'=>(int)$total['n'],'imported'=>(int)$total['imported'],'skipped'=>(int)$total['skipped']];
            if($expected<0||(int)$total['n']!==$expected)$block('mapping_count_mismatch:'.$kind);
            $cursor=0;$seen=0;
            // Index-backed keyset; no OFFSET and no unbounded fetchAll into PHP memory.
            do {
                $query=$db->prepare('SELECT source_id,target_id,outcome,reason FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id>? ORDER BY source_id LIMIT 100');
                $query->execute([$jobId,$kind,$cursor]);$page=$query->fetchAll(PDO::FETCH_ASSOC);
                foreach($page as $map){
                    $cursor=(int)$map['source_id'];$seen++;
                    if($cursor<=0){$block('invalid_source_id:'.$kind);continue;}
                    if($map['outcome']==='skipped'){
                        if($map['target_id']!==null||!is_string($map['reason'])||$map['reason']==='')$block('invalid_skip:'.$kind);
                        else {
                            if(!$firstSkip)hash_update($skipHash,"\n");
                            hash_update($skipHash,$kind."\0".$cursor."\0".$map['reason']);
                            $firstSkip=false;$skipCount++;
                        }
                        continue;
                    }
                    if($map['outcome']!=='imported'||!is_string($map['target_id'])||$map['target_id']===''){$block('invalid_import_mapping:'.$kind);continue;}
                    $target=$map['target_id'];
                    $record=$this->one($db,"SELECT * FROM $table WHERE $field=?".($kind==='avatars'?" AND kind='avatar'":''),[$target]);
                    if($record===null){$block('missing_target:'.$kind.':'.$cursor);continue;}
                    if($kind==='users' && $record['status']!=='disabled')$block('imported_user_not_disabled:'.$cursor);
                    if($kind==='avatars' && (!is_string($record['image_data']) || \CajeerEngine\Forum\Security\ProfileImage::decodeStored('avatar',$record['image_data'])===null))$block('corrupt_avatar:'.$cursor);
                    if($kind==='groups' && ($record['permissions']??null)!=='[]')$block('imported_group_has_permissions:'.$cursor);
                    if($kind==='forums' && ($record['category_id']!==$row['category_id']||$record['visibility']!=='hidden'||$record['state']!=='readonly'))$block('imported_board_not_quarantined:'.$cursor);
                    if(in_array($kind,['topics','posts'],true)) {
                        $board=$kind==='topics' ? $record['board_id'] : ($this->one($db,'SELECT board_id FROM ce_forum_threads WHERE id=?',[$record['thread_id']])['board_id']??null);
                        $scope=$board===null?null:$this->one($db,'SELECT category_id,visibility,state FROM ce_forum_boards WHERE id=?',[$board]);
                        if($scope===null||$scope['category_id']!==$row['category_id']||$scope['visibility']!=='hidden'||$scope['state']!=='readonly')$block('imported_content_moved_out_of_quarantine:'.$kind.':'.$cursor);
                    }
                    if(in_array($kind,['attachments','conversation_attachments'],true)){
                        $payload=base64_decode((string)$record['file_data'],true);
                        if($payload===false || strlen($payload)!==(int)$record['size_bytes'] || !hash_equals((string)$record['sha256'],hash('sha256',$payload)))
                            $block('corrupt_attachment:'.$kind.':'.$cursor);
                        if($kind==='attachments' && ($record['post_id']===null||$record['message_id']!==null))$block('public_attachment_link_error:'.$cursor);
                        if($kind==='conversation_attachments' && ($record['message_id']===null||$record['post_id']!==null))$block('private_attachment_link_error:'.$cursor);
                    }
                    if($kind==='conversations'){
                        $expectedUsers=[];
                        foreach($this->source->mediaRecipients($cursor) as $recipient){
                            if($recipient['recipient_state']!=='active')continue;
                            $mapped=$this->one($db,"SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind='users' AND source_id=? AND outcome='imported'",[$jobId,$recipient['user_id']]);
                            if($mapped!==null)$expectedUsers[]=$mapped['target_id'];
                        }
                        $inactive=$this->one($db,'SELECT COUNT(*) AS n FROM ce_forum_conversation_participants WHERE conversation_id=? AND active<>1',[$target]);
                        if((int)$inactive['n']>0)$block('inactive_private_participant:'.$cursor);
                        $got=$db->prepare('SELECT user_id FROM ce_forum_conversation_participants WHERE conversation_id=? ORDER BY user_id');
                        $got->execute([$target]);$users=$got->fetchAll(PDO::FETCH_COLUMN);
                         $expectedUsers=array_values(array_unique($expectedUsers));
                        sort($expectedUsers,SORT_STRING);sort($users,SORT_STRING);
                        if($users!==$expectedUsers || $users===[])$block('private_audience_mismatch:'.$cursor);
                    }
                    if(in_array($kind,['posts','conversation_messages'],true)){
                        $revisionTable=$kind==='posts'?'ce_forum_post_revisions':'ce_forum_conversation_revisions';
                        $revisionId=$kind==='posts'?'post_id':'message_id';
                        $rev=$this->one($db,"SELECT body,body_format FROM $revisionTable WHERE $revisionId=? AND revision_number=1",[$target]);
                        if($rev===null||$rev['body']!==$record['body']||$rev['body_format']!==$record['body_format'])$block('message_revision_mismatch:'.$kind.':'.$cursor);
                    }
                    $audited++;
                }
            }while(count($page)===100);
            // Check actual source IDs, not merely COUNT/MAX, which can conceal replacement rows.
            // This also prevents a forged ID map of the same size from passing reconciliation.
            $cursor=0;$offset=0;$sourceSeen=0;
            do {
                $batch=match($kind){
                    'profiles','reactions'=>$this->source->additionalBatch($kind,$cursor,(int)$plan[$kind]['high'],100),
                    'avatars','follows'=>$this->source->communityBatch($kind,$cursor,(int)$plan[$kind]['high'],100),
                    'conversations','conversation_messages','conversation_attachments','conversation_content'=>$this->source->mediaBatch($kind,$cursor,(int)$plan[$kind]['high'],100),
                    'content_links'=>$this->source->batch('posts',$cursor,(int)$plan[$kind]['high'],100),
                    default=>$this->source->batch($kind,$cursor,(int)$plan[$kind]['high'],100),
                };
                foreach($batch as $sourceRow){
                    $sourceId=(int)$sourceRow[self::SOURCE_KEYS[$kind]];$sourceSeen++;
                    $link=$this->one($db,'SELECT outcome FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=?',[$jobId,$kind,$sourceId]);
                    if($link===null)$block('missing_source_mapping:'.$kind.':'.$sourceId);
                    if(in_array($kind,['posts','conversation_messages'],true) && ($link['outcome']??null)==='imported') {
                        $mapped=$this->mappedId($db,$jobId,$kind,$sourceId);
                        $table=$kind==='posts'?'ce_forum_posts':'ce_forum_conversation_messages';
                        $stored=$mapped===null?null:$this->one($db,"SELECT body,body_format,author_id,".($kind==='posts'?'thread_id':'conversation_id')." FROM $table WHERE id=?",[$mapped]);
                        if($stored===null){$block('source_content_target_missing:'.$kind.':'.$sourceId);}
                        else {
                            $srcText=$kind==='posts'?$sourceRow['post_text']:$sourceRow['body'];
                            $rewritten=$this->one($db,'SELECT outcome FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=?',[$jobId,$kind==='posts'?'content_links':'conversation_content',$sourceId]);
                            $format=($rewritten['outcome']??null)==='imported'?'bbcode':'plain';
                            $expectedBody=$this->expectedBody($db,$jobId,$kind,is_string($srcText)?$srcText:'',$format);
                            if($format!==$stored['body_format']||$expectedBody!==$stored['body'])$block('source_content_mismatch:'.$kind.':'.$sourceId);
                            $author=$this->mappedId($db,$jobId,'users',(int)$sourceRow[$kind==='posts'?'poster_id':'author_source_id']);
                            if($stored['author_id']!==$author)$block('source_author_mismatch:'.$kind.':'.$sourceId);
                            $parent=$this->mappedId($db,$jobId,$kind==='posts'?'topics':'conversations',(int)$sourceRow[$kind==='posts'?'topic_id':'conversation_id']);
                            if($stored[$kind==='posts'?'thread_id':'conversation_id']!==$parent)$block('source_parent_mismatch:'.$kind.':'.$sourceId);
                        }
                    }
                    if($kind==='reactions' && ($link['outcome']??null)==='imported') {
                        $imported=$this->one($db,'SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=?',[$jobId,'reactions',$sourceId]);
                        $actor=$this->one($db,"SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind='users' AND source_id=? AND outcome='imported'",[$jobId,(int)$sourceRow['user_id']]);
                        $reaction=($imported!==null&&$actor!==null)?$this->one($db,'SELECT reaction_type FROM ce_forum_reactions WHERE post_id=? AND user_id=?',[$imported['target_id'],$actor['target_id']]):null;
                        $expectedType=match((int)$sourceRow['source_type']){1=>'like',2=>'celebrate',default=>null};
                        if($expectedType===null||$reaction===null||$reaction['reaction_type']!==$expectedType)$block('missing_or_modified_reaction:'.$sourceId);
                    }
                    if($kind==='follows' && ($link['outcome']??null)==='imported') {
                        $follower=$this->one($db,"SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind='users' AND source_id=? AND outcome='imported'",[$jobId,$sourceId]);
                        $after=0;
                        do {
                            $followed=$this->source->communityFollowing($sourceId,$after,500);
                            foreach($followed as $followee) {
                                $after=(int)$followee;
                                $mapped=$this->one($db,"SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind='users' AND source_id=? AND outcome='imported'",[$jobId,$after]);
                                if($mapped===null||$follower===null||$mapped['target_id']===$follower['target_id'])continue;
                                $rel=$this->one($db,"SELECT user_id FROM ce_forum_member_relations WHERE user_id=? AND target_id=? AND kind='follow'",[$follower['target_id'],$mapped['target_id']]);
                                if($rel===null)$block('missing_follow_relation:'.$sourceId.':'.$after);
                            }
                        }while(count($followed)===500);
                    }
                    if($kind==='avatars' && ($link['outcome']??null)==='imported') {
                        $shard=intdiv($sourceId,1000);
                        $original=$this->frozenPath($this->avatars,'o/'.$shard.'/'.$sourceId.'.jpg')
                            ??$this->frozenPath($this->avatars,'l/'.$shard.'/'.$sourceId.'.jpg');
                        if($original===null||($bytes=filesize($original))===false||$bytes<1||$bytes>2097152)
                            $block('source_avatar_missing_or_unsafe:'.$sourceId);
                    }
                    if(in_array($kind,['attachments','conversation_attachments'],true) && ($link['outcome']??null)==='imported') {
                        $target=$this->one($db,'SELECT sha256 FROM ce_forum_attachments WHERE id=(SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=?)',[$jobId,$kind,$sourceId]);
                        $path=$this->frozenPath($this->files,(string)($sourceRow['physical_filename']??''));
                        if($path===null||$target===null||!hash_equals((string)$target['sha256'],(string)hash_file('sha256',$path)))
                            $block('source_attachment_mismatch:'.$kind.':'.$sourceId);
                    }
                }
                if($kind==='forums'){$offset+=count($batch);$cursor=$offset;}
                elseif($batch!==[])$cursor=(int)$batch[array_key_last($batch)][match($kind){'forums'=>'left_id',default=>match($kind){'content_links'=>'post_id','conversation_content'=>'message_source_id',default=>self::SOURCE_KEYS[$kind]}}];
            }while(count($batch)===100);
            if($sourceSeen!==$expected)$block('source_row_count_drift:'.$kind);
        }
        // Audit no target over-grants for newly imported users, even if the role mappings were altered.
        $extraRoles=$db->prepare("SELECT m.source_id FROM ce_forum_import_map m JOIN ce_user_roles ur ON ur.user_id=".($this->db->driver()==='pgsql'?'CAST(m.target_id AS UUID)':'m.target_id')." JOIN ce_roles r ON r.id=ur.role_id WHERE m.job_id=? AND m.kind='users' AND m.outcome='imported' AND r.handle<>'forum-member' AND NOT EXISTS (SELECT 1 FROM ce_forum_import_map g WHERE g.job_id=m.job_id AND g.kind='groups' AND g.outcome='imported' AND g.target_id=".($this->db->driver()==='pgsql'?'CAST(r.id AS VARCHAR(36))':'r.id').") LIMIT 1");
        $extraRoles->execute([$jobId]);if($extraRoles->fetchColumn()!==false)$block('imported_user_elevated_role');
        $digest=hash_final($skipHash);
        if($skipCount>0 && ($approvedSkipDigest===null || !hash_equals($digest,$approvedSkipDigest)))$block('skipped_rows_not_approved');
        if(($counts['users']['imported']??0)<1||($counts['posts']['imported']??0)<1)$block('empty_migration_not_accepted');
        return ['ok'=>$errors===[],'job'=>$jobId,'platform'=>'xenforo','pipeline_version'=>5,'source_version'=>$row['source_version'],
            'checks'=>['source_rows_reconciled'=>$errors===[],'target_rows_examined'=>$audited,'skip_rows'=>$skipCount,
                       'skip_sha256'=>$digest,'approved_skips'=>$skipCount===0||($approvedSkipDigest!==null && hash_equals($digest,$approvedSkipDigest)),
                       'tree_quarantined'=>$category!==null&&$category['visibility']==='hidden'],
            'counts'=>$counts,'blockers'=>$errors];
    }

    private function mappedId(PDO $pdo,string $job,string $kind,int $sourceId): ?string
    {
        $row=$this->one($pdo,"SELECT target_id FROM ce_forum_import_map WHERE job_id=? AND kind=? AND source_id=? AND outcome='imported'",[$job,$kind,$sourceId]);
        return $row['target_id']??null;
    }

    private function expectedBody(PDO $pdo,string $job,string $kind,string $raw,string $format): string
    {
        if($format==='plain') {
            $body=str_replace("\0",'', $raw);
            return trim($body)===''?'[Empty imported message]':$body;
        }
        $resolver=function(string $type,int $original)use($pdo,$job,$kind):?string {
            $lookup=$type;
            if($kind==='conversation_messages') {
                if(!in_array($type,['users','attachments','conversations'],true))return null;
                if($type==='attachments')$lookup='conversation_attachments';
            }elseif(!in_array($type,['users','topics','forums','attachments','posts'],true))return null;
            $target=$this->mappedId($pdo,$job,$lookup,$original);
            if($target===null)return null;
            return match($type){
                'users'=>'/forum/members/'.$target,
                'forums'=>'/forum/boards/'.$target,
                'topics'=>'/forum/threads/'.$target,
                'conversations'=>'/forum/conversations/'.$target,
                'attachments'=>'/api/v1/forum/attachments/'.$target.'/download',
                'posts'=>($post=$this->one($pdo,'SELECT thread_id,ordinal FROM ce_forum_posts WHERE id=?',[$target]))!==null
                    ?'/forum/threads/'.$post['thread_id'].'?page='.(1+intdiv(max(1,(int)$post['ordinal'])-1,25)).'#post-'.$target:null,
                default=>null,
            };
        };
        return XenForoContent::convert($raw,$resolver,$this->source->contentOrigin());
    }

    private function frozenPath(?string $directory,string $relative): ?string
    {
        if($directory===null||!preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D',$relative))return null;
        if(in_array('..',explode('/',$relative),true)||in_array('.',explode('/',$relative),true))return null;
        $root=realpath($directory);
        if($root===false)return null;
        $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        if(is_link($path)||!is_file($path))return null;
        $real=realpath($path);
        return $real!==false&&str_starts_with($real,$root.DIRECTORY_SEPARATOR)?$real:null;
    }

    private function one(PDO $pdo,string $sql,array $params): ?array
    {
        $s=$pdo->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;
    }
}
