<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Repository;

use CajeerEngine\Database\DatabaseManager;
use PDO;
use PDOStatement;

abstract readonly class Repository
{
    public function __construct(protected DatabaseManager $db) {}

    protected function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->db->connection()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    protected function rows(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function row(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Only server-resolved UUIDs; quote with the connection, not caller SQL. */
    protected function boardFilter(string $column, ?array $ids): string
    {
        if ($ids===null) return '1=1';
        if ($ids===[]) return '1=0';
        return $column.' IN ('.implode(',',array_map(fn(string $id)=>$this->db->connection()->quote($id),$ids)).')';
    }

    // All lock calls are made inside a transaction. SQLite must obtain its writer
    // reservation before reading, otherwise a deferred snapshot can fail to upgrade.
    protected function lock(string $table, string $predicate, array $params): ?array
    {
        if (!$this->db->connection()->inTransaction()) throw new \LogicException('Forum lock requires a transaction');
        if ($this->db->driver() === 'sqlite') {
            $this->query("UPDATE $table SET id=id WHERE $predicate", $params);
            return $this->row("SELECT * FROM $table WHERE $predicate", $params);
        }
        return $this->row("SELECT * FROM $table WHERE $predicate FOR UPDATE", $params);
    }
}
