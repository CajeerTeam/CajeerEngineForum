<?php
/** Required PDO SQLite source compatibility regression for Invision Community 4/5. */
declare(strict_types=1);
use CajeerEngine\Forum\Migration\InvisionSourceDiscovery;
use CajeerEngine\Forum\Migration\ForumMigrationCli;

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $path=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($path))require $path;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"pdo_sqlite is required for Invision source discovery tests\n");exit(1);
}
$ok=0;
$check=static function(bool $passed,string $label)use(&$ok):void{if(!$passed)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";$ok++;};
$reject=static function(callable $fn,string $part)use($check):void{
    try{$fn();}catch(\Throwable $e){$check(str_contains($e->getMessage(),$part),'fail closed: '.$part);return;}
    throw new RuntimeException('Expected failure: '.$part);
};
$open=static function(string $change='')use($root):PDO{
    $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec(file_get_contents($root.'/tools/forum-tests/invision_fixture.sql'));
    if($change!=='')$db->exec($change);
    return $db;
};
$pdo=$open();$source=new InvisionSourceDiscovery($pdo);
$plan=$source->plan();
$check($plan['ok']===true,'4.7.24 frozen source accepted');
$check($plan['version']==='4.7.24'&&$plan['major_version']===4&&$plan['prefix']==='ips_','installed version and table prefix detected');
$check($plan['counts']['forums_posts']['count']===2&&$plan['counts']['forums_topics']['high']===20,'real bounded entity totals and high water');
$check($plan['optional_counts']['core_file_storage']['count']===1,'configured file-storage count exposed without secrets');
$check(!str_contains(json_encode($plan,JSON_THROW_ON_ERROR),'private-')&&!str_contains(json_encode($plan,JSON_THROW_ON_ERROR),'password'),'no private content, email or storage credentials disclosed');
$check(preg_match('/\A[a-f0-9]{64}\z/D',$plan['schema_sha256'])===1,'schema checksum returned');
$reject(static fn()=> $pdo->exec("INSERT INTO ips_core_groups VALUES (4,'bad')"),'readonly');
$reject(static fn()=> new InvisionSourceDiscovery($open(),'ips_','4.7.25'),'does not match');
$reject(static fn()=> new InvisionSourceDiscovery($open(),'bad-prefix!'),'Invalid Invision table prefix');
$reject(static fn()=> new InvisionSourceDiscovery($open('DROP TABLE ips_forums_posts'),'ips_'),'missing table ips_forums_posts');
$reject(static fn()=> new InvisionSourceDiscovery($open('DROP TABLE ips_forums_posts')),'prefix ambiguous or missing');
$reject(static fn()=> new InvisionSourceDiscovery($open('ALTER TABLE ips_core_members DROP COLUMN email')),'missing columns email');
$reject(static fn()=> new InvisionSourceDiscovery($open("UPDATE ips_core_applications SET app_version='3.4.0' WHERE app_directory='core'")),'Unsupported or unrecognized');
$reject(static fn()=> new InvisionSourceDiscovery($open("DELETE FROM ips_core_applications WHERE app_directory='forums'")),'Forums application is missing or duplicated');
$reject(static fn()=> InvisionSourceDiscovery::detectPrefix(['ips_core_applications','ips_core_members','ips_forums_topics','ips_forums_posts','ibf_core_applications','ibf_core_members','ibf_forums_topics','ibf_forums_posts']),'prefix ambiguous');
$pdo5=$open("UPDATE ips_core_applications SET app_version='5.0.20'; ALTER TABLE ips_forums_posts RENAME COLUMN post TO post_content");
$plan5=(new InvisionSourceDiscovery($pdo5,'ips_','5.0.20'))->plan();
$check($plan5['ok']&&$plan5['major_version']===5&&$plan5['version']==='5.0.20','Invision 5 schema profile accepted from actual 5.x application metadata');
$check($plan['schema_sha256']!==$plan5['schema_sha256'],'different source layouts have different schema digests');
$reject(static fn()=>new InvisionSourceDiscovery($open("UPDATE ips_core_applications SET app_version='5.0.20' WHERE app_directory='core'")),'major versions are inconsistent');
$pdo=$open('INSERT INTO ips_core_attachments VALUES(1,\'a.txt\',\'a.txt\')');$plan=(new InvisionSourceDiscovery($pdo))->plan();
$check(!$plan['ok']&&$plan['blockers'][0]['code']==='attachment_snapshot_required','attachment snapshot is required before import');
$check((new InvisionSourceDiscovery($open('INSERT INTO ips_core_attachments VALUES(1,\'a.txt\',\'a.txt\')')))->plan(sys_get_temp_dir())['ok'],'operator supplied snapshot clears missing-snapshot blocker');
$check((new InvisionSourceDiscovery($open('UPDATE ips_forums_posts SET topic_id=999 WHERE pid=31')))->plan()['integrity']['orphans']['posts_missing_topic']===1,'dangling post topic found');
$check(!(new InvisionSourceDiscovery($open('UPDATE ips_forums_forums SET parent_id=11 WHERE id=10')))->plan()['ok'],'forum cycle blocks source acceptance');
$reject(static fn()=>(new InvisionSourceDiscovery($open('INSERT INTO ips_forums_topics VALUES(20,11,1,\'Duplicate\',170,1)')))->plan(),'invalid or duplicate key');
$check((new InvisionSourceDiscovery($open("DELETE FROM ips_core_members WHERE member_id=2")))->plan()['warnings'][0]['code']==='deleted_post_authors','removed member posts are flagged');
// Exercise the exact production CLI with frozen disk snapshot; it must not instantiate a target database.
$tmp=tempnam(sys_get_temp_dir(),'ips-snap-');
try{
    $disk=new PDO('sqlite:'.$tmp);$disk->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $disk->exec(file_get_contents($root.'/tools/forum-tests/invision_fixture.sql'));unset($disk);
    putenv('FORUM_IMPORT_SOURCE_DSN=sqlite:'.$tmp);
    $result=ForumMigrationCli::run($root,new \CajeerEngine\Runtime\ConfigRepository($root),['cajeer','forum:import:discover','--platform=invision','--snapshot=test-4','--json']);
    $check($result['ok']&&$result['snapshot']==='test-4','production CLI returns a live source discovery report');
    $reject(static fn()=>ForumMigrationCli::run($root,new \CajeerEngine\Runtime\ConfigRepository($root),['cajeer','forum:import:discover','--platform=invision','--snapshot=x','--unknown-option=1']),'Unknown import option');
}finally{putenv('FORUM_IMPORT_SOURCE_DSN');@unlink($tmp);}
echo "Invision discovery 1.1.7: $ok PASS\n";
