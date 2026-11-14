<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Forum\ForumException;

final readonly class BoardRepository extends Repository
{
    public function categories(?array $allowed=null): array { return $this->rows('SELECT * FROM ce_forum_categories WHERE '.$this->boardFilter('id',$allowed).' ORDER BY position,name,id'); }
    private const COUNTS='SELECT b.*, (SELECT COUNT(*) FROM ce_forum_threads t WHERE t.board_id=b.id) AS threads, (SELECT COUNT(*) FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE t.board_id=b.id) AS posts, (SELECT MAX(t.updated_at) FROM ce_forum_threads t WHERE t.board_id=b.id) AS last_activity FROM ce_forum_boards b';
    public function boards(?array $allowed=null): array {
        $rows=$this->rows(self::COUNTS.' WHERE '.$this->boardFilter('b.id',$allowed).' ORDER BY b.position,b.name,b.id');$map=array_column($rows,null,'id');
        foreach ($rows as &$b) $b['effective_state']=self::effectiveState($b,$map);
        return $rows;
    }
    public function children(string $id,?array $allowed=null): array { return $this->rows(self::COUNTS.' WHERE b.parent_id=? AND '.$this->boardFilter('b.id',$allowed).' ORDER BY b.position,b.name,b.id',[$id]); }

    public function find(string $id): ?array { return $this->row('SELECT * FROM ce_forum_boards WHERE id=?', [$id]); }
    public function lockBoard(string $id): ?array { return $this->lock('ce_forum_boards', 'id=?', [$id]); }
    public function lockCategory(string $id): ?array { return $this->lock('ce_forum_categories', 'id=?', [$id]); }
    public function insertCategory(string $id, string $name, string $description): void { $this->query('INSERT INTO ce_forum_categories(id,name,description) VALUES(?,?,?)', [$id,$name,$description]); }
    public function insertBoard(string $id, string $category, string $name, string $description): void { $this->query('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)', [$id,$category,$name,$description]); }

    /** Serialize topology changes, including an empty tree. Lock boards before reading topology.
     * Publication locks its target board, so a move or archive cannot race a write. */
    public function lockTree(): void
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Tree lock requires a transaction');
        if ($this->db->driver()==='sqlite') {
            $this->query('UPDATE ce_forum_tree_lock SET id=id WHERE id=1');
            $row=$this->row('SELECT id FROM ce_forum_tree_lock WHERE id=1');
        } else {
            $row=$this->row('SELECT id FROM ce_forum_tree_lock WHERE id=1 FOR UPDATE');
            $this->rows('SELECT id FROM ce_forum_boards ORDER BY id FOR UPDATE');
        }
        if ($row===null) throw new \RuntimeException('Forum tree lock missing');
    }
    public function topology(): array { return $this->rows('SELECT * FROM ce_forum_boards ORDER BY position,name,id'); }
    public function updateCategory(string $id,string $name,string $description): void { $this->query('UPDATE ce_forum_categories SET name=?,description=? WHERE id=?',[$name,$description,$id]); }
    public function categoryPosition(string $id,int $position): void { $this->query('UPDATE ce_forum_categories SET position=? WHERE id=?',[$position,$id]); }
    public function updateBoard(string $id,string $name,string $description,string $type,string $state,?string $rules=null): void { $this->query('UPDATE ce_forum_boards SET name=?,description=?,node_type=?,state=?,rules=COALESCE(?,rules) WHERE id=?',[$name,$description,$type,$state,$rules,$id]); }
    public function moveBoard(string $id,string $category,?string $parent): void { $this->query('UPDATE ce_forum_boards SET category_id=?,parent_id=? WHERE id=?',[$category,$parent,$id]); }
    public function boardPosition(string $id,int $position): void { $this->query('UPDATE ce_forum_boards SET position=? WHERE id=?',[$position,$id]); }
    public function hasThreads(string $id): bool { return $this->row('SELECT id FROM ce_forum_threads WHERE board_id=? LIMIT 1',[$id])!==null; }
    public function deleteBoard(string $id): void { $this->query('DELETE FROM ce_forum_boards WHERE id=?',[$id]); }
    public function deleteCategory(string $id): void { $this->query('DELETE FROM ce_forum_categories WHERE id=?',[$id]); }

    /** A public node view includes inherited state, without granting any new permissions. */
    public static function effectiveState(array $board,array $map): string
    {
        $seen=[]; $state='open';
        while (true) {
            if (isset($seen[$board['id']]) || count($seen)>=16) return 'archived';
            $seen[$board['id']]=true;
            if ($board['state']==='archived') return 'archived';
            if ($board['state']==='readonly') $state='readonly';
            $parent=$board['parent_id'];
            if ($parent===null) return $state;
            if (!isset($map[$parent]) || $map[$parent]['category_id']!==$board['category_id']) return 'archived';
            $board=$map[$parent];
        }
    }
    public function view(string $id): ?array
    {
        $board=$this->find($id);if ($board===null) return null;
        $map=[$id=>$board];$parent=$board['parent_id'];
        while ($parent!==null && !isset($map[$parent]) && count($map)<16) {
            $row=$this->find($parent);if ($row===null) break;
            $map[$parent]=$row;$parent=$row['parent_id'];
        }
        $board['effective_state']=self::effectiveState($board,$map);return $board;
    }
    /** Effective publication state includes ancestors. Caller holds target board lock for writes. */
    public function writable(string $id): void
    {
        $board=$this->lockBoard($id) ?? throw new ForumException('Раздел не найден.',404);
        if ($board['node_type']!=='forum') throw new ForumException('Контейнер не принимает обсуждения.',409);
        $seen=[];
        while ($board!==null) {
            if (isset($seen[$board['id']]) || count($seen)>=16) throw new ForumException('Некорректная иерархия.',409);
            $seen[$board['id']]=true;
            if ($board['state']!=='open') throw new ForumException('Раздел доступен только для чтения.',409);
            if ($board['parent_id']===null) return;
            $parent=$this->find($board['parent_id']);
            if ($parent===null || $parent['category_id']!==$board['category_id']) throw new ForumException('Некорректная иерархия.',409);
            $board=$parent;
        }
    }
}
