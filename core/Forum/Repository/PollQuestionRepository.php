<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

final readonly class PollQuestionRepository extends Repository
{
    public function createPoll(string $id,array $p): void
    {
        $this->query('INSERT INTO ce_forum_polls(thread_id,question,max_choices,allow_change,visibility,closes_at) VALUES(?,?,?,?,?,?)',[$id,$p['question'],$p['max_choices'],(int)$p['allow_change'],$p['visibility'],$p['closes_at']]);
        foreach($p['options'] as $i=>$label) $this->query('INSERT INTO ce_forum_poll_options(thread_id,option_no,label) VALUES(?,?,?)',[$id,$i+1,$label]);
    }
    public function createQuestion(string $id): void { $this->query('INSERT INTO ce_forum_questions(thread_id) VALUES(?)',[$id]); }
    public function poll(string $id): ?array { return $this->row('SELECT * FROM ce_forum_polls WHERE thread_id=?',[$id]); }
    public function question(string $id): ?array { return $this->row('SELECT * FROM ce_forum_questions WHERE thread_id=?',[$id]); }
    public function visibleQuestion(string $id): ?array { return $this->row('SELECT q.thread_id,p.id AS accepted_post_id FROM ce_forum_questions q LEFT JOIN ce_forum_live_posts p ON p.id=q.accepted_post_id WHERE q.thread_id=?',[$id]); }
    public function options(string $id): array { return $this->rows('SELECT option_no,label FROM ce_forum_poll_options WHERE thread_id=? ORDER BY option_no',[$id]); }
    public function choices(string $id,string $user): array { return array_map('intval',array_column($this->rows('SELECT option_no FROM ce_forum_poll_votes WHERE thread_id=? AND user_id=? ORDER BY option_no',[$id,$user]),'option_no')); }
    public function tally(string $id): array { return $this->rows('SELECT option_no,COUNT(*) AS votes FROM ce_forum_poll_votes WHERE thread_id=? GROUP BY option_no',[$id]); }
    public function voters(string $id): int { return (int)$this->query('SELECT COUNT(DISTINCT user_id) FROM ce_forum_poll_votes WHERE thread_id=?',[$id])->fetchColumn(); }
    public function vote(string $id,string $user,array $choices): void
    {
        $this->query('DELETE FROM ce_forum_poll_votes WHERE thread_id=? AND user_id=?',[$id,$user]);
        foreach($choices as $n) $this->query('INSERT INTO ce_forum_poll_votes(thread_id,user_id,option_no) VALUES(?,?,?)',[$id,$user,$n]);
    }
    public function close(string $id,bool $closed,?int $deadline): void { $this->query('UPDATE ce_forum_polls SET closed=?,closes_at=? WHERE thread_id=?',[(int)$closed,$deadline,$id]); }
    public function accept(string $id,?string $post): void { $this->query('UPDATE ce_forum_questions SET accepted_post_id=? WHERE thread_id=?',[$post,$id]); }
    public function answerVote(string $post,string $user,bool $enabled): void
    {
        $this->query('DELETE FROM ce_forum_answer_votes WHERE post_id=? AND user_id=?',[$post,$user]);
        if($enabled) $this->query('INSERT INTO ce_forum_answer_votes(post_id,user_id) VALUES(?,?)',[$post,$user]);
    }
    public function answerPage(string $id,string $post): int
    { return 1+intdiv((int)$this->query('SELECT COUNT(*) FROM ce_forum_live_posts p WHERE p.thread_id=? AND p.ordinal<(SELECT ordinal FROM ce_forum_live_posts WHERE id=? AND thread_id=?)',[$id,$post,$id])->fetchColumn(),25); }
    public function answerStats(array $ids,string $viewer): array
    {
        if(!$ids)return [];
        $in=implode(',',array_fill(0,count($ids),'?'));
        $rows=$this->rows("SELECT post_id,COUNT(*) AS score,SUM(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS voted FROM ce_forum_answer_votes WHERE post_id IN ($in) GROUP BY post_id",[$viewer===''?null:$viewer,...$ids]);
        $out=[];foreach($rows as $r)$out[$r['post_id']]=['score'=>(int)$r['score'],'voted'=>(int)$r['voted']===1];return $out;
    }
    public function answers(string $id,string $viewer,int $offset,bool $ranked): array
    {
        $sql='SELECT p.id,p.ordinal,p.author_id,u.name AS author,p.created_at,(SELECT COUNT(*) FROM ce_forum_answer_votes v WHERE v.post_id=p.id) AS score,(SELECT COUNT(*) FROM ce_forum_answer_votes v WHERE v.post_id=p.id AND v.user_id=?) AS voted,(SELECT COUNT(*) FROM ce_forum_live_posts p2 WHERE p2.thread_id=p.thread_id AND p2.ordinal<p.ordinal) AS preceding FROM ce_forum_live_posts p LEFT JOIN ce_users u ON u.id=p.author_id WHERE p.thread_id=? AND p.ordinal>(SELECT MIN(p3.ordinal) FROM ce_forum_live_posts p3 WHERE p3.thread_id=p.thread_id) ORDER BY '.($ranked?'score DESC,p.ordinal':'p.ordinal').' LIMIT 25 OFFSET '.$offset;
        $rows=$this->rows($sql,[$viewer===''?null:$viewer,$id]);
        foreach($rows as &$r){$r['ordinal']=(int)$r['ordinal'];$r['score']=(int)$r['score'];$r['voted']=(int)$r['voted']===1;$r['page']=1+intdiv((int)$r['preceding'],25);unset($r['preceding']);}unset($r);return $rows;
    }
    public function questions(array $allowed,string $status,int $offset): array
    {
        $sql='SELECT t.id,t.title,t.updated_at,p.id AS accepted_post_id FROM ce_forum_questions q JOIN ce_forum_live_threads t ON t.id=q.thread_id LEFT JOIN ce_forum_live_posts p ON p.id=q.accepted_post_id WHERE '.$this->boardFilter('t.board_id',$allowed);
        $sql.=match($status){'solved'=>' AND p.id IS NOT NULL','open'=>' AND p.id IS NULL','unanswered'=>' AND (SELECT COUNT(*) FROM ce_forum_live_posts p WHERE p.thread_id=t.id)=1',default=>''};
        return $this->rows($sql.' ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET '.$offset);
    }
    public function detachAnswer(string $source,string $post): void
    {
        $this->query('UPDATE ce_forum_questions SET accepted_post_id=NULL WHERE thread_id=? AND accepted_post_id=?',[$source,$post]);
        $this->query('DELETE FROM ce_forum_answer_votes WHERE post_id=?',[$post]);
    }
}
