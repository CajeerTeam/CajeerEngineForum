<?php

declare(strict_types=1);

namespace CajeerEngine\Installer;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\SettingsRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use CajeerEngine\Security\SecurityHardener;
use CajeerEngine\Database\MigrationLock;
use CajeerEngine\Forum\ForumVersion;

final readonly class InstallerService
{
    public function __construct(private string $rootPath, private array $operatorRuntime = [])
    {
    }

    /** @return array<string, mixed> */
    public function requirements(?string $driver = null, bool $inspectDatabase = true): array
    {
        if ($inspectDatabase) $this->loadEnvFileToProcess();
        $driver = $this->normalizeDriver($driver ?: (getenv('DB_DRIVER') ?: ($inspectDatabase ? $this->envValue('DB_DRIVER') : null) ?: 'pgsql'));
        if (!in_array($driver, ['sqlite','pgsql','mysql'], true)) $driver = 'pgsql';
        $checks = [
            'php_version' => [
                'label' => 'PHP >= 8.4.1',
                'ok' => version_compare(PHP_VERSION, '8.4.1', '>='),
                'current' => PHP_VERSION,
                'fix' => 'Установите PHP 8.4+ и используйте нужный php-fpm/CLI binary.',
            ],
            'ext_json' => ['label' => 'PHP extension json', 'ok' => extension_loaded('json'), 'fix' => 'Установите php-json.'],
            'ext_sodium' => ['label' => 'PHP extension sodium', 'ok' => extension_loaded('sodium'), 'fix' => 'Включите sodium.'],
            'forum_assets' => ['label' => 'Forum assets', 'ok' => $this->forumAssetsReady(), 'fix' => 'Восстановите файлы public/forum-assets.'],
            'ext_fileinfo' => ['label' => 'PHP extension fileinfo', 'ok' => extension_loaded('fileinfo'), 'fix' => 'Установите php-fileinfo.'],
            'ext_mbstring' => ['label' => 'PHP extension mbstring', 'ok' => extension_loaded('mbstring'), 'fix' => 'Установите php-mbstring.'],
            'ext_openssl' => ['label' => 'PHP extension openssl', 'ok' => extension_loaded('openssl'), 'fix' => 'Включите openssl extension.'],
            'ext_pdo' => ['label' => 'PHP extension pdo', 'ok' => extension_loaded('pdo'), 'fix' => 'Установите/включите PDO.'],
            'ext_selected_pdo' => [
                'label' => 'PDO driver: ' . $driver,
                'ok' => extension_loaded($this->pdoExtension($driver)),
                'driver' => $driver,
                'extension' => $this->pdoExtension($driver),
                'fix' => 'Установите расширение ' . $this->pdoExtension($driver) . '.',
            ],
            'storage_writable' => ['label' => 'storage/ writable', 'ok' => $this->writable('storage'), 'fix' => 'php bin/cajeer fix:permissions'],
            'storage_app_writable' => ['label' => 'storage/app/ writable', 'ok' => $this->writable('storage/app'), 'fix' => 'php bin/cajeer fix:storage'],
            'storage_database_writable' => ['label' => 'storage/database/ writable', 'ok' => $this->writable('storage/database'), 'fix' => 'php bin/cajeer fix:storage'],
            'public_uploads_writable' => ['label' => 'public/uploads/ writable', 'ok' => $this->writable('public/uploads'), 'fix' => 'php bin/cajeer fix:permissions'],
            'bootstrap_cache_writable' => ['label' => 'bootstrap/cache/ writable', 'ok' => $this->writable('bootstrap/cache'), 'fix' => 'php bin/cajeer fix:storage'],
            'env_file' => ['label' => '.env present', 'ok' => is_file($this->rootPath . '/.env'), 'fix' => 'php bin/cajeer install --preset=sqlite'],
            'app_key' => ['label' => 'APP_KEY configured', 'ok' => $inspectDatabase && $this->appKeyReady(), 'fix' => 'php bin/cajeer fix:env'],
            'composer_autoload' => ['label' => 'vendor/autoload.php present', 'ok' => is_file($this->rootPath . '/vendor/autoload.php'), 'fix' => 'Используйте dist-архив или выполните composer install --no-dev --optimize-autoloader.'],
            'admin_assets' => ['label' => 'prebuilt Admin assets present', 'ok' => $this->adminAssetsReady(), 'fix' => 'Используйте dist-архив или выполните npm run build:release.'],
        ];

        $database = null;
        $migrations = ['ok' => false, 'error' => 'DB connection ещё не проверялся.'];
        if ($inspectDatabase && is_file($this->rootPath . '/.env') && extension_loaded($this->pdoExtension($driver))) {
            try {
                $config = new ConfigRepository($this->rootPath);
                if ($driver === 'sqlite' && !is_file($this->absolutePath($config->string('database.connections.sqlite.database')))) {
                    throw new \RuntimeException('SQLite database file is missing');
                }
                $databaseManager = new DatabaseManager($config);
                $database = $databaseManager->health();
                if (($database['ok'] ?? false) === true) $this->assertDatabaseSupported($databaseManager);
                $migrations = $this->migrationStatus($databaseManager);
                if (($database['schema_ready'] ?? false) === true) {
                    $database['forum'] = (new \CajeerEngine\Forum\ForumDatabaseCheck($databaseManager))->check();
                    $database['schema_ready'] = $database['forum']['ok'] && ($databaseManager->driver() !== 'sqlite' || $databaseManager->connection()->query('PRAGMA foreign_key_check')->fetch() === false);
                }
            } catch (\Throwable $e) {
                $database = ['ok' => false, 'driver' => $driver, 'error' => ['message' => $e->getMessage(), 'class' => $e::class]];
            }
        }

        $preflight = $checks;
        unset($preflight['env_file'], $preflight['app_key']);
        $preflightReady = !in_array(false, array_column($preflight, 'ok'), true);
        $configuredReady = $preflightReady && $checks['env_file']['ok'] && $checks['app_key']['ok'] && ($database['schema_ready'] ?? false) === true && ($migrations['ok'] ?? false) === true && ($migrations['pending'] ?? -1) === 0;
        return [
            'ready' => $this->installed() ? $configuredReady : $preflightReady,
            'configured_ready' => $configuredReady,
            'installed' => $this->installed(),
            'version' => $this->version(),
            'edition_version' => $this->editionVersion(),
            'driver' => $driver,
            'checks' => $checks,
            'database' => $database,
            'migrations' => $migrations,
            'directories' => $this->runtimeDirectories(),
            'lock_file' => $this->lockPath(),
        ];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function install(array $options = []): array
    {
        $dir = $this->rootPath . '/storage/app';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new \RuntimeException('Cannot create installer state directory');
        if (is_link($dir.'/install-running.lock')) throw new \RuntimeException('Unsafe installer lock');
        $lock = fopen($dir . '/install-running.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Cannot open installer lock');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Установка уже выполняется другим процессом.');
            return $this->installLocked($options);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function installLocked(array $options): array
    {
        if ($this->installed()) {
            throw new \RuntimeException('CajeerEngine уже установлен. Найден lock-файл: ' . $this->lockPath());
        }

        if (($options['source'] ?? '') === 'web-installer' && !SetupToken::verify($this->rootPath, (string)($options['setup_token'] ?? ''))) throw new \RuntimeException('Setup token invalid or expired');
        if (!is_file($this->rootPath . '/vendor/autoload.php')) throw new \RuntimeException('Используйте dist с production Composer dependencies.');
        require_once $this->rootPath . '/vendor/autoload.php';
        if (version_compare(PHP_VERSION,'8.4.1','<')) throw new \RuntimeException('PHP 8.4.1+ required');
        if (!class_exists(\Symfony\Component\HttpFoundation\Request::class) || !class_exists(\Symfony\Component\Dotenv\Dotenv::class)) throw new \RuntimeException('Production Composer dependencies are incomplete.');
        if (!$this->adminAssetsReady() || !$this->forumAssetsReady()) throw new \RuntimeException('Admin assets повреждены или отсутствуют. Восстановите dist или выполните publish-admin-assets.mjs.');
        foreach (['json', 'mbstring', 'openssl', 'pdo', 'fileinfo', 'sodium'] as $requiredExtension) {
            if (!extension_loaded($requiredExtension)) {
                throw new \RuntimeException('Не установлен обязательный PHP extension: ' . $requiredExtension);
            }
        }
        $profile = $this->profile($options);
        if ($profile['write_lock'] && !$profile['create_admin']) throw new \RuntimeException('Для завершения установки требуется первый администратор.');
        $extension = $this->pdoExtension((string) $profile['db_driver']);
        if (!extension_loaded($extension)) {
            throw new \RuntimeException('Не установлен PHP extension для выбранной БД: ' . $extension);
        }
        if ($profile['create_admin']) (new PasswordHasher(new ConfigRepository($this->rootPath)))->assertStrong((string) $profile['admin_password']);
        if (!$profile['create_env'] && !is_file($this->rootPath . '/.env')) throw new \RuntimeException('--no-env requires an existing .env');
        $created = $this->ensureRuntimeDirectories(true);
        $envResult = ['created' => false, 'changed' => []];
        if ($profile['create_env']) {
            // Validate the candidate connection before replacing an existing configuration.
            $this->loadProcessEnv($profile['env']);
            $this->prepareDatabaseStorage($profile);
            $candidate = new DatabaseManager(new ConfigRepository($this->rootPath));
            $this->assertDatabaseSupported($candidate);
            $this->assertExistingAdministrator($candidate, $profile);
            $envResult = $this->writeEnv($profile);
        } else {
            (new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv($this->rootPath . '/.env');
            $config = new ConfigRepository($this->rootPath);
            if ((new DatabaseManager($config))->driver() !== $profile['db_driver'] || !$this->appKeyReady() || !(new SecurityHardener($this->rootPath, $config))->diagnose()['ok']) throw new \RuntimeException('Existing .env does not match the selected database or security prerequisites.');
            $profile['db_name'] = $config->string('database.connections.' . $profile['db_driver'] . '.database');
        }
        $this->prepareDatabaseStorage($profile);

        $configured = new DatabaseManager(new ConfigRepository($this->rootPath));
        $this->assertDatabaseSupported($configured);
        $this->assertExistingAdministrator($configured, $profile);
        $dbCheck = $this->checkDatabaseConnection();
        if (($dbCheck['ok'] ?? false) !== true) {
            throw new \RuntimeException('Не удалось подключиться к БД: ' . ($dbCheck['error']['message'] ?? 'unknown error'));
        }

        $migrationResult = null;
        if (($profile['run_migrations'] ?? true) === true) {
            $migrationResult = $this->runMigrations();
        }

        $seedResult = null;
        if (($profile['create_admin'] ?? true) === true) {
            $seedResult = $this->seed($profile);
        }

        // Initialize the default CMS before marking a fresh installation ready.
        if (($profile['run_migrations'] ?? true) === true) {
            (new \CajeerEngine\Cms\CmsBootstrapService($this->rootPath))->run();
        }
        $final = $this->finalVerify();

        $result = [
            'ok' => $final['ok'] === true,
            'installed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'source' => (string) ($profile['source'] ?? 'installer'),
            'preset' => $profile['preset'],
            'domain' => $profile['domain'],
            'database_driver' => $profile['db_driver'],
            'created_directories' => $created,
            'env_created' => $envResult['created'],
            'env_changed' => array_keys($envResult['changed']),
            'database' => $dbCheck,
            'migrations' => $migrationResult,
            'seed' => $seedResult,
            'final' => $final,
            'lock_file' => $this->lockPath(),
        ];

        $this->writeInstallState($result);
        if (!$result['ok']) throw new \RuntimeException('Финальная проверка не пройдена. installed.lock не создан. Проверьте storage/app/install-state.json и повторите установку с теми же данными.');
        if ($profile['write_lock']) {
            $this->writeInstallLock($profile, $final);
            SetupToken::consume($this->rootPath);
        }
        return $result;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function profile(array $options): array
    {
        $preset = strtolower((string) ($options['preset'] ?? ''));
        if (!empty($options['sqlite'])) {
            $preset = 'sqlite';
        }
        $dbDriver = $this->normalizeDriver((string) ($options['db'] ?? $options['db_driver'] ?? ''));
        if ($dbDriver === '') {
            $dbDriver = match ($preset) {
                'sqlite' => 'sqlite',
                'mysql', 'mariadb' => 'mysql',
                default => 'pgsql',
            };
        }
        if ($preset === '') {
            $preset = $dbDriver === 'sqlite' ? 'sqlite' : 'custom';
        }

        $production = (bool) ($options['production'] ?? in_array($preset, ['production', 'aapanel', 'postgres'], true));
        if ($preset === 'aapanel') {
            $options['php_binary'] ??= '/www/server/php/84/bin/php';
        }

        $domain = trim((string) ($options['domain'] ?? '127.0.0.1:8080'));
        $appUrl = $this->normalizeUrl((string) ($options['app_url'] ?? $domain));
        $dbName = (string) ($options['db_name'] ?? $options['database'] ?? ($dbDriver === 'sqlite' ? 'storage/database/cajeer.sqlite' : 'cajeerengine'));
        $dbHost = (string) ($options['db_host'] ?? '127.0.0.1');
        $dbPort = filter_var($options['db_port'] ?? ($dbDriver === 'mysql' ? 3306 : 5432), FILTER_VALIDATE_INT);
        if (!in_array($dbDriver, ['sqlite','pgsql','mysql'], true) || $dbPort === false || $dbPort < 1 || $dbPort > 65535) throw new \InvalidArgumentException('Некорректный тип БД или порт.');
        $dbUser = (string) ($options['db_user'] ?? ($dbDriver === 'sqlite' ? '' : 'cajeerengine'));
        $dbPassword = (string) ($options['db_password'] ?? '');
        if (preg_match('/[\x00\r\n]/',$dbPassword)) throw new \InvalidArgumentException('Multiline database password is unsupported');
        $adminName = trim((string) ($options['admin_name'] ?? 'Administrator')) ?: 'Administrator';
        $adminEmail = trim((string) ($options['admin_email'] ?? ''));
        $adminPassword = (string) ($options['admin_password'] ?? '');
        $createAdmin = (bool) ($options['create_admin'] ?? true);
        if ($createAdmin) {
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Для установки нужен корректный email администратора.');
            }
            if ($adminPassword === '') {
                throw new \InvalidArgumentException('Для установки нужен пароль администратора.');
            }
        }

        $appName = $this->setupText($options['app_name'] ?? 'CajeerEngine Forum Edition', 'app_name', 120);
        $category = $this->setupText($options['category_name'] ?? 'Сообщество', 'category_name', 120);
        $board = $this->setupText($options['board_name'] ?? 'Общие обсуждения', 'board_name', 120);
        $registration = filter_var($options['registration'] ?? !$production, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($registration === null) throw new \InvalidArgumentException('Invalid registration mode');
        $url = parse_url($appUrl);
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http','https'], true) || empty($url['host']) || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path']) || preg_match('/[\x00-\x20\x7f]/', $appUrl)) throw new \InvalidArgumentException('Укажите абсолютный URL форума без пути, параметров и credentials.');
        if ($production && $url['scheme'] !== 'https' && !in_array($url['host'],['127.0.0.1','localhost','[::1]'],true)) throw new \InvalidArgumentException('Production URL requires HTTPS');
        if ($dbDriver === 'sqlite') {
            $absolute = $this->safeSqlitePath($dbName);
            if (($options['source'] ?? '') === 'web-installer' && !str_starts_with($absolute,$this->rootPath.'/storage/database/')) throw new \InvalidArgumentException('Web installer SQLite path must be in storage/database/');
            $dbName = str_starts_with($dbName,'/') ? $absolute : substr($absolute,strlen($this->rootPath)+1);
        }
        else {
            foreach ([$dbHost,$dbName,$dbUser] as $part) if ($part === '' || preg_match('/[;\x00-\x20\x7f]/', $part)) throw new \InvalidArgumentException('Invalid database connection parameter');
        }
        $sslmode = (string) ($options['db_sslmode'] ?? 'prefer');
        if (!in_array($sslmode, ['disable','allow','prefer','require','verify-ca','verify-full'], true)) throw new \InvalidArgumentException('Invalid PostgreSQL SSL mode');
        if ($createAdmin) $adminName = $this->setupText($adminName, 'admin_name', 120);
        $env = [
            'APP_NAME' => $appName,
            'FORUM_REGISTRATION' => $registration ? 'true' : 'false',
            'DB_SQLITE_WAL' => $dbDriver === 'sqlite' ? 'true' : 'false',
            'DB_SQLITE_BUSY_TIMEOUT_MS' => '5000',
            'APP_ENV' => $production ? 'production' : 'local',
            'APP_DEBUG' => $production ? 'false' : 'true',
            'APP_URL' => $appUrl,
            'APP_KEY' => $this->existingOrGeneratedSecret('APP_KEY', 'base64:' . base64_encode(random_bytes(32)), true),
            'APP_LOCALE' => (string) ($options['locale'] ?? 'ru'),
            'APP_VERSION' => $this->version(),
            'JWT_SECRET' => $this->existingOrGeneratedSecret('JWT_SECRET', bin2hex(random_bytes(48))),
            'API_TOKEN_SECRET' => $this->existingOrGeneratedSecret('API_TOKEN_SECRET', bin2hex(random_bytes(48))),
            'WEBHOOK_SIGNING_SECRET' => $this->existingOrGeneratedSecret('WEBHOOK_SIGNING_SECRET', bin2hex(random_bytes(48))),
            'DB_DRIVER' => $dbDriver,
            'DB_HOST' => $dbHost,
            'DB_PORT' => (string) $dbPort,
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPassword,
            'DB_SSLMODE' => $sslmode,
            'SQLITE_DATABASE' => $dbDriver === 'sqlite' ? $dbName : 'storage/database/cajeer.sqlite',
            'CACHE_DRIVER' => (string) ($options['cache_driver'] ?? 'file'),
            'QUEUE_DRIVER' => (string) ($options['queue_driver'] ?? 'file'),
            'SEARCH_DRIVER' => (string) ($options['search_driver'] ?? 'file'),
            'STORAGE_DRIVER' => 'local',
            'STORAGE_LOCAL_PATH' => 'storage/app',
            'STORAGE_PUBLIC_UPLOADS_URL' => '/uploads',
            'SECURITY_REQUIRE_AUTH' => 'true',
            'SECURITY_PROTECT_CONTENT_WRITES' => 'true',
            'SECURITY_PROTECT_SYSTEM_ROUTES' => 'true',
            'MEDIA_ALLOW_SVG' => 'false',
            'MEDIA_STORAGE' => 'database',
            'CONTENT_STORAGE' => 'database',
            'CMS_STORAGE' => 'database',
            'MEDIA_REQUIRE_IMAGE_PROCESSOR' => $production ? 'true' : 'false',
            'PASSWORD_MIN_LENGTH' => '12',
            'PASSWORD_REQUIRE_MIXED' => 'true',
            'ADMIN_EMAIL' => $adminEmail,
            'ADMIN_NAME' => $adminName,
            'ADMIN_PASSWORD' => '',
        ];

        // Only trusted operator code may provide runtime overrides; never read them from HTTP options.
        foreach ($this->operatorRuntime as $key => $value) {
            if (!in_array($key, ['REDIS_HOST','REDIS_PORT','REDIS_PASSWORD','REDIS_DATABASE','FORUM_REDIS_NAMESPACE','FORUM_CACHE_ENABLED','CACHE_DRIVER','RATE_LIMIT_DRIVER','QUEUE_DRIVER','QUEUE_REDIS_PREFIX','SCHEDULER_DRIVER','BACKUP_KEY_FILE','UPDATE_TRUSTED_PUBLIC_KEY_FILE','UPDATE_WEB_UPGRADE_ENABLED','OBSERVABILITY_METRICS_TOKEN'], true) || !is_string($value)) throw new \InvalidArgumentException('Unsupported operator runtime option');
            $env[$key] = $value;
        }

        return [
            'preset' => $preset,
            'source' => (string) ($options['source'] ?? 'cli'),
            'production' => $production,
            'domain' => $domain,
            'app_url' => $appUrl,
            'db_driver' => $dbDriver,
            'db_name' => $dbName,
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_user' => $dbUser,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'create_admin' => $createAdmin,
            'app_name' => $appName,
            'category_name' => $category,
            'board_name' => $board,
            'registration' => $registration,
            'create_env' => !array_key_exists('create_env', $options) || (bool) $options['create_env'],
            'run_migrations' => !array_key_exists('run_migrations', $options) || (bool) $options['run_migrations'],
            'write_lock' => !array_key_exists('write_lock', $options) || (bool) $options['write_lock'],
            'env' => $env,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function runtimeDirectories(): array
    {
        $result = [];
        foreach ($this->runtimeDirectoryList() as $directory) {
            $path = $this->rootPath . '/' . $directory;
            $result[$directory] = [
                'exists' => is_dir($path),
                'writable' => is_dir($path) ? is_writable($path) : is_writable(dirname($path)),
            ];
        }
        return $result;
    }

    public function installed(): bool
    {
        return is_file($this->lockPath());
    }

    public function lockPath(): string
    {
        return $this->rootPath . '/storage/app/installed.lock';
    }

    /** @return list<string> */
    private function runtimeDirectoryList(): array
    {
        return [
            'storage/app',
            'storage/app/content',
            'storage/app/exports',
            'storage/app/imports',
            'storage/app/updates',
            'storage/app/update-requests',
            'storage/app/extensions',
            'storage/app/media',
            'storage/app/stable',
            'storage/app/support',
            'storage/app/backups',
            'storage/cache',
            'storage/cache/templates',
            'storage/database',
            'storage/logs',
            'storage/tmp',
            'storage/queue/pending/default',
            'storage/queue/processed/default',
            'storage/queue/failed/default',
            'storage/rate-limits',
            'storage/static-export',
            'storage/releases',
            'public/uploads',
            'bootstrap/cache',
            'plugins',
            'themes',
        ];
    }

    /** @return list<string> */
    private function ensureRuntimeDirectories(bool $writeGitkeep): array
    {
        $created = [];
        foreach ($this->runtimeDirectoryList() as $directory) {
            $path = $this->rootPath . '/' . $directory;
            if (!is_dir($path)) {
                if (!mkdir($path, 0775, true) && !is_dir($path)) {
                    throw new \RuntimeException('Не удалось создать директорию: ' . $directory);
                }
                $created[] = $directory;
            }
            if ($writeGitkeep) {
                $gitkeep = $path . '/.gitkeep';
                if (!is_file($gitkeep)) {
                    @file_put_contents($gitkeep, '');
                }
            }
        }
        return $created;
    }

    /** @param array<string, mixed> $profile @return array{created:bool,changed:array<string,array{old:?string,new:string}>} */
    private function writeEnv(array $profile): array
    {
        $result = (new InstallerEnvironment($this->rootPath.'/.env'))->write($profile['env'], $this->rootPath.'/.env.example');
        $this->loadProcessEnv((new InstallerEnvironment($this->rootPath.'/.env'))->read());
        return $result;
    }

    private function loadEnvFileToProcess(): void
    {
        if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class) && is_file($this->rootPath.'/vendor/autoload.php')) require_once $this->rootPath.'/vendor/autoload.php';
        if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class)) return;
        foreach ((new InstallerEnvironment($this->rootPath.'/.env'))->read() as $key=>$value) {
            if (getenv($key) === false) $this->loadProcessEnv([$key=>$value]);
        }
    }

    /** @param array<string, string> $values */
    private function loadProcessEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /** @param array<string, mixed> $profile */
    private function prepareDatabaseStorage(array $profile): void
    {
        if (($profile['db_driver'] ?? '') !== 'sqlite') {
            return;
        }
        $relative = $this->safeSqlitePath((string) ($profile['db_name'] ?? 'storage/database/cajeer.sqlite'));
        $path = $this->absolutePath($relative);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать директорию SQLite: ' . $dir);
        }
        if (!is_file($path)) {
            $file = @fopen($path, 'x+b');
            if ($file === false) throw new \RuntimeException('Cannot create SQLite file');
            fclose($file);
            if (!chmod($path, 0600)) {
                throw new \RuntimeException('Не удалось создать SQLite-файл: ' . $path);
            }
        }
    }

    /** @return array<string, mixed> */
    private function checkDatabaseConnection(): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        return $database->health();
    }

    /** @return array<string, mixed> */
    private function runMigrations(): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
        return $runner->run($database->migrationDriver());
    }

    /** @return array<string, mixed> */
    private function seed(array $profile): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $roles = new RoleRepository($database);
        $settings = new SettingsRepository($database);
        $users = new UserRepository($database, $roles, new PasswordHasher($config));
        $audit = new AuditLogRepository($database);
        $email = $profile['admin_email'];
        $password = $profile['admin_password'];
        return MigrationLock::run($database->connection(), fn() => $database->transaction(function () use ($profile,$config,$database,$roles,$settings,$users,$audit,$email,$password): array {
            // Acquire the SQLite writer reservation before reading the users table.
            if ($database->driver() === 'sqlite') $database->connection()->exec('UPDATE ce_roles SET id=id WHERE 1=0');
            $existing = $users->findByEmail($email);
            if ($existing !== null) {
                $auth = $users->findByEmailForAuth($email);
                if (!in_array('admin', $existing['roles'] ?? [], true) || ($existing['status'] ?? '') !== 'active' || !password_verify($password, (string) ($auth['password_hash'] ?? ''))) {
                    throw new \RuntimeException('Для возобновления используйте существующего активного администратора и его пароль.');
                }
                return ['roles_seeded'=>0,'admin_user_id'=>$existing['id'],'admin_email'=>mb_strtolower($email),'resumed'=>true];
            }
            if ($users->count() > 0) throw new \RuntimeException('База уже содержит пользователей. Используйте существующую учётную запись администратора.');
            $roleCount = $roles->seedDefaults();
            $userId = $users->createAdmin($email, $profile['admin_name'], $password);
            $settings->set('app.name', $config->string('app.name'), 'string', $userId);
            $settings->set('app.locale', $config->string('app.locale', 'ru'), 'string', $userId);
            $settings->set('engine.version', $this->version(), 'string', $userId);
            $settings->set('engine.database_driver', $database->driver(), 'string', $userId);
            $settings->set('forum.initialized', true, 'boolean', $userId);
            $pdo = $database->connection();
            $pdo->prepare('UPDATE ce_forum_categories SET name=? WHERE id=?')->execute([$profile['category_name'],'11111111-1111-4111-8111-111111111111']);
            $pdo->prepare('UPDATE ce_forum_boards SET name=? WHERE id=?')->execute([$profile['board_name'],'22222222-2222-4222-8222-222222222222']);
            $audit->record('installer.admin_created', $userId, ['email'=>mb_strtolower($email),'source'=>'forum-'.$this->editionVersion()]);
            return ['roles_seeded'=>$roleCount,'admin_user_id'=>$userId,'admin_email'=>mb_strtolower($email),'resumed'=>false];
        }));
    }

    /** @return array<string, mixed> */
    private function finalVerify(): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $migrationStatus = $this->migrationStatus($database);
        $security = (new SecurityHardener($this->rootPath, $config))->diagnose();
        $adminAssets = $this->adminAssetsReady();
        $databaseHealth = $database->health();
        $ok = ($databaseHealth['ok'] ?? false) === true
            && ($databaseHealth['schema_ready'] ?? false) === true
            && ($migrationStatus['ok'] ?? false) === true
            && $this->appKeyReady()
            && (bool) ($security['ok'] ?? false)
            && $adminAssets && $this->forumAssetsReady();
        $forum = (new \CajeerEngine\Forum\ForumDatabaseCheck($database))->check();
        $ok = $ok && $forum['ok'];
        if ($forum['ok']) {
            $forum['data_integrity']=(new \CajeerEngine\Forum\ForumIntegrityCheck($database))->check();
            $ok=$ok && $forum['data_integrity']['ok'];
        }
        if ($database->driver()==='sqlite') {
            $forum['foreign_keys_ok'] = $database->connection()->query('PRAGMA foreign_key_check')->fetch() === false;
            $ok = $ok && $forum['foreign_keys_ok'];
        }

        $routing=['ok'=>false];
        if($ok){try{$routing=(new \CajeerEngine\Public\RouteIndex($this->rootPath,$database))->rebuild();}catch(\Throwable $e){$routing=['ok'=>false,'message'=>$e->getMessage()];$ok=false;}}
        return [
            'ok' => $ok,
            'routing' => $routing,
            'forum' => $forum,
            'database' => $databaseHealth,
            'migrations' => $migrationStatus,
            'security_doctor' => $security,
            'admin_assets' => ['ok' => $adminAssets],
        ];
    }

    /** @param array<string, mixed> $profile @param array<string, mixed> $final */
    private function writeInstallLock(array $profile, array $final): void
    {
        $payload = [
            'format' => 'cajeerengine.installed.v1',
            'version' => $this->version(),
            'edition_version' => $this->editionVersion(),
            'installed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'source' => $profile['source'] ?? 'installer',
            'preset' => $profile['preset'] ?? 'custom',
            'app_url' => $profile['app_url'] ?? null,
            'database_driver' => $profile['db_driver'] ?? null,
            'final_ok' => (bool) ($final['ok'] ?? false),
        ];
        $path = $this->lockPath();
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Не удалось создать директорию lock-файла.');
        }
        if (($final['ok'] ?? false) !== true) throw new \RuntimeException('Cannot lock incomplete installation');
        $this->atomicWrite($path, $payload);
    }

    private function writable(string $relativePath): bool
    {
        $path = $this->rootPath . '/' . $relativePath;
        return is_dir($path) ? is_writable($path) : is_writable(dirname($path));
    }

    private function appKeyReady(): bool
    {
        $value = $this->envValue('APP_KEY');
        return $value !== null && $value !== '' && $value !== 'CHANGE_ME' && $value !== 'base64:CHANGE_ME';
    }

    private function adminAssetsReady(): bool
    {
        return (new \CajeerEngine\Admin\AdminAssetPublisher($this->rootPath))->manifest()['ready'];
    }

    private function envValue(string $key): ?string
    {
        $path = $this->rootPath . '/.env';
        if (!is_file($path)) {
            return null;
        }
        if (!class_exists(\Symfony\Component\Dotenv\Dotenv::class)) return null;
        return (new InstallerEnvironment($path))->read()[$key] ?? null;
    }

    private function existingOrGeneratedSecret(string $key, string $generated, bool $rejectChangeMe = false): string
    {
        $existing = $this->envValue($key);
        if ($existing !== null && $existing !== '' && (!$rejectChangeMe || !in_array($existing, ['CHANGE_ME', 'base64:CHANGE_ME'], true))) {
            return $existing;
        }
        return $generated;
    }

    private function pdoExtension(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'pdo_sqlite',
            'mysql' => 'pdo_mysql',
            default => 'pdo_pgsql',
        };
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    private function normalizeUrl(string $domainOrUrl): string
    {
        $domainOrUrl = trim($domainOrUrl);
        if ($domainOrUrl === '') {
            return 'http://127.0.0.1:8080';
        }
        if (!preg_match('~^https?://~i', $domainOrUrl)) {
            $domainOrUrl = 'https://' . $domainOrUrl;
        }
        return rtrim($domainOrUrl, '/');
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return $this->rootPath . '/storage/database/cajeer.sqlite';
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->rootPath . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function migrationStatus(DatabaseManager $database): array
    {
        try {
            $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
            $status = $runner->verifyApplied($database->migrationDriver());
            $pending = count(array_filter($status['errors'], static fn(string $reason): bool => $reason === 'pending'));
            $badKnown = count(array_filter($status['errors'], static fn(string $reason): bool => $reason !== 'missing'));
            return [
                'ok' => $status['ok'],
                'driver' => $database->migrationDriver(),
                'applied' => $status['expected'] - $badKnown,
                'pending' => $pending,
                'failed' => count($status['errors']) - $pending,
                'errors' => $status['errors'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $state */
    private function writeInstallState(array $state): void
    {
        $path = $this->rootPath . '/storage/app/install-state.json';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $this->atomicWrite($path, $state);
    }

    private function atomicWrite(string $path, array $payload): void
    {
        $body = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body) || !chmod($tmp, 0600) || !rename($tmp, $path)) throw new \RuntimeException('Cannot save installation state');
        } finally { if (is_file($tmp)) unlink($tmp); }
    }

    private function setupText(mixed $value, string $key, int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) || trim($value)==='' || mb_strlen(trim($value))>$max) throw new \InvalidArgumentException('Invalid installer field: '.$key);
        return trim($value);
    }

    private function safeSqlitePath(string $path): string
    {
        if ($path === '' || $path === ':memory:' || preg_match('/[\x00-\x1f\x7f]/', $path)) throw new \InvalidArgumentException('SQLite requires a persistent database file');
        $absolute = $this->absolutePath($path);
        // Disallow traversal and symlinks, including paths under the web root.
        $parts = explode('/', $absolute);
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($part === '.' || $part === '..') throw new \InvalidArgumentException('Unsafe SQLite path');
            $current .= '/'.$part;
            if (is_link($current)) throw new \InvalidArgumentException('SQLite symlinks are not supported by installer');
        }
        $public = realpath($this->rootPath.'/public') ?: $this->rootPath.'/public';
        if ($current === $public || str_starts_with($current,$public.'/')) throw new \InvalidArgumentException('SQLite must be outside public/');
        return $current;
    }

    private function assertExistingAdministrator(DatabaseManager $db, array $profile): void
    {
        if (!$db->tableExists('ce_users')) return;
        $users = new UserRepository($db, new RoleRepository($db));
        if ($users->count() === 0) return;
        $user = $users->findByEmail($profile['admin_email']);
        $auth = $users->findByEmailForAuth($profile['admin_email']);
        if (!$profile['create_admin'] || $user === null || !in_array('admin',$user['roles'] ?? [],true) || ($user['status'] ?? '') !== 'active' || !password_verify($profile['admin_password'],(string)($auth['password_hash'] ?? ''))) {
            throw new \RuntimeException('Existing database requires credentials of its active administrator');
        }
    }

    private function assertDatabaseSupported(DatabaseManager $database): void
    {
        $pdo = $database->connection();
        if ($database->driver()==='pgsql') {
            if ((int) $pdo->query('SHOW server_version_num')->fetchColumn()<170000) throw new \RuntimeException('PostgreSQL 17+ required');
            if ($pdo->query('SELECT current_schema()')->fetchColumn() !== 'public') throw new \RuntimeException('Installer requires the public PostgreSQL schema');
        } elseif ($database->driver()==='sqlite') {
            if (version_compare((string)$pdo->query('SELECT sqlite_version()')->fetchColumn(),'3.40.0','<')) throw new \RuntimeException('SQLite 3.40+ required');
            if ((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()!==1) throw new \RuntimeException('SQLite foreign keys must be enabled');
        }
    }

    private function forumAssetsReady(): bool
    {
        foreach (['index.html','forum.js','forum.css'] as $file) if (!is_file($this->rootPath.'/public/forum-assets/'.$file) || filesize($this->rootPath.'/public/forum-assets/'.$file)===0) return false;
        return true;
    }

    private function editionVersion(): string { return (new ForumVersion($this->rootPath))->current(); }

    private function version(): string
    {
        return trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.3.0';
    }
}
