<?php
/** Check actual runtime JSON validation for all support write endpoints. */
declare(strict_types=1);
require dirname(__DIR__).'/core/Api/ApiContract.php';
use CajeerEngine\Api\ApiContract;
$contract=new ApiContract(dirname(__DIR__));
$faq=['question'=>'How do I sign in?','answer'=>'Contact support if you cannot sign in.','published'=>true,'sort_order'=>1];
$fixtures=[
    'api.v1.forum.support.create'=>[['subject'=>'New request','message'=>'Literal <img src=x onerror=alert(1)>','ai_help'=>false],['subject'=>'New request'],['subject'=>'New request','message'=>'Valid message','ai_help'=>'false']],
    'api.v1.forum.support.reply'=>[['message'=>'A reply','expected_revision'=>1],['message'=>'A reply'],['message'=>'A reply','expected_revision'=>0]],
    'api.v1.forum.support.handoff'=>[['expected_revision'=>1],[],['expected_revision'=>'1']],
    'api.v1.forum.acp.support.action'=>[['action'=>'resolve','expected_revision'=>2],['action'=>'resolve'],['action'=>'delete','expected_revision'=>2]],
    'api.v1.forum.acp.support.reply'=>[['message'=>'An operator reply','expected_revision'=>1],['expected_revision'=>1],['message'=>'An operator reply','expected_revision'=>0]],
    'api.v1.forum.acp.support.faqs.create'=>[$faq,['question'=>'A question'],array_replace($faq,['published'=>1])],
    'api.v1.forum.acp.support.faqs.update'=>[$faq+['expected_revision'=>1],$faq,array_replace($faq,['sort_order'=>10001,'expected_revision'=>1])],
    'api.v1.forum.acp.support.faqs.delete'=>[['expected_revision'=>1],[],['expected_revision'=>0]],
];
foreach($fixtures as $route=>[$valid,$missing,$invalid]){
    $schema=$contract->operation($route)['requestBody']['content']['application/json']['schema']??null;
    if(!is_array($schema))throw new RuntimeException('Missing request schema: '.$route);
    if($contract->validate((object)$valid,$schema)!==[])throw new RuntimeException('Valid request rejected: '.$route);
    foreach([$missing,$invalid,$valid+['unexpected_field'=>true]] as $bad){
        if($contract->validate((object)$bad,$schema)===[])throw new RuntimeException('Invalid request accepted: '.$route);
    }
    echo 'PASS '.$route."\n";
}
echo count($fixtures)." support contracts verified (valid, missing, invalid, unknown field).\n";
