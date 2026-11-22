<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use PDO;

/** Validate the *actual* frozen XenForo 2.2/2.3 database before importing anything. */
final class XenForoSchema
{
    /** Every selected source field, including the relation scans used after the primary phases. */
    private const REQUIRED = [
        'user_group' => ['user_group_id', 'title'],
        'user' => ['user_id', 'username', 'email', 'register_date', 'user_group_id', 'secondary_group_ids'],
        'node' => ['node_id', 'parent_node_id', 'display_order', 'node_type_id', 'title', 'description'],
        'thread' => ['thread_id', 'node_id', 'user_id', 'title', 'post_date', 'last_post_date', 'discussion_type', 'discussion_open', 'sticky', 'discussion_state'],
        'post' => ['post_id', 'thread_id', 'user_id', 'message', 'post_date', 'last_edit_date', 'message_state'],
        'attachment' => ['attachment_id', 'content_id', 'content_type', 'data_id', 'unassociated', 'attach_date'],
        'attachment_data' => ['data_id', 'user_id', 'filename', 'file_size'],
        'poll' => ['poll_id', 'content_id', 'content_type', 'question', 'close_date', 'max_votes'],
        'poll_response' => ['poll_response_id', 'poll_id', 'response'],
        'poll_vote' => ['poll_response_id', 'user_id'],
        'thread_watch' => ['thread_id', 'user_id'],
        'forum_watch' => ['node_id', 'user_id'],
        'user_profile' => ['user_id', 'about', 'location', 'website', 'signature'],
        'reaction_content' => ['reaction_content_id', 'content_type', 'content_id', 'reaction_user_id', 'reaction_id'],
        'conversation_message' => [],
    ];

    private array $columns = [];
    private string $attachmentKey;
    private bool $is23;

    public function __construct(private PDO $pdo, private string $prefix, string $version)
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,40}\z/D', $prefix)
            || !preg_match('/\A2\.[23]\.\d+\z/D', $version)) {
            throw new \InvalidArgumentException('Invalid XenForo source prefix or version');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('XenForo source requires MySQL/MariaDB or SQLite');
        }
        foreach (self::REQUIRED as $table => $required) {
            $name = $prefix . $table;
            $found = $this->readColumns($driver, $name);
            if ($found === []) {
                throw new \RuntimeException("XenForo source preflight: missing table $name (check snapshot/prefix)");
            }
            $missing = array_diff($required, $found);
            if ($missing !== []) {
                throw new \RuntimeException("XenForo source preflight: $name missing required columns " . implode(', ', $missing));
            }
            sort($found, SORT_STRING);
            $this->columns[$table] = $found;
        }
        $keys = $this->columns['attachment_data'];
        $this->is23 = str_starts_with($version, '2.3.');
        if ($this->is23) {
            if (!in_array('file_key', $keys, true)) {
                throw new \RuntimeException('XenForo 2.3 source preflight: xf_attachment_data.file_key is required; source/version mismatch');
            }
            $this->attachmentKey = 'file_key';
        } elseif (in_array('file_key', $keys, true)) {
            // An upgraded or mixed 2.2 snapshot can contain both fields. Prefer
            // the stable file key, falling back only when the field is empty.
            $this->attachmentKey = 'file_key';
        } elseif (in_array('file_hash', $keys, true)) {
            $this->attachmentKey = 'file_hash';
        } else {
            throw new \RuntimeException('XenForo source preflight: attachment_data requires file_hash or file_key');
        }
    }

    /** Never derive identifiers from source data: only hardcoded, probed column names. */
    public function attachmentKeySql(string $alias = 'd'): string
    {
        if (!$this->is23 && $this->attachmentKey === 'file_key'
            && in_array('file_hash', $this->columns['attachment_data'], true)) {
            return "CASE WHEN $alias.file_key IS NULL OR $alias.file_key='' THEN $alias.file_hash ELSE $alias.file_key END";
        }
        return $alias . '.' . $this->attachmentKey;
    }

    public function attachmentKeyColumn(): string { return $this->attachmentKey; }

    /** Stable across reconnects; catches a changed table layout on job resume. */
    public function fingerprint(): string
    {
        ksort($this->columns, SORT_STRING);
        return hash('sha256', json_encode($this->columns, JSON_THROW_ON_ERROR));
    }

    private function readColumns(string $driver, string $name): array
    {
        if ($driver === 'mysql') {
            $statement = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $statement->execute([$name]);
            return array_map('strtolower', $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        // SQLite PRAGMA table_info(<identifier>) does not accept a bind parameter.
        // The identifier is already validated in the constructor.
        $statement = $this->pdo->query('PRAGMA table_info("' . $name . '")');
        return array_map('strtolower', array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'name'));
    }
}
