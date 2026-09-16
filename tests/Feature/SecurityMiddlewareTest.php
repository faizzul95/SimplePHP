<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Routing\Router;

/**
 * End-to-end checks that the security middleware actually block what they claim
 * to, with the status and body a client can act on.
 *
 * These run the real middleware classes through the real pipeline. A unit test
 * can prove a middleware throws; only this can prove the throw survives the
 * pipeline, negotiates content, and reaches the client as the right status.
 */
final class SecurityMiddlewareTest extends FeatureTestCase
{
    protected function middlewareAliases(): array
    {
        return [
            'trusted.hosts' => \App\Http\Middleware\ValidateTrustedHosts::class,
            'content.type' => \App\Http\Middleware\EnforceContentType::class,
            'payload.limits' => \App\Http\Middleware\ValidatePayloadLimits::class,
            'throttle' => \App\Http\Middleware\RateLimit::class,
            'headers' => \App\Http\Middleware\SetSecurityHeaders::class,
        ];
    }

    protected function frameworkConfig(): array
    {
        $config = parent::frameworkConfig();

        $config['security'] = [
            'trusted' => ['hosts' => ['example.test'], 'proxies' => []],
            'request_hardening' => [
                'enabled' => true,
                'allowed_hosts' => ['example.test'],
                'max_uri_length' => 2000,
                'max_body_bytes' => 1024,
                'max_header_count' => 64,
                'max_user_agent_length' => 1024,
                'max_input_vars' => 200,
                'allowed_write_content_types' => ['application/json'],
            ],
            'csp' => ['enabled' => false],
        ];

        $config['framework']['rate_limiters'] = [
            'tight' => ['max_attempts' => 2, 'decay_seconds' => 60, 'scope' => 'ip-route'],
        ];

        $config['framework']['content_type_profiles'] = [
            'json' => ['application/json', 'application/*+json'],
        ];

        return $config;
    }

    private function ok(): callable
    {
        return static fn(): Responsable => new JsonResponse(['code' => 200, 'ok' => true]);
    }

    // ─── Trusted hosts ───────────────────────────────────────────────

    public function testAnAllowedHostPassesThrough(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/guarded', headers: ['Accept' => 'application/json']),
            fn(Router $r) => $r->get('/guarded', $this->ok())->middleware('trusted.hosts')
        );

        $this->assertStatus(200, $response);
    }

    public function testAHostHeaderOutsideTheAllowListIsRejected(): void
    {
        $request = $this->request('GET', '/guarded', headers: ['Accept' => 'application/json']);
        $request->setAttribute('route.middleware', ['trusted.hosts']);

        // Host-header poisoning: the value reaches password-reset links and cache keys.
        $_SERVER['HTTP_HOST'] = 'attacker.test';
        $spoofed = new Request([], [], array_merge($_SERVER, [
            'HTTP_HOST' => 'attacker.test',
            'REQUEST_URI' => '/guarded',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT' => 'application/json',
        ]));

        $response = $this->dispatch(
            $spoofed,
            fn(Router $r) => $r->get('/guarded', $this->ok())->middleware('trusted.hosts')
        );

        self::assertGreaterThanOrEqual(400, $response->status(), 'An untrusted Host must not be served.');
    }

    // ─── Content type ────────────────────────────────────────────────

    public function testAWriteWithTheWrongContentTypeIsRejected(): void
    {
        $response = $this->dispatch(
            $this->request('POST', '/api/save', body: ['a' => 1], headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'text/xml',
            ]),
            fn(Router $r) => $r->post('/api/save', $this->ok())->middleware('content.type:json')
        );

        $this->assertStatus(415, $response);
        self::assertInstanceOf(JsonResponse::class, $response);
    }

    public function testAWriteWithTheCorrectContentTypePasses(): void
    {
        $response = $this->dispatch(
            $this->request('POST', '/api/save', body: ['a' => 1], headers: [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]),
            fn(Router $r) => $r->post('/api/save', $this->ok())->middleware('content.type:json')
        );

        $this->assertStatus(200, $response);
    }

    // ─── Rate limiting ───────────────────────────────────────────────

    public function testTheRateLimiterBlocksOnceTheBudgetIsSpent(): void
    {
        $routes = fn(Router $r) => $r->get('/limited', $this->ok())->middleware('throttle:tight');

        $first = $this->dispatch($this->request('GET', '/limited', headers: ['Accept' => 'application/json']), $routes);
        $second = $this->dispatch($this->request('GET', '/limited', headers: ['Accept' => 'application/json']), $routes);
        $third = $this->dispatch($this->request('GET', '/limited', headers: ['Accept' => 'application/json']), $routes);

        $this->assertStatus(200, $first);
        $this->assertStatus(200, $second);
        $this->assertStatus(429, $third, 'The third request exceeds max_attempts=2.');
    }

    public function testA429TellsTheClientWhenToRetry(): void
    {
        $routes = fn(Router $r) => $r->get('/limited2', $this->ok())->middleware('throttle:tight');
        $request = fn() => $this->request('GET', '/limited2', headers: ['Accept' => 'application/json']);

        $this->dispatch($request(), $routes);
        $this->dispatch($request(), $routes);
        $blocked = $this->dispatch($request(), $routes);

        $this->assertStatus(429, $blocked);
        $this->assertHeaderPresent('Retry-After', $blocked);

        // A mobile client backs off on the body field, not just the header.
        $payload = $this->jsonOf($blocked);
        self::assertArrayHasKey('retry_after', $payload);
    }

    public function testRateLimitHeadersAreOnSuccessfulResponsesToo(): void
    {
        $response = $this->dispatch(
            $this->request('GET', '/counted', headers: ['Accept' => 'application/json']),
            fn(Router $r) => $r->get('/counted', $this->ok())->middleware('throttle:tight')
        );

        $this->assertHeaderPresent('X-RateLimit-Limit', $response);
        $this->assertHeaderPresent('X-RateLimit-Remaining', $response);
    }
}
