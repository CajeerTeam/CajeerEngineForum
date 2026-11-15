<?php
/** Pure SSR rendering and sitemap boundaries; runs without a database or Composer. */
declare(strict_types=1);
use CajeerEngine\Forum\{ForumPageRenderer,ForumSeoSettings,ForumSitemapPage,ForumException};
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{if(str_starts_with($class,'CajeerEngine\\')){$path=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($path))require $path;}});
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$boards=array_map(static fn($n)=>sprintf('board-%04d',$n),range(1,1001));
$p=ForumSitemapPage::plan($boards,2100,1);check(count($p['paths'])===1000&&$p['paths'][0]==='/forum'&&$p['thread_limit']===0&&$p['pages']===4,'sitemap first chunk');
$p=ForumSitemapPage::plan($boards,2100,2);check(count($p['paths'])===2&&$p['thread_offset']===0&&$p['thread_limit']===998,'sitemap mixed chunk');
$p=ForumSitemapPage::plan($boards,2100,3);check($p['paths']===[]&&$p['thread_offset']===998&&$p['thread_limit']===1000,'sitemap thread offset');
$p=ForumSitemapPage::plan($boards,2100,4);check($p['thread_offset']===1998,'sitemap tail');
try{ForumSitemapPage::plan([],0,2);throw new RuntimeException('page accepted');}catch(ForumException $e){check($e->status===404,'invalid sitemap page');}
putenv('APP_URL=https://forum.test/');putenv('CMS_STORAGE=file');putenv('FORUM_SEO_INDEX=true');
$settings=new ForumSeoSettings($root);check($settings->baseUrl()==='https://forum.test','canonical origin');
$id='00000000-0000-0000-0000-000000000001';putenv('FORUM_SEO_EXCLUDED_BOARDS='.$id);check(!$settings->permitsPath([['kind'=>'board','id'=>$id,'seo_index'=>1],['kind'=>'board','id'=>'child','seo_index'=>1]]),'ancestor environment exclusion');
putenv('FORUM_SEO_EXCLUDED_BOARDS=');check(!$settings->permitsPath([['kind'=>'board','id'=>$id,'seo_index'=>0],['kind'=>'board','id'=>'child','seo_index'=>1]])&&$settings->permitsPath([['kind'=>'board','id'=>$id,'seo_index'=>1]]),'ancestor board SEO switch');
putenv('FORUM_SEO_INDEX=false');check(!$settings->indexEnabled()&&!$settings->sitemapEnabled(),'global noindex');
$r=new ReflectionClass(ForumPageRenderer::class);$renderer=$r->newInstanceWithoutConstructor();$r->getProperty('settings')->setValue($renderer,$settings);
$invoke=static fn(string $name,array $args)=>$r->getMethod($name)->invokeArgs($renderer,$args);
$p=['id'=>$id,'author'=>'<bad>','author_id'=>$id,'author_profile_visible'=>false,'created_at'=>'2026-01-01 00:00:00','body_format'=>'plain','body_html'=>'<p>Safe &lt;script&gt; text</p>','attachments'=>[['id'=>$id,'original_name'=>'<file>']]];
$html=$invoke('post',[$p,$id]);check(str_contains($html,'&lt;bad&gt;')&&str_contains($html,'&lt;file&gt;')&&!str_contains($html,'/forum/members/')&&str_contains($html,'Принятый ответ')&&str_contains($html,'&lt;script&gt;'),'SSR escapes names and preserves safe body');
$schema=$invoke('discussionSchema',[['posts'=>[$p,$p+[]],'thread'=>['title'=>'Topic'],'interaction'=>['kind'=>'question','question'=>['accepted_post_id'=>$id]],'total_posts'=>2],'/forum/threads/'.$id]);check($schema['@type']==='QAPage'&&$schema['mainEntity']['acceptedAnswer']['text']==='Safe <script> text ','Q&A structured data from visible posts');
$links=$invoke('paging',['/forum/questions',2,true,['status'=>'open']]);check(str_contains($links,'status=open&amp;page=3')&&str_contains($links,'rel="prev"'),'crawlable pagination preserves filter');
check($invoke('summary',[str_repeat('я',200)])===str_repeat('я',160),'Unicode meta description truncation');
