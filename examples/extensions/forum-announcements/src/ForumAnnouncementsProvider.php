<?php

declare(strict_types=1);
namespace CajeerExample;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Extension\Contracts\AbstractExtensionProvider;
use CajeerEngine\Extension\Runtime\ExtensionContext;
use CajeerEngine\Forum\Extension\ForumExtension;

final class ForumAnnouncementsProvider extends AbstractExtensionProvider
{
    public function boot(ExtensionContext $c): void
    {
        $db=$c->container->get(DatabaseManager::class);$sdk=new ForumExtension($c);$p=$c->registrations();
        $read=static fn()=> $db->connection()->query('SELECT * FROM ce_ext_forum_announcements WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
        $sdk->panel('announcement','home',static function()use($read){
            $r=$read();$now=time();if(!$r||$r['title']===''||$r['body']===''||(int)$r['starts_at']>$now||((int)$r['ends_at']!==0&&(int)$r['ends_at']<=$now))return null;
            return ['title'=>$r['title'],'text'=>$r['body']];
        });
        foreach(['forum.thread.create'=>'topics','forum.post.reply'=>'replies'] as $event=>$column){
            $sdk->listen($column,$event,static function(array $data)use($db,$event,$column): void {
                // Receipt and aggregate commit together. The lock serializes concurrent receipts on all drivers.
                $db->transaction(static function(\PDO $pdo)use($data,$event,$column): void {
                    $pdo->exec('UPDATE ce_ext_forum_announcement_counts SET topics=topics WHERE id=1');
                    $q=$pdo->prepare('SELECT event_id FROM ce_ext_forum_announcement_receipts WHERE event_id=?');$q->execute([$data['event_id']]);if($q->fetchColumn()!==false)return;
                    $q=$pdo->prepare('INSERT INTO ce_ext_forum_announcement_receipts (event_id,event_name,occurred_at) VALUES (?,?,?)');$q->execute([$data['event_id'],$event,$data['occurred_at']]);
                    $pdo->exec('UPDATE ce_ext_forum_announcement_counts SET '.$column.'='.$column.'+1 WHERE id=1');
                });
            });
        }
        $p->action($c,'overview',static function()use($db,$read): array {
            $r=$read();return ['title'=>'Forum Announcements','data'=>['announcement'=>$r,'activity'=>$db->connection()->query('SELECT topics,replies FROM ce_ext_forum_announcement_counts WHERE id=1')->fetch(\PDO::FETCH_ASSOC)],'form'=>['action'=>'save','submit'=>'Сохранить объявление','refresh'=>true,'fields'=>[
                ['name'=>'revision','label'=>'Текущая ревизия','required'=>true,'type'=>'number','value'=>(int)$r['revision']],['name'=>'title','label'=>'Заголовок (пустой — скрыть)','value'=>$r['title']],['name'=>'body','label'=>'Текст объявления','type'=>'textarea','value'=>$r['body']],['name'=>'starts_at','label'=>'Начало UTC, Unix seconds (0 — сразу)','type'=>'number','value'=>(int)$r['starts_at']],['name'=>'ends_at','label'=>'Окончание UTC, Unix seconds (0 — бессрочно)','type'=>'number','value'=>(int)$r['ends_at']]]]];
        });
        $p->action($c,'save',static function(array $input)use($db,$read): array {
            $title=$input['title']??'';$body=$input['body']??'';
            if(!is_string($title)||!is_string($body)||strlen($title)>240||strlen($body)>4000||preg_match('//u',$title)!==1||preg_match('//u',$body)!==1||($title!==''&&trim($body)===''))throw new \InvalidArgumentException('Некорректное объявление');
            $number=static function($v): int {if(!is_int($v)&&(!is_string($v)||!preg_match('/^\d{1,10}$/D',$v)))throw new \InvalidArgumentException('Некорректное число');$v=(int)$v;if($v<0||$v>9999999999)throw new \InvalidArgumentException('Число вне диапазона');return $v;};
            $revision=$number($input['revision']??null);$start=$number(($input['starts_at']??'')===''?0:$input['starts_at']);$end=$number(($input['ends_at']??'')===''?0:$input['ends_at']);
            if($end!==0&&$end<=$start)throw new \InvalidArgumentException('Окончание должно быть позже начала');
            $q=$db->connection()->prepare('UPDATE ce_ext_forum_announcements SET revision=revision+1,title=?,body=?,starts_at=?,ends_at=? WHERE id=1 AND revision=?');$q->execute([trim($title),trim($body),$start,$end,$revision]);
            if($q->rowCount()!==1)throw new \InvalidArgumentException('Объявление изменилось; обновите страницу');return $read();
        },true);
        $p->page($c,'announcements','Forum Announcements','overview');
    }
}
