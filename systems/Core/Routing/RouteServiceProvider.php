<?php

namespace Core\Routing;

use Core\Http\Request;

class RouteServiceProvider
{
    private array $frameworkConfig;

    public function __construct(array $frameworkConfig = [])
    {
        $this->frameworkConfig = $frameworkConfig;
    }

    public function map(Request $request, Router $router): void
    {
        // Always load both web and API routes so that:
        // 1. route('name') resolves names from both files regardless of request type
        // 2. API routes are available for web AJAX calls
        // 3. Web page-rendering routes are available for browser GETs
        $this->mapWeb($router);
        $this->mapApi($router);
    }

    public function mapWeb(Router $router): void
    {
        $file = ROOT_DIR . ($this->frameworkConfig['route_files']['web'] ?? 'app/routes/web.php');
        $groupMiddleware = (array) ($this->frameworkConfig['middleware_groups']['web'] ?? ['headers']);

        $router->group(['middleware' => $groupMiddleware], function (Router $router) use ($file) {
            require $file;
        });
    }

    /**
     * Map API routes.
     * 
     * Supports both versioned and non-versioned API routes:
     *   - Versioned:     /api/v1/users, /api/v2/users
     *   - Non-versioned: /api/users
     * 
     * The prefix and version are configurable via framework config:
     *   'api' => ['prefix' => 'api', 'version' => 'v1']
     * 
     * Set version to null or '' for non-versioned APIs.
     */
    public function mapApi(Router $router): void
    {
        $file = ROOT_DIR . ($this->frameworkConfig['route_files']['api'] ?? 'app/routes/api.php');
        $groupMiddleware = (array) ($this->frameworkConfig['middleware_groups']['api'] ?? ['headers']);

        $router->group(['middleware' => $groupMiddleware], function (Router $router) use ($file) {
            require $file;
        });
    }
}
