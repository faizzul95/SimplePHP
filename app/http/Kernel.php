<?php

namespace App\Http;

use Core\Diagnostics\MemoryProfiler;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Router;

class Kernel
{
    private array $frameworkConfig;

    public function __construct()
    {
        $this->frameworkConfig = config('framework') ?? [];
    }

    public function handle(Request $request): void
    {
        $profileHandle = MemoryProfiler::begin($request);
        try {
            $router = new Router();
            $router->aliasMiddleware((array) ($this->frameworkConfig['middleware_aliases'] ?? []));
            $router->middlewareGroup((array) ($this->frameworkConfig['middleware_groups'] ?? []));

            $routeProvider = framework_service('route.provider');
            $routeProvider->map($request, $router);

            $result = $router->dispatch($request);

            if ($result === null) {
                return;
            }

            if (is_array($result)) {
                $status = isset($result['code']) ? (int) $result['code'] : 200;
                Response::json($result, $status);
                return; // Response::json exits, but added for clarity
            }

            if (is_string($result)) {
                echo $result;
            }
        } finally {
            MemoryProfiler::end($profileHandle, $request);
        }
    }
}
