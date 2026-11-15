<?php

declare(strict_types=1);
use CajeerEngine\Extension\Runtime\ExtensionContext;
return static function(ExtensionContext $context,?PDO $pdo): void {
    if($pdo===null)throw new RuntimeException('Forum Announcements requires the database');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ce_ext_forum_announcements (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, title VARCHAR(240) NOT NULL, body TEXT NOT NULL, starts_at BIGINT NOT NULL, ends_at BIGINT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ce_ext_forum_announcement_receipts (event_id VARCHAR(32) PRIMARY KEY, event_name VARCHAR(80) NOT NULL, occurred_at BIGINT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ce_ext_forum_announcement_counts (id INTEGER PRIMARY KEY, topics BIGINT NOT NULL, replies BIGINT NOT NULL)');
    if(!$pdo->query('SELECT id FROM ce_ext_forum_announcements WHERE id=1')->fetchColumn())$pdo->exec("INSERT INTO ce_ext_forum_announcements VALUES (1,0,'','',0,0)");
    if(!$pdo->query('SELECT id FROM ce_ext_forum_announcement_counts WHERE id=1')->fetchColumn())$pdo->exec('INSERT INTO ce_ext_forum_announcement_counts VALUES (1,0,0)');
};
