<?php

declare(strict_types=1);

namespace CajeerEngine\Database;

use PDO;

/** Executes the published SQL migrations without relying on PDO multi-statement mode.
 *
 * MySQL 8.4 does not accept ADD COLUMN IF NOT EXISTS (MariaDB does). Historic
 * migrations 0005/0007 use it and must retain their original SHA-256 digests.
 * Inspecting information_schema lets both servers apply the same immutable SQL.
 */
final class MigrationSqlExecutor
{
    public static function execute(PDO $pdo, string $driver, string $sql): void
    {
        foreach (self::statements($sql) as $statement) {
            if ($driver === 'mysql') {
                $statement = self::mysqlConditionalColumn($pdo, $statement);
                if ($statement === null) {
                    continue;
                }
                $statement = self::mysqlHistoricalForeignKeys($pdo, $statement);
            }
            $pdo->exec($statement);
        }
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $result = [];
        $buffer = '';
        $state = 'normal';
        $length = strlen($sql);
        $dollar = null;
        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($dollar !== null) {
                if (substr_compare($sql, $dollar, $i, strlen($dollar)) === 0) {
                    $buffer .= $dollar;
                    $i += strlen($dollar) - 1;
                    $dollar = null;
                } else {
                    $buffer .= $ch;
                }
                continue;
            }
            if ($state === 'line') {
                if ($ch === "\n" || $ch === "\r") {
                    $state = 'normal';
                    $buffer .= ' ';
                }
                continue;
            }
            if ($state === 'block') {
                if ($ch === '*' && $next === '/') {
                    $state = 'normal';
                    $i++;
                    $buffer .= ' ';
                }
                continue;
            }
            if ($state !== 'normal') {
                $buffer .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                } elseif ($ch === $state) {
                    if ($next === $state) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $state = 'normal';
                    }
                }
                continue;
            }
            if ($ch === '-' && $next === '-' && ($i + 2 === $length || ctype_space($sql[$i + 2]))) {
                $state = 'line';
                $i++;
                continue;
            }
            if ($ch === '#') {
                $state = 'line';
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $state = 'block';
                $i++;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $state = $ch;
                $buffer .= $ch;
                continue;
            }
            // PostgreSQL function bodies can contain arbitrary semicolons and comments.
            if ($ch === '$' && ($i === 0 || !preg_match('/[a-zA-Z0-9_$\x80-\xff]/', $sql[$i - 1]))
                && preg_match('/\G\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$/', $sql, $match, 0, $i)) {
                $dollar = $match[0];
                $buffer .= $dollar;
                $i += strlen($dollar) - 1;
                continue;
            }
            if ($ch === ';') {
                // SQLite triggers are one statement, including their BEGIN ... END body.
                if (preg_match('/^\s*CREATE\s+(?:(?:TEMP|TEMPORARY)\s+)?TRIGGER\b.*\bBEGIN\b/is', $buffer)
                    && !preg_match('/;\s*END\s*$/iD', $buffer)) {
                    $buffer .= ';';
                    continue;
                }
                if (trim($buffer) !== '') {
                    $result[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if ($dollar !== null || ($state !== 'normal' && $state !== 'line')) {
            throw new \RuntimeException('Unterminated SQL string/comment in migration');
        }
        if (trim($buffer) !== '') {
            $result[] = trim($buffer);
        }
        return $result;
    }

    /**
     * Historical SQL must retain its checksums. Match the referenced columns
     * when a migration inherited a different server/database default collation.
     */
    private static function mysqlHistoricalForeignKeys(PDO $pdo, string $sql): string
    {
        if (preg_match('/^CREATE\s+TABLE\s+ce_identity_challenges\s*\(/i', $sql) === 1) {
            $references = ['user_id' => 'ce_users'];
        } elseif (preg_match('/^CREATE\s+TABLE\s+ce_ai_tool_calls\s*\(/i', $sql) === 1) {
            $references = ['conversation_id' => 'ce_ai_conversations', 'assistant_message_id' => 'ce_ai_messages'];
        } else {
            return $sql;
        }
        $metadata = $pdo->prepare('SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
        foreach ($references as $column => $parent) {
            $metadata->execute(['table' => $parent, 'column' => 'id']);
            $definition = $metadata->fetch(PDO::FETCH_ASSOC);
            $metadata->closeCursor();
            $type = is_array($definition) ? strtolower((string) ($definition['COLUMN_TYPE'] ?? '')) : '';
            $charset = is_array($definition) ? (string) ($definition['CHARACTER_SET_NAME'] ?? '') : '';
            $collation = is_array($definition) ? (string) ($definition['COLLATION_NAME'] ?? '') : '';
            if (preg_match('/^(?:char|varchar)\(36\)$/D', $type) !== 1
                || preg_match('/^[a-zA-Z0-9_]+$/D', $charset) !== 1
                || preg_match('/^[a-zA-Z0-9_]+$/D', $collation) !== 1) {
                throw new \RuntimeException('Cannot resolve compatible foreign key metadata for ' . $parent . '.id');
            }
            $columnType = strtoupper($type) . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation;
            $sql = preg_replace_callback(
                '/(^|\n)([ \t]*' . $column . '\s+)(?:VAR)?CHAR\s*\(\s*36\s*\)(\s+NOT\s+NULL)/i',
                static fn (array $match): string => $match[1] . $match[2] . $columnType . $match[3],
                $sql,
                1,
                $replacements,
            );
            if ($sql === null || $replacements !== 1) {
                throw new \RuntimeException('Unexpected foreign key definition for ' . $column);
            }
        }
        return $sql;
    }

    private static function mysqlConditionalColumn(PDO $pdo, string $sql): ?string
    {
        if (!preg_match('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i', $sql)) {
            return $sql;
        }
        if (!preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+(.+)$/is', $sql, $matches)) {
            throw new \RuntimeException('Unsupported conditional MySQL migration statement');
        }
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $check->execute(['table' => $matches[1], 'column' => $matches[2]]);
        if ((int) $check->fetchColumn() > 0) {
            return null;
        }
        return sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $matches[1], $matches[2], $matches[3]);
    }
}
