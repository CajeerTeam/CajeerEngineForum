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

    /**
     * Convert IPS4/IPS5 editor HTML to the forum's closed BBCode grammar.
     * Unknown HTML is stripped, active content discarded, and all links are
     * vetted before being emitted. Resolution runs after attachment/post mapping.
     *
     * @param callable(string,int):?string $resolve
     */
    public static function rich(string $html, callable $resolve, ?string $origin=null): ?string
    {
        if(strlen($html)>1048576 || preg_match('//u',$html)!==1)return null;
        $html=preg_replace('~<(script|style|iframe|object|svg|form|template)\b[^>]*>.*?</\1\s*>~isu','',$html);
        if($html===null)return null;
        $tokens=preg_split('~(<!--[\s\S]*?-->|<![^>]*>|<[^>]{1,4096}>)~u',$html,-1,PREG_SPLIT_DELIM_CAPTURE);
        if($tokens===false||count($tokens)>200000)return null;
        $out='';$stack=[];
        foreach($tokens as $token){
            if($token==='')continue;
            if($token[0]!=='<'){
                $value=html_entity_decode($token,ENT_QUOTES|ENT_HTML5,'UTF-8');
                $out.=self::literal($value);continue;
            }
            if(!preg_match('~^<\s*(/?)\s*([a-z][a-z0-9]*)\b([^>]*)>$~isuD',$token,$m))continue;
            $closing=$m[1]==='/';$tag=strtolower($m[2]);$attrs=$m[3];
            if(in_array($tag,['br','hr'],true)){$out.="\n";continue;}
            if($closing){
                if($stack!==[] && $stack[count($stack)-1]['tag']===$tag){
                    $x=array_pop($stack);$out.=$x['close'];
                    if(in_array($tag,['p','div','blockquote','li','h1','h2','h3','h4','h5','h6'],true))$out.="\n";
                }
                continue;
            }
            if(count($stack)>=32)return null;
            $open='';$close='';
            switch($tag){
                case 'b':case 'strong':$open='[b]';$close='[/b]';break;
                case 'i':case 'em':$open='[i]';$close='[/i]';break;
                case 'u':$open='[u]';$close='[/u]';break;
                case 's':case 'strike':case 'del':$open='[s]';$close='[/s]';break;
                case 'pre':case 'code':$open='[code]';$close='[/code]';break;
                case 'blockquote':
                    $old=self::attribute($attrs,'data-cid') ?? self::attribute($attrs,'data-postid');
                    if($old!==null&&preg_match('/\A[1-9][0-9]{0,17}\z/D',$old)){
                        $url=$resolve('posts',(int)$old);
                        if(is_string($url)&&self::safeUrl($url))$out.='[url='.$url.']Цитируемое сообщение[/url]' . "\n";
                    }
                    $open='[quote]';$close='[/quote]';break;
                case 'ul':$open='[list]';$close='[/list]';break;
                case 'ol':$open='[list=1]';$close='[/list]';break;
                case 'li':$open='[*]';$close='';break;
                case 'a':
                    $url=self::resolveUrl(self::attribute($attrs,'href')??'',$resolve,$origin);
                    if($url!==null){$open='[url='.$url.']';$close='[/url]';}
                    break;
                case 'img':
                    $src=self::attribute($attrs,'data-src')??self::attribute($attrs,'src')??'';
                    $url=self::resolveUrl($src,$resolve,$origin);
                    if($url!==null){$out.='[url='.$url.']Изображение[/url]';}
                    break;
                case 'span':
                    $user=self::attribute($attrs,'data-mentionid')??self::attribute($attrs,'data-memberid');
                    if($user!==null&&preg_match('/\A[1-9][0-9]{0,17}\z/D',$user)){
                        $url=$resolve('users',(int)$user);
                        if(is_string($url)&&self::safeUrl($url)){$open='[url='.$url.']';$close='[/url]';}
                    }
                    break;
            }
            if(in_array($tag,['p','div','h1','h2','h3','h4','h5','h6'],true)&&$out!==''&&!str_ends_with($out,"\n"))$out.="\n";
            $out.=$open;
            if(!in_array($tag,['img','input','source','meta','link','area'],true))$stack[]=['tag'=>$tag,'close'=>$close];
        }
        // Never leave unclosed BBCode: unbalanced input must not leak raw tags.
        while($stack!==[]){$x=array_pop($stack);$out.=$x['close'];}
        $out=trim(preg_replace('/\n{4,}/',"\n\n\n",str_replace("\0",'', $out))??'');
        return strlen($out)<=80000&&preg_match_all('/./us',$out)<=20000?$out:null;
    }
    private static function literal(string $s):string
    {return str_replace(['\\','['],['\\\\','\\['],str_replace("\0",'',str_replace("\r",'', $s)));}
    private static function attribute(string $attrs,string $name):?string
    {
        if(!preg_match('~(?:^|\s)'.preg_quote($name,'~').'\s*=\s*(?:"([^"]{0,2048})"|\'([^\']{0,2048})\'|([^\s/>]{1,2048}))~iu',$attrs,$m))return null;
        return html_entity_decode(($m[1]??'')!==''?$m[1]:(($m[2]??'')!==''?$m[2]:($m[3]??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
    }
    private static function safeUrl(string $url):bool
    {
        if(strlen($url)>2048||preg_match('~[\x00-\x20\x7f\\\\\[\]]~',$url))return false;
        if(preg_match('~\A/(?:forum/(?:threads|boards|members)/[a-f0-9-]{36}|api/v1/forum/attachments/[a-f0-9-]{36}/download)(?:\?page=[1-9][0-9]{0,5})?(?:\#post-[a-f0-9-]{36})?\z~D',$url))return true;
        $p=parse_url($url);
        return is_array($p)&&in_array(strtolower($p['scheme']??''),['http','https'],true)&&isset($p['host'])&&!isset($p['user'])&&!isset($p['pass']);
    }
    private static function resolveUrl(string $raw,callable $resolve,?string $origin):?string
    {
        $raw=trim($raw);
        if(strlen($raw)>2048||preg_match('~[\x00-\x20\x7f\\\\]~',$raw))return null;
        $legacy=$raw;
        if(preg_match('~\Ahttps?://~i',$raw)){
            $source=parse_url($raw);$base=$origin===null?false:parse_url($origin);
            if(!is_array($source)||!self::safeUrl($raw))return null;
            if(!is_array($base) || strtolower($source['host']??'')!==strtolower($base['host']??'')
                || strtolower($source['scheme']??'')!==strtolower($base['scheme']??'')
                || ($source['port']??null)!==($base['port']??null))return $raw;
            $path=$source['path']??'/';$root=rtrim($base['path']??'','/');
            if($root!=='' && !($path===$root||str_starts_with($path,$root.'/')))return $raw;
            $legacy=substr($path,strlen($root));
            if(isset($source['query']))$legacy.='?'.$source['query'];
        }
        // Never treat a URL on an unrelated domain as a local imported item.
        // If no operator-verified origin is supplied, absolute source links
        // cannot be reliably distinguished from third-party URLs.
        if($legacy!==$raw || !preg_match('~\Ahttps?://~i',$raw)){
            if(preg_match('~(?:[?&]comment=|[?&]findComment=)([1-9][0-9]{0,17})~i',$legacy,$post)){
                $url=$resolve('posts',(int)$post[1]);
                return is_string($url)&&self::safeUrl($url)?$url:null;
            }
        }
        // IPS attachment links (including editor storage placeholders) must be
        // mapped; never emit the old storage path or a private source URL.
        if(preg_match('~(?:attach_id=|/attachment\.php\?id=|/attachments/)([1-9][0-9]{0,17})~i',$legacy,$m)){
            $url=$resolve('attachments',(int)$m[1]);return is_string($url)&&self::safeUrl($url)?$url:null;
        }
        if(preg_match('~(?:^|/)topic/([1-9][0-9]{0,17})(?:[-/?#]|\z)~i',$legacy,$m)){
            $url=$resolve('topics',(int)$m[1]);return is_string($url)&&self::safeUrl($url)?$url:null;
        }
        if(preg_match('~(?:^|/)profile/([1-9][0-9]{0,17})(?:[-/?#]|\z)~i',$legacy,$m)){
            $url=$resolve('users',(int)$m[1]);return is_string($url)&&self::safeUrl($url)?$url:null;
        }
        if(str_contains($raw,'<fileStore.')||str_contains($raw,'<___base_url___>'))return null;
        // Unrecognized relative source paths are not valid destinations.
        return self::safeUrl($raw)?$raw:null;
    }

}
