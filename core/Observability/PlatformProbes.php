<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use PDO;

/** Passive diagnostics: no provider calls, credentials, connection IDs or user data in results. */
final readonly class PlatformProbes
{
    public function __construct(private string $root,private ConfigRepository $config,private DatabaseManager $db) {}

    public function ai(): array
    {
        // Atomic settings publication permits a read without taking a potentially busy writer lock.
        $settings=(new JsonFile($this->root.'/storage/app/ai/settings.json'))->readObject();
        $configured=!empty($settings['key_ciphertext'])||trim((string)\CajeerEngine\Security\SecretEnvironment::get('OPENAI_API_KEY'))!=='';
        unset($settings);
        $transport=function_exists('curl_init');
        return ['ok'=>!$configured||$transport,'configured'=>$configured,'transport_available'=>$transport,'provider_probe'=>'passive'];
    }

    public function aiAdmission(): array
    {
        return $this->db->readSnapshot(function(PDO $pdo){
            if($this->db->driver()==='pgsql'){$pdo->exec("SET LOCAL statement_timeout = '1500ms'");$pdo->exec("SET LOCAL lock_timeout = '500ms'");}
            $now=time();$q=$pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN active=1 AND expires_at>? THEN 1 ELSE 0 END) AS active_count, SUM(CASE WHEN active=0 AND expires_at>? THEN 1 ELSE 0 END) AS queued_count, SUM(CASE WHEN expires_at<=? THEN 1 ELSE 0 END) AS expired_count FROM ce_forum_ai_admission');$q->execute([$now,$now,$now]);$r=$q->fetch(PDO::FETCH_ASSOC);
            $cooldown=$pdo->query('SELECT cooldown_until FROM ce_forum_ai_admission_state WHERE id=1')->fetchColumn();if($cooldown===false)throw new \RuntimeException('AI admission state missing');
            return ['ok'=>(int)$r['expired_count']===0,'active'=>(int)$r['active_count'],'queued'=>(int)$r['queued_count'],'expired'=>(int)$r['expired_count'],'cooldown_seconds'=>max(0,(int)$cooldown-$now)];
        });
    }

    public function analytics(): array
    {
        $enabled=$this->config->bool('integrations.enabled',true)&&$this->config->bool('analytics.enabled',true);
        return $this->db->readSnapshot(function(PDO $pdo)use($enabled){
            if($this->db->driver()==='pgsql'){$pdo->exec("SET LOCAL statement_timeout = '1500ms'");$pdo->exec("SET LOCAL lock_timeout = '500ms'");}
            $count=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM ce_forum_analytics_events WHERE aggregated_at IS NULL LIMIT 10001) AS bounded')->fetchColumn();
            $oldest=$pdo->query('SELECT created_at FROM ce_forum_analytics_events WHERE aggregated_at IS NULL ORDER BY created_at LIMIT 1')->fetchColumn();
            $age=$oldest===false||$oldest===null?0:max(0,time()-(int)$oldest);
            $r=$pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN enabled=1 THEN 1 ELSE 0 END) AS enabled_count, SUM(CASE WHEN enabled=1 AND last_error IS NOT NULL THEN 1 ELSE 0 END) AS failed_count FROM analytics_connections")->fetch(PDO::FETCH_ASSOC);
            $failed=(int)$r['failed_count'];
            return ['ok'=>$age<=max(60,$this->config->int('observability.queue_max_age',300))&&(!$enabled||$failed===0),'native_pending'=>$count,'native_pending_capped'=>$count>=10001,'native_oldest_age_seconds'=>$age,'external_enabled'=>$enabled,'external_connections'=>(int)$r['total'],'external_active'=>(int)$r['enabled_count'],'external_failed'=>$failed];
        });
    }

    public function integrations(): array
    {
        $enabled=$this->config->bool('integrations.enabled',true);
        $state=(new JsonFile($this->root.'/storage/app/integrations/registry.json'))->readObject(['version'=>1,'items'=>[]]);
        if(($state['version']??null)!==1||!is_array($state['items']??null))throw new \RuntimeException('Invalid registry');
        $active=0;$failed=0;
        foreach($state['items'] as $item){if(!($item['enabled']??false))continue;$active++;if(($item['last_run']['ok']??null)===false)$failed++;}
        return ['ok'=>!$enabled||$failed===0,'enabled'=>$enabled,'active'=>$active,'last_run_failed'=>$failed,'provider_probe'=>'passive'];
    }

    public function redis(): array
    {
        $required=getenv('RATE_LIMIT_DRIVER')==='redis'||$this->config->string('queue.default')==='redis';
        $enabled=$required||$this->config->string('cache.default')==='redis'||\CajeerEngine\Forum\Service\ForumRuntimeCache::enabled();
        if(!$enabled)return ['ok'=>true,'enabled'=>false,'required'=>false];
        $started=hrtime(true);$r=null;$ok=false;
        try{$r=\CajeerEngine\Cache\RedisConnection::open($this->root);$ok=(bool)$r->ping();return ['ok'=>$ok,'enabled'=>true,'required'=>$required];}
        catch(\Throwable){return ['ok'=>false,'enabled'=>true,'required'=>$required];}
        finally{if($r!==null)try{$r->close();}catch(\Throwable){} Operation::record('redis','ping',$ok?'success':'failure',$started,$this->root);}
    }
}
