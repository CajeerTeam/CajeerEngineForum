<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Backup\DatabaseBackup;

/** Single-host systemd lifecycle. The host, not the release or HTTP request, selects units. */
final class ServiceLifecycle
{
    private \Closure $command;
    private \Closure $probe;
    private array $units;
    private string $base;
    private string $host;

    public static function configured(): ?self
    {
        $path=getenv('UPDATE_SERVICE_POLICY_FILE');
        if ($path===false || $path==='') return null;
        if (PHP_OS_FAMILY!=='Linux' || PHP_SAPI!=='cli') throw new \RuntimeException('Systemd lifecycle requires Linux CLI');
        // Every ancestor must be root-owned, non-writable by other users and not a symlink.
        if (!str_starts_with($path,'/etc/') || realpath($path)!==$path) throw new \RuntimeException('Service policy must be a canonical /etc file');
        for ($p=$path; $p!=='/'; $p=dirname($p)) {
            $st=@lstat($p);
            if (!$st || $st['uid']!==0 || ($st['mode']&0022)!==0 || is_link($p)) throw new \RuntimeException('Unsafe service policy ownership');
        }
        if (!is_file($path) || filesize($path)>16384) throw new \RuntimeException('Invalid service policy file');
        return new self(OperationFiles::read($path));
    }

    /** Command/probe adapters are injectable only in process for regression tests. */
    public function __construct(array $policy, ?callable $command=null, ?callable $probe=null)
    {
        if (($policy['schema']??null)!==1 || !is_array($policy['workers']??null) || !array_is_list($policy['workers']) || count($policy['workers'])<1 || count($policy['workers'])>32) throw new \RuntimeException('Invalid service policy');
        $fpm=$policy['php_fpm']??null;
        if (!is_string($fpm) || !preg_match('/^(?:php(?:[0-9]+\.[0-9]+)?-fpm|cajeerengine-php)\.service$/D',$fpm)) throw new \RuntimeException('Invalid PHP-FPM unit');
        foreach ($policy['workers'] as $unit) if (!is_string($unit) || !preg_match('/^cajeerengine-(?:queue|worker(?:-(?:[2-9]|[12][0-9]|3[0-2]))?)\.service$/D',$unit)) throw new \RuntimeException('Invalid worker unit');
        if (count(array_unique($policy['workers']))!==count($policy['workers'])) throw new \RuntimeException('Duplicate worker units');
        $this->units=['php_fpm'=>$fpm,'workers'=>$policy['workers'],'timer'=>'cajeerengine-scheduler.timer','scheduler'=>'cajeerengine-scheduler.service'];
        $port=$policy['probe_port']??null;
        $host=$policy['probe_host']??'localhost';
        if (!is_int($port)||$port<1||$port>65535||!is_string($host)||!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/D',$host)) throw new \RuntimeException('Invalid loopback probe configuration');
        $this->base='http://127.0.0.1:'.$port; $this->host=$host;
        $this->command=$command!==null?\Closure::fromCallable($command):self::systemctl(...);
        $this->probe=$probe!==null?\Closure::fromCallable($probe):$this->http(...);
    }

    public function identity(): array { return $this->units+['probe'=>$this->base,'host'=>$this->host]; }
    public function assertIdentity(array $identity): void { if ($identity!==$this->identity()) throw new \RuntimeException('Service policy changed during transaction; restore original host policy'); }
    private function state(string $unit): string
    {
        $raw=($this->command)(['show','--property=LoadState,ActiveState','--',$unit]);
        $values=[]; foreach (explode("\n",trim($raw)) as $line) { $parts=explode('=',$line,2); if(count($parts)===2)$values[$parts[0]]=$parts[1]; }
        if (($values['LoadState']??null)!=='loaded' || !in_array($values['ActiveState']??null,['active','activating','inactive','failed','deactivating','reloading'],true)) throw new \RuntimeException('Unavailable systemd unit: '.$unit);
        return $values['ActiveState'];
    }
    public function preflight(string $version): void
    {
        foreach ([$this->units['php_fpm'],$this->units['timer'],...$this->units['workers']] as $unit) if (!in_array($this->state($unit),['active','activating'],true)) throw new \RuntimeException('Required service not running: '.$unit);
        $this->state($this->units['scheduler']);
        $this->verifyHttp($version);
    }
    public function stop(): void
    {
        // Disable future scheduler invocations, then stop writers, then PHP-FPM.
        foreach ([$this->units['timer'],$this->units['scheduler'],...$this->units['workers'],$this->units['php_fpm']] as $unit) {
            ($this->command)(['stop','--',$unit]);
            if ($this->state($unit)!=='inactive') throw new \RuntimeException('Service did not stop: '.$unit);
        }
    }
    public function start(string $version): void
    {
        ($this->command)(['start','--',$this->units['php_fpm']]);
        if ($this->state($this->units['php_fpm'])!=='active') throw new \RuntimeException('PHP-FPM failed to start');
        // The exclusive lease and maintenance marker MUST still deny traffic.
        $this->verifyHttp($version);
        foreach ([...$this->units['workers'],$this->units['timer']] as $unit) {
            ($this->command)(['start','--',$unit]);
            if (!in_array($this->state($unit),['active','activating'],true)) throw new \RuntimeException('Service did not start: '.$unit);
        }
    }
    private function verifyHttp(string $version): void
    {
        // The previous 1.5.6 release has no edition field on /health/live.
        // A fresh FPM process plus the separate CLI version/schema gate covers rollback to that release.
        for ($i=0;$i<15;$i++) {
            try {
                [$code,$live]=($this->probe)('/health/live');
                [$readyCode,$ready]=($this->probe)('/health/ready');
                if ($code===200 && ($live['status']??null)==='alive' && (version_compare($version,'1.5.7','<') || ($live['edition_version']??null)===$version) && $readyCode===503 && ($ready['status']??null)==='not_ready') return;
            } catch (\Throwable) { /* bounded retries for FPM/socket startup */ }
            if ($i<14) usleep(200000);
        }
        throw new \RuntimeException('Local HTTP lifecycle gate failed (expected protected readiness and live PHP)');
    }
    private function http(string $path): array
    {
        $context=stream_context_create(['http'=>['method'=>'GET','header'=>"Host: {$this->host}\r\nConnection: close\r\n",'timeout'=>2,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,'protocol_version'=>1.1]]);
        $raw=@file_get_contents($this->base.$path,false,$context,0,8193);
        $headers=$http_response_header??[];
        if (!is_string($raw)||strlen($raw)>8192||!preg_match('~^HTTP/\S+ (\d{3})~',$headers[0]??'',$m)) throw new \RuntimeException('Local HTTP probe unavailable');
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid probe response');
        return [(int)$m[1],$data];
    }
    private static function systemctl(array $args): string
    {
        $tmp=tempnam(sys_get_temp_dir(),'ce-systemd-');
        if ($tmp===false) throw new \RuntimeException('Cannot allocate service command output');
        try {
            DatabaseBackup::run(['/usr/bin/systemctl','--system','--no-ask-password',...$args],[],$tmp,null,180);
            $out=file_get_contents($tmp,false,null,0,16385);
            if (!is_string($out)||strlen($out)>16384) throw new \RuntimeException('Invalid systemd response');
            return $out;
        } finally { unlink($tmp); }
    }
}
