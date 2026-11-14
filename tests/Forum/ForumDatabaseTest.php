<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Database\{DatabaseManager, MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\Service\ForumTransaction;
use CajeerEngine\Runtime\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class ForumDatabaseTest extends TestCase
{
    public function testAtomicAuditAndMigrationLedger(): void
    {
        $root=dirname(__DIR__,2);
        $tmp=sys_get_temp_dir().'/forum-phpunit-'.bin2hex(random_bytes(8));
        mkdir($tmp.'/config',0700,true);
        foreach (glob($root.'/config/*.php') as $file) copy($file,$tmp.'/config/'.basename($file));
        $driver=getenv('FORUM_TEST_DRIVER')==='pgsql'?'pgsql':'sqlite';
        $database=$driver==='pgsql'?(string)getenv('FORUM_TEST_PHPUNIT_DB'):$tmp.'/forum.sqlite';
        $env=['DB_DRIVER'=>$driver,'DB_DATABASE'=>$database,'SQLITE_DATABASE'=>$tmp.'/forum.sqlite'];
        $previous=[];
        foreach ($env as $key=>$value) { $previous[$key]=getenv($key); putenv($key.'='.$value); }
        try {
            if($driver==='pgsql' && (!preg_match('/^forum_test_[a-z0-9_]+$/D',$database) || getenv('DB_HOST')!=='127.0.0.1')) self::fail('Dedicated loopback PHPUnit database required');
            $db=new DatabaseManager(new ConfigRepository($tmp)); $pdo=$db->connection();
            if($driver==='pgsql') self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM pg_catalog.pg_tables WHERE schemaname='public'")->fetchColumn());
            $runner=new MigrationRunner($pdo,$root.'/migrations');
            self::assertGreaterThan(0,$runner->run($driver)['applied']);
            self::assertSame(0,$runner->run($driver)['applied']);
            $status=$runner->status($driver);
            self::assertSame([],$status['pending']); self::assertSame([],$status['failed']);
            foreach($status['applied'] as $migration) self::assertTrue($migration['checksum_ok']);
            self::assertTrue($runner->verifyApplied($driver)['ok']);
            $roles = new \CajeerEngine\Database\Repository\RoleRepository($db);
            $roles->seedDefaults();
            $forum = new \CajeerEngine\Forum\ForumDatabaseCheck($db);
            self::assertTrue($forum->check()['ok']);
            $name = $status['applied'][0]['migration'];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE ce_migrations SET checksum=:checksum WHERE migration=:name');
                $stmt->execute(['checksum'=>str_repeat('0',64),'name'=>$name]);
                self::assertSame('checksum',$runner->verifyApplied($driver)['errors'][$name]);
                $stmt->execute(['checksum'=>'','name'=>$name]);
                self::assertSame('checksum',$runner->verifyApplied($driver)['errors'][$name]);
            } finally { $pdo->rollBack(); }
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT INTO ce_migrations (migration,checksum) VALUES ('9999_orphan.sql','orphan')");
                self::assertSame('missing',$runner->verifyApplied($driver)['errors']['9999_orphan.sql']);
                $pdo->exec('DROP INDEX ce_forum_post_thread');
                self::assertContains('ce_forum_post_thread',$forum->check()['missing_indexes']);
            } finally { $pdo->rollBack(); }
            self::assertTrue($runner->verifyApplied($driver)['ok']);
            self::assertTrue($forum->check()['ok']);
            try { $roles->delete('forum-member'); self::fail('System role deletion allowed'); }
            catch (\InvalidArgumentException) {}
            $audit=new AuditLogRepository($db); $tx=new ForumTransaction($db,$audit);
            $before=$audit->count();
            try {
                $tx->write(function()use($audit){$audit->record('forum.test.rollback');throw new \DomainException('rollback');});
                self::fail('Expected rollback');
            } catch (\DomainException $e) { self::assertSame('rollback',$e->getMessage()); }
            self::assertSame($before,$audit->count());
            $tx->write(function()use($tx,$audit){
                $audit->record('forum.test.outer');
                try { $tx->write(function()use($audit){$audit->record('forum.test.inner');throw new \DomainException('nested rollback');}); }
                catch (\DomainException) {}
                $audit->record('forum.test.after');
            });
            self::assertSame($before+2,$audit->count());
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event='forum.test.inner'")->fetchColumn());
            self::assertFalse($pdo->inTransaction());
            $users=new \CajeerEngine\Database\Repository\UserRepository($db,$roles);
            $admin=$users->create('groups-admin@example.test','Admin','unused-test-hash',['admin']);
            $member=$users->create('groups-member@example.test','Member','unused-test-hash',['forum-member','viewer']);
            $actor=new \CajeerEngine\Forum\Security\ForumAccessPolicy($admin,['*']);
            $groups=$tx->groups();$default=$roles->findIdByHandle('forum-member');
            self::assertSame($default,$groups->membership($actor,$member)['primary_group_id']);
            $vip=$groups->create($actor,'test-vip','VIP','Private access',['forum:write']);
            $mod=$groups->create($actor,'test-mod','Moderation','',['forum:write','forum:moderate']);
            self::assertSame($vip['id'],$groups->assign($actor,$member,$vip['id'],[$default,$mod['id']])['primary_group_id']);
            self::assertContains('system:read',$users->permissionsForUser($member));
            self::assertContains('forum:moderate',$users->permissionsForUser($member));
            $users->syncRoles($member,['viewer','forum-member',$vip['handle'],$mod['handle']]);
            self::assertSame($vip['id'],$groups->membership($actor,$member)['primary_group_id']);
            $board='22222222-2222-4222-8222-222222222222';
            $m=new \CajeerEngine\Forum\Security\ForumAccessPolicy($member,['forum:write','forum:moderate']);
            self::assertFalse($tx->permissions()->access($m)['boards'][$board]['thread:moderate']);
            $tx->permissions()->replace($actor,'board',$board,'private',[
                ['subject_type'=>'role','subject_id'=>$vip['id'],'permission'=>'thread:read','effect'=>'allow'],
                ['subject_type'=>'role','subject_id'=>$mod['id'],'permission'=>'thread:moderate','effect'=>'allow'],
            ]);
            self::assertTrue($tx->permissions()->access($m)['boards'][$board]['thread:moderate']);
            $groups->assign($actor,$member,$default,[]);
            self::assertFalse($tx->permissions()->access($m)['boards'][$board]['thread:read']);
            self::assertNotContains('forum:moderate',$users->permissionsForUser($member));
            self::assertContains('system:read',$users->permissionsForUser($member));
            try { $groups->delete($actor,$vip['id']);self::fail('Referenced group deleted'); }
            catch (\CajeerEngine\Forum\ForumException $e) {self::assertSame(409,$e->status);}
            $tx->permissions()->replace($actor,'board',$board,'public',[]);
            $groups->delete($actor,$vip['id']);$groups->delete($actor,$mod['id']);
            self::assertNull($roles->find($vip['id']));
            self::assertTrue($forum->check()['ok']);
            $integrity=new \CajeerEngine\Forum\ForumIntegrityCheck($db);
            self::assertTrue($integrity->check()['ok']);
            // Validate actual DB corruption rejection, not a mocked diagnostic.
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)')->execute(['99999999-9999-4999-8999-999999999999',$board,$member,'Incomplete thread']);
                self::assertSame(1,$integrity->check()['violations']['empty_threads']);
                $pdo->prepare('DELETE FROM ce_forum_primary_groups WHERE user_id=?')->execute([$member]);
                self::assertSame(1,$integrity->check()['violations']['missing_primary_groups']);
            } finally { $pdo->rollBack(); }
            self::assertTrue($integrity->check()['ok']);
            // Real transactional publication and cursor writes on both certified DBs.
            $boards=new \CajeerEngine\Forum\Repository\BoardRepository($db);
            $threads=new \CajeerEngine\Forum\Repository\ThreadRepository($db);
            $posts=new \CajeerEngine\Forum\Repository\PostRepository($db);
            $discussion=new \CajeerEngine\Forum\Service\DiscussionService($db,$tx,$boards,$threads,$posts,new \CajeerEngine\Forum\Repository\MemberRepository($db));
            $reader=new \CajeerEngine\Forum\Security\ForumAccessPolicy($member,['forum:write']);
            $thread=$discussion->createThread($actor,$board,'Read tracking','First');
            $page=$discussion->thread($thread,0,$reader);$first=$page['posts'][0];
            $reply=$discussion->reply($actor,$thread,'Second');
            $ack=['scope'=>'thread','id'=>$thread,'post_id'=>$first['id'],'sequence'=>(int)$first['read_sequence']];
            $read=$discussion->readTracking();
            self::assertSame(1,$read->mark($reader,$ack)['unread_posts']);
            self::assertSame($reply,$discussion->thread($thread,0,$reader)['read_state']['first_unread']['id']);
            self::assertSame(0,$read->mark($reader,['scope'=>'thread','id'=>$thread])['unread_posts']);
            self::assertSame(0,$read->mark($reader,$ack)['unread_posts']);
            self::assertSame(2,$discussion->thread($thread,0,$actor)['read_state']['unread_posts']);
            $target=$discussion->createThread($actor,$board,'Target','First');
            $read->mark($reader,['scope'=>'thread','id'=>$target]);
            $discussion->management()->transfer($actor,$thread,[$reply],$target,null,null);
            self::assertSame(1,$discussion->thread($target,0,$reader)['read_state']['unread_posts']);
            $replacement=$discussion->reply($actor,$thread,'Replacement');
            self::assertSame($replacement,$discussion->thread($thread,0,$reader)['read_state']['first_unread']['id']);
            $read->mark($reader,['scope'=>'board','id'=>$board]);
            self::assertSame([],$read->unread($reader,0));
            $discussion->management()->merge($actor,$target,[$thread]);
            self::assertSame(2,$discussion->thread($target,0,$reader)['read_state']['unread_posts']);
            self::assertTrue($integrity->check()['ok']);

            // Outbox rows commit/rollback with the actual publication, not external queue IO.
            $notifications=$discussion->notifications();
            $notified=$discussion->createThread($reader,$board,'Notify owner','First');
            $first=$discussion->thread($notified,0,$reader)['first_post_id'];
            try {
                $tx->write(function()use($discussion,$actor,$notified,$member){$discussion->reply($actor,$notified,'@{'.$member.'}');throw new \DomainException('rollback notification');});
                self::fail('Expected publication rollback');
            }catch(\DomainException $e){self::assertSame('rollback notification',$e->getMessage());}
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM ce_forum_notifications')->fetchColumn());
            $sent=$discussion->reply($actor,$notified,'@{'.$member.'} @{'.$member.'} >>{'.$first.'}');
            self::assertSame(0,$notifications->inbox($reader,0)['unread']);
            self::assertSame(3,$notifications->dispatch());
            self::assertSame(0,$notifications->dispatch());
            $inbox=$notifications->inbox($reader,0);
            self::assertSame(3,$inbox['unread']);
            self::assertEqualsCanonicalizing(['reply','mention','quote'],array_column($inbox['items'],'kind'));
            self::assertSame($sent,$notifications->target($reader,$inbox['items'][0]['id'])['post_id']);
            self::assertSame(2,$notifications->mark($reader,['id'=>$inbox['items'][0]['id']])['unread']);
            $tx->permissions()->replace($actor,'board',$board,'hidden',[]);
            self::assertSame(0,$notifications->inbox($reader,0)['unread']);
            $tx->permissions()->replace($actor,'board',$board,'public',[]);
            self::assertSame(2,$notifications->inbox($reader,0)['unread']);
            self::assertSame(0,$notifications->mark($reader,['all'=>true])['unread']);

            // Mail events use the same publication transaction and survive retries.
            $watch=$discussion->watchDelivery();
            $watchRepo=new \CajeerEngine\Forum\Repository\WatchRepository($db);
            $pdo->prepare('UPDATE ce_users SET email_verified_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$member]);
            $watch->set($reader,'thread',$notified,true,'daily');
            $now=time();
            $daily=$watchRepo::due('daily',$now);$weekly=$watchRepo::due('weekly',$now);
            self::assertGreaterThan($now,$daily);self::assertLessThanOrEqual($now+86400,$daily);
            self::assertSame('Mon 00:00',gmdate('D H:i',$weekly));
            try {
                $tx->write(function()use($discussion,$actor,$notified){$discussion->reply($actor,$notified,'Mail rollback');throw new \DomainException('rollback mail');});
                self::fail('Expected mail rollback');
            }catch(\DomainException $e){self::assertSame('rollback mail',$e->getMessage());}
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM ce_forum_mail_delivery')->fetchColumn());
            $discussion->reply($actor,$notified,'Mail one');$discussion->reply($actor,$notified,'Mail two');
            self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM ce_forum_mail_delivery')->fetchColumn());
            self::assertNull($watchRepo->candidate($now));
            $pdo->exec('UPDATE ce_forum_mail_delivery SET due_at=0');
            $candidate=$watchRepo->candidate($now);self::assertNotNull($candidate);
            $lease='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            $ids=$tx->write(fn()=> $watchRepo->claim($candidate,$now,$lease));self::assertCount(2,$ids);
            self::assertNull($watchRepo->candidate($now));
            $tx->write(fn()=> $watchRepo->retry($lease,$now));
            self::assertNull($watchRepo->candidate($now));
            self::assertSame(2,(int)$pdo->query("SELECT COUNT(*) FROM ce_forum_mail_delivery WHERE attempts=1 AND state='pending' AND lease_token IS NULL")->fetchColumn());
            $pdo->exec('UPDATE ce_forum_mail_delivery SET due_at=0');
            $candidate=$watchRepo->candidate($now);self::assertNotNull($candidate);
            $tx->write(fn()=> $watchRepo->claim($candidate,$now,$lease));
            $tx->write(fn()=> $watchRepo->complete($lease,$ids,'sent',$now));
            self::assertSame(2,(int)$pdo->query("SELECT COUNT(*) FROM ce_forum_mail_delivery WHERE state='sent'")->fetchColumn());
            $discussion->reply($actor,$notified,'Mail three');
            $watch->set($reader,'thread',$notified,true,'none');
            self::assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM ce_forum_mail_delivery WHERE state='cancelled'")->fetchColumn());

            // Simulate a request authenticated before its role was removed. Services
            // must recheck the DB, not trust the stale wildcard from middleware.
            $oldAdmin=$users->create('stale-admin@example.test','Stale admin','unused-test-hash',['admin']);
            $stale=new \CajeerEngine\Forum\Security\ForumAccessPolicy($oldAdmin,['*']);
            $users->syncRoles($oldAdmin,['forum-member']);
            $nodes=new \CajeerEngine\Forum\Service\NodeService($tx,new \CajeerEngine\Forum\Repository\BoardRepository($db));
            foreach ([fn()=> $nodes->createCategory($stale,'Forbidden',''),fn()=> $groups->all($stale),fn()=> $groups->create($stale,'forbidden','Forbidden','',['forum:write']),fn()=> $tx->permissions()->replace($stale,'board',$board,'hidden',[]),fn()=> $tx->permissions()->inspect($stale,'board',$board,null)] as $operation) {
                try { $operation(); self::fail('Stale administrator grant accepted'); }
                catch (\CajeerEngine\Forum\ForumException $e) { self::assertSame(403,$e->status); }
            }
            $users->disable($member);
            try { $tx->permissions()->access($m); self::fail('Disabled actor retained ACL'); }
            catch (\CajeerEngine\Forum\ForumException $e) { self::assertSame(401,$e->status); }
            $inspection=$tx->permissions()->inspect($actor,'board',$board,$member);
            self::assertTrue($inspection['trace']['inactive']);
            self::assertNotContains(true,$inspection['effective']);
            self::assertTrue($integrity->check()['ok']);

        } finally {
            unset($runner,$pdo,$db,$audit,$tx);
            (new \CajeerEngine\Observability\Metrics($tmp))->flush();
            foreach($previous as $key=>$value) putenv($value===false?$key:$key.'='.$value);
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file) { if($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
            rmdir($tmp);
        }
    }
}
