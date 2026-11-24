<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

/** Best-effort, bounded instrumentation. A telemetry failure never replaces the business result. */
final class Operation
{
    public static function record(string $kind,string $name,string $outcome,int $started,?string $root=null): void
    {
        try{
            $root??=dirname(__DIR__,2);$duration=(hrtime(true)-$started)/1e9;
            (new Metrics($root))->buffered($kind,$name,$outcome,$duration);
            if(TraceExporter::enabled()&&in_array($kind,['ai','analytics','integration'],true))
                (new TraceExporter($root))->record(TraceScope::child(),$kind,$name,microtime(true)-$duration,$duration,!in_array($outcome,['failure','lost','rate_limited','rejected'],true));
        }
        catch(\Throwable){/* No credentials, payloads, exception text or business identifiers. */}
    }
    public static function measure(string $kind,string $name,callable $fn,?string $root=null,?callable $classify=null): mixed
    {
        $started=hrtime(true);$outcome='failure';
        try{$result=$fn();$outcome='success';if($classify!==null)try{$outcome=$classify($result);}catch(\Throwable){$outcome='other';}return $result;}
        catch(\CajeerEngine\AI\AiProviderException $e){$outcome=$e->httpStatus===429?'rate_limited':($e->httpStatus===422?'rejected':'failure');throw $e;}
        finally{self::record($kind,$name,$outcome,$started,$root);}
    }
}
