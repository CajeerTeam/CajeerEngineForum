<?php

declare(strict_types=1);
namespace CajeerEngine\Forum;

use CajeerEngine\Forum\Service\{DiscussionService, EngagementService, ModerationService, RegistrationService};
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};

final readonly class ForumController
{
    public function __construct(private DiscussionService $discussions, private EngagementService $engagement, private ModerationService $moderation, private RegistrationService $registration, private string $root) {}

    public function page(): Response
    {
        return new Response((string) file_get_contents($this->root.'/public/forum-assets/index.html'), 200, ['Content-Type'=>'text/html; charset=UTF-8']);
    }
    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try { return new JsonResponse(['data'=>$operation()],$status); }
        catch (ForumException $e) { return new JsonResponse(['error'=>['code'=>'forum_error','message'=>$e->getMessage()]],$e->status); }
    }
    private function field(Request $r, string $key): mixed
    {
        $json = $r->attributes->get('json',[]);
        return is_array($json) ? ($json[$key] ?? null) : null;
    }
    private function text(Request $r, string $key, int $max, int $min = 1): string { return V::text($this->field($r,$key),$key,$max,$min); }
    private function actor(Request $r): ForumAccessPolicy { return ForumAccessPolicy::fromAuth($r->attributes->get('auth')); }
    private function id(array $p): string { return V::id($p['id'] ?? null); }
    private function offset(Request $r): int { return V::offset($r->query->all()['page'] ?? 1); }
    public function index(): JsonResponse { return $this->respond(fn () => $this->discussions->index()); }
    public function board(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->discussions->board($this->id($p),$this->offset($r))); }
    public function thread(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->discussions->thread($this->id($p),$this->offset($r))); }
    public function search(Request $r): JsonResponse { return $this->respond(fn () => $this->discussions->search(V::text($r->query->all()['q'] ?? '', 'q',100,2),$this->offset($r))); }
    public function latest(Request $r): JsonResponse { return $this->respond(fn () => $this->discussions->latest($this->offset($r))); }
    public function profile(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->discussions->profile($this->id($p),$this->offset($r))); }
    public function register(Request $r): JsonResponse { return $this->respond(fn () => ['id'=>$this->registration->register($this->text($r,'email',254),$this->text($r,'name',60,2),V::password($this->field($r,'password')))],201); }
    public function createThread(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->createThread($this->actor($r),$this->id($p),$this->text($r,'title',180,3),$this->text($r,'body',20000))],201); }
    public function reply(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->reply($this->actor($r),$this->id($p),$this->text($r,'body',20000))],201); }
    public function edit(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->edit($this->actor($r),$this->id($p),$this->text($r,'body',20000))]); }
    public function reaction(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->engagement->reaction($this->actor($r),$this->id($p),V::enabled($this->field($r,'enabled')))); }
    public function watch(Request $r,array $p): JsonResponse { return $this->respond(fn () => $this->engagement->watch($this->actor($r),$this->id($p),V::enabled($this->field($r,'enabled')))); }
    public function watches(Request $r): JsonResponse { return $this->respond(fn () => $this->engagement->watches($this->actor($r),$this->offset($r))); }
    public function report(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->report($this->actor($r),$this->id($p),$this->text($r,'reason',500,3))],201); }
    public function reports(Request $r): JsonResponse { return $this->respond(fn () => $this->moderation->reports($this->actor($r),$this->offset($r))); }
    public function resolve(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->resolve($this->actor($r),$this->id($p))]); }
    public function moderate(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->moderation->moderate($this->actor($r),$this->id($p),$this->text($r,'action',20))]); }
    public function category(Request $r): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->category($this->actor($r),$this->text($r,'name',120),$this->text($r,'description',1000,0))],201); }
    public function createBoard(Request $r,array $p): JsonResponse { return $this->respond(fn () => ['id'=>$this->discussions->createBoard($this->actor($r),$this->id($p),$this->text($r,'name',120),$this->text($r,'description',1000,0))],201); }
}
