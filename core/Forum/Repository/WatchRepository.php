<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Support\Uuid;

final readonly class WatchRepository extends Repository
{
    public const MODES=['none','internal','email','daily','weekly'];
    public const EMAIL_MODES=['email','daily','weekly'];

    public function mode(string $kind,string $id,string $user): ?string
    {
        [$table,$column]=$this->table($kind);
        return $this->row("SELECT mode FROM $table WHERE $column=? AND user_id=?",[$id,$user])['mode']??null;
    }
    private function table(string $kind): array
    { return match($kind){'thread'=>['ce_forum_watches','thread_id'],'board'=>['ce_forum_board_watches','board_id'],default=>throw new \InvalidArgumentException('Invalid watch kind')}; }
    public function set(string $kind,string $id,string $user,?string $mode,bool $preserve=false): void
    {
        [$table,$column]=$this->table($kind);
        if($mode===null){$this->query("DELETE FROM $table WHERE $column=? AND user_id=?",[$id,$user]);return;}
        $suffix=$this->db->driver()==='mysql'
            ? ' ON DUPLICATE KEY UPDATE mode='.($preserve?'mode':'VALUES(mode)')
            : " ON CONFLICT ($column,user_id) ".($preserve?'DO NOTHING':'DO UPDATE SET mode=excluded.mode');
        $this->query("INSERT INTO $table($column,user_id,mode) VALUES(?,?,?)".$suffix,[$id,$user,$mode]);
    }
    public function autoMode(string $user): string
    { return $this->row('SELECT auto_mode FROM ce_forum_watch_preferences WHERE user_id=?',[$user])['auto_mode']??'off'; }
    public function setAutoMode(string $user,string $mode): void
    {
        $suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE auto_mode=VALUES(auto_mode)':' ON CONFLICT(user_id) DO UPDATE SET auto_mode=excluded.auto_mode';
        $this->query('INSERT INTO ce_forum_watch_preferences(user_id,auto_mode) VALUES(?,?)'.$suffix,[$user,$mode]);
    }
    public function boards(string $user,array $allowed,int $offset): array
    { return $this->rows('SELECT b.id,b.name,w.mode FROM ce_forum_board_watches w JOIN ce_forum_boards b ON b.id=w.board_id WHERE w.user_id=? AND '.$this->boardFilter('b.id',$allowed).' ORDER BY b.name,b.id LIMIT 25 OFFSET '.$offset,[$user]); }
    public function threadBoard(string $thread): ?string
    { return $this->row('SELECT board_id FROM ce_forum_threads WHERE id=?',[$thread])['board_id']??null; }
    public function user(string $user): ?array
    { return $this->row('SELECT id,email,status,email_verified_at FROM ce_users WHERE id=?',[$user]); }
    public function lockUser(string $user): ?array
    { return $this->db->driver()==='pgsql'?$this->row('SELECT * FROM ce_users WHERE id=? FOR NO KEY UPDATE',[$user]):$this->lock('ce_users','id=?',[$user]); }
    /** Called within the publication transaction. No external queue handoff. */
    public function published(string $actor,string $thread,string $post,string $board,bool $reply): array
    {
        $table=$reply?'ce_forum_watches':'ce_forum_board_watches';$column=$reply?'thread_id':'board_id';$id=$reply?$thread:$board;
        $source=$reply?'thread':'board';$now=time();
        $candidates=$this->rows("SELECT w.user_id,w.mode FROM $table w JOIN ce_users u ON u.id=w.user_id WHERE w.$column=? AND w.user_id<>? AND u.status='active'",[$id,$actor]);
        $internal=[];
        foreach($candidates as $w){
            if($w['mode']==='internal'){if(!$reply)$internal[]=$w['user_id'];continue;}
            if(!in_array($w['mode'],self::EMAIL_MODES,true))continue;
            $this->query('INSERT INTO ce_forum_mail_delivery(id,user_id,post_id,board_id,source_kind,mode,due_at) VALUES(?,?,?,?,?,?,?)',[Uuid::v4(),$w['user_id'],$post,$board,$source,$w['mode'],self::due($w['mode'],$now)]);
        }
        return $internal;
    }
    public static function due(string $mode,int $now): int
    { return match($mode){'daily'=>intdiv($now,86400)*86400+86400,'weekly'=>intdiv($now+259200,604800)*604800+604800-259200,default=>$now}; }
    public function candidate(int $now): ?array
    { return $this->row("SELECT id,user_id,mode FROM ce_forum_mail_delivery d WHERE state='pending' AND due_at<=? AND lease_until<=? AND NOT EXISTS (SELECT 1 FROM ce_forum_mail_delivery x WHERE x.user_id=d.user_id AND x.state='pending' AND x.lease_until>?) AND NOT EXISTS (SELECT 1 FROM ce_forum_mail_delivery s WHERE s.user_id=d.user_id AND s.state='sent' AND s.sent_at>?) ORDER BY due_at,id LIMIT 1",[$now,$now,$now,$now-60]); }
    /** Serialize claims for a recipient; the SMTP connection is outside this transaction. */
    public function claim(array $candidate,int $now,string $token): array
    {
        $user=$candidate['user_id'];$mode=$candidate['mode'];
        if($this->lockUser($user)===null)return [];
        if($this->row("SELECT id FROM ce_forum_mail_delivery WHERE user_id=? AND state='pending' AND lease_until>? LIMIT 1",[$user,$now])!==null)return [];
        if($this->row("SELECT id FROM ce_forum_mail_delivery WHERE user_id=? AND state='sent' AND sent_at>? LIMIT 1",[$user,$now-60])!==null)return [];
        $this->query("UPDATE ce_forum_mail_delivery SET state='dead',lease_until=0,lease_token=NULL,last_error='Retry limit exhausted' WHERE user_id=? AND state='pending' AND attempts>=8 AND lease_until<=?",[$user,$now]);
        $rows=$this->rows("SELECT id FROM ce_forum_mail_delivery WHERE user_id=? AND mode=? AND state='pending' AND due_at<=? AND lease_until<=? ORDER BY due_at,id LIMIT ".($mode==='email'?20:100),[$user,$mode,$now,$now]);
        foreach($rows as $r)$this->query("UPDATE ce_forum_mail_delivery SET lease_token=?,lease_until=?,attempts=attempts+1 WHERE id=? AND state='pending'",[$token,$now+300,$r['id']]);
        return array_column($rows,'id');
    }
    public function leased(string $token): array
    { return $this->rows("SELECT d.*,p.thread_id,t.board_id AS current_board FROM ce_forum_mail_delivery d JOIN ce_forum_posts p ON p.id=d.post_id JOIN ce_forum_threads t ON t.id=p.thread_id WHERE d.lease_token=? AND d.state='pending' ORDER BY d.id",[$token]); }
    public function complete(string $token,array $ids,string $state,int $now): void
    {
        foreach($ids as $id)$this->query("UPDATE ce_forum_mail_delivery SET state=?,sent_at=?,lease_token=NULL,lease_until=0,last_error=NULL WHERE id=? AND lease_token=? AND state='pending'",[$state,$state==='sent'?$now:null,$id,$token]);
    }
    public function retry(string $token,int $now): void
    {
        foreach($this->leased($token) as $r){$attempt=(int)$r['attempts'];$this->query("UPDATE ce_forum_mail_delivery SET state=?,due_at=?,lease_until=0,lease_token=NULL,last_error='SMTP delivery failed' WHERE id=? AND lease_token=? AND state='pending'",[$attempt>=8?'dead':'pending',$now+min(3600,30*(2**min(7,$attempt))),$r['id'],$token]);}
    }
    public function unsubscribe(string $user): void
    {
        foreach(['ce_forum_watches','ce_forum_board_watches'] as $table)$this->query("UPDATE $table SET mode='internal' WHERE user_id=? AND mode IN ('email','daily','weekly')",[$user]);
        if(in_array($this->autoMode($user),self::EMAIL_MODES,true))$this->setAutoMode($user,'internal');
        $this->query("UPDATE ce_forum_mail_delivery SET state='cancelled',lease_token=NULL,lease_until=0 WHERE user_id=? AND state='pending'",[$user]);
    }
    public function stats(string $user,array $allowed): array
    {
        $counts=array_fill_keys(['pending','sent','cancelled','dead'],0);
        foreach($this->rows('SELECT d.state,COUNT(*) AS total FROM ce_forum_mail_delivery d JOIN ce_forum_posts p ON p.id=d.post_id JOIN ce_forum_threads t ON t.id=p.thread_id WHERE d.user_id=? AND '.$this->boardFilter('t.board_id',$allowed).' AND '.$this->boardFilter('d.board_id',$allowed).' GROUP BY d.state',[$user]) as $r)$counts[$r['state']]=(int)$r['total'];
        return $counts;
    }
    public function cancel(string $kind,string $id,string $user): void
    {
        $filter=$kind==='board'?"source_kind='board' AND board_id=?":"source_kind='thread' AND post_id IN (SELECT id FROM ce_forum_posts WHERE thread_id=?)";
        $this->query("UPDATE ce_forum_mail_delivery SET state='cancelled',lease_token=NULL,lease_until=0 WHERE user_id=? AND state='pending' AND $filter",[$user,$id]);
    }
}
