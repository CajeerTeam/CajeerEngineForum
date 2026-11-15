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
                'invalid_poll_options'=> 'SELECT COUNT(*) FROM ce_forum_polls p WHERE (SELECT COUNT(*) FROM ce_forum_poll_options o WHERE o.thread_id=p.thread_id)<2 OR (SELECT COUNT(*) FROM ce_forum_poll_options o WHERE o.thread_id=p.thread_id)<p.max_choices',
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
