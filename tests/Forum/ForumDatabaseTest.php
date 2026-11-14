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
        } finally {
            unset($runner,$pdo,$db,$audit,$tx);
            (new \CajeerEngine\Observability\Metrics($tmp))->flush();
            foreach($previous as $key=>$value) putenv($value===false?$key:$key.'='.$value);
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file) { if($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
            rmdir($tmp);
        }
    }
}
