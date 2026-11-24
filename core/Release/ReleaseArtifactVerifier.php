<?php

declare(strict_types=1);
namespace CajeerEngine\Release;

/** Validates the ZIP itself, not the working tree or an untrusted sidecar. */
final class ReleaseArtifactVerifier
{
    public function verify(string $path, string $mode, string $version): array
    {
        $path = realpath($path) ?: throw new \RuntimeException('Artifact does not exist');
        $zip = new \PharData($path);
        $prefix = 'cajeerengine/';
        $manifestName = $prefix . 'release-manifest.json';
        if (!isset($zip[$manifestName]) || $zip[$manifestName]->getSize() > 16 * 1024 * 1024) throw new \RuntimeException('Missing or oversized release manifest');
        $manifest = json_decode($zip[$manifestName]->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? '') !== 'cajeerengine.release-manifest.v5' || ($manifest['project'] ?? '') !== 'cajeerengine' || ($manifest['mode'] ?? '') !== $mode || ($manifest['version'] ?? '') !== $version) throw new \RuntimeException('Invalid release manifest identity');
        $files = $manifest['files'] ?? null;
        $checksums = $manifest['checksums'] ?? null;
        if (!is_array($files) || !array_is_list($files) || !is_array($checksums) || count($files) !== count($checksums) || count($files) !== count(array_unique($files))) throw new \RuntimeException('Invalid release inventory');
        $actual = [];
        // ZipArchive exposes the raw central directory, including duplicate names.
        if (class_exists(\ZipArchive::class)) {
            $raw = new \ZipArchive();
            if ($raw->open($path, \ZipArchive::CHECKCONS) !== true) throw new \RuntimeException('Invalid ZIP');
            try {
                for ($i = 0; $i < $raw->numFiles; $i++) {
                    $name = $raw->getNameIndex($i);
                    if (!is_string($name) || isset($actual[$name])) throw new \RuntimeException('Duplicate ZIP entry');
                    $actual[$name] = true;
                    if ($raw->getExternalAttributesIndex($i, $os, $attr) && $os === 3 && !in_array((($attr >> 16) & 0170000),[0,0100000],true)) throw new \RuntimeException('ZIP nonregular entry');
                }
            } finally { $raw->close(); }
        } else {
            // PharData can hide duplicate names; use the raw ZIP listing when ext-zip is unavailable.
            $process = proc_open(['unzip', '-Z1', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new \RuntimeException('ZIP verification requires ext-zip or unzip');
            $list = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new \RuntimeException('Invalid ZIP: ' . $error);
            foreach (explode("\n", rtrim($list, "\n")) as $name) {
                if (isset($actual[$name])) throw new \RuntimeException('Duplicate ZIP entry');
                $actual[$name] = true;
            }
            $process=proc_open(['unzip','-Z','-l',$path],[1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
            if (!is_resource($process)) throw new \RuntimeException('Cannot inspect ZIP attributes');
            $attributes=stream_get_contents($pipes[1]); fclose($pipes[1]);
            if (proc_close($process)!==0||preg_match('/^[dlbcps][rwxStTs-]{9}\s/m',$attributes)) throw new \RuntimeException('ZIP nonregular entry');
        }
        unset($actual[$manifestName]);
        $total = 0;
        foreach ($files as $file) {
            if (!is_string($file) || !ReleaseFilePolicy::allows($file, $mode) || !isset($actual[$prefix . $file])) throw new \RuntimeException('Forbidden or missing release file: ' . (string) $file);
            $expected = $checksums[$file] ?? null;
            $entry = $zip[$prefix . $file];
            $size = $entry->getSize();
            $total += $size;
            if ($size > 256 * 1024 * 1024 || $total > 2 * 1024 * 1024 * 1024) throw new \RuntimeException('Release exceeds safe extraction size');
            $digest = hash_file('sha256', 'phar://' . $path . '/' . $prefix . $file);
            if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected) || !is_string($digest) || !hash_equals($expected, $digest)) throw new \RuntimeException('Checksum mismatch: ' . $file);
            if (in_array($file, ReleaseFilePolicy::KEEP, true) && $entry->getSize() !== 0) throw new \RuntimeException('Nonempty runtime marker');
            unset($actual[$prefix . $file]);
        }
        if ($actual !== []) throw new \RuntimeException('Unlisted ZIP entries: ' . implode(', ', array_keys($actual)));
        $upstream = $version;
        if (($manifest['edition'] ?? null) === 'forum' && isset($manifest['upstream_version'])) {
            $upstream = \CajeerEngine\Forum\ForumVersion::validate($manifest['upstream_version']);
            $release = json_decode($zip[$prefix . 'release.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (($release['edition'] ?? null) !== 'forum' || ($release['edition_version'] ?? null) !== $version || ($release['upstream_version'] ?? null) !== $upstream || ($release['version'] ?? null) !== $upstream) throw new \RuntimeException('Forum release identity mismatch');
        }
        $required = array_merge(['VERSION', 'release.json', 'composer.json', '.env.example', 'bin/cajeer', 'public/index.php', 'public/install/index.php', 'public/upgrade/index.php', 'public/upgrade/upgrade.js', 'tools/update-postcheck.php', 'tools/update-recovery.php', 'core/Backup/BackupManager.php', 'core/Update/UpdateManager.php', 'public/admin/index.html', 'public/admin/assets/admin-app.js', 'public/admin/assets/admin-app.css', 'public/admin/assets/admin-manifest.json'], ReleaseFilePolicy::KEEP);
        if (($manifest['edition'] ?? null) === 'forum' && isset($manifest['upstream_version'])) $required = array_merge($required, ['core/Forum/ForumVersion.php','core/Forum/ForumController.php','core/Forum/Service/DiscussionService.php','core/Forum/Service/ForumTransaction.php','public/forum-assets/index.html','public/forum-assets/forum.js','public/forum-assets/forum.css']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.6.3','>=')) $required = array_merge($required,['core/Forum/ForumPageRenderer.php','core/Forum/ForumSeoSettings.php','core/Forum/ForumSitemapPage.php','core/Forum/Repository/ForumSitemapRepository.php','migrations/sqlite/0041_forum_seo_policy.sql','migrations/pgsql/0041_forum_seo_policy.sql','migrations/mysql/0041_forum_seo_policy.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.6.4','>=')) $required = array_merge($required,['core/Cache/RedisConnection.php','core/Forum/Service/ForumRuntimeCache.php','core/Forum/Service/ForumCounterCache.php','core/Forum/Service/ForumRuntimeJobs.php','migrations/sqlite/0042_forum_cache_generation.sql','migrations/pgsql/0042_forum_cache_generation.sql','migrations/mysql/0042_forum_cache_generation.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.7.3','>=')) $required = array_merge($required,['core/Forum/ForumTheme.php','core/Forum/Service/ForumThemeService.php','migrations/sqlite/0045_forum_themes.sql','migrations/pgsql/0045_forum_themes.sql','migrations/mysql/0045_forum_themes.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.7.4','>=')) $required = array_merge($required,['core/Forum/Service/ForumExtensionOutbox.php','core/Forum/Extension/ForumExtension.php','migrations/sqlite/0046_forum_extension_events.sql','migrations/pgsql/0046_forum_extension_events.sql','migrations/mysql/0046_forum_extension_events.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.7.5','>=')) $required = array_merge($required,['core/Forum/Migration/ForumMigrationEngine.php','core/Forum/Migration/PhpbbSource.php','core/Forum/Migration/ForumMigrationCli.php','core/Forum/Migration/PhpbbText.php','migrations/sqlite/0047_forum_migration.sql','migrations/pgsql/0047_forum_migration.sql','migrations/mysql/0047_forum_migration.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.8.0','>=')) $required = array_merge($required,['core/Forum/Service/ForumEcosystemCheck.php']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.7.7','>=')) $required = array_merge($required,['core/Forum/Migration/LegacyForumUrl.php']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.7.6','>=')) $required = array_merge($required,['core/Forum/Migration/ForumSource.php','core/Forum/Migration/MultiForumSource.php']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.7.2','>=')) $required = array_merge($required,['core/Forum/Service/ForumCommunitySettings.php','migrations/sqlite/0044_forum_community_settings.sql','migrations/pgsql/0044_forum_community_settings.sql','migrations/mysql/0044_forum_community_settings.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.6.5','>=')) $required = array_merge($required,['core/Forum/Validation/ForumFeedCursor.php','core/Forum/Service/ForumPerformanceCheck.php','migrations/sqlite/0043_forum_performance.sql','migrations/pgsql/0043_forum_performance.sql','migrations/mysql/0043_forum_performance.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.2.1','>=')) $required = array_merge($required,['core/Forum/Service/NodeService.php','core/Forum/Repository/BoardRepository.php']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.2.2','>=')) $required = array_merge($required,['core/Forum/Service/NodePermissionService.php','core/Forum/Security/PermissionResolver.php','core/Forum/Repository/PermissionRepository.php','migrations/sqlite/0019_forum_node_permissions.sql','migrations/pgsql/0019_forum_node_permissions.sql','migrations/mysql/0019_forum_node_permissions.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.2.3','>=')) $required = array_merge($required,['core/Forum/Service/GroupService.php','core/Forum/Repository/GroupRepository.php','migrations/sqlite/0020_forum_groups.sql','migrations/pgsql/0020_forum_groups.sql','migrations/mysql/0020_forum_groups.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.2.4','>=')) $required = array_merge($required,['core/Forum/Service/DiscussionManagementService.php','core/Forum/Repository/DiscussionManagementRepository.php','migrations/sqlite/0021_forum_discussion_management.sql','migrations/pgsql/0021_forum_discussion_management.sql','migrations/mysql/0021_forum_discussion_management.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.4.1','>=')) $required = array_merge($required,['core/Forum/Content/ForumContentRenderer.php','migrations/sqlite/0028_forum_rich_content.sql','migrations/pgsql/0028_forum_rich_content.sql','migrations/mysql/0028_forum_rich_content.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.4.2','>=')) $required = array_merge($required,['core/Forum/Service/AttachmentService.php','core/Forum/Repository/AttachmentRepository.php','migrations/sqlite/0029_forum_attachments.sql','migrations/pgsql/0029_forum_attachments.sql','migrations/mysql/0029_forum_attachments.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.4.3','>=')) $required = array_merge($required,['core/Forum/Content/ForumImageProcessor.php','core/Forum/Service/ForumMediaService.php','core/Forum/Repository/ForumMediaRepository.php','migrations/sqlite/0030_forum_media_storage.sql','migrations/pgsql/0030_forum_media_storage.sql','migrations/mysql/0030_forum_media_storage.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.4.4','>=')) $required = array_merge($required,['core/Forum/Service/PollQuestionService.php','core/Forum/Repository/PollQuestionRepository.php','migrations/sqlite/0031_forum_polls_questions.sql','migrations/pgsql/0031_forum_polls_questions.sql','migrations/mysql/0031_forum_polls_questions.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.4.5','>=')) $required = array_merge($required,['core/Forum/Service/DraftService.php','core/Forum/Repository/DraftRepository.php','core/Forum/Repository/RevisionRepository.php','migrations/sqlite/0032_forum_drafts_revisions.sql','migrations/pgsql/0032_forum_drafts_revisions.sql','migrations/mysql/0032_forum_drafts_revisions.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.5.0','>=')) $required = array_merge($required,['core/Forum/Content/ContentIntegrity.php','core/Forum/ForumIntegrityCheck.php']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.5.1','>=')) $required = array_merge($required,['core/Forum/Service/RecycleBinService.php','core/Forum/Repository/RecycleBinRepository.php','migrations/sqlite/0033_forum_soft_delete.sql','migrations/pgsql/0033_forum_soft_delete.sql','migrations/mysql/0033_forum_soft_delete.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.5.2','>=')) $required = array_merge($required,['core/Forum/Service/ModerationQueueService.php','core/Forum/Repository/ModerationQueueRepository.php','migrations/sqlite/0034_forum_moderation_center.sql','migrations/pgsql/0034_forum_moderation_center.sql','migrations/mysql/0034_forum_moderation_center.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.6.0','>=')) $required = array_merge($required,['migrations/sqlite/0039_forum_safety_stable.sql','migrations/pgsql/0039_forum_safety_stable.sql','migrations/mysql/0039_forum_safety_stable.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.5.6','>=')) $required = array_merge($required,['core/Forum/Service/TrustService.php','migrations/sqlite/0038_forum_trust_safety.sql','migrations/pgsql/0038_forum_trust_safety.sql','migrations/mysql/0038_forum_trust_safety.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.5.5','>=')) $required = array_merge($required,['core/Forum/Repository/PersonalDataRepository.php','migrations/sqlite/0037_forum_personal_data.sql','migrations/pgsql/0037_forum_personal_data.sql','migrations/mysql/0037_forum_personal_data.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.5.4','>=')) $required = array_merge($required,['core/Forum/Service/AntiSpamService.php','core/Forum/Repository/AntiSpamRepository.php','migrations/sqlite/0036_forum_antispam.sql','migrations/pgsql/0036_forum_antispam.sql','migrations/mysql/0036_forum_antispam.sql']);
        if (($manifest['edition'] ?? null) === 'forum' && version_compare((string)($manifest['version']??'0.0.0'),'0.5.3','>=')) $required = array_merge($required,['core/Forum/Service/SanctionService.php','core/Forum/Repository/SanctionRepository.php','migrations/sqlite/0035_forum_sanctions.sql','migrations/pgsql/0035_forum_sanctions.sql','migrations/mysql/0035_forum_sanctions.sql']);
        if ($mode === 'dist') $required = array_merge($required, ['composer.lock'], ReleaseDependencyInventory::AUTOLOAD_FILES);
        elseif (version_compare($upstream,'1.2.5','>=')) $required = array_merge($required, ['deploy/production.py','deploy/install.php','deploy/verify.php','deploy/host-smoke.sh','deploy/test_production.py','deploy/site.example.json','docs/production-deployment.md']);
        if ($mode === 'source' && version_compare($upstream, '1.3.0', '>=')) $required = array_merge($required, ['tools/production-acceptance.py','tools/certification-evidence.py','tools/certification-regression.py','tools/rate-limit-concurrency.php','docs/production-certification.md']);
        if ($mode === 'source' && ($manifest['edition'] ?? null) === 'forum' && version_compare($version, '0.9.1', '>=')) $required = array_merge($required, ['tools/verify-signed-release.php','tools/collect-ci-jobs.py','tools/verify-certified-run.py','tools/github-publish-release.sh']);
        if ($mode === 'source' && ($manifest['edition'] ?? null) === 'forum' && version_compare($version, '0.8.2', '>=')) $required = array_merge($required, ['.dockerignore','deploy/container/Dockerfile','deploy/container/compose.yaml','deploy/container/init.sh','deploy/container/gate.php','deploy/container/bootstrap.php','deploy/container/runtime.php','deploy/container/loop.sh','deploy/container/php-fpm.conf','deploy/container/nginx.conf.template','deploy/container/gateway.sh','deploy/container/postgres-start.sh','deploy/container/postgres-init.sh','deploy/container/redis-start.sh','deploy/container/prepare.sh','deploy/container/smoke.py','deploy/container/test_container.py','deploy/container/site.example.env','docs/container-deployment.md']);
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.8.3','>=')) {
            $required[]='core/Update/UpdatePreflight.php';
            if ($mode==='source') $required=array_merge($required,['deploy/container/update.py','deploy/container/update-operator.php','deploy/container/update-operator.sh','deploy/container/test_update.py','docs/secure-updates.md']);
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.8.4','>=')) {
            $required=array_merge($required,['core/Backup/AutomatedBackup.php','core/Backup/BackupPolicy.php','core/Backup/OffsiteBackup.php','docs/automated-backup.md']);
            if ($mode==='source') $required=array_merge($required,['tools/backup-automation-regression.php','deploy/backup-policy.example.json','deploy/cajeerengine-backup.service','deploy/cajeerengine-backup.timer','deploy/container/backup.compose.yaml','deploy/container/backup.sh','deploy/container/backup.py','deploy/container/cajeerengine-container-backup.service','deploy/container/cajeerengine-container-backup.timer']);
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.8.5','>=')) {
            $required=array_merge($required,['core/Backup/ColdRecovery.php','tools/recovery-probe.php','docs/disaster-recovery.md']);
            if ($mode==='source') $required=array_merge($required,['tools/cold-recovery-regression.php','tools/forum-tests/cold_recovery_acceptance.py']);
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.8.6','>=')) {
            $required=array_merge($required,['core/Observability/ForumMetrics.php','docs/forum-monitoring.md','resources/observability/forum-rules.yml','resources/observability/grafana/dashboards/cajeer-forum-http.json','resources/observability/grafana/dashboards/cajeer-forum-operations.json','resources/observability/grafana/provisioning/datasources/prometheus.yml','resources/observability/grafana/provisioning/dashboards/forum.yml']);
            foreach(['sqlite','pgsql','mysql'] as $driver)$required[]='migrations/'.$driver.'/0048_forum_observability_indexes.sql';
            if($mode==='source')$required=array_merge($required,['deploy/monitoring/compose.yaml','deploy/monitoring/configure.py','deploy/monitoring/test_monitoring.py','deploy/monitoring/rules-test.yml','tools/forum-observability-regression.php','tools/forum-tests/observability_sql_regression.py']);
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.8.7','>=')) {
            $required[]='docs/production-qualification.md';
            if($mode==='source')$required=array_merge($required,['deploy/container/qualify.py','deploy/container/test_qualify.py','tools/forum-qualification-regression.php','tools/forum-tests/reliability_sql_regression.py']);
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.9.5','>=') && $mode==='source') $required=array_merge($required,['deploy/container/chaos.py','deploy/container/chaos_update.py','deploy/container/test_chaos.py','docs/forum-chaos-0.9.5.md']);
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.9.6','>=') && $mode==='source') $required=array_merge($required,['tools/forum-tests/dr_certification.py','tools/forum-tests/dr_tls.py','tools/forum-tests/test_dr_tls.py','tools/backup-discovery-regression.php','docs/forum-dr-0.9.6.md']);
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.9.8','>=') && $mode==='source') $required=array_merge($required,['tools/forum-tests/test_upgrade_098.py','docs/forum-upgrade-certification.md']);
        if (($manifest['edition']??null)==='forum' && version_compare($version,'0.9.9','>=') && $mode==='source') $required=array_merge($required,['tools/staging-gate.py','tools/final-audit.py','tools/final-audit-regression.py','tools/release-freeze.py','docs/forum-rc-final-audit.md']);
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.0.0','>=') && $mode==='source') $required=array_merge($required,['tools/stable-certification.py','tools/stable-certification-regression.py','docs/forum-1.0-production-operations.md','docs/forum-1.0-upgrade.md','docs/forum-1.0-compatibility.md']);
        if ($mode==='source' && ($manifest['edition']??null)==='forum' && version_compare($version,'1.5.0','>=')) $required=array_merge($required,ReleaseFilePolicy::QUALIFICATION_SOURCE);
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.5.2','>=')) {
            if (!is_array($release['upgrade_compatibility']??null)) throw new \RuntimeException('Missing authenticated upgrade compatibility policy');
            $required=array_merge($required,['core/Update/ReleasePath.php','core/Update/UpdateCompatibility.php']);
            if($mode==='source')$required[]='tools/update-channels-regression.php';
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.5.4','>=')) {
            $required[]='core/Update/UpdatePolicy.php';
            if ($mode==='source') $required[]='tools/update-scheduling-regression.php';
            if (array_key_exists('security_update',$release) && !is_bool($release['security_update'])) throw new \RuntimeException('Invalid signed security classification');
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.5.5','>=')) {
            $required=array_merge($required,['core/Update/AutonomousUpdate.php','core/Update/OperationBusy.php']);
            if ($mode==='source') $required[]='tools/autonomous-update-regression.php';
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.5.6','>=')) {
            $required=array_merge($required,['core/Backup/BackupManager.php','core/Backup/DatabaseBackup.php','core/Update/UpdatePreflight.php']);
            if ($mode==='source') $required[]='tools/update-backup-preflight-regression.php';
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.5.7','>=')) {
            $required=array_merge($required,['core/Update/ServiceLifecycle.php','core/Update/UpdateManager.php','core/Observability/ProbeEndpoint.php']);
            if ($mode==='source') $required[]='tools/update-service-lifecycle-regression.php';
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.5.8','>=')) {
            $required=array_merge($required,['core/Update/RecoveryJournal.php','tools/update-supervisor.php']);
            if ($mode==='source') $required[]='tools/update-crash-recovery-regression.php';
        }
        if (($manifest['edition']??null)==='forum' && version_compare($version,'1.5.9','>=')) {
            $required[]='core/Update/UpdateCenterPolicy.php';
            if ($mode==='source') $required=array_merge($required,['tools/update-center-policy-regression.php','tools/update-center-browser-regression.py']);
        }
        if (($manifest['edition']??null)==='forum' && version_compare(explode('-', $version, 2)[0],'1.5.10','>=')) {
            $required[]='core/Update/ReleaseTrust.php';
            if ($mode==='source') $required[]='tools/update-supply-chain-regression.php';
            if (!isset($zip[$prefix.'composer.lock']) || ($manifest['dependency_sbom']??null)!==ReleaseDependencyInventory::sbom($zip[$prefix.'composer.lock']->getContent(),$version)) throw new \RuntimeException('Signed dependency SBOM differs from lock inventory');
        }
        foreach ($required as $file) if (!in_array($file, $files, true)) throw new \RuntimeException('Required ZIP entry missing: ' . $file);
        foreach (['pgsql', 'mysql', 'sqlite'] as $driver) {
            if (!array_filter($files, static fn ($file) => str_starts_with($file, 'migrations/' . $driver . '/') && str_ends_with($file, '.sql'))) throw new \RuntimeException('Missing migrations: ' . $driver);
        }
        if ($mode === 'dist') {
            $packages = ReleaseDependencyInventory::validate($zip[$prefix . 'composer.lock']->getContent(), $zip[$prefix . 'vendor/composer/installed.json']->getContent());
            foreach ($packages as $package) if (!isset($checksums['vendor/' . $package . '/composer.json'])) throw new \RuntimeException('Missing locked package: ' . $package);
        }
        if (trim($zip[$prefix . 'VERSION']->getContent()) !== $upstream) throw new \RuntimeException('Archive VERSION mismatch');
        $admin = json_decode($zip[$prefix . 'public/admin/assets/admin-manifest.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (($admin['ready'] ?? false) !== true || ($admin['version'] ?? '') !== $upstream) throw new \RuntimeException('Invalid admin manifest');
        foreach (['index.html', 'assets/admin-app.js', 'assets/admin-app.css', 'assets/admin-preview.js', 'assets/admin-preview.css'] as $asset) {
            $hash = $checksums['public/admin/' . $asset] ?? null;
            if ($hash === null || ($admin['files'][$asset]['sha256'] ?? null) !== $hash) throw new \RuntimeException('Admin asset mismatch: ' . $asset);
        }
        return $manifest;
    }
}
