<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class DraftRepository extends Repository
{
    public function find(string $id,string $user): ?array
    {return $this->row('SELECT * FROM ce_forum_drafts WHERE id=? AND user_id=?',[$id,$user]);}
    public function lockDraft(string $id,string $user): ?array
    {return $this->lock('ce_forum_drafts','id=? AND user_id=?',[$id,$user]);}
    public function countActive(string $user): int
    {return (int)$this->query('SELECT COUNT(*) FROM ce_forum_drafts WHERE user_id=? AND result_json IS NULL AND expires_at>?',[$user,time()])->fetchColumn();}
    public function list(string $user,int $offset,?string $scope=null,?string $target=null): array
    {
        $sql='SELECT * FROM ce_forum_drafts WHERE user_id=? AND result_json IS NULL AND expires_at>?';$args=[$user,time()];
        if($scope!==null){$sql.=' AND scope=?';$args[]=$scope;if($target!==null){$sql.=' AND target_id=?';$args[]=$target;}else $sql.=' AND target_id IS NULL';}
        return $this->rows($sql.' ORDER BY updated_at DESC,id LIMIT 25 OFFSET '.$offset,$args);
    }
    public function insert(array $d): void
    {$this->query('INSERT INTO ce_forum_drafts(id,user_id,scope,target_id,revision_number,payload,updated_at,expires_at) VALUES(?,?,?,?,1,?,?,?)',[$d['id'],$d['user_id'],$d['scope'],$d['target_id'],$d['payload'],$d['updated_at'],$d['expires_at']]);}
    public function save(string $id,string $user,int $revision,string $payload,int $now,int $expires): bool
    {return $this->query('UPDATE ce_forum_drafts SET payload=?,revision_number=revision_number+1,updated_at=?,expires_at=? WHERE id=? AND user_id=? AND revision_number=? AND result_json IS NULL',[$payload,$now,$expires,$id,$user,$revision])->rowCount()===1;}
    public function complete(string $id,string $user,int $revision,string $result,int $now): bool
    {return $this->query("UPDATE ce_forum_drafts SET payload='{}',result_json=?,updated_at=?,expires_at=? WHERE id=? AND user_id=? AND revision_number=? AND result_json IS NULL",[$result,$now,$now+2592000,$id,$user,$revision])->rowCount()===1;}
    public function delete(string $id,string $user,int $revision): bool
    {return $this->query('DELETE FROM ce_forum_drafts WHERE id=? AND user_id=? AND revision_number=? AND result_json IS NULL',[$id,$user,$revision])->rowCount()===1;}
    public function cleanup(int $now): int
    {
        $rows=$this->rows('SELECT id,user_id FROM ce_forum_drafts WHERE expires_at<=? ORDER BY expires_at,id LIMIT 100',[$now]);$n=0;
        foreach($rows as $r)$n+=$this->query('DELETE FROM ce_forum_drafts WHERE id=? AND user_id=? AND expires_at<=?',[$r['id'],$r['user_id'],$now])->rowCount();return $n;
    }
    public function extendAttachment(string $id,string $user,int $expires): void
    {$this->query('UPDATE ce_forum_attachments SET expires_at=CASE WHEN expires_at<? THEN ? ELSE expires_at END WHERE id=? AND owner_id=? AND post_id IS NULL AND message_id IS NULL',[$expires,$expires,$id,$user]);}
}
