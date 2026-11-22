<?php
/** Regression of the actual XenForo converter and the live forum BBCode renderer (no DB required). */
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
use CajeerEngine\Forum\Migration\XenForoContent;
use CajeerEngine\Forum\Content\ForumContentRenderer;
$pass=0;
$check=static function(bool $ok,string $description) use (&$pass):void{
    if(!$ok)throw new RuntimeException('FAIL: '.$description);
    $pass++;echo 'PASS '.$description."\n";
};
$ids=['users'=>[45=>'00000000-0000-4000-8000-000000000045'],
      'posts'=>[12=>'00000000-0000-4000-8000-000000000012'],
      'topics'=>[3=>'00000000-0000-4000-8000-000000000003'],
      'attachments'=>[7=>'00000000-0000-4000-8000-000000000007']];
$resolve=static function(string $kind,int $id)use($ids):?string{
    $target=$ids[$kind][$id]??null;
    if($target===null)return null;
    return match($kind){
        'users'=>'/forum/members/'.$target,
        'posts'=>'/forum/threads/'.$ids['topics'][3].'?page=2#post-'.$target,
        'topics'=>'/forum/threads/'.$target,
        'attachments'=>'/api/v1/forum/attachments/'.$target.'/download',
        default=>null,
    };
};
$origin='https://old.example/community';
$transform=static fn(string $s):string=>XenForoContent::convert($s,$resolve,$origin);
$render=static fn(string $s):string=>ForumContentRenderer::render($transform($s),'bbcode')['html'];
$check(XenForoContent::validOrigin($origin),'trusted origin validated');
foreach(['https://old.example.evil/community','https://old.example/community/?q=1','https://me@old.example/community','https://old.example/community/../../'] as $bad)
    $check(!XenForoContent::validOrigin($bad) || $bad==='https://old.example.evil/community','origin validation rejects malformed/suspicious origin');
$check(str_contains($render('[B]Bold[/B], [I]italic[/I], [CODE=php]echo 1;[/CODE]'),'<strong>Bold</strong>'),'bold formatting preserved');
$check(str_contains($render('[B]Bold[/B], [I]italic[/I], [CODE=php]echo 1;[/CODE]'),'<em>italic</em>'),'italic formatting preserved');
$check(str_contains($render('[CODE=php][URL=https://old.example/community/threads/topic.3/]ignore[/URL][/CODE]'), '[URL='),'code block never rewrites URLs');
$check(str_contains($render('[QUOTE="Alice, post: 12, member: 45"]Hello[/QUOTE]'), 'href="/forum/threads/'.$ids['topics'][3].'?page=2#post-'.$ids['posts'][12].'"'),'quoted post link resolves in final phase');
$check(str_contains($render('[USER=45]Alice[/USER]'),'href="/forum/members/'.$ids['users'][45].'"'),'mention points at local user');
$check(!str_contains($render('[USER=555]Absent[/USER]'), 'href='),'unmapped user is inert text');
$check(str_contains($render('[URL=https://old.example/community/threads/example.3/]Thread[/URL]'),'href="/forum/threads/'.$ids['topics'][3].'"'),'absolute source URL remaps only when origin matches');
$check(str_contains($render('[URL=/posts/12/]Message[/URL]'),'href="/forum/threads/'.$ids['topics'][3].'?page=2#post-'.$ids['posts'][12].'"'),'relative post URL remaps');
$check(str_contains($render('Read https://old.example/community/threads/example.3/ now'),'href="/forum/threads/'.$ids['topics'][3].'"'),'bare source URL remaps');
$check(str_contains($render('[URL=https://other.example/threads/example.3/]External[/URL]'),'href="https://other.example/threads/example.3/"'),'external origin remains external');
$check(!str_contains($render('[URL=https://old.example/community/threads/missing.999/]Missing[/URL]'), 'href='),'missing source target never produces broken original link');
$check(str_contains($render('[ATTACH=full]7[/ATTACH]'),'href="/api/v1/forum/attachments/'.$ids['attachments'][7].'/download"'),'attachment link uses imported ID');
$check(!str_contains($render('[URL=javascript:alert(1)]X[/URL]'),'href='),'javascript scheme never creates clickable link');
$check(!str_contains($render('[USER=45][/url]<img src=x onerror=1>[/USER]'),'<img'),'user name cannot inject HTML');
$check(!str_contains($render('[USER=45][/url][url=https://evil.example]OPEN[/USER]'), 'href="https://evil.example"'),'user name cannot inject BBCode');
$check(!str_contains($render('http://old.example.evil/threads/evil.3'),'/forum/threads/'),'lookalike host never treated as source');
$check(!str_contains($render('[URL=/admin/]Admin[/URL]'),'href='),'untrusted internal URL remains text');
$check(!str_contains($render('[URL=//evil.example]Evil[/URL]'),'href='),'protocol-relative URL rejected');
$check(str_contains($render('[HEADING=2]Title[/HEADING]'),'<h2>Title</h2>'),'heading level preserved');
$check(str_contains($render('[SPOILER=Hidden details]Secret[/SPOILER]'),'<details class="content-spoiler"><summary>Hidden details</summary>Secret</details>'),'spoiler remains hidden until opened');
$check(str_contains($render('See /threads/example.3/ now'),'href="/forum/threads/'.$ids['topics'][3].'"'),'bare relative XenForo link remapped');
$check(str_contains($render('[IMG]https://example.org/illustration.png[/IMG]'),'href="https://example.org/illustration.png"'),'image URL remains safe clickable link without remote embedding');
echo "XenForo 1.1.3 content fidelity: $pass PASS\n";
