<?php
declare(strict_types=1);
namespace CajeerEngine\Extension\Runtime;

/** Process-local registrations owned by an extension. No arbitrary core route replacement. */
final class PlatformServices
{
    private array $actions=[]; private array $jobs=[]; private array $pages=[]; private array $tasks=[]; private array $services=[];
    private array $forumListeners=[];
    private array $forumPanels=[];
    public function forumListen(ExtensionContext $c,string $name,string $event,callable $handler): void
    {
        if(!array_key_exists($event,\CajeerEngine\Forum\Service\ForumExtensionOutbox::EVENTS))throw new \InvalidArgumentException('Unsupported forum event');
        $key=$this->key($c,$name,'forum.events');
        if(count($this->forumListeners)>=128)throw new \RuntimeException('Forum subscriber limit exceeded');
        $since=strtotime((string)($c->state['enabled_at']??''))?:0;
        $this->put($this->forumListeners,$key,['event'=>$event,'handler'=>$handler,'since'=>$since]);
    }
    public function forumSubscribers(string $event, int $occurredAt=PHP_INT_MAX): array
    {
        return array_keys(array_filter($this->forumListeners,static fn($entry)=>$entry['event']===$event&&$entry['since']<=$occurredAt));
    }
    public function deliverForumEvent(string $key,string $event,array $payload): bool
    {
        $listener=$this->forumListeners[$key]??null;
        if($listener===null||$listener['event']!==$event||$listener['since']>(int)($payload['occurred_at']??0))return false;
        ($listener['handler'])($payload,$event);return true;
    }
    /** Slots accept structured plain text only; no arbitrary HTML, scripts or route replacement. */
    public function forumPanel(ExtensionContext $c,string $name,string $slot,callable $handler): void
    {
        if(!in_array($slot,['home','sidebar','thread','composer'],true))throw new \InvalidArgumentException('Unsupported forum slot');
        $this->put($this->forumPanels,$this->key($c,$name,'forum.ui'),['slot'=>$slot,'handler'=>$handler,'context'=>$c]);
    }
    public function hasForumPanels(): bool {return $this->forumPanels!==[];}
    public function forumPanels(string $slot): array
    {
        $panels=[];
        foreach($this->forumPanels as $key=>$entry){
            if($entry['slot']!==$slot)continue;
            try{
                // No request, user identifier or content is passed to a public renderer.
                $value=($entry['handler'])();if($value===null)continue;
                if(!is_array($value)||!is_string($value['title']??null)||!is_string($value['text']??null)||strlen($value['title'])>240||strlen($value['text'])>4000)throw new \InvalidArgumentException('Invalid forum panel');
                $panels[]=['title'=>$value['title'],'text'=>$value['text']];
            }catch(\Throwable $e){
                // A broken optional panel must not break reading a discussion.
                try{$entry['context']->log('forum.panel.failed',['slot'=>$slot,'panel'=>$key]);}catch(\Throwable){}
            }
            if(count($panels)>=10)break;
        }
        return $panels;
    }
    public function forumDiagnostics(): array {return ['listeners'=>array_keys($this->forumListeners),'panels'=>array_keys($this->forumPanels)];}
    private function key(ExtensionContext $c,string $name,string $permission): string
    {
        $c->requirePermission($permission);
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$name)) throw new \InvalidArgumentException('Invalid registration name');
        return $c->slug().'.'.$name;
    }
    private function put(array &$map,string $key,mixed $value): void { if (isset($map[$key])) throw new \RuntimeException('Duplicate registration: '.$key); $map[$key]=$value; }
    public function service(ExtensionContext $c,string $name,callable $factory): void { $key=$this->key($c,$name,'services.register'); $this->put($this->services,$key,$factory); $c->container->set('extension.'.$key,$factory); }
    public function action(ExtensionContext $c,string $name,callable $handler,bool $write=false): void { $this->put($this->actions,$this->key($c,$name,'routes.register'),['handler'=>$handler,'write'=>$write]); }
    public function page(ExtensionContext $c,string $name,string $title,string $action): void { $key=$this->key($c,$name,'admin.register'); $this->put($this->pages,$key,['slug'=>$c->slug(),'name'=>$name,'title'=>$title,'action'=>$action]); }
    public function job(ExtensionContext $c,string $name,callable $handler): void { $this->put($this->jobs,'extension.'.$this->key($c,$name,'queue.register'),$handler); }
    public function task(ExtensionContext $c,string $name,callable $handler): void { $this->put($this->tasks,$this->key($c,$name,'scheduler.register'),$handler); }
    public function execute(string $slug,string $name,array $payload,bool $write): array { $a=$this->actions[$slug.'.'.$name]??throw new \OutOfBoundsException('Extension action not found'); if ($a['write']!==$write) throw new \OutOfBoundsException('Extension action method mismatch'); $result=($a['handler'])($payload); if (!is_array($result)) throw new \RuntimeException('Extension action must return an array'); return $result; }
    public function handleJob(string $name,array $payload,?\CajeerEngine\Queue\JobContext $context=null): void { $handler=$this->jobs[$name]??throw new \RuntimeException('Extension job unavailable: '.$name); $handler($payload,$context); }
    public function pages(): array { return array_values($this->pages); }
    public function tasks(): array { return array_keys($this->tasks); }
    public function runTasks(string $root,?\CajeerEngine\Scheduler\TaskRunStore $store=null): array {
        $store??=new \CajeerEngine\Scheduler\TaskRunStore($root); $results=[];
        foreach($this->tasks as $name=>$handler)$results[$name]=$store->run('extension.'.$name,60,$handler);
        return $results;
    }
}
