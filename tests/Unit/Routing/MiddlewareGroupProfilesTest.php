<?php

declare(strict_types=1);

use Core\Http\Middleware\MiddlewareInterface;
use Core\Http\Request;
use Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class MiddlewareGroupProfilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['config']['framework']['middleware_override_aliases'] = ['xss', 'content.type'];

        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/profile',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }

    public function testNestedMiddlewareGroupsExpandDuringDispatch(): void
    {
        $router = new Router();
        $router->aliasMiddleware([
            'trace' => MiddlewareGroupProfilesTraceMiddleware::class,
            'auth' => MiddlewareGroupProfilesAuthMiddleware::class,
            'permission' => MiddlewareGroupProfilesPermissionMiddleware::class,
            'content.type' => MiddlewareGroupProfilesTraceMiddleware::class,
        ]);
        $router->middlewareGroup([
            'api' => ['trace:api'],
            'api.app' => ['api', 'auth'],
            'api.upload' => ['api.app', 'permission:user-upload-profile', 'content.type:multipart'],
        ]);

        $router->get('/profile', static function (Request $request): array {
            return [
                'code' => 200,
                'trace' => $request->attributes('trace', []),
            ];
        })->middleware('api.upload');

        $result = $router->dispatch(new Request([], [], $_SERVER, []));

        self::assertSame(200, $result['code']);
        self::assertSame(['api', 'auth', 'permission:user-upload-profile', 'multipart'], $result['trace']);
    }

    public function testOverridableMiddlewareKeepsLastContentTypeDeclaration(): void
    {
        $router = new Router();
        $router->aliasMiddleware([
            'content.type' => MiddlewareGroupProfilesJoinedTraceMiddleware::class,
        ]);
        $router->middlewareGroup([
            'api' => ['content.type:json,form'],
        ]);

        $router->post('/profile', static function (Request $request): array {
            return [
                'code' => 200,
                'trace' => $request->attributes('trace', []),
            ];
        })->middleware('api')->middleware('content.type:json,form,multipart');

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = $router->dispatch(new Request([], [], $_SERVER, []));

        self::assertSame(200, $result['code']);
        self::assertSame(['json,form,multipart'], $result['trace']);
    }
}

final class MiddlewareGroupProfilesTraceMiddleware implements MiddlewareInterface
{
    private string $label = 'unknown';

    public function setParameters(array $parameters): void
    {
        $this->label = (string) ($parameters[0] ?? 'unknown');
    }

    public function handle(Request $request, callable $next)
    {
        $trace = (array) $request->attributes('trace', []);
        $trace[] = $this->label;
        $request->setAttribute('trace', $trace);

        return $next($request);
    }
}

final class MiddlewareGroupProfilesAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        $trace = (array) $request->attributes('trace', []);
        $trace[] = 'auth';
        $request->setAttribute('trace', $trace);

        return $next($request);
    }
}

final class MiddlewareGroupProfilesPermissionMiddleware implements MiddlewareInterface
{
    private string $permission = 'unknown';

    public function setParameters(array $parameters): void
    {
        $this->permission = (string) ($parameters[0] ?? 'unknown');
    }

    public function handle(Request $request, callable $next)
    {
        $trace = (array) $request->attributes('trace', []);
        $trace[] = 'permission:' . $this->permission;
        $request->setAttribute('trace', $trace);

        return $next($request);
    }
}

final class MiddlewareGroupProfilesJoinedTraceMiddleware implements MiddlewareInterface
{
    private string $label = 'unknown';

    public function setParameters(array $parameters): void
    {
        $this->label = implode(',', array_map(static function ($value): string {
            return (string) $value;
        }, $parameters));
    }

    public function handle(Request $request, callable $next)
    {
        $trace = (array) $request->attributes('trace', []);
        $trace[] = $this->label;
        $request->setAttribute('trace', $trace);

        return $next($request);
    }
}