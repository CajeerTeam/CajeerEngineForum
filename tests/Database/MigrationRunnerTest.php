<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\Database;

use CajeerEngine\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/** No database connection is needed to test the exact MySQL DDL adapter. */
final class RecordingMySqlPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];
    public function __construct() {}
    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        return 0;
    }
}

final class MigrationRunnerTest extends TestCase
{
    public function testFailedMigrationCanBeRepairedAndRetried(): void
    {
        $dir = sys_get_temp_dir() . '/ce-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir . '/sqlite', 0777, true);
        file_put_contents($dir . '/sqlite/0001_init.sql', 'CREATE TABLE example (id INT PRIMARY KEY);');
        $pdo = new PDO('sqlite::memory:');
        $runner = new MigrationRunner($pdo, $dir);
        $result = $runner->run('sqlite');
        self::assertSame(1, $result['applied']);
        $status = $runner->status('sqlite');
        self::assertSame(0, count($status['failed']));
    }

    public function testMySqlHistoricalForeignKeysUseCompatibleCollationWithoutChangingFiles(): void
    {
        $pdo = new RecordingMySqlPdo();
        $runner = new MigrationRunner($pdo, '/nonexistent');
        $method = new \ReflectionMethod(MigrationRunner::class, 'executeMySqlMigration');
        $method->invoke($runner, "CREATE TABLE ce_identity_challenges (user_id CHAR(36) NOT NULL, FOREIGN KEY (user_id) REFERENCES ce_users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n");
        self::assertCount(1, $pdo->statements);
        self::assertStringEndsWith('DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $pdo->statements[0]);

        $method->invoke($runner, "CREATE TABLE ce_example (id CHAR(36)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n");
        self::assertSame(1, substr_count($pdo->statements[1], 'COLLATE=utf8mb4_unicode_ci'));

        $method->invoke($runner, 'UPDATE ce_users SET name = name;');
        self::assertSame('UPDATE ce_users SET name = name', $pdo->statements[2]);
    }

}
