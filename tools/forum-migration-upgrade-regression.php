<?php
/** Resumable extended engine acceptance against a real PDO SQLite target; mandatory in CI. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource};
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $f=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($f))require $f;
    }
});
function assertImport(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"pdo_sqlite is required for this regression\n");exit(1);
}
$tmp=sys_get_temp_dir().'/ce-extended-import-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
    $sourceDb=new PDO('sqlite:'.$tmp.'/source.sqlite');$sourceDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $sourceDb->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));$sourceDb=null;
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');
    $db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();
    (new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
    $pdo->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('existing','existing@example.test','existing','hash')");
    $open=static fn():PDO=>new PDO('sqlite:'.$tmp.'/source.sqlite');
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','extended-test');
    $engine=new ForumMigrationEngine($db,$adapter);
    $job=$engine->start('test-backup')['job']['id'];
    $initial=$engine->progress()['job']['plan'];
    assertImport($initial['pipeline_version']===2&&$initial['profiles']['count']===3&&$initial['reactions']['count']===6,'versioned frozen extended plan');
    // Exhaust base phases and the profile phase; inject a rollback into the reactions phase.
    for($i=0;$i<200;$i++){
        $progress=$engine->progress()['job'];
        if((int)$progress['phase']===12)break;
        $engine->step(10);
    }
    $progress=$engine->progress()['job'];
    assertImport((int)$progress['phase']===12,'extended reaction phase reached on real importer');
    $pdo->exec("CREATE TRIGGER injected_failure BEFORE INSERT ON ce_forum_reactions BEGIN SELECT RAISE(ABORT,'test failure'); END");
    try{$engine->step(10);throw new LogicException('Expected injected database failure');}catch(PDOException $exception){}
    $after=$engine->progress()['job'];
    assertImport((int)$after['phase']===12&&(int)$after['cursor_id']===0,'failed additional writes rollback checkpoint');
    assertImport((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_reactions')->fetchColumn()===0,'failed additional writes rollback content');
    $pdo->exec('DROP TRIGGER injected_failure');
    $source->rollBack();unset($engine,$adapter,$source);
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','extended-test');
    $engine=new ForumMigrationEngine($db,$adapter);
    assertImport($engine->matchesRecordedPlan($initial),'unchanged frozen source supports restart');
    for($i=0;$i<30;$i++){if($engine->step(10)['job']['state']==='complete')break;}
    assertImport($engine->status()['job']['state']==='complete','extended import resumes to completion');
    assertImport((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_reactions')->fetchColumn()===2,'real reactions imported once');
    assertImport((int)$pdo->query('SELECT COUNT(*) FROM ce_forum_member_profiles')->fetchColumn()===2,'real member profiles imported');
    // An in-flight job created in 1.1.0 has no pipeline_version and completes after eleven phases.
    $source->rollBack();unset($engine,$adapter,$source);
    $source=$open();$adapter=new MultiForumSource($source,'xf_','xenforo','2.3.0','old-job-test');
    $engine=new ForumMigrationEngine($db,$adapter);
    $old=$adapter->plan();$oldId=$adapter->identity();
    $category='22222222-2222-4222-8222-222222222222';
    $s=$pdo->prepare("INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,phase,cursor_id,subcursor_id,created_at,updated_at) VALUES(?,?,?,?,?,10,0,0,?,?)");
    $s->execute([$oldId,'2.3.0',$category,'old-backup',json_encode($old,JSON_THROW_ON_ERROR),time(),time()]);
    assertImport($engine->matchesRecordedPlan($old),'legacy 11-phase recorded plan remains valid');
    // Guard against accidental interpretation of 11 as an extended phase on old job.
    $phaseNames=(new ReflectionClass(ForumMigrationEngine::class))->getMethod('phaseNames');
    assertImport(count($phaseNames->invoke($engine,$old))===11&&count($phaseNames->invoke($engine,$initial))===13,'old and new checkpoint indices remain stable');
    echo "PASS migration upgrade regression\n";
}finally{
    unset($pdo,$db,$source,$adapter,$engine);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){
        $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());
    }
    rmdir($tmp);
}
