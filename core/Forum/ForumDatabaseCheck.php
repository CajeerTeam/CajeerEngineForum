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
            'ce_forum_groups'=>['role_id','description'],
            'ce_forum_primary_groups'=>['user_id','role_id'],
            'ce_forum_categories'=>['id','name','description','position','visibility'],
            'ce_forum_category_permissions'=>['node_id','subject_type','subject_id','permission','effect'],
            'ce_forum_board_permissions'=>['node_id','subject_type','subject_id','permission','effect'],
            'ce_forum_boards'=>['id','category_id','name','description','position','parent_id','node_type','state','visibility','rules'],
            'ce_forum_tree_lock'=>['id'],
            'ce_forum_thread_tags'=>['thread_id','tag'],
            'ce_forum_thread_redirects'=>['source_id','source_board_id','target_id'],
            'ce_forum_threads'=>['id','board_id','author_id','prefix','title','locked','pinned','created_at','updated_at'],
            'ce_forum_posts'=>['id','thread_id','author_id','body','ordinal','created_at','edited_at'],
            'ce_forum_reactions'=>['post_id','user_id'], 'ce_forum_watches'=>['thread_id','user_id'],
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
            if ($role===null || array_diff($permissions,$role['permissions'] ?? [])!==[]) $invalidRoles[]=$handle;
        }
        $pdo=$this->db->connection();
        if ((int)$pdo->query("SELECT COUNT(*) FROM ce_forum_groups g JOIN ce_roles r ON r.id=g.role_id WHERE r.handle IN ('forum-member','forum-moderator')")->fetchColumn()!==2) $missing[]='forum_system_groups';
        $indexes = match($this->db->driver()) {
            'pgsql' => $pdo->query('SELECT indexname FROM pg_indexes WHERE schemaname=current_schema()')->fetchAll(\PDO::FETCH_COLUMN),
            'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(\PDO::FETCH_COLUMN),
            default => $pdo->query('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE()')->fetchAll(\PDO::FETCH_COLUMN),
        };
        $missingIndexes = array_values(array_diff(['ce_forum_tag_lookup','ce_forum_redirect_target','ce_forum_group_members','ce_forum_board_parent','ce_identity_expiry_idx','ce_forum_post_thread','ce_forum_thread_latest','ce_forum_thread_author','ce_forum_post_author','ce_forum_watch_user','ce_forum_reaction_user','ce_forum_report_post','ce_forum_report_user','ce_forum_report_resolver'],$indexes));
        try {
            if ((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_tree_lock WHERE id=1')->fetchColumn()!==1) $missing[]='ce_forum_tree_lock.id=1';
            \CajeerEngine\Forum\Service\NodeService::validateTree($pdo->query('SELECT id,category_id,parent_id FROM ce_forum_boards')->fetchAll(\PDO::FETCH_ASSOC));
        } catch (ForumException $e) { $missing[]='forum_tree_integrity'; }
        return ['ok'=>$missing===[] && $invalidRoles===[] && $missingIndexes===[], 'missing'=>$missing,'invalid_roles'=>$invalidRoles,'missing_indexes'=>$missingIndexes];
    }
}
