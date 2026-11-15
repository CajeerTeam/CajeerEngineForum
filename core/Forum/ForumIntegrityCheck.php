<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Database\DatabaseManager;

/** Offline release/installation gate. Does not mutate data or expose content. */
final readonly class ForumIntegrityCheck
{
    public function __construct(private DatabaseManager $db) {}

    /** @return array{ok:bool,violations:array<string,int>} */
    public function check(): array
    {
        return $this->db->readSnapshot(function(\PDO $pdo): array {
            // Run only after schema verification. These checks may scan the discussion corpus,
            // so deliberately keep them out of the per-request readiness endpoint.
            $queries=[
                'invalid_spam_lock'=> 'SELECT CASE WHEN COUNT(*)=1 AND MIN(id)=1 THEN 0 ELSE 1 END FROM ce_forum_spam_lock',
                'invalid_spam_event'=>"SELECT COUNT(*) FROM ce_forum_spam_events WHERE amount<=0 OR (action='register' AND user_id IS NOT NULL) OR (action<>'register' AND (user_id IS NULL OR subject<>CAST(user_id AS CHAR(36)))) OR action NOT IN ('register','thread','reply','edit','private','report','upload','upload_bytes','reaction','vote') OR (fingerprint IS NOT NULL AND action NOT IN ('thread','reply')) OR (reason IS NOT NULL AND (action NOT IN ('thread','reply') OR reason NOT IN ('duplicate','links','mentions')))",
                'invalid_sanction_state'=> "SELECT COUNT(*) FROM ce_forum_sanctions WHERE (ends_at IS NOT NULL AND ends_at<=starts_at) OR (revoked_at IS NOT NULL AND (revoked_at<starts_at OR revoke_reason IS NULL)) OR (revoked_at IS NULL AND (revoked_by IS NOT NULL OR revoke_reason IS NOT NULL)) OR (kind='warning' AND points<1) OR (kind<>'warning' AND points<>0)",
                'invalid_derived_sanction'=> "SELECT COUNT(*) FROM ce_forum_sanctions s JOIN ce_forum_sanctions w ON w.id=s.source_warning_id WHERE w.kind<>'warning' OR s.user_id<>w.user_id OR s.board_id IS NOT NULL OR w.board_id IS NOT NULL OR s.kind NOT IN ('posting','suspension') OR (w.revoked_at IS NOT NULL AND s.revoked_at IS NULL)",
                'invalid_appeal_state'=> "SELECT COUNT(*) FROM ce_forum_appeals a JOIN ce_forum_sanctions s ON s.id=a.sanction_id WHERE (a.status='pending' AND (a.decided_at IS NOT NULL OR a.decided_by IS NOT NULL OR a.decision IS NOT NULL)) OR (a.status<>'pending' AND (a.decided_at IS NULL OR a.decision IS NULL OR a.decided_at<a.created_at)) OR (a.status='accepted' AND s.revoked_at IS NULL)",
                'invalid_review_files'=> "SELECT COUNT(*) FROM ce_forum_review_files h JOIN ce_forum_review_submissions s ON s.id=h.submission_id JOIN ce_forum_attachments a ON a.id=h.attachment_id WHERE s.status<>'pending' OR a.owner_id<>s.author_id OR a.post_id IS NOT NULL OR a.message_id IS NOT NULL",
                'invalid_review_state'=> "SELECT COUNT(*) FROM ce_forum_review_submissions WHERE (status='pending' AND (result_id IS NOT NULL OR decided_by IS NOT NULL OR decision IS NOT NULL)) OR (status<>'pending' AND decided_by IS NULL) OR (status='approved' AND result_id IS NULL) OR (status<>'approved' AND result_id IS NOT NULL) OR updated_at<created_at",
                'invalid_report_snapshot'=> "SELECT COUNT(*) FROM ce_forum_reports WHERE (snapshot_body IS NULL AND snapshot_format IS NOT NULL) OR (snapshot_body IS NOT NULL AND (snapshot_format IS NULL OR snapshot_format NOT IN ('plain','markdown','bbcode')))",
                'invalid_post_revision'=> 'SELECT COUNT(*) FROM ce_forum_posts p WHERE (p.revision_number<>(SELECT COUNT(*) FROM ce_forum_post_revisions r WHERE r.post_id=p.id) OR p.revision_number<>(SELECT MAX(r.revision_number) FROM ce_forum_post_revisions r WHERE r.post_id=p.id) OR NOT EXISTS (SELECT 1 FROM ce_forum_post_revisions r WHERE r.post_id=p.id AND r.revision_number=p.revision_number AND r.body=p.body AND r.body_format=p.body_format))',
                'invalid_message_revision'=> 'SELECT COUNT(*) FROM ce_forum_conversation_messages p WHERE (p.revision_number<>(SELECT COUNT(*) FROM ce_forum_conversation_revisions r WHERE r.message_id=p.id) OR p.revision_number<>(SELECT MAX(r.revision_number) FROM ce_forum_conversation_revisions r WHERE r.message_id=p.id) OR NOT EXISTS (SELECT 1 FROM ce_forum_conversation_revisions r WHERE r.message_id=p.id AND r.revision_number=p.revision_number AND r.body=p.body AND r.body_format=p.body_format))',
                'invalid_draft_expiry'=> 'SELECT COUNT(*) FROM ce_forum_drafts WHERE expires_at<=updated_at OR revision_number<1',
                'invalid_media_variant_dimensions'=> "SELECT COUNT(*) FROM ce_forum_media_variants WHERE (variant='thumbnail' AND (width>240 OR height>240)) OR (variant='medium' AND (width>960 OR height>960))",
                'invalid_poll_options'=> 'SELECT COUNT(*) FROM ce_forum_polls p WHERE (SELECT COUNT(*) FROM ce_forum_poll_options o WHERE o.thread_id=p.thread_id)<2 OR (SELECT COUNT(*) FROM ce_forum_poll_options o WHERE o.thread_id=p.thread_id)>20 OR (SELECT COUNT(*) FROM ce_forum_poll_options o WHERE o.thread_id=p.thread_id)<p.max_choices',
                'invalid_poll_votes'=> 'SELECT COUNT(*) FROM (SELECT v.thread_id,v.user_id FROM ce_forum_poll_votes v JOIN ce_forum_polls p ON p.thread_id=v.thread_id GROUP BY v.thread_id,v.user_id,p.max_choices HAVING COUNT(*)>p.max_choices) AS excess_votes',
                'invalid_question_type'=> 'SELECT COUNT(*) FROM ce_forum_questions q JOIN ce_forum_polls p ON p.thread_id=q.thread_id',
                'invalid_accepted_answer'=> 'SELECT COUNT(*) FROM ce_forum_questions q JOIN ce_forum_posts p ON p.id=q.accepted_post_id WHERE p.thread_id<>q.thread_id OR p.ordinal=(SELECT MIN(p2.ordinal) FROM ce_forum_posts p2 WHERE p2.thread_id=q.thread_id)',
                'invalid_answer_vote'=> 'SELECT COUNT(*) FROM ce_forum_answer_votes v JOIN ce_forum_posts p ON p.id=v.post_id LEFT JOIN ce_forum_questions q ON q.thread_id=p.thread_id WHERE q.thread_id IS NULL OR p.author_id=v.user_id OR p.ordinal=(SELECT MIN(p2.ordinal) FROM ce_forum_posts p2 WHERE p2.thread_id=p.thread_id)',
                'invalid_answer_notification'=> 'SELECT COUNT(*) FROM ce_forum_answer_notifications n JOIN ce_forum_posts p ON p.id=n.post_id LEFT JOIN ce_forum_questions q ON q.thread_id=p.thread_id WHERE q.thread_id IS NULL OR q.accepted_post_id IS NULL OR q.accepted_post_id<>n.post_id OR p.author_id IS NULL OR p.author_id<>n.recipient_id',
                'invalid_media_state'=> "SELECT COUNT(*) FROM ce_forum_media_state s JOIN ce_forum_attachments a ON a.id=s.attachment_id WHERE a.mime NOT IN ('image/jpeg','image/png','image/webp') OR (s.state='ready' AND (SELECT COUNT(*) FROM ce_forum_media_variants v WHERE v.attachment_id=s.attachment_id)<>3)",
                'missing_media_state'=> "SELECT COUNT(*) FROM ce_forum_attachments a WHERE a.mime IN ('image/jpeg','image/png','image/webp') AND NOT EXISTS (SELECT 1 FROM ce_forum_media_state s WHERE s.attachment_id=a.id)",
                'oversized_post_attachments'=> 'SELECT COUNT(*) FROM (SELECT post_id FROM ce_forum_attachments WHERE post_id IS NOT NULL GROUP BY post_id HAVING COUNT(*)>10 OR SUM(size_bytes)>52428800) AS oversized_posts',
                'oversized_message_attachments'=> 'SELECT COUNT(*) FROM (SELECT message_id FROM ce_forum_attachments WHERE message_id IS NOT NULL GROUP BY message_id HAVING COUNT(*)>10 OR SUM(size_bytes)>52428800) AS oversized_messages',
                'invalid_attachment_state'=> 'SELECT COUNT(*) FROM ce_forum_attachments WHERE (post_id IS NOT NULL AND message_id IS NOT NULL) OR (post_id IS NULL AND message_id IS NULL AND expires_at<=created_at) OR ((post_id IS NOT NULL OR message_id IS NOT NULL) AND expires_at<>0)',
                'invalid_post_format'=> "SELECT COUNT(*) FROM ce_forum_posts WHERE body_format NOT IN ('plain','markdown','bbcode')",
                'invalid_conversation_format'=> "SELECT COUNT(*) FROM ce_forum_conversation_messages WHERE body_format NOT IN ('plain','markdown','bbcode')",
                'invalid_notification_recipient'=> 'SELECT COUNT(*) FROM ce_forum_notifications WHERE actor_id=recipient_id',
                'invalid_notification_state'=> "SELECT COUNT(*) FROM ce_forum_notifications WHERE (state=0 AND is_read<>0) OR (subscription_kind IS NOT NULL AND kind<>'reply') OR (kind='moderation' AND moderation_action IS NULL) OR (kind<>'moderation' AND moderation_action IS NOT NULL)",
                'orphan_reaction_notification'=> "SELECT COUNT(*) FROM ce_forum_notifications n WHERE n.kind='reaction' AND NOT EXISTS (SELECT 1 FROM ce_forum_reactions r WHERE r.post_id=n.post_id AND r.user_id=n.actor_id)",
                'invalid_conversation_limits'=> "SELECT COUNT(*) FROM ce_forum_conversation_limits WHERE action NOT IN ('create','invite','send','edit','report') OR hits<1 OR window_start<0",
                'invalid_reaction_type'=> "SELECT COUNT(*) FROM ce_forum_reactions WHERE reaction_type NOT IN ('like','helpful','celebrate')",
                'invalid_member_relation'=> "SELECT COUNT(*) FROM ce_forum_member_relations WHERE user_id=target_id OR kind NOT IN ('follow','ignore')",
                'invalid_achievement_rule'=> "SELECT COUNT(*) FROM ce_forum_achievement_rules WHERE code NOT IN ('first_post','active_member','topic_author','helpful_member','veteran') OR threshold<1 OR threshold>1000000 OR enabled NOT IN (0,1)",
                'missing_achievement_rule'=> "SELECT 5-COUNT(*) FROM ce_forum_achievement_rules WHERE code IN ('first_post','active_member','topic_author','helpful_member','veteran')",

                'invalid_thread_delete_metadata'=> 'SELECT COUNT(*) FROM ce_forum_threads WHERE (deleted_at IS NULL AND (deleted_by IS NOT NULL OR delete_reason IS NOT NULL OR deletion_id IS NOT NULL)) OR (deleted_at IS NOT NULL AND (deleted_at<1 OR deleted_by IS NULL OR delete_reason IS NULL OR deletion_id IS NULL))',
                'invalid_post_delete_metadata'=> 'SELECT COUNT(*) FROM ce_forum_posts WHERE (deleted_at IS NULL AND (deleted_by IS NOT NULL OR delete_reason IS NOT NULL OR deletion_id IS NOT NULL)) OR (deleted_at IS NOT NULL AND (deleted_at<1 OR deleted_by IS NULL OR delete_reason IS NULL OR deletion_id IS NULL))',
                'invalid_live_threads'=> 'SELECT COUNT(*) FROM ce_forum_live_threads WHERE deleted_at IS NOT NULL',
                'invalid_live_posts'=> 'SELECT COUNT(*) FROM ce_forum_live_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.deleted_at IS NOT NULL OR t.deleted_at IS NOT NULL',
                'missing_live_threads'=> 'SELECT COUNT(*) FROM ce_forum_threads t WHERE t.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM ce_forum_live_threads v WHERE v.id=t.id)',
                'missing_live_posts'=> 'SELECT COUNT(*) FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.deleted_at IS NULL AND t.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM ce_forum_live_posts v WHERE v.id=p.id)',
                'deleted_first_post'=> 'SELECT COUNT(*) FROM ce_forum_posts p WHERE p.deleted_at IS NOT NULL AND p.ordinal=(SELECT MIN(p2.ordinal) FROM ce_forum_posts p2 WHERE p2.thread_id=p.thread_id)',
                'empty_threads'=> 'SELECT COUNT(*) FROM ce_forum_threads t WHERE NOT EXISTS (SELECT 1 FROM ce_forum_posts p WHERE p.thread_id=t.id)',
                'container_threads'=> "SELECT COUNT(*) FROM ce_forum_threads t JOIN ce_forum_boards b ON b.id=t.board_id WHERE b.node_type<>'forum'",
                'invalid_publication_sequence'=> 'SELECT COUNT(*) FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.read_sequence<1 OR p.read_sequence>t.read_sequence',
                'invalid_read_cursor'=> 'SELECT COUNT(*) FROM ce_forum_read_state r JOIN ce_forum_threads t ON t.id=r.thread_id WHERE r.read_sequence>t.read_sequence OR r.read_sequence<0',
                'invalid_publication_order'=> 'SELECT COUNT(*) FROM (SELECT read_sequence,LAG(read_sequence) OVER (PARTITION BY thread_id ORDER BY ordinal) AS previous_sequence FROM ce_forum_posts) AS ordered_posts WHERE read_sequence<=previous_sequence',
                'invalid_conversation_cursor'=> 'SELECT COUNT(*) FROM ce_forum_conversation_participants p JOIN ce_forum_conversations c ON c.id=p.conversation_id WHERE p.read_sequence>c.last_sequence OR p.joined_sequence>c.last_sequence+1 OR p.read_sequence<p.joined_sequence-1',
                'invalid_conversation_sequence'=> 'SELECT COUNT(*) FROM ce_forum_conversation_messages m JOIN ce_forum_conversations c ON c.id=m.conversation_id WHERE m.message_sequence>c.last_sequence',
                'invalid_conversation_head'=> 'SELECT COUNT(*) FROM ce_forum_conversations c WHERE c.last_sequence<>(SELECT COALESCE(MAX(m.message_sequence),0) FROM ce_forum_conversation_messages m WHERE m.conversation_id=c.id) OR c.last_sequence<>(SELECT COUNT(*) FROM ce_forum_conversation_messages m WHERE m.conversation_id=c.id)',
                'invalid_conversation_owner'=> 'SELECT COUNT(*) FROM ce_forum_conversations c WHERE c.owner_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND p.user_id=c.owner_id AND p.active=1)',
                'too_many_conversation_members'=> 'SELECT COUNT(*) FROM ce_forum_conversations c WHERE (SELECT COUNT(*) FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND p.active=1)>20',
                'invalid_direct_members'=> "SELECT COUNT(*) FROM ce_forum_conversations c WHERE c.kind='direct' AND (SELECT COUNT(*) FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id)>2",
                'invalid_mail_delivery'=> "SELECT COUNT(*) FROM ce_forum_mail_delivery WHERE attempts<0 OR attempts>8 OR (lease_token IS NULL AND lease_until<>0) OR (lease_token IS NOT NULL AND (state<>'pending' OR lease_until<=0)) OR (state='sent' AND sent_at IS NULL) OR (state<>'sent' AND sent_at IS NOT NULL)",
                'invalid_ordinals'=> 'SELECT COUNT(*) FROM ce_forum_posts WHERE ordinal<1',
                'duplicate_ordinals'=> 'SELECT COUNT(*) FROM (SELECT thread_id,ordinal FROM ce_forum_posts GROUP BY thread_id,ordinal HAVING COUNT(*)>1) AS duplicate_posts',
                'redirect_collision'=> 'SELECT COUNT(*) FROM ce_forum_thread_redirects r JOIN ce_forum_threads t ON t.id=r.source_id',
                'redirect_missing_target'=> 'SELECT COUNT(*) FROM ce_forum_thread_redirects r LEFT JOIN ce_forum_threads t ON t.id=r.target_id LEFT JOIN ce_forum_boards b ON b.id=r.source_board_id WHERE t.id IS NULL OR b.id IS NULL',
                'invalid_primary_groups'=> 'SELECT COUNT(*) FROM ce_forum_primary_groups p LEFT JOIN ce_user_roles ur ON ur.user_id=p.user_id AND ur.role_id=p.role_id LEFT JOIN ce_forum_groups g ON g.role_id=p.role_id WHERE ur.user_id IS NULL OR g.role_id IS NULL',
                'missing_primary_groups'=> 'SELECT COUNT(DISTINCT ur.user_id) FROM ce_user_roles ur JOIN ce_forum_groups g ON g.role_id=ur.role_id LEFT JOIN ce_forum_primary_groups p ON p.user_id=ur.user_id WHERE p.user_id IS NULL',
                'invalid_group_identity'=> "SELECT COUNT(*) FROM ce_forum_groups g JOIN ce_roles r ON r.id=g.role_id WHERE r.handle NOT IN ('forum-member','forum-moderator') AND r.handle NOT LIKE 'forum-group-%'",
                'unmanaged_group_roles'=> "SELECT COUNT(*) FROM ce_roles r LEFT JOIN ce_forum_groups g ON g.role_id=r.id WHERE r.handle LIKE 'forum-group-%' AND g.role_id IS NULL",
            ];
            $violations=[];
            foreach ($queries as $name=>$sql) {
                $count=(int)$pdo->query($sql)->fetchColumn();
                if ($count!==0) $violations[$name]=$count;
            }
            // Validate restored profile images without loading the entire image collection into memory.
            $badImages=0;
            $images=$pdo->query('SELECT kind,image_data FROM ce_forum_profile_images');
            while ($image=$images->fetch(\PDO::FETCH_ASSOC)) {
                if (\CajeerEngine\Forum\Security\ProfileImage::decodeStored((string)$image['kind'],(string)$image['image_data'])===null) ++$badImages;
            }
            $images->closeCursor();
            if ($badImages!==0) $violations['invalid_profile_images']=$badImages;
            $badFiles=0;
            $cursor=null;
            do {
                $page=$pdo->prepare('SELECT id,size_bytes,sha256 FROM ce_forum_attachments'.($cursor===null?'':' WHERE id>?').' ORDER BY id LIMIT 100');
                $page->execute($cursor===null?[]:[$cursor]);$metadata=$page->fetchAll(\PDO::FETCH_ASSOC);$page->closeCursor();
                $payload=$pdo->prepare('SELECT file_data FROM ce_forum_attachments WHERE id=?');
                foreach($metadata as $file){$cursor=$file['id'];$payload->execute([$cursor]);$bytes=base64_decode((string)$payload->fetchColumn(),true);$payload->closeCursor();
                    if($bytes===false||strlen($bytes)!==(int)$file['size_bytes']||!hash_equals((string)$file['sha256'],hash('sha256',$bytes)))++$badFiles;
                }
            }while(count($metadata)===100);
            if($badFiles!==0)$violations['invalid_attachment_bytes']=$badFiles;
            $badVariants=0;$cursor=null;
            do{$stmt=$pdo->prepare('SELECT attachment_id,variant,mime,width,height,size_bytes,sha256 FROM ce_forum_media_variants'.($cursor===null?'':' WHERE attachment_id>?').' ORDER BY attachment_id,variant LIMIT 300');$stmt->execute($cursor===null?[]:[$cursor]);$rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);$stmt->closeCursor();
                // Fetch complete sets for the last ID, so pagination cannot skip its variants.
                if($rows){$last=$rows[count($rows)-1]['attachment_id'];$rows=array_values(array_filter($rows,static fn($r)=>$r['attachment_id']!==$last));$stmt=$pdo->prepare('SELECT attachment_id,variant,mime,width,height,size_bytes,sha256 FROM ce_forum_media_variants WHERE attachment_id=?');$stmt->execute([$last]);$rows=array_merge($rows,$stmt->fetchAll(\PDO::FETCH_ASSOC));$stmt->closeCursor();$cursor=$last;}
                $payload=$pdo->prepare('SELECT file_data FROM ce_forum_media_variants WHERE attachment_id=? AND variant=?');foreach($rows as $v){$payload->execute([$v['attachment_id'],$v['variant']]);$bytes=base64_decode((string)$payload->fetchColumn(),true);$payload->closeCursor();if($bytes===false||!\CajeerEngine\Forum\Content\ForumImageProcessor::valid($v,$bytes))++$badVariants;}
            }while($rows);
            if($badVariants)$violations['invalid_media_variant_bytes']=$badVariants;
            // Validate serialized content in small pages; never include draft text or keys in diagnostics.
            $badDrafts=0;$owner=null;$id=null;
            do {
                $stmt=$pdo->prepare('SELECT user_id,id,scope,target_id,payload,result_json FROM ce_forum_drafts'.($owner===null?'':' WHERE user_id>? OR (user_id=? AND id>?)').' ORDER BY user_id,id LIMIT 50');
                $stmt->execute($owner===null?[]:[$owner,$owner,$id]);$rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);$stmt->closeCursor();
                foreach($rows as $row){$owner=$row['user_id'];$id=$row['id'];if(!\CajeerEngine\Forum\Content\ContentIntegrity::draft($row))++$badDrafts;}
            }while(count($rows)===50);
            if($badDrafts)$violations['invalid_draft_payload']=$badDrafts;
            $badSubmissions=0;$id=null;
            do {
                $stmt=$pdo->prepare('SELECT id,board_id,thread_id,status,payload FROM ce_forum_review_submissions'.($id===null?'':' WHERE id>?').' ORDER BY id LIMIT 50');
                $stmt->execute($id===null?[]:[$id]);$rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);$stmt->closeCursor();
                $held=$pdo->prepare('SELECT attachment_id FROM ce_forum_review_files WHERE submission_id=?');
                foreach($rows as $row){$id=$row['id'];$held->execute([$id]);$files=$held->fetchAll(\PDO::FETCH_COLUMN);$held->closeCursor();if(!\CajeerEngine\Forum\Content\ContentIntegrity::submission($row,$files))++$badSubmissions;}
            }while(count($rows)===50);
            if($badSubmissions)$violations['invalid_review_payload']=$badSubmissions;
            $badReplicas=0;$id=null;
            do {
                $stmt=$pdo->prepare('SELECT r.attachment_id,r.destination,r.object_key,a.sha256 FROM ce_forum_media_replicas r LEFT JOIN ce_forum_attachments a ON a.id=r.attachment_id'.($id===null?'':' WHERE r.attachment_id>?').' ORDER BY r.attachment_id LIMIT 100');
                $stmt->execute($id===null?[]:[$id]);$rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);$stmt->closeCursor();
                foreach($rows as $row){$id=$row['attachment_id'];if(!\CajeerEngine\Forum\Content\ContentIntegrity::replica($row))++$badReplicas;}
            }while(count($rows)===100);
            if($badReplicas)$violations['invalid_media_replica_key']=$badReplicas;
            $invalid=0;
            foreach ($pdo->query('SELECT r.handle,r.permissions FROM ce_forum_groups g JOIN ce_roles r ON r.id=g.role_id')->fetchAll(\PDO::FETCH_ASSOC) as $group) {
                $permissions=json_decode((string)$group['permissions'],true);
                if (!is_array($permissions) || !array_is_list($permissions) || count(array_filter($permissions,'is_string'))!==count($permissions)) { ++$invalid; continue; }
                sort($permissions);
                $expected=match($group['handle']) {
                    'forum-member'=>['forum:write'],
                    'forum-moderator'=>['forum:moderate','forum:write'],
                    default=>null,
                };
                if ($expected!==null ? $permissions!==$expected : (array_diff($permissions,['forum:write','forum:moderate'])!==[] || count(array_unique($permissions))!==count($permissions) || (in_array('forum:moderate',$permissions,true)&&!in_array('forum:write',$permissions,true)))) ++$invalid;
            }
            if ($invalid!==0) $violations['invalid_group_scopes']=$invalid;
            return ['ok'=>$violations===[],'violations'=>$violations];
        });
    }
}
