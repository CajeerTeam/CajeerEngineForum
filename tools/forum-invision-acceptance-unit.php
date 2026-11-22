<?php
/** Release-safe Invision skip fingerprint and fail-closed API regression without a database. */
declare(strict_types=1);
require dirname(__DIR__).'/core/Forum/Migration/XenForoAcceptance.php';
require dirname(__DIR__).'/core/Forum/Migration/InvisionAcceptance.php';
use CajeerEngine\Forum\Migration\InvisionAcceptance;
$passes=0;
$check=static function(bool $condition,string $label)use(&$passes):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    $passes++;echo 'PASS '.$label.PHP_EOL;
};
$rows=[
    ['kind'=>'inv_conversations','source_id'=>61,'reason'=>'inactive_or_departed_recipient'],
    ['kind'=>'inv_reactions','source_id'=>3,'reason'=>'self_reaction'],
    ['kind'=>'inv_follow','source_id'=>12,'reason'=>'anonymous_follow_not_exposed'],
];
$hash=InvisionAcceptance::skipFingerprint($rows);
$check(preg_match('/\A[a-f0-9]{64}\z/D',$hash)===1,'canonical lowercase digest');
$check(hash_equals($hash,InvisionAcceptance::skipFingerprint(array_reverse($rows))),'stable review across ledger ordering');
$check(!hash_equals($hash,InvisionAcceptance::skipFingerprint(array_slice($rows,0,2))),'omitted record invalidates approval');
$changed=$rows;$changed[0]['reason']='different_reason';
$check(!hash_equals($hash,InvisionAcceptance::skipFingerprint($changed)),'different skip reason invalidates approval');
$changed=$rows;$changed[0]['source_id']=62;
$check(!hash_equals($hash,InvisionAcceptance::skipFingerprint($changed)),'different source ID invalidates approval');
$check(InvisionAcceptance::skipFingerprint([])===hash('sha256',''),'empty skips have canonical fingerprint');
foreach([
    [['kind'=>'posts','source_id'=>-2,'reason'=>'bad']],
    [['kind'=>'','source_id'=>2,'reason'=>'bad']],
    [['kind'=>'posts','source_id'=>2,'reason'=>'']],
] as $bad){
    try{InvisionAcceptance::skipFingerprint($bad);throw new LogicException('Unsafe skip accepted');}
    catch(InvalidArgumentException){$check(true,'malformed skip rejected');}
}
$ref=new ReflectionClass(InvisionAcceptance::class);
$keys=$ref->getReflectionConstant('KEYS')->getValue();
$targets=$ref->getReflectionConstant('TARGETS')->getValue();
$check(count($keys)===14 && array_keys($keys)===array_keys($targets),'all mapped Invision v8 phases require target reconciliation');
echo "Invision acceptance unit: $passes PASS".PHP_EOL;
