<?php

declare(strict_types=1);

use App\Providers\ServiceProvider;
use Core\Database\QueryCache;
use PHPUnit\Framework\TestCase;

final class ProviderBootstrapDummyProvider extends ServiceProvider
{
    public static array $events = [];

    public function register(): void
    {
        self::$events[] = 'register';
        register_framework_service('dummy', fn() => (object) ['provider' => 'dummy']);
    }

    public function boot(): void
    {
        self::$events[] = 'boot';
    }
}

final class ProviderBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProviderBootstrapDummyProvider::$events = [];
        reset_framework_service();
        reset_event_dispatcher();
    }

    public function testProviderRegisterAndBootRunInOrder(): void
    {
        $observed = [];
        on_event('providers.registering', function () use (&$observed) {
            $observed[] = 'providers.registering';
        });
        on_event('providers.booted', function () use (&$observed) {
            $observed[] = 'providers.booted';
        });

        bootstrapRegisterServiceProviders([
            'framework' => [
                'providers' => [ProviderBootstrapDummyProvider::class],
            ],
        ], ['runtime' => 'cli']);

        self::assertSame(['register', 'boot'], ProviderBootstrapDummyProvider::$events);
        self::assertSame(['providers.registering', 'providers.booted'], $observed);
        self::assertSame('dummy', framework_service('dummy')->provider);
    }

    public function testFocusedProvidersRegisterManagedHelpers(): void
    {
        $config = [
            'auth' => ['methods' => ['session']],
            'framework' => [
                'view_path' => 'app/views',
                'view_cache_path' => 'storage/cache/views',
                'maintenance' => [],
            ],
            'features' => [
                'flags' => ['runtime_service_registry' => true],
            ],
        ];

        (new \App\Providers\LogServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\SecurityServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\FilesystemServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\ViewServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\ResponseServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\RoutingServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\EventServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\MaintenanceServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\FeatureServiceProvider($config, ['runtime' => 'cli']))->register();
        (new \App\Providers\AuthServiceProvider($config, ['runtime' => 'cli']))->register();

        self::assertInstanceOf(\Components\Logger::class, framework_service('logger'));
        self::assertInstanceOf(\Components\Security::class, framework_service('security'));
        self::assertInstanceOf(\Components\Files::class, framework_service('files'));
        self::assertInstanceOf(\Core\Filesystem\StorageManager::class, framework_service('storage'));
        self::assertInstanceOf(\Components\Auth::class, framework_service('auth'));
        self::assertInstanceOf(\App\Support\Auth\LoginPolicy::class, framework_service('auth.login_policy'));
        self::assertInstanceOf(\App\Support\Auth\AuthorizationService::class, framework_service('auth.authorization'));
        self::assertInstanceOf(\App\Support\Auth\TokenService::class, framework_service('auth.tokens'));
        self::assertInstanceOf(\Components\FeatureManager::class, framework_service('feature'));
        self::assertInstanceOf(\Core\Http\ResponseFactory::class, framework_service('response'));
        self::assertInstanceOf(\Core\Routing\RouteServiceProvider::class, framework_service('route.provider'));
        self::assertInstanceOf(\App\Support\EventDispatcher::class, framework_service('events'));
        self::assertInstanceOf(\Components\Maintenance::class, framework_service('maintenance'));
        self::assertInstanceOf(\Core\View\BladeEngine::class, framework_service('blade_engine'));
    }

    public function testCacheServiceProviderBootAppliesQueryCacheSettings(): void
    {
        QueryCache::enable();

        $provider = new \App\Providers\CacheServiceProvider([
            'db' => [
                'cache' => [
                    'enabled' => false,
                    'path' => ROOT_DIR . 'storage/cache/query-provider-test',
                ],
            ],
        ], ['runtime' => 'cli']);

        try {
            $provider->boot();

            self::assertFalse(QueryCache::isEnabled());
        } finally {
            QueryCache::enable();
        }
    }

    public function testDatabaseServiceProviderRegistersManagedDatabaseRuntime(): void
    {
        $provider = new \App\Providers\DatabaseServiceProvider([
            'environment' => 'development',
            'db' => [
                'default' => [
                    'development' => [
                        'driver' => 'mysql',
                        'database' => 'resi_emas',
                    ],
                ],
            ],
        ], ['runtime' => 'cli']);

        $provider->register();

        self::assertInstanceOf(\App\Support\DatabaseRuntime::class, framework_service('database.runtime'));
    }

    public function testAppServiceProviderBootAppliesTimezoneSettings(): void
    {
        $originalTimezone = date_default_timezone_get();

        $provider = new \App\Providers\AppServiceProvider([
            'timezone' => 'Asia/Kuala_Lumpur',
        ], ['runtime' => 'cli']);

        try {
            $provider->boot();

            self::assertSame('Asia/Kuala_Lumpur', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}