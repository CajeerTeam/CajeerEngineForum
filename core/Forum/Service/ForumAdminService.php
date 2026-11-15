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

    public function listing(ForumAccessPolicy $actor,array $q): array
    {
        return $this->tx->read(function() use($actor,$q) {
            $this->tx->permissions()->requireAdmin($actor);
            $view=V::text($q['view']??'overview','view',20);
            $page=V::offset($q['page']??1); // existing 25-row page contract
            $search=V::text($q['q']??'','q',100,0);
            $status=V::text($q['status']??'all','status',20);
            if($view==='access')return ['allowed'=>true];
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
            $this->tx->permissions()->requireAdmin($actor,true);
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
            if(in_array('*',$users->permissionsForUser($id),true))throw new ForumException('Административные аккаунты управляются отдельно.',403);
            $name=V::text($input['name']??null,'name',120);$status=V::text($input['status']??null,'status',20);
            if(!in_array($status,['active','pending','disabled'],true))throw new ForumException('Некорректный статус.');
            try{$after=$users->update($id,['name'=>$name,'status'=>$status]);}catch(\InvalidArgumentException $e){throw new ForumException($e->getMessage(),422);}
            $this->tx->audit('forum.acp.member.update',$actor->userId,['user_id'=>$id,'before'=>['name'=>$before['name'],'status'=>$before['status']],'after'=>['name'=>$name,'status'=>$status]]);
            return $after;
        });
    }
}
