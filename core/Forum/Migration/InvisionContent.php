<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

/** Safe plain-text fallback for IPS HTML posts; rich conversion follows in 1.1.9. */
final class InvisionContent
{
    public static function plain(string $html):?string
    {
        if(strlen($html)>1048576||!preg_match('//u',$html))return null;
        $html=preg_replace('~<(script|style|iframe|object|svg|form)\b[^>]*>.*?</\1\s*>~isu','',$html);
        if($html===null)return null;
        $html=preg_replace('~<\s*(?:br|/p|/div|/li|/blockquote|/pre|/h[1-6])\b[^>]*>~iu',"\n",$html);
        if($html===null)return null;
        $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=str_replace("\0",'',str_replace("\r",'', $text));
        $text=preg_replace('/[\t ]+/u',' ',$text);
        $text=preg_replace('/\n{4,}/u',"\n\n\n",(string)$text);
        if($text===null||strlen($text)>1048576||!preg_match('//u',$text))return null;
        return trim($text);
    }
}
