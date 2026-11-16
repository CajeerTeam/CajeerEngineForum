<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

/** Convert phpBB 3.3 s9e XML / legacy BBCode into safe literal text.
 * Unknown tags are not executed, remote media is not fetched. */
final class PhpbbText
{
    public static function plain(string $value,string $uid=''): string
    {
        if(!preg_match('//u',$value))throw new \RuntimeException('Invalid UTF-8 in source');
        // s9e stores formatting markers as <s>, <e> and <i> nodes.
        if(preg_match('/\A\s*<(?:r|t)(?:\s|>)/',$value)){
            $value=preg_replace('~<(?:s|e|i)(?:\s[^>]*)?>.*?</(?:s|e|i)>~su','',$value)??$value;
            $value=preg_replace('~<(?:br\s*/?|/p|/QUOTE|/LI|/LIST)>~i',"\n",$value)??$value;
            $value=strip_tags($value);
        } elseif($uid!==''&&preg_match('/\A[a-zA-Z0-9]{1,32}\z/D',$uid)){
            $value=preg_replace('/:'.preg_quote($uid,'/').'(?=\])/u','',$value)??$value;
        }
        $value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
        return str_replace("\0",'',$value);
    }
    public static function short(string $value,int $max,string $fallback): string
    {
        $value=trim(preg_replace('/\s+/u',' ',self::plain($value))??'');
        if($value==='')$value=$fallback;
        return mb_substr($value,0,$max,'UTF-8');
    }
    public static function date(mixed $seconds): string {return gmdate('Y-m-d H:i:s',max(0,min(253402300799,(int)$seconds)));}
}
