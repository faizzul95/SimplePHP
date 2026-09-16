<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Auth\AuthManager;
use Core\Http\JsonResponse;
use Core\Http\Responsable;
use Core\Routing\Router;

/**
 * Authentication double: the driver test cares about which credential the stack
 * accepts, not about how a token is looked up in the database.
 */
final class FakeAuth extends AuthManager
{
    public bool $validToken = false;
    public bool $validSession = false;

    /** @var list<string> */
    public array $apiMethods = ['token'];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function apiMethods(array|string|null $methods = null): array
    {
        return $this->apiMethods;
    }

    public function check(array|string|null $methods = null): bool
    {
        foreach ((array) ($methods ?? ['session']) as $method) {
            if ($method === 'token' && $this->validToken) {
                return true;
            }

            if ($method === 'session' && $this->validSession) {
                return true;
            }
        }

        return false;
    }

    public function checkSession(): bool
    {
        return $this->validSession;
    }

    public function id(array|string|null $methods = null): ?int
    {
        return $this->check($methods) ? 7 : null;
    }
}

/**
 * The composed api.app stack, driven end to end.
 *
 * ApiAuthDriverConfigTest proves the shipped config composes the right list of
 * middleware. This proves the list behaves: that a token client needs no CSRF
 * token and no Origin header, that switching to cookies actually turns the token
 * off, and — the one that matters — that hybrid's CSRF skip cannot be turned
 * into a bypass by attaching a junk bearer to a cookie-authenticated request.
 */
final class ApiAuthDriverTest extends FeatureTestCase
{
    private FakeAuth $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auth = new FakeAuth();
        register_framework_service('auth', fn(): FakeAuth => $this->auth);
    }

    protected function middlewareAliases(): array
    {
        return [
            'auth' => \App\Http\Middleware\RequireAuth::class,
            'auth.api' => \App\Http\Middleware\RequireApiToken::class,
            'auth.web' => \App\Http\Middleware\RequireSessionAuth::class,
            'csrf' => \App\Http\Middleware\VerifyCsrfToken::class,
            'origin.policy' => \App\Http\Middleware\EnforceOriginPolicy::class,
        ];
    }

    /**
     * The three stacks app/config/framework.php composes, minus the shared `api`
     * layers that are not what this test is about.
     */
    protected function middlewareGroups(): array
    {
        return [
            'token' => ['auth.api'],
            'session' => ['origin.policy:strict', 'auth.web', 'csrf:force'],
            'hybrid' => ['auth:token,session', 'csrf:stateful'],
        ];
    }

    protected function frameworkConfig(): array
    {
        $config = parent::frameworkConfig();

        $config['security'] = [
            'csrf' => [
                'csrf_protection' => true,
                'csrf_token_name' => 'csrf_token',
                'csrf_cookie_name' => 'csrf_cookie',
                'csrf_origin_check' => true,
                'csrf_allow_missing_origin' => false,
                'csrf_trusted_origins' => [],
            ],
        ];

        return $config;
    }

    private function ok(): callable
    {
        return static fn(): Responsable => new JsonResponse(['code' => 200, 'ok' => true]);
    }

    /** @param array<string, string> $headers */
    private function call(string $group, string $method, array $headers = []): Responsable
    {
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';

        return $this->dispatch(
            $this->request($method, '/api/v1/things', headers: $headers),
            fn(Router $r) => strtoupper($method) === 'POST'
                ? $r->post('/api/v1/things', $this->ok())->middleware($group)
                : $r->get('/api/v1/things', $this->ok())->middleware($group)
        );
    }

    /**
     * Give the request everything a same-site browser form would carry.
     *
     * @return array<string, string>
     */
    private function browserCredentials(): array
    {
        $token = str_repeat('a1b2', 16);
        $_COOKIE['csrf_cookie'] = $token;
        $_COOKIE['csrf_cookie_time'] = (string) time();

        return ['Origin' => 'https://example.test', 'X-CSRF-TOKEN' => $token];
    }

    // ─── Token driver ────────────────────────────────────────────────

    public function testATokenClientIsRejectedWithoutACredential(): void
    {
        $this->assertStatus(401, $this->call('token', 'GET'));
    }

    public function testAValidTokenGetsThrough(): void
    {
        $this->auth->validToken = true;

        $this->assertStatus(200, $this->call('token', 'GET'));
    }

    /**
     * The point of the default. A mobile client posts with no cookie, no CSRF
     * token, and no Origin header — under the old cookie stack every one of those
     * was a rejection.
     */
    public function testATokenClientCanWriteWithNoCsrfTokenAndNoOriginHeader(): void
    {
        $this->auth->validToken = true;

        $this->assertStatus(200, $this->call('token', 'POST'));
    }

    public function testASessionAloneIsNotEnoughUnderTheTokenDriver(): void
    {
        $this->auth->validSession = true;

        $this->assertStatus(401, $this->call('token', 'GET'));
    }

    public function testTheRejectionIsJsonRatherThanALoginRedirect(): void
    {
        $response = $this->call('token', 'GET');

        self::assertInstanceOf(JsonResponse::class, $response);
        $this->assertJsonSubset(['code' => 401], $response);
    }

    // ─── Session driver ──────────────────────────────────────────────

    public function testTheSessionDriverIgnoresABearerToken(): void
    {
        $this->auth->validToken = true;

        $this->assertStatus(401, $this->call('session', 'GET'));
    }

    public function testTheSessionDriverAcceptsACookieSession(): void
    {
        $this->auth->validSession = true;

        $this->assertStatus(200, $this->call('session', 'GET', $this->browserCredentials()));
    }

    public function testTheSessionDriverStillRejectsAWriteWithNoOrigin(): void
    {
        $this->auth->validSession = true;

        $this->assertStatus(403, $this->call('session', 'POST'));
    }

    // ─── Hybrid driver ───────────────────────────────────────────────

    public function testHybridAcceptsATokenClientWithNoCsrfToken(): void
    {
        $this->auth->validToken = true;

        $this->assertStatus(200, $this->call('hybrid', 'POST'));
    }

    public function testHybridAcceptsACookieClientThatSendsItsCsrfToken(): void
    {
        $this->auth->validSession = true;

        $this->assertStatus(200, $this->call('hybrid', 'POST', $this->browserCredentials()));
    }

    public function testHybridStillRejectsACookieWriteWithNoCsrfToken(): void
    {
        $this->auth->validSession = true;

        $this->assertStatus(419, $this->call('hybrid', 'POST'));
    }

    /**
     * The bypass this design has to survive.
     *
     * An attacker's page cannot read the victim's cookie, but the browser sends
     * it anyway. If the CSRF skip keyed off *the presence* of an Authorization
     * header, the attacker would attach any string as a bearer and the request
     * would authenticate on the cookie with CSRF skipped. Keying off the
     * authentication that actually succeeded closes that: the junk bearer fails,
     * so the request is cookie-authenticated, so CSRF applies.
     */
    public function testAJunkBearerDoesNotBuyACookieRequestOutOfCsrf(): void
    {
        $this->auth->validSession = true;
        $this->auth->validToken = false;

        $response = $this->call('hybrid', 'POST', ['Authorization' => 'Bearer not-a-real-token']);

        $this->assertStatus(419, $response);
    }

    public function testHybridRejectsARequestWithNeitherCredential(): void
    {
        $this->assertStatus(401, $this->call('hybrid', 'POST'));
    }

    /**
     * With no stateless method enabled there is nothing for the skip to key off,
     * so `csrf:stateful` has to fall back to enforcing rather than to allowing.
     */
    public function testStatefulCsrfEnforcesWhenNoStatelessMethodIsConfigured(): void
    {
        $this->auth->validSession = true;
        $this->auth->validToken = true;
        $this->auth->apiMethods = ['session'];

        $this->assertStatus(419, $this->call('hybrid', 'POST'));
    }
}
