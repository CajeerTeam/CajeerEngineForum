<?php
/** Actual source -> importer -> target integration, with injected rollback and restart. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource};
use CajeerEngine\Forum\Content\ForumContentRenderer;
use CajeerEngine\Runtime\ConfigRepository;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"pdo_sqlite required for real importer integration\n");exit(1);
}
$pass=0;
$check=static function(bool $valid,string $label)use(&$pass):void{
    if(!$valid)throw new RuntimeException('FAIL '.$label);
    $pass++;echo 'PASS '.$label."\n";
};
$tmp=sys_get_temp_dir().'/xf-content-import-'.bin2hex(random_bytes(10));
if(!mkdir($tmp,0700))throw new RuntimeException('Could not create isolated fixture');
try{
    $source=new PDO('sqlite:'.$tmp.'/source.sqlite');$source->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $source->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));
    $source->prepare('UPDATE xf_post SET message=? WHERE post_id=30')->execute([
        '[B]Bold[/B] [QUOTE="Bob, post: 31, member: 2"]quoted[/QUOTE] [USER=2]Bob[/USER] ' .
        '[URL=https://archive.example/community/threads/entry.20/]Thread[/URL] [URL=/posts/31/]Later[/URL] ' .
        '[URL=javascript:alert(1)]Danger[/URL] <script>alert(1)</script>'
    ]);
    $source=null;
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');putenv('APP_ENV=testing');
    $db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();
    (new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
    $open=static fn():PDO=>new PDO('sqlite:'.$tmp.'/source.sqlite');
    $snapshot=$open();$adapter=new MultiForumSource($snapshot,'xf_','xenforo','2.3.0','content-fidelity', 'https://archive.example/community');
    $engine=new ForumMigrationEngine($db,$adapter,null,null,3);
    $job=$engine->start('test-backup')['job']['id'];$plan=$engine->progress()['job']['plan'];
    $check($plan['pipeline_version']===3 && $plan['content_links']===$plan['posts'],'v3 frozen content conversion ledger');
    for($i=0;$i<120;$i++){
        $status=$engine->progress()['job'];
        if((int)$status['phase']===13)break;
        $engine->step(20);
    }
    $status=$engine->progress()['job'];
    $check((int)$status['phase']===13,'original posts and attachments mapped before conversion');
    $first=ForumMigrationEngine::mapping($db,$job,'posts',30)['mapping']['target_id'];
    $later=ForumMigrationEngine::mapping($db,$job,'posts',31)['mapping']['target_id'];
    $thread=ForumMigrationEngine::mapping($db,$job,'topics',20)['mapping']['target_id'];
    $bob=ForumMigrationEngine::mapping($db,$job,'users',2)['mapping']['target_id'];
    $check($first!==null&&$later!==null&&$thread!==null&&$bob!==null,'forward references have valid target IDs');
    $pdo->exec("CREATE TRIGGER fail_rewrite BEFORE UPDATE OF body ON ce_forum_posts BEGIN SELECT RAISE(ABORT,'injected content failure'); END");
    try{$engine->step(20);throw new LogicException('Expected rollback');}catch(PDOException){}
    $check((int)$engine->progress()['job']['cursor_id']===0,'checkpoint rolled back after injected content failure');
    $s=$pdo->prepare('SELECT body,body_format FROM ce_forum_posts WHERE id=?');$s->execute([$first]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    $check($row['body_format']==='plain','failed conversion did not partially commit post');
    $check(!ForumMigrationEngine::mapping($db,$job,'content_links',30)['ok'],'failed conversion did not commit ID map');
    $pdo->exec('DROP TRIGGER fail_rewrite');
    $snapshot->rollBack();unset($engine,$adapter,$snapshot);
    $snapshot=$open();$adapter=new MultiForumSource($snapshot,'xf_','xenforo','2.3.0','content-fidelity','https://archive.example/community');
    $engine=new ForumMigrationEngine($db,$adapter,null,null,3);
    $check($engine->matchesRecordedPlan($plan),'same snapshot and URL origin allow safe resume');
    for($i=0;$i<25;$i++)if($engine->step(20)['job']['state']==='complete')break;
    $check($engine->status()['job']['state']==='complete','importer completed after restart');
    $s=$pdo->prepare('SELECT body,body_format FROM ce_forum_posts WHERE id=?');$s->execute([$first]);$row=$s->fetch(PDO::FETCH_ASSOC);
    $html=ForumContentRenderer::render($row['body'],$row['body_format'])['html'];
    $check($row['body_format']==='bbcode'&&str_contains($html,'<strong>Bold</strong>'),'formatted content stored and rendered');
    $check(str_contains($html,'/forum/threads/'.$thread.'?page=1#post-'.$later),'forward quote and post link rewritten');
    $check(str_contains($html,'/forum/members/'.$bob),'imported member mention resolves');
    $check(!str_contains($html,'<script>')&&!str_contains($html,'href="javascript:'),'HTML and javascript remain inert');
    $s=$pdo->prepare('SELECT body,body_format FROM ce_forum_post_revisions WHERE post_id=? AND revision_number=1');$s->execute([$first]);
    $check($s->fetch(PDO::FETCH_ASSOC)===$row,'baseline revision updated atomically');
    $check(ForumMigrationEngine::mapping($db,$job,'content_links',30)['mapping']['target_id']===$first,'conversion mapped exactly once');
    $engine->step(20);
    $check((int)$pdo->query("SELECT COUNT(*) FROM ce_forum_import_map WHERE job_id='$job' AND kind='content_links'")->fetchColumn()==4,'completed rerun does not duplicate conversion ledger');
    $check($engine->status()['job']['plan']['source_content_origin']==='https://archive.example/community','trusted source origin remains bound to job');
    echo "Real XenForo content integration: $pass PASS\n";
}finally{
    unset($pdo,$db,$snapshot,$adapter,$engine);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){
        $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    }
    rmdir($tmp);
}
