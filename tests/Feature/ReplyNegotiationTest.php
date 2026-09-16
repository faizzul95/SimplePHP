<?php

declare(strict_types=1);

namespace Tests\Feature;

use Core\Http\JsonResponse;
use Core\Http\Reply;
use Core\Http\Request;
use Core\Http\Responsable;
use Core\Routing\Router;

/**
 * Reply driven through the real router and the real middleware stack.
 *
 * ReplyTest covers the object in isolation. This covers the part that only the
 * kernel can answer: that a controller *returning* a Reply survives the pipeline
 * and reaches the client as the right kind of response — the previous shape,
 * jsonResponse(), threw, and a return value takes a completely different path
 * out of the router.
 */
final class ReplyNegotiationTest extends FeatureTestCase
{
    protected function middlewareAliases(): array
    {
        return [
            'headers' => \App\Http\Middleware\SetSecurityHeaders::class,
        ];
    }

    protected function frameworkConfig(): array
    {
        $config = parent::frameworkConfig();

        $config['security'] = [
            'trusted' => ['hosts' => ['example.test'], 'proxies' => []],
            'csp' => ['enabled' => false],
            'csrf' => ['csrf_protection' => false],
        ];

        return $config;
    }

    /** @param array<string, string> $headers */
    private function call(callable $action, string $method = 'POST', array $headers = []): Responsable
    {
        return $this->dispatch(
            $this->request($method, '/things/save', headers: $headers),
            fn(Router $r) => strtoupper($method) === 'POST'
                ? $r->post('/things/save', $action)
                : $r->get('/things/save', $action)
        );
    }

    // ─── A controller returning a Reply ──────────────────────────────

    public function testAnApiCallerGetsTheJsonBody(): void
    {
        $response = $this->call(
            static fn(): Reply => Reply::make('Saved', ['id' => 7]),
            headers: ['Accept' => 'application/json']
        );

        $this->assertStatus(200, $response);
        $this->assertJsonSubset(['code' => 200, 'message' => 'Saved'], $response);
    }

    /**
     * The behaviour that did not exist before: every controller answered in
     * JSON, so a plain form post got a JSON body instead of a redirect and the
     * message never reached a page.
     */
    public function testABrowserFormPostGetsARedirect(): void
    {
        $response = $this->call(
            static fn(): Reply => Reply::make('Saved')->to('/things'),
            headers: ['Accept' => 'text/html']
        );

        $this->assertStatus(303, $response);
        $this->assertHeaderPresent('Location', $response);
        self::assertStringContainsString('/things', $response->headers()['Location']);
    }

    public function testAFailureSendsTheBrowserBack(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.test/things/edit/7';

        $response = $this->call(
            static fn(): Reply => Reply::make('Could not save')->code(422),
            headers: ['Accept' => 'text/html']
        );

        $this->assertStatus(302, $response);
        self::assertStringContainsString('/things/edit/7', $response->headers()['Location']);
    }

    public function testTheSameActionServesBothCallers(): void
    {
        $action = static fn(): Reply => Reply::make('Saved', ['id' => 7])->to('/things');

        $api = $this->call($action, headers: ['Accept' => 'application/json']);
        $browser = $this->call($action, headers: ['Accept' => 'text/html']);

        // The router hands back the Reply itself — it is a Responsable — so the
        // assertion is on what it resolves to, not on its class.
        self::assertArrayNotHasKey('Location', $api->headers());
        self::assertStringContainsString('application/json', $api->headers()['Content-Type'] ?? '');
        self::assertSame(200, $api->status());

        self::assertArrayHasKey('Location', $browser->headers());
        self::assertSame(303, $browser->status());
    }

    // ─── It still travels through the middleware stack ───────────────

    /** A Reply is a Responsable, so response-touching middleware must still see it. */
    public function testSecurityHeadersAreStillAppliedToAReply(): void
    {
        $response = $this->dispatch(
            $this->request('POST', '/things/save', headers: ['Accept' => 'application/json']),
            fn(Router $r) => $r->post('/things/save', static fn(): Reply => Reply::make('Saved'))
                ->middleware('headers')
        );

        $this->assertStatus(200, $response);
        $this->assertHeaderPresent('X-Content-Type-Options', $response);
    }

    // ─── Validation failures use the same negotiation ────────────────

    /**
     * Router's ValidationException handler now builds a Reply rather than
     * hand-rolling the negotiation a second time. Both shapes have to match, or
     * a client cannot rely on either.
     */
    public function testARejectedWriteAnswersAnApiCallerWithAnErrorsObject(): void
    {
        $response = $this->call(
            static fn() => throw new \Core\Http\ValidationException(
                'The email field is required.',
                ['email' => 'The email field is required.'],
                422
            ),
            headers: ['Accept' => 'application/json']
        );

        $this->assertStatus(422, $response);

        $payload = $this->jsonOf($response);
        self::assertSame('The email field is required.', $payload['message']);
        self::assertSame(['email' => 'The email field is required.'], $payload['errors']);
    }

    public function testARejectedWriteSendsABrowserBackToTheForm(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.test/things/edit/7';

        $response = $this->call(
            static fn() => throw new \Core\Http\ValidationException('Bad input', ['email' => 'Required'], 422),
            headers: ['Accept' => 'text/html']
        );

        $this->assertStatus(302, $response);
        self::assertStringContainsString('/things/edit/7', $response->headers()['Location']);
    }

    /**
     * 403 from authorize() has no form to return to, so it renders rather than
     * redirecting — a redirect there would loop the user back to a page they
     * are not allowed to submit.
     */
    public function testAnUnauthorizedWriteRendersRatherThanRedirecting(): void
    {
        $response = $this->call(
            static fn() => throw new \Core\Http\ValidationException('This action is unauthorized.', [], 403),
            headers: ['Accept' => 'text/html']
        );

        $this->assertStatus(403, $response);
        $this->assertHeaderMissing('Location', $response);
    }

    public function testAValidationFailureOnAReadStillRenders(): void
    {
        $response = $this->call(
            static fn() => throw new \Core\Http\ValidationException('Bad query', ['q' => 'Required'], 422),
            'GET',
            ['Accept' => 'text/html']
        );

        $this->assertStatus(422, $response);
        $this->assertHeaderMissing('Location', $response);
    }

    // ─── Controllers can still return the old shapes ─────────────────

    /** The array form predates Reply and a lot of code still uses it. */
    public function testAControllerReturningAPlainArrayStillWorks(): void
    {
        $response = $this->call(
            static fn(): array => ['code' => 201, 'message' => 'Created'],
            headers: ['Accept' => 'application/json']
        );

        $this->assertStatus(201, $response);
        $this->assertJsonSubset(['message' => 'Created'], $response);
    }

    public function testAControllerReturningAJsonResponseStillWorks(): void
    {
        $response = $this->call(
            static fn(): JsonResponse => new JsonResponse(['code' => 200, 'ok' => true]),
            headers: ['Accept' => 'application/json']
        );

        $this->assertStatus(200, $response);
        $this->assertJsonSubset(['ok' => true], $response);
    }
}
