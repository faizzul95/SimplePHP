<?php

namespace Core\Console;

/**
 * Built-in Framework Commands
 *
 * Standard Artisan-like commands that ship with SimplePHP.
 * These are auto-registered by the Console Kernel — do NOT modify.
 * Custom commands go in: app/routes/console.php
 */
class Commands
{
    /**
     * Register all built-in commands on the given kernel.
     */
    public static function register(Kernel $console): void
    {
        self::cacheCommands($console);
        self::routeCommands($console);
        self::makeCommands($console);
        self::databaseCommands($console);
        self::migrationCommands($console);
        self::serveCommand($console);
        self::keyCommand($console);
        self::maintenanceCommands($console);
        self::inspectCommands($console);
        self::queueCommands($console);
    }

    // ─── Cache Commands ──────────────────────────────────────

    private static function cacheCommands(Kernel $console): void
    {
        $console->command('cache:clear', function (array $args = [], array $options = []) use ($console) {
            $dirs = [
                'views'      => ROOT_DIR . 'storage/cache/views',
                'query'      => ROOT_DIR . 'storage/cache/query',
                'rate_limit' => ROOT_DIR . 'storage/cache/rate_limit',
                'app'        => ROOT_DIR . 'storage/cache/app',
            ];

            $target = strtolower($args[0] ?? 'all');
            $totalFiles = 0;

            $console->newLine();

            foreach ($dirs as $key => $dir) {
                if ($target !== 'all' && $key !== $target) {
                    continue;
                }

                if (!is_dir($dir)) {
                    $console->comment("  SKIP  {$key} — directory not found");
                    continue;
                }

                $count = 0;
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($iterator as $item) {
                    if ($item->isFile()) {
                        @unlink($item->getPathname());
                        $count++;
                    }
                }

                $totalFiles += $count;
                $console->task("{$key} cache", true, "{$count} file(s)");
            }

            $console->newLine();
            $console->success("  Cache cleared successfully. ({$totalFiles} total files removed)");
            $console->newLine();
        }, 'Clear application cache [all|views|query|rate_limit|app]');

        $console->command('view:clear', function () use ($console) {
            $cacheDir = ROOT_DIR . 'storage/cache/views';

            if (!is_dir($cacheDir)) {
                $console->warn("  View cache directory not found.");
                return;
            }

            $count = 0;
            foreach (glob($cacheDir . '/*.php') as $file) {
                @unlink($file);
                $count++;
            }

            $console->newLine();
            $console->success("  Compiled views cleared ({$count} files).");
            $console->newLine();
        }, 'Clear compiled view files');

        $console->command('config:clear', function () use ($console) {
            $cacheFile = ROOT_DIR . 'storage/cache/config.php';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
                $console->success("  Configuration cache cleared.");
            } else {
                $console->info("  Configuration cache file not found. Nothing to clear.");
            }
        }, 'Remove the configuration cache file');
    }

    // ─── Route Commands ──────────────────────────────────────

    private static function routeCommands(Kernel $console): void
    {
        $console->command('route:list', function (array $args = [], array $options = []) use ($console) {
            $frameworkConfig = config('framework') ?? [];
            $router = new \Core\Routing\Router();
            $router->aliasMiddleware((array) ($frameworkConfig['middleware_aliases'] ?? []));

            $routeFiles = [
                'Web' => ROOT_DIR . ($frameworkConfig['route_files']['web'] ?? 'app/routes/web.php'),
                'API' => ROOT_DIR . ($frameworkConfig['route_files']['api'] ?? 'app/routes/api.php'),
            ];

            $filterMethod = strtoupper($options['method'] ?? '');
            $filterName = $options['name'] ?? '';
            $filterPath = $options['path'] ?? '';

            foreach ($routeFiles as $type => $file) {
                if (!file_exists($file)) {
                    continue;
                }

                $console->newLine();
                $console->info("  {$type} Routes");
                $console->line('  ' . str_repeat('─', 56));

                $routerRef = $router;
                (function () use ($routerRef, $file) {
                    $router = $routerRef;
                    require $file;
                })();

                $routes = $router->getRoutes();
                if (empty($routes)) {
                    $console->warn("  No routes registered.");
                    continue;
                }

                $rows = [];
                foreach ($routes as $route) {
                    // Apply filters
                    if ($filterMethod !== '' && $route->method !== $filterMethod) {
                        continue;
                    }
                    if ($filterPath !== '' && stripos($route->uri, $filterPath) === false) {
                        continue;
                    }

                    $name = $route->name ?? '';
                    if ($filterName !== '' && stripos($name, $filterName) === false) {
                        continue;
                    }

                    $middleware = !empty($route->middleware) ? implode(', ', $route->middleware) : '—';

                    $action = '';
                    if (is_array($route->action) && count($route->action) === 2) {
                        $class = is_string($route->action[0]) ? $route->action[0] : get_class($route->action[0]);
                        $shortClass = basename(str_replace('\\', '/', $class));
                        $action = $shortClass . '@' . $route->action[1];
                    } elseif ($route->action instanceof \Closure) {
                        $action = 'Closure';
                    }

                    $rows[] = [$route->method, $route->uri, $name ?: '—', $action, $middleware];
                }

                if (empty($rows)) {
                    $console->warn("  No routes match the given filters.");
                } else {
                    $console->table(['Method', 'URI', 'Name', 'Action', 'Middleware'], $rows);
                    $console->info("  Showing " . count($rows) . " route(s).");
                }

                $router = new \Core\Routing\Router();
                $router->aliasMiddleware((array) ($frameworkConfig['middleware_aliases'] ?? []));
            }

            $console->newLine();
        }, 'Display all registered routes [--method=GET] [--name=] [--path=]');
    }

    // ─── Make (Generator) Commands ───────────────────────────

    private static function makeCommands(Kernel $console): void
    {
        $console->command('make:controller', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:controller NameController [--resource] [--api]");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_\/\\\\]/', '', $name);
            if ($clean === '') {
                $console->error("  Invalid controller name.");
                return;
            }

            if (!str_ends_with($clean, 'Controller')) {
                $clean .= 'Controller';
            }

            $parts = explode('/', str_replace('\\', '/', $clean));
            $className = array_pop($parts);
            $subDir = !empty($parts) ? implode('/', $parts) . '/' : '';
            $namespace = 'App\\Http\\Controllers' . (!empty($parts) ? '\\' . implode('\\', $parts) : '');

            $path = ROOT_DIR . 'app/http/controllers/' . $subDir . $className . '.php';

            if (file_exists($path)) {
                $console->error("  Controller already exists: {$path}");
                return;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $isResource = isset($options['resource']);
            $isApi = isset($options['api']);

            if ($isResource || $isApi) {
                $content = <<<PHP
<?php

namespace {$namespace};

use Core\Http\Controller;
use Core\Http\Request;
use Core\Http\Response;

class {$className} extends Controller
{
    public function index(Request \$request)
    {
        //
    }

    public function store(Request \$request)
    {
        //
    }

    public function show(Request \$request, string \$id)
    {
        //
    }

    public function update(Request \$request, string \$id)
    {
        //
    }

    public function destroy(Request \$request, string \$id)
    {
        //
    }
}
PHP;
            } else {
                $content = <<<PHP
<?php

namespace {$namespace};

use Core\Http\Controller;
use Core\Http\Request;

class {$className} extends Controller
{
    public function index(Request \$request)
    {
        //
    }
}
PHP;
            }

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Controller created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a controller [--resource] [--api]');

        $console->command('make:middleware', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:middleware MiddlewareName");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_]/', '', $name);
            $path = ROOT_DIR . 'app/http/middleware/' . $clean . '.php';

            if (file_exists($path)) {
                $console->error("  Middleware already exists: {$path}");
                return;
            }

            $content = <<<PHP
<?php

namespace App\Http\Middleware;

use Core\Http\Request;
use Core\Http\Middleware\MiddlewareInterface;

class {$clean} implements MiddlewareInterface
{
    public function handle(Request \$request, callable \$next)
    {
        // Add your middleware logic here

        return \$next(\$request);
    }
}
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Middleware created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a middleware class');

        $console->command('make:request', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:request Api/StoreUserRequest");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_\/\\\\]/', '', $name);
            $parts = explode('/', str_replace('\\', '/', $clean));
            $className = array_pop($parts);
            $subDir = !empty($parts) ? implode('/', $parts) . '/' : '';
            $namespace = 'App\\Http\\Requests' . (!empty($parts) ? '\\' . implode('\\', $parts) : '');

            $path = ROOT_DIR . 'app/http/requests/' . $subDir . $className . '.php';

            if (file_exists($path)) {
                $console->error("  Request already exists: {$path}");
                return;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $content = <<<PHP
<?php

namespace {$namespace};

use Core\Http\FormRequest;

class {$className} extends FormRequest
{
    public function rules(): array
    {
        return [
            // 'field' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [];
    }
}
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Form request created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a form request validation class');

        $console->command('make:model', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:model ModelName");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_]/', '', $name);
            $path = ROOT_DIR . 'app/models/' . $clean . '.php';

            if (file_exists($path)) {
                $console->error("  Model already exists: {$path}");
                return;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $clean)) . 's';

            $content = <<<PHP
<?php

namespace App\Models;

class {$clean}
{
    protected string \$table = '{$table}';
    protected string \$primaryKey = 'id';

    public function all(): array
    {
        return db()->table(\$this->table)->get();
    }

    public function find(string|int \$id): ?array
    {
        return db()->table(\$this->table)
            ->where(\$this->primaryKey, \$id)
            ->fetch() ?: null;
    }

    public function create(array \$data): int
    {
        return db()->table(\$this->table)->insert(\$data);
    }

    public function update(string|int \$id, array \$data): bool
    {
        return (bool) db()->table(\$this->table)
            ->where(\$this->primaryKey, \$id)
            ->update(\$data);
    }

    public function delete(string|int \$id): bool
    {
        return (bool) db()->table(\$this->table)
            ->where(\$this->primaryKey, \$id)
            ->delete();
    }
}
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Model created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a model class');

        $console->command('make:job', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:job SendWelcomeEmail");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_\/\\\\]/', '', $name);
            $parts = explode('/', str_replace('\\', '/', $clean));
            $className = array_pop($parts);
            $subDir = !empty($parts) ? implode('/', $parts) . '/' : '';
            $namespace = 'App\\Jobs' . (!empty($parts) ? '\\' . implode('\\', $parts) : '');

            $path = ROOT_DIR . 'app/jobs/' . $subDir . $className . '.php';

            if (file_exists($path)) {
                $console->error("  Job already exists: {$path}");
                return;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $content = <<<PHP
<?php

namespace {$namespace};

use Core\Queue\Job;

class {$className} extends Job
{
    public function __construct(
        // Define your constructor parameters here
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Job logic here
    }

    /**
     * Handle a job failure (optional).
     */
    public function failed(\Throwable \$e): void
    {
        logger()->log_error("{$className} failed: " . \$e->getMessage());
    }
}
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Job created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a job class');

        $console->command('make:command', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:command SendReportEmails");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_]/', '', $name);
            $path = ROOT_DIR . 'app/console/commands/' . $clean . '.php';

            if (file_exists($path)) {
                $console->error("  Command already exists: {$path}");
                return;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $commandName = strtolower(preg_replace('/(?<!^)[A-Z]/', ':$0', $clean));
            $commandName = str_replace('::', ':', $commandName);

            $content = <<<PHP
<?php

namespace App\Console\Commands;

/**
 * Custom console command.
 *
 * Register in app/routes/console.php:
 *   (new \\App\\Console\\Commands\\{$clean}())->register(\$console);
 */
class {$clean}
{
    protected string \$name = '{$commandName}';
    protected string \$description = 'Description of the {$clean} command';

    public function register(\\Core\\Console\\Kernel \$console): void
    {
        \$console->command(\$this->name, [\$this, 'handle'], \$this->description);
    }

    public function handle(array \$args = [], array \$options = []): int
    {
        // Command logic here
        echo "Running {$commandName}..." . PHP_EOL;

        return 0;
    }
}
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Command created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a console command class');
    }

    // ─── Database Commands ───────────────────────────────────

    private static function databaseCommands(Kernel $console): void
    {
        $console->command('db:backup', function (array $args = [], array $options = []) use ($console) {
            $console->newLine();
            $console->info("  Running database backup...");

            try {
                $backup = new \Components\Backup();
                $result = $backup->database()->run();

                if ($result['success']) {
                    $console->task('Database backup', true);
                    $console->line("    Path: {$result['path']}");
                    $console->line("    Size: {$result['size']}");
                } else {
                    $console->task('Database backup', false);
                    $console->error("    {$result['error']}");
                }
            } catch (\Throwable $e) {
                $console->error("  Backup error: " . $e->getMessage());
            }

            $console->newLine();
        }, 'Create a database backup');

        $console->command('backup:run', function (array $args = [], array $options = []) use ($console) {
            $console->newLine();
            $console->info("  Running application backup...");

            try {
                $backup = new \Components\Backup();

                $onlyDb = isset($options['only-db']);
                $onlyFiles = isset($options['only-files']);

                if ($onlyDb) {
                    $result = $backup->database()->run();
                } elseif ($onlyFiles) {
                    $result = $backup->files()->run();
                } else {
                    $result = $backup->database()->files()->run();
                }

                if ($result['success']) {
                    $console->task('Application backup', true);
                    $console->line("    Path: {$result['path']}");
                    $console->line("    Size: {$result['size']}");
                } else {
                    $console->task('Application backup', false);
                    $console->error("    " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Throwable $e) {
                $console->error("  Backup error: " . $e->getMessage());
            }

            $console->newLine();
        }, 'Run full backup [--only-db] [--only-files]');

        $console->command('backup:clean', function (array $args = [], array $options = []) use ($console) {
            $days = isset($options['days']) ? (int) $options['days'] : 30;

            $console->newLine();
            $console->info("  Cleaning backups older than {$days} days...");

            try {
                $backup = new \Components\Backup();
                $cleaned = $backup->cleanup($days);
                $console->task("Old backup cleanup", true, "{$cleaned} removed");
            } catch (\Throwable $e) {
                $console->error("  Cleanup error: " . $e->getMessage());
            }

            $console->newLine();
        }, 'Clean old backups [--days=30]');

        $console->command('db:seed:sql', function (array $args = [], array $options = []) use ($console) {
            $file = $args[0] ?? null;
            $dir = ROOT_DIR . '#db/';

            if ($file !== null) {
                $path = $dir . $file;
                if (!file_exists($path)) {
                    $console->error("  Seed file not found: {$path}");
                    return;
                }

                if (!$console->confirm("  Run seed file: {$file}?")) {
                    $console->info("  Cancelled.");
                    return;
                }

                $sql = file_get_contents($path);
                try {
                    db()->rawQuery($sql);
                    $console->task("Seed: {$file}", true);
                } catch (\Throwable $e) {
                    $console->task("Seed: {$file}", false);
                    $console->error("    " . $e->getMessage());
                }
                return;
            }

            $console->newLine();
            if (!is_dir($dir)) {
                $console->warn("  No #db directory found.");
                return;
            }

            $files = glob($dir . '*.sql');
            if (empty($files)) {
                $console->warn("  No .sql files found in #db/.");
                return;
            }

            $console->info("  Available seed files:");
            foreach ($files as $f) {
                $console->line("    - " . basename($f));
            }
            $console->newLine();
        }, 'Run or list raw SQL seed files from #db/ [filename.sql]');
    }

    // ─── Migration Commands ──────────────────────────────────

    private static function migrationCommands(Kernel $console): void
    {
        $console->command('migrate', function (array $args = [], array $options = []) use ($console) {
            $console->newLine();
            $console->info("  Running migrations...");
            $console->newLine();

            $runner = new \Core\Database\Schema\MigrationRunner();
            $result = $runner->migrate(function (string $status, string $message) use ($console) {
                match ($status) {
                    'running'  => $console->comment("  RUNNING  {$message}"),
                    'migrated' => $console->task($message, true),
                    'info'     => $console->info("  {$message}"),
                    'error'    => $console->error("  ERROR  {$message}"),
                    default    => $console->line("  {$message}"),
                };
            });

            $console->newLine();
            $count = count($result['migrated']);
            if ($count > 0) {
                $console->success("  {$count} migration(s) executed successfully.");
            }
            if (!empty($result['errors'])) {
                $console->error("  " . count($result['errors']) . " migration(s) failed.");
            }
            $console->newLine();
        }, 'Run all pending database migrations');

        $console->command('migrate:rollback', function (array $args = [], array $options = []) use ($console) {
            $steps = isset($options['step']) ? (int) $options['step'] : null;

            $console->newLine();
            $console->info("  Rolling back migrations...");
            $console->newLine();

            $runner = new \Core\Database\Schema\MigrationRunner();
            $result = $runner->rollback(function (string $status, string $message) use ($console) {
                match ($status) {
                    'rolling_back' => $console->comment("  ROLLING BACK  {$message}"),
                    'rolled_back'  => $console->task($message, true, 'rolled back'),
                    'info'         => $console->info("  {$message}"),
                    'error'        => $console->error("  ERROR  {$message}"),
                    default        => $console->line("  {$message}"),
                };
            }, $steps);

            $console->newLine();
            $count = count($result['rolled_back']);
            if ($count > 0) {
                $console->success("  {$count} migration(s) rolled back successfully.");
            }
            if (!empty($result['errors'])) {
                $console->error("  " . count($result['errors']) . " rollback(s) failed.");
            }
            $console->newLine();
        }, 'Rollback the last batch of migrations [--step=N]');

        $console->command('migrate:reset', function (array $args = [], array $options = []) use ($console) {
            if (!$console->confirm("  Are you sure you want to reset ALL migrations?")) {
                $console->info("  Cancelled.");
                return;
            }

            $console->newLine();
            $console->info("  Resetting all migrations...");
            $console->newLine();

            $runner = new \Core\Database\Schema\MigrationRunner();
            $result = $runner->reset(function (string $status, string $message) use ($console) {
                match ($status) {
                    'rolling_back' => $console->comment("  ROLLING BACK  {$message}"),
                    'rolled_back'  => $console->task($message, true, 'rolled back'),
                    'info'         => $console->info("  {$message}"),
                    'error'        => $console->error("  ERROR  {$message}"),
                    default        => $console->line("  {$message}"),
                };
            });

            $console->newLine();
            $count = count($result['rolled_back']);
            $console->success("  {$count} migration(s) reset successfully.");
            $console->newLine();
        }, 'Rollback all database migrations');

        $console->command('migrate:fresh', function (array $args = [], array $options = []) use ($console) {
            if (!$console->confirm("  WARNING: This will DROP ALL TABLES. Continue?")) {
                $console->info("  Cancelled.");
                return;
            }

            $console->newLine();
            $console->info("  Running fresh migration...");
            $console->newLine();

            $runner = new \Core\Database\Schema\MigrationRunner();
            $result = $runner->fresh(function (string $status, string $message) use ($console) {
                match ($status) {
                    'dropped'  => $console->task("DROP {$message}", true),
                    'running'  => $console->comment("  RUNNING  {$message}"),
                    'migrated' => $console->task($message, true),
                    'info'     => $console->info("  {$message}"),
                    'error'    => $console->error("  ERROR  {$message}"),
                    default    => $console->line("  {$message}"),
                };
            });

            $console->newLine();
            $count = count($result['migrated']);
            if ($count > 0) {
                $console->success("  Fresh migration completed. {$count} migration(s) executed.");
            }
            if (!empty($result['errors'])) {
                $console->error("  " . count($result['errors']) . " error(s) occurred.");
            }
            $console->newLine();
        }, 'Drop all tables and re-run all migrations (DESTRUCTIVE)');

        $console->command('migrate:status', function (array $args = [], array $options = []) use ($console) {
            $runner = new \Core\Database\Schema\MigrationRunner();
            $status = $runner->status();

            $console->newLine();

            // Migrations
            $console->info("  Migrations");
            $console->line('  ' . str_repeat('─', 56));

            if (empty($status['migrations'])) {
                $console->warn("  No migration files found.");
            } else {
                $rows = [];
                foreach ($status['migrations'] as $m) {
                    $rows[] = [
                        $m['file'],
                        $m['batch'] ?? '—',
                        $m['status'],
                        $m['migrated_at'] ?? '—',
                    ];
                }
                $console->table(['Migration', 'Batch', 'Status', 'Migrated At'], $rows);
            }

            $console->newLine();

            // Seeders
            $console->info("  Seeders");
            $console->line('  ' . str_repeat('─', 56));

            if (empty($status['seeders'])) {
                $console->warn("  No seeder files found.");
            } else {
                $rows = [];
                foreach ($status['seeders'] as $s) {
                    $rows[] = [
                        $s['file'],
                        $s['status'],
                        $s['migrated_at'] ?? '—',
                    ];
                }
                $console->table(['Seeder', 'Status', 'Seeded At'], $rows);
            }

            $console->newLine();
        }, 'Show the status of all migrations and seeders');

        $console->command('db:seed', function (array $args = [], array $options = []) use ($console) {
            $specific = $options['class'] ?? ($args[0] ?? null);

            $console->newLine();
            $console->info("  Running seeders...");
            $console->newLine();

            $runner = new \Core\Database\Schema\MigrationRunner();
            $result = $runner->seed($specific, function (string $status, string $message) use ($console) {
                match ($status) {
                    'seeding' => $console->comment("  SEEDING  {$message}"),
                    'seeded'  => $console->task($message, true),
                    'skipped' => $console->comment("  SKIP  {$message}"),
                    'info'    => $console->info("  {$message}"),
                    'error'   => $console->error("  ERROR  {$message}"),
                    default   => $console->line("  {$message}"),
                };
            });

            $console->newLine();
            $count = count($result['seeded']);
            if ($count > 0) {
                $console->success("  {$count} seeder(s) executed successfully.");
            }
            if (!empty($result['errors'])) {
                $console->error("  " . count($result['errors']) . " seeder(s) failed.");
            }
            $console->newLine();
        }, 'Run database seeders [SeederName] [--class=SeederFile]');

        $console->command('make:migration', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:migration create_posts_table");
                $console->newLine();
                return;
            }

            // Sanitize name
            $clean = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));

            $migrationsDir = ROOT_DIR . 'app/database/migrations/';
            if (!is_dir($migrationsDir)) {
                mkdir($migrationsDir, 0775, true);
            }

            // Auto-number: find existing files for today and determine next sequence
            $datePrefix = date('Ymd');
            $existing = glob($migrationsDir . $datePrefix . '_*_*.php');
            $maxSeq = 0;
            foreach ($existing as $f) {
                $base = basename($f);
                if (preg_match('/^\d{8}_(\d+)_/', $base, $m)) {
                    $maxSeq = max($maxSeq, (int) $m[1]);
                }
            }
            $seq = str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);
            $filename = "{$datePrefix}_{$seq}_{$clean}.php";

            $path = $migrationsDir . $filename;

            if (file_exists($path)) {
                $console->error("  Migration already exists: {$filename}");
                return;
            }

            // Detect table name from migration name
            $tableName = 'table_name';
            if (preg_match('/^create_(.+)_table$/', $clean, $matches)) {
                $tableName = $matches[1];
            } elseif (preg_match('/^add_\w+_to_(.+)_table$/', $clean, $matches)) {
                $tableName = $matches[1];
            }

            $isCreate = str_starts_with($clean, 'create_');

            if ($isCreate) {
                $content = <<<PHP
<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string \$table = '{$tableName}';
    protected string \$connection = 'default';

    public function up(): void
    {
        Schema::create(\$this->table, function (Blueprint \$table) {
            \$table->id();
            // Add your columns here
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(\$this->table);
    }
};
PHP;
            } else {
                $content = <<<PHP
<?php

use Core\Database\Schema\Schema;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Migration;

return new class extends Migration
{
    protected string \$table = '{$tableName}';
    protected string \$connection = 'default';

    public function up(): void
    {
        Schema::table(\$this->table, function (Blueprint \$table) {
            // Add your changes here
        });
    }

    public function down(): void
    {
        Schema::table(\$this->table, function (Blueprint \$table) {
            // Reverse the changes
        });
    }
};
PHP;
            }

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Migration created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a new migration file');

        $console->command('make:seeder', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:seeder UsersSeeder");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_]/', '', $name);
            if (!str_ends_with($clean, 'Seeder')) {
                $clean .= 'Seeder';
            }

            $seedersDir = ROOT_DIR . 'app/database/seeders/';
            if (!is_dir($seedersDir)) {
                mkdir($seedersDir, 0775, true);
            }

            // Auto-number: find existing files for today and determine next sequence
            $datePrefix = date('Ymd');
            $existing = glob($seedersDir . $datePrefix . '_*_*.php');
            $maxSeq = 0;
            foreach ($existing as $f) {
                $base = basename($f);
                if (preg_match('/^\d{8}_(\d+)_/', $base, $m)) {
                    $maxSeq = max($maxSeq, (int) $m[1]);
                }
            }
            $seq = str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);
            $filename = "{$datePrefix}_{$seq}_{$clean}.php";

            $path = $seedersDir . $filename;

            if (file_exists($path)) {
                $console->error("  Seeder already exists: {$filename}");
                return;
            }

            // Detect table name from seeder name (e.g., MasterRolesSeeder → master_roles)
            $tableName = 'table_name';
            $baseName = preg_replace('/Seeder$/', '', $clean);
            if (!empty($baseName)) {
                // Convert PascalCase to snake_case
                $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $baseName));
            }

            $content = <<<PHP
<?php

use Core\Database\Schema\Seeder;

return new class extends Seeder
{
    protected string \$table = '{$tableName}';
    protected string \$connection = 'default';

    public function run(): void
    {
        // Example using insert:
        // \$this->insert(\$this->table, [
        //     'column1' => 'value1',
        //     'column2' => 'value2',
        // ]);

        // Example using insertOrUpdate:
        // \$this->insertOrUpdate(\$this->table, ['id' => 1], [
        //     'column1' => 'value1',
        //     'column2' => 'value2',
        // ]);
    }
};
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Seeder created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a new seeder file');
    }

    // ─── Serve Command ───────────────────────────────────────

    private static function serveCommand(Kernel $console): void
    {
        $console->command('serve', function (array $args = [], array $options = []) use ($console) {
            $host = $options['host'] ?? 'localhost';
            $port = $options['port'] ?? '8000';

            $console->newLine();
            $console->info("  SimplePHP development server started");
            $console->newLine();
            $console->line("  Local:   \033[4mhttp://{$host}:{$port}\033[0m");
            $console->newLine();
            $console->warn("  Press Ctrl+C to stop the server.");
            $console->newLine();

            $docRoot = ROOT_DIR;
            passthru("php -S " . escapeshellarg($host) . ":" . escapeshellarg($port) . " -t " . escapeshellarg($docRoot));
        }, 'Start the development server [--host=localhost] [--port=8000]');
    }

    // ─── Key Generation ──────────────────────────────────────

    private static function keyCommand(Kernel $console): void
    {
        $console->command('key:generate', function () use ($console) {
            $key = 'base64:' . base64_encode(random_bytes(32));
            $console->newLine();
            $console->success("  Application key generated:");
            $console->line("  {$key}");
            $console->newLine();
            $console->comment("  Add this to your config/config.php as 'app_key'.");
            $console->newLine();
        }, 'Generate a new application key');
    }

    // ─── Maintenance Commands ────────────────────────────────

    private static function maintenanceCommands(Kernel $console): void
    {
        $console->command('storage:link', function () use ($console) {
            $target = ROOT_DIR . 'storage/app/public';
            $link = ROOT_DIR . 'public/storage';

            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }

            if (file_exists($link)) {
                $console->warn("  The [public/storage] link already exists.");
                return;
            }

            if (PHP_OS_FAMILY === 'Windows') {
                exec("mklink /D " . escapeshellarg($link) . " " . escapeshellarg($target), $output, $code);
            } else {
                symlink($target, $link);
                $code = 0;
            }

            $console->newLine();
            if ($code === 0) {
                $console->success("  The [public/storage] link has been created.");
            } else {
                $console->error("  Failed to create symlink. Try running as administrator.");
            }
            $console->newLine();
        }, 'Create symbolic link from public/storage to storage/app/public');

        $console->command('down', function (array $args = [], array $options = []) use ($console) {
            $file = ROOT_DIR . 'storage/framework/down';
            $dir = dirname($file);

            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $payload = json_encode([
                'time'    => time(),
                'message' => $options['message'] ?? 'Service Unavailable',
                'retry'   => isset($options['retry']) ? (int) $options['retry'] : null,
                'secret'  => $options['secret'] ?? null,
            ], JSON_PRETTY_PRINT);

            file_put_contents($file, $payload);

            $console->newLine();
            $console->warn("  Application is now in maintenance mode.");
            $console->newLine();
        }, 'Put the application into maintenance mode [--message=] [--retry=] [--secret=]');

        $console->command('up', function () use ($console) {
            $file = ROOT_DIR . 'storage/framework/down';

            if (!file_exists($file)) {
                $console->info("  Application is already live.");
                return;
            }

            @unlink($file);

            $console->newLine();
            $console->success("  Application is now live.");
            $console->newLine();
        }, 'Bring the application out of maintenance mode');
    }

    // ─── Inspect Commands ────────────────────────────────────

    private static function inspectCommands(Kernel $console): void
    {
        $console->command('env', function () use ($console) {
            $console->newLine();
            $console->info("  Application Environment");
            $console->line('  ' . str_repeat('─', 40));

            $rows = [
                ['Environment', ENVIRONMENT],
                ['PHP Version', PHP_VERSION],
                ['Root Directory', ROOT_DIR],
                ['Base URL', defined('BASE_URL') ? BASE_URL : 'N/A'],
                ['Debug Mode', (ENVIRONMENT === 'development') ? 'ON' : 'OFF'],
                ['Timezone', date_default_timezone_get()],
                ['OS', PHP_OS_FAMILY],
            ];

            foreach ($rows as [$label, $value]) {
                $padding = str_repeat(' ', max(1, 22 - strlen($label)));
                $console->line("  \033[33m{$label}\033[0m{$padding}{$value}");
            }

            $console->newLine();
        }, 'Display the current environment information');

        $console->command('about', function () use ($console) {
            $console->newLine();
            $console->info("  ┌──────────────────────────────────────┐");
            $console->info("  │          SimplePHP Framework         │");
            $console->info("  └──────────────────────────────────────┘");
            $console->newLine();

            $sections = [
                'Environment' => [
                    'Application Name' => defined('APP_NAME') ? APP_NAME : 'SimplePHP',
                    'Environment'      => ENVIRONMENT,
                    'PHP Version'      => PHP_VERSION,
                    'Timezone'         => date_default_timezone_get(),
                ],
                'Drivers' => [
                    'Cache'    => 'file',
                    'Queue'    => config('queue.default') ?? 'database',
                    'Session'  => 'file',
                    'Database' => config('db.default.' . ENVIRONMENT . '.driver') ?? 'mysql',
                ],
                'Cache' => [
                    'Views'      => ROOT_DIR . 'storage/cache/views',
                    'Query'      => ROOT_DIR . 'storage/cache/query',
                    'Rate Limit' => ROOT_DIR . 'storage/cache/rate_limit',
                    'App'        => ROOT_DIR . 'storage/cache/app',
                ],
            ];

            foreach ($sections as $title => $items) {
                $console->warn("  {$title}");
                foreach ($items as $label => $value) {
                    $padding = str_repeat(' ', max(1, 24 - strlen($label)));
                    $console->line("    \033[32m{$label}\033[0m{$padding}{$value}");
                }
                $console->newLine();
            }
        }, 'Display basic information about the application');
    }

    // ─── Queue Commands ──────────────────────────────────────

    private static function queueCommands(Kernel $console): void
    {
        $console->command('queue:work', function (array $args = [], array $options = []) use ($console) {
            $queue = $args[0] ?? 'default';
            $sleep = isset($options['sleep']) ? (int) $options['sleep'] : 3;
            $tries = isset($options['tries']) ? (int) $options['tries'] : 3;
            $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 60;
            $once = isset($options['once']);

            $console->newLine();
            $console->info("  Processing jobs on [{$queue}] queue...");
            $console->comment("  Press Ctrl+C to stop the worker.");
            $console->newLine();

            $worker = new \Core\Queue\Worker();
            $worker->work($queue, [
                'sleep'   => $sleep,
                'tries'   => $tries,
                'timeout' => $timeout,
                'once'    => $once,
            ], function (string $status, string $message) use ($console) {
                match ($status) {
                    'processed' => $console->task($message, true),
                    'failed'    => $console->task($message, false),
                    'info'      => $console->comment("  {$message}"),
                    default     => $console->line("  {$message}"),
                };
            });
        }, 'Process jobs on the queue [queue_name] [--sleep=3] [--tries=3] [--timeout=60] [--once]');

        $console->command('queue:retry', function (array $args = [], array $options = []) use ($console) {
            $id = $args[0] ?? null;

            $worker = new \Core\Queue\Worker();

            if ($id === 'all') {
                $count = $worker->retryAll();
                $console->success("  {$count} failed job(s) pushed back to the queue.");
            } elseif ($id !== null) {
                if ($worker->retry($id)) {
                    $console->success("  Job [{$id}] pushed back to the queue.");
                } else {
                    $console->error("  Job [{$id}] not found in failed jobs.");
                }
            } else {
                $console->error("  Usage: php myth queue:retry {id|all}");
            }
        }, 'Retry a failed job by ID, or retry all [id|all]');

        $console->command('queue:failed', function () use ($console) {
            $worker = new \Core\Queue\Worker();
            $failed = $worker->listFailed();

            $console->newLine();

            if (empty($failed)) {
                $console->info("  No failed jobs found.");
                $console->newLine();
                return;
            }

            $rows = [];
            foreach ($failed as $job) {
                $rows[] = [
                    $job['id'] ?? '—',
                    $job['queue'] ?? 'default',
                    $job['payload']['class'] ?? 'Unknown',
                    $job['attempts'] ?? 0,
                    $job['failed_at'] ?? '—',
                    mb_strimwidth($job['error'] ?? '', 0, 50, '...'),
                ];
            }

            $console->table(['ID', 'Queue', 'Job', 'Attempts', 'Failed At', 'Error'], $rows);
            $console->info("  Showing " . count($rows) . " failed job(s).");
            $console->newLine();
        }, 'List all failed queue jobs');

        $console->command('queue:flush', function () use ($console) {
            $worker = new \Core\Queue\Worker();
            $count = $worker->flush();
            $console->success("  {$count} failed job(s) deleted.");
        }, 'Flush all failed queue jobs');

        $console->command('queue:clear', function (array $args = []) use ($console) {
            $queue = $args[0] ?? 'default';
            $worker = new \Core\Queue\Worker();
            $count = $worker->clear($queue);
            $console->success("  Cleared {$count} job(s) from [{$queue}] queue.");
        }, 'Clear all jobs from a queue [queue_name]');
    }
}
