<?php

/*
|--------------------------------------------------------------------------
| ENV LOADER (.env)
|--------------------------------------------------------------------------
*/

if (!function_exists('setEnvValue')) {
    function setEnvValue(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

if (!function_exists('loadDotEnv')) {
    function loadDotEnv(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $rawValue] = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '' || preg_match('/^[A-Z0-9_]+$/i', $name) !== 1) {
                continue;
            }

            // Do not overwrite already defined environment variables.
            if (getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                continue;
            }

            $value = trim($rawValue);
            if ($value !== '') {
                $first = $value[0];
                $last = substr($value, -1);
                $quoted = ($first === '"' && $last === '"') || ($first === "'" && $last === "'");

                if ($quoted && strlen($value) >= 2) {
                    $value = substr($value, 1, -1);
                } else {
                    $hashPos = strpos($value, ' #');
                    if ($hashPos !== false) {
                        $value = rtrim(substr($value, 0, $hashPos));
                    }
                }

                $value = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $value);
            }

            setEnvValue($name, $value);
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        $normalized = strtolower(trim($value));
        return match ($normalized) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('env_list')) {
    function env_list(string $key, array $default = []): array
    {
        $value = env($key, null);
        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn($v) => trim((string) $v), $value), static fn($v) => $v !== ''));
        }

        $csv = trim((string) $value);
        if ($csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($v) => $v !== ''));
    }
}

// Load .env as early as possible before config files are evaluated.
$rootForEnv = defined('ROOT_DIR')
    ? ROOT_DIR
    : dirname(__DIR__) . DIRECTORY_SEPARATOR;
loadDotEnv($rootForEnv . '.env');

/*
|--------------------------------------------------------------------------
| GET PROJECT BASE URL
|--------------------------------------------------------------------------
*/

if (!function_exists('getProjectBaseUrl')) {
    function getProjectBaseUrl()
    {
        $configuredBaseUrl = trim((string) env('APP_URL', ''));
        if ($configuredBaseUrl !== '') {
            return rtrim($configuredBaseUrl, '/') . '/';
        }

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        );

        $protocol = $isHttps ? 'https' : 'http';

        // Check if we're on localhost
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if ($host === '') {
            $projectRoot = defined('ROOT_DIR') ? rtrim(ROOT_DIR, DIRECTORY_SEPARATOR) : dirname(__DIR__);
            return $protocol . '://localhost/' . basename($projectRoot) . '/';
        }

        $isLocalhost = (
            strpos($host, 'localhost') !== false ||
            strpos($host, '127.0.0.1') !== false ||
            strpos($host, '::1') !== false ||
            preg_match('/^192\.168\./', $host) ||
            preg_match('/^10\./', $host) ||
            preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)
        );

        if ($isLocalhost) {
            // For localhost, include the project folder
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            $pathSegments = explode('/', trim($scriptDir, '/'));
            $projectFolder = !empty($pathSegments[0]) ? '/' . $pathSegments[0] : '';

            return $protocol . '://' . $host . $projectFolder . '/';
        } else {
            // For production, just return domain
            return $protocol . '://' . $host . '/';
        }
    }
}

/*
|--------------------------------------------------------------------------
| GET CONFIGURATION VALUE
|--------------------------------------------------------------------------
*/
if (!function_exists('config')) {
    function config($key, $default = null)
    {
        global $config;

        if (empty($key)) {
            return $config;
        }

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }
}

if (!function_exists('framework_service_store')) {
    function &framework_service_store(): array
    {
        if (!isset($GLOBALS['__framework_service_store']) || !is_array($GLOBALS['__framework_service_store'])) {
            $GLOBALS['__framework_service_store'] = [
                'instances' => [],
                'resolvers' => [],
            ];
        }

        return $GLOBALS['__framework_service_store'];
    }
}

if (!function_exists('framework_event_dispatcher')) {
    function framework_event_dispatcher(): \App\Support\EventDispatcher
    {
        if (!isset($GLOBALS['__framework_event_dispatcher']) || !$GLOBALS['__framework_event_dispatcher'] instanceof \App\Support\EventDispatcher) {
            $GLOBALS['__framework_event_dispatcher'] = new \App\Support\EventDispatcher();
        }

        return $GLOBALS['__framework_event_dispatcher'];
    }
}

if (!function_exists('event_dispatcher')) {
    function event_dispatcher(): \App\Support\EventDispatcher
    {
        return framework_event_dispatcher();
    }
}

if (!function_exists('on_event')) {
    function on_event(string $event, callable $listener): void
    {
        framework_event_dispatcher()->listen($event, $listener);
    }
}

if (!function_exists('dispatch_event')) {
    function dispatch_event(string $event, array $payload = []): array
    {
        return framework_event_dispatcher()->dispatch($event, $payload);
    }
}

if (!function_exists('reset_event_dispatcher')) {
    function reset_event_dispatcher(): void
    {
        framework_event_dispatcher()->reset();
    }
}

if (!function_exists('register_framework_service')) {
    function register_framework_service(string $name, callable $resolver): void
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            throw new InvalidArgumentException('Service name cannot be empty.');
        }

        $store = &framework_service_store();
        $store['resolvers'][$name] = $resolver;
        unset($store['instances'][$name]);
    }
}

if (!function_exists('framework_service')) {
    function framework_service(string $name, ?callable $resolver = null, bool $reset = false)
    {
        $store = &framework_service_store();

        $name = strtolower(trim($name));
        if ($name === '') {
            throw new InvalidArgumentException('Service name cannot be empty.');
        }

        if ($reset) {
            unset($store['instances'][$name]);
            return null;
        }

        if ($resolver !== null && !isset($store['resolvers'][$name])) {
            $store['resolvers'][$name] = $resolver;
        }

        if (array_key_exists($name, $store['instances'])) {
            return $store['instances'][$name];
        }

        if (!isset($store['resolvers'][$name])) {
            throw new RuntimeException('Service not registered: ' . $name);
        }

        $store['instances'][$name] = $store['resolvers'][$name]();

        return $store['instances'][$name];
    }
}

if (!function_exists('reset_framework_service')) {
    function reset_framework_service(?string $name = null): void
    {
        $store = &framework_service_store();

        if ($name === null) {
            $serviceNames = array_values(array_unique(array_merge(
                ['logger', 'security', 'files', 'response', 'blade_engine', 'maintenance', 'feature', 'auth', 'events', 'route.provider', 'database.runtime'],
                array_keys((array) ($store['instances'] ?? [])),
                array_keys((array) ($store['resolvers'] ?? []))
            )));

            foreach ($serviceNames as $serviceName) {
                framework_service($serviceName, null, true);
            }

            $store['resolvers'] = [];

            return;
        }

        framework_service($name, null, true);
        unset($store['resolvers'][strtolower(trim($name))]);
    }
}

if (!function_exists('bootstrapRegisterServiceProviders')) {
    function bootstrapRegisterServiceProviders(array $config, array $runtimeState = []): void
    {
        $providerConfig = (array) ($config['framework']['providers'] ?? []);
        if (empty($providerConfig)) {
            return;
        }

        // Grouped format: providers split by runtime type
        //   ['always' => [...], 'web' => [...], 'api' => [...], 'cli' => [...]]
        // Flat format (legacy): a plain list of class strings
        $isGrouped = array_key_exists('always', $providerConfig)
            || array_key_exists('web', $providerConfig)
            || array_key_exists('api', $providerConfig)
            || array_key_exists('cli', $providerConfig);

        if ($isGrouped) {
            $runtime   = (string) ($runtimeState['runtime'] ?? 'web');
            $always    = (array) ($providerConfig['always'] ?? []);
            $specific  = (array) ($providerConfig[$runtime] ?? []);
            $providers = array_values(array_unique(array_merge($always, $specific)));
        } else {
            $providers = $providerConfig;
        }

        dispatch_event('providers.registering', [
            'providers' => $providers,
            'config' => $config,
            'runtime' => $runtimeState,
        ]);

        $instances = [];

        foreach ($providers as $providerClass) {
            if (!is_string($providerClass) || trim($providerClass) === '') {
                continue;
            }

            if (!class_exists($providerClass)) {
                if (function_exists('bootstrapFail')) {
                    bootstrapFail('Configured service provider not found: ' . $providerClass, 500);
                }

                throw new RuntimeException('Configured service provider not found: ' . $providerClass);
            }

            $provider = new $providerClass($config, $runtimeState);
            $instances[] = $provider;

            if (method_exists($provider, 'register')) {
                $provider->register();
            }
        }

        foreach ($instances as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }

        dispatch_event('providers.booted', [
            'providers' => $providers,
            'instances' => $instances,
            'config' => $config,
            'runtime' => $runtimeState,
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL COMPONENTS SYSTEMS
|--------------------------------------------------------------------------
*/

spl_autoload_register(function ($class) {
    $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__) . DIRECTORY_SEPARATOR;

    $prefixMap = [
        'App\\Http\\Controllers\\' => $rootDir . 'app' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR,
        'App\\Http\\Middleware\\'  => $rootDir . 'app' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'middleware' . DIRECTORY_SEPARATOR,
        'App\\Http\\Requests\\'   => $rootDir . 'app' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR . 'requests' . DIRECTORY_SEPARATOR,
        'App\\Http\\'             => $rootDir . 'app' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR,
        'App\\Console\\'          => $rootDir . 'app' . DIRECTORY_SEPARATOR . 'console' . DIRECTORY_SEPARATOR,
        'App\\'                   => $rootDir . 'app' . DIRECTORY_SEPARATOR,
        'Core\\'                  => $rootDir . 'systems' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR,
        'Components\\'            => $rootDir . 'systems' . DIRECTORY_SEPARATOR . 'Components' . DIRECTORY_SEPARATOR,
        'Middleware\\'             => $rootDir . 'systems' . DIRECTORY_SEPARATOR . 'Middleware' . DIRECTORY_SEPARATOR,
    ];

    foreach ($prefixMap as $prefix => $baseDir) {
        if (strpos($class, $prefix) !== 0) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }

        return;
    }

    // Backward compatibility for existing system classes
    $legacyFile = $rootDir . 'systems' . DIRECTORY_SEPARATOR . $classPath;
    if (is_readable($legacyFile)) {
        require_once $legacyFile;
    }
});

/*
|--------------------------------------------------------------------------
| LOAD ALL HELPERS FUNCTIONS
|--------------------------------------------------------------------------
*/

if (!function_exists('loadHelperFiles')) {
    function loadHelperFiles()
    {
        $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $helpersDir = $rootDir . 'app' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR;

        // Get all PHP files in the General folder
        $helperFiles = glob($helpersDir . '*.php');

        foreach ($helperFiles as $file) {
            try {
                if (is_readable($file)) {
                    include_once $file;
                } else {
                    throw new Exception("File not readable: $file");
                }
            } catch (Exception $e) {
                die("Error: Unable to resolve file path for $file. " . $e->getMessage());
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL CONTROLLER SCOPE & MACRO FUNCTIONS
|--------------------------------------------------------------------------
*/

/**
 * Load and execute all functions from specified files and folders
 * Enhanced with better error handling and validation
 * @param mixed $params Parameters to pass to functions (can be single value or array)
 * @param string|array $filename Single filename or array of filenames
 * @param string|array $foldername Single folder name or array of folder names
 * @param string $base_path Base path for files/folders (absolute path recommended)
 * @param bool $silent Whether to suppress error reporting (default: false)
 */
if (!function_exists('loadScopeMacroDBFunctions')) {
    function loadScopeMacroDBFunctions($params, $filename = [], $foldername = [], $base_path = null, $silent = false)
    {
        // Default to ROOT_DIR/app/database/ if no base_path provided
        if ($base_path === null) {
            $base_path = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__) . DIRECTORY_SEPARATOR) . 'app' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR;
        }

        // Input validation
        if (empty($filename) && empty($foldername)) {
            if (!$silent) {
                error_log("loadScopeMacroDBFunctions: No files or folders specified");
            }
            return;
        }

        if (!is_string($base_path) || empty(trim($base_path))) {
            if (!$silent) {
                error_log("loadScopeMacroDBFunctions: Invalid base path provided");
            }
            return;
        }

        $executed_functions = [];
        $errors = [];
        $base_path = rtrim($base_path, '/') . '/';

        // Validate base path exists
        if (!is_dir($base_path)) {
            if (!$silent) {
                error_log("loadScopeMacroDBFunctions: Base path does not exist: {$base_path}");
            }
            return;
        }

        // Convert single values to arrays and validate
        $filenames = [];
        $foldernames = [];

        if (!empty($filename)) {
            $filenames = is_array($filename) ? $filename : [$filename];
            $filenames = array_filter($filenames, function ($f) {
                return is_string($f) && !empty(trim($f));
            });
        }

        if (!empty($foldername)) {
            $foldernames = is_array($foldername) ? $foldername : [$foldername];
            $foldernames = array_filter($foldernames, function ($f) {
                return is_string($f) && !empty(trim($f));
            });
        }

        // Anonymous function to safely extract functions from file
        $extractFunctions = function ($file_path) use (&$errors, $silent) {
            if (!file_exists($file_path)) {
                if (!$silent) {
                    $errors[] = "File does not exist: {$file_path}";
                }
                return [];
            }

            if (!is_readable($file_path)) {
                if (!$silent) {
                    $errors[] = "File is not readable: {$file_path}";
                }
                return [];
            }

            $content = @file_get_contents($file_path);
            if ($content === false) {
                if (!$silent) {
                    $errors[] = "Failed to read file: {$file_path}";
                }
                return [];
            }

            $functions = [];

            try {
                // Match all function declarations with better regex
                if (preg_match_all('/(?:^|\s)function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/im', $content, $matches)) {
                    if (!empty($matches[1])) {
                        $functions = array_unique($matches[1]);
                        // Filter out magic methods and constructors that shouldn't be called directly
                        $functions = array_filter($functions, function ($func) {
                            return !in_array(strtolower($func), ['__construct', '__destruct', '__call', '__callstatic', '__get', '__set', '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize', '__tostring', '__invoke', '__set_state', '__clone', '__debuginfo']);
                        });
                    }
                }
            } catch (Exception $e) {
                if (!$silent) {
                    $errors[] = "Error parsing file {$file_path}: " . $e->getMessage();
                }
            }

            return $functions;
        };

        // Anonymous function to safely execute function with parameters
        $executeFunction = function ($function_name, $params) use (&$errors, $silent) {
            if (!is_string($function_name) || empty($function_name)) {
                return false;
            }

            if (!function_exists($function_name)) {
                if (!$silent) {
                    $errors[] = "Function does not exist: {$function_name}";
                }
                return false;
            }

            try {
                // Get function reflection for better parameter validation
                $reflection = new ReflectionFunction($function_name);
                $required_params = $reflection->getNumberOfRequiredParameters();
                $total_params = $reflection->getNumberOfParameters();

                $param_count = is_array($params) ? count($params) : 1;

                // Check parameter count
                if ($param_count < $required_params || $param_count > $total_params) {
                    if (!$silent) {
                        $errors[] = "Function {$function_name} expects {$required_params}-{$total_params} parameters, {$param_count} given";
                    }
                    return false;
                }

                // Execute function
                if (is_array($params)) {
                    call_user_func_array($function_name, $params);
                } else {
                    call_user_func($function_name, $params);
                }

                return true;
            } catch (ReflectionException $e) {
                if (!$silent) {
                    $errors[] = "Reflection error for {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (ArgumentCountError $e) {
                if (!$silent) {
                    $errors[] = "Parameter count error in {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (TypeError $e) {
                if (!$silent) {
                    $errors[] = "Type error in {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (Error $e) {
                if (!$silent) {
                    $errors[] = "Fatal error in {$function_name}: " . $e->getMessage();
                }
                return false;
            } catch (Exception $e) {
                if (!$silent) {
                    $errors[] = "Exception in {$function_name}: " . $e->getMessage();
                }
                return false;
            }
        };

        // Anonymous function to safely process a single file
        $processFile = function ($file_path) use ($params, &$executed_functions, $extractFunctions, $executeFunction) {
            // Validate file extension
            if (pathinfo($file_path, PATHINFO_EXTENSION) !== 'php') {
                return;
            }

            if (file_exists($file_path) && is_readable($file_path)) {
                // Include file safely
                $included = @include_once $file_path;
                if ($included === false) {
                    return;
                }

                // Get all functions from file
                $functions = $extractFunctions($file_path);

                // Execute each function with params
                foreach ($functions as $function_name) {
                    if (!in_array($function_name, $executed_functions)) {
                        $result = $executeFunction($function_name, $params);
                        if ($result) {
                            $executed_functions[] = $function_name;
                        }
                    }
                }
            }
        };

        // Anonymous function to safely process a folder
        $processFolder = function ($folder_path) use ($processFile, &$errors, $silent) {
            if (!is_dir($folder_path)) {
                if (!$silent) {
                    $errors[] = "Directory does not exist: {$folder_path}";
                }
                return;
            }

            if (!is_readable($folder_path)) {
                if (!$silent) {
                    $errors[] = "Directory is not readable: {$folder_path}";
                }
                return;
            }

            $php_files = @glob($folder_path . '/*.php');
            if ($php_files === false) {
                if (!$silent) {
                    $errors[] = "Failed to read directory: {$folder_path}";
                }
                return;
            }

            foreach ($php_files as $file_path) {
                $processFile($file_path);
            }
        };

        // Process individual files
        foreach ($filenames as $file) {
            $file_path = $base_path . ltrim($file, '/');
            $processFile($file_path);
        }

        // Process folders
        foreach ($foldernames as $folder) {
            $folder_path = $base_path . ltrim($folder, '/');
            $processFolder($folder_path);
        }

        // Log errors if any occurred and not in silent mode
        if (!$silent && !empty($errors)) {
            foreach ($errors as $error) {
                error_log("loadScopeMacroDBFunctions: " . $error);
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL MIDDLEWARES
|--------------------------------------------------------------------------
*/

if (!function_exists('loadMiddlewaresFiles')) {
    function loadMiddlewaresFiles(array $middlewares, $args = null)
    {
        foreach ($middlewares as $middleware) {
            $class = "Middleware\\$middleware";
            if (class_exists($class)) {
                $instance = new $class();
                if (method_exists($instance, 'run')) {
                    $instance->run($args);
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DEBUG COMPONENT 
|--------------------------------------------------------------------------
*/

if (!function_exists('debug')) {
    function debug()
    {
        return new \Components\Debug();
    }
}

/*
|--------------------------------------------------------------------------
| LOGGER COMPONENT 
|--------------------------------------------------------------------------
*/

if (!function_exists('logger')) {
    function logger()
    {
        return framework_service('logger');
    }
}

/*
|--------------------------------------------------------------------------
| SECURITY COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('security')) {
    function security()
    {
        return framework_service('security');
    }
}

/*
|--------------------------------------------------------------------------
| FILES COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('files')) {
    function files()
    {
        return framework_service('files');
    }
}

if (!function_exists('storage')) {
    function storage(?string $disk = null)
    {
        $manager = framework_service('storage');

        if ($disk === null) {
            return $manager;
        }

        return $manager->disk($disk);
    }
}

/*
|--------------------------------------------------------------------------
| REQUEST COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('request')) {
    function request()
    {
        $current = \Core\Http\Request::current();
        if ($current instanceof \Core\Http\Request) {
            return $current;
        }

        $request = \Core\Http\Request::capture();
        \Core\Http\Request::setCurrent($request);

        return $request;
    }
}

/*
|--------------------------------------------------------------------------
| FRAMEWORK VIEW ENGINE
|--------------------------------------------------------------------------
*/

if (!function_exists('blade_engine')) {
    function blade_engine()
    {
        return framework_service('blade_engine');
    }
}

if (!function_exists('response')) {
    function response()
    {
        return framework_service('response');
    }
}

if (!function_exists('menu_manager')) {
    function menu_manager()
    {
        return new \Components\MenuManager(config('menu', []));
    }
}

if (!function_exists('maintenance')) {
    function maintenance()
    {
        return framework_service('maintenance');
    }
}

if (!function_exists('feature')) {
    function feature(?string $key = null, bool $default = false, array $context = [])
    {
        $manager = framework_service('feature');

        if ($key === null) {
            return $manager;
        }

        return $manager->enabled($key, $default, $context);
    }
}

if (!function_exists('featureFlag')) {
    function featureFlag(?string $key = null, bool $default = false, array $context = [])
    {
        return feature($key, $default, $context);
    }
}

if (!function_exists('feature_value')) {
    function feature_value(string $key, mixed $default = null, array $context = [])
    {
        return framework_service('feature')->value($key, $default, $context);
    }
}

if (!function_exists('view')) {
    function view($view, array $data = [])
    {
        response()->view((string) $view, $data)->send();
    }
}

if (!function_exists('view_raw')) {
    function view_raw($view, array $data = [])
    {
        return blade_engine()->render((string) $view, $data);
    }
}

if (!function_exists('response_cache')) {
    /**
     * Apply HTTP cache policy to the current response.
     *
     * Examples:
     *   response_cache(300);                    // public cache for 5 minutes
     *   response_cache(0, false);               // private no-max-age
     *   response_cache(0, false, false, true);  // no-store/no-cache
     */
    function response_cache(int $seconds, bool $public = true, bool $immutable = false, bool $noStore = false): void
    {
        if ($noStore) {
            \Core\Http\Response::noCache();
            return;
        }

        \Core\Http\Response::cache($seconds, $public, $immutable);
    }
}

/*
|--------------------------------------------------------------------------
| AUTH COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('auth')) {
    function auth()
    {
        return framework_service('auth');
    }
}

/*
|--------------------------------------------------------------------------
| VALIDATION COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('validator')) {
    function validator($data = [], $rules = [], $customMessage = [])
    {
        $validator = new \Components\Validation();

        if (!empty($data)) {
            $validator->setData($data);
        }

        if (!empty($rules)) {
            $validator->setRules($rules);
        }

        if (!empty($customMessage)) {
            $validator->setMessages($customMessage);
        }

        return $validator;
    }
}

/*
|--------------------------------------------------------------------------
| CSRF COMPONENT
|--------------------------------------------------------------------------
*/

if (!function_exists('csrf')) {
    function csrf()
    {
        static $instance = null;
        if ($instance === null) {
            global $config;
            $instance = new \Components\CSRF($config['security']['csrf']);
        }
        return $instance;
    }
}


if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return csrf()->field();
    }
}

if (!function_exists('csrf_value')) {
    function csrf_value()
    {
        return csrf()->getToken() ?: csrf()->init();
    }
}

/*
|--------------------------------------------------------------------------
| COLLECTION HELPER
|--------------------------------------------------------------------------
|
|  collect([1, 2, 3])->map(fn($v) => $v * 2)->toArray();
|
*/

if (!function_exists('collect')) {
    /**
     * Create a new Collection instance.
     *
     * @param  array|\Core\Collection $items
     * @return \Core\Collection
     */
    function collect(array|\Core\Collection $items = []): \Core\Collection
    {
        return new \Core\Collection($items);
    }
}

/*
|--------------------------------------------------------------------------
| CACHE HELPER
|--------------------------------------------------------------------------
|
|  cache('key');                      // get value
|  cache(['key' => 'val'], 300);     // put value (300 seconds)
|  cache()->remember('k', 60, fn()=> ...);
|
*/

if (!function_exists('cache')) {
    /**
     * Get / set cache values, or return the CacheManager instance.
     *
     * @param  string|array|null $key
     * @param  mixed             $default
     * @return mixed|\Core\Cache\CacheManager
     */
    function cache(string|array|null $key = null, mixed $default = null): mixed
    {
        static $manager = null;

        if ($manager === null) {
            $manager = new \Core\Cache\CacheManager(\config('cache') ?? []);
        }

        // No arguments → return the manager
        if ($key === null) {
            return $manager;
        }

        // Array → batch put
        if (is_array($key)) {
            return $manager->putMany($key, is_int($default) ? $default : 0);
        }

        // String → get
        return $manager->get($key, $default);
    }
}

/*
|--------------------------------------------------------------------------
| JOB DISPATCH HELPER
|--------------------------------------------------------------------------
|
|  dispatch(new \App\Jobs\SendEmail($user));
|
*/

if (!function_exists('dispatch')) {
    /**
     * Dispatch a job to the queue.
     *
     * @param  \Core\Queue\Job $job
     * @return string|null  Job ID (null for sync driver)
     */
    function dispatch(\Core\Queue\Job $job): ?string
    {
        static $dispatcher = null;

        if ($dispatcher === null) {
            $dispatcher = new \Core\Queue\Dispatcher(\config('queue') ?? []);
        }

        return $dispatcher->dispatch($job);
    }
}

if (!function_exists('schema')) {
    /**
     * Get the Schema builder instance.
     *
     * Usage:
     *   schema()::create('users', function ($table) { ... });
     *   schema()::dropIfExists('table');
     *
     * Or use Schema directly:
     *   use Core\Database\Schema\Schema;
     *   Schema::create('users', ...);
     *
     * @return string The Schema class FQCN for static calls
     */
    function schema(): string
    {
        return \Core\Database\Schema\Schema::class;
    }
}
