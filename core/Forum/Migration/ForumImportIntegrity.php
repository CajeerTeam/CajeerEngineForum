<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Migration;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/** SQL-backed reconciliation of a quarantined import. No source DB writes, and no public access. */
final class ForumImportIntegrity
{
    private static function count(PDO $pdo,string $sql,array $args=[]):int
    {
        $statement=$pdo->prepare($sql);
        $statement->execute($args);
        return (int)$statement->fetchColumn();
    }

    /** Fail before publishing or completing a migration with broken privacy or ownership. */
    public static function assertSafe(DatabaseManager $db,string $jobId):void
    {
        $report=self::inspect($db,$jobId);
        if(!$report['ok'])throw new \RuntimeException('Import integrity failed: '.implode(', ',$report['violations']));
    }

    /** Incomplete jobs can be inspected without changing the checkpoint or promoting content. */
    public static function inspect(DatabaseManager $db,string $jobId,bool $deepFiles=false):array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$jobId))throw new \InvalidArgumentException('Invalid import job ID');
        $pdo=$db->connection();
        $stmt=$pdo->prepare('SELECT id,state,category_id,phase,plan_json FROM ce_forum_import_jobs WHERE id=?');
        $stmt->execute([$jobId]);$job=$stmt->fetch(PDO::FETCH_ASSOC);
        if($job===false)throw new \RuntimeException('Import job not found');
        $plan=json_decode((string)$job['plan_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($plan))throw new \RuntimeException('Malformed import plan');
        $target=$db->driver()==='pgsql'?'CAST(m.target_id AS UUID)':'m.target_id';
        // Keep the indexed varchar mapping key on the left for PostgreSQL UUID joins.
        $participantUser=$db->driver()==='pgsql'?'CAST(p.user_id AS VARCHAR(36))':'p.user_id';
        $issues=[];$metrics=[];
        $check=static function(string $name,string $sql,array $args=[])use($pdo,&$issues,&$metrics):void{
            $count=self::count($pdo,$sql,$args);$metrics[$name]=$count;
            if($count>0)$issues[]=$name.'='.$count;
        };
        $check('missing_or_visible_quarantine',
            "SELECT COUNT(*) FROM ce_forum_import_jobs j LEFT JOIN ce_forum_categories c ON c.id=j.category_id WHERE j.id=? AND (c.id IS NULL OR c.visibility<>'hidden')",[$jobId]);
        $check('enabled_imported_accounts',
            "SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_users u ON u.id=$target WHERE m.job_id=? AND m.kind='users' AND m.outcome='imported' AND (u.status<>'disabled' OR u.disabled_at IS NULL)",[$jobId]);
        $check('exposed_imported_boards',
            "SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_boards b ON b.id=$target WHERE m.job_id=? AND m.kind='forums' AND m.outcome='imported' AND (b.category_id<>? OR b.visibility<>'hidden' OR b.state<>'readonly')",[$jobId,$job['category_id']]);
        $check('threads_outside_quarantine',
            "SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_threads t ON t.id=$target LEFT JOIN ce_forum_boards b ON b.id=t.board_id WHERE m.job_id=? AND m.kind='topics' AND m.outcome='imported' AND (b.id IS NULL OR b.category_id<>?)",[$jobId,$job['category_id']]);
        $check('posts_outside_quarantine',
            "SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_posts p ON p.id=$target LEFT JOIN ce_forum_threads t ON t.id=p.thread_id LEFT JOIN ce_forum_boards b ON b.id=t.board_id WHERE m.job_id=? AND m.kind='posts' AND m.outcome='imported' AND (b.id IS NULL OR b.category_id<>?)",[$jobId,$job['category_id']]);
        // Both IPS and XenForo must keep only active, mapped recipients and the owner.
        foreach(['inv_conversations','conversations'] as $kind){
            $check($kind.'_unsafe_audience',
                "SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_conversations c ON c.id=$target WHERE m.job_id=? AND m.kind=? AND m.outcome='imported' AND (\n                   c.owner_id IS NULL OR (SELECT COUNT(*) FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND p.active=1)<1 OR\n                   NOT EXISTS (SELECT 1 FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND p.user_id=c.owner_id AND p.active=1) OR\n                   EXISTS (SELECT 1 FROM ce_forum_conversation_participants p WHERE p.conversation_id=c.id AND (p.active<>1 OR NOT EXISTS\n                       (SELECT 1 FROM ce_forum_import_map u WHERE u.job_id=m.job_id AND u.kind='users' AND u.outcome='imported' AND u.target_id=$participantUser))))",[$jobId,$kind]);
        }
        foreach(['inv_messages','conversation_messages'] as $kind){
            $check($kind.'_unauthorized_author',
                "SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_conversation_messages x ON x.id=$target LEFT JOIN ce_forum_conversation_participants p ON p.conversation_id=x.conversation_id AND p.user_id=x.author_id AND p.active=1 WHERE m.job_id=? AND m.kind=? AND m.outcome='imported' AND p.user_id IS NULL",[$jobId,$kind]);
        }
        foreach(['attachments','conversation_attachments'] as $kind){
            $check($kind.'_unsafe_ownership',
                "SELECT COUNT(*) FROM ce_forum_import_map m JOIN ce_forum_attachments a ON a.id=$target LEFT JOIN ce_forum_posts p ON p.id=a.post_id LEFT JOIN ce_forum_conversation_messages x ON x.id=a.message_id WHERE m.job_id=? AND m.kind=? AND m.outcome='imported' AND (\n                    (a.post_id IS NULL AND a.message_id IS NULL) OR\n                    (a.post_id IS NOT NULL AND a.message_id IS NOT NULL) OR\n                    (a.post_id IS NOT NULL AND (p.id IS NULL OR p.author_id<>a.owner_id)) OR\n                    (a.message_id IS NOT NULL AND (x.id IS NULL OR x.author_id<>a.owner_id)) OR\n                    a.expires_at<>0)",[$jobId,$kind]);
        }
        $filesChecked=0;
        if($deepFiles){
            foreach(['attachments','conversation_attachments'] as $kind){
                $after=-1;
                // Read at most one base64 blob into memory. This remains bounded for large imports.
                $sql="SELECT m.source_id,a.id,a.file_data,a.size_bytes,a.sha256 FROM ce_forum_import_map m JOIN ce_forum_attachments a ON a.id=$target WHERE m.job_id=? AND m.kind=? AND m.outcome='imported' AND m.source_id>? ORDER BY m.source_id LIMIT 1";
                $query=$pdo->prepare($sql);
                while(true){
                    $query->execute([$jobId,$kind,$after]);$row=$query->fetch(PDO::FETCH_ASSOC);$query->closeCursor();
                    if($row===false)break;
                    $after=(int)$row['source_id'];$filesChecked++;
                    $raw=base64_decode((string)$row['file_data'],true);
                    if($raw===false || strlen($raw)!==(int)$row['size_bytes'] || !hash_equals(strtolower((string)$row['sha256']),hash('sha256',$raw)))
                        $issues[]='attachment_checksum_failure:'.$kind.':'.$after;
                }
            }
        }
        return ['ok'=>$issues===[],'complete'=>$job['state']==='complete','job'=>$jobId,
            'pipeline_version'=>$plan['pipeline_version']??1,'violations'=>$issues,'counts'=>$metrics,
            'files_verified'=>$filesChecked,'deep_files'=>$deepFiles];
    }
}
