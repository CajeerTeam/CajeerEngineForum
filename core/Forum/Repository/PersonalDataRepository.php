<?php
declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Forum\ForumException;

/** Only fixed, owner-scoped queries. Never serialize credential or moderation-internal rows. */
final readonly class PersonalDataRepository extends Repository
{
    public const SECTIONS = ['account','profile','profile_images','profile_fields','preferences','notification_preferences','watch_preferences','conversation_preferences','threads','posts','post_revisions','messages','message_revisions','drafts','attachments','subscriptions','board_subscriptions','reactions','poll_votes','answer_votes','relations','blocks','conversation_memberships','read_state','notifications','answer_notifications','reports','conversation_reports','submissions','sanctions','appeals','sessions'];
    public const PAGE_SIZE = 25;

    public function page(string $id, string $section, int $offset, array $boards): array
    {
        $board = $this->boardFilter('t.board_id', $boards);
        $threadBoard = $this->boardFilter('t.board_id', $boards);
        $member = 'EXISTS (SELECT 1 FROM ce_forum_conversation_participants cp WHERE cp.conversation_id=m.conversation_id AND cp.user_id=m.author_id AND cp.active=1 AND cp.joined_sequence<=m.message_sequence)';
        $queries = [
            'account'=>['SELECT id,email,name,status,email_verified_at,last_login_at,created_at,updated_at,two_factor_enabled FROM ce_users WHERE id=?','id'],
            'profile'=>['SELECT display_name,bio,location,website,signature FROM ce_forum_member_profiles WHERE user_id=?','user_id'],
            'profile_images'=>['SELECT kind,image_data FROM ce_forum_profile_images WHERE user_id=?','kind'],
            'profile_fields'=>['SELECT v.field_id,f.label,v.field_value FROM ce_forum_profile_field_values v JOIN ce_forum_profile_fields f ON f.id=v.field_id WHERE v.user_id=?','v.field_id'],
            'preferences'=>['SELECT profile_visibility,activity_visibility,show_groups,show_signatures,theme,locale,timezone FROM ce_forum_member_preferences WHERE user_id=?','user_id'],
            'notification_preferences'=>['SELECT reply,mention,quote,reaction,moderation,accepted FROM ce_forum_notification_preferences WHERE user_id=?','user_id'],
            'watch_preferences'=>['SELECT auto_mode FROM ce_forum_watch_preferences WHERE user_id=?','user_id'],
            'conversation_preferences'=>['SELECT incoming,contact_mode FROM ce_forum_conversation_preferences WHERE user_id=?','user_id'],
            'threads'=>["SELECT t.id,t.board_id,t.title,t.prefix,t.created_at,t.updated_at FROM ce_forum_live_threads t WHERE t.author_id=? AND $threadBoard",'t.id'],
            'posts'=>["SELECT p.id,p.thread_id,p.body,p.body_format,p.created_at,p.edited_at FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.author_id=? AND $board",'p.id'],
            'post_revisions'=>["SELECT r.post_id,r.revision_number,r.body,r.body_format,r.created_at FROM ce_forum_post_revisions r JOIN ce_forum_live_posts p ON p.id=r.post_id JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE r.editor_id=? AND r.editor_id=p.author_id AND $board",'r.post_id,r.revision_number'],
            'messages'=>["SELECT m.id,m.conversation_id,m.body,m.body_format,m.created_at,m.edited_at FROM ce_forum_conversation_messages m WHERE m.author_id=? AND $member",'m.id'],
            'message_revisions'=>["SELECT r.message_id,r.revision_number,r.body,r.body_format,r.created_at FROM ce_forum_conversation_revisions r JOIN ce_forum_conversation_messages m ON m.id=r.message_id WHERE r.editor_id=? AND r.editor_id=m.author_id AND $member",'r.message_id,r.revision_number'],
            'drafts'=>['SELECT id,scope,target_id,revision_number,payload,result_json,updated_at,expires_at FROM ce_forum_drafts WHERE user_id=?','id'],
            'attachments'=>['SELECT id,post_id,message_id,filename,mime,size_bytes,sha256,created_at,expires_at FROM ce_forum_attachments WHERE owner_id=?','id'],
            'subscriptions'=>['SELECT thread_id,mode FROM ce_forum_watches WHERE user_id=?','thread_id'],
            'board_subscriptions'=>['SELECT board_id,mode FROM ce_forum_board_watches WHERE user_id=?','board_id'],
            'reactions'=>['SELECT post_id,reaction_type FROM ce_forum_reactions WHERE user_id=?','post_id'],
            'poll_votes'=>['SELECT thread_id,option_no FROM ce_forum_poll_votes WHERE user_id=?','thread_id,option_no'],
            'answer_votes'=>['SELECT post_id FROM ce_forum_answer_votes WHERE user_id=?','post_id'],
            'relations'=>['SELECT target_id,kind FROM ce_forum_member_relations WHERE user_id=?','target_id,kind'],
            'blocks'=>['SELECT blocked_id FROM ce_forum_user_blocks WHERE user_id=?','blocked_id'],
            'conversation_memberships'=>['SELECT conversation_id,joined_sequence,read_sequence,active,archived,muted FROM ce_forum_conversation_participants WHERE user_id=?','conversation_id'],
            'read_state'=>['SELECT thread_id,read_sequence FROM ce_forum_read_state WHERE user_id=?','thread_id'],
            'notifications'=>['SELECT event_id,post_id,kind,is_read,created_at FROM ce_forum_notifications WHERE recipient_id=?','event_id'],
            'answer_notifications'=>['SELECT event_id,post_id,is_read,created_at FROM ce_forum_answer_notifications WHERE recipient_id=?','event_id'],
            'reports'=>['SELECT id,post_id,reason,status,category,created_at,resolved_at,decision_kind FROM ce_forum_reports WHERE user_id=?','id'],
            'conversation_reports'=>['SELECT id,message_id,reason,status,created_at,resolved_at FROM ce_forum_conversation_reports WHERE user_id=?','id'],
            'submissions'=>['SELECT id,board_id,thread_id,payload,status,result_id,created_at,updated_at FROM ce_forum_review_submissions WHERE author_id=?','id'],
            'sanctions'=>['SELECT id,kind,board_id,reason,points,starts_at,ends_at,revoked_at,revoke_reason FROM ce_forum_sanctions WHERE user_id=?','id'],
            'appeals'=>['SELECT a.id,a.sanction_id,a.body,a.status,a.created_at,a.decided_at,a.decision FROM ce_forum_appeals a JOIN ce_forum_sanctions s ON s.id=a.sanction_id WHERE s.user_id=?','a.id'],
            'sessions'=>['SELECT id,name,created_at,last_used_at,expires_at,revoked_at FROM ce_api_tokens WHERE user_id=?','id'],
        ];
        if (!isset($queries[$section])) throw new ForumException('Неизвестный раздел экспорта.',422);
        [$sql,$order]=$queries[$section];
        $rows=$this->rows($sql.' ORDER BY '.$order.' LIMIT '.(self::PAGE_SIZE+1).' OFFSET '.$offset,[$id]);
        $more=count($rows)>self::PAGE_SIZE;
        if($more)array_pop($rows);
        return ['format_version'=>1,'section'=>$section,'offset'=>$offset,'next_offset'=>$more?$offset+self::PAGE_SIZE:null,'items'=>$rows];
    }

    public function clearProfile(string $id): void
    {
        foreach(['ce_forum_profile_images','ce_forum_profile_field_values','ce_forum_member_profiles'] as $table) $this->query("DELETE FROM $table WHERE user_id=?",[$id]);
        $profiles=new ProfileRepository($this->db);
        $profiles->save($id,'preferences',['profile_visibility'=>'private','activity_visibility'=>'private','show_groups'=>0]+$profiles->preferences($id));
    }

    /** Keep shared publications, moderation evidence and the original foreign-key identity. */
    public function erase(string $id, string $replacementEmail, string $passwordHash): void
    {
        $now=time();
        $this->clearProfile($id);
        $moderation=new ModerationQueueRepository($this->db);
        foreach($this->rows("SELECT id,revision_number FROM ce_forum_review_submissions WHERE author_id=? AND status='pending' ORDER BY id",[$id]) as $row){
            $case=$row['id'];$revision=(int)$row['revision_number'];
            $moderation->release($case);
            if(!$moderation->update('submission',$case,$revision,'cancel','Account closed',$id))throw new ForumException('Заявка изменилась. Повторите запрос.',409);
            $moderation->event('submission',$case,$id,'cancel','Account closed',$revision+1);
        }
        $conversations=new ConversationRepository($this->db);
        foreach($this->rows('SELECT conversation_id FROM ce_forum_conversation_participants WHERE user_id=? AND active=1 ORDER BY conversation_id',[$id]) as $row)$conversations->leave($row['conversation_id'],$id);
        foreach(['ce_forum_member_preferences','ce_forum_notification_preferences','ce_forum_watch_preferences','ce_forum_conversation_preferences','ce_forum_drafts','ce_forum_read_state','ce_forum_watches','ce_forum_board_watches','ce_forum_mail_delivery','ce_forum_reactions','ce_forum_poll_votes','ce_forum_answer_votes','ce_forum_conversation_limits','ce_forum_conversation_participants','ce_forum_primary_groups','ce_user_roles','ce_identity_challenges','ce_api_tokens'] as $table)$this->query("DELETE FROM $table WHERE user_id=?",[$id]);
        foreach(['ce_forum_notifications','ce_forum_answer_notifications'] as $table)$this->query("DELETE FROM $table WHERE recipient_id=?",[$id]);
        $this->query("DELETE FROM ce_forum_notifications WHERE actor_id=? AND kind='reaction'",[$id]);
        $this->query('DELETE FROM ce_forum_member_relations WHERE user_id=? OR target_id=?',[$id,$id]);
        $this->query('DELETE FROM ce_forum_user_blocks WHERE user_id=? OR blocked_id=?',[$id,$id]);
        // The media replica table intentionally retains tombstones for the normal deletion worker.
        $this->query('DELETE FROM ce_forum_attachments WHERE owner_id=? AND post_id IS NULL AND message_id IS NULL AND NOT EXISTS (SELECT 1 FROM ce_forum_review_files h WHERE h.attachment_id=ce_forum_attachments.id)',[$id]);
        foreach(['ce_forum_category_permissions','ce_forum_board_permissions'] as $table)$this->query("DELETE FROM $table WHERE subject_type='user' AND subject_id=?",[$id]);
        $recovery=match($this->db->driver()){'pgsql'=>"'[]'::jsonb",'mysql'=>'JSON_ARRAY()',default=>"'[]'"};
        $this->query("UPDATE ce_users SET email=?,name=?,password_hash=?,status='disabled',two_factor_enabled=FALSE,two_factor_secret=NULL,two_factor_last_step=NULL,two_factor_recovery_codes=$recovery,email_verified_at=NULL,password_changed_at=NULL,last_login_at=NULL,disabled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?",[$replacementEmail,'Удалённый участник',$passwordHash,$id]);
        $this->query('INSERT INTO ce_forum_erased_accounts(user_id,erased_at) VALUES (?,?)',[$id,$now]);
    }
}
