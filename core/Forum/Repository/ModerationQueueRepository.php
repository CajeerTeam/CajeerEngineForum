<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Support\Uuid;

final readonly class ModerationQueueRepository extends Repository
{
    public function required(string $board): bool
    {
        // Publication already holds a shared board lock on PostgreSQL. Do not upgrade it.
        $row=$this->db->driver()==='pgsql'?$this->row('SELECT review_required FROM ce_forum_boards WHERE id=? FOR SHARE',[$board]):$this->lock('ce_forum_boards','id=?',[$board]);
        return (int)($row['review_required']??0)===1;
    }
    public function policy(string $board,bool $enabled): void
    {$this->query('UPDATE ce_forum_boards SET review_required=? WHERE id=?',[(int)$enabled,$board]);}
    public function insert(array $row): void
    {$this->query('INSERT INTO ce_forum_review_submissions(id,author_id,board_id,thread_id,payload,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$row['id'],$row['author_id'],$row['board_id'],$row['thread_id'],$row['payload'],$row['created_at'],$row['created_at']]);}
    public function countPending(string $author): int
    {return count($this->rows("SELECT id FROM ce_forum_review_submissions WHERE author_id=? AND status='pending' LIMIT 25".($this->db->driver()==='sqlite'?'':' FOR UPDATE'),[$author]));}
    public function hold(string $submission,string $file,int $until): void
    {$this->query('INSERT INTO ce_forum_review_files(attachment_id,submission_id) VALUES(?,?)',[$file,$submission]);$this->query('UPDATE ce_forum_attachments SET expires_at=CASE WHEN expires_at<? THEN ? ELSE expires_at END WHERE id=?',[$until,$until,$file]);}
    public function held(string $file): bool
    {return $this->row('SELECT attachment_id FROM ce_forum_review_files WHERE attachment_id=?'.($this->db->driver()==='sqlite'?'':' FOR UPDATE'),[$file])!==null;}
    public function heldBy(string $file,string $submission,bool $lock=false): bool
    {return $this->row('SELECT attachment_id FROM ce_forum_review_files WHERE attachment_id=? AND submission_id=?'.($lock&&$this->db->driver()!=='sqlite'?' FOR UPDATE':''),[$file,$submission])!==null;}
    public function renew(string $file,int $until): void
    {$this->query('UPDATE ce_forum_attachments SET expires_at=CASE WHEN expires_at<? THEN ? ELSE expires_at END WHERE id=?',[$until,$until,$file]);}
    public function release(string $submission): void
    {$this->query('DELETE FROM ce_forum_review_files WHERE submission_id=?',[$submission]);}
    public function lockSubmission(string $id): ?array
    {return $this->lock('ce_forum_review_submissions','id=?',[$id]);}
    private const SUB='SELECT s.*,COALESCE(t.board_id,s.board_id) AS current_board_id FROM ce_forum_review_submissions s LEFT JOIN ce_forum_threads t ON t.id=s.thread_id';
    private const REPORT='SELECT r.*,p.thread_id,t.board_id AS current_board_id,t.title,p.body AS current_body,p.body_format AS current_format,(SELECT COUNT(*) FROM ce_forum_reports related WHERE related.post_id=r.post_id AND related.status=\'open\') AS related_open FROM ce_forum_reports r JOIN ce_forum_posts p ON p.id=r.post_id JOIN ce_forum_threads t ON t.id=p.thread_id';
    public function submission(string $id): ?array {return $this->row(self::SUB.' WHERE s.id=?',[$id]);}
    public function report(string $id): ?array {return $this->row(self::REPORT.' WHERE r.id=?',[$id]);}
    public function list(string $kind,array $boards,string $status,int $offset,?string $assigned): array
    {
        $sub=$kind==='submission';$a=$sub?'s':'r';$sql=($sub?self::SUB:self::REPORT).' WHERE '.$this->boardFilter($sub?'COALESCE(t.board_id,s.board_id)':'t.board_id',$boards);$params=[];
        if($status!=='all'){$sql.=" AND $a.status=?";$params[]=$status;}
        if($assigned!==null){$sql.=$assigned==='unassigned'?" AND $a.assigned_to IS NULL":" AND $a.assigned_to=?";if($assigned!=='unassigned')$params[]=$assigned;}
        return $this->rows($sql." ORDER BY $a.priority DESC,$a.created_at,$a.id LIMIT 25 OFFSET ".$offset,$params);
    }
    public function own(string $author,int $offset): array
    {return $this->rows(self::SUB.' WHERE s.author_id=? ORDER BY s.created_at DESC,s.id LIMIT 25 OFFSET '.$offset,[$author]);}
    public function event(string $kind,string $id,?string $actor,string $action,string $detail,int $version=1): void
    {$this->query('INSERT INTO ce_forum_moderation_events(id,kind,case_id,actor_id,revision_number,action,detail,created_at) VALUES(?,?,?,?,?,?,?,?)',[Uuid::v4(),$kind,$id,$actor,$version,$action,$detail,time()]);}
    public function history(string $kind,string $id,int $offset): array
    {return $this->rows('SELECT * FROM ce_forum_moderation_events WHERE kind=? AND case_id=? ORDER BY revision_number DESC LIMIT 25 OFFSET '.$offset,[$kind,$id]);}
    public function update(string $kind,string $id,int $version,string $action,mixed $value,string $actor): bool
    {
        $table=$kind==='report'?'ce_forum_reports':'ce_forum_review_submissions';
        $set=match($action){'assign'=>'assigned_to=?','priority'=>'priority=?','note'=>'revision_number=revision_number', 'resolve'=>"status='resolved',resolved_by=?,decision=?,decision_kind=?,resolved_at=?",'approve'=>"status='approved',decided_by=?,decision=?,result_id=?",'reject'=>"status='rejected',decided_by=?,decision=?",'cancel'=>"status='cancelled',decided_by=?,decision=?",default=>throw new \LogicException('Invalid moderation action')};
        $params=match($action){'note'=>[],'resolve'=>[$actor,$value['reason'],$value['disposition'],time()],'approve'=>[$actor,$value['reason'],$value['id']],'reject','cancel'=>[$actor,$value],default=>[$value]};
        if($action==='note')$set='revision_number=revision_number+1';else $set.=',revision_number=revision_number+1';
        if($kind==='submission'){$set.=',updated_at=?';$params[]=time();}
        return $this->query("UPDATE $table SET $set WHERE id=? AND revision_number=?",[...$params,$id,$version])->rowCount()===1;
    }
}
