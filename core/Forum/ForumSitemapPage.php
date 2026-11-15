<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

/** Plan a bounded sitemap window: home, sorted boards, then sorted live threads. */
final class ForumSitemapPage
{
    public const SIZE=1000;
    public static function plan(array $boards,int $threads,?int $page): array
    {
        sort($boards,SORT_STRING);
        $boardCount=count($boards)+1;$total=$boardCount+$threads;$pages=(int)ceil($total/self::SIZE);
        if($pages>50000)throw new ForumException('Слишком много страниц карты сайта.',503);
        if($page===null)return ['pages'=>$pages];
        if($page<1||$page>$pages)throw new ForumException('Карта сайта не найдена.',404);
        $offset=($page-1)*self::SIZE;$paths=[];
        if($offset===0)$paths[]='/forum';
        if($offset<$boardCount)foreach(array_slice($boards,max(0,$offset-1),self::SIZE-count($paths)) as $id)$paths[]='/forum/boards/'.$id;
        return ['pages'=>$pages,'paths'=>$paths,'thread_offset'=>max(0,$offset-$boardCount),'thread_limit'=>self::SIZE-count($paths)];
    }
}
