<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Forum\ForumException;

final readonly class RevisionRepository extends Repository
{
    private function names(string $kind): array
    {
        return match($kind){'post'=>['ce_forum_posts','ce_forum_post_revisions','post_id'],'message'=>['ce_forum_conversation_messages','ce_forum_conversation_revisions','message_id'],default=>throw new \LogicException('Invalid revision kind')};
    }
    public function snapshot(string $kind,string $id,int $number,string $body,string $format,?string $editor,string $reason): void
    {
        if(!$this->db->connection()->inTransaction())throw new \LogicException('Revision requires a write transaction');
        [, $table,$key]=$this->names($kind);
        $this->query("INSERT INTO $table($key,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,?,?,?,?,?,?)",[$id,$number,$body,$format,$editor,$reason,time()]);
    }
    public function initial(string $kind,string $id,string $body,string $format,string $editor): void
    { $this->snapshot($kind,$id,1,$body,$format,$editor,''); }
    /** Parent publication is already locked, and the same transaction updates its body. */
    public function advance(string $kind,array $old,string $body,string $format,string $editor,?int $expected,string $reason,bool $force=false): int
    {
        [$parent,$table,$key]=$this->names($kind);$id=$old['id'];$number=(int)$old['revision_number'];
        if($expected!==null&&$expected!==$number)throw new ForumException('Сообщение уже изменено. Откройте актуальную версию.',409);
        if(!$force&&$body===$old['body']&&$format===$old['body_format'])return $number;
        // Compatibility for imported rows; never fabricate prior edits.
        if($number===1&&!$this->row("SELECT revision_number FROM $table WHERE $key=? AND revision_number=1",[$id]))
            $this->snapshot($kind,$id,1,$old['body'],$old['body_format'],$old['author_id'],'Import baseline');
        $this->snapshot($kind,$id,$number+1,$body,$format,$editor,$reason);
        if($this->query("UPDATE $parent SET revision_number=? WHERE id=? AND revision_number=?",[$number+1,$id,$number])->rowCount()!==1)throw new ForumException('Конфликт версии сообщения.',409);
        return $number+1;
    }
    private static function normalize(array $row): array { $row['revision_number']=(int)$row['revision_number'];$row['created_at']=(int)$row['created_at'];return $row; }
    public function history(string $kind,string $id,int $offset): array
    { [, $table,$key]=$this->names($kind);return array_map(self::normalize(...),$this->rows("SELECT revision_number,body,body_format,editor_id,edit_reason,created_at FROM $table WHERE $key=? ORDER BY revision_number DESC LIMIT 25 OFFSET ".$offset,[$id])); }
    public function version(string $kind,string $id,int $number): array
    { [, $table,$key]=$this->names($kind);return self::normalize($this->row("SELECT revision_number,body,body_format,editor_id,edit_reason,created_at FROM $table WHERE $key=? AND revision_number=?",[$id,$number])??throw new ForumException('Версия не найдена.',404)); }
}
