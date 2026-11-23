<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Privacy\ConsentPolicy;
use CajeerEngine\Privacy\TrackingConfig;
use Symfony\Component\HttpFoundation\Request;

/** Reuses the kernel's live, consent-gated tracking engine. Never instruments private pages. */
final readonly class ForumAnalyticsIntegration
{
    public function __construct(private string $root) {}

    /** Only anonymous, indexable representations are known to be public under Forum ACL. */
    public static function eligible(bool $forumEnabled,bool $indexable,bool $guest,bool $preview): bool
    {
        return $forumEnabled && $indexable && $guest && !$preview;
    }

    /** Only public forum listings and threads are tracked, never profiles or account routes. */
    public static function publicPath(string $path): bool
    {
        return $path === '/forum' || (bool)preg_match('~^/forum/(?:boards|threads)/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$~D', $path);
    }

    /** @return array{html:string,active:bool} */
    public function decorate(string $html,Request $request,array $goals=[]): array
    {
        // Fail closed if the core configuration, public origin or a consent asset is unavailable.
        if(!$request->isSecure() || $request->getMethod()!=='GET' || !self::publicPath(rtrim($request->getPathInfo(),'/')?:'/'))return ['html'=>$html,'active'=>false];
        try {
            $tracking=(new TrackingConfig($this->root))->get();
            if(!$tracking['enabled']||!in_array(strtolower($request->getHost()),$tracking['hosts'],true))return ['html'=>$html,'active'=>false];
            $decorated=(new ConsentPolicy($this->root))->decorate($html);
            $active=$decorated!==$html && str_contains($decorated,'data-ce-consent-config=');
            if (!$active) return ['html'=>$html,'active'=>false];
            // The active counter is owned by the shared privacy runtime. These are only
            // static, operator-validated goal names, never visitor data or inline JS.
            $goals=ForumCommunitySettings::validateMetrikaGoals($goals?:ForumCommunitySettings::metrikaGoals());
            $goalJson=htmlspecialchars(json_encode($goals,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            // This browser document was authorized for exactly one public path.
            // A later history.replaceState() must not authorize tracking an unrelated/private route.
            $path=htmlspecialchars(rtrim($request->getPathInfo(),'/')?:'/',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $decorated=str_replace('data-ce-consent-dynamic="1"', 'data-ce-consent-dynamic="1" data-ce-forum-approved-path="'.$path.'" data-ce-forum-metrika-goals="'.$goalJson.'"', $decorated);
            return ['html'=>$decorated,'active'=>true];
        } catch (\Throwable) {
            return ['html'=>$html,'active'=>false];
        }
    }
}
