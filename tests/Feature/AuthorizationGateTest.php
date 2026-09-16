<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Auth\AuthManager;
use Core\Http\JsonResponse;
use Core\Http\Responsable;
use Core\Routing\Router;

/**
 * Authorization double.
 *
 * The gates are the last thing between a request and data it should not see, so
 * what matters here is the *decision*, not how a permission is loaded — which is
 * why the ability list is set rather than queried.
 */
final class GateAuth extends AuthManager
{
    public bool $authenticated = false;

    /** @var list<string> */
    public array $abilities = [];

    /** @var list<string> */
    public array $roles = [];

    /** @var list<string> Every ability the gates asked about, in order. */
    public array $asked = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function check(array|string|null $methods = null): bool
    {
        return $this->authenticated;
    }

    public function checkSession(): bool
    {
        return $this->authenticated;
    }

    public function id(array|string|null $methods = null): ?int
    {
        return $this->authenticated ? 1 : null;
    }

    public function can(string $ability, mixed $arguments = []): bool
    {
        $this->asked[] = $ability;

        return in_array($ability, $this->abilities, true);
    }

    public function hasRole(string|int $role, ?int $userId = null): bool
    {
        return in_array((string) $role, $this->roles, true);
    }

    public function hasAnyRole(array|string $roles, ?int $userId = null): bool
    {
        return array_intersect(array_map('strval', (array) $roles), $this->roles) !== [];
    }

    public function hasAnyPermission(array|string $permissions, ?int $userId = null): bool
    {
        foreach ((array) $permissions as $permission) {
            $this->asked[] = (string) $permission;
        }

        return array_intersect(array_map('strval', (array) $permissions), $this->abilities) !== [];
    }
}

/**
 * RBAC and feature flags driven through the real middleware pipeline.
 *
 * A unit test can prove a gate returns false. Only this can prove the refusal
 * survives the pipeline, negotiates content, and reaches the client as a status
 * a caller can act on — and, more importantly, that a gate cannot be walked
 * past by a request shaped slightly differently than the one it was written for.
 */
final class AuthorizationGateTest extends FeatureTestCase
{
    private GateAuth $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auth = new GateAuth();
        register_framework_service('auth', fn(): GateAuth => $this->auth);
    }

    protected function middlewareAliases(): array
    {
        return [
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'permission.any' => \App\Http\Middleware\RequireAnyPermission::class,
            'role' => \App\Http\Middleware\RequireRole::class,
            'feature' => \App\Http\Middleware\RequireFeature::class,
        ];
    }

    protected function frameworkConfig(): array
    {
        $config = parent::frameworkConfig();

        // Nested under `flags`, which FeatureServiceProvider checks first. A flat
        // map is only the fallback, so an earlier test leaving a features.flags
        // key behind would win over it — a failure that appears only in the full
        // run.
        $config['features'] = [
            'flags' => [
                'billing' => true,
                'beta-reports' => false,
                'staging-only' => ['enabled' => true, 'environments' => ['staging']],
                'admins-only' => ['enabled' => true, 'roles' => ['admin']],
            ],
        ];

        return $config;
    }

    /** @param list<string> $middleware */
    private function call(array $middleware, string $path = '/reports'): Responsable
    {
        return $this->dispatch(
            $this->request('GET', $path, headers: ['Accept' => 'application/json']),
            function (Router $r) use ($middleware, $path): void {
                $route = $r->get($path, static fn(): JsonResponse => new JsonResponse(['code' => 200, 'ok' => true]));

                foreach ($middleware as $layer) {
                    $route->middleware($layer);
                }
            }
        );
    }

    // ─── Permissions ─────────────────────────────────────────────────

    /** An unauthenticated caller is 401, not 403: it has not failed a check yet. */
    public function testAnUnauthenticatedCallerIsRejectedBeforeAnyPermissionLookup(): void
    {
        $response = $this->call(['permission:report-view']);

        $this->assertStatus(401, $response);
        self::assertSame([], $this->auth->asked, 'No permission should be looked up for a guest.');
    }

    public function testAMissingPermissionIsForbidden(): void
    {
        $this->auth->authenticated = true;
        $this->auth->abilities = ['report-list'];

        $response = $this->call(['permission:report-view']);

        $this->assertStatus(403, $response);
        $this->assertJsonSubset(['permission' => 'report-view'], $response);
    }

    public function testTheGrantedPermissionPassesThrough(): void
    {
        $this->auth->authenticated = true;
        $this->auth->abilities = ['report-view'];

        $this->assertStatus(200, $this->call(['permission:report-view']));
    }

    /** Several permissions on one route means all of them, not any of them. */
    public function testEveryListedPermissionIsRequired(): void
    {
        $this->auth->authenticated = true;
        $this->auth->abilities = ['report-view'];

        $this->assertStatus(403, $this->call(['permission:report-view,report-export']));
    }

    public function testAllListedPermissionsTogetherPass(): void
    {
        $this->auth->authenticated = true;
        $this->auth->abilities = ['report-view', 'report-export'];

        $this->assertStatus(200, $this->call(['permission:report-view,report-export']));
    }

    /** permission.any is the opposite contract: one of them is enough. */
    public function testAnyPermissionNeedsOnlyOne(): void
    {
        $this->auth->authenticated = true;
        $this->auth->abilities = ['report-export'];

        $this->assertStatus(200, $this->call(['permission.any:report-view,report-export']));
    }

    public function testAnyPermissionStillRefusesWhenNoneMatch(): void
    {
        $this->auth->authenticated = true;
        $this->auth->abilities = ['something-else'];

        $this->assertStatus(403, $this->call(['permission.any:report-view,report-export']));
    }

    /**
     * A gate given no permission to check must not become a gate that admits
     * anyone — it still has to require authentication.
     */
    public function testAnEmptyPermissionListStillRequiresAuthentication(): void
    {
        $this->assertStatus(401, $this->call(['permission']));

        $this->auth->authenticated = true;
        $this->assertStatus(200, $this->call(['permission']));
    }

    // ─── Roles ───────────────────────────────────────────────────────

    public function testAMissingRoleIsForbidden(): void
    {
        $this->auth->authenticated = true;
        $this->auth->roles = ['editor'];

        $this->assertStatus(403, $this->call(['role:admin']));
    }

    public function testTheGrantedRolePassesThrough(): void
    {
        $this->auth->authenticated = true;
        $this->auth->roles = ['admin'];

        $this->assertStatus(200, $this->call(['role:admin']));
    }

    // ─── Feature flags ───────────────────────────────────────────────

    public function testAnEnabledFeatureIsReachable(): void
    {
        $this->auth->authenticated = true;

        $this->assertStatus(200, $this->call(['feature:billing']));
    }

    public function testADisabledFeatureIsNotReachable(): void
    {
        $this->auth->authenticated = true;

        $response = $this->call(['feature:beta-reports']);

        self::assertNotSame(200, $response->status(), 'A disabled feature must not serve its route.');
    }

    /** An unknown flag is off: a typo must not open a route, it must close one. */
    public function testAnUnknownFeatureIsTreatedAsDisabled(): void
    {
        $this->auth->authenticated = true;

        $response = $this->call(['feature:never-defined']);

        self::assertNotSame(200, $response->status());
    }

    /** ENVIRONMENT is 'testing' here, so a staging-scoped flag stays closed. */
    public function testAFeatureScopedToAnotherEnvironmentStaysClosed(): void
    {
        $this->auth->authenticated = true;

        $response = $this->call(['feature:staging-only']);

        self::assertNotSame(200, $response->status());
    }

    // ─── Layered gates ───────────────────────────────────────────────

    /**
     * The order matters: a caller who lacks the permission must be refused even
     * when the feature is on, and vice versa. Neither layer may be sufficient
     * on its own.
     */
    public function testBothTheFeatureAndThePermissionMustPass(): void
    {
        $this->auth->authenticated = true;

        // Feature on, permission missing.
        $this->auth->abilities = [];
        self::assertNotSame(200, $this->call(['feature:billing', 'permission:report-view'])->status());

        // Permission granted, feature off.
        $this->auth->abilities = ['report-view'];
        self::assertNotSame(200, $this->call(['feature:beta-reports', 'permission:report-view'])->status());

        // Both satisfied.
        self::assertSame(200, $this->call(['feature:billing', 'permission:report-view'])->status());
    }

    /** A refusal has to be machine-readable for the client that provoked it. */
    public function testARefusalIsJsonForAnApiCaller(): void
    {
        $this->auth->authenticated = true;
        $this->auth->abilities = [];

        $response = $this->call(['permission:report-view']);

        self::assertInstanceOf(JsonResponse::class, $response);
        $this->assertJsonSubset(['code' => 403], $response);
    }
}
