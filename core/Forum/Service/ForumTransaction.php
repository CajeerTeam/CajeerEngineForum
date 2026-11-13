<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Forum\ForumException;
use PDOException;

final readonly class ForumTransaction
{
    public function __construct(private DatabaseManager $db, private AuditLogRepository $audit) {}

    /** Run mutations and audit on the same connection; exceptions roll both back. */
    public function write(callable $operation): mixed
    {
        try {
            return $this->db->transaction($operation);
        } catch (PDOException $e) {
            $state = (string) $e->getCode();
            $native = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($state, ['40001','40P01','55P03'], true)
                || ($this->db->driver() === 'sqlite' && in_array($native & 255, [5,6], true))
                || ($this->db->driver() === 'mysql' && in_array($native, [1205,1213], true))) {
                throw new ForumException('Конфликт параллельных изменений. Повторите запрос.', 409);
            }
            throw $e;
        }
    }

    public function audit(string $event, string $actor, array $context): void
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Forum audit requires a transaction');
        $this->audit->record($event, $actor, $context);
    }
}
