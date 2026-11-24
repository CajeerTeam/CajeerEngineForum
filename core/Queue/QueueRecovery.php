<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

/** Recover abandoned reservations in every discovered queue, even without active consumers. */
final readonly class QueueRecovery
{
    public function __construct(private ManagedQueueDriverInterface $queue) {}
    public function run(int $limit=100): array
    {
        if (!$this->queue instanceof LeaseQueueDriverInterface) throw new \RuntimeException('Lease recovery unsupported');
        $limit=max(1,min(1000,$limit));
        $diagnostics=$this->queue->diagnostics();
        if (($diagnostics['connected']??true)===false) throw new \RuntimeException('Queue unavailable');
        $names=array_unique(['default',...array_keys($diagnostics['queues']??[])]);
        $counts=[];$errors=[];
        foreach ($names as $name) {
            try {$counts[$name]=$this->queue->recover(QueueOptions::queue((string)$name),$limit);}
            catch (\Throwable) {$errors[]=$name;}
        }
        return ['ok'=>$errors===[],'recovered'=>array_sum($counts),'queues'=>$counts,'failed_queues'=>$errors];
    }
}
