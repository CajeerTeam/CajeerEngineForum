<?php
/** 0.8.0 ecosystem regression: signed constraints, editor projection and actual persisted readiness. */
declare(strict_types=1);
use CajeerEngine\Extension\{ExtensionRegistry,PackageTrust};
use CajeerEngine\Update\{OperationFiles as F,ReleaseSignature};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Forum\{ForumTheme,ForumException};
use CajeerEngine\Forum\Service\{ForumThemeService,ForumCommunitySettings,ForumEcosystemCheck,ForumTransaction,ForumAdminService};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\Migration\{ForumMigrationEngine,MultiForumSource};
$root=dirname(__DIR__);require $root.'/core/Console/NativeCli.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejects(callable $f,string $name):void{try{$f();}catch(Throwable){check(true,$name);return;}throw new RuntimeException('Expected failure: '.$name);}
function removeTree(string $p):void{if(!is_dir($p))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($p);}
$tmp=sys_get_temp_dir().'/ce-ecosystem-'.bin2hex(random_bytes(8));mkdir($tmp,0700);
try{
    $presets=ForumTheme::presets();$state=ForumThemeService::validateDocument([],[]);check($state['active']===$presets['default'],'original empty theme document remains valid');
    $draft=ForumThemeService::transition($state,'draft',['design'=>$presets['ocean']]);$active=ForumThemeService::transition($draft,'activate',[]);
    check(ForumThemeService::validateDocument($active,$presets['ocean'])===$active,'editor and published theme agree');
    rejects(fn()=>ForumThemeService::validateDocument($active,$presets['sand']),'divergent published projection rejected');
    $bad=$active;$bad['library']['bad']=$presets['ocean'];rejects(fn()=>ForumThemeService::validateDocument($bad,$presets['ocean']),'corrupted library rejected');
    $site=$tmp.'/site';mkdir($site);mkdir($site.'/config');foreach(glob($root.'/config/*.php') as $f)copy($f,$site.'/config/'.basename($f));copy($root.'/VERSION',$site.'/VERSION');
    $release=F::read($root.'/release.json');$old=$release;$old['edition_version']='0.7.7';F::json($site.'/release.json',$old);
    mkdir($tmp.'/trust');ReleaseSignature::generate($tmp.'/secret',$tmp.'/trust/test.pub');putenv('EXTENSIONS_TRUST_DIR='.$tmp.'/trust');putenv('APP_ENV=testing');
    $registry=new ExtensionRegistry($site,new ConfigRepository($site));$dir=$site.'/plugins/old';mkdir($dir);
    $manifest=['name'=>'test/old','type'=>'plugin','version'=>'1.0.0','engine'=>'^1.1','forum'=>'>=0.7.4 <0.8.0','permissions'=>['forum.events']];
    F::json($dir.'/cajeer.extension.json',$manifest,0644);PackageTrust::sign($dir,$tmp.'/secret');$item=$registry->install($dir,true);
    check($registry->targetCompatibility('0.7.7')['ok']&&!$registry->targetCompatibility('0.8.0')['ok'],'signed old manifest blocks upgrade target');
    $epoch=$item['enabled_at'];sleep(1);$manifest['version']='1.0.1';$manifest['forum']='>=0.7.4 <0.9.0';F::json($dir.'/cajeer.extension.json',$manifest,0644);PackageTrust::sign($dir,$tmp.'/secret');
    $item=$registry->install($dir,true);check($item['enabled_at']===$epoch&&$registry->targetCompatibility('0.8.0')['ok'],'resigned compatible upgrade preserves original activation');
    $registry->enable('test/old');check($registry->findInstalled('test/old')['enabled_at']===$epoch,'idempotent enable retains activation');
    F::json($site.'/release.json',$release);$config=new ConfigRepository($site);$registry=new ExtensionRegistry($site,$config);check($registry->targetCompatibility('0.8.0')['ok'],'new release accepts verified compatible package');
    // Verify real delivery selection after a package update, using the retained activation time.
    $services=new CajeerEngine\Extension\Runtime\PlatformServices();$ctx=new CajeerEngine\Extension\Runtime\ExtensionContext($site,(new CajeerEngine\Extension\ManifestLoader())->load($dir),$registry->findInstalled('test/old'),new CajeerEngine\Runtime\ServiceContainer(),$config,new CajeerEngine\Runtime\EventDispatcher(),new CajeerEngine\Runtime\RuntimeLogger($site),$services);
    $seen=0;(new CajeerEngine\Forum\Extension\ForumExtension($ctx))->listen('event','forum.post.reply',function()use(&$seen){$seen++;});
    check($services->deliverForumEvent('test--old.event','forum.post.reply',['occurred_at'=>strtotime($epoch)])&&$seen===1,'pre-upgrade event remains deliverable after upgrade');
    if(!in_array('--database',$argv,true)){removeTree($tmp);exit(0);}
    putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$tmp.'/target.sqlite');$config=new ConfigRepository($site);$db=new DatabaseManager($config);$pdo=$db->connection();(new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
    $admin='e1111111-1111-4111-8111-111111111111';$pdo->exec("INSERT INTO ce_users(id,email,name,password_hash) VALUES('$admin','operator@example.test','Operator','hash')");$pdo->exec("INSERT INTO ce_roles(id,handle,name,permissions) VALUES('e2222222-2222-4222-8222-222222222222','admin','Administrator','[\"*\"]')");$pdo->exec("INSERT INTO ce_user_roles(user_id,role_id) SELECT '$admin',id FROM ce_roles WHERE handle='admin'");
    $gate=new ForumEcosystemCheck($db,$config);$tx=new ForumTransaction($db,new AuditLogRepository($db));$actor=new ForumAccessPolicy($admin,['*']);
    check($gate->check(null,true)['ok'],'actual schema, search, integrity and ecosystem pass');
    $themes=new ForumThemeService($db,$tx);$theme=$themes->snapshot();$theme=$themes->change($actor,['revision'=>$theme['revision'],'action'=>'draft','design'=>$presets['ocean']]);$theme=$themes->change($actor,['revision'=>$theme['revision'],'action'=>'activate']);
    check($gate->check()['ok']&&$themes->active()===$presets['ocean'],'published theme survives readiness check');
    $pdo->prepare('UPDATE ce_forum_theme_settings SET active_payload=? WHERE id=1')->execute([json_encode($presets['sand'])]);check(!$gate->check()['checks']['theme'],'gate detects split theme state');rejects(fn()=>$gate->assertReady(),'invalid theme blocks release readiness');
    $pdo->prepare('UPDATE ce_forum_theme_settings SET active_payload=? WHERE id=1')->execute([json_encode($presets['ocean'])]);
    $policy=$tx->settings()->snapshot();$values=$policy['values'];unset($values['paused'],$values['registration_open']);$values['name']='Stable community';$tx->settings()->save($actor,['revision'=>$policy['revision'],'values'=>$values]);check($gate->check()['checks']['community_policy'],'saved community policy is valid');
    $pdo->prepare('UPDATE ce_forum_community_settings SET payload=? WHERE id=1')->execute(['{"name":"partial"}']);check(!$gate->check()['checks']['community_policy'],'gate detects malformed stored policy');
    $pdo->prepare('UPDATE ce_forum_community_settings SET payload=? WHERE id=1')->execute([json_encode($values,JSON_THROW_ON_ERROR)]);$pdo->exec("UPDATE ce_roles SET permissions='[]' WHERE handle='forum-acp-settings'");check(!$gate->check()['checks']['delegation'],'gate detects damaged delegation');
    $pdo->prepare("UPDATE ce_roles SET permissions=? WHERE handle='forum-acp-settings'")->execute(['["forum:acp:settings"]']);
    $source=new PDO('sqlite:'.$tmp.'/source.sqlite');$source->exec(file_get_contents($root.'/tools/forum-tests/multi_forum_fixture.sql'));$engine=new ForumMigrationEngine($db,new MultiForumSource($source,'xf_','xenforo','2.3.0','stable'));$job=$engine->start('test-backup')['job']['id'];
    check(!$gate->check()['checks']['migration_idle'],'unfinished import blocks release readiness');
    rejects(fn()=>(new ForumMigrationEngine($db,new MultiForumSource($source,'xf_','xenforo','2.3.0','other')))->start('test-backup'),'second job serialized and refused');
    for($i=0;$i<100;$i++){if($engine->step(100)['job']['state']==='complete')break;}
    check($engine->progress()['job']['state']==='complete'&&$gate->check()['ok'],'real import completion restores ecosystem readiness');
    $report=(new ForumAdminService($db,$tx))->maintenance($actor,['action'=>'ecosystem_check']);check($report['ok'],'actual ACP diagnostic and audit');
    check((int)$pdo->query("SELECT COUNT(*) FROM ce_audit_log WHERE event='forum.acp.maintenance'")->fetchColumn()===1,'diagnostic recorded in audit');
}finally{removeTree($tmp);}
