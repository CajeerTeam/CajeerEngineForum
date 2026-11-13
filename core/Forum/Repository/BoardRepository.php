<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class BoardRepository extends Repository
{
    public function categories(): array { return $this->rows('SELECT * FROM ce_forum_categories ORDER BY position,name,id'); }
    public function boards(): array { return $this->rows('SELECT b.*, (SELECT COUNT(*) FROM ce_forum_threads t WHERE t.board_id=b.id) AS threads FROM ce_forum_boards b ORDER BY position,name,id'); }
    public function find(string $id): ?array { return $this->row('SELECT * FROM ce_forum_boards WHERE id=?', [$id]); }
    public function lockBoard(string $id): ?array { return $this->lock('ce_forum_boards', 'id=?', [$id]); }
    public function lockCategory(string $id): ?array { return $this->lock('ce_forum_categories', 'id=?', [$id]); }
    public function insertCategory(string $id, string $name, string $description): void { $this->query('INSERT INTO ce_forum_categories(id,name,description) VALUES(?,?,?)', [$id,$name,$description]); }
    public function insertBoard(string $id, string $category, string $name, string $description): void { $this->query('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)', [$id,$category,$name,$description]); }
}
