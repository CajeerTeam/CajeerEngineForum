<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Console/NativeCli.php';
use CajeerEngine\Update\{GitHubReleaseMonitor,GitHubEndpoints,OperationLock,OperationFiles,UpdateResolver};

function syncAssert(bool $ok,string $what): void { if (!$ok) throw new RuntimeException('FAIL '.$what); echo 'PASS '.$what.PHP_EOL; }
function fixture(string $version): array {
    $assets=[]; foreach (['','.sha256','.sig'] as $suffix) $assets[]=['name'=>'cajeerengine-'.$version.'-dist.zip'.$suffix,'size'=>100,'state'=>'uploaded'];
    return ['tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>false,'published_at'=>'2026-11-24T00:00:00Z','assets'=>$assets];
}
function cleanup(string $root): void { foreach (new FilesystemIterator($root) as $f) { if ($f->isDir()&&!$f->isLink()) cleanup($f->getPathname()); else unlink($f->getPathname()); } rmdir($root); }
$root=sys_get_temp_dir().'/ce-sync-'.bin2hex(random_bytes(8)); mkdir($root,0700);
$now=time(); $clock=static function() use (&$now): int { return $now; }; $delays=[]; $sleep=static function(int $ms) use (&$delays):void { $delays[]=$ms; };
$next='<https://api.github.com/repositories/123/releases?per_page=100&page=2>; rel="next"';
$body=static fn(string $v):string=>json_encode([fixture($v)],JSON_THROW_ON_ERROR);
$requests=[]; $responses=[];
$transport=static function(string $url,array $headers) use (&$requests,&$responses):array { $requests[]=[$url,$headers]; if (!$responses) throw new LogicException('Unexpected request'); $r=array_shift($responses); if ($r instanceof Throwable) throw $r; return $r; };
$oldToken=getenv('UPDATE_GITHUB_TOKEN');$oldFile=getenv('UPDATE_GITHUB_TOKEN_FILE');putenv('UPDATE_GITHUB_TOKEN');putenv('UPDATE_GITHUB_TOKEN_FILE');
try {
    $monitor=new GitHubReleaseMonitor($root,$transport,sleep:$sleep,clock:$clock);
    $responses=[[200,$body('1.5.2'),['Link'=>$next,'ETag'=>'"page-one"']],[200,$body('1.5.3'),['etag'=>'"page-two"']]];
    $r=$monitor->fetch(); syncAssert($r['_status']==='fresh'&&count($r['releases'])===2&&$r['_page_count']===2,'complete two-page catalog');
    syncAssert($requests[1][0]===GitHubEndpoints::RELEASES_URL.'&page=2','next page rebuilt on canonical endpoint');
    $responses=[[304,'',[]],[304,'',[]]]; $now+=1; $r=$monitor->fetch();
    syncAssert($r['_status']==='not_modified'&&$requests[2][1]['If-None-Match']==='"page-one"'&&$requests[3][1]['If-None-Match']==='"page-two"','per-page ETags and retained next link');
    syncAssert($r['_checked_at']!==$r['_fetched_at'],'304 advances successful check without resetting fetched timestamp');
    $responses=[[304,'',[]],[200,$body('1.5.4'),['etag'=>'"page-two-new"']]]; $r=$monitor->fetch();
    syncAssert($r['_status']==='fresh'&&$r['releases'][1]['version']==='1.5.4','changed later page refreshes catalog');
    $responses=[[200,$body('1.5.5'),['link'=>$next]],[502,'',[]],[503,'',[]],[500,'',[]]]; $r=$monitor->fetch();
    syncAssert($r['_status']==='stale'&&$r['releases'][0]['version']==='1.5.2'&&count($delays)===2,'partial failure retains last complete catalog and retries twice');
    syncAssert($delays[1]>$delays[0]&&$r['_retry_at']!==null,'exponential retry and persistent backoff');
    $count=count($requests); $monitor2=new GitHubReleaseMonitor($root,$transport,sleep:$sleep,clock:$clock);
    syncAssert($monitor2->fetch()['_failure']==='http_error'&&count($requests)===$count,'cooldown survives monitor restart');
    $now+=31; $responses=[[200,$body('1.5.5'),['link'=>'<https://evil.example/releases?page=2>; rel="next"']]];
    $r=$monitor2->fetch(); syncAssert($r['_failure']==='invalid_response'&&count($requests)===$count+1,'untrusted next link rejected without request');
    $responses=[[200,$body('1.5.5'),['link'=>$next]],[200,$body('1.5.5'),[]]];
    syncAssert($monitor->fetch()['_failure']==='duplicate_version','cross-page duplicate version rejected');
    $responses=[[200,'{}',[]]]; syncAssert($monitor->fetch()['_failure']==='invalid_response','JSON object not accepted as empty list');
    $lock=OperationLock::openLockFile($root.'/storage/app/updates/github-release-cache.lock');flock($lock,LOCK_EX);$count=count($requests);
    try { syncAssert($monitor->fetch()['_failure']==='sync_busy'&&count($requests)===$count,'concurrent discovery does not duplicate requests'); } finally { fclose($lock); }
    $responses=[[200,'[]',[]]]; $r=$monitor->fetch(); syncAssert($r['_status']==='fresh'&&$r['releases']===[]&&$r['_page_count']===1,'removed pages and releases are discarded');
    $cold=$root.'/cold';mkdir($cold,0700);$coldMonitor=new GitHubReleaseMonitor($cold,$transport,sleep:$sleep,clock:$clock);
    $responses=[[429,'',[]]]; $r=$coldMonitor->fetch(); $count=count($requests);
    syncAssert($r['_status']==='unavailable'&&$r['_retry_at']===gmdate(DATE_ATOM,$now+60),'first-request 429 without headers persists conservative cooldown');
    syncAssert((new GitHubReleaseMonitor($cold,$transport,sleep:$sleep,clock:$clock))->fetch()['_failure']==='rate_limited'&&count($requests)===$count,'cold cooldown survives process restart');
    $now+=61;$responses=[[403,'',['x-ratelimit-remaining'=>'0','x-ratelimit-reset'=>(string)($now+900)]]];$r=$coldMonitor->fetch();
    syncAssert($r['_retry_at']===gmdate(DATE_ATOM,$now+900),'primary rate limit reset honored');
    $now+=901;$responses=[[429,'',['retry-after'=>gmdate('D, d M Y H:i:s',$now+180).' GMT']]];
    syncAssert($coldMonitor->fetch()['_retry_at']===gmdate(DATE_ATOM,$now+180),'HTTP-date Retry-After honored');
    $now+=181;$responses=[new RuntimeException('SENSITIVE_NETWORK_ERROR'),[502,'',[]],[200,$body('1.5.6'),[]]];
    syncAssert($coldMonitor->fetch()['_status']==='fresh','network error and transient HTTP failure recover within same sync');
    $secret='github_pat_test_1234567890';$tokenFile=$root.'/token';file_put_contents($tokenFile,$secret);chmod($tokenFile,0600);putenv('UPDATE_GITHUB_TOKEN_FILE='.$tokenFile);
    $responses=[[200,$body('1.5.7'),['etag'=>'"private"']]];$r=$coldMonitor->fetch();$last=end($requests);
    syncAssert(($last[1]['Authorization']??null)==='Bearer '.$secret&&!isset($last[1]['If-None-Match']),'protected token and isolated conditional cache');
    syncAssert(!str_contains(file_get_contents($cold.'/storage/app/updates/github-release-cache.json'),$secret)&&!str_contains(json_encode($r),$secret),'credential never persisted or returned');
    putenv('UPDATE_GITHUB_TOKEN_FILE');syncAssert($coldMonitor->snapshot()===null,'authenticated catalog not reused after credential removal');
    $responses=[[304,'',[]]];syncAssert($coldMonitor->fetch()['_status']==='unavailable','unsolicited 304 without cache cannot authorize');
    $responses=[];for($i=1;$i<=10;$i++)$responses[]=[200,$body('2.0.'.$i),['link'=>'<https://api.github.com/repos/'.GitHubEndpoints::REPOSITORY.'/releases?per_page=100&page='.($i+1).'>; rel="next"']];
    syncAssert($coldMonitor->fetch()['_failure']==='pagination_limit'&&!$responses,'bounded pagination fails closed rather than truncating');
    syncAssert($coldMonitor->snapshot()===null,'partial oversized catalog not published');
    $responses=[[200,$body('1.5.8'),[]]]; $resolver=new UpdateResolver([], $cold, $coldMonitor);$r=$resolver->check('1.5.1');
    syncAssert($r['github_reachable']&&$r['metadata_page_count']===1&&$r['latest']['version']==='1.5.8','production resolver consumes synchronized catalog');
    $count=count($requests);$d=$resolver->diagnostics('1.5.1');syncAssert($d['metadata_page_count']===1&&count($requests)===$count,'ACP diagnostic snapshot is network-free');
} finally { putenv($oldToken===false?'UPDATE_GITHUB_TOKEN':'UPDATE_GITHUB_TOKEN='.$oldToken);putenv($oldFile===false?'UPDATE_GITHUB_TOKEN_FILE':'UPDATE_GITHUB_TOKEN_FILE='.$oldFile);cleanup($root); }
