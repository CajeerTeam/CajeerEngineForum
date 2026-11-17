<?php

declare(strict_types=1);

namespace CajeerEngine\Database;

use PDO;
use Throwable;

final readonly class MigrationRunner
{
    public function __construct(private PDO $pdo, private string $migrationsPath)
    {
    }

    /** @return array{applied:int,batch:int,migrations:list<array<string,mixed>>} */
    public function run(string $driver): array
    {
        return MigrationLock::run($this->pdo, fn()=>$this->runLocked($driver));
    }

    private function runLocked(string $driver): array
    {
        $driver = $this->normalizeDriver($driver);
        if ($driver !== $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) throw new \InvalidArgumentException('Migration driver does not match PDO connection');
        $this->ensureTable($driver);
        $dir = $this->migrationsPath . '/' . $driver;
        if (!is_dir($dir)) {
            throw new \RuntimeException('Директория миграций не найдена: ' . $dir);
        }

        $batch = $this->nextBatch();
        $applied = $this->applied();
        $count = 0;
        $executed = [];
        $files=$this->files($dir);$knownFiles=[];
        foreach($files as $file){$digest=hash_file('sha256',$file);if($digest===false)throw new \RuntimeException('Cannot hash migration');$knownFiles[basename($file)]=$digest;}
        foreach($applied as $name=>$row){
            if(!isset($knownFiles[$name]))throw new \RuntimeException('Applied migration file missing: '.$name);
            if(($row['status']??'applied')!=='applied') {
                if (($row['status']??'')!=='failed') throw new \RuntimeException('Unknown migration status: '.$name);
                continue;
            }
            if(($row['checksum']??'')!==''&&!hash_equals($row['checksum'],$knownFiles[$name]))throw new \RuntimeException('Applied migration checksum mismatch: '.$name);
        }

        foreach ($files as $file) {
            $name = basename($file);
            $checksum = $knownFiles[$name];

            if (isset($applied[$name])) {
                $status = (string) ($applied[$name]['status'] ?? 'applied');
                $knownChecksum = (string) ($applied[$name]['checksum'] ?? '');
                if ($status === 'applied') {
                    if ($knownChecksum !== '' && $knownChecksum !== $checksum) {
                        throw new \RuntimeException("Checksum миграции изменился после применения: {$name}");
                    }
                    continue;
                }

                if ($status !== 'failed') {
                    throw new \RuntimeException("Неизвестный статус миграции {$name}: {$status}");
                }

                if($driver==='mysql')throw new \RuntimeException('MySQL migration failed or was interrupted; inspect schema and restore backup before migrate:repair: '.$name);
                // Keep the failed ledger row until transactional DDL and the
                // applied status commit together (or the next failure is recorded).
            }

            $startedAt = microtime(true);
            $contents = file_get_contents($file);
            if ($contents === false || !hash_equals($checksum, hash('sha256',$contents))) throw new \RuntimeException('Migration changed during execution: '.$name);
            $sql = trim($contents);
            if($driver==='mysql')$this->insertMigration($name,$checksum,$batch,0,'failed','MySQL DDL started; schema must be inspected if interrupted');
            else $this->pdo->beginTransaction();
            try {
                if ($sql !== '') {
                    if ($driver === 'mysql') $this->executeMySqlMigration($sql);
                    else $this->pdo->exec($sql);
                }
                $this->insertMigration($name, $checksum, $batch, (int) round((microtime(true) - $startedAt) * 1000), 'applied', null);
                if ($this->pdo->inTransaction()) $this->pdo->commit();
                $count++;
                $executed[] = ['migration' => $name, 'checksum' => $checksum, 'batch' => $batch, 'status' => 'applied'];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->insertMigration($name, $checksum, $batch, (int) round((microtime(true) - $startedAt) * 1000), 'failed', $e->getMessage());
                throw $e;
            }
        }

        return ['driver' => $driver, 'applied' => $count, 'batch' => $batch, 'migrations' => $executed];
    }

    /** Read-only verification for readiness and update acceptance. Never creates or alters a ledger. */
    public function verifyApplied(string $driver): array
    {
        $driver = $this->normalizeDriver($driver);
        if ($driver !== $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) throw new \InvalidArgumentException('Migration driver does not match PDO connection');
        $dir = $this->migrationsPath . '/' . $driver;
        if (!is_dir($dir)) throw new \RuntimeException('Migration directory missing');
        $files = $this->files($dir);
        if ($files === []) throw new \RuntimeException('Migration directory empty');
        $ledger = $this->applied();
        $errors = [];
        foreach ($files as $file) {
            $name = basename($file);
            $row = $ledger[$name] ?? null;
            unset($ledger[$name]);
            $checksum = hash_file('sha256', $file);
            if ($row === null) $errors[$name] = 'pending';
            elseif (($row['status'] ?? '') !== 'applied') $errors[$name] = 'failed';
            elseif ($checksum === false || !hash_equals((string) ($row['checksum'] ?? ''), $checksum)) $errors[$name] = 'checksum';
        }
        foreach ($ledger as $name => $row) $errors[$name] = 'missing';
        return ['ok' => $errors === [], 'errors' => $errors, 'expected' => count($files)];
    }

    /** @return array{driver:string,applied:list<array<string,mixed>>,pending:list<array<string,mixed>>,failed:list<array<string,mixed>>} */
    public function status(string $driver): array
    {
        return MigrationLock::run($this->pdo, fn()=>$this->statusLocked($driver));
    }

    private function statusLocked(string $driver): array
    {
        $driver = $this->normalizeDriver($driver);
        if ($driver !== $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) throw new \InvalidArgumentException('Migration driver does not match PDO connection');
        $this->ensureTable($driver);
        $dir = $this->migrationsPath . '/' . $driver;
        if (!is_dir($dir)) {
            throw new \RuntimeException('Директория миграций не найдена: ' . $dir);
        }

        $applied = $this->applied();
        $appliedRows = [];
        $pendingRows = [];
        $failedRows = [];
        $seen = [];

        foreach ($this->files($dir) as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file) ?: '';
            if (!isset($applied[$name])) {
                $pendingRows[] = ['migration' => $name, 'checksum' => $checksum];
                continue;
            }

            $seen[$name] = true;
            $row = $applied[$name];
            $row['current_checksum'] = $checksum;
            $row['checksum_ok'] = ($row['checksum'] ?? '') === '' || ($row['checksum'] ?? '') === $checksum;
            if (($row['status'] ?? 'applied') !== 'applied') {
                $failedRows[] = $row;
            } else {
                $appliedRows[] = $row;
            }
        }

        foreach ($applied as $name=>$row) {
            if (isset($seen[$name])) continue;
            $row['checksum_ok'] = false;
            $row['error'] = 'Migration file missing: '.$name;
            $failedRows[] = $row;
        }
        return [
            'driver' => $driver,
            'applied' => array_values($appliedRows),
            'pending' => array_values($pendingRows),
            'failed' => array_values($failedRows),
        ];
    }


    /** @return array{driver:string,removed:int,removed_migrations:list<string>,rerun?:array<string,mixed>} */
    public function repairFailed(string $driver, bool $runAfterRepair = false): array
    {
        return MigrationLock::run($this->pdo,fn()=>$this->repairLocked($driver,$runAfterRepair));
    }

    private function repairLocked(string $driver, bool $runAfterRepair): array
    {
        $driver = $this->normalizeDriver($driver);
        if ($driver !== $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) throw new \InvalidArgumentException('Migration driver does not match PDO connection');
        $this->ensureTable($driver);
        $status = $this->statusLocked($driver);
        $removed = [];
        foreach ($status['failed'] as $row) {
            $migration = (string) ($row['migration'] ?? '');
            if ($migration === '' || ($row['status'] ?? '') !== 'failed' || !is_file($this->migrationsPath.'/'.$driver.'/'.$migration)) {
                continue;
            }
            $this->deleteFailedMigration($migration);
            $removed[] = $migration;
        }

        $result = [
            'driver' => $driver,
            'removed' => count($removed),
            'removed_migrations' => $removed,
        ];
        if ($runAfterRepair) {
            $result['rerun'] = $this->runLocked($driver);
        }
        return $result;
    }

    private function ensureTable(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ce_migrations (
                migration VARCHAR(190) PRIMARY KEY,
                batch INT NOT NULL DEFAULT 1,
                checksum VARCHAR(64) NOT NULL DEFAULT '',
                execution_ms INT NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT 'applied',
                error TEXT NULL,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            return;
        }

        if ($driver === 'pgsql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ce_migrations (
                migration VARCHAR(190) PRIMARY KEY,
                batch INT NOT NULL DEFAULT 1,
                checksum VARCHAR(64) NOT NULL DEFAULT '',
                execution_ms INT NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT 'applied',
                error TEXT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            foreach ([
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS batch INT NOT NULL DEFAULT 1",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) NOT NULL DEFAULT ''",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS execution_ms INT NOT NULL DEFAULT 0",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'applied'",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS error TEXT NULL",
            ] as $sql) {
                $this->pdo->exec($sql);
            }
            return;
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ce_migrations (
            migration VARCHAR(190) PRIMARY KEY,
            batch INT NOT NULL DEFAULT 1,
            checksum VARCHAR(64) NOT NULL DEFAULT '',
            execution_ms INT NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'applied',
            error TEXT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addColumnIfMissing('ce_migrations', 'batch', 'INT NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('ce_migrations', 'checksum', "VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addColumnIfMissing('ce_migrations', 'execution_ms', 'INT NOT NULL DEFAULT 0');
        $this->addColumnIfMissing('ce_migrations', 'status', "VARCHAR(40) NOT NULL DEFAULT 'applied'");
        $this->addColumnIfMissing('ce_migrations', 'error', 'TEXT NULL');
    }

    /** MySQL 8.4 does not support ADD COLUMN IF NOT EXISTS; MariaDB does.
     * Handle both with a schema lookup while preserving historical .sql bytes
     * and therefore the checksums of already-applied migrations. Execute one
     * statement at a time to avoid reliance on PDO multi-statement settings.
     */
    private function executeMySqlMigration(string $sql): void
    {
        foreach (preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            if (preg_match('/\AALTER\s+TABLE\s+(ce_[a-z0-9_]+)\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+([a-z_][a-z0-9_]*)\s+(.+)\z/isD', $statement, $matches)) {
                $this->addColumnIfMissing($matches[1], $matches[2], $matches[3]);
            } else {
                // Historical CREATE TABLE files sometimes declare a charset but
                // omit its collation. MySQL 8.x and MariaDB then choose different
                // defaults, creating foreign keys incompatible with ce_users.id.
                // Normalize at execution time; do not modify checksummed SQL files.
                if (preg_match('/\ACREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?ce_[a-z0-9_]+\b/i', $statement)
                    && preg_match('/\bDEFAULT\s+CHARSET\s*=\s*utf8mb4\b/i', $statement)
                    && !preg_match('/\bCOLLATE\s*=/i', $statement)) {
                    $statement = preg_replace('/\bDEFAULT\s+CHARSET\s*=\s*utf8mb4\b/i', 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $statement, 1);
                }
                $this->pdo->exec($statement);
            }
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute(['table' => $table, 'column' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function applied(): array
    {
        $rows = $this->pdo->query('SELECT migration, batch, checksum, execution_ms, status, error, applied_at FROM ce_migrations ORDER BY applied_at ASC, migration ASC')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['migration']] = $row;
        }
        return $result;
    }

    private function nextBatch(): int
    {
        $batch = $this->pdo->query('SELECT MAX(batch) FROM ce_migrations')->fetchColumn();
        return ((int) $batch) + 1;
    }

    private function insertMigration(string $migration, string $checksum, int $batch, int $executionMs, string $status, ?string $error): void
    {
        $stmt=$this->pdo->prepare('UPDATE ce_migrations SET batch=:batch,checksum=:checksum,execution_ms=:execution_ms,status=:status,error=:error,applied_at=CURRENT_TIMESTAMP WHERE migration=:migration AND status=\'failed\'');
        $params=['migration'=>$migration,'batch'=>$batch,'checksum'=>$checksum,'execution_ms'=>$executionMs,'status'=>$status,'error'=>$error];
        $stmt->execute($params);
        if($stmt->rowCount()>0)return;
        // MySQL reports 0 when a marker is unchanged; do not insert a duplicate.
        $check=$this->pdo->prepare('SELECT COUNT(*) FROM ce_migrations WHERE migration=:migration AND status=\'failed\'');$check->execute(['migration'=>$migration]);if((int)$check->fetchColumn()>0)return;
        $stmt = $this->pdo->prepare('INSERT INTO ce_migrations (migration, batch, checksum, execution_ms, status, error, applied_at) VALUES (:migration, :batch, :checksum, :execution_ms, :status, :error, CURRENT_TIMESTAMP)');
        $stmt->execute([
            'migration' => $migration,
            'batch' => $batch,
            'checksum' => $checksum,
            'execution_ms' => $executionMs,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function deleteFailedMigration(string $migration): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ce_migrations WHERE migration = :migration AND status = :status');
        $stmt->execute(['migration' => $migration, 'status' => 'failed']);
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = strtolower($driver);
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
