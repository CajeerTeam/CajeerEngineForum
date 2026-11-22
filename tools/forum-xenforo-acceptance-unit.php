<?php
/** Runtime acceptance fingerprint regression; database tests run in the companion integration suite. */
declare(strict_types=1);
require dirname(__DIR__).'/core/Forum/Migration/XenForoAcceptance.php';
use CajeerEngine\Forum\Migration\XenForoAcceptance;
$n=0;
$check=static function(bool $condition,string $message)use(&$n):void {
    if(!$condition)throw new RuntimeException('FAIL '.$message);
    ++$n;echo 'PASS '.$message."\n";
};
$rows=[
    ['kind'=>'conversations','source_id'=>92,'reason'=>'no_active_recipients'],
    ['kind'=>'posts','source_id'=>33,'reason'=>'unapproved_or_missing_topic'],
    ['kind'=>'conversation_attachments','source_id'=>41,'reason'=>'orphan_or_unimported_private_message'],
];
$hash=XenForoAcceptance::skipFingerprint($rows);
$check(strlen($hash)===64,'stable SHA-256 review digest');
$check($hash===XenForoAcceptance::skipFingerprint(array_reverse($rows)),'digest independent of database row order');
$check($hash!==XenForoAcceptance::skipFingerprint(array_slice($rows,0,2)),'a missing skipped file invalidates approval');
$changed=$rows;$changed[0]['reason']='different_reason';
$check($hash!==XenForoAcceptance::skipFingerprint($changed),'a changed skip reason invalidates approval');
$changed=$rows;$changed[0]['source_id']=93;
$check($hash!==XenForoAcceptance::skipFingerprint($changed),'a different source ID invalidates approval');
$check(XenForoAcceptance::skipFingerprint([])===hash('sha256',''),'empty skip ledger is canonical');
try{XenForoAcceptance::skipFingerprint([['kind'=>'posts','source_id'=>-1,'reason'=>'bad']]);throw new LogicException('Invalid ID was accepted');}
catch(InvalidArgumentException){$check(true,'invalid skip records are refused');}
try{XenForoAcceptance::skipFingerprint([['kind'=>'posts','source_id'=>4,'reason'=>'']]);throw new LogicException('Empty reason accepted');}
catch(InvalidArgumentException){$check(true,'unexplained skip records are refused');}
echo "XenForo production acceptance fingerprint: $n PASS\n";
