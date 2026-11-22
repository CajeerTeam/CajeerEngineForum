<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use CajeerEngine\Forum\ForumException;

/**
 * XenForo 2.2/2.3 message converter. Never executes markup or follows remote URLs.
 * Source references are resolved only in the final import phase, after all ID maps exist.
 */
final class XenForoContent
{
    /** The supplied origin is an operator-approved origin, not an untrusted post URL. */
    public static function validOrigin(string $origin): bool
    {
        if(strlen($origin)>300 || preg_match('~[\x00-\x20\x7f\\\\]~', $origin))return false;
        $u=parse_url($origin);
        if(!is_array($u)||!in_array(strtolower($u['scheme']??''),['http','https'],true)
            ||empty($u['host'])||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment']))return false;
        return !isset($u['path'])||preg_match('~\A(?:/[a-zA-Z0-9_-]+)*/?\z~D',$u['path'])===1;
    }

    /** Escape generated labels so they cannot impersonate additional BBCode tags. */
    private static function literal(string $s): string
    {
        return str_replace(['\\','['],['\\\\','\\['],str_replace("\0",'', $s));
    }

    private static function httpUrl(string $url): bool
    {
        if(strlen($url)>2048||preg_match('~[\x00-\x20\x7f\\\\]~',$url))return false;
        $u=parse_url($url);
        return is_array($u)&&in_array(strtolower($u['scheme']??''),['http','https'],true)
            &&!empty($u['host'])&&!isset($u['user'])&&!isset($u['pass']);
    }

    /** @return array{0:bool,1:?string} Whether the URL belongs to this source and its resolved target. */
    private static function legacyUrl(string $url,callable $resolve,?string $origin): array
    {
        $url=html_entity_decode(trim($url," \t\r\n\"'"),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(strlen($url)>2048||preg_match('~[\x00-\x20\x7f\\\\]~',$url))return [false,null];
        $absolute=preg_match('~\Ahttps?://~i',$url)===1;
        if($absolute){
            if($origin===null||!self::httpUrl($url))return [false,null];
            $u=parse_url($url);$base=parse_url($origin);
            if(!is_array($u)||!is_array($base)||strtolower($u['host'])!==strtolower($base['host'])
                ||strtolower($u['scheme'])!==strtolower($base['scheme'])
                ||($u['port']??null)!==($base['port']??null))return [false,null];
            $path=$u['path']??'/';$root=rtrim($base['path']??'','/');
            if($root!==''&&!($path===$root||str_starts_with($path,$root.'/')))return [false,null];
            $path=substr($path,strlen($root));$query=$u['query']??'';$fragment=$u['fragment']??'';
        } else {
            if(str_starts_with($url,'//')||str_starts_with($url,'#')||str_starts_with($url,'?'))return [false,null];
            $u=parse_url($url);
            if(!is_array($u)||isset($u['scheme'])||isset($u['host']))return [false,null];
            $path=$u['path']??'';$query=$u['query']??'';$fragment=$u['fragment']??'';
        }
        $path=ltrim($path,'/');
        if($path==='index.php' && preg_match('~\A(?:threads|forums|posts|members|attachments)/~i',$query)){
            $path=$query;$query='';
        }
        // Recognized legacy routes are never redirected back to an old private board.
        if(!preg_match('~\A(?:threads|forums|posts|members|attachments|goto)/~i',$path))return [false,null];
        if(preg_match('~\Athreads/(?:[^/]{1,240}\.)?[1-9][0-9]{0,18}/post-([1-9][0-9]{0,18})/?\z~iD',$path,$m))
            return [true,$resolve('posts',(int)$m[1])];
        if(preg_match('~\Apost-([1-9][0-9]{0,18})\z~iD',$fragment,$m)
            ||preg_match('~\Apost-([1-9][0-9]{0,18})\z~iD',ltrim($fragment,'#'),$m))
            return [true,$resolve('posts',(int)$m[1])];
        parse_str($query,$qs);
        if($path==='goto/post'&&isset($qs['id'])&&is_string($qs['id'])&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$qs['id']))
            return [true,$resolve('posts',(int)$qs['id'])];
        if(preg_match('~\A(?:members|attachments)/(?:[^/]{1,240}\.)?([1-9][0-9]{0,18})/?\z~iD',$path,$m))
            return [true,$resolve(str_starts_with(strtolower($path),'members/')?'users':'attachments',(int)$m[1])];
        try{
            [$kind,$id]=LegacyForumUrl::resolve('xenforo',$path,$qs);
            return [true,$resolve($kind,(int)$id)];
        }catch(ForumException){return [true,null];}
    }

    /**
     * @param callable(string,int):?string $resolve Resolves old ID to a canonical, local URL.
     * @return string BBCode consumed by the existing safe ForumContentRenderer.
     */
    public static function convert(string $source,callable $resolve,?string $origin=null): string
    {
        if(!preg_match('//u',$source))throw new \InvalidArgumentException('Invalid source UTF-8');
        if($origin!==null&&!self::validOrigin($origin))throw new \InvalidArgumentException('Invalid source origin');
        $source=str_replace(["\r\n","\r","\0"],["\n","\n",''],$source);
        // Isolate code verbatim: URLs, mentions and quote-like text in code are not links.
        $parts=preg_split('~(\[(?:code|icode)(?:=[^\]\n]{1,30})?\].*?\[/(?:code|icode)\])~is',$source,-1,PREG_SPLIT_DELIM_CAPTURE);
        if(!is_array($parts))throw new \RuntimeException('Cannot split XenForo source content');
        foreach($parts as $index=>&$part){
            if($index%2===1)continue;
            $part=preg_replace_callback('~\[USER=([1-9][0-9]{0,18})\](.*?)\[/USER\]~is',static function(array $m)use($resolve):string{
                $label=self::literal($m[2]);$link=$resolve('users',(int)$m[1]);
                return $link!==null?'[url='.$link.']'.$label.'[/url]':$label;
            },$part)??$part;
            $part=preg_replace_callback('~\[QUOTE(?:=([^\]\n]{1,500}))?\]~i',static function(array $m)use($resolve):string{
                if(!isset($m[1]))return '[quote]';
                $metadata=trim($m[1]," \t\"'");
                $name=trim(preg_split('~\s*,\s*(?:post|member):~i',$metadata,2)[0]);
                $name=self::literal(preg_match('/\A.{0,120}/us',$name,$cut)?$cut[0]:'');
                $prefix=$name!==''?$name.': ' : 'Quote: ';
                if(preg_match('~(?:^|,)\s*post:\s*([1-9][0-9]{0,18})(?:,|$)~i',$metadata,$id)){
                    $link=$resolve('posts',(int)$id[1]);
                    if($link!==null)$prefix='[url='.$link.']'.$name.'[/url]: ';
                }
                return '[quote]'.$prefix."\n";
            },$part)??$part;
            $part=preg_replace_callback('~\[ATTACH(?:=[^\]\n]{1,50})?\]([1-9][0-9]{0,18})\[/ATTACH\]~i',static function(array $m)use($resolve):string{
                $url=$resolve('attachments',(int)$m[1]);
                return $url!==null?'[url='.$url.']Attachment #'.$m[1].'[/url]':'Attachment #'.$m[1].' (unavailable)';
            },$part)??$part;
            $part=preg_replace_callback('~\[IMG(?:=[^\]\n]{1,100})?\](.*?)\[/IMG\]~is',static function(array $m):string{
                $url=trim($m[1]);
                return self::httpUrl($url)?'[url='.$url.']Image[/url]':'Image: '.self::literal($url);
            },$part)??$part;
            // Support the common 2.3 heading tag without extending the target grammar.
            $part=preg_replace_callback('~\[HEADING=([1-3])\](.*?)\[/HEADING\]~is',
                static fn(array $m):string=>'[h'.$m[1].']'.$m[2].'[/h'.$m[1].']',$part)??$part;
            $part=preg_replace_callback('~\[URL=([^\]\n]{1,2048})\](.*?)\[/URL\]~is',static function(array $m)use($resolve,$origin):string{
                $href=html_entity_decode(trim($m[1]," \t\"'"),ENT_QUOTES|ENT_HTML5,'UTF-8');
                // Links generated earlier in this pass already use strictly validated
                // local target paths; keep them instead of treating them as source links.
                if(preg_match('~\A/(?:forum/(?:threads|boards|members)/[a-f0-9-]{36}|api/v1/forum/attachments/[a-f0-9-]{36}/download)(?:\?page=[1-9][0-9]{0,5})?(?:\#post-[a-f0-9-]{36})?\z~D',$href))
                    return '[url='.$href.']'.$m[2].'[/url]';
                [$local,$url]=self::legacyUrl($href,$resolve,$origin);
                if($local)return $url===null?self::literal($m[2]):'[url='.$url.']'.$m[2].'[/url]';
                return self::httpUrl($href)?'[url='.$href.']'.$m[2].'[/url]':self::literal($m[2]);
            },$part)??$part;
            // Native [URL]https://...[/URL] links need the same origin rules.
            $part=preg_replace_callback('~\[URL\]([^\[\]\n]{1,2048})\[/URL\]~i',static function(array $m)use($resolve,$origin):string{
                $href=trim($m[1]);[$local,$url]=self::legacyUrl($href,$resolve,$origin);
                if($local)return $url===null?self::literal($href):'[url='.$url.']'.self::literal($href).'[/url]';
                return self::httpUrl($href)?'[url='.$href.']'.self::literal($href).'[/url]':self::literal($href);
            },$part)??$part;
            // Rewrite bare links only outside existing URL BBCode to avoid nested anchors.
            $blocks=preg_split('~(\[url(?:=[^\]\n]*)?\].*?\[/url\])~is',$part,-1,PREG_SPLIT_DELIM_CAPTURE);
            if(!is_array($blocks))throw new \RuntimeException('Cannot tokenize source links');
            foreach($blocks as $key=>&$block){
                if($key%2===1)continue;
                $block=preg_replace_callback('~(?<![=\w/])https?://[^\s\[\]<>\"\']{1,2048}~iu',static function(array $m)use($resolve,$origin):string{
                    $url=rtrim($m[0],'.!,;');$suffix=substr($m[0],strlen($url));
                    [$local,$link]=self::legacyUrl($url,$resolve,$origin);
                    if(!$local)return $m[0];
                    return ($link===null?self::literal($url):'[url='.$link.']'.self::literal($url).'[/url]').$suffix;
                },$block)??$block;
                $block=preg_replace_callback('~(?<![=\w/])/(?:threads|forums|posts|members|attachments)/[A-Za-z0-9_.-]{1,240}(?:/[A-Za-z0-9_.-]{1,80})?/?~i',static function(array $m)use($resolve,$origin):string{
                    [$local,$link]=self::legacyUrl($m[0],$resolve,$origin);
                    if(!$local)return $m[0];
                    return $link===null?self::literal($m[0]):'[url='.$link.']'.self::literal($m[0]).'[/url]';
                },$block)??$block;
            }unset($block);
            $part=implode('',$blocks);
        }unset($part);
        return implode('',$parts);
    }
}
