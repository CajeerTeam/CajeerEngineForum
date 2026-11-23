<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use PDO;

/** Member-owned support tickets, editor-maintained FAQ and grounded, opt-in AI assistance. */
final readonly class ForumSupportAssistant
{
    public function __construct(private DatabaseManager $db, private ForumAiSearch $search) {}

    public static function uuid(): string
    {
        $b=random_bytes(16); $b[6]=chr((ord($b[6]) & 15)|64); $b[8]=chr((ord($b[8]) & 63)|128);
        $v=bin2hex($b);
        return substr($v,0,8).'-'.substr($v,8,4).'-'.substr($v,12,4).'-'.substr($v,16,4).'-'.substr($v,20);
    }

    private static function id(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/iD',$value))
            throw new ForumException('Некорректный идентификатор.',422);
        return $value;
    }

    private static function content(mixed $value,int $limit,string $name):string
    {
        if(!is_string($value)||!preg_match('//u',$value))throw new ForumException('Некорректное поле: '.$name,422);
        $value=trim($value);
        if(mb_strlen($value)<3||strlen($value)>$limit)throw new ForumException('Неверная длина поля: '.$name,422);
        return $value;
    }

    private static function validateKeys(array $data,array $keys):void
    {
        if(array_diff(array_keys($data),$keys)!==[])throw new ForumException('Неизвестное поле запроса.',422);
    }

    private static function operator(ForumAccessPolicy $actor):void
    {
        $actor->require('forum:acp:members');
    }

    public function faq():array
    {
        return $this->db->connection()->query('SELECT id,question,answer,sort_order,updated_at FROM ce_forum_support_faq WHERE published=1 ORDER BY sort_order ASC, updated_at DESC, id ASC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function manageFaq(ForumAccessPolicy $actor):array
    {
        self::operator($actor);
        return $this->db->connection()->query('SELECT * FROM ce_forum_support_faq ORDER BY sort_order ASC, updated_at DESC, id ASC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveFaq(ForumAccessPolicy $actor,?string $id,array $data):array
    {
        self::operator($actor);
        self::validateKeys($data,['question','answer','published','sort_order','expected_revision']);
        $question=self::content($data['question']??null,240,'вопрос');
        $answer=self::content($data['answer']??null,6000,'ответ');
        if(!is_bool($data['published']??null)||!is_int($data['sort_order']??null)||$data['sort_order']<0||$data['sort_order']>10000)
            throw new ForumException('Некорректные настройки FAQ.',422);
        $now=time();$new=$id===null;$id=$id===null?self::uuid():self::id($id);
        $this->db->transaction(function(PDO $pdo)use($new,$id,$question,$answer,$data,$now):void{
            if($new){
                $pdo->prepare('INSERT INTO ce_forum_support_faq(id,question,answer,published,sort_order,revision,updated_at) VALUES(?,?,?,?,?,1,?)')
                    ->execute([$id,$question,$answer,$data['published']?1:0,$data['sort_order'],$now]);
            }else{
                if(!is_int($data['expected_revision']??null)||$data['expected_revision']<1)throw new ForumException('Требуется версия FAQ.',422);
                $q=$pdo->prepare('UPDATE ce_forum_support_faq SET question=?,answer=?,published=?,sort_order=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');
                $q->execute([$question,$answer,$data['published']?1:0,$data['sort_order'],$now,$id,$data['expected_revision']]);
                if($q->rowCount()!==1)throw new ForumException('FAQ изменён или удалён. Обновите страницу.',409);
            }
        });
        $q=$this->db->connection()->prepare('SELECT * FROM ce_forum_support_faq WHERE id=?');$q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function deleteFaq(ForumAccessPolicy $actor,string $id,int $revision):void
    {
        self::operator($actor);self::id($id);
        if($revision<1)throw new ForumException('Некорректная версия FAQ.',422);
        $this->db->transaction(function(PDO $pdo)use($id,$revision):void{
            $q=$pdo->prepare('DELETE FROM ce_forum_support_faq WHERE id=? AND revision=?');$q->execute([$id,$revision]);
            if($q->rowCount()!==1)throw new ForumException('FAQ изменён. Обновите страницу.',409);
        });
    }

    private static function ticket(PDO $pdo,string $id):array
    {
        $q=$pdo->prepare('SELECT * FROM ce_forum_support_tickets WHERE id=?');$q->execute([self::id($id)]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new ForumException('Обращение не найдено.',404);
        return $row;
    }

    private static function owned(ForumAccessPolicy $actor,array $ticket):void
    {
        if($ticket['user_id']!==$actor->userId&&!$actor->has('forum:acp:members')&&!$actor->has('*'))
            throw new ForumException('Обращение не найдено.',404);
    }

    private static function append(PDO $pdo,string $ticket,string $role,string $body,?string $user=null,?array $sources=null):void
    {
        $pdo->prepare('INSERT INTO ce_forum_support_messages(id,ticket_id,role,author_id,body,sources_json,created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([self::uuid(),$ticket,$role,$user,$body,$sources===null?null:json_encode($sources,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),(int)floor(microtime(true)*1000000)]);
    }

    private function questions(PDO $pdo,string $ticketId):string
    {
        $q=$pdo->prepare("SELECT body FROM ce_forum_support_messages WHERE ticket_id=? AND role='user' ORDER BY created_at ASC,id ASC LIMIT 1");
        $q->execute([$ticketId]);return (string)$q->fetchColumn();
    }

    public function mine(ForumAccessPolicy $actor):array
    {
        $q=$this->db->connection()->prepare('SELECT id,subject,status,needs_human,assigned_to,revision,created_at,updated_at FROM ce_forum_support_tickets WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 100');
        $q->execute([$actor->userId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function queue(ForumAccessPolicy $actor,string $status='open'):array
    {
        self::operator($actor);
        if(!in_array($status,['open','waiting','resolved','all'],true))throw new ForumException('Неизвестный фильтр.',422);
        $where=$status==='all'?'':' WHERE status=?';
        $q=$this->db->connection()->prepare('SELECT id,user_id,subject,status,needs_human,assigned_to,revision,created_at,updated_at FROM ce_forum_support_tickets'.$where.' ORDER BY needs_human DESC, updated_at DESC, id DESC LIMIT 100');
        $q->execute($status==='all'?[]:[$status]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Live check: if a public post is removed, an old AI answer becomes unavailable. */
    private function groundedSources(ForumAccessPolicy $actor,string $question,array $stored):?array
    {
        if($stored===[])return null;
        $live=$this->search->retrieve($actor,$question)['sources'];
        $byId=[];foreach($live as $source)$byId[$source['post_id']]=$source;
        $verified=[];
        foreach($stored as $source){
            if(!is_array($source)||!is_string($source['post_id']??null)||!isset($byId[$source['post_id']])||
                !hash_equals((string)($source['excerpt']??''),(string)$byId[$source['post_id']]['excerpt']))return null;
            $verified[]=$byId[$source['post_id']];
        }
        return $verified;
    }

    public function show(ForumAccessPolicy $actor,string $id):array
    {
        $pdo=$this->db->connection();$ticket=self::ticket($pdo,$id);self::owned($actor,$ticket);
        $q=$pdo->prepare('SELECT id,role,author_id,body,sources_json,created_at FROM ce_forum_support_messages WHERE ticket_id=? ORDER BY created_at ASC,id ASC LIMIT 200');
        $q->execute([$id]);$messages=$q->fetchAll(PDO::FETCH_ASSOC);
        $question=$this->questions($pdo,$id);
        foreach($messages as &$row){
            $raw=$row['sources_json'];unset($row['sources_json']);$row['sources']=[];
            if($row['role']!=='assistant')continue;
            $saved=is_string($raw)?json_decode($raw,true):null;
            try{$valid=is_array($saved)&&array_is_list($saved)?$this->groundedSources($actor,self::safeQuestion($question),$saved):null;}
            catch(\Throwable){$valid=null;}
            if($valid===null){$row['body']='AI-ответ скрыт: источник стал недоступен или изменился. Передайте обращение оператору.';}
            else $row['sources']=$valid;
        }unset($row);
        if(!$actor->has('forum:acp:members')&&!$actor->has('*')){
            unset($ticket['assigned_to']);
            foreach($messages as &$item)unset($item['author_id']);
            unset($item);
        }
        unset($ticket['user_id']);
        return ['ticket'=>$ticket,'messages'=>$messages];
    }

    public function create(ForumAccessPolicy $actor,array $data):array
    {
        self::validateKeys($data,['subject','message','ai_help']);
        $subject=self::content($data['subject']??null,160,'тема');
        $message=self::content($data['message']??null,4000,'сообщение');
        if(isset($data['ai_help'])&&!is_bool($data['ai_help']))throw new ForumException('Неверное согласие на AI.',422);
        $id=self::uuid();$now=time();
        $this->db->transaction(function(PDO $pdo)use($actor,$subject,$message,$id,$now):void{
            // Serialize submissions by the same member before evaluating durable quotas.
            // PostgreSQL/MySQL need an explicit row lock; SQLite serializes the write.
            if ($this->db->driver()!=='sqlite') {
                $lock=$pdo->prepare('SELECT id FROM ce_users WHERE id=? FOR UPDATE');
                $lock->execute([$actor->userId]);
                if ($lock->fetchColumn()===false)throw new ForumException('Аккаунт недоступен.',401);
            }
            $c=$pdo->prepare('SELECT COUNT(*) FROM ce_forum_support_tickets WHERE user_id=? AND created_at>=?');
            $c->execute([$actor->userId,$now-86400]);
            if((int)$c->fetchColumn()>=5)throw new ForumException('Не более пяти новых обращений за сутки.',429);
            $c=$pdo->prepare("SELECT COUNT(*) FROM ce_forum_support_tickets WHERE user_id=? AND status<>'resolved'");$c->execute([$actor->userId]);
            if((int)$c->fetchColumn()>=10)throw new ForumException('Слишком много активных обращений.',429);
            $pdo->prepare('INSERT INTO ce_forum_support_tickets(id,user_id,subject,status,needs_human,assigned_to,revision,created_at,updated_at) VALUES(?,?,?, ?,1,NULL,1,?,?)')
                ->execute([$id,$actor->userId,$subject,'open',$now,$now]);
            self::append($pdo,$id,'user',$message,$actor->userId);
        });
        // Ticket creation must survive unavailable AI, exhausted budgets and missing evidence.
        if(($data['ai_help']??false)===true){try{$this->automatic($actor,$id,$message);}catch(\Throwable){} }
        return $this->show($actor,$id);
    }

    /** Search is entirely public; redact common secrets before any provider call. */
    public static function safeQuestion(string $body):string
    {
        $body=(string)preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu','[email]',$body);
        $body=(string)preg_replace('/\b(?:sk-[A-Za-z0-9_\-]{10,}|eyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{10,})\b/','[secret]',$body);
        $body=(string)preg_replace('/\b\+?[0-9][0-9\s()\-]{7,}[0-9]\b/u','[phone]',$body);
        $body=(string)preg_replace('/(?:token|password|passwd|secret|api[_-]?key)\s*[:=]\s*\S+/iu','[secret]',$body);
        $body=trim((string)preg_replace('/\s+/u',' ',$body));
        return mb_strcut($body,0,450,'UTF-8');
    }

    /** Guarded auto-answer: unsourced or unverified output always leaves a human ticket. */
    private function automatic(ForumAccessPolicy $actor,string $id,string $message):void
    {
        $question=self::safeQuestion($message);
        try{$question=ForumAiSearch::question($question);}catch(ForumException){return;}
        $retrieved=$this->search->retrieve($actor,$question);
        if($retrieved['sources']===[])return;
        $answer=$this->search->answer($actor,$question,null,$retrieved);
        if(($answer['citation_check']['valid']??false)!==true||($answer['sources']??[])===[])return;
        $citations=$answer['citation_check']['cited_numbers'];
        $used=array_values(array_filter($answer['sources'],fn(array $s)=>in_array($s['number'],$citations,true)));
        if($used===[])return;
        // Check current public evidence again after the model has returned.
        if($this->groundedSources($actor,$question,$used)===null)return;
        $this->db->transaction(function(PDO $pdo)use($actor,$id,$answer,$used):void {
            $q=$pdo->prepare('UPDATE ce_forum_support_tickets SET status=?,needs_human=0,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=1 AND status=? AND needs_human=1');
            $q->execute(['waiting',time(),$id,$actor->userId,'open']);
            if($q->rowCount()!==1)return;
            self::append($pdo,$id,'assistant',self::content($answer['text'],12000,'AI-ответ'),null,$used);
        });
    }

    public function reply(ForumAccessPolicy $actor,string $id,array $data,bool $asOperator=false):array
    {
        self::validateKeys($data,['message','expected_revision']);
        $body=self::content($data['message']??null,4000,'сообщение');
        $rev=$data['expected_revision']??null;
        if(!is_int($rev)||$rev<1)throw new ForumException('Укажите версию обращения.',422);
        $this->db->transaction(function(PDO $pdo)use($actor,$id,$body,$rev,$asOperator):void{
            $t=self::ticket($pdo,$id);self::owned($actor,$t);
            $staff=$asOperator||$t['user_id']!==$actor->userId;
            if($staff)self::operator($actor);
            if($staff&&$t['assigned_to']!==null&&$t['assigned_to']!==$actor->userId)throw new ForumException('Обращение закреплено за другим оператором.',403);
            if($t['status']==='resolved')throw new ForumException('Обращение закрыто.',409);
            $count=$pdo->prepare('SELECT COUNT(*) FROM ce_forum_support_messages WHERE ticket_id=?');$count->execute([$id]);
            if((int)$count->fetchColumn()>=200)throw new ForumException('Превышена длина переписки.',409);
            $q=$pdo->prepare('UPDATE ce_forum_support_tickets SET status=?,needs_human=?,assigned_to=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');
            $q->execute([$staff?'waiting':'open',$staff?0:1,$staff?($t['assigned_to']??$actor->userId):$t['assigned_to'],time(),$id,$rev]);
            if($q->rowCount()!==1)throw new ForumException('Обращение изменено. Обновите страницу.',409);
            self::append($pdo,$id,$staff?'operator':'user',$body,$actor->userId);
        });
        return $this->show($actor,$id);
    }

    public function handoff(ForumAccessPolicy $actor,string $id,array $data):array
    {
        self::validateKeys($data,['expected_revision']);$rev=$data['expected_revision']??null;
        if(!is_int($rev)||$rev<1)throw new ForumException('Укажите версию обращения.',422);
        $this->db->transaction(function(PDO $pdo)use($actor,$id,$rev):void{
            $t=self::ticket($pdo,$id);
            if($t['user_id']!==$actor->userId)throw new ForumException('Обращение не найдено.',404);
            if($t['status']==='resolved')throw new ForumException('Обращение закрыто.',409);
            $q=$pdo->prepare("UPDATE ce_forum_support_tickets SET status='open',needs_human=1,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?");
            $q->execute([time(),$id,$actor->userId,$rev]);
            if($q->rowCount()!==1)throw new ForumException('Обращение изменено. Обновите страницу.',409);
        });
        return $this->show($actor,$id);
    }

    public function action(ForumAccessPolicy $actor,string $id,array $data):array
    {
        self::operator($actor);self::validateKeys($data,['action','expected_revision']);
        $action=$data['action']??null;$rev=$data['expected_revision']??null;
        if(!in_array($action,['claim','resolve','reopen'],true)||!is_int($rev)||$rev<1)throw new ForumException('Некорректное действие.',422);
        $this->db->transaction(function(PDO $pdo)use($actor,$id,$action,$rev):void{
            $t=self::ticket($pdo,$id);
            if($t['assigned_to']!==null&&$t['assigned_to']!==$actor->userId)throw new ForumException('Обращение закреплено за другим оператором.',403);
            if($action==='claim' && $t['status']==='resolved')throw new ForumException('Закрытое обращение можно только возобновить.',409);
            $status=$action==='resolve'?'resolved':'open';$needs=$action==='resolve'?0:1;
            $q=$pdo->prepare('UPDATE ce_forum_support_tickets SET status=?,needs_human=?,assigned_to=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');
            $q->execute([$status,$needs,$t['assigned_to']??$actor->userId,time(),$id,$rev]);
            if($q->rowCount()!==1)throw new ForumException('Обращение изменено. Обновите страницу.',409);
        });
        return $this->show($actor,$id);
    }
}
