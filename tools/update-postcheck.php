<?php
// Invoked in a fresh process so migrations and health checks use the newly installed code.
declare(strict_types=1);
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Server\ServerDoctorService;
use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Update\OperationLock;
$root=dirname(__DIR__);
chdir($root);
try {
    require $root.'/vendor/autoload.php';
    if (is_file($root.'/.env')) (new Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv($root.'/.env');
    $config=new ConfigRepository($root); $db=new DatabaseManager($config);
    $runner=new MigrationRunner($db->connection(),$root.'/migrations');
    if (($argv[1]??'')==='migrate') $runner->run($db->migrationDriver());
    if (!$runner->verifyApplied($db->migrationDriver())['ok']) throw new RuntimeException('Migration verification failed');
    if (!(new CajeerEngine\Forum\ForumDatabaseCheck($db))->check()['ok']) throw new RuntimeException('Forum schema or roles not ready');
    if ($db->driver()==='sqlite' && $db->connection()->query('PRAGMA foreign_key_check')->fetch()!==false) throw new RuntimeException('Forum database foreign key integrity failed');
    foreach (['index.html','forum.js','forum.css'] as $asset) if (!is_file($root.'/public/forum-assets/'.$asset) || filesize($root.'/public/forum-assets/'.$asset)===0) throw new RuntimeException('Forum assets invalid');
    $health=$db->health(); if (!($health['ok']??false)||!($health['schema_ready']??false)) throw new RuntimeException('DB schema not ready');
    // Public readiness deliberately skips DB access while maintenance or an
    // exclusive operation lease blocks traffic. Verify the schema independently.
    foreach (['ce_content_entries','ce_users','ce_public_routes','ce_cms_search','ce_migrations','ce_forum_categories','ce_forum_boards','ce_forum_threads','ce_forum_posts','ce_forum_reactions','ce_forum_watches','ce_forum_reports'] as $table) {
        $db->connection()->query('SELECT 1 FROM '.$table.' WHERE 1=0');
    }
    (new CajeerEngine\Public\RouteIndex($root,$db))->rebuild();
    if (!(new AdminAssetPublisher($root))->manifest()['ready']) throw new RuntimeException('Admin assets invalid');
    $doctor=(new ServerDoctorService($root))->run(false); if (!($doctor['ok']??false)) throw new RuntimeException('Doctor failed');
    // The operation owner retains its exclusive lease until this child exits.
    // Public traffic MUST remain unready; never remove markers or unlock to test.
    $maintenance=OperationLock::maintenance($root);
    $activityAvailable=false;
    try {
        $probeLease=OperationLock::acquire($root,false);
        $activityAvailable=true;
        OperationLock::release($probeLease);
    } catch (RuntimeException $e) {
        if ($e->getMessage()!=='Another operation is running; retry after drain') throw $e;
    }
    $protected=$maintenance||!$activityAvailable;
    $app=CajeerEngine\Kernel\Application::boot($root);
    $response=$app->handle(Symfony\Component\HttpFoundation\Request::create('/api/v1/health','GET'));
    $payload=json_decode((string)$response->getContent(),true,512,JSON_THROW_ON_ERROR);
    $checks=$payload['checks']??null;
    if (($payload['edition_version'] ?? null) !== $config->string('app.edition_version')) throw new RuntimeException('Forum release version mismatch');
    $expectedCode=$protected?503:200;
    if ($response->getStatusCode()!==$expectedCode||($payload['status']??null)!==($protected?'degraded':'ok')||!is_array($checks)) {
        throw new RuntimeException('HTTP health smoke failed: expected '.$expectedCode.', got '.$response->getStatusCode());
    }
    // Only the public traffic guards and their intentional DB skip may be false.
    $expected=['dependencies'=>true,'installed'=>true,'storage'=>true,'disk'=>true,
        'maintenance'=>!$maintenance,'activity_lock'=>$activityAvailable,'database'=>!$protected];
    foreach ($expected as $name=>$value) {
        if (($checks[$name]??null)!==$value) throw new RuntimeException('HTTP health smoke failed: '.$name);
    }
    foreach ($checks as $name=>$value) {
        if (!array_key_exists($name,$expected)&&$value!==true) throw new RuntimeException('HTTP health smoke failed: '.$name);
    }
    if ($protected) echo "Protected HTTP health check passed (public readiness remains 503)\n";
    echo "Post-update checks passed\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
