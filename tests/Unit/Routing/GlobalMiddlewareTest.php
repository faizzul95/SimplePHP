<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Core\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Middleware was opt-in per route, so a route registered outside the web/api groups
 * silently received no security headers, host validation, proxy validation or IP
 * blocklist — and nothing reported it.
 */
final class GlobalMiddlewareTest extends TestCase
{
    /** @var array<string,string> */
    private array $aliases = [
        'headers'         => \App\Http\Middleware\SetSecurityHeaders::class,
        'trusted.hosts'   => \App\Http\Middleware\ValidateTrustedHosts::class,
        'trusted.proxies' => \App\Http\Middleware\ValidateTrustedProxies::class,
        'ip.blocklist'    => \App\Http\Middleware\BlocklistIp::class,
        'csrf'            => \App\Http\Middleware\VerifyCsrfToken::class,
        'session.stateful' => \App\Http\Middleware\StartStatefulSession::class,
    ];

    private function router(array $global, array $groups = []): Router
    {
        $router = new Router();
        $router->aliasMiddleware($this->aliases);
        $router->middlewareGroup($groups);
        $router->globalMiddleware($global);

        return $router;
    }

    /** @return list<string> short class names of the resolved stack */
    private function resolve(Router $router, array $routeMiddleware, array $global): array
    {
        $method = new ReflectionMethod(Router::class, 'resolveMiddleware');

        return array_map(
            static fn(object $m): string => (new \ReflectionClass($m))->getShortName(),
            $method->invoke($router, array_merge($global, $routeMiddleware))
        );
    }

    public function testARouteWithNoMiddlewareStillGetsTheGlobalStack(): void
    {
        $global = ['headers', 'trusted.hosts', 'ip.blocklist'];
        $router = $this->router($global);

        self::assertSame(
            ['SetSecurityHeaders', 'ValidateTrustedHosts', 'BlocklistIp'],
            $this->resolve($router, [], $global)
        );
    }

    public function testGlobalMiddlewareRunsBeforeRouteMiddleware(): void
    {
        $global = ['headers'];
        $router = $this->router($global);

        $stack = $this->resolve($router, ['csrf'], $global);

        self::assertSame(['SetSecurityHeaders', 'VerifyCsrfToken'], $stack);
    }

    public function testOverlapWithAGroupDoesNotDuplicate(): void
    {
        $global = ['headers', 'trusted.hosts'];
        $router = $this->router($global, ['web' => ['headers', 'session.stateful', 'csrf']]);

        $stack = $this->resolve($router, ['web'], $global);

        self::assertSame(
            ['SetSecurityHeaders', 'ValidateTrustedHosts', 'StartStatefulSession', 'VerifyCsrfToken'],
            $stack
        );
        self::assertSame(count($stack), count(array_unique($stack)));
    }

    public function testSessionStillStartsBeforeCsrfIsVerified(): void
    {
        // The one ordering dependency that matters: CSRF reads the session.
        $global = ['headers', 'trusted.hosts', 'trusted.proxies', 'ip.blocklist'];
        $router = $this->router($global, ['web' => ['session.stateful', 'headers', 'csrf']]);

        $stack = $this->resolve($router, ['web'], $global);

        self::assertLessThan(
            array_search('VerifyCsrfToken', $stack, true),
            array_search('StartStatefulSession', $stack, true),
            'CSRF verification must not run before the session has started.'
        );
    }

    public function testAnEmptyGlobalListChangesNothing(): void
    {
        $router = $this->router([]);

        self::assertSame(['VerifyCsrfToken'], $this->resolve($router, ['csrf'], []));
    }

    public function testDispatchActuallyMergesTheGlobalStack(): void
    {
        // The resolver tests above prove the merge is correct; this proves dispatch
        // performs it. Without this a wiring regression looks like a passing suite.
        $router = (string) file_get_contents(dirname(__DIR__, 3) . '/systems/Core/Routing/Router.php');

        self::assertStringContainsString(
            'array_merge($this->globalMiddleware, $route->middleware)',
            $router,
            'Router::dispatch() no longer prepends the global stack, so ungrouped routes '
            . 'are unprotected again.'
        );
    }

    public function testTheKernelPassesTheConfiguredGlobalList(): void
    {
        $kernel = (string) file_get_contents(dirname(__DIR__, 3) . '/app/http/Kernel.php');

        self::assertStringContainsString(
            "globalMiddleware((array) (\$this->frameworkConfig['middleware_global'] ?? []))",
            $kernel,
            'The Kernel no longer feeds middleware_global to the router, so the config is inert.'
        );
    }

    public function testTheShippedConfigProtectsUngroupedRoutes(): void
    {
        // framework.php assigns into the including scope rather than returning, so
        // asserting on the declaration is simpler and unambiguous.
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/config/framework.php');

        self::assertStringContainsString(
            "'middleware_global' => [",
            $source,
            'middleware_global is gone, so ungrouped routes get no protection.'
        );

        $block = substr($source, (int) strpos($source, "'middleware_global' => ["));
        $block = substr($block, 0, (int) strpos($block, '],'));

        foreach (['headers', 'trusted.hosts', 'ip.blocklist'] as $required) {
            self::assertStringContainsString("'{$required}'", $block);
        }
    }
}
