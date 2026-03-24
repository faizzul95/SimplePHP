<?php

namespace Core\Routing;

use Core\Http\FormRequest;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\ValidationException;
use Core\Http\Middleware\Pipeline;
use Throwable;

class Router
{
    private array $routes = [];
    private array $groupStack = [];
    private array $middlewareAliases = [];
    private array $middlewareGroups = [];
    private static array $namedRoutes = [];

    public function aliasMiddleware(array $aliases): void
    {
        $this->middlewareAliases = array_merge($this->middlewareAliases, $aliases);
    }

    /**
     * Register named middleware groups.
     *
     * A middleware group is a shorthand name that expands to a list of
     * middleware. Groups can reference aliases or other groups.
     *
     * Configuration (framework.php):
     *   'middleware_groups' => [
     *       'web' => ['headers', 'throttle:web'],
     *       'api' => ['headers', 'throttle:api', 'xss', 'api.log'],
     *   ]
     *
     * Usage in routes:
     *   $router->group(['middleware' => ['web']], fn($r) => ...);
     *   $router->get('/profile', ...)->middleware('web');
     */
    public function middlewareGroup(array $groups): void
    {
        $this->middlewareGroups = array_merge($this->middlewareGroups, $groups);
    }

    public function get(string $uri, $action): RouteDefinition
    {
        return $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, $action): RouteDefinition
    {
        return $this->addRoute('POST', $uri, $action);
    }

    public function put(string $uri, $action): RouteDefinition
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    public function patch(string $uri, $action): RouteDefinition
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    public function delete(string $uri, $action): RouteDefinition
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    public function options(string $uri, $action): RouteDefinition
    {
        return $this->addRoute('OPTIONS', $uri, $action);
    }

    public function resource(string $name, string $controller, array $options = []): array
    {
        $only = $options['only'] ?? ['index', 'store', 'show', 'update', 'destroy'];
        $except = $options['except'] ?? [];
        $actions = array_values(array_diff($only, $except));
        $base = '/' . trim($name, '/');

        $routes = [];

        if (in_array('index', $actions, true)) {
            $routes[] = $this->get($base, [$controller, 'index']);
        }

        if (in_array('store', $actions, true)) {
            $routes[] = $this->post($base, [$controller, 'store']);
        }

        if (in_array('show', $actions, true)) {
            $routes[] = $this->get($base . '/{id}', [$controller, 'show']);
        }

        if (in_array('update', $actions, true)) {
            $routes[] = $this->put($base . '/{id}', [$controller, 'update']);
            $routes[] = $this->patch($base . '/{id}', [$controller, 'update']);
        }

        if (in_array('destroy', $actions, true)) {
            $routes[] = $this->delete($base . '/{id}', [$controller, 'destroy']);
        }

        return $routes;
    }

    /**
     * Register an API resource route (without create/edit views)
     */
    public function apiResource(string $name, string $controller, array $options = []): array
    {
        $only = $options['only'] ?? ['index', 'store', 'show', 'update', 'destroy'];
        $options['only'] = $only;
        return $this->resource($name, $controller, $options);
    }

    /**
     * Register a route that responds to multiple HTTP methods
     */
    public function match(array $methods, string $uri, $action): array
    {
        $routes = [];
        foreach ($methods as $method) {
            $method = strtoupper($method);
            $routes[] = $this->addRoute($method, $uri, $action);
        }
        return $routes;
    }

    /**
     * Register a route that responds to all HTTP methods
     */
    public function any(string $uri, $action): array
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);
    }

    /**
     * Register a convenience redirect route
     */
    public function redirect(string $from, string $to, int $status = 302): RouteDefinition
    {
        return $this->get($from, function () use ($to, $status) {
            Response::redirect($this->normalizeRedirectTarget($to), $status);
        });
    }

    private function normalizeRedirectTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || preg_match('/[\r\n\0]/', $target) === 1) {
            return '/';
        }

        if (str_starts_with($target, '//')) {
            return '/';
        }

        $parts = parse_url($target);
        if ($parts === false) {
            return '/';
        }

        $isAbsolute = isset($parts['scheme']) || isset($parts['host']);
        if (!$isAbsolute) {
            $normalizedTarget = ltrim($target, '/');
            if (function_exists('url')) {
                return url($normalizedTarget);
            }

            return '/' . $normalizedTarget;
        }

        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = preg_replace('/:\d+$/', '', $requestHost);
        $targetHost = strtolower((string) ($parts['host'] ?? ''));
        $targetHost = preg_replace('/:\d+$/', '', $targetHost);

        if ($requestHost === '' || $targetHost === '' || $requestHost !== $targetHost) {
            return '/';
        }

        return $target;
    }

    /**
     * Register a convenience route that renders a view
     */
    public function view(string $uri, string $view, array $data = []): RouteDefinition
    {
        return $this->get($uri, function () use ($view, $data) {
            echo blade_engine()->render($view, $data);
            exit;
        });
    }

    /**
     * Register a fallback route (for 404 handling)
     */
    public function fallback($action): RouteDefinition
    {
        return $this->addRoute('GET', '/__fallback__', $action);
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = [
            'prefix' => trim((string) ($attributes['prefix'] ?? ''), '/'),
            'middleware' => (array) ($attributes['middleware'] ?? []),
        ];

        $callback($this);
        array_pop($this->groupStack);
    }

    public function dispatch(Request $request)
    {
        $this->indexNamedRoutes();
        $errorViews = $this->resolveErrorViews();
        $notFoundRedirect = $this->resolveNotFoundRedirect();
        $error404View = $errorViews['404'] ?? 'app/views/errors/404.php';
        $generalErrorView = $errorViews['general'] ?? 'app/views/errors/general_error.php';
        $errorImage = $errorViews['error_image'] ?? 'general/images/nodata/403.png';
        $match = $this->findRoute($request->method(), $request->path());

        if ($match === null) {
            // Check if the route exists for other methods (405 Method Not Allowed)
            $allowedMethods = $this->getAllowedMethods($request->path());
            if (!empty($allowedMethods)) {
                if (strtoupper($request->method()) === 'OPTIONS') {
                    $allow = array_values(array_unique(array_merge($allowedMethods, ['OPTIONS'])));
                    sort($allow);
                    http_response_code(204);
                    header('Allow: ' . implode(', ', $allow));
                    return null;
                }

                if ($request->expectsJson()) {
                    Response::json([
                        'code' => 405,
                        'message' => 'Method not allowed',
                        'allowed_methods' => $allowedMethods,
                    ], 405);
                }

                http_response_code(405);
                header('Allow: ' . implode(', ', $allowedMethods));
                render($generalErrorView, [
                    'image' => $errorImage,
                    'title' => '405 Method Not Allowed',
                    'message' => 'The ' . $request->method() . ' method is not supported for this route.',
                ]);
                return null;
            }

            if ($request->expectsJson()) {
                Response::json(['code' => 404, 'message' => 'Route not found'], 404);
            }

            if (!$request->expectsJson()) {
                $currentPath = trim($this->normalizeUri($request->path()), '/');
                $targetPath = trim($notFoundRedirect, '/');

                if ($targetPath !== '' && $currentPath !== $targetPath) {
                    Response::redirect(url($targetPath));
                }
            }

            // Check for a registered fallback route
            $fallback = $this->findRoute('GET', '/__fallback__');
            if ($fallback !== null) {
                [$fallbackRoute, $fallbackParams] = $fallback;
                return $this->invokeAction($fallbackRoute->action, $request, $fallbackParams);
            }

            http_response_code(404);
            render($error404View, [
                'image' => $errorImage,
                'title' => '404 Page Not Found',
                'message' => 'Oops! 😖 The requested URL was not found on this server.',
            ]);
            return null;
        }

        [$route, $params] = $match;
        $request->setRouteParams($params);

        $middleware = $this->resolveMiddleware($route->middleware);

        $pipeline = new Pipeline();

        try {
            return $pipeline->process($request, $middleware, function (Request $request) use ($route, $params) {
                return $this->invokeAction($route->action, $request, $params);
            });
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                Response::json([
                    'code' => $e->statusCode(),
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->statusCode());
            }

            http_response_code($e->statusCode());
            render($generalErrorView, [
                'image' => $errorImage,
                'title' => (string) $e->statusCode(),
                'message' => $e->getMessage(),
            ]);
            return null;
        } catch (Throwable $e) {
            logger()->logException($e);

            if ($request->expectsJson()) {
                Response::json(['code' => 500, 'message' => 'Internal Server Error'], 500);
            }

            http_response_code(500);
            render($generalErrorView, [
                'image' => $errorImage,
                'title' => '500',
                'message' => 'Internal Server Error',
            ]);
            return null;
        }
    }

    private function addRoute(string $method, string $uri, $action): RouteDefinition
    {
        $uri = $this->normalizeUri($uri);

        $groupPrefix = $this->resolveGroupPrefix();
        if ($groupPrefix !== '') {
            $uri = $this->normalizeUri('/' . $groupPrefix . '/' . ltrim($uri, '/'));
        }

        $route = new RouteDefinition($method, $uri, $action);
        $route->middleware($this->resolveGroupMiddleware());

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Resolve error view paths from framework config.
     * Falls back to sensible defaults when config is unavailable.
     */
    private function resolveErrorViews(): array
    {
        $defaults = [
            '404'         => 'app/views/errors/404.php',
            'general'     => 'app/views/errors/general_error.php',
            'error_image' => 'general/images/nodata/403.png',
        ];

        if (!function_exists('config')) {
            return $defaults;
        }

        $cfg = config('framework.error_views');
        if (!is_array($cfg)) {
            return $defaults;
        }

        return array_replace($defaults, $cfg);
    }

    /**
     * Resolve web not-found redirect target from config.
     * Supports route name (preferred) or direct path fallback.
     */
    private function resolveNotFoundRedirect(): string
    {
        $default = 'login';

        if (!function_exists('config')) {
            return $default;
        }

        $target = (string) config('framework.not_found_redirect.web', $default);
        if ($target === '') {
            return $default;
        }

        if (function_exists('route')) {
            $resolved = (string) route($target);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return ltrim($target, '/');
    }

    /** @var array Pre-compiled regex cache */
    private array $compiledRegex = [];

    /** @var array Static routes indexed by method+path for O(1) lookup */
    private array $staticRoutes = [];

    /** @var array Dynamic routes that contain parameters */
    private array $dynamicRoutes = [];

    /** @var bool Whether the route index has been built */
    private bool $routeIndexBuilt = false;

    /** @var array Registered HTTP methods found during index build */
    private array $registeredMethods = [];

    /** @var array Cache for resolved middleware instances (keyed by serialized middleware list) */
    private array $middlewareCache = [];

    private function buildRouteIndex(): void
    {
        if ($this->routeIndexBuilt) {
            return;
        }

        foreach ($this->routes as $index => $route) {
            $this->registeredMethods[$route->method] = true;
            $hasParams = str_contains($route->uri, '{');

            // Skip fallback routes from index
            if ($route->uri === '/__fallback__') {
                $regex = $this->compileRouteRegex($route->uri, $route->wheres);
                $this->compiledRegex[$index] = $regex;
                $this->dynamicRoutes[$route->method][$index] = $route;
                continue;
            }

            if (!$hasParams) {
                // Static route: index by method+uri for O(1) lookup
                $key = $route->method . ':' . $route->uri;
                $this->staticRoutes[$key] = $route;
            } else {
                // Dynamic route: pre-compile regex with where() constraints
                $regex = $this->compileRouteRegex($route->uri, $route->wheres);
                $this->compiledRegex[$index] = $regex;
                $this->dynamicRoutes[$route->method][$index] = $route;
            }
        }

        $this->routeIndexBuilt = true;
    }

    private function findRoute(string $method, string $path): ?array
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $normalizedPath = $this->normalizeUri($path);

        $this->buildRouteIndex();

        // 1. Try static route lookup first (O(1))
        $staticKey = $method . ':' . $normalizedPath;
        if (isset($this->staticRoutes[$staticKey])) {
            return [$this->staticRoutes[$staticKey], []];
        }

        // 2. Try dynamic routes with pre-compiled regex
        $methodRoutes = $this->dynamicRoutes[$method] ?? [];
        foreach ($methodRoutes as $index => $route) {
            $regex = $this->compiledRegex[$index];
            $matches = [];
            if (preg_match($regex, $normalizedPath, $matches) === 1) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                return [$route, $params];
            }
        }

        return null;
    }

    private function compileRouteRegex(string $uri, array $wheres = []): string
    {
        $segments = explode('/', trim($uri, '/'));

        if (empty($segments) || $segments === ['']) {
            return '#^/$#';
        }

        $patternParts = [];

        foreach ($segments as $segment) {
            // Optional parameter: {id?}
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}$/', $segment, $matches) === 1) {
                $paramName = $matches[1];
                $constraint = $wheres[$paramName] ?? '[A-Za-z0-9_-]+';
                $patternParts[] = '(?:/(?P<' . $paramName . '>' . $constraint . '))?';
                continue;
            }

            // Required parameter: {id}
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $paramName = $matches[1];
                $constraint = $wheres[$paramName] ?? '[A-Za-z0-9_-]+';
                $patternParts[] = '/(?P<' . $paramName . '>' . $constraint . ')';
                continue;
            }

            $patternParts[] = '/' . preg_quote($segment, '#');
        }

        return '#^' . implode('', $patternParts) . '$#';
    }

    private function invokeAction($action, Request $request, array $params)
    {
        if (is_callable($action)) {
            return $this->invokeCallable($action, $request, $params);
        }

        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $controller = new $class();
            return $this->invokeCallable([$controller, $method], $request, $params);
        }

        if (is_array($action) && count($action) === 2) {
            [$classOrObject, $method] = $action;
            $controller = is_object($classOrObject) ? $classOrObject : new $classOrObject();
            return $this->invokeCallable([$controller, $method], $request, $params);
        }

        throw new \RuntimeException('Invalid route action.');
    }

    private function invokeCallable(callable $callable, Request $request, array $params)
    {
        $ref = is_array($callable)
            ? new \ReflectionMethod($callable[0], $callable[1])
            : new \ReflectionFunction($callable);

        $arguments = [];
        foreach ($ref->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if ($typeName === Request::class) {
                $arguments[] = $request;
                continue;
            }

            if ($typeName !== null && is_subclass_of($typeName, FormRequest::class)) {
                $formRequest = new $typeName();
                $formRequest->setRequest($request);
                $formRequest->validateResolved();
                $arguments[] = $formRequest;
                continue;
            }

            $name = $parameter->getName();
            if (array_key_exists($name, $params)) {
                $arguments[] = $params[$name];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $arguments[] = null;
        }

        return call_user_func_array($callable, $arguments);
    }

    private function resolveMiddleware(array $middleware): array
    {
        // Cache resolved middleware instances to avoid repeated reflection/instantiation
        $cacheKey = implode('|', $middleware);
        if (isset($this->middlewareCache[$cacheKey])) {
            return $this->middlewareCache[$cacheKey];
        }

        // Expand middleware groups first, then resolve aliases
        $expanded = $this->expandMiddlewareGroups($middleware);
        $instances = [];

        foreach ($expanded as $item) {
            $name = (string) $item;
            $parameters = [];

            if (str_contains($name, ':')) {
                [$name, $parameterString] = explode(':', $name, 2);
                $parameters = array_filter(array_map('trim', explode(',', (string) $parameterString)), function ($value) {
                    return $value !== '';
                });
            }

            $class = $this->middlewareAliases[$name] ?? $name;
            if (!class_exists($class)) {
                throw new \RuntimeException("Middleware class {$class} not found.");
            }

            $instance = new $class();

            if (!empty($parameters) && method_exists($instance, 'setParameters')) {
                $instance->setParameters($parameters);
            }

            $instances[] = $instance;
        }

        $this->middlewareCache[$cacheKey] = $instances;
        return $instances;
    }

    /**
     * Expand middleware group names into their individual middleware.
     *
     * If a middleware name matches a registered group, it is replaced
     * by the group's middleware list. Otherwise it passes through as-is.
     *
     * @param string[] $middleware
     * @return string[]
     */
    private function expandMiddlewareGroups(array $middleware, array $seen = []): array
    {
        $result = [];

        foreach ($middleware as $item) {
            $name = (string) $item;
            // Strip parameters before checking group name
            $baseName = str_contains($name, ':') ? explode(':', $name, 2)[0] : $name;

            if (isset($this->middlewareGroups[$baseName])) {
                // Prevent infinite recursion from circular group references
                if (in_array($baseName, $seen, true)) {
                    continue;
                }
                // Recursively expand in case groups reference other groups
                $result = array_merge($result, $this->expandMiddlewareGroups($this->middlewareGroups[$baseName], array_merge($seen, [$baseName])));
            } else {
                $result[] = $name;
            }
        }

        return array_values(array_unique($result));
    }

    private function resolveGroupPrefix(): string
    {
        $parts = [];
        foreach ($this->groupStack as $group) {
            if (!empty($group['prefix'])) {
                $parts[] = trim($group['prefix'], '/');
            }
        }

        return trim(implode('/', $parts), '/');
    }

    private function resolveGroupMiddleware(): array
    {
        $middleware = [];

        foreach ($this->groupStack as $group) {
            $middleware = array_merge($middleware, (array) $group['middleware']);
        }

        return array_values(array_unique($middleware));
    }

    private function normalizeUri(string $uri): string
    {
        $uri = '/' . trim($uri, '/');
        return $uri === '//' ? '/' : $uri;
    }

    /**
     * Get allowed HTTP methods for a given path (for 405 responses).
     * Uses the pre-built route index and cached regex for performance.
     */
    private function getAllowedMethods(string $path): array
    {
        $normalizedPath = $this->normalizeUri($path);
        $methods = [];

        $this->buildRouteIndex();

        // Check static routes (O(1) per method)
        foreach (array_keys($this->registeredMethods) as $method) {
            $key = $method . ':' . $normalizedPath;
            if (isset($this->staticRoutes[$key])) {
                $methods[] = $method;
            }
        }

        // Check dynamic routes using pre-compiled regex
        foreach ($this->dynamicRoutes as $method => $routes) {
            if (in_array($method, $methods, true)) {
                continue;
            }
            foreach ($routes as $index => $route) {
                $regex = $this->compiledRegex[$index];
                if (preg_match($regex, $normalizedPath) === 1) {
                    $methods[] = $method;
                    break; // One match per method is enough
                }
            }
        }

        return $methods;
    }

    private function indexNamedRoutes(): void
    {
        foreach ($this->routes as $route) {
            if (!empty($route->name)) {
                self::$namedRoutes[$route->name] = $route->uri;
            }
        }
    }

    public static function hasNamedRoute(string $name): bool
    {
        return isset(self::$namedRoutes[$name]);
    }

    public static function urlFor(string $name, array $params = []): ?string
    {
        if (!isset(self::$namedRoutes[$name])) {
            return null;
        }

        $uri = self::$namedRoutes[$name];
        $remaining = $params;

        $segments = explode('/', trim($uri, '/'));
        if (empty($segments) || $segments === ['']) {
            return '/';
        }

        $resolvedSegments = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}$/', $segment, $matches) === 1) {
                $paramName = $matches[1];
                if (array_key_exists($paramName, $remaining)) {
                    $resolvedSegments[] = rawurlencode((string) $remaining[$paramName]);
                    unset($remaining[$paramName]);
                }
                continue;
            }

            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $paramName = $matches[1];
                if (!array_key_exists($paramName, $remaining)) {
                    // frontend code can replace it later (e.g. .replace('{id}', value)).
                    $resolvedSegments[] = '{' . $paramName . '}';
                    continue;
                }

                $resolvedSegments[] = rawurlencode((string) $remaining[$paramName]);
                unset($remaining[$paramName]);
                continue;
            }

            $resolvedSegments[] = $segment;
        }

        $path = '/' . implode('/', $resolvedSegments);

        if (!empty($remaining)) {
            $query = http_build_query($remaining);
            if ($query !== '') {
                $path .= '?' . $query;
            }
        }

        return $path;
    }

    /**
     * Get all registered routes (for route:list command).
     *
     * @return RouteDefinition[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
