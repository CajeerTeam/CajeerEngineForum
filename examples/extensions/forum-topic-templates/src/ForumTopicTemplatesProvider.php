<?php

declare(strict_types=1);
namespace CajeerExample;

use CajeerEngine\Extension\Contracts\AbstractExtensionProvider;
use CajeerEngine\Extension\Runtime\ExtensionContext;
use CajeerEngine\Forum\Extension\ForumExtension;

final class ForumTopicTemplatesProvider extends AbstractExtensionProvider
{
    public function boot(ExtensionContext $c): void
    {
        $sdk=new ForumExtension($c);$config=$c->extensionConfig();
        foreach(['bug'=>'Сообщение об ошибке','question'=>'Вопрос'] as $key=>$title){
            $sdk->topicTemplate($key,static function()use($key,$title,$config){
                $text=$config[$key]??null;
                if(!is_string($text)||trim($text)==='')return null;
                return ['title'=>$title,'text'=>$text];
            });
        }
    }
}
