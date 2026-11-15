<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class AttachmentRepository extends Repository
{
    private const META='id,owner_id,post_id,message_id,filename,mime,size_bytes,sha256,created_at,expires_at';
    public function find(string $id): ?array {return $this->row('SELECT '.self::META.' FROM ce_forum_attachments WHERE id=?',[$id]);}
    public function bytes(string $id): ?array {return $this->row('SELECT file_data FROM ce_forum_attachments WHERE id=?',[$id]);}
    public function lockItem(string $id): ?array
    {
        // Avoid reading the large payload for metadata-only locks.
        if ($this->db->driver()==='sqlite') {$this->query('UPDATE ce_forum_attachments SET id=id WHERE id=?',[$id]);return $this->find($id);}
        return $this->row('SELECT '.self::META.' FROM ce_forum_attachments WHERE id=? FOR UPDATE',[$id]);
    }
    public function insert(array $a,string $data): void
    {$this->query('INSERT INTO ce_forum_attachments(id,owner_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)',[$a['id'],$a['owner_id'],$a['filename'],$a['mime'],$a['size_bytes'],$a['sha256'],base64_encode($data),$a['created_at'],$a['expires_at']]);}
    public function restoreBytes(string $id,string $data): void
    {$this->query('UPDATE ce_forum_attachments SET file_data=? WHERE id=?',[base64_encode($data),$id]);}
    public function usage(string $user): array
    {return $this->row('SELECT COUNT(*) AS files,COALESCE(SUM(size_bytes),0) AS bytes FROM ce_forum_attachments WHERE owner_id=?',[$user])??['files'=>0,'bytes'=>0];}
    public function primaryGroup(string $user): ?string
    {return $this->row('SELECT r.handle FROM ce_forum_primary_groups p JOIN ce_roles r ON r.id=p.role_id WHERE p.user_id=?',[$user])['handle']??null;}
    public function pendingCount(string $user): int
    {return (int)$this->query('SELECT COUNT(*) FROM ce_forum_attachments WHERE owner_id=? AND post_id IS NULL AND message_id IS NULL',[$user])->fetchColumn();}
    public function pending(string $user,int $now): array
    {return $this->rows('SELECT '.self::META.' FROM ce_forum_attachments WHERE owner_id=? AND post_id IS NULL AND message_id IS NULL AND expires_at>? ORDER BY created_at DESC,id LIMIT 100',[$user,$now]);}
    public function attached(string $kind,array $ids): array
    {
        if(!$ids)return [];$column=$kind==='post'?'post_id':'message_id';
        return $this->rows('SELECT '.self::META.' FROM ce_forum_attachments WHERE '.$this->boardFilter($column,$ids).' ORDER BY created_at,id');
    }
    public function bind(string $kind,string $target,string $id,string $user,int $now): bool
    { $column=$kind==='post'?'post_id':'message_id';return $this->query("UPDATE ce_forum_attachments SET $column=?,expires_at=0 WHERE id=? AND owner_id=? AND post_id IS NULL AND message_id IS NULL AND expires_at>?",[$target,$id,$user,$now])->rowCount()===1; }
    public function post(string $id): ?array
    {return $this->row('SELECT p.id,p.author_id,p.thread_id,t.board_id,t.locked FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.id=?',[$id]);}
    public function message(string $id): ?array
    {return $this->row('SELECT id,author_id,conversation_id,message_sequence FROM ce_forum_conversation_messages WHERE id=?',[$id]);}
    public function delete(string $id): void {$this->query('DELETE FROM ce_forum_attachments WHERE id=?',[$id]);}
    public function cleanup(int $now): int
    {
        $rows=$this->rows('SELECT id FROM ce_forum_attachments WHERE post_id IS NULL AND message_id IS NULL AND expires_at<=? ORDER BY expires_at,id LIMIT 100',[$now]);$n=0;usort($rows,static fn($a,$b)=>strcmp($a['id'],$b['id']));
        foreach($rows as $row)$n+=$this->query('DELETE FROM ce_forum_attachments WHERE id=? AND post_id IS NULL AND message_id IS NULL AND expires_at<=?',[$row['id'],$now])->rowCount();return $n;
    }
}
