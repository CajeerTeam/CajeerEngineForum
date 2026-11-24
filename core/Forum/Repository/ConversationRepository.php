<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Support\Uuid;

final readonly class ConversationRepository extends Repository
{
    // All conversation mutations share this lock. This also serializes block/invite/send decisions.
    public function lockWrites(): void
    {
        if ($this->lock('ce_forum_conversation_lock','id=?',[1])===null) throw new \LogicException('Conversation lock missing');
    }
    public function activeUser(string $id): bool { return $this->row("SELECT id FROM ce_users WHERE id=? AND status='active'",[$id])!==null; }
    public function eligibleUser(string $id): bool { if(!$this->activeUser($id)||\CajeerEngine\Forum\Service\SanctionService::blocks((new SanctionRepository($this->db))->active($id,time()),'suspension'))return false;$scopes=(new \CajeerEngine\Database\Repository\RoleRepository($this->db))->permissionsForUser($id);return in_array('*',$scopes,true)||in_array('forum:write',$scopes,true); }
    public function membership(string $id,string $user): array
    {
        return $this->row('SELECT c.*,p.joined_sequence,p.read_sequence,p.archived,p.muted FROM ce_forum_conversations c JOIN ce_forum_conversation_participants p ON p.conversation_id=c.id WHERE c.id=? AND p.user_id=? AND p.active=1',[$id,$user])??throw new ForumException('Переписка не найдена.',404);
    }
    public function participants(string $id): array
    { return $this->rows('SELECT p.user_id,u.name FROM ce_forum_conversation_participants p JOIN ce_users u ON u.id=p.user_id WHERE p.conversation_id=? AND p.active=1 ORDER BY p.joined_sequence,p.user_id',[$id]); }
    public function incoming(string $user): string {  $r=$this->row('SELECT incoming,contact_mode FROM ce_forum_conversation_preferences WHERE user_id=?',[$user]);return ($r['incoming']??'members')==='none'?'none':($r['contact_mode']??'members'); }
    public function publishedCount(string $user): int
    { return (int)$this->query('SELECT COUNT(*) FROM ce_forum_live_posts WHERE author_id=?',[$user])->fetchColumn(); }
    public function follows(string $user,string $target): bool
    { return $this->row("SELECT user_id FROM ce_forum_member_relations WHERE user_id=? AND target_id=? AND kind='follow'",[$user,$target])!==null; }
    public function removeFollows(string $a,string $b): void
    { $this->query("DELETE FROM ce_forum_member_relations WHERE kind='follow' AND ((user_id=? AND target_id=?) OR (user_id=? AND target_id=?))",[$a,$b,$b,$a]); }
    public function postAuthor(string $id): ?string
    { return $this->row('SELECT author_id FROM ce_forum_live_posts WHERE id=?',[$id])['author_id']??null; }
    public function blocked(string $a,string $b): bool { return $this->row('SELECT user_id FROM ce_forum_user_blocks WHERE (user_id=? AND blocked_id=?) OR (user_id=? AND blocked_id=?)',[$a,$b,$b,$a])!==null; }
    public function limit(string $user,string $action,int $seconds,int $max): void
    {
        $now=time();$row=$this->row('SELECT window_start,hits FROM ce_forum_conversation_limits WHERE user_id=? AND action=?',[$user,$action]);
        if($row===null){$this->query('INSERT INTO ce_forum_conversation_limits(user_id,action,window_start,hits) VALUES (?,?,?,1)',[$user,$action,$now]);return;}
        if($now-(int)$row['window_start'] >= $seconds){$this->query('UPDATE ce_forum_conversation_limits SET window_start=?,hits=1 WHERE user_id=? AND action=?',[$now,$user,$action]);return;}
        if((int)$row['hits'] >= $max)throw new ForumException('Слишком много действий. Повторите позже.',429);
        $this->query('UPDATE ce_forum_conversation_limits SET hits=hits+1 WHERE user_id=? AND action=?',[$user,$action]);
    }
    public function create(string $owner,string $title,string $kind): string
    {
        $id=Uuid::v4();$now=time();$this->query('INSERT INTO ce_forum_conversations(id,owner_id,title,kind,created_at,updated_at) VALUES (?,?,?,?,?,?)',[$id,$owner,$title,$kind,$now,$now]);return $id;
    }
    public function join(string $id,string $user,int $message_sequence): void
    {
        $existing=$this->row('SELECT active FROM ce_forum_conversation_participants WHERE conversation_id=? AND user_id=?',[$id,$user]);
        if($existing!==null){if((int)$existing['active']===1)throw new ForumException('Участник уже в переписке.',409);$this->query('UPDATE ce_forum_conversation_participants SET joined_sequence=?,read_sequence=?,active=1,archived=0,muted=0 WHERE conversation_id=? AND user_id=?',[$message_sequence,$message_sequence-1,$id,$user]);return;}
        $this->query('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence) VALUES (?,?,?,?)',[$id,$user,$message_sequence,$message_sequence-1]);
    }
    public function append(string $id,string $user,string $body,int $message_sequence,string $format='plain'): string
    {
        $msg=Uuid::v4();$now=time();$this->query('INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,created_at,body_format) VALUES (?,?,?,?,?,?,?)',[$msg,$id,$user,$message_sequence,$body,$now,$format]);
        $this->query('UPDATE ce_forum_conversations SET last_sequence=?,updated_at=? WHERE id=?',[$message_sequence,$now,$id]);
        $this->query('UPDATE ce_forum_conversation_participants SET archived=0 WHERE conversation_id=? AND active=1',[$id]);return $msg;
    }
    public function message(string $id,string $msg,int $joined): array
    { return $this->row('SELECT * FROM ce_forum_conversation_messages WHERE id=? AND conversation_id=? AND message_sequence>=?',[$msg,$id,$joined])??throw new ForumException('Сообщение не найдено.',404); }
    public function edit(string $msg,string $body,?string $format=null): void { $this->query('UPDATE ce_forum_conversation_messages SET body=?,body_format=COALESCE(?,body_format),edited_at=? WHERE id=?',[$body,$format,time(),$msg]); }
    public function mark(string $id,string $user,int $seq): void { $this->query('UPDATE ce_forum_conversation_participants SET read_sequence=? WHERE conversation_id=? AND user_id=? AND read_sequence<?',[$seq,$id,$user,$seq]); }
    public function leave(string $id,string $user): void
    {
        $this->query('UPDATE ce_forum_conversation_participants SET active=0 WHERE conversation_id=? AND user_id=?',[$id,$user]);
        $this->query('UPDATE ce_forum_conversations SET owner_id=? WHERE id=? AND owner_id=?',[$this->participants($id)[0]['user_id']??null,$id,$user]);
    }
    public function flags(string $id,string $user,array $flags): void { foreach($flags as $key=>$value){if(!in_array($key,['archived','muted'],true))throw new \LogicException('Invalid flag');$this->query('UPDATE ce_forum_conversation_participants SET '.$key.'=? WHERE conversation_id=? AND user_id=?',[(int)$value,$id,$user]);} }
    public function inbox(string $user,int $offset,bool $archived,bool $notifications=false,string $search=''): array
    {
        $sql='SELECT c.id,c.title,c.kind,c.updated_at,c.owner_id,p.archived,p.muted,p.read_sequence,p.joined_sequence,c.last_sequence,(SELECT COUNT(*) FROM ce_forum_conversation_messages m WHERE m.conversation_id=c.id AND m.message_sequence>p.read_sequence AND m.message_sequence>=p.joined_sequence AND (m.author_id IS NULL OR m.author_id<>p.user_id)) AS unread FROM ce_forum_conversations c JOIN ce_forum_conversation_participants p ON p.conversation_id=c.id WHERE p.user_id=? AND p.active=1';
        $params=[$user];if($notifications)$sql.=' AND p.muted=0 AND EXISTS (SELECT 1 FROM ce_forum_conversation_messages m WHERE m.conversation_id=c.id AND m.message_sequence>p.read_sequence AND m.message_sequence>=p.joined_sequence AND (m.author_id IS NULL OR m.author_id<>p.user_id))';else{$sql.=' AND p.archived=?';$params[]=(int)$archived;}
        if($search!==''){$sql.=" AND (LOWER(c.title) LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM ce_forum_conversation_messages m WHERE m.conversation_id=c.id AND m.message_sequence>=p.joined_sequence AND LOWER(m.body) LIKE ? ESCAPE '!'))";$term='%'.strtr(mb_strtolower($search),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$params[]=$term;$params[]=$term;}
        return array_map(self::normalize(...),$this->rows($sql.' ORDER BY c.updated_at DESC,c.id DESC LIMIT 25 OFFSET '.$offset,$params));
    }
    public function unread(string $user,bool $notifications=false): int
    { return (int)$this->query('SELECT COUNT(*) FROM ce_forum_conversation_participants p JOIN ce_forum_conversation_messages m ON m.conversation_id=p.conversation_id WHERE p.user_id=? AND p.active=1 AND m.message_sequence>p.read_sequence AND m.message_sequence>=p.joined_sequence AND (m.author_id IS NULL OR m.author_id<>p.user_id)'.($notifications?' AND p.muted=0':''),[$user])->fetchColumn(); }
    /** Both alert counts from one indexed membership/message join. */
    public function unreadCounts(string $user): array
    {
        $row=$this->row('SELECT COUNT(*) AS total, COUNT(CASE WHEN p.muted=0 THEN 1 END) AS notified FROM ce_forum_conversation_participants p JOIN ce_forum_conversation_messages m ON m.conversation_id=p.conversation_id WHERE p.user_id=? AND p.active=1 AND m.message_sequence>p.read_sequence AND m.message_sequence>=p.joined_sequence AND (m.author_id IS NULL OR m.author_id<>p.user_id)',[$user]);
        return ['conversation_unread'=>(int)$row['notified'],'conversation_total_unread'=>(int)$row['total']];
    }
    public static function normalize(array $row): array { foreach(['last_sequence','joined_sequence','read_sequence','message_sequence','revision_number','created_at','updated_at','edited_at','resolved_at','archived','muted','unread'] as $k)if(isset($row[$k]))$row[$k]=(int)$row[$k];return $row; }
    public function messages(string $id,int $joined,int $offset): array
    { return array_map(static function(array $m): array { $content=\CajeerEngine\Forum\Content\ForumContentRenderer::render($m['body'],$m['body_format']);$m['body_html']=$content['html'];$m['renderer_version']=$content['renderer_version'];return self::normalize($m); },$this->rows('SELECT m.id,m.author_id,u.name AS author_name,m.message_sequence,m.body,m.body_format,m.created_at,m.edited_at,m.revision_number FROM ce_forum_conversation_messages m LEFT JOIN ce_users u ON u.id=m.author_id WHERE m.conversation_id=? AND m.message_sequence>=? ORDER BY m.message_sequence DESC LIMIT 25 OFFSET '.$offset,[$id,$joined])); }
    public function settings(string $user): array { return ['incoming'=>$this->incoming($user),'blocked'=>$this->rows('SELECT b.blocked_id AS id,u.name FROM ce_forum_user_blocks b JOIN ce_users u ON u.id=b.blocked_id WHERE b.user_id=? ORDER BY u.name,u.id',[$user])]; }
    public function setIncoming(string $user,string $mode): void
    { $legacy=$mode==='none'?'none':'members';$contact=in_array($mode,['trusted','following'],true)?$mode:'members';if($this->row('SELECT user_id FROM ce_forum_conversation_preferences WHERE user_id=?',[$user])===null)$this->query('INSERT INTO ce_forum_conversation_preferences(user_id,incoming,contact_mode) VALUES (?,?,?)',[$user,$legacy,$contact]);else $this->query('UPDATE ce_forum_conversation_preferences SET incoming=?,contact_mode=? WHERE user_id=?',[$legacy,$contact,$user]); }
    public function block(string $user,string $target,bool $enabled): void
    { if(!$enabled){$this->query('DELETE FROM ce_forum_user_blocks WHERE user_id=? AND blocked_id=?',[$user,$target]);return;}if($this->row('SELECT user_id FROM ce_forum_user_blocks WHERE user_id=? AND blocked_id=?',[$user,$target])===null)$this->query('INSERT INTO ce_forum_user_blocks(user_id,blocked_id) VALUES (?,?)',[$user,$target]); }
    public function existingReport(string $user,string $message): ?string
    {return $this->row('SELECT id FROM ce_forum_conversation_reports WHERE user_id=? AND message_id=?',[$user,$message])['id']??null;}
    public function ownReports(string $user,int $offset): array
    {return array_map(self::normalize(...),$this->rows('SELECT id,message_id,reason,status,created_at,resolved_at FROM ce_forum_conversation_reports WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET '.$offset,[$user]));}
    public function report(string $user,array $message,string $reason): string
    {
        $old=$this->row('SELECT id FROM ce_forum_conversation_reports WHERE user_id=? AND message_id=?',[$user,$message['id']]);if($old!==null)return $old['id'];
        $id=Uuid::v4();$this->query('INSERT INTO ce_forum_conversation_reports(id,message_id,user_id,reason,body_snapshot,created_at) VALUES (?,?,?,?,?,?)',[$id,$message['id'],$user,$reason,$message['body'],time()]);return $id;
    }
    public function reports(int $offset,string $status='all'): array { return array_map(self::normalize(...),$this->rows('SELECT id,message_id,user_id,status,created_at,resolved_at FROM ce_forum_conversation_reports'.($status==='all'?'':' WHERE status=?').' ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET '.$offset,$status==='all'?[]:[$status])); }
    public function reportDetail(string $id): array { return self::normalize($this->row('SELECT r.*,m.author_id FROM ce_forum_conversation_reports r JOIN ce_forum_conversation_messages m ON m.id=r.message_id WHERE r.id=?',[$id])??throw new ForumException('Жалоба не найдена.',404)); }
    public function resolve(string $id,string $user,string $status,string $reason='',?string $sanction=null): void { if($this->query("UPDATE ce_forum_conversation_reports SET status=?,resolved_by=?,resolved_at=?,decision=?,sanction_id=? WHERE id=? AND status='open'",[$status,$user,time(),$reason,$sanction,$id])->rowCount()!==1)throw new ForumException('Жалоба уже обработана или не найдена.',409); }
}
