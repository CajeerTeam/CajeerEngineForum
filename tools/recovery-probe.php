<?php
// Run the restored application in its unpublished directory, not the operator's installation.
declare(strict_types=1);
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Forum\{ForumDatabaseCheck,ForumIntegrityCheck};
use CajeerEngine\Forum\Service\{ForumEcosystemCheck,ForumSearchMaintenance};
use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Kernel\Application;
use CajeerEngine\Update\OperationFiles as F;
use Symfony\Component\HttpFoundation\Request;
try {
    $root=realpath($argv[1]??'');
    if ($argc!==2||$root===false||!is_file($root.'/storage/app/maintenance.json')||basename(dirname($root))===''||!str_ends_with(dirname($root),'.recovery')) throw new RuntimeException('Probe requires the unpublished recovery workspace');
    chdir($root); require $root.'/vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv($root.'/.env');
    $config=new ConfigRepository($root); $db=new DatabaseManager($config);
    $pdo=$db->connection();
    $ledger=(new MigrationRunner($pdo,$root.'/migrations'))->verifyApplied($db->migrationDriver());
    if (!$ledger['ok']||!(new ForumDatabaseCheck($db))->check()['ok']||!(new ForumIntegrityCheck($db))->check()['ok']||!(new ForumSearchMaintenance($db))->check()['ok']||!(new ForumEcosystemCheck($db,$config))->check(null,true)['ok']) throw new RuntimeException('Recovered forum integrity failed');
    if ($db->driver()==='sqlite'&&$pdo->query('PRAGMA foreign_key_check')->fetch()!==false) throw new RuntimeException('Recovered foreign keys invalid');
    if (!(new AdminAssetPublisher($root))->manifest()['ready']) throw new RuntimeException('Recovered admin assets invalid');
    $app=Application::boot($root);
    $origin=rtrim($config->string('app.url'),'/');
    $get=static fn(string $path)=>$app->handle(Request::create($origin.$path,'GET'));
    if ($get('/api/v1/health')->getStatusCode()!==503) throw new RuntimeException('Recovery maintenance gate not active');
    $marker=$root.'/storage/app/maintenance.json'; $saved=F::read($marker);
    // No HTTP listener exists, no workers are started, and the directory is private/unpublished.
    F::remove($marker);
    try {
        $health=$get('/api/v1/health'); $payload=json_decode((string)$health->getContent(),true,512,JSON_THROW_ON_ERROR);
        if ($health->getStatusCode()!==200||($payload['edition_version']??null)!==$config->string('app.edition_version')||($payload['status']??null)!=='ok') throw new RuntimeException('Recovered HTTP health failed');
        $page=$get('/forum'); $html=(string)$page->getContent();
        if ($page->getStatusCode()!==200||!str_contains($html,'data-rendered="true"')||!str_contains($html,'id="forum-theme"')||!str_contains($html,'/forum-assets/forum.js')) throw new RuntimeException('Recovered forum SSR failed');
        $api=$get('/api/v1/forum'); $data=json_decode((string)$api->getContent(),true,512,JSON_THROW_ON_ERROR)['data']??null;
        if ($api->getStatusCode()!==200||!is_array($data)||!is_array($data['categories']??null)||!is_array($data['boards']??null)) throw new RuntimeException('Recovered forum API failed');
        if (!in_array($get('/api/v1/forum/acp')->getStatusCode(),[401,403],true)) throw new RuntimeException('Recovered ACP authorization failed');
        $search=$get('/api/v1/forum/search?q=recovery');
        if ($search->getStatusCode()!==200||!is_array(json_decode((string)$search->getContent(),true,512,JSON_THROW_ON_ERROR)['data']??null)) throw new RuntimeException('Recovered forum search failed');
    } finally { F::json($marker,$saved,0644); }
    echo json_encode(['ok'=>true,'schema'=>true,'integrity'=>true,'search'=>true,'theme'=>true,'extensions'=>true,'ssr'=>true,'api'=>true,'acp_acl'=>true,'maintenance'=>true],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR,'Recovery application probe failed: '.$e->getMessage()."\n"); exit(1); }
