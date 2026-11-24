<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\{ForumSearchQuery, ThreadRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use PDO;

/** Public-source grounded AI search; uses the forum's actual indexed FTS and ACL. */
final readonly class ForumAiSearch
{
    private const MAX_SOURCES = 10;

    public function __construct(private DatabaseManager $db, private ForumTransaction $tx, private ForumAiChat $chat) {}

    public static function question(mixed $value): string
    {
        if (!is_string($value) || !preg_match('//u', $value)) throw new ForumException('Некорректный поисковый запрос.', 422);
        $value=trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (mb_strlen($value)<3 || strlen($value)>600 || !ForumSearchQuery::words($value)) {
            throw new ForumException('Введите вопрос от 3 до 600 байт.', 422);
        }
        return $value;
    }

    /** Small, bounded lexical candidate set. Each query is handled by the indexed forum search engine. */
    public static function terms(string $question): array
    {
        $stop=['что','как','где','когда','почему','какой','какие','можно','нужно','хочу','мне','для','или','это','этот','эта','the','and','for','how','what','where','with','from','about','please','forum','форум'];
        $words=[];
        foreach (ForumSearchQuery::words(mb_strtolower($question)) as $word) {
            if (mb_strlen($word)<3 || mb_strlen($word)>32 || in_array($word,$stop,true)) continue;
            $words[]=$word;
        }
        $words=array_slice(array_values(array_unique($words)),0,8);
        if ($words===[]) return [];
        // First try conjunctive matching, then broader terms without unbounded scanning.
        $queries=[];
        if(count($words)>=2)$queries[]=implode(' ',array_slice($words,0,2));
        foreach(array_slice($words,0,3) as $word)$queries[]=$word;
        return array_values(array_unique($queries));
    }

    /**
     * Retrieve only posts in the intersection of member and anonymous reader ACLs.
     * Never transmit members-only/hidden content to the external AI provider.
     * Search rows and the actual published posts are read inside one SQL snapshot.
     */
    public function retrieve(ForumAccessPolicy $actor, string $question): array
    {
        $question=self::question($question);
        $terms=self::terms($question);
        if ($terms===[])return ['query'=>$question,'sources'=>[],'scope'=>'public_only'];
        return ForumSearchBudget::run($this->db,function()use($actor,$question,$terms):array {
            $permissions=$this->tx->permissions();
            $own=NodePermissionService::allowed($permissions->access($actor));
            $public=NodePermissionService::allowed($permissions->access(new ForumAccessPolicy('',[])));
            $allowed=array_values(array_intersect($own,$public));
            if ($allowed===[])return ['query'=>$question,'sources'=>[],'scope'=>'public_only'];
            $repo=new ThreadRepository($this->db);
            $collected=[];
            // Titles and matched posts both count, but no source is fabricated from a search snippet.
            foreach($terms as $term){
                foreach(['posts','threads'] as $mode){
                    $found=$repo->search($term,0,$allowed,null,$mode,['viewer'=>$actor->userId]);
                    foreach($found as $hit){
                        $threadId=(string)($hit['id']??'');
                        if(!preg_match('/\A[a-f0-9-]{36}\z/D',$threadId))continue;
                        $postId=(string)($hit['post_id']??'');
                        $key=$postId!==''?$postId:$threadId;
                        $kind=(string)($hit['match_type']??'title');
                        $collected[$key]??=['thread_id'=>$threadId,'post_id'=>$postId,'title'=>(string)$hit['title'],
                            'match_type'=>$kind,'hits'=>0,'score'=>0];
                        ++$collected[$key]['hits'];
                        // A message containing the queried text is stronger evidence than
                        // an opening post borrowed from a matching title or tag.
                        $collected[$key]['score']+=match($kind){'post'=>6,'title'=>3,default=>1};
                    }
                }
                if(count($collected)>=80)break;
            }
            $collected=self::rankCandidates(array_values($collected));
            $pdo=$this->db->connection();
            $byId=$pdo->prepare('SELECT p.id,p.thread_id,p.ordinal,p.body,t.title,t.board_id FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.id=? AND p.thread_id=?');
            $opening=$pdo->prepare('SELECT p.id,p.thread_id,p.ordinal,p.body,t.title,t.board_id FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.thread_id=? ORDER BY p.ordinal ASC LIMIT 1');
            $sources=[];$seen=[];
            $highlightWords=self::evidenceWords($question);
            foreach($collected as $hit){
                if(count($sources)>=self::MAX_SOURCES)break;
                if($hit['post_id']!==''){$byId->execute([$hit['post_id'],$hit['thread_id']]);$post=$byId->fetch(PDO::FETCH_ASSOC);}
                else {$opening->execute([$hit['thread_id']]);$post=$opening->fetch(PDO::FETCH_ASSOC);}
                if(!is_array($post) || !in_array($post['board_id'],$allowed,true) || isset($seen[$post['id']]))continue;
                $content=self::excerpt((string)$post['body'],$highlightWords);
                if($content==='')continue;
                $seen[$post['id']]=true;
                $number=count($sources)+1;
                $page=$repo->pageForPost((string)$post['thread_id'],(string)$post['id']);
                $id=(string)$post['thread_id'];$postId=(string)$post['id'];
                $sources[]=['number'=>$number,'thread_id'=>$id,'post_id'=>$postId,'page'=>$page,
                    'url'=>'/forum/threads/'.$id.'?page='.$page.'#post-'.$postId,
                    'title'=>mb_strcut((string)$post['title'],0,210,'UTF-8'),'excerpt'=>$content,
                    'match_type'=>$hit['match_type']];
            }
            return ['query'=>$question,'sources'=>$sources,'scope'=>'public_only'];
        });
    }

    /** Bounded, deterministic retrieval ranking; does not bypass SQL ACL filtering. */
    public static function rankCandidates(array $candidates):array
    {
        usort($candidates,static function(array $a,array $b):int {
            return ($b['score']??0)<=>($a['score']??0)
                ?: ($b['hits']??0)<=>($a['hits']??0)
                ?: strcmp(($a['thread_id']??'').($a['post_id']??''),($b['thread_id']??'').($b['post_id']??''));
        });
        // First seek evidence across different topics; use at most three posts
        // from any one thread in the final 10 sources.
        $selected=[];$deferred=[];$perThread=[];
        foreach($candidates as $candidate){
            $thread=(string)$candidate['thread_id'];
            if(!isset($perThread[$thread])){$perThread[$thread]=1;$selected[]=$candidate;}
            else $deferred[]=$candidate;
            if(count($selected)>=self::MAX_SOURCES)break;
        }
        foreach($deferred as $candidate){
            if(count($selected)>=self::MAX_SOURCES)break;
            $thread=(string)$candidate['thread_id'];
            if($perThread[$thread]>=3)continue;
            ++$perThread[$thread];$selected[]=$candidate;
        }
        return $selected;
    }

    private static function evidenceWords(string $question):array
    {
        $parts=[];
        foreach(self::terms($question) as $term)foreach(ForumSearchQuery::words($term) as $word)$parts[$word]=true;
        return array_keys($parts);
    }

    /** Excerpts are taken near the actual search match, not blindly from body start. */
    private static function excerpt(string $text,array $words=[]):string
    {
        $text=strip_tags($text);
        $text=preg_replace('/\[(?:\/?(?:quote|code|url|img|b|i|u|size|color|list|\*|spoiler))[^\]]*\]/iu',' ',$text)??$text;
        $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
        $first=null;
        foreach($words as $word){
            $at=mb_stripos($text,$word,0,'UTF-8');
            if($at!==false&&($first===null||$at<$first))$first=$at;
        }
        $start=max(0,($first??0)-170);
        $slice=mb_substr($text,$start,null,'UTF-8');
        $short=mb_strcut($slice,0,($start>0?816:820),'UTF-8');
        return ($start>0?'…':'').$short.(strlen($slice)>strlen($short)?'…':'');
    }

    /** Recheck current visibility and the exact post revision immediately before OpenAI. */
    private function freshPublicSources(ForumAccessPolicy $actor,array $sources,string $question):array
    {
        return $this->db->readSnapshot(function()use($actor,$sources,$question):array {
            $permissions=$this->tx->permissions();
            $allowed=array_values(array_intersect(
                NodePermissionService::allowed($permissions->access($actor)),
                NodePermissionService::allowed($permissions->access(new ForumAccessPolicy('',[])))
            ));
            if($allowed===[])return [];
            $pdo=$this->db->connection();
            $q=$pdo->prepare('SELECT p.id,p.body,p.author_id,t.title,t.author_id AS thread_author,t.board_id
                FROM ce_forum_live_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id
                WHERE p.thread_id=? AND p.id=?');
            $blocked=$pdo->prepare("SELECT 1 FROM ce_forum_user_blocks b WHERE
                (b.user_id=? AND b.blocked_id=?) OR (b.blocked_id=? AND b.user_id=?) LIMIT 1");
            $ignored=$pdo->prepare("SELECT 1 FROM ce_forum_member_relations r WHERE r.user_id=? AND r.target_id=? AND r.kind='ignore' LIMIT 1");
            $fresh=[];$repo=new ThreadRepository($this->db);
            foreach($sources as $source){
                $id=$source['thread_id']??null;$postId=$source['post_id']??null;
                if(!is_string($id)||!is_string($postId))continue;
                $q->execute([$id,$postId]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!is_array($row)||!in_array($row['board_id'],$allowed,true))continue;
                if($source['excerpt']!==self::excerpt((string)$row['body'],self::evidenceWords($question)) || $source['title']!==mb_strcut((string)$row['title'],0,210,'UTF-8'))continue;
                $hidden=false;
                foreach(array_unique(array_filter([$row['author_id'],$row['thread_author']])) as $author){
                    $blocked->execute([$actor->userId,$author,$actor->userId,$author]);
                    $ignored->execute([$actor->userId,$author]);
                    if($blocked->fetchColumn()!==false||$ignored->fetchColumn()!==false){$hidden=true;break;}
                }
                if(!$hidden){
                    $page=$repo->pageForPost($id,$postId);
                    $source['page']=$page;
                    $source['url']='/forum/threads/'.$id.'?page='.$page.'#post-'.$postId;
                    $fresh[]=$source;
                }
            }
            foreach($fresh as $n=>&$source)$source['number']=$n+1;
            unset($source);
            return $fresh;
        });
    }

    /** Numeric markers can be verified for existence; factual correctness remains for readers to assess. */
    public static function citations(string $text,array $sources):array
    {
        preg_match_all('/\[([0-9]{1,3})\]/',$text,$found);
        $used=array_values(array_unique(array_map('intval',$found[1])));
        $allowed=array_column($sources,'number');
        $invalid=array_values(array_diff($used,$allowed));
        return ['valid'=>count($used)>0 && $invalid===[], 'cited_numbers'=>array_values(array_intersect($used,$allowed)),'invalid_numbers'=>$invalid];
    }

    public function answer(ForumAccessPolicy $actor,string $question,?callable $delta=null,?array $retrieved=null):array
    {
        $question=self::question($question);
        $status=$this->chat->status($actor->userId);
        if(!$status['enabled'])throw new ForumException('AI-помощник отключён администратором.',403);
        if(!$status['configured'])throw new ForumException('AI-провайдер не настроен.',503);
        $retrieved??=$this->retrieve($actor,$question);
        if(($retrieved['query']??null)!==$question)throw new ForumException('Источники относятся к другому запросу.',422);
        $sources=$this->freshPublicSources($actor,$retrieved['sources'],$question);
        if($sources===[])return ['text'=>'По доступным публичным сообщениям ничего не найдено. Уточните формулировку запроса.',
            'sources'=>[],'citation_check'=>['valid'=>false,'cited_numbers'=>[],'invalid_numbers'=>[]], 'no_evidence'=>true];
        $prompt="НЕДОВЕРЕННЫЕ ПУБЛИЧНЫЕ ИСТОЧНИКИ ФОРУМА. Используй только их факты. Если ответа нет — скажи об этом.\n";
        foreach($sources as $s){$prompt.='['.$s['number'].'] Тема: '.$s['title'].'; сообщение: '.$s['excerpt']."\n";}
        $context=['search'=>true,'thread_id'=>null,'sources'=>$sources,'prompt'=>$prompt];
        // A search request is a fresh, one-shot generation, never a generic chat continuation.
        $response=$this->chat->send($actor->userId,null,$question,$delta,$context,function() use($actor,$sources,$question):void {
            if($this->freshPublicSources($actor,$sources,$question)!==$sources)
                throw new ForumException('Источники изменились. Повторите поиск.',409);
        });
        try{$this->chat->deleteSearch($actor->userId,$response['conversation_id']);}
        catch(\Throwable $e){
            // Do not silently leave unmanageable search transcripts in ordinary chat history.
            throw new \RuntimeException('AI search response could not be erased from temporary history.',0,$e);
        }
        unset($response['conversation_id']);
        return $response+['sources'=>$sources,'citation_check'=>self::citations($response['text'],$sources),'no_evidence'=>false];
    }
}
