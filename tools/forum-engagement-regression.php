<?php
/** Executable 1.2.11 privacy and grouped engagement regression on real PDO SQLite in CI. */
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void{
    if(str_starts_with($name,'CajeerEngine\\')) {
        $p=$root.'/core/'.str_replace('\\','/',substr($name,strlen('CajeerEngine\\'))).'.php';
        if(is_file($p))require_once $p;
    }
});
use CajeerEngine\Forum\Service\ForumEngagementAnalytics;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
$ok=0;
$check=static function(bool $test,string $what)use(&$ok):void{
    if(!$test)throw new RuntimeException('FAIL: '.$what);
    ++$ok;
};
$service=(new ReflectionClass(ForumEngagementAnalytics::class))->newInstanceWithoutConstructor();
foreach ([0,1,6,31,365] as $days) {
    try {$service->get($days);$failed=false;}
    catch(\CajeerEngine\Forum\ForumException $e) {$failed=$e->status===422;}
    $check($failed,'invalid range '.$days);
}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    echo "Forum engagement: {$ok} validation PASS; SQL integration SKIP (pdo_sqlite not installed)\n";exit(0);
}
$config=new ConfigRepository($root);$config->set('database.default','sqlite');
$db=new DatabaseManager($config);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
(new ReflectionProperty($db,'pdo'))->setValue($db,$pdo);
$pdo->exec('CREATE TABLE ce_users(id TEXT PRIMARY KEY,created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE ce_roles(id TEXT PRIMARY KEY,handle TEXT NOT NULL)');
$pdo->exec('CREATE TABLE ce_user_roles(user_id TEXT NOT NULL,role_id TEXT NOT NULL,PRIMARY KEY(user_id,role_id))');
$pdo->exec('CREATE TABLE ce_forum_posts(id TEXT PRIMARY KEY,author_id TEXT,created_at TEXT NOT NULL,ordinal INTEGER NOT NULL,deleted_at TEXT NULL)');
$pdo->exec('CREATE VIEW ce_forum_live_posts AS SELECT * FROM ce_forum_posts WHERE deleted_at IS NULL');
$pdo->exec("INSERT INTO ce_roles VALUES('role-forum','forum-member'),('role-staff','forum-moderator'),('role-cms','cms-editor')");
$register=$pdo->prepare('INSERT INTO ce_users VALUES(?,?)');$role=$pdo->prepare('INSERT INTO ce_user_roles VALUES(?,?)');
foreach(['a'=>'2026-11-10','b'=>'2026-10-20','c'=>'2026-11-16','d'=>'2026-11-21','e'=>'2026-11-20',
    'old'=>'2026-07-01','cms'=>'2026-11-10'] as $id=>$date){
    $register->execute([$id,$date.' 10:00:00']);$role->execute([$id,$id==='cms'?'role-cms':($id==='b'?'role-staff':'role-forum')]);
}
$posts=[
 ['a','2026-11-10',1],['a','2026-11-11',2],['a','2026-11-17',3],['a','2026-11-20',4],['a','2026-11-22',5],
 ['b','2026-10-20',1],['b','2026-10-27',2],['b','2026-11-19',3],['b','2026-11-22',4],
 ['d','2026-11-22',1],['e','2026-11-20',1],['e','2026-11-21',2],
 ['old','2026-11-22',2],['cms','2026-11-22',1]
];
$insert=$pdo->prepare('INSERT INTO ce_forum_posts(id,author_id,created_at,ordinal) VALUES(?,?,?,?)');
foreach($posts as $n=>[$id,$date,$ordinal])$insert->execute(['p'.str_pad((string)$n,3,'0',STR_PAD_LEFT),$id,$date.' 14:00:00',$ordinal]);
// Deleted material is excluded by the same live-post view used by the forum UI.
$pdo->exec("INSERT INTO ce_forum_posts(id,author_id,created_at,ordinal,deleted_at) VALUES('deleted','a','2026-11-22 11:00:00',2,'2026-11-22 11:30:00')");
$report=(new ForumEngagementAnalytics($db))->get(7,strtotime('2026-11-23 12:00:00 UTC'));
$check($report['days']===7 && $report['from']==='2026-11-17','bounded window');
$check($report['summary']['contributors']===5,'unique authors including preexisting member');
$check($report['summary']['contributions']===9,'9 authored posts without CMS user');
$check($report['summary']['topics']===2 && $report['summary']['replies']===7,'opening posts not counted twice');
$check($report['summary']['returning_week_contributors']===1,'weekly returning author');
$check($report['summary']['active_member_days']===9,'distinct active member-days');
$check($report['retention']['d1']['returned']===3,'exact D1 authors');
$check($report['retention']['d7']['returned']===null && $report['retention']['d7']['eligible']===null,'small D7 cohort suppressed');
$check($report['retention']['d30']['returned']===null,'small D30 cohort suppressed');
$check($report['funnel']['eligible_registrations']===null,'small seven-day funnel suppressed');
$check($report['funnel']['first_contribution']===null && $report['funnel']['second_contribution']===null,'small funnel stages suppressed');
$cohorts=array_column($report['cohorts'],null,'date');
$check($cohorts['2026-11-21']['d7_rate']===null,'immature cohort is unavailable');
$check($cohorts['2026-10-20']['suppressed'] && $cohorts['2026-10-20']['d30_rate']===null && $cohorts['2026-10-20']['registrations']===null,'identifiable daily cohort is redacted');
$check(!str_contains(json_encode($report,JSON_THROW_ON_ERROR),'"author_id"')&&!str_contains(json_encode($report,JSON_THROW_ON_ERROR),'"user_id"'),'no personal identifiers in response');
$check($report['privacy_min_cohort']===5,'privacy threshold declared');
$check($pdo->query('SELECT COUNT(*) FROM ce_forum_posts')->fetchColumn()===count($posts)+1,'report is read-only');
// Five members registered on one day reveal an aggregate cohort, not identities.
for($i=0;$i<5;$i++){
    $id='batch'.$i;$register->execute([$id,'2026-11-10 10:00:00']);$role->execute([$id,'role-forum']);
    $insert->execute(['batch-post-'.$i.'-1',$id,'2026-11-10 11:00:00',1]);
    $insert->execute(['batch-post-'.$i.'-2',$id,'2026-11-10 12:00:00',2]);
    $insert->execute(['batch-post-'.$i.'-3',$id,'2026-11-11 10:00:00',2]);
}
$grouped=(new ForumEngagementAnalytics($db))->get(7,strtotime('2026-11-23 12:00:00 UTC'));
$cohort=array_column($grouped['cohorts'],null,'date')['2026-11-10'];
$check($cohort['registrations']===6 && !$cohort['suppressed'],'sufficient cohort reports actual registration count');
$check($cohort['first_contribution']===6 && $cohort['second_contribution']===6,'multiple same-day posts counted as sequential funnel steps');
$check($cohort['d1']===6,'grouped daily posts count each author once for D+1');
$check($grouped['summary']['topics']===7 && $grouped['summary']['replies']===17,'database pre-aggregation preserves full post totals');
$report90=(new ForumEngagementAnalytics($db))->get(90,strtotime('2026-11-23 12:00:00 UTC'));
$check(count($report90['daily'])===90 && count($report90['cohorts'])===90,'complete 90-day arrays');
echo "Forum engagement: {$ok} PASS\n";
