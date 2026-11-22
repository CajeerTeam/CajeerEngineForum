<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

/** Decode untrusted IPS poll choices without constructing PHP objects or executing source code. */
final class InvisionPoll
{
    /** @return list<string>|null */
    public static function options(string $raw):?array
    {
        if(strlen($raw)>65536 || !preg_match('//u',$raw))return null;
        $input=json_decode($raw,true,16);
        if(json_last_error()!==JSON_ERROR_NONE){
            try{$input=@unserialize($raw,['allowed_classes'=>false,'max_depth'=>16]);}
            catch(\Throwable){return null;}
        }
        if(!is_array($input))return null;
        $list=self::findOptions($input,0);
        if($list===null||count($list)<2||count($list)>20)return null;
        $result=[];
        foreach($list as $item){
            if(is_array($item))$item=$item['value']??$item['title']??$item['name']??null;
            if(!is_string($item)||!preg_match('//u',$item))return null;
            $text=trim(html_entity_decode(strip_tags($item),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if($text===''||strlen($text)>180||preg_match_all('/./us',$text)>180)return null;
            $result[]=$text;
        }
        if(count(array_unique($result))!==count($result))return null;
        return array_values($result);
    }
    private static function findOptions(array $v,int $depth):?array
    {
        if($depth>=6 || count($v)>100)return null;
        foreach(['choices','options','choice','answers'] as $key){
            if(isset($v[$key])&&is_array($v[$key]))return self::findOptions($v[$key],$depth+1);
        }
        if(array_is_list($v)){
            if(count($v)>=2 && count($v)<=20 && count(array_filter($v,static fn($x)=>is_string($x)||is_array($x)))===count($v)){
                if(isset($v[0]['question'],$v[0]['choice']))return self::findOptions($v[0]['choice'],$depth+1);
                return $v;
            }
            if(count($v)===1 && is_array($v[0]))return self::findOptions($v[0],$depth+1);
        }
        if(count($v)>=2 && count($v)<=20 && count(array_filter($v,static fn($x)=>is_string($x)||is_array($x)))===count($v))return array_values($v);
        foreach($v as $child)if(is_array($child) && ($found=self::findOptions($child,$depth+1))!==null)return $found;
        return null;
    }
}
