<?php

declare(strict_types=1);

namespace CajeerEngine\Extension\Runtime;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationLock;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use PDO;

/**
 * Runs extension migrations under the same database-wide schema lock as core migrations.
 * The local JSON backend is strictly a development fallback; it journals an interrupted
 * migration so a non-transactional side effect can never be silently retried.
 */
final class MigrationLedger
{
    private ?PDO $pdo = null;
    private JsonFile $file;
    private string $directory;

    public function __construct(string $root, ConfigRepository $config, ?DatabaseManager $database)
    {
        $this->directory = $root . '/storage/app/extensions';
        $this->file = new JsonFile($this->directory . '/migrations.json');
        try {
            if ($database !== null && $database->tableExists('ce_extension_migrations')) {
                $this->pdo = $database->connection();
            }
        } catch (\Throwable $e) {
            if (!in_array($config->string('app.env'), ['local', 'dev', 'testing'], true)) {
                throw $e;
            }
        }
        if ($this->pdo === null && !in_array($config->string('app.env'), ['local', 'dev', 'testing'], true)) {
            throw new \RuntimeException('Run core migrations before extension migrations');
        }
    }

    /** @return array<string,string> */
    public function applied(string $name): array
    {
        $legacy = $this->file->readObject([])[$name] ?? [];
        if (!is_array($legacy)) throw new \RuntimeException('Invalid extension migration history');
        if ($this->pdo === null) return $legacy;
        if ($legacy !== []) {
            throw new \RuntimeException('Legacy extension migration history requires operator reconciliation before database adoption: ' . $name);
        }
        $query = $this->pdo->prepare('SELECT migration_id, checksum FROM ce_extension_migrations WHERE extension_name = :name');
        $query->execute(['name' => $name]);
        return $query->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function run(string $name, string $id, string $sha, callable $migration, ExtensionContext $context): void
    {
        if (!preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $name)
            || !preg_match('/^[a-zA-Z0-9_.-]{1,190}\.php$/D', $id)
            || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            throw new \InvalidArgumentException('Invalid extension migration identity');
        }
        if ($this->pdo !== null) {
            MigrationLock::run($this->pdo, function () use ($name, $id, $sha, $migration, $context): void {
                $known = $this->applied($name);
                if (isset($known[$id])) {
                    if (!hash_equals((string) $known[$id], $sha)) throw new \RuntimeException('Extension migration checksum mismatch: ' . $id);
                    return; // A second worker completed this migration while we were waiting.
                }
                // MySQL implicitly commits surrounding transactions for most DDL. Record a
                // durable, deliberately invalid checksum *before* invoking extension code.
                // An interrupted/partly-applied change must require manual reconciliation.
                $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
                $inflight = str_repeat('0', 64);
                if ($mysql) {
                    $marker = $this->pdo->prepare('INSERT INTO ce_extension_migrations (extension_name,migration_id,checksum,applied_at) VALUES (:name,:id,:sha,:at)');
                    $marker->execute(['name' => $name, 'id' => $id, 'sha' => $inflight, 'at' => gmdate('Y-m-d H:i:s')]);
                }
                $this->pdo->beginTransaction();
                try {
                    $migration($context, $this->pdo);
                    if (!$this->pdo->inTransaction()) {
                        throw new \RuntimeException('Extension migration implicitly committed its transaction; restore from backup before retry');
                    }
                    if ($mysql) {
                        $query = $this->pdo->prepare('UPDATE ce_extension_migrations SET checksum = :sha, applied_at = :at WHERE extension_name = :name AND migration_id = :id AND checksum = :marker');
                        $query->execute(['sha' => $sha, 'at' => gmdate('Y-m-d H:i:s'), 'name' => $name, 'id' => $id, 'marker' => $inflight]);
                        if ($query->rowCount() !== 1) throw new \RuntimeException('Extension migration completion marker missing');
                    } else {
                        $query = $this->pdo->prepare('INSERT INTO ce_extension_migrations (extension_name,migration_id,checksum,applied_at) VALUES (:name,:id,:sha,:at)');
                        $query->execute(['name' => $name, 'id' => $id, 'sha' => $sha, 'at' => gmdate('Y-m-d H:i:s')]);
                    }
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $e;
                }
            });
            return;
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create migration ledger directory');
        }
        $lockPath = $this->directory . '/migrations.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Unsafe extension migration lock');
        $handle = fopen($lockPath, 'c+b');
        if ($handle === false) throw new \RuntimeException('Cannot open extension migration lock');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot acquire extension migration lock');
            $state = $this->file->readObject([]);
            $old = $state[$name][$id] ?? null;
            if ($old !== null) {
                if (!is_string($old) || !hash_equals($old, $sha)) throw new \RuntimeException('Extension migration checksum mismatch: ' . $id);
                return;
            }
            $pending = new JsonFile($this->directory . '/migrations-inflight.json');
            $inflight = $pending->readObject([]);
            if ($inflight !== []) {
                throw new \RuntimeException('Interrupted extension migration requires operator reconciliation before retry');
            }
            $pending->writeObject(['extension' => $name, 'migration' => $id, 'checksum' => $sha, 'started_at' => gmdate(DATE_ATOM)]);
            // The JSON backend cannot roll back arbitrary plugin effects. Preserve the
            // in-flight marker on failure so an operator must inspect those effects.
            $migration($context, null);
            $state[$name][$id] = $sha;
            $this->file->writeObject($state);
            $pending->writeObject([]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
