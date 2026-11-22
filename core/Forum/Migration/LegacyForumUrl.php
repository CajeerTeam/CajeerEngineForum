<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use CajeerEngine\Forum\ForumException;

/** Decode only known historical routes. The URL is never used as a redirect destination. */
final class LegacyForumUrl
{
    /** @return array{0:string,1:string} */
    public static function resolve(string $platform,string $path,array $query): array
    {
        $path=ltrim($path,'/');
        $numeric=static function(mixed $value): ?string {
            if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)||(string)(int)$value!==$value)return null;
            return $value;
        };
        $result=null;
        if($platform==='phpbb'){
            if($path==='viewtopic.php')$result=isset($query['p'])?['posts',$numeric($query['p'])]:['topics',$numeric($query['t']??null)];
            elseif($path==='viewforum.php')$result=['forums',$numeric($query['f']??null)];
        }elseif($platform==='mybb'){
            if($path==='showthread.php')$result=isset($query['pid'])?['posts',$numeric($query['pid'])]:['topics',$numeric($query['tid']??null)];
            elseif($path==='forumdisplay.php')$result=['forums',$numeric($query['fid']??null)];
            elseif(preg_match('/\A(thread|forum|post)-([1-9][0-9]{0,18})\.html\z/D',$path,$m))$result=[match($m[1]){'thread'=>'topics','forum'=>'forums','post'=>'posts'},$numeric($m[2])];
        }elseif($platform==='smf'&&$path==='index.php'){
            if(is_string($query['topic']??null)&&preg_match('/\A([1-9][0-9]{0,18})(?:\.(?:[0-9]+|msg([1-9][0-9]{0,18})))?\z/D',$query['topic'],$m))$result=isset($m[2])?['posts',$numeric($m[2])]:['topics',$numeric($m[1])];
            elseif(is_string($query['board']??null)&&preg_match('/\A([1-9][0-9]{0,18})(?:\.[0-9]+)?\z/D',$query['board'],$m))$result=['forums',$numeric($m[1])];
        }elseif($platform==='xenforo'){
            // The public legacy endpoint intentionally never redirects private conversations.
            if($path==='goto/post' && isset($query['id']))$result=['posts',$numeric($query['id'])];
            elseif(preg_match('~\Athreads/(?:[^/]{1,240}\.)?[1-9][0-9]{0,18}/post-([1-9][0-9]{0,18})/?\z~D',$path,$post))$result=['posts',$numeric($post[1])];
            elseif(preg_match('~\A(threads|forums|posts)/(?:[^/]{1,240}\.)?([1-9][0-9]{0,18})(?:/page-[1-9][0-9]{0,8})?/?\z~D',$path,$m))$result=[$m[1]==='threads'?'topics':$m[1],$numeric($m[2])];
        }
        if($result===null||$result[1]===null)throw new ForumException('Страница не найдена.',404);
        return $result;
    }
}
