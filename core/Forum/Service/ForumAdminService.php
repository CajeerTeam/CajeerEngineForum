<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Forum\Repository\BoardRepository;

/** Private operator read model; writes use the existing transactional services. */
final readonly class ForumAdminService
{
    public function __construct(private DatabaseManager $db, private ForumTransaction $tx) {}

    public const DELEGATED=['forum:acp:settings','forum:acp:members','forum:acp:audit','forum:acp:maintenance'];
    private function capabilities(ForumAccessPolicy $actor): array
    {
        $current=$this->tx->permissions()->currentPolicy($actor);
        $caps=['admin'=>$current->has('*')];
        foreach(self::DELEGATED as $scope)$caps[substr($scope,10)]=$current->has($scope);
        if(!in_array(true,$caps,true))throw new ForumException('Недостаточно прав.',403);
        return $caps;
    }
    public function delegation(ForumAccessPolicy $actor,string $id,array $input): array
    {
        $scopes=$input['scopes']??null;
        if(!is_array($scopes)||!array_is_list($scopes)||count($scopes)>4)throw new ForumException('Некорректные полномочия.');
        foreach($scopes as $scope)if(!is_string($scope)||!in_array($scope,self::DELEGATED,true))throw new ForumException('Неизвестное полномочие.');
        if(count($scopes)!==count(array_unique($scopes)))throw new ForumException('Повторяющиеся полномочия.');
        return $this->tx->write(function()use($actor,$id,$scopes){
            $this->tx->permissions()->requireAdmin($actor,true);
            if($id===$actor->userId)throw new ForumException('Нельзя делегировать собственному аккаунту.',403);
            $roles=new RoleRepository($this->db);$users=new UserRepository($this->db,$roles);
            return $users->locked($id,function()use($actor,$id,$scopes,$roles,$users){
                $user=$users->findById($id)??throw new ForumException('Участник не найден.',404);
                if(in_array('*',$user['permissions'],true))throw new ForumException('Глобальные администраторы управляются отдельно.',403);
                if($scopes!==[]&&$user['status']!=='active')throw new ForumException('Назначение возможно только активному участнику.',409);
                $managed=[];foreach(self::DELEGATED as $scope){$handle='forum-acp-'.substr($scope,10);$role=$roles->find($handle);if($role===null||$role['permissions']!==[$scope])throw new ForumException('Системная роль ACP повреждена.',409);$managed[$scope]=$handle;}
                $before=array_values(array_intersect($user['roles'],array_values($managed)));
                $after=array_values(array_map(fn($scope)=>$managed[$scope],$scopes));
                // Only these four roles are changed; no replacement of other roles/groups.
                $wanted=array_merge(array_values(array_diff($user['roles'],array_values($managed))),$after);
                try{$users->syncRoles($id,$wanted);}catch(\InvalidArgumentException $e){throw new ForumException($e->getMessage(),422);}
                $this->tx->audit('forum.acp.delegation.update',$actor->userId,['user_id'=>$id,'before'=>$before,'after'=>$after]);
                return ['user_id'=>$id,'scopes'=>$scopes];
            });
        });
    }
    public function theme(ForumAccessPolicy $actor,array $input): array
    { return (new ForumThemeService($this->db,$this->tx))->change($actor,$input); }
    public function policy(ForumAccessPolicy $actor,array $input): array
    { return $this->tx->settings()->save($actor,$input); }
    public function maintenance(ForumAccessPolicy $actor,array $input): array
    {
        $action=$input['action']??null;
        return $this->tx->write(function()use($actor,$action){
            $this->tx->permissions()->requireCurrent($actor,'forum:acp:maintenance',true);
            $result=match($action){
                'cleanup_spam'=>['removed'=>$this->tx->antiSpam()->cleanup()],
                'cleanup_drafts'=>['removed'=>$this->tx->drafts()->cleanup()],
                'cleanup_uploads'=>['removed'=>$this->tx->attachments()->cleanup()],
                'search_check'=>(new ForumSearchMaintenance($this->db))->check(),
                'performance_check'=>(new ForumPerformanceCheck($this->db))->check(),
                'redis_check'=>ForumRuntimeCache::diagnostics(),
                'ecosystem_check'=>(new ForumEcosystemCheck($this->db,$this->db->config()))->check(),
                default=>throw new ForumException('Неизвестная операция обслуживания.',422),
            };
            // No arbitrary SQL, system commands or operator secrets in audit.
            $this->tx->audit('forum.acp.maintenance',$actor->userId,['action'=>$action,'ok'=>$result['ok']??true,'removed'=>$result['removed']??null]);
            return $result;
        });
    }
    public function listing(ForumAccessPolicy $actor,array $q): array
    {
        return $this->tx->read(function() use($actor,$q) {
            $caps=$this->capabilities($actor);
            $view=V::text($q['view']??'overview','view',20);
            $page=V::offset($q['page']??1); // existing 25-row page contract
            $search=V::text($q['q']??'','q',100,0);
            $status=V::text($q['status']??'all','status',20);
            if($view==='access')return ['allowed'=>true,'capabilities'=>$caps];
            $permission=match($view){'settings','themes'=>'settings','users'=>'members','audit','policy_history'=>'audit',default=>'admin'};
            if(!$caps['admin']&&!$caps[$permission])throw new ForumException('Недостаточно прав.',403);
            if($view==='delegation'){
                $id=V::id($q['id']??null);$user=(new UserRepository($this->db,new RoleRepository($this->db)))->findById($id)??throw new ForumException('Участник не найден.',404);
                return ['user_id'=>$id,'scopes'=>array_values(array_filter(self::DELEGATED,fn($scope)=>in_array('forum-acp-'.substr($scope,10),$user['roles'],true)))];
            }
            if($view==='imports') {
                $rows=$this->db->connection()->query('SELECT id,source_version,category_id,backup_id,phase,cursor_id,state,created_at,updated_at,plan_json FROM ce_forum_import_jobs ORDER BY created_at DESC,id ASC LIMIT 26 OFFSET '.(int)$page)->fetchAll(\PDO::FETCH_ASSOC);
                $more=count($rows)>25;if($more)array_pop($rows);
                foreach($rows as &$row){$plan=json_decode($row['plan_json'],true,512,JSON_THROW_ON_ERROR);$row['platform']=$plan['source_platform']??'phpbb';unset($row['plan_json']);}unset($row);
                return ['items'=>$rows,'page'=>intdiv($page,25)+1,'has_more'=>$more];
            }
            if($view==='import_job') {
                $id=$q['job']??'';if(!is_string($id)||!preg_match('/\A[a-f0-9]{64}\z/D',$id))throw new ForumException('Некорректный ID импорта.',422);
                $stmt=$this->db->connection()->prepare('SELECT id FROM ce_forum_import_jobs WHERE id=?');$stmt->execute([$id]);if(!$stmt->fetchColumn())throw new ForumException('Импорт не найден.',404);
                return \CajeerEngine\Forum\Migration\ForumMigrationEngine::report($this->db,$id);
            }
            if($view==='themes')return (new ForumThemeService($this->db,$this->tx))->editor($actor);
            if($view==='settings')return $this->tx->settings()->snapshot();
            if($view==='policy_history') {
                $stmt=$this->db->connection()->query("SELECT id,event,actor_id,context,created_at FROM ce_audit_log WHERE event IN ('forum.acp.policy.update','forum.acp.maintenance','forum.acp.delegation.update','forum.acp.theme.draft','forum.acp.theme.activate','forum.acp.theme.restore','forum.acp.theme.delete') ORDER BY id DESC LIMIT 26 OFFSET ".(int)$page);
                $rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);$more=count($rows)>25;if($more)array_pop($rows);
                foreach($rows as &$row)$row['context']=json_decode((string)$row['context'],true,512,JSON_THROW_ON_ERROR);unset($row);
                return ['items'=>$rows,'page'=>intdiv($page,25)+1,'has_more'=>$more];
            }
            // Delegated audit is limited to policy/maintenance history, not moderation metadata.
            if($view==='audit'&&!$caps['admin'])throw new ForumException('Недостаточно прав.',403);
            if($view==='overview') {
                $counts=[];
                foreach(['users'=>'ce_users','active_users'=>"ce_users WHERE status='active'",'boards'=>'ce_forum_boards','threads'=>'ce_forum_live_threads','posts'=>'ce_forum_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.deleted_at IS NULL','reports'=>"ce_forum_reports WHERE status='open'",'pending'=>"ce_forum_review_submissions WHERE status='pending'",'attachments'=>'ce_forum_attachments'] as $key=>$source) $counts[$key]=(int)$this->db->connection()->query('SELECT COUNT(*) FROM '.$source)->fetchColumn();
                return ['counts'=>$counts];
            }
            if($view==='structure') {
                $boards=new BoardRepository($this->db);
                return ['categories'=>$boards->categories(),'boards'=>$boards->topology()];
            }
            if($view==='post') {
                $id=V::id($q['id']??null);
                $stmt=$this->db->connection()->prepare('SELECT p.id,p.thread_id,p.body,p.body_format,p.revision_number FROM ce_forum_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id WHERE p.id=? AND p.deleted_at IS NULL');
                $stmt->execute([$id]);$post=$stmt->fetch(\PDO::FETCH_ASSOC);
                if(!$post)throw new ForumException('Сообщение не найдено.',404);
                $post['revision_number']=(int)$post['revision_number'];
                return ['post'=>$post];
            }
            [$sql,$params]=self::pageQuery($view,$q,$search,$status,$page);
            $pdo=$this->db->connection();$stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);
            $more=count($rows)>25;if($more)array_pop($rows);
            return ['items'=>$rows,'page'=>intdiv($page,25)+1,'has_more'=>$more];
        });
    }

    /** Build only whitelisted list queries. Exposed for database regression tests. */
    public static function pageQuery(string $view,array $q,string $search,string $status,int $page): array
    {
        if($page<0||$page>249975||$page%25!==0)throw new ForumException('Некорректная страница.',400);
        $params=[];$where=[];
        // Escape LIKE metacharacters; input is never SQL syntax.
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($search)).'%';
        switch($view) {
            case 'users':
                $select='u.id,u.name,u.email,u.status,u.created_at,u.email_verified_at';$from='ce_users u';$order='u.created_at DESC,u.id ASC';
                if($search!=='') {$where[]="(LOWER(u.name) LIKE ? ESCAPE '!' OR LOWER(u.email) LIKE ? ESCAPE '!')";$params=[$pattern,$pattern];}
                if($status!=='all'){if(!in_array($status,['active','pending','disabled'],true))throw new ForumException('Некорректный статус.');$where[]='u.status=?';$params[]=$status;}break;
            case 'threads':
                $select='t.id,t.title,t.board_id,t.author_id,t.pinned,t.locked,t.prefix,t.updated_at,b.name AS board_name';$from='ce_forum_live_threads t JOIN ce_forum_boards b ON b.id=t.board_id';$order='t.updated_at DESC,t.id ASC';
                if($search!==''){$where[]="LOWER(t.title) LIKE ? ESCAPE '!'";$params[]=$pattern;}
                if(isset($q['board_id'])&&$q['board_id']!==''){$where[]='t.board_id=?';$params[]=V::id($q['board_id']);}break;
            case 'posts':
                $select='p.id,p.thread_id,p.author_id,p.ordinal,p.created_at,t.title,SUBSTR(p.body,1,500) AS excerpt';$from='ce_forum_posts p JOIN ce_forum_live_threads t ON t.id=p.thread_id';$order='p.created_at DESC,p.id ASC';$where[]='p.deleted_at IS NULL';
                if($search!==''){$where[]="LOWER(t.title) LIKE ? ESCAPE '!'";$params[]=$pattern;}
                if(isset($q['thread_id'])&&$q['thread_id']!==''){$where[]='p.thread_id=?';$params[]=V::id($q['thread_id']);}break;
            case 'attachments':
                // No file bytes, private message content, storage paths or credentials.
                $select='a.id,a.owner_id,a.filename,a.mime,a.size_bytes,a.created_at,a.post_id';$from='ce_forum_attachments a';$order='a.created_at DESC,a.id ASC';
                $where[]='a.post_id IS NOT NULL';
                if($search!==''){$where[]="LOWER(a.filename) LIKE ? ESCAPE '!'";$params[]=$pattern;}break;
            case 'audit':
                $select='id,event,actor_id,created_at';$from='ce_audit_log';$order='id DESC';$where[]="event LIKE 'forum.%'";
                if($search!==''){$where[]="LOWER(event) LIKE ? ESCAPE '!'";$params[]=$pattern;}break;
            default: throw new ForumException('Неизвестный раздел ACP.',422);
        }
        $sql=$from.($where?' WHERE '.implode(' AND ',$where):'');
        return ['SELECT '.$select.' FROM '.$sql.' ORDER BY '.$order.' LIMIT 26 OFFSET '.(int)$page,$params];
    }

    public function updateMember(ForumAccessPolicy $actor,string $id,array $input): array
    {
        return $this->tx->write(function()use($actor,$id,$input){
            $this->tx->permissions()->requireCurrent($actor,'forum:acp:members',true);
            if($id===$actor->userId)throw new ForumException('Собственный аккаунт изменяется в личном кабинете.',403);
            $users=new UserRepository($this->db,new RoleRepository($this->db));
            // Lock the target before inspecting permissions; never disable an operator via ACP.
            $pdo=$this->db->connection();
            if($this->db->driver()==='sqlite'){$s=$pdo->prepare('UPDATE ce_users SET id=id WHERE id=?');$s->execute([$id]);}
            $s=$pdo->prepare('SELECT id FROM ce_users WHERE id=?'.($this->db->driver()==='sqlite'?'':' FOR UPDATE'));$s->execute([$id]);
            if(!$s->fetchColumn())throw new ForumException('Участник не найден.',404);
            $s->closeCursor();
            $before=$users->findById($id);
            if($before===null)throw new ForumException('Участник не найден.',404);
            
            $global=$this->tx->permissions()->currentPolicy($actor)->has('*');$targetPermissions=$users->permissionsForUser($id);
            if(in_array('*',$targetPermissions,true)||(!$global&&array_diff($targetPermissions,['forum:write'])!==[]))throw new ForumException('Административные аккаунты управляются отдельно.',403);
            $name=V::text($input['name']??null,'name',120);$status=V::text($input['status']??null,'status',20);
            if(!in_array($status,['active','pending','disabled'],true))throw new ForumException('Некорректный статус.');
            if(!$global&&$status!==$before['status']&&($status==='pending'||$before['status']==='pending'||($status==='active'&&RegistrationService::verificationRequired()&&empty($before['email_verified_at']))))throw new ForumException('Изменение статуса подтверждения доступно только глобальному администратору.',403);
            try{$after=$users->update($id,['name'=>$name,'status'=>$status]);}catch(\InvalidArgumentException $e){throw new ForumException($e->getMessage(),422);}
            $this->tx->audit('forum.acp.member.update',$actor->userId,['user_id'=>$id,'before'=>['name'=>$before['name'],'status'=>$before['status']],'after'=>['name'=>$name,'status'=>$status]]);
            return $after;
        });
    }
}
