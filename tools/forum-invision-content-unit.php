<?php
/** Isolated executable tests with no database driver required. */
declare(strict_types=1);
use CajeerEngine\Forum\Migration\{InvisionContent,InvisionPoll};
use CajeerEngine\Forum\Content\ForumContentRenderer;
$root=dirname(__DIR__);
foreach(['core/Forum/Migration/InvisionContent.php','core/Forum/Migration/InvisionPoll.php','core/Forum/Content/ForumContentRenderer.php'] as $name)require $root.'/'.$name;
$count=0;
$check=static function(bool $ok,string $name)use(&$count):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$count++;echo 'PASS '.$name."\n";};
$thread='11111111-1111-4111-8111-111111111111';$post='22222222-2222-4222-8222-222222222222';$attachment='33333333-3333-4333-8333-333333333333';$member='44444444-4444-4444-8444-444444444444';
$resolver=static fn(string $kind,int $id):?string=>match($kind){
    'topics'=>$id===20?'/forum/threads/'.$thread:null,
    'posts'=>$id===31?'/forum/threads/'.$thread.'?page=2#post-'.$post:null,
    'attachments'=>$id===7?'/api/v1/forum/attachments/'.$attachment.'/download':null,
    'users'=>$id===1?'/forum/members/'.$member:null,
    default=>null,
};
$input='<p>Hello <strong>World</strong> &amp; <em>friends</em></p><script>alert(1)</script>'.
    '<blockquote class="ipsBlockquote" data-cid="31">Older quote</blockquote>'.
    '<a href="/topic/20-discussion/">Thread</a><a href="/attachment.php?id=7">File</a>'.
    '<img src="index.php?app=core&amp;module=attach&amp;attach_id=7">'.
    '<span data-mentionid="1">@Alice</span>';
$out=InvisionContent::rich($input,$resolver);
$check(is_string($out),'rich conversion returns content');
$check(str_contains($out,'[b]World[/b]')&&str_contains($out,'[i]friends[/i]'),'inline emphasis preserved');
$check(str_contains($out,'[quote]Older quote[/quote]')&&str_contains($out,'page=2#post-'.$post),'quote target and correct pagination');
$check(str_contains($out,'[url=/forum/threads/'.$thread.']Thread[/url]'),'topic mapped');
$check(substr_count($out,'/api/v1/forum/attachments/'.$attachment.'/download')===2,'download and embedded image resolve to guarded file');
$check(str_contains($out,'/forum/members/'.$member),'mention mapped');
$check(!str_contains($out,'alert(1)')&&!str_contains($out,'<script'),'script removed');
$check(!str_contains(InvisionContent::rich('<a href="javascript:alert(1)">click</a><img src="file:///etc/passwd">',$resolver),'[url='),'unsafe links discarded');
$check(!str_contains(InvisionContent::rich('<a href="/topic/999-private/">private</a>',$resolver),'[url='),'unmapped internal links not leaked');
$check(!str_contains(InvisionContent::rich('<a href="https://someone@evil.example/topic/20">unsafe</a>',$resolver),'[url='),'embedded username in external URLs denied');
$check(!str_contains(InvisionContent::rich('<a href="https://someone:secret@evil.example/topic/20">unsafe</a>',$resolver),'[url='),'embedded password in external URLs denied');
$check(InvisionContent::rich(str_repeat('a',1048577),$resolver)===null,'source size bounded');
$check(InvisionContent::rich("invalid\xFF",$resolver)===null,'invalid utf8 rejected');
$render=ForumContentRenderer::render($out,'bbcode')['html'];
$check(str_contains($render,'<strong>World</strong>')&&str_contains($render,'href="/forum/threads/')&&!str_contains($render,'<script'),'converted BBCode renders through real server sanitizer');
$absolute=InvisionContent::rich('<a href="https://legacy.example/topic/20-old">old</a>',$resolver,'https://legacy.example');
$check(str_contains($absolute,'/forum/threads/'.$thread),'absolute legacy links remapped only for configured origin');
$thirdParty=InvisionContent::rich('<a href="https://unrelated.example/topic/20-old">external</a>',$resolver,'https://legacy.example');
$check(!str_contains($thirdParty,'/forum/threads/'.$thread),'unrelated host cannot spoof legacy remapping');
$comment=InvisionContent::rich('<a href="https://legacy.example/topic/20-old?do=findComment&comment=31">reply</a>',$resolver,'https://legacy.example');
$check(str_contains($comment,'page=2#post-'.$post),'permalink to imported comment restored');
$check(InvisionPoll::options('["Yes","No"]')===['Yes','No'],'JSON choices');
$check(InvisionPoll::options(serialize(['choice'=>[0=>'Red',1=>'Blue']]))===['Red','Blue'],'safe serialized choices');
$check(InvisionPoll::options(serialize(['choices'=>[['question'=>'Pick','choice'=>['A','B']]]]))===['A','B'],'nested Invision choices');
$check(InvisionPoll::options('not a poll')===null,'unsupported encoding denied');
$check(InvisionPoll::options('["Yes","Yes"]')===null,'duplicated answers refused');
$check(InvisionPoll::options(serialize([new stdClass(),'No']))===null,'objects never accepted');
$check(InvisionPoll::options(json_encode(range(1,50)))===null,'answer count bounded');
echo "Invision 1.1.9 content unit: $count PASS\n";
