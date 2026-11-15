<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class ForumMediaRepository extends Repository
{
    public function enqueue(string $id): void
    { $this->query("INSERT INTO ce_forum_media_state(attachment_id,state,due_at) VALUES (?,'pending',0)",[$id]); }
    public function nextImage(int $now): ?array
    { return $this->row("SELECT s.attachment_id,s.attempts FROM ce_forum_media_state s WHERE s.state='pending' AND s.due_at<=? ORDER BY s.due_at,s.attachment_id LIMIT 1",[$now]); }
    public function imageState(string $id): ?array
    { return $this->row('SELECT state,attempts,due_at,lease_token FROM ce_forum_media_state WHERE attachment_id=?',[$id]); }
    public function claimImage(string $id,int $now,int $lease,string $token): bool
    {return $this->query("UPDATE ce_forum_media_state SET due_at=?,lease_token=? WHERE attachment_id=? AND state='pending' AND due_at<=?",[$lease,$token,$id,$now])->rowCount()===1;}
    public function store(string $id,array $variants): void
    {
        $this->query('DELETE FROM ce_forum_media_variants WHERE attachment_id=?',[$id]);
        foreach($variants as $name=>$v)$this->query('INSERT INTO ce_forum_media_variants(attachment_id,variant,mime,width,height,size_bytes,sha256,file_data) VALUES (?,?,?,?,?,?,?,?)',[$id,$name,$v['mime'],$v['width'],$v['height'],$v['size_bytes'],$v['sha256'],base64_encode($v['data'])]);
        $this->query("UPDATE ce_forum_media_state SET state='ready',due_at=0,lease_token=NULL WHERE attachment_id=?",[$id]);
    }
    public function retryImage(string $id): void
    { $this->query("UPDATE ce_forum_media_state SET state='pending',attempts=0,due_at=0,lease_token=NULL WHERE attachment_id=?",[$id]); }
    public function failImage(string $id,int $attempts,int $due): void
    { $this->query("UPDATE ce_forum_media_state SET attempts=?,due_at=?,state=?,lease_token=NULL WHERE attachment_id=? AND state='pending'",[$attempts,$due,$attempts>=5?'failed':'pending',$id]); }
    public function variants(array $ids): array
    { if(!$ids)return [];return $this->rows('SELECT attachment_id,variant,mime,width,height,size_bytes,sha256 FROM ce_forum_media_variants WHERE '.$this->boardFilter('attachment_id',$ids).' ORDER BY width,height,variant'); }
    public function variant(string $id,string $name): ?array
    { return $this->row('SELECT * FROM ce_forum_media_variants WHERE attachment_id=? AND variant=?',[$id,$name]); }
    public function lockOwner(string $user): bool
    {
        if($this->db->driver()==='sqlite')return $this->query('UPDATE ce_users SET id=id WHERE id=?',[$user])->rowCount()===1;
        return $this->row('SELECT id FROM ce_users WHERE id=?'.($this->db->driver()==='pgsql'?' FOR NO KEY UPDATE':' FOR UPDATE'),[$user])!==null;
    }
    public function attachmentVariantUsage(string $id): int
    {return (int)$this->query('SELECT COALESCE(SUM(size_bytes),0) FROM ce_forum_media_variants WHERE attachment_id=?',[$id])->fetchColumn();}
    public function variantUsage(string $user): int
    { return (int)$this->query('SELECT COALESCE(SUM(v.size_bytes),0) FROM ce_forum_media_variants v JOIN ce_forum_attachments a ON a.id=v.attachment_id WHERE a.owner_id=?',[$user])->fetchColumn(); }
    public function missingReplica(): ?array
    { return $this->row('SELECT a.id,a.sha256 FROM ce_forum_attachments a LEFT JOIN ce_forum_media_replicas r ON r.attachment_id=a.id WHERE r.attachment_id IS NULL ORDER BY a.id LIMIT 1'); }
    public function replica(string $id): ?array
    { return $this->row('SELECT * FROM ce_forum_media_replicas WHERE attachment_id=?',[$id]); }
    public function addReplica(string $id,string $destination,string $key): void
    { $this->query("INSERT INTO ce_forum_media_replicas(attachment_id,destination,object_key,state,due_at) VALUES (?,?,?,'pending',0)",[$id,$destination,$key]); }
    public function nextReplica(string $destination,int $now): ?array
    { return $this->row('SELECT r.attachment_id FROM ce_forum_media_replicas r LEFT JOIN ce_forum_attachments a ON a.id=r.attachment_id WHERE r.destination=? AND (r.due_at<=? OR (a.id IS NULL AND r.state=\'ready\')) ORDER BY CASE WHEN a.id IS NULL THEN 0 ELSE 1 END,r.due_at,r.attachment_id LIMIT 1',[$destination,$now]); }
    public function lockReplica(string $id): ?array
    {
        if($this->db->driver()==='sqlite'){$this->query('UPDATE ce_forum_media_replicas SET attachment_id=attachment_id WHERE attachment_id=?',[$id]);return $this->replica($id);}
        return $this->row('SELECT * FROM ce_forum_media_replicas WHERE attachment_id=? FOR UPDATE',[$id]);
    }
    public function replicaResult(string $id,string $state,int $attempts,int $due): void
    { $this->query('UPDATE ce_forum_media_replicas SET state=?,attempts=?,due_at=? WHERE attachment_id=?',[$state,$attempts,$due,$id]); }
    public function deleteReplica(string $id): void
    { $this->query('DELETE FROM ce_forum_media_replicas WHERE attachment_id=?',[$id]); }
    public function mismatchedReplicas(string $destination): int
    {return (int)$this->query('SELECT COUNT(*) FROM ce_forum_media_replicas WHERE destination<>?',[$destination])->fetchColumn();}
    public function stats(): array
    { return ['images'=>$this->rows('SELECT state,COUNT(*) AS total FROM ce_forum_media_state GROUP BY state'),'variants'=>$this->row('SELECT COUNT(*) AS total,COALESCE(SUM(size_bytes),0) AS bytes FROM ce_forum_media_variants'),'replicas'=>$this->rows('SELECT state,COUNT(*) AS total FROM ce_forum_media_replicas GROUP BY state'),'orphans'=>(int)$this->query('SELECT COUNT(*) FROM ce_forum_media_replicas r LEFT JOIN ce_forum_attachments a ON a.id=r.attachment_id WHERE a.id IS NULL')->fetchColumn(),'unregistered'=>(int)$this->query('SELECT COUNT(*) FROM ce_forum_attachments a LEFT JOIN ce_forum_media_replicas r ON r.attachment_id=a.id WHERE r.attachment_id IS NULL')->fetchColumn()]; }
}
