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
        self::storageCommands($console);
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

        $console->command('view:cache', function () use ($console) {
            try {
                (new \Core\Console\Commands\ViewCacheCommand())->handle();
            } catch (\Throwable $e) {
                $console->error('  ' . $e->getMessage());
            }
        }, 'Pre-compile all Blade templates into the view cache');

        $console->command('config:cache', function () use ($console) {
            try {
                (new \Core\Console\Commands\ConfigCacheCommand())->handle();
            } catch (\Throwable $e) {
                $console->error('  ' . $e->getMessage());
            }
        }, 'Compile all config files into storage/cache/config.cache.php');

        $console->command('config:clear', function () use ($console) {
            // Remove both the legacy name and the current cache file name
            $files = [
                ROOT_DIR . 'storage/cache/config.php',
                ROOT_DIR . 'storage/cache/config.cache.php',
            ];
            $cleared = false;
            foreach ($files as $cacheFile) {
                if (file_exists($cacheFile)) {
                    @unlink($cacheFile);
                    $cleared = true;
                }
            }
            if ($cleared) {
                $console->success('  Configuration cache cleared.');
            } else {
                $console->info('  Configuration cache file not found. Nothing to clear.');
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

        $console->command('route:cache', function () use ($console) {
            try {
                (new \Core\Console\Commands\RouteCacheCommand())->handle();
            } catch (\Throwable $e) {
                $console->error('  ' . $e->getMessage());
            }
        }, 'Compile all routes into storage/cache/routes.cache.php');

        $console->command('route:clear', function () use ($console) {
            try {
                (new \Core\Console\Commands\RouteClearCommand())->handle();
            } catch (\Throwable $e) {
                $console->error('  ' . $e->getMessage());
            }
        }, 'Remove the route cache file');
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

        $console->command('make:repository', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:repository UserRepository [--table=users]");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_\/\\\\]/', '', $name);
            $parts = explode('/', str_replace('\\\\', '/', $clean));
            $className = array_pop($parts);
            $subDir = !empty($parts) ? implode('/', $parts) . '/' : '';
            $namespace = 'App\\Repositories' . (!empty($parts) ? '\\' . implode('\\', $parts) : '');

            if (!str_ends_with($className, 'Repository')) {
                $className .= 'Repository';
            }

            $path = ROOT_DIR . 'app/repositories/' . $subDir . $className . '.php';

            if (file_exists($path)) {
                $console->error("  Repository already exists: {$path}");
                return;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $table = trim((string) ($options['table'] ?? ''));
            if ($table === '') {
                $base = preg_replace('/Repository$/', '', $className);
                $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $base)) . 's';
            }

            $content = <<<PHP
<?php

namespace {$namespace};

class {$className}
{
    protected string \$table = '{$table}';

    public function all(): array
    {
        return db()->table(\$this->table)->get();
    }

    public function find(int|string \$id): ?array
    {
        return db()->table(\$this->table)->where('id', \$id)->fetch() ?: null;
    }

    public function paginate(int \$start = 0, int \$limit = 20, int \$draw = 1): array
    {
        return db()->table(\$this->table)->paginate(\$start, \$limit, \$draw);
    }

    public function create(array \$data): array
    {
        return db()->table(\$this->table)->insert(\$data);
    }

    public function update(int|string \$id, array \$data): array
    {
        return db()->table(\$this->table)->where('id', \$id)->update(\$data);
    }

    public function delete(int|string \$id): array
    {
        return db()->table(\$this->table)->where('id', \$id)->delete();
    }
}
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  Repository created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a lightweight query-builder repository [--table=users]');

        $console->command('make:dto', function (array $args = [], array $options = []) use ($console) {
            $name = $args[0] ?? null;

            if (empty($name)) {
                $console->newLine();
                $console->error("  Usage: php myth make:dto UserDTO");
                $console->newLine();
                return;
            }

            $clean = preg_replace('/[^A-Za-z0-9_\/\\\\]/', '', $name);
            $parts = explode('/', str_replace('\\\\', '/', $clean));
            $className = array_pop($parts);
            $subDir = !empty($parts) ? implode('/', $parts) . '/' : '';
            $namespace = 'App\\DTO' . (!empty($parts) ? '\\' . implode('\\', $parts) : '');

            if (!str_ends_with($className, 'DTO')) {
                $className .= 'DTO';
            }

            $path = ROOT_DIR . 'app/DTO/' . $subDir . $className . '.php';

            if (file_exists($path)) {
                $console->error("  DTO already exists: {$path}");
                return;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $content = <<<PHP
<?php

namespace {$namespace};

class {$className} implements \JsonSerializable
{
    protected array \$attributes = [];

    public function __construct(array \$attributes = [])
    {
        \$this->attributes = \$attributes;
    }

    public static function fromArray(array \$attributes): self
    {
        return new self(\$attributes);
    }

    public function get(string \$key, mixed \$default = null): mixed
    {
        return \$this->attributes[\$key] ?? \$default;
    }

    public function set(string \$key, mixed \$value): self
    {
        \$this->attributes[\$key] = \$value;
        return \$this;
    }

    public function toArray(): array
    {
        return \$this->attributes;
    }

    public function jsonSerialize(): array
    {
        return \$this->toArray();
    }
}
PHP;

            file_put_contents($path, $content);
            $console->newLine();
            $console->success("  DTO created successfully.");
            $console->line("  → {$path}");
            $console->newLine();
        }, 'Generate a DTO/value object class');
    }

    // ─── Database Commands ───────────────────────────────────

    private static function databaseCommands(Kernel $console): void
    {
        $console->command('db:backup', function (array $args = [], array $options = []) use ($console) {
            $console->newLine();
            $console->info("  Running database backup...");

            try {
                $backup = new \Components\Backup();
                if (!empty($options['disk'])) {
                    $backup->setBackupDisk((string) $options['disk'], isset($options['prefix']) ? (string) $options['prefix'] : null);
                }
                $result = $backup->database()->run();

                if ($result['success']) {
                    $console->task('Database backup', true);
                    $console->line("    Path: {$result['path']}");
                    if (!empty($result['disk'])) {
                        $console->line("    Published Disk: {$result['disk']}");
                        $console->line("    Published Path: {$result['disk_path']}");
                    }
                    $console->line("    Size: {$result['size']}");
                } else {
                    $console->task('Database backup', false);
                    $console->error("    {$result['error']}");
                }
            } catch (\Throwable $e) {
                $console->error("  Backup error: " . $e->getMessage());
            }

            $console->newLine();
        }, 'Create a database backup [--disk=name] [--prefix=path]');

        $console->command('backup:run', function (array $args = [], array $options = []) use ($console) {
            $console->newLine();
            $console->info("  Running application backup...");

            try {
                $backup = new \Components\Backup();
                if (!empty($options['disk'])) {
                    $backup->setBackupDisk((string) $options['disk'], isset($options['prefix']) ? (string) $options['prefix'] : null);
                }

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
                    if (!empty($result['disk'])) {
                        $console->line("    Published Disk: {$result['disk']}");
                        $console->line("    Published Path: {$result['disk_path']}");
                    }
                    $console->line("    Size: {$result['size']}");
                } else {
                    $console->task('Application backup', false);
                    $console->error("    " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Throwable $e) {
                $console->error("  Backup error: " . $e->getMessage());
            }

            $console->newLine();
        }, 'Run full backup [--only-db] [--only-files] [--disk=name] [--prefix=path]');

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

    private static function storageCommands(Kernel $console): void
    {
        $console->command('storage:check', function (array $args = [], array $options = []) use ($console) {
            $disk = trim((string) ($options['disk'] ?? $args[0] ?? ''));
            if ($disk === '') {
                $console->error('  Storage check requires --disk=name or a disk name argument.');
                $console->newLine();
                return 1;
            }

            $path = trim((string) ($options['path'] ?? 'healthchecks/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.bin'));
            $bytes = isset($options['bytes']) ? max(1, (int) $options['bytes']) : 1048576;
            $keep = isset($options['keep']);

            $console->newLine();
            $console->info("  Probing storage disk [{$disk}]...");

            $writeStream = fopen('php://temp/maxmemory:2097152', 'r+b');
            if ($writeStream === false) {
                $console->error('  Failed to allocate the probe stream.');
                $console->newLine();
                return 1;
            }

            $expectedHash = hash_init('sha256');
            $pattern = str_repeat('0123456789abcdef', 4096);
            $remaining = $bytes;

            while ($remaining > 0) {
                $chunk = substr($pattern, 0, min($remaining, strlen($pattern)));
                fwrite($writeStream, $chunk);
                hash_update($expectedHash, $chunk);
                $remaining -= strlen($chunk);
            }

            rewind($writeStream);

            try {
                $storage = storage($disk);
                $console->task('Resolve disk', true, $disk);

                $writeOk = $storage->writeStream($path, $writeStream);
                $console->task('Write stream', $writeOk, $path);
                if (!$writeOk) {
                    throw new \RuntimeException('Disk writeStream returned false.');
                }

                $exists = $storage->exists($path);
                $console->task('Existence check', $exists, $path);
                if (!$exists) {
                    throw new \RuntimeException('Probe file was not found after write.');
                }

                $readStream = $storage->readStream($path);
                if (!is_resource($readStream)) {
                    throw new \RuntimeException('Disk readStream did not return a stream resource.');
                }

                try {
                    $actualHash = hash_init('sha256');
                    $actualBytes = 0;
                    while (!feof($readStream)) {
                        $chunk = fread($readStream, 1048576);
                        if ($chunk === false) {
                            throw new \RuntimeException('Failed to read probe file from the disk stream.');
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        $actualBytes += strlen($chunk);
                        hash_update($actualHash, $chunk);
                    }
                } finally {
                    fclose($readStream);
                }

                $expectedDigest = hash_final($expectedHash);
                $actualDigest = hash_final($actualHash);
                $integrityOk = $actualBytes === $bytes && hash_equals($expectedDigest, $actualDigest);
                $console->task('Integrity check', $integrityOk, $actualBytes . ' bytes');
                if (!$integrityOk) {
                    throw new \RuntimeException('Probe file integrity verification failed.');
                }

                $url = $storage->url($path);
                $console->line("    URL: {$url}");

                if (!$keep) {
                    $deleted = $storage->delete($path);
                    $console->task('Cleanup probe', $deleted, $path);
                    if (!$deleted) {
                        throw new \RuntimeException('Failed to remove the probe file after verification.');
                    }
                } else {
                    $console->comment("  Probe file retained at {$path}");
                }

                $console->success('  Storage disk probe completed successfully.');
                $console->newLine();
                return 0;
            } catch (\Throwable $e) {
                $console->error('  Storage probe failed: ' . $e->getMessage());
                $console->newLine();
                return 1;
            } finally {
                fclose($writeStream);
            }
        }, 'Probe a storage disk with streamed write/read verification [disk] [--path=file] [--bytes=N] [--keep]');
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
            $console->info("  MythPHP development server started");
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
                'refresh' => isset($options['refresh']) ? (int) $options['refresh'] : null,
                'secret'  => $options['secret'] ?? null,
                'status'  => isset($options['status']) ? (int) $options['status'] : 503,
                'redirect' => isset($options['redirect']) ? (string) $options['redirect'] : null,
                'render' => isset($options['render']) ? (string) $options['render'] : null,
            ], JSON_PRETTY_PRINT);

            file_put_contents($file, $payload);

            $console->newLine();
            $console->warn("  Application is now in maintenance mode.");
            $console->newLine();
        }, 'Put the application into maintenance mode [--message=] [--retry=] [--refresh=] [--secret=] [--status=] [--redirect=] [--render=]');

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

        $console->command('env:check', function (array $args = [], array $options = []) use ($console) {
            $strictMode = isset($options['strict']) || isset($options['ci']);
            $showValues = isset($options['show-values']);

            $passed = [];
            $warnings = [];
            $failures = [];

            $envFile = ROOT_DIR . '.env';
            if (is_file($envFile) && is_readable($envFile)) {
                $passed[] = ['.env', 'env file found and readable'];
            } else {
                $warnings[] = ['.env', 'env file not found/readable; relying on system environment variables'];
            }

            $getRaw = static function (string $key): ?string {
                $value = getenv($key);
                if ($value === false) {
                    $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
                }

                if ($value === null) {
                    return null;
                }

                if (!is_scalar($value)) {
                    return null;
                }

                return trim((string) $value);
            };

            $mask = static function (?string $value): string {
                if ($value === null) {
                    return '[missing]';
                }

                $length = strlen($value);
                if ($length <= 4) {
                    return str_repeat('*', max(1, $length));
                }

                return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
            };

            $display = static function (?string $raw, bool $secret, bool $showValues) use ($mask): string {
                if ($raw === null) {
                    return '[missing]';
                }

                if ($raw === '') {
                    return '[empty]';
                }

                if ($secret && !$showValues) {
                    return $mask($raw);
                }

                return $raw;
            };

            $checkRequired = static function (string $key, bool $secret = false) use (&$passed, &$failures, $getRaw, $display, $showValues): ?string {
                $raw = $getRaw($key);
                if ($raw === null || $raw === '') {
                    $failures[] = [$key, 'required value missing'];
                    return null;
                }

                $passed[] = [$key, 'set (' . $display($raw, $secret, $showValues) . ')'];
                return $raw;
            };

            $checkBoolean = static function (string $key, bool $required = false) use (&$passed, &$warnings, &$failures, $getRaw): void {
                $raw = $getRaw($key);

                if ($raw === null || $raw === '') {
                    if ($required) {
                        $failures[] = [$key, 'boolean value missing'];
                    } else {
                        $warnings[] = [$key, 'not set; using config default'];
                    }
                    return;
                }

                $normalized = strtolower($raw);
                $valid = in_array($normalized, ['true', 'false', '(true)', '(false)'], true);

                if (!$valid) {
                    $failures[] = [$key, "invalid boolean '{$raw}' (use true/false)"];
                    return;
                }

                $passed[] = [$key, 'valid boolean'];
            };

            $checkInteger = static function (string $key, int $min = 0, ?int $max = null, bool $required = false) use (&$passed, &$warnings, &$failures, $getRaw): void {
                $raw = $getRaw($key);

                if ($raw === null || $raw === '') {
                    if ($required) {
                        $failures[] = [$key, 'integer value missing'];
                    } else {
                        $warnings[] = [$key, 'not set; using config default'];
                    }
                    return;
                }

                if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
                    $failures[] = [$key, "invalid integer '{$raw}'"];
                    return;
                }

                $intVal = (int) $raw;
                if ($intVal < $min) {
                    $failures[] = [$key, "must be >= {$min}"];
                    return;
                }
                if ($max !== null && $intVal > $max) {
                    $failures[] = [$key, "must be <= {$max}"];
                    return;
                }

                $passed[] = [$key, 'valid integer'];
            };

            $requireIfEnabled = static function (string $toggleKey, string $requiredKey, bool $secret = false) use (&$passed, &$failures, $getRaw, $display, $showValues): void {
                $rawToggle = strtolower((string) ($getRaw($toggleKey) ?? 'false'));
                $enabled = in_array($rawToggle, ['true', '(true)'], true);

                if (!$enabled) {
                    return;
                }

                $required = $getRaw($requiredKey);
                if ($required === null || $required === '') {
                    $failures[] = [$toggleKey, "enabled but '{$requiredKey}' is missing"];
                    return;
                }

                $passed[] = [$requiredKey, 'set (' . $display($required, $secret, $showValues) . ')'];
                $passed[] = [$toggleKey, "enabled and '{$requiredKey}' is set"];
            };

            $requiredKeys = [
                'APP_ENV',
                'APP_DEBUG',
                'APP_TIMEZONE',
                'DB_CONNECTION',
                'DB_HOST',
                'DB_PORT',
                'DB_DATABASE',
                'DB_USERNAME',
                'DB_CHARSET',
            ];

            foreach ($requiredKeys as $requiredKey) {
                $checkRequired($requiredKey);
            }

            $appEnv = strtolower((string) env('APP_ENV', 'development'));
            if (!in_array($appEnv, ['development', 'staging', 'production'], true)) {
                $failures[] = ['APP_ENV', "invalid value '{$appEnv}' (expected development|staging|production)"];
            } else {
                $passed[] = ['APP_ENV', 'supported environment'];
            }

            $checkBoolean('APP_DEBUG', true);
            $checkBoolean('DB_PROFILING_ENABLED');
            $checkBoolean('DB_CACHE_ENABLED');
            $checkBoolean('API_AUTH_REQUIRED');
            $checkBoolean('API_VERSIONING_ENABLED');
            $checkBoolean('API_RATE_LIMIT_ENABLED');
            $checkBoolean('API_CORS_ALLOW_CREDENTIALS');
            $checkBoolean('AUTH_JWT_ENABLED');
            $checkBoolean('AUTH_API_KEY_ENABLED');
            $checkBoolean('AUTH_OAUTH2_ENABLED');
            $checkBoolean('AUTH_BASIC_ENABLED');
            $checkBoolean('AUTH_DIGEST_ENABLED');
            $checkBoolean('RECAPTCHA_ENABLED');

            $checkInteger('DB_PORT', 1, 65535, true);
            $checkInteger('DB_CACHE_TTL', 0);
            $checkInteger('AUTH_LOGIN_POLICY_MAX_ATTEMPTS', 1);
            $checkInteger('AUTH_LOGIN_POLICY_DECAY_SECONDS', 1);
            $checkInteger('AUTH_LOGIN_POLICY_LOCKOUT_SECONDS', 1);
            $checkInteger('AUTH_SESSION_MAX_DEVICES', 0);
            $checkInteger('AUTH_SESSION_CONCURRENCY_TTL', 1);
            $checkInteger('AUTH_DIGEST_NONCE_TTL', 1);
            $checkInteger('AUTH_DIGEST_NONCE_FUTURE_SKEW', 0);

            if ($appEnv === 'production') {
                if ((bool) env('APP_DEBUG', true)) {
                    $failures[] = ['APP_DEBUG', 'must be false in production'];
                } else {
                    $passed[] = ['APP_DEBUG', 'disabled in production'];
                }

                $checkRequired('APP_KEY', true);
            }

            $authMethods = array_map('strtolower', env_list('AUTH_METHODS', ['session']));
            if (empty($authMethods)) {
                $warnings[] = ['AUTH_METHODS', 'empty list; config defaults may be used'];
            }

            $apiMethods = array_map('strtolower', env_list('API_AUTH_METHODS', ['token']));
            if ((bool) env('API_AUTH_REQUIRED', true) && empty($apiMethods)) {
                $failures[] = ['API_AUTH_METHODS', 'cannot be empty when API_AUTH_REQUIRED=true'];
            }

            if (in_array('jwt', $authMethods, true) || (bool) env('AUTH_JWT_ENABLED', false)) {
                $checkRequired('AUTH_JWT_SECRET', true);
            }

            if (in_array('digest', $authMethods, true) || (bool) env('AUTH_DIGEST_ENABLED', false)) {
                $checkRequired('AUTH_DIGEST_NONCE_SECRET', true);
            }

            $requireIfEnabled('RECAPTCHA_ENABLED', 'RECAPTCHA_SITE_KEY', false);
            $requireIfEnabled('RECAPTCHA_ENABLED', 'RECAPTCHA_SECRET_KEY', true);

            if (strtolower((string) env('MAIL_DRIVER', 'smtp')) === 'smtp') {
                $checkRequired('MAIL_HOST');
                $checkRequired('MAIL_PORT');
                $checkInteger('MAIL_PORT', 1, 65535, true);
            }

            $console->newLine();
            $console->info('  MythPHP Environment Check');
            $console->line('  ' . str_repeat('─', 56));

            if (!empty($passed)) {
                $console->table(['PASS', 'Detail'], $passed);
            }
            if (!empty($warnings)) {
                $console->newLine();
                $console->warn('  Warnings');
                $console->table(['WARN', 'Detail'], $warnings);
            }
            if (!empty($failures)) {
                $console->newLine();
                $console->error('  Failed checks');
                $console->table(['FAIL', 'Detail'], $failures);
            }

            $failCount = count($failures);
            $warnCount = count($warnings);

            $console->newLine();
            $console->line('  Summary: ' . count($passed) . ' passed, ' . $warnCount . ' warning(s), ' . $failCount . ' failed.');
            $console->line('  Mode: ' . ($strictMode ? 'strict' : 'normal'));
            if (!$showValues) {
                $console->line('  Tip: use --show-values to display non-secret values in output.');
            }
            $console->newLine();

            if ($failCount > 0) {
                return 1;
            }

            if ($strictMode && $warnCount > 0) {
                return 2;
            }

            return 0;
        }, 'Validate environment variables and security-sensitive env settings [--strict] [--ci] [--show-values]');

        $console->command('about', function () use ($console) {
            $console->newLine();
            $console->info("  ┌──────────────────────────────────────┐");
            $console->info("  │           MythPHP Framework          │");
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

        $console->command('security:audit', function (array $args = [], array $options = []) use ($console) {
            $security = (array) (config('security') ?? []);
            $framework = (array) (config('framework') ?? []);
            $api = (array) (config('api') ?? []);

            $failures = [];
            $warnings = [];
            $passed = [];

            $record = static function (bool $ok, string $label, string $message, array &$passed, array &$failures): void {
                if ($ok) {
                    $passed[] = [$label, $message];
                    return;
                }
                $failures[] = [$label, $message];
            };

            $record((bool) ($security['csrf']['csrf_protection'] ?? false), 'CSRF', 'csrf_protection enabled', $passed, $failures);
            $record((bool) ($security['csrf']['csrf_origin_check'] ?? false), 'CSRF', 'csrf_origin_check enabled', $passed, $failures);
            $record((bool) ($security['request_hardening']['enabled'] ?? false), 'Request Hardening', 'request_hardening enabled', $passed, $failures);
            $record((bool) ($security['csp']['enabled'] ?? false), 'CSP', 'csp enabled', $passed, $failures);

            $scriptSrc = (array) ($security['csp']['script-src'] ?? []);
            if (in_array("'unsafe-eval'", $scriptSrc, true)) {
                $warnings[] = ['CSP', "script-src contains 'unsafe-eval'"];
            } else {
                $passed[] = ['CSP', "script-src excludes 'unsafe-eval'"];
            }

            $aliases = (array) ($framework['middleware_aliases'] ?? []);
            $groups = (array) ($framework['middleware_groups'] ?? []);
            $record(isset($aliases['request.safety']), 'Middleware', 'request.safety alias registered', $passed, $failures);
            $record(in_array('request.safety', (array) ($groups['web'] ?? []), true), 'Middleware', 'web group includes request.safety', $passed, $failures);
            $record(in_array('request.safety', (array) ($groups['api'] ?? []), true), 'Middleware', 'api group includes request.safety', $passed, $failures);
            $record(in_array('xss', (array) ($groups['api'] ?? []), true), 'Middleware', 'api group includes xss', $passed, $failures);

            $cors = (array) ($api['cors'] ?? []);
            $allowOrigin = (array) ($cors['allow_origin'] ?? []);
            $authRequired = (bool) ($api['auth']['required'] ?? false);
            $allowCredentials = (bool) ($cors['allow_credentials'] ?? false);
            $allowWildcardWithAuth = (bool) ($cors['allow_wildcard_with_auth'] ?? false);
            $hasWildcard = in_array('*', $allowOrigin, true);

            if ($allowCredentials && $hasWildcard) {
                $failures[] = ['CORS', 'allow_credentials=true cannot be combined with allow_origin=*'];
            } else {
                $passed[] = ['CORS', 'credentials/origin policy is valid'];
            }

            if ($authRequired && $hasWildcard && $allowWildcardWithAuth) {
                $failures[] = ['CORS', 'authenticated API cannot allow wildcard origins'];
            } else {
                $passed[] = ['CORS', 'authenticated wildcard policy is restricted'];
            }

            $trustedProxies = (array) ($security['trusted_proxies'] ?? []);
            if (in_array('*', $trustedProxies, true) || in_array('0.0.0.0/0', $trustedProxies, true)) {
                $failures[] = ['Proxy Trust', 'trusted_proxies must not contain global wildcard values'];
            } else {
                $passed[] = ['Proxy Trust', 'trusted_proxies does not include global wildcard'];
            }

            $strictMode = isset($options['strict']) || isset($options['ci']);

            $console->newLine();
            $console->info('  MythPHP Security Audit (OWASP baseline)');
            $console->line('  ' . str_repeat('─', 56));

            if (!empty($passed)) {
                $console->table(['PASS', 'Detail'], $passed);
            }
            if (!empty($warnings)) {
                $console->newLine();
                $console->warn('  Warnings');
                $console->table(['WARN', 'Detail'], $warnings);
            }
            if (!empty($failures)) {
                $console->newLine();
                $console->error('  Failed checks');
                $console->table(['FAIL', 'Detail'], $failures);
            }

            $failCount = count($failures);
            $warnCount = count($warnings);
            $console->newLine();
            $console->line('  Summary: ' . count($passed) . ' passed, ' . $warnCount . ' warning(s), ' . $failCount . ' failed.');
            $console->line('  Mode: ' . ($strictMode ? 'strict' : 'normal'));
            $console->newLine();

            if ($failCount > 0) {
                return 1;
            }

            if ($strictMode && $warnCount > 0) {
                return 2;
            }

            return 0;
        }, 'Run OWASP-aligned security audit checks [--strict] [--ci]');

        $console->command('auth:security:test', function (array $args = [], array $options = []) use ($console) {
            $authConfig = (array) (config('auth') ?? []);
            $strictMode = isset($options['strict']) || isset($options['ci']);

            $failures = [];
            $warnings = [];
            $passed = [];

            $record = static function (bool $ok, string $label, string $message, array &$passed, array &$failures): void {
                if ($ok) {
                    $passed[] = [$label, $message];
                    return;
                }

                $failures[] = [$label, $message];
            };

            $invokePrivate = static function (object $object, string $method, array $args = []) {
                $ref = new \ReflectionMethod($object, $method);
                $ref->setAccessible(true);
                return $ref->invokeArgs($object, $args);
            };

            $base64UrlEncode = static function (string $value): string {
                return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            };

            $makeJwt = static function (array $header, array $payload, string $secret, string $algo = 'sha256') use ($base64UrlEncode): string {
                $encodedHeader = $base64UrlEncode((string) json_encode($header));
                $encodedPayload = $base64UrlEncode((string) json_encode($payload));
                $signature = hash_hmac($algo, $encodedHeader . '.' . $encodedPayload, $secret, true);
                return $encodedHeader . '.' . $encodedPayload . '.' . $base64UrlEncode($signature);
            };

            $methods = array_values((array) ($authConfig['methods'] ?? []));
            $leastPrivilegeDefaults = [
                ['session'],
                ['session', 'token'],
            ];
            $record(
                in_array($methods, $leastPrivilegeDefaults, true),
                'Auth Config',
                'default methods use least-privilege baseline (session-first)',
                $passed,
                $failures
            );

            $allowQueryParam = (($authConfig['api_key']['allow_query_param'] ?? false) === true);
            if ($allowQueryParam) {
                $warnings[] = ['Auth Config', 'api_key.allow_query_param is enabled (higher leakage risk)'];
            } else {
                $passed[] = ['Auth Config', 'api_key.allow_query_param is disabled'];
            }

            // JWT tamper checks: valid token passes, alg mismatch is rejected.
            $jwtSecret = 'ci-jwt-test-secret';
            $jwtConfig = array_replace_recursive($authConfig, [
                'jwt' => [
                    'enabled' => true,
                    'algo' => 'HS256',
                    'secret' => $jwtSecret,
                    'leeway' => 0,
                    'user_id_claim' => 'sub',
                ],
            ]);
            $jwtAuth = new \Components\Auth($jwtConfig);
            $validToken = $makeJwt(
                ['alg' => 'HS256', 'typ' => 'JWT'],
                ['sub' => 321, 'exp' => time() + 300],
                $jwtSecret,
                'sha256'
            );
            $tamperedAlgToken = $makeJwt(
                ['alg' => 'HS512', 'typ' => 'JWT'],
                ['sub' => 321, 'exp' => time() + 300],
                $jwtSecret,
                'sha256'
            );

            $decodedValid = $invokePrivate($jwtAuth, 'decodeJwt', [$validToken]);
            $decodedTampered = $invokePrivate($jwtAuth, 'decodeJwt', [$tamperedAlgToken]);
            $record(is_array($decodedValid) && ((int) ($decodedValid['sub'] ?? 0) === 321), 'JWT', 'valid JWT accepted', $passed, $failures);
            $record($decodedTampered === null, 'JWT', 'JWT tamper via header alg mismatch rejected', $passed, $failures);

            // Digest replay checks: same nonce/counter replay must fail.
            $digestAuth = new \Components\Auth($authConfig);
            $nonce = 'ci-nonce-' . bin2hex(random_bytes(8));
            $digestFirst = (bool) $invokePrivate($digestAuth, 'isDigestNonceCounterValid', ['ci-user', $nonce, '00000001']);
            $digestReplay = (bool) $invokePrivate($digestAuth, 'isDigestNonceCounterValid', ['ci-user', $nonce, '00000001']);
            $digestIncrement = (bool) $invokePrivate($digestAuth, 'isDigestNonceCounterValid', ['ci-user', $nonce, '00000002']);
            $record($digestFirst, 'Digest', 'initial nonce counter accepted', $passed, $failures);
            $record($digestReplay === false, 'Digest', 'nonce counter replay rejected', $passed, $failures);
            $record($digestIncrement, 'Digest', 'higher nonce counter accepted', $passed, $failures);

            // API key query rejection checks.
            $previousGet = $_GET;
            $previousServer = $_SERVER;

            try {
                $_GET['api_key'] = 'query-key-should-not-pass';
                unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION']);

                $apiKeyNoQueryAuth = new \Components\Auth(array_replace_recursive($authConfig, [
                    'api_key' => [
                        'enabled' => true,
                        'header' => 'X-API-KEY',
                        'query_param' => 'api_key',
                        'allow_query_param' => false,
                    ],
                ]));
                $extractedNoQuery = $invokePrivate($apiKeyNoQueryAuth, 'extractApiKey');
                $record($extractedNoQuery === null, 'API Key', 'query-string API key rejected when allow_query_param=false', $passed, $failures);

                $apiKeyAllowQueryAuth = new \Components\Auth(array_replace_recursive($authConfig, [
                    'api_key' => [
                        'enabled' => true,
                        'header' => 'X-API-KEY',
                        'query_param' => 'api_key',
                        'allow_query_param' => true,
                    ],
                ]));
                $extractedWithQuery = $invokePrivate($apiKeyAllowQueryAuth, 'extractApiKey');
                $record($extractedWithQuery === 'query-key-should-not-pass', 'API Key', 'query-string API key accepted when explicitly enabled', $passed, $failures);
            } finally {
                $_GET = $previousGet;
                $_SERVER = $previousServer;
            }

            $console->newLine();
            $console->info('  MythPHP Auth Security Tests');
            $console->line('  ' . str_repeat('─', 56));

            if (!empty($passed)) {
                $console->table(['PASS', 'Detail'], $passed);
            }

            if (!empty($warnings)) {
                $console->newLine();
                $console->warn('  Warnings');
                $console->table(['WARN', 'Detail'], $warnings);
            }

            if (!empty($failures)) {
                $console->newLine();
                $console->error('  Failed tests');
                $console->table(['FAIL', 'Detail'], $failures);
            }

            $failCount = count($failures);
            $warnCount = count($warnings);

            $console->newLine();
            $console->line('  Summary: ' . count($passed) . ' passed, ' . $warnCount . ' warning(s), ' . $failCount . ' failed.');
            $console->line('  Mode: ' . ($strictMode ? 'strict' : 'normal'));
            $console->newLine();

            if ($failCount > 0) {
                return 1;
            }

            if ($strictMode && $warnCount > 0) {
                return 2;
            }

            return 0;
        }, 'Run auth hardening tests (JWT tamper, Digest replay, API key leakage) [--strict] [--ci]');

        $console->command('perf:benchmark', function (array $args = [], array $options = []) use ($console) {
            $iterations = max(10, (int) ($options['iterations'] ?? 200));
            $routeCount = max(10, (int) ($options['routes'] ?? 200));
            $dbIterations = max(5, (int) ($options['db-iterations'] ?? 100));

            $console->newLine();
            $console->info('  Running MythPHP performance benchmark...');
            $console->line('  This benchmark focuses on routing, validation, and query workload baselines.');
            $console->newLine();

            $results = [];

            // Routing benchmark
            $router = new \Core\Routing\Router();
            for ($i = 0; $i < $routeCount; $i++) {
                $router->get('/perf/route-' . $i, function () {
                    return ['code' => 200, 'ok' => true];
                });
            }

            $request = new \Core\Http\Request([], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/perf/route-' . ($routeCount - 1),
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_HOST' => 'localhost',
            ]);

            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $router->dispatch($request);
            }
            $routingSeconds = max(0.000001, microtime(true) - $start);
            $results[] = ['routing', $iterations, round(($routingSeconds / $iterations) * 1000, 4), round($iterations / $routingSeconds, 2)];

            // Validation benchmark
            $payload = [
                'name' => 'Myth User',
                'email' => 'myth@example.com',
                'age' => '30',
                'status' => '1',
            ];
            $rules = [
                'name' => 'required|string|min_length:3|max_length:100',
                'email' => 'required|email|max_length:120',
                'age' => 'required|numeric|min:18|max:120',
                'status' => 'required|integer|min:0|max:1',
            ];

            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $validator = validator($payload, $rules);
                $validator->passed();
            }
            $validationSeconds = max(0.000001, microtime(true) - $start);
            $results[] = ['validation', $iterations, round(($validationSeconds / $iterations) * 1000, 4), round($iterations / $validationSeconds, 2)];

            // Query benchmark (optional, skipped when DB unavailable)
            $queryLabel = 'query (SELECT 1)';
            try {
                $pdo = db()->getPdo();
                $start = microtime(true);
                for ($i = 0; $i < $dbIterations; $i++) {
                    $stmt = $pdo->query('SELECT 1');
                    $stmt->fetchColumn();
                    $stmt->closeCursor();
                }
                $querySeconds = max(0.000001, microtime(true) - $start);
                $results[] = [$queryLabel, $dbIterations, round(($querySeconds / $dbIterations) * 1000, 4), round($dbIterations / $querySeconds, 2)];
            } catch (\Throwable $e) {
                $results[] = [$queryLabel, 0, 'skipped', 'DB unavailable'];
            }

            $console->table(['Workload', 'Iterations', 'Avg (ms/op)', 'Ops/sec'], $results);
            $console->line('  Peak memory: ' . round(memory_get_peak_usage(true) / 1048576, 2) . ' MB');
            $console->newLine();
        }, 'Run baseline performance benchmark [--iterations=200] [--routes=200] [--db-iterations=100]');

        $console->command('perf:report', function (array $args = [], array $options = []) use ($console) {
            $limit = max(1, (int) ($options['limit'] ?? 5));

            try {
                $report = db()->getPerformanceReport([
                    'slow_limit' => $limit,
                    'frequent_limit' => $limit,
                    'recent_limit' => $limit,
                    'heavy_limit' => $limit,
                ]);
            } catch (\Throwable $e) {
                $console->newLine();
                $console->error('  Unable to build performance report: ' . $e->getMessage());
                $console->newLine();
                return;
            }

            if (isset($options['json'])) {
                $console->line(json_encode($report, JSON_PRETTY_PRINT));
                return;
            }

            $summary = $report['summary'] ?? [];
            $console->newLine();
            $console->info('  MythPHP Performance Report');
            $console->line('  ' . str_repeat('─', 56));
            $console->line('  Total queries      : ' . (int) ($summary['total_queries'] ?? 0));
            $console->line('  Slow queries       : ' . (int) ($summary['slow_queries'] ?? 0));
            $console->line('  Avg query time (s) : ' . round((float) ($summary['avg_time'] ?? 0), 6));
            $console->line('  Max query time (s) : ' . round((float) ($summary['max_time'] ?? 0), 6));
            $console->line('  Memory peak (MB)   : ' . round(((float) ($summary['memory_peak'] ?? 0)) / 1048576, 2));

            $slow = array_slice((array) ($report['slow_queries'] ?? []), 0, $limit);
            if (!empty($slow)) {
                $rows = [];
                foreach ($slow as $item) {
                    $rows[] = [
                        $item['query_type'] ?? 'unknown',
                        round((float) ($item['execution_time'] ?? 0), 6),
                        (int) ($item['row_count'] ?? 0),
                        mb_strimwidth((string) ($item['sql'] ?? ''), 0, 80, '...'),
                    ];
                }
                $console->newLine();
                $console->table(['Type', 'Time (s)', 'Rows', 'SQL'], $rows);
            }

            $heavy = array_slice((array) ($report['heavy_queries'] ?? []), 0, $limit);
            if (!empty($heavy)) {
                $rows = [];
                foreach ($heavy as $item) {
                    $rows[] = [
                        $item['query_type'] ?? 'unknown',
                        (int) ($item['count'] ?? 0),
                        round((float) ($item['total_time'] ?? 0), 6),
                        round((float) ($item['avg_time'] ?? 0), 6),
                        mb_strimwidth((string) ($item['sql'] ?? ''), 0, 80, '...'),
                    ];
                }
                $console->newLine();
                $console->table(['Type', 'Count', 'Total (s)', 'Avg (s)', 'SQL'], $rows);
            }

            $recent = array_slice((array) ($report['recent_queries'] ?? []), 0, $limit);
            if (!empty($recent)) {
                $rows = [];
                foreach ($recent as $item) {
                    $rows[] = [
                        $item['query_type'] ?? 'unknown',
                        round((float) ($item['execution_time'] ?? 0), 6),
                        date('Y-m-d H:i:s', (int) ($item['timestamp'] ?? time())),
                        mb_strimwidth((string) ($item['sql'] ?? ''), 0, 80, '...'),
                    ];
                }
                $console->newLine();
                $console->table(['Type', 'Time (s)', 'Timestamp', 'SQL'], $rows);
            }

            if (!empty($options['export'])) {
                $path = (string) $options['export'];
                $path = str_starts_with($path, ROOT_DIR) ? $path : ROOT_DIR . ltrim($path, '/\\');

                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));
                $console->newLine();
                $console->success('  Report exported to: ' . $path);
            }

            if (isset($options['reset'])) {
                \Core\Database\PerformanceMonitor::reset();
                $console->line('  Performance monitor state reset.');
            }

            $console->newLine();
        }, 'Show performance monitor report [--json] [--export=logs/perf_report.json] [--limit=5] [--reset]');
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
