<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Forum\ForumVersion;

/** Catalog discovery is advisory; signed compatibility is authoritative at staging/apply. */
final class ReleasePath
{
    public static function accepts(string $selected, string $actual): bool
    {
        return in_array($selected, ['stable','rc','beta','dev'], true)
            && in_array($actual, ['stable','rc','beta','dev'], true)
            && ($actual === 'stable' || $selected === $actual || $selected === 'dev');
    }

    /** SemVer precedence, including arbitrary development identifiers and large numeric components. */
    public static function compare(string $a, string $b): int
    {
        $parse = static function(string $v): array {
            ForumVersion::validate($v);
            [$base, $pre] = array_pad(explode('-', $v, 2), 2, null);
            $ids = $pre === null ? [] : explode('.', $pre);
            foreach ($ids as $id) if (ctype_digit($id) && strlen($id)>1 && $id[0]==='0') throw new \RuntimeException('Invalid numeric prerelease identifier');
            return [explode('.', $base), $ids];
        };
        $numeric = static fn(string $x,string $y): int => (strlen($x)<=>strlen($y)) ?: strcmp($x,$y);
        [$baseA,$preA]=$parse($a); [$baseB,$preB]=$parse($b);
        for($i=0;$i<3;$i++) if(($c=$numeric($baseA[$i],$baseB[$i]))!==0)return $c;
        if ($preA===[] || $preB===[]) return ($preA===[]) <=> ($preB===[]);
        foreach ($preA as $i=>$x) {
            if (!isset($preB[$i])) return 1;
            $y=$preB[$i]; $nx=ctype_digit($x); $ny=ctype_digit($y);
            $c=$nx && $ny ? $numeric($x,$y) : ($nx!==$ny ? ($ny<=>$nx) : strcmp($x,$y));
            if($c!==0)return $c;
        }
        return count($preA)<=>count($preB);
    }

    public static function kind(string $from,string $to): string
    {
        $a=explode('.',explode('-',$from,2)[0]); $b=explode('.',explode('-',$to,2)[0]);
        return $a[0]!==$b[0]?'major':($a[1]!==$b[1]?'minor':($a[2]!==$b[2]?'patch':'prerelease'));
    }

    public static function plan(array $releases,string $current,string $channel,bool $sequential): array
    {
        if(!in_array($channel,['stable','rc','beta','dev'],true))throw new \RuntimeException('Invalid UPDATE_CHANNEL');
        self::compare($current,$current);
        $eligible=[]; $duplicates=[];
        foreach($releases as $release){
            if(!is_array($release)||!is_string($release['version']??null)||!is_array($release['artifacts']['dist']??null))continue;
            $v=$release['version'];
            try{ $actual=UpdatePreflight::channelOf($v); self::compare($v,$v); }catch(\Throwable){continue;}
            if($actual!==($release['channel']??'stable')||!self::accepts($channel,$actual))continue;
            if(isset($eligible[$v]))$duplicates[$v]=true;
            $eligible[$v]=$release;
        }
        $eligible=array_diff_key($eligible,$duplicates);
        uasort($eligible,static fn(array $a,array $b):int=>self::compare($a['version'],$b['version']));
        $latest=$eligible===[]?null:end($eligible);
        $newer=array_values(array_filter($eligible,static fn(array $r):bool=>self::compare($r['version'],$current)>0));
        if(!$sequential&&$newer!==[])$newer=[end($newer)];
        $steps=[];$from=$current;
        foreach($newer as $r){$steps[]=['from'=>$from,'version'=>$r['version'],'channel'=>$r['channel']??'stable','kind'=>self::kind($from,$r['version'])];$from=$r['version'];}
        return ['latest'=>$latest,'next'=>$newer[0]??null,'steps'=>$steps,'sequential'=>$sequential];
    }
}
