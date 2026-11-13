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
            'ce_forum_categories'=>['id','name','description','position'],
            'ce_forum_boards'=>['id','category_id','name','description','position'],
            'ce_forum_threads'=>['id','board_id','author_id','title','locked','pinned','created_at','updated_at'],
            'ce_forum_posts'=>['id','thread_id','author_id','body','ordinal','created_at','edited_at'],
            'ce_forum_reactions'=>['post_id','user_id'], 'ce_forum_watches'=>['thread_id','user_id'],
            'ce_forum_reports'=>['id','post_id','user_id','reason','status','created_at','resolved_by'],
        ];
        $missing=[];
        foreach ($required as $table=>$columns) {
            if (!$this->db->tableExists($table)) { $missing[]=$table; continue; }
            foreach ($columns as $column) if (!$this->db->columnExists($table,$column)) $missing[]=$table.'.'.$column;
        }
        $roles = new RoleRepository($this->db);
        $invalidRoles=[];
        foreach (['admin'=>['*'],'forum-member'=>['forum:write'],'forum-moderator'=>['forum:write','forum:moderate']] as $handle=>$permissions) {
            $role=$roles->find($handle);
            if ($role===null || array_diff($permissions,$role['permissions'] ?? [])!==[]) $invalidRoles[]=$handle;
        }
        $pdo=$this->db->connection();
        $indexes = match($this->db->driver()) {
            'pgsql' => $pdo->query('SELECT indexname FROM pg_indexes WHERE schemaname=current_schema()')->fetchAll(\PDO::FETCH_COLUMN),
            'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(\PDO::FETCH_COLUMN),
            default => $pdo->query('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE()')->fetchAll(\PDO::FETCH_COLUMN),
        };
        $missingIndexes = array_values(array_diff(['ce_forum_post_thread','ce_forum_thread_latest','ce_forum_thread_author','ce_forum_post_author','ce_forum_watch_user','ce_forum_reaction_user','ce_forum_report_post','ce_forum_report_user','ce_forum_report_resolver'],$indexes));
        return ['ok'=>$missing===[] && $invalidRoles===[] && $missingIndexes===[], 'missing'=>$missing,'invalid_roles'=>$invalidRoles,'missing_indexes'=>$missingIndexes];
    }
}
