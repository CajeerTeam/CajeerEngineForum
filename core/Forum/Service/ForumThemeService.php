<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\{ForumException,ForumTheme};
use CajeerEngine\Forum\Security\ForumAccessPolicy;

/** One versioned document; draft edits never mutate the published design. */
final readonly class ForumThemeService
{
    public function __construct(private DatabaseManager $db,private ?ForumTransaction $tx=null) {}
    public function snapshot(): array
    {
        $row=$this->db->connection()->query('SELECT revision,payload,active_payload FROM ce_forum_theme_settings WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
        if(!$row)throw new \LogicException('Forum theme settings missing; run migrations');
        return ['revision'=>(int)$row['revision']]+self::validateDocument(
            json_decode((string)$row['payload'],true,512,JSON_THROW_ON_ERROR),
            json_decode((string)$row['active_payload'],true,512,JSON_THROW_ON_ERROR));
    }
    /** Validate the entire persisted editor document, including the published projection. */
    public static function validateDocument(array $document,array $published): array
    {
        $base=ForumTheme::presets()['default'];
        if(array_diff(array_keys($document),['active','draft','library','history']))throw new \RuntimeException('Unknown theme document fields');
        $state=array_replace(['active'=>$base,'draft'=>$base,'library'=>[],'history'=>[]],$document);
        $state['active']=ForumTheme::validate($state['active']);$state['draft']=ForumTheme::validate($state['draft']);
        if($state['active']!==ForumTheme::validate($published?:$base))throw new \RuntimeException('Published theme and editor state diverged');
        if(!is_array($state['library'])||count($state['library'])>12||!is_array($state['history'])||!array_is_list($state['history'])||count($state['history'])>10)throw new \RuntimeException('Invalid theme library or history');
        foreach($state['library'] as $key=>$design){$design=ForumTheme::validate($design);if($key!==hash('sha256',$design['name']))throw new \RuntimeException('Invalid theme library key');$state['library'][$key]=$design;}
        foreach($state['history'] as $i=>$entry){if(!is_array($entry)||!is_string($entry['at']??null)||!is_array($entry['design']??null))throw new \RuntimeException('Invalid theme history');$state['history'][$i]['design']=ForumTheme::validate($entry['design']);}
        return $state;
    }
    public function active(): array
    {
        $raw=$this->db->connection()->query('SELECT active_payload FROM ce_forum_theme_settings WHERE id=1')->fetchColumn();
        if($raw===false)throw new \LogicException('Forum theme settings missing; run migrations');
        $design=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($design))throw new \RuntimeException('Invalid published theme document');
        return ForumTheme::validate($design===[]?ForumTheme::presets()['default']:$design);
    }
    public function editor(ForumAccessPolicy $actor): array
    {
        $tx=$this->tx??throw new \LogicException('Missing transaction');
        $tx->permissions()->requireCurrent($actor,'forum:acp:settings');
        return $this->snapshot()+['presets'=>ForumTheme::presets()];
    }
    public function change(ForumAccessPolicy $actor,array $input): array
    {
        $revision=$input['revision']??null;$action=$input['action']??null;
        if(!is_int($revision)||$revision<0||!in_array($action,['draft','activate','restore','delete'],true))throw new ForumException('Некорректная операция темы.',422);
        $allowed=['revision','action'];if($action==='draft')$allowed[]='design';if($action==='delete')$allowed[]='key';if($action==='restore')$allowed[]='index';
        if(array_diff(array_keys($input),$allowed))throw new ForumException('Неизвестное поле операции темы.',422);
        $tx=$this->tx??throw new \LogicException('Missing transaction');
        return $tx->write(function()use($actor,$revision,$action,$input,$tx){
            $tx->permissions()->requireCurrent($actor,'forum:acp:settings',true);
            $before=$this->snapshot();if($before['revision']!==$revision)throw new ForumException('Тема изменена. Обновите страницу.',409);
            $next=self::transition($before,$action,$input);
            $s=$this->db->connection()->prepare('UPDATE ce_forum_theme_settings SET revision=revision+1,payload=?,active_payload=? WHERE id=1 AND revision=?');
            $s->execute([json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($next['active'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$revision]);if($s->rowCount()!==1)throw new ForumException('Тема изменена. Обновите страницу.',409);
            $tx->audit('forum.acp.theme.'.$action,$actor->userId,['before'=>['revision'=>$revision,'active'=>$before['active'],'draft'=>$before['draft']],'after'=>['revision'=>$revision+1,'active'=>$next['active'],'draft'=>$next['draft']],'key'=>$action==='delete'?$input['key']:null,'index'=>$action==='restore'?$input['index']:null]);
            return ['revision'=>$revision+1]+$next+['presets'=>ForumTheme::presets()];
        });
    }
    /** Pure transition shared by the transactional writer and deterministic tests. */
    public static function transition(array $before,string $action,array $input): array
    {
        if(!in_array($action,['draft','activate','restore','delete'],true))throw new ForumException('Некорректная операция темы.',422);
        $next=$before;unset($next['revision']);
        if($action==='draft'){
            if(!is_array($input['design']??null))throw new ForumException('Требуется дизайн.',422);
            $next['draft']=ForumTheme::validate($input['design']);
        }elseif($action==='delete'){
            $key=$input['key']??null;if(!is_string($key)||!isset($next['library'][$key]))throw new ForumException('Тема не найдена.',404);
            unset($next['library'][$key]);
        }else{
            if($action==='restore'){
                $index=$input['index']??null;if(!is_int($index)||!isset($next['history'][$index]))throw new ForumException('Версия темы не найдена.',404);
                $design=ForumTheme::validate($next['history'][$index]['design']);
            }else $design=ForumTheme::validate($next['draft']);
            if($design!==$next['active']){
                array_unshift($next['history'],['at'=>gmdate('c'),'design'=>$next['active']]);$next['history']=array_slice($next['history'],0,10);
            }
            $next['active']=$design;$next['draft']=$design;
            // Name is display-only; hashed keys cannot inject SQL or prototype properties.
            if($action==='activate'&&!in_array($design,ForumTheme::presets(),true)){
                $key=hash('sha256',$design['name']);$next['library'][$key]=$design;
            }
            if(count($next['library'])>12)throw new ForumException('Не более 12 сохранённых тем. Удалите неиспользуемую тему.',422);
        }
        return $next;
    }
}
