<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Runtime\ConfigRepository;

/** Host-owned policy. GitHub metadata and browser requests cannot change it. */
final class UpdatePolicy
{
    private const MODES = ['legacy','disabled','notify_only','download_only','manual_approval','automatic_security','automatic_patch','automatic_minor','fully_automatic'];
    private string $mode;
    private \DateTimeZone $zone;
    private array $days;
    private ?int $start = null;
    private ?int $end = null;
    private ?int $defer = null;
    private array $blocked;
    private ?string $error = null;

    public function __construct(private readonly ConfigRepository $config)
    {
        $this->mode = $config->string('updates.policy.mode','legacy');
        $this->zone = new \DateTimeZone('UTC');
        $this->days = []; $this->blocked = [];
        try {
            if (!in_array($this->mode,self::MODES,true)) throw new \InvalidArgumentException('invalid_mode');
            $tz = $config->string('updates.policy.timezone','UTC');
            if (!in_array($tz,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)) throw new \InvalidArgumentException('invalid_timezone');
            $this->zone = new \DateTimeZone($tz);
            $days = explode(',', $config->string('updates.policy.days','1,2,3,4,5,6,7'));
            foreach ($days as $day) {
                if (!preg_match('/^[1-7]$/D',trim($day))) throw new \InvalidArgumentException('invalid_days');
                $this->days[] = (int)trim($day);
            }
            $this->days = array_values(array_unique($this->days)); sort($this->days);
            $start = $config->string('updates.policy.window_start'); $end = $config->string('updates.policy.window_end');
            if ($start !== '' || $end !== '') {
                $this->start = self::minute($start); $this->end = self::minute($end);
                if ($this->start === $this->end) throw new \InvalidArgumentException('empty_window');
            }
            $defer = $config->string('updates.policy.defer_until');
            if ($defer !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$defer,new \DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $defer) throw new \InvalidArgumentException('invalid_defer_until');
                $this->defer = $date->getTimestamp();
            }
            $blocked = $config->string('updates.policy.blocked_versions');
            if ($blocked !== '') {
                if (strlen($blocked)>8192) throw new \InvalidArgumentException('invalid_blocklist');
                foreach (explode(',',$blocked) as $v) {
                    $v=trim($v); if (strlen($v)>100) throw new \InvalidArgumentException('invalid_blocklist');
                    try { ReleasePath::compare($v,$v); } catch (\Throwable) { throw new \InvalidArgumentException('invalid_blocklist'); }
                    $this->blocked[]=$v;
                }
                $this->blocked=array_values(array_unique($this->blocked)); sort($this->blocked);
            }
        } catch (\InvalidArgumentException $e) { $this->error=$e->getMessage(); }
    }

    private static function minute(string $value): int
    {
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D',$value,$m)) throw new \InvalidArgumentException('invalid_window');
        return (int)$m[1]*60+(int)$m[2];
    }

    public function discoveryEnabled(): bool
    {
        return $this->error===null && $this->mode!=='disabled' && $this->config->bool('updates.core.auto_check_enabled',true);
    }

    public function stagingEnabled(): bool
    {
        return $this->error===null && match($this->mode) {
            'legacy' => $this->config->bool('updates.core.auto_stage_enabled'),
            'disabled','notify_only' => false,
            default => true,
        };
    }

    public function autonomousEnabled(): bool
    {
        return $this->error===null && $this->config->bool('updates.policy.auto_apply_enabled') && $this->discoveryEnabled() && in_array($this->mode,['automatic_security','automatic_patch','automatic_minor','fully_automatic'],true);
    }

    public function autonomousReason(string $from,string $to,?bool $verifiedSecurity,int $now): ?string
    {
        if (!$this->autonomousEnabled()) return 'autonomous_disabled';
        return $this->stageReason($from,$to,$verifiedSecurity) ?? $this->requestReason('apply',$to,$now);
    }

    /** Policy uses verified security classification only, never release notes or an API label. */
    public function stageReason(string $from,string $to,?bool $verifiedSecurity=null): ?string
    {
        if (!$this->stagingEnabled()) return 'staging_disabled';
        if (in_array($to,$this->blocked,true)) return 'version_blocked';
        if (ReleasePath::compare($to,$from)<=0) return 'not_newer';
        if (!ReleasePath::accepts($this->config->string('updates.core.channel','stable'),UpdatePreflight::channelOf($to))) return 'channel_blocked';
        $kind=ReleasePath::kind($from,$to);
        $stable=UpdatePreflight::channelOf($to)==='stable';
        return match($this->mode) {
            // Null means not yet downloaded: inspect the signed package before publishing.
            'automatic_security' => !$stable || $verifiedSecurity===false ? 'security_release_required' : null,
            'automatic_patch' => !$stable || $kind!=='patch' ? 'patch_release_required' : null,
            'automatic_minor' => !$stable || !in_array($kind,['patch','minor'],true) ? 'minor_or_patch_required' : null,
            default => null,
        };
    }

    /** Local wall-clock minutes, end exclusive. Overnight windows belong to the start day.
     * Evaluating actual UTC instants handles skipped/repeated DST hours without inventing times. */
    public function windowOpen(int $timestamp): bool
    {
        if ($this->error!==null || ($this->defer!==null && $timestamp<$this->defer)) return false;
        $local=(new \DateTimeImmutable('@'.$timestamp))->setTimezone($this->zone);
        $day=(int)$local->format('N'); $minute=(int)$local->format('G')*60+(int)$local->format('i');
        if ($this->start===null) return in_array($day,$this->days,true);
        if ($this->start<$this->end) return in_array($day,$this->days,true) && $minute >= $this->start && $minute < $this->end;
        return ($minute >= $this->start && in_array($day,$this->days,true)) || ($minute < $this->end && in_array($day===1?7:$day-1,$this->days,true));
    }

    public function nextWindow(int $now): ?int
    {
        if ($this->error!==null) return null;
        $base=max($now,$this->defer??$now);
        if ($this->windowOpen($base)) return $base;
        // A weekly window can be skipped by DST; two weeks cover the next occurrence.
        $first=$base-($base%60)+60;
        for ($t=$first;$t<=$base+15*86400;$t+=60) if ($this->windowOpen($t)) return $t;
        return null;
    }

    public function requestReason(string $action,?string $version,int $now,bool $execution=true): ?string
    {
        // Recovery/backup never wait for an update window.
        if (!in_array($action,['download','apply'],true)) return null;
        if ($this->error!==null) return 'invalid_policy';
        if ($this->mode==='disabled') return 'updates_disabled';
        if ($version!==null && in_array($version,$this->blocked,true)) return 'version_blocked';
        if ($action==='apply' && $execution && !$this->windowOpen($now)) return 'outside_maintenance_window';
        return null;
    }

    public function snapshot(?int $now=null): array
    {
        $now??=time();
        return ['mode'=>$this->error==='invalid_mode'?'invalid':$this->mode,'valid'=>$this->error===null,'error'=>$this->error,
            'discovery_enabled'=>$this->discoveryEnabled(),'staging_enabled'=>$this->stagingEnabled(),'autonomous_apply_enabled'=>$this->autonomousEnabled(),
            'timezone'=>$this->zone->getName(),'days'=>$this->days,
            'window_start'=>$this->start===null?null:sprintf('%02d:%02d',intdiv($this->start,60),$this->start%60),
            'window_end'=>$this->end===null?null:sprintf('%02d:%02d',intdiv($this->end,60),$this->end%60),
            'defer_until'=>$this->defer===null?null:gmdate(DATE_ATOM,$this->defer),'blocked_versions'=>$this->blocked,
            'window_open'=>$this->windowOpen($now),'next_window_at'=>$this->nextWindow($now)];
    }
}
