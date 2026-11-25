<?php
/** A missing schema must produce a real negative ecosystem report, not an unknown command. */
declare(strict_types=1);
$root=dirname(__DIR__);
$env=['PATH'=>getenv('PATH')?:'/usr/bin:/bin','APP_ENV'=>'testing','DB_DRIVER'=>'sqlite','SQLITE_DATABASE'=>':memory:','DB_SQLITE_WAL'=>'false'];
$process=proc_open([PHP_BINARY,$root.'/bin/cajeer','forum:ecosystem:check','--deep','--json'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);
if(!is_resource($process))throw new RuntimeException('Cannot start ecosystem CLI');
fclose($pipes[0]);
$output=stream_get_contents($pipes[1]);fclose($pipes[1]);
$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
$status=proc_close($process);
$report=json_decode((string)$output,true,512,JSON_THROW_ON_ERROR);
$expected=['extensions','community_policy','theme','delegation','migration_idle','schema','integrity','search'];
if($status!==1||($report['ok']??null)!==false||($report['deep']??null)!==true||($report['checks']['schema']??null)!==false||array_keys($report['checks']??[])!==$expected)throw new RuntimeException('Ecosystem CLI must return a failed deep report for an empty database');
echo 'PASS ecosystem command dispatch and fail-closed JSON ('.(is_file($root.'/vendor/autoload.php')?'Composer present':'native fallback').")\n";
