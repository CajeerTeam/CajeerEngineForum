<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Runtime\ConfigRepository;

/** Use a configured origin, never a request Host or forwarded header. */
final readonly class ForumSeoSettings
{
    public function __construct(private string $root) {}
    public function baseUrl(): string { return PublicPath::base((new ConfigRepository($this->root))->string('app.url')); }
    public function indexEnabled(): bool
    {
        $flag=getenv('FORUM_SEO_INDEX');
        return ($flag===false || filter_var($flag,FILTER_VALIDATE_BOOLEAN)) && (bool)((new ThemeConfigRepository($this->root))->get()['robots_index']??true);
    }
    public function sitemapEnabled(): bool
    {
        return $this->indexEnabled() && (bool)((new ThemeConfigRepository($this->root))->get()['sitemap_enabled']??true);
    }
    public function excludedBoards(): array
    {
        return array_values(array_filter(array_map('trim',explode(',',getenv('FORUM_SEO_EXCLUDED_BOARDS')?:'')),static fn($id)=>preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)));
    }
    public function permitsPath(array $breadcrumbs): bool
    {
        $excluded=$this->excludedBoards();
        foreach($breadcrumbs as $node) if($node['kind']==='board' && ((int)($node['seo_index']??1)!==1 || in_array($node['id'],$excluded,true)))return false;
        return true;
    }
}
