<?php
/** Actual service/SQLite transaction and multi-process regression. No HTTP mocks. */
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
$tmp=sys_get_temp_dir().'/forum-core-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
try {
    foreach(['APP_ENV=testing','DB_DRIVER=sqlite','SQLITE_DATABASE='.$tmp.'/forum.sqlite','DB_SQLITE_BUSY_TIMEOUT_MS=15000','DB_SQLITE_WAL=true','FORUM_REGISTRATION=true','PASSWORD_ARGON2_MEMORY=8192','PASSWORD_ARGON2_TIME=2'] as $env) putenv($env);
    [$db,$discussion,$engagement,$moderation,$registration]=services($root);
    $pdo=$db->connection();$runner=new MigrationRunner($pdo,$root.'/migrations');$runner->run('sqlite');
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
    // Failure injection at audit write: both thread and first post must roll back.
    $pdo->exec("CREATE TRIGGER forum_test_abort BEFORE INSERT ON ce_audit_log WHEN NEW.event='forum.thread.create' BEGIN SELECT RAISE(ABORT,'test audit failure'); END");
    try { $discussion->createThread($m,$board,'Rollback topic','Must not persist'); throw new LogicException('Expected audit failure'); } catch (PDOException) {}
    $pdo->exec('DROP TRIGGER forum_test_abort');
    check((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_threads')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()===1,'audit failure rolls back thread and first post');
    $pdo->exec("CREATE TRIGGER forum_test_abort BEFORE INSERT ON ce_audit_log WHEN NEW.event='forum.post.reply' BEGIN SELECT RAISE(ABORT,'test audit failure'); END");
    try { $discussion->reply($m,$t,'Rollback reply'); throw new LogicException('Expected audit failure'); } catch (PDOException) {}
    $pdo->exec('DROP TRIGGER forum_test_abort');
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
    check($runner->run('sqlite')['applied']===0 && $discussion->thread($t,0)['posts'][0]['body']==='Moderator edit','existing migration ledger and data unchanged');
    $moderation->moderate($a,$t,'delete');
    foreach(['ce_forum_posts','ce_forum_reactions','ce_forum_watches','ce_forum_reports'] as $table) check((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'cascade '.$table);
    check($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'foreign keys intact');
    $v=new ForumVersion($root);check($v->current()==='0.1.2' && $v->upstream()==='1.3.0','independent product/core versions');
    echo "Forum core regression passed\n";
} finally {
    unset($db,$discussion,$engagement,$moderation,$registration,$pdo,$runner);
    foreach(glob($tmp.'/*')?:[] as $file) unlink($file); rmdir($tmp);
}
