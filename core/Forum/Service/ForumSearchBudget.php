<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Forum\ForumException;
use PDO;
use PDOException;

/** Bound expensive PostgreSQL search statements without changing pooled session settings. */
final class ForumSearchBudget
{
    public static function milliseconds(): int
    {
        $raw=getenv('FORUM_SEARCH_TIMEOUT_MS');
        return $raw!==false && preg_match('/\A[0-9]{1,5}\z/D',$raw) ? max(100,min(30000,(int)$raw)) : 3000;
    }

    public static function run(DatabaseManager $db,callable $load): mixed
    {
        // A caller-owned transaction is not ours to abort or reconfigure.
        if($db->connection()->inTransaction())return $load();
        $pg=$db->driver()==='pgsql';
        try {
            return $db->readSnapshot(function(PDO $pdo)use($load,$pg){
                if($pg){
                    // pg_settings reports statement_timeout in milliseconds. Respect a stricter DBA setting.
                    $configured=(int)$pdo->query("SELECT setting FROM pg_settings WHERE name='statement_timeout'")->fetchColumn();
                    $timeout=self::milliseconds();
                    if($configured>0)$timeout=min($configured,$timeout);
                    $stmt=$pdo->prepare("SELECT set_config('statement_timeout', ?, true)");
                    $stmt->execute([$timeout.'ms']);$stmt->fetchColumn();
                }
                return $load();
            });
        } catch(PDOException $e){
            if($pg && (string)($e->errorInfo[0]??$e->getCode())==='57014'){
                throw new ForumException('Поиск временно не завершён. Уточните запрос или повторите позже.',503);
            }
            throw $e;
        }
    }
}
