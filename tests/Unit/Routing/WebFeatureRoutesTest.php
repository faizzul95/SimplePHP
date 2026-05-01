<?php

declare(strict_types=1);

use Core\Routing\RouteDefinition;
use Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class WebFeatureRoutesTest extends TestCase
{
    /**
     * @return array<string, RouteDefinition>
     */
    private function loadNamedRoutes(): array
    {
        $router = new Router();

        require __DIR__ . '/../../../app/routes/web.php';

        $routes = [];
        foreach ($router->getRoutes() as $route) {
            if ($route instanceof RouteDefinition && $route->name !== null) {
                $routes[$route->name] = $route;
            }
        }

        return $routes;
    }

    public function testFeatureSensitiveWebPagesCarryFeatureMiddleware(): void
    {
        $routes = $this->loadNamedRoutes();

        self::assertArrayHasKey('rbac.roles', $routes);
        self::assertContains('web', $routes['rbac.roles']->middleware);
        self::assertContains('auth.web', $routes['rbac.roles']->middleware);
        self::assertContains('feature:rbac.role', $routes['rbac.roles']->middleware);
        self::assertContains('permission:rbac-roles-view', $routes['rbac.roles']->middleware);

        self::assertArrayHasKey('rbac.email', $routes);
        self::assertContains('web', $routes['rbac.email']->middleware);
        self::assertContains('auth.web', $routes['rbac.email']->middleware);
        self::assertContains('feature:email-template', $routes['rbac.email']->middleware);
        self::assertContains('permission:rbac-email-view', $routes['rbac.email']->middleware);
    }

    public function testGuestAndAuthenticatedWebRoutesCarryExpectedMiddleware(): void
    {
        $routes = $this->loadNamedRoutes();

        self::assertArrayHasKey('login', $routes);
        self::assertContains('web', $routes['login']->middleware);
        self::assertContains('guest', $routes['login']->middleware);

        self::assertArrayHasKey('auth.login', $routes);
        self::assertContains('web', $routes['auth.login']->middleware);
        self::assertContains('guest', $routes['auth.login']->middleware);
        self::assertContains('xss', $routes['auth.login']->middleware);

        self::assertArrayHasKey('home', $routes);
        self::assertContains('web', $routes['home']->middleware);
        self::assertContains('auth.web', $routes['home']->middleware);
        self::assertContains('permission:management-view', $routes['home']->middleware);

        self::assertArrayHasKey('dashboard', $routes);
        self::assertContains('web', $routes['dashboard']->middleware);
        self::assertContains('auth.web', $routes['dashboard']->middleware);

        self::assertArrayHasKey('auth.logout', $routes);
        self::assertContains('web', $routes['auth.logout']->middleware);
        self::assertContains('auth.web', $routes['auth.logout']->middleware);

        self::assertArrayHasKey('modal.content', $routes);
        self::assertContains('web', $routes['modal.content']->middleware);
        self::assertContains('auth.web', $routes['modal.content']->middleware);
    }
}