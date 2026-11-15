<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Runtime\ConfigRepository;

/** Use a configured origin, never a request Host. Freeze policy per representation,
 * not for the lifetime of a long-running application/worker. */
final class ForumSeoSettings
{
    private ?array $policy=null;
    public function __construct(private readonly string $root,private readonly ?DatabaseManager $db=null,private readonly bool $freeze=false) {}
    public function forRequest(): self { return new self($this->root,$this->db,true); }
    private function policy(): array
    {
        if($this->policy!==null)return $this->policy;
        $theme=(new ThemeConfigRepository($this->root,$this->db))->get();
        $flag=getenv('FORUM_SEO_INDEX');
        $excluded=array_values(array_unique(array_filter(array_map('trim',explode(',',getenv('FORUM_SEO_EXCLUDED_BOARDS')?:'')),static fn($id)=>preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id))));
        sort($excluded,SORT_STRING);
        $data=['base'=>PublicPath::base((new ConfigRepository($this->root))->string('app.url')),
            'index'=>($flag===false||filter_var($flag,FILTER_VALIDATE_BOOLEAN))&&filter_var($theme['robots_index']??true,FILTER_VALIDATE_BOOLEAN),
            'follow'=>filter_var($theme['robots_follow']??true,FILTER_VALIDATE_BOOLEAN),'sitemap'=>filter_var($theme['sitemap_enabled']??true,FILTER_VALIDATE_BOOLEAN),'excluded'=>$excluded, 'community'=>$this->db===null?\CajeerEngine\Forum\Service\ForumCommunitySettings::effective([]):(new \CajeerEngine\Forum\Service\ForumCommunitySettings($this->db))->snapshot()['values']];
        if($this->freeze)$this->policy=$data;
        return $data;
    }
    public function community(): array { return $this->policy()['community']; }
    public function baseUrl(): string { return $this->policy()['base']; }
    public function indexEnabled(): bool { return $this->policy()['index']; }
    public function followEnabled(): bool { return $this->policy()['follow']; }
    public function sitemapEnabled(): bool { $p=$this->policy();return $p['index']&&$p['sitemap']; }
    public function excludedBoards(): array { return $this->policy()['excluded']; }
    public function cacheIdentity(): array { return $this->policy(); }
    public function permitsPath(array $breadcrumbs): bool
    {
        $excluded=$this->excludedBoards();
        foreach($breadcrumbs as $node) if($node['kind']==='board' && ((int)($node['seo_index']??1)!==1 || in_array($node['id'],$excluded,true)))return false;
        return true;
    }
}
