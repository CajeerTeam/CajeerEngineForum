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

    /** @return array{html:string,active:bool} */
    public function decorate(string $html,Request $request): array
    {
        // Fail closed if the core configuration, public origin or a consent asset is unavailable.
        if(!$request->isSecure() || !in_array($request->getMethod(),['GET','HEAD'],true))return ['html'=>$html,'active'=>false];
        try {
            $tracking=(new TrackingConfig($this->root))->get();
            if(!$tracking['enabled']||!in_array(strtolower($request->getHost()),$tracking['hosts'],true))return ['html'=>$html,'active'=>false];
            $decorated=(new ConsentPolicy($this->root))->decorate($html);
            $active=$decorated!==$html && str_contains($decorated,'data-ce-consent-config=');
            return ['html'=>$active?$decorated:$html,'active'=>$active];
        } catch (\Throwable) {
            return ['html'=>$html,'active'=>false];
        }
    }
}
