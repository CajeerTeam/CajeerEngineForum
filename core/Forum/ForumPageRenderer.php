<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\DiscussionService;
use CajeerEngine\Forum\Validation\ForumValidator;
use Symfony\Component\HttpFoundation\{Request,Response,RedirectResponse};

/** Server-rendered read views; the existing JS enhances the same routes via the REST API. */
final readonly class ForumPageRenderer
{
    private ForumSeoSettings $settings;
    public function __construct(private DiscussionService $discussions,private string $root)
    { $this->settings=new ForumSeoSettings($root); }

    public function render(Request $request,ForumAccessPolicy $actor): Response
    {
        $path=rtrim($request->getPathInfo(),'/')?:'/';
        $path=$path==='/'?'/forum':$path;
        $name=getenv('APP_NAME')?:'CajeerEngine Forum Edition';
        $title=$name;$description='Обсуждайте идеи, делитесь опытом и находите ответы вместе.';
        $canonical=null;$body='';$schema=[];$status=200;$index=false;
        // Do not expose user-specific metadata or index private content, even on an authenticated request.
        $guest=$actor->userId==='';
        $q=$request->query->all();$page=1;
        try {
            if($path==='/forum'){
                $d=$this->discussions->index($actor);$title='Разделы форума';$canonical='/forum';$index=$guest;
                foreach($d['categories'] as $c){
                    $body.='<section class="category" id="category-'.self::e($c['id']).'"><h2>'.self::e($c['name']).'</h2><p>'.self::e($c['description']).'</p>'.$this->boardTree($d['boards'],$c['id']).'</section>';
                }
                if($body==='')$body='<p>Пока нет доступных разделов.</p>';
            } elseif(preg_match('~^/forum/(boards|threads|members)/([^/]+)$~D',$path,$m)){
                $kind=$m[1];$id=ForumValidator::id($m[2]);$page=self::page($q['page']??null,10000);$offset=25*($page-1);
                $canonical=self::pagePath($path,$page);
                if($kind==='boards'){
                    $d=$this->discussions->board($id,$offset,$actor,true);$b=$d['board'];$title=$b['name'];$description=self::summary(self::e($b['description']?:$title));
                    if($page>1 && !$d['threads'])throw new ForumException('Страница не найдена.',404);
                    $index=$guest&&$this->settings->permitsPath($d['breadcrumbs']);
                    $body=$this->breadcrumbs($d['breadcrumbs']).'<p>'.self::e($b['description']).'</p>';
                    if($d['children'])$body.='<h2>Подразделы</h2>'.$this->boardTree($d['children'],$b['category_id'],$id);
                    $body.='<h2>Обсуждения</h2>'.$this->threads($d['threads']).$this->paging($path,$page,(int)$b['threads']>$offset+25);
                    $schema[]=$this->breadcrumbSchema($d['breadcrumbs']);
                } elseif($kind==='threads'){
                    $d=$this->discussions->thread($id,$offset,$actor,($q['unread']??null)==='1',true);$t=$d['thread'];
                    if($id!==$t['id']){
                        // Both source and destination ACLs were checked by DiscussionService.
                        // The merged destination has different pagination, so restart on page 1.
                        return new RedirectResponse('/forum/threads/'.$t['id'],301,['Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex']);
                    }
                    if(!$d['posts'])throw new ForumException('Страница не найдена.',404);
                    $page=(int)$d['page'];$offset=25*($page-1);$canonical=self::pagePath($path,$page);$title=$t['title'];$description=self::summary($d['posts'][0]['body_html']);$index=$guest&&($q['unread']??null)!=='1'&&$this->settings->permitsPath($d['breadcrumbs']);
                    $body=$this->breadcrumbs($d['breadcrumbs'],$title);
                    if($t['tags']){$body.='<p class="meta">Теги: ';foreach($t['tags'] as $tag)$body.='<a class="thread-tag" href="/forum/search?tag='.self::e(rawurlencode($tag)).'">'.self::e($tag).'</a> ';$body.='</p>';}
                    if($d['interaction']['kind']==='poll'){$poll=$d['interaction']['poll'];$body.='<section class="panel"><h2>'.self::e($poll['question']).'</h2><ul>';foreach($poll['options'] as $option)$body.='<li>'.self::e($option['label']).(isset($option['votes'])?' — '.(int)$option['votes']:'').'</li>';$body.='</ul></section>';}
                    foreach($d['posts'] as $post)$body.=$this->post($post,$d['interaction']['question']['accepted_post_id']??null);
                    $body.=$this->paging($path,$page,(int)$d['total_posts']>$offset+25);
                    $schema[]=$this->breadcrumbSchema($d['breadcrumbs'],$title,$path);
                    if($page===1)$schema[]=$this->discussionSchema($d,$path);
                } else {
                    $d=$this->discussions->profile($id,$offset,$actor);$member=$d['member'];$title=$member['name'];$description=self::summary(self::e($member['bio']?:$title));
                    if($page>1&&!$d['threads'])throw new ForumException('Страница не найдена.',404);
                    $index=$guest;
                    $body='<section class="panel"><p class="meta">Участник с '.self::e($member['created_at']).'</p><p class="profile-text">'.self::e($member['bio']).'</p></section>';
                    if($member['activity_visible'])$body.='<h2>Обсуждения участника</h2>'.$this->threads($d['threads']).$this->paging($path,$page,count($d['threads'])===25);
                    if($page===1)$schema[]=['@context'=>'https://schema.org','@type'=>'ProfilePage','mainEntity'=>['@type'=>'Person','name'=>$title,'description'=>$description,'url'=>$this->settings->baseUrl().$path]];
                }
            } elseif($path==='/forum/latest'){
                $title='Последние обсуждения';
                if(array_key_exists('page',$q)&&!array_key_exists('cursor',$q)){
                    $page=self::page($q['page'],10000);$d=$this->discussions->latest(25*($page-1),$actor);
                    if($page>1&&!$d)throw new ForumException('Страница не найдена.',404);
                    $body=$this->threads($d).$this->paging($path,$page,count($d)===25);
                }else{
                    $cursor=\CajeerEngine\Forum\Validation\ForumFeedCursor::decode($q['cursor']??'');
                    $d=$this->discussions->latestCursor($cursor,$actor);$body=$this->threads($d['items']).'<nav class="pager" aria-label="Навигация">';
                    if($cursor!==null)$body.='<a class="button secondary" href="/forum/latest">К началу</a>';
                    if($d['next_cursor']!==null)$body.='<a class="button secondary" href="/forum/latest?cursor='.self::e(rawurlencode($d['next_cursor'])).'">Далее →</a>';
                    $body.='</nav>';
                } // duplicate listing, intentionally noindex
            } elseif($path==='/forum/questions'){
                $page=self::page($q['page']??null,10000);$filter=$q['status']??'all';
                $d=$this->discussions->polls()->questions($actor,25*($page-1),$filter);$title='Вопросы и ответы';
                if($page>1&&!$d)throw new ForumException('Страница не найдена.',404);
                $body='<nav class="actions" aria-label="Вопросы">';foreach(['all'=>'Все','open'=>'Нерешённые','solved'=>'Решённые','unanswered'=>'Без ответов'] as $key=>$label)$body.='<a class="button secondary" href="/forum/questions?status='.$key.'">'.$label.'</a>';$body.='</nav>';
                $body.=$this->threads($d).$this->paging($path,$page,count($d)===25,['status'=>$filter]);
            } elseif(self::interactive($path)){
                $title='Форум';$body='<p>Для этого раздела необходим JavaScript.</p><p><a href="/forum">К разделам форума</a></p>';
            } else throw new ForumException('Страница не найдена.',404);
        } catch(ForumException $e){
            $status=$e->status>=500?$e->status:($e->status===401?401:($e->status===403?403:404));$title=$status===401?'Требуется авторизация':'Страница не найдена';
            $body='<p>'.self::e($title).'. <a href="/forum">К разделам форума</a> · <a href="/forum/login">Войти</a></p>';
            $canonical=null;$schema=[];$index=false;$description=$title;
        }
        $index=$index&&$this->settings->indexEnabled()&&$status===200;
        $meta='<meta name="robots" content="'.($index?'index, follow':'noindex, follow').'">';
        if($index&&$canonical!==null){
            $meta.='<link rel="alternate" type="application/rss+xml" title="RSS форума" href="'.self::e($this->settings->baseUrl().'/forum/rss.xml').'">';
            $url=$this->settings->baseUrl().$canonical;
            $meta.='<link rel="canonical" href="'.self::e($url).'"><meta name="description" content="'.self::e($description).'">';
            foreach(['og:title'=>$title,'og:description'=>$description,'og:url'=>$url,'og:type'=>'website','og:site_name'=>$name] as $k=>$v)$meta.='<meta property="'.$k.'" content="'.self::e($v).'">';
            $meta.='<meta name="twitter:card" content="summary"><meta name="twitter:title" content="'.self::e($title).'"><meta name="twitter:description" content="'.self::e($description).'">';
            foreach($schema as $item)$meta.='<script type="application/ld+json">'.json_encode($item,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE).'</script>';
        }
        $registration=filter_var(getenv('FORUM_REGISTRATION')===false?'true':getenv('FORUM_REGISTRATION'),FILTER_VALIDATE_BOOLEAN);
        $html=strtr((string)file_get_contents($this->root.'/public/forum-assets/index.html'),[
            '<!--FORUM_NAME-->'=>self::e($name),'<!--FORUM_REGISTRATION-->'=>$registration?'true':'false',
            '<!--FORUM_TITLE-->'=>self::e($title.($title===$name?'':' — '.$name).($page>1?' · '.$page:'')),
            '<!--FORUM_HEADING-->'=>self::e($title),'<!--FORUM_DESCRIPTION-->'=>self::e($description),
            '<meta name="robots" content="noindex, nofollow" data-forum-template>'=>$meta,'<!--FORUM_BODY-->'=>$body,'<!--FORUM_RENDERED-->'=>$canonical!==null||$path==='/forum/latest'||$path==='/forum/questions'?'true':'false','<!--FORUM_SCRIPT-->'=>$status===200?'<script src="/forum-assets/forum.js" defer></script>':'',
        ]);
        return new Response($html,$status,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store','Vary'=>'Cookie, Authorization, X-API-Token','X-Robots-Tag'=>$index?'index, follow':'noindex, follow']);
    }

    public function sitemap(Request $request): Response
    {
        $headers=['Content-Type'=>'application/xml; charset=UTF-8','Cache-Control'=>'no-store','X-Robots-Tag'=>'noindex'];
        if(!$this->settings->sitemapEnabled())return new Response('',404,$headers);
        try{
            $raw=$request->query->all()['page']??null;$page=$raw===null?null:self::page($raw,50000);
            $data=$this->discussions->sitemapPage($page,$this->settings->excludedBoards());
            if($data['pages']>50000)throw new ForumException('Слишком много страниц карты сайта.',503);
            $base=$this->settings->baseUrl();$xml='<?xml version="1.0" encoding="UTF-8"?>';$ns=' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
            if($page===null){$xml.='<sitemapindex'.$ns.'>';for($i=1;$i<=$data['pages'];$i++)$xml.='<sitemap><loc>'.self::xml($base.'/forum/sitemap.xml?page='.$i).'</loc></sitemap>';$xml.='</sitemapindex>';}
            else{$xml.='<urlset'.$ns.'>';foreach($data['paths'] as $path)$xml.='<url><loc>'.self::xml($base.$path).'</loc></url>';$xml.='</urlset>';}
            return new Response($xml,200,$headers);
        }catch(ForumException $e){return new Response('', $e->status===503?503:404,$headers);}
    }

    public function rss(): Response
    {
        $headers=['Content-Type'=>'application/rss+xml; charset=UTF-8','Cache-Control'=>'no-store','X-Robots-Tag'=>'noindex'];
        if(!$this->settings->indexEnabled())return new Response('',404,$headers);
        $base=$this->settings->baseUrl();$name=getenv('APP_NAME')?:'CajeerEngine Forum Edition';
        $xml='<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>'.self::xml($name).'</title><link>'.self::xml($base.'/forum').'</link><description>Последние общедоступные обсуждения</description>';
        foreach($this->discussions->seoLatest($this->settings->excludedBoards()) as $t){
            $url=self::xml($base.'/forum/threads/'.$t['id']);$date=(new \DateTimeImmutable($t['updated_at'],new \DateTimeZone('UTC')))->format(DATE_RSS);
            $xml.='<item><title>'.self::xml($t['title']).'</title><link>'.$url.'</link><guid isPermaLink="true">'.$url.'</guid><pubDate>'.self::xml($date).'</pubDate></item>';
        }
        return new Response($xml.'</channel></rss>',200,$headers);
    }

    private static function interactive(string $path): bool
    {
        return in_array($path,array_map(static fn($p)=>'/forum/'.$p,['search','members','questions','activity','leaderboard','unread','notifications','conversations','conversation-settings','conversation-reports','drafts','watches','manage','moderation','moderation/appeals','moderation/sanctions','submissions','recycle-bin','safety','sanctions','member-relations','login','register','forgot-password','resend-email','verify-email','reset-password','unsubscribe','account','account/profile','account/preferences','account/privacy','account/security','account/sessions']),true)
            || preg_match('~^/forum/conversations/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$~D',$path)===1;
    }
    private static function page(mixed $value,int $max): int
    {
        if($value===null)return 1;
        if(!is_string($value)||!ctype_digit($value)||strlen($value)>6||(int)$value<1||(int)$value>$max)throw new ForumException('Некорректная страница.',404);
        return (int)$value;
    }
    private static function e(mixed $v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function xml(string $v): string {return htmlspecialchars($v,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE|ENT_DISALLOWED,'UTF-8');}
    private static function summary(string $html): string
    {
        $text=html_entity_decode(strip_tags(str_replace(['<br>','</p>','</li>'], ' ', $html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=trim(preg_replace('/\s+/u',' ',$text)??'');
        // Unicode-safe even in minimal CLI environments.
        preg_match('/^.{0,160}/us',$text,$m);return $m[0]??'';
    }
    private static function pagePath(string $path,int $page): string {return $path.($page>1?'?page='.$page:'');}
    private function paging(string $path,int $page,bool $next,array $params=[]): string
    {
        $html='<nav class="pager" aria-label="Страницы">';$url=static fn(int $p)=>$path.($params?('?'.http_build_query($params+($p>1?['page'=>$p]:[]),'','&',PHP_QUERY_RFC3986)):($p>1?'?page='.$p:''));
        if($page>1)$html.='<a class="button secondary" rel="prev" href="'.self::e($url($page-1)).'">← Назад</a>';
        if($next&&$page<10000)$html.='<a class="button secondary" rel="next" href="'.self::e($url($page+1)).'">Далее →</a>';
        return $html.'</nav>';
    }
    private function boardTree(array $boards,string $category,?string $parent=null,int $depth=0): string
    {
        if($depth>=16)return '';$html='';
        foreach($boards as $b)if($b['category_id']===$category&&$b['parent_id']===$parent){
            $html.='<li><div class="entry"><div><a class="strong" href="/forum/boards/'.self::e($b['id']).'">'.self::e($b['name']).'</a><p>'.self::e($b['description']).'</p><span class="meta">'.(int)$b['threads'].' тем · '.(int)$b['posts'].' сообщ.</span></div></div>'.$this->boardTree($boards,$category,$b['id'],$depth+1).'</li>';
        }
        return $html===''?'':'<ul class="node-tree public-tree">'.$html.'</ul>';
    }
    private function threads(array $threads): string
    {
        $html='<div class="category">';if(!$threads)$html.='<p class="entry">Пока ничего нет.</p>';
        foreach($threads as $t)$html.='<div class="entry"><div><a class="strong" href="/forum/threads/'.self::e($t['id']).'">'.self::e($t['title']).'</a><p class="meta">'.self::e($t['author']??'').' · '.self::e($t['updated_at']).'</p></div></div>';
        return $html.'</div>';
    }
    private function breadcrumbs(array $nodes,?string $title=null): string
    {
        $html='<nav class="crumb" aria-label="Навигационная цепочка"><ol><li><a href="/forum">Форумы</a></li>';
        foreach($nodes as $node)$html.='<li><a href="'.self::e($this->nodePath($node)).'">'.self::e($node['name']).'</a></li>';
        if($title!==null)$html.='<li aria-current="page">'.self::e($title).'</li>';
        return $html.'</ol></nav>';
    }
    private function nodePath(array $node): string {return $node['kind']==='category'?'/forum#category-'.$node['id']:'/forum/boards/'.$node['id'];}
    private function breadcrumbSchema(array $nodes,?string $title=null,?string $path=null): array
    {
        $base=$this->settings->baseUrl();$items=[['@type'=>'ListItem','position'=>1,'name'=>'Форумы','item'=>$base.'/forum']];
        foreach($nodes as $n)$items[]=['@type'=>'ListItem','position'=>count($items)+1,'name'=>$n['name'],'item'=>$base.$this->nodePath($n)];
        if($title!==null)$items[]=['@type'=>'ListItem','position'=>count($items)+1,'name'=>$title,'item'=>$base.$path];
        return ['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>$items];
    }
    private function post(array $p,?string $accepted): string
    {
        $author=self::e($p['author']??'Удалённый участник');
        if($p['author_profile_visible']&&$p['author_id'])$author='<a href="/forum/members/'.self::e($p['author_id']).'">'.$author.'</a>';
        // body_html comes exclusively from ForumContentRenderer, with quote links resolved by PostRepository.
        $html='<article class="panel post" id="post-'.self::e($p['id']).'"><header><strong>'.$author.'</strong> <span class="meta">'.self::e($p['created_at']).'</span></header>';
        if($accepted===$p['id'])$html.='<p><strong>Принятый ответ</strong></p>';
        $html.='<div class="post-body rich-content'.($p['body_format']==='plain'?' rich-plain':'').'">'.$p['body_html'].'</div>';
        foreach($p['attachments']??[] as $a)if(isset($a['id']))$html.='<p><a href="/api/v1/forum/attachments/'.self::e($a['id']).'/download">'.self::e($a['original_name']??$a['filename']??'Вложение').'</a></p>';
        return $html.'</article>';
    }
    private function discussionSchema(array $d,string $path): array
    {
        $base=$this->settings->baseUrl();$url=$base.$path;$first=$d['posts'][0];
        $text=static fn($p)=>html_entity_decode(strip_tags(str_replace(['<br>','</p>','</li>'],' ',$p['body_html'])),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $date=static fn($p)=>(new \DateTimeImmutable($p['created_at'],new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $author=static fn($p)=>['@type'=>'Person','name'=>$p['author']??'Удалённый участник'];
        $main=['@type'=>'DiscussionForumPosting','headline'=>$d['thread']['title'],'text'=>$text($first),'author'=>$author($first),'datePublished'=>$date($first),'url'=>$url];
        $comments=[];foreach(array_slice($d['posts'],1) as $p)$comments[]=['@type'=>'Comment','text'=>$text($p),'author'=>$author($p),'datePublished'=>$date($p),'url'=>$url.'#post-'.$p['id']];
        if($comments)$main['comment']=$comments;
        if($d['interaction']['kind']==='question'&&$comments){
            $accepted=$d['interaction']['question']['accepted_post_id'];$question=['@type'=>'Question','name'=>$d['thread']['title'],'text'=>$text($first),'author'=>$author($first),'datePublished'=>$date($first),'answerCount'=>max(0,(int)$d['total_posts']-1)];$answers=[];
            foreach(array_slice($d['posts'],1) as $p){$answer=['@type'=>'Answer','text'=>$text($p),'author'=>$author($p),'datePublished'=>$date($p),'url'=>$url.'#post-'.$p['id']];if($p['id']===$accepted)$question['acceptedAnswer']=$answer;else $answers[]=$answer;}
            if($answers)$question['suggestedAnswer']=$answers;
            return ['@context'=>'https://schema.org','@type'=>'QAPage','mainEntity'=>$question];
        }
        return ['@context'=>'https://schema.org']+$main;
    }
}
