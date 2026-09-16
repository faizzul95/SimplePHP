<?php

namespace Core\Routing;

use Core\Http\Emitter;
use Core\Http\FormRequest;
use Core\Http\HtmlResponse;
use Core\Http\JsonResponse;
use Core\Http\RedirectResponse;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Http\Response;
use Core\Http\ResponseEmitted;
use Core\Http\ValidationException;
use Core\Http\Middleware\Pipeline;
use Throwable;

class Router
{
    /** @var self|null Singleton instance used by route caching. */
    private static ?self $currentInstance = null;

    /** Capture the router instance so RouteCacheCommand can retrieve it. */
    public static function setInstance(self $router): void
    {
        self::$currentInstance = $router;
    }

    /** Return the last-registered router instance (set by RouteServiceProvider). */
    public static function getInstance(): ?self
    {
        return self::$currentInstance;
    }

    /**
     * Build the route index and return a serializable cache payload.
     *
     * Closure actions cannot be stored — routes using closures are skipped
     * with a warning. All class-based routes (string "Class@method" or
     * [ClassName::class, 'method'] arrays) are cached normally.
     *
     * @return array{static: array, dynamic: array, named: array, patterns: array, skipped: int}
     */
    public function getCompiledRoutes(): array
    {
        $this->buildRouteIndex();
        $this->indexNamedRoutes();

        // Which routes were dropped, not just how many. A count alone tells an
        // operator that something will 404 in production but not what, and the
        // symptom — works in development, missing after `deploy` — is one of the
        // harder ones to trace back to its cause.
        $skippedRoutes = [];

        // Serialize static routes
        $staticExport = [];
        foreach ($this->staticRoutes as $key => $route) {
            if ($route->action instanceof \Closure) {
                $skippedRoutes[] = $route->method . ' ' . $route->uri;
                continue;
            }
            $staticExport[$key] = $this->routeToArray($route);
        }

        // Serialize dynamic routes (include compiled regex)
        $dynamicExport = [];
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $index => $route) {
                if ($route->action instanceof \Closure) {
                    $skippedRoutes[] = $route->method . ' ' . $route->uri;
                    continue;
                }
                $dynamicExport[$method][] = array_merge(
                    $this->routeToArray($route),
                    ['regex' => $this->compiledRegex[$index] ?? '']
                );
            }
        }

        return [
            'static'       => $staticExport,
            'dynamic'      => $dynamicExport,
            'named'        => self::$namedRoutes,
            'patterns'     => self::$globalPatterns,
            'skipped'      => count($skippedRoutes),
            'skipped_routes' => $skippedRoutes,
            'generated_at' => time(),
        ];
    }

    /**
     * Restore a pre-built route index from a cache file produced by getCompiledRoutes().
     *
     * Returns true on success, false if the cache is missing, unreadable, or malformed.
     * When false is returned the caller MUST fall back to normal route registration.
     *
     * @param string $cacheFile Absolute path to the routes.cache.php file.
     */
    public function loadFromCache(string $cacheFile): bool
    {
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return false;
        }

        try {
            $data = include $cacheFile;
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($data) || !isset($data['static'], $data['dynamic'], $data['named'])) {
            return false;
        }

        // Reset all existing route state before restoring, so a double-call
        // (e.g. during testing) never accumulates stale routes.
        $this->routes            = [];
        $this->staticRoutes      = [];
        $this->dynamicRoutes     = [];
        $this->compiledRegex     = [];
        $this->registeredMethods = [];
        $this->routeIndexBuilt   = false;

        try {
            // Restore static routes
            foreach ((array) $data['static'] as $key => $row) {
                $route = $this->arrayToRoute((array) $row);
                $this->staticRoutes[$key]                = $route;
                $this->registeredMethods[$route->method] = true;
                $this->routes[]                          = $route;
            }

            // Restore dynamic routes + pre-compiled regex
            foreach ((array) $data['dynamic'] as $method => $rows) {
                foreach ((array) $rows as $row) {
                    $route = $this->arrayToRoute((array) $row);
                    $regex = isset($row['regex']) && is_string($row['regex']) ? $row['regex'] : '';
                    $index = count($this->routes);
                    $this->routes[]                       = $route;
                    $this->compiledRegex[$index]          = $regex;
                    $this->dynamicRoutes[$method][$index] = $route;
                    $this->registeredMethods[$method]     = true;
                }
            }
        } catch (\Throwable) {
            // Cache is corrupt — reset everything and signal failure so route
            // files are loaded normally on this request.
            $this->routes            = [];
            $this->staticRoutes      = [];
            $this->dynamicRoutes     = [];
            $this->compiledRegex     = [];
            $this->registeredMethods = [];
            return false;
        }

        // Restore named routes and global patterns
        self::$namedRoutes    = is_array($data['named']) ? $data['named'] : [];
        self::$globalPatterns = array_merge(
            self::$globalPatterns,
            is_array($data['patterns'] ?? null) ? $data['patterns'] : []
        );

        $this->routeIndexBuilt = true;

        return true;
    }

    /** Convert a RouteDefinition to a plain array safe for var_export(). */
    private function routeToArray(RouteDefinition $route): array
    {
        return [
            'method'     => $route->method,
            'uri'        => $route->uri,
            'action'     => $route->action,   // must be string or array (no closures)
            'middleware' => $route->middleware,
            'name'       => $route->name,
            'wheres'     => $route->wheres,
        ];
    }

    /**
     * Reconstruct a RouteDefinition from a cached plain array.
     *
     * @throws \UnexpectedValueException if required fields are absent.
     */
    private function arrayToRoute(array $row): RouteDefinition
    {
        if (!isset($row['method'], $row['uri'], $row['action'])) {
            throw new \UnexpectedValueException(
                'Malformed route cache entry: missing method, uri, or action.'
            );
        }

        $route             = new RouteDefinition((string) $row['method'], (string) $row['uri'], $row['action']);
        $route->middleware = is_array($row['middleware'] ?? null) ? $row['middleware'] : [];
        $route->name       = isset($row['name']) ? (string) $row['name'] : null;
        $route->wheres     = is_array($row['wheres'] ?? null) ? $row['wheres'] : [];
        return $route;
    }

    private array $routes = [];
    private array $groupStack = [];
    private array $middlewareAliases = [];
    private array $middlewareGroups = [];
    private array $globalMiddleware = [];
    private static array $namedRoutes = [];

    /**
     * Global parameter type constraints applied to all routes when no
     * per-route ->where() constraint is set for a given parameter name.
     *
     * Pre-seeded with safe defaults for the most common parameter names:
     *   {id}   → digits only        (prevents type-confusion / path traversal)
     *   {uuid} → UUID v1-v5 format  (strict format enforcement)
     *   {slug} → lowercase alphanum + hyphens
     *
     * Add custom patterns in RouteServiceProvider::map() or bootstrap:
     *   Router::pattern('locale', '[a-z]{2}');
     *   Router::pattern(['year' => '[0-9]{4}', 'month' => '0[1-9]|1[0-2]']);
     */
    private static array $globalPatterns = [
        'id'   => '[0-9]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}',
        'slug' => '[a-z0-9][a-z0-9-]*',
    ];

    /**
     * Register a global parameter constraint.
     *
     * Once registered, every route whose URI contains {paramName} will
     * automatically enforce this regex unless the route declares its own
     * ->where(paramName, ...) override.
     *
     * @param string|array $param  Parameter name or associative [param => pattern]
     * @param string|null  $regex  Regex pattern (when $param is a string)
     */
    public static function pattern(string|array $param, ?string $regex = null): void
    {
        if (is_array($param)) {
            foreach ($param as $key => $pat) {
                self::$globalPatterns[(string) $key] = (string) $pat;
            }
        } elseif ($regex !== null) {
            self::$globalPatterns[(string) $param] = $regex;
        }
    }

    /**
     * Return the currently registered global patterns (read-only snapshot).
     *
     * @return array<string, string>
     */
    public static function getPatterns(): array
    {
        return self::$globalPatterns;
    }

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

    /**
     * Middleware applied to every route, ahead of its own stack.
     *
     * Without this, a route registered outside the web/api groups silently gets no
     * CSRF, security headers, host validation or rate limiting, and nothing reports it.
     */
    public function globalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = array_values(array_unique(array_map('strval', $middleware)));
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
        return $this->get($from, function () use ($to, $status): Responsable {
            return new RedirectResponse($this->normalizeRedirectTarget($to), $status);
        });
    }

    private function normalizeRedirectTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '/';
        }

        if (preg_match('#^https?://#i', $target) === 1) {
            return Response::sanitizeRedirectTarget($target, false);
        }

        return Response::sanitizeRedirectTarget(url(ltrim($target, '/')), false);
    }

    /**
     * Register a convenience route that renders a view
     */
    public function view(string $uri, string $view, array $data = []): RouteDefinition
    {
        return $this->get($uri, function () use ($view, $data): Responsable {
            return response()->view($view, $data);
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

                    return new HtmlResponse('', 204, ['Allow' => implode(', ', $allow)]);
                }

                $allowHeader = ['Allow' => implode(', ', $allowedMethods)];

                if ($request->expectsJson()) {
                    return new JsonResponse([
                        'code' => 405,
                        'message' => 'Method not allowed',
                        'allowed_methods' => $allowedMethods,
                    ], 405, $allowHeader);
                }

                return $this->renderErrorView($generalErrorView, [
                    'image' => $errorImage,
                    'title' => '405 Method Not Allowed',
                    'message' => 'The ' . $request->method() . ' method is not supported for this route.',
                ], 405, $allowHeader);
            }

            if ($request->expectsJson()) {
                return new JsonResponse(['code' => 404, 'message' => 'Route not found'], 404);
            }

            $currentPath = trim($this->normalizeUri($request->path()), '/');
            $targetPath = trim($notFoundRedirect, '/');

            if ($targetPath !== '' && $currentPath !== $targetPath) {
                return new RedirectResponse(url($targetPath));
            }

            // Check for a registered fallback route
            $fallback = $this->findRoute('GET', '/__fallback__');
            if ($fallback !== null) {
                [$fallbackRoute, $fallbackParams] = $fallback;

                try {
                    return $this->invokeAction($fallbackRoute->action, $request, $fallbackParams);
                } catch (ResponseEmitted $emitted) {
                    return $emitted->response();
                }
            }

            return $this->renderErrorView($error404View, [
                'image' => $errorImage,
                'title' => '404 Page Not Found',
                'message' => 'Oops! 😖 The requested URL was not found on this server.',
            ], 404);
        }

        [$route, $params] = $match;
        $request->setRouteParams($params);
        $request->setAttributes([
            'route.uri' => $route->uri,
            'route.name' => $route->name,
            'route.middleware' => $route->middleware,
        ]);

        $middleware = $this->resolveMiddleware(
            array_merge($this->globalMiddleware, $route->middleware)
        );

        $pipeline = new Pipeline();

        try {
            return $pipeline->process($request, $middleware, function (Request $request) use ($route, $params) {
                try {
                    return $this->invokeAction($route->action, $request, $params);
                } catch (ResponseEmitted $emitted) {
                    // Caught here, inside the pipeline, so every middleware's
                    // post-$next() code still runs on the way back out. The full
                    // response object is returned, not just its array payload —
                    // otherwise a redirect or an HTML body loses its status and
                    // headers on the way through.
                    return $emitted->response();
                }
            });
        } catch (ResponseEmitted $emitted) {
            // A middleware short-circuited (rate limit, blocklist). It threw from
            // outside the destination closure, so it lands here instead.
            return $emitted->response();
        } catch (ValidationException $e) {
            // A rejected write goes through Reply, which is the same negotiation
            // controllers use: JSON with an `errors` object for an API caller,
            // back to the form with the errors and the old input for a browser.
            // Written out here as well, the two drifted — the shapes have to match
            // or a client cannot rely on either.
            if ($e->statusCode() === 422 && !in_array($request->method(), ['GET', 'HEAD'], true)) {
                return \Core\Http\Reply::make($e->getMessage())
                    ->code($e->statusCode())
                    ->errors($e->errors());
            }

            if ($request->expectsJson()) {
                return new JsonResponse([
                    'code' => $e->statusCode(),
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->statusCode());
            }

            // 403 from authorize(), or a 422 on a read: neither has a form to go
            // back to, so the error page is the answer.
            return $this->renderErrorView($generalErrorView, [
                'image' => $errorImage,
                'title' => (string) $e->statusCode(),
                'message' => $e->getMessage(),
            ], $e->statusCode());
        } catch (Throwable $e) {
            logger()->logException($e);

            if ($request->expectsJson()) {
                return new JsonResponse(['code' => 500, 'message' => 'Internal Server Error'], 500);
            }

            return $this->renderErrorView($generalErrorView, [
                'image' => $errorImage,
                'title' => '500',
                'message' => 'Internal Server Error',
            ], 500);
        }
    }

    /**
     * Render an error view into a response instead of echoing it.
     *
     * render() writes straight to the output stream, which bypasses the single
     * emission point and makes the status code depend on call order. Buffering it
     * keeps every exit path in dispatch() returning a Responsable.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private function renderErrorView(string $view, array $data, int $status, array $headers = []): Responsable
    {
        $level = ob_get_level();

        try {
            ob_start();
            render($view, $data);
            $content = (string) ob_get_clean();
        } catch (Throwable $e) {
            // Clean up any buffer the failed render left open, then fall back to a
            // plain body — an error page that itself errors must not produce a
            // blank 200.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            // SafeLog, not logger(): an error page that fails while the container
            // is half-built must still produce a body rather than a new exception.
            \Core\Support\SafeLog::exception($e, 'Error view failed to render');
            $content = (string) ($data['title'] ?? $status) . ' - ' . (string) ($data['message'] ?? 'Error');
        }

        return new HtmlResponse($content, $status, array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $headers
        ));
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

        if ($segments === ['']) {
            return '#^/$#';
        }

        $patternParts = [];

        foreach ($segments as $segment) {
            // Optional parameter: {id?}
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}$/', $segment, $matches) === 1) {
                $paramName = $matches[1];
                $constraint = $wheres[$paramName] ?? self::$globalPatterns[$paramName] ?? '[A-Za-z0-9_-]+';
                $patternParts[] = '(?:/(?P<' . $paramName . '>' . $constraint . '))?';
                continue;
            }

            // Required parameter: {id}
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $paramName = $matches[1];
                $constraint = $wheres[$paramName] ?? self::$globalPatterns[$paramName] ?? '[A-Za-z0-9_-]+';
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

            // Route model binding: auto-resolve Model subclasses from route params
            if (
                $typeName !== null
                && class_exists($typeName)
                && is_subclass_of($typeName, \Core\Database\Model::class)
                && array_key_exists($name, $params)
            ) {
                $model = $typeName::findById($params[$name]);
                if ($model === null) {
                    // Was: echo JSON + exit, regardless of what the client asked for,
                    // so a browser hitting a bad id got raw JSON instead of the 404
                    // view. Throwing lets dispatch() negotiate content the same way
                    // every other error path does.
                    throw new ResponseEmitted(
                        ['code' => 404, 'message' => 'Resource not found.'],
                        404
                    );
                }
                $arguments[] = $model;
                continue;
            }

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
        $expanded = $this->normalizeOverridableMiddleware($expanded);
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
     * Normalize middleware aliases where a later declaration should override an
     * earlier one with the same alias.
     *
     * Overridable aliases are configured in framework.middleware_override_aliases.
     * This allows group defaults such as `xss` to stay enabled globally while a
     * route-specific declaration like `xss:email_body` replaces the earlier one.
     *
     * @param string[] $middleware
     * @return string[]
     */
    private function normalizeOverridableMiddleware(array $middleware): array
    {
        $overridable = $this->resolveOverridableMiddlewareAliases();
        if (empty($overridable)) {
            return $middleware;
        }

        $lastIndexByBase = [];

        foreach ($middleware as $index => $item) {
            $name = (string) $item;
            $baseName = str_contains($name, ':') ? explode(':', $name, 2)[0] : $name;

            if (in_array($baseName, $overridable, true)) {
                $lastIndexByBase[$baseName] = $index;
            }
        }

        if (empty($lastIndexByBase)) {
            return $middleware;
        }

        $normalized = [];

        foreach ($middleware as $index => $item) {
            $name = (string) $item;
            $baseName = str_contains($name, ':') ? explode(':', $name, 2)[0] : $name;

            if (isset($lastIndexByBase[$baseName]) && $lastIndexByBase[$baseName] !== $index) {
                continue;
            }

            $normalized[] = $name;
        }

        return $normalized;
    }

    /**
     * Resolve middleware aliases that use last-one-wins override semantics.
     *
     * @return string[]
     */
    private function resolveOverridableMiddlewareAliases(): array
    {
        if (!function_exists('config')) {
            return ['xss'];
        }

        $configured = config('framework.middleware_override_aliases', ['xss']);
        if (!is_array($configured)) {
            return ['xss'];
        }

        return array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $configured), static function (string $value): bool {
            return $value !== '';
        }));
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
            if ($route instanceof RouteDefinition && !empty($route->name)) {
                self::$namedRoutes[$route->name] = $route->uri;
            }
        }
    }

    public static function registerNamedRoute(string $name, string $uri): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        self::$namedRoutes[$name] = $uri;
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
        if ($segments === ['']) {
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
