<?php
/** Actual service/SQLite and PostgreSQL transaction and multi-process regression. No HTTP mocks. */
declare(strict_types=1);

use CajeerEngine\Database\{DatabaseManager, MigrationRunner};
use CajeerEngine\Database\Repository\{AuditLogRepository, RoleRepository, UserRepository};
use CajeerEngine\Forum\{ForumException, ForumVersion};
use CajeerEngine\Forum\Repository\{BoardRepository, ThreadRepository, PostRepository, MemberRepository, EngagementRepository, ReportRepository};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Service\{DiscussionService, EngagementService, ModerationService, ForumTransaction, RegistrationService};
use CajeerEngine\Forum\Validation\ForumValidator;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Support\Uuid;

$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';
// Large legacy fixture intentionally exceeds normal new-account budgets; focused anti-spam tests exercise real limits.
putenv('FORUM_SPAM_NEW_THREADS_HOUR=100');putenv('FORUM_SPAM_THREADS_HOUR=100');putenv('FORUM_SPAM_NEW_REPLIES_10MIN=200');putenv('FORUM_SPAM_REPLIES_10MIN=200');putenv('FORUM_SPAM_NEW_PRIVATE_10MIN=100');putenv('FORUM_SPAM_PRIVATE_10MIN=100');
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function expectStatus(int $status, callable $fn): void {
    try { $fn(); } catch (ForumException $e) { check($e->status === $status, 'expected forum status '.$status); return; }
    throw new RuntimeException('Expected forum error '.$status);
}
function services(string $root): array {
    $cfg=new ConfigRepository($root); $db=new DatabaseManager($cfg);
    $tx=new ForumTransaction($db,new AuditLogRepository($db)); $threads=new ThreadRepository($db);
    return [$db,new DiscussionService($db,$tx,new BoardRepository($db),$threads,new PostRepository($db),new MemberRepository($db)),
        new EngagementService($tx,new EngagementRepository($db),$threads),new ModerationService($tx,new ReportRepository($db),$threads),
        new RegistrationService($tx,new UserRepository($db,new RoleRepository($db)),new PasswordHasher($cfg))];
}
if (($argv[1]??'')==='worker') {
    [, $service]=services($root);
    $actor=new ForumAccessPolicy($argv[2],['forum:write']);
    // Synchronize independent processes before the first mutation.
    $deadline=microtime(true)+15;
    while (!is_file($argv[4])) { if (microtime(true)>$deadline) throw new RuntimeException('Barrier timeout'); usleep(1000); }
    for($i=0;$i<5;$i++) $service->reply($actor,$argv[3],'Concurrent reply '.$i);
    exit(0);
}
if (($argv[1]??'')==='report-race') {
    [, , , $service]=services($root);
    $actor=new ForumAccessPolicy($argv[2],['*']);
    $deadline=microtime(true)+15;
    while (!is_file($argv[5])) { if (microtime(true)>$deadline) throw new RuntimeException('Barrier timeout'); usleep(1000); }
    if ($argv[3]==='delete') $service->moderate($actor,$argv[4],'delete');
    elseif ($argv[3]==='resolve') {
        try { $service->resolve($actor,$argv[4]); }
        catch (ForumException $e) { if ($e->status!==404) throw $e; }
    } else throw new LogicException('Unknown race operation');
    exit(0);
}
$tmp=sys_get_temp_dir().'/forum-core-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
try {
    $driver=getenv('FORUM_TEST_DRIVER')==='pgsql'?'pgsql':'sqlite';
    if($driver==='pgsql'){
        $name=(string)getenv('FORUM_TEST_CORE_DB');
        if(!preg_match('/^forum_test_[a-z0-9_]+$/D',$name)||getenv('DB_HOST')!=='127.0.0.1')throw new RuntimeException('Dedicated loopback forum_test_* database required');
        putenv('DB_DATABASE='.$name);
    }
    foreach(['APP_ENV=testing','DB_DRIVER='.$driver,'SQLITE_DATABASE='.$tmp.'/forum.sqlite','DB_SQLITE_BUSY_TIMEOUT_MS=15000','DB_SQLITE_WAL=true','FORUM_REGISTRATION=true','FORUM_REQUIRE_EMAIL_VERIFICATION=false','PASSWORD_ARGON2_MEMORY=8192','PASSWORD_ARGON2_TIME=2'] as $env) putenv($env);
    [$db,$discussion,$engagement,$moderation,$registration]=services($root);
    $pdo=$db->connection();$runner=new MigrationRunner($pdo,$root.'/migrations');
    if($driver==='pgsql' && (int)$pdo->query("SELECT COUNT(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")->fetchColumn()!==0)throw new RuntimeException('Disposable database must be empty');
    $runner->run($driver);
    (new RoleRepository($db))->seedDefaults();
    $member=$registration->register('first@example.test','Member','ForumPass12345');
    $admin=(new UserRepository($db,new RoleRepository($db),new PasswordHasher(new ConfigRepository($root))))->createAdmin('admin@example.test','Admin','AdminPass12345');
    $m=new ForumAccessPolicy($member,['forum:write']);$a=new ForumAccessPolicy($admin,['*']);
    $board=$discussion->index()['boards'][0]['id'];
    $t=$discussion->createThread($m,$board,'Existing topic','First post');$p=$discussion->thread($t,0)['posts'][0]['id'];
    expectStatus(422,fn()=>$discussion->createThread($m,$board,'  ','x'));
    expectStatus(422,fn()=>ForumValidator::text("bad\0text",'body',100));
    expectStatus(422,fn()=>ForumValidator::enabled('true'));
    expectStatus(400,fn()=>ForumValidator::offset(['1']));
    expectStatus(409,fn()=>$registration->register('first@example.test','Duplicate','ForumPass12345'));
    expectStatus(403,fn()=>$discussion->edit(new ForumAccessPolicy($admin,['forum:write']),$p,'No rights'));
    expectStatus(404,fn()=>$moderation->resolve($a,Uuid::v4()));
    $report=$moderation->report($m,$p,'Test report');$moderation->resolve($a,$report);$moderation->resolve($a,$report);
    check((int)$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event='forum.report.resolve'")->fetchColumn()===1,'resolve idempotent audit');
    $engagement->reaction($m,$p,true);$engagement->reaction($m,$p,true);$engagement->watch($m,$t,true);$engagement->watch($m,$t,true);
    check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_reactions')->fetchColumn()===1 && count($engagement->watches($m,0))===1,'idempotent reaction and watch');
    // Current score is derived from public posts and one typed reaction per voter.
    $engagement->reaction($a,$p,true,'helpful');$engagement->reaction($a,$p,true,'helpful');
    check($discussion->profile($member,0)['member']['reputation']['reputation']===2,'idempotent helpful reaction; self reaction excluded');
    $engagement->reaction($a,$p,true,'celebrate');
    check($discussion->profile($member,0)['member']['reputation']['reputation']===1,'reaction replacement adjusts reputation');
    $activity=$discussion->activity();$activity->setRelation($a,$member,['kind'=>'follow','enabled'=>true]);
    check(count($activity->feed($a,'following',0))===1,'follow feed contains publication');
    $activity->setRelation($a,$member,['kind'=>'ignore','enabled'=>true]);
    check($activity->feed($a,'following',0)===[],'ignored author excluded from feed');
    $activity->setRelation($a,$member,['kind'=>'ignore','enabled'=>false]);$activity->setRelation($a,$member,['kind'=>'follow','enabled'=>false]);
    $engagement->reaction($a,$p,false);
    check($discussion->profile($member,0)['member']['reputation']['reputation']===0,'reaction removal adjusts reputation');
    // Failure injection at audit write: both thread and first post must roll back.
    $inject=function(string $event)use($pdo,$driver):void{
        if(!in_array($event,['forum.thread.create','forum.post.reply','forum.thread.merge','forum.thread.bulk'],true))throw new LogicException('Invalid failure event');
        if($driver==='pgsql')$pdo->exec("ALTER TABLE ce_audit_log ADD CONSTRAINT forum_test_abort CHECK (event <> '$event') NOT VALID");
        else $pdo->exec("CREATE TRIGGER forum_test_abort BEFORE INSERT ON ce_audit_log WHEN NEW.event='$event' BEGIN SELECT RAISE(ABORT,'test audit failure'); END");
    };
    $remove=function()use($pdo,$driver):void{$pdo->exec($driver==='pgsql'?'ALTER TABLE ce_audit_log DROP CONSTRAINT forum_test_abort':'DROP TRIGGER forum_test_abort');};
    $inject('forum.thread.create');
    try { $discussion->createThread($m,$board,'Rollback topic','Must not persist'); throw new LogicException('Expected audit failure'); } catch (PDOException) {}
    $remove();
    check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_threads')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()===1,'audit failure rolls back thread and first post');
    $inject('forum.post.reply');
    try { $discussion->reply($m,$t,'Rollback reply'); throw new LogicException('Expected audit failure'); } catch (PDOException) {}
    $remove();
    check(count($discussion->thread($t,0)['posts'])===1,'reply rollback preserves ordinal');
    $workers=[]; $barrier=$tmp.'/go';
    for($i=0;$i<4;$i++) {
        $proc=proc_open([PHP_BINARY,__FILE__,'worker',$member,$t,$barrier],[0=>['file','/dev/null','r'],1=>['file',$tmp.'/worker-'.$i.'.log','w'],2=>['file',$tmp.'/worker-'.$i.'.log','a']],$pipes);
        if(!is_resource($proc)) throw new RuntimeException('Cannot start worker'); $workers[]=[$proc,$i];
    }
    touch($barrier);
    foreach($workers as [$proc,$i]) check(proc_close($proc)===0,'concurrent worker '.$i.' '.file_get_contents($tmp.'/worker-'.$i.'.log'));
    $posts=$discussion->thread($t,0)['posts'];
    check(count($posts)===21 && array_map('intval',array_column($posts,'ordinal'))===range(1,21),'20 parallel replies, unique contiguous ordinals');
    $moderation->moderate($a,$t,'lock');
    expectStatus(409,fn()=>$discussion->reply($m,$t,'Locked'));
    expectStatus(403,fn()=>$discussion->edit($m,$p,'Locked'));
    $discussion->edit($a,$p,'Moderator edit');
    $moderation->moderate($a,$t,'unlock');
    check($runner->run($driver)['applied']===0 && $discussion->thread($t,0)['posts'][0]['body']==='Moderator edit','existing migration ledger and data unchanged');
    $moderation->moderate($a,$t,'delete');
    expectStatus(404,fn()=>$discussion->thread($t,0,$m));
    $moderation->recycle()->change($a,'thread',$t,'restore','',$moderation->recycle()->detail($a,'thread',$t)['item']['deletion_id']);
    check(count($discussion->thread($t,0)['posts'])===21,'restore preserves all posts');
    $moderation->moderate($a,$t,'delete');$moderation->recycle()->change($a,'thread',$t,'purge','',$moderation->recycle()->detail($a,'thread',$t)['item']['deletion_id']);
    foreach(['ce_forum_posts','ce_forum_reactions','ce_forum_watches','ce_forum_reports'] as $table) check((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'cascade '.$table);
    // Real topology operations, preserving content and enforcing inherited publication state.
    $nodes=$discussion->nodes();$cat=$nodes->createCategory($a,'Node category','');$other=$nodes->createCategory($a,'Destination','');
    $rootNode=$nodes->createBoard($a,$cat,'Container','',null,'container');
    $child=$nodes->createBoard($a,$cat,'Child','',$rootNode);$sibling=$nodes->createBoard($a,$cat,'Sibling','',$rootNode);
    $nested=$nodes->createBoard($a,$cat,'Nested','',$child);
    expectStatus(403,fn()=>$nodes->move($m,$child,$other,null,0));
    expectStatus(409,fn()=>$nodes->move($a,$rootNode,$cat,$nested,null));
    expectStatus(409,fn()=>$nodes->createBoard($a,$other,'Wrong parent','',$rootNode));
    expectStatus(409,fn()=>$discussion->createThread($m,$rootNode,'Container topic','Blocked'));
    $topic=$discussion->createThread($m,$nested,'Nested topic','Original');$original=$discussion->thread($topic,0)['posts'][0]['id'];
    $nodes->reorder($a,'board',$sibling,'board',$child,'before');
    expectStatus(409,fn()=>$nodes->reorder($a,'board',$rootNode,'board',$nested,'inside'));
    check(array_column($discussion->thread($topic,0)['breadcrumbs'],'id')===[$cat,$rootNode,$child,$nested],'nested thread breadcrumbs');
    $inspected=$discussion->permissions()->inspect($a,'board',$nested,$member);
    check($inspected['effective']===$discussion->board($nested,0,$m)['board']['permissions'],'inspector equals runtime ACL');
    expectStatus(403,fn()=>$discussion->permissions()->inspect($m,'board',$nested,$member));
    check(array_column($discussion->board($rootNode,0)['children'],'id')===[$sibling,$child],'sibling reorder');
    $nodes->move($a,$child,$other,null,0);
    check($discussion->board($nested,0)['board']['category_id']===$other && $discussion->board($nested,0)['board']['parent_id']===$child,'subtree cross-category move');
    check($discussion->thread($topic,0)['posts'][0]['id']===$original,'move preserves thread and post IDs');
    expectStatus(409,fn()=>$nodes->delete($a,$child,false));
    expectStatus(409,fn()=>$nodes->delete($a,$nested,false));
    expectStatus(409,fn()=>$nodes->delete($a,$other,true));
    expectStatus(409,fn()=>$nodes->updateBoard($a,$nested,'Nested','','container','open'));
    expectStatus(422,fn()=>$nodes->move($a,$child,$cat,$rootNode,999));
    check($discussion->board($child,0)['board']['category_id']===$other,'invalid position rolls back entire move');
    $nodes->updateBoard($a,$child,'Child','','forum','archived');
    expectStatus(409,fn()=>$discussion->reply($m,$topic,'Blocked'));
    expectStatus(409,fn()=>$discussion->edit($a,$original,'Blocked'));
    expectStatus(409,fn()=>$discussion->createThread($m,$nested,'Blocked topic','Blocked'));
    check($discussion->thread($topic,0)['board']['effective_state']==='archived','archive inherited and content still readable');
    $nodes->updateBoard($a,$child,'Child','','forum','open');$discussion->reply($m,$topic,'Open again');
    $nodes->updateCategory($a,$other,'Renamed destination','Updated',0);
    check($discussion->index()['categories'][0]['id']===$other,'category rename and reorder');
    $moderation->moderate($a,$topic,'delete');$moderation->recycle()->change($a,'thread',$topic,'purge','',$moderation->recycle()->detail($a,'thread',$topic)['item']['deletion_id']);$nodes->delete($a,$nested,false);$nodes->delete($a,$child,false);$nodes->delete($a,$other,true);
    check(!in_array($other,array_column($discussion->index()['categories'],'id'),true),'empty nodes removed safely');
    // Discussion management uses the same real transactions and audit log on both drivers.
    $management=$discussion->management();
    $mc=$nodes->createCategory($a,'Management regression','');
    $dest=$nodes->createBoard($a,$mc,'Discussion destination','',null,'forum','open',null,'Respect other participants.');
    check($discussion->board($dest,0)['board']['rules']==='Respect other participants.','board rules persisted');
    $nodes->updateBoard($a,$dest,'Discussion destination','Updated','forum','open');
    check($discussion->board($dest,0)['board']['rules']==='Respect other participants.','omitted board rules preserved');
    $source=$discussion->createThread($m,$board,'Management source','First source');
    $target=$discussion->createThread($m,$board,'Management target','First target');
    $first=$discussion->thread($source,0)['first_post_id'];
    $reply=$discussion->reply($m,$source,'Transferred reply');
    $extra=$discussion->reply($m,$source,'Another transferred reply');
    $engagement->reaction($m,$reply,true);$rid=$moderation->report($m,$reply,'Keep report');
    $engagement->watch($m,$source,true);$engagement->watch($m,$target,true);
    expectStatus(403,fn()=>$management->bulk($m,[$source],'move',$dest));
    $management->metadata($a,$source,'Question',['PHP','php','Migration']);
    $management->metadata($a,$target,'Answer',['SQLite']);
    check($discussion->thread($source,0)['thread']['tags']===['migration','php'],'tags normalized and deduplicated');
    check(in_array($source,array_column($discussion->search('',0,$m,'php'),'id'),true),'exact tag lookup');
    expectStatus(422,fn()=>$management->metadata($a,$source,'',array_fill(0,11,'tag')));
    $management->bulk($a,[$source,$target],'pin');
    check((int)$discussion->thread($source,0)['thread']['pinned']===1 && (int)$discussion->thread($target,0)['thread']['pinned']===1,'bulk pin all');
    expectStatus(404,fn()=>$management->bulk($a,[$source,Uuid::v4()],'unpin'));
    check((int)$discussion->thread($source,0)['thread']['pinned']===1,'bulk failure leaves all selected topics unchanged');
    $management->bulk($a,[$source],'move',$dest);
    check($discussion->thread($source,0)['thread']['board_id']===$dest,'move preserves topic id');
    expectStatus(409,fn()=>$management->transfer($a,$source,[$first],null,$dest,'Invalid split'));
    $split=$management->transfer($a,$source,[$reply],null,$dest,'Split discussion')['id'];
    check($discussion->thread($split,0)['first_post_id']===$reply && $discussion->thread($split,0)['thread']['author_id']===$member,'split preserves original post and author');
    check((int)$discussion->thread($split,0)['posts'][0]['likes']===1,'split retains reactions');
    expectStatus(409,fn()=>$management->transfer($a,$source,[$reply],$target,null,null));
    $management->transfer($a,$source,[$extra],$split,null,null);
    check(array_column($discussion->thread($split,0)['posts'],'id')===[$reply,$extra],'move selected replies maintains order');
    $inject('forum.thread.merge');
    try { $management->merge($a,$target,[$source,$split]);throw new LogicException('Expected merge audit failure'); } catch (PDOException) {}
    $remove();
    check($discussion->thread($source,0)['thread']['id']===$source && $discussion->thread($split,0)['first_post_id']===$reply,'failed merge rolls back posts and redirects');
    $management->merge($a,$target,[$source,$split]);
    $merged=$discussion->thread($target,0);
    check(array_slice(array_column($merged['posts'],'id'),1)===[$first,$reply,$extra],'merge retains ordered post UUIDs');
    check($merged['thread']['tags']===['migration','php','sqlite'] && $merged['thread']['prefix']==='Answer','merge preserves target prefix and combines tags');
    check($discussion->thread($source,0)['thread']['id']===$target && $discussion->thread($split,0)['thread']['id']===$target,'old merged URLs resolve');
    check(count(array_filter($engagement->watches($m,0),fn($w)=>$w['id']===$target))===1,'merge deduplicates subscriptions');
    $moderation->resolve($a,$rid); // report still follows the moved post
    $discussion->permissions()->replace($a,'board',$dest,'hidden',[]);
    expectStatus(404,fn()=>$discussion->thread($source,0,$m));
    check($discussion->thread($target,0,$m)['thread']['id']===$target,'public target remains readable');
    $discussion->permissions()->replace($a,'board',$dest,'public',[]);
    $next=$discussion->createThread($m,$board,'Final target','First final');
    $management->merge($a,$next,[$target]);
    check($discussion->thread($source,0)['thread']['id']===$next && $discussion->thread($target,0)['thread']['id']===$next,'redirects flatten after another merge');
    $moderation->moderate($a,$next,'delete');
    expectStatus(404,fn()=>$discussion->thread($source,0));
    // Merge against independent processes replying to the target: no duplicate or lost ordinals.
    $parallel=$discussion->createThread($m,$board,'Parallel target','Target first');
    $parallelSource=$discussion->createThread($m,$board,'Parallel source','Source first');
    $parallelPost=$discussion->thread($parallelSource,0)['first_post_id'];
    $workers=[];$barrier=$tmp.'/merge-go';
    for($i=0;$i<4;$i++) {
        $proc=proc_open([PHP_BINARY,__FILE__,'worker',$member,$parallel,$barrier],[0=>['file','/dev/null','r'],1=>['file',$tmp.'/merge-worker-'.$i.'.log','w'],2=>['file',$tmp.'/merge-worker-'.$i.'.log','a']],$pipes);
        if(!is_resource($proc)) throw new RuntimeException('Cannot start merge worker');$workers[]=[$proc,$i];
    }
    touch($barrier);$management->merge($a,$parallel,[$parallelSource]);
    foreach($workers as [$proc,$i]) check(proc_close($proc)===0,'merge worker '.$i.' '.file_get_contents($tmp.'/merge-worker-'.$i.'.log'));
    $all=$discussion->thread($parallel,0)['posts'];
    check(count($all)===22 && array_map('intval',array_column($all,'ordinal'))===range(1,22) && in_array($parallelPost,array_column($all,'id'),true),'concurrent merge and 20 replies preserve ordinals and post IDs');
    $moderation->moderate($a,$parallel,'delete');
    $nodes->delete($a,$dest,false);$nodes->delete($a,$mc,true);
    // Resolve and cascade-delete concurrently. Both operations must acquire the
    // topic before the report; resolution may legitimately lose to deletion.
    $raceThread=$discussion->createThread($m,$board,'Report race topic','Race body');
    $racePost=$discussion->thread($raceThread,0)['first_post_id'];
    $raceReport=$moderation->report($m,$racePost,'Race report');
    $workers=[];$barrier=$tmp.'/report-go';
    foreach (['resolve'=>$raceReport,'delete'=>$raceThread] as $action=>$id) {
        $log=$tmp.'/report-'.$action.'.log';
        $proc=proc_open([PHP_BINARY,__FILE__,'report-race',$admin,$action,$id,$barrier],[0=>['file','/dev/null','r'],1=>['file',$log,'w'],2=>['file',$log,'a']],$pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start report race worker');
        $workers[]=[$proc,$log];
    }
    touch($barrier);
    foreach ($workers as [$proc,$log]) check(proc_close($proc)===0,'report/delete worker '.file_get_contents($log));
    expectStatus(404,fn()=> $discussion->thread($raceThread,0));
    $moderation->resolve($a,$raceReport);$moderation->recycle()->change($a,'thread',$raceThread,'purge','',$moderation->recycle()->detail($a,'thread',$raceThread)['item']['deletion_id']);
    check((new \CajeerEngine\Forum\ForumIntegrityCheck($db))->check()['ok'],'forum data integrity after concurrent moderation');

    // Exercise actual service, transaction, live ACL, and quotas on both DB drivers.
    $files=$discussion->attachments();$previousMax=getenv('FORUM_ATTACHMENT_MAX_MB');$previousQuota=getenv('FORUM_ATTACHMENT_QUOTA_MB');$previousGroup=getenv('FORUM_ATTACHMENT_GROUP_LIMITS');
    try {
        putenv('FORUM_ATTACHMENT_MAX_MB=1');putenv('FORUM_ATTACHMENT_QUOTA_MB=1');putenv('FORUM_ATTACHMENT_GROUP_LIMITS={}');
        $blob=str_repeat("Attachment quota test.\n",30000);
        $file=$files->upload($m,'report.txt',$blob);$aid=$file['id'];
        expectStatus(409,fn()=>$files->upload($m,'overflow.txt',$blob));
        expectStatus(404,fn()=>$files->get($a,$aid));
        expectStatus(404,fn()=>$discussion->createThread($a,$board,'Foreign attachment','Denied','plain',[$aid]));
        check($files->get($m,$aid,true)['data']===$blob,'pending bytes preserved after failed publication');
        $fileThread=$discussion->createThread($m,$board,'Attachment regression','File message','plain',[$aid]);
        $filePost=$discussion->thread($fileThread,0)['posts'][0];
        check($filePost['attachments'][0]['id']===$aid&&$files->get(new ForumAccessPolicy('',[]),$aid,true)['data']===$blob,'public attachment bytes and metadata');
        expectStatus(404,fn()=>$discussion->reply($m,$fileThread,'Reuse','plain',[$aid]));
        $moderation->moderate($a,$fileThread,'delete');expectStatus(404,fn()=>$files->get($m,$aid));
        $expired=$files->upload($m,'expired.txt','Expiry test file')['id'];
        $pdo->prepare('UPDATE ce_forum_attachments SET created_at=?,expires_at=? WHERE id=?')->execute([time()-86401,time()-1,$expired]);
        check($files->cleanup()===1,'expired pending file removed by service');
        expectStatus(404,fn()=>$files->get($m,$expired));
    } finally {
        foreach(['FORUM_ATTACHMENT_MAX_MB'=>$previousMax,'FORUM_ATTACHMENT_QUOTA_MB'=>$previousQuota,'FORUM_ATTACHMENT_GROUP_LIMITS'=>$previousGroup] as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    }

    // New draft publication uses the normal domain service and one outer transaction.
    $drafts=$discussion->drafts();$did=Uuid::v4();$data=['scope'=>'thread','target_id'=>$board,'revision_number'=>0,'payload'=>['title'=>'Draft integration','body'=>'**Draft body**','body_format'=>'markdown','attachment_ids'=>[]]];
    $saved=$drafts->save($m,$did,$data);check($saved['revision_number']===1,'draft saved');
    expectStatus(404,fn()=>$drafts->get($a,$did));
    check($drafts->save($m,$did,$data)==$saved,'idempotent draft save replay');
    $conflict=$data;$conflict['payload']['body']='Conflicting text';expectStatus(409,fn()=>$drafts->save($m,$did,$conflict));
    $data['revision_number']=1;$data['payload']['body']='New draft text';$saved=$drafts->save($m,$did,$data);
    $published=$drafts->publish($m,$did,2,$discussion);check($drafts->publish($m,$did,2,$discussion)===$published,'idempotent draft publication');
    $dp=$discussion->thread($published['id'],0,$m)['posts'][0];check($dp['body']==='New draft text'&&$dp['revision_number']===1,'draft text published with initial revision');
    $discussion->edit($m,$dp['id'],'Second version','plain',[],1,'test edit');
    expectStatus(409,fn()=>$discussion->edit($m,$dp['id'],'Stale version','plain',[],1));
    $history=$discussion->revisionHistory($m,$dp['id']);check(count($history)===2&&$history[0]['body']==='Second version','revision history contains original and edit');
    $discussion->restoreRevision($m,$dp['id'],1,2);check($discussion->thread($published['id'],0,$m)['posts'][0]['body']==='New draft text','restore through actual edit service');
    check(count($discussion->revisionHistory($m,$dp['id']))===3,'restore is a new revision');
    $moderation->moderate($a,$published['id'],'delete');$moderation->recycle()->change($a,'thread',$published['id'],'purge','',$moderation->recycle()->detail($a,'thread',$published['id'])['item']['deletion_id']);
    check((int)$pdo->query("SELECT COUNT(*) FROM ce_forum_post_revisions WHERE post_id=".$pdo->quote($dp['id']))->fetchColumn()===0,'revision cascade');

    // Exercise the actual service, not just the SQL model, on both CI databases.
    putenv('APP_KEY=forum-core-test-secret');putenv('FORUM_SPAM_NEW_THREADS_HOUR=3');
    $spamUser=$registration->register('spam-regression@example.test','Spam test','ForumPass12345');$spamActor=new ForumAccessPolicy($spamUser,['forum:write']);
    $queue=$discussion->moderationQueue();$long='A long duplicate example for the actual publication pipeline, deliberately longer than eighty characters.';
    $clean=$queue->submit($spamActor,'thread',$board,['title'=>'Clean topic','body'=>$long],$discussion);
    check(!isset($clean['pending_review']),'clean anti-spam publication');
    $queued=$queue->submit($spamActor,'thread',$board,['title'=>'Duplicate topic','body'=>$long],$discussion);
    check($queued['pending_review']===true,'duplicate held for moderation');
    expectStatus(404,fn()=>$discussion->thread($queued['submission_id'],0));
    $approved=$queue->action($a,'submission',$queued['submission_id'],['action'=>'approve','revision_number'=>1],$discussion);
    check($approved['status']==='approved','review approval bypasses duplicate charge');
    $third=$queue->submit($spamActor,'thread',$board,['title'=>'Link topic','body'=>'https://one.example/a https://two.example/b https://three.example/c'],$discussion);
    check($third['pending_review']===true,'link spam held');
    expectStatus(429,fn()=>$queue->submit($spamActor,'thread',$board,['title'=>'Flood topic','body'=>'Fourth publication'],$discussion));
    expectStatus(422,fn()=>$discussion->edit($spamActor,$discussion->thread($clean['id'],0)['posts'][0]['id'],'https://one.example/a https://two.example/b https://three.example/c'));
    putenv('FORUM_SPAM_REGISTRATIONS_HOUR=2');putenv('FORUM_SPAM_REGISTRATIONS_GLOBAL_HOUR=3');$registration->checkAttempt('192.0.2.1',null);expectStatus(422,fn()=>$registration->checkAttempt('192.0.2.1','bot'));
    expectStatus(429,fn()=>$registration->checkAttempt('192.0.2.1',null));$registration->checkAttempt('192.0.2.2',null);expectStatus(429,fn()=>$registration->checkAttempt('192.0.2.3',null));
    check(count($discussion->antiSpam()->stats($a)['review_reasons'])>=2,'admin aggregate anti-spam diagnostics');
    expectStatus(403,fn()=>$discussion->antiSpam()->stats($spamActor));

    if($driver==='sqlite')check($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'foreign keys intact');
    $v=new ForumVersion($root);check($v->current()==='0.8.3' && $v->upstream()==='1.3.0','independent product/core versions');
    echo "Forum core regression passed\n";
} finally {
    unset($db,$discussion,$engagement,$moderation,$registration,$pdo,$runner);
    foreach(glob($tmp.'/*')?:[] as $file) unlink($file); rmdir($tmp);
}
