<?php
/** Executable regression of 1.0.2 production paths; no Composer or network required. */
declare(strict_types=1);

use CajeerEngine\Api\ApiContract;
use CajeerEngine\Extension\{ExtensionManifest,ExtensionRegistry};
use CajeerEngine\Extension\Runtime\{ExtensionContext,ExtensionRuntime,MigrationLedger};
use CajeerEngine\Integration\{IntegrationCenter,IntegrationStore,SecretVault};
use CajeerEngine\Integration\Provider\{ProviderInterface,ProviderRegistry};
use CajeerEngine\Runtime\{ConfigRepository,EventDispatcher,RuntimeLogger,ServiceContainer};

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen('CajeerEngine\\'))) . '.php';
        if (is_file($file)) require $file;
    }
});
function verify(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
function reject(callable $function, string $name): void {
    try { $function(); } catch (Throwable) { verify(true, $name); return; }
    throw new RuntimeException('Expected rejection: ' . $name);
}
function clean(string $directory): void {
    if (!is_dir($directory)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($directory);
}

final class TestIntegrationProvider implements ProviderInterface {
    public int $calls = 0;
    public function id(): string { return 'test-integration'; }
    public function descriptor(): array { return ['id' => $this->id(), 'name' => 'Regression', 'operations' => ['send']]; }
    public function configuration(array $config): array {
        if (!is_string($config['url'] ?? null) || !str_starts_with($config['url'], 'https://')) throw new InvalidArgumentException('Invalid URL');
        return ['url' => $config['url']];
    }
    public function validateSecret(string $secret): void { if ($secret !== '') throw new InvalidArgumentException('No secret supported'); }
    public function execute(array $config, string $secret, string $event, array|stdClass $payload, string $eventId): int {
        $this->calls++;
        return 202;
    }
}

$tmp = sys_get_temp_dir() . '/forum-102-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
try {
    $api = new ApiContract($root);
    verify($api->operation('api.v1.integrations.store') !== null, 'real OpenAPI operations indexed');
    verify($api->validate((object)['name' => 'éé'], ['type'=>'object','required'=>['name'],'properties'=>['name'=>['type'=>'string','maxLength'=>2]]])===[], 'Unicode character bounds');
    reject(fn()=>throw_if_api_valid($api, (object)['x'=>str_repeat('a',8)], ['type'=>'object','properties'=>['x'=>['type'=>'string','maxLength'=>4]]]), 'OpenAPI maximum string length');
    verify($api->validate([1,1],['type'=>'array','uniqueItems'=>true])!==[], 'OpenAPI unique items');
    verify($api->validate([1,2,3],['type'=>'array','maxItems'=>2])!==[], 'OpenAPI maximum items');
    verify($api->validate((object)['x'=>3], ['type'=>'object','properties'=>['x'=>['type'=>'string']]])!==[], 'OpenAPI nested type');
    verify($api->validate((object)['x'=>1], ['type'=>'object','additionalProperties'=>['type'=>'string']])!==[], 'OpenAPI typed additional properties');
    verify($api->validate(5, ['oneOf'=>[['type'=>'number'],['type'=>'integer']]])!==[], 'OpenAPI oneOf requires exactly one alternative');
    verify($api->validate(5, ['allOf'=>[['type'=>'integer'],['minimum'=>7]]])!==[], 'OpenAPI allOf validates all alternatives');

    mkdir($tmp . '/config');
    foreach (glob($root . '/config/*.php') as $file) copy($file, $tmp . '/config/' . basename($file));
    copy($root . '/VERSION', $tmp . '/VERSION');
    copy($root . '/release.json', $tmp . '/release.json');
    putenv('APP_ENV=testing');
    putenv('INTEGRATIONS_ALLOWED_PROVIDERS=test-integration');
    putenv('INTEGRATIONS_ALLOWED_HOSTS=allowed.example');
    putenv('INTEGRATIONS_MAX_CONNECTIONS=1');
    putenv('INTEGRATIONS_MAX_PAYLOAD_BYTES=1024');
    putenv('EXTENSIONS_ALLOW_UNSIGNED_IN_DEV=true');
    $config = new ConfigRepository($tmp);
    verify($config->string('app.edition_version')==='1.0.2', 'Forum edition version propagated at runtime');
    $providers = new ProviderRegistry(); $provider = new TestIntegrationProvider(); $providers->register($provider);
    $center = new IntegrationCenter(new IntegrationStore($tmp),$providers,new SecretVault('base64:'.base64_encode(random_bytes(32))),$config);
    $first = $center->create(['provider'=>'test-integration','name'=>'Test','config'=>['url'=>'https://allowed.example/invoke']], 'operator');
    verify($first['revision']===1 && !array_key_exists('credential',$first), 'integration persisted with credential redaction');
    reject(fn()=>$center->create(['provider'=>'test-integration','name'=>'Denied host','config'=>['url'=>'https://bad.example/api']], 'operator'), 'destination host allowlist enforced');
    reject(fn()=>$center->create(['provider'=>'test-integration','name'=>'Limit','config'=>['url'=>'https://allowed.example/x']], 'operator'), 'integration connection limit enforced');
    reject(fn()=>$center->run($first['id'],1,'test.event',(object)['blob'=>str_repeat('x',1200)],'operator',true), 'outbound event payload budget enforced');
    $enabled=$center->update($first['id'],['revision'=>1,'enabled'=>true],'operator');
    verify($enabled['revision']===2, 'optimistic integration configuration update');
    reject(fn()=>$center->update($first['id'],['revision'=>1,'name'=>'Stale'],'operator'), 'stale configuration revision rejected');
    $result=$center->run($first['id'],2,'test.event',(object)['ok'=>true],'operator');
    verify($result['ok'] && $result['status_code']===202 && $provider->calls===1, 'live integration execution and recorded status');
    $center->update($first['id'],['revision'=>2,'enabled'=>false],'operator');
    reject(fn()=>$center->run($first['id'],3,'test.event',new stdClass(),'operator'), 'disabled integration cannot execute');
    putenv('INTEGRATIONS_ALLOWED_PROVIDERS=http-json');
    $restricted=new IntegrationCenter(new IntegrationStore($tmp),$providers,new SecretVault('base64:'.base64_encode(random_bytes(32))),new ConfigRepository($tmp));
    reject(fn()=>$restricted->run($first['id'],3,'test.event',new stdClass(),'operator',true), 'provider allowlist enforced at execution');
    putenv('INTEGRATIONS_MAX_CONNECTIONS=999999');
    reject(fn()=>new ConfigRepository($tmp), 'invalid integration budgets fail closed');
    putenv('INTEGRATIONS_MAX_CONNECTIONS=1');
    putenv('INTEGRATIONS_ALLOWED_PROVIDERS=test-integration');

    $manifest = new ExtensionManifest('test/sample','plugin','1.0.0','*');
    $context = new ExtensionContext($tmp,$manifest,[],new ServiceContainer(),$config,new EventDispatcher(),new RuntimeLogger($tmp));
    $ledger = new MigrationLedger($tmp,$config,null);
    $sha = hash('sha256','migration one');
    $runs=0;
    $ledger->run('test/sample','0001_init.php',$sha,static function () use (&$runs): void {$runs++;},$context);
    (new MigrationLedger($tmp,$config,null))->run('test/sample','0001_init.php',$sha,static function () use (&$runs): void {$runs++;},$context);
    verify($runs===1 && $ledger->applied('test/sample')['0001_init.php']===$sha, 'JSON migration once-only execution across ledger instances');
    reject(fn()=>$ledger->run('test/sample','0001_init.php',hash('sha256','drift'),static fn()=>null,$context), 'migration checksum drift blocked');
    reject(fn()=>$ledger->run('test/sample','0002_failure.php',hash('sha256','two'),static fn()=>throw new RuntimeException('crash'),$context), 'migration failure propagated');
    reject(fn()=>$ledger->run('test/sample','0003_next.php',hash('sha256','three'),static fn()=>null,$context), 'interrupted non-transactional migration blocks automatic continuation');

    $package = $tmp . '/plugins/sample'; mkdir($tmp . '/plugins'); mkdir($package); mkdir($package.'/assets');
    file_put_contents($package.'/cajeer.extension.json',json_encode(['name'=>'test/sample','type'=>'plugin','version'=>'1.0.0','engine'=>'*','forum'=>'>=1.0.0 <1.1.0'],JSON_THROW_ON_ERROR));
    file_put_contents($package.'/assets/app.js','console.log("old");');
    $registry = new ExtensionRegistry($tmp,new ConfigRepository($tmp));
    $registry->install('plugins/sample');
    $runtime = new ExtensionRuntime($tmp,$registry,new ServiceContainer(),new ConfigRepository($tmp),new EventDispatcher(),new RuntimeLogger($tmp));
    $runtime->publishAssets('test/sample');
    $assets=$tmp.'/public/extensions/test--sample';
    verify(file_get_contents($assets.'/app.js')==='console.log("old");', 'working initial static asset publication');
    file_put_contents($package.'/assets/app.js','console.log("new");');
    $registry->install('plugins/sample');
    $runtime->publishAssets('test/sample');
    verify(file_get_contents($assets.'/app.js')==='console.log("new");', 'atomic asset update activates new bytes');
    if (function_exists('symlink') && @symlink($root.'/VERSION',$package.'/assets/escape.js')) {
        reject(fn()=>$runtime->publishAssets('test/sample'), 'symlinked extension asset blocked');
        verify(file_get_contents($assets.'/app.js')==='console.log("new");', 'invalid update preserves previously published assets');
    }
} finally {
    clean($tmp);
}

function throw_if_api_valid(ApiContract $api, mixed $input,array $schema): void {
    if ($api->validate($input,$schema)!==[]) throw new RuntimeException('validated violation');
}
