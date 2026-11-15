<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{PollQuestionRepository,ThreadRepository,BoardRepository,PostRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;

final readonly class PollQuestionService
{
    public function __construct(private ForumTransaction $tx,private PollQuestionRepository $repo,private ThreadRepository $threads,private BoardRepository $boards,private PostRepository $posts) {}
    private static function deadline(mixed $v): ?int
    {
        if($v===null)return null;
        if(!is_int($v)||$v<=time()||$v>time()+366*86400)throw new ForumException('Срок опроса должен быть в пределах следующего года.');
        return $v;
    }
    public static function normalize(mixed $p): array
    {
        if(!is_array($p)||!isset($p['options'])||!is_array($p['options'])||!array_is_list($p['options'])||count($p['options'])<2||count($p['options'])>20)throw new ForumException('Укажите от 2 до 20 вариантов.');
        $options=array_map(fn($v)=>V::text($v,'option',180),$p['options']);
        if(count(array_unique($options))!==count($options))throw new ForumException('Варианты должны различаться.');
        $max=$p['max_choices']??1;$change=$p['allow_change']??true;$visibility=$p['visibility']??'always';
        if(!is_int($max)||$max<1||$max>count($options)||!is_bool($change)||!in_array($visibility,['always','voted','closed'],true))throw new ForumException('Некорректные настройки опроса.');
        return ['question'=>V::text($p['question']??null,'question',180),'options'=>$options,'max_choices'=>$max,'allow_change'=>$change,'visibility'=>$visibility,'closes_at'=>self::deadline($p['closes_at']??null)];
    }
    public function initialize(string $id,mixed $kind,mixed $poll): void
    {
        if(!in_array($kind,['discussion','question','poll'],true))throw new ForumException('Некорректный тип темы.');
        if($kind==='poll')$this->repo->createPoll($id,self::normalize($poll));
        elseif($poll!==null)throw new ForumException('Опрос требует типа poll.');
        if($kind==='question')$this->repo->createQuestion($id);
    }
    private function closed(array $p): bool { return (int)$p['closed']===1||($p['closes_at']!==null&&(int)$p['closes_at']<=time()); }
    public function view(string $id,string $viewer): array
    {
        $p=$this->repo->poll($id);$q=$this->repo->question($id);
        if($p!==null){
            $mine=$viewer===''?[]:$this->repo->choices($id,$viewer);$closed=$this->closed($p);
            $visible=$p['visibility']==='always'||$closed||($p['visibility']==='voted'&&$mine!==[]);
            $counts=$visible?array_column($this->repo->tally($id),'votes','option_no'):[];$total=$visible?$this->repo->voters($id):null;
            $options=$this->repo->options($id);
            foreach($options as &$o){$o['option_no']=(int)$o['option_no'];if($visible){$o['votes']=(int)($counts[$o['option_no']]??0);$o['percent']=$total?round(100*$o['votes']/$total,1):0;}}unset($o);
            $p['max_choices']=(int)$p['max_choices'];$p['allow_change']=(int)$p['allow_change']===1;$p['closed']=$closed;$p['closes_at']=$p['closes_at']===null?null:(int)$p['closes_at'];$p['options']=$options;$p['my_choices']=$mine;$p['voters']=$total;$p['results_visible']=$visible;
        }
        return ['kind'=>$p!==null?'poll':($q!==null?'question':'discussion'),'poll'=>$p,'question'=>$q];
    }
    private function lock(ForumAccessPolicy $actor,string $id,bool $owner=false): array
    {
        $actor->require('forum:write');$t=$this->threads->lockThread(V::id($id))??throw new ForumException('Тема не найдена.',404);
        $this->boards->lockBoard($t['board_id'])??throw new ForumException('Раздел не найден.',404);
        $access=$this->tx->permissions()->access($actor,lockActor:true);
        NodePermissionService::assert($access,$t['board_id'],'thread:read');
        $moderator=$owner&&($access['boards'][$t['board_id']]['thread:moderate']??false);
        if($owner&&!$moderator&&$t['author_id']!==$actor->userId)throw new ForumException('Недостаточно прав.',403);
        NodePermissionService::assert($access,$t['board_id'],$moderator?'thread:moderate':'post:reply');
        $this->boards->writable($t['board_id']);
        if((int)$t['locked']!==0&&!$moderator)throw new ForumException('Тема закрыта.',409);
        return $t;
    }
    public function vote(ForumAccessPolicy $actor,string $id,mixed $choices): array
    {
        if(!is_array($choices)||!array_is_list($choices)||count($choices)>20)throw new ForumException('Некорректный выбор.');
        foreach($choices as $n)if(!is_int($n)||$n<1||$n>20)throw new ForumException('Некорректный вариант.');
        if(count(array_unique($choices))!==count($choices))throw new ForumException('Повтор варианта.');sort($choices,SORT_NUMERIC);
        return $this->tx->write(function()use($actor,$id,$choices){
            $this->lock($actor,$id);$p=$this->repo->poll($id)??throw new ForumException('Опрос не найден.',404);
            $mine=$this->repo->choices($id,$actor->userId);
            if($this->closed($p))throw new ForumException('Опрос завершён.',409);
            if($mine===$choices)return $this->view($id,$actor->userId);
            if($mine!==[]&&!(int)$p['allow_change'])throw new ForumException('Изменение голоса запрещено.',409);
            if(count($choices)>(int)$p['max_choices']||array_diff($choices,array_map('intval',array_column($this->repo->options($id),'option_no'))))throw new ForumException('Некорректный выбор.');
            $this->repo->vote($id,$actor->userId,$choices);return $this->view($id,$actor->userId);
        });
    }
    public function control(ForumAccessPolicy $actor,string $id,mixed $closed,mixed $deadline=null): array
    {
        $closed=V::enabled($closed);$deadline=self::deadline($deadline);
        return $this->tx->write(function()use($actor,$id,$closed,$deadline){
            $this->lock($actor,$id,true);$p=$this->repo->poll($id)??throw new ForumException('Опрос не найден.',404);
            $end=$deadline??($p['closes_at']===null?null:(int)$p['closes_at']);
            if(!$closed&&$end!==null&&$end<=time())throw new ForumException('Укажите новый срок для открытия.',409);
            $this->repo->close($id,$closed,$end);$this->tx->audit('forum.poll.control',$actor->userId,['thread_id'=>$id,'closed'=>$closed,'closes_at'=>$end]);return $this->view($id,$actor->userId);
        });
    }
    private function answer(string $id,string $post): array
    {
        $p=$this->posts->findForWrite(V::id($post))??throw new ForumException('Ответ не найден.',404);
        if($p['thread_id']!==$id||$this->posts->firstId($id)===$post)throw new ForumException('Ответ не найден.',404);
        return $p;
    }
    public function accept(ForumAccessPolicy $actor,string $id,mixed $post): array
    {
        $post=$post===null?null:V::id($post);
        return $this->tx->write(function()use($actor,$id,$post){
            $this->lock($actor,$id,true);$q=$this->repo->question($id)??throw new ForumException('Вопрос не найден.',404);
            if($post!==null)$answer=$this->answer($id,$post);
            if($q['accepted_post_id']!==$post){
                if($q['accepted_post_id']!==null)$this->tx->notifications()->removeAccepted($q['accepted_post_id']);
                $this->repo->accept($id,$post);
                if($post!==null&&$answer['author_id']!==null)$this->tx->notifications()->accepted($actor->userId,$post,$answer['author_id']);
                $this->tx->audit('forum.question.accept',$actor->userId,['thread_id'=>$id,'post_id'=>$post]);}
            return $this->view($id,$actor->userId);
        });
    }
    public function answerVote(ForumAccessPolicy $actor,string $post,mixed $enabled): array
    {
        $post=V::id($post);$enabled=V::enabled($enabled);
        return $this->tx->write(function()use($actor,$post,$enabled){
            $t=$this->threads->lockForPost($post)??throw new ForumException('Ответ не найден.',404);$id=$t['id'];$this->lock($actor,$id);
            if($this->repo->question($id)===null)throw new ForumException('Вопрос не найден.',404);
            $p=$this->answer($id,$post);if($p['author_id']===$actor->userId)throw new ForumException('Нельзя голосовать за свой ответ.',403);
            $this->repo->answerVote($post,$actor->userId,$enabled);return ['id'=>$post,'enabled'=>$enabled];
        });
    }
    public function answers(ForumAccessPolicy $actor,string $id,int $offset,mixed $sort): array
    {
        if(!in_array($sort,['score','time'],true))throw new ForumException('Некорректная сортировка.');
        return $this->tx->read(function()use($actor,$id,$offset,$sort){
            $t=$this->threads->find(V::id($id))??throw new ForumException('Тема не найдена.',404);NodePermissionService::assert($this->tx->permissions()->access($actor),$t['board_id'],'thread:read');
            if($this->repo->question($id)===null)throw new ForumException('Вопрос не найден.',404);
            return $this->repo->answers($id,$actor->userId,$offset,$sort==='score');
        });
    }
    public function structured(string $id): bool { return $this->repo->poll($id)!==null||$this->repo->question($id)!==null; }
    public function detachAnswer(string $source,string $post): void { $this->repo->detachAnswer($source,$post); $this->tx->notifications()->removeAccepted($post); }
    public function questions(ForumAccessPolicy $actor,int $offset,mixed $status): array
    {
        if(!in_array($status,['all','open','solved','unanswered'],true))throw new ForumException('Некорректный фильтр.');
        return $this->tx->read(fn()=> $this->repo->questions(NodePermissionService::allowed($this->tx->permissions()->access($actor)),$status,$offset));
    }
}
