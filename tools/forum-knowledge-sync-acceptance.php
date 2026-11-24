#!/usr/bin/env php
<?php
/** Mandatory real PDO test: live index sync, fault injection, repair and search quality. */
declare(strict_types=1);

use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Repository\ThreadRepository;
use CajeerEngine\Forum\Service\ForumSearchMaintenance;
use CajeerEngine\Runtime\ConfigRepository;

$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
$driver=getenv('FORUM_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','pgsql','mysql'],true)||!in_array($driver,PDO::getAvailableDrivers(),true))
    throw new RuntimeException('Required real PDO driver: '.$driver);
if($driver!=='sqlite'){putenv('DB_DRIVER='.$driver);putenv('DB_DATABASE=forum_knowledge_sync');}
$config=new ConfigRepository($root);$config->set('database.default',$driver);$db=new DatabaseManager($config);
if($driver==='sqlite'){
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    (new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
    foreach(glob($root.'/migrations/sqlite/*.sql') as $sql)$pdo->exec((string)file_get_contents($sql));
}else{
    $pdo=$db->connection();
    $actual=(string)$pdo->query($driver==='pgsql'?'SELECT current_database()':'SELECT DATABASE()')->fetchColumn();
    if($actual!=='forum_knowledge_sync')throw new RuntimeException('Refusing non-disposable DB: '.$actual);
    (new MigrationRunner($pdo,$root.'/migrations'))->run($driver);
}
$uuid=static fn()=>\CajeerEngine\Support\Uuid::v4();
$thread=$uuid();$post=$uuid();$reply=$uuid();$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {
    if(!$ok)throw new RuntimeException('FAIL knowledge-sync: '.$label);
    ++$checks;echo 'PASS '.$label."\n";
};
$exec=static function(string $sql,array $args=[])use($pdo):void{$pdo->prepare($sql)->execute($args);};
$board=(string)$pdo->query('SELECT id FROM ce_forum_boards ORDER BY id LIMIT 1')->fetchColumn();
if($board==='')throw new RuntimeException('Missing seeded forum board');
$maintenance=new ForumSearchMaintenance($db);$repo=new ThreadRepository($db);
$search=static fn(string $term)=>$repo->search($term,0,[$board],null,'posts');
try{
    $exec('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)',[$thread,$board,'Index sync acceptance']);
    $exec('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',[$post,$thread,'Stargazing observatory telescope',1,1]);
    $check($maintenance->check()['ok'],'initial source/index parity and live triggers');
    $check(($search('stargazing')[0]['post_id']??null)===$post,'new published post indexed');
    $exec('UPDATE ce_forum_posts SET body=? WHERE id=?',['Stargazing blackhole quasar',$post]);
    $check($search('telescope')===[] && ($search('quasar')[0]['post_id']??null)===$post,'edit reindexes without old text');
    $exec('UPDATE ce_forum_posts SET deleted_at=? WHERE id=?',[time(),$post]);
    $check($search('quasar')===[],'soft-deleted source is hidden from AI search');
    $exec('UPDATE ce_forum_posts SET deleted_at=NULL WHERE id=?',[$post]);
    $check(($search('quasar')[0]['post_id']??null)===$post,'restored post searchable immediately');
    $exec('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',[$reply,$thread,'Nebula starfield singularity',2,2]);
    $check(($search('nebula')[0]['post_id']??null)===$reply,'new reply indexed automatically');
    if($driver==='sqlite'){
        $pdo->exec('DROP TRIGGER ce_forum_fts_posts_au');
        $exec('UPDATE ce_forum_posts SET body=? WHERE id=?',['Stargazing supercluster repaired',$post]);
        $health=$maintenance->check();
        $check(!$health['ok'] && in_array('ce_forum_fts_posts_au',$health['indexes']['post']['missing_triggers'],true),'lost trigger and stale source detected');
        $check($maintenance->repair()['repaired']===true,'transactional FTS and trigger repair performed');
        $check($maintenance->check()['ok'],'repair restored all keys, rows and triggers');
        $check($search('quasar')===[]&&($search('supercluster')[0]['post_id']??null)===$post,'repair removes stale terms and indexes current text');
        $exec('UPDATE ce_forum_posts SET body=? WHERE id=?',['Stargazing pulsar synced',$post]);
        $check($search('supercluster')===[]&&($search('pulsar')[0]['post_id']??null)===$post,'repaired trigger continues handling future edits');
        $pdo->exec('DROP TRIGGER ce_forum_fts_posts_au');
        $pdo->exec('CREATE TRIGGER ce_forum_fts_posts_au AFTER UPDATE OF body ON ce_forum_posts BEGIN SELECT 1; END');
        $check(in_array('ce_forum_fts_posts_au',$maintenance->check()['indexes']['post']['invalid_triggers'],true),
            'inert trigger with correct name cannot bypass index diagnostic');
        $check($maintenance->repair()['repaired']===true,'repair restores valid operational trigger body');
    }else{
        if($driver==='pgsql')$pdo->exec('DROP INDEX ce_forum_fts_posts');
        else $pdo->exec('ALTER TABLE ce_forum_posts DROP INDEX ce_forum_fts_posts');
        $check(!$maintenance->check()['indexes']['post']['ok'],'missing native FTS index detected');
        $check($maintenance->repair()['repaired']===true,'native index recreated from source');
        $check($maintenance->check()['ok'],'native index repaired and valid');
        $check(($search('quasar')[0]['post_id']??null)===$post,'native search works after rebuild');
    }
    $check($maintenance->repair()['repaired']===false,'healthy indexes not rebuilt unnecessarily');
    $exec('DELETE FROM ce_forum_posts WHERE id=?',[$reply]);
    $check($search('nebula')===[],'physically deleted reply cannot be cited');
    $check($maintenance->check()['ok'],'final source parity');
    echo "Forum knowledge sync real $driver: $checks PASS\n";
}finally{
    $exec('DELETE FROM ce_forum_threads WHERE id=?',[$thread]);
}
