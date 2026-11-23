<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use CajeerEngine\Privacy\ConsentPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** One-use, signed page-view receipts. The receipt identifies a page render, not a visitor.
 * No IP, cookie, User-Agent, URL, UUID, query, account or browser fingerprint is persisted.
 */
final readonly class ForumNativeAnalytics
{
    public const PAGE_EVENTS = [
        'index'=>'forum_index_view',
        'board'=>'forum_board_view',
        'thread'=>'forum_thread_view',
        'page'=>'forum_paginated_view',
    ];

    public function __construct(private DatabaseManager $db) {}

    private static function key(): ?string
    {
        $secret=getenv('APP_KEY');
        if (!is_string($secret) || strlen($secret)<32) return null;
        // Domain separation: never reuse the application's signing key as a raw token.
        return hash_hmac('sha256','forum-native-pageviews-v1',$secret,true);
    }

    public static function pageKind(string $path,array $query): ?string
    {
        if (array_diff(array_keys($query),['page'])) return null;
        if ($path==='/forum') return $query===[]?'index':null;
        if (!ForumAnalyticsIntegration::publicPath($path)) return null;
        $page=$query['page']??null;
        if ($page!==null && (!is_string($page)&&!is_int($page) || !preg_match('/^[1-9][0-9]{0,3}$/D',(string)$page))) return null;
        if ($page!==null && (int)$page>1) return 'page';
        return str_starts_with($path,'/forum/boards/')?'board':'thread';
    }

    public static function issue(string $kind,?int $now=null): ?string
    {
        $key=self::key();
        if ($key===null || !array_key_exists($kind,self::PAGE_EVENTS)) return null;
        $at=$now??time();
        $payload='v1.'.$kind.'.'.$at.'.'.bin2hex(random_bytes(16));
        return $payload.'.'.hash_hmac('sha256',$payload,$key);
    }

    /** Invalid/expired/synthetic receipts never enter the database. */
    public static function verify(mixed $receipt,?int $now=null): array
    {
        $key=self::key();
        if ($key===null || !is_string($receipt) || strlen($receipt)>200 ||
            !preg_match('/^v1\.(index|board|thread|page)\.([1-9][0-9]{0,10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D',$receipt,$m))
            throw new ForumException('Недопустимое подтверждение просмотра.',403);
        $at=(int)$m[2];$time=$now??time();
        if ($at>$time+60 || $at<$time-1800 || !hash_equals(hash_hmac('sha256',substr($receipt,0,-65),$key),$m[4]))
            throw new ForumException('Подтверждение просмотра истекло.',403);
        return [self::PAGE_EVENTS[$m[1]],$m[3]];
    }

    /** The browser must explicitly opt in; signed receipts cannot create views by GET or crawler requests. */
    public function collect(Request $request,mixed $receipt): bool
    {
        if (!$request->isSecure() || $request->getMethod()!=='POST') throw new ForumException('Требуется HTTPS.',403);
        $origin=(string)$request->headers->get('Origin','');
        $expected='https://'.strtolower($request->getHttpHost());
        if (!hash_equals($expected,strtolower($origin)) ||
            !in_array(strtolower((string)$request->headers->get('Sec-Fetch-Site','same-origin')),['same-origin','none'],true))
            throw new ForumException('Запрос должен исходить с форума.',403);
        [$event,$id]=self::verify($receipt);
        return (new ForumAnalyticsEvents($this->db))->recordPage($event,$id);
    }

    /** Add runtime/asset after the cache lookup, so each response gets a fresh receipt.
     * The cache itself contains neither reusable receipts nor visitor-specific content.
     */
    public static function enhance(Response $response,Request $request,string $root,bool $enabled,bool $guest): Response
    {
        if (!$enabled || !$guest || !$request->isSecure() || $request->getMethod()!=='GET' || $response->getStatusCode()!==200 ||
            !str_starts_with((string)$response->headers->get('X-Robots-Tag',''),'index,') ||
            $request->headers->has('Authorization') || $request->headers->has('X-API-Token')) return $response;
        $path=rtrim($request->getPathInfo(),'/')?:'/';
        $kind=self::pageKind($path,$request->query->all());
        if ($kind===null || ($receipt=self::issue($kind))===null) return $response;
        $html=$response->getContent();
        if (!is_string($html) || !str_contains($html,'</head>')) return $response;
        if (!str_contains($html,'data-ce-consent-config=')) {
            $html=(new ConsentPolicy($root))->decorate($html);
            if (!str_contains($html,'data-ce-consent-config=')) return $response;
        }
        $tag='<script defer src="/forum-assets/native-analytics.js?v=1.2.8" data-ce-native-receipt="'.$receipt.'" data-ce-native-path="'.htmlspecialchars($path,ENT_QUOTES,'UTF-8').'"></script>';
        $html=str_ireplace('</head>',$tag.'</head>',$html);
        $response->setContent($html);
        $response->headers->set('X-Forum-Analytics-Public','1');
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }
}
