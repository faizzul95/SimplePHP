<?php

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', realpath(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'ResiConnect');
}

if (!defined('REDIRECT_LOGIN')) {
    define('REDIRECT_LOGIN', 'login');
}

if (!defined('REDIRECT_403')) {
    define('REDIRECT_403', 'app/views/errors/general_error.php');
}

if (!defined('REDIRECT_404')) {
    define('REDIRECT_404', 'app/views/errors/404.php');
}

if (!function_exists('bootstrapFail')) {
    function bootstrapFail(string $message, int $statusCode = 500, ?\Throwable $previous = null): never
    {
        error_log($message . ($previous !== null ? ' :: ' . $previous->getMessage() : ''));

        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !headers_sent()) {
            http_response_code($statusCode);
        }

        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            echo $message;
            if ($previous !== null) {
                echo ' :: ' . $previous->getMessage();
            }
        } else {
            echo $statusCode >= 500 ? '500 - Internal Server Error' : 'Application bootstrap failed';
        }

        exit(1);
    }
}

$config = isset($config) && is_array($config) ? $config : [];

if (!function_exists('bootstrapLoadComposerAutoload')) {
    function bootstrapLoadComposerAutoload(): void
    {
        $autoloadFile = __DIR__ . '/vendor/autoload.php';
        if (is_file($autoloadFile)) {
            require_once $autoloadFile;
        }
    }
}

if (!function_exists('bootstrapLoadCoreHooks')) {
    function bootstrapLoadCoreHooks(): void
    {
        require_once __DIR__ . '/systems/hooks.php';
    }
}

if (!function_exists('bootstrapRegisterConsoleAlias')) {
    function bootstrapRegisterConsoleAlias(): void
    {
        if (!class_exists('Myth', false)) {
            class_alias(\Core\Console\Myth::class, 'Myth');
        }
    }
}

if (!function_exists('loadConfig')) {
    function loadConfig(array $baseConfig = []): array
    {
        global $config;

        $loadedConfig = $baseConfig;
        $configFiles = glob(__DIR__ . '/app/config/*.php') ?: [];
        sort($configFiles, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($configFiles as $file) {
            try {
                if (!is_readable($file)) {
                    throw new RuntimeException('File not readable: ' . $file);
                }

                $config = $loadedConfig;
                $included = include $file;

                if (is_array($included)) {
                    $key = pathinfo($file, PATHINFO_FILENAME);
                    if (!isset($loadedConfig[$key]) || !is_array($loadedConfig[$key])) {
                        $loadedConfig[$key] = [];
                    }

                    $loadedConfig[$key] = array_replace_recursive($loadedConfig[$key], $included);
                }

                if (isset($config) && is_array($config)) {
                    $loadedConfig = array_replace_recursive($loadedConfig, $config);
                }
            } catch (\Throwable $e) {
                bootstrapFail('Unable to load config file: ' . $file, 500, $e);
            }
        }

        $config = $loadedConfig;

        return $loadedConfig;
    }
}

if (!function_exists('bootstrapApplyEnvironmentPresets')) {
    function bootstrapApplyEnvironmentPresets(array &$config): void
    {
        $environmentPresets = $config['security']['presets'][ENVIRONMENT] ?? [];
        if (!is_array($environmentPresets) || empty($environmentPresets)) {
            return;
        }

        foreach ($environmentPresets as $topLevelSection => $sectionOverrides) {
            if (!is_array($sectionOverrides)) {
                continue;
            }

            if (isset($sectionOverrides[$topLevelSection]) && is_array($sectionOverrides[$topLevelSection])) {
                $sectionOverrides = $sectionOverrides[$topLevelSection];
            }

            if (!isset($config[$topLevelSection]) || !is_array($config[$topLevelSection])) {
                $config[$topLevelSection] = [];
            }

            applyConfigOverrides($config[$topLevelSection], $sectionOverrides);
        }
    }
}

if (!function_exists('bootstrapNormalizeSecurityConfig')) {
    function bootstrapNormalizeSecurityConfig(array &$config): void
    {
        $security = (array) ($config['security'] ?? []);
        $requestHardening = (array) ($security['request_hardening'] ?? []);
        $trusted = (array) ($security['trusted'] ?? []);

        $trustedHosts = $trusted['hosts'] ?? $requestHardening['allowed_hosts'] ?? [];
        $trustedProxies = $trusted['proxies'] ?? $security['trusted_proxies'] ?? [];

        $trustedHosts = array_values(array_filter(array_map(static function ($host) {
            return trim((string) $host);
        }, (array) $trustedHosts), static function ($host) {
            return $host !== '';
        }));

        $trustedProxies = array_values(array_filter(array_map(static function ($proxy) {
            return trim((string) $proxy);
        }, (array) $trustedProxies), static function ($proxy) {
            return $proxy !== '';
        }));

        $requestHardening['allowed_hosts'] = $trustedHosts;
        $security['request_hardening'] = $requestHardening;
        $security['trusted'] = [
            'hosts' => $trustedHosts,
            'proxies' => $trustedProxies,
        ];
        $security['trusted_proxies'] = $trustedProxies;

        $config['security'] = $security;
    }
}

if (!function_exists('configureEnvironment')) {
    function configureEnvironment(array &$config): void
    {
        if (!defined('ENVIRONMENT')) {
            define('ENVIRONMENT', (string) ($config['environment'] ?? 'development'));
        }

        bootstrapApplyEnvironmentPresets($config);
        bootstrapNormalizeSecurityConfig($config);

        switch (ENVIRONMENT) {
            case 'development':
                error_reporting(-1);
                ini_set('display_errors', '1');
                break;

            case 'testing':
            case 'production':
                ini_set('display_errors', '0');
                error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
                break;

            default:
                bootstrapFail('The application environment is not set correctly.', 503);
        }
    }
}

// Apply security/performance presets for the current environment.
if (!function_exists('applyConfigOverrides')) {
    function applyConfigOverrides(array &$target, array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($target[$key]) && is_array($target[$key])) {
                applyConfigOverrides($target[$key], $value);
                continue;
            }

            $target[$key] = $value;
        }
    }
}

if (!function_exists('bootstrapRunsInCli')) {
    function bootstrapRunsInCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}

if (!function_exists('bootstrapRequestPath')) {
    function bootstrapRequestPath(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $requestPath = parse_url($requestUri, PHP_URL_PATH);

        return is_string($requestPath) ? $requestPath : '';
    }
}

if (!function_exists('bootstrapHasApiCredential')) {
    function bootstrapHasApiCredential(): bool
    {
        return !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            || !empty($_SERVER['PHP_AUTH_USER'])
            || !empty($_SERVER['PHP_AUTH_DIGEST'])
            || !empty($_SERVER['HTTP_X_API_KEY']);
    }
}

if (!function_exists('bootstrapRuntime')) {
    function bootstrapRuntime(): string
    {
        if (bootstrapRunsInCli()) {
            return 'cli';
        }

        $requestPath = bootstrapRequestPath();
        $isApiRequest = preg_match('#(?:^|/)api(?:/|$)#i', $requestPath) === 1;

        if ($isApiRequest || bootstrapHasApiCredential()) {
            return 'api';
        }

        return 'web';
    }
}

if (!function_exists('bootstrapSessionConfig')) {
    function bootstrapSessionConfig(array $config): array
    {
        $sessionConfig = (array) ($config['framework']['bootstrap']['session'] ?? []);
        $sessionConfig['enabled'] = array_key_exists('enabled', $sessionConfig) ? (bool) $sessionConfig['enabled'] : true;
        $sessionConfig['cli'] = (bool) ($sessionConfig['cli'] ?? false);
        $sessionConfig['api'] = (bool) ($sessionConfig['api'] ?? false);

        return $sessionConfig;
    }
}

if (!function_exists('shouldBootstrapSession')) {
    function shouldBootstrapSession(array $config): bool
    {
        $sessionConfig = bootstrapSessionConfig($config);
        $enabled = array_key_exists('enabled', $sessionConfig) ? (bool) $sessionConfig['enabled'] : true;

        if (!$enabled) {
            return false;
        }

        $sessionName = session_name();
        $hasSessionCookie = $sessionName !== '' && !empty($_COOKIE[$sessionName]);
        if ($hasSessionCookie) {
            return true;
        }

        return match (bootstrapRuntime()) {
            'cli' => (bool) ($sessionConfig['cli'] ?? false),
            'api' => (bool) ($sessionConfig['api'] ?? false),
            default => true,
        };
    }
}

if (!function_exists('bootstrapConfigureSessionIni')) {
    function bootstrapConfigureSessionIni(): void
    {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', ENVIRONMENT === 'production' ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        if (PHP_VERSION_ID < 80400) {
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
    }
}

if (!function_exists('bootstrapStartSession')) {
    function bootstrapStartSession(array $config): void
    {
        bootstrapConfigureSessionIni();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            if (!isset($_SESSION['last_regeneration'])) {
                $_SESSION['last_regeneration'] = time();
            }
        }

        if (
            !empty($config['sess_regenerate_destroy'])
            && session_status() === PHP_SESSION_ACTIVE
            && (!isset($_SESSION['last_regeneration']) || (time() - $_SESSION['last_regeneration']) > ($config['sess_time_to_update'] ?? 300))
        ) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
}

if (!function_exists('initializeSession')) {
    function initializeSession(array $config): bool
    {
        $bootstrapSessionEnabled = shouldBootstrapSession($config);

        if (!defined('BOOTSTRAP_SESSION_ENABLED')) {
            define('BOOTSTRAP_SESSION_ENABLED', $bootstrapSessionEnabled);
        }

        if (!defined('BOOTSTRAP_STATEFUL_REQUEST')) {
            define('BOOTSTRAP_STATEFUL_REQUEST', $bootstrapSessionEnabled);
        }

        if ($bootstrapSessionEnabled) {
            bootstrapStartSession($config);
        }

        return $bootstrapSessionEnabled;
    }
}

if (!function_exists('initializeRuntime')) {
    function initializeRuntime(array $config): array
    {
        $runtime = bootstrapRuntime();

        if (!defined('BOOTSTRAP_RUNTIME')) {
            define('BOOTSTRAP_RUNTIME', $runtime);
        }

        $sessionEnabled = initializeSession($config);

        if (!defined('BASE_URL')) {
            define('BASE_URL', getProjectBaseUrl());
        }

        if (!defined('APP_DIR')) {
            $basePath = (string) parse_url(BASE_URL, PHP_URL_PATH);
            define('APP_DIR', basename(trim($basePath, '/')) ?: basename(rtrim(ROOT_DIR, DIRECTORY_SEPARATOR)));
        }

        if (!defined('APP_ENV')) {
            define('APP_ENV', ENVIRONMENT);
        }

        if (!defined('TEMPLATE_DIR')) {
            define('TEMPLATE_DIR', __DIR__ . DIRECTORY_SEPARATOR);
        }

        return [
            'runtime' => $runtime,
            'session_enabled' => $sessionEnabled,
            'stateful' => $sessionEnabled,
        ];
    }
}

if (!function_exists('bootstrapInitializeHelpers')) {
    function bootstrapInitializeHelpers(): void
    {
        loadHelperFiles();
    }
}

if (!function_exists('bootstrapInitializeSystems')) {
    function bootstrapInitializeSystems(): void
    {
        require_once __DIR__ . '/systems/app.php';
    }
}

bootstrapLoadComposerAutoload();
bootstrapLoadCoreHooks();
dispatch_event('boot.starting', ['root' => ROOT_DIR]);
bootstrapRegisterConsoleAlias();
$config = loadConfig($config);
dispatch_event('config.loaded', ['config' => $config]);
configureEnvironment($config);
$runtimeState = initializeRuntime($config);
bootstrapRegisterServiceProviders($config, $runtimeState);

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

bootstrapInitializeHelpers();

// Start connection to database, middleware bootstrap, and database helpers.
bootstrapInitializeSystems();
