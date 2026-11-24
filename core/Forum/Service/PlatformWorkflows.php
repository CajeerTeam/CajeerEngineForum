<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Support\Uuid;
use PDO;

/** Durable rule engine; no arbitrary code, external recipients or AI tool execution. */
final readonly class PlatformWorkflows
{
    public const LIMIT=30;
    public function __construct(private DatabaseManager $db) {}
    private function rows(string $sql,array $args=[]): array { $s=$this->db->connection()->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function change(string $sql,array $args=[]): int { $s=$this->db->connection()->prepare($sql);$s->execute($args);return $s->rowCount(); }
    private function lock(): void { if($this->change('UPDATE ce_platform_workflow_lock SET revision=revision+1 WHERE id=1')!==1)throw new \RuntimeException('Workflow schema missing'); }
    private function audit(string $event,?string $actor,array $context): void { (new AuditLogRepository($this->db))->record('platform.workflow.'.$event,$actor,$context); }
    private function allowed(string $id,string $scope): bool
    {
        // Locking reads avoid stale MySQL REPEATABLE READ snapshots during execution.
        $lock=$this->db->connection()->inTransaction()&&$this->db->driver()!=='sqlite'?' FOR UPDATE':'';
        $row=$this->rows('SELECT status FROM ce_users WHERE id=?'.$lock,[$id])[0]??null;
        if(($row['status']??null)!=='active')return false;
        $now=time();
        if($this->rows("SELECT id FROM ce_forum_sanctions WHERE user_id=? AND kind='suspension' AND revoked_at IS NULL AND starts_at<=? AND (ends_at IS NULL OR ends_at>?) LIMIT 1".$lock,[$id,$now,$now]))return false;
        foreach($this->rows('SELECT r.permissions FROM ce_roles r JOIN ce_user_roles ur ON ur.role_id=r.id WHERE ur.user_id=?'.$lock,[$id]) as $role){
            $scopes=json_decode((string)$role['permissions'],true);
            if(is_array($scopes)&&(in_array('*',$scopes,true)||in_array($scope,$scopes,true)))return true;
        }
        return false;
    }
    private function requireAdmin(string $id): void { if(!$this->allowed($id,'*'))throw new ForumException('Недостаточно прав.',403); }
    public static function validate(array $data): array
    {
        $keys=['id','revision','name','trigger_name','action_name','operator_id','condition_value','interval_seconds','enabled'];
        if(array_diff(array_keys($data),$keys))throw new ForumException('Неизвестное поле.',422);
        $name=$data['name']??null;$trigger=$data['trigger_name']??null;$action=$data['action_name']??null;
        $condition=$data['condition_value']??null;$interval=$data['interval_seconds']??null;$operator=$data['operator_id']??null;
        if(!is_string($name)||trim($name)===''||strlen($name)>120||!preg_match('//u',$name)||!is_bool($data['enabled']??null)
            ||!in_array($trigger,['schedule','support.requested'],true)||!in_array($action,['support.assign','analytics.aggregate','analytics.cleanup'],true)
            ||!is_int($condition)||!is_int($interval))throw new ForumException('Некорректное правило.',422);
        if($trigger==='schedule'&&($interval<60||$interval>86400))throw new ForumException('Интервал: 60–86400 секунд.',422);
        if($trigger==='support.requested'&&($action!=='support.assign'||$condition!==0||$interval!==0))throw new ForumException('Событие поддерживает только назначение без задержки.',422);
        if($action==='support.assign'){
            if(!self::validId($operator)||$condition<0||$condition>604800)throw new ForumException('Укажите оператора и возраст обращения (0–604800 секунд).',422);
        }elseif($operator!==null||$condition<0||$condition>1000||($action==='analytics.cleanup'&&$condition!==0))throw new ForumException('Некорректное условие аналитики.',422);
        $id=$data['id']??null;$revision=$data['revision']??0;
        if(($id!==null&&!self::validId($id))||!is_int($revision)||($id===null?$revision!==0:$revision<1))throw new ForumException('Некорректная ревизия.',422);
        return ['id'=>$id,'revision'=>$revision,'name'=>trim($name),'trigger_name'=>$trigger,'action_name'=>$action,'operator_id'=>$operator,'condition_value'=>$condition,'interval_seconds'=>$interval,'enabled'=>$data['enabled']];
    }
    private static function validId(mixed $id): bool {return is_string($id)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$id)===1;}
    public function overview(string $actor): array
    {
        $this->requireAdmin($actor);
        return ['rules'=>$this->rows('SELECT * FROM ce_platform_workflows ORDER BY updated_at DESC,id LIMIT 30'),
            'runs'=>$this->rows('SELECT * FROM ce_platform_workflow_runs ORDER BY created_at DESC,id DESC LIMIT 100')];
    }
    public function save(string $actor,array $data): array
    {
        $r=self::validate($data);
        return $this->db->transaction(function()use($actor,$r):array{
            $this->lock();$this->requireAdmin($actor);
            if($r['action_name']==='support.assign'&&$r['enabled']&&!$this->allowed($r['operator_id'],'forum:acp:members'))throw new ForumException('Оператор не имеет действующих полномочий.',422);
            $now=time();$id=$r['id']??Uuid::v4();$due=$r['trigger_name']==='schedule'?$now+$r['interval_seconds']:0;
            $fields=[$r['name'],$actor,$r['trigger_name'],$r['action_name'],$r['operator_id'],$r['condition_value'],$r['interval_seconds'],(int)$r['enabled'],$due,$now];
            if($r['id']===null){
                if((int)$this->db->connection()->query('SELECT COUNT(*) FROM ce_platform_workflows')->fetchColumn()>=self::LIMIT)throw new ForumException('Лимит правил.',409);
                $this->change('INSERT INTO ce_platform_workflows(name,owner_id,trigger_name,action_name,operator_id,condition_value,interval_seconds,enabled,next_due,updated_at,id,revision) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)',[...$fields,$id]);
            }elseif($this->change('UPDATE ce_platform_workflows SET name=?,owner_id=?,trigger_name=?,action_name=?,operator_id=?,condition_value=?,interval_seconds=?,enabled=?,next_due=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?',[...$fields,$id,$r['revision']])!==1)throw new ForumException('Правило изменено. Обновите страницу.',409);
            $this->audit('saved',$actor,['rule_id'=>$id,'revision'=>$r['revision']+1,'enabled'=>$r['enabled'],'action'=>$r['action_name']]);
            return $this->overview($actor);
        });
    }
    public function remove(string $actor,string $id,int $revision): array
    {
        if(!self::validId($id)||$revision<1)throw new ForumException('Некорректная ревизия.',422);
        return $this->db->transaction(function()use($actor,$id,$revision):array{
            $this->lock();$this->requireAdmin($actor);
            if($this->change('DELETE FROM ce_platform_workflows WHERE id=? AND revision=?',[$id,$revision])!==1)throw new ForumException('Правило изменено.',409);
            $this->change('DELETE FROM ce_platform_workflow_events WHERE rule_id=?',[$id]);
            $this->audit('removed',$actor,['rule_id'=>$id,'revision'=>$revision]);return $this->overview($actor);
        });
    }
    /** Capture only metadata in the same transaction as successful member support requests. */
    public function supportRequested(string $ticket): void
    {
        if(!$this->db->tableExists('ce_platform_workflows'))return;
        if(!$this->db->connection()->inTransaction())throw new \LogicException('Workflow event must be transactional');
        $ticketRevision=$this->rows('SELECT revision FROM ce_forum_support_tickets WHERE id=?',[$ticket])[0]['revision']??null;
        if($ticketRevision===null)return;
        foreach($this->rows("SELECT id,revision FROM ce_platform_workflows WHERE enabled=1 AND trigger_name='support.requested' ORDER BY id LIMIT 30") as $rule){
            $suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(rule_id,rule_revision,ticket_id,ticket_revision) DO NOTHING';
            $this->change('INSERT INTO ce_platform_workflow_events(id,rule_id,rule_revision,ticket_id,ticket_revision,created_at) VALUES(?,?,?,?,?,?)'.$suffix,[Uuid::v4(),$rule['id'],$rule['revision'],$ticket,$ticketRevision,time()]);
        }
    }
    private function candidates(array $r,?string $ticket,int $now): array
    {
        $sql="SELECT id,revision FROM ce_forum_support_tickets WHERE status='open' AND needs_human=1 AND assigned_to IS NULL AND updated_at<=?";
        $args=[$now-(int)$r['condition_value']];if($ticket!==null){$sql.=' AND id=?';$args[]=$ticket;}
        return $this->rows($sql.' ORDER BY updated_at,id LIMIT 50',$args);
    }
    private function pendingAnalytics(): int
    {return count($this->rows('SELECT id FROM ce_forum_analytics_events WHERE aggregated_at IS NULL ORDER BY created_at,id LIMIT 1000'));}
    public function preview(string $actor,array $data): array
    {
        $this->requireAdmin($actor);$r=self::validate($data);
        if($r['action_name']==='support.assign'){
            if(!$this->allowed($r['operator_id'],'forum:acp:members'))throw new ForumException('Оператор недоступен.',422);
            return ['eligible'=>count($this->candidates($r,null,time())),'limit'=>50,'note'=>'Предпросмотр очереди; событие назначает только конкретное обращение.'];
        }
        if($r['action_name']==='analytics.aggregate'){$n=$this->pendingAnalytics();return ['eligible'=>$n>=(int)$r['condition_value']?min(500,$n):0,'limit'=>500];}
        return ['eligible'=>null,'note'=>'Очистка использует неизменяемые сроки хранения аналитики; предпросмотр не удаляет данные.'];
    }
    public function pending(?int $now=null): bool
    {
        $now??=time();return $this->rows('SELECT id FROM ce_platform_workflow_events LIMIT 1')!==[]||$this->rows("SELECT id FROM ce_platform_workflows WHERE enabled=1 AND trigger_name='schedule' AND next_due<=? LIMIT 1",[$now])!==[]||$this->rows('SELECT id FROM ce_platform_workflow_runs WHERE created_at<? LIMIT 1',[$now-90*86400])!==[];
    }
    private function execute(array $r,?string $ticket,int $now): array
    {
        // Role changes serialize on these same user rows. Always lock in stable order.
        if($this->db->driver()!=='sqlite'){
            $ids=array_unique(array_filter([$r['owner_id'],$r['operator_id']]));sort($ids);
            foreach($ids as $id)$this->rows('SELECT id FROM ce_users WHERE id=? FOR UPDATE',[$id]);
        }
        if(!$this->allowed($r['owner_id'],'*'))return ['revoked',0];
        if($r['action_name']==='support.assign'){
            if(!$this->allowed($r['operator_id'],'forum:acp:members'))return ['revoked',0];
            $count=0;foreach($this->candidates($r,$ticket,$now) as $t){
                $count+=$this->change("UPDATE ce_forum_support_tickets SET assigned_to=?,revision=revision+1 WHERE id=? AND revision=? AND status='open' AND needs_human=1 AND assigned_to IS NULL",[$r['operator_id'],$t['id'],$t['revision']]);
            }return [$count?'done':'no_match',$count];
        }
        $rollup=new ForumAnalyticsRollup($this->db);
        if($r['action_name']==='analytics.aggregate'){
            if($this->pendingAnalytics()<(int)$r['condition_value'])return ['no_match',0];
            $n=$rollup->aggregateBatch(500,$now);return [$n?'done':'no_match',$n];
        }
        if($r['action_name']==='analytics.cleanup'){$n=array_sum($rollup->cleanup($now));return [$n?'done':'no_match',$n];}
        throw new \LogicException('Unknown workflow action');
    }
    /** Each action, cursor advance, audit and history commit together. Worker crashes roll them all back. */
    public function drain(int $limit=10,?int $now=null): int
    {
        $now??=time();$done=0;$start=microtime(true);
        for($i=0;$i<max(1,min(20,$limit))&&microtime(true)-$start<3;$i++){
            $ran=$this->db->transaction(function()use($now,$i):bool{
                $this->lock();
                // Alternate due schedules and event jobs to avoid starvation.
                $event=$this->rows('SELECT * FROM ce_platform_workflow_events ORDER BY created_at,id LIMIT 1')[0]??null;
                $schedule=$this->rows("SELECT * FROM ce_platform_workflows WHERE enabled=1 AND trigger_name='schedule' AND next_due<=? ORDER BY next_due,id LIMIT 1",[$now])[0]??null;
                if($event!==null&&($schedule===null||$i%2===0)){
                    $r=$this->rows('SELECT * FROM ce_platform_workflows WHERE id=?',[$event['rule_id']])[0]??null;
                    if($r===null||(int)$r['enabled']!==1||$r['trigger_name']!=='support.requested'||(int)$r['revision']!==(int)$event['rule_revision']){
                        $this->change('DELETE FROM ce_platform_workflow_events WHERE id=?',[$event['id']]);
                        $this->change('INSERT INTO ce_platform_workflow_runs(id,rule_id,rule_revision,trigger_name,action_name,status,affected,created_at) VALUES(?,?,?,?,?,?,?,?)',[Uuid::v4(),$event['rule_id'],$event['rule_revision'],'support.requested','support.assign','stale',0,$now]);return true;
                    }
                    $ticket=$event['ticket_id'];
                }elseif($schedule!==null){$r=$schedule;$ticket=null;
                    // Coalesce missed intervals, rather than replaying an unbounded backlog after downtime.
                    $this->change('UPDATE ce_platform_workflows SET next_due=? WHERE id=?',[$now+(int)$r['interval_seconds'],$r['id']]);
                }else return false;
                try{[$status,$count]=$this->db->transaction(fn()=>$this->execute($r,$ticket,$now));}
                catch(\PDOException $e){
                    $state=(string)($e->errorInfo[0]??$e->getCode());$native=(int)($e->errorInfo[1]??0);
                    // Lost connections, transaction conflicts and worker interruptions are
                    // not acknowledged; a fresh worker can recover the durable event.
                    if(!$this->db->connection()->inTransaction()||str_starts_with($state,'08')||in_array($state,['40001','40P01','55P03','HYT00','HYT01','57014'],true)||in_array($native,[1205,1213,2006,2013],true)||($this->db->driver()==='sqlite'&&in_array($native&255,[5,6],true)))throw $e;
                    $status='failed';$count=0;
                }
                catch(\Throwable){$status='failed';$count=0;}
                if($status==='revoked')$this->change('UPDATE ce_platform_workflows SET enabled=0,revision=revision+1,updated_at=? WHERE id=?',[$now,$r['id']]);
                if($ticket!==null)$this->change('DELETE FROM ce_platform_workflow_events WHERE id=?',[$event['id']]);
                $this->change('INSERT INTO ce_platform_workflow_runs(id,rule_id,rule_revision,trigger_name,action_name,status,affected,created_at) VALUES(?,?,?,?,?,?,?,?)',[Uuid::v4(),$r['id'],$r['revision'],$r['trigger_name'],$r['action_name'],$status,$count,$now]);
                $this->audit('executed',$this->rows('SELECT id FROM ce_users WHERE id=?',[$r['owner_id']])!==[]?$r['owner_id']:null,['rule_id'=>$r['id'],'revision'=>(int)$r['revision'],'status'=>$status,'affected'=>$count]);
                return true;
            });
            if(!$ran)break;$done++;
        }
        // History is metadata-only and retained for 90 days, bounded per wakeup.
        foreach($this->rows('SELECT id FROM ce_platform_workflow_runs WHERE created_at<? ORDER BY created_at,id LIMIT 200',[$now-90*86400]) as $r)$this->change('DELETE FROM ce_platform_workflow_runs WHERE id=?',[$r['id']]);
        return $done;
    }
}
