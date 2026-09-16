<?php

define('ROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

/*
 * Runtime constants bootstrap.php would normally define.
 *
 * url() and route() require BASE_URL, and the Router's 404 path calls url() — so
 * without these a feature test hits an InvalidArgumentException from the harness
 * rather than exercising the redirect it is there to check.
 */
// Matches what AssetVersioningTest already expected, so both suites agree on one
// canonical test host. Feature requests use the same host, or generated URLs would
// look external and be neutralised by the open-redirect guard.
if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://example.test/');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'SimplePHP');
}

if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'testing');
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

if (!defined('TEMPLATE_DIR')) {
    define('TEMPLATE_DIR', ROOT_DIR);
}

require_once ROOT_DIR . 'vendor/autoload.php';
require_once ROOT_DIR . 'systems/hooks.php';

$GLOBALS['config'] = $GLOBALS['config'] ?? [];

// cache() memoises its manager on first call, so the store has to exist before any
// test touches it. The array driver keeps tests isolated and needs no filesystem.
$GLOBALS['config']['cache'] = $GLOBALS['config']['cache'] ?? [
    'default' => 'array',
    'stores'  => ['array' => ['driver' => 'array']],
];

if (!function_exists('config')) {
    function config($key, $default = null)
    {
        $config = $GLOBALS['config'] ?? [];
        if ($key === null || $key === '') {
            return $config;
        }

        $segments = explode('.', (string) $key);
        $value = $config;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            return $default;
        }

        return $value;
    }
}

if (!function_exists('bootstrapTestFrameworkServices')) {
    function bootstrapTestFrameworkServices(array $config = [], array $runtimeState = ['runtime' => 'cli']): void
    {
        $defaultProviders = [
            \App\Providers\AppServiceProvider::class,
            \App\Providers\LogServiceProvider::class,
            \App\Providers\DatabaseServiceProvider::class,
            \App\Providers\CacheServiceProvider::class,
            \App\Providers\SecurityServiceProvider::class,
            \App\Providers\FilesystemServiceProvider::class,
            \App\Providers\ViewServiceProvider::class,
            \App\Providers\ResponseServiceProvider::class,
            \App\Providers\RoutingServiceProvider::class,
            \App\Providers\EventServiceProvider::class,
            \App\Providers\MaintenanceServiceProvider::class,
            \App\Providers\FeatureServiceProvider::class,
            \App\Providers\AuthServiceProvider::class,
        ];

        $framework = (array) ($config['framework'] ?? []);
        if (!isset($framework['providers'])) {
            $framework['providers'] = $defaultProviders;
        }

        $GLOBALS['config'] = array_replace_recursive($GLOBALS['config'] ?? [], $config, [
            'framework' => $framework,
        ]);

        reset_framework_service();
        reset_event_dispatcher();
        bootstrapRegisterServiceProviders($GLOBALS['config'], $runtimeState);
    }
}

/*
 * Global helpers.
 *
 * The framework calls url(), route(), jsonResponse() and friends from inside
 * Core — Router's 404 path calls url(), controllers call jsonResponse(). A test
 * run without them is not exercising the same code the runtime does, and the
 * failures it produces are about the harness rather than the framework.
 */
foreach (glob(ROOT_DIR . 'app/helpers/*.php') ?: [] as $helperFile) {
    require_once $helperFile;
}

// The Feature suite's base class. Required explicitly rather than autoloaded so
// the suite runs without a `composer dump-autoload` after checkout — the
// autoload-dev mapping exists too, this is just the belt to its braces.
require_once ROOT_DIR . 'tests/Feature/FeatureTestCase.php';