<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

/** Durable per-user watermarks. All write callers hold the relevant thread locks. */
final readonly class ReadStateRepository extends Repository
{
    public function cursor(string $user,string $thread): int
    { return (int)$this->query('SELECT read_sequence FROM ce_forum_read_state WHERE user_id=? AND thread_id=?',[$user,$thread])->fetchColumn(); }

    public function post(string $thread,string $id): ?array
    { return $this->row('SELECT id,read_sequence FROM ce_forum_live_posts WHERE thread_id=? AND id=?',[$thread,$id]); }

    public function mark(string $user,string $thread,int $sequence): void
    {
        $sql='INSERT INTO ce_forum_read_state(user_id,thread_id,read_sequence) VALUES(?,?,?)'.$this->upsert();
        $this->query($sql,[$user,$thread,$sequence]);
    }

    private function upsert(): string
    {
        return $this->db->driver()==='mysql'
            ? ' ON DUPLICATE KEY UPDATE read_sequence=GREATEST(ce_forum_read_state.read_sequence,VALUES(read_sequence))'
            : ' ON CONFLICT (user_id,thread_id) DO UPDATE SET read_sequence=CASE WHEN excluded.read_sequence>ce_forum_read_state.read_sequence THEN excluded.read_sequence ELSE ce_forum_read_state.read_sequence END';
    }

    /** Monotone, snapshot-captured ID stream, bounded to 1 MiB of PHP memory.
     * A million-topic forum previously allocated two complete PHP arrays.
     * SplTempFileObject spills automatically to an OS-managed private temp file.
     */
    public function candidateThreads(array $boards): \SplTempFileObject
    {
        $captured=new \SplTempFileObject(1024*1024);
        if($boards===[])return $captured;
        $query=$this->query('SELECT id FROM ce_forum_live_threads WHERE '.$this->boardFilter('board_id',$boards).' ORDER BY id');
        while(($id=$query->fetchColumn())!==false)$captured->fwrite($id."\n");
        $query->closeCursor();
        $captured->rewind();
        return $captured;
    }

    /** Lock captured threads in UUID order; stream the locked ID/board pairs to
     * disk-backed storage. No mark happens before the tree and ACL are rechecked.
     */
    public function lockCandidates(\SplTempFileObject $candidates): \SplTempFileObject
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Thread locks require a transaction');
        $locked=new \SplTempFileObject(1024*1024);
        $ids=[];
        $flush=function()use(&$ids,$locked): void {
            if(!$ids)return;
            $sql='SELECT id,board_id FROM ce_forum_threads WHERE deleted_at IS NULL AND '.$this->boardFilter('id',$ids).' ORDER BY id';
            if($this->db->driver()!=='sqlite')$sql.=' FOR UPDATE';
            $cursor=$this->query($sql);
            while(($row=$cursor->fetch(\PDO::FETCH_ASSOC))!==false)$locked->fwrite($row['id'].' '.$row['board_id']."\n");
            $cursor->closeCursor();
            $ids=[];
        };
        $candidates->rewind();
        while(!$candidates->eof()){
            $id=trim($candidates->fgets());
            if($id==='')continue;
            $ids[]=$id;
            if(count($ids)===500)$flush();
        }
        $flush();
        $locked->rewind();
        return $locked;
    }

    /** Compute actual unread posts, not the difference between counters (which may contain holes). */
    public function decorateThreads(array $threads,string $user): array
    {
        if ($user==='' || !$threads) return $threads;
        $counts=$this->rows('SELECT t.id AS thread_id,(SELECT COUNT(*) FROM ce_forum_posts p WHERE p.thread_id=t.id AND p.deleted_at IS NULL AND p.read_sequence>COALESCE(r.read_sequence,0)) AS unread_posts FROM ce_forum_live_threads t LEFT JOIN ce_forum_read_state r ON r.thread_id=t.id AND r.user_id=? WHERE '.$this->boardFilter('t.id',array_unique(array_column($threads,'id'))),[$user]);
        $map=array_column($counts,'unread_posts','thread_id');
        foreach($threads as &$thread) $thread['unread_posts']=(int)($map[$thread['id']]??0);
        return $threads;
    }

    public function boardCounts(string $user,array $boards): array
    {
        if ($user==='' || !$boards) return [];
        return array_column($this->rows('SELECT t.board_id,COUNT(*) AS unread_threads FROM ce_forum_live_threads t LEFT JOIN ce_forum_read_state r ON r.thread_id=t.id AND r.user_id=? WHERE EXISTS (SELECT 1 FROM ce_forum_posts p WHERE p.thread_id=t.id AND p.deleted_at IS NULL AND p.read_sequence>COALESCE(r.read_sequence,0)) AND '.$this->boardFilter('t.board_id',$boards).' GROUP BY t.board_id',[$user]),'unread_threads','board_id');
    }

    public function unread(string $user,array $allowed,int $offset): array
    {
        return $this->decorateThreads($this->rows('SELECT t.id,t.title,t.prefix,t.updated_at,t.pinned,t.locked FROM ce_forum_live_threads t LEFT JOIN ce_forum_read_state r ON r.thread_id=t.id AND r.user_id=? WHERE '.$this->boardFilter('t.board_id',$allowed).' AND EXISTS (SELECT 1 FROM ce_forum_posts p WHERE p.thread_id=t.id AND p.deleted_at IS NULL AND p.read_sequence>COALESCE(r.read_sequence,0)) ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET '.$offset,[$user]),$user);
    }

    public function summary(string $user,string $thread): array
    {
        $cursor=$this->cursor($user,$thread);
        $first=$this->row('SELECT id,ordinal FROM ce_forum_live_posts WHERE thread_id=? AND read_sequence>? ORDER BY ordinal LIMIT 1',[$thread,$cursor]);
        $count=(int)$this->query('SELECT COUNT(*) FROM ce_forum_live_posts WHERE thread_id=? AND read_sequence>?',[$thread,$cursor])->fetchColumn();
        $page=$first===null?null:1+intdiv((int)$this->query('SELECT COUNT(*) FROM ce_forum_live_posts WHERE thread_id=? AND ordinal<?',[$thread,$first['ordinal']])->fetchColumn(),25);
        return ['read_sequence'=>$cursor,'unread_posts'=>$count,'first_unread'=>$first===null?null:['id'=>$first['id'],'page'=>$page]];
    }
}
