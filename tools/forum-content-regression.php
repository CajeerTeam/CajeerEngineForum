<?php

declare(strict_types=1);
require __DIR__.'/../core/Forum/ForumException.php';
require __DIR__.'/../core/Forum/Content/ForumContentRenderer.php';
use CajeerEngine\Forum\Content\ForumContentRenderer as R;
function verify(bool $ok,string $what): void { if(!$ok)throw new RuntimeException($what);echo "PASS $what\n"; }
$id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$ref='@{'.$id.'}';$quote='>>{'.$id.'}';
$plain=R::render("**old**\n<script>alert(1)</script>",'plain');verify($plain['html']==='**old**<br>&lt;script&gt;alert(1)&lt;/script&gt;','legacy plain literal');
$m=R::render("# Heading\n**bold** *italic* ~~strike~~ [link](https://example.test)\n- One\n- Two\n> **quoted**\n| A | B |\n| --- | --- |\n| 1 | 2 |\n```php\n$ref\n```\n    $quote\n`$quote` \\$ref $ref $quote",'markdown');
foreach(['<h1>Heading</h1>','<strong>bold</strong>','<em>italic</em>','<del>strike</del>','<ul><li>One</li><li>Two</li></ul>','<blockquote>','<table>','<pre><code class="language-php">','href="https://example.test"'] as $part)verify(str_contains($m['html'],$part),'Markdown '.$part);
verify($m['mentions']===[$id]&&$m['quotes']===[$id],'active references parsed');
$m=R::render("```\n$ref $quote\n```\n`$ref`\n    $quote\n\\$ref \\$quote",'markdown');verify($m['mentions']===[]&&$m['quotes']===[],'code and escapes do not notify');
$b=R::render('[b]B[i]I[/i][/b][u]U[/u][quote][p]Q[/p][/quote][url=https://example.test]Link[/url][code=php]'.$ref.' \\[/code] end[/code][icode]'.$quote.'[/icode] \\'.$ref,'bbcode');
verify(str_contains($b['html'],'<strong>B<em>I</em></strong><u>U</u>')&&str_contains($b['html'],'<blockquote><p>Q</p></blockquote>'),'BBCode nesting and formatting');verify(str_contains($b['html'],'[/code] end</code>')&&$b['mentions']===[]&&$b['quotes']===[],'BBCode opaque code and escapes');
foreach(['plain','markdown','bbcode'] as $format){$html=R::render('<svg/onload=alert(1)><img src=x onerror=alert(1)> [x](javascript:alert) [url=javascript:alert]x[/url] [url=data:text/html;base64,AAAA]x[/url] [url=https://example.test/"onclick="evil]x[/url]',$format)['html'];verify(!preg_match('~<(script|svg|img|iframe|object|style)\b|href="(?:javascript|data):|\sonclick="~i',$html),'hostile content '.$format);}
try{R::format('html');throw new RuntimeException('invalid format accepted');}catch(CajeerEngine\Forum\ForumException){echo "PASS invalid format rejected\n";}
foreach(['markdown','bbcode'] as $format){$start=microtime(true);$r=R::render(str_repeat($format==='markdown'?'** _ [x] ` ':'[b][url=x][i]',1500),$format);verify(strlen($r['html'])<1000000&&microtime(true)-$start<3,'bounded malformed input '.$format);}
verify(R::render('[list=1][*]One[*][b]Two[/b][/list]','bbcode')['html']==='<ol><li>One</li><li><strong>Two</strong></li></ol>','classic BBCode list');
verify(R::render('**bold *italic*** and *italic **bold***','markdown')['html']==='<p><strong>bold <em>italic</em></strong> and <em>italic <strong>bold</strong></em></p>','nested Markdown emphasis');
verify(R::source("    indented code\n",'markdown')==="    indented code\n",'source indentation preserved');
verify(R::source('  legacy  ','plain')==='legacy','legacy source trimmed');
foreach([['[b][/b]','bbcode'],['','markdown'],[str_repeat('я',20001),'markdown'],["bad\0text",'plain']] as [$source,$format]){
    try{R::source($source,$format);throw new RuntimeException('invalid source accepted');}catch(CajeerEngine\Forum\ForumException){echo "PASS invalid source rejected\n";}
}
echo "Forum content regression passed\n";
