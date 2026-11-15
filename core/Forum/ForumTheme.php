<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

/** Design tokens only: no uploaded executable templates, arbitrary CSS or remote resources. */
final class ForumTheme
{
    public static function presets(): array
    {
        $base=['name'=>'Cajeer Default','scheme'=>'system','font'=>'system','font_size'=>15,'width'=>1220,'radius'=>12,'spacing'=>18,'sidebar'=>'right','logo'=>'C',
            'light'=>['bg'=>'#f2f5fa','panel'=>'#ffffff','text'=>'#172334','muted'=>'#52647b','accent'=>'#175ca6','button'=>'#2365bd','line'=>'#cbd5e1'],
            'dark'=>['bg'=>'#0d1320','panel'=>'#172235','text'=>'#e8edf7','muted'=>'#9eafc7','accent'=>'#6cb5ff','button'=>'#2879e3','line'=>'#293950']];
        $ocean=$base;$ocean['name']='Ocean';$ocean['light']=array_replace($base['light'],['bg'=>'#eef8f8','panel'=>'#ffffff','accent'=>'#006b70','button'=>'#006b70','line'=>'#b6d5d6']);$ocean['dark']=array_replace($base['dark'],['bg'=>'#081b23','panel'=>'#102c37','accent'=>'#72e2df','button'=>'#147c81','line'=>'#315461']);
        $warm=$base;$warm['name']='Sand';$warm['font']='serif';$warm['light']=['bg'=>'#faf5ec','panel'=>'#fffdf8','text'=>'#352b22','muted'=>'#685544','accent'=>'#984719','button'=>'#984719','line'=>'#dccbb9'];$warm['dark']=['bg'=>'#221a15','panel'=>'#352820','text'=>'#fff1df','muted'=>'#dfc3aa','accent'=>'#ffc18c','button'=>'#a95725','line'=>'#624838'];
        return ['default'=>$base,'ocean'=>$ocean,'sand'=>$warm];
    }
    public static function validate(array $v): array
    {
        $base=self::presets()['default'];
        if(array_diff_key($v,$base)||array_diff_key($base,$v))throw new ForumException('Некорректный набор настроек темы.',422);
        foreach(['name'=>60,'logo'=>4] as $key=>$max){if(!is_string($v[$key])||!preg_match('/\A[^\p{C}<>]{1,'.$max.'}\z/u',$v[$key])||trim($v[$key])==='')throw new ForumException('Некорректное поле темы: '.$key,422);$v[$key]=trim($v[$key]);}
        foreach(['scheme'=>['system','light','dark'],'font'=>['system','serif','mono'],'sidebar'=>['left','right','none']] as $key=>$choices)if(!in_array($v[$key],$choices,true))throw new ForumException('Недопустимый вариант: '.$key,422);
        foreach(['font_size'=>[13,20],'width'=>[800,1600],'radius'=>[0,24],'spacing'=>[8,32]] as $key=>[$min,$max])if(!is_int($v[$key])||$v[$key]<$min||$v[$key]>$max)throw new ForumException('Недопустимое значение: '.$key,422);
        foreach(['light','dark'] as $scheme){
            if(!is_array($v[$scheme])||array_diff_key($v[$scheme],$base[$scheme])||array_diff_key($base[$scheme],$v[$scheme]))throw new ForumException('Некорректная палитра.',422);
            foreach($base[$scheme] as $key=>$_){$color=$v[$scheme][$key];if(!is_string($color)||!preg_match('/\A#[0-9a-fA-F]{6}\z/D',$color))throw new ForumException('Цвет должен иметь формат #RRGGBB.',422);$v[$scheme][$key]=strtolower($color);}
            foreach(['text','muted','accent'] as $key)foreach(['bg','panel'] as $surface)if(self::contrast($v[$scheme][$key],$v[$scheme][$surface])<4.5)throw new ForumException('Недостаточный контраст '.$scheme.': '.$key.' / '.$surface,422);
        }
        // Canonical key order makes equivalent styles share one cache identity.
        foreach(['light','dark'] as $scheme)$v[$scheme]=array_replace($base[$scheme],$v[$scheme]);
        return array_replace($base,$v);
    }
    private static function luminance(string $hex): float
    { $n=[];foreach([1,3,5] as $i){$c=hexdec(substr($hex,$i,2))/255;$n[]=$c<=0.04045?$c/12.92:(($c+0.055)/1.055)**2.4;}return .2126*$n[0]+.7152*$n[1]+.0722*$n[2]; }
    public static function contrast(string $a,string $b): float
    { $x=self::luminance($a);$y=self::luminance($b);return (max($x,$y)+.05)/(min($x,$y)+.05); }
    public static function css(array $v): string
    {
        $v=self::validate($v);$font=match($v['font']){'serif'=>'Georgia,serif','mono'=>'ui-monospace,monospace',default=>'system-ui,sans-serif'};
        $palette=static function(string $scheme)use($v):string{
            $p=$v[$scheme];$css='color-scheme:'.$scheme.';';foreach($p as $key=>$color)$css.='--'.$key.':'.$color.';';
            return $css.'--blue:'.$p['button'].';--on-button:'.(self::contrast($p['button'],'#ffffff')>=4.5?'#ffffff':'#000000').';';
        };
        $default=$v['scheme']==='light'?'light':'dark';
        return ':root{'.$palette($default).'--forum-font:'.$font.';--forum-font-size:'.$v['font_size'].'px;--forum-width:'.$v['width'].'px;--forum-radius:'.$v['radius'].'px;--forum-spacing:'.$v['spacing'].'px;}'
            .':root[data-theme=light]{'.$palette('light').'}:root[data-theme=dark]{'.$palette('dark').'}'
            .'@media(prefers-color-scheme:light){:root[data-theme=system]{'.$palette('light').'}}@media(prefers-color-scheme:dark){:root[data-theme=system]{'.$palette('dark').'}}';
    }
}
