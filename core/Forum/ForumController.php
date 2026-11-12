<?php

declare(strict_types=1);

namespace CajeerEngine\Forum;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Support\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ForumController
{
    public function __construct(private DatabaseManager $db, private UserRepository $users, private PasswordHasher $passwords, private string $root) {}

    public function page(): Response
    {
        return new Response((string) file_get_contents($this->root.'/public/forum-assets/index.html'), 200, ['Content-Type'=>'text/html; charset=UTF-8']);
    }
    private function query(string $sql, array $params = []): \PDOStatement
    {
        $s = $this->db->connection()->prepare($sql); $s->execute($params); return $s;
    }
    private function rows(string $sql, array $params = []): array { return $this->query($sql,$params)->fetchAll(\PDO::FETCH_ASSOC); }
    private function row(string $sql, array $params = []): ?array { return $this->query($sql,$params)->fetch(\PDO::FETCH_ASSOC) ?: null; }
    private function ok(mixed $data, int $status=200): JsonResponse { return new JsonResponse(['data'=>$data],$status); }
    private function error(string $message, int $status=422): JsonResponse { return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$message]],$status); }
    private function text(Request $r,string $key,int $max,int $min=1): string
    {
        $v=$r->attributes->get('json',[])[$key]??null;
        if(!is_string($v) || mb_strlen(trim($v))<$min || mb_strlen(trim($v))>$max || str_contains($v,"\0")) throw new \InvalidArgumentException('Некорректное поле: '.$key);
        return trim($v);
    }
    private function uid(Request $r): string { return (string)$r->attributes->get('auth')['user_id']; }
    private function moderator(Request $r): bool { return (bool) array_intersect(['*','forum:moderate'],$r->attributes->get('auth')['scopes']??[]); }
    private function id(array $p): string { $id=(string)($p['id']??''); if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id)) throw new \InvalidArgumentException('Некорректный ID.'); return $id; }
    private function offset(Request $r): int { return (max(1,min(10000,$r->query->getInt('page',1)))-1)*25; }
    public function index(): JsonResponse
    {
        return $this->ok(['categories'=>$this->rows('SELECT * FROM ce_forum_categories ORDER BY position,name'), 'boards'=>$this->rows('SELECT b.*, (SELECT COUNT(*) FROM ce_forum_threads t WHERE t.board_id=b.id) AS threads FROM ce_forum_boards b ORDER BY position,name')]);
    }
    public function board(Request $r,array $p): JsonResponse
    {
        $id=$this->id($p); $b=$this->row('SELECT * FROM ce_forum_boards WHERE id=?',[$id]); if(!$b)return $this->error('Раздел не найден.',404);
        return $this->ok(['board'=>$b,'threads'=>$this->rows('SELECT t.*,u.name AS author,(SELECT COUNT(*) FROM ce_forum_posts p WHERE p.thread_id=t.id) AS posts FROM ce_forum_threads t LEFT JOIN ce_users u ON u.id=t.author_id WHERE t.board_id=? ORDER BY pinned DESC,updated_at DESC,id LIMIT 25 OFFSET '.$this->offset($r),[$id])]);
    }
    public function thread(Request $r,array $p): JsonResponse
    {
        $id=$this->id($p); $t=$this->row('SELECT t.*,b.name AS board_name FROM ce_forum_threads t JOIN ce_forum_boards b ON b.id=t.board_id WHERE t.id=?',[$id]); if(!$t)return $this->error('Тема не найдена.',404);
        return $this->ok(['thread'=>$t,'posts'=>$this->rows('SELECT p.*,u.name AS author,(SELECT COUNT(*) FROM ce_forum_reactions a WHERE a.post_id=p.id) AS likes FROM ce_forum_posts p LEFT JOIN ce_users u ON u.id=p.author_id WHERE p.thread_id=? ORDER BY p.ordinal LIMIT 25 OFFSET '.$this->offset($r),[$id])]);
    }
    public function search(Request $r): JsonResponse
    {
        $q=trim($r->query->getString('q','')); if(mb_strlen($q)<2||mb_strlen($q)>100)return $this->error('Введите 2–100 символов.');
        $q='%'.mb_strtolower(str_replace(['!','%','_'],['!!','!%','!_'],$q)).'%';
        return $this->ok($this->rows("SELECT t.id,t.title,t.updated_at FROM ce_forum_threads t WHERE LOWER(t.title) LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM ce_forum_posts p WHERE p.thread_id=t.id AND LOWER(p.body) LIKE ? ESCAPE '!') ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET ".$this->offset($r),[$q,$q]));
    }
    public function latest(Request $r): JsonResponse
    {
        return $this->ok($this->rows('SELECT t.id,t.title,t.updated_at,t.pinned,t.locked,u.name AS author FROM ce_forum_threads t LEFT JOIN ce_users u ON u.id=t.author_id ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET '.$this->offset($r)));
    }
    public function profile(Request $r,array $p): JsonResponse
    {
        $id=$this->id($p);$u=$this->row("SELECT id,name,created_at FROM ce_users WHERE id=? AND status='active'",[$id]);if(!$u)return $this->error('Пользователь не найден.',404);
        return $this->ok(['member'=>$u,'threads'=>$this->rows('SELECT id,title,updated_at FROM ce_forum_threads WHERE author_id=? ORDER BY updated_at DESC,id LIMIT 25 OFFSET '.$this->offset($r),[$id])]);
    }
    public function register(Request $r): JsonResponse
    {
        if(!filter_var(getenv('FORUM_REGISTRATION') === false ? 'true' : getenv('FORUM_REGISTRATION'),FILTER_VALIDATE_BOOLEAN))return $this->error('Регистрация отключена.',403);
        $email=$this->text($r,'email',254);$name=$this->text($r,'name',60,2);$password=$r->attributes->get('json',[])['password']??null;
        if(!is_string($password)||strlen($password)>1024)return $this->error('Некорректный пароль.');
        try {$id=$this->users->create($email,$name,$this->passwords->hash($password),['forum-member']);} catch(\InvalidArgumentException $e){return $this->error($e->getMessage());} catch(\PDOException $e){if(in_array((string)$e->getCode(),['23000','23505'],true))return $this->error('Регистрация невозможна.',409);throw $e;}
        return $this->ok(['id'=>$id],201);
    }
    public function createThread(Request $r,array $p): JsonResponse
    {
        $board=$this->id($p);$title=$this->text($r,'title',180,3);$body=$this->text($r,'body',20000);$uid=$this->uid($r);$id=Uuid::v4();
        if(!$this->row('SELECT id FROM ce_forum_boards WHERE id=?',[$board]))return $this->error('Раздел не найден.',404);
        $this->db->transaction(function()use($board,$title,$body,$uid,$id){$this->query('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)',[$id,$board,$uid,$title]);$this->query('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal) VALUES(?,?,?,?,?)',[Uuid::v4(),$id,$uid,$body,1]);});
        return $this->ok(['id'=>$id],201);
    }
    public function reply(Request $r,array $p): JsonResponse
    {
        $id=$this->id($p);$body=$this->text($r,'body',20000);$uid=$this->uid($r);$post=Uuid::v4();
        return $this->db->transaction(function()use($id,$body,$uid,$post){
            $this->query('UPDATE ce_forum_threads SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND locked=0',[$id]);
            // MySQL reports changed rows only; lock/read separately if timestamp did not change.
            $t=$this->row('SELECT locked FROM ce_forum_threads WHERE id=?'.($this->db->driver()==='sqlite'?'':' FOR UPDATE'),[$id]);
            if(!$t)return $this->error('Тема не найдена.',404);if((int)$t['locked'])return $this->error('Тема закрыта.',409);
            $ordinal=1+(int)$this->query('SELECT MAX(ordinal) FROM ce_forum_posts WHERE thread_id=?',[$id])->fetchColumn();
            $this->query('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal) VALUES(?,?,?,?,?)',[$post,$id,$uid,$body,$ordinal]);return $this->ok(['id'=>$post],201);
        });
    }
    public function edit(Request $r,array $p): JsonResponse
    {
        $id=$this->id($p);$body=$this->text($r,'body',20000);$uid=$this->uid($r);
        $post=$this->row('SELECT p.author_id,t.locked FROM ce_forum_posts p JOIN ce_forum_threads t ON t.id=p.thread_id WHERE p.id=?',[$id]);if(!$post)return $this->error('Сообщение не найдено.',404);
        if(!$this->moderator($r)&&($post['author_id']!==$uid||(int)$post['locked']))return $this->error('Недостаточно прав.',403);
        $this->query('UPDATE ce_forum_posts SET body=?,edited_at=CURRENT_TIMESTAMP WHERE id=?',[$body,$id]);return $this->ok(['id'=>$id]);
    }
    public function reaction(Request $r,array $p): JsonResponse { return $this->toggle($r,$p,'ce_forum_reactions','post_id','ce_forum_posts'); }
    public function watch(Request $r,array $p): JsonResponse { return $this->toggle($r,$p,'ce_forum_watches','thread_id','ce_forum_threads'); }
    private function toggle(Request $r,array $p,string $table,string $column,string $target): JsonResponse
    {
        $id=$this->id($p);$uid=$this->uid($r);$enabled=$r->attributes->get('json',[])['enabled']??null;if(!is_bool($enabled))return $this->error('enabled должен быть boolean.');
        if(!$this->row("SELECT id FROM $target WHERE id=?",[$id]))return $this->error('Объект не найден.',404);
        if(!$enabled)$this->query("DELETE FROM $table WHERE $column=? AND user_id=?",[$id,$uid]);else{
            $sql= $this->db->driver()==='mysql'?"INSERT INTO $table($column,user_id) VALUES(?,?) ON DUPLICATE KEY UPDATE user_id=user_id":"INSERT INTO $table($column,user_id) VALUES(?,?) ON CONFLICT DO NOTHING";$this->query($sql,[$id,$uid]);
        }return $this->ok(['enabled'=>$enabled]);
    }
    public function watches(Request $r): JsonResponse {return $this->ok($this->rows('SELECT t.id,t.title,t.updated_at FROM ce_forum_watches w JOIN ce_forum_threads t ON t.id=w.thread_id WHERE w.user_id=? ORDER BY t.updated_at DESC,t.id LIMIT 25 OFFSET '.$this->offset($r),[$this->uid($r)]));}
    public function report(Request $r,array $p): JsonResponse
    {
        $id=$this->id($p);$reason=$this->text($r,'reason',500,3);if(!$this->row('SELECT id FROM ce_forum_posts WHERE id=?',[$id]))return $this->error('Сообщение не найдено.',404);$report=Uuid::v4();$this->query('INSERT INTO ce_forum_reports(id,post_id,user_id,reason) VALUES(?,?,?,?)',[$report,$id,$this->uid($r),$reason]);return $this->ok(['id'=>$report],201);
    }
    public function reports(Request $r): JsonResponse {return $this->ok($this->rows("SELECT r.*,p.thread_id,p.body FROM ce_forum_reports r JOIN ce_forum_posts p ON p.id=r.post_id WHERE r.status='open' ORDER BY r.created_at,r.id LIMIT 25 OFFSET ".$this->offset($r)));}
    public function resolve(Request $r,array $p): JsonResponse {$id=$this->id($p);$this->query("UPDATE ce_forum_reports SET status='resolved',resolved_by=? WHERE id=?",[$this->uid($r),$id]);return $this->ok(['id'=>$id]);}
    public function moderate(Request $r,array $p): JsonResponse
    {
        $id=$this->id($p);$action=$this->text($r,'action',20);$sql=match($action){'lock'=>'UPDATE ce_forum_threads SET locked=1 WHERE id=?','unlock'=>'UPDATE ce_forum_threads SET locked=0 WHERE id=?','pin'=>'UPDATE ce_forum_threads SET pinned=1 WHERE id=?','unpin'=>'UPDATE ce_forum_threads SET pinned=0 WHERE id=?','delete'=>'DELETE FROM ce_forum_threads WHERE id=?',default=>null};if(!$sql)return $this->error('Неизвестное действие.');if(!$this->row('SELECT id FROM ce_forum_threads WHERE id=?',[$id]))return $this->error('Тема не найдена.',404);$this->db->transaction(function()use($sql,$id,$r,$action){$this->query($sql,[$id]);(new \CajeerEngine\Database\Repository\AuditLogRepository($this->db))->record('forum.moderate',$this->uid($r),['thread_id'=>$id,'action'=>$action]);});return $this->ok(['id'=>$id]);
    }
    public function category(Request $r): JsonResponse {$id=Uuid::v4();$this->query('INSERT INTO ce_forum_categories(id,name,description) VALUES(?,?,?)',[$id,$this->text($r,'name',120),$this->text($r,'description',1000,0)]);return $this->ok(['id'=>$id],201);}
    public function createBoard(Request $r,array $p): JsonResponse {$id=$this->id($p);if(!$this->row('SELECT id FROM ce_forum_categories WHERE id=?',[$id]))return $this->error('Категория не найдена.',404);$board=Uuid::v4();$this->query('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',[$board,$id,$this->text($r,'name',120),$this->text($r,'description',1000,0)]);return $this->ok(['id'=>$board],201);}
}
