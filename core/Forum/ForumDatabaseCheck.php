<?php

declare(strict_types=1);

namespace CajeerEngine\Forum;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;

/** Verify actual forum schema and seeded permissions, not just the migration ledger. */
final readonly class ForumDatabaseCheck
{
    public function __construct(private DatabaseManager $db) {}

    public function check(): array
    {
        $required = [
            'ce_identity_challenges'=>['user_id','purpose','token_hash','secret','issued_at','expires_at'],
            'ce_users'=>['id','email','password_hash','status','email_verified_at','password_changed_at','two_factor_enabled','two_factor_secret','two_factor_recovery_codes','two_factor_last_step'],
            'ce_roles'=>['id','handle','permissions'],
            'ce_user_roles'=>['user_id','role_id'],
            'ce_api_tokens'=>['id','user_id','name','token_hash','scopes','expires_at','revoked_at','last_used_at'],
            'ce_forum_member_profiles'=>['user_id','display_name','bio','location','website','signature'],
            'ce_forum_member_preferences'=>['user_id','profile_visibility','activity_visibility','show_groups','show_signatures','theme','locale','timezone'],
            'ce_forum_profile_images'=>['user_id','kind','image_data'],
            'ce_forum_profile_fields'=>['id','label','visibility','position'],
            'ce_forum_profile_field_values'=>['user_id','field_id','field_value'],
            'ce_forum_groups'=>['role_id','description'],
            'ce_forum_primary_groups'=>['user_id','role_id'],
            'ce_forum_categories'=>['id','name','description','position','visibility'],
            'ce_forum_category_permissions'=>['node_id','subject_type','subject_id','permission','effect'],
            'ce_forum_board_permissions'=>['node_id','subject_type','subject_id','permission','effect'],
            'ce_forum_boards'=>['id','category_id','name','description','position','parent_id','node_type','state','visibility','rules'],
            'ce_forum_tree_lock'=>['id'],
            'ce_forum_thread_tags'=>['thread_id','tag'],
            'ce_forum_thread_redirects'=>['source_id','source_board_id','target_id'],
            'ce_forum_threads'=>['id','board_id','author_id','prefix','title','locked','pinned','created_at','updated_at','read_sequence'],
            'ce_forum_media_state'=>['attachment_id','state','attempts','due_at','lease_token'],
            'ce_forum_media_variants'=>['attachment_id','variant','mime','width','height','size_bytes','sha256','file_data'],
            'ce_forum_media_replicas'=>['attachment_id','destination','object_key','state','attempts','due_at'],
            'ce_forum_attachments'=>['id','owner_id','post_id','message_id','filename','mime','size_bytes','sha256','file_data','created_at','expires_at'],
            'ce_forum_posts'=>['id','thread_id','author_id','body','body_format','ordinal','created_at','edited_at','read_sequence'],
            'ce_forum_notifications'=>['event_id','recipient_id','actor_id','post_id','kind','moderation_action','subscription_kind','state','is_read','created_at'],
            'ce_forum_notification_preferences'=>['user_id','reply','mention','quote','reaction','moderation'],
            'ce_forum_read_state'=>['user_id','thread_id','read_sequence'],
            'ce_forum_member_relations'=>['user_id','target_id','kind'],
            'ce_forum_achievement_rules'=>['code','threshold','enabled'],
            'ce_forum_reactions'=>['post_id','user_id','reaction_type'], 'ce_forum_watches'=>['thread_id','user_id','mode'],
            'ce_forum_board_watches'=>['board_id','user_id','mode'],
            'ce_forum_watch_preferences'=>['user_id','auto_mode'],
            'ce_forum_mail_delivery'=>['id','user_id','post_id','board_id','source_kind','mode','state','attempts','due_at','lease_until','lease_token','sent_at','last_error'],
            'ce_forum_conversation_lock'=>['id'],
            'ce_forum_conversations'=>['id','owner_id','title','kind','last_sequence','created_at','updated_at'],
            'ce_forum_conversation_participants'=>['conversation_id','user_id','joined_sequence','read_sequence','active','archived','muted'],
            'ce_forum_conversation_messages'=>['id','conversation_id','author_id','message_sequence','body','body_format','created_at','edited_at'],
            'ce_forum_conversation_preferences'=>['user_id','incoming'],
            'ce_forum_user_blocks'=>['user_id','blocked_id'],
            'ce_forum_conversation_reports'=>['id','message_id','user_id','reason','body_snapshot','status','created_at','resolved_by'],
            'ce_forum_conversation_limits'=>['user_id','action','window_start','hits'],
            'ce_forum_reports'=>['id','post_id','user_id','reason','status','created_at','resolved_by'],
        ];
        $missing=[];
        foreach ($required as $table=>$columns) {
            if (!$this->db->tableExists($table)) { $missing[]=$table; continue; }
            foreach ($columns as $column) if (!$this->db->columnExists($table,$column)) $missing[]=$table.'.'.$column;
        }
        if ($missing !== []) return ['ok'=>false, 'missing'=>$missing, 'invalid_roles'=>[], 'missing_indexes'=>[]];
        $roles = new RoleRepository($this->db);
        $invalidRoles=[];
        foreach (['admin'=>['*'],'forum-member'=>['forum:write'],'forum-moderator'=>['forum:write','forum:moderate']] as $handle=>$permissions) {
            $role=$roles->find($handle);
            if ($role===null || array_diff($permissions,$role['permissions'] ?? [])!==[] || array_diff($role['permissions'] ?? [],$permissions)!==[]) $invalidRoles[]=$handle;
        }
        $pdo=$this->db->connection();
        if ((int)$pdo->query("SELECT COUNT(*) FROM ce_forum_groups g JOIN ce_roles r ON r.id=g.role_id WHERE r.handle IN ('forum-member','forum-moderator')")->fetchColumn()!==2) $missing[]='forum_system_groups';
        $indexes = match($this->db->driver()) {
            'pgsql' => $pdo->query('SELECT indexname FROM pg_indexes WHERE schemaname=current_schema()')->fetchAll(\PDO::FETCH_COLUMN),
            'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(\PDO::FETCH_COLUMN),
            default => $pdo->query('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE()')->fetchAll(\PDO::FETCH_COLUMN),
        };
        $missingIndexes = array_values(array_diff(['ce_forum_media_due','ce_forum_replica_due','ce_forum_attachment_owner','ce_forum_attachment_post','ce_forum_attachment_message','ce_forum_attachment_expiry','ce_forum_relation_target','ce_forum_activity_post','ce_forum_conv_member','ce_forum_conv_blocked','ce_forum_conv_report_queue','ce_forum_mail_sent','ce_forum_mail_due','ce_forum_mail_user','ce_forum_mail_post','ce_forum_board_watch_user','ce_forum_notify_pending','ce_forum_notify_inbox','ce_forum_notify_page','ce_forum_notify_post','ce_forum_notify_actor','ce_forum_post_read_sequence','ce_forum_read_thread','ce_forum_profile_field_lookup','ce_forum_tag_lookup','ce_forum_redirect_target','ce_forum_group_members','ce_forum_board_parent','ce_identity_expiry_idx','ce_forum_post_thread','ce_forum_thread_latest','ce_forum_thread_author','ce_forum_post_author','ce_forum_watch_user','ce_forum_reaction_user','ce_forum_report_post','ce_forum_report_user','ce_forum_report_resolver'],$indexes));
        if ((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_conversation_lock WHERE id=1')->fetchColumn()!==1) $missing[]='ce_forum_conversation_lock.id=1';
        try {
            if ((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_tree_lock WHERE id=1')->fetchColumn()!==1) $missing[]='ce_forum_tree_lock.id=1';
            \CajeerEngine\Forum\Service\NodeService::validateTree($pdo->query('SELECT id,category_id,parent_id FROM ce_forum_boards')->fetchAll(\PDO::FETCH_ASSOC));
        } catch (ForumException $e) { $missing[]='forum_tree_integrity'; }
        return ['ok'=>$missing===[] && $invalidRoles===[] && $missingIndexes===[], 'missing'=>$missing,'invalid_roles'=>$invalidRoles,'missing_indexes'=>$missingIndexes];
    }
}
