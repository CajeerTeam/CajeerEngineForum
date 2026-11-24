<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

final readonly class QueueFactory
{
    public function __construct(private string $rootPath, private ConfigRepository $config, private ?DatabaseManager $database = null)
    {
    }

    public function make(): ManagedQueueDriverInterface
    {
        $driver = strtolower($this->config->string('queue.default', 'file'));
        $lease = QueueOptions::lease($this->config->int('queue.workers.lease_seconds', 300));
        return match ($driver) {
            'redis' => $this->redis(),
            'pgsql', 'postgres', 'postgresql', 'database' => $this->postgres($lease),
            'rabbitmq', 'amqp' => throw new \RuntimeException('RabbitMQ reliability is not supported; drain legacy queue before switching to file/pgsql/redis'),
            'file' => new FileQueueDriver($this->rootPath, $lease),
            default => throw new \RuntimeException('Unsupported queue driver: '.$driver),
        };
    }

    private function postgres(int $lease): PostgresQueueDriver
    {
        $db=$this->database();if($db->driver()!=='pgsql')throw new \RuntimeException('PostgreSQL queue requires DB_DRIVER=pgsql');
        return new PostgresQueueDriver($db->connection(),$lease);
    }

    private function redis(): RedisQueueDriver
    {
        $redis = \CajeerEngine\Cache\RedisConnection::open($this->rootPath);
        return new RedisQueueDriver($redis, $this->config->string('queue.connections.redis.prefix','ce:queue:'), $this->config->int('queue.workers.lease_seconds', 300),$this->rootPath);
    }

    private function database(): DatabaseManager
    {
        return $this->database ?? new DatabaseManager($this->config);
    }
}
