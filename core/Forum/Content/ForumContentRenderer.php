<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Content;

use CajeerEngine\Forum\ForumException;

/** Closed grammar: user input is never interpreted as HTML. No external resources. */
final class ForumContentRenderer
{
    public const VERSION=1;
    public const FORMATS=['plain','markdown','bbcode'];
    private array $references=['@'=>[], '>>'=>[]];
    public static function format(mixed $value): string
    {
        if(!is_string($value)||!in_array($value,self::FORMATS,true))throw new ForumException('Некорректный формат сообщения.');
        return $value;
    }
    /** Validate source without trimming meaningful indentation in formatted documents. */
    public static function source(mixed $body,string $format,bool $allowEmpty=false): string
    {
        self::format($format);
        if(!is_string($body)||strlen($body)>80000||str_contains($body,"\0")||preg_match('//u',$body)!==1)throw new ForumException('Некорректное поле: body');
        if($format==='plain')$body=trim($body);
        if(preg_match_all('/./us',$body)>20000||(!$allowEmpty&&trim($body)===''))throw new ForumException('Некорректное поле: body');
        if(!$allowEmpty&&$format!=='plain'){
            $html=self::render($body,$format)['html'];
            if(trim(html_entity_decode(strip_tags(str_replace('<br>',"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8'))==='')throw new ForumException('Сообщение не содержит текста.');
        }
        return $body;
    }
    private static function e(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function url(string $s): ?string
    {
        if(strlen($s)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$s))return null;
        $p=parse_url($s);if(!is_array($p)||!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass']))return null;
        return $s;
    }
    private function anchor(string $url,string $label): string
    { $url=self::url($url);return $url===null?$label:'<a href="'.self::e($url).'" rel="nofollow ugc noopener noreferrer">'.$label.'</a>'; }
    private function reference(string $token,string $prefix,string $id): string
    {
        $this->references[$prefix][$id]=true;
        return $prefix==='@'?'<a class="content-reference" href="/forum/members/'.$id.'">'.self::e($token).'</a>':'<a class="content-reference" href="#post-'.$id.'">'.self::e($token).'</a>';
    }
    public static function render(string $body,string $format): array
    {
        self::format($format);$r=new self();$body=str_replace(["\r\n","\r"],"\n",$body);
        // 20,000 Unicode characters are validated by the publishing service.
        // Bound bytes as well, including reads of legacy or damaged database rows.
        if(strlen($body)>80000)throw new ForumException('Сообщение слишком длинное.');
        $html=match($format){'markdown'=>$r->markdown($body),'bbcode'=>$r->bbcode($body),default=>str_replace("\n",'<br>',self::e($body))};
        if($format==='plain')foreach(['@','>>'] as $prefix){preg_match_all('/'.preg_quote($prefix,'/').'\{([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\}/',$body,$m);$r->references[$prefix]=array_fill_keys($m[1],true);}
        return ['html'=>$html,'renderer_version'=>self::VERSION,'mentions'=>array_slice(array_keys($r->references['@']),0,20),'quotes'=>array_slice(array_keys($r->references['>>']),0,20)];
    }
    private function inline(string $s,int $depth=0): string
    {
        if($depth>=8)return self::e($s);
        // Tokenize left to right; escaped literals and code never produce references.
        $pattern='~\\\\([[:punct:]])|(`+)([^\n]*?)\2|\[([^\]\n]{1,2000})\]\(([^\s()]{1,2048})\)|((?:@|>>)\{([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\})|(?|(\*)(?=\S)((?:[^*\n]|\*\*[^*\n]+\*\*)+?)(?<=\S)\8(?!\*)|(\*\*|\*)(?=\S)([^\n]+?)(?<=\S)\8(?!\*)|(_)(?=\S)((?:[^_\n]|__[^_\n]+__)+?)(?<=\S)\8(?!_)|(__|_)(?=\S)([^\n]+?)(?<=\S)\8(?!_)|(\~\~)(?=\S)([^\n]+?)(?<=\S)\8(?!\~))~u';
        $out='';$at=0;
        while(preg_match($pattern,$s,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL,$at)===1){
            [$token,$pos]=$m[0];$out.=self::e(substr($s,$at,$pos-$at));
            $g=static fn(int $n)=>$m[$n][0]??null;
            if($g(1)!==null)$out.=self::e($g(1));
            elseif($g(2)!==null)$out.='<code>'.self::e($g(3)).'</code>';
            elseif($g(4)!==null)$out.=$this->anchor($g(5),self::e($g(4)));
            elseif($g(6)!==null)$out.=$this->reference($token,str_starts_with($token,'@')?'@':'>>',$g(7));
            else{$tag=match($g(8)){'**','__'=>'strong','~~'=>'del',default=>'em'};$out.='<'.$tag.'>'.$this->inline($g(9),$depth+1).'</'.$tag.'>';}
            $at=$pos+strlen($token);
        }
        return $out.self::e(substr($s,$at));
    }
    private function markdown(string $s,int $depth=0): string
    {
        if($depth>=8)return str_replace("\n",'<br>',self::e($s));
        $lines=explode("\n",$s);$out='';$n=count($lines);
        for($i=0;$i<$n;){$line=$lines[$i];if(trim($line)===''){$i++;continue;}
            if(preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*([a-zA-Z0-9_+-]{0,30})\s*$/',$line,$m)){
                $f=$m[1];$code=[];$i++;while($i<$n&&!preg_match('/^ {0,3}'.preg_quote($f,'/').substr($f,0,1).'*\s*$/',$lines[$i]))$code[]=$lines[$i++];if($i<$n)$i++;
                $out.='<pre><code'.($m[2]!==''?' class="language-'.self::e($m[2]).'"':'').'>'.self::e(implode("\n",$code)).'</code></pre>';continue;
            }
            if(preg_match('/^(?: {4}|\t)/',$line)){$code=[];while($i<$n&&preg_match('/^(?: {4}|\t)(.*)$/',$lines[$i],$m)){$code[]=$m[1];$i++;}$out.='<pre><code>'.self::e(implode("\n",$code)).'</code></pre>';continue;}
            if(preg_match('/^ {0,3}(#{1,6})\s+(.+)$/',$line,$m)){$tag='h'.strlen($m[1]);$out.='<'.$tag.'>'.$this->inline($m[2]).'</'.$tag.'>';$i++;continue;}
            if(preg_match('/^ {0,3}> ?(.*)$/',$line)&&!str_starts_with(ltrim($line),'>>{')){$q=[];while($i<$n&&preg_match('/^ {0,3}> ?(.*)$/',$lines[$i],$m)&&!str_starts_with(ltrim($lines[$i]),'>>{')){$q[]=$m[1];$i++;}$out.='<blockquote>'.$this->markdown(implode("\n",$q),$depth+1).'</blockquote>';continue;}
            if(preg_match('/^ {0,3}([-+*]|\d{1,9}\.)\s+(.+)$/',$line,$m)){$tag=str_ends_with($m[1],'.')?'ol':'ul';$out.='<'.$tag.'>';while($i<$n&&preg_match('/^ {0,3}([-+*]|\d{1,9}\.)\s+(.+)$/',$lines[$i],$m)&& (str_ends_with($m[1],'.')?'ol':'ul')===$tag){$out.='<li>'.$this->inline($m[2]).'</li>';$i++;}$out.='</'.$tag.'>';continue;}
            if($i+1<$n&&str_contains($line,'|')&&preg_match('/^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$/',$lines[$i+1])){
                $cells=static fn(string $v)=>array_map('trim',explode('|',trim(trim($v),'|')));$header=$cells($line);$count=count($header);$out.='<table><thead><tr>';foreach($header as $v)$out.='<th>'.$this->inline($v).'</th>';$out.='</tr></thead><tbody>';$i+=2;
                while($i<$n&&str_contains($lines[$i],'|')&&count($row=$cells($lines[$i]))===$count){$out.='<tr>';foreach($row as $v)$out.='<td>'.$this->inline($v).'</td>';$out.='</tr>';$i++;}$out.='</tbody></table>';continue;
            }
            $out.='<p>'.$this->inline($line).'</p>';$i++;
        }return $out;
    }
    /** BBCode stack with a strict allowlist; unmatched tokens are literal, depth bounded. */
    private function bbcode(string $s): string
    {
        $tags=['b'=>'strong','i'=>'em','u'=>'u','s'=>'del','quote'=>'blockquote','ul'=>'ul','ol'=>'ol','li'=>'li','list'=>'ul','*'=>'li','h1'=>'h1','h2'=>'h2','h3'=>'h3','h4'=>'h4','h5'=>'h5','h6'=>'h6','table'=>'table','tr'=>'tr','th'=>'th','td'=>'td','p'=>'p','code'=>'code','icode'=>'code','url'=>'a'];
        $pattern='~\\\\(\\\\|\[|@|>)|\[(/?)(b|i|u|s|quote|ul|ol|li|list|\*|h[1-6]|table|tr|th|td|p|icode|code|url)(?:=([^\]\n]{1,2048}))?\]|((?:@|>>)\{([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\})~i';
        $stack=[];$out='';$at=0;
        $emit=static function(string $html)use(&$stack,&$out){if($stack)$stack[count($stack)-1]['html'].=$html;else $out.=$html;};
        while(preg_match($pattern,$s,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL,$at)===1){[$token,$pos]=$m[0];$emit(str_replace("\n",'<br>',self::e(substr($s,$at,$pos-$at))));$at=$pos+strlen($token);$g=static fn(int $n)=>$m[$n][0]??null;
            if($g(1)!==null){$emit(self::e($g(1)));continue;}
            if($g(5)!==null){if(in_array('url',array_column($stack,'name'),true)){$emit(self::e($token));continue;}$emit($this->reference($token,str_starts_with($token,'@')?'@':'>>',strtolower($g(6))));continue;}
            $name=strtolower($g(3));$arg=$g(4);
            // Classic BBCode [list][*]...[*]...[/list] uses implicit item endings.
            if(($name==='list'&&$g(2)==='/')||($name==='*'&&$g(2)==='')){
                if($stack&&$stack[count($stack)-1]['name']==='*'){$item=array_pop($stack);$emit('<li>'.$item['html'].'</li>');}
                if($name==='*'&&$g(2)===''){
                    if(!$stack||$stack[count($stack)-1]['name']!=='list'||$arg!==null||count($stack)>=16){$emit(self::e($token));continue;}
                    $stack[]=['name'=>'*','arg'=>null,'html'=>'','token'=>$token];continue;
                }
            }
            if($g(2)==='/'){
                if(!$stack||$stack[count($stack)-1]['name']!==$name||$arg!==null){$emit(self::e($token));continue;}
                $x=array_pop($stack);$tag=$name==='list'&&$x['arg']==='1'?'ol':$tags[$name];$emit($name==='url'?$this->anchor($x['arg']??html_entity_decode(strip_tags($x['html']),ENT_QUOTES,'UTF-8'),$x['html']):'<'.$tag.'>'.$x['html'].'</'.$tag.'>');continue;
            }
            if($name==='code'||$name==='icode'){
                // Escape literal closing delimiters and backslashes; code remains opaque.
                $code='';$closed=false;$close='[/'.$name.']';$cursor=$at;
                while($cursor<strlen($s)){
                    if($s[$cursor]==='\\'&&isset($s[$cursor+1])&&in_array($s[$cursor+1],['\\','['],true)){$code.=$s[$cursor+1];$cursor+=2;continue;}
                    if(strcasecmp(substr($s,$cursor,strlen($close)),$close)===0){$cursor+=strlen($close);$closed=true;break;}
                    $code.=$s[$cursor++];
                }
                if(!$closed){$emit(self::e($token.substr($s,$at)));$at=strlen($s);break;}
                $lang=$arg!==null&&preg_match('/^[a-zA-Z0-9_+-]{1,30}$/D',$arg)?' class="language-'.self::e($arg).'"':'';
                $emit(($name==='code'?'<pre>':'').'<code'.$lang.'>'.self::e($code).'</code>'.($name==='code'?'</pre>':''));$at=$cursor;continue;
            }
            if(count($stack)>=16||($arg!==null&&$name!=='url'&&!($name==='list'&&$arg==='1'))){$emit(self::e($token));continue;}
            $stack[]=['name'=>$name,'arg'=>$arg,'html'=>'','token'=>$token];
        }
        $emit(str_replace("\n",'<br>',self::e(substr($s,$at))));while($stack){$x=array_pop($stack);$emit(self::e($x['token']).$x['html']);}return $out;
    }
}
