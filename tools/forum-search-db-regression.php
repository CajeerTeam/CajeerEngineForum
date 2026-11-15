<?php
/** Real native index integration; requires a disposable MySQL/MariaDB database. */
declare(strict_types=1);
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\Repository\ThreadRepository;
use CajeerEngine\Forum\Service\ForumSearchMaintenance;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\Uuid;
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';
if (getenv('FORUM_TEST_DRIVER')!=='mysql') throw new RuntimeException('Set FORUM_TEST_DRIVER=mysql and use a disposable DB');
putenv('DB_DRIVER=mysql');$db=new DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();
if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!=='forum_search') throw new RuntimeException('Expected disposable forum_search database');
$pdo->exec('ALTER DATABASE forum_search CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
(new MigrationRunner($pdo,$root.'/migrations'))->run('mysql');
function verify(bool $value,string $label):void {if (!$value) throw new RuntimeException($label);echo "PASS $label\n";}
$insert=static function(string $sql,array $args)use($pdo){$pdo->prepare($sql)->execute($args);};
$board=(string)$pdo->query('SELECT id FROM ce_forum_boards LIMIT 1')->fetchColumn();$cat=(string)$pdo->query('SELECT id FROM ce_forum_categories LIMIT 1')->fetchColumn();
$hidden=Uuid::v4();$a=Uuid::v4();$b=Uuid::v4();$p=Uuid::v4();$q=Uuid::v4();
$insert('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',[$hidden,$cat,'Private','']);
$insert('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)',[$a,$board,'Astronomy public']);
$insert('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)',[$b,$hidden,'Astronomy private']);
$insert('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',[$p,$a,'Observatory telescopes marker',1,1]);
$insert('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',[$q,$b,'Observatory secrets',1,1]);
$insert('INSERT INTO ce_forum_thread_tags(thread_id,tag) VALUES(?,?)',[$a,'astronomy']);
$repo=new ThreadRepository($db);$search=fn($term,$mode='threads',$tag=null)=>$repo->search($term,0,[$board],$tag,$mode);
verify(array_column($search('astronomy'),'id')===[$a],'public title search and ACL');
$latest=$repo->latestAfter([$board],'',null);verify(array_column($latest,'id')===[$a],'native cursor feed ACL');
$cursor=\CajeerEngine\Forum\Validation\ForumFeedCursor::decode(\CajeerEngine\Forum\Validation\ForumFeedCursor::encode($latest[0]));verify($repo->latestAfter([$board],'',$cursor)===[],'native cursor range');
verify($search('telescopes','posts')[0]['post_id']===$p,'native fulltext post and snippet');
verify($search('observatory','posts')[0]['post_id']===$p,'common term fallback and private isolation');
verify($search('','threads','astronomy')[0]['id']===$a,'exact tag search');
$advanced=fn($term,$mode='threads',$opts=[])=>$repo->search($term,0,[$board],null,$mode,$opts);
verify($advanced('"Observatory telescopes"','posts',['board_id'=>$board])[0]['post_id']===$p,'quoted phrase and board filter');
verify($advanced('observatory -telescopes','posts')===[],'literal exclusion and ACL');
verify($advanced('astr','threads',['discover'=>'suggest'])[0]['id']===$a,'native prefix discovery');
verify($advanced('astronomy unknown','threads',['discover'=>'similar'])[0]['id']===$a,'related topic OR match');
verify($advanced('','threads',['board_id'=>$board,'sort'=>'replies'])[0]['total_results']===1,'filter-only count and reply sort');

$insert('UPDATE ce_forum_posts SET body=? WHERE id=?',['Different replacement',$p]);
verify($search('telescopes','posts')===[]&&$search('replacement','posts')[0]['post_id']===$p,'transactional edit index');
$insert('UPDATE ce_forum_posts SET deleted_at=1 WHERE id=?',[$p]);verify($search('replacement','posts')===[],'soft delete');
$insert('UPDATE ce_forum_posts SET deleted_at=NULL WHERE id=?',[$p]);verify($search('replacement','posts')[0]['post_id']===$p,'restore');
$maintenance=new ForumSearchMaintenance($db);verify($maintenance->check()['ok'],'native index diagnostic');
verify($maintenance->rebuild()['ok'],'native rebuild');verify($search('replacement','posts')[0]['post_id']===$p,'search after rebuild');
$insert('DELETE FROM ce_forum_threads WHERE id=?',[$a]);verify($search('replacement','posts')===[],'cascade purge');
