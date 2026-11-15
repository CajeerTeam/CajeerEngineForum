<?php
/** Pure cursor validation regression; no Composer or database needed. */
declare(strict_types=1);
$root=dirname(__DIR__);foreach(['ForumException','Validation/ForumValidator','Validation/ForumFeedCursor'] as $f)require $root.'/core/Forum/'.$f.'.php';
use CajeerEngine\Forum\Validation\ForumFeedCursor as C;
use CajeerEngine\Forum\ForumException;
$id='22222222-2222-4222-8222-222222222222';
foreach(['2026-11-15 12:00:00','2026-11-15 12:00:00.123456+00','2026-11-15 12:00:00-07:00'] as $date){if(C::decode(C::encode(['updated_at'=>$date,'id'=>$id]))!==['at'=>$date,'id'=>$id])throw new RuntimeException('roundtrip');}
if(C::decode('')!==null)throw new RuntimeException('empty cursor');
foreach([[],null,'invalid',str_repeat('a',257),C::encode(['updated_at'=>'2026-02-30 10:00:00','id'=>$id]),C::encode(['updated_at'=>'2026-01-01 25:00:00','id'=>$id]),C::encode(['updated_at'=>'2026-01-01 12:00:00','id'=>'bad'])] as $bad){try{C::decode($bad);throw new RuntimeException('invalid accepted');}catch(ForumException $e){if($e->status!==400)throw $e;}}
echo "PASS cursor roundtrip, timezone precision and validation\n";
